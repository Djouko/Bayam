# WordPress de BAYAM : image officielle + extension PHP Redis (cache d'objets persistant et file des événements WhatsApp).
FROM wordpress:7.0.4-php8.3-apache
RUN pecl install redis-6.3.0 \
    && docker-php-ext-enable redis \
    && rm -rf /tmp/pear
