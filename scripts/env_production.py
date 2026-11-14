"""Prépare deploiement/.env.production (jamais dans git) : le fichier à coller dans l'environnement d'Easypanel.

Usage : python scripts/env_production.py https://<adresse-easypanel> <nom-de-la-sauvegarde>

Garde du poste ce qui doit rester identique pour reprendre les données (clé des jetons Facebook, secrets WhatsApp,
comptes Meta, R2, paiements, assistant) ; génère de nouveaux secrets pour tout le reste (bases, bus, jetons, clés
WordPress). Rien n'est affiché : seul le chemin du fichier est écrit à l'écran.
"""
import pathlib
import re
import secrets
import sys

RACINE = pathlib.Path(__file__).resolve().parents[1]
GARDER = {'WAHA_API_KEY', 'WAHA_WEBHOOK_SECRET', 'RETOURS_ALERTE_WHATSAPP', 'FACEBOOK_APP_ID', 'FACEBOOK_APP_SECRET',
          'FACEBOOK_CONFIG_ID', 'FACEBOOK_JETON_VERIFICATION', 'FACEBOOK_CLE', 'CINETPAY_API_KEY', 'CINETPAY_API_PASSWORD',
          'CINETPAY_ENV', 'STRIPE_PUBLISHABLE_KEY', 'STRIPE_SECRET_KEY', 'STRIPE_WEBHOOK_SECRET', 'STRIPE_MODE',
          'ANTHROPIC_API_KEY', 'GEMINI_API_KEY', 'R2_ENDPOINT', 'R2_ACCESS_KEY', 'R2_SECRET_KEY', 'R2_BUCKET', 'R2_PUBLIC_URL',
          'WP_VENDOR_PASSWORD'}
NOUVEAUX = {'DB_PASSWORD', 'DB_ROOT_PASSWORD', 'POSTGRES_PASSWORD', 'CANAL_WHATSAPP_DB_PASSWORD', 'CANAL_FACEBOOK_DB_PASSWORD',
            'RETOURS_DB_PASSWORD', 'REDIS_MOT_DE_PASSE', 'NATS_MDP_PASSERELLE', 'NATS_MDP_CANAL_WHATSAPP',
            'NATS_MDP_CANAL_FACEBOOK', 'BAYAM_SERVICE_JETON', 'BAYAM_CLE_LIENS', 'WAHA_DASHBOARD_PASSWORD', 'WP_ADMIN_PASSWORD',
            'WORDPRESS_AUTH_KEY', 'WORDPRESS_SECURE_AUTH_KEY', 'WORDPRESS_LOGGED_IN_KEY', 'WORDPRESS_NONCE_KEY',
            'WORDPRESS_AUTH_SALT', 'WORDPRESS_SECURE_AUTH_SALT', 'WORDPRESS_LOGGED_IN_SALT', 'WORDPRESS_NONCE_SALT'}


def lire(chemin):
    valeurs = {}
    for ligne in chemin.read_text(encoding='utf-8').splitlines():
        m = re.match(r'^([A-Z0-9_]+)=(.*)$', ligne)
        if m:
            valeurs[m.group(1)] = m.group(2)
    return valeurs


def main():
    if len(sys.argv) != 3 or not re.match(r'^https://[a-z0-9.-]+$', sys.argv[1]):
        sys.exit(__doc__)
    local = lire(RACINE / '.env')
    sortie = []
    for ligne in (RACINE / '.env.example').read_text(encoding='utf-8').splitlines():
        m = re.match(r'^([A-Z0-9_]+)=(.*)$', ligne)
        if not m:
            sortie.append(ligne)
            continue
        nom, defaut = m.groups()
        if nom == 'BAYAM_URL_PUBLIQUE':
            valeur = sys.argv[1]
        elif nom == 'BAYAM_RESTAURER':
            valeur = sys.argv[2]
        elif nom == 'RESTAURER_WHATSAPP':
            valeur = 'oui'
        elif nom in NOUVEAUX:
            valeur = secrets.token_hex(32)
        elif nom in GARDER:
            valeur = local.get(nom, defaut)
        else:
            valeur = local.get(nom, defaut)
        sortie.append(f'{nom}={valeur}')
    cible = RACINE / 'deploiement' / '.env.production'
    cible.write_text('\n'.join(sortie) + '\n', encoding='utf-8')
    print(f'Écrit : {cible} (à coller dans Easypanel, onglet Environment ; ne jamais l\'envoyer ailleurs)')


if __name__ == '__main__':
    main()
