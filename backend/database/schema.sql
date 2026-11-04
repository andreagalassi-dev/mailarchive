-- ==============================================================================
-- MailArchive - Database Schema (MariaDB 10.6+ / MySQL 8.0+)
-- ==============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Tabella: users (Account con ruoli RBAC e 2FA)
CREATE TABLE IF NOT EXISTS `users` (
    `id` VARCHAR(36) NOT NULL,
    `email` VARCHAR(190) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` VARCHAR(20) NOT NULL DEFAULT 'VIEWER',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `failed_login_attempts` INT NOT NULL DEFAULT 0,
    `locked_until` DATETIME NULL,
    `totp_secret` VARCHAR(255) NULL,
    `totp_enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_users_email` (`email`),
    INDEX `idx_users_role` (`role`),
    INDEX `idx_users_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tabella: sessions (Sessioni attive con token hash SHA-256 e rotazione)
CREATE TABLE IF NOT EXISTS `sessions` (
    `id` VARCHAR(36) NOT NULL,
    `user_id` VARCHAR(36) NOT NULL,
    `session_token_hash` VARCHAR(64) NOT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `user_agent` TEXT NULL,
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_active_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_sessions_token_hash` (`session_token_hash`),
    INDEX `idx_sessions_user_id` (`user_id`),
    INDEX `idx_sessions_expires_at` (`expires_at`),
    CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Tabella: login_attempts (Tentativi di accesso per rate-limiting e lockout)
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id` VARCHAR(36) NOT NULL,
    `email` VARCHAR(190) NOT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `user_agent` TEXT NULL,
    `successful` TINYINT(1) NOT NULL,
    `failure_reason` VARCHAR(100) NULL,
    `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_login_attempts_email_date` (`email`, `attempted_at`),
    INDEX `idx_login_attempts_ip_date` (`ip_address`, `attempted_at`),
    INDEX `idx_login_attempts_date` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Tabella: audit_logs (Registro eventi immutabile applicativo)
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` VARCHAR(36) NOT NULL,
    `user_id` VARCHAR(36) NULL,
    `user_email` VARCHAR(190) NULL,
    `action` VARCHAR(100) NOT NULL,
    `resource_type` VARCHAR(50) NULL,
    `resource_id` VARCHAR(100) NULL,
    `ip_address` VARCHAR(45) NULL,
    `user_agent` TEXT NULL,
    `details_json` LONGTEXT NULL,
    `timestamp` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_audit_logs_user_id` (`user_id`),
    INDEX `idx_audit_logs_action` (`action`),
    INDEX `idx_audit_logs_resource` (`resource_type`, `resource_id`),
    INDEX `idx_audit_logs_timestamp` (`timestamp`),
    CONSTRAINT `fk_audit_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Tabella: mailboxes (Caselle email e credenziali cifrate AES-256-GCM)
CREATE TABLE IF NOT EXISTS `mailboxes` (
    `id` VARCHAR(36) NOT NULL,
    `name` VARCHAR(190) NOT NULL,
    `email_address` VARCHAR(190) NOT NULL,
    `provider` VARCHAR(50) NOT NULL DEFAULT 'CUSTOM',
    `auth_type` VARCHAR(50) NOT NULL DEFAULT 'PASSWORD',
    `status` VARCHAR(50) NOT NULL DEFAULT 'ACTIVE',
    `incoming_host` VARCHAR(190) NOT NULL DEFAULT '',
    `incoming_port` INT NOT NULL DEFAULT 993,
    `incoming_encryption` VARCHAR(20) NOT NULL DEFAULT 'SSL',
    `outgoing_host` VARCHAR(190) NOT NULL DEFAULT '',
    `outgoing_port` INT NOT NULL DEFAULT 465,
    `outgoing_encryption` VARCHAR(20) NOT NULL DEFAULT 'SSL',
    `host` VARCHAR(190) NOT NULL,
    `port` INT NOT NULL DEFAULT 993,
    `encryption` VARCHAR(20) NOT NULL DEFAULT 'TLS',
    `username` VARCHAR(190) NOT NULL,
    `encrypted_password` TEXT NOT NULL,
    `sync_frequency_minutes` INT NOT NULL DEFAULT 60,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_sync_at` DATETIME NULL,
    `last_sync_status` VARCHAR(50) NULL,
    `last_error_message` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_mailboxes_email` (`email_address`),
    INDEX `idx_mailboxes_is_active` (`is_active`),
    INDEX `idx_mailboxes_provider` (`provider`),
    INDEX `idx_mailboxes_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Tabella: backup_jobs (Regole di schedulazione backup)
CREATE TABLE IF NOT EXISTS `backup_jobs` (
    `id` VARCHAR(36) NOT NULL,
    `mailbox_id` VARCHAR(36) NOT NULL,
    `schedule_cron` VARCHAR(100) NOT NULL DEFAULT '0 * * * *',
    `status` VARCHAR(50) NOT NULL DEFAULT 'IDLE',
    `last_run_at` DATETIME NULL,
    `next_run_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_backup_jobs_mailbox_id` (`mailbox_id`),
    INDEX `idx_backup_jobs_status` (`status`),
    CONSTRAINT `fk_backup_jobs_mailbox` FOREIGN KEY (`mailbox_id`) REFERENCES `mailboxes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Tabella: backup_runs (Storico esecuzioni backup e statistiche)
CREATE TABLE IF NOT EXISTS `backup_runs` (
    `id` VARCHAR(36) NOT NULL,
    `job_id` VARCHAR(36) NULL,
    `mailbox_id` VARCHAR(36) NOT NULL,
    `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at` DATETIME NULL,
    `status` VARCHAR(50) NOT NULL DEFAULT 'RUNNING',
    `emails_imported` INT NOT NULL DEFAULT 0,
    `emails_skipped` INT NOT NULL DEFAULT 0,
    `total_size_bytes` BIGINT NOT NULL DEFAULT 0,
    `error_message` TEXT NULL,
    `log_output` LONGTEXT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_backup_runs_mailbox_started` (`mailbox_id`, `started_at`),
    INDEX `idx_backup_runs_job_id` (`job_id`),
    INDEX `idx_backup_runs_status` (`status`),
    CONSTRAINT `fk_backup_runs_job` FOREIGN KEY (`job_id`) REFERENCES `backup_jobs` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_backup_runs_mailbox` FOREIGN KEY (`mailbox_id`) REFERENCES `mailboxes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Tabella: emails (Messaggi archiviati e metadati RFC 5322)
CREATE TABLE IF NOT EXISTS `emails` (
    `id` VARCHAR(36) NOT NULL,
    `mailbox_id` VARCHAR(36) NOT NULL,
    `message_id` VARCHAR(190) NULL,
    `thread_id` VARCHAR(190) NULL,
    `from_address` VARCHAR(255) NOT NULL,
    `from_name` VARCHAR(255) NULL,
    `to_addresses` TEXT NOT NULL,
    `cc_addresses` TEXT NULL,
    `bcc_addresses` TEXT NULL,
    `subject` VARCHAR(255) NOT NULL,
    `date_sent` DATETIME NOT NULL,
    `date_archived` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `body_text` LONGTEXT NULL,
    `body_html_sanitized` LONGTEXT NULL,
    `has_attachments` TINYINT(1) NOT NULL DEFAULT 0,
    `size_bytes` INT NOT NULL DEFAULT 0,
    `raw_storage_path` VARCHAR(255) NULL,
    `is_spam` TINYINT(1) NOT NULL DEFAULT 0,
    `is_flagged` TINYINT(1) NOT NULL DEFAULT 0,
    `folder` VARCHAR(50) NOT NULL DEFAULT 'INBOX',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_emails_mailbox_id` (`mailbox_id`),
    INDEX `idx_emails_message_id` (`message_id`),
    INDEX `idx_emails_mailbox_message` (`mailbox_id`, `message_id`),
    INDEX `idx_emails_mailbox_folder` (`mailbox_id`, `folder`),
    INDEX `idx_emails_date_sent` (`date_sent`),
    INDEX `idx_emails_from_address` (`from_address`),
    INDEX `idx_emails_subject` (`subject`),
    INDEX `idx_emails_folder` (`folder`),
    CONSTRAINT `fk_emails_mailbox` FOREIGN KEY (`mailbox_id`) REFERENCES `mailboxes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Tabella: attachments (Allegati con SHA-256 e percorsi isolati)
CREATE TABLE IF NOT EXISTS `attachments` (
    `id` VARCHAR(36) NOT NULL,
    `email_id` VARCHAR(36) NOT NULL,
    `filename` VARCHAR(255) NOT NULL,
    `content_type` VARCHAR(190) NOT NULL DEFAULT 'application/octet-stream',
    `size_bytes` INT NOT NULL DEFAULT 0,
    `content_id` VARCHAR(190) NULL,
    `storage_path` VARCHAR(255) NOT NULL,
    `checksum_sha256` VARCHAR(64) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_attachments_email_id` (`email_id`),
    INDEX `idx_attachments_checksum` (`checksum_sha256`),
    CONSTRAINT `fk_attachments_email` FOREIGN KEY (`email_id`) REFERENCES `emails` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Tabella: system_alerts (Allarmi di sistema e notifiche)
CREATE TABLE IF NOT EXISTS `system_alerts` (
    `id` VARCHAR(36) NOT NULL,
    `severity` VARCHAR(20) NOT NULL DEFAULT 'INFO',
    `category` VARCHAR(50) NOT NULL DEFAULT 'SYSTEM',
    `title` VARCHAR(255) NOT NULL,
    `message` TEXT NOT NULL,
    `is_resolved` TINYINT(1) NOT NULL DEFAULT 0,
    `resolved_at` DATETIME NULL,
    `resolved_by_user_id` VARCHAR(36) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_system_alerts_severity` (`severity`),
    INDEX `idx_system_alerts_resolved` (`is_resolved`),
    INDEX `idx_system_alerts_category` (`category`),
    INDEX `idx_system_alerts_created_at` (`created_at`),
    CONSTRAINT `fk_system_alerts_user` FOREIGN KEY (`resolved_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Tabella: settings (Configurazioni chiave/valore)
CREATE TABLE IF NOT EXISTS `settings` (
    `id` VARCHAR(36) NOT NULL,
    `key` VARCHAR(100) NOT NULL,
    `value_json` LONGTEXT NOT NULL,
    `description` TEXT NULL,
    `category` VARCHAR(50) NOT NULL DEFAULT 'GENERAL',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_settings_key` (`key`),
    INDEX `idx_settings_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Tabella: two_factor_challenges (Sfide pre-autenticazione 2FA)
CREATE TABLE IF NOT EXISTS `two_factor_challenges` (
    `id` VARCHAR(36) NOT NULL,
    `token_hash` VARCHAR(64) NOT NULL,
    `user_id` VARCHAR(36) NOT NULL,
    `email_otp_code` VARCHAR(10) NULL,
    `email_otp_expires_at` DATETIME NULL,
    `attempts` INT NOT NULL DEFAULT 0,
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_2fa_token_hash` (`token_hash`),
    INDEX `idx_2fa_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
