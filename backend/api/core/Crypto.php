<?php
declare(strict_types=1);

namespace MailArchive\Core;

use MailArchive\Config\Config;
use RuntimeException;

class Crypto
{
    private const BCRYPT_COST = 12;

    /**
     * Hashing sicuro con bcrypt (cost factor 12), identico al backend Node.js (bcryptjs)
     */
    public static function hashPassword(string $plainText): string
    {
        return password_hash($plainText, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
    }

    /**
     * Verifica password a confronto costante con hash memorizzato
     */
    public static function verifyPassword(string $plainText, string $hash): bool
    {
        return password_verify($plainText, $hash);
    }

    /**
     * Cifratura bidirezionale autenticata AES-256-GCM
     * Formato di memorizzazione nel DB: iv_hex:authTag_hex:ciphertext_hex
     */
    public static function encryptSecret(string $plainText, ?string $encryptionKey = null): string
    {
        $key = $encryptionKey ?? (string) Config::get('ENCRYPTION_KEY', '');
        if ($key === '') {
            throw new RuntimeException('ENCRYPTION_KEY non configurata o vuota.');
        }

        // Derivazione chiave a 256 bit binaria (32 byte)
        $keyBin = hash('sha256', $key, true);
        $iv = random_bytes(16);
        $tag = '';

        $cipherRaw = openssl_encrypt(
            $plainText,
            'aes-256-gcm',
            $keyBin,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if ($cipherRaw === false) {
            throw new RuntimeException('Errore durante la cifratura AES-256-GCM: ' . openssl_error_string());
        }

        return bin2hex($iv) . ':' . bin2hex($tag) . ':' . bin2hex($cipherRaw);
    }

    /**
     * Decifratura autenticata AES-256-GCM con verifica dell'Auth Tag
     */
    public static function decryptSecret(string $cipherBundle, ?string $encryptionKey = null): string
    {
        $parts = explode(':', $cipherBundle);
        if (count($parts) !== 3) {
            throw new RuntimeException('Formato del testo cifrato non valido (atteso iv:tag:ciphertext).');
        }

        $key = $encryptionKey ?? (string) Config::get('ENCRYPTION_KEY', '');
        if ($key === '') {
            throw new RuntimeException('ENCRYPTION_KEY non configurata o vuota.');
        }

        $keyBin = hash('sha256', $key, true);
        $iv = hex2bin($parts[0]);
        $tag = hex2bin($parts[1]);
        $cipherRaw = hex2bin($parts[2]);

        if ($iv === false || $tag === false || $cipherRaw === false) {
            throw new RuntimeException('Errore nella decodifica esadecimale dei parametri crittografici.');
        }

        $decrypted = openssl_decrypt(
            $cipherRaw,
            'aes-256-gcm',
            $keyBin,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($decrypted === false) {
            throw new RuntimeException('Decifratura fallita: integrità del messaggio non valida o chiave errata.');
        }

        return $decrypted;
    }

    /**
     * Genera un token crittografico casuale (es. per sessioni, tempToken)
     */
    public static function generateSecureToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /**
     * Hashing SHA-256 per memorizzare i token nel database senza esporli in chiaro
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
