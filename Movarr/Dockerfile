FROM php:8.3-apache

RUN a2enmod rewrite

COPY index.php   /var/www/html/index.php
COPY config.php  /var/www/html/config.php
COPY logs.php    /var/www/html/logs.php
COPY queue.php   /var/www/html/queue.php
COPY includes/   /var/www/html/includes/
COPY images/     /var/www/html/images/

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
CMD ["apache2-foreground"]
