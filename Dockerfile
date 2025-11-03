# ---- Base image with PHP 8.3 + Caddy (FrankenPHP) ----
FROM dunglas/frankenphp:1.1-php8.3

# PHP extensions you need for Laravel + PostgreSQL
RUN install-php-extensions \
    pdo_pgsql opcache intl gd zip bcmath pcntl exif

# Set PHP upload limits for image uploads
RUN echo "upload_max_filesize = 10M" > /usr/local/etc/php/conf.d/uploads.ini && \
    echo "post_max_size = 50M" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "max_execution_time = 300" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/uploads.ini

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
# We clear caches at runtime (in CMD) to ensure fresh routes after each deployment
# Only cache views during build (views don't depend on env vars or routes)
RUN php artisan view:cache || true

# FrankenPHP configuration
ENV SERVER_NAME=:8080
ENV PORT=8080
EXPOSE 8080

# Default command - Use Laravel's built-in server for Render compatibility
# Render will route HTTP traffic to port 8080
# Clear caches on startup to ensure fresh routes/config
CMD php artisan route:clear && php artisan config:clear && php artisan serve --host=0.0.0.0 --port=${PORT:-8080}

