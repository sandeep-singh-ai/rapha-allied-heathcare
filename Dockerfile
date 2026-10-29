FROM composer:2 AS dependencies
WORKDIR /app/api
COPY api/composer.json ./composer.json
RUN composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress

FROM php:8.3-apache
RUN apt-get update && apt-get install -y --no-install-recommends libonig-dev libcurl4-openssl-dev \
    && docker-php-ext-install mbstring curl \
    && rm -rf /var/lib/apt/lists/*
COPY index.html /var/www/html/index.html
COPY api/submit.php /var/www/html/api/submit.php
COPY --from=dependencies /app/api/vendor /var/www/html/api/vendor
RUN printf '%s\n' '<Directory /var/www/html/api/vendor>' '  Require all denied' '</Directory>' > /etc/apache2/conf-available/rapha-protect.conf \
    && a2enconf rapha-protect \
    && printf '%s\n' 'upload_max_filesize=10M' 'post_max_size=16M' 'max_file_uploads=5' > /usr/local/etc/php/conf.d/uploads.ini
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 CMD php -r 'exit(@file_get_contents("http://127.0.0.1/")===false?1:0);'
