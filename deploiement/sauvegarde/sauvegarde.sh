#!/usr/bin/env bash
# Sauvegardes de BAYAM dans Cloudflare R2 (offre gratuite : 10 Go, 1 million d'écritures par mois ; on reste sous
# 8 Go). Tout est rangé sous bayam/sauvegardes/<nom>/ et rien d'autre du compartiment n'est jamais touché.
#
#   sauvegarde sauvegarder [nom]   bases (MariaDB, PostgreSQL), fichiers envoyés, sessions WhatsApp ; puis ménage
#   sauvegarde restaurer <nom>     remet une sauvegarde en place (bases, fichiers, sessions WhatsApp si demandé)
#   sauvegarde premier-demarrage   restaure BAYAM_RESTAURER une seule fois (déploiement sur un nouveau serveur)
#   sauvegarde lister              sauvegardes présentes et place occupée
#   sauvegarde supprimer <nom>     efface une sauvegarde (essais)
#   sauvegarde planifier           sauvegarde chaque nuit (service « sauvegarde »)
#
# Variables : R2_ENDPOINT, R2_ACCESS_KEY, R2_SECRET_KEY, R2_BUCKET ; DB_HOST, DB_PASSWORD (MariaDB, base wordpress) ;
# PGHOST, POSTGRES_PASSWORD (PostgreSQL, base bayam) ; CHEMIN_UPLOADS, CHEMIN_WAHA (dossiers montés).
set -euo pipefail

PREFIXE="bayam/sauvegardes"
PLAFOND_OCTETS=$((8 * 1024 * 1024 * 1024))
GARDER_QUOTIDIENNES=7
CHEMIN_UPLOADS="${CHEMIN_UPLOADS:-/donnees/uploads}"
CHEMIN_WAHA="${CHEMIN_WAHA:-/donnees/waha}"
TRAVAIL=$(mktemp -d)
trap 'rm -rf "$TRAVAIL"' EXIT

journal() { printf '{"t":"%s","service":"sauvegarde","niveau":"%s","message":"%s"}\n' "$(date -u +%FT%TZ)" "$1" "$2"; }
r2() { # r2 <méthode> <clé> [fichier à envoyer | fichier où écrire]
  local methode=$1 cle=$2 fichier=${3:-}
  local url="${R2_ENDPOINT%/}/${R2_BUCKET}/${cle}"
  case "$methode" in
    PUT) curl -fsS --retry 3 --aws-sigv4 "aws:amz:auto:s3" --user "$R2_ACCESS_KEY:$R2_SECRET_KEY" -T "$fichier" "$url" ;;
    GET) curl -fsS --retry 3 --aws-sigv4 "aws:amz:auto:s3" --user "$R2_ACCESS_KEY:$R2_SECRET_KEY" -o "$fichier" "$url" ;;
    DELETE) curl -fsS --retry 3 --aws-sigv4 "aws:amz:auto:s3" --user "$R2_ACCESS_KEY:$R2_SECRET_KEY" -X DELETE "$url" ;;
  esac
}
# Liste « taille clé » des objets sous un préfixe (pages de 1000).
r2_lister() {
  local suite="" page
  while :; do
    page=$(curl -fsS --retry 3 --aws-sigv4 "aws:amz:auto:s3" --user "$R2_ACCESS_KEY:$R2_SECRET_KEY" \
      "${R2_ENDPOINT%/}/${R2_BUCKET}?list-type=2&prefix=$1&max-keys=1000${suite:+&continuation-token=$suite}")
    echo "$page" | sed 's#</Contents>#\n#g' | sed -nE 's#.*<Key>([^<]*)</Key>.*<Size>([0-9]*)</Size>.*#\2 \1#p'
    echo "$page" | grep -q '<IsTruncated>true</IsTruncated>' || break
    suite=$(echo "$page" | sed -nE 's#.*<NextContinuationToken>([^<]*)</NextContinuationToken>.*#\1#p' | sed 's/+/%2B/g;s/\//%2F/g;s/=/%3D/g')
  done
}
attendre() { # attendre <description> <commande…>
  local quoi=$1; shift
  for _ in $(seq 1 60); do "$@" >/dev/null 2>&1 && return 0; sleep 2; done
  journal erreur "$quoi injoignable"; return 1
}
maria() { local outil=$1; shift; MYSQL_PWD="$DB_PASSWORD" "$outil" -h "${DB_HOST:-db}" -u wordpress "$@"; }

