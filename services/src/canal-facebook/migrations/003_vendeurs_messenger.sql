-- Comptes Messenger reconnus comme le vendeur lui-même (lien signé ouvert depuis son compte Facebook) : ses messages
-- à sa Page vont à son menu et à ses commandes BAYAM, pas au catalogue des clients.
CREATE TABLE canal_facebook.vendeurs_messenger (
  page text NOT NULL,
  client text NOT NULL,
  vendeur integer NOT NULL,
  relie timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (page, client)
);
