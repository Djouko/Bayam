// Menu du vendeur dans « Message à moi-même » : un seul mot à retenir, « bayam ». Le menu s'ouvre en liste ; chaque
// action se fait en touchant un choix (ou en répondant par son numéro), et les plus fréquentes (stock, vente, nouvel
// article) se font pas à pas. L'action finale est une commande du Copilote, exécutée par l'extension WordPress : celui
// qui la connaît peut toujours la taper directement (« bayam +5 polo »). Fonctions sans envoi : le service envoie.

import type { PageArticles } from '../commun/commerce.ts';
import { couper, fcfa, liste, LIMITES, type EnvoiPret, type Ligne } from './catalogue.ts';
import { sansAccents } from './appel.ts';
import { MARQUE } from '../passerelle/filtre.ts';


type Action = 'stock' | 'vente' | 'publier';
type Etape =
  | 'menu' | 'bilan' | 'boutique' | 'publier_quoi' | 'publier_ou'
  | 'article' | 'recherche' | 'variante' | 'variante_libre' | 'quantite' | 'quantite_libre'
  | 'prix' | 'prix_libre' | 'paiement' | 'acompte' | 'client'
  | 'photo' | 'nom' | 'prix_nouveau' | 'quantites_nouveau';

/** Où en est le vendeur : l'étape, les choix affichés (pour répondre par numéro) et ce qu'il a déjà choisi. */
export type Etat = {
  etape: Etape;
  options: string[];
  action?: Action;
  article?: { id: number; nom: string; prix: number; variable: boolean };
  variantes?: string[];
  variante?: string;
  quantite?: number;
  montant?: number | null;
  mode?: string;
  acompte?: number;
  quoi?: string;
  page?: number;
  recherche?: string;
  photo?: string;
  nom?: string;
  prix?: number;
};

export type Entree = { type: 'choix'; id: string } | { type: 'texte'; texte: string } | { type: 'photo'; url: string };

/** Résultat d'un pas : messages à envoyer, état suivant (null : terminé), commande du Copilote à exécuter. */
export type Pas = { envois: EnvoiPret[]; etat: Etat | null; commande?: { texte: string; photo?: string } };

/** Ce dont le menu a besoin du commerce : les articles du vendeur, par page ou par recherche. */
export type SourceArticles = (recherche: string, page: number) => Promise<PageArticles>;

const PAR_PAGE = 8;
const ID = (code: string) => `bayam:v:${code}`;
const ANNULER = '(« annuler » pour arrêter)';

/** Commandes du Copilote derrière les choix directs (aucune question à poser). */
const COMMANDES: Record<string, string> = {
  commandes: 'commandes', voir: 'stock', dettes: 'dettes', aide: 'aide',
  bilan_jour: 'bilan', bilan_hier: 'bilan hier', bilan_semaine: 'bilan semaine', bilan_mois: 'bilan mois',
  lien: 'boutique', politique: 'politique', facebook: 'facebook', occupe: 'assistant', reprends: 'assistant reprends', raccourcis: 'raccourcis', support: 'support',
};

/** Liste numérotée : le vendeur touche « Choisir » ou répond par le numéro ; les identifiants sont gardés dans l'état. */
function choisir(titre: string, texte: string, sections: { titre: string; lignes: Ligne[] }[], etat: Omit<Etat, 'options'>): Pas {
  let n = 0;
  const numerotees = sections.map((s) => ({
    titre: s.titre,
    lignes: s.lignes.map((l) => ({ ...l, titre: couper(`${++n}. ${l.titre}`, LIMITES.titreLigne) })),
  }));
  return {
    envois: [liste(titre, `${texte}\n\nTouchez *Choisir*, ou répondez par le numéro.`, 'Choisir', 'BAYAM · « annuler » pour arrêter', numerotees)],
    etat: { ...etat, options: numerotees.flatMap((s) => s.lignes.map((l) => l.id)) },
  };
}

function question(texte: string, etat: Omit<Etat, 'options'>): Pas {
  return { envois: [{ type: 'texte', texte: `${MARQUE}${texte}\n${ANNULER}` }], etat: { ...etat, options: [] } };
}

