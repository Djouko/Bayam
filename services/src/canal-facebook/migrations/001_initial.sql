-- Pages Facebook connectées par les vendeurs : jeton de Page chiffré (coffre.ts), jamais en clair.
CREATE TABLE canal_facebook.pages (
  page text PRIMARY KEY,
  vendeur integer NOT NULL,
  nom text NOT NULL,
  jeton text NOT NULL,
  actif boolean NOT NULL DEFAULT true,
  connectee timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX pages_vendeur ON canal_facebook.pages (vendeur);

-- Liens personnels de commande envoyés dans Messenger : valables 24 h, aucune donnée lisible dans l'adresse.
CREATE TABLE canal_facebook.jetons_commande (
  jeton text PRIMARY KEY,
  vendeur integer NOT NULL,
  produit integer NOT NULL,
  page text NOT NULL,
  client text NOT NULL,
  cree timestamptz NOT NULL DEFAULT now(),
  expire timestamptz NOT NULL,
  ouvertures integer NOT NULL DEFAULT 0
);
CREATE INDEX jetons_commande_expire ON canal_facebook.jetons_commande (expire);

-- Publications et stories demandées par les vendeurs (file durable), puis le lien publication -> articles qui
-- permet de répondre aux commentaires avec le bon article.
CREATE TABLE canal_facebook.publications (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  vendeur integer NOT NULL,
  page text NOT NULL,
  type text NOT NULL CHECK (type IN ('publication', 'story')),
  produits integer[] NOT NULL,
  statut text NOT NULL DEFAULT 'attente' CHECK (statut IN ('attente', 'publie', 'echec')),
  essais integer NOT NULL DEFAULT 0,
  prochain timestamptz NOT NULL DEFAULT now(),
  publication text,
  cree timestamptz NOT NULL DEFAULT now(),
  publie timestamptz
);
CREATE INDEX publications_a_faire ON canal_facebook.publications (prochain) WHERE statut = 'attente';
CREATE UNIQUE INDEX publications_par_post ON canal_facebook.publications (publication) WHERE publication IS NOT NULL;

-- Confirmations de commande à envoyer dans Messenger (texte effacé une fois envoyé).
CREATE TABLE canal_facebook.notifications (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  cle text NOT NULL UNIQUE,
  page text NOT NULL,
  client text NOT NULL,
  texte text NOT NULL,
  statut text NOT NULL DEFAULT 'attente' CHECK (statut IN ('attente', 'envoye', 'echec')),
  essais integer NOT NULL DEFAULT 0,
  prochain timestamptz NOT NULL DEFAULT now(),
  cree timestamptz NOT NULL DEFAULT now(),
  envoye timestamptz
);
CREATE INDEX notifications_a_envoyer ON canal_facebook.notifications (prochain) WHERE statut = 'attente';

-- Événements déjà traités : une nouvelle livraison du bus ne renvoie pas les mêmes messages.
CREATE TABLE canal_facebook.evenements_traites (
  evt_id text PRIMARY KEY,
  traite timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX evenements_traites_date ON canal_facebook.evenements_traites (traite);
