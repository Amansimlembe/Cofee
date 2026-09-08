FROM php:8.3-apache

RUN docker-php-ext-install pdo pdo_pgsql

COPY . /var/www/html/

RUN a2enmod rewrite

EXPOSE 10000

CMD ["sh", "-c", "sed -i \"s/Listen 80/Listen ${PORT:-10000}/\" /etc/apache2/ports.conf && sed -i \"s/:80>/:${PORT:-10000}>/\" /etc/apache2/sites-available/000-default.conf && apache2-foreground"]

