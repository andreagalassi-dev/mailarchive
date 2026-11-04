<?php
declare(strict_types=1);

namespace MailArchive\Core;

use MailArchive\Config\Database;
use MailArchive\Config\Config;
use Throwable;

class Audit
{
    public static function log(
        string $action,
        ?string $resourceType = null,
        ?string $resourceId = null,
        ?array $details = null,
        ?string $userId = null,
        ?string $userEmail = null,
        ?string $ip = null,
        ?string $userAgent = null
    ): void {
        try {
            $pdo = Database::pdo();
            $id = Database::uuid();
            $ipAddress = $ip ?? Config::getClientIp();
            $ua = $userAgent ?? Config::getUserAgent();
            $detailsJson = $details !== null ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
            $timestamp = Database::now();

            $stmt = $pdo->prepare('
                INSERT INTO audit_logs (id, user_id, user_email, action, resource_type, resource_id, ip_address, user_agent, details_json, timestamp)
                VALUES (:id, :user_id, :user_email, :action, :resource_type, :resource_id, :ip_address, :user_agent, :details_json, :timestamp)
            ');

            $stmt->execute([
                ':id' => $id,
                ':user_id' => $userId,
                ':user_email' => $userEmail,
                ':action' => $action,
                ':resource_type' => $resourceType,
                ':resource_id' => $resourceId,
                ':ip_address' => $ipAddress,
                ':user_agent' => $ua,
                ':details_json' => $detailsJson,
                ':timestamp' => $timestamp
            ]);
        } catch (Throwable) {
            // Silently absorb to avoid interrupting the main business flow
        }
    }
}
