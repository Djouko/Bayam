#!/usr/bin/env bash
# Interrupteurs des fonctions de BAYAM : mettre une partie en maintenance sans toucher au reste ni redéployer.
# Les services, WordPress et les applications (GET /api/config) le voient en quelques secondes.
#   bash scripts/fonctions.sh liste
#   bash scripts/fonctions.sh maintenance facebook.commentaires 30 "Réponses aux commentaires en pause, retour à 15 h."
#   bash scripts/fonctions.sh arret whatsapp.assistant "L'assistant revient bientôt."
#   bash scripts/fonctions.sh testeurs whatsapp.copilote 4 7     (ces vendeurs gardent l'accès pour vérifier)
#   bash scripts/fonctions.sh actif facebook.commentaires
# Fonctions : whatsapp, whatsapp.catalogue, whatsapp.menu, whatsapp.copilote, whatsapp.publication,
# whatsapp.assistant, facebook, facebook.messenger, facebook.commentaires, facebook.publications,
# facebook.connexion, paiement, mobile, web (un préfixe vaut pour toutes ses fonctions).
set -euo pipefail
cd "$(dirname "$0")/.."
CLE=bayam:fonctions
redis() { docker compose exec -T redis redis-cli "$@"; }
json() { python -c 'import json,sys; print(json.dumps(dict(a.split("=",1) for a in sys.argv[1:]), ensure_ascii=False))' "$@"; }
case "${1:-liste}" in
  liste)
    sortie=$(redis HGETALL "$CLE")
    [ -z "$sortie" ] && echo "Toutes les fonctions sont actives." || echo "$sortie" | paste - - ;;
  maintenance)
    jusqua=$(python -c "import datetime,sys; print((datetime.datetime.now(datetime.timezone.utc)+datetime.timedelta(minutes=int(sys.argv[1]))).isoformat())" "${3:-30}")
    redis HSET "$CLE" "$2" "$(json etat=maintenance "jusqua=$jusqua" "message=${4:-}")" >/dev/null
    echo "« $2 » en maintenance jusqu'à $jusqua (UTC), rouverte toute seule ensuite." ;;
  arret)
    redis HSET "$CLE" "$2" "$(json etat=arret "message=${3:-}")" >/dev/null
    echo "« $2 » à l'arrêt jusqu'à « actif »." ;;
  testeurs)
    nom=$2; shift 2
    actuel=$(redis HGET "$CLE" "$nom")
    [ -z "$actuel" ] && { echo "« $nom » n'est ni en maintenance ni à l'arrêt."; exit 1; }
    redis HSET "$CLE" "$nom" "$(python -c 'import json,sys; d=json.loads(sys.argv[1]); d["testeurs"]=[int(x) for x in sys.argv[2:]]; print(json.dumps(d, ensure_ascii=False))' "$actuel" "$@")" >/dev/null
    echo "Testeurs de « $nom » : $*" ;;
  actif)
    redis HDEL "$CLE" "$2" >/dev/null
    echo "« $2 » de nouveau active." ;;
  *) sed -n 2,13p "$0"; exit 1 ;;
esac
