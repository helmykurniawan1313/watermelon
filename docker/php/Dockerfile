# ---- Stage 1: Composer deps ----
FROM composer:2 AS vendor
WORKDIR /app
COPY database/ database/
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction --optimize-autoloader

# ---- Stage 2: Final PHP-FPM image ----
FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
    libpng-dev libjpeg-turbo-dev freetype-dev libzip-dev icu-dev oniguruma-dev \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install pdo_mysql mbstring zip exif pcntl gd intl bcmath opcache

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY .env.docker .env

# The pre-built Vue frontend (cashew-web/dist) is uploaded alongside this repo
# as ./frontend-dist and served as the SPA at the domain root via nginx.
COPY frontend-dist ./public-spa

RUN rm -f bootstrap/cache/packages.php bootstrap/cache/services.php bootstrap/cache/config.php \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/.env \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod 664 /var/www/html/.env

EXPOSE 9000
CMD ["php-fpm"]
