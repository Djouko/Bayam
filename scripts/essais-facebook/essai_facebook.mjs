// Essai de bout en bout du canal Facebook, avec la vraie chaîne BAYAM (Traefik -> passerelle -> NATS -> canal-facebook
// -> WordPress) et un faux Graph à la place de Meta. Boutique 4 (Accessoires Akwa, sans WhatsApp relié : aucun envoi réel).
import { createHmac } from 'node:crypto';

const { SECRET, VERIFICATION, JETON_SERVICE } = process.env;
const PAGE = '900000000000004';
const CLIENT = '7000000000000001';
const ENTREE = 'http://traefik/webhooks/facebook';
const ENTETES_PUBLICS = { host: 'localhost:8080', 'cf-connecting-ip': '41.202.10.20' };
const service = { 'x-bayam-service': JETON_SERVICE, 'content-type': 'application/json' };
let reussis = 0;
const echecs = [];

function verifier(nom, ok, detail = '') {
  if (ok) {
    reussis++;
    console.log(`  ✓ ${nom}`);
  } else {
    echecs.push(nom);
    console.log(`  ✗ ${nom}${detail ? ` : ${typeof detail === 'string' ? detail : JSON.stringify(detail).slice(0, 600)}` : ''}`);
  }
}
const attendre = (ms) => new Promise((ok) => setTimeout(ok, ms));
const appels = async () => (await fetch('http://faux-graph:9000/__appels')).json();
const vider = () => fetch('http://faux-graph:9000/__appels', { method: 'DELETE' });

/** Attend que le faux Graph ait reçu au moins n appels qui vérifient le filtre (5 s au plus) ; rend ces appels. */
async function recus(filtre, n = 1, delai = 5000) {
  const debut = Date.now();
  let trouves = [];
  while (Date.now() - debut < delai) {
    trouves = (await appels()).filter(filtre);
    if (trouves.length >= n) return trouves;
    await attendre(50);
  }
  return trouves;
}

// Identifiants propres à ce passage : le bus écarte un identifiant déjà vu dans les 10 dernières minutes.
const PASSAGE = Date.now().toString(36);
let sequence = 0;
async function meta(entree) {
  const corps = JSON.stringify({ object: 'page', entry: [{ id: PAGE, time: Date.now(), ...entree }] });
  const signature = `sha256=${createHmac('sha256', SECRET).update(corps).digest('hex')}`;
  return fetch(ENTREE, { method: 'POST', headers: { ...ENTETES_PUBLICS, 'content-type': 'application/json', 'x-hub-signature-256': signature }, body: corps });
}
const message = (contenu) => meta({ messaging: [{ sender: { id: CLIENT }, recipient: { id: PAGE }, timestamp: Date.now(), ...contenu }] });
const texte = (t) => message({ message: { mid: `m.essai.${PASSAGE}.${++sequence}`, text: t } });
const envoisAuClient = (a) => a.methode === 'POST' && a.chemin === `/${PAGE}/messages` && a.corps?.recipient?.id === CLIENT;

console.log('\nVérification de l\'adresse par Meta et signature');
const declaration = await recus((a) => a.methode === 'POST' && a.chemin === '/1000000000000001/subscriptions', 1, 8000);
verifier('le service déclare lui-même à Meta l\'adresse publique des événements et ses champs',
  /^https:\/\/.+\/webhooks\/facebook$/.test(declaration[0]?.corps?.callback_url ?? '') && declaration[0].corps.verify_token === VERIFICATION
  && declaration[0].corps.fields === 'messages,messaging_postbacks,messaging_referrals,message_echoes,feed' && declaration[0].jeton === `1000000000000001|${SECRET}`, declaration[0]);
