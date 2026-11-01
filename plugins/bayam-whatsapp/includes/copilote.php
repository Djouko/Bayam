<?php
defined('ABSPATH') || exit;

/**
 * Copilote WhatsApp du vendeur (cahier des charges, exigences COP et tableau des commandes) : le vendeur écrit dans
 * « Message à moi-même », le Copilote répond. Les commandes tolèrent les fautes et les accents oubliés.
 * traiter() rend la liste des réponses ; l'appelant les envoie dans la bonne discussion.
 */
final class Bayam_WA_Copilote
{
    /** Mots-clés reconnus (et leurs variantes courantes) pour chaque intention. */
    private const INTENTIONS = [
        'menu' => ['menu', 'aide', 'help', 'bonjour', 'salut', '?'],
        'catalogue' => ['catalogue', 'catalog', 'produits', 'articles'],
        'cartes' => ['cartes', 'carte'],
        'statut' => ['statut', 'status'],
        'publier' => ['publier', 'publie', 'poster', 'diffuser'],
        'envoie' => ['envoie', 'envoyer', 'envoi'],
        'stock' => ['stock', 'stocks', 'reste'],
        'vendu' => ['vendu', 'vendue', 'vente', 'vendus'],
        'lien' => ['lien', 'paiement', 'encaisser'],
        'commandes' => ['commandes', 'commande', 'cmd'],
        'bilan' => ['bilan', 'ventes', 'rapport', 'resume'],
        'client' => ['client', 'cliente'],
        'dettes' => ['dettes', 'dette', 'credit', 'credits'],
        'paye' => ['paye', 'payé', 'rembourse', 'regle'],
        'promo' => ['promo', 'promotion', 'coupon', 'code'],
        'groupes' => ['groupes', 'groupe'],
        'boutique' => ['boutique', 'magasin'],
        'pin' => ['pin'],
        'equipe' => ['equipe', 'team'],
        'support' => ['support', 'assistance', 'humain'],
        'assistant' => ['assistant', 'occupe', 'occupee', 'remplace', 'remplace-moi'],
        'plancher' => ['plancher', 'minimum'],
        'remise' => ['remise', 'reduction'],
        'ok' => ['ok', 'confirme', 'valide'],
        'annule' => ['annule', 'annuler'],
    ];

    /** Actions qu'un membre de l'équipe ne peut faire qu'avec le code PIN (le vendeur, lui, tient son téléphone). */
    private const SENSIBLES = ['vendu', 'lien', 'promo', 'dettes', 'paye', 'moins', 'plancher', 'remise', 'ok', 'annule'];

    private int $vendeur;
    private bool $equipe;

    private function __construct(int $vendeur, bool $equipe)
    {
        $this->vendeur = $vendeur;
        $this->equipe = $equipe;
    }

    /**
     * @param array|null $photo ['binaire' => ...] quand le message contient une photo
     * @return array<int, array{texte?: string, image?: array, legende?: string}>
     */
    public static function traiter(int $vendeur, string $message, ?array $photo = null, bool $equipe = false): array
    {
        $c = new self($vendeur, $equipe);
        try {
            return $c->repondre(trim($message), $photo);
        } catch (Throwable $e) {
            Bayam_WA_Outils::journal('Copilote', ['erreur' => $e->getMessage(), 'message' => $message]);
            return [['texte' => "Désolé, je n'ai pas pu faire cette action. Réessayez ou écrivez *bayam menu*."]];
        }
    }

    private function repondre(string $message, ?array $photo): array
    {
        if ($photo) {
            return $this->ajout_produit($message, $photo);
        }
        $t = Bayam_WA_Outils::normaliser($message);
        if ($t === '') {
            return [['texte' => $this->menu()]]; // « bayam » seul
        }
        // Mouvement de stock : « +5 robe rouge M », « -2 robe rouge M ».
        if (preg_match('/^([+-])\s*(\d+)\s+(.+)$/u', $t, $m)) {
            if ($m[1] === '-' && ($refus = $this->controle_pin('moins', $t))) {
                return $refus;
            }
            return $this->mouvement((int) ($m[1] . $m[2]), $this->sans_pin($m[3]));
        }
        if (str_starts_with($t, '#')) {
            return $this->catalogue(substr($t, 1));
        }
        [$premier, $reste] = array_pad(explode(' ', $t, 2), 2, '');
        $intention = $this->intention($premier);
        $naturel = $intention === null ? $this->naturel($t, mb_strtolower(trim($message), 'UTF-8')) : null;
        if ($naturel) {
            [$intention, $reste] = $naturel;
            if ($intention === 'entree') {
                return $this->mouvement((int) strtok($reste, ' '), (string) strtok(''));
            }
        }
        if ($intention === null) {
            // Des noms d'articles seuls (« robes », « sweat zippé ») valent une recherche dans le catalogue ; tous les
            // mots doivent y être, pour qu'une phrase inconnue ne renvoie pas d'articles au hasard.
            $trouves = Bayam_WA_Boutique::chercher($this->vendeur, $t, true, 8, true);
            return $trouves ? $this->catalogue($t) : [['texte' => "Je n'ai pas compris « {$message} ». Écrivez *bayam menu* pour voir ce que je sais faire."]];
        }
        if (in_array($intention, self::SENSIBLES, true) && ($refus = $this->controle_pin($intention, $t))) {
            return $refus;
        }
        $reste = $this->sans_pin($reste);
        // Les noms de clients gardent leurs accents (« Nadège ») : même découpage, sans retirer les accents.
        $avec_accents = $naturel ? $reste : $this->sans_pin(array_pad(explode(' ', mb_strtolower(preg_replace('/\s+/u', ' ', trim($message)), 'UTF-8'), 2), 2, '')[1]);
        return match ($intention) {
            'menu' => [['texte' => $this->menu()]],
            'catalogue' => $this->catalogue($reste),
            'cartes' => $this->cartes($reste, false),
            'statut' => $this->cartes($reste, true),
            'publier' => $this->publier($reste),
            'envoie' => $this->envoyer_collection($reste),
            'stock' => $this->stock($reste),
            'vendu' => $this->vendu($reste),
            'lien' => $this->lien($avec_accents),
            'commandes' => $this->commandes(),
            'bilan' => $this->bilan($reste),
            'client' => $this->client($reste),
            'dettes' => $this->dettes($avec_accents),
            'paye' => $this->paye($avec_accents),
            'promo' => $this->promo($reste),
            'groupes' => $this->groupes(),
            'boutique' => $this->lien_boutique(),
            'pin' => $this->pin($reste),
            'equipe' => $this->equipe_commande($reste),
            'assistant' => $this->assistant($reste),
            'plancher' => $this->plancher($reste),
            'remise' => $this->remise($reste),
            'ok' => $this->confirmer_commande($reste, true),
            'annule' => $this->confirmer_commande($reste, false),
            'support' => [['texte' => "L'équipe BAYAM vous répond du lundi au samedi, de 8 h à 19 h : écrivez à contact@bayam.cm en décrivant votre souci (et une capture d'écran si possible)."]],
        };
    }

