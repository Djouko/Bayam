# ADR 0001 : pile du web et place de Spring Boot

- Date : 13/11/2026
- État : proposée (avis donné à la direction, à confirmer avant le chantier web)

## Contexte
La direction propose Spring Boot pour le web. Le « web » de BAYAM recouvre trois choses : la boutique publique (pages produit partagées sur WhatsApp et Facebook, souvent ouvertes sur un téléphone d'entrée de gamme en 3G), l'espace vendeur et l'administration, et les services métier derrière (catalogue, commandes, paiements, abonnements) qui remplaceront WordPress et WooCommerce. Les services déjà en place (passerelle, canaux, retours) sont en TypeScript sur Node 24, reliés par NATS et PostgreSQL, documentés en OpenAPI.

## Décision proposée
1. **Spring Boot (Java 21 ou 25, threads virtuels) pour le cœur commerce** quand WordPress sera remplacé : catalogue, commandes, paiements, facturation des abonnements. Ces domaines demandent des transactions solides, des règles métier nombreuses et une équipe qui grandit ; Java est enseigné et répandu au Cameroun, Spring Security et Spring Data sont mûrs.
2. **Les services d'échange restent en TypeScript** (passerelle, canaux WhatsApp et Facebook, retours, médias) : légers (environ 60 Mo de mémoire chacun contre 250 à 400 Mo pour une JVM), déjà écrits et testés, proches des API de Meta et de WAHA.
3. **Spring Boot ne sert pas les écrans.** Boutique publique : rendu côté serveur (Next.js) pour un premier affichage rapide sur réseau lent, et pour les aperçus de lien dans WhatsApp et Facebook, qui exigent des balises produites par le serveur. Espace vendeur et administration : Flutter web, qui reprend le code et la charte de l'application mobile.
4. Les deux langages ne se parlent que par contrats : OpenAPI pour les appels, événements NATS décrits pour le bus. Une équipe backend Java et une équipe TypeScript travaillent sans se gêner.

## Conséquences
- Deux langages côté serveur : outils de qualité, images de base et analyses de sécurité à tenir pour les deux (prévu dans l'intégration continue).
- Mémoire : un serveur de préproduction de 8 Go suffit pour les services TypeScript et deux ou trois services Spring Boot ; au-delà, compilation native (GraalVM) ou serveurs supplémentaires.
- Rien n'est commencé en Java tant que le chantier web n'est pas lancé ; la base d'un service Spring Boot (squelette, sécurité, OpenAPI, essais, image) sera préparée au premier service métier migré.

## Options écartées
- Tout en Spring Boot, écrans compris (Thymeleaf) : expérience moins riche, et réécriture des services d'échange déjà prêts sans gain.
- Tout en TypeScript : possible, mais le cœur commerce gagne à la rigueur et au vivier de développeurs Java.
