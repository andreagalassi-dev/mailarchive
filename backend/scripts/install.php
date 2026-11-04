<?php
declare(strict_types=1);

// Script di amministrazione: eseguibile solo da riga di comando, mai via web.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * scripts/install.php
 * Script di installazione automatica, verifica requisiti e gestione credenziali admin.
 * Eseguibile solo via CLI: php scripts/install.php
 */

$isCli = (php_sapi_name() === 'cli');

function output(string $msg, bool $isCli, string $type = 'info'): void {
    if ($isCli) {
        $prefix = match ($type) {
            'success' => '✅ ',
            'error' => '❌ ',
            'warning' => '⚠️  ',
            default => 'ℹ️  '
        };
        echo "{$prefix}{$msg}\n";
    } else {
        $color = match ($type) {
            'success' => '#16a34a',
            'error' => '#dc2626',
            'warning' => '#ca8a04',
            default => '#38bdf8'
        };
        echo "<div style='color: {$color}; font-family: monospace; margin: 6px 0;'>[{$type}] " . htmlspecialchars($msg) . "</div>";
    }
}

if (!$isCli) {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>MailArchive - Installazione e Configurazione</title>';
    echo '<style>
        body { font-family: system-ui, -apple-system, sans-serif; background: #0f172a; color: #f8fafc; padding: 40px 20px; line-height: 1.5; }
        .card { max-width: 680px; margin: 0 auto; background: #1e293b; padding: 32px; border-radius: 12px; border: 1px solid #334155; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
        h1 { margin-top: 0; color: #38bdf8; font-size: 1.5rem; }
        .form-box { margin-top: 24px; padding: 20px; background: #0f172a; border-radius: 8px; border: 1px solid #334155; }
        input[type="text"], input[type="password"] { width: 100%; padding: 12px; border-radius: 6px; border: 1px solid #475569; background: #1e293b; color: #fff; font-size: 1rem; box-sizing: border-box; margin-top: 8px; margin-bottom: 16px; }
        .btn { display: inline-block; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-weight: 600; cursor: pointer; border: none; font-size: 0.95rem; }
        .btn-green { background: #16a34a; color: #fff; }
        .btn-blue { background: #2563eb; color: #fff; }
    </style></head><body><div class="card"><h1>MailArchive - Configurazione Amministratore</h1>';
}

// 1. Controllo versione PHP (>= 8.1)
if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    output("Versione PHP non compatibile: " . PHP_VERSION . " (richiesto PHP 8.1 o superiore).", $isCli, 'error');
    exit(1);
}

// 2. Controllo estensioni necessarie
$requiredExtensions = ['pdo', 'openssl', 'mbstring'];
foreach ($requiredExtensions as $ext) {
    if (!extension_loaded($ext)) {
        output("Estensione PHP mancante: {$ext}", $isCli, 'error');
        exit(1);
    }
}

// 3. Controllo cartelle storage
$rootDir = dirname(__DIR__);
$storageDirs = [
    $rootDir . '/storage',
    $rootDir . '/storage/raw',
    $rootDir . '/storage/attachments',
    $rootDir . '/storage/tmp'
];

foreach ($storageDirs as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

$storageHtaccess = $rootDir . '/storage/.htaccess';
if (!file_exists($storageHtaccess)) {
    file_put_contents($storageHtaccess, "Require all denied\nDeny from all\n");
}

// 4. Inizializzazione Database
require_once $rootDir . '/api/config/Config.php';
require_once $rootDir . '/api/config/Database.php';
require_once $rootDir . '/api/core/Crypto.php';
require_once $rootDir . '/api/core/Audit.php';

try {
    \MailArchive\Config\Config::load();
    $pdo = \MailArchive\Config\Database::pdo();
    output("Connessione al database MariaDB/MySQL verificata con successo.", $isCli, 'success');

    // Applica schema SQL se necessario
    $isSqlite = \MailArchive\Config\Database::isSqlite();
    $schemaFile = $isSqlite ? $rootDir . '/database/schema-sqlite.sql' : $rootDir . '/database/schema.sql';

    if (file_exists($schemaFile)) {
        $sql = file_get_contents($schemaFile);
        if ($isSqlite) {
            $pdo->exec($sql);
        } else {
            $queries = explode(';', $sql);
            foreach ($queries as $q) {
                $trimmed = trim($q);
                if ($trimmed !== '') {
                    try { $pdo->exec($trimmed); } catch (\Throwable) {}
                }
            }
        }
    }

    $targetEmail = strtolower(trim((string) \MailArchive\Config\Config::get('INITIAL_ADMIN_EMAIL', 'admin@example.com')));

    // Gestione impostazione password personalizzata via POST o GET
    $newPassword = trim($_POST['admin_password'] ?? $_GET['admin_password'] ?? '');

    if ($newPassword !== '') {
        if (strlen($newPassword) < 8) {
            output("La password deve contenere almeno 8 caratteri.", $isCli, 'error');
        } else {
            $hash = \MailArchive\Core\Crypto::hashPassword($newPassword);
            $now = \MailArchive\Config\Database::now();

            $stmtCheck = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $stmtCheck->execute([':email' => $targetEmail]);
            $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $upd = $pdo->prepare('
                    UPDATE users 
                    SET password_hash = :hash, failed_login_attempts = 0, locked_until = NULL, is_active = 1, updated_at = :now 
                    WHERE id = :id
                ');
                $upd->execute([':hash' => $hash, ':now' => $now, ':id' => $existing['id']]);
                output("Password reimpostata con successo per {$targetEmail}!", $isCli, 'success');
                output("Account sbloccato e attivo al 100%.", $isCli, 'success');
            } else {
                $userId = \MailArchive\Config\Database::uuid();
                $ins = $pdo->prepare('
                    INSERT INTO users (id, email, password_hash, role, is_active, failed_login_attempts, totp_enabled, created_at, updated_at)
                    VALUES (:id, :email, :hash, "ADMIN", 1, 0, 0, :now, :now)
                ');
                $ins->execute([':id' => $userId, ':email' => $targetEmail, ':hash' => $hash, ':now' => $now]);
                output("Utente amministratore {$targetEmail} creato con successo!", $isCli, 'success');
            }

            if (!$isCli) {
                echo '<div style="margin: 24px 0; padding: 16px; background: rgba(22, 163, 74, 0.15); border: 1px solid #16a34a; border-radius: 8px;">';
                echo '<p style="color: #4ade80; font-weight: bold; margin: 0 0 10px 0;">🎉 Credenziali aggiornate!</p>';
                echo '<p style="margin: 4px 0;"><strong>Email:</strong> ' . htmlspecialchars($targetEmail) . '</p>';
                echo '<p style="margin: 4px 0;"><strong>Password:</strong> ' . htmlspecialchars($newPassword) . '</p>';
                echo '<p style="margin-top: 16px;"><a href="/" class="btn btn-green">Vai all\'applicazione ed Accedi</a></p>';
                echo '</div>';
            }
        }
    } else {
        // Verifica se l'utente esiste già
        $stmt = $pdo->prepare('SELECT id, email, is_active, failed_login_attempts, locked_until FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $targetEmail]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            output("Account amministratore presente: {$targetEmail}", $isCli, 'info');
            if (!empty($user['locked_until']) && $user['locked_until'] > \MailArchive\Config\Database::now()) {
                output("ATTENZIONE: L'account risultava temporaneamente bloccato per troppi tentativi errati.", $isCli, 'warning');
            }
        } else {
            // Esegui seed iniziale automatico
            require_once $rootDir . '/database/seed.php';
        }

        if (!$isCli) {
            echo '<div class="form-box">';
            echo '<h3 style="margin-top: 0; color: #38bdf8;">Imposta o Cambia la Password Amministratore</h3>';
            echo '<p style="color: #94a3b8; font-size: 0.9rem;">Scegli una password per l\'account <strong>' . htmlspecialchars($targetEmail) . '</strong> per accedere comodamente:</p>';
            echo '<form method="POST">';
            echo '<label style="font-size: 0.85rem; color: #cbd5e1;">Nuova Password (minimo 8 caratteri):</label>';
            echo '<input type="text" name="admin_password" required minlength="8" placeholder="Inserisci qui la password desiderata..." autocomplete="off" />';
            echo '<button type="submit" class="btn btn-green">Salva Password e Sblocca Account</button>';
            echo '</form>';
            echo '</div>';
            echo '<p style="margin-top: 24px;"><a href="/" class="btn btn-blue">Vai alla schermata di Login</a></p>';
        }
    }

} catch (\Throwable $e) {
    output("Errore: " . $e->getMessage(), $isCli, 'error');
}

if (!$isCli) {
    echo '</div></body></html>';
}