    /**
     * Phrases courantes des vendeurs, dites comme ils parlent : « combien de polo il reste ? », « mes ventes de la
     * semaine », « qui me doit ? », « Paul a payé 2000 », « j'ai reçu 10 casquettes », « montre-moi mes commandes ».
     *
     * @return array{0: string, 1: string}|null intention et reste de la demande
     */
    private function naturel(string $t, string $brut): ?array
    {
        $t = trim(preg_replace('/[?!.]+/', ' ', str_replace('’', "'", $t)));
        $brut = trim(preg_replace('/[?!.]+/', ' ', str_replace('’', "'", $brut)));
        if (preg_match('/^je suis (?:occupee?|prise?|en route|au marche|en reunion|en voyage)\b(.*)$/u', $t, $m) || preg_match('/^remplace[- ]?moi\b(.*)$/u', $t, $m)) {
            return ['assistant', trim($m[1])];
        }
        if (preg_match('/^(?:je suis (?:la|de retour|revenue?)|je (?:reprends|reprend|suis dispo|suis disponible))\b/u', $t)) {
            return ['assistant', 'off'];
        }
        if (preg_match('/^(?:j\'?ai )?(?:ajoute|ajouter|recu|arrivage de|rentre|nouveau stock de)\s+(\d+)\s+(.+)$/u', $t, $m)) {
            return ['entree', $m[1] . ' ' . $m[2]];
        }
        // Le nom vient du texte d'origine : « Hélène a payé 2000 » garde son accent.
        if (preg_match('/^(.+?)\s+(?:a|m\'a|ma)\s+(?:payé|paye|remboursé|rembourse|réglé|regle|donné|donne|versé|verse)\s+(\d[\d .]*k?)\s*(?:f|fcfa|frs?)?$/u', $brut, $m)) {
            return ['paye', $m[1] . ' ' . $m[2]];
        }
        if (preg_match('/\bqui (?:me|nous) doi(?:t|vent)\b|\b(?:mes|les) (?:dettes|credits)\b/u', $t)) {
            return ['dettes', ''];
        }
        if (preg_match('/\bcommandes?\b/u', $t)) {
            return ['commandes', ''];
        }
        if (preg_match('/\b(?:reste|restent|stock)\b/u', $t)) {
            $vides = ['combien', 'de', 'des', 'du', 'd', 'il', 'me', 'nous', 'reste', 'restent', 'en', 'stock', 't', 'y', 'a', 'est', 'ce', 'que', 'qu', 'encore', 'les', 'mes', 'la', 'le'];
            $mots = array_filter(preg_split("/[\\s']+/u", $t), static fn ($m) => $m !== '' && !in_array($m, $vides, true));
            return ['stock', implode(' ', $mots)];
        }
        if (preg_match('/\b(?:ventes?|vendu|bilan|chiffre|recette)\b/u', $t)) {
            return ['bilan', preg_match('/\b(semaine|mois|hier)\b/u', $t, $p) ? $p[1] : ''];
        }
        return null;
    }

    private function intention(string $mot): ?string
    {
        foreach (self::INTENTIONS as $intention => $mots) {
            if (in_array($mot, $mots, true)) {
                return $intention;
            }
        }
        // Une faute de frappe sur un mot-clé d'au moins 5 lettres (« comandes », « catalgue »).
        foreach (self::INTENTIONS as $intention => $mots) {
            foreach ($mots as $m) {
                if (strlen($m) >= 5 && levenshtein($mot, $m) <= 1) {
                    return $intention;
                }
            }
        }
        return null;
    }

    // --- Sécurité ----------------------------------------------------------------------------------------------

    private function controle_pin(string $intention, string $texte): ?array
    {
        if (!$this->equipe) {
            return null;
        }
        $pin = (string) Bayam_WA_Stockage::reglages($this->vendeur)['pin'];
        if ($pin === '') {
            return [['texte' => "Cette action est réservée au propriétaire de la boutique tant qu'il n'a pas choisi de code PIN pour l'équipe (commande *bayam pin 1234* dans sa discussion « Message à moi-même »)."]];
        }
        // Un code de 4 chiffres se devine par essais : après 5 erreurs en 15 minutes, actions sensibles bloquées.
        $compteur = 'bayam_wa_pin_echecs_' . $this->vendeur;
        $echecs = (int) get_transient($compteur);
        if ($echecs >= 5) {
            return [['texte' => "🔒 Trop de codes PIN erronés : les actions sensibles de l'équipe sont bloquées pendant 15 minutes."]];
        }
        if (!preg_match('/\bpin\s*(\d{4,6})$/', $texte, $m)) {
            return [['texte' => "Action sensible : ajoutez le code PIN de la boutique à la fin du message, par exemple « … pin 1234 »."]];
        }
        if (!hash_equals($pin, $m[1])) {
            set_transient($compteur, ++$echecs, 15 * MINUTE_IN_SECONDS);
            if ($echecs === 5) {
                Bayam_WA_Regles::au_vendeur($this->vendeur, "⚠️ 5 codes PIN erronés viennent d'être tapés par un numéro de votre équipe. Les ventes, liens, promos et dettes de l'équipe sont bloqués 15 minutes. Si ce n'est pas normal, changez le code : *bayam pin 1234*.");
            }
            return [['texte' => 'Code PIN incorrect. Vérifiez-le auprès du propriétaire de la boutique.']];
        }
        delete_transient($compteur);
        return null;
    }

