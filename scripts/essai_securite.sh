#!/usr/bin/env bash
# Essai de sécurité de la plateforme démarrée : rejoue les attaques de l'audit (S-01 à S-14) depuis l'intérieur
# (conteneur de la passerelle, position d'un attaquant qui l'aurait compromis) et vérifie les ports ouverts.
# Usage : bash scripts/essai_securite.sh
set -euo pipefail
cd "$(dirname "$0")/.."
export MSYS_NO_PATHCONV=1
. scripts/commun.sh
jeton=$(valeur BAYAM_SERVICE_JETON)
# Hôte attendu par WordPress : le domaine sur le serveur, localhost:8080 sur le poste.
hote=$(valeur BAYAM_URL_PUBLIQUE | sed -E "s#^https?://##")
hote=${hote:-localhost:8080}
code=0
docker exec -i "$(conteneur passerelle)" node --input-type=module - "$jeton" "$hote" < scripts/essais-securite/essai_securite.mjs || code=1
docker exec -i -w /app "$(conteneur canal-whatsapp)" node --input-type=module - < scripts/essais-securite/droits_nats.mjs || code=1
# S-05 : ports publiés sur cet ordinateur seulement (jamais sur le réseau local).
for p in 8080 8081 3000; do
  adresses=$(docker ps --format '{{.Ports}}' | tr ',' '\n' | grep -E ":$p->" || true)
  if [ -z "$adresses" ]; then
    echo "OK    S-05 port $p : non publié"
  elif ! echo "$adresses" | grep -qvE '^ *127\.0\.0\.1:'; then
    echo "OK    S-05 port $p ouvert sur 127.0.0.1 seulement"
  else
    echo "ÉCHEC S-05 port $p : $adresses"; code=1
  fi
done
exit "$code"
