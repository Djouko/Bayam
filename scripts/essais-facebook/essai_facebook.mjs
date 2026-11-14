// Essai de bout en bout du canal Facebook, avec la vraie chaîne BAYAM (Traefik -> passerelle -> NATS -> canal-facebook
// -> WordPress) et un faux Graph à la place de Meta. Boutique 4 (Accessoires Akwa, sans WhatsApp relié : aucun envoi réel).
import { createHmac } from 'node:crypto';

const { SECRET, VERIFICATION, JETON_SERVICE, CLE_LIENS } = process.env;
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

/** Attend que le faux Graph ait reçu au moins n appels qui vérifient le filtre (15 s au plus, poste chargé) ; rend ces appels. */
async function recus(filtre, n = 1, delai = 15000) {
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
r = await fetch('http://canal-facebook:8092/interne/vendeurs/4/pages', { method: 'POST', headers: service, body: JSON.stringify({ jeton: 'jeton-court-essai', pages: [PAGE] }) });
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

console.log('\nConnexion par la fenêtre Facebook : le vendeur coche ses Pages');
const echeance = Math.floor(Date.now() / 1000) + 600;
const signe = `v=4&e=${echeance}&s=${createHmac('sha256', CLE_LIENS).update(`facebook:4.${echeance}`).digest('hex')}`;
r = await fetch(`http://traefik/facebook/connexion?${signe}`, { headers: ENTETES_PUBLICS, redirect: 'manual' });
const etat = new URL(r.headers.get('location') ?? 'http://x').searchParams.get('state') ?? '';
const cookie = (r.headers.get('set-cookie') ?? '').split(';')[0];
verifier('lien de connexion : redirection vers Facebook avec un état aléatoire, cookie lié au navigateur (HttpOnly, Secure, SameSite)',
  r.status === 302 && /^[\w-]{24}$/.test(etat) && cookie === `bayam_fb_etat=${etat}` && /HttpOnly; Secure; SameSite=Lax/.test(r.headers.get('set-cookie') ?? ''), [r.status, etat]);
r = await fetch(`http://traefik/facebook/retour?code=CODE-ESSAI&state=${etat}`, { headers: ENTETES_PUBLICS });
verifier('retour de Facebook dans un autre navigateur (sans le cookie) : refusé', r.status === 403);
r = await fetch(`http://traefik/facebook/connexion?${signe}`, { headers: ENTETES_PUBLICS, redirect: 'manual' });
const etat2 = new URL(r.headers.get('location') ?? 'http://x').searchParams.get('state') ?? '';
r = await fetch(`http://traefik/facebook/retour?code=CODE-ESSAI&state=${etat2}`, { headers: { ...ENTETES_PUBLICS, cookie: `bayam_fb_etat=${etat2}` } });
const choix = await r.text();
const cle = /name="c" value="([\w-]{24})"/.exec(choix)?.[1];
verifier('compte qui gère deux Pages : le vendeur choisit, la Page déjà reliée est cochée, l\'autre non',
  r.status === 200 && choix.includes('Quelles Pages relier') && /value="900000000000004" checked/.test(choix) && /value="900000000000005">/.test(choix) && Boolean(cle), choix.slice(0, 300));
r = await fetch(`http://traefik/facebook/choisir?c=${cle}&page=${PAGE}`, { headers: ENTETES_PUBLICS });
const reliees = await (await fetch('http://canal-facebook:8092/interne/vendeurs/4/pages', { headers: service })).json();
verifier('seule la Page cochée est reliée ; le lien de choix ne sert qu\'une fois',
  (await r.text()).includes('Page reliée à BAYAM') && reliees.map((p) => p.page).join() === PAGE
  && (await fetch(`http://traefik/facebook/choisir?c=${cle}&page=900000000000005`, { headers: ENTETES_PUBLICS })).status === 403, reliees);

console.log('\nMessenger : un client écrit à la Page');
await vider();
let t0 = Date.now();
await texte('Bonsoir');
let e = await recus(envoisAuClient, 2);
const delaiMenu = e[0] ? e[0].t - t0 : null;
verifier(`premier message (« Bonsoir ») : carrousel des rayons, puis la même liste numérotée en texte (${delaiMenu} ms)`,
  e[0]?.corps.message.attachment?.payload.elements?.some((x) => x.buttons?.[0]?.payload === 'bayam:rayon:72:1') && e[0].corps.message.metadata === 'bayam' && e[0].corps.messaging_type === 'RESPONSE'
  && /Bienvenue chez Accessoires Akwa[\s\S]*\n1\. /.test(e[1]?.corps.message.text ?? ''), e.map((x) => x.corps.message));
await vider();
await texte('Merci');
await attendre(1500);
verifier('« Merci » en cours de conversation : rien (ni menu ni accusé)', (await appels()).filter(envoisAuClient).length === 0);
await texte('le 1');
e = await recus(envoisAuClient, 2);
verifier('réponse « le 1 » (messagerie de Facebook, sans cartes) : le premier rayon de la liste, en cartes et en texte',
  Boolean(e[0]?.corps.message.attachment?.payload.elements?.length) && /\n1\. .+FCFA/.test(e[1]?.corps.message.text ?? ''), e.map((x) => x.corps.message));
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
verifier('« Commander » : carte avec le bouton vers le lien personnel, et le même lien en texte (lisible partout)',
  bouton?.type === 'web_url' && /\/commander\/[A-Za-z0-9_-]{24}$/.test(bouton.url) && (e[1]?.corps.message.text ?? '').includes(bouton.url), e.map((x) => x.corps.message));
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
const accuses = (await appels()).filter(envoisAuClient).filter((a) => (a.corps.message.text ?? '').startsWith('Merci 🙏'));
verifier('question libre : un seul accusé de réception pour deux messages à la suite', accuses.length === 1, accuses.map((a) => a.corps.message.text));
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

console.log('\nEspace vendeur dans Messenger (le même menu et les mêmes commandes que sur WhatsApp)');
const VENDEUR = '7000000000000009';
const versVendeur = (a) => a.methode === 'POST' && a.chemin === `/${PAGE}/messages` && a.corps?.recipient?.id === VENDEUR;
const parVendeur = (contenu) => meta({ messaging: [{ sender: { id: VENDEUR }, recipient: { id: PAGE }, timestamp: Date.now(), ...contenu }] });
const echeanceEspace = Math.floor(Date.now() / 1000) + 3600;
const signature = createHmac('sha256', CLE_LIENS).update(`fbvendeur:4.${echeanceEspace}`).digest('hex').slice(0, 32);
await vider();
await parVendeur({ referral: { ref: `vendeur-4-${echeanceEspace}-${signature}`, source: 'SHORTLINK' } });
e = await recus(versVendeur);
verifier('lien signé ouvert : ce compte Messenger devient l\'espace vendeur', (e[0]?.corps.message.text ?? '').includes('espace vendeur BAYAM est prêt'), e[0]?.corps.message);
await vider();
await parVendeur({ message: { mid: `m.essai.${PASSAGE}.${++sequence}`, text: 'bayam' } });
e = await recus(versVendeur);
const menuTexte = e[0]?.corps.message.text ?? '';
verifier('« bayam » du vendeur : son menu, en texte numéroté lisible partout', menuTexte.includes('1. 📦 Ajouter du stock') && menuTexte.includes('Répondez par le numéro') && !menuTexte.includes('\u2063'), menuTexte.slice(0, 200));
await vider();
await parVendeur({ message: { mid: `m.essai.${PASSAGE}.${++sequence}`, text: '5' } });
e = await recus(versVendeur);
verifier('le vendeur répond « 5 » : sous-menu du bilan', (e[0]?.corps.message.text ?? '').includes('Quelle période'), e[0]?.corps.message);
await vider();
await parVendeur({ message: { mid: `m.essai.${PASSAGE}.${++sequence}`, text: 'bayam stock' } });
e = await recus(versVendeur, 1, 30000);
verifier('« bayam stock » du vendeur : réponse du Copilote dans Messenger, sans la mise en forme WhatsApp', Boolean(e[0]?.corps.message.text) && !/\*[^*\n]+\*/.test(e[0].corps.message.text), e[0]?.corps.message);
await vider();
await meta({ messaging: [{ sender: { id: '7000000000000008' }, recipient: { id: PAGE }, timestamp: Date.now(), referral: { ref: `vendeur-4-${echeanceEspace}-${'0'.repeat(32)}`, source: 'SHORTLINK' } }] });
e = await recus((a) => a.chemin === `/${PAGE}/messages` && a.corps?.recipient?.id === '7000000000000008');
verifier('lien falsifié : refusé, ce compte reste un client', (e[0]?.corps.message.text ?? '').includes('expiré ou n\'est pas le vôtre'), e[0]?.corps.message);
await vider();
await meta({ messaging: [{ sender: { id: '7000000000000007' }, recipient: { id: PAGE }, timestamp: Date.now(), referral: { ref: `vendeur-4-${echeanceEspace}-${signature}`, source: 'SHORTLINK' } }] });
e = await recus((a) => a.chemin === `/${PAGE}/messages` && a.corps?.recipient?.id === '7000000000000007');
verifier('vrai lien transféré à un autre compte : refusé (usage unique)', (e[0]?.corps.message.text ?? '').includes('expiré ou n\'est pas le vôtre'), e[0]?.corps.message);

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
const texteReponse = e[0]?.corps.message.text ?? '';
verifier(`réponse privée au commentaire : l'article, son prix et des choix numérotés en texte (${e[0] ? e[0].t - t0 : '?'} ms)`,
  texteReponse.startsWith('Bonjour Nadège 👋') && texteReponse.includes('Montre femme argentée') && texteReponse.includes('FCFA') && texteReponse.includes('1. 🛒 La photo et votre lien de commande'), e[0]?.corps);
verifier('réponse publique courte sous le commentaire', /Nadège.*📩|📩/.test(publique[0]?.corps?.message ?? ''), publique[0]?.corps);
await vider();
await meta({ changes: [{ field: 'feed', value: { item: 'comment', verb: 'add', comment_id: `${post}_c2`, post_id: post, from: { id: '7000000000000002', name: 'Nadège Ewane' }, message: 'Dispo ?', created_time: Math.floor(Date.now() / 1000) } }] });
await meta({ changes: [{ field: 'feed', value: { item: 'comment', verb: 'add', comment_id: `${post}_c3`, post_id: post, from: { id: '7000000000000003', name: 'Paul' }, message: 'Ma sœur Clarisse Ngo', created_time: Math.floor(Date.now() / 1000) } }] });
await attendre(1500);
verifier('même personne sous la même publication : pas de deuxième réponse ; simple mention d\'une amie : rien', (await appels()).length === 0, (await appels()).map((a) => a.chemin));
await vider();
await meta({ messaging: [{ sender: { id: '7000000000000002' }, recipient: { id: PAGE }, timestamp: Date.now(), message: { mid: `m.essai.${PASSAGE}.${++sequence}`, text: '1' } }] });
e = await recus((a) => a.chemin === `/${PAGE}/messages` && a.corps?.recipient?.id === '7000000000000002', 2);
verifier('la personne qui a commenté répond « 1 » dans Messenger : la photo et son lien de commande',
  e[0]?.corps.message.attachment?.payload.elements?.[0]?.title === 'Montre femme argentée' && /\/commander\//.test(e[1]?.corps.message.text ?? ''), e.map((x) => x.corps.message));

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
