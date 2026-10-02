# syntax=docker/dockerfile:1.7
#
# Logbook — multi-stage, multi-arch (amd64, arm64) image.
# 64-bit only: Phinx requires 64-bit PHP, so 32-bit ARM (arm/v7) is unsupported.
# Raspberry Pi 3/4/5 on 64-bit Raspberry Pi OS use the arm64 image.
#
#   docker build -t logbook .                 # production image (default target)
#   docker build --target dev -t logbook:dev . # toolchain image (composer, dev ini)

ARG PHP_VERSION=8.4

# ---------------------------------------------------------------------------
# base: PHP + Apache + the extensions the app needs on every engine.
# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-apache AS base

RUN set -eux; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends libicu-dev libpq-dev libzip-dev libjpeg62-turbo-dev libpng-dev libwebp-dev; \
    # gd (JPEG, PNG, WebP) and exif: every photo upload is turned upright and stripped of its metadata (spec §7.12)
    docker-php-ext-configure gd --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" intl pdo_mysql pdo_pgsql opcache zip gd exif; \
    # keep only the runtime libraries the compiled extensions link against
    apt-mark auto '.*' > /dev/null; \
    [ -z "$savedAptMark" ] || apt-mark manual $savedAptMark; \
    find /usr/local -type f -name '*.so*' -exec ldd '{}' ';' \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) { next }; gsub("^/(usr/)?", "", so); printf "*%s\n", so }' \
        | sort -u | xargs -r dpkg-query --search | cut -d: -f1 | sort -u | xargs -r apt-mark manual; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*; \
    php -m | grep -qi '^intl$'; php -m | grep -qi '^pdo_pgsql$'; php -m | grep -qi '^pdo_mysql$'; php -m | grep -qi '^pdo_sqlite$'; php -m | grep -qi '^zip$'; php -m | grep -qi '^gd$'; php -m | grep -qi '^exif$'; \
    php -r 'exit(function_exists("imagecreatefromwebp") && function_exists("imagecreatefromjpeg") ? 0 : 1);'

# Ghostscript renders scanned PDFs for reading (spec §7.27); text PDFs and
# photos need nothing more.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends ghostscript; \
    rm -rf /var/lib/apt/lists/*; \
    gs --version

RUN set -eux; \
    a2enmod rewrite headers; \
    a2dissite 000-default; \
    rm -f /etc/apache2/sites-available/000-default.conf /etc/apache2/sites-available/default-ssl.conf

COPY docker/apache/logbook.conf /etc/apache2/sites-available/logbook.conf
COPY docker/php/logbook.ini /usr/local/etc/php/conf.d/zz-logbook.ini
RUN a2ensite logbook

WORKDIR /var/www/html
# Zero-config quick start: `docker run -v logbook:/data -p 8080:80 <image>` uses
# SQLite on the volume. The compose files switch DB_DRIVER to pgsql / mysql.
ENV APP_ENV=production \
    APP_BASE_PATH="" \
    DB_DRIVER=sqlite \
    DB_NAME=/data/logbook.sqlite \
    UPLOAD_PATH=/data/uploads \
    BACKUP_PATH=/data/backups \
    LOGBOOK_DOCKER=1

# ---------------------------------------------------------------------------
# build: install production Composer dependencies.
# ---------------------------------------------------------------------------
FROM base AS build
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apt-get update && apt-get install -y --no-install-recommends git unzip && rm -rf /var/lib/apt/lists/*
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction

# ---------------------------------------------------------------------------
# dev: toolchain image used by docker-compose.dev.yml and for running the
# quality gates on machines without a local PHP.
# ---------------------------------------------------------------------------
FROM base AS dev
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apt-get update && apt-get install -y --no-install-recommends git unzip && rm -rf /var/lib/apt/lists/* \
    && cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
ENV APP_ENV=development \
    COMPOSER_ALLOW_SUPERUSER=1
COPY docker/entrypoint.sh /usr/local/bin/logbook-entrypoint
RUN chmod +x /usr/local/bin/logbook-entrypoint
ENTRYPOINT ["logbook-entrypoint"]
CMD ["apache2-foreground"]

# ---------------------------------------------------------------------------
# production (default): app code + vendor, one persistent volume at /data.
# ---------------------------------------------------------------------------
FROM base AS production
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY --from=build --chown=www-data:www-data /var/www/html /var/www/html
# The release number shown in the app and /health comes from the VERSION file
# copied above; fail the build rather than ship an image without it.
RUN grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+' VERSION
COPY docker/entrypoint.sh /usr/local/bin/logbook-entrypoint
RUN chmod +x /usr/local/bin/logbook-entrypoint \
    && mkdir -p /data var/cache var/log \
    && chown -R www-data:www-data /data var

VOLUME ["/data"]
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS http://127.0.0.1/health > /dev/null || exit 1

ENTRYPOINT ["logbook-entrypoint"]
CMD ["apache2-foreground"]
