FROM php:8.4-apache

RUN a2enmod rewrite \
    && apt-get update && apt-get install -y wget && rm -rf /var/lib/apt/lists/*

COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html \
    && mkdir -p /data && chown www-data:www-data /data

EXPOSE 80
