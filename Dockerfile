FROM php:8.3-fpm-alpine

RUN apk add --no-cache sqlite sqlite-dev libzip-dev \
    && docker-php-ext-install pdo pdo_sqlite zip

RUN { \
      echo 'upload_max_filesize=20M'; \
      echo 'post_max_size=20M'; \
      echo 'max_file_uploads=50'; \
    } > /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /var/www

COPY . /var/www

RUN mkdir -p /var/www/storage /var/www/storage/uploads /var/www/public/uploads
ENTRYPOINT ["/var/www/docker/entrypoint.sh"]