function fin(texte: string): Pas {
  return { envois: [{ type: 'texte', texte: `${MARQUE}${texte}` }], etat: null };
}

function executer(texte: string, photo?: string): Pas {
  return { envois: [], etat: null, commande: photo ? { texte, photo } : { texte } };
}

export function menu(): Pas {
  const l = (code: string, titre: string, description: string): Ligne => ({ id: ID(code), titre, description });
  return choisir('Menu BAYAM', 'Que voulez-vous faire ? 👇', [
    { titre: 'Au quotidien', lignes: [
      l('stock', '📦 Ajouter du stock', 'Une arrivée de marchandise'),
      l('vente', '💵 Noter une vente', 'Espèces, MoMo, OM, crédit, acompte'),
      l('nouveau', '➕ Nouvel article', 'Photo, nom, prix, quantités'),
      l('commandes', '🧾 Mes commandes', 'À payer, à préparer'),
      l('bilan', '📊 Mon bilan', 'Aujourd\'hui, hier, semaine, mois'),
    ] },
    { titre: 'Boutique', lignes: [
      l('voir', '🔎 Voir mon stock', 'Quantités par article et par taille'),
      l('publier', '📣 Publier', 'Groupes, chaîne, statut, Facebook'),
      l('dettes', '📝 Dettes des clients', 'Qui vous doit combien'),
      l('boutique', '⚙️ Ma boutique', 'Lien, paiement, Facebook, assistant'),
      l('aide', '📚 Toutes les actions', 'Et les commandes rapides'),
    ] },
  ], { etape: 'menu' });
}

function sousMenu(code: 'bilan' | 'boutique'): Pas {
  const retour = { id: ID('menu'), titre: '↩️ Retour au menu' };
  if (code === 'bilan') {
    return choisir('Mon bilan', 'Quelle période ?', [{ titre: 'Période', lignes: [
      { id: ID('bilan_jour'), titre: 'Aujourd\'hui' }, { id: ID('bilan_hier'), titre: 'Hier' },
      { id: ID('bilan_semaine'), titre: 'Cette semaine' }, { id: ID('bilan_mois'), titre: 'Ce mois' }, retour,
    ] }], { etape: 'bilan' });
  }
  return choisir('Ma boutique', 'Que voulez-vous voir ou régler ?', [{ titre: 'Boutique', lignes: [
    { id: ID('lien'), titre: '🔗 Lien de ma boutique', description: 'À mettre dans vos biographies' },
    { id: ID('politique'), titre: '💳 Paiement, livraison', description: 'Vos conditions, vos numéros MoMo et OM' },
    { id: ID('facebook'), titre: '📘 Ma Page Facebook', description: 'La relier à BAYAM' },
    { id: ID('occupe'), titre: '🤖 Je suis occupé', description: 'L\'assistant répond à vos clients' },
    { id: ID('reprends'), titre: '👋 Je reprends la main', description: 'L\'assistant s\'arrête' },
    { id: ID('raccourcis'), titre: '🧠 Mes raccourcis', description: 'Ce que BAYAM a appris de vous' },
    { id: ID('support'), titre: '🆘 Aide de l\'équipe', description: 'Contacter BAYAM' },
    retour,
  ] }], { etape: 'boutique' });
}

/** Liste des articles (huit par page), avec leur stock, pour choisir celui du stock, de la vente ou de la publication. */
export function listeArticles(etat: Etat, page: PageArticles, numero: number): Pas {
  const titres = { stock: 'Ajouter du stock', vente: 'Enregistrer une vente', publier: 'Publier un article' };
  const base = { etape: 'article' as const, action: etat.action, page: numero, recherche: etat.recherche };
  if (!page.articles.length) {
    return etat.recherche
      ? question(`Aucun article ne correspond à « ${etat.recherche} ». Tapez un autre nom.`, { ...base, etape: 'recherche', recherche: '' })
      : fin('Vous n\'avez encore aucun article. Choisissez « Nouvel article » dans le menu (tapez *bayam*).');
  }
  const lignes: Ligne[] = page.articles.map((a) => ({ id: ID(`art:${a.id}`), titre: a.nom, description: etatStock(a) }));
  const suite = page.total > numero * PAR_PAGE;
  if (suite) {
    lignes.push({ id: ID(`page:${numero + 1}`), titre: '➡️ Articles suivants', description: `${page.total - numero * PAR_PAGE} de plus` });
  }
  lignes.push({ id: ID('chercher'), titre: '🔎 Chercher par nom', description: 'Tapez une partie du nom' });
  const texte = etat.recherche ? `Articles pour « ${etat.recherche} » : lequel ?` : `Quel article ? (${page.total} au total)`;
  return choisir(titres[etat.action ?? 'stock'], texte, [{ titre: 'Articles', lignes }], base);
}

