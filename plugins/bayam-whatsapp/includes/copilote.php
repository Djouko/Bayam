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
    ];

    /** Actions qu'un membre de l'équipe ne peut faire qu'avec le code PIN (le vendeur, lui, tient son téléphone). */
    private const SENSIBLES = ['vendu', 'lien', 'promo', 'dettes', 'paye', 'moins'];

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
            return [['texte' => "Désolé, je n'ai pas pu faire cette action. Réessayez ou écrivez *menu*."]];
        }
    }

    private function repondre(string $message, ?array $photo): array
    {
        if ($photo) {
            return $this->ajout_produit($message, $photo);
        }
        $t = Bayam_WA_Outils::normaliser($message);
        if ($t === '') {
            return [];
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
        if ($intention === null) {
            // Un mot seul qui désigne des produits (« robes ») vaut une recherche dans le catalogue.
            $trouves = Bayam_WA_Boutique::chercher($this->vendeur, $t);
            return $trouves ? $this->catalogue($t) : [['texte' => "Je n'ai pas compris « {$message} ». Écrivez *menu* pour voir ce que je sais faire."]];
        }
        if (in_array($intention, self::SENSIBLES, true) && ($refus = $this->controle_pin($intention, $t))) {
            return $refus;
        }
        $reste = $this->sans_pin($reste);
        return match ($intention) {
            'menu' => [['texte' => $this->menu()]],
            'catalogue' => $this->catalogue($reste),
            'cartes' => $this->cartes($reste, false),
            'statut' => $this->cartes($reste, true),
            'publier' => $this->publier($reste),
            'envoie' => $this->envoyer_collection($reste),
            'stock' => $this->stock($reste),
            'vendu' => $this->vendu($reste),
            'lien' => $this->lien($reste),
            'commandes' => $this->commandes(),
            'bilan' => $this->bilan($reste),
            'client' => $this->client($reste),
            'dettes' => $this->dettes($reste),
            'paye' => $this->paye($reste),
            'promo' => $this->promo($reste),
            'groupes' => $this->groupes(),
            'boutique' => $this->lien_boutique(),
            'pin' => $this->pin($reste),
            'equipe' => $this->equipe_commande($reste),
            'support' => [['texte' => "L'équipe BAYAM vous répond du lundi au samedi, de 8 h à 19 h : écrivez à contact@bayam.cm en décrivant votre souci (et une capture d'écran si possible)."]],
        };
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
            return [['texte' => "Cette action est réservée au propriétaire de la boutique tant qu'il n'a pas choisi de code PIN pour l'équipe (commande *pin 1234* dans sa discussion « Message à moi-même »)."]];
        }
        if (!preg_match('/\bpin\s*(\d{4,6})$/', $texte, $m) || !hash_equals($pin, $m[1])) {
            return [['texte' => "Action sensible : ajoutez le code PIN de la boutique à la fin du message, par exemple « … pin 1234 »."]];
        }
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
            . "Écrivez une commande ici, je m'occupe du reste :\n\n"
            . "🛍️ *robes* ou *#robes* : vos robes en stock\n"
            . "🖼️ *cartes robes* : cartes à transférer\n"
            . "📣 *publier robes* : dans vos groupes, envoi étalé\n"
            . "📲 *statut robes* : visuels pour votre statut\n"
            . "📦 *stock robe rouge* : quantités par taille\n"
            . "➕ *+5 robe rouge M* / *-2 robe rouge M*\n"
            . "💵 *vendu robe rouge M x2 15000 cash*\n"
            . "🔗 *lien 15000 Marie* : lien de paiement\n"
            . "🧾 *commandes* : à payer, à préparer\n"
            . "📊 *bilan*, *bilan semaine*, *bilan mois*\n"
            . "👤 *client 6 90 00 00 10*\n"
            . "📝 *dettes*, *dette Paul 5000*, *payé Paul 2000*\n"
            . "🏷️ *promo NOEL10 10%*\n"
            . "📷 Photo + « robe rouge 15000 M:3 L:2 » : nouveau produit\n"
            . "🔁 *boutique* : votre lien unique · *groupes* · *support*";
    }

    private function catalogue(string $mots): array
    {
        $produits = Bayam_WA_Boutique::chercher($this->vendeur, $mots);
        if (!$produits) {
            return [['texte' => $mots === '' ? "Aucun article en stock pour le moment." : "Rien en stock pour « {$mots} ». Essayez un autre mot, ou *catalogue* pour tout voir."]];
        }
        $lignes = [];
        foreach ($produits as $i => $p) {
            $lignes[] = ($i + 1) . '. *' . $p->get_name() . '* : ' . Bayam_WA_Outils::fcfa((float) $p->get_price()) . "\n   " . Bayam_WA_Liens::produit($this->vendeur, $p);
        }
        $titre = $mots === '' ? 'Votre catalogue' : "En stock pour « {$mots} »";
        return [['texte' => "*{$titre}* (" . count($produits) . ")\n\n" . implode("\n", $lignes)
            . "\n\n👉 *cartes {$mots}* pour les cartes à transférer, *publier {$mots}* pour vos groupes."]];
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
            ? "Voici vos visuels de statut. Appuyez longuement sur une image, puis « Transférer » et choisissez *Mon statut*. Ou écrivez *publier {$mots} statut* et je les publie pour vous."
            : 'Appuyez longuement sur une carte, puis « Transférer » vers vos groupes et clients. Chaque lien compte les clics.'];
        return $reponses;
    }

    private function publier(string $reste): array
    {
        $vers_statut = (bool) preg_match('/\bstatut\b/', $reste);
        $mots = trim(preg_replace('/\b(statut|groupes?|dans|mes|sur|mon|le|les)\b/', '', $reste));
        $produits = Bayam_WA_Boutique::chercher($this->vendeur, $mots, true, 5);
        if (!$produits) {
            return [['texte' => "Rien en stock pour « {$mots} »."]];
        }
        $r = Bayam_WA_Diffusion::programmer($this->vendeur, $produits, $vers_statut);
        return [['texte' => $r]];
    }

    private function envoyer_collection(string $reste): array
    {
        if (!preg_match('/^(.*?)\s*((?:\+?237)?\s*6[\d ]{8,12})$/', $reste, $m) || !($numero = Bayam_WA_Outils::numero($m[2]))) {
            return [['texte' => 'Écrivez par exemple : *envoie robes 6 90 00 00 10*.']];
        }
        $produits = Bayam_WA_Boutique::chercher($this->vendeur, $m[1], true, 6);
        if (!$produits) {
            return [['texte' => "Rien en stock pour « {$m[1]} »."]];
        }
        return [['texte' => Bayam_WA_Clients::envoyer_collection($this->vendeur, $numero, $produits)]];
    }

    private function stock(string $reste): array
    {
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

    private function vendu(string $reste): array
    {
        $modes = ['cash' => 'cash', 'especes' => 'cash', 'espece' => 'cash', 'momo' => 'momo', 'mtn' => 'momo', 'om' => 'om', 'orange' => 'om', 'credit' => 'credit'];
        $mode = 'cash';
        $quantite = 1;
        $montant = null;
        $mots = [];
        foreach (explode(' ', $reste) as $mot) {
            if (isset($modes[$mot])) {
                $mode = $modes[$mot];
            } elseif (preg_match('/^x(\d+)$/', $mot, $m)) {
                $quantite = max(1, (int) $m[1]);
            } elseif (($n = Bayam_WA_Outils::montant($mot)) !== null && $n >= 100) {
                $montant = $n;
            } else {
                $mots[] = $mot;
            }
        }
        [$p, $v] = Bayam_WA_Boutique::designer($this->vendeur, implode(' ', $mots));
        if (!$p) {
            return [['texte' => 'Je ne trouve pas cet article. Exemple : *vendu robe rouge M x2 15000 cash*.']];
        }
        if ($p->is_type('variable') && !$v) {
            return [['texte' => 'Précisez la variante vendue, par exemple : *vendu ' . $p->get_name() . " M*.\n" . implode(' · ', Bayam_WA_Boutique::stock_lignes($p))]];
        }
        $article = $v ?: $p;
        if ($article->managing_stock() && (int) $article->get_stock_quantity() < $quantite) {
            return [['texte' => "Stock insuffisant : il reste {$article->get_stock_quantity()} pièce(s)."]];
        }
        $commande = Bayam_WA_Boutique::vendre($this->vendeur, $p, $v, $quantite, $montant, $mode);
        $restant = $article->managing_stock() ? (int) wc_get_product($article->get_id())->get_stock_quantity() : null;
        $recu = Bayam_WA_Liens::creer($this->vendeur, $commande->get_view_order_url(), 'recu');
        return [['texte' => "✅ Vente n° {$commande->get_order_number()} enregistrée : {$quantite} × " . $article->get_name() . ' = *' . Bayam_WA_Outils::fcfa((float) $commande->get_total()) . "*"
            . ($mode === 'credit' ? " (à crédit : pensez à *dette nom montant*)" : '')
            . ($restant !== null ? "\nStock restant : {$restant}." : '')
            . "\nReçu à envoyer au client : {$recu}"]];
    }

    private function lien(string $reste): array
    {
        if (preg_match('/^boutique\b/', $reste)) {
            return $this->lien_boutique();
        }
        if (!preg_match('/^(\d[\d .]*\s*k?)\s*(.*)$/', $reste, $m) || !($montant = Bayam_WA_Outils::montant($m[1]))) {
            return [['texte' => 'Exemple : *lien 15000 Marie* (montant, puis le nom du client).']];
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
            return [['texte' => $actif ? '⏰ Résumés automatiques réactivés.' : '🔕 Résumés automatiques arrêtés. *rapport on* pour les reprendre.']];
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
            return [['texte' => 'Exemple : *client 6 90 00 00 10*.']];
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
            return [['texte' => 'Exemple : *dette Paul 5000* ou *dette Paul 5000 15/11* (avec échéance).']];
        }
        $echeance = isset($m[3]) ? sprintf('%04d-%02d-%02d', (int) wp_date('Y'), (int) $m[4], (int) $m[3]) : null;
        $nom = mb_convert_case(trim($m[1]), MB_CASE_TITLE, 'UTF-8');
        Bayam_WA_Rapports::ajouter_dette($this->vendeur, $nom, $montant, $echeance);
        return [['texte' => "📝 Dette notée : *{$nom}* doit " . Bayam_WA_Outils::fcfa($montant) . ($echeance ? ' pour le ' . wp_date('d/m', strtotime($echeance)) : '') . ".\nQuand il paie : *payé {$nom} montant*."]];
    }

    private function paye(string $reste): array
    {
        if (!preg_match('/^(.+?)\s+(\d[\d .]*k?)$/', $reste, $m) || !($montant = Bayam_WA_Outils::montant($m[2]))) {
            return [['texte' => 'Exemple : *payé Paul 2000*.']];
        }
        return [['texte' => Bayam_WA_Rapports::encaisser_dette($this->vendeur, mb_convert_case(trim($m[1]), MB_CASE_TITLE, 'UTF-8'), $montant)]];
    }

    private function promo(string $reste): array
    {
        if (!preg_match('/^([a-z0-9]{3,20})\s+(\d+(?:[.,]\d+)?)\s*(%|f|fcfa)?$/', $reste, $m)) {
            return [['texte' => 'Exemple : *promo NOEL10 10%* (pourcentage) ou *promo AMIE 2000* (montant).']];
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
            return [['texte' => 'Choisissez un code de 4 à 6 chiffres : *pin 4826*. Il protège les ventes, liens, promos et dettes demandés par votre équipe.']];
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
            return [['texte' => (str_starts_with($m[1], 'ajout') ? '👥 Ajouté à' : '👥 Retiré de') . " l'équipe : " . Bayam_WA_Outils::numero_lisible($numero) . '.' . ($r['pin'] === '' ? "\nPensez à choisir un code PIN (*pin 1234*) pour les actions sensibles." : '')]];
        }
        $liste = $r['equipe'] ? implode("\n• ", array_map([Bayam_WA_Outils::class, 'numero_lisible'], $r['equipe'])) : 'personne pour le moment';
        return [['texte' => "*Équipe autorisée à piloter la boutique*\n• {$liste}\n\nAjouter : *equipe ajouter 6 90 00 00 10* · Retirer : *equipe retirer 6 90 00 00 10*"]];
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
        return [['texte' => "✅ Produit créé : *{$p->get_name()}*, " . Bayam_WA_Outils::fcfa($prix) . " ({$detail}).\nLien à partager : {$lien}\n\n👉 *cartes " . Bayam_WA_Outils::normaliser($nom) . "* pour sa carte à transférer."]];
    }
}
