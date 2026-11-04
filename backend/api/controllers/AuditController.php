<?php
declare(strict_types=1);

namespace MailArchive\Controllers;

use MailArchive\Config\Database;
use MailArchive\Middleware\AuthMiddleware;
use MailArchive\Core\Response;
use PDO;

class AuditController
{
    public static function listAuditLogs(): void
    {
        AuthMiddleware::requireRole('ADMIN');
        $pdo = Database::pdo();
        $filters = $_GET;

        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = min(100, max(1, (int) ($filters['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;

        $where = [];
        $params = [];

        if (!empty($filters['userId'])) {
            $where[] = 'user_id = :uid';
            $params[':uid'] = $filters['userId'];
        }

        if (!empty($filters['action'])) {
            $where[] = 'action = :action';
            $params[':action'] = $filters['action'];
        }

        if (!empty($filters['resourceType'])) {
            $where[] = 'resource_type = :rtype';
            $params[':rtype'] = $filters['resourceType'];
        }

        if (!empty($filters['dateFrom'])) {
            $where[] = 'timestamp >= :dfrom';
            $params[':dfrom'] = $filters['dateFrom'];
        }

        if (!empty($filters['dateTo'])) {
            $where[] = 'timestamp <= :dto';
            $params[':dto'] = $filters['dateTo'] . ' 23:59:59';
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM audit_logs {$whereSql}");
        $stmtCount->execute($params);
        $totalCount = (int) $stmtCount->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT * FROM audit_logs 
            {$whereSql} 
            ORDER BY timestamp DESC 
            LIMIT :limit OFFSET :offset
        ");

        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $logs = array_map(function ($r) {
            return [
                'id' => $r['id'],
                'userId' => $r['user_id'],
                'userEmail' => $r['user_email'],
                'action' => $r['action'],
                'resourceType' => $r['resource_type'],
                'resourceId' => $r['resource_id'],
                'ipAddress' => $r['ip_address'],
                'userAgent' => $r['user_agent'],
                'details' => !empty($r['details_json']) ? json_decode($r['details_json'], true) : null,
                'timestamp' => $r['timestamp']
            ];
        }, $rows);

        $totalPages = (int) ceil($totalCount / $limit);

        Response::json([
            'status' => 'ok',
            'data' => $logs,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'totalCount' => $totalCount,
                'totalPages' => $totalPages,
                'hasNextPage' => $page < $totalPages,
                'hasPrevPage' => $page > 1
            ]
        ]);
    }

    public static function listLoginAttempts(): void
    {
        AuthMiddleware::requireRole('ADMIN');
        $pdo = Database::pdo();
        $filters = $_GET;

        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = min(100, max(1, (int) ($filters['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;

        $where = [];
        $params = [];

        if (!empty($filters['email'])) {
            $where[] = 'email LIKE :email';
            $params[':email'] = '%' . $filters['email'] . '%';
        }

        if (!empty($filters['ipAddress'])) {
            $where[] = 'ip_address = :ip';
            $params[':ip'] = $filters['ipAddress'];
        }

        if (isset($filters['successful'])) {
            $where[] = 'successful = :succ';
            $params[':succ'] = filter_var($filters['successful'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }

        if (!empty($filters['dateFrom'])) {
            $where[] = 'attempted_at >= :dfrom';
            $params[':dfrom'] = $filters['dateFrom'];
        }

        if (!empty($filters['dateTo'])) {
            $where[] = 'attempted_at <= :dto';
            $params[':dto'] = $filters['dateTo'] . ' 23:59:59';
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM login_attempts {$whereSql}");
        $stmtCount->execute($params);
        $totalCount = (int) $stmtCount->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT * FROM login_attempts 
            {$whereSql} 
            ORDER BY attempted_at DESC 
            LIMIT :limit OFFSET :offset
        ");

        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $attempts = array_map(function ($r) {
            return [
                'id' => $r['id'],
                'email' => $r['email'],
                'ipAddress' => $r['ip_address'],
                'userAgent' => $r['user_agent'],
                'successful' => (bool) $r['successful'],
                'failureReason' => $r['failure_reason'],
                'attemptedAt' => $r['attempted_at']
            ];
        }, $rows);

        $totalPages = (int) ceil($totalCount / $limit);

        Response::json([
            'status' => 'ok',
            'data' => $attempts,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'totalCount' => $totalCount,
                'totalPages' => $totalPages,
                'hasNextPage' => $page < $totalPages,
                'hasPrevPage' => $page > 1
            ]
        ]);
    }
}
