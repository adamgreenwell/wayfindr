# syntax=docker/dockerfile:1.7

ARG PHP_VERSION=8.4
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

# postgresql-client for wayfindr:backup / wayfindr:restore (pg_dump/pg_restore
# are NOT the pdo_pgsql PHP driver). Pinned to major 17 to match the Postgres
# service — pg_dump refuses a server newer than the client, so these versions
# must move together on any future Postgres upgrade (ADR 0009).
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl gnupg lsb-release \
    && install -d /usr/share/postgresql-common/pgdg \
    && curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc \
        -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc \
    && echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt $(lsb_release -cs)-pgdg main" \
        > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends postgresql-client-17 \
    && apt-get purge -y gnupg \
    && apt-get autoremove -y \
    && rm -rf /var/lib/apt/lists/*

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

# Release identity is baked at build time so the image can answer "what is
# running here?" on /operator without operator configuration. It goes into BOTH
# env (visibility) and files (the un-shadowable source config falls back to when
# a blank env_file line would otherwise override the env).
#
# The release workflow passes the tag and commit. A SOURCE build passes neither,
# and instead of the old opaque "source" it derives an identity from the
# committed VERSION file: `<VERSION>-dev`, plus `+<sha>` when the builder
# supplies a commit (ADR 0012). `.git` is dockerignored, so the VERSION file is
# the only in-context anchor a source build has.
#
# The sha is NOT abbreviated. Build metadata is what pins the build for version
# comparison, and an abbreviation that collides would make two different commits
# compare as the same build — reintroducing the fail-open this is here to
# prevent. The operator console shows the commit on its own line ("Source
# revision"), so the version string carries no display burden.
ARG WAYFINDR_VERSION=
ARG WAYFINDR_COMMIT=

COPY VERSION /tmp/wayfindr-version

RUN mkdir -p /etc/wayfindr \
    # "source" is accepted as a synonym for "unset" so a stale compose overlay
    # passing the retired literal still derives a real identity.
    && if [ -n "${WAYFINDR_VERSION}" ] && [ "${WAYFINDR_VERSION}" != "source" ]; then \
           version="${WAYFINDR_VERSION}"; \
       else \
           version="$(tr -d '[:space:]' < /tmp/wayfindr-version)-dev"; \
           if [ -n "${WAYFINDR_COMMIT}" ]; then \
               version="${version}+$(printf '%s' "${WAYFINDR_COMMIT}" | tr -cd '[:alnum:]')"; \
           fi; \
       fi \
    && printf '%s' "${version}" > /etc/wayfindr/version \
    && printf '%s' "${WAYFINDR_COMMIT}" > /etc/wayfindr/commit \
    && rm -f /tmp/wayfindr-version

# NOTE: ENV can only re-export the raw ARG — it cannot see the value derived in
# the RUN above. So on a source build this exports blank (harmless: a blank env
# falls through to the baked file) and on a stale overlay passing the retired
# literal it exports `source`. ReleaseIdentity therefore treats `source` as "not
# given" too, so it can never shadow the derived identity in the file.
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    SERVER_NAME=:80 \
    WAYFINDR_VERSION=${WAYFINDR_VERSION} \
    WAYFINDR_COMMIT=${WAYFINDR_COMMIT}

WORKDIR /app/apps/server

COPY --from=vendor /app/apps/server /app/apps/server
COPY release.json /app/release.json
COPY releases/history.json /app/releases/history.json
COPY scripts/release/build-manifest.php /app/scripts/release/build-manifest.php

# What this release requires of an operator, and the history a skipped span needs
# (ADR 0013). Both are read by the guard before migrations run, so they have to
# be on disk in the image rather than fetched.
#
# The history starts from the repository's record of published releases and gains
# this build on top. Seeding it from the repo is what lets the guard evaluate a
# v1 -> v3 jump: the v3 image has to know what v2 asked for, and v2's declaration
# exists nowhere else at build time.
#
# `version` is re-read from the identity file rather than recomputed, so the
# manifest and /etc/wayfindr/version cannot disagree about which release this is.
RUN cp /app/releases/history.json /etc/wayfindr/release-history.json \
    && php /app/scripts/release/build-manifest.php \
        --version="$(cat /etc/wayfindr/version)" \
        --commit="$(cat /etc/wayfindr/commit)" \
        --declaration=/app/release.json \
        --out=/etc/wayfindr/release.json \
        --history=/etc/wayfindr/release-history.json \
    && rm -rf /app/release.json /app/releases /app/scripts
COPY packages/widget-js/src /app/packages/widget-js/src
# The realtime library ships INSIDE the image. Omitting it would not fail the
# build: the widget would simply be served without realtime, on an install
# whose config says realtime is on -- the exact silent degradation issue #714
# exists to end.
COPY packages/widget-js/vendor /app/packages/widget-js/vendor
COPY docker/self-hosting/php.ini /usr/local/etc/php/conf.d/wayfindr.ini
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
