FROM php:8.2-apache

# Install PostgreSQL extension
RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install pgsql pdo_pgsql

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy application files
COPY . /var/www/html/

# Install dependencies
RUN composer install --no-dev --optimize-autoloader

# Enable mod_rewrite
RUN a2enmod rewrite

# Set permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
