FROM php:8.3-apache

# Install PHP extensions and system dependencies
RUN apt-get update \
    && apt-get install -y \
        libpq-dev \
        libzip-dev \
        libxml2-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        unzip \
        git \
    && docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
    && docker-php-ext-install \
        pdo \
        pdo_pgsql \
        pgsql \
        zip \
        xml \
        gd \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install Composer dependencies
COPY composer.json ./

RUN composer validate --no-check-publish \
    && composer install \
        --no-dev \
        --prefer-dist \
        --no-interaction \
        --optimize-autoloader

# Copy application files
COPY . /var/www/html/

# Apache configuration
RUN a2enmod rewrite \
    && echo "ServerName localhost" >> /etc/apache2/apache2.conf \
    && chown -R www-data:www-data /var/www/html

# Render provides the PORT environment variable
RUN printf '#!/bin/sh\nsed -i "s/Listen 80/Listen ${PORT:-10000}/" /etc/apache2/ports.conf\nsed -i "s/:80>/:${PORT:-10000}>/g" /etc/apache2/sites-available/000-default.conf\nexec apache2-foreground\n' > /usr/local/bin/start-render.sh \
    && chmod +x /usr/local/bin/start-render.sh

EXPOSE 10000

CMD ["/usr/local/bin/start-render.sh"]