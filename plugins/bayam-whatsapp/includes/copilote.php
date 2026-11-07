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
        'menu' => ['menu', 'bonjour', 'salut', '?'],
        'aide' => ['aide', 'help', 'actions', 'liste'],
        'politique' => ['politique', 'conditions'],
        'numero_paiement' => ['momo', 'om', 'orange'],
        'raccourcis' => ['raccourcis', 'raccourci', 'vocabulaire'],
        'oui' => ['oui'],
        'catalogue' => ['catalogue', 'catalog', 'produits', 'articles'],
        'cartes' => ['cartes', 'carte'],
        'statut' => ['statut', 'status'],
        'publier' => ['publier', 'publie', 'poster', 'diffuser'],
        'facebook' => ['facebook', 'fb', 'messenger'],
        'partager' => ['partager', 'partage', 'partagez'],
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

    /** $ia : faux quand la demande vient déjà du modèle de langage (pas de seconde interprétation). */
    private function repondre(string $message, ?array $photo, bool $ia = true): array
    {
        if ($photo) {
            return $this->ajout_produit($message, $photo);
        }
        $t = Bayam_WA_Outils::normaliser($message);
        if ($t === '') {
            return [['texte' => $this->menu()]]; // « bayam » seul
        }
        // Tournure que ce vendeur a déjà fait comprendre : reconnue tout de suite, sans modèle de langage.
        $appris = $ia ? ($this->raccourcis()[$t] ?? null) : null;
        if ($appris !== null) {
            return $this->consultation($appris) ? $this->repondre($appris, null, false) : $this->proposer($t, $appris, '');
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
        if ($intention === null && !$naturel) {
            // « j'ai besoin d'aide », « donne-moi le catalogue », « je veux publier robes » : sans les mots de
            // remplissage, le premier mot utile est une action.
            $utiles = self::mots_utiles($t);
            if ($utiles && ($intention = self::intention($utiles[0])) !== null) {
                $reste = implode(' ', array_slice($utiles, 1));
            }
        }
        if ($intention === 'lien' && $premier === 'paiement' && !preg_match('/^\d/', $reste)) {
            $intention = 'politique'; // « bayam paiement à la livraison » : les conditions, pas un lien de paiement
        }
        if ($intention === null) {
            // Des noms d'articles seuls (« robes », « sweat zippé ») valent une recherche dans le catalogue ; tous les
            // mots doivent y être, pour qu'une phrase inconnue ne renvoie pas d'articles au hasard.
            $mots_article = implode(' ', self::mots_utiles($t)) ?: $t;
            $trouves = Bayam_WA_Boutique::chercher($this->vendeur, $mots_article, true, 8, true);
            if ($trouves) {
                return $this->catalogue($mots_article);
            }
            return $ia && Bayam_WA_IA::disponible() ? $this->interpreter($message)
                : [['texte' => "Je n'ai pas compris « {$message} ». Écrivez *bayam aide* pour voir tout ce que je sais faire."]];
        }
        if (in_array($intention, self::SENSIBLES, true) && ($refus = $this->controle_pin($intention, $t))) {
            return $refus;
        }
        $reste = $this->sans_pin($reste);
        // Les noms de clients gardent leurs accents (« Nadège ») : même découpage, sans retirer les accents.
        $avec_accents = $naturel ? $reste : $this->sans_pin(array_pad(explode(' ', mb_strtolower(preg_replace('/\s+/u', ' ', trim($message)), 'UTF-8'), 2), 2, '')[1]);
        return match ($intention) {
            'menu' => [['texte' => $this->menu()]],
            'aide' => [['texte' => Bayam_WA_Aide::reponse($reste)]],
            'oui' => $this->valider_proposition(),
            'politique' => $this->politique($reste),
            'numero_paiement' => $this->numero_paiement($premier, $avec_accents),
            'raccourcis' => $this->liste_raccourcis($reste),
            'catalogue' => $this->catalogue($reste),
            'cartes' => $this->cartes($reste, false),
            'statut' => $this->cartes($reste, true),
            'publier' => $this->publier($reste),
            'facebook' => $this->facebook(),
            'partager' => $this->partager($reste),
            'envoie' => $this->envoyer_collection($reste),
            'stock' => $this->stock($reste),
            'vendu' => $this->vendu($reste, $avec_accents),
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
        if (preg_match("/^(?:comment|a quoi (?:ca )?sert|explique(?:[- ]moi)?|que fait|kesako)\b/u", $t) && Bayam_WA_Aide::action_de($t) !== null) {
            return ['aide', $t];
        }
        $question = (bool) preg_match("/^(?:c'?est quoi|c est quoi|qu'?est[- ]ce (?:que|qu)|quel(?:le)?s? (?:est|sont))\b/u", $t);
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
            $vides = ['combien', 'de', 'des', 'du', 'd', 'il', 'me', 'nous', 'reste', 'restent', 'en', 'stock', 't', 'y', 'a', 'est', 'ce', 'que', 'qu', 'encore', 'les', 'mes', 'la', 'le',
                'c', 'quoi', 'quel', 'quelle', 'aujourd', 'hui', 'aujourdhui', 'actuel', 'actuellement', 'maintenant', 'mon', 'ma', 'moi', 'svp', 'stp'];
            $mots = array_filter(preg_split("/[\\s']+/u", $t), static fn ($m) => $m !== '' && !in_array($m, $vides, true));
            return ['stock', implode(' ', $mots)];
        }
        // « j'ai vendu 2 robes à 30 mille par momo » : une vente (un montant est dit) ; sinon un bilan.
        $sans_dates = preg_replace('/\b\d{1,2}[\/-]\d{1,2}(?:[\/-]\d{2,4})?\b/', '', $t);
        if (!$question && preg_match('/\bvendu[es]?\s+(.+)$/u', $sans_dates, $m) && preg_match('/\d{3,}|\d+\s*(?:mille|k)\b/u', $m[1])) {
            return ['vendu', $m[1]];
        }
        if (preg_match('/\b(?:ventes?|vendu[es]?|bilan|chiffre|recette|encaisse)\b/u', $t)) {
            return ['bilan', $t];
        }
        if ($question && Bayam_WA_Aide::action_de($t) !== null) {
            return ['aide', $t];
        }
        return null;
    }

    public static function intention(string $mot): ?string
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

    // --- Demandes écrites librement -------------------------------------------------------------------------------

    /** Actions qui ne font que montrer (rien n'est modifié, rien n'est envoyé aux clients) : exécutées sans confirmation. */
    private const CONSULTATIONS = ['menu', 'aide', 'catalogue', 'cartes', 'statut', 'stock', 'commandes', 'client', 'groupes', 'boutique', 'support'];

    private const SCHEMA_IA = [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'comprise' => ['type' => 'boolean'],
            'commande' => ['type' => 'string', 'description' => 'Commande BAYAM complète, commençant par « bayam », ou vide.'],
            'explication' => ['type' => 'string', 'description' => 'Une phrase pour le vendeur : ce que fera la commande, ou ce qui manque.'],
        ],
        'required' => ['comprise', 'commande', 'explication'],
    ];

    /**
     * Demande que le Copilote ne reconnaît pas (« j'ai écoulé 2 robes wax à 30 mille par momo ») : le modèle de langage
     * la traduit en commande BAYAM. Une consultation part tout de suite ; ce qui modifie la boutique ou écrit aux
     * clients attend « bayam oui », pour qu'une erreur de compréhension ne coûte jamais rien au vendeur.
     */
    private function interpreter(string $message): array
    {
        $noms = array_map(static fn (WC_Product $p) => $p->get_name(), array_slice(Bayam_WA_Boutique::produits($this->vendeur), 0, 80));
        $appris = array_slice($this->raccourcis(), -15, null, true);
        $contexte = 'Articles de la boutique : ' . implode(', ', $noms)
            . ($appris ? "\nTournures déjà validées par ce vendeur (il parle ainsi) :\n" . implode("\n", array_map(static fn ($phrase, $demande) => "« {$phrase} » donne bayam {$demande}", array_keys($appris), $appris)) : '');
        $r = Bayam_WA_IA::json(self::consignes_ia(), $contexte, [['role' => 'user', 'content' => $message]], self::SCHEMA_IA);
        $commande = trim(preg_replace('/\s+/u', ' ', (string) ($r['commande'] ?? '')));
        if (!$r || empty($r['comprise']) || !preg_match('/^bayam\s+(.+)$/iu', $commande, $m)) {
            $raison = trim((string) ($r['explication'] ?? ''));
            return [['texte' => ($raison !== '' ? "🤖 {$raison}\n\n" : "Je n'ai pas compris « {$message} ». ") . "Écrivez *bayam aide* pour voir tout ce que je sais faire."]];
        }
        $demande = trim($m[1]);
        if ($this->consultation($demande)) {
            $this->apprendre($message, $demande);
            $reponses = $this->repondre($demande, null, false);
            if (isset($reponses[0]['texte'])) {
                $reponses[0]['texte'] = "🤖 J'ai compris : *{$commande}*\n\n" . $reponses[0]['texte'];
            }
            return $reponses;
        }
        return $this->proposer($message, $demande, trim((string) ($r['explication'] ?? '')));
    }

    /** Proposition qui attend « bayam oui » ; la tournure du vendeur est gardée pour être apprise s'il valide. */
    private function proposer(string $phrase, string $demande, string $explication): array
    {
        set_transient($this->cle_proposition(), ['demande' => $demande, 'phrase' => $phrase], 10 * MINUTE_IN_SECONDS);
        return [['texte' => "🤖 Je propose : *bayam {$demande}*" . ($explication !== '' ? "\n{$explication}" : '')
            . "\n\nRépondez *bayam oui* pour valider, ou écrivez la commande corrigée."]];
    }

    private function consultation(string $demande): bool
    {
        $t = Bayam_WA_Outils::normaliser($demande);
        [$premier, $reste] = array_pad(explode(' ', $t, 2), 2, '');
        $intention = self::intention($premier);
        if ($intention === 'bilan') {
            return !preg_match('/^(\d|on\b|off\b|arret|stop|marche)/', $reste); // « rapport 21h » change un réglage
        }
        if ($intention === 'dettes') {
            return $reste === '';
        }
        return in_array($intention, self::CONSULTATIONS, true) && !preg_match('/^[+-]/', $t);
    }

    private function valider_proposition(): array
    {
        $proposition = get_transient($this->cle_proposition());
        if (!is_array($proposition) || empty($proposition['demande'])) {
            return [['texte' => 'Aucune proposition en attente. Écrivez votre demande, ou *bayam aide*.']];
        }
        delete_transient($this->cle_proposition());
        $this->apprendre((string) $proposition['phrase'], (string) $proposition['demande']);
        return $this->repondre((string) $proposition['demande'], null, false);
    }

    // --- Tournures apprises de chaque vendeur -----------------------------------------------------------------------

    private const RACCOURCIS_MAX = 60;

    /** @return array<string, string> tournure normalisée => commande (sans « bayam »), la plus récente en dernier */
    private function raccourcis(): array
    {
        $r = get_user_meta($this->vendeur, 'bayam_wa_raccourcis', true);
        return is_array($r) ? $r : [];
    }

    /** Garde une tournure validée par le vendeur : la prochaine fois, elle est comprise directement. */
    private function apprendre(string $phrase, string $demande): void
    {
        $phrase = Bayam_WA_Outils::normaliser($phrase);
        if ($phrase === '' || $phrase === Bayam_WA_Outils::normaliser($demande)) {
            return;
        }
        $r = $this->raccourcis();
        unset($r[$phrase]);
        $r[$phrase] = $demande;
        update_user_meta($this->vendeur, 'bayam_wa_raccourcis', array_slice($r, -self::RACCOURCIS_MAX, null, true));
    }

    /** « bayam raccourcis » : ce que le Copilote a appris ; « bayam raccourcis effacer » pour tout oublier. */
    private function liste_raccourcis(string $reste): array
    {
        if (preg_match('/^(?:effacer|efface|oublie|oublier|supprimer|vider)$/', $reste)) {
            delete_user_meta($this->vendeur, 'bayam_wa_raccourcis');
            return [['texte' => '🧹 Tournures apprises effacées. Je réapprendrai au fil de vos demandes.']];
        }
        $r = array_slice($this->raccourcis(), -20, null, true);
        if (!$r) {
            return [['texte' => "🧠 Je n'ai encore rien appris de vous. Quand vous écrivez à votre façon et que vous validez ma proposition, je retiens la tournure : la fois suivante, je comprends tout de suite."]];
        }
        $lignes = array_map(static fn ($phrase, $demande) => "• « {$phrase} » : *bayam {$demande}*", array_keys($r), $r);
        return [['texte' => "🧠 *Ce que j'ai appris de vous*\n" . implode("\n", $lignes) . "\n\n*bayam raccourcis effacer* pour tout oublier."]];
    }

    // --- Conditions de paiement ----------------------------------------------------------------------------------

    /** « bayam paiement à la livraison », « … acompte 30% », « … avant envoi », « bayam paiement » (voir). */
    private function politique(string $reste): array
    {
        if ($this->equipe) {
            return [['texte' => 'Seul le propriétaire de la boutique choisit les conditions de paiement.']];
        }
        $changement = null;
        $pourcentage = preg_match('/(\d{1,2})\s*%/', $reste, $m) ? (int) $m[1] : null;
        if (preg_match('/acompte|moitie|une partie|partiel/', $reste) || ($pourcentage !== null && str_contains($reste, 'avance'))) {
            $pourcentage ??= 50;
            if ($pourcentage < 10 || $pourcentage > 90) {
                return [['texte' => 'Choisissez un acompte entre 10 et 90 %, par exemple *bayam paiement acompte 50%*.']];
            }
            $changement = ['paiement' => 'acompte', 'acompte' => $pourcentage];
        } elseif (preg_match('/avant|avance|tout|d.abord|totalite|complet/', $reste)) {
            $changement = ['paiement' => 'avance'];
        } elseif (preg_match('/livraison|reception/', $reste)) {
            $changement = ['paiement' => 'livraison'];
        } elseif ($reste !== '') {
            return [['texte' => "Je n'ai pas compris ces conditions. Écrivez *bayam paiement à la livraison*, *bayam paiement acompte 50%* ou *bayam paiement avant envoi*."]];
        }
        if ($changement) {
            Bayam_WA_Stockage::enregistrer($this->vendeur, $changement);
        }
        $sans_numero = Bayam_WA_Stockage::reglages($this->vendeur)['paiement'] !== 'livraison' && Bayam_WA_Boutique::numeros_paiement($this->vendeur) === '';
        return [['texte' => ($changement ? '✅ ' : '') . '💳 *Vos conditions de paiement* : ' . Bayam_WA_Boutique::conditions_paiement($this->vendeur)
            . "\nVotre assistant, vos confirmations de commande et votre catalogue l'annoncent aux clients."
            . ($sans_numero ? "\n\n⚠️ Donnez le numéro qui reçoit l'argent : *bayam momo 6 54 77 00 63 Aïcha Mbarga* (ou *bayam om …*)." : '')
            . "\n\nPour changer : *bayam paiement à la livraison* · *bayam paiement acompte 50%* · *bayam paiement avant envoi*"]];
    }

    /** « bayam momo 6 54 77 00 63 Aïcha Mbarga », « bayam om 6 90 … », « bayam momo retirer ». Propriétaire seulement. */
    private function numero_paiement(string $operateur, string $reste): array
    {
        if ($this->equipe) {
            return [['texte' => "Seul le propriétaire de la boutique donne les numéros qui reçoivent l'argent."]];
        }
        $cle = $operateur === 'momo' ? 'momo' : 'om';
        $libelle = $cle === 'momo' ? 'MTN MoMo' : 'Orange Money';
        $numeros = (array) Bayam_WA_Stockage::reglages($this->vendeur)['numeros_paiement'];
        $reste = trim($reste);
        if (preg_match('/^(?:retirer|retire|supprimer|enlever)$/u', $reste)) {
            unset($numeros[$cle]);
            Bayam_WA_Stockage::enregistrer($this->vendeur, ['numeros_paiement' => $numeros]);
            return [['texte' => "Numéro {$libelle} retiré."]];
        }
        if (!preg_match('/^((?:\+?237)?[\s.]*6[\d\s.]{8,12})\s*(.*)$/u', $reste, $m) || !($numero = Bayam_WA_Outils::numero($m[1]))) {
            $actuels = Bayam_WA_Boutique::numeros_paiement($this->vendeur);
            return [['texte' => ($actuels !== '' ? "💳 Vos clients paient par {$actuels}.\n\n" : '')
                . "Pour donner le numéro qui reçoit l'argent : *bayam {$cle} 6 54 77 00 63 Aïcha Mbarga* (le nom affiché par {$libelle} rassure le client)."]];
        }
        $numeros[$cle] = $numero;
        $changement = ['numeros_paiement' => $numeros];
        if (trim($m[2]) !== '') {
            $changement['nom_paiement'] = mb_convert_case(trim($m[2]), MB_CASE_TITLE, 'UTF-8');
        }
        Bayam_WA_Stockage::enregistrer($this->vendeur, $changement);
        return [['texte' => "✅ {$libelle} enregistré : vos clients paient par " . Bayam_WA_Boutique::numeros_paiement($this->vendeur)
            . ".\nConditions actuelles : " . Bayam_WA_Boutique::conditions_paiement($this->vendeur) . ' (*bayam paiement* pour les changer).']];
    }

    /** Mots de remplissage ôtés d'une phrase dite naturellement (« les », « j'ai besoin d' », « donne-moi »). */
    private static function mots_utiles(string $t): array
    {
        $vides = ['les', 'le', 'la', 'l', 'mes', 'ma', 'mon', 'des', 'du', 'de', 'd', 'un', 'une', 'svp', 'stp', 'moi', 'me', 'donne', 'donner', 'montre',
            'montrer', 'affiche', 'afficher', 'voir', 'veux', 'voudrais', 'besoin', 'j', 'ai', 'je', 'c', 'est', 'quoi', 'quel', 'quelle', 'aujourd', 'hui',
            'aujourdhui', 'pour', 'please', 'pls', 'stp', 'merci', 'bonjour', 'bonsoir', 'a', 'peux', 'tu', 'pouvez', 'vous', 'faire'];
        return array_values(array_filter(preg_split("/[\s'-]+/u", $t), static fn ($m) => $m !== '' && !in_array($m, $vides, true)));
    }

    private function cle_proposition(): string
    {
        return 'bayam_wa_proposition_' . $this->vendeur . ($this->equipe ? '_equipe' : '');
    }

    private static function consignes_ia(): string
    {
        return "Tu aides des commerçants camerounais qui gèrent leur boutique sur WhatsApp avec BAYAM. On te donne le message "
            . "d'un vendeur, écrit comme il parle (français, anglais, pidgin, fautes possibles). Traduis-le en UNE commande BAYAM, "
            . "écrite exactement comme dans les exemples ci-dessous et commençant par « bayam ».\n"
            . "Règles : montants en chiffres, sans espace ni devise (30 mille, 30k, 30 000 F donnent 30000) ; quantité avec x (x2) ; "
            . "moyens de paiement : cash, momo, om, credit ; reprends le nom exact d'un article de la boutique quand il correspond ; "
            . "une question sur le fonctionnement (« comment… », « à quoi sert… ») donne « bayam aide » suivi de l'action. "
            . "Si le message n'est pas une demande pour BAYAM, ou s'il manque une information indispensable (montant, numéro, "
            . "article), mets comprise à false et dis en une phrase simple ce qui manque. N'invente jamais un montant, un numéro "
            . "ni un nom.\n\nActions disponibles :\n" . Bayam_WA_Aide::pour_ia();
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
            . "Majuscules, accents et petites fautes sont acceptés (*Bayam*, *BAYAM*, *bayan*). "
            . "Écrivez aussi comme vous parlez : je comprends.\n"
            . "📚 *bayam aide* : toutes les actions · *bayam aide publier* : le détail d'une action\n\n"
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
            $photo = $statut ? null : Bayam_WA_Cartes::photo_whatsapp($p);
            $reponses[] = [
                'image' => $photo ?? Bayam_WA_Cartes::carte($p, $lien, $boutique, $statut),
                'legende' => $photo ? Bayam_WA_Cartes::legende($p, $lien) : '👉 Commander : ' . $lien,
            ];
        }
        $reponses[] = ['texte' => $statut
            ? "Voici vos visuels de statut. Appuyez longuement sur une image, puis « Transférer » et choisissez *Mon statut*. Ou écrivez *bayam publier {$mots} statut* et je les publie pour vous."
            : 'Appuyez longuement sur une photo, puis « Transférer » vers vos groupes et clients : la légende part avec. Chaque lien compte les clics.'];
        return $reponses;
    }

    /**
     * « bayam publier robes », « … statut », « … chaîne », « … facebook », « … story », « … partout », « … dans Clientes
     * Akwa », « bayam publier nouveautés », « bayam publier promos » : la sélection part en quelques secondes, au rythme
     * d'un vendeur sur WhatsApp, et par le canal Facebook sur la Page.
     */
    private function publier(string $reste): array
    {
        [$ou, $facebook, $story] = self::cibles($reste);
        $groupes = null;
        if (preg_match('/\bdans (?:le groupe |mon groupe )?(.+)$/', $reste, $m) && !preg_match('/^(?:tous )?(?:mes |les )?groupes?$/', trim($m[1]))) {
            $nom = trim(preg_replace('/\b(?:et )?(?:statut|status|partout|chaines?|facebook|fb|story|stories)\b/', '', $m[1]));
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
            $ou = $ou === null ? 'groupes' : (str_contains($ou, 'groupes') || $ou === 'partout' ? $ou : "groupes+{$ou}");
        }
        [$produits, $mots] = $this->selection($reste);
        if (!$produits) {
            return [['texte' => "Rien en stock pour « {$mots} »."]];
        }
        return $this->lancer_publication($produits, $ou, $facebook, $story, $groupes);
    }

    /**
     * Articles demandés (5 au plus, en stock) : « nouveautés » (de la semaine, sinon les derniers), « promos », un
     * article choisi dans le menu (« #25 ») ou des mots du nom ; les mots de destination sont ignorés.
     *
     * @return array{0: WC_Product[], 1: string} articles et mots retenus
     */
    private function selection(string $reste): array
    {
        $mots = trim(preg_replace('/\b(statut|status|partout|groupes?|chaines?|whatsapp|facebook|fb|page|story|stories|storys|profil|dans|mes|ma|sur|mon|le|les|et|en|tous|tout)\b/', '', $reste));
        $mots = trim(preg_replace('/\s+/', ' ', $mots));
        if (preg_match('/\b(nouveautes?|nouveaux?|nouvelles?|derniers?|dernieres?|new)\b/', $mots)) {
            $en_stock = array_filter(Bayam_WA_Boutique::produits($this->vendeur), static fn (WC_Product $p) => $p->is_in_stock());
            $recents = array_filter($en_stock, static fn (WC_Product $p) => $p->get_date_created() && $p->get_date_created()->getTimestamp() > time() - 7 * DAY_IN_SECONDS);
            return [array_slice(array_values($recents ?: $en_stock), 0, 5), $mots]; // sans nouveauté de la semaine : les 5 derniers
        }
        if (preg_match('/\b(promos?|promotions?|soldes?)\b/', $mots)) {
            return [array_slice(array_values(array_filter(Bayam_WA_Boutique::produits($this->vendeur), static fn (WC_Product $p) => $p->is_in_stock() && $p->is_on_sale())), 0, 5), $mots];
        }
        return [Bayam_WA_Boutique::chercher($this->vendeur, $mots, true, 5), $mots];
    }

    /**
     * « bayam partager robes » : pour publier soi-même sur son profil Facebook ou dans ses groupes (aucune API ne le
     * permet), chaque article arrive prêt à coller : sa photo, puis un texte avec le prix et deux liens de commande.
     * Le lien WhatsApp ouvre la discussion du vendeur avec la référence de l'article (BAYAM répond avec la fiche) ;
     * le lien Messenger ouvre l'article dans la Page, si elle est reliée.
     */
    private function partager(string $reste): array
    {
        [$produits, $mots] = $this->selection($reste);
        if (!$produits) {
            return [['texte' => "Rien en stock pour « {$mots} »."]];
        }
        $numero = Bayam_WA_Outils::numero_de((string) get_user_meta($this->vendeur, 'bayam_wa_moi', true));
        $page = array_values(array_filter(Bayam_WA_Facebook::pages($this->vendeur) ?? [], static fn ($p) => !empty($p['actif'])))[0]['page'] ?? null;
        $reponses = [['texte' => "✍️ *Prêt à publier sur votre profil ou dans vos groupes Facebook* : pour chaque article, enregistrez la photo (appui long, puis Enregistrer), copiez le texte (appui long, puis Copier), puis collez-les dans une nouvelle publication. Le client qui touche un lien arrive directement sur l'article, et BAYAM lui répond."]];
        foreach ($produits as $p) {
            $photo = Bayam_WA_Cartes::photo_whatsapp($p);
            if ($photo) {
                $reponses[] = ['image' => $photo, 'legende' => ''];
            }
            $reponses[] = ['texte' => self::texte_a_coller($p, $numero, $page)];
        }
        return $reponses;
    }

    /** Texte d'une publication Facebook (sans gras WhatsApp, que Facebook n'affiche pas) : nom, prix, tailles, liens. */
    public static function texte_a_coller(WC_Product $p, ?string $numero, ?string $page): string
    {
        $prix = Bayam_WA_Outils::fcfa((float) $p->get_price());
        if ($p->is_on_sale() && (float) $p->get_regular_price() > (float) $p->get_price()) {
            $prix .= ' au lieu de ' . Bayam_WA_Outils::fcfa((float) $p->get_regular_price());
        }
        $resume = trim(wp_strip_all_tags((string) ($p->get_short_description() ?: $p->get_description())));
        $lignes = ['🛍️ ' . html_entity_decode($p->get_name(), ENT_QUOTES, 'UTF-8'), '💰 ' . ($p->is_type('variable') ? 'dès ' : '') . $prix];
        if (($options = Bayam_WA_Boutique::options_disponibles($p)) !== '') {
            $lignes[] = '📏 ' . $options;
        }
        if ($resume !== '') {
            $lignes[] = mb_strimwidth($resume, 0, 220, '…', 'UTF-8');
        }
        $lignes[] = '';
        if ($numero) {
            $message = 'Bonjour ! Je veux commander : ' . html_entity_decode($p->get_name(), ENT_QUOTES, 'UTF-8') . ' (réf. P' . $p->get_id() . ')';
            $lignes[] = '👉 Commander sur WhatsApp : https://wa.me/' . $numero . '?text=' . rawurlencode($message);
        }
        if ($page) {
            $lignes[] = '💬 Ou sur Messenger : https://m.me/' . $page . '?ref=produit-' . $p->get_id();
        }
        if (!$numero && !$page) {
            $lignes[] = '👉 Commander : ' . Bayam_WA_Liens::produit((int) get_post_field('post_author', $p->get_id()), $p, 'facebook');
        }
        return implode("\n", $lignes);
    }

    /**
     * Où publier, d'après la demande. WhatsApp : « groupes » (par défaut), « statut », « chaîne », plusieurs à la fois.
     * Facebook : « facebook », « fb » ou « page » ; « story » pour les stories de la Page. « partout » : tout, la Page
     * Facebook comprise si elle est reliée.
     *
     * @return array{0: ?string, 1: bool, 2: bool} cibles WhatsApp pour Bayam_WA_Diffusion::programmer (null : aucune), Facebook, story
     */
    private static function cibles(string $texte): array
    {
        if (preg_match('/\bpartout\b/', $texte)) {
            return ['partout', true, false];
        }
        $story = (bool) preg_match('/\b(story|stories|storys)\b/', $texte);
        $facebook = $story || (bool) preg_match('/\b(facebook|fb|page)\b/', $texte);
        $whatsapp = [];
        if (preg_match('/\bgroupes?\b/', $texte)) {
            $whatsapp[] = 'groupes';
        }
        if (preg_match('/\b(statut|status)\b/', $texte)) {
            $whatsapp[] = 'statut';
        }
        if (preg_match('/\bchaines?\b/', $texte)) {
            $whatsapp[] = 'chaine';
        }
        if (!$whatsapp && !$facebook) {
            $whatsapp[] = 'groupes';
        }
        return [$whatsapp ? implode('+', $whatsapp) : null, $facebook, $story];
    }

    /** @param WC_Product[] $produits */
    private function lancer_publication(array $produits, ?string $ou, bool $facebook, bool $story, ?array $groupes = null): array
    {
        $reponses = [];
        if ($ou !== null) {
            $reponses[] = ['texte' => Bayam_WA_Diffusion::programmer($this->vendeur, $produits, $ou, $groupes)];
        }
        if ($facebook) {
            $reponses[] = ['texte' => Bayam_WA_Facebook::publier($this->vendeur, $produits, $story)];
        }
        return $reponses;
    }

    /** « bayam facebook » : Pages reliées, et le lien qui relie une Page en une minute (propriétaire seulement). */
    private function facebook(): array
    {
        if ($this->equipe) {
            return [['texte' => 'Seul le propriétaire de la boutique peut relier une Page Facebook.']];
        }
        $pages = Bayam_WA_Facebook::pages($this->vendeur) ?? [];
        $noms = static fn (array $liste) => implode(', ', array_map(static fn ($p) => '« ' . $p['nom'] . ' »', $liste));
        $actives = array_filter($pages, static fn ($p) => !empty($p['actif']));
        $coupees = array_filter($pages, static fn ($p) => empty($p['actif']));
        $etat = ($actives ? '✅ Reliée(s) : ' . $noms($actives) . "
" : '') . ($coupees ? '⚠️ À reconnecter : ' . $noms($coupees) . "
" : '');
        $lien = Bayam_WA_Facebook::lien_connexion($this->vendeur);
        return [['texte' => "📘 *Facebook*
{$etat}" . ($actives ? 'Pour relier une autre Page' : 'Reliez la Page Facebook de votre boutique en une minute')
            . " : touchez ce lien (valable 30 min), connectez-vous à Facebook et cochez votre Page.
{$lien}

"
            . "Ensuite, tout seul : votre catalogue dans Messenger, une réponse privée à chaque « prix ? » sous vos publications, les commandes confirmées dans Messenger. Pour publier : *bayam publier robes facebook*, ou *… story*."]];
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
            '✅ ' . Bayam_WA_Outils::nom_boutique($this->vendeur) . " confirme votre commande n° {$m[1]} : {$articles}, total " . Bayam_WA_Outils::fcfa((float) $commande->get_total()) . ".\n\n"
            . Bayam_WA_Boutique::conditions_paiement($this->vendeur, (float) $commande->get_total()) . "\nNous vous contactons pour la livraison. Merci !", null, 'assistant');
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

    /** $brut : même demande avec ses accents, pour le nom du client d'une vente à crédit (« credit Nadège »). */
    private function vendu(string $reste, string $brut = ''): array
    {
        $modes = ['cash' => 'cash', 'especes' => 'cash', 'espece' => 'cash', 'momo' => 'momo', 'mtn' => 'momo', 'om' => 'om', 'orange' => 'om', 'credit' => 'credit'];
        $nombres = ['un' => 1, 'une' => 1, 'deux' => 2, 'trois' => 3, 'quatre' => 4, 'cinq' => 5, 'six' => 6, 'sept' => 7, 'huit' => 8, 'neuf' => 9, 'dix' => 10,
            'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5];
        $mode = 'cash';
        $quantite = 1;
        $montant = null;
        $acompte = null;
        $attend_acompte = false;
        $mots = [];
        $client = [];
        // « 30 mille », « 30k » : 30000 (même découpage pour le texte avec accents, d'où viennent les noms).
        $milliers = static fn (string $s): string => preg_replace('/\b(\d+)\s*(?:mille|milles|k)\b/u', '${1}000', $s);
        $reste = $milliers($reste);
        $originaux = explode(' ', $milliers($brut !== '' ? $brut : $reste));
        foreach (explode(' ', $reste) as $rang => $mot) {
            if (isset($modes[$mot])) {
                $mode = $modes[$mot];
            } elseif (in_array($mot, ['acompte', 'avance'], true)) {
                $attend_acompte = true; // « acompte 5000 » : somme reçue tout de suite, le reste plus tard
            } elseif (in_array($mot, ['a', 'au', 'par', 'en', 'pour', 'de', 'du', 'la', 'le', 'les', 'l', 'd', 'j', 'ai', 'vendu', 'vendus', 'vendue', 'vendues', 'f', 'fcfa', 'frs'], true)) {
                continue; // mots de liaison d'une phrase dite naturellement
            } elseif (($mode === 'credit' || $acompte !== null) && !preg_match('/\d/', $mot)) {
                $client[] = $originaux[$rang] ?? $mot; // après « credit » ou l'acompte : le nom du client
            } elseif ($attend_acompte && ($n = Bayam_WA_Outils::montant($mot)) !== null && $n >= 100) {
                $acompte = $n;
                $attend_acompte = false;
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
        $commande = Bayam_WA_Boutique::vendre($this->vendeur, $p, $v, $quantite, $montant, $mode, '', $acompte);
        $restant = $article->managing_stock() ? (int) wc_get_product($article->get_id())->get_stock_quantity() : null;
        $recu = Bayam_WA_Liens::creer($this->vendeur, $commande->get_view_order_url(), 'recu');
        $nom = mb_convert_case(trim(implode(' ', $client)), MB_CASE_TITLE, 'UTF-8');
        $total = (float) $commande->get_total();
        $du = $mode === 'credit' ? (int) round($total) : ($acompte !== null && $acompte < $total ? (int) round($total - $acompte) : 0);
        if ($du > 0 && $nom !== '') {
            Bayam_WA_Rapports::ajouter_dette($this->vendeur, $nom, $du, null, null, $commande->get_id());
        }
        $suivi = $nom !== '' ? " (dette de {$nom} notée : *bayam payé {$nom} " . $du . "* quand il paie)" : ' (ajoutez le nom du client à la fin pour le suivre)';
        $paiement = $mode === 'credit' ? "\nÀ crédit" . $suivi . '.'
            : ($du > 0 ? "\nAcompte reçu : " . Bayam_WA_Outils::fcfa((float) $acompte) . '. Reste à encaisser : *' . Bayam_WA_Outils::fcfa($du) . '*' . $suivi . '.' : '');
        return [['texte' => "✅ Vente n° {$commande->get_order_number()} enregistrée : {$quantite} × " . $article->get_name() . ' = *' . Bayam_WA_Outils::fcfa($total) . "*"
            . $paiement
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
        [$debut, $fin, $libelle] = Bayam_WA_Rapports::periode($reste);
        return [['texte' => Bayam_WA_Rapports::texte_bilan($this->vendeur, $debut, $libelle, $fin)]];
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
        if (!$p) {
            return [['texte' => "❌ Le produit *{$nom}* n'a pas pu être créé. Renvoyez la photo avec la même légende."]];
        }
        $lien = Bayam_WA_Liens::produit($this->vendeur, $p);
        $detail = $tailles ? implode(', ', array_map(static fn ($k, $v) => "$k : $v", array_keys($tailles), $tailles)) : "$stock pièce(s)";
        if (preg_match('/\bpubli(?:er|e|ez)\b(.*)$/', $reste, $pub)) {
            [$ou, $facebook, $story] = self::cibles($pub[1]);
            return [['texte' => "✅ Produit créé : *{$p->get_name()}*, " . Bayam_WA_Outils::fcfa($prix) . " ({$detail}).\nLien : {$lien}"],
                ...$this->lancer_publication([$p], $ou, $facebook, $story)];
        }
        return [['texte' => "✅ Produit créé : *{$p->get_name()}*, " . Bayam_WA_Outils::fcfa($prix) . " ({$detail}).\nLien à partager : {$lien}\n\n👉 *bayam cartes " . Bayam_WA_Outils::normaliser($nom) . "* pour sa carte à transférer."]];
    }
}
