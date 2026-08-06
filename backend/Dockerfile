FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

FROM php:8.4-cli-alpine
WORKDIR /var/www/html
RUN apk add --no-cache curl icu-libs libzip mysql-client \
    && apk add --no-cache --virtual .build-deps icu-dev libzip-dev $PHPIZE_DEPS \
    && docker-php-ext-install intl pdo_mysql zip \
    && apk del .build-deps
COPY --from=vendor /app/vendor ./vendor
COPY . .
RUN rm -f bootstrap/cache/*.php \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
