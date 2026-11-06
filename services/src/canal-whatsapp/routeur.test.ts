import { test } from 'node:test';
import assert from 'node:assert/strict';
import { MARQUE } from '../passerelle/filtre.ts';
import { lireMessage, type Evenement } from './message.ts';
import { decider, idAction, lireAction, type Action } from './routeur.ts';

const MAINTENANT = 1_793_949_820;

// Forme réelle relevée sur WAHA 2026.10.1 (GOWS) : réponse d'un client à une liste BAYAM.
function reponseListe(rowId: string, description = `${MARQUE}Voici nos rayons`): Evenement {
  return {
    id: 'evt_01m6qvvm7swcg2kdfesz89smzh',
    event: 'message.any',
    session: 'boutique-3',
    payload: {
      id: 'false_32779945431068@lid_A5EBAF5911B2F9D62CF8139D3EF8C107',
      timestamp: MAINTENANT - 6,
      from: '32779945431068@lid',
      fromMe: false,
      body: 'T-shirts\n5 articles',
      replyTo: { body: `Mode Aïcha\n${description}` },
      _data: {
        Info: { Chat: '32779945431068@lid', IsGroup: false, SenderAlt: '237681010044@s.whatsapp.net', PushName: 'Kekottech' },
        Message: { listResponseMessage: { singleSelectReply: { selectedRowID: rowId }, contextInfo: { quotedMessage: { listMessage: { title: 'Mode Aïcha', description } } } } },
      },
    },
  };
}

function texte(body: string, extra: { fromMe?: boolean; chat?: string; age?: number } = {}): Evenement {
  const chat = extra.chat ?? '32779945431068@lid';
  return {
    id: 'evt_2', event: 'message.any', session: 'boutique-3',
    payload: { id: 'x', timestamp: MAINTENANT - (extra.age ?? 2), from: chat, fromMe: extra.fromMe ?? false, body, _data: { Info: { Chat: chat, SenderAlt: '237681010044@s.whatsapp.net' } } },
  };
}

test('lecture d\'une réponse à une liste BAYAM : discussion, numéro du client, ligne choisie', () => {
  const m = lireMessage(reponseListe('bayam:rayon:49:1'));
  assert.ok(m);
  assert.equal(m.chat, '32779945431068@lid');
  assert.equal(m.numero, '237681010044');
  assert.equal(m.choixListe, 'bayam:rayon:49:1');
  assert.equal(m.nom, 'Kekottech');
});

test('choix dans une liste BAYAM : traité par le catalogue', () => {
  const d = decider(lireMessage(reponseListe('bayam:rayon:49:2')), MAINTENANT);
  assert.equal(d.route, 'catalogue');
  assert.deepEqual(d.route === 'catalogue' && d.action, { type: 'rayon', rayon: 49, page: 2 });
});

test('liste qui ne vient pas de BAYAM : laissée au vendeur', () => {
  const m = lireMessage(reponseListe('bayam:menu', 'Liste d\'un autre outil'));
  assert.equal(m?.choixListe, null);
  assert.equal(decider(m, MAINTENANT).route, 'heritage');
});

test('ligne inconnue ou mal formée : laissée à WordPress', () => {
  for (const id of ['option1', 'bayam:rayon:abc', 'bayam:commander:', 'bayam:inconnu', 'bayam:rayon:12:0']) {
    assert.equal(decider(lireMessage(reponseListe(id)), MAINTENANT).route, 'heritage', id);
  }
});

test('« bayam » ou « #menu » du client : menu interactif', () => {
  for (const body of ['bayam', 'Bayam menu', '#catalogue', 'bayan']) {
    assert.equal(decider(lireMessage(texte(body)), MAINTENANT).route, 'catalogue', body);
  }
});

test('conversation ordinaire, recherche précise, groupe, vieux message : WordPress comme avant', () => {
  assert.equal(decider(lireMessage(texte('Bonjour, c\'est combien ?')), MAINTENANT).route, 'heritage');
  assert.equal(decider(lireMessage(texte('bayam robe rouge')), MAINTENANT).route, 'heritage');
  assert.equal(decider(lireMessage(texte('bayam', { chat: '120363000000000001@g.us' })), MAINTENANT).route, 'heritage');
  assert.equal(decider(lireMessage(texte('bayam', { age: 3600 })), MAINTENANT).route, 'heritage');
  assert.equal(decider(lireMessage({ event: 'session.status', session: 'boutique-3', payload: {} }), MAINTENANT).route, 'heritage');
});

test('le vendeur tape « bayam menu » chez un client : menu envoyé à ce client ; « #menu » du vendeur reste une note', () => {
  assert.equal(decider(lireMessage(texte('bayam menu', { fromMe: true })), MAINTENANT).route, 'catalogue');
  assert.equal(decider(lireMessage(texte('#menu', { fromMe: true })), MAINTENANT).route, 'heritage');
});

test('écho de nos propres envois (marque en tête) : jamais retraité', () => {
  assert.equal(decider(lireMessage(texte(`${MARQUE}Mode Aïcha\nbayam`, { fromMe: true })), MAINTENANT).route, 'heritage');
});

test('identifiants de ligne : aller-retour sans perte', () => {
  const actions: Action[] = [{ type: 'menu' }, { type: 'rayon', rayon: 49, page: 3 }, { type: 'commander', produit: 25 }, { type: 'commandes' }, { type: 'livraison' }, { type: 'vendeur' }];
  for (const a of actions) {
    assert.deepEqual(lireAction(idAction(a)), a);
  }
});
