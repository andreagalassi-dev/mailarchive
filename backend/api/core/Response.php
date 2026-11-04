<?php
declare(strict_types=1);

namespace MailArchive\Core;

class Response
{
    private static array $booleanFields = [
        'is_active', 'isActive',
        'totp_enabled', 'totpEnabled',
        'successful',
        'has_attachments', 'hasAttachments',
        'is_spam', 'isSpam',
        'is_flagged', 'isFlagged',
        'is_resolved', 'isResolved'
    ];

    public static function json(mixed $data, int $status = 200, array $headers = []): void
    {
        http_response_code($status);

        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');

        foreach ($headers as $name => $value) {
            header("{$name}: {$value}");
        }

        $formatted = self::formatData($data);
        echo json_encode($formatted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function error(string $message, int $status = 400, mixed $details = null): void
    {
        $payload = ['error' => $message];
        if ($details !== null) {
            $payload['details'] = $details;
        }
        self::json($payload, $status);
    }

    public static function formatData(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }

        $isAssoc = array_keys($data) !== range(0, count($data) - 1);
        $result = [];

        foreach ($data as $key => $value) {
            $formattedValue = is_array($value) ? self::formatData($value) : $value;

            if ($isAssoc && is_string($key)) {
                // Convert boolean integers
                if (in_array($key, self::$booleanFields, true) && ($value === 1 || $value === 0 || $value === '1' || $value === '0')) {
                    $formattedValue = (bool) $value;
                }

                $camelKey = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));
                $result[$camelKey] = $formattedValue;
            } else {
                $result[$key] = $formattedValue;
            }
        }

        return $result;
    }
}
