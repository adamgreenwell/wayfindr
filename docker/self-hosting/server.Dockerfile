# syntax=docker/dockerfile:1.7

ARG PHP_VERSION=8.4
ARG NODE_VERSION=24

# --- Dashboard assets ---------------------------------------------------------

FROM node:${NODE_VERSION}-alpine AS assets

WORKDIR /app/apps/server

COPY apps/server/package.json apps/server/package-lock.json ./

RUN npm ci --ignore-scripts

COPY apps/server/public ./public
COPY apps/server/resources ./resources
COPY apps/server/vite.config.js ./

RUN npm run build

# --- Shared PHP runtime (FrankenPHP) -----------------------------------------
# FrankenPHP is the production web server (Caddy + embedded PHP): one binary,
# HTTP/2/3, and automatic HTTPS when SERVER_NAME is a real hostname. The same
# base runs the CLI processes (queue, scheduler, reverb) so extensions can
# never drift between web and workers.

FROM dunglas/frankenphp:1-php${PHP_VERSION} AS php-base

RUN install-php-extensions \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        sockets \
        zip

# --- Composer vendor tree -----------------------------------------------------

FROM php-base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app/apps/server

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
        storage/app/private/attachments \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

# --- Runtime ------------------------------------------------------------------
# The image keeps the monorepo shape: the app serves the widget script from
# ../../packages/widget-js relative to apps/server (WidgetScriptController),
# so /app is the repo root — not the Laravel root.

FROM php-base AS runtime

# Release identity is baked at build time so the official image can answer
# "what is running here?" on /operator without operator configuration. The
# release workflow passes the tag and commit; local builds get "source". It
# goes into BOTH env (visibility) and files (the un-shadowable source config
# falls back to when a blank env_file line would otherwise override the env).
ARG WAYFINDR_VERSION=source
ARG WAYFINDR_COMMIT=

RUN mkdir -p /etc/wayfindr \
    && printf '%s' "${WAYFINDR_VERSION}" > /etc/wayfindr/version \
    && printf '%s' "${WAYFINDR_COMMIT}" > /etc/wayfindr/commit

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    SERVER_NAME=:80 \
    WAYFINDR_VERSION=${WAYFINDR_VERSION} \
    WAYFINDR_COMMIT=${WAYFINDR_COMMIT}

WORKDIR /app/apps/server

COPY --from=vendor /app/apps/server /app/apps/server
COPY packages/widget-js/src /app/packages/widget-js/src
COPY docker/self-hosting/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/self-hosting/docker-entrypoint.sh /usr/local/bin/wayfindr-entrypoint

# Non-root, but still able to bind 80/443 for automatic HTTPS: grant the
# binary the bind capability and hand Caddy's state dirs to the app user.
RUN chmod +x /usr/local/bin/wayfindr-entrypoint \
    && useradd --uid 1000 --user-group --create-home wayfindr \
    && setcap CAP_NET_BIND_SERVICE=+eip "$(command -v frankenphp)" \
    && mkdir -p /data /config \
    && chown -R wayfindr:wayfindr /data /config /app/apps/server/storage /app/apps/server/bootstrap/cache

USER wayfindr

EXPOSE 80 443 443/udp 8000

ENTRYPOINT ["wayfindr-entrypoint"]

CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