    private function sans_pin(string $texte): string
    {
        return trim(preg_replace('/\bpin\s*\d{4,6}$/', '', $texte));
    }

    // --- Commandes ----------------------------------------------------------------------------------------------

    private function menu(): string
    {
        $boutique = Bayam_WA_Outils::nom_boutique($this->vendeur);
        return "*Copilote BAYAM, {$boutique}*\n"
            . "Commencez chaque message par *bayam* : vos notes personnelles ici restent tranquilles. "
            . "Majuscules, accents et petites fautes sont acceptés (*Bayam*, *BAYAM*, *bayan*).\n\n"
            . "🛍️ *bayam robes* : vos robes en stock\n"
            . "🖼️ *bayam cartes robes* : cartes à transférer\n"
            . "📣 *bayam publier robes* : dans vos groupes, envoi étalé\n"
            . "📲 *bayam statut robes* : visuels pour votre statut\n"
            . "📦 *bayam stock robe rouge* : quantités par taille\n"
            . "➕ *bayam +5 robe rouge M* / *bayam -2 robe rouge M*\n"
            . "💵 *bayam vendu robe rouge M x2 15000 cash*\n"
            . "🔗 *bayam lien 15000 Marie* : lien de paiement\n"
            . "🧾 *bayam commandes* : à payer, à préparer\n"
            . "📊 *bayam bilan*, *bayam bilan semaine*, *bayam bilan mois*\n"
            . "👤 *bayam client 6 90 00 00 10*\n"
            . "📝 *bayam dettes*, *bayam dette Paul 5000*, *bayam payé Paul 2000*\n"
            . "🏷️ *bayam promo NOEL10 10%*\n"
            . "📷 Photo avec la légende *bayam robe rouge 15000 M:3 L:2* : nouveau produit\n"
            . "🤖 *bayam je suis occupé* : votre assistant répond à vos clients · *bayam je reprends*\n"
            . "🔒 *bayam plancher robe rouge 12000* : prix minimum en négociation · *bayam remise 15%*\n"
            . "🔁 *bayam boutique* : votre lien unique · *bayam groupes* · *bayam support*";
    }

    private function catalogue(string $mots): array
    {
        $produits = Bayam_WA_Boutique::chercher($this->vendeur, $mots);
        if (!$produits) {
            return [['texte' => $mots === '' ? "Aucun article en stock pour le moment." : "Rien en stock pour « {$mots} ». Essayez un autre mot, ou *bayam catalogue* pour tout voir."]];
        }
        $lignes = [];
        foreach ($produits as $i => $p) {
            $lignes[] = ($i + 1) . '. *' . $p->get_name() . '* : ' . Bayam_WA_Outils::fcfa((float) $p->get_price()) . "\n   " . Bayam_WA_Liens::produit($this->vendeur, $p);
        }
        $titre = $mots === '' ? 'Votre catalogue' : "En stock pour « {$mots} »";
        return [['texte' => "*{$titre}* (" . count($produits) . ")\n\n" . implode("\n", $lignes)
            . "\n\n👉 *bayam cartes {$mots}* pour les cartes à transférer, *bayam publier {$mots}* pour vos groupes."]];
    }

    private function cartes(string $mots, bool $statut): array
    {
        $produits = Bayam_WA_Boutique::chercher($this->vendeur, $mots, true, 5);
        if (!$produits) {
            return [['texte' => "Rien en stock pour « {$mots} »."]];
        }
        $boutique = Bayam_WA_Outils::nom_boutique($this->vendeur);
        $reponses = [];
        foreach ($produits as $p) {
            $lien = Bayam_WA_Liens::produit($this->vendeur, $p, $statut ? 'statut' : 'carte');
            $reponses[] = [
                'image' => Bayam_WA_Cartes::carte($p, $lien, $boutique, $statut),
                'legende' => $p->get_name() . ' : ' . Bayam_WA_Outils::fcfa((float) $p->get_price()) . "\nCommander : " . $lien,
            ];
        }
        $reponses[] = ['texte' => $statut
            ? "Voici vos visuels de statut. Appuyez longuement sur une image, puis « Transférer » et choisissez *Mon statut*. Ou écrivez *bayam publier {$mots} statut* et je les publie pour vous."
            : 'Appuyez longuement sur une carte, puis « Transférer » vers vos groupes et clients. Chaque lien compte les clics.'];
        return $reponses;
    }

