# MailCadence

App web per inviare una campagna email a un gruppo di contatti **a scaglioni**, per ridurre il
rischio di finire in spam quando si invia a molti destinatari (fino a ~1500) usando un proprio
server SMTP invece di un servizio di invio massivo esterno.

Flusso tipico: componi una email → scegli una lista di destinatari → l'app la invia in gruppi
(es. 50 contatti ogni ora, configurabile per ogni campagna) fino a esaurimento della lista.

## Funzionalità

- Anagrafica contatti con import da CSV (`email,nome`) e stato attivo/disiscritto
- Liste di destinatari (un contatto può stare in più liste)
- Campagne: oggetto + testo HTML, lista destinatari, dimensione del gruppo e intervallo in minuti
  configurabili per ogni campagna, avvio/pausa/ripresa/annullamento
- Invio scaglionato eseguito da un worker cron (`app/src/send_batch.php`), non da PHP-FPM/Apache,
  così l'invio prosegue in background indipendentemente dalle richieste HTTP
- Link di disiscrizione automatico in ogni email (pagina pubblica `unsubscribe.php`, protetta da
  un token HMAC — nessun account richiesto per disiscriversi) e header `List-Unsubscribe` /
  `List-Unsubscribe-Post` per la disiscrizione one-click supportata dai client email moderni
- Invio SMTP autonomo (nessuna dipendenza Composer) in multipart/alternative (testo + HTML), verso
  il proprio server SMTP

## Stack

PHP 8.2 + Apache, MySQL 8, Docker Compose. Nessun account "amministratore" multiplo: le
credenziali di accesso sono un'unica coppia email/password.

## Avvio (Docker su un server con rete `proxy-manager_default` già esistente)

```bash
cp .env.example .env
# genera un APP_SECRET:
openssl rand -hex 32
# compila .env con APP_SECRET, le credenziali SMTP del tuo server e le password del DB
# (ADMIN_EMAIL/ADMIN_PASSWORD_HASH puoi lasciarle vuote, vedi sotto)

docker compose up -d --build
```

Al primo avvio l'app crea da sola le tabelle nel database (vedi `database/schema.sql`), non serve
importarlo a mano. Il worker di invio gira dentro lo stesso container `app` via cron, ogni 5
minuti (vedi `app/crontab`); l'intervallo delle singole campagne (60, 120 minuti, ...) si imposta
al momento della creazione della campagna, non nel codice.

### Primo accesso

Se `ADMIN_EMAIL`/`ADMIN_PASSWORD_HASH` non sono impostate, la prima visita a qualunque pagina
mostra automaticamente `/setup.php`, dove scegli email e password dell'unico account
amministratore: vengono salvate (con password hashata) nel database. La pagina si disattiva da
sola non appena un account esiste già, quindi non resta accessibile dopo il primo utilizzo. In
alternativa puoi continuare a impostare `ADMIN_EMAIL`/`ADMIN_PASSWORD_HASH` nel `.env` prima
dell'avvio (genera l'hash con `php -r 'echo password_hash("la-tua-password", PASSWORD_DEFAULT), PHP_EOL;'`):
in quel caso la pagina di setup resta disattivata fin dal primo avvio.

Il servizio va poi esposto tramite il tuo reverse proxy (es. Nginx Proxy Manager, già collegato
via rete Docker esterna `proxy-manager_default`) puntando al container `app` sulla porta 80.

## Note anti-spam

- Usa sempre il tuo dominio/SMTP proprio con SPF/DKIM/DMARC configurati: senza questi, anche
  l'invio più lento finisce comunque nello spam
- Tieni la dimensione del gruppo e l'intervallo prudenti (es. 50 ogni 1-2 ore) soprattutto le
  prime volte che invii a un indirizzo IP/dominio nuovo
- Il link di disiscrizione è obbligatorio ed è già incluso automaticamente: i contatti che si
  disiscrivono non vengono più inclusi negli invii successivi, nemmeno se restano in una lista
