<?php
/**
 * Aide du Copilote : toutes les actions du vendeur, rangées par thème, avec à quoi chacune sert, comment l'écrire,
 * des exemples et les précautions. Source unique pour « bayam aide », « bayam aide publier » et pour le modèle de
 * langage qui comprend les demandes écrites librement.
 */

defined('ABSPATH') || exit;

final class Bayam_WA_Aide
{
    private const THEMES = [
        'stock' => '📦 Articles et stock',
        'publier' => '📣 Publier et faire connaître',
        'vendre' => '💵 Vendre et encaisser',
        'clients' => '👥 Commandes et clients',
        'assistant' => '🤖 Votre assistant',
        'equipe' => '🔒 Équipe et sécurité',
        'boutique' => '🏪 Boutique et aide',
    ];

    /**
     * Clé = intention du Copilote (ou action sans mot-clé : photo, vitrine, entree).
     * titre : une ligne ; detail : ce que l'action fait vraiment ; exemples : à copier tels quels.
     */
    public const ACTIONS = [
        'catalogue' => ['theme' => 'stock', 'titre' => 'Voir vos articles en stock, avec prix et liens',
            'detail' => "Affiche vos articles en stock, avec leur prix et un lien qui compte les clics. Écrivez simplement le nom de l'article, ou *catalogue* pour tout voir.",
            'exemples' => ['bayam robes', 'bayam catalogue', 'bayam #polo']],
        'stock' => ['theme' => 'stock', 'titre' => 'Connaître ou modifier le stock d\'un article',
            'detail' => "Donne les quantités restantes, taille par taille. Pour corriger : *+* ajoute des pièces, *-* en retire (un membre de l'équipe doit donner le code PIN pour retirer). Sans article, vous recevez l'état de tout le stock et ce qui manque.",
            'exemples' => ['bayam stock robe sirène', 'bayam combien de polo il reste ?', 'bayam +5 robe sirène M', 'bayam -2 polo', "bayam j'ai reçu 10 casquettes"]],
        'photo' => ['theme' => 'stock', 'titre' => 'Créer un article en envoyant sa photo',
            'detail' => "Envoyez la photo dans « Message à moi-même » avec une légende qui commence par *bayam* : nom, prix, puis les tailles et quantités. L'article est créé, rangé dans le bon rayon et visible tout de suite sur votre boutique. Ajoutez *publier* à la fin pour l'envoyer aussi dans vos groupes.",
            'exemples' => ['bayam Robe wax 15000 M:3 L:2', 'bayam Casquette brodée 6500 4', 'bayam Robe wax 15000 M:3 publier partout']],
        'cartes' => ['theme' => 'publier', 'titre' => 'Recevoir des cartes produit à transférer',
            'detail' => "Fabrique une belle image par article (photo, nom, prix, lien). Appuyez longuement sur une carte puis « Transférer » vers vos clients et groupes. Chaque lien compte les clics.",
            'exemples' => ['bayam cartes robes', 'bayam cartes polo']],
        'statut' => ['theme' => 'publier', 'titre' => 'Recevoir des visuels pour votre statut',
            'detail' => "Fabrique des images au format du statut WhatsApp. Transférez-les vers *Mon statut*, ou écrivez *bayam publier … statut* pour que BAYAM les publie à votre place.",
            'exemples' => ['bayam statut robes']],
        'publier' => ['theme' => 'publier', 'titre' => 'Publier des articles : groupes, statut, chaîne, Page Facebook',
            'detail' => "Envoie les articles choisis dans les groupes que vous avez sélectionnés (espace vendeur, rubrique WhatsApp), au rythme d'une personne pour protéger votre numéro, puis vous fait le bilan. Ajoutez *statut*, *chaîne* (votre chaîne WhatsApp), *facebook* (votre Page : photo, prix et réponse privée à chaque « prix ? »), *story* (story de la Page avec le prix sur l'image), *partout* (tout à la fois) ou *dans* suivi du nom d'un groupe. *nouveautés* et *promos* choisissent les articles pour vous.",
            'exemples' => ['bayam publier robes', 'bayam publier polo statut', 'bayam publier robes facebook', 'bayam publier sandales story', 'bayam publier nouveautés partout', 'bayam publier promos dans Clientes Akwa']],
        'partager' => ['theme' => 'publier', 'titre' => 'Publier sur votre profil ou vos groupes Facebook',
            'detail' => "Facebook ne permet à aucune application de publier sur un profil ou dans un groupe : BAYAM vous prépare tout. Pour chaque article, vous recevez sa photo et un texte prêt à coller (prix, tailles, lien de commande WhatsApp, et Messenger si votre Page est reliée). Le client qui touche le lien arrive sur l'article et BAYAM lui répond.",
            'exemples' => ['bayam partager robes', 'bayam partager nouveautés', 'bayam partager promos']],
        'signaler' => ['theme' => 'boutique', 'titre' => 'Signaler un souci à l\'équipe BAYAM',
            'detail' => "Décrivez ce qui ne marche pas après le mot : votre message arrive à l'équipe avec un numéro de suivi, et nous vous répondons ici. Marche aussi pendant une maintenance.",
            'exemples' => ['bayam signaler le lien de paiement ne s\'ouvre pas']],
        'idee' => ['theme' => 'boutique', 'titre' => 'Proposer une amélioration',
            'detail' => "Une idée pour rendre BAYAM plus utile ? Écrivez-la après le mot : l'équipe la lit et vous répond.",
            'exemples' => ['bayam idée pouvoir publier sur TikTok']],
        'whatsapp' => ['theme' => 'boutique', 'titre' => 'Relier votre WhatsApp (depuis Messenger aussi)',
            'detail' => "Envoyez votre numéro : vous recevez un code à saisir dans WhatsApp (Appareils connectés › Connecter avec le numéro de téléphone), sans QR code ni ordinateur. Sans numéro : l'état de la connexion.",
            'exemples' => ['bayam whatsapp', 'bayam whatsapp 6 90 00 00 10']],
        'facebook' => ['theme' => 'publier', 'titre' => 'Relier votre Page Facebook',
            'detail' => "Vous envoie un lien (valable 30 minutes) : connectez-vous à Facebook et cochez la Page de votre boutique. Ensuite, sans rien faire : vos clients ont le catalogue dans Messenger (photos, prix, bouton Commander), chaque commentaire « prix ? » reçoit une réponse privée avec l'article, et la commande est confirmée dans Messenger. Seul le propriétaire peut relier une Page.",
            'exemples' => ['bayam facebook']],
        'envoie' => ['theme' => 'publier', 'titre' => 'Envoyer une sélection d\'articles à un client',
            'detail' => "Envoie au client, sur son WhatsApp, les articles demandés avec prix et liens, en un message. Utile quand un client vous demande « qu'est-ce que vous avez ? ». Le client doit vous avoir déjà écrit.",
            'exemples' => ['bayam envoie robes 6 90 00 00 10']],
        'vitrine' => ['theme' => 'publier', 'titre' => 'Montrer votre boutique à un client',
            'detail' => "Dans la discussion du client, écrivez *bayam menu* : il reçoit la liste de vos rayons, puis les articles en image et un lien personnel pour commander. Le client peut aussi l'obtenir seul en vous écrivant *bayam*.",
            'exemples' => ['bayam menu (dans la discussion du client)']],
        'vendu' => ['theme' => 'vendre', 'titre' => 'Enregistrer une vente faite en direct',
            'detail' => "Enregistre une vente conclue au téléphone ou en boutique : le stock baisse, la vente entre dans vos bilans et le client reçoit un reçu. Donnez l'article, la taille, la quantité (x2), le prix réellement payé et le moyen : *cash*, *momo*, *om* ou *credit*. Le client a payé une partie ? Ajoutez *acompte* et la somme reçue. Avec le nom du client à la fin, ce qui reste à payer est noté dans ses dettes, et la vente se termine toute seule quand il a tout payé. Vous pouvez aussi l'écrire comme vous parlez : « j'ai vendu 2 robes à 30 mille par momo ».",
            'exemples' => ['bayam vendu robe sirène M x2 30000 momo', 'bayam vendu polo 12000 cash', 'bayam vendu robe sirène M 18000 acompte 9000 momo Clarisse', 'bayam vendu casquette 6000 credit Paul']],
        'politique' => ['theme' => 'vendre', 'titre' => 'Choisir vos conditions de paiement',
            'detail' => "Dites comment vos clients paient : à la livraison, avec un acompte avant l'envoi (le reste à la livraison) ou tout avant l'envoi. Votre assistant, vos confirmations de commande et votre catalogue l'annoncent aux clients, avec la somme exacte à envoyer. Seul le propriétaire de la boutique peut le changer.",
            'exemples' => ['bayam paiement', 'bayam paiement à la livraison', 'bayam paiement acompte 50%', 'bayam paiement avant envoi']],
        'numero_paiement' => ['theme' => 'vendre', 'titre' => 'Donner le numéro MoMo ou OM qui reçoit l\'argent',
            'detail' => "Le numéro MTN MoMo ou Orange Money où vos clients envoient l'acompte ou le paiement, avec le nom qu'affiche le transfert pour les rassurer. Seul le propriétaire de la boutique peut le donner ou le changer.",
            'exemples' => ['bayam momo 6 54 77 00 63 Aïcha Mbarga', 'bayam om 6 90 12 34 56', 'bayam momo retirer']],
        'lien' => ['theme' => 'vendre', 'titre' => 'Créer un lien de paiement pour un client',
            'detail' => "Crée un lien que vous transférez au client pour qu'il paie à distance (Mobile Money ou carte selon ce que la boutique accepte). Vous êtes prévenu ici dès que le paiement arrive.",
            'exemples' => ['bayam lien 15000 Marie', 'bayam lien 7 500 F Tata Odile']],
        'promo' => ['theme' => 'vendre', 'titre' => 'Créer un code promo',
            'detail' => "Crée un code que vos clients saisissent au paiement : en pourcentage ou en montant fixe, valable sur vos articles seulement.",
            'exemples' => ['bayam promo NOEL10 10%', 'bayam promo AMIE 2000']],
        'commandes' => ['theme' => 'clients', 'titre' => 'Voir vos commandes en cours',
            'detail' => "Liste les commandes à payer et à préparer, avec le client et le montant.",
            'exemples' => ['bayam commandes', 'bayam montre-moi mes commandes']],
        'bilan' => ['theme' => 'clients', 'titre' => 'Voir vos ventes et choisir l\'heure du résumé',
            'detail' => "Résume vos ventes sur la période que vous dites : aujourd'hui, *hier*, *avant-hier*, un jour (*lundi*, *le 3 novembre*, *03/11*), *cette semaine*, *la semaine passée*, *ce mois* ou *le mois dernier* : chiffre d'affaires, argent encaissé, articles vendus, ruptures et dettes à recouvrer. *rapport 21h* choisit l'heure du résumé quotidien, *rapport off* l'arrête.",
            'exemples' => ['bayam bilan', "bayam qu'est-ce que j'ai vendu hier ?", 'bayam bilan avant-hier', 'bayam mes ventes de la semaine passée', 'bayam rapport 21h']],
        'client' => ['theme' => 'clients', 'titre' => 'Voir l\'historique d\'un client',
            'detail' => "Donne les achats d'un client chez vous (dates, montants, état) à partir de son numéro.",
            'exemples' => ['bayam client 6 90 00 00 10']],
        'dettes' => ['theme' => 'clients', 'titre' => 'Noter et suivre les dettes de vos clients',
            'detail' => "*dettes* liste qui vous doit combien. *dette* suivi du nom et du montant en note une nouvelle, avec une date d'échéance si vous voulez : BAYAM vous la rappelle.",
            'exemples' => ['bayam dettes', 'bayam qui me doit ?', 'bayam dette Paul 5000', 'bayam dette Paul 5000 15/11']],
        'paye' => ['theme' => 'clients', 'titre' => 'Noter qu\'un client a remboursé',
            'detail' => "Diminue la dette du client du montant reçu. Quand tout est payé, la dette disparaît de la liste.",
            'exemples' => ['bayam payé Paul 2000', 'bayam Paul a payé 2000']],
        'assistant' => ['theme' => 'assistant', 'titre' => 'Laisser votre assistant répondre à vos clients',
            'detail' => "Quand vous êtes occupé, l'assistant répond à vos clients comme vous le feriez : prix, tailles, disponibilité, livraison, et il négocie sans jamais descendre sous votre prix minimum. Il prépare la commande et vous la soumet. Dès que vous écrivez vous-même à un client, il vous laisse la main. *je reprends* l'arrête.",
            'exemples' => ['bayam je suis occupé', 'bayam assistant 2h', 'bayam je reprends']],
        'plancher' => ['theme' => 'assistant', 'titre' => 'Fixer le prix minimum d\'un article',
            'detail' => "Le prix le plus bas que votre assistant peut accepter en négociation pour cet article. Le client ne le voit jamais. Sans prix fixé, l'assistant applique la remise maximale.",
            'exemples' => ['bayam plancher robe sirène 16000', 'bayam plancher polo']],
        'remise' => ['theme' => 'assistant', 'titre' => 'Fixer la remise maximale de l\'assistant',
            'detail' => "La réduction la plus forte (en %) que l'assistant peut accorder sur les articles sans prix minimum.",
            'exemples' => ['bayam remise 15%', 'bayam remise']],
        'ok' => ['theme' => 'assistant', 'titre' => 'Valider une commande préparée par l\'assistant',
            'detail' => "Confirme la commande : le client reçoit la confirmation sur WhatsApp et la commande passe « en cours ». Le numéro est dans le message de l'assistant.",
            'exemples' => ['bayam ok 740']],
        'annule' => ['theme' => 'assistant', 'titre' => 'Refuser une commande préparée par l\'assistant',
            'detail' => "Annule la commande préparée par l'assistant. Pensez à prévenir le client.",
            'exemples' => ['bayam annule 740']],
        'equipe' => ['theme' => 'equipe', 'titre' => 'Donner l\'accès à vos vendeurs',
            'detail' => "Les membres de l'équipe envoient les mêmes commandes depuis leur téléphone en commençant par */* ou *bayam*. Seul le propriétaire ajoute ou retire un membre. Les ventes, liens, promos et dettes de l'équipe demandent le code PIN.",
            'exemples' => ['bayam equipe', 'bayam equipe ajouter 6 77 00 00 02', 'bayam equipe retirer 6 77 00 00 02']],
        'pin' => ['theme' => 'equipe', 'titre' => 'Choisir le code PIN de l\'équipe',
            'detail' => "Code de 4 à 6 chiffres que votre équipe ajoute aux actions sensibles (*… pin 4826*). Après 5 erreurs, les actions sensibles sont bloquées 15 minutes et vous êtes prévenu.",
            'exemples' => ['bayam pin 4826']],
        'groupes' => ['theme' => 'boutique', 'titre' => 'Voir vos groupes de publication',
            'detail' => "Liste les groupes où BAYAM publie pour vous. Vous les choisissez dans votre espace vendeur, rubrique WhatsApp.",
            'exemples' => ['bayam groupes']],
        'boutique' => ['theme' => 'boutique', 'titre' => 'Recevoir le lien unique de votre boutique',
            'detail' => "Le lien à mettre dans vos biographies TikTok, Instagram et Facebook. Le QR code imprimable est dans votre espace vendeur.",
            'exemples' => ['bayam boutique']],
        'menu' => ['theme' => 'boutique', 'titre' => 'Voir l\'essentiel en un coup d\'œil',
            'detail' => "Les actions les plus utiles, avec un exemple chacune.",
            'exemples' => ['bayam menu', 'bayam']],
        'raccourcis' => ['theme' => 'boutique', 'titre' => 'Voir ce que BAYAM a appris de vous',
            'detail' => "Quand vous écrivez à votre façon et que vous validez ma proposition, je retiens la tournure : la fois suivante, je la comprends tout de suite. Vous voyez ici ce que j'ai appris, et vous pouvez tout effacer.",
            'exemples' => ['bayam raccourcis', 'bayam raccourcis effacer']],
        'aide' => ['theme' => 'boutique', 'titre' => 'Voir toutes les actions, ou le détail d\'une action',
            'detail' => "Sans rien après : toutes les actions par thème. Avec le nom d'une action : à quoi elle sert, comment l'écrire, des exemples. Vous pouvez aussi demander « comment publier ? » ou « c'est quoi le plancher ? ».",
            'exemples' => ['bayam aide', 'bayam aide publier', 'bayam comment créer une promo ?']],
        'support' => ['theme' => 'boutique', 'titre' => 'Joindre l\'équipe BAYAM',
            'detail' => "Pour un souci ou une question, l'équipe BAYAM vous répond du lundi au samedi, de 8 h à 19 h.",
            'exemples' => ['bayam support']],
    ];

