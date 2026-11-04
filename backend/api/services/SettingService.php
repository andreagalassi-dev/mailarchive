<?php
declare(strict_types=1);

namespace MailArchive\Services;

use MailArchive\Config\Database;
use MailArchive\Core\Audit;
use PDO;
use RuntimeException;

class SettingService
{
    public static function listSettings(?string $category = null): array
    {
        $pdo = Database::pdo();
        $sql = 'SELECT * FROM settings';
        $params = [];

        if ($category !== null && $category !== '') {
            $sql .= ' WHERE category = :cat';
            $params[':cat'] = strtoupper($category);
        }

        $sql .= ' ORDER BY `key` ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $settings = [];
        foreach ($rows as $row) {
            $val = json_decode($row['value_json'], true);
            $settings[] = [
                'id' => $row['id'],
                'key' => $row['key'],
                'value' => $val !== null ? $val : $row['value_json'],
                'valueJson' => $row['value_json'],
                'description' => $row['description'],
                'category' => $row['category'],
                'updatedAt' => $row['updated_at']
            ];
        }

        return $settings;
    }

    public static function getSetting(string $key): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM settings WHERE `key` = :k LIMIT 1');
        $stmt->execute([':k' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('Impostazione non trovata');
        }

        $val = json_decode($row['value_json'], true);
        return [
            'id' => $row['id'],
            'key' => $row['key'],
            'value' => $val !== null ? $val : $row['value_json'],
            'valueJson' => $row['value_json'],
            'description' => $row['description'],
            'category' => $row['category'],
            'updatedAt' => $row['updated_at']
        ];
    }

    public static function setSetting(string $key, array $input, array $actor): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT id FROM settings WHERE `key` = :k LIMIT 1');
        $stmt->execute([':k' => $key]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        $valJson = is_string($input['value'] ?? null)
            ? (json_decode((string) $input['value']) !== null ? (string) $input['value'] : json_encode($input['value']))
            : json_encode($input['value'] ?? $input['valueJson'] ?? '');

        $category = strtoupper((string) ($input['category'] ?? 'GENERAL'));
        $description = isset($input['description']) ? (string) $input['description'] : null;
        $now = Database::now();

        if ($existing) {
            $stmtUpd = $pdo->prepare('
                UPDATE settings 
                SET value_json = :v, description = COALESCE(:desc, description), category = :cat, updated_at = :now 
                WHERE id = :id
            ');
            $stmtUpd->execute([
                ':v' => $valJson,
                ':desc' => $description,
                ':cat' => $category,
                ':now' => $now,
                ':id' => $existing['id']
            ]);
            Audit::log('SETTING_UPDATED', 'SETTING', $key, null, $actor['id'] ?? null, $actor['email'] ?? null);
        } else {
            $id = Database::uuid();
            $stmtIns = $pdo->prepare('
                INSERT INTO settings (id, `key`, value_json, description, category, updated_at)
                VALUES (:id, :k, :v, :desc, :cat, :now)
            ');
            $stmtIns->execute([
                ':id' => $id,
                ':k' => $key,
                ':v' => $valJson,
                ':desc' => $description,
                ':cat' => $category,
                ':now' => $now
            ]);
            Audit::log('SETTING_CREATED', 'SETTING', $key, null, $actor['id'] ?? null, $actor['email'] ?? null);
        }

        return self::getSetting($key);
    }

    public static function deleteSetting(string $key, array $actor): array
    {
        $pdo = Database::pdo();
        $existing = self::getSetting($key);

        $stmt = $pdo->prepare('DELETE FROM settings WHERE `key` = :k');
        $stmt->execute([':k' => $key]);

        Audit::log('SETTING_DELETED', 'SETTING', $key, null, $actor['id'] ?? null, $actor['email'] ?? null);

        return $existing;
    }
}
