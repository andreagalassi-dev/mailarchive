<?php
declare(strict_types=1);

namespace MailArchive\Services;

use MailArchive\Config\Database;
use MailArchive\Config\Config;
use MailArchive\Core\Audit;
use PDO;
use Throwable;

class AlertService
{
    /**
     * Invia un'email di notifica usando SMTP socket o mail() nativo
     */
    public static function sendEmailNotification(string $to, string $subject, string $htmlContent): bool
    {
        $smtpHost = (string) Config::get('ALERT_SMTP_HOST', '');
        $from = (string) Config::get('ALERT_FROM_EMAIL', Config::getDefaultSenderEmail());

        if ($smtpHost !== '') {
            try {
                return self::sendViaSmtpSocket($to, $subject, $htmlContent);
            } catch (Throwable $e) {
                error_log('[AlertService] Errore invio SMTP: ' . $e->getMessage());
            }
        }

        // Fallback su mail() standard di PHP
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=utf-8',
            "From: {$from}",
            "Reply-To: {$from}",
            'X-Mailer: MailArchive-PHP/1.0'
        ];

        return @mail($to, $subject, $htmlContent, implode("\r\n", $headers));
    }

    private static function sendViaSmtpSocket(string $to, string $subject, string $htmlContent): bool
    {
        $host = (string) Config::get('ALERT_SMTP_HOST', '');
        $port = (int) Config::get('ALERT_SMTP_PORT', 465);
        $user = (string) Config::get('ALERT_SMTP_USER', '');
        $pass = (string) Config::get('ALERT_SMTP_PASS', '');
        $from = (string) Config::get('ALERT_FROM_EMAIL', $user ?: Config::getDefaultSenderEmail());
        $isSecure = filter_var(Config::get('ALERT_SMTP_SECURE', true), FILTER_VALIDATE_BOOLEAN) || $port === 465;

        $protocol = $isSecure ? 'ssl://' : '';
        $socket = @fsockopen($protocol . $host, $port, $errno, $errstr, 15);
        if (!$socket) {
            throw new \RuntimeException("Connessione SMTP fallita: {$errstr} ({$errno})");
        }

        $getResponse = function () use ($socket) {
            $data = '';
            while ($str = fgets($socket, 515)) {
                $data .= $str;
                if (substr($str, 3, 1) === ' ') break;
            }
            return $data;
        };

        $sendCommand = function (string $cmd) use ($socket, $getResponse) {
            fputs($socket, $cmd . "\r\n");
            return $getResponse();
        };

        $getResponse(); // Banner iniziale
        $sendCommand('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));

        if ($user !== '' && $pass !== '') {
            $sendCommand('AUTH LOGIN');
            $sendCommand(base64_encode($user));
            $sendCommand(base64_encode($pass));
        }

        $sendCommand("MAIL FROM: <{$from}>");
        $sendCommand("RCPT TO: <{$to}>");
        $sendCommand('DATA');

        $headers = "From: MailArchive <{$from}>\r\n" .
                   "To: <{$to}>\r\n" .
                   "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n" .
                   "MIME-Version: 1.0\r\n" .
                   "Content-Type: text/html; charset=UTF-8\r\n" .
                   "Date: " . date('r') . "\r\n";

        $body = $headers . "\r\n" . $htmlContent . "\r\n.";
        $sendCommand($body);
        $sendCommand('QUIT');
        fclose($socket);

        return true;
    }

    /**
     * Invia notifica HTTP POST in formato JSON verso il Webhook configurato (es. Slack, Discord, endpoint custom)
     */
    public static function sendWebhookNotification(array $payload): bool
    {
        $webhookUrl = (string) Config::get('ALERT_WEBHOOK_URL', '');
        if ($webhookUrl === '') {
            return false;
        }

        try {
            $body = json_encode([
                'app' => 'MailArchive',
                'event' => 'SYSTEM_ALERT',
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
                'alert' => $payload
            ], JSON_UNESCAPED_SLASHES);

            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\nUser-Agent: MailArchive-AlertNotifier/1.0\r\n",
                    'content' => $body,
                    'timeout' => 10,
                    'ignore_errors' => true
                ]
            ]);

            $res = @file_get_contents($webhookUrl, false, $ctx);
            return $res !== false;
        } catch (Throwable) {
            return false;
        }
    }

    public static function createAlert(
        string $severity,
        string $category,
        string $title,
        string $message,
        ?string $userId = null
    ): array {
        $pdo = Database::pdo();
        $id = Database::uuid();
        $now = Database::now();

        $stmt = $pdo->prepare('
            INSERT INTO system_alerts (id, severity, category, title, message, is_resolved, created_at)
            VALUES (:id, :sev, :cat, :title, :msg, 0, :now)
        ');
        $stmt->execute([
            ':id' => $id,
            ':sev' => strtoupper($severity),
            ':cat' => strtoupper($category),
            ':title' => $title,
            ':msg' => $message,
            ':now' => $now
        ]);

        $alertData = [
            'id' => $id,
            'severity' => strtoupper($severity),
            'category' => strtoupper($category),
            'title' => $title,
            'message' => $message,
            'isResolved' => false,
            'createdAt' => $now
        ];

        // Se severità CRITICAL, invia notifica email all'amministratore
        if (strtoupper($severity) === 'CRITICAL') {
            $recipient = (string) Config::get('ALERT_RECIPIENT_EMAIL', Config::get('INITIAL_ADMIN_EMAIL', ''));
            if ($recipient !== '') {
                $subj = "[MailArchive CRITICAL] {$title}";
                $html = "<p><strong>Allarme di Sistema MailArchive</strong></p><p>Categoria: {$category}</p><p>Messaggio: {$message}</p>";
                self::sendEmailNotification($recipient, $subj, $html);
            }
        }

        // Dispaccio Webhook se configurato
        self::sendWebhookNotification($alertData);

        return $alertData;
    }

    public static function listAlerts(array $filters): array
    {
        $pdo = Database::pdo();
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = min(100, max(1, (int) ($filters['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $where = [];
        $params = [];

        if (isset($filters['isResolved'])) {
            $isResolved = filter_var($filters['isResolved'], FILTER_VALIDATE_BOOLEAN);
            $where[] = 'is_resolved = :resolved';
            $params[':resolved'] = $isResolved ? 1 : 0;
        }

        if (!empty($filters['severity'])) {
            $where[] = 'severity = :sev';
            $params[':sev'] = strtoupper((string) $filters['severity']);
        }

        if (!empty($filters['category'])) {
            $where[] = 'category = :cat';
            $params[':cat'] = strtoupper((string) $filters['category']);
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmtCount = $pdo->prepare("SELECT COUNT(*) AS total FROM system_alerts {$whereSql}");
        $stmtCount->execute($params);
        $totalCount = (int) $stmtCount->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT * FROM system_alerts
            {$whereSql}
            ORDER BY created_at DESC
            LIMIT :limit OFFSET :offset
        ");

        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalPages = (int) ceil($totalCount / $limit);

        return [
            'data' => $alerts,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'totalCount' => $totalCount,
                'totalPages' => $totalPages,
                'hasNextPage' => $page < $totalPages,
                'hasPrevPage' => $page > 1
            ]
        ];
    }

    public static function resolveAlert(string $id, string $userId): array
    {
        $pdo = Database::pdo();
        $now = Database::now();

        $stmt = $pdo->prepare('
            UPDATE system_alerts 
            SET is_resolved = 1, resolved_at = :now, resolved_by_user_id = :uid
            WHERE id = :id
        ');
        $stmt->execute([':now' => $now, ':uid' => $userId, ':id' => $id]);

        $stmtGet = $pdo->prepare('SELECT * FROM system_alerts WHERE id = :id LIMIT 1');
        $stmtGet->execute([':id' => $id]);
        $alert = $stmtGet->fetch(PDO::FETCH_ASSOC);

        if (!$alert) {
            throw new \RuntimeException('Alert non trovato');
        }

        return $alert;
    }
}
