# Installazione

Guida per un hosting condiviso con PHP/Apache (es. Plesk). Sostituisci `mailarchive.example.com` con il tuo dominio.

## 1. Prerequisiti sul server

- PHP 8.1+ con `pdo`, `pdo_mysql` (o `pdo_sqlite`), `openssl`, `mbstring`, `zip`; `memory_limit` ≥ 128M
- Apache con `mod_rewrite` e `mod_headers`
- Certificato HTTPS valido con reindirizzamento da HTTP (e HSTS, se possibile)
- Un database MariaDB/MySQL con utente dedicato, charset `utf8mb4`

## 2. Compila e carica

Sul tuo computer: `./scripts/build-release.sh`, poi carica il contenuto di `release/` nella document root.

Controlla che sul server esistano `storage/` (scrivibile da PHP) e `storage/.htaccess`.

## 3. Configura `.env`

Copia `.env.example` in `.env` **sul server** e compilalo. Genera ogni segreto con `openssl rand -hex 32`
(valori diversi tra loro). Non committare né condividere mai questo file e conserva `ENCRYPTION_KEY`:
senza non puoi più leggere le password IMAP salvate.

Se l'app sta dietro un proxy o un CDN, imposta `TRUSTED_PROXIES` con gli IP del proxy, altrimenti il
blocco per IP vede l'indirizzo del proxy invece di quello del visitatore.

## 4. Database e primo amministratore

Gli script di amministrazione funzionano **solo da riga di comando** (non sono raggiungibili dal web).
Da SSH, oppure da un'attività pianificata una tantum del pannello (tipo "esegui script PHP"):

```bash
mysql -u UTENTE -p NOME_DB < database/schema.sql
php scripts/install.php                                   # verifica requisiti e crea l'admin
php database/seed.php --password="UNA_PASSWORD_LUNGA" --reset   # opzionale: imposta/reimposta la password
```

Se non indichi una password ne viene generata una casuale e mostrata a schermo una sola volta.

**Dopo l'installazione elimina dal server la cartella `scripts/`** (`install.php` non serve più).

## 5. Sincronizzazione automatica

Crea un'attività pianificata (cron) ogni 5–15 minuti:

```
php -f /percorso/della/docroot/cron/sync.php
```

## 6. Verifiche

```bash
curl -i https://mailarchive.example.com/health        # 200 {"status":"ok"}
curl -i https://mailarchive.example.com/.env          # 403
curl -i https://mailarchive.example.com/storage/      # 403
curl -i https://mailarchive.example.com/scripts/install.php   # 403
```

Al primo accesso configura il secondo fattore (TOTP) dalle impostazioni.
