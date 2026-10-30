<?php
defined('ABSPATH') || exit;

/**
 * Cartes produits prêtes à transférer (format 4:5, 1080 × 1350) et kit de statut (9:16, 1080 × 1920) :
 * photo, nom, prix en émeraude, ancien prix barré, nom de la boutique et lien de commande, aux couleurs de BAYAM.
 */
final class Bayam_WA_Cartes
{
    private const NUIT = [11, 37, 69];
    private const EMERAUDE = [10, 122, 85];
    private const ARDOISE = [71, 85, 105];
    private const CORAIL = [255, 107, 87];
    private const NUAGE = [247, 250, 249];
    private const MENTHE = [230, 247, 240];

    /** @return array{mimetype: string, data: string} image JPEG en base64, prête pour WAHA */
    public static function carte(WC_Product $p, string $lien, string $boutique, bool $statut = false): array
    {
        [$l, $h] = $statut ? [1080, 1920] : [1080, 1350];
        $im = imagecreatetruecolor($l, $h);
        imagefill($im, 0, 0, self::couleur($im, self::NUAGE));

        // Mise en page du bas vers le haut : bandeau, prix, nom (une ou deux lignes), puis la photo dans l'espace restant.
        $bas = $h - ($statut ? 260 : 200);
        $taille_nom = $statut ? 64 : 56;
        $taille_prix = $statut ? 72 : 62;
        $lignes_nom = self::decouper($p->get_name(), 'Poppins-Bold', $taille_nom, $l - 144, 2);
        $y_prix = $bas - ($statut ? 90 : 56);
        $y_nom = $y_prix - $taille_prix - 36 - (int) ((count($lignes_nom) - 1) * $taille_nom * 1.3);
        $bas_photo = $y_nom - $taille_nom - 48;

        // Photo dans un cadre de la couleur de son propre fond (coin haut gauche) : elle s'y fond sans bord visible.
        $photo = self::photo($p);
        $fond = $photo ? imagecolorsforindex($photo, imagecolorat($photo, 2, 2)) : ['red' => 255, 'green' => 255, 'blue' => 255];
        imagefilledrectangle($im, 48, 48, $l - 48, $bas_photo, self::couleur($im, [$fond['red'], $fond['green'], $fond['blue']]));
        if ($photo) {
            self::coller_contenu($im, $photo, 72, 72, $l - 144, $bas_photo - 96);
            imagedestroy($photo);
        }

        // Pastille de remise.
        $regulier = (float) $p->get_regular_price();
        $prix = (float) $p->get_price();
        if ($p->is_on_sale() && $regulier > 0 && $prix < $regulier) {
            $remise = '-' . round(100 - $prix * 100 / $regulier) . ' %';
            imagefilledrectangle($im, 72, 72, 72 + 190, 72 + 76, self::couleur($im, self::CORAIL));
            self::texte($im, $remise, 'Poppins-Bold', 34, 96, 128, [255, 255, 255]);
        }

        foreach ($lignes_nom as $i => $ligne) {
            self::texte($im, $ligne, 'Poppins-Bold', $taille_nom, 72, $y_nom + (int) ($i * $taille_nom * 1.3), self::NUIT);
        }
        $texte_prix = ($p->is_type('variable') || $p->is_type('grouped') ? 'Dès ' : '') . self::prix($prix);
        self::texte($im, $texte_prix, 'Poppins-ExtraBold', $taille_prix, 72, $y_prix, self::EMERAUDE);
        if ($p->is_on_sale() && $regulier > $prix) {
            $ancien = self::prix($regulier);
            $boite = imagettfbbox(36, 0, self::police('Inter-Medium'), $ancien);
            $x = $l - 72 - ($boite[2] - $boite[0]);
            self::texte($im, $ancien, 'Inter-Medium', 36, $x, $y_prix - 6, self::ARDOISE);
            imagesetthickness($im, 3);
            imageline($im, $x, $y_prix - 20, $x + ($boite[2] - $boite[0]), $y_prix - 20, self::couleur($im, self::ARDOISE));
        }

        // Bandeau du bas : boutique et lien de commande.
        imagefilledrectangle($im, 0, $bas, $l, $h, self::couleur($im, self::MENTHE));
        self::texte($im, $boutique, 'Inter-SemiBold', 38, 72, $bas + 72, self::NUIT);
        self::texte($im, 'Commander : ' . preg_replace('#^https?://#', '', $lien), 'Inter-SemiBold', 34, 72, $bas + 136, self::EMERAUDE);
        $logo = @imagecreatefrompng(BAYAM_WA_DIR . 'assets/images/logo.png');
        if ($logo) {
            $lw = 180;
            $lh = (int) round(imagesy($logo) * $lw / imagesx($logo));
            // En haut à droite du bandeau, au niveau du nom de la boutique, au-dessus du lien.
            imagecopyresampled($im, $logo, $l - 72 - $lw, $bas + 26, 0, 0, $lw, $lh, imagesx($logo), imagesy($logo));
            imagedestroy($logo);
        }

        ob_start();
        imagejpeg($im, null, 88);
        $data = ob_get_clean();
        imagedestroy($im);
        return ['mimetype' => 'image/jpeg', 'filename' => sanitize_title($p->get_name()) . '.jpg', 'data' => base64_encode($data)];
    }

    /** Prix pour l'image : espace normale entre les milliers (l'espace fine manque dans la police Poppins). */
    private static function prix(float $montant): string
    {
        return number_format($montant, 0, ',', ' ') . ' FCFA';
    }

    private static function photo(WC_Product $p)
    {
        $id = $p->get_image_id();
        $chemin = $id ? get_attached_file($id) : null;
        if (!$chemin || !file_exists($chemin)) {
            return null;
        }
        $donnees = file_get_contents($chemin);
        return $donnees ? @imagecreatefromstring($donnees) : null;
    }

    /** Colle l'image entière dans la zone, centrée, sans la déformer (contain). */
    private static function coller_contenu($dest, $src, int $x, int $y, int $l, int $h): void
    {
        $sl = imagesx($src);
        $sh = imagesy($src);
        $r = min($l / $sl, $h / $sh);
        $nl = (int) ($sl * $r);
        $nh = (int) ($sh * $r);
        imagecopyresampled($dest, $src, $x + (int) (($l - $nl) / 2), $y + (int) (($h - $nh) / 2), 0, 0, $nl, $nh, $sl, $sh);
    }

    private static function police(string $nom): string
    {
        return BAYAM_WA_DIR . "assets/polices/$nom.ttf";
    }

    private static function couleur($im, array $rgb): int
    {
        return imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]);
    }

    private static function texte($im, string $texte, string $police, int $taille, int $x, int $y, array $rgb): void
    {
        imagettftext($im, $taille, 0, $x, $y, self::couleur($im, $rgb), self::police($police), $texte);
    }

    /** Texte coupé aux mots en $lignes_max lignes au plus (points de suspension au besoin). */
    private static function decouper(string $texte, string $police, int $taille, int $largeur, int $lignes_max): array
    {
        $lignes = [];
        $courante = '';
        foreach (explode(' ', $texte) as $mot) {
            $essai = trim("$courante $mot");
            $boite = imagettfbbox($taille, 0, self::police($police), $essai);
            if ($boite[2] - $boite[0] > $largeur && $courante !== '') {
                $lignes[] = $courante;
                $courante = $mot;
            } else {
                $courante = $essai;
            }
        }
        $lignes[] = $courante;
        if (count($lignes) > $lignes_max) {
            $lignes = array_slice($lignes, 0, $lignes_max);
            $lignes[$lignes_max - 1] .= '…';
        }
        return $lignes;
    }
}
