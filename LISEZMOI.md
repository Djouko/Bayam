# Plateforme locale FluxStore (serveur de démonstration)

FluxStore Multi Vendor n'est que l'application mobile. Elle a besoin d'un serveur WordPress équipé de WooCommerce (boutique), de WCFM (place de marché multi-vendeurs) et de l'extension MStore API (le pont entre le serveur et l'application). Ce dossier installe ce serveur sur le poste, dans Docker, en une commande, réglé pour le Cameroun.

## Ce qui est installé

| Élément | Version | Rôle |
|---|---|---|
| WordPress | 7.0.4 (PHP 8.3) | Socle du site et de l'administration |
| MariaDB | 11.4.9 | Base de données |
| WooCommerce | 11.2.1 | Catalogue, panier, commandes, livraison, paiements |
| MStore API | 4.21.3 | API utilisée par l'application FluxStore (la 4.21.0 citée par FluxStore n'est pas publiée ; 4.21.3 est le correctif de la même branche) |
| WCFM Frontend Manager | 6.8.1 | Espace vendeur sur le web |
| WCFM Marketplace | 3.8.3 | Place de marché : boutiques, commissions, annuaire |
| WCFM Membership | 2.12.0 | Inscription des vendeurs |

Réglages : site en français, fuseau de Douala, devise FCFA sans décimales, vente au Cameroun uniquement, livraison en ville (1 000 FCFA) et retrait en boutique, paiement à la livraison.

Données de démonstration : le jeu officiel de WooCommerce (18 produits, prix convertis en FCFA) réparti entre trois boutiques fictives : Mode Aïcha (vêtements, Akwa), Accessoires Akwa, Musique Bonamoussadi.

## Architecture (migration progressive vers des microservices)

WordPress reste la boutique pendant la transition ; les nouvelles fonctions naissent dans des services indépendants (dossier `services/`, TypeScript exécuté par Node 24) et WordPress disparaîtra quand le web et l'application utiliseront la nouvelle API.

| Élément | Rôle |
|---|---|
| Traefik 3.6 (`infra/traefik`) | Entrée unique sur le port 8080 et pour le tunnel : répartition de charge, limitation de débit, en-têtes de sécurité, adresses internes refusées |
| Passerelle (`services/src/passerelle`) | Reçoit les événements de WAHA et de Meta (Facebook), vérifie leur signature, garde ce qui concerne BAYAM et l'écrit dans le bus |
| NATS JetStream 2.12 (`infra/nats`) | Bus d'événements durable : rien ne se perd si un service redémarre |
| Canal WhatsApp (`services/src/canal-whatsapp`) | Catalogue interactif (rayons, cartes, lien personnel de commande), confirmations aux clients ; transmet le reste à l'extension WordPress |
| Canal Facebook (`services/src/canal-facebook`) | Pages Facebook des vendeurs (API officielle de Meta) : catalogue dans Messenger, réponses aux commentaires, publications et stories, confirmations de commande |
| Retours (`services/src/retours`) | Signalements, idées et pannes détectées : doublons regroupés en sujets, urgence, alertes à l'équipe, réponse aux signaleurs |
| PostgreSQL 18 (`infra/postgres`) | Base des nouveaux services, un rôle et un schéma par service |
| Redis 8.2 | Cache, verrous, plafonds d'envoi (mot de passe obligatoire) |

Réseaux : `donnees` (MariaDB, PostgreSQL, Redis, NATS) sans accès à Internet et réservé aux services qui ont des identifiants ; chaque porte vers Docker sur son propre réseau ; ports publiés sur 127.0.0.1 seulement. Chaque service a son compte NATS : seule la passerelle peut écrire des événements.

