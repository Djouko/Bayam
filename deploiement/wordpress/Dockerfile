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
# /var/www/html est déclaré volume par l'image officielle : Docker Compose le garde d'un conteneur à l'autre et
# masquerait le code neuf à chaque déploiement. WordPress vit donc dans /srv/wordpress, qui suit toujours l'image.
RUN mkdir -p /srv/wordpress \
    && sed -ri 's#/var/www/html#/srv/wordpress#g; s#<Directory /var/www/>#<Directory /srv/>#g' \
       /etc/apache2/apache2.conf /etc/apache2/sites-available/*.conf /etc/apache2/conf-available/docker-php.conf \
    && cp -a /usr/src/wordpress/. /srv/wordpress/ && cp /usr/src/wordpress/wp-config-docker.php /srv/wordpress/wp-config.php
WORKDIR /srv/wordpress
COPY --from=extensions /x/plugins/ /srv/wordpress/wp-content/plugins/
COPY --from=extensions /x/themes/ /srv/wordpress/wp-content/themes/
COPY plugins/bayam-whatsapp /srv/wordpress/wp-content/plugins/bayam-whatsapp
COPY --from=dependances /app/vendor /srv/wordpress/wp-content/plugins/bayam-whatsapp/vendor
COPY plugins/bayam-cinetpay /srv/wordpress/wp-content/plugins/bayam-cinetpay
COPY themes/bayam-vitrine /srv/wordpress/wp-content/themes/bayam-vitrine
COPY mu-plugins /srv/wordpress/wp-content/mu-plugins
COPY scripts/installer.sh scripts/wp-cli.yml /scripts/
COPY scripts/catalogue /scripts/catalogue
COPY scripts/pages /scripts/pages
COPY scripts/medias /scripts/medias
COPY deploiement/wordpress/preparer.sh /scripts/preparer.sh
# Cache d'objets Redis actif sans écrire dans wp-content au démarrage ; essais retirés de l'image de production.
RUN cp /srv/wordpress/wp-content/plugins/redis-cache/includes/object-cache.php /srv/wordpress/wp-content/object-cache.php \
    && rm -rf /srv/wordpress/wp-content/plugins/bayam-whatsapp/tests \
    && mkdir -p /srv/wordpress/wp-content/uploads /srv/wordpress/wp-content/languages \
    && chown -R www-data:www-data /srv/wordpress /scripts
VOLUME ["/srv/wordpress/wp-content/uploads", "/srv/wordpress/wp-content/languages"]
