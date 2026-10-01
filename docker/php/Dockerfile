# syntax=docker/dockerfile:1
# ---------------------------------------------------------------------------
# Laravel API image. Used for php-fpm, the queue worker and Horizon — one image,
# three commands, so they can never drift out of sync.
# ---------------------------------------------------------------------------
FROM php:8.4-fpm-alpine AS base

RUN apk add --no-cache \
        postgresql-dev \
        libzip-dev \
        icu-dev \
        oniguruma-dev \
        git \
        unzip \
        tzdata \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        bcmath \
        intl \
        zip \
        opcache \
    && apk del --no-network git

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Sensible production PHP defaults; overridden by the dev stage below.
RUN { \
      echo 'opcache.enable=1'; \
      echo 'opcache.enable_cli=0'; \
      echo 'opcache.memory_consumption=192'; \
      echo 'opcache.max_accelerated_files=20000'; \
      echo 'opcache.validate_timestamps=0'; \
      echo 'realpath_cache_size=4096K'; \
      echo 'realpath_cache_ttl=600'; \
    } > /usr/local/etc/php/conf.d/opcache.ini \
 && { \
      echo 'memory_limit=256M'; \
      echo 'upload_max_filesize=12M'; \
      echo 'post_max_size=12M'; \
      echo 'expose_php=Off'; \
    } > /usr/local/etc/php/conf.d/app.ini

WORKDIR /var/www/html

# ---------------------------------------------------------------------------
FROM base AS production

COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY backend/ ./
RUN composer dump-autoload --optimize --classmap-authoritative \
 && mkdir -p storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache

USER www-data

# ---------------------------------------------------------------------------
FROM base AS development

RUN { \
      echo 'opcache.validate_timestamps=1'; \
      echo 'opcache.revalidate_freq=0'; \
    } >> /usr/local/etc/php/conf.d/opcache.ini