Documentation des API (OpenAPI 3) : `services/docs/api/<service>.json`, régénérée par `npm run openapi` (un essai échoue si elle n'est plus à jour). À lire avec n'importe quel lecteur OpenAPI (Swagger Editor, Redoc, extension de l'éditeur).

Essais des services : `docker run --rm -v "$(pwd -W)/services:/app" -w /app node:24.22.2-alpine npm test` (et `npm run types` pour le typage). Tableau de bord de l'entrée : http://127.0.0.1:8081/dashboard/ (poste seulement).

## Démarrer et arrêter

Prérequis : Docker Desktop lancé.

```
cd plateforme-locale
docker compose up -d                                   # démarrer (quelques secondes)
docker compose run --rm cli bash /scripts/installer.sh # première fois seulement (environ 5 minutes)
docker compose stop                                    # arrêter
docker compose down -v                                 # tout effacer (base comprise)
```

Le script d'installation peut être relancé sans risque : chaque étape vérifie ce qui existe déjà.

## Adresses

| Page | Adresse |
|---|---|
| Boutique | http://localhost:8080/shop/ |
| Annuaire des boutiques | http://localhost:8080/boutiques/ |
| Une boutique | http://localhost:8080/store/aicha/ |
| Espace vendeur (WCFM) | http://localhost:8080/store-manager/ |
| Administration WordPress | http://localhost:8080/wp-admin/ |

## Comptes

Les mots de passe sont dans le fichier `.env` (créé automatiquement, propre à ce poste, à ne jamais publier).

| Compte | Identifiant | Mot de passe |
|---|---|---|
| Administrateur | `admin` | valeur `WP_ADMIN_PASSWORD` du fichier `.env` |
| Vendeurs | `aicha`, `akwa`, `bonamoussadi` | valeur `WP_VENDOR_PASSWORD` du fichier `.env` |

## Tester l'application (téléphone ou émulateur)

L'application est déjà branchée sur ce serveur et compilée (`bayam-project/build/app/outputs/flutter-apk/`). Une seule commande prépare tout :

```
& "F:\Workspace\Freelance\Cameroon E-Translate\inaf\principal\plateforme-locale\tester.ps1"
```

Elle démarre le serveur s'il est arrêté (rien à faire s'il tourne déjà), installe les données de démonstration si la base est vide,
compile l'application si l'APK manque, puis, pour chaque appareil connecté, redirige le port 8080, installe l'application (même si elle
a été supprimée du téléphone) et la lance. Elle affiche à la fin les comptes de démonstration. Après une modification du code de
l'application : ajouter `-Recompiler` à la commande.

Comptes de démonstration (mot de passe commun : `WP_VENDOR_PASSWORD` du fichier `.env`) : acheteur `brice` ; vendeurs `aicha`,
`akwa`, `bonamoussadi` et `mireille`. Administration du site : `WP_ADMIN` et `WP_ADMIN_PASSWORD`.
Si l'application reste sur un écran bleu nuit au tout premier lancement d'une nouvelle version, la fermer et la rouvrir.

- Téléphone Android réel : activer les « Options pour les développeurs » (taper 7 fois sur « Numéro de build » dans « À propos du téléphone »), activer « Débogage USB », brancher le câble et accepter l'autorisation sur le téléphone, puis lancer la commande. À relancer après chaque débranchement : la redirection du port disparaît.
- Émulateur : Android Studio, « Device Manager », démarrer « Medium Phone API 36.1 » (ou `emulator -avd Medium_Phone_API_36.1`), puis lancer la commande.
- Sans câble (Wi-Fi seul) : pas encore configuré. Il faudrait remplacer `localhost` par l'adresse IP du PC dans `docker-compose.yml` (WP_HOME, WP_SITEURL) et dans `bayam-project/lib/env.dart`, puis recompiler.

Dans l'application : créer un compte client depuis l'onglet profil, ou se connecter avec un compte vendeur (`aicha`, `akwa`, `bonamoussadi`) ; l'espace vendeur s'ouvre alors dans l'application (tableau de bord WCFM).

Attention à la mémoire : compiler l'application pendant que Docker tourne peut saturer les 16 Go du poste (Gradle est réglé par le projet pour réserver jusqu'à 8 Go). Si le site ne répond plus après une compilation, relancer simplement `tester.ps1`.

## Brancher l'application FluxStore

Les clés de l'API WooCommerce générées pour l'application sont dans `scripts/cles_api.txt`. Dans le projet FluxStore, `lib/env.dart`, rubrique `serverConfig` :

```
'type': 'wcfm',
'url': 'http://localhost:8080',
'consumerKey': '<consumerKey de cles_api.txt>',
'consumerSecret': '<consumerSecret de cles_api.txt>',
```

