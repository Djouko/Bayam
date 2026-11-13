-- Sujets : un problème ou une idée, qui regroupe tous les signalements identiques (doublons) avec le nombre de
-- personnes touchées. C'est l'unité de travail de l'équipe (statut, responsable, résolution).
CREATE TABLE retours.sujets (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  titre text NOT NULL,
  type text NOT NULL,
  categorie text NOT NULL,
  gravite smallint NOT NULL CHECK (gravite BETWEEN 1 AND 4),
  statut text NOT NULL DEFAULT 'nouveau',
  fonction text,
  -- Panne détectée par une sonde : une clé stable, pour rouvrir le même sujet et le clore au rétablissement.
  cle_incident text UNIQUE,
  signaleurs integer NOT NULL DEFAULT 0,
  signalements integer NOT NULL DEFAULT 0,
  alerte smallint NOT NULL DEFAULT 0,
  assigne text,
  resolution text,
  doublon_de bigint REFERENCES retours.sujets (id),
  premier timestamptz NOT NULL DEFAULT now(),
  dernier timestamptz NOT NULL DEFAULT now(),
  modifie timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX sujets_ouverts ON retours.sujets (categorie, dernier DESC) WHERE statut IN ('nouveau', 'confirme', 'en_cours');
CREATE INDEX sujets_statut ON retours.sujets (statut, dernier DESC);

-- Signalements reçus. « personne » est une empreinte (HMAC) de l'expéditeur : elle compte les personnes distinctes
-- sans garder son identifiant. « contact » sert seulement à prévenir la personne quand c'est corrigé, puis s'efface.
CREATE TABLE retours.signalements (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  sujet bigint NOT NULL REFERENCES retours.sujets (id),
  type text NOT NULL,
  texte text NOT NULL,
  canal text NOT NULL,
  auteur text NOT NULL DEFAULT '',
  personne text NOT NULL,
  vendeur integer,
  contact jsonb,
  contexte jsonb NOT NULL DEFAULT '{}',
  prevenu timestamptz,
  cree timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX signalements_sujet ON retours.signalements (sujet, cree DESC);
CREATE INDEX signalements_personne ON retours.signalements (sujet, personne);

-- Journal de chaque décision prise sur un sujet (qui, quoi, quand) : traçabilité et revue après incident.
CREATE TABLE retours.historique (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  sujet bigint NOT NULL REFERENCES retours.sujets (id),
  action text NOT NULL,
  auteur text NOT NULL,
  details jsonb NOT NULL DEFAULT '{}',
  quand timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX historique_sujet ON retours.historique (sujet, quand DESC);