function etatStock(a: PageArticles['articles'][number]): string {
  if (a.variable) {
    const lignes = a.variantes.map((v) => `${v.nom} : ${v.stock ?? '?'}`);
    return lignes.length ? lignes.join(' · ') : 'Sans variante';
  }
  if (!a.en_stock) {
    return `Épuisé · ${fcfa(a.prix)}`;
  }
  return `${a.stock === null ? 'En stock' : `Stock : ${a.stock}`} · ${fcfa(a.prix)}`;
}

function quantites(etat: Etat): Pas {
  const vente = etat.action === 'vente';
  const valeurs = vente ? [1, 2, 3, 4, 5] : [1, 2, 3, 5, 10, 20];
  const nom = nomComplet(etat);
  return choisir(vente ? 'Quantité vendue' : 'Quantité reçue', vente ? `Combien de *${nom}* vendus ?` : `Combien de *${nom}* ajoutez-vous au stock ?`, [{ titre: 'Quantité', lignes: [
    ...valeurs.map((q) => ({ id: ID(`q:${q}`), titre: vente ? `${q}` : `+${q}` })),
    { id: ID('q:autre'), titre: 'Autre nombre', description: 'Vous le tapez' },
  ] }], { ...etat, etape: 'quantite' });
}

function nomComplet(etat: Etat): string {
  return `${etat.article?.nom ?? ''}${etat.variante ? ` (${etat.variante})` : ''}`;
}

function prixVente(etat: Etat): Pas {
  const q = etat.quantite ?? 1;
  const catalogue = etat.article && !etat.article.variable ? ` : ${fcfa(etat.article.prix * q)}` : '';
  return choisir('Prix de la vente', `${q} × *${nomComplet(etat)}* : à quel prix ?`, [{ titre: 'Prix', lignes: [
    { id: ID('prix:catalogue'), titre: 'Prix normal', description: catalogue ? `${catalogue.slice(3)}, le prix de votre boutique` : 'Le prix de votre boutique' },
    { id: ID('prix:autre'), titre: 'Autre prix', description: 'Prix négocié : vous tapez le total' },
  ] }], { ...etat, etape: 'prix' });
}

function paiement(etat: Etat): Pas {
  return choisir('Paiement', 'Comment le client a-t-il payé ?', [{ titre: 'Paiement', lignes: [
    { id: ID('pay:cash'), titre: '💵 Espèces' },
    { id: ID('pay:momo'), titre: '📱 MTN MoMo' },
    { id: ID('pay:om'), titre: '🟠 Orange Money' },
    { id: ID('pay:credit'), titre: '📝 À crédit', description: 'Il paiera plus tard' },
    { id: ID('pay:acompte_momo'), titre: 'Acompte par MoMo', description: 'Une partie payée, le reste plus tard' },
    { id: ID('pay:acompte_om'), titre: 'Acompte par OM', description: 'Une partie payée, le reste plus tard' },
    { id: ID('pay:acompte_cash'), titre: 'Acompte en espèces', description: 'Une partie payée, le reste plus tard' },
  ] }], { ...etat, etape: 'paiement' });
}

/** Commande « vendu » du Copilote, dans l'ordre qu'il lit : article, variante, quantité, prix, paiement, acompte, client. */
export function commandeVente(etat: Etat, client = ''): string {
  return ['vendu', `#${etat.article?.id}`, etat.variante ?? '', `x${etat.quantite ?? 1}`, etat.montant ? String(etat.montant) : '', etat.mode ?? 'cash',
    etat.acompte ? `acompte ${etat.acompte}` : '', client].filter(Boolean).join(' ').replace(/,/g, ' ').replace(/\s+/g, ' ');
}

