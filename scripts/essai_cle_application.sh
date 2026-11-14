#!/usr/bin/env bash
# Essai réel du verrou de la clé de l'application mobile (mu-plugin bayam-cle-application), par Internet comme un
# tiers qui aurait extrait la clé de l'APK : le catalogue se lit, rien d'autre. Sur le poste, vérifie aussi qu'une
# personne connectée lit bien ses propres commandes (cookie généré par WP-CLI).
# Usage : bash scripts/essai_cle_application.sh [adresse]   (défaut http://localhost:8080)
set -euo pipefail
cd "$(dirname "$0")/.."
export MSYS_NO_PATHCONV=1
base=${1:-http://localhost:8080}
ck=$(grep -E '^consumerKey=' scripts/cles_api.txt | cut -d= -f2-)
cs=$(grep -E '^consumerSecret=' scripts/cles_api.txt | cut -d= -f2-)
cle="consumer_key=$ck&consumer_secret=$cs"
echecs=0
statut() { curl -s -o /dev/null -w '%{http_code}' --max-time 40 -H 'X-Forwarded-Proto: https' "$@"; }  # HTTPS simulé sur le poste, réel en production
# Refus attendu : 403 du verrou BAYAM, ou 401 de WooCommerce quand la clé n'a pas le droit d'écrire.
refuse() { case "$2" in 401|403) echo "OK    $1 ($2)";; *) echo "ÉCHEC $1 : $2"; echecs=$((echecs + 1));; esac; }
verifier() { if [ "$2" = "$3" ]; then echo "OK    $1"; else echo "ÉCHEC $1 : $2 au lieu de $3"; echecs=$((echecs + 1)); fi; }

verifier "catalogue : produits lisibles" "$(statut "$base/wp-json/wc/v3/products?per_page=1&$cle")" 200
verifier "catalogue : rayons lisibles" "$(statut "$base/wp-json/wc/v3/products/categories?per_page=1&$cle")" 200
verifier "liste des clients refusée" "$(statut "$base/wp-json/wc/v3/customers?$cle")" 403
verifier "compte d'un client sans sa session : refusé" "$(statut "$base/wp-json/wc/v3/customers/1?$cle")" 403
verifier "toutes les commandes : refusé" "$(statut "$base/wp-json/wc/v3/orders?$cle")" 403
verifier "commandes d'un autre client : refusé" "$(statut "$base/wp-json/wc/v3/orders?customer=2&$cle")" 403
verifier "rapports et réglages : refusés" "$(statut "$base/wp-json/wc/v3/settings/general?$cle")" 403
# Écritures visant des numéros inexistants : même si une protection manquait, aucune donnée ne serait touchée.
refuse "écriture (modification de produit) : refusée" "$(statut -X PUT -H 'content-type: application/json' -d '{"regular_price":"1"}' "$base/wp-json/wc/v3/products/999999999?$cle")"
refuse "suppression d'une commande : refusée" "$(statut -X DELETE "$base/wp-json/wc/v3/orders/999999999?$cle")"
verifier "liste des codes promo sans code précis : refusée" "$(statut "$base/wp-json/wc/v3/coupons?$cle")" 403
reglages=$(curl -s --max-time 40 -H 'X-Forwarded-Proto: https' "$base/wp-json/wc/v3/payment_gateways?$cle" | grep -c '"settings"' || true)
verifier "moyens de paiement sans leurs réglages" "$reglages" 0

# Sur le poste : une vraie cliente connectée lit ses commandes, pas celles des autres.
if [ "$base" = "http://localhost:8080" ]; then
  donnees=$(docker compose run --rm -T cli wp eval '
    $u = get_users(["role" => "customer", "number" => 1])[0] ?? null;
    if ($u) { echo $u->ID, " ", base64_encode(wp_generate_auth_cookie($u->ID, time() + 600, "logged_in")); }' 2>/dev/null | tail -1)
  id=${donnees%% *}; cookie=${donnees#* }
  if [ -n "$id" ] && [ "$id" != "$donnees" ]; then
    verifier "cliente connectée : ses propres commandes" "$(statut -H "User-Cookie: $cookie" "$base/wp-json/wc/v3/orders?customer=$id&$cle")" 200
    verifier "cliente connectée : son propre compte" "$(statut -H "User-Cookie: $cookie" "$base/wp-json/wc/v3/customers/$id?$cle")" 200
    verifier "cliente connectée : le compte d'une autre personne reste refusé" "$(statut -H "User-Cookie: $cookie" "$base/wp-json/wc/v3/customers/1?$cle")" 403
  else
    echo "ÉCHEC aucune cliente trouvée pour l'essai positif"; echecs=$((echecs + 1))
  fi
fi
[ "$echecs" = 0 ] && echo "TOUT EST BON" || { echo "$echecs ÉCHEC(S)"; exit 1; }
