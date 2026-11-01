<?php
defined('ABSPATH') || exit;

/**
 * Assistant du vendeur : quand le vendeur est occupé (« bayam je suis occupé »), il répond à sa place aux clients
 * qui lui écrivent en privé, comme la boutique le ferait : prix, disponibilité, tailles, livraison, négociation
 * jusqu'au prix minimum fixé par le vendeur, prise de commande. L'acheteur n'a rien à apprendre : il écrit
 * naturellement (français, anglais, pidgin, camfranglais). Les messages personnels (famille, amis) restent sans
 * réponse, et dès que le vendeur écrit lui-même dans une discussion, l'assistant s'y efface.
 * Modèle : Claude (API Anthropic), clé ANTHROPIC_API_KEY dans .env ; modèle réglable par BAYAM_ASSISTANT_MODELE.
 */
final class Bayam_WA_Assistant
{
    /** Le vendeur a écrit lui-même dans la discussion : l'assistant s'y tait pendant ce temps (secondes). */
    private const MAIN_AU_VENDEUR = 1800;

    /** Messages de la discussion relus pour garder le fil (les plus récents). */
    private const HISTORIQUE = 14;

    /** Remise maximale par défaut (en %) que l'assistant peut accorder sans prix minimum fixé par produit. */
    public const REMISE_MAX = 10;

    /** Décision du modèle remplaçable pendant les essais (aucun appel réel). */
    private static $decideur = null;

    public static function remplacer(?callable $decideur): void
    {
        self::$decideur = $decideur;
    }

    /** L'assistant peut-il travailler ? (clé de l'API et SDK présents, ou modèle simulé pendant les essais) */
    public static function disponible(): bool
    {
        return self::$decideur !== null || ((string) getenv('ANTHROPIC_API_KEY') !== '' && class_exists('Anthropic\\Client'));
    }

    // --- État ------------------------------------------------------------------------------------------------

    public static function actif(int $vendeur): bool
    {
        $r = Bayam_WA_Stockage::reglages($vendeur);
        return !empty($r['assistant']) && (empty($r['assistant_jusqua']) || (int) $r['assistant_jusqua'] > time());
    }

    /** Active l'assistant, pour une durée (heures) ou jusqu'à nouvel ordre. */
    public static function activer(int $vendeur, ?float $heures = null): void
    {
        Bayam_WA_Stockage::enregistrer($vendeur, ['assistant' => true, 'assistant_jusqua' => $heures ? time() + (int) ($heures * HOUR_IN_SECONDS) : null]);
    }

    public static function desactiver(int $vendeur): void
    {
        Bayam_WA_Stockage::enregistrer($vendeur, ['assistant' => false, 'assistant_jusqua' => null]);
    }

    /** Le vendeur vient d'écrire lui-même à ce client : il garde la main sur cette discussion. */
    public static function vendeur_intervient(int $vendeur, string $chat): void
    {
        set_transient(self::cle_main($vendeur, $chat), time(), self::MAIN_AU_VENDEUR);
    }

    public static function vendeur_a_la_main(int $vendeur, string $chat): bool
    {
        return (bool) get_transient(self::cle_main($vendeur, $chat));
    }

    private static function cle_main(int $vendeur, string $chat): string
    {
        return 'bayam_wa_main_' . md5($vendeur . '|' . Bayam_WA_Outils::numero_de($chat));
    }

    /** Prix minimum d'un article : celui fixé par le vendeur, sinon le prix affiché moins la remise maximale. */
    public static function plancher(int $vendeur, WC_Product $p): int
    {
        $fixe = (int) $p->get_meta('_bayam_prix_plancher');
        if ($fixe > 0) {
            return $fixe;
        }
        $remise = (int) (Bayam_WA_Stockage::reglages($vendeur)['remise_max'] ?? self::REMISE_MAX);
        return (int) (ceil((float) $p->get_price() * (100 - $remise) / 100 / 100) * 100); // arrondi à la centaine
    }

    // --- Conversation ----------------------------------------------------------------------------------------

