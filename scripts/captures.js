// Captures d'écran de la plateforme locale (site, annuaire, boutique, espace vendeur WCFM connecté).
// Usage : NODE_PATH=../cahier-des-charges/src/node_modules node scripts/captures.js   (depuis plateforme-locale/)
const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer-core');

const RACINE = path.join(__dirname, '..');
const SORTIE = path.join(RACINE, 'captures');
const URL = 'http://localhost:8080';
const env = Object.fromEntries(fs.readFileSync(path.join(RACINE, '.env'), 'utf8').split(/\r?\n/)
  .filter(l => l.includes('=') && !l.startsWith('#')).map(l => [l.slice(0, l.indexOf('=')), l.slice(l.indexOf('=') + 1)]));

(async () => {
  fs.mkdirSync(SORTIE, { recursive: true });
  const browser = await puppeteer.launch({ executablePath: 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe', headless: true });
  try {
    const page = await browser.newPage();
    await page.setViewport({ width: 1440, height: 900 });
    for (const [nom, chemin] of [['1_boutique', '/shop/'], ['2_annuaire_boutiques', '/boutiques/'], ['3_boutique_mode_aicha', '/store/aicha/']]) {
      await page.goto(URL + chemin, { waitUntil: 'networkidle2', timeout: 120000 });
      await page.screenshot({ path: path.join(SORTIE, `${nom}.png`) });
    }
    await page.goto(URL + '/wp-login.php', { waitUntil: 'networkidle2' });
    await page.type('#user_login', 'aicha');
    await page.type('#user_pass', env.WP_VENDOR_PASSWORD);
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('#wp-submit')]);
    for (const [nom, chemin] of [['4_espace_vendeur', '/store-manager/'], ['5_espace_vendeur_produits', '/store-manager/products/']]) {
      await page.goto(URL + chemin, { waitUntil: 'networkidle2', timeout: 120000 });
      await page.screenshot({ path: path.join(SORTIE, `${nom}.png`) });
    }
    console.log('captures écrites dans', SORTIE);
  } finally {
    await browser.close();
  }
})().catch(e => { console.error(e.message); process.exit(1); });
