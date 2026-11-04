# PHP image for LOCAL DEVELOPMENT: the API, queue workers and scheduler (see compose.yaml).
# The source code is not copied in: it is mounted from the host, so edits apply immediately.
# Versions are pinned so rebuilds are reproducible.

FROM composer:2.10.2 AS composer

FROM php:8.4.25-cli-bookworm

# poppler-utils: pdftotext/pdfinfo for PDF text extraction.
# libpq/libicu/libzip: build dependencies of the PHP extensions below.
RUN apt-get update \
    && apt-get install -y --no-install-recommends poppler-utils libpq-dev libicu-dev libzip-dev unzip \
    && docker-php-ext-install pdo_pgsql intl zip bcmath pcntl opcache \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY php.ini /usr/local/etc/php/conf.d/askmydocs.ini
COPY entrypoint.sh /usr/local/bin/askmydocs-entrypoint
# Strip Windows line endings (CRLF) in case the file was checked out on Windows.
RUN sed -i 's/\r$//' /usr/local/bin/askmydocs-entrypoint && chmod +x /usr/local/bin/askmydocs-entrypoint

# Run as an unprivileged user. /app/vendor is a Docker volume, owned by this user.
RUN useradd --create-home --uid 1000 app \
    && mkdir -p /app/vendor \
    && chown app:app /app /app/vendor
USER app
WORKDIR /app

ENTRYPOINT ["askmydocs-entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
