# Diplomacy Lab: a single-container production image.
#
# Diplomacy Lab is one person's private analysis board, so it deliberately runs as one small
# service: Apache with mod_php, talking to a managed MySQL over a private network. It needs no
# Redis, no gamemaster worker and no cron, because a Lab position is only ever adjudicated when
# the user presses RESOLVE (see lib/labMode.php and doc/DIPLOMACY_LAB_ONLINE.md).
FROM php:8.4-apache

# webDiplomacy needs gd for the map rendering, mysqli for the database, and gmp/bcmath for the
# scoring maths. zip is here so Composer can install without shelling out to unzip. There is
# deliberately no MySQL client: docker/lab-init.php runs the install script through mysqli itself.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
        libgmp-dev libzip-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" gd mysqli gmp bcmath zip opcache; \
    apt-get clean; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN a2enmod rewrite headers authn_file authz_user setenvif

WORKDIR /var/www/html

# Install PHP dependencies first so that changing application code does not re-run Composer.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --ignore-platform-reqs --no-scripts

COPY . /var/www/html

COPY docker/php-lab.ini /usr/local/etc/php/conf.d/zz-lab.ini
COPY docker/apache-lab.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/lab-entrypoint
RUN chmod +x /usr/local/bin/lab-entrypoint

# webDiplomacy writes rendered maps and the compiled variant data into the tree at runtime.
RUN mkdir -p cache mapstore datc/maps variants/Classic/cache errorlog orderlog; \
    chown -R www-data:www-data cache mapstore datc variants errorlog orderlog /var/www/html

# Railway provides the port to listen on; 8080 is only the local default.
ENV PORT=8080
EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/lab-entrypoint"]
CMD ["apache2-foreground"]
