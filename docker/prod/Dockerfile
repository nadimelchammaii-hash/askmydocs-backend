# PRODUCTION image of the API: PHP-FPM serving Laravel. The same image runs the queue
# workers and the scheduler (see compose.prod.yaml). The code is copied in (not mounted),
# without development dependencies. Build from the repository root:
#   docker build -f docker/prod/Dockerfile -t askmydocs-api:prod .
# Versions are pinned so rebuilds are reproducible.

FROM composer:2.10.2 AS composer

# --- PHP with the extensions the app needs ---------------------------------------
FROM php:8.4.25-fpm-bookworm AS php

# poppler-utils: pdftotext/pdfinfo for PDF text extraction.
# The -dev packages are only needed to compile the extensions and are removed afterwards;
# libpq5/libicu72/libzip4 are the runtime libraries the extensions use.
RUN set -eux; \
    buildDeps='libpq-dev libicu-dev libzip-dev'; \
    apt-get update; \
    apt-get install -y --no-install-recommends poppler-utils libpq5 libicu72 libzip4 $buildDeps; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql intl zip bcmath pcntl opcache; \
    apt-get purge -y --auto-remove $buildDeps; \
    rm -rf /var/lib/apt/lists/*

# --- Application code and production dependencies ----------------------------------
FROM php AS build

COPY --from=composer /usr/bin/composer /usr/bin/composer
WORKDIR /app

# Dependencies first, so this layer is reused when only application code changes.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY . .
# Optimised autoloader; its post-autoload script discovers the installed packages.
RUN composer dump-autoload --no-dev --optimize --no-interaction \
    && rm -f /usr/bin/composer

# --- Final image -------------------------------------------------------------------
FROM php

COPY docker/prod/php.ini /usr/local/etc/php/conf.d/zz-askmydocs.ini
COPY docker/prod/php-fpm.conf /usr/local/etc/php-fpm.d/zz-askmydocs.conf
COPY docker/prod/entrypoint.sh /usr/local/bin/askmydocs-entrypoint
# Strip Windows line endings (CRLF) in case the file was checked out on Windows.
RUN sed -i 's/\r$//' /usr/local/bin/askmydocs-entrypoint && chmod +x /usr/local/bin/askmydocs-entrypoint

RUN useradd --uid 1000 --no-create-home app

# The code belongs to root and is read-only for the app user; only storage/ (a volume
# in production) and bootstrap/cache (config/route caches written at start) are writable.
COPY --from=build /app /app
RUN chown -R app:app /app/storage /app/bootstrap/cache

USER app
WORKDIR /app

# php-fpm listens on 9000 (inside the Docker network only; Caddy connects to it).
EXPOSE 9000

ENTRYPOINT ["askmydocs-entrypoint"]
CMD ["php-fpm"]