let r = await fetch(`${ENTREE}?hub.mode=subscribe&hub.verify_token=${VERIFICATION}&hub.challenge=1158201444`, { headers: ENTETES_PUBLICS });
verifier('GET de vérification : défi renvoyé tel quel', r.status === 200 && (await r.text()) === '1158201444');
r = await fetch(`${ENTREE}?hub.mode=subscribe&hub.verify_token=mauvais&hub.challenge=1`, { headers: ENTETES_PUBLICS });
verifier('mauvais jeton de vérification : refusé (403)', r.status === 403);
r = await fetch(ENTREE, { method: 'POST', headers: { ...ENTETES_PUBLICS, 'content-type': 'application/json', 'x-hub-signature-256': 'sha256=00' }, body: '{"object":"page","entry":[]}' });
verifier('événement mal signé : refusé (401)', r.status === 401);
r = await fetch('http://traefik/whatsapp', { method: 'POST', headers: ENTETES_PUBLICS, body: '{}' });
verifier('l\'entrée WhatsApp de la passerelle n\'est pas publique', r.status !== 200 || !(await r.text()).includes('"ok"'));

console.log('\nConnexion de la Page (jeton collé, comme par l\'assistance)');
await vider();
r = await fetch('http://canal-facebook:8092/interne/vendeurs/4/pages', { method: 'POST', headers: service, body: JSON.stringify({ jeton: 'jeton-court-essai' }) });
const connexion = await r.json();
verifier('Page connectée', r.status === 200 && connexion.pages?.[0] === 'Accessoires Akwa Douala', connexion);
const a0 = await appels();
verifier('jeton longue durée demandé, Page abonnée aux 5 événements utiles, bouton Démarrer et accueil réglés',
  a0.some((a) => a.requete.fb_exchange_token) && a0.some((a) => a.chemin === `/${PAGE}/subscribed_apps` && a.corps?.subscribed_fields === 'messages,messaging_postbacks,messaging_referrals,message_echoes,feed')
  && a0.some((a) => a.chemin === '/me/messenger_profile' && a.corps?.get_started?.payload === 'bayam:menu' && a.corps.persistent_menu?.[0]?.call_to_actions?.length === 3), a0.map((a) => a.chemin));
verifier('chaque appel porte la preuve du secret de l\'application', a0.filter((a) => a.jeton).every((a) => a.preuve === true));
r = await fetch('http://canal-facebook:8092/interne/vendeurs/4/pages', { headers: service });
const listees = await r.json();
verifier('Page listée pour WordPress, sans son jeton, avec les autorisations accordées',
  listees.length === 1 && listees[0].nom === 'Accessoires Akwa Douala' && !('jeton' in listees[0]) && listees[0].permissions.includes('pages_manage_posts'), listees);
r = await fetch('http://canal-facebook:8092/interne/vendeurs/4/pages', { headers: { 'x-bayam-service': 'faux' } });
verifier('API interne sans le jeton de service : refusée (401)', r.status === 401);

console.log('\nMessenger : un client écrit à la Page');
await vider();
let t0 = Date.now();
await texte('Bonsoir');
let e = await recus(envoisAuClient);
const delaiMenu = e[0] ? e[0].t - t0 : null;
verifier(`« Bonsoir » : menu avec les rayons en réponses rapides (${delaiMenu} ms)`, e[0]?.corps.message.quick_replies?.some((q) => q.payload === 'bayam:rayon:72:1') && e[0].corps.message.metadata === 'bayam' && e[0].corps.messaging_type === 'RESPONSE', e[0]?.corps);
await vider();
await message({ message: { mid: `m.essai.${PASSAGE}.${++sequence}`, text: 'Montres et bijoux', quick_reply: { payload: 'bayam:rayon:72:1' } } });
e = await recus(envoisAuClient, 2);
const elements = e[0]?.corps.message.attachment?.payload.elements ?? [];
verifier('rayon touché : carrousel des 4 articles (photo en adresse publique, prix, boutons), puis la suite',
  elements.length === 4 && elements.every((x) => /^https:\/\/.+\/wp-content\/uploads\/.+\.(jpe?g|png|webp)$/.test(x.image_url ?? '') && /FCFA/.test(x.subtitle)) && elements[0].buttons[0].payload === `bayam:produit:${1036}` && e[1]?.corps.message.text?.includes('Montres et bijoux'), e.map((x) => x.corps.message));
