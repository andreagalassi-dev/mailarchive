# MailArchive

Applicazione web self-hosted per **archiviare, consultare ed esportare i backup delle tue email**, così da non perderle mai.
*Self-hosted web app to back up, browse and export your mailboxes over IMAP.* (interfaccia in italiano)

## Funzioni

- Sincronizzazione IMAP periodica di più caselle (Posta in arrivo e Inviata), con copia grezza `.eml` e allegati
- Ricerca con filtri e visualizzatore email sicuro (HTML sanificato, iframe senza script)
- Esportazione singola o massiva in `.eml`, ZIP o `.mbox`
- Utenti con ruoli `ADMIN` / `VIEWER`, log di accesso e audit, allarmi
- Sicurezza: login con blocco account, 2FA (TOTP o codice via email), CSRF, password IMAP cifrate con AES-256-GCM, header di sicurezza

## Requisiti

- Hosting con **PHP 8.1+** (estensioni `pdo`, `pdo_mysql` o `pdo_sqlite`, `openssl`, `mbstring`, `zip`) e Apache con `mod_rewrite`
- MariaDB 10.6+ / MySQL 8+ (oppure SQLite)
- **Node.js 20+ solo sul tuo computer**, per compilare il frontend: sul server non serve

## Struttura

```
backend/    API PHP (api/), cron di sincronizzazione (cron/), schema DB (database/), .htaccess
frontend/   Interfaccia React + Vite (sorgente)
docs/       Guida all'installazione
scripts/    build-release.sh
```

## Installazione rapida

1. `./scripts/build-release.sh` crea la cartella `release/`
2. Caricane il contenuto nella document root del tuo (sotto)dominio, con HTTPS attivo
3. Copia `.env.example` in `.env` e compila i valori

Tutti i passaggi (database, primo amministratore, cron, verifiche) sono in [docs/DEPLOY.md](docs/DEPLOY.md).

## Sviluppo locale

```bash
php -S 127.0.0.1:8089 -t backend backend/scripts/dev-router.php   # API
cd frontend && npm install && npm run dev                          # interfaccia su :5173
```

## Sicurezza

Questo software gestisce posta privata: leggi [SECURITY.md](SECURITY.md) prima di metterlo online e per segnalare vulnerabilità.

## Licenza

[GNU GPL v3.0](LICENSE) — puoi usarlo e modificarlo; le versioni modificate che distribuisci devono restare GPL-3.0 con il sorgente disponibile.
