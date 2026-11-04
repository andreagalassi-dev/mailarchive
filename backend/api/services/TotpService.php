<?php
declare(strict_types=1);

namespace MailArchive\Services;

use MailArchive\Core\QrCode;

class TotpService
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Genera un nuovo segreto Base32 crittograficamente sicuro (16 o 32 caratteri)
     */
    public static function generateSecret(int $length = 16): string
    {
        $secret = '';
        $alphabetLen = strlen(self::BASE32_ALPHABET);

        for ($i = 0; $i < $length; $i++) {
            $secret .= self::BASE32_ALPHABET[random_int(0, $alphabetLen - 1)];
        }

        return $secret;
    }

    /**
     * Decodifica una stringa Base32 in formato binario
     */
    public static function base32Decode(string $base32): string
    {
        $clean = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $base32) ?? '');
        $binaryString = '';

        for ($i = 0; $i < strlen($clean); $i++) {
            $char = $clean[$i];
            $val = strpos(self::BASE32_ALPHABET, $char);
            if ($val === false) {
                continue;
            }
            $binaryString .= str_pad(decbin($val), 5, '0', STR_PAD_LEFT);
        }

        $octets = str_split($binaryString, 8);
        $result = '';

        foreach ($octets as $octet) {
            if (strlen($octet) === 8) {
                $result .= chr((int) bindec($octet));
            }
        }

        return $result;
    }

    /**
     * Calcola il codice numerico TOTP a 6 cifre per un intervallo temporale specificato
     */
    public static function calculateCode(string $secret, ?int $timeSlice = null): string
    {
        if ($timeSlice === null) {
            $timeSlice = (int) floor(time() / 30);
        }

        $secretBin = self::base32Decode($secret);

        // Contatore a 64 bit big-endian (8 byte)
        $timeData = pack('N*', 0) . pack('N*', $timeSlice);

        // Calcolo HMAC-SHA1
        $hash = hash_hmac('sha1', $timeData, $secretBin, true);

        // Dynamic Truncation (RFC 4226 / RFC 6238)
        $offset = ord($hash[19]) & 0x0f;
        $binary = (
            ((ord($hash[$offset]) & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8) |
            (ord($hash[$offset + 3]) & 0xff)
        );

        $otp = $binary % 1000000;
        return str_pad((string) $otp, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Verifica la validità del token con tolleranza di clock drift (default: window = 1, ossia ±30s)
     */
    public static function verifyToken(string $token, string $secret, int $window = 1): bool
    {
        $cleanToken = trim($token);
        if (strlen($cleanToken) !== 6 || !ctype_digit($cleanToken)) {
            return false;
        }

        $currentTimeSlice = (int) floor(time() / 30);

        for ($i = -$window; $i <= $window; $i++) {
            $calculated = self::calculateCode($secret, $currentTimeSlice + $i);
            if (hash_equals($calculated, $cleanToken)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Genera l'URL otpauth standard per Google Authenticator, Microsoft Auth, 1Password
     */
    public static function getOtpAuthUri(string $userEmail, string $secret, string $issuer = 'MailArchive'): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($userEmail);
        return "otpauth://totp/{$label}?secret={$secret}&issuer=" . rawurlencode($issuer) . "&algorithm=SHA1&digits=6&period=30";
    }

    /**
     * Genera il Data URL SVG del QR Code
     */
    public static function getQrCodeDataUrl(string $otpauthUri): string
    {
        return QrCode::svgDataUrl($otpauthUri, 240);
    }
}
