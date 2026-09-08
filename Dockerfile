FROM php:8.3-apache

# Install system dependencies and PHP extensions
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libpq-dev \
        libzip-dev \
        libxml2-dev \
        libonig-dev \
        libpng-dev \
        unzip \
        git \
        curl \
    && docker-php-ext-install \
        pdo \
        pdo_pgsql \
        zip \
        xml \
        mbstring \
        gd \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
    && php composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && rm composer-setup.php

WORKDIR /var/www/html

# Copy application
COPY . /var/www/html/

# Ignore any invalid composer.json from the repository
# and create a clean Composer configuration inside the image.
RUN rm -f composer.json composer.lock \
    && composer init \
        --name="coffee/kagera-auction" \
        --description="Coffee Kagera Auction System" \
        --require="phpoffice/phpspreadsheet:^5.3" \
        --no-interaction \
    && composer install \
        --no-dev \
        --prefer-dist \
        --no-interaction \
        --optimize-autoloader

# Enable Apache rewrite
RUN a2enmod rewrite

# Render uses port 10000
ENV PORT=10000

RUN sed -i 's/Listen 80/Listen 10000/' /etc/apache2/ports.conf \
    && sed -i 's/:80>/:10000>/g' /etc/apache2/sites-available/000-default.conf

EXPOSE 10000

CMD ["apache2-foreground"]