L'émulateur Android ne voit pas `localhost` du PC : avant de lancer l'application, rediriger le port avec `adb reverse tcp:8080 tcp:8080` (à refaire à chaque démarrage de l'émulateur). Un téléphone réel branché en USB fonctionne de la même façon.

En HTTP simple, l'application signe ses requêtes (OAuth 1.0a) ; c'est vérifié et fonctionnel. En production, le serveur devra être en HTTPS.

## Vendre depuis WhatsApp (extension BAYAM WhatsApp)

Trois services s'ajoutent à WordPress (démarrés avec les autres par `docker compose up -d`) :

| Service | Rôle |
|---|---|
| `waha` | Connecteur libre WAHA (moteur GOWS, sans navigateur) : relie le WhatsApp d'un vendeur comme un appareil lié. API et tableau de bord sur http://127.0.0.1:3000 (identifiant `admin`, mot de passe `WAHA_DASHBOARD_PASSWORD` du fichier `.env`). |
| `travailleur` | Traite chaque message WhatsApp en moins d'une seconde et fait tourner les tâches programmées (publications étalées, rapports quotidiens). |
| extension `bayam-whatsapp` | Code dans `plugins/bayam-whatsapp/` : Copilote du vendeur, réponses aux mots-clés des clients, cartes produits, publication dans les groupes et le statut, liens courts suivis, liens de paiement, dettes, promos, bilans, alertes de commande et de paiement. |

Connexion d'un vendeur : espace vendeur, rubrique **WhatsApp**, accepter les règles, « Afficher le QR code », puis dans WhatsApp sur le téléphone de la boutique : Appareils connectés, Connecter un appareil. Le vendeur n'a qu'un mot à retenir : **bayam**, dans sa discussion « Message à moi-même ». Le menu s'ouvre en liste (stock, vente, nouvel article, commandes, bilan, publication, dettes, boutique) ; il touche un choix ou répond par son numéro, et les actions courantes se font pas à pas (article, taille, quantité, prix, paiement). Celui qui connaît la commande la tape directement (« bayam +5 polo », photo avec la légende « bayam Robe wax 15000 M:3 »). **bayam aide** donne la liste de toutes les commandes. Il peut aussi écrire comme il parle : une demande non reconnue est traduite en commande par le modèle de langage ; une consultation part tout de suite, une modification attend « bayam oui ».

Modèle de langage (assistant du vendeur, demandes écrites librement) : Claude si `ANTHROPIC_API_KEY` est réglée dans `.env`, sinon Google Gemini avec `GEMINI_API_KEY` (offre gratuite ; modèles réglables par `BAYAM_GEMINI_MODELES`, essayés dans l'ordre quand l'un est saturé). Attention : avec l'offre gratuite de Gemini, Google peut se servir des messages envoyés pour améliorer ses produits ; passer à l'offre payante (ou à Claude) avant d'ouvrir à de vrais clients.

Paiement par carte : extension Stripe, clés `STRIPE_PUBLISHABLE_KEY` et `STRIPE_SECRET_KEY` dans `.env` (mode test tant que `STRIPE_MODE` ne vaut pas `live`), carte bancaire seulement. Carte d'essai : 4242 4242 4242 4242, date future, code 123.

Adresse publique : pendant les démonstrations, un tunnel Cloudflare gratuit donne une adresse en `trycloudflare.com` qui expire sans prévenir ; le service `veilleur` le relance tout seul, mais l'adresse change et les liens déjà envoyés ne marchent plus. Pour de vrais clients : un nom de domaine et un tunnel nommé (ou un serveur public), puis `BAYAM_URL_PUBLIQUE=https://…` dans `.env`.

Essais automatiques (sans téléphone, données de démonstration remises en état à la fin) :

```
docker compose run --rm cli wp eval-file /var/www/html/wp-content/plugins/bayam-whatsapp/tests/essais.php
```

Journal de l'extension : WooCommerce, État, Journaux, source `bayam-whatsapp`.

Chaînes WhatsApp : « bayam publier robes chaîne » publie dans les chaînes dont le vendeur est propriétaire ou administrateur (celles qu'il suit seulement sont ignorées) ; « partout » couvre les groupes, la chaîne, le statut et la Page Facebook. Essayé en réel le 07/11/2026 sur une chaîne d'essai (photo et légende publiées).

Profil personnel et groupes Facebook : aucune application n'a le droit d'y publier. « bayam partager robes » donne, pour chaque article, sa photo et un texte prêt à coller avec le prix et un lien WhatsApp qui contient la référence de l'article (« réf. P25 ») ; le client qui le touche arrive dans la discussion du vendeur et BAYAM lui envoie aussitôt la fiche de l'article. Si la Page est reliée, un lien Messenger s'ajoute.

## Vendre sur Facebook (canal Facebook)

Ce que fait une Page reliée, sans effort du vendeur :

- Messenger : le client qui écrit (« Bonsoir », « prix ? ») reçoit les rayons en réponses rapides, puis un carrousel d'articles à faire défiler (photo, prix, stock, boutons), l'article choisi avec le bouton « Commander » vers son lien personnel, et la confirmation de sa commande dans Messenger. Un message que BAYAM ne sait pas traiter prévient le vendeur sur son WhatsApp (une fois par demi-heure et par client). Quand le vendeur répond lui-même, BAYAM se tait deux heures dans cette discussion.
- Commentaires : chaque commentaire d'achat (« prix ? », « cb », « dispo », « ib », « je prends », « le 2 ») reçoit une réponse privée avec l'article et son prix, et une réponse publique courte ; une seule par personne et par publication. Les mentions d'amis et les « Amen » sont ignorés.
- Publications : « bayam publier robes facebook » (photo ou album numéroté), « … story » (visuel vertical avec le prix). Chaque publication porte un lien m.me qui ouvre l'article directement dans Messenger : le vendeur peut le recopier sur son profil, dans les groupes ou sur Marketplace.
- Connexion : le vendeur écrit « bayam facebook » sur WhatsApp, touche le lien (valable 30 minutes) et coche sa Page. Les jetons des Pages sont chiffrés en base (`FACEBOOK_CLE`). BAYAM règle dans Messenger le bouton « Démarrer » et un menu permanent (articles, livraison et paiement, vendeur) ; une autorisation refusée dans la fenêtre Facebook est expliquée au vendeur (ce qui ne marchera pas, et comment l'accorder). Une Page cochée par erreur se retire par « bayam facebook retirer <nom> ».

Mise en service (une fois, gratuite) : créer une application sur developers.facebook.com avec les cas d'usage « Engage with customers on Messenger from Meta » et « Manage everything on your Page », puis renseigner `FACEBOOK_APP_ID`, `FACEBOOK_APP_SECRET` et `FACEBOOK_CONFIG_ID` (configuration créée dans « Facebook Login for Business ») dans `.env` et relancer `docker compose up -d`. Le service déclare lui-même à Meta l'adresse des événements (`https://<adresse publique>/webhooks/facebook`, champs `messages`, `messaging_postbacks`, `messaging_referrals`, `message_echoes`, `feed`) et la redéclare quand le tunnel change d'adresse. Reste à saisir dans l'application l'adresse de retour de connexion `https://<adresse publique>/facebook/retour` (réglages de la connexion Facebook). Tant que l'application est en mode développement, seules les personnes qui y ont un rôle (administrateur, testeur) peuvent écrire à la Page ; l'examen de l'application par Meta ouvre ensuite à tous.

Essai de bout en bout sans application Meta (un faux Graph remplace Meta, tout le reste est réel, données effacées à la fin) : `bash scripts/essai_facebook.sh`.

Messagerie de Facebook (application et site) : elle n'affiche ni cartes ni boutons. Chaque envoi garde ses cartes pour Messenger et ajoute un texte numéroté ; le client répond « 2 » et BAYAM retrouve la dernière liste qu'il lui a envoyée. Le menu vient quand le client le demande (« bayam », « menu »), à son premier message ou après 3 jours d'absence ; « aide » explique comment acheter.

## WhatsApp et Facebook : les mêmes fonctions des deux côtés

- Espace vendeur dans Messenger : le vendeur ouvre le lien « Mon menu BAYAM dans Messenger » (page de connexion Facebook, ou « bayam facebook ») depuis son compte Facebook ; ce compte devient le sien sur la Page. Il y a le même menu et les mêmes commandes que dans « Message à moi-même » sur WhatsApp, et il peut y relier son WhatsApp : « bayam whatsapp 6 90 00 00 10 » donne un code à saisir dans WhatsApp (Appareils connectés, Connecter avec le numéro de téléphone), sans QR code ni ordinateur.
- Publier : dans le menu, « Publier » propose chacun des groupes et chaînes du vendeur, son statut, sa Page, une story, le texte à coller sur son profil, ou partout ; plusieurs à la fois en répondant « 1 3 4 ».
- Aide : « bayam aide » (vendeur), « bayam aide » sur WhatsApp et « aide » dans Messenger (clients).
- Soucis et idées : « bayam signaler … », « bayam idée … » (vendeurs, clients, WhatsApp et Messenger, ou menu « Ma boutique »). Chaque retour reçoit un numéro, arrive dans l'administration WordPress (menu « Retours ») et par e-mail à l'adresse d'administration.

## Maintenance sans couper le reste

Chaque fonction a son interrupteur, lu en quelques secondes par tous les services, WordPress et les applications, sans redéploiement ni mise à jour :

```
bash scripts/fonctions.sh liste
bash scripts/fonctions.sh maintenance facebook.commentaires 30 "Réponses aux commentaires en pause, retour à 15 h."
bash scripts/fonctions.sh testeurs facebook.commentaires 4      (ces vendeurs gardent l'accès pour vérifier)
bash scripts/fonctions.sh actif facebook.commentaires
```

Fonctions : `whatsapp` (catalogue, menu, copilote, publication, assistant), `facebook` (messenger, commentaires, publications, connexion), `paiement`, `mobile`, `web` ; un préfixe vaut pour toutes ses fonctions. Pendant une maintenance, l'utilisateur reçoit un message clair (une fois par heure) au lieu d'une erreur, signaler un souci reste possible, les publications et les commentaires attendent leur tour, et la fonction se rouvre seule à l'heure annoncée. Les applications lisent l'état de chaque fonction sur `https://<adresse>/api/config` (gardé 30 s). Les événements reçus pendant le redémarrage d'un service restent dans le bus et sont traités au retour : une mise à jour des services ne fait perdre aucun message.

## Retours des utilisateurs (signalements, idées, pannes)

Les clients et les vendeurs écrivent « bayam signaler … » ou « bayam idée … » (WhatsApp, Messenger, Copilote) ; l'application et le site envoient sur `POST /api/retours` (6 envois par heure et par personne au plus). Chaque retour reçoit un numéro (R-…), est classé (catégorie, gravité) et rejoint le sujet identique s'il existe déjà : l'équipe voit un seul sujet avec le nombre de personnes touchées.

Tableau de bord : administration WordPress, menu **Retours** (du plus urgent au moins urgent). Une décision (statut, responsable, gravité, résolution, doublon) est gardée dans l'historique ; « Corrigé » avec « Prévenir » envoie à chaque signaleur joignable un message sur son canal. Un sujet critique, une panne ou un sujet signalé par trois personnes prévient l'équipe tout de suite (WhatsApp `RETOURS_ALERTE_WHATSAPP` et courriel de l'administrateur). Les moyens de contact s'effacent 30 jours après la clôture.

## Sécurité et essais

| Commande | Ce qu'elle vérifie |
|---|---|
| `docker run --rm -v "$(pwd -W)/services:/app" -w /app node:24.22.2-alpine sh -c "npm run types; npm test"` | Typage et essais unitaires des services, documentation OpenAPI à jour |
| `docker compose run --rm cli wp eval-file /var/www/html/wp-content/plugins/bayam-whatsapp/tests/essais.php` | Extension WordPress (176 vérifications) |
| `bash scripts/essai_facebook.sh` | Canal Facebook de bout en bout avec un faux Graph (40 vérifications) |
| `bash scripts/essai_retours.sh` | Service des retours en réel, réponse sur le téléphone d'essai (10 vérifications) |
| `bash scripts/essai_securite.sh` | Rejoue les attaques de l'audit (`cahier-des-charges/recherche/S_audit_securite.md`) : chacune doit échouer |

Secrets : uniquement dans `.env` (jamais dans git). Les liens signés (connexion Facebook, espace vendeur) ont leur propre clé `BAYAM_CLE_LIENS`, distincte du jeton de l'API interne. Le code PIN de l'équipe est gardé en empreinte.

## Captures

`node scripts/captures.js` (avec `NODE_PATH=../cahier-des-charges/src/node_modules`) produit des captures du site et de l'espace vendeur dans `captures/`.

## Limites à connaître

- Ce serveur sert à découvrir et tester FluxStore, pas à la production (mots de passe locaux, HTTP, pas de sauvegarde).
- Aucun paiement Mobile Money n'est branché : seul le paiement à la livraison est actif.
- Le choix d'architecture pour BAYAM (garder WordPress ou brancher l'application sur le cœur Laravel prévu au cahier des charges) est traité dans `cahier-des-charges/etude-fluxstore/SYNTHESE.md`.
