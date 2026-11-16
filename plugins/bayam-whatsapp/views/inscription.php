<?php
/** Page /vendre : ouvrir sa boutique BAYAM depuis son seul téléphone (Bayam_WA_Inscription). */
defined('ABSPATH') || exit;
$api = esc_url_raw(rest_url('bayam/v1/inscription'));
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ouvrir ma boutique BAYAM</title>
<meta name="description" content="Ouvrez votre boutique en ligne en 2 minutes depuis votre téléphone : WhatsApp, Facebook et l'application BAYAM.">
<style>
:root{--nuit:#0B2545;--emeraude:#0A7A55;--menthe:#E6F7F0;--nuage:#F7FAF9;--soleil:#FFC233;--gris:#475569;--corail:#FF6B57}
*{box-sizing:border-box}body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:var(--nuage);color:var(--nuit)}
main{max-width:560px;margin:0 auto;padding:24px 16px 48px}.carte{background:#fff;border-radius:24px;padding:24px;box-shadow:0 10px 40px rgba(11,37,69,.08);animation:monte .45s ease-out}
@keyframes monte{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}
h1{font-size:26px;margin:0 0 6px}p{line-height:1.55}.doux{color:var(--gris)}
label{display:block;font-weight:600;margin:14px 0 6px}input{width:100%;font-size:17px;padding:13px 14px;border:1.5px solid #CBD5E1;border-radius:12px}
input:focus{outline:none;border-color:var(--emeraude)}.choix{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:8px}
.choix label{margin:0;font-weight:600;border:1.5px solid #CBD5E1;border-radius:12px;padding:12px;text-align:center;cursor:pointer}
.choix input{display:none}.choix input:checked+span{color:var(--emeraude)}.choix label:has(input:checked){border-color:var(--emeraude);background:var(--menthe)}
.case{display:flex;gap:10px;align-items:flex-start;font-weight:400;margin-top:16px}.case input{width:auto;margin-top:4px}
button{width:100%;border:0;background:var(--emeraude);color:#fff;font-size:18px;font-weight:700;padding:16px;border-radius:16px;margin-top:20px;cursor:pointer}
button:disabled{opacity:.6}.piege{position:absolute;left:-9999px}
.code{font-size:38px;letter-spacing:6px;font-weight:800;text-align:center;background:var(--menthe);border-radius:16px;padding:18px;margin:14px 0;font-family:ui-monospace,monospace}
ol{padding-left:20px;line-height:1.75}.erreur{color:var(--corail);font-weight:600;margin-top:12px}
.attente{display:flex;gap:10px;align-items:center;color:var(--gris);margin-top:12px}.rond{width:14px;height:14px;border-radius:50%;background:var(--emeraude);animation:pulse 1.2s infinite}
@keyframes pulse{0%,100%{opacity:.3;transform:scale(.8)}50%{opacity:1;transform:scale(1)}}[hidden]{display:none!important}
</style>
</head>
<body><main><div class="carte">
<section id="etape-formulaire">
  <h1>Ouvrez votre boutique</h1>
  <p class="doux">2 minutes, depuis ce téléphone. Vos clients commandent ensuite sur WhatsApp, Facebook et l'application BAYAM.</p>
  <form id="formulaire" novalidate>
    <label for="boutique">Nom de la boutique</label><input id="boutique" name="boutique" maxlength="60" autocomplete="organization" required placeholder="Ex. Mode Aïcha">
    <label for="prenom">Votre prénom</label><input id="prenom" name="prenom" maxlength="40" autocomplete="given-name" required>
    <label for="telephone">Votre numéro WhatsApp</label><input id="telephone" name="telephone" inputmode="tel" autocomplete="tel" required placeholder="6 90 00 00 10">
    <label for="quartier">Quartier (facultatif)</label><input id="quartier" name="quartier" maxlength="60" placeholder="Ex. Akwa, Douala">
    <input class="piege" name="site" tabindex="-1" autocomplete="off" aria-hidden="true">
    <label>Je commence par</label>
    <div class="choix">
      <label><input type="radio" name="mode" value="whatsapp" checked><span>📱 WhatsApp</span></label>
      <label><input type="radio" name="mode" value="facebook"><span>📘 Ma Page Facebook</span></label>
    </div>
    <label class="case"><input type="checkbox" name="accepte" value="1"><span>J'accepte les <a href="<?php echo esc_url(home_url('/privacy-policy/')); ?>">conditions d'utilisation et de confidentialité</a>. BAYAM répond à mes clients pour moi et n'envoie jamais de messages en masse.</span></label>
    <button id="envoyer" type="submit">Ouvrir ma boutique</button>
    <p class="erreur" id="erreur" hidden></p>
  </form>
</section>

<section id="etape-code" hidden>
  <h1>Votre code WhatsApp</h1>
  <p class="doux">À saisir dans WhatsApp sur le téléphone du <strong id="numero"></strong>.</p>
  <div class="code" id="code"></div>
  <ol>
    <li>Ouvrez WhatsApp, puis <strong>⋮</strong> (ou Réglages sur iPhone) › <strong>Appareils connectés</strong>.</li>
    <li>Touchez <strong>Connecter un appareil</strong>, puis en bas <strong>Connecter avec le numéro de téléphone</strong>.</li>
    <li>Tapez le code ci-dessus. Revenez ici : la page se met à jour toute seule.</li>
  </ol>
  <div class="attente"><span class="rond"></span><span>En attente de la saisie du code…</span></div>
</section>

<section id="etape-fin" hidden>
  <h1>🎉 Votre boutique est prête</h1>
  <p><strong id="nom-boutique"></strong> est reliée à BAYAM.</p>
  <ol>
    <li>Dans WhatsApp, ouvrez la discussion <strong>« Message à moi-même »</strong> : votre menu de vendeur vous y attend. Écrivez <strong>bayam</strong> à tout moment.</li>
    <li>Ajoutez vos premiers articles : envoyez une photo avec le nom et le prix, par exemple « Robe wax 15000 ».</li>
    <li>Votre Page Facebook : écrivez <strong>bayam facebook</strong> dans le même menu.</li>
  </ol>
  <p class="doux"><a href="<?php echo esc_url(home_url('/telecharger')); ?>">Télécharger l'application BAYAM</a></p>
</section>
</div></main>
<script>
(() => {
  const api = <?php echo wp_json_encode($api); ?>;
  const $ = (id) => document.getElementById(id);
  const montrer = (id) => ['etape-formulaire', 'etape-code', 'etape-fin'].forEach((e) => { $(e).hidden = e !== id; });
  const suivre = (jeton) => {
    const tour = async () => {
      try {
        const r = await fetch(`${api}/${jeton}`, { cache: 'no-store' });
        const d = await r.json();
        if (d.whatsapp || d.facebook) { $('nom-boutique').textContent = d.boutique || 'Votre boutique'; montrer('etape-fin'); return; }
      } catch (e) { /* réseau coupé : on réessaie */ }
      setTimeout(tour, 4000);
    };
    tour();
  };
  $('formulaire').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const f = new FormData(ev.target);
    const donnees = Object.fromEntries(f.entries());
    donnees.accepte = f.get('accepte') === '1';
    $('erreur').hidden = true; $('envoyer').disabled = true; $('envoyer').textContent = 'Un instant…';
    try {
      const r = await fetch(api, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify(donnees) });
      const d = await r.json();
      if (!r.ok) { throw new Error(d.erreur || 'Une erreur est survenue, réessayez.'); }
      if (d.mode === 'facebook') { window.location.href = d.lien; return; }
      if (d.relie) { montrer('etape-fin'); return; }
      $('code').textContent = d.code; $('numero').textContent = d.numero; montrer('etape-code'); suivre(d.jeton);
    } catch (e) {
      $('erreur').textContent = e.message; $('erreur').hidden = false;
    } finally { $('envoyer').disabled = false; $('envoyer').textContent = 'Ouvrir ma boutique'; }
  });
})();
</script>
</body></html>
