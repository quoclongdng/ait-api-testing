FROM php:8.4-cli-alpine

RUN apk add --no-cache curl libzip-dev postgresql-dev icu-dev $PHPIZE_DEPS \
    && docker-php-ext-install pdo_pgsql zip intl pcntl bcmath \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
# Allow 5 MB spec uploads (PHP's default upload_max_filesize is 2M).
COPY docker/web/php/uploads.ini /usr/local/etc/php/conf.d/zz-ait-uploads.ini
COPY docker/web/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html
ENTRYPOINT ["entrypoint.sh"]
# Built-in server directly (not `artisan serve`, which drops env vars already present in .env)
CMD ["sh", "-c", "cd public && exec php -S 0.0.0.0:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"]
