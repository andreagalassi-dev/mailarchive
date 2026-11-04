<?php
declare(strict_types=1);

namespace MailArchive\Services;

use MailArchive\Config\Database;
use MailArchive\Config\Config;
use MailArchive\Core\Audit;
use MailArchive\Core\Response;
use PDO;
use RuntimeException;
use ZipArchive;

class ExportService
{
    public static function export(array $filters, array $actor): void
    {
        $pdo = Database::pdo();
        $storageRoot = Config::getStorageDir();

        $where = [];
        $params = [];
        $mailboxName = 'tutte_le_caselle';

        if (!empty($filters['mailboxId']) && $filters['mailboxId'] !== 'all') {
            $where[] = 'e.mailbox_id = :mid';
            $params[':mid'] = $filters['mailboxId'];

            $stmtMb = $pdo->prepare('SELECT email_address FROM mailboxes WHERE id = :id LIMIT 1');
            $stmtMb->execute([':id' => $filters['mailboxId']]);
            $mbEmail = $stmtMb->fetchColumn();
            if ($mbEmail) {
                $mailboxName = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string) $mbEmail);
            }
        }

        if (!empty($filters['folder']) && $filters['folder'] !== 'ALL') {
            $where[] = 'e.folder = :folder';
            $params[':folder'] = strtoupper((string) $filters['folder']);
        }

        if (!empty($filters['dateFrom'])) {
            $where[] = 'e.date_sent >= :dfrom';
            $params[':dfrom'] = $filters['dateFrom'];
        }

        if (!empty($filters['dateTo'])) {
            $where[] = 'e.date_sent <= :dto';
            $params[':dto'] = $filters['dateTo'] . ' 23:59:59';
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Conta messaggi
        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM emails e {$whereSql}");
        $stmtCount->execute($params);
        $totalCount = (int) $stmtCount->fetchColumn();

        if ($totalCount === 0) {
            Response::error('Nessuna email trovata per i criteri di ricerca specificati.', 404);
        }

        // Nome file ZIP
        $nowStr = date('Y-m-d_H-i');
        $zipFilename = "mailarchive_{$mailboxName}_{$nowStr}.zip";

        // Cartella temporanea
        $tmpDir = $storageRoot . DIRECTORY_SEPARATOR . 'tmp';
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $tempZipPath = $tmpDir . DIRECTORY_SEPARATOR . 'export_' . Database::uuid() . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            Response::error('Impossibile creare l’archivio ZIP temporaneo', 500);
        }

        // Guida al ripristino
        $readme = "MailArchive - Guida al Ripristino delle Email\n" .
                  "=====================================================\n" .
                  "Questo archivio contiene {$totalCount} messaggi archiviati in formato standard RFC 822 / MIME (.EML).\n\n" .
                  "STRUTTURA CARTELLE:\n" .
                  "- Posta_in_Arrivo/ : Email ricevute (INBOX)\n" .
                  "- Posta_Inviata/   : Email inviate (SENT)\n\n" .
                  "COME REINSERIRE LE EMAIL NEL PROPRIO SERVIZIO DI POSTA (Outlook, Thunderbird, Apple Mail):\n" .
                  "1. Apri il tuo programma di posta sul computer.\n" .
                  "2. Seleziona i file .EML da questa cartella.\n" .
                  "3. TRASCINA CON IL MOUSE (Drag & Drop) i file direttamente dentro la cartella desiderata\n" .
                  "   del tuo programma di posta.\n" .
                  "4. Il client caricherà automaticamente i messaggi sul server di posta via IMAP!\n\n" .
                  "Generato da MailArchive: " . date('Y-m-d H:i:s') . "\n";

        $zip->addFromString('COME_RIPRISTINARE_LE_EMAIL.txt', $readme);

        $format = strtoupper((string) ($filters['format'] ?? 'EML'));

        // Query a lotti per evitare problemi di memoria
        $stmtEmails = $pdo->prepare("
            SELECT e.id, e.folder, e.subject, e.raw_storage_path, m.email_address
            FROM emails e
            INNER JOIN mailboxes m ON e.mailbox_id = m.id
            {$whereSql}
            ORDER BY e.date_sent ASC
        ");
        $stmtEmails->execute($params);

        if ($format === 'MBOX') {
            // Aggregazione in formato MBOX
            $mboxData = [];
            while ($em = $stmtEmails->fetch(PDO::FETCH_ASSOC)) {
                if (empty($em['raw_storage_path'])) continue;
                $emlPath = $storageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $em['raw_storage_path']);
                if (!file_exists($emlPath)) continue;

                $folderTag = $em['folder'] === 'SENT' ? 'sent.mbox' : 'inbox.mbox';
                $boxPath = ($filters['mailboxId'] ?? 'all') === 'all'
                    ? preg_replace('/[^a-zA-Z0-9._-]/', '_', $em['email_address']) . '/' . $folderTag
                    : $folderTag;

                if (!isset($mboxData[$boxPath])) {
                    $mboxData[$boxPath] = '';
                }

                $content = file_get_contents($emlPath);
                $mboxData[$boxPath] .= "From MailArchive " . date('r') . "\r\n" . $content . "\r\n\r\n";
            }

            foreach ($mboxData as $entryName => $content) {
                $zip->addFromString($entryName, $content);
            }
        } else {
            // Formato standard EML suddiviso per cartelle
            $idx = 1;
            while ($em = $stmtEmails->fetch(PDO::FETCH_ASSOC)) {
                if (empty($em['raw_storage_path'])) continue;
                $emlPath = $storageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $em['raw_storage_path']);
                if (!file_exists($emlPath)) continue;

                $folderName = $em['folder'] === 'SENT' ? 'Posta_Inviata' : 'Posta_in_Arrivo';
                $safeSubj = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string) ($em['subject'] ?: 'messaggio'));
                $entryName = "{$folderName}/" . sprintf('%05d', $idx++) . "_{$safeSubj}.eml";

                $zip->addFile($emlPath, $entryName);
            }
        }

        $zip->close();

        Audit::log('EMAIL_BULK_EXPORTED', 'EMAIL', null, [
            'totalCount' => $totalCount,
            'format' => $format,
            'mailboxName' => $mailboxName
        ], $actor['id'] ?? null, $actor['email'] ?? null);

        // Invio file in streaming
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . rawurlencode($zipFilename) . '"');
        header('Content-Length: ' . filesize($tempZipPath));
        header('Cache-Control: no-store, private');

        readfile($tempZipPath);
        @unlink($tempZipPath);
        exit;
    }
}
