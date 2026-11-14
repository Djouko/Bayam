#!/usr/bin/env bash
# Outils communs des scripts : retrouver un conteneur de BAYAM sur le poste comme sur le serveur (Easypanel choisit
# lui-même le nom du projet Compose). BAYAM_PROJET force un projet ; sinon le poste (fluxstore-local) s'il tourne,
# sinon le seul projet qui contient une passerelle BAYAM.

projet() {
  if [ -n "${BAYAM_PROJET:-}" ]; then echo "$BAYAM_PROJET"; return; fi
  local projets
  projets=$(docker ps --filter label=com.docker.compose.service=passerelle --format '{{.Label "com.docker.compose.project"}}' | sort -u)
  if echo "$projets" | grep -qx fluxstore-local; then echo fluxstore-local; return; fi
  [ "$(echo "$projets" | grep -c .)" = 1 ] || { echo "Projet BAYAM introuvable ou ambigu ($projets) : définir BAYAM_PROJET" >&2; return 1; }
  echo "$projets"
}

# conteneur <service> : identifiant d'un conteneur en marche de ce service.
conteneur() {
  local id
  id=$(docker ps -q --filter "label=com.docker.compose.project=$(projet)" --filter "label=com.docker.compose.service=$1" | head -1)
  [ -n "$id" ] || { echo "Conteneur « $1 » introuvable" >&2; return 1; }
  echo "$id"
}

# valeur <NOM> : variable du fichier .env (poste, ou celui écrit par Easypanel sur le serveur) ; BAYAM_ENV pour un autre fichier.
valeur() { { grep -E "^$1=" "${BAYAM_ENV:-.env}" || true; } | head -1 | cut -d= -f2-; }
