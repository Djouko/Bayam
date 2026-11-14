// Essai de sécurité rejoué à chaque changement (scripts/essai_securite.sh) : chaque attaque trouvée par l'audit
// (cahier-des-charges/recherche/S_audit_securite.md) est retentée et doit échouer. Lancé depuis le conteneur de la
// passerelle, le plus exposé : c'est la position d'un attaquant qui l'aurait compromis.

import net from 'node:net';

const [jeton, hote = 'localhost:8080'] = process.argv.slice(2);
const PUBLIC = { host: hote, 'cf-connecting-ip': '203.0.113.77' };
let echecs = 0;
function verifier(nom, ok, details) {
  console.log(`${ok ? 'OK   ' : 'ÉCHEC'} ${nom}${ok ? '' : ` : ${JSON.stringify(details).slice(0, 300)}`}`);
  if (!ok) echecs++;
}
const essai = (url, options = {}) => fetch(url, { redirect: 'manual', signal: AbortSignal.timeout(10_000), ...options }).catch((e) => ({ status: 0, erreur: String(e.cause?.code ?? e.message) }));

/** Échange brut sur un port (Redis, NATS) : ce que répond le serveur à une connexion sans identifiants. */
function brut(hote, port, envoi) {
  return new Promise((ok) => {
    const s = net.connect({ host: hote, port, timeout: 4000 }, () => s.write(envoi));
    let recu = '';
    s.on('data', (d) => { recu += d; if (recu.includes('\n') && recu.length > 20) s.end(); });
    s.on('timeout', () => s.destroy());
    s.on('error', (e) => ok(`erreur ${e.code}`));
    s.on('close', () => ok(recu));
  });
}

// S-01 : la porte vers Docker (variables d'environnement de tous les conteneurs) est hors d'atteinte.
const docker = await essai('http://proxy-docker:2375/containers/json');
verifier('S-01 porte Docker de Traefik injoignable depuis un service', docker.status === 0, docker);
const docker2 = await essai('http://proxy-docker-veilleur:2375/containers/json');
verifier('S-01 porte Docker du veilleur injoignable depuis un service', docker2.status === 0, docker2);

// S-02 : Redis et NATS refusent toute connexion sans identifiants.
const redis = await brut('redis', 6379, 'PING\r\n');
verifier('S-02 Redis sans mot de passe : refusé (NOAUTH)', /NOAUTH/.test(redis), redis);
const nats = await brut('nats', 4222, 'CONNECT {"verbose":false,"pedantic":false}\r\nPING\r\n');
verifier('S-02 NATS sans compte : refusé (Authorization Violation)', /Authorization Violation/i.test(nats), nats.slice(0, 200));

// S-04 : routes internes de WordPress fermées à l'entrée publique, quelle que soit l'écriture.
for (const chemin of ['/wp-json/bayam/v1/interne/boutiques/3', '/wp-json/Bayam/v1/INTERNE/boutiques/3', '/index.php/wp-json/bayam/v1/interne/boutiques/3',
  '/?rest_route=/BAYAM/v1/interne/boutiques/3', '/xmlrpc.php', '/XMLRPC.php']) {
  const r = await essai(`http://traefik${chemin}`, { headers: { ...PUBLIC, 'x-bayam-service': jeton } });
  verifier(`S-04 ${chemin} par l'entrée publique, même avec le jeton : 403`, r.status === 403, r.status);
}
const direct = await essai('http://wordpress/wp-json/bayam/v1/interne/boutiques/3', { headers: { 'x-bayam-service': jeton, 'x-bayam-entree': 'publique' } });
verifier('S-04 WordPress refuse un appel interne marqué « entrée publique », même avec le jeton', direct.status === 401, direct.status);
const sain = await essai('http://wordpress/wp-json/bayam/v1/interne/boutiques/3', { headers: { 'x-bayam-service': jeton } });
verifier('S-04 appel interne direct d’un service : toujours accepté', sain.status === 200, sain.status);
const marque = await essai('http://traefik/wp-json/wc/store/v1/cart', { headers: { ...PUBLIC, 'x-bayam-entree': 'non' } });
verifier('S-04 l’entrée publique pose sa marque (route publique toujours servie)', marque.status === 200, marque.status);

// S-05 : limite de débit sur les connexions (wp-login) toujours active.
// Envois simultanés : la limite se mesure sur la rafale, pas sur la vitesse de réponse de WordPress.
const statuts = (await Promise.all(Array.from({ length: 14 }, () => essai('http://traefik/wp-login.php',
  { method: 'POST', headers: { ...PUBLIC, 'content-type': 'application/x-www-form-urlencoded' }, body: 'log=x&pwd=y' })))).map((r) => r.status);
verifier('S-05 essais de mot de passe en rafale : bloqués (429) avant le 12e', statuts.includes(429), statuts);

// S-06 : comptes non énumérables.
const utilisateurs = await essai('http://traefik/wp-json/wp/v2/users', { headers: PUBLIC });
verifier('S-06 liste des comptes refusée aux visiteurs', utilisateurs.status === 404, utilisateurs.status);
const auteur = await essai('http://traefik/?author=1', { headers: PUBLIC });
verifier('S-06 /?author=1 ne révèle pas l’identifiant', !(auteur.headers?.get('location') ?? '').includes('/author/'), auteur.headers?.get('location'));

// S-07 : en-têtes de sécurité.
const page = await essai('http://traefik/shop/', { headers: PUBLIC });
const h = (n) => page.headers?.get(n) ?? '';
verifier('S-07 en-têtes HSTS, CSP, COOP, nosniff, anti-cadre', /max-age=31536000/.test(h('strict-transport-security')) && /frame-ancestors/.test(h('content-security-policy'))
  && h('cross-origin-opener-policy') && h('x-content-type-options') === 'nosniff' && h('x-frame-options') === 'DENY' && !h('x-powered-by'), Object.fromEntries(page.headers ?? []));

// S-08 : retour de paiement sans la clé de commande : rien de révélé.
const cinetpay = await essai('http://traefik/wp-json/bayam/v1/cinetpay/retour/1', { headers: PUBLIC });
verifier('S-08 retour CinetPay sans clé : renvoi vers l’accueil seulement', !(cinetpay.headers?.get('location') ?? '').includes('key='), cinetpay.headers?.get('location'));

// S-11 : envoi géant sur l'adresse des événements Meta : refus propre avant toute lecture.
const geant = await essai('http://traefik/webhooks/facebook', { method: 'POST', headers: { ...PUBLIC, 'content-type': 'application/json' }, body: 'x'.repeat(6 * 1024 * 1024) });
verifier('S-11 corps de 6 Mo sur /webhooks/facebook : 413', geant.status === 413, geant.status);

// S-14 : fichiers techniques jamais servis.
for (const f of ['/wp-content/plugins/bayam-whatsapp/composer.json', '/wp-content/plugins/bayam-whatsapp/composer.lock', '/readme.html', '/wp-content/plugins/bayam-whatsapp/vendor/autoload.php']) {
  const r = await essai(`http://traefik${f}`, { headers: PUBLIC });
  verifier(`S-14 ${f} : 404`, r.status === 404, r.status);
}

// Retours : la route publique n'accepte que l'envoi (pas de lecture de la base).
const lecture = await essai('http://traefik/api/retours', { headers: PUBLIC });
verifier('route publique des retours : lecture impossible', lecture.status !== 200, lecture.status);

console.log(echecs ? `${echecs} ÉCHEC(S)` : 'TOUT EST BON');
process.exit(echecs ? 1 : 0);
