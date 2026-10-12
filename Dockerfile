# syntax=docker/dockerfile:1

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock* ./
COPY src ./src
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts \
    && composer dump-autoload --optimize --no-dev

FROM node:20-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm ci || npm install
COPY scripts/copy-assets.js ./scripts/copy-assets.js
RUN npm run build:assets

FROM php:8.3-apache-bookworm

# Deno is yt-dlp's default JS runtime for YouTube EJS challenges.
# See https://github.com/yt-dlp/yt-dlp/wiki/EJS
ARG DENO_VERSION=2.9.7

RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        ffmpeg \
        libsqlite3-dev \
        python3 \
        python3-pip \
        unzip \
    && docker-php-ext-install pdo pdo_sqlite \
    && arch="$(dpkg --print-architecture)" \
    && case "$arch" in \
         amd64) deno_arch=x86_64 ;; \
         arm64) deno_arch=aarch64 ;; \
         *) echo "unsupported arch: $arch" >&2; exit 1 ;; \
       esac \
    && curl -fsSL "https://github.com/denoland/deno/releases/download/v${DENO_VERSION}/deno-${deno_arch}-unknown-linux-gnu.zip" -o /tmp/deno.zip \
    && unzip -q /tmp/deno.zip -d /usr/local/bin \
    && chmod +x /usr/local/bin/deno \
    && rm /tmp/deno.zip \
    && pip3 install --no-cache-dir --break-system-packages "yt-dlp[default]" \
    && a2enmod rewrite headers \
    && a2dissite 000-default \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY composer.json ./
COPY src ./src
COPY templates ./templates
COPY config ./config
COPY public ./public
COPY --from=assets /app/public/assets ./public/assets
COPY docker/apache-vhost.conf /etc/apache2/sites-available/tools.conf
COPY docker/entrypoint.sh /entrypoint.sh
COPY scripts ./scripts

RUN a2ensite tools \
    && sed -i 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf \
    && mkdir -p storage/temp .secure \
    && chown -R www-data:www-data storage .secure \
    && chmod +x scripts/ytdl.sh scripts/process_download_job.php /entrypoint.sh

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public \
    DB_PATH=/var/www/html/storage/app.db \
    TEMP_DIR=/var/www/html/storage/temp \
    YTDLP_COOKIES_PATH=/var/www/html/.secure/cookies.txt

EXPOSE 8080

ENTRYPOINT ["/entrypoint.sh"]