await vider();
await message({ postback: { mid: `m.essai.${PASSAGE}.${++sequence}`, title: '🛒 Commander', payload: 'bayam:produit:1036' } });
e = await recus(envoisAuClient, 2);
const bouton = e[0]?.corps.message.attachment?.payload.elements?.[0]?.buttons?.[0];
verifier('« Commander » : carte de l\'article avec le bouton vers le lien personnel', bouton?.type === 'web_url' && /\/commander\/[A-Za-z0-9_-]{24}$/.test(bouton.url), e[0]?.corps.message);
const jeton = bouton?.url?.split('/').pop();
r = await fetch(`http://traefik/commander/${jeton}`, { headers: ENTETES_PUBLICS, redirect: 'manual' });
verifier('lien personnel ouvert : le site le reconnaît (lien Messenger) et mène à la commande', r.status === 302 && /checkout|commande|product|bracelet/i.test(r.headers.get('location') ?? ''), `${r.status} ${r.headers.get('location')}`);
r = await fetch(`http://canal-facebook:8092/interne/jetons/${jeton}`, { headers: service });
const j = await r.json();
verifier('jeton résolu : boutique, article, Page et client Messenger, sans numéro', j.vendeur === 4 && j.produit === 1036 && j.page === PAGE && j.chat === CLIENT && j.numero === '' && j.canal === 'facebook', j);

console.log('\nLien m.me partagé sur un profil ou un groupe (ref=produit-1034)');
await vider();
await message({ referral: { ref: 'produit-1034', source: 'SHORTLINK', type: 'OPEN_THREAD' } });
e = await recus(envoisAuClient);
verifier('le client arrive directement sur l\'article', e[0]?.corps.message.attachment?.payload.elements?.[0]?.title === 'Collier de perles africaines', e[0]?.corps.message);

console.log('\nMessage libre et reprise par le vendeur');
await vider();
await texte('Vous livrez à Bonabéri ce soir ?');
await texte('Je peux payer par OM ?');
e = await recus(envoisAuClient, 1);
await attendre(1500);
verifier('question libre : un seul accusé de réception pour deux messages à la suite', (await appels()).filter(envoisAuClient).length === 1 && e[0]?.corps.message.text.startsWith('Merci 🙏'), (await appels()).filter(envoisAuClient).map((a) => a.corps.message.text));
await vider();
await meta({ messaging: [{ sender: { id: PAGE }, recipient: { id: CLIENT }, timestamp: Date.now(), message: { mid: `m.vendeur.${Date.now()}`, is_echo: true, text: 'Oui on livre, je vous appelle' } }] });
await meta({ messaging: [{ sender: { id: PAGE }, recipient: { id: CLIENT }, timestamp: Date.now(), message: { mid: `m.bayam.${Date.now()}`, is_echo: true, metadata: 'bayam', text: 'écho' } }] });
await attendre(800);
await texte('Bonjour');
await attendre(1500);
verifier('le vendeur a répondu lui-même : BAYAM se tait dans cette discussion (message tapé)', (await appels()).filter(envoisAuClient).length === 0);
await message({ postback: { mid: `m.essai.${PASSAGE}.${++sequence}`, title: 'Livraison', payload: 'bayam:livraison' } });
e = await recus(envoisAuClient);
verifier('… mais un bouton touché par le client reçoit toujours sa réponse', e[0]?.corps.message.text?.startsWith('🚚 Livraison'), e[0]?.corps.message);

console.log('\nPublication sur la Page, puis commentaire « prix ? »');
await vider();
r = await fetch('http://canal-facebook:8092/interne/publications', { method: 'POST', headers: service, body: JSON.stringify({ vendeur: 4, produits: [1032], type: 'publication' }) });
verifier('publication demandée (202, une Page)', r.status === 202 && (await r.json()).pages === 1);
e = await recus((a) => a.chemin === `/${PAGE}/photos` && a.corps?.published === 'true', 1, 8000);
verifier('photo envoyée en fichier JPEG, légende avec prix et lien m.me direct vers l\'article',
  e[0]?.corps.multipart && e[0].corps.jpeg && e[0].corps.caption.startsWith('Montre femme argentée') && e[0].corps.caption.includes(`https://m.me/${PAGE}?ref=produit-1032`) && e[0].corps.caption.includes('FCFA'), e[0]?.corps);
