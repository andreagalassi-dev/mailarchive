<?php
declare(strict_types=1);

// Script di amministrazione: eseguibile solo da riga di comando, mai via web.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * database/seed.php
 * Script CLI per inizializzare l'utente amministratore e le impostazioni predefinite
 * Uso:
 *   php database/seed.php
 *   php database/seed.php --password="MiaPasswordSicura123!" --reset
 */

spl_autoload_register(function (string $class) {
    $prefix = 'MailArchive\\';
    if (!str_starts_with($class, $prefix)) return;
    $relative = substr($class, strlen($prefix));
    $parts = explode('\\', $relative);
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR;
    if (count($parts) > 1) {
        $path .= strtolower($parts[0]) . DIRECTORY_SEPARATOR . $parts[1] . '.php';
    } else {
        $path .= $parts[0] . '.php';
    }
    if (file_exists($path)) {
        require_once $path;
    }
});

use MailArchive\Config\Config;
use MailArchive\Config\Database;
use MailArchive\Core\Crypto;
use MailArchive\Core\Audit;

Config::load();
$pdo = Database::pdo();

$targetEmail = strtolower(trim((string) Config::get('INITIAL_ADMIN_EMAIL', 'admin@example.com')));
echo "\n🔍 [Seed Admin] Verifica account per: {$targetEmail}...\n";

$stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
$stmt->execute([':email' => $targetEmail]);
$existingUser = $stmt->fetch(PDO::FETCH_ASSOC);

$isReset = in_array('--reset', $argv ?? [], true) || in_array('--force', $argv ?? [], true);

if ($existingUser && !$isReset) {
    echo "\n⚠️  [Seed Admin] L'utente {$targetEmail} esiste già nel database:\n";
    echo "   - ID: {$existingUser['id']}\n";
    echo "   - Ruolo: {$existingUser['role']}\n";
    echo "   - Attivo: " . ($existingUser['is_active'] ? 'Sì' : 'No') . "\n";
    echo "   - Creato il: {$existingUser['created_at']}\n";
    echo "\n💡 Per reimpostare la password di questo account, esegui:\n";
    echo "   php database/seed.php --password=\"NuovaPassword123!\" --reset\n\n";
    exit(0);
}

// Lettura password da CLI o generazione casuale
$adminPassword = '';
$wasGenerated = false;

foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--password=')) {
        $adminPassword = substr($arg, 11);
    }
}

if ($adminPassword === '') {
    $envPass = (string) Config::get('INITIAL_ADMIN_PASSWORD', '');
    if ($envPass !== '') {
        $adminPassword = $envPass;
    }
}

if ($adminPassword === '') {
    $wasGenerated = true;
    $adminPassword = Crypto::generateSecureToken(12);
}

if (strlen($adminPassword) < 8) {
    echo "❌ Errore: La password deve contenere almeno 8 caratteri.\n";
    exit(1);
}

echo "🔒 [Seed Admin] Calcolo hash crittografico Bcrypt (cost 12)...\n";
$hash = Crypto::hashPassword($adminPassword);
$now = Database::now();

if ($existingUser) {
    $upd = $pdo->prepare('
        UPDATE users 
        SET password_hash = :hash, failed_login_attempts = 0, locked_until = NULL, is_active = 1, updated_at = :now 
        WHERE id = :id
    ');
    $upd->execute([':hash' => $hash, ':now' => $now, ':id' => $existingUser['id']]);
    $userId = $existingUser['id'];
    echo "🔄 [Seed Admin] Password reimpostata con successo per {$targetEmail}\n";
} else {
    $userId = Database::uuid();
    $ins = $pdo->prepare('
        INSERT INTO users (id, email, password_hash, role, is_active, failed_login_attempts, totp_enabled, created_at, updated_at)
        VALUES (:id, :email, :hash, "ADMIN", 1, 0, 0, :now, :now)
    ');
    $ins->execute([
        ':id' => $userId,
        ':email' => $targetEmail,
        ':hash' => $hash,
        ':now' => $now
    ]);
    echo "✅ [Seed Admin] Utente amministratore creato con successo\n";
}

Audit::log($existingUser ? 'ADMIN_PASSWORD_RESET' : 'ADMIN_INITIAL_SEED', 'USER', $userId, [
    'email' => $targetEmail,
    'role' => 'ADMIN',
    'wasGenerated' => $wasGenerated
], $userId, $targetEmail, '127.0.0.1', 'CLI/seed');

// Seed impostazioni iniziali
$settingsToSeed = [
    'general.app_name' => ['value' => 'MailArchive', 'desc' => 'Nome dell\'applicazione', 'cat' => 'GENERAL'],
    'security.login_max_attempts' => ['value' => 5, 'desc' => 'Tentativi massimi di login prima del blocco', 'cat' => 'SECURITY'],
    'security.lockout_minutes' => ['value' => 15, 'desc' => 'Minuti di blocco account', 'cat' => 'SECURITY'],
    'backup.default_frequency_minutes' => ['value' => 60, 'desc' => 'Intervallo predefinito sincronizzazione caselle', 'cat' => 'BACKUP']
];

if (Database::isSqlite()) {
    $stmtSet = $pdo->prepare('
        INSERT OR IGNORE INTO settings (id, "key", value_json, description, category, updated_at)
        VALUES (:id, :k, :v, :desc, :cat, :now)
    ');
} else {
    $stmtSet = $pdo->prepare('
        INSERT INTO settings (id, `key`, value_json, description, category, updated_at)
        VALUES (:id, :k, :v, :desc, :cat, :now)
        ON DUPLICATE KEY UPDATE updated_at = updated_at
    ');
}

foreach ($settingsToSeed as $k => $info) {
    try {
        $stmtSet->execute([
            ':id' => Database::uuid(),
            ':k' => $k,
            ':v' => json_encode($info['value']),
            ':desc' => $info['desc'],
            ':cat' => $info['cat'],
            ':now' => $now
        ]);
    } catch (\Throwable) {}
}

echo "\n================================================================================\n";
echo "✅ AMMINISTRATORE CONFIGURATO CON SUCCESSO\n";
echo "================================================================================\n";
echo "Email:   {$targetEmail}\n";
echo "Ruolo:   ADMIN\n";
echo "ID:      {$userId}\n";

if ($wasGenerated) {
    echo "--------------------------------------------------------------------------------\n";
    echo "Password generata: {$adminPassword}\n";
    echo "--------------------------------------------------------------------------------\n";
    echo "⚠️  IMPORTANTE: Salva adesso questa password nel tuo gestore password!\n";
} else {
    echo "Password: (fornita manualmente via CLI / .env)\n";
}
echo "================================================================================\n\n";
