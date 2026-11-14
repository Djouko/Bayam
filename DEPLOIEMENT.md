# Mettre BAYAM en ligne sur le serveur (Easypanel)

Ce guide se suit dans l'ordre, une seule fois. Ensuite, chaque `git push` met le serveur à jour tout seul.

## Comment c'est organisé
- Easypanel déploie BAYAM comme un service **Compose** à partir de GitHub, avec le fichier `docker-compose.prod.yml`. Chaque microservice garde son propre conteneur : une panne reste isolée et chaque service redémarre seul.
- À chaque déploiement, Easypanel reconstruit les images depuis le dépôt (`docker compose up --build -d`). Les données (bases, photos, sessions WhatsApp) restent dans les volumes.
- Ordre garanti au démarrage : bases, puis restauration (une seule fois), puis préparation de WordPress (mises à jour de la base, adresse du site), puis le reste.
- L'adresse publique d'Easypanel pointe sur le conteneur `traefik` (port 80), qui garde nos règles : limites de débit, en-têtes de sécurité, routes internes fermées.
- Chaque nuit à 3 h, une sauvegarde part dans Cloudflare R2. Les 7 dernières sont gardées, sous 8 Go (offre gratuite).

## Étape 1 : envoyer le code sur GitHub (sur le poste)
1. Sur github.com, créez un dépôt **privé** vide nommé `bayam-plateforme`, sans README, sans licence et sans .gitignore.
2. Dans un terminal, dans le dossier `plateforme-locale` :
   ```
   git remote add origin https://github.com/<votre-compte>/bayam-plateforme.git
   git push -u origin master
   ```
   Le fichier `.env` (vos secrets) n'est jamais envoyé : il est exclu par `.gitignore`.

## Étape 2 : créer le service dans Easypanel
1. Ouvrez Easypanel, puis **Create Project**, avec le nom `bayam`.
2. Dans le projet, cliquez sur **+ Service**, puis **Compose**, avec le nom `plateforme`.
3. Onglet **Source**, choisissez **Git** :
   - Repository URL : `git@github.com:<votre-compte>/bayam-plateforme.git`
   - Branch : `master`
   - Build Path : `/`
   - Docker Compose File : `docker-compose.prod.yml`
4. Easypanel affiche une **clé SSH** (deploy key). Sur GitHub, ouvrez le dépôt, puis **Settings**, **Deploy keys**, **Add deploy key**. Collez la clé, laissez « Allow write access » décoché, et enregistrez.
5. Enregistrez la source dans Easypanel, **sans déployer** pour l'instant.
6. Onglet **Domains**, cliquez sur **Add Domain** :
   - gardez l'adresse proposée par Easypanel (du type `plateforme-bayam.xxxx.easypanel.host`) ;
   - Service : `traefik` ;
   - Port : `80` ;
   - HTTPS : activé.

   Notez cette adresse : c'est `<adresse>` dans la suite.

## Étape 3 : transférer les données du poste et préparer l'environnement (sur le poste)
1. Le poste doit être allumé, Docker démarré et la plateforme locale en marche. Dans `plateforme-locale` :
   ```
   bash scripts/migrer_vers_serveur.sh
   ```
   Le script arrête WhatsApp et Facebook sur le poste, puis envoie une sauvegarde complète dans R2. Il affiche ensuite son nom, du type `migration-20261114-0930`, et arrête la plateforme locale.
   Important : ne relancez plus WhatsApp sur le poste. Une même session WhatsApp ne doit jamais tourner à deux endroits.
2. Générez le fichier d'environnement de production :
   ```
   python scripts/env_production.py https://<adresse> <nom-de-la-sauvegarde>
   ```
   Le fichier `deploiement/.env.production` est créé :
   - il garde ce qui doit rester identique pour reprendre les données (clé des jetons Facebook, secrets WhatsApp, R2, paiements) ;
   - il crée de nouveaux secrets pour tout le reste ;
   - il n'est jamais envoyé sur GitHub.
3. Dans Easypanel, onglet **Environment** du service `plateforme` :
   - collez tout le contenu de `deploiement/.env.production` ;
   - cochez **Create .env file** ;
   - enregistrez.

## Étape 4 : premier déploiement
1. Cliquez sur **Deploy**. Le premier déploiement construit les images et prend 10 à 20 minutes. Les suivants sont plus rapides.
2. Suivez le journal de déploiement. À la fin, chaque conteneur doit être `running` ou `healthy`, sauf deux qui doivent être `exited (0)` : c'est normal, ils ont fini leur travail.
   - `restauration` affiche « sauvegarde … restaurée » ;
   - `preparation` affiche « WordPress prêt ».
