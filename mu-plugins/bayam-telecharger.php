<?php
/**
 * Plugin Name: BAYAM, télécharger l'application
 * Description: Page /telecharger : dernière version de l'application Android (fichier signé, empreinte, code QR, guide
 * d'installation), lue dans le fichier de version publié par bayam/outils/publier_apk.sh (Cloudflare R2).
 */

defined('ABSPATH') || exit;

final class Bayam_Telecharger
{
    /**
     * Dernière version publiée, lue auprès de la passerelle sur le réseau interne (elle relit le fichier de version
     * de R2 toutes les 5 minutes) ; gardée 2 minutes, un échec n'est gardé que 30 secondes.
     */
    public static function version(): ?array
    {
        $v = get_transient('bayam_app_version');
        if ($v === false) {
            $r = wp_remote_get(rtrim((string) (getenv('BAYAM_PASSERELLE') ?: 'http://passerelle:8090'), '/') . '/config', ['timeout' => 5]);
            $a = is_wp_error($r) ? null : (json_decode((string) wp_remote_retrieve_body($r), true)['application'] ?? null);
            $v = is_array($a) && !empty($a['derniere']) && !empty($a['fichiers']) ? ['version' => $a['derniere']] + $a : [];
            set_transient('bayam_app_version', $v, $v ? 2 * MINUTE_IN_SECONDS : 30);
        }
        return $v ?: null;
    }

    public static function init(): void
    {
        add_action('template_redirect', static function (): void {
            $chemin = trim((string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
            if ($chemin === 'telecharger') {
                status_header(200);
                nocache_headers();
                self::page(self::version());
                exit;
            }
        });
    }

    private static function taille(int $octets): string
    {
        return number_format($octets / 1048576, 1, ',', ' ') . ' Mo';
    }

    private static function page(?array $v): void
    {
        $f = $v['fichiers'] ?? [];
        $principal = $f['arm64'] ?? null;
        $ancien = $f['v7a'] ?? null;
        header('Content-Type: text/html; charset=utf-8');
        ?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Télécharger BAYAM</title>
<meta name="description" content="Application BAYAM pour Android : commandez et vendez sur WhatsApp, Facebook et dans l'application.">
<style>
:root{--nuit:#0B2545;--emeraude:#0A7A55;--menthe:#E6F7F0;--nuage:#F7FAF9;--soleil:#FFC233;--gris:#475569}
*{box-sizing:border-box}body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:var(--nuage);color:var(--nuit)}
main{max-width:720px;margin:0 auto;padding:32px 16px 48px}
.carte{background:#fff;border-radius:24px;padding:28px;box-shadow:0 10px 40px rgba(11,37,69,.08);animation:monte .5s ease-out}
@keyframes monte{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}
h1{font-size:28px;margin:0 0 6px}p{line-height:1.55}.doux{color:var(--gris)}
.bouton{display:block;text-align:center;background:var(--emeraude);color:#fff;text-decoration:none;font-weight:700;font-size:18px;padding:16px;border-radius:16px;margin:18px 0 8px;transition:transform .15s}
.bouton:active{transform:scale(.98)}.lien{color:var(--emeraude);font-weight:600}
.grille{display:grid;grid-template-columns:1fr auto;gap:20px;align-items:center}
.qr{width:150px;height:150px;border-radius:16px;background:var(--menthe);padding:8px}
ol{padding-left:20px;line-height:1.7}.note{background:var(--menthe);border-radius:14px;padding:12px 16px;margin-top:14px}
code{font-size:12px;word-break:break-all;color:var(--gris)}
@media (max-width:560px){.grille{grid-template-columns:1fr}.qr{display:none}}
</style>
</head>
<body><main><div class="carte">
<?php if (!$principal) : ?>
  <h1>BAYAM arrive bientôt sur Android</h1>
  <p class="doux">La prochaine version est en préparation. En attendant, commandez auprès de vos boutiques sur WhatsApp en écrivant « bayam ».</p>
<?php else : ?>
  <div class="grille"><div>
    <h1>Télécharger BAYAM</h1>
    <p class="doux">Version <?php echo esc_html((string) ($v['version'] ?? '')); ?> pour Android<?php echo !empty($v['date']) ? esc_html(', publiée le ' . wp_date('d/m/Y', (int) strtotime((string) $v['date']))) : ''; ?>.</p>
    <?php if (!empty($v['notes'])) : ?><div class="note">✨ <?php echo esc_html((string) $v['notes']); ?></div><?php endif; ?>
  </div><?php if (!empty($v['qr'])) : ?><img class="qr" src="<?php echo esc_url((string) $v['qr']); ?>" alt="Code QR de cette page"><?php endif; ?></div>
  <a class="bouton" href="<?php echo esc_url((string) $principal['url']); ?>">Télécharger pour Android (<?php echo esc_html(self::taille((int) $principal['taille'])); ?>)</a>
  <?php if ($ancien) : ?><p class="doux">Téléphone ancien (avant 2018) ? <a class="lien" href="<?php echo esc_url((string) $ancien['url']); ?>">Version pour anciens téléphones (<?php echo esc_html(self::taille((int) $ancien['taille'])); ?>)</a></p><?php endif; ?>
  <h2>Installer en 1 minute</h2>
  <ol>
    <li>Touchez « Télécharger », puis ouvrez le fichier une fois téléchargé.</li>
    <li>Si le téléphone le demande, autorisez l'installation depuis votre navigateur (Paramètres, Installer des applications inconnues).</li>
    <li>Touchez « Installer », puis « Ouvrir ». Les mises à jour vous seront proposées directement dans l'application.</li>
  </ol>
  <p class="doux">iPhone : bientôt sur l'App Store. En attendant, écrivez « bayam » sur WhatsApp à votre boutique.</p>
  <p class="doux">Fichier signé par BAYAM. Empreinte SHA-256 : <code><?php echo esc_html((string) $principal['sha256']); ?></code></p>
<?php endif; ?>
</div></main></body></html>
<?php
    }
}

Bayam_Telecharger::init();
