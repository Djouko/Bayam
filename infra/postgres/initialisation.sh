#!/bin/sh
# Premier démarrage de PostgreSQL : un rôle et un schéma par service (moindre privilège). Le canal WhatsApp ne voit
# que ses propres tables ; personne ne peut créer d'objets dans le schéma public.
set -eu
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" -v mdp_canal="$CANAL_WHATSAPP_DB_PASSWORD" <<'SQL'
REVOKE ALL ON DATABASE bayam FROM PUBLIC;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
CREATE ROLE canal_whatsapp LOGIN PASSWORD :'mdp_canal' CONNECTION LIMIT 50;
GRANT CONNECT ON DATABASE bayam TO canal_whatsapp;
CREATE SCHEMA canal_whatsapp AUTHORIZATION canal_whatsapp;
ALTER ROLE canal_whatsapp SET search_path = canal_whatsapp;
SQL
