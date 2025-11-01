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
# Note: config:cache is NOT run here because environment variables aren't available during build
# Config will be read fresh from environment variables at runtime
# Clear route cache first to ensure fresh routes, then cache them
# Only cache routes and views (these don't depend on env vars)
RUN php artisan route:clear || true \
 && php artisan route:cache || true \
 && php artisan view:cache || true

# FrankenPHP configuration
ENV SERVER_NAME=:8080
ENV PORT=8080
EXPOSE 8080

# Default command - Use Laravel's built-in server for Render compatibility
# Render will route HTTP traffic to port 8080
CMD php artisan serve --host=0.0.0.0 --port=${PORT:-8080}

