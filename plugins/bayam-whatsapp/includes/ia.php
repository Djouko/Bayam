<?php
/**
 * Modèle de langage de l'assistant et du Copilote, derrière une seule fonction : une réponse JSON conforme à un
 * schéma. Fournisseur : Anthropic (Claude) si ANTHROPIC_API_KEY est réglée, sinon Google Gemini (GEMINI_API_KEY),
 * dont l'offre gratuite suffit aux essais. Modèles réglables : BAYAM_ASSISTANT_MODELE (Claude),
 * BAYAM_GEMINI_MODELES (liste, essayés dans l'ordre quand l'un est saturé).
 */

defined('ABSPATH') || exit;

final class Bayam_WA_IA
{
    private const GEMINI = 'https://generativelanguage.googleapis.com/v1beta/models/';

    /** Réponse du modèle simulée pendant les essais (aucun appel réel). */
    private static $simule = null;

    public static function remplacer(?callable $simule): void
    {
        self::$simule = $simule;
    }

    public static function disponible(): bool
    {
        return self::$simule !== null || self::claude_disponible() || (string) getenv('GEMINI_API_KEY') !== '';
    }

    /**
     * @param array $messages [['role' => 'user'|'assistant', 'content' => string], …]
     * @return array|null données conformes au schéma ; ['refus' => true] si le modèle refuse de répondre ; null si
     *                    aucun modèle n'est joignable
     */
    public static function json(string $consignes, string $contexte, array $messages, array $schema): ?array
    {
        if (self::$simule) {
            return (self::$simule)($consignes, $contexte, $messages, $schema);
        }
        if (self::claude_disponible()) {
            return self::claude($consignes, $contexte, $messages, $schema);
        }
        return (string) getenv('GEMINI_API_KEY') !== '' ? self::gemini($consignes, $contexte, $messages, $schema) : null;
    }

    private static function claude_disponible(): bool
    {
        return (string) getenv('ANTHROPIC_API_KEY') !== '' && class_exists('Anthropic\\Client');
    }

    private static function claude(string $consignes, string $contexte, array $messages, array $schema): ?array
    {
        try {
            $reponse = (new Anthropic\Client())->messages->create(
                model: getenv('BAYAM_ASSISTANT_MODELE') ?: 'claude-opus-5-5',
                maxTokens: 4000,
                system: [
                    // Consignes identiques pour toutes les boutiques : mises en cache, seul le contexte change.
                    ['type' => 'text', 'text' => $consignes, 'cacheControl' => ['type' => 'ephemeral']],
                    ['type' => 'text', 'text' => $contexte],
                ],
                messages: $messages,
                outputConfig: ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => $schema]],
            );
        } catch (Throwable $e) {
            Bayam_WA_Outils::journal('Claude injoignable', ['erreur' => $e->getMessage()]);
            return null;
        }
        if ($reponse->stopReason === 'refusal') {
            return ['refus' => true];
        }
        foreach ($reponse->content as $bloc) {
            if ($bloc->type === 'text') {
                $donnees = json_decode($bloc->text, true);
                return is_array($donnees) ? $donnees : null;
            }
        }
        return null;
    }

    private static function gemini(string $consignes, string $contexte, array $messages, array $schema): ?array
    {
        // Rôles de Gemini (user / model) ; deux messages consécutifs du même côté sont réunis.
        $contenus = [];
        foreach ($messages as $m) {
            $role = $m['role'] === 'assistant' ? 'model' : 'user';
            $texte = (string) $m['content'];
            if ($contenus && $contenus[array_key_last($contenus)]['role'] === $role) {
                $contenus[array_key_last($contenus)]['parts'][0]['text'] .= "\n" . $texte;
            } else {
                $contenus[] = ['role' => $role, 'parts' => [['text' => $texte]]];
            }
        }
        $corps = wp_json_encode([
            'systemInstruction' => ['parts' => [['text' => $consignes], ['text' => $contexte]]],
            'contents' => $contenus,
            'generationConfig' => ['responseMimeType' => 'application/json', 'responseJsonSchema' => $schema, 'temperature' => 0.3],
        ]);
        foreach (self::modeles_gemini() as $modele) {
            $r = wp_remote_post(self::GEMINI . rawurlencode($modele) . ':generateContent', [
                'timeout' => 25,
                'headers' => ['x-goog-api-key' => (string) getenv('GEMINI_API_KEY'), 'content-type' => 'application/json'],
                'body' => $corps,
            ]);
            $code = is_wp_error($r) ? 0 : (int) wp_remote_retrieve_response_code($r);
            if ($code === 200) {
                $d = json_decode(wp_remote_retrieve_body($r), true);
                $candidat = $d['candidates'][0] ?? null;
                if (!$candidat || isset($d['promptFeedback']['blockReason'])
                    || in_array($candidat['finishReason'] ?? '', ['SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'SPII', 'RECITATION'], true)) {
                    return ['refus' => true];
                }
                $texte = '';
                foreach ($candidat['content']['parts'] ?? [] as $partie) {
                    $texte .= empty($partie['thought']) ? (string) ($partie['text'] ?? '') : '';
                }
                $donnees = json_decode($texte, true);
                return is_array($donnees) ? $donnees : null;
            }
            Bayam_WA_Outils::journal('Gemini', ['modele' => $modele, 'code' => $code, 'erreur' => is_wp_error($r) ? $r->get_error_message() : mb_substr(wp_remote_retrieve_body($r), 0, 200)]);
            // Saturé, quota atteint, modèle retiré ou réseau coupé : on essaie le suivant. Autre erreur : on s'arrête.
            if (!in_array($code, [0, 404, 429, 500, 503], true)) {
                return null;
            }
        }
        return null;
    }

    private static function modeles_gemini(): array
    {
        // Flash complet d'abord (suit mieux les consignes : vouvoiement, prix minimum caché), versions légères en secours.
        $liste = (string) (getenv('BAYAM_GEMINI_MODELES') ?: 'gemini-3.6-flash,gemini-3.5-flash-lite,gemini-3.1-flash-lite');
        return array_values(array_filter(array_map('trim', explode(',', $liste))));
    }
}