    /** Mots que les vendeurs emploient pour désigner une action, en plus des mots-clés du Copilote. */
    private const SYNONYMES = [
        'vendre' => 'vendu', 'vente' => 'vendu', 'ventes' => 'bilan', 'payer' => 'lien', 'paiement' => 'politique', 'encaisser' => 'lien',
        'acompte' => 'vendu', 'livraison' => 'politique', 'conditions' => 'politique', 'mobile' => 'numero_paiement', 'appris' => 'raccourcis',
        'photo' => 'photo', 'photos' => 'photo', 'nouveau' => 'photo', 'ajouter' => 'photo', 'creer' => 'photo', 'article' => 'photo',
        'stocker' => 'stock', 'quantite' => 'stock', 'reception' => 'stock', 'arrivage' => 'stock',
        'montrer' => 'vitrine', 'vitrine' => 'vitrine', 'rayons' => 'vitrine',
        'messenger' => 'facebook', 'page' => 'facebook', 'story' => 'publier', 'chaine' => 'publier', 'chaines' => 'publier',
        'reduction' => 'remise', 'negocier' => 'plancher', 'negociation' => 'plancher', 'minimum' => 'plancher', 'prix' => 'plancher',
        'confirmer' => 'ok', 'valider' => 'ok', 'refuser' => 'annule', 'rembourser' => 'paye', 'credit' => 'dettes', 'doit' => 'dettes',
        'rapport' => 'bilan', 'resume' => 'bilan', 'vendeurs' => 'equipe', 'employe' => 'equipe', 'code' => 'pin', 'qr' => 'boutique',
        'transferer' => 'cartes', 'image' => 'cartes', 'images' => 'cartes', 'diffuser' => 'publier', 'poster' => 'publier',
    ];

