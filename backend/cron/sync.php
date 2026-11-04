<?php
declare(strict_types=1);

/**
 * cron/sync.php
 * Script CLI autonomo per la sincronizzazione periodica IMAP
 * Configurato nei Task Pianificati (Cron Jobs) di Plesk:
 * php -f /path/to/your/docroot/cron/sync.php
 */

// 1. Registrazione Autoloader
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
use MailArchive\Services\ImapSyncService;
use MailArchive\Services\AlertService;

// Verifica se eseguito da CLI o tramite token web autorizzato
$isCli = (php_sapi_name() === 'cli' || empty($_SERVER['REMOTE_ADDR']));
if (!$isCli) {
    Config::load();
    $cronSecret = (string) Config::get('CRON_SECRET', Config::get('SESSION_SECRET', ''));
    $tokenParam = $_GET['cron_token'] ?? $_GET['token'] ?? '';
    if ($cronSecret === '' || !hash_equals($cronSecret, (string) $tokenParam)) {
        http_response_code(403);
        echo "403 Forbidden: Richiesta non autorizzata\n";
        exit(1);
    }
}

echo "================================================================================\n";
echo "🕒 [MailArchive CRON] Inizio processo di sincronizzazione: " . date('Y-m-d H:i:s') . "\n";
echo "================================================================================\n";

// Concurrency lock basato su file per impedire sovrapposizioni di cron job
$storageDir = Config::getStorageDir();
$tmpDir = $storageDir . DIRECTORY_SEPARATOR . 'tmp';
if (!is_dir($tmpDir)) {
    @mkdir($tmpDir, 0755, true);
}

$lockFile = $tmpDir . DIRECTORY_SEPARATOR . 'cron_sync.lock';
$lockHandle = fopen($lockFile, 'c+');

if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "⚠️  [MailArchive CRON] Un'altra istanza di sincronizzazione è già in esecuzione. Uscita.\n";
    exit(0);
}

try {
    $pdo = Database::pdo();
    $forceAll = in_array('--all', $argv ?? [], true) || in_array('--force', $argv ?? [], true);

    // Seleziona tutte le caselle attive
    $stmt = $pdo->query('
        SELECT id, name, email_address, sync_frequency_minutes, last_sync_at 
        FROM mailboxes 
        WHERE is_active = 1
        ORDER BY CASE WHEN last_sync_at IS NULL THEN 0 ELSE 1 END, last_sync_at ASC
    ');
    $mailboxes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "📋 Trovate " . count($mailboxes) . " caselle email attive configurate.\n\n";

    $systemActor = [
        'id' => 'cron-system',
        'email' => 'cron@localhost',
        'ip' => '127.0.0.1',
        'userAgent' => 'Plesk-Cron/1.0'
    ];

    $processed = 0;
    $imported = 0;
    $errors = 0;

    foreach ($mailboxes as $mb) {
        $shouldSync = $forceAll;

        if (!$shouldSync) {
            if (empty($mb['last_sync_at'])) {
                $shouldSync = true;
            } else {
                $freqMin = max(5, (int) $mb['sync_frequency_minutes']);
                $lastTs = strtotime($mb['last_sync_at']);
                if ((time() - $lastTs) >= ($freqMin * 60)) {
                    $shouldSync = true;
                }
            }
        }

        if (!$shouldSync) {
            echo "⏭️  [SKIPPED] Casella {$mb['email_address']}: sincronizzata di recente ({$mb['last_sync_at']}).\n";
            continue;
        }

        echo "🔄 [SYNCING] Avvio per {$mb['email_address']} (intervallo: {$mb['sync_frequency_minutes']} min)...\n";
        $processed++;

        // Cerca backup_job associato
        $stmtJob = $pdo->prepare('SELECT id FROM backup_jobs WHERE mailbox_id = :mid LIMIT 1');
        $stmtJob->execute([':mid' => $mb['id']]);
        $jobId = $stmtJob->fetchColumn() ?: null;

        $res = ImapSyncService::syncMailbox($mb['id'], $jobId ? (string) $jobId : null, $systemActor);

        if ($res['success']) {
            $imported += (int) $res['emailsImported'];
            echo "   ✅ Successo: {$res['emailsImported']} importate, {$res['emailsSkipped']} saltate, {$res['totalSizeBytes']} byte.\n";
        } else {
            $errors++;
            echo "   ❌ Errore: " . ($res['error'] ?? 'Sconosciuto') . "\n";
        }
    }

    // =========================================================================
    // 🐕 Watchdog Diagnostico e Manutenzione (Equivalente watchdog.service.ts)
    // =========================================================================
    echo "\n🐕 [Watchdog] Esecuzione controlli proattivi di sistema...\n";

    // 1. Sblocco job rimasti congelati in RUNNING da oltre 45 minuti
    $stuckThreshold = gmdate('Y-m-d H:i:s', time() - (45 * 60));
    $stmtStuck = $pdo->prepare('
        UPDATE backup_jobs 
        SET status = "FAILED", updated_at = :now 
        WHERE status = "RUNNING" AND updated_at < :thresh
    ');
    $stmtStuck->execute([':now' => Database::now(), ':thresh' => $stuckThreshold]);
    $stuckJobsCount = $stmtStuck->rowCount();
    if ($stuckJobsCount > 0) {
        echo "   ⚠️ Sbloccati {$stuckJobsCount} job di backup rimasti in RUNNING da oltre 45 minuti.\n";
        AlertService::createAlert('WARNING', 'BACKUP', 'Job di backup bloccato', "Il Watchdog ha sbloccato {$stuckJobsCount} job rimasti congelati in RUNNING.");
    } else {
        echo "   ✅ Nessun job di backup bloccato.\n";
    }

    // 2. Controllo spazio su disco
    $freeBytes = @disk_free_space($storageDir);
    if ($freeBytes !== false) {
        $freeMb = round($freeBytes / (1024 * 1024));
        if ($freeMb < 1000) { // Meno di 1GB rimanente
            echo "   ⚠️ Spazio disco in esaurimento: {$freeMb} MB liberi!\n";
            AlertService::createAlert('CRITICAL', 'STORAGE', 'Spazio disco in esaurimento', "Lo spazio rimanente sulla partizione storage è di soli {$freeMb} MB.");
        } else {
            echo "   ✅ Spazio disco sufficiente ({$freeMb} MB disponibili).\n";
        }
    }

    // 3. Controllo caselle stale (non sincronizzate da oltre 24 ore)
    $staleThreshold = gmdate('Y-m-d H:i:s', time() - (24 * 3600));
    $stmtStale = $pdo->prepare('
        SELECT email_address FROM mailboxes 
        WHERE is_active = 1 AND (last_sync_at < :thresh OR (last_sync_at IS NULL AND created_at < :thresh))
    ');
    $stmtStale->execute([':thresh' => $staleThreshold]);
    $staleMailboxes = $stmtStale->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($staleMailboxes)) {
        $staleList = implode(', ', $staleMailboxes);
        echo "   ⚠️ Caselle non sincronizzate da oltre 24h: {$staleList}\n";
    } else {
        echo "   ✅ Tutte le caselle attive sono sincronizzate regolarmente.\n";
    }

    echo "\n================================================================================\n";
    echo "🏁 [MailArchive CRON] Completato: " . date('Y-m-d H:i:s') . "\n";
    echo "   - Caselle elaborate: {$processed}\n";
    echo "   - Nuove email importate: {$imported}\n";
    echo "   - Errori: {$errors}\n";
    echo "================================================================================\n";

} catch (Throwable $e) {
    echo "💥 [FATAL CRON ERROR] " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}
