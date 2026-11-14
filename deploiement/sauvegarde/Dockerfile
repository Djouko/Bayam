# Sauvegardes et restaurations de BAYAM vers Cloudflare R2 (contexte : racine du dépôt). Base PostgreSQL 18 pour
# avoir pg_dump et pg_restore de la même version que le serveur ; client MariaDB, curl (signature S3 pour R2), zstd.
FROM postgres:18.6-alpine
RUN apk add --no-cache bash curl zstd tar mariadb-client tzdata
COPY deploiement/sauvegarde/sauvegarde.sh /usr/local/bin/sauvegarde
COPY deploiement/sauvegarde/crontab /etc/crontabs/root
RUN chmod +x /usr/local/bin/sauvegarde
ENTRYPOINT ["/usr/local/bin/sauvegarde"]
CMD ["planifier"]
