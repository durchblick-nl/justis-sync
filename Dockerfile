FROM php:8.4-apache

RUN a2enmod rewrite \
    && apt-get update && apt-get install -y wget && rm -rf /var/lib/apt/lists/* \
    && echo "post_max_size = 5M\nupload_max_filesize = 5M" > /usr/local/etc/php/conf.d/justis.ini

COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html \
    && mkdir -p /data && chown www-data:www-data /data

EXPOSE 80
