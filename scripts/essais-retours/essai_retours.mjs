// Essai réel du service des retours, dans le réseau de la plateforme (lancé par scripts/essai_retours.sh) :
// route publique par Traefik (débit limité), regroupement des doublons, réponse WhatsApp au signaleur sur le
// téléphone d'essai, panne détectée puis rétablie avec alerte à l'équipe. Les sujets créés sont listés à la fin
// pour être effacés par le script.

const [jeton, cleWaha] = process.argv.slice(2);
const passage = Date.now().toString(36);
const H = { 'content-type': 'application/json', 'x-bayam-service': jeton };
const RETOURS = 'http://retours:8093';
const TRAEFIK = 'http://traefik';
const ACHETEUR = '237681010044@c.us';
const sujets = new Set();
let echecs = 0;

function verifier(nom, ok, details) {
  console.log(`${ok ? 'OK   ' : 'ÉCHEC'} ${nom}${ok ? '' : ` : ${JSON.stringify(details).slice(0, 400)}`}`);
  if (!ok) echecs++;
}
const attendre = (ms) => new Promise((f) => setTimeout(f, ms));
async function publier(ip, texte) {
  const r = await fetch(`${TRAEFIK}/api/retours`, {
    method: 'POST', headers: { 'content-type': 'application/json', 'cf-connecting-ip': ip, host: 'localhost', 'user-agent': `essai-${passage}` },
    body: JSON.stringify({ type: 'souci', texte, contexte: { essai: passage, version: '1.4.0' } }),
  });
  return { statut: r.status, corps: r.status === 201 ? await r.json() : await r.text() };
}

// 1. Deux personnes, deux formulations du même souci, depuis l'application : un seul sujet, deux personnes.
const a = await publier(`203.0.113.${10 + (Date.now() % 50)}`, `Essai technique ${passage} : le panier se vide quand je change de rayon`);
const b = await publier(`198.51.100.${10 + (Date.now() % 50)}`, `Essai technique ${passage} : mon panier se vide dès que je change de rayon`);
verifier('route publique : accusé avec numéro et message', a.statut === 201 && /^R-\d+$/.test(a.corps.numero) && a.corps.message.includes(a.corps.numero), a);
verifier('doublon reconnu : même sujet, 2 personnes, message « 1 autre personne »', b.statut === 201 && b.corps.sujet === a.corps.sujet && b.corps.signaleurs === 2 && /1 autre personne/.test(b.corps.message), b);
sujets.add(a.corps.sujet);

// 2. Débit limité : 3 envois par heure et par personne au plus, le quatrième est refusé.
const ip = `192.0.2.${10 + (Date.now() % 200)}`;
const statuts = [];
for (let i = 0; i < 4; i++) {
  const r = await publier(ip, `Essai technique ${passage} : envoi répété numéro ${i} pour tester la limite`);
  statuts.push(r.statut);
  if (r.statut === 201) sujets.add(r.corps.sujet);
}
verifier('débit limité : le 4e envoi de la même personne est refusé (429)', statuts.slice(0, 3).every((s) => s === 201) && statuts[3] === 429, statuts);

// 3. Entrées refusées sans toucher la base : type inconnu, texte trop long, route interne sans jeton depuis l'entrée.
const mauvais = await fetch(`${TRAEFIK}/api/retours`, { method: 'POST', headers: { 'content-type': 'application/json', host: 'localhost', 'cf-connecting-ip': '192.0.2.250' }, body: JSON.stringify({ type: 'souci', texte: 'x'.repeat(3000) }) });
const interne = await fetch(`${TRAEFIK}/interne/sujets`, { headers: { host: 'localhost' }, redirect: 'manual' });
verifier('texte trop long refusé (400) ; aucune route interne joignable par l’entrée', mauvais.status === 400 && interne.status !== 200, [mauvais.status, interne.status]);

