#!/usr/bin/env sh
set -eu

mkdir -p /var/www/storage /var/www/storage/uploads /var/www/public/uploads
chown -R www-data:www-data /var/www/storage || true

exec php-fpm
