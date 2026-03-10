FROM php:8.3-apache

RUN a2enmod rewrite

# Install SQLite3 + cron, then enable the PHP PDO driver
RUN apt-get update && apt-get install -y --no-install-recommends \
        libsqlite3-dev \
        cron \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo_sqlite

# Install the Movarr cron schedule
COPY cron.d/movarr /etc/cron.d/movarr
RUN chmod 0644 /etc/cron.d/movarr

COPY index.php   /var/www/html/index.php
COPY sync.php    /var/www/html/sync.php
COPY config.php  /var/www/html/config.php
COPY logs.php    /var/www/html/logs.php
COPY queue.php   /var/www/html/queue.php
COPY manual.php  /var/www/html/manual.php
COPY history.php /var/www/html/history.php
COPY health.php  /var/www/html/health.php
COPY tracked.php /var/www/html/tracked.php
COPY includes/   /var/www/html/includes/
COPY images/     /var/www/html/images/

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
CMD ["apache2-foreground"]
