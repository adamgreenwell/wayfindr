# syntax=docker/dockerfile:1.7

ARG PHP_VERSION=8.4
ARG NODE_VERSION=24

FROM php:${PHP_VERSION}-cli-alpine AS php-base

RUN apk add --no-cache \
        bash \
        curl \
        libcurl \
        libpq \
        libxml2 \
        oniguruma \
        tzdata \
        unzip \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        curl-dev \
        libxml2-dev \
        linux-headers \
        oniguruma-dev \
        postgresql-dev \
    && docker-php-ext-install -j"$(nproc)" \
        curl \
        dom \
        mbstring \
        opcache \
        pcntl \
        pdo_pgsql \
        sockets \
    && apk del .build-deps

WORKDIR /app/apps/server

FROM node:${NODE_VERSION}-alpine AS assets

WORKDIR /app/apps/server

COPY apps/server/package*.json ./

RUN if [ -f package-lock.json ]; then npm ci; else npm install --ignore-scripts; fi

COPY apps/server/public ./public
COPY apps/server/resources ./resources
COPY apps/server/vite.config.js ./

RUN npm run build

FROM php-base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1

COPY apps/server/composer.json apps/server/composer.lock ./

RUN composer install \
        --no-dev \
        --prefer-dist \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --optimize-autoloader

COPY apps/server ./
COPY --from=assets /app/apps/server/public/build ./public/build

RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
    && php artisan package:discover --ansi \
    && mkdir -p \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

FROM php-base AS runtime

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

COPY --from=vendor /app/apps/server /app/apps/server

RUN addgroup -g 1000 wayfindr \
    && adduser -D -G wayfindr -u 1000 wayfindr \
    && chown -R wayfindr:wayfindr storage bootstrap/cache

USER wayfindr

EXPOSE 8000 8080

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