const post = e[0]?.reponse?.post_id;
await attendre(800); // le temps que la publication soit enregistrée (publication -> articles)
await vider();
t0 = Date.now();
await meta({ changes: [{ field: 'feed', value: { item: 'comment', verb: 'add', comment_id: `${post}_c1`, post_id: post, from: { id: '7000000000000002', name: 'Nadège Ewane' }, message: 'Prix svp ?', created_time: Math.floor(Date.now() / 1000) } }] });
e = await recus((a) => a.chemin === `/${PAGE}/messages` && a.corps?.recipient?.comment_id === `${post}_c1`);
const publique = await recus((a) => a.chemin === `/${post}_c1/comments`);
const texteReponse = e[0]?.corps.message.attachment?.payload.text ?? '';
verifier(`réponse privée au commentaire avec l'article et son prix (${e[0] ? e[0].t - t0 : '?'} ms)`, texteReponse.startsWith('Bonjour Nadège 👋') && texteReponse.includes('Montre femme argentée') && texteReponse.includes('FCFA') && e[0].corps.message.attachment.payload.buttons[0].payload === 'bayam:produit:1032', e[0]?.corps);
verifier('réponse publique courte sous le commentaire', /Nadège.*📩|📩/.test(publique[0]?.corps?.message ?? ''), publique[0]?.corps);
await vider();
await meta({ changes: [{ field: 'feed', value: { item: 'comment', verb: 'add', comment_id: `${post}_c2`, post_id: post, from: { id: '7000000000000002', name: 'Nadège Ewane' }, message: 'Dispo ?', created_time: Math.floor(Date.now() / 1000) } }] });
await meta({ changes: [{ field: 'feed', value: { item: 'comment', verb: 'add', comment_id: `${post}_c3`, post_id: post, from: { id: '7000000000000003', name: 'Paul' }, message: 'Ma sœur Clarisse Ngo', created_time: Math.floor(Date.now() / 1000) } }] });
await attendre(1500);
verifier('même personne sous la même publication : pas de deuxième réponse ; simple mention d\'une amie : rien', (await appels()).length === 0, (await appels()).map((a) => a.chemin));

console.log('\nAlbum de plusieurs articles, story, confirmation de commande');
await vider();
await fetch('http://canal-facebook:8092/interne/publications', { method: 'POST', headers: service, body: JSON.stringify({ vendeur: 4, produits: [1036, 1034, 1030], type: 'publication' }) });
e = await recus((a) => a.chemin === `/${PAGE}/feed`, 1, 10000);
const photosCachees = (await appels()).filter((a) => a.chemin === `/${PAGE}/photos` && a.corps?.published === 'false');
verifier('3 articles : 3 photos non publiées puis un album numéroté', photosCachees.length === 3 && e[0]?.corps.attached_media.length === 3 && e[0].corps.message.includes('3. Montre homme en acier'), e[0]?.corps);
await vider();
await fetch('http://canal-facebook:8092/interne/publications', { method: 'POST', headers: service, body: JSON.stringify({ vendeur: 4, produits: [1036], type: 'story' }) });
e = await recus((a) => a.chemin === `/${PAGE}/photo_stories`, 1, 10000);
const visuel = (await appels()).find((a) => a.chemin === `/${PAGE}/photos`);
verifier('story : visuel vertical avec le prix envoyé non publié, puis publié en story', visuel?.corps.published === 'false' && visuel.corps.octets > 30_000 && /^ph\d+$/.test(e[0]?.corps?.photo_id ?? ''), { visuel: visuel?.corps, story: e[0]?.corps });
await vider();
r = await fetch('http://canal-facebook:8092/interne/notifications', { method: 'POST', headers: service, body: JSON.stringify({ cle: 'essai-commande-fb-1', page: PAGE, client: CLIENT, texte: '✅ *Commande n° 1301 reçue* chez *Accessoires Akwa*\n1 × Bracelet jonc doré' }) });
e = await recus(envoisAuClient, 1, 6000);
verifier('confirmation de commande envoyée dans Messenger, sans les astérisques de WhatsApp', r.status === 202 && e[0]?.corps.message.text === '✅ Commande n° 1301 reçue chez Accessoires Akwa\n1 × Bracelet jonc doré', e[0]?.corps.message);

console.log(`\n${echecs.length ? `ÉCHECS : ${echecs.length} (${echecs.join(' ; ')})` : 'TOUT EST BON'}, ${reussis} vérification(s) réussie(s).`);
process.exit(echecs.length ? 1 : 0);
