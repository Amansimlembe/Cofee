FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libzip-dev libpng-dev unzip git curl \
    && docker-php-ext-install pdo pdo_pgsql zip gd \
    && php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
    && php composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && rm composer-setup.php \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY . /var/www/html/

WORKDIR /var/www/html

RUN composer validate --no-check-publish \
    && composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader \
    && a2enmod rewrite

EXPOSE 10000

CMD ["apache2-foreground"]
