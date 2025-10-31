# ---- Base image with PHP 8.3 + Caddy (FrankenPHP) ----
FROM dunglas/frankenphp:1.1-php8.3

# PHP extensions you need for Laravel + PostgreSQL
RUN install-php-extensions \
    pdo_pgsql opcache intl gd zip bcmath pcntl exif

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Composer dependencies (cache layer)
# Install without scripts first (artisan not available yet)
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts

# App code
COPY . .

# Run composer scripts now that artisan is available
RUN composer dump-autoload --optimize --no-interaction

# Laravel caches (ignore failures on first build)
RUN php artisan key:generate --force || true \
 && php artisan config:cache || true \
 && php artisan route:cache || true \
 && php artisan view:cache || true

# FrankenPHP configuration
ENV SERVER_NAME=:8080
EXPOSE 8080

# Default command - Start php-fpm in foreground
# Render's proxy will route to php-fpm on port 9000
# FrankenPHP will be used via php-fpm worker mode
CMD ["php-fpm", "-F", "-y", "/usr/local/etc/php-fpm.d/www.conf"]

