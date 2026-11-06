-- Liens personnels de commande envoyés sur WhatsApp : valables 24 h, le numéro du client n'apparaît jamais dans l'adresse.
CREATE TABLE canal_whatsapp.jetons_commande (
  jeton text PRIMARY KEY,
  vendeur integer NOT NULL,
  produit integer NOT NULL,
  numero text NOT NULL DEFAULT '',
  chat text NOT NULL,
  cree timestamptz NOT NULL DEFAULT now(),
  expire timestamptz NOT NULL,
  ouvertures integer NOT NULL DEFAULT 0
);
CREATE INDEX jetons_commande_expire ON canal_whatsapp.jetons_commande (expire);

-- Messages envoyés par le service (contrôle des volumes, audit) : identifiants seulement, jamais le contenu.
CREATE TABLE canal_whatsapp.messages_envoyes (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  session text NOT NULL,
  chat text NOT NULL,
  nature text NOT NULL,
  message_id text,
  envoye timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX messages_envoyes_session ON canal_whatsapp.messages_envoyes (session, envoye);

-- Événements déjà traités par le catalogue : une nouvelle livraison du bus ne renvoie pas les mêmes messages.
CREATE TABLE canal_whatsapp.evenements_traites (
  evt_id text PRIMARY KEY,
  traite timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX evenements_traites_date ON canal_whatsapp.evenements_traites (traite);
