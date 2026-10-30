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

## Captures

`node scripts/captures.js` (avec `NODE_PATH=../cahier-des-charges/src/node_modules`) produit des captures du site et de l'espace vendeur dans `captures/`.

## Limites à connaître

- Ce serveur sert à découvrir et tester FluxStore, pas à la production (mots de passe locaux, HTTP, pas de sauvegarde).
- Aucun paiement Mobile Money n'est branché : seul le paiement à la livraison est actif.
- Le choix d'architecture pour BAYAM (garder WordPress ou brancher l'application sur le cœur Laravel prévu au cahier des charges) est traité dans `cahier-des-charges/etude-fluxstore/SYNTHESE.md`.