    /** Réponse à « bayam aide » (toutes les actions) ou « bayam aide <action> » (le détail). */
    public static function reponse(string $demande): string
    {
        $action = trim($demande) === '' ? null : self::action_de($demande);
        if ($action !== null) {
            return self::detail($action);
        }
        return trim($demande) === '' ? self::liste()
            : "Je ne connais pas l'action « " . trim($demande) . " ». Voici toutes celles que je sais faire :\n\n" . self::liste();
    }

    public static function liste(): string
    {
        $texte = "📚 *Tout ce que BAYAM fait pour vous*\nCommencez chaque message par *bayam*. Pour le détail d'une action : *bayam aide* suivi de son nom, par exemple *bayam aide publier*.\n";
        foreach (self::THEMES as $theme => $libelle) {
            $texte .= "\n*{$libelle}*\n";
            foreach (self::ACTIONS as $cle => $a) {
                if ($a['theme'] === $theme) {
                    $texte .= '• *' . self::nom($cle) . '* : ' . lcfirst($a['titre']) . "\n";
                }
            }
        }
        return rtrim($texte) . "\n\nVous pouvez aussi écrire comme vous parlez : « bayam combien de polo il reste ? », « bayam qui me doit ? ».";
    }

    public static function detail(string $action): string
    {
        $a = self::ACTIONS[$action];
        $exemples = implode("\n", array_map(static fn ($e) => '• *' . $e . '*', $a['exemples']));
        return '📘 *' . $a['titre'] . "*\n\n" . $a['detail'] . "\n\n*Exemples*\n" . $exemples
            . "\n\nToutes les actions : *bayam aide*.";
    }

