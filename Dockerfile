# ---- Base image with PHP 8.3 + Caddy (FrankenPHP) ----
FROM dunglas/frankenphp:1.1-php8.3

# PHP extensions you need for Laravel + PostgreSQL
RUN install-php-extensions \
    pdo_pgsql opcache intl gd zip bcmath pcntl exif

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Composer dependencies (cache layer)
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# App code
COPY . .

# Laravel caches (ignore failures on first build)
RUN php artisan key:generate --force || true \
 && php artisan config:cache || true \
 && php artisan route:cache || true \
 && php artisan view:cache || true

# FrankenPHP will serve /public on port 8080
ENV SERVER_NAME=:8080
EXPOSE 8080

# Default command for the web service
CMD ["frankenphp", "run", "--workers=4", "--static", "/app/public"]

