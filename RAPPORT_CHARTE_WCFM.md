# Rapport : charte BAYAM sur l'espace vendeur WCFM

## Ce qui a été fait
Un mu-plugin (chargé automatiquement, sans reconstruction) applique la charte BAYAM à l'espace vendeur et à la page publique de la boutique.

Fichiers créés :
- `mu-plugins/bayam-charte-wcfm.php` : charge les polices (Poppins, Inter) et la feuille de style, uniquement sur les URL `/store-manager/`, `/store/` et `/boutiques/`. Ajoute aussi un petit script de finition (voir Limites).
- `mu-plugins/bayam-charte-wcfm/charte.css` : feuille unique, organisée en sections (base, en-tête, menu, cartes, boutons, formulaires, tableaux, statuts, mobile, page boutique).
- `mu-plugins/bayam-charte-wcfm/traductions.php` : filtre `gettext` limité aux domaines WCFM (et `mstore-api` pour une chaîne) avec environ 150 chaînes traduites en français.

Principaux changements visuels :
- Couleurs : plus aucun violet, bleu, orange ou turquoise WCFM dans les écrans (boutons émeraude foncé, onglets menthe, statuts en pastilles émeraude, soleil et corail, cartes de statistiques et légendes des rapports aux couleurs de la marque).
- Mobile : le menu latéral est devenu une barre d'onglets horizontale (défilable, effet verre, cibles de 44 px). L'en-tête passe sur deux lignes sans chevauchement. Les tableaux occupent toute la largeur, avec états vides en encart menthe. La barre d'actions des formulaires (Brouillon, Soumettre, Sauvegarder) est en verre.
- Ordinateur : menu nuit avec élément actif émeraude, cartes arrondies à ombre douce, tableaux lisibles.
- Page boutique : bandeau nuit, onglets en pastilles, cartes produit arrondies, badge promo corail, boutons émeraude.

## Pages vérifiées (mobile 390 px et ordinateur 1366 px, aucun débordement horizontal)
Accueil OK, Produits (liste) OK, Ajout produit OK, Modification produit OK, Commandes OK, Détail commande OK, Clients OK, Coupons (liste et ajout) OK, Rapports (ventes, rupture de stock) OK, Avis OK, Notifications (Sujets) OK, Messages OK, Demandes clients OK, Profil OK, Réglages (boutique, paiement, livraison, SEO, réseaux sociaux, politiques) OK, Retraits OK, Paiements OK, Grand livre OK, Articles OK, Médias OK, Aide OK, page publique Mode Aïcha OK, liste /boutiques/ OK (bonus).
Captures : `captures/wcfm/avant-*.png`, `apres-*.png`, `planche-mobile.png`, `planche-ordinateur.png`.

## Limites restantes
- Les courbes et le camembert du tableau de bord et des rapports sont dessinés par JavaScript avec les couleurs WCFM (turquoise, bleu clair). Elles ne se changent pas en CSS ; il faudrait surcharger le JS de WCFM.
- Les notifications enregistrées en base en anglais (créées par le plugin mstore-api, par exemple « You have received an Order ») sont traduites à l'affichage par le petit script du mu-plugin, pour les formes connues seulement. Les nouveaux types de messages anglais devront être ajoutés aux règles du script.
- Certains libellés viennent du domaine WooCommerce (par exemple « Create variations from all attributes », dans un menu masqué) et ne sont pas traduits, car la traduction est volontairement limitée aux domaines WCFM.
- L'entrée de menu « WhatsApp » vient du plugin bayam-whatsapp ; elle est habillée, mais son contenu n'a pas été audité.
- Les polices viennent de Google Fonts : sans accès internet, Inter et Poppins se replient sur la police système.
- Les menus déroulants des sous-menus WCFM (ajouter produit, etc.) sont masqués sur mobile, car ils reposent sur le survol ; les boutons « Ajouter » restent disponibles dans les pages.
- Aucune vérification sur appareil réel ou dans la WebView de l'application, uniquement avec Chromium (Playwright). Aucune donnée ni option de la plateforme n'a été modifiée.
- Pendant les tests, la liste des produits et des notifications a évolué (nouvelles données créées en dehors de ce travail) ; les captures « après » reflètent ces données.

## Retour arrière
Supprimer `mu-plugins/bayam-charte-wcfm.php` et le dossier `mu-plugins/bayam-charte-wcfm/`. L'effet est immédiat, sans autre action.
