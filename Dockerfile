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

RUN apt-get update && apt-get install -y --no-install-recommends \
        curl \
        ffmpeg \
        libsqlite3-dev \
        python3 \
        python3-pip \
        unzip \
    && docker-php-ext-install pdo pdo_sqlite \
    && pip3 install --no-cache-dir --break-system-packages yt-dlp \
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
COPY scripts/ytdl.sh ./scripts/ytdl.sh

RUN a2ensite tools \
    && sed -i 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf \
    && mkdir -p storage/temp .secure \
    && chown -R www-data:www-data storage .secure \
    && chmod +x scripts/ytdl.sh /entrypoint.sh

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public \
    DB_PATH=/var/www/html/storage/app.db \
    TEMP_DIR=/var/www/html/storage/temp \
    YTDLP_COOKIES_PATH=/var/www/html/.secure/cookies.txt

EXPOSE 8080

ENTRYPOINT ["/entrypoint.sh"]