    /**
     * « bayam publier robes », « … statut », « … partout », « … dans Clientes Akwa », « bayam publier nouveautés »,
     * « bayam publier promos » : la sélection part en quelques secondes, au rythme d'un vendeur.
     */
    private function publier(string $reste): array
    {
        $ou = preg_match('/\bpartout\b/', $reste) ? 'partout' : (preg_match('/\b(statut|status)\b/', $reste) ? 'statut' : 'groupes');
        $groupes = null;
        if (preg_match('/\bdans (?:le groupe |mon groupe )?(.+)$/', $reste, $m) && !preg_match('/^(?:tous )?(?:mes |les )?groupes?$/', trim($m[1]))) {
            $nom = trim(preg_replace('/\b(?:et )?(?:statut|status|partout)\b/', '', $m[1]));
            foreach (Bayam_WA_Stockage::reglages($this->vendeur)['groupes'] as $id => $libelle) {
                $l = Bayam_WA_Outils::normaliser($libelle);
                if ($nom !== '' && (str_contains($l, $nom) || str_contains($nom, $l))) {
                    $groupes[$id] = $libelle;
                }
            }
            if (!$groupes) {
                return [['texte' => "Je ne trouve pas le groupe « {$nom} » parmi vos groupes de publication (*bayam groupes* pour la liste)."]];
            }
            $reste = trim(substr($reste, 0, strpos($reste, $m[0])));
        }
        $mots = trim(preg_replace('/\b(statut|status|partout|groupes?|dans|mes|sur|mon|le|les|et|tous|tout)\b/', '', $reste));
        $mots = trim(preg_replace('/\s+/', ' ', $mots));
        if (preg_match('/\b(nouveautes?|nouveaux?|nouvelles?|derniers?|dernieres?|new)\b/', $mots)) {
            $en_stock = array_filter(Bayam_WA_Boutique::produits($this->vendeur), static fn (WC_Product $p) => $p->is_in_stock());
            $recents = array_filter($en_stock, static fn (WC_Product $p) => $p->get_date_created() && $p->get_date_created()->getTimestamp() > time() - 7 * DAY_IN_SECONDS);
            $produits = array_slice(array_values($recents ?: $en_stock), 0, 5); // sans nouveauté de la semaine : les 5 derniers
        } elseif (preg_match('/\b(promos?|promotions?|soldes?)\b/', $mots)) {
            $produits = array_slice(array_values(array_filter(Bayam_WA_Boutique::produits($this->vendeur), static fn (WC_Product $p) => $p->is_in_stock() && $p->is_on_sale())), 0, 5);
        } else {
            $produits = Bayam_WA_Boutique::chercher($this->vendeur, $mots, true, 5);
        }
        if (!$produits) {
            return [['texte' => "Rien en stock pour « {$mots} »."]];
        }
        return [['texte' => Bayam_WA_Diffusion::programmer($this->vendeur, $produits, $ou, $groupes)]];
    }

    private function envoyer_collection(string $reste): array
    {
        if (!preg_match('/^(.*?)\s*((?:\+?237)?\s*6[\d ]{8,12})$/', $reste, $m) || !($numero = Bayam_WA_Outils::numero($m[2]))) {
            return [['texte' => 'Écrivez par exemple : *bayam envoie robes 6 90 00 00 10*.']];
        }
        $produits = Bayam_WA_Boutique::chercher($this->vendeur, $m[1], true, 6);
        if (!$produits) {
            return [['texte' => "Rien en stock pour « {$m[1]} »."]];
        }
        return [['texte' => Bayam_WA_Clients::envoyer_collection($this->vendeur, $numero, $produits)]];
    }

    private function stock(string $reste): array
    {
        if ($reste === '') {
            return [['texte' => $this->etat_du_stock()]];
        }
        [$p] = Bayam_WA_Boutique::designer($this->vendeur, $reste);
        if (!$p) {
            return [['texte' => "Je ne trouve pas « {$reste} » dans votre boutique."]];
        }
        return [['texte' => '*Stock : ' . $p->get_name() . "*\n" . implode("\n", Bayam_WA_Boutique::stock_lignes($p))]];
    }

    private function mouvement(int $delta, string $reste): array
    {
        [$p, $v] = Bayam_WA_Boutique::designer($this->vendeur, $reste);
        if (!$p) {
            return [['texte' => "Je ne trouve pas « {$reste} » dans votre boutique."]];
        }
        if ($p->is_type('variable') && !$v) {
            return [['texte' => 'Précisez la variante, par exemple : *' . ($delta > 0 ? '+' : '') . $delta . ' ' . $p->get_name() . " M*.\nVariantes : " . implode(' · ', Bayam_WA_Boutique::stock_lignes($p))]];
        }
        $cible = $v ?: $p;
        $nouveau = Bayam_WA_Boutique::mouvement($cible, $delta);
        $nom = $v ? $p->get_name() . ' (' . wc_get_formatted_variation($v, true, false, false) . ')' : $p->get_name();
        return [['texte' => ($delta > 0 ? '✅ Entrée' : '✅ Sortie') . " de {$this->abs($delta)} : *{$nom}*.\nStock : {$nouveau}."]];
    }

    private function abs(int $n): int
    {
        return abs($n);
    }

