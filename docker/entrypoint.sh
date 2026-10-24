#!/bin/bash
set -euo pipefail

mkdir -p /var/www/html/storage/temp
chown -R www-data:www-data /var/www/html/storage || true

php-fpm -D
exec nginx -g 'daemon off;'
