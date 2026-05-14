FROM php:8.3-fpm-alpine

RUN apk add --no-cache sqlite sqlite-dev libzip-dev libpng-dev libjpeg-turbo-dev libwebp-dev freetype-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install pdo pdo_sqlite zip gd

RUN { \
      echo 'upload_max_filesize=20M'; \
      echo 'post_max_size=20M'; \
      echo 'max_file_uploads=50'; \
    } > /usr/local/etc/php/conf.d/uploads.ini

RUN { \
      echo 'opcache.enable=1'; \
      echo 'opcache.enable_cli=1'; \
      echo 'opcache.memory_consumption=192'; \
      echo 'opcache.interned_strings_buffer=24'; \
      echo 'opcache.max_accelerated_files=20000'; \
      echo 'opcache.save_comments=1'; \
      echo 'opcache.validate_timestamps=0'; \
      echo 'opcache.revalidate_freq=0'; \
      echo 'opcache.jit_buffer_size=96M'; \
      echo 'opcache.jit=tracing'; \
      echo 'memory_limit=512M'; \
      echo 'realpath_cache_size=4096K'; \
      echo 'realpath_cache_ttl=600'; \
      echo 'output_buffering=4096'; \
    } > /usr/local/etc/php/conf.d/performance.ini

RUN { \
      echo '[www]'; \
      echo 'pm = dynamic'; \
      echo 'pm.max_children = 24'; \
      echo 'pm.start_servers = 4'; \
      echo 'pm.min_spare_servers = 2'; \
      echo 'pm.max_spare_servers = 8'; \
      echo 'pm.max_requests = 500'; \
      echo 'request_terminate_timeout = 120s'; \
      echo 'catch_workers_output = yes'; \
      echo 'clear_env = no'; \
    } > /usr/local/etc/php-fpm.d/zz-performance.conf

WORKDIR /var/www

COPY . /var/www

RUN mkdir -p /var/www/storage /var/www/storage/uploads /var/www/public/uploads
ENTRYPOINT ["/var/www/docker/entrypoint.sh"]
