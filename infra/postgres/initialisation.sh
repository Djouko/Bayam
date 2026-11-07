#!/bin/sh
# Premier démarrage de PostgreSQL : un rôle et un schéma par service (moindre privilège). Chaque canal ne voit que
# ses propres tables ; personne ne peut créer d'objets dans le schéma public.
# Base existante (service ajouté plus tard) : relancer ce script dans le conteneur, il ne crée que ce qui manque.
set -eu
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<'SQL'
REVOKE ALL ON DATABASE bayam FROM PUBLIC;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
SQL
for service in canal_whatsapp canal_facebook; do
  variable=$(echo "${service}_DB_PASSWORD" | tr '[:lower:]' '[:upper:]')
  mdp=$(printenv "$variable" || true)
  if [ -z "$mdp" ]; then
    echo "Mot de passe absent ($variable) : rôle $service non créé" >&2
    continue
  fi
  psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" -v role="$service" -v mdp="$mdp" <<'SQL'
SELECT NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'role') AS a_creer \gset
\if :a_creer
CREATE ROLE :"role" LOGIN PASSWORD :'mdp' CONNECTION LIMIT 50;
GRANT CONNECT ON DATABASE bayam TO :"role";
CREATE SCHEMA :"role" AUTHORIZATION :"role";
ALTER ROLE :"role" SET search_path = :"role";
\endif
SQL
done