    /**
     * Message d'un client pendant que l'assistant est actif. Rend true si l'assistant s'en est chargé (réponse,
     * commande ou transmission au vendeur), false s'il n'a pas pu (l'appelant revient alors aux réponses simples).
     *
     * @param WC_Product[] $cites articles de la carte, du statut ou de la liste BAYAM citée par le client
     */
    public static function message(int $vendeur, string $chat, string $numero, string $texte, ?string $message_id, array $cites = []): bool
    {
        $decision = self::decider($vendeur, $chat, $texte, $cites);
        if ($decision === null) {
            return false;
        }
        $action = (string) ($decision['action'] ?? 'ignorer');
        $reponse = trim((string) ($decision['message'] ?? ''));
        if ($action === 'ignorer') {
            self::oublier_message($vendeur, $message_id); // message personnel : rien n'est gardé
            return true;
        }
        if ($action === 'commande' && !self::conclure($vendeur, $chat, $numero, (array) ($decision['commande'] ?? []), (string) ($decision['resume_vendeur'] ?? ''))) {
            $action = 'transmettre'; // commande incomplète ou sous le prix minimum : le vendeur tranche
        }
        if ($action === 'transmettre') {
            Bayam_WA_Regles::au_vendeur($vendeur, "📩 *Votre assistant vous transmet une demande* de " . Bayam_WA_Outils::numero_lisible($numero) . " :\n« " . mb_substr($texte, 0, 300) . " »\n"
                . ((string) ($decision['resume_vendeur'] ?? '') !== '' ? "\n" . $decision['resume_vendeur'] . "\n" : '')
                . "\nRépondre : https://wa.me/{$numero}");
        }
        if ($reponse !== '') {
            Bayam_WA_Regles::repondre($vendeur, $chat, $reponse, $message_id, 'assistant');
        }
        return true;
    }

    /** Message personnel : son texte, gardé un instant pour le fil de la conversation, est effacé. */
    private static function oublier_message(int $vendeur, ?string $message_id): void
    {
        global $wpdb;
        if ($message_id) {
            $wpdb->update(Bayam_WA_Stockage::table('wa_messages'), ['texte' => ''], ['vendeur' => $vendeur, 'message_id' => $message_id, 'sens' => 'in']);
        }
    }

    /** Décision du modèle : action, message au client, commande éventuelle. null si le modèle est injoignable. */
    private static function decider(int $vendeur, string $chat, string $texte, array $cites): ?array
    {
        $systeme = self::consignes();
        $boutique = self::contexte_boutique($vendeur, $texte, $cites);
        $messages = self::historique($vendeur, $chat, $texte);
        if (self::$decideur) {
            return (self::$decideur)($systeme, $boutique, $messages);
        }
        if (!self::disponible()) {
            return null;
        }
        try {
            $client = new Anthropic\Client();
            $reponse = $client->messages->create(
                model: getenv('BAYAM_ASSISTANT_MODELE') ?: 'claude-opus-5-5',
                maxTokens: 4000,
                system: [
                    // Consignes identiques pour toutes les boutiques : mises en cache, seul le contexte change.
                    ['type' => 'text', 'text' => $systeme, 'cacheControl' => ['type' => 'ephemeral']],
                    ['type' => 'text', 'text' => $boutique],
                ],
                messages: $messages,
                outputConfig: ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => self::schema()]],
            );
        } catch (Throwable $e) {
            Bayam_WA_Outils::journal('Assistant injoignable', ['erreur' => $e->getMessage()]);
            return null;
        }
        if ($reponse->stopReason === 'refusal') {
            return ['action' => 'transmettre', 'message' => '', 'resume_vendeur' => "Je n'ai pas pu traiter ce message : à vous de répondre."];
        }
        foreach ($reponse->content as $bloc) {
            if ($bloc->type === 'text') {
                $donnees = json_decode($bloc->text, true);
                return is_array($donnees) ? $donnees : null;
            }
        }
        return null;
    }

    /** Fil de la discussion : messages enregistrés, du plus ancien au plus récent, rôles alternés. */
    private static function historique(int $vendeur, string $chat, string $texte): array
    {
        global $wpdb;
        $lignes = array_reverse($wpdb->get_results($wpdb->prepare(
            'SELECT sens, texte FROM ' . Bayam_WA_Stockage::table('wa_messages') . " WHERE vendeur = %d AND (chat = %s OR numero = %s) AND texte <> '' ORDER BY id DESC LIMIT %d",
            $vendeur, $chat, Bayam_WA_Outils::numero_de($chat), self::HISTORIQUE
        )));
        $messages = [];
        foreach ($lignes as $l) {
            $role = $l->sens === 'out' ? 'assistant' : 'user';
            $contenu = str_replace(Bayam_WA_Outils::MARQUE, '', (string) $l->texte);
            if ($messages && end($messages)['role'] === $role) {
                $messages[count($messages) - 1]['content'] .= "\n" . $contenu; // messages successifs d'une même personne
            } else {
                $messages[] = ['role' => $role, 'content' => $contenu];
            }
        }
        while ($messages && $messages[0]['role'] !== 'user') {
            array_shift($messages);
        }
        // Le message à traiter termine toujours le fil (il peut ne pas encore être enregistré).
        if (!$messages || end($messages)['role'] !== 'user') {
            $messages[] = ['role' => 'user', 'content' => $texte];
        } elseif (!str_ends_with(end($messages)['content'], $texte)) {
            $messages[count($messages) - 1]['content'] .= "\n" . $texte;
        }
        return $messages;
    }

    /** Consignes de l'assistant, communes à toutes les boutiques. */
    private static function consignes(): string
    {
        return <<<'TXT'
Tu réponds sur WhatsApp à la place d'un commerçant camerounais occupé, comme le ferait un bon vendeur de sa boutique. Tu parles au nom de la boutique (« nous »), avec chaleur et naturel, en messages courts comme sur WhatsApp, dans la langue du client : français, anglais, pidgin ou camfranglais, avec ses expressions s'il en utilise. Pas de longues listes, pas de formules de robot.

Ce que tu sais : uniquement le catalogue, les prix, le stock, la livraison et les paiements donnés dans le contexte de la boutique. N'invente jamais un article, un prix, une taille, une couleur, un délai ou une promesse. Si on te demande quelque chose que tu ne sais pas, dis que tu vérifies avec la boutique et transmets.

Négociation : les clients négocient presque toujours, c'est normal. Commence par le prix affiché, puis tu peux baisser par petites étapes si le client insiste, sans jamais descendre sous le « prix minimum » d'un article. Ne révèle jamais le prix minimum ni l'existence d'une limite. Pour plusieurs pièces, tu peux faire un geste, toujours au-dessus du minimum par pièce.

Commande : quand le client est d'accord, assure-toi d'avoir l'article (et la taille ou la couleur s'il y en a), la quantité, le prix convenu, le lieu de livraison (quartier, repère) ou le retrait en boutique, et un nom. Demande ce qui manque, une chose à la fois. Quand tout y est, confirme au client que sa commande est notée et que la boutique le recontacte pour la livraison, et renvoie l'action « commande ».

