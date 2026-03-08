FROM php:8.3-apache

RUN a2enmod rewrite

COPY index.php   /var/www/html/index.php
COPY config.php  /var/www/html/config.php
COPY logs.php    /var/www/html/logs.php
COPY includes/   /var/www/html/includes/

EXPOSE 80
