#!/usr/bin/env sh
set -eu

mkdir -p /var/www/storage /var/www/public/uploads
chown -R www-data:www-data /var/www/storage /var/www/public/uploads || true

exec php-fpm