// 4. Signalement WhatsApp avec contact, puis correction : le téléphone d'essai reçoit la bonne nouvelle.
const s = await (await fetch(`${RETOURS}/interne/signalements`, { method: 'POST', headers: H, body: JSON.stringify({
  type: 'souci', texte: `Essai technique ${passage} : la photo de l'article ne s'affiche pas`, canal: 'whatsapp', auteur: 'Téléphone d’essai',
  identifiant: '237681010044', vendeur: 3, contact: { canal: 'whatsapp', session: 'boutique-3', chat: ACHETEUR }, contexte: { essai: passage } }) })).json();
sujets.add(s.sujet);
const liste = await (await fetch(`${RETOURS}/interne/sujets?limite=500`, { headers: H })).json();
verifier('liste triée par urgence, sujet présent avec son score', liste.some((x) => x.id === s.sujet && x.score > 0) && liste.every((x, i) => i === 0 || liste[i - 1].score >= x.score), liste.length);
const avant = Math.floor(Date.now() / 1000);
const patch = await (await fetch(`${RETOURS}/interne/sujets/${s.sujet}`, { method: 'PATCH', headers: H, body: JSON.stringify({
  auteur: 'essai', statut: 'corrige', resolution: 'Essai technique', prevenir: true, message: `✅ Essai technique BAYAM ${passage} : le souci signalé est réglé, merci 🙏` }) })).json();
verifier('corrigé avec « prévenir » : 1 personne prévenue', patch.prevenus === 1 && patch.sujet.statut === 'corrige', patch);
let recu = false;
for (let i = 0; i < 12 && !recu; i++) {
  await attendre(5000);
  const m = await (await fetch(`http://waha:3000/api/acheteur-essai/chats/237654770063@c.us/messages?limit=5&downloadMedia=false`, { headers: { 'X-Api-Key': cleWaha } })).json();
  recu = m.some((x) => !x.fromMe && x.timestamp >= avant && (x.body || '').includes(passage));
}
verifier('réponse reçue sur le vrai téléphone d’essai (WhatsApp)', recu);
const detail = await (await fetch(`${RETOURS}/interne/sujets/${s.sujet}`, { headers: H })).json();
verifier('détail : signalement marqué prévenu, historique de la décision, aucune donnée de contact exposée',
  detail.signalements[0].prevenu && detail.historique.some((h) => h.action === 'modifie') && !JSON.stringify(detail).includes('237681010044'), detail.historique);

// 5. Panne détectée par une sonde : sujet ouvert (alerte à l'équipe), même sujet si elle se répète, puis rétabli.
const cle = `essai-${passage}`;
const i1 = await (await fetch(`${RETOURS}/interne/incidents`, { method: 'POST', headers: H, body: JSON.stringify({ cle, texte: `Essai technique ${passage} : sonde, ne rien faire`, fonction: 'whatsapp.catalogue' }) })).json();
const i2 = await (await fetch(`${RETOURS}/interne/incidents`, { method: 'POST', headers: H, body: JSON.stringify({ cle, texte: `Essai technique ${passage} : sonde, ne rien faire`, fonction: 'whatsapp.catalogue' }) })).json();
sujets.add(i1.sujet);
const retabli = await fetch(`${RETOURS}/interne/incidents/${cle}/retabli`, { method: 'POST', headers: H });
const r = retabli.status === 200 ? await retabli.json() : null;
verifier('panne : un seul sujet pour la même sonde, puis corrigé au rétablissement', i1.sujet === i2.sujet && r?.statut === 'corrige' && r?.resolution === 'Rétabli automatiquement', [i1, i2, r]);
const stats = await (await fetch(`${RETOURS}/interne/statistiques`, { headers: H })).json();
verifier('statistiques du tableau de bord', Array.isArray(stats.parStatut) && Array.isArray(stats.signalementsParJour), stats);

console.log(`SUJETS ${[...sujets].join(',')}`);
console.log(echecs ? `${echecs} ÉCHEC(S)` : 'TOUT EST BON');
process.exit(echecs ? 1 : 0);