    /** « bayam je suis occupé », « bayam assistant 2h », « bayam je reprends » : l'assistant prend ou rend la main. */
    private function assistant(string $reste): array
    {
        if (preg_match('/^(?:off|stop|arret|non|fin|reprends|je reprends)$/', $reste)) {
            Bayam_WA_Assistant::desactiver($this->vendeur);
            return [['texte' => "👋 Vous reprenez la main : l'assistant ne répond plus à vos clients."]];
        }
        if (!Bayam_WA_Assistant::disponible()) {
            return [['texte' => "L'assistant n'est pas encore branché sur votre boutique : l'équipe BAYAM l'active dès que possible. Écrivez *bayam support* si c'est urgent."]];
        }
        $heures = null;
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(h|heures?|min|minutes?)\b/', $reste, $m)) {
            $n = (float) str_replace(',', '.', $m[1]);
            $heures = str_starts_with($m[2], 'm') ? $n / 60 : $n;
        }
        Bayam_WA_Assistant::activer($this->vendeur, $heures);
        $remise = (int) Bayam_WA_Stockage::reglages($this->vendeur)['remise_max'];
        $duree = $heures ? ' pendant ' . ($heures < 1 ? round($heures * 60) . ' min' : rtrim(rtrim(number_format($heures, 1, ',', ''), '0'), ',') . ' h') : ' jusqu\'à *bayam je reprends*';
        return [['texte' => "🤖 *Votre assistant prend le relais*{$duree}.\n"
            . "Il répond à vos clients en privé (prix, tailles, livraison), négocie sans jamais descendre sous vos prix minimums "
            . "({$remise} % de remise au plus, ou *bayam plancher polo 10000* article par article) et vous envoie chaque commande à confirmer.\n"
            . "Vos discussions personnelles restent sans réponse, et dès que vous écrivez vous-même à un client, il s'efface de cette discussion."]];
    }

    /** « bayam plancher polo 10000 » : prix minimum en négociation ; « bayam plancher polo » : le prix minimum actuel. */
    private function plancher(string $reste): array
    {
        if (preg_match('/^(.+?)\s+(\d[\d .]*k?)$/', $reste, $m) && ($montant = Bayam_WA_Outils::montant($m[2]))) {
            [$p] = Bayam_WA_Boutique::designer($this->vendeur, $m[1]);
            if (!$p) {
                return [['texte' => "Je ne trouve pas « {$m[1]} » dans votre boutique."]];
            }
            if ($montant > (float) $p->get_price()) {
                return [['texte' => 'Ce prix minimum dépasse le prix affiché de *' . $p->get_name() . '* (' . Bayam_WA_Outils::fcfa((float) $p->get_price()) . ').']];
            }
            $p->update_meta_data('_bayam_prix_plancher', $montant);
            $p->save();
            return [['texte' => '🔒 Prix minimum de *' . $p->get_name() . '* : ' . Bayam_WA_Outils::fcfa($montant) . ' (prix affiché ' . Bayam_WA_Outils::fcfa((float) $p->get_price()) . ").\nVotre assistant ne descendra jamais en dessous."]];
        }
        [$p] = $reste !== '' ? Bayam_WA_Boutique::designer($this->vendeur, $reste) : [null];
        if (!$p) {
            return [['texte' => 'Exemple : *bayam plancher polo 10000* (prix le plus bas que votre assistant peut accepter).']];
        }
        return [['texte' => 'Prix minimum de *' . $p->get_name() . '* : ' . Bayam_WA_Outils::fcfa(Bayam_WA_Assistant::plancher($this->vendeur, $p)) . ' (prix affiché ' . Bayam_WA_Outils::fcfa((float) $p->get_price()) . ').']];
    }

    /** « bayam remise 15% » : remise maximale de l'assistant, pour les articles sans prix minimum fixé. */
    private function remise(string $reste): array
    {
        if (!preg_match('/^(?:max(?:imum|imale)?\s*)?(\d{1,2})\s*%?$/', $reste, $m)) {
            return [['texte' => 'Remise maximale actuelle : ' . Bayam_WA_Stockage::reglages($this->vendeur)['remise_max'] . " %. Exemple : *bayam remise 15%*."]];
        }
        if ((int) $m[1] > 50) {
            return [['texte' => 'Plus de 50 % de remise ressemble à une erreur : vérifiez le chiffre.']];
        }
        Bayam_WA_Stockage::enregistrer($this->vendeur, ['remise_max' => (int) $m[1]]);
        return [['texte' => "🏷️ Votre assistant peut accorder jusqu'à {$m[1]} % de remise en négociation (sauf prix minimum fixé article par article)."]];
    }

    /** « bayam ok 740 » / « bayam annule 740 » : commande conclue par l'assistant, confirmée ou annulée. */
    private function confirmer_commande(string $reste, bool $ok): array
    {
        if (!preg_match('/^(\d+)/', $reste, $m)) {
            return [['texte' => 'Exemple : *bayam ok 740* pour confirmer, *bayam annule 740* pour annuler.']];
        }
        $commande = wc_get_order((int) $m[1]);
        if (!$commande || (int) $commande->get_meta('_bayam_vendeur') !== $this->vendeur || !$commande->get_meta('_bayam_assistant')) {
            return [['texte' => "Je ne trouve pas de commande n° {$m[1]} conclue par votre assistant."]];
        }
        if (!$commande->has_status('on-hold')) {
            return [['texte' => "La commande n° {$m[1]} est déjà " . wc_get_order_status_name($commande->get_status()) . '.']];
        }
        $numero = Bayam_WA_Outils::numero($commande->get_billing_phone());
        if (!$ok) {
            $commande->update_status('cancelled', 'Annulée par la boutique sur WhatsApp.');
            return [['texte' => "❌ Commande n° {$m[1]} annulée." . ($numero ? " Prévenez le client si besoin : https://wa.me/{$numero}" : '')]];
        }
        $commande->update_status('processing', 'Confirmée par la boutique sur WhatsApp.');
        $articles = implode(', ', array_map(static fn ($l) => $l->get_quantity() . ' × ' . $l->get_name(), $commande->get_items()));
        $prevenu = $numero && Bayam_WA_Regles::repondre($this->vendeur, Bayam_WA_Outils::chat_id($numero),
            '✅ ' . Bayam_WA_Outils::nom_boutique($this->vendeur) . " confirme votre commande n° {$m[1]} : {$articles}, total " . Bayam_WA_Outils::fcfa((float) $commande->get_total()) . ". Nous vous contactons pour la livraison. Merci !", null, 'assistant');
        return [['texte' => "✅ Commande n° {$m[1]} confirmée." . ($prevenu ? ' Le client est prévenu sur WhatsApp.' : '')]];
    }

    /** État du stock de la boutique : articles épuisés d'abord, puis les autres avec leurs quantités. */
    private function etat_du_stock(): string
    {
        $epuises = $autres = [];
        foreach (Bayam_WA_Boutique::produits($this->vendeur) as $p) {
            $ligne = '• ' . $p->get_name() . ' : ' . implode(' · ', Bayam_WA_Boutique::stock_lignes($p));
            $p->is_in_stock() ? $autres[] = $ligne : $epuises[] = $ligne;
        }
        if (!$epuises && !$autres) {
            return "Aucun article dans votre boutique pour l'instant. Envoyez une photo avec la légende *bayam robe rouge 15000 M:3* pour en créer un.";
        }
        return '*Stock de votre boutique*' . ($epuises ? "\n\n⚠️ Épuisés :\n" . implode("\n", $epuises) : '')
            . ($autres ? "\n\n" . implode("\n", array_slice($autres, 0, 25)) : '')
            . (count($autres) > 25 ? "\n… et " . (count($autres) - 25) . " autres. *bayam stock nom de l'article* pour le détail." : '');
    }

    private function vendu(string $reste): array
    {
        $modes = ['cash' => 'cash', 'especes' => 'cash', 'espece' => 'cash', 'momo' => 'momo', 'mtn' => 'momo', 'om' => 'om', 'orange' => 'om', 'credit' => 'credit'];
        $nombres = ['un' => 1, 'une' => 1, 'deux' => 2, 'trois' => 3, 'quatre' => 4, 'cinq' => 5, 'six' => 6, 'sept' => 7, 'huit' => 8, 'neuf' => 9, 'dix' => 10,
            'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5];
        $mode = 'cash';
        $quantite = 1;
        $montant = null;
        $mots = [];
        foreach (explode(' ', $reste) as $mot) {
            if (isset($modes[$mot])) {
                $mode = $modes[$mot];
            } elseif (preg_match('/^(?:x(\d+)|(\d+)x)$/', $mot, $m)) {
                $quantite = max(1, (int) ($m[1] ?: $m[2]));
            } elseif (!$mots && (isset($nombres[$mot]) || (ctype_digit($mot) && (int) $mot > 0 && (int) $mot < 100))) {
                $quantite = $nombres[$mot] ?? (int) $mot; // quantité dite avant l'article
            } elseif (($n = Bayam_WA_Outils::montant($mot)) !== null && $n >= 100) {
                $montant = $n;
            } else {
                $mots[] = $mot;
            }
        }
        [$p, $v] = Bayam_WA_Boutique::designer($this->vendeur, implode(' ', $mots));
        if (!$p) {
            return [['texte' => 'Je ne trouve pas cet article. Exemple : *bayam vendu robe rouge M x2 15000 cash*.']];
        }
        if ($p->is_type('variable') && !$v) {
            return [['texte' => 'Précisez la variante vendue, par exemple : *bayam vendu ' . $p->get_name() . " M*.\n" . implode(' · ', Bayam_WA_Boutique::stock_lignes($p))]];
        }
        $article = $v ?: $p;
        if ($article->managing_stock() && (int) $article->get_stock_quantity() < $quantite) {
            return [['texte' => "Stock insuffisant : il reste {$article->get_stock_quantity()} pièce(s)."]];
        }
        $commande = Bayam_WA_Boutique::vendre($this->vendeur, $p, $v, $quantite, $montant, $mode);
        $restant = $article->managing_stock() ? (int) wc_get_product($article->get_id())->get_stock_quantity() : null;
        $recu = Bayam_WA_Liens::creer($this->vendeur, $commande->get_view_order_url(), 'recu');
        return [['texte' => "✅ Vente n° {$commande->get_order_number()} enregistrée : {$quantite} × " . $article->get_name() . ' = *' . Bayam_WA_Outils::fcfa((float) $commande->get_total()) . "*"
            . ($mode === 'credit' ? " (à crédit : pensez à *bayam dette nom montant*)" : '')
            . ($restant !== null ? "\nStock restant : {$restant}." : '')
            . "\nReçu à envoyer au client : {$recu}"]];
    }

    private function lien(string $reste): array
    {
        if (preg_match('/^boutique\b/', $reste)) {
            return $this->lien_boutique();
        }
        if (!preg_match('/^(\d[\d .]*\s*k?)\s*(.*)$/', $reste, $m) || !($montant = Bayam_WA_Outils::montant($m[1]))) {
            return [['texte' => 'Exemple : *bayam lien 15000 Marie* (montant, puis le nom du client).']];
        }
        $nom = $m[2] !== '' ? mb_convert_case($m[2], MB_CASE_TITLE, 'UTF-8') : 'Client';
        $url = Bayam_WA_Paiements::creer_lien($this->vendeur, $montant, $nom);
        return [['texte' => "🔗 Lien de paiement de *" . Bayam_WA_Outils::fcfa($montant) . "* pour {$nom} :\n{$url}\n\nTransférez-le au client. Je vous préviens ici dès que le paiement est reçu."]];
    }

    private function commandes(): array
    {
        $groupes = Bayam_WA_Boutique::commandes_ouvertes($this->vendeur);
        if (!$groupes) {
            return [['texte' => '👍 Aucune commande en attente.']];
        }
        $texte = "*Vos commandes en cours*\n";
        foreach ($groupes as $etape => $commandes) {
            $texte .= "\n*{$etape}* (" . count($commandes) . ")\n";
            foreach (array_slice($commandes, 0, 6) as $c) {
                $nom = preg_replace('/\s+/', ' ', trim($c->get_billing_first_name() . ' ' . $c->get_billing_last_name())) ?: 'Client';
                $texte .= "• n° {$c->get_order_number()}, {$nom}, " . Bayam_WA_Outils::fcfa((float) $c->get_total()) . "\n";
            }
        }
        return [['texte' => rtrim($texte)]];
    }

    private function bilan(string $reste): array
    {
        // « rapport 21h » : heure du résumé quotidien ; « rapport off » / « rapport on ».
        if (preg_match('/^(\d{1,2})\s*h(?:00)?$/', $reste, $h) && (int) $h[1] <= 23) {
            Bayam_WA_Stockage::enregistrer($this->vendeur, ['heure_rapport' => (int) $h[1], 'rapport_actif' => true]);
            return [['texte' => "⏰ Résumé quotidien chaque jour à {$h[1]} h. Bilan de la semaine le lundi, du mois le 1er."]];
        }
        if (in_array($reste, ['off', 'arret', 'stop', 'on', 'marche'], true)) {
            $actif = in_array($reste, ['on', 'marche'], true);
            Bayam_WA_Stockage::enregistrer($this->vendeur, ['rapport_actif' => $actif]);
            return [['texte' => $actif ? '⏰ Résumés automatiques réactivés.' : '🔕 Résumés automatiques arrêtés. *bayam rapport on* pour les reprendre.']];
        }
        $periodes = ['semaine' => ['monday this week', 'cette semaine'], 'mois' => ['first day of this month', 'ce mois-ci'], 'hier' => ['yesterday', 'hier']];
        $choix = 'jour';
        foreach (array_keys($periodes) as $p) {
            if (str_contains($reste, $p)) {
                $choix = $p;
            }
        }
        [$depuis, $libelle] = $periodes[$choix] ?? ['today', "aujourd'hui"];
        return [['texte' => Bayam_WA_Rapports::texte_bilan($this->vendeur, wp_date('Y-m-d 00:00:00', strtotime($depuis)), $libelle)]];
    }

    private function client(string $reste): array
    {
        $numero = Bayam_WA_Outils::numero($reste);
        if (!$numero) {
            return [['texte' => 'Exemple : *bayam client 6 90 00 00 10*.']];
        }
        $r = Bayam_WA_Boutique::client($this->vendeur, $numero);
        if (!$r['commandes']) {
            return [['texte' => 'Aucun achat chez vous pour le ' . Bayam_WA_Outils::numero_lisible($numero) . '.']];
        }
        $lignes = array_map(static fn (WC_Order $c) => '• ' . $c->get_date_created()->date_i18n('d/m') . ' : ' . Bayam_WA_Outils::fcfa((float) $c->get_total()) . ' (' . wc_get_order_status_name($c->get_status()) . ')', array_slice($r['commandes'], 0, 8));
        $dette = Bayam_WA_Rapports::dette_de($this->vendeur, $numero);
        return [['texte' => '*Client ' . Bayam_WA_Outils::numero_lisible($numero) . "*\n" . count($r['commandes']) . ' achat(s), ' . Bayam_WA_Outils::fcfa($r['total']) . " au total.\n" . implode("\n", $lignes)
            . ($dette ? "\n⚠️ Doit encore " . Bayam_WA_Outils::fcfa($dette) . '.' : '')]];
    }

    private function dettes(string $reste): array
    {
        if ($reste === '') {
            return [['texte' => Bayam_WA_Rapports::texte_dettes($this->vendeur)]];
        }
        // « dette Paul 5000 » ou « dette Paul 5000 15/11 » (échéance) ou « dette Paul 6 90 00 00 10 5000 ».
        if (!preg_match('/^(.+?)\s+(\d[\d .]*k?)(?:\s+(\d{1,2})\/(\d{1,2}))?$/', $reste, $m) || !($montant = Bayam_WA_Outils::montant($m[2]))) {
            return [['texte' => 'Exemple : *bayam dette Paul 5000* ou *bayam dette Paul 5000 15/11* (avec échéance).']];
        }
        $echeance = isset($m[3]) ? sprintf('%04d-%02d-%02d', (int) wp_date('Y'), (int) $m[4], (int) $m[3]) : null;
        $nom = mb_convert_case(trim($m[1]), MB_CASE_TITLE, 'UTF-8');
        Bayam_WA_Rapports::ajouter_dette($this->vendeur, $nom, $montant, $echeance);
        return [['texte' => "📝 Dette notée : *{$nom}* doit " . Bayam_WA_Outils::fcfa($montant) . ($echeance ? ' pour le ' . wp_date('d/m', strtotime($echeance)) : '') . ".\nQuand il paie : *bayam payé {$nom} montant*."]];
    }

    private function paye(string $reste): array
    {
        if (!preg_match('/^(.+?)\s+(\d[\d .]*k?)$/', $reste, $m) || !($montant = Bayam_WA_Outils::montant($m[2]))) {
            return [['texte' => 'Exemple : *bayam payé Paul 2000*.']];
        }
        return [['texte' => Bayam_WA_Rapports::encaisser_dette($this->vendeur, mb_convert_case(trim($m[1]), MB_CASE_TITLE, 'UTF-8'), $montant)]];
    }

    private function promo(string $reste): array
    {
        if (!preg_match('/^([a-z0-9]{3,20})\s+(\d+(?:[.,]\d+)?)\s*(%|f|fcfa)?$/', $reste, $m)) {
            return [['texte' => 'Exemple : *bayam promo NOEL10 10%* (pourcentage) ou *bayam promo AMIE 2000* (montant).']];
        }
        $pourcentage = ($m[3] ?? '') === '%';
        $valeur = (float) str_replace(',', '.', $m[2]);
        if ($pourcentage && $valeur > 90) {
            return [['texte' => 'Une remise de plus de 90 % ressemble à une erreur : vérifiez le pourcentage.']];
        }
        $coupon = Bayam_WA_Boutique::creer_promo($this->vendeur, $m[1], $valeur, $pourcentage);
        if (is_wp_error($coupon)) {
            return [['texte' => $coupon->get_error_message()]];
        }
        $remise = $pourcentage ? "{$valeur} %" : Bayam_WA_Outils::fcfa($valeur);
        $code = strtoupper($coupon->get_code());
        $boutique = Bayam_WA_Liens::boutique($this->vendeur, 'promo');
        return [['texte' => "🏷️ Code *{$code}* créé : -{$remise} sur votre boutique, valable 30 jours."],
            ['texte' => "🎉 Promo chez " . Bayam_WA_Outils::nom_boutique($this->vendeur) . " : -{$remise} avec le code *{$code}* jusqu'au " . wp_date('d/m', strtotime('+30 days')) . ".\nC'est ici : {$boutique}"]];
    }

    private function groupes(): array
    {
        $choisis = Bayam_WA_Stockage::reglages($this->vendeur)['groupes'];
        if (!$choisis) {
            return [['texte' => "Aucun groupe choisi pour vos publications. Choisissez-les dans votre espace vendeur BAYAM, rubrique WhatsApp."]];
        }
        return [['texte' => "*Vos groupes de publication*\n• " . implode("\n• ", $choisis) . "\n\nStatistiques des 30 derniers jours dans votre espace vendeur, rubrique WhatsApp."]];
    }

    private function lien_boutique(): array
    {
        $lien = Bayam_WA_Liens::boutique($this->vendeur);
        return [['texte' => "🔁 Lien unique de votre boutique, pour vos biographies TikTok, Instagram et Facebook :\n{$lien}\n\nLe QR code imprimable est dans votre espace vendeur, rubrique WhatsApp."]];
    }

    private function pin(string $reste): array
    {
        if ($this->equipe) {
            return [['texte' => 'Seul le propriétaire de la boutique peut changer le code PIN.']];
        }
        if (!preg_match('/^\d{4,6}$/', $reste)) {
            return [['texte' => 'Choisissez un code de 4 à 6 chiffres : *bayam pin 4826*. Il protège les ventes, liens, promos et dettes demandés par votre équipe.']];
        }
        Bayam_WA_Stockage::enregistrer($this->vendeur, ['pin' => $reste]);
        return [['texte' => '🔒 Code PIN de l\'équipe enregistré. Ne le communiquez qu\'aux personnes de confiance.']];
    }

    private function equipe_commande(string $reste): array
    {
        if ($this->equipe) {
            return [['texte' => "Seul le propriétaire de la boutique gère l'équipe."]];
        }
        $r = Bayam_WA_Stockage::reglages($this->vendeur);
        if (preg_match('/^(ajouter|ajoute|retirer|retire|enlever)\s+(.+)$/', $reste, $m) && ($numero = Bayam_WA_Outils::numero($m[2]))) {
            $equipe = array_values(array_diff($r['equipe'], [$numero]));
            if (str_starts_with($m[1], 'ajout')) {
                $equipe[] = $numero;
            }
            Bayam_WA_Stockage::enregistrer($this->vendeur, ['equipe' => $equipe]);
            return [['texte' => (str_starts_with($m[1], 'ajout') ? '👥 Ajouté à' : '👥 Retiré de') . " l'équipe : " . Bayam_WA_Outils::numero_lisible($numero) . '.' . ($r['pin'] === '' ? "\nPensez à choisir un code PIN (*bayam pin 1234*) pour les actions sensibles." : '')]];
        }
        $liste = $r['equipe'] ? implode("\n• ", array_map([Bayam_WA_Outils::class, 'numero_lisible'], $r['equipe'])) : 'personne pour le moment';
        return [['texte' => "*Équipe autorisée à piloter la boutique*\n• {$liste}\n\nAjouter : *bayam equipe ajouter 6 90 00 00 10* · Retirer : *bayam equipe retirer 6 90 00 00 10*"]];
    }

    /** Photo + légende « robe rouge 15000 M:3 L:2 » (ou « sac cuir 25000 4 » pour 4 pièces sans taille). */
    private function ajout_produit(string $legende, array $photo): array
    {
        // Le nom garde les accents tapés par le vendeur ; le prix est le premier nombre plausible (100 F au moins),
        // pour que « chaussures pointure 42 25000 42:2 » ou « casquette 6500 4 » (4 en stock) se lisent bien.
        $legende = trim(preg_replace('/\s+/u', ' ', $legende));
        preg_match_all('/(?<=\s)(\d{1,3}(?:[ .]\d{3})+|\d+)\s*(k|mille)?\s*(?:fcfa|xaf|frs?|f)?(?=\s|$)/iu', $legende, $nombres, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $prix = null;
        foreach ($nombres as $n) {
            $montant = Bayam_WA_Outils::montant($n[1][0] . ($n[2][0] ?? ''));
            if ($montant >= 100) {
                $prix = $montant;
                $nom = trim(substr($legende, 0, $n[0][1]));
                $reste = Bayam_WA_Outils::normaliser(substr($legende, $n[0][1] + strlen($n[0][0])));
                break;
            }
        }
        if (!$prix || $nom === '') {
            return [['texte' => "Pour créer un produit, envoyez sa photo avec une légende comme : *robe rouge 15000 M:3 L:2* (nom, prix, tailles et quantités)."]];
        }
        $tailles = [];
        $stock = 1;
        foreach (preg_split('/\s+/', $reste) as $morceau) {
            if (preg_match('/^([a-z0-9]{1,5}):(\d+)$/', $morceau, $x)) {
                $tailles[strtoupper($x[1])] = (int) $x[2];
            } elseif (ctype_digit($morceau)) {
                $stock = (int) $morceau;
            }
        }
        $image = Bayam_WA_Boutique::importer_image($this->vendeur, $photo['binaire'], $nom);
        $p = Bayam_WA_Boutique::creer_produit($this->vendeur, $nom, $prix, $tailles, $stock, $image);
        $lien = Bayam_WA_Liens::produit($this->vendeur, $p);
        $detail = $tailles ? implode(', ', array_map(static fn ($k, $v) => "$k : $v", array_keys($tailles), $tailles)) : "$stock pièce(s)";
        if (preg_match('/\bpubli(?:er|e|ez)\b(.*)$/', $reste, $pub)) {
            $ou = preg_match('/\bpartout\b/', $pub[1]) ? 'partout' : (preg_match('/\b(statut|status)\b/', $pub[1]) ? 'statut' : 'groupes');
            return [['texte' => "✅ Produit créé : *{$p->get_name()}*, " . Bayam_WA_Outils::fcfa($prix) . " ({$detail}).\nLien : {$lien}"],
                ['texte' => Bayam_WA_Diffusion::programmer($this->vendeur, [$p], $ou)]];
        }
        return [['texte' => "✅ Produit créé : *{$p->get_name()}*, " . Bayam_WA_Outils::fcfa($prix) . " ({$detail}).\nLien à partager : {$lien}\n\n👉 *bayam cartes " . Bayam_WA_Outils::normaliser($nom) . "* pour sa carte à transférer."]];
    }
}
