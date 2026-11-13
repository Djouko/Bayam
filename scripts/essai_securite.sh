#!/usr/bin/env bash
# Essai de sécurité de la plateforme démarrée : rejoue les attaques de l'audit (S-01 à S-14) depuis l'intérieur
# (conteneur de la passerelle, position d'un attaquant qui l'aurait compromis) et vérifie les ports ouverts.
# Usage : bash scripts/essai_securite.sh
set -euo pipefail
cd "$(dirname "$0")/.."
export MSYS_NO_PATHCONV=1
jeton=$(grep -E '^BAYAM_SERVICE_JETON=' .env | cut -d= -f2-)
code=0
docker exec -i fluxstore-local-passerelle-1 node --input-type=module - "$jeton" < scripts/essais-securite/essai_securite.mjs || code=1
docker exec -i -w /app fluxstore-local-canal-whatsapp-1 node --input-type=module - < scripts/essais-securite/droits_nats.mjs || code=1
# S-05 : ports publiés sur cet ordinateur seulement (jamais sur le réseau local).
for p in 8080 8081 3000; do
  adresses=$(docker ps --format '{{.Ports}}' | tr ',' '\n' | grep -E ":$p->" || true)
  if [ -n "$adresses" ] && ! echo "$adresses" | grep -qvE '^ *127\.0\.0\.1:'; then
    echo "OK    S-05 port $p ouvert sur 127.0.0.1 seulement"
  else
    echo "ÉCHEC S-05 port $p : $adresses"; code=1
  fi
done
exit "$code"