/** Montant tapé comme on le dit : « 15000 », « 15 000 », « 15.000 F », « 15k », « 15 mille ». */
export function montant(texte: string): number | null {
  const m = /^(\d{1,3}(?:[ .\u202f\u00a0]?\d{3})*|\d+)\s*(k|mille)?\s*(f|fcfa|frs|francs)?$/i.exec(texte.trim());
  if (!m) {
    return null;
  }
  const n = Number(m[1].replace(/[ .\u202f\u00a0]/g, '')) * (m[2] ? 1000 : 1);
  return n > 0 && n < 100_000_000 ? n : null;
}

function quiPaie(etat: Etat): Pas {
  return question('Nom du client ? Il sert à suivre ce qu\'il vous doit (exemple : *Paul Mbarga*).', { ...etat, etape: 'client' });
}

async function versArticles(etat: Etat, source: SourceArticles, numero = 1): Promise<Pas> {
  return listeArticles(etat, await source(etat.recherche ?? '', numero), numero);
}

/** Avance d'un pas. Un nombre tapé vaut le choix de même rang dans la dernière liste. */
export async function avancer(etat: Etat, entree: Entree, source: SourceArticles): Promise<Pas> {
  const texte = entree.type === 'texte' ? entree.texte.trim() : '';
  const mot = sansAccents(texte.toLowerCase());
  if (['annuler', 'stop', 'arreter', 'non merci'].includes(mot)) {
    return fin('C\'est annulé. Tapez *bayam* pour revenir au menu.');
  }
  if (entree.type === 'texte' && etat.options.length && /^\d{1,2}$/.test(texte)) {
    const id = etat.options[Number(texte) - 1];
    if (!id) {
      return { envois: [{ type: 'texte', texte: `${MARQUE}Choisissez un numéro entre 1 et ${etat.options.length}. ${ANNULER}` }], etat };
    }
    return avancer(etat, { type: 'choix', id }, source);
  }
  if (entree.type === 'choix') {
    return choix(etat, entree.id.replace(/^bayam:v:/, ''), source);
  }
  return saisie(etat, entree, texte, source);
}

