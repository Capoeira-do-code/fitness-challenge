FROM php:8.3-fpm-alpine

RUN apk add --no-cache sqlite sqlite-dev \
    && docker-php-ext-install pdo pdo_sqlite

WORKDIR /var/www

COPY . /var/www

RUN mkdir -p /var/www/storage /var/www/public/uploads
ENTRYPOINT ["/var/www/docker/entrypoint.sh"]
