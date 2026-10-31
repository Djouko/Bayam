# WP-CLI de BAYAM (travailleur, installation, essais) : même extension Redis que le site.
FROM wordpress:cli-2.12.0-php8.3
USER root
RUN apk add --no-cache --virtual .build $PHPIZE_DEPS \
    && pecl install redis-6.3.0 \
    && docker-php-ext-enable redis \
    && apk del .build && rm -rf /tmp/pear
USER www-data