sauvegarder() {
  local nom=${1:-quotidienne-$(date +%Y%m%d-%H%M)} occupe
  occupe=$(r2_lister "bayam/" | awk '{s+=$1} END {print s+0}')
  if [ "$occupe" -gt "$PLAFOND_OCTETS" ]; then
    journal erreur "R2 : $occupe octets occupés, au-delà du plafond de sécurité ; sauvegarde annulée (offre gratuite)"; return 1
  fi
  attendre MariaDB maria mariadb -e 'SELECT 1'
  attendre PostgreSQL pg_isready -h "${PGHOST:-postgres}" -U "${PGUSER:-bayam_admin}"
  maria mariadb-dump --single-transaction --quick --routines --triggers --no-tablespaces wordpress | zstd -q -10 -o "$TRAVAIL/mariadb.sql.zst"
  PGPASSWORD="$POSTGRES_PASSWORD" pg_dump -h "${PGHOST:-postgres}" -U "${PGUSER:-bayam_admin}" -Fc bayam -f "$TRAVAIL/postgres.dump"
  [ -d "$CHEMIN_UPLOADS" ] && tar -C "$CHEMIN_UPLOADS" -cf - . | zstd -q -3 -o "$TRAVAIL/uploads.tar.zst"
  [ -d "$CHEMIN_WAHA" ] && tar -C "$CHEMIN_WAHA" -cf - . | zstd -q -3 -o "$TRAVAIL/waha.tar.zst"
  for f in "$TRAVAIL"/*; do r2 PUT "$PREFIXE/$nom/$(basename "$f")" "$f"; done
  journal info "sauvegarde $nom envoyée ($(du -sh "$TRAVAIL" | cut -f1))"
  menage
}

# Garde les 7 dernières sauvegardes quotidiennes ; les sauvegardes nommées (migration, avant mise à jour) restent.
menage() {
  local anciennes
  anciennes=$(r2_lister "$PREFIXE/quotidienne-" | awk '{print $2}' | cut -d/ -f3 | sort -u \
    | awk -v g="$GARDER_QUOTIDIENNES" '{n[NR] = $0} END {for (i = 1; i <= NR - g; i++) print n[i]}')
  for nom in $anciennes; do
    for cle in $(r2_lister "$PREFIXE/$nom/" | awk '{print $2}'); do r2 DELETE "$cle"; done
    journal info "ancienne sauvegarde $nom supprimée"
  done
}

restaurer() {
  local nom=${1:?nom de la sauvegarde}
  for f in mariadb.sql.zst postgres.dump; do r2 GET "$PREFIXE/$nom/$f" "$TRAVAIL/$f"; done
  attendre MariaDB maria mariadb -e 'SELECT 1'
  attendre PostgreSQL pg_isready -h "${PGHOST:-postgres}" -U "${PGUSER:-bayam_admin}"
  zstd -dc "$TRAVAIL/mariadb.sql.zst" | maria mariadb wordpress
  # Objets recréés avec leurs propriétaires d'origine (rôles des services créés au premier démarrage de PostgreSQL).
  PGPASSWORD="$POSTGRES_PASSWORD" pg_restore -h "${PGHOST:-postgres}" -U "${PGUSER:-bayam_admin}" -d bayam --clean --if-exists "$TRAVAIL/postgres.dump"
  if r2 GET "$PREFIXE/$nom/uploads.tar.zst" "$TRAVAIL/uploads.tar.zst" 2>/dev/null; then
    mkdir -p "$CHEMIN_UPLOADS" && zstd -dc "$TRAVAIL/uploads.tar.zst" | tar -C "$CHEMIN_UPLOADS" -xf - && chown -R 33:33 "$CHEMIN_UPLOADS"
  fi
  # Sessions WhatsApp : seulement sur demande, et jamais pendant qu'elles tournent ailleurs (WhatsApp déconnecterait).
  if [ "${RESTAURER_WHATSAPP:-non}" = oui ] && r2 GET "$PREFIXE/$nom/waha.tar.zst" "$TRAVAIL/waha.tar.zst" 2>/dev/null; then
    mkdir -p "$CHEMIN_WAHA" && zstd -dc "$TRAVAIL/waha.tar.zst" | tar -C "$CHEMIN_WAHA" -xf -
  fi
  journal info "sauvegarde $nom restaurée"
}

# Premier déploiement sur un serveur : restaure BAYAM_RESTAURER une seule fois (repère dans le volume d'état), puis
# rend la main ; les déploiements suivants ne touchent plus aux données.
premier_demarrage() {
  local nom=${BAYAM_RESTAURER:-} repere="/etat/restaure-${BAYAM_RESTAURER:-aucune}"
  if [ -z "$nom" ] || [ -e "$repere" ]; then
    journal info "aucune restauration à faire"; return 0
  fi
  restaurer "$nom"
  mkdir -p /etat && date -u +%FT%TZ > "$repere"
}

lister() {
  r2_lister "$PREFIXE/" | awk '{split($2, c, "/"); t[c[3]] += $1; s += $1} END {for (n in t) printf "%-40s %8.1f Mo\n", n, t[n]/1048576; printf "Total : %.1f Mo (plafond de sécurité 8 Go)\n", s/1048576}' | sort
}

supprimer() {
  local nom=${1:?nom de la sauvegarde}
  for cle in $(r2_lister "$PREFIXE/$nom/" | awk '{print $2}'); do r2 DELETE "$cle"; done
  journal info "sauvegarde $nom supprimée"
}

case "${1:-}" in
  sauvegarder) sauvegarder "${2:-}" ;;
  supprimer) supprimer "${2:-}" ;;
  restaurer) restaurer "${2:-}" ;;
  premier-demarrage) premier_demarrage ;;
  lister) lister ;;
  planifier) journal info "sauvegarde planifiée chaque nuit à 3 h"; exec crond -f -l 8 ;;
  *) sed -n '2,13p' "$0"; exit 1 ;;
esac
