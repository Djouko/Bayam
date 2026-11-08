-- Autorisations accordées par le vendeur à la connexion : BAYAM sait d'avance ce qu'il peut faire sur la Page,
-- et explique au vendeur ce qui manque au lieu d'échouer en silence.
ALTER TABLE canal_facebook.pages ADD COLUMN permissions text[] NOT NULL DEFAULT '{}';
