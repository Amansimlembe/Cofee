FROM php:8.3-apache

# Install system dependencies
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

# Enable Apache rewrite
RUN a2enmod rewrite

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy Composer files first
COPY composer.json ./

# Validate and install dependencies
RUN composer validate --no-check-publish \
    && composer install \
        --no-dev \
        --prefer-dist \
        --no-interaction \
        --optimize-autoloader

# Copy application
COPY . /var/www/html/

# Set Apache document permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

CMD ["apache2-foreground"]