FROM serversideup/php:8.3-fpm-nginx

USER root

# Pastikan extension PostgreSQL terpasang
RUN install-php-extensions pdo_pgsql pgsql gd

# Copy kode aplikasi dengan ownership yang benar
COPY --chown=www-data:www-data . /var/www/html

WORKDIR /var/www/html

# Install dependency PHP sebagai user non-root (best practice image ini)
USER www-data
RUN composer install --no-dev --optimize-autoloader --no-interaction

ENV APP_ENV=production
ENV PHP_OPCACHE_ENABLE=1

# Otomatis jalankan migration & storage:link setiap container start
ENV AUTORUN_ENABLED=true
ENV AUTORUN_LARAVEL_MIGRATION=true
ENV AUTORUN_LARAVEL_STORAGE_LINK=true
