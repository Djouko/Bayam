// Faux Graph API de Meta pour l'essai de bout en bout : répond comme Graph (formes documentées), garde chaque appel,
// vérifie la preuve du secret (appsecret_proof). GET /__appels rend les appels reçus, DELETE /__appels les efface.
import { createHmac } from 'node:crypto';
import { createServer } from 'node:http';

const SECRET = process.env.SECRET;
const PAGE = '900000000000004';
let appels = [];
// Numéros de publication propres à chaque démarrage, comme les vrais identifiants de Meta.
let n = Math.floor(Date.now() / 1000) % 1_000_000 * 1000;

createServer((req, res) => {
  const morceaux = [];
  req.on('data', (m) => morceaux.push(m));
  req.on('end', () => {
    const url = new URL(req.url, 'http://faux-graph');
    const chemin = url.pathname.replace(/^\/v\d+\.\d+/, '');
    const envoyer = (statut, d) => {
      if (chemin !== '/__appels' && appels.length) appels[appels.length - 1].reponse = d;
      res.writeHead(statut, { 'content-type': 'application/json' }).end(JSON.stringify(d));
    };
    if (chemin === '/__appels') {
      if (req.method === 'DELETE') appels = [];
      return envoyer(200, appels);
    }
    const brut = Buffer.concat(morceaux);
    const type = String(req.headers['content-type'] ?? '');
    let corps = null;
    if (type.startsWith('application/json')) corps = JSON.parse(brut.toString('utf8'));
    if (type.startsWith('multipart/form-data')) {
      const texte = brut.toString('latin1');
      corps = { multipart: true, octets: brut.length, published: /name="published"\r\n\r\n(\w+)/.exec(texte)?.[1], caption: Buffer.from(/name="caption"\r\n\r\n([\s\S]*?)\r\n--/.exec(texte)?.[1] ?? '', 'latin1').toString('utf8'), jpeg: texte.includes('\xff\xd8\xff') };
    }
    const jeton = url.searchParams.get('access_token');
    const preuve = jeton ? url.searchParams.get('appsecret_proof') === createHmac('sha256', SECRET).update(jeton).digest('hex') : null;
    appels.push({ methode: req.method, chemin, jeton, preuve, requete: Object.fromEntries([...url.searchParams].filter(([k]) => !['access_token', 'appsecret_proof', 'client_secret'].includes(k))), corps, t: Date.now() });
    n++;
    if (chemin.endsWith('/subscriptions')) return envoyer(200, req.method === 'GET' ? { data: [] } : { success: true });
    if (chemin === '/debug_token') return envoyer(200, { data: { scopes: ['pages_show_list', 'business_management', 'pages_messaging', 'pages_manage_metadata', 'pages_read_engagement', 'pages_read_user_content', 'pages_manage_engagement', 'pages_manage_posts'] } });
    if (chemin === '/oauth/access_token') return envoyer(200, { access_token: url.searchParams.get('code') ? 'COURT' : 'LONG-UTILISATEUR', token_type: 'bearer' });
    if (chemin === '/me/accounts') return envoyer(200, { data: [
      { id: PAGE, name: 'Accessoires Akwa Douala', access_token: 'JETON-PAGE-ESSAI', tasks: ['MESSAGING', 'CREATE_CONTENT', 'MODERATE'] },
      { id: '900000000000005', name: 'Page perso du vendeur', access_token: 'JETON-AUTRE-PAGE', tasks: ['MESSAGING', 'CREATE_CONTENT'] },
    ] });
    if (chemin.endsWith('/subscribed_apps')) return envoyer(200, { success: true });
    if (chemin === '/me/messenger_profile') return envoyer(200, { result: 'success' });
    // Réponse privée à un commentaire : Meta renvoie l'identifiant Messenger de la personne qui a commenté.
    if (chemin.endsWith('/messages')) return envoyer(200, { recipient_id: corps?.recipient?.id ?? (corps?.recipient?.comment_id ? '7000000000000002' : ''), message_id: `m.faux.${n}` });
    if (chemin.endsWith('/comments')) return envoyer(200, { id: `reponse_${n}` });
    if (chemin.endsWith('/photos')) return envoyer(200, corps?.published === 'true' ? { id: `ph${n}`, post_id: `${PAGE}_70${n}` } : { id: `ph${n}` });
    if (chemin.endsWith('/feed')) return envoyer(200, { id: `${PAGE}_80${n}` });
    if (chemin.endsWith('/photo_stories')) return envoyer(200, { success: true, post_id: 90000 + n });
    envoyer(400, { error: { code: 100, message: `chemin inconnu du faux Graph : ${chemin}` } });
  });
}).listen(9000, () => console.log('faux Graph prêt'));
