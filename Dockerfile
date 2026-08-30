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

# The virtual host includes this file, and the entrypoint rewrites it on startup with the access
# rule that suits wherever the Lab is running. Creating it here means the configuration is complete
# and can be tested at build time, and denying everything means a Lab whose entrypoint somehow did
# not write it refuses requests rather than serving them to anyone.
RUN printf 'Require all denied\n' > /etc/apache2/lab-access.conf

# PHP runs here as mod_php, which is not thread-safe, so Apache has to use the prefork MPM. Apache
# also refuses to start at all when more than one MPM is loaded:
#
#     AH00534: apache2: Configuration error: More than one MPM loaded.
#
# The base image ships prefork on its own, but installing packages over it can leave Debian's
# default MPM enabled alongside it, and a2enmod will not undo that by itself. So rather than trust
# the inherited state, the MPM is chosen explicitly here and the result is then proved: exactly one
# MPM loaded, and the whole configuration parses. If a future base image or package ever loads a
# second one, this fails the build rather than the deployment.
RUN set -eux; \
    a2dismod mpm_event mpm_worker || true; \
    a2enmod mpm_prefork; \
    apache2ctl -M 2>/dev/null | grep -E 'mpm_[a-z]+_module'; \
    test "$(apache2ctl -M 2>/dev/null | grep -c 'mpm_[a-z]*_module')" = '1'; \
    apache2ctl -t

ENTRYPOINT ["/usr/local/bin/lab-entrypoint"]
CMD ["apache2-foreground"]
