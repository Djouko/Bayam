<?php
/** Vue de la rubrique WhatsApp de l'espace vendeur. Variables : $vendeur. */
defined('ABSPATH') || exit;

$reglages = Bayam_WA_Stockage::reglages($vendeur);
$lien_unique = Bayam_WA_Liens::boutique($vendeur);
$stats = Bayam_WA_Liens::statistiques($vendeur);
$nonce = wp_create_nonce('bayam_wa');
?>
<div class="collapse wcfm-collapse bwa" id="bwa">
  <div class="wcfm-page-headig"><span class="wcfmfa fa-comments"></span><span class="wcfm-page-heading-text">WhatsApp</span></div>
  <div class="wcfm-collapse-content">

    <section class="bwa-carte bwa-entete">
      <div>
        <h2>Vendez depuis votre WhatsApp</h2>
        <p>Connectez le WhatsApp de votre boutique : votre discussion « Message à moi-même » devient votre Copilote (stock, ventes, liens de paiement, bilans), vos clients qui écrivent « #robes » reçoivent vos articles en stock, et vos nouveautés partent dans vos groupes et votre statut.</p>
      </div>
      <span class="bwa-pastille" id="bwa-statut">…</span>
    </section>

    <!-- Étape 1 : consentement et connexion -->
    <section class="bwa-carte" id="bwa-connexion" hidden>
      <h3>Connecter mon WhatsApp</h3>
      <ol class="bwa-regles">
        <li>BAYAM se connecte à votre WhatsApp comme un appareil lié (comme WhatsApp Web). Vos messages restent sur votre téléphone ; BAYAM lit seulement ce qui sert à votre boutique.</li>
        <li>BAYAM n'écrit jamais en premier à une personne : il répond à vos clients et publie dans les groupes dont vous êtes administrateur.</li>
        <li>Les envois sont étalés et plafonnés pour protéger votre numéro. Cette connexion utilise un outil non officiel : WhatsApp peut, rarement, limiter un numéro qui abuse des envois.</li>
        <li>Vous pouvez déconnecter BAYAM à tout moment, ici ou dans WhatsApp (Appareils connectés).</li>
      </ol>
      <div class="bwa-case"><input type="checkbox" id="bwa-accord"><label for="bwa-accord">J'ai lu ces règles et je connecte le WhatsApp de ma boutique.</label></div>
      <button class="bwa-bouton" id="bwa-connecter">Afficher le QR code</button>
    </section>

    <!-- Étape 2 : QR code -->
    <section class="bwa-carte bwa-qr" id="bwa-scan" hidden>
      <div class="bwa-qr-image"><img id="bwa-qr" alt="QR code de connexion WhatsApp"><span class="bwa-chargement"></span></div>
      <div>
        <h3>Scannez avec le téléphone de la boutique</h3>
        <ol>
          <li>Ouvrez WhatsApp sur ce téléphone.</li>
          <li>Touchez <b>⋮</b> (ou <b>Réglages</b> sur iPhone), puis <b>Appareils connectés</b>.</li>
          <li>Touchez <b>Connecter un appareil</b> et visez ce QR code.</li>
        </ol>
        <p class="bwa-discret">Le QR code change toutes les 20 secondes ; la page se met à jour toute seule.</p>
        <details class="bwa-code">
          <summary>Je n'ai qu'un téléphone : me connecter avec un code</summary>
          <p>Entrez le numéro WhatsApp de la boutique, puis dans WhatsApp : Appareils connectés, Connecter un appareil, <b>Se connecter avec le numéro de téléphone</b>, et saisissez le code.</p>
          <div class="bwa-ligne"><input type="tel" id="bwa-numero" placeholder="6 90 00 00 10"><button class="bwa-bouton bwa-tonal" id="bwa-demander-code">Recevoir un code</button></div>
          <p class="bwa-gros-code" id="bwa-code"></p>
        </details>
      </div>
    </section>

    <!-- Étape 3 : connecté -->
    <div id="bwa-connecte" hidden>
      <section class="bwa-carte bwa-succes">
        <div>
          <h3>✅ Connecté : <span id="bwa-numero-connecte"></span></h3>
          <p>Ouvrez votre discussion <b>« Message à moi-même »</b> (votre propre nom en haut de la liste des contacts) et écrivez <b>bayam menu</b>. Le Copilote ne lit que les messages qui commencent par « bayam » : vos notes personnelles restent les vôtres.</p>
        </div>
        <button class="bwa-bouton bwa-tonal" id="bwa-essai">M'envoyer un message d'essai</button>
      </section>

      <section class="bwa-carte">
        <h3>Groupes de publication</h3>
        <p class="bwa-discret">Choisissez les groupes où le Copilote publie vos articles (commande <b>publier robes</b>). Seuls les groupes dont vous êtes administrateur sont proposés.</p>
        <div class="bwa-outils-groupes">
          <input type="search" id="bwa-filtre" placeholder="Rechercher un groupe" hidden>
          <span id="bwa-compte" class="bwa-discret"></span>
          <button type="button" class="bwa-bouton bwa-tonal" id="bwa-actualiser">Actualiser la liste</button>
        </div>
        <div id="bwa-groupes" class="bwa-groupes"><span class="bwa-chargement"></span></div>
      </section>

      <section class="bwa-carte">
        <h3>Réglages</h3>
        <div class="bwa-case"><input type="checkbox" id="bwa-mots-cles" <?php checked($reglages['mots_cles']); ?>><label for="bwa-mots-cles">Répondre automatiquement aux clients qui demandent un article (« #robes », « bayam robe rouge »)</label></div>
        <div class="bwa-case"><input type="checkbox" id="bwa-rapport" <?php checked($reglages['rapport_actif']); ?>><label for="bwa-rapport">Résumé quotidien des ventes à</label>
          <select id="bwa-heure" aria-label="Heure du résumé quotidien"><?php for ($h = 6; $h <= 23; $h++) : ?><option value="<?php echo $h; ?>" <?php selected((int) $reglages['heure_rapport'], $h); ?>><?php echo $h; ?> h</option><?php endfor; ?></select>
        </div>
        <div class="bwa-actions"><button class="bwa-bouton" id="bwa-enregistrer">Enregistrer</button> <span class="bwa-message" id="bwa-message"></span></div>
      </section>

      <section class="bwa-carte">
        <h3>Santé du canal</h3>
        <div class="bwa-sante" id="bwa-sante"></div>
      </section>
    </div>

    <section class="bwa-carte">
      <h3>Votre lien unique et votre QR code</h3>
      <p class="bwa-discret">À mettre dans vos biographies TikTok, Instagram et Facebook, et à imprimer pour la boutique physique. Ce lien fonctionne même sans connecter WhatsApp.</p>
      <div class="bwa-ligne"><input type="text" readonly value="<?php echo esc_attr($lien_unique); ?>" id="bwa-lien"><button class="bwa-bouton bwa-tonal" id="bwa-copier">Copier</button></div>
      <div class="bwa-autocollant" id="bwa-autocollant">
        <div id="bwa-qr-boutique"></div>
        <strong><?php echo esc_html(Bayam_WA_Outils::nom_boutique($vendeur)); ?></strong>
        <span>Scannez pour voir nos articles et commander</span>
      </div>
      <button class="bwa-bouton bwa-tonal" onclick="window.print()">Imprimer l'autocollant</button>
    </section>

    <section class="bwa-carte">
      <h3>Clics par source, 30 derniers jours</h3>
      <?php if ($stats) : ?>
        <table class="bwa-table"><thead><tr><th>Source</th><th>Clics</th></tr></thead><tbody>
        <?php
        $libelles = ['whatsapp' => 'WhatsApp', 'carte' => 'Cartes transférées', 'statut' => 'Statut', 'reponse' => 'Réponses aux clients', 'collection' => 'Collections envoyées', 'bio' => 'Lien unique', 'paiement' => 'Liens de paiement', 'promo' => 'Promos', 'recu' => 'Reçus'];
        foreach ($stats as $s) :
            $source = $reglages['groupes'][$s['source']] ?? ($libelles[$s['source']] ?? $s['source']);
            ?>
          <tr><td><?php echo esc_html($source); ?></td><td><?php echo (int) $s['clics']; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
      <?php else : ?>
        <p class="bwa-discret">Les clics apparaîtront ici dès que vos clients toucheront vos liens.</p>
      <?php endif; ?>
    </section>

    <p class="bwa-pied" id="bwa-pied" hidden><button class="bwa-lien-rouge" id="bwa-deconnecter">Déconnecter WhatsApp de BAYAM</button></p>
  </div>
</div>

<style>
.bwa{--em:#0A7A55;--em2:#0E9F6E;--nuit:#0B2545;--ard:#475569;--menthe:#E6F7F0;--bord:#E2E8F0;--cor:#FF6B57;font-family:Inter,system-ui,sans-serif;color:var(--nuit)}
/* « hidden » doit l'emporter sur les display des cartes ; les titres échappent aux flottements de WCFM. */
.bwa [hidden]{display:none!important}
.bwa h2,.bwa h3{font-family:Poppins,Inter,sans-serif;color:var(--nuit);margin:0 0 8px;float:none!important;display:block!important;width:auto!important;line-height:1.3;background:none!important;padding:0!important;border:0!important}
.bwa h2{font-size:22px}.bwa h3{font-size:17px}
.bwa-carte{background:#fff;border:1px solid var(--bord);border-radius:16px;padding:18px;margin:0 0 14px;box-shadow:0 4px 16px rgba(11,37,69,.06);animation:bwaEntree .45s cubic-bezier(.2,.8,.2,1) both}
.bwa-carte:nth-child(2){animation-delay:.05s}.bwa-carte:nth-child(3){animation-delay:.1s}.bwa-carte:nth-child(4){animation-delay:.15s}
@keyframes bwaEntree{from{opacity:0;transform:translateY(12px) scale(.985)}to{opacity:1;transform:none}}
@media (prefers-reduced-motion:reduce){.bwa-carte{animation:none}}
.bwa-entete{display:flex;gap:16px;align-items:flex-start;justify-content:space-between;background:linear-gradient(135deg,#fff 0%,var(--menthe) 100%)}
.bwa-pastille{white-space:nowrap;border-radius:999px;padding:6px 12px;font-weight:600;font-size:13px;background:#F1F5F9;color:var(--ard)}
.bwa-pastille.ok{background:var(--menthe);color:var(--em)}.bwa-pastille.attente{background:#FFF4D6;color:#8A5A00}.bwa-pastille.ko{background:#FFE9E6;color:#B42318}
.bwa ol,.bwa ul{margin:0 0 12px!important;padding-left:20px!important}
.bwa-regles{color:var(--ard);line-height:1.55}.bwa-regles li{margin:0 0 6px}
/* Jamais de case à cocher dans un <label> : WCFM (unwrapSelect) retire ces labels toutes les 500 ms. */
.bwa-case{display:flex;gap:10px;align-items:center;margin:12px 0;line-height:1.4}
.bwa-case input[type=checkbox]{flex:0 0 auto;margin:0!important}
.bwa .bwa-case label,.bwa .bwa-groupe label{float:none!important;width:auto!important;display:block!important;margin:0!important;padding:0;font-weight:500;font-size:15px;color:var(--nuit);cursor:pointer}
.bwa-case label{flex:0 1 auto;min-width:0}
.bwa-case select{min-height:40px;border:1px solid var(--bord);border-radius:10px;padding:0 10px;width:auto!important;float:none!important;margin:0!important}
.bwa-actions{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:16px}
.bwa-bouton{background:var(--em);color:#fff;border:0;border-radius:14px;padding:12px 18px;font-weight:600;min-height:44px;cursor:pointer;transition:transform .15s,background .15s,box-shadow .15s}
.bwa-bouton:hover{background:#086447;box-shadow:0 6px 14px rgba(10,122,85,.25)}.bwa-bouton:active{transform:scale(.97)}
.bwa-bouton[disabled]{opacity:.5;cursor:not-allowed}
.bwa-tonal{background:var(--menthe);color:var(--em)}.bwa-tonal:hover{background:#d3f0e3;box-shadow:none}
.bwa-qr{display:flex;gap:22px;flex-wrap:wrap;align-items:flex-start}
.bwa-qr-image{position:relative;width:260px;height:260px;border-radius:16px;border:1px solid var(--bord);display:grid;place-items:center;background:#fff;flex:0 0 auto}
.bwa-qr-image img{width:240px;height:240px;transition:opacity .3s}
.bwa-qr ol{padding-left:18px;line-height:1.7}
.bwa-discret{color:var(--ard);font-size:14px}
.bwa-code summary{cursor:pointer;color:var(--em);font-weight:600;margin-top:8px}
.bwa-ligne{display:flex;gap:8px;flex-wrap:wrap}.bwa-ligne input{flex:1 1 200px;min-height:44px;border:1px solid var(--bord);border-radius:12px;padding:0 12px}
.bwa-gros-code{font-family:Poppins,monospace;font-size:28px;letter-spacing:4px;color:var(--em);font-weight:700}
.bwa-succes{display:flex;gap:16px;justify-content:space-between;align-items:center;flex-wrap:wrap;border-color:#bfe8d6}
.bwa-outils-groupes{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:0 0 12px}
.bwa-outils-groupes input[type=search]{flex:1 1 220px;min-width:0;border:1px solid var(--bord);border-radius:12px;padding:10px 12px;font:inherit}
.bwa-outils-groupes .bwa-bouton{margin-left:auto}
.bwa-groupes{max-height:420px;overflow:auto;display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:8px}
.bwa-groupe{display:flex;align-items:center;border:1px solid var(--bord);border-radius:12px;padding:0 0 0 12px;transition:border-color .15s,background .15s}
.bwa-groupe:hover{border-color:#B9D9CC}
.bwa-groupe:has(input:checked){border-color:var(--em2);background:var(--menthe)}
.bwa-groupe input[type=checkbox]{flex:0 0 auto;margin:0!important}
.bwa .bwa-groupe label{flex:1 1 0;min-width:0;padding:10px 12px 10px 10px;overflow-wrap:anywhere}
.bwa-groupe small{display:block;color:var(--ard);font-weight:400;font-size:13px}
.bwa-sante{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px}
.bwa-indic{border-radius:12px;background:#F7FAF9;padding:12px}.bwa-indic b{display:block;font-family:Poppins,sans-serif;font-size:18px}
.bwa-indic span{color:var(--ard);font-size:13px}
.bwa-table{width:100%;border-collapse:collapse}.bwa-table th,.bwa-table td{padding:10px;border-bottom:1px solid var(--bord);text-align:left}
.bwa-autocollant{margin:14px 0;display:inline-flex;flex-direction:column;align-items:center;gap:6px;padding:18px 22px;border-radius:18px;border:2px dashed var(--em2);text-align:center}
.bwa-autocollant span{color:var(--ard);font-size:13px}
.bwa-message{color:var(--em);font-weight:600}
.bwa-lien-rouge{background:none;border:0;color:var(--cor);font-weight:600;cursor:pointer;min-height:44px}
.bwa-chargement{width:28px;height:28px;border:3px solid var(--menthe);border-top-color:var(--em);border-radius:50%;animation:bwaTour 1s linear infinite;display:inline-block}
.bwa-qr-image img[src] + .bwa-chargement{display:none}
@keyframes bwaTour{to{transform:rotate(360deg)}}
@media (max-width:600px){.bwa-carte{padding:14px}.bwa-entete{flex-direction:column}.bwa-qr-image{width:100%;max-width:280px;margin:auto}}
@media print{body *{visibility:hidden}.bwa-autocollant,.bwa-autocollant *{visibility:visible}.bwa-autocollant{position:fixed;left:30px;top:30px}}
</style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
(function ($) {
  const ajax = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, nonce = <?php echo wp_json_encode($nonce); ?>;
  const choisis = <?php echo wp_json_encode((object) $reglages['groupes']); ?>;
  const libelles = {WORKING: ['Connecté', 'ok'], SCAN_QR_CODE: ['En attente du QR code', 'attente'], STARTING: ['Démarrage…', 'attente'], FAILED: ['Échec de connexion', 'ko'], STOPPED: ['Non connecté', ''], ABSENTE: ['Non connecté', '']};
  let minuterie = null, groupesCharges = false;
  const appel = (action, donnees = {}) => $.post(ajax, Object.assign({action: 'bayam_wa_' + action, nonce}, donnees));

  function afficher(etat) {
    const [texte, classe] = libelles[etat.statut] || [etat.statut, ''];
    $('#bwa-statut').text(texte).attr('class', 'bwa-pastille ' + classe);
    $('#bwa-connexion').prop('hidden', ['WORKING', 'SCAN_QR_CODE', 'STARTING'].includes(etat.statut));
    $('#bwa-scan').prop('hidden', !['SCAN_QR_CODE', 'STARTING'].includes(etat.statut));
    $('#bwa-connecte, #bwa-pied').prop('hidden', etat.statut !== 'WORKING');
    if (etat.qr) { $('#bwa-qr').attr('src', etat.qr); }
    if (etat.statut === 'WORKING') {
      $('#bwa-numero-connecte').text((etat.nom ? etat.nom + ', ' : '') + (etat.numero || ''));
      if (etat.sante) {
        const s = etat.sante, c = {NONE: 'normal', FIRST_WARNING: 'premier avertissement', SECOND_WARNING: 'second avertissement', CAPPED: 'plafonné'};
        $('#bwa-sante').html(
          indic(s.reponses, 'réponses aux clients aujourd’hui') + indic(s.diffusions, 'publications dans les groupes') +
          indic(s.statuts, 'publications en statut') + indic(s.restreint ? 'Oui' : 'Non', 'limitation par WhatsApp') +
          (s.whatsapp ? indic(c[s.whatsapp] || s.whatsapp, 'quota d’envoi WhatsApp') : ''));
      }
      if (!groupesCharges) { chargerGroupes(); }
    }
    clearTimeout(minuterie);
    minuterie = setTimeout(rafraichir, etat.statut === 'WORKING' ? 30000 : 4000);
  }
  const indic = (valeur, libelle) => `<div class="bwa-indic"><b>${valeur}</b><span>${libelle}</span></div>`;
  const rafraichir = () => appel('etat').done(r => r.success && afficher(r.data));

  function chargerGroupes(frais) {
    groupesCharges = true;
    // Les cases déjà cochées restent cochées quand la liste est relue.
    $('#bwa-groupes input').each(function () { this.checked ? (choisis[this.value] = $(this).data('nom')) : delete choisis[this.value]; });
    $('#bwa-groupes').html('<span class="bwa-chargement"></span>');
    $('#bwa-actualiser').prop('disabled', true);
    appel('groupes', frais ? {frais: 1} : {}).done(r => {
      if (!r.success) { $('#bwa-groupes').text(r.data.message); return; }
      const admins = r.data.groupes.filter(g => g.admin);
      $('#bwa-compte').text(r.data.groupes.length ? `${admins.length} groupe${admins.length > 1 ? 's' : ''} administré${admins.length > 1 ? 's' : ''} sur ${r.data.groupes.length}` : '');
      $('#bwa-filtre').prop('hidden', admins.length < 8);
      if (!admins.length) { $('#bwa-groupes').html('<p class="bwa-discret">Vous n’administrez encore aucun groupe WhatsApp. Créez un groupe pour vos clients, puis revenez ici.</p>'); return; }
      const echapper = s => $('<i>').text(s).html().replace(/"/g, '&quot;');
      $('#bwa-groupes').html(admins.map((g, i) => `<div class="bwa-groupe"><input type="checkbox" id="bwa-g${i}" value="${echapper(g.id)}" data-nom="${echapper(g.nom)}" ${choisis[g.id] ? 'checked' : ''}><label for="bwa-g${i}">${echapper(g.nom)}<small>${g.membres} membre${g.membres > 1 ? 's' : ''}</small></label></div>`).join(''));
      filtrer();
    }).fail(() => $('#bwa-groupes').text('La liste des groupes n’a pas pu être lue. Touchez « Actualiser la liste ».'))
      .always(() => $('#bwa-actualiser').prop('disabled', false));
  }
  function filtrer() {
    const q = $('#bwa-filtre').val().trim().toLowerCase();
    $('#bwa-groupes .bwa-groupe').each(function () { this.hidden = q !== '' && !this.textContent.toLowerCase().includes(q); });
  }
  $('#bwa-filtre').on('input', filtrer);
  $('#bwa-actualiser').on('click', () => chargerGroupes(true));

  $('#bwa-connecter').on('click', function () {
    if (!$('#bwa-accord').is(':checked')) { alert('Cochez d’abord la case pour accepter les règles d’usage.'); return; }
    $(this).prop('disabled', true);
    appel('connecter', {consentement: 1}).done(r => { r.success ? (afficher(r.data), rafraichir()) : alert(r.data.message); }).always(() => $(this).prop('disabled', false));
  });
  $('#bwa-demander-code').on('click', () => appel('code', {numero: $('#bwa-numero').val()}).done(r => r.success ? $('#bwa-code').text(r.data.code) : alert(r.data.message)));
  $('#bwa-essai').on('click', () => appel('essai').done(r => alert(r.success ? r.data.message : r.data.message)));
  $('#bwa-enregistrer').on('click', function () {
    const groupes = {};
    $('#bwa-groupes input:checked').each(function () { groupes[this.value] = $(this).data('nom'); });
    appel('reglages', {groupes, mots_cles: $('#bwa-mots-cles').is(':checked') ? 1 : '', rapport_actif: $('#bwa-rapport').is(':checked') ? 1 : '', heure_rapport: $('#bwa-heure').val()})
      .done(r => { $('#bwa-message').text(r.success ? r.data.message : r.data.message).fadeIn().delay(2500).fadeOut(); });
  });
  $('#bwa-deconnecter').on('click', () => { if (confirm('Déconnecter WhatsApp de BAYAM ? Le Copilote et les réponses automatiques s’arrêteront.')) { appel('deconnecter').done(r => r.success && afficher(r.data)); } });
  $('#bwa-copier').on('click', function () { navigator.clipboard.writeText($('#bwa-lien').val()); $(this).text('Copié ✓'); setTimeout(() => $(this).text('Copier'), 2000); });
  if (window.QRCode) { new QRCode(document.getElementById('bwa-qr-boutique'), {text: $('#bwa-lien').val(), width: 168, height: 168, colorDark: '#0B2545', colorLight: '#ffffff'}); }
  rafraichir();
})(jQuery);
</script>