Messages personnels : si le message ne concerne pas un achat (famille, amis, salutations entre proches, affaires privées, groupes de discussion), ne réponds pas : action « ignorer », message vide. Un simple « bonjour » d'un inconnu peut venir d'un client : réponds poliment en demandant ce qui l'intéresse.

Transmettre au vendeur : réclamation, retour, problème de paiement ou de livraison passée, demande sortant du catalogue, client fâché, ou tout ce que tu ne peux pas régler : réponds avec tact que tu transmets à la boutique, action « transmettre », et résume la demande pour le vendeur.

Honnêteté : si on te demande si tu es un robot ou une vraie personne, réponds simplement que tu es l'assistant de la boutique et que le vendeur peut prendre le relais. Ne donne jamais de lien que le contexte ne contient pas ; quand tu parles d'un article, tu peux donner son lien.

Réponds uniquement par l'objet JSON demandé.
TXT;
    }

    /** Schéma de la décision : action, message au client, commande conclue, résumé pour le vendeur. */
    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['repondre', 'commande', 'transmettre', 'ignorer']],
                'message' => ['type' => 'string', 'description' => 'Message à envoyer au client, vide pour « ignorer ».'],
                'commande' => [
                    'type' => 'object',
                    'description' => 'Rempli seulement pour « commande » ; sinon valeurs vides ou 0.',
                    'properties' => [
                        'produit_id' => ['type' => 'integer'],
                        'quantite' => ['type' => 'integer'],
                        'prix_unitaire' => ['type' => 'integer', 'description' => 'Prix convenu par pièce, en FCFA.'],
                        'precision' => ['type' => 'string', 'description' => 'Taille, couleur ou autre déclinaison choisie.'],
                        'livraison' => ['type' => 'string', 'description' => 'Quartier et repère, ou « retrait en boutique ».'],
                        'nom_client' => ['type' => 'string'],
                    ],
                    'required' => ['produit_id', 'quantite', 'prix_unitaire', 'precision', 'livraison', 'nom_client'],
                    'additionalProperties' => false,
                ],
                'resume_vendeur' => ['type' => 'string', 'description' => 'Résumé pour le vendeur (commande ou demande transmise), sinon vide.'],
            ],
            'required' => ['action', 'message', 'commande', 'resume_vendeur'],
            'additionalProperties' => false,
        ];
    }

    /** Contexte propre à la boutique : nom, livraison, paiements, articles utiles à cette conversation. */
    private static function contexte_boutique(int $vendeur, string $texte, array $cites): string
    {
        $produits = [];
        foreach ($cites as $p) {
            $produits[$p->get_id()] = $p;
        }
        $mots = Bayam_WA_Clients::mots_utiles($texte);
        foreach ($mots !== '' ? Bayam_WA_Boutique::chercher($vendeur, $mots, false, 8) : [] as $p) {
            $produits[$p->get_id()] = $p;
        }
        foreach (array_slice(Bayam_WA_Boutique::produits($vendeur), 0, 25) as $p) {
            $produits[$p->get_id()] ??= $p;
        }
        $lignes = [];
        foreach (array_slice($produits, 0, 30, true) as $p) {
            $lignes[] = sprintf('- id %d · %s · prix affiché %s · prix minimum %s · %s · lien %s',
                $p->get_id(), $p->get_name(), Bayam_WA_Outils::fcfa((float) $p->get_price()), Bayam_WA_Outils::fcfa(self::plancher($vendeur, $p)),
                implode(' · ', Bayam_WA_Boutique::stock_lignes($p)), Bayam_WA_Liens::produit($vendeur, $p, 'assistant'));
        }
        $paiements = [];
        foreach (WC()->payment_gateways() ? WC()->payment_gateways()->get_available_payment_gateways() : [] as $passerelle) {
            $paiements[] = $passerelle->get_title();
        }
        return "Boutique : " . Bayam_WA_Outils::nom_boutique($vendeur) . "\n"
            . "Livraison : " . self::livraison() . "\n"
            . "Paiement : " . ($paiements ? implode(', ', $paiements) : 'à la livraison') . "\n"
            . ($cites ? "Le client répond à une publication de la boutique sur : " . implode(', ', array_map(static fn ($p) => $p->get_name(), $cites)) . "\n" : '')
            . "Articles (stock en temps réel) :\n" . implode("\n", $lignes);
    }

    /** Modes de livraison réellement réglés dans la boutique (zones WooCommerce). */
    private static function livraison(): string
    {
        $modes = [];
        foreach (WC_Shipping_Zones::get_zones() as $zone) {
            foreach ($zone['shipping_methods'] as $methode) {
                if ($methode->is_enabled()) {
                    $cout = (float) $methode->get_option('cost', 0);
                    $modes[] = $methode->get_title() . ' (' . ($cout > 0 ? Bayam_WA_Outils::fcfa($cout) : 'gratuit') . ')';
                }
            }
        }
        return $modes ? implode(', ', $modes) : 'à convenir avec la boutique';
    }

    // --- Commande conclue ------------------------------------------------------------------------------------

    /** Commande « à confirmer » au prix négocié, et alerte au vendeur. false si la commande n'est pas recevable. */
    private static function conclure(int $vendeur, string $chat, string $numero, array $c, string $resume): bool
    {
        $p = wc_get_product((int) ($c['produit_id'] ?? 0));
        $quantite = max(1, (int) ($c['quantite'] ?? 1));
        $prix = (int) ($c['prix_unitaire'] ?? 0);
        if (!$p || (int) get_post_field('post_author', $p->get_id()) !== $vendeur || $prix < self::plancher($vendeur, $p)) {
            return false; // article d'une autre boutique, ou prix sous le minimum : jamais conclu par l'assistant
        }
        [$p, $variante] = $p->is_type('variable') ? Bayam_WA_Boutique::designer($vendeur, $p->get_name() . ' ' . ($c['precision'] ?? '')) : [$p, null];
        $commande = Bayam_WA_Boutique::commande_a_confirmer($vendeur, $p, $variante, $quantite, $prix * $quantite, (string) ($c['nom_client'] ?? ''), $numero, (string) ($c['livraison'] ?? ''));
        $article = $variante ? $p->get_name() . ' (' . wc_get_formatted_variation($variante, true, false, false) . ')' : $p->get_name();
        $n = $commande->get_order_number();
        Bayam_WA_Regles::au_vendeur($vendeur, "🤝 *Commande conclue par votre assistant* (n° {$n}, à confirmer)\n"
            . "{$quantite} × {$article} à " . Bayam_WA_Outils::fcfa($prix) . ' (prix affiché ' . Bayam_WA_Outils::fcfa((float) $p->get_price()) . ")\n"
            . 'Total : *' . Bayam_WA_Outils::fcfa($prix * $quantite) . "*\n"
            . 'Client : ' . trim(($c['nom_client'] ?? '') . ', ' . Bayam_WA_Outils::numero_lisible($numero), ', ') . "\n"
            . 'Livraison : ' . (($c['livraison'] ?? '') ?: 'à préciser') . "\n"
            . ($resume !== '' ? "\n{$resume}\n" : '')
            . "\n✅ *bayam ok {$n}* pour confirmer · ❌ *bayam annule {$n}*\nDiscussion : https://wa.me/{$numero}");
        return true;
    }
}
