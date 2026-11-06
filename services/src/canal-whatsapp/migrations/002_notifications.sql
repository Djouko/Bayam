-- Boîte d'envoi des notifications (confirmation de commande au client…) déposées par les autres services :
-- l'envoi se fait à part, au rythme humain, avec nouvelles tentatives ; le texte est effacé une fois envoyé.
CREATE TABLE canal_whatsapp.notifications (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  cle text NOT NULL UNIQUE,
  session text NOT NULL,
  chat text NOT NULL,
  texte text NOT NULL,
  statut text NOT NULL DEFAULT 'attente' CHECK (statut IN ('attente', 'envoye', 'echec')),
  essais integer NOT NULL DEFAULT 0,
  prochain timestamptz NOT NULL DEFAULT now(),
  cree timestamptz NOT NULL DEFAULT now(),
  envoye timestamptz
);
CREATE INDEX notifications_a_envoyer ON canal_whatsapp.notifications (prochain) WHERE statut = 'attente';
