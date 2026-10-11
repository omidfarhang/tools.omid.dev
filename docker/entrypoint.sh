#!/bin/bash
set -euo pipefail
mkdir -p /var/www/html/storage/temp /var/www/html/.secure
chown -R www-data:www-data /var/www/html/storage /var/www/html/.secure || true
exec apache2-foreground
