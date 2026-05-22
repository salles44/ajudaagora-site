FROM php:8.2-apache

RUN apt-get update && apt-get install -y curl \
    && a2enmod rewrite \
    && echo "OK" > /var/www/html/health.html

COPY . /var/www/html/

EXPOSE 80
