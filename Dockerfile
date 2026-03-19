FROM php:8.4-fpm-alpine

# Install runtime dependencies
RUN apk add --no-cache \
    git \
    curl \
    libpq-dev \
    icu-dev \
    icu-libs \
    rabbitmq-c-dev \
    zip \
    unzip

# Install build-time deps, compile PHP extensions, then clean up
RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        linux-headers \
    && docker-php-ext-install -j$(nproc) \
        pdo_pgsql \
        intl \
        opcache \
        sockets \
    && pecl install redis amqp \
    && docker-php-ext-enable redis amqp \
    && apk del .build-deps \
    && rm -rf /tmp/pear

# PHP runtime config
COPY docker/php/php.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy dependency manifests first (layer cache optimization)
COPY composer.json composer.lock* ./

# Install PHP dependencies (no scripts/autoloader until source is copied)
RUN composer install \
    --no-scripts \
    --no-autoloader \
    --no-interaction \
    --prefer-dist \
    --no-progress

# Copy application source
COPY . .

# Generate optimized autoloader and run post-install scripts
RUN composer dump-autoload --optimize --classmap-authoritative \
    && composer run-script post-install-cmd --no-interaction || true \
    && mkdir -p var/log var/cache \
    && chown -R www-data:www-data var/

EXPOSE 9000

CMD ["php-fpm"]