async function choix(etat: Etat, code: string, source: SourceArticles): Promise<Pas> {
  const [genre, valeur = ''] = code.split(':');
  if (COMMANDES[code]) {
    return executer(COMMANDES[code]);
  }
  // Choix touché dans une liste d'un parcours terminé ou expiré : on rouvre le menu plutôt que d'agir au hasard.
  const manque = (['var', 'q', 'prix', 'pay'].includes(genre) && !etat.article) || (['page', 'chercher', 'art'].includes(genre) && !etat.action)
    || (genre === 'ou' && !etat.quoi);
  if (manque) {
    const m = menu();
    return { ...m, envois: [{ type: 'texte', texte: `${MARQUE}Ce choix vient d'une ancienne liste. Voici le menu 👇` }, ...m.envois] };
  }
  switch (genre) {
    case 'menu':
      return menu();
    case 'bilan':
    case 'boutique':
      return sousMenu(genre);
    case 'stock':
    case 'vente':
      return versArticles({ etape: 'article', options: [], action: genre }, source);
    case 'nouveau':
      return question('📷 Envoyez ici la photo de l\'article.', { etape: 'photo' });
    case 'publier':
      return choisir('Publier', 'Quels articles publier ?', [{ titre: 'Articles', lignes: [
        { id: ID('quoi:nouveautes'), titre: '🆕 Nouveautés', description: 'Arrivés cette semaine' },
        { id: ID('quoi:promos'), titre: '🔥 Promotions', description: 'Vos articles en promo' },
        { id: ID('quoi:article'), titre: '👕 Un article', description: 'Vous le choisissez' },
      ] }], { etape: 'publier_quoi' });
    case 'quoi':
      return valeur === 'article' ? versArticles({ etape: 'article', options: [], action: 'publier' }, source) : ouPublier(valeur);
    case 'ou':
      return valeur === 'partager' ? executer(`partager ${etat.quoi}`) : executer(`publier ${etat.quoi} ${valeur}`);
    case 'page':
      return versArticles(etat, source, Number(valeur) || 1);
    case 'chercher':
      return question('Tapez le nom de l\'article, ou une partie (exemple : *robe*).', { ...etat, etape: 'recherche' });
    case 'art':
      return articleChoisi(etat, Number(valeur), source);
    case 'var': {
      if (valeur === 'autre') {
        return question(`Tapez la variante de *${etat.article?.nom}* (exemple : *${etat.variantes?.at(-1) ?? 'M'}*).`, { ...etat, etape: 'variante_libre' });
      }
      const variante = etat.variantes?.[Number(valeur)];
      return variante ? quantites({ ...etat, variante }) : menu();
    }
    case 'q':
      if (valeur === 'autre') {
        return question('Combien de pièces ? Tapez le nombre (exemple : *12*).', { ...etat, etape: 'quantite_libre' });
      }
      return apresQuantite({ ...etat, quantite: Number(valeur) || 1 });
    case 'prix':
      return valeur === 'autre'
        ? question(`Prix total pour ${etat.quantite ?? 1} × ${nomComplet(etat)} ? (exemple : *24000*)`, { ...etat, etape: 'prix_libre' })
        : paiement({ ...etat, montant: null });
    case 'pay': {
      if (valeur.startsWith('acompte_')) {
        return question('Combien le client a-t-il déjà payé ? (exemple : *5000*)', { ...etat, etape: 'acompte', mode: valeur.slice(8) });
      }
      const suite = { ...etat, mode: valeur };
      return valeur === 'credit' ? quiPaie(suite) : executer(commandeVente(suite));
    }
    default:
      return menu();
  }
}

function ouPublier(quoi: string): Pas {
  return choisir('Publier', 'Où publier ?', [{ titre: 'Où', lignes: [
    { id: ID('ou:groupes'), titre: '👥 Mes groupes', description: 'Groupes WhatsApp choisis dans votre espace' },
    { id: ID('ou:statut'), titre: '📲 Mon statut', description: 'Statut WhatsApp' },
    { id: ID('ou:chaine'), titre: '📢 Ma chaîne', description: 'Chaîne WhatsApp' },
    { id: ID('ou:facebook'), titre: '📘 Ma Page Facebook', description: 'Photo, prix et réponse aux commentaires' },
    { id: ID('ou:story'), titre: '🎞️ Story Facebook', description: 'Visuel avec le prix, 24 h' },
    { id: ID('ou:partager'), titre: '✍️ Profil, groupes FB', description: 'Votre profil et vos groupes Facebook : texte et photo prêts à coller' },
    { id: ID('ou:partout'), titre: '🌍 Partout', description: 'Groupes, chaîne, statut et Page' },
  ] }], { etape: 'publier_ou', quoi });
}

async function articleChoisi(etat: Etat, id: number, source: SourceArticles): Promise<Pas> {
  // L'article est relu : nom, prix et variantes du moment (la liste affichée peut dater de quelques minutes).
  const page = await source(`#${id}`, 1);
  const a = page.articles.find((x) => x.id === id);
  if (!a) {
    return fin('Cet article n\'existe plus. Tapez *bayam* pour revenir au menu.');
  }
  const suite: Etat = { ...etat, article: { id: a.id, nom: a.nom, prix: a.prix, variable: a.variable } };
  if (etat.action === 'publier') {
    return ouPublier(`#${a.id}`);
  }
  if (a.variable && a.variantes.length) {
    const noms = a.variantes.map((v) => v.nom);
    const trop = a.variantes.length > LIMITES.lignes;
    const lignes: Ligne[] = a.variantes.slice(0, trop ? LIMITES.lignes - 1 : LIMITES.lignes).map((v, i) => ({ id: ID(`var:${i}`), titre: v.nom, description: `Stock : ${v.stock ?? 'non suivi'}` }));
    if (trop) {
      lignes.push({ id: ID('var:autre'), titre: 'Autre : je la tape', description: `${a.variantes.length - lignes.length} autres variantes` });
    }
    return choisir('Taille ou couleur', `*${a.nom}* : laquelle ?`, [{ titre: 'Variantes', lignes }], { ...suite, etape: 'variante', variantes: noms });
  }
  if (etat.action === 'vente' && !a.en_stock) {
    return fin(`*${a.nom}* est épuisé : ajoutez d'abord du stock (menu, « Ajouter du stock »).`);
  }
  return quantites(suite);
}

