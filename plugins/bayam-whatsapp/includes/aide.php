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
        'publier' => ['theme' => 'publier', 'titre' => 'Publier des articles dans vos groupes ou votre statut',
            'detail' => "Envoie les articles choisis dans les groupes que vous avez sélectionnés (espace vendeur, rubrique WhatsApp), au rythme d'une personne pour protéger votre numéro, puis vous fait le bilan. Ajoutez *statut*, *partout* (groupes et statut) ou *dans* suivi du nom d'un groupe. *nouveautés* et *promos* choisissent les articles pour vous.",
            'exemples' => ['bayam publier robes', 'bayam publier polo statut', 'bayam publier nouveautés partout', 'bayam publier promos dans Clientes Akwa']],
        'envoie' => ['theme' => 'publier', 'titre' => 'Envoyer une sélection d\'articles à un client',
            'detail' => "Envoie au client, sur son WhatsApp, les articles demandés avec prix et liens, en un message. Utile quand un client vous demande « qu'est-ce que vous avez ? ». Le client doit vous avoir déjà écrit.",
            'exemples' => ['bayam envoie robes 6 90 00 00 10']],
        'vitrine' => ['theme' => 'publier', 'titre' => 'Montrer votre boutique à un client',
            'detail' => "Dans la discussion du client, écrivez *bayam menu* : il reçoit la liste de vos rayons, puis les articles en image et un lien personnel pour commander. Le client peut aussi l'obtenir seul en vous écrivant *bayam*.",
            'exemples' => ['bayam menu (dans la discussion du client)']],
        'vendu' => ['theme' => 'vendre', 'titre' => 'Enregistrer une vente faite en direct',
            'detail' => "Enregistre une vente conclue au téléphone ou en boutique : le stock baisse, la vente entre dans vos bilans et le client reçoit un reçu. Donnez l'article, la taille, la quantité (x2), le prix réellement payé et le moyen : *cash*, *momo*, *om* ou *credit* suivi du nom du client (la dette est notée toute seule).",
            'exemples' => ['bayam vendu robe sirène M x2 30000 momo', 'bayam vendu polo 12000 cash', 'bayam vendu casquette 6000 credit Paul']],
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
            'detail' => "Résume vos ventes : aujourd'hui, *hier*, *semaine* ou *mois* : montant encaissé, meilleures ventes, articles en rupture et dettes à recouvrer. *rapport 21h* choisit l'heure du résumé quotidien, *rapport off* l'arrête.",
            'exemples' => ['bayam bilan', 'bayam bilan semaine', 'bayam mes ventes du mois', 'bayam rapport 21h']],
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
        'aide' => ['theme' => 'boutique', 'titre' => 'Voir toutes les actions, ou le détail d\'une action',
            'detail' => "Sans rien après : toutes les actions par thème. Avec le nom d'une action : à quoi elle sert, comment l'écrire, des exemples. Vous pouvez aussi demander « comment publier ? » ou « c'est quoi le plancher ? ».",
            'exemples' => ['bayam aide', 'bayam aide publier', 'bayam comment créer une promo ?']],
        'support' => ['theme' => 'boutique', 'titre' => 'Joindre l\'équipe BAYAM',
            'detail' => "Pour un souci ou une question, l'équipe BAYAM vous répond du lundi au samedi, de 8 h à 19 h.",
            'exemples' => ['bayam support']],
    ];

    /** Mots que les vendeurs emploient pour désigner une action, en plus des mots-clés du Copilote. */
    private const SYNONYMES = [
        'vendre' => 'vendu', 'vente' => 'vendu', 'ventes' => 'bilan', 'payer' => 'lien', 'paiement' => 'lien', 'encaisser' => 'lien',
        'photo' => 'photo', 'photos' => 'photo', 'nouveau' => 'photo', 'ajouter' => 'photo', 'creer' => 'photo', 'article' => 'photo',
        'stocker' => 'stock', 'quantite' => 'stock', 'reception' => 'stock', 'arrivage' => 'stock',
        'montrer' => 'vitrine', 'vitrine' => 'vitrine', 'rayons' => 'vitrine',
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
        return ['photo' => 'photo + légende', 'vitrine' => 'menu (chez un client)', 'paye' => 'payé', 'ok' => 'ok', 'annule' => 'annule'][$cle] ?? $cle;
    }
}