3. Ouvrez `https://<adresse>/shop/` : la boutique s'affiche.

## Étape 5 : déploiement automatique à chaque `git push`
1. Dans Easypanel, onglet **Deployments**, copiez l'adresse **Deployment Trigger**.
2. Sur GitHub, ouvrez le dépôt, puis **Settings**, **Webhooks**, **Add webhook** :
   - Payload URL : l'adresse copiée ;
   - Content type : `application/json` ;
   - événement : « Just the push event » ;
   - enregistrez.
3. Désormais, chaque `git push` sur `master` redéploie le serveur.

## Étape 6 : réglages Meta (application « BAYAM essai »)
1. Dans developers.facebook.com, ouvrez votre application, puis **Facebook Login** (ou **Facebook Login for Business**), **Settings**. Dans **Valid OAuth Redirect URIs**, ajoutez `https://<adresse>/facebook/retour`.
2. Dans **App settings**, **Basic**, champ **App domains**, ajoutez l'adresse sans `https://`.
3. L'adresse des événements (webhook) est déclarée par BAYAM lui-même dans les 10 minutes. Vous pouvez le vérifier dans **Webhooks** : l'adresse doit être `https://<adresse>/webhooks/facebook`.

## Étape 7 : essais réels (comme d'habitude)
1. **Site :** `https://<adresse>/shop/` s'affiche. `https://<adresse>/wp-admin` accepte vos identifiants habituels, et le menu **Retours** est visible.
2. **WhatsApp, client :** depuis le téléphone d'essai (6 81 01 00 44), écrivez `bayam` à Mode Aïcha. Les rayons arrivent.
3. **WhatsApp, vendeur :** dans « Message à moi-même » de Mode Aïcha, écrivez `bayam`. Le menu vendeur arrive.
4. **Messenger :** depuis le compte Socrate Kratos, écrivez `bayam` à la Page Kratos Learn. Le menu arrive.
5. **Signalement :** écrivez `bayam signaler essai technique, ne pas traiter`. Un numéro R-… arrive. Vous pouvez ensuite le fermer dans le menu Retours.
6. Envoyez-moi le résultat de chaque essai, avec une capture si quelque chose ne va pas.

Si les sessions WhatsApp ne reviennent pas, écrivez `bayam whatsapp 6 54 77 00 63` depuis Messenger : vous recevez un code à saisir dans WhatsApp, menu **Appareils connectés**, puis **Connecter avec le numéro de téléphone**.

## Étape 8 : me donner accès au serveur pour les essais en direct
Avec cet accès, je lance moi-même sur le serveur les essais de sécurité, de bout en bout et de charge, et je lis les journaux sans vous déranger.
1. Connectez-vous au serveur en SSH, avec l'accès fourni par votre hébergeur (ou sa console web).
2. Ajoutez cette clé publique (une seule ligne) à la fin du fichier `~/.ssh/authorized_keys` :
   ```
   ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAGMvK6LPvq/eHqavZ330tzNNT2C5n772/6T6/mu3wBl bayam-deploiement
   ```
   Commande prête à coller sur le serveur :
   ```
   mkdir -p ~/.ssh && echo "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAGMvK6LPvq/eHqavZ330tzNNT2C5n772/6T6/mu3wBl bayam-deploiement" >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys
   ```
3. Donnez-moi l'adresse IP du serveur et le nom d'utilisateur. L'accès se retire à tout moment en effaçant cette ligne.

## Au quotidien
- **Mettre à jour :** `git push`. Easypanel reconstruit et redémarre ; la préparation met la base à jour avant que le site ne reçoive du trafic.
- **Revenir en arrière :** `git revert <commit>` puis `git push` (ou, dans Easypanel, redéployer le commit précédent).
- **Sauvegardes :** automatiques chaque nuit. Pour en lancer une à la main, ouvrez dans Easypanel la **Console** du conteneur `sauvegarde` et tapez `sauvegarde sauvegarder avant-mise-a-jour`. Pour voir la liste : `sauvegarde lister`.
- **Maintenance d'une fonction :** même interrupteur qu'en local, par SSH, dans le dossier du code du service (celui qu'Easypanel a cloné), par exemple `bash scripts/fonctions.sh maintenance paiement 30 "Paiement en ligne de retour à 15 h."`. Vous pouvez aussi me le demander.
- **Plus tard, nom de domaine BAYAM :**
  - ajoutez-le dans **Domains** (service `traefik`, port 80) ;
  - changez `BAYAM_URL_PUBLIQUE` dans **Environment** ;
  - redéployez. La préparation réécrit seule toutes les adresses en base.