    /**
     * Action désignée par un mot ou une phrase (« publier », « vendre », « c'est quoi le plancher »), ou null.
     * Les noms d'actions et mots-clés du Copilote passent avant les synonymes : dans « comment créer une promo »,
     * c'est « promo » qui compte, pas « créer ».
     */
    public static function action_de(string $demande): ?string
    {
        $mots = preg_split('/[^\p{L}\d-]+/u', Bayam_WA_Outils::normaliser($demande), -1, PREG_SPLIT_NO_EMPTY);
        // « paiement à la livraison », « mes conditions » : les conditions de paiement, pas le lien de paiement.
        if (array_intersect($mots, ['livraison', 'conditions', 'politique'])) {
            return 'politique';
        }
        foreach ($mots as $mot) {
            $intention = isset(self::ACTIONS[$mot]) ? $mot : Bayam_WA_Copilote::intention($mot);
            if ($intention !== null && isset(self::ACTIONS[$intention])) {
                return $intention;
            }
        }
        foreach ($mots as $mot) {
            if (isset(self::SYNONYMES[$mot])) {
                return self::SYNONYMES[$mot];
            }
        }
        return null;
    }

    /** Liste compacte des actions et de leur écriture, pour le modèle de langage qui traduit les demandes libres. */
    public static function pour_ia(): string
    {
        $lignes = [];
        foreach (self::ACTIONS as $cle => $a) {
            $lignes[] = "- {$cle} : {$a['titre']}. Écriture : " . implode(' | ', $a['exemples']);
        }
        return implode("\n", $lignes);
    }

    /** Nom d'une action tel qu'on l'écrit après « bayam ». */
    private static function nom(string $cle): string
    {
        return ['photo' => 'photo + légende', 'vitrine' => 'menu (chez un client)', 'paye' => 'payé', 'politique' => 'paiement', 'numero_paiement' => 'momo / om'][$cle] ?? $cle;
    }
}
