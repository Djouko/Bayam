#!/usr/bin/env bash
# Essai de bout en bout du canal Facebook sans application Meta : un faux Graph remplace Meta, tout le reste est réel
# (Traefik, passerelle, NATS, canal-facebook, WordPress, liens de commande). Boutique 4 (Accessoires Akwa, sans
# WhatsApp relié) : aucun message ne part vers un vrai téléphone. Les données d'essai sont effacées à la fin et les
# services reprennent leur configuration normale.
# Lancer depuis plateforme-locale : bash scripts/essai_facebook.sh
set -euo pipefail
cd "$(dirname "$0")/.."
export MSYS_NO_PATHCONV=1
ESSAIS="$(pwd -W 2>/dev/null || pwd)/scripts/essais-facebook"
SURCHARGE="$ESSAIS/essai.override.yml"
ESSAI_FB_SECRET=$(openssl rand -hex 24)
ESSAI_FB_VERIFICATION=$(openssl rand -hex 12)
export ESSAI_FB_SECRET ESSAI_FB_VERIFICATION
JETON_SERVICE=$(grep '^BAYAM_SERVICE_JETON=' .env | cut -d= -f2-)
PAGE=900000000000004

remettre_en_etat() {
  docker compose exec -T postgres psql -q -U bayam_admin -d bayam -c "
    DELETE FROM canal_facebook.pages WHERE page = '$PAGE'; DELETE FROM canal_facebook.publications WHERE page = '$PAGE';
    DELETE FROM canal_facebook.jetons_commande WHERE page = '$PAGE'; DELETE FROM canal_facebook.notifications WHERE page = '$PAGE';
    DELETE FROM canal_facebook.evenements_traites WHERE evt_id LIKE 'm.essai.%' OR evt_id LIKE 'm.vendeur.%' OR evt_id LIKE '%$PAGE%';" >/dev/null
  docker compose exec -T redis sh -c "for k in \$(redis-cli --scan --pattern 'fb:*$PAGE*'); do redis-cli del \"\$k\" >/dev/null; done"
  docker compose exec -T -e MYSQL_PWD="$(grep '^DB_PASSWORD=' .env | cut -d= -f2-)" db mariadb -u wordpress wordpress \
    -e "DELETE FROM wp_woocommerce_sessions WHERE session_value LIKE '%$PAGE%'"
  docker stop faux-graph >/dev/null 2>&1 || true
  docker compose up -d passerelle canal-facebook >/dev/null 2>&1
  echo "Données d'essai effacées, services remis dans leur configuration normale."
}
trap remettre_en_etat EXIT

docker run -d --rm --name faux-graph --network fluxstore-local_default -e SECRET="$ESSAI_FB_SECRET" \
  -v "$ESSAIS:/essai:ro" node:24.22.2-alpine node /essai/faux_graph.mjs >/dev/null
docker compose -f docker-compose.yml -f "$SURCHARGE" up -d passerelle canal-facebook >/dev/null 2>&1
for _ in $(seq 1 30); do
  etat=$(docker compose ps --format '{{.Health}}' passerelle canal-facebook | sort -u | tr '\n' ' ')
  [ "$etat" = "healthy " ] && break
  sleep 2
done
docker run --rm --network fluxstore-local_default -e SECRET="$ESSAI_FB_SECRET" -e VERIFICATION="$ESSAI_FB_VERIFICATION" \
  -e JETON_SERVICE="$JETON_SERVICE" -v "$ESSAIS:/essai:ro" node:24.22.2-alpine node /essai/essai_facebook.mjs
