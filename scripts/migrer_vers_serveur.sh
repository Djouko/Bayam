#!/usr/bin/env bash
# Migration des données du poste vers le serveur, en une commande (DEPLOIEMENT.md, étape 3) :
#   1. arrête ce qui écrit ou parle à l'extérieur (WhatsApp, Facebook, travailleur, tunnel) : les sessions WhatsApp
#      ne doivent jamais tourner à deux endroits à la fois, et Meta ne doit plus envoyer ses événements au poste ;
#   2. envoie une sauvegarde complète dans Cloudflare R2 (bases, fichiers, sessions WhatsApp) ;
#   3. affiche le nom à donner à BAYAM_RESTAURER sur le serveur, puis arrête le reste de la plateforme locale.
# Usage : bash scripts/migrer_vers_serveur.sh
set -euo pipefail
cd "$(dirname "$0")/.."
export MSYS_NO_PATHCONV=1
nom="migration-$(date +%Y%m%d-%H%M)"
echo "1. Arrêt de WhatsApp, Facebook, du travailleur et du tunnel sur le poste"
docker compose stop waha passerelle canal-whatsapp canal-facebook retours travailleur tunnel veilleur
echo "2. Sauvegarde complète envoyée dans R2 : $nom"
docker compose --profile outils run --rm sauvegarde sauvegarder "$nom"
docker compose --profile outils run --rm sauvegarde lister
echo "3. Arrêt du reste de la plateforme locale (les données restent sur le poste)"
docker compose stop
echo
echo "À mettre dans Easypanel :  BAYAM_RESTAURER=$nom   et   RESTAURER_WHATSAPP=oui"
echo "Fichier d'environnement prêt à coller :  python scripts/env_production.py https://<adresse-easypanel> $nom"
