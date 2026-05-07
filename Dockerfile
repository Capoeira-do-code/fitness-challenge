FROM php:8.3-fpm-alpine

RUN apk add --no-cache sqlite sqlite-dev libzip-dev \
    && docker-php-ext-install pdo pdo_sqlite zip

RUN { \
      echo 'upload_max_filesize=20M'; \
      echo 'post_max_size=20M'; \
      echo 'max_file_uploads=50'; \
    } > /usr/local/etc/php/conf.d/uploads.ini

RUN { \
      echo 'opcache.enable=1'; \
      echo 'opcache.enable_cli=1'; \
      echo 'opcache.memory_consumption=192'; \
      echo 'opcache.max_accelerated_files=20000'; \
      echo 'memory_limit=512M'; \
      echo 'realpath_cache_size=4096K'; \
      echo 'realpath_cache_ttl=600'; \
      echo 'output_buffering=4096'; \
    } > /usr/local/etc/php/conf.d/performance.ini

WORKDIR /var/www

COPY . /var/www

RUN mkdir -p /var/www/storage /var/www/storage/uploads /var/www/public/uploads
ENTRYPOINT ["/var/www/docker/entrypoint.sh"]
