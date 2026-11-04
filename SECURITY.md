# Sicurezza

MailArchive custodisce posta elettronica e credenziali IMAP: trattalo come un servizio sensibile.

## Prima di metterlo online

- Usa **HTTPS** e tieni `REQUIRE_2FA=true`
- Genera segreti casuali e distinti in `.env`; il file `.env` non deve mai essere pubblico né committato
- Elimina `scripts/` dal server dopo l'installazione
- Fai backup della cartella `storage/` e di `ENCRYPTION_KEY`
- Considera di limitare l'accesso al sottodominio per IP (firewall / `.htaccess`) se possibile

## Segnalare una vulnerabilità

Non aprire una issue pubblica. Usa la funzione **"Report a vulnerability"** nella scheda *Security*
del repository GitHub (Private vulnerability reporting). Cerchiamo di rispondere entro 7 giorni.
