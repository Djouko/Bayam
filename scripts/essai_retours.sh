#!/usr/bin/env bash
# Essai réel du service des retours (plateforme démarrée) : envoie de vrais signalements par Traefik et par le réseau
# interne, vérifie la réponse WhatsApp sur le téléphone d'essai et l'alerte de l'équipe, puis efface tout ce qu'il a créé.
# Usage : bash scripts/essai_retours.sh
set -euo pipefail
cd "$(dirname "$0")/.."
export MSYS_NO_PATHCONV=1
. scripts/commun.sh
jeton=$(valeur BAYAM_SERVICE_JETON)
waha=$(valeur WAHA_API_KEY)
sortie=$(docker exec -i "$(conteneur canal-whatsapp)" node --input-type=module - "$jeton" "$waha" < scripts/essais-retours/essai_retours.mjs) && code=0 || code=$?
echo "$sortie" | grep -v '^SUJETS '
sujets=$(echo "$sortie" | sed -n 's/^SUJETS //p')
if [ -n "$sujets" ]; then
  docker exec -i "$(conteneur postgres)" psql -q -U bayam_admin -d bayam -v ON_ERROR_STOP=1 -c "
    DELETE FROM retours.historique WHERE sujet IN ($sujets);
    DELETE FROM retours.signalements WHERE sujet IN ($sujets);
    UPDATE retours.sujets SET doublon_de = NULL WHERE doublon_de IN ($sujets);
    DELETE FROM retours.sujets WHERE id IN ($sujets);" && echo "Données d'essai effacées (sujets $sujets)."
fi
exit "$code"
