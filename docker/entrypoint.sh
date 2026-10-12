#!/bin/bash
set -euo pipefail
mkdir -p /var/www/html/storage/temp
chown -R www-data:www-data /var/www/html/storage || true
exec apache2-foreground
