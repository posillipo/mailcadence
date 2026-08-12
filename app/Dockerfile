# Contesto di build: la root del repository (vedi docker-compose.yml: build.context = "."), non
# app/ come in chifacosa — serve per poter copiare anche database/schema.sql, che sta un livello
# sopra app/ e viene usato da src/db.php per inizializzare automaticamente un database vuoto.
FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql mysqli \
    && a2enmod rewrite \
    && a2enmod headers \
    && apt-get update \
    && apt-get install -y --no-install-recommends cron \
    && rm -rf /var/lib/apt/lists/*

COPY app/public/ /var/www/html/
COPY app/src/ /var/www/src/
COPY database/ /var/www/database/

# Worker di invio: esegue src/send_batch.php ogni 5 minuti (vedi app/crontab).
COPY app/crontab /etc/cron.d/mailcadence
RUN chmod 0644 /etc/cron.d/mailcadence \
    && touch /var/log/send_batch.log \
    && chown www-data:www-data /var/log/send_batch.log

COPY app/docker-entrypoint-wrapper.sh /usr/local/bin/docker-entrypoint-wrapper.sh
RUN chmod +x /usr/local/bin/docker-entrypoint-wrapper.sh
ENTRYPOINT ["docker-entrypoint-wrapper.sh"]
CMD ["apache2-foreground"]

RUN chown -R www-data:www-data /var/www/html

RUN { \
      echo 'upload_max_filesize = 8M'; \
      echo 'post_max_size = 10M'; \
    } > /usr/local/etc/php/conf.d/uploads.ini

RUN { \
      echo 'display_errors = Off'; \
      echo 'display_startup_errors = Off'; \
      echo 'log_errors = On'; \
      echo 'error_log = /dev/stderr'; \
      echo 'error_reporting = E_ALL'; \
    } > /usr/local/etc/php/conf.d/error-handling.ini

RUN { \
      echo '<Directory /var/www/html>'; \
      echo '    AllowOverride All'; \
      echo '    Require all granted'; \
      echo '</Directory>'; \
    } > /etc/apache2/conf-available/mailcadence.conf \
    && a2enconf mailcadence

WORKDIR /var/www/html
