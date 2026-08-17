#!/bin/bash
set -e

# I job definiti in /etc/cron.d NON ereditano le variabili d'ambiente passate al container (a
# differenza del processo Apache): cron le esegue con un ambiente proprio, quasi vuoto. Le
# scriviamo qui in un file che il crontab sorgenta prima di lanciare il worker (vedi app/crontab),
# così DB_HOST/SMTP_*/APP_SECRET restano quelli passati a `docker run`/compose senza doverli
# duplicare da un'altra parte.
: > /etc/cron.d/mailcadence.env
for var in DB_HOST DB_NAME DB_USER DB_PASS APP_SECRET SITE_URL ADMIN_EMAIL ADMIN_PASSWORD_HASH \
           SMTP_HOST SMTP_PORT SMTP_USER SMTP_PASS SMTP_SECURE SMTP_FROM SMTP_FROM_NAME; do
    val="${!var:-}"
    esc=$(printf '%s' "$val" | sed "s/'/'\\\\''/g")
    echo "export ${var}='${esc}'" >> /etc/cron.d/mailcadence.env
done
# Il job in app/crontab gira come utente www-data (non root), quindi deve poter LEGGERE questo
# file per sorgentarlo: 600 (solo root) glielo impediva con un "Permission denied" silenzioso, che
# per via dell'"&&" nel crontab faceva fallire l'intera riga prima ancora di lanciare
# send_batch.php — il worker automatico non partiva mai, pur restando cron attivo nel container.
chown root:www-data /etc/cron.d/mailcadence.env
chmod 640 /etc/cron.d/mailcadence.env

service cron start

exec docker-php-entrypoint "$@"
