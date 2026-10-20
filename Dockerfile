# The site sonic.mimichat.fr: Symfony on FrankenPHP (classic mode), behind the Caddy of the game's server
# (it does the TLS); the database: the MariaDB of the same compose file. See README.md.
FROM dunglas/frankenphp:1-php8.4-alpine

RUN install-php-extensions intl opcache pdo_mysql zip \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-site.ini
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
ENV APP_ENV=prod APP_DEBUG=0 SERVER_NAME=:8080 COMPOSER_ALLOW_SUPERUSER=1
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress
COPY . .
RUN rm -f .env.local && composer dump-autoload --classmap-authoritative --no-dev \
 && composer run-script --no-dev post-install-cmd \
 && chown -R www-data:www-data var \
 && chown -R www-data:www-data /data /config

COPY docker/entrypoint.sh /usr/local/bin/site-entrypoint
USER www-data
EXPOSE 8080
ENTRYPOINT ["site-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