function apresQuantite(etat: Etat): Pas {
  return etat.action === 'vente' ? prixVente(etat) : executer(`+${etat.quantite ?? 1} #${etat.article?.id}${etat.variante ? ` ${etat.variante.replace(/,/g, ' ')}` : ''}`);
}

async function saisie(etat: Etat, entree: Entree, texte: string, source: SourceArticles): Promise<Pas> {
  switch (etat.etape) {
    case 'photo':
      return entree.type === 'photo'
        ? question('Nom de l\'article ? (exemple : *Robe wax courte*)', { etape: 'nom', photo: entree.url })
        : question('J\'attends la photo de l\'article : envoyez-la ici.', etat);
    case 'nom':
      return texte.length >= 2
        ? question(`Prix de vente de *${texte}* ? (exemple : *15000*)`, { ...etat, etape: 'prix_nouveau', nom: texte })
        : question('Nom de l\'article ?', etat);
    case 'prix_nouveau': {
      const prix = montant(texte);
      return prix
        ? question('Quantités ? Le nombre de pièces (exemple : *5*), ou par taille (exemple : *M:3 L:2*).', { ...etat, etape: 'quantites_nouveau', prix })
        : question('Je n\'ai pas compris le prix. Tapez-le en chiffres (exemple : *15000*).', etat);
    }
    case 'quantites_nouveau': {
      const q = texte.replace(/\s*:\s*/g, ':');
      return /^(\d{1,4}|(\S+:\d{1,4}\s*)+)$/.test(q)
        ? executer(`${etat.nom} ${etat.prix} ${q}`, etat.photo)
        : question('Tapez le nombre de pièces (exemple : *5*), ou par taille (exemple : *M:3 L:2*).', etat);
    }
    case 'variante_libre': {
      const voulue = sansAccents(texte.toLowerCase()).replace(/\s+/g, ' ');
      const variante = etat.variantes?.find((v) => sansAccents(v.toLowerCase()).replace(/\s+/g, ' ') === voulue);
      return variante ? quantites({ ...etat, variante }) : question(`Je ne trouve pas « ${texte} ». Variantes : ${etat.variantes?.join(' · ')}`, etat);
    }
    case 'recherche':
      return texte ? versArticles({ ...etat, etape: 'article', recherche: texte }, source) : question('Tapez une partie du nom.', etat);
    case 'quantite_libre': {
      const q = Number(texte);
      return Number.isInteger(q) && q > 0 && q <= 10_000 ? apresQuantite({ ...etat, quantite: q }) : question('Tapez un nombre de pièces (exemple : *12*).', etat);
    }
    case 'prix_libre': {
      const total = montant(texte);
      return total ? paiement({ ...etat, montant: total }) : question('Tapez le prix total en chiffres (exemple : *24000*).', etat);
    }
    case 'acompte': {
      const a = montant(texte);
      return a ? quiPaie({ ...etat, acompte: a }) : question('Tapez la somme déjà payée en chiffres (exemple : *5000*).', etat);
    }
    case 'client':
      return /\p{L}{2,}/u.test(texte) && !/\d/.test(texte)
        ? executer(commandeVente(etat, texte))
        : question('Tapez le nom du client, sans chiffres (exemple : *Paul Mbarga*).', etat);
    default:
      // Étape à choix : le vendeur a tapé autre chose qu'un numéro ; on lui rappelle la liste.
      return { envois: [{ type: 'texte', texte: `${MARQUE}Touchez *Choisir* dans la liste ci-dessus, ou répondez par le numéro. ${ANNULER}` }], etat };
  }
}
