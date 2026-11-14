#!/usr/bin/env bash
# Préparation de WordPress à chaque déploiement, avant que le site ne reçoive du trafic (service « preparation » de
# docker-compose.prod.yml). Rejouable sans risque : chaque étape vérifie l'existant.
#   1. attend la base ;
#   2. base vide (premier déploiement sans restauration) : installation complète (scripts/installer.sh) ;
#   3. mises à jour de la base (WordPress, WooCommerce), extensions actives, traductions ;
#   4. adresse du site changée (poste vers serveur, nouveau domaine) : liens réécrits partout en base ;
#   5. caches vidés.
set -euo pipefail
cd /var/www/html
journal() { printf '{"t":"%s","service":"preparation","message":"%s"}\n' "$(date -u +%FT%TZ)" "$1"; }

: "${BAYAM_URL_PUBLIQUE:?BAYAM_URL_PUBLIQUE manquant (adresse publique du site, https://…)}"

for essai in $(seq 1 60); do
  php -r 'exit(@mysqli_connect(getenv("WORDPRESS_DB_HOST"), getenv("WORDPRESS_DB_USER"), getenv("WORDPRESS_DB_PASSWORD"), getenv("WORDPRESS_DB_NAME")) ? 0 : 1);' && break
  [ "$essai" = 60 ] && { journal "base injoignable"; exit 1; }
  sleep 2
done

if ! wp core is-installed 2>/dev/null; then
  journal "base vide : installation complète"
  WP_URL="$BAYAM_URL_PUBLIQUE" bash /scripts/installer.sh
fi

wp core update-db --quiet
wp plugin activate woocommerce mstore-api wc-frontend-manager wc-multivendor-marketplace wc-multivendor-membership \
  woocommerce-gateway-stripe redis-cache bayam-whatsapp bayam-cinetpay --quiet
wp wc update --quiet 2>/dev/null || true   # rien à faire : la commande le dit sans échouer
# Traductions (volume « langues ») : réseau parfois lent, trois essais avant de remettre au déploiement suivant.
for essai in 1 2 3; do
  wp language core install fr_FR --activate --quiet 2>/dev/null && break
  [ "$essai" = 3 ] && journal "traduction du cœur non téléchargée (réessai au prochain déploiement)"
  sleep 5
done
wp language plugin install --all fr_FR --quiet 2>/dev/null || true

# Adresse enregistrée en base (celle du poste après une restauration) remplacée par l'adresse publique, partout.
ancienne=$(wp eval 'global $wpdb; echo $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = \"home\"");')
if [ -n "$ancienne" ] && [ "$ancienne" != "$BAYAM_URL_PUBLIQUE" ]; then
  journal "adresse du site : remplacement de l'ancienne adresse en base"
  wp search-replace "$ancienne" "$BAYAM_URL_PUBLIQUE" --all-tables-with-prefix --skip-columns=guid --precise --quiet
  wp eval 'global $wpdb; foreach (["home", "siteurl"] as $o) { $wpdb->update($wpdb->options, ["option_value" => getenv("BAYAM_URL_PUBLIQUE")], ["option_name" => $o]); }'
fi

# Clé de l'application mobile : lecture seule à chaque déploiement (le verrou bayam-cle-application fait le reste).
wp eval 'global $wpdb; $n = $wpdb->query("UPDATE {$wpdb->prefix}woocommerce_api_keys SET permissions = \"read\" WHERE description LIKE \"Application%\" AND permissions <> \"read\""); if ($n) { echo "cle application passee en lecture seule", PHP_EOL; }'

wp rewrite flush --quiet   # règles en base ; le .htaccess standard est déjà dans l'image
wp cache flush --quiet
journal "WordPress prêt"
