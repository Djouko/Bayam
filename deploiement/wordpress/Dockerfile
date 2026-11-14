# syntax=docker/dockerfile:1.7
# Image WordPress de production de BAYAM (contexte : racine du dépôt). Le cœur, les extensions de wordpress.org aux
# versions figées (extensions.txt) et le code BAYAM sont cuits dans l'image : chaque déploiement construit une image
# neuve, toutes les copies du site tournent avec exactement le même code, et revenir en arrière = redéployer la
# version précédente. Seuls les fichiers envoyés (uploads) et les traductions vivent dans des volumes.
# La même image sert au site (Apache), au travailleur et à la préparation (WP-CLI).

FROM composer:2.9.2 AS dependances
WORKDIR /app
COPY plugins/bayam-whatsapp/composer.json plugins/bayam-whatsapp/composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --no-scripts

FROM alpine:3.22 AS extensions
RUN apk add --no-cache curl unzip
WORKDIR /x
COPY deploiement/wordpress/extensions.txt .
RUN mkdir plugins themes \
    && grep -vE '^\s*(#|$)' extensions.txt | while read -r type nom version; do \
         curl -fsSL --retry 5 --retry-delay 3 -o z.zip "https://downloads.wordpress.org/${type}/${nom}.${version}.zip" \
         && unzip -q z.zip -d "${type}s" && rm z.zip || exit 1; \
       done

FROM wordpress:7.0.4-php8.3-apache
# Extension PHP Redis (cache d'objets, file des événements), client MariaDB (commandes « wp db »), WP-CLI vérifié.
RUN apt-get update && apt-get install -y --no-install-recommends mariadb-client \
    && rm -rf /var/lib/apt/lists/* \
    && pecl install redis-6.3.0 && docker-php-ext-enable redis && rm -rf /tmp/pear \
    && curl -fsSL -o /usr/local/bin/wp https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar \
    && echo "$(curl -fsSL https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar.sha512)  /usr/local/bin/wp" | sha512sum -c - \
    && chmod +x /usr/local/bin/wp
COPY php.ini /usr/local/etc/php/conf.d/fluxstore.ini

# Cœur WordPress et configuration lue dans les variables d'environnement (wp-config-docker.php de l'image officielle) :
# présents dès la construction, pour le site comme pour WP-CLI.
RUN cp -a /usr/src/wordpress/. /var/www/html/ && cp /usr/src/wordpress/wp-config-docker.php /var/www/html/wp-config.php
COPY --from=extensions /x/plugins/ /var/www/html/wp-content/plugins/
COPY --from=extensions /x/themes/ /var/www/html/wp-content/themes/
COPY plugins/bayam-whatsapp /var/www/html/wp-content/plugins/bayam-whatsapp
COPY --from=dependances /app/vendor /var/www/html/wp-content/plugins/bayam-whatsapp/vendor
COPY plugins/bayam-cinetpay /var/www/html/wp-content/plugins/bayam-cinetpay
COPY themes/bayam-vitrine /var/www/html/wp-content/themes/bayam-vitrine
COPY mu-plugins /var/www/html/wp-content/mu-plugins
COPY scripts/installer.sh scripts/wp-cli.yml /scripts/
COPY scripts/catalogue /scripts/catalogue
COPY scripts/pages /scripts/pages
COPY scripts/medias /scripts/medias
COPY deploiement/wordpress/preparer.sh /scripts/preparer.sh
# Cache d'objets Redis actif sans écrire dans wp-content au démarrage ; essais retirés de l'image de production.
RUN cp /var/www/html/wp-content/plugins/redis-cache/includes/object-cache.php /var/www/html/wp-content/object-cache.php \
    && rm -rf /var/www/html/wp-content/plugins/bayam-whatsapp/tests \
    && mkdir -p /var/www/html/wp-content/uploads /var/www/html/wp-content/languages \
    && chown -R www-data:www-data /var/www/html /scripts
VOLUME ["/var/www/html/wp-content/uploads", "/var/www/html/wp-content/languages"]
