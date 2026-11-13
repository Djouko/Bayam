// Droits sur le bus (S-02) : avec le vrai compte du canal (variables du conteneur), publier un faux événement doit
// être refusé par NATS. Lancé dans le conteneur canal-whatsapp par scripts/essai_securite.sh.

import { connect } from '@nats-io/transport-node';

const nc = await connect({ servers: process.env.NATS_URL, user: process.env.NATS_UTILISATEUR, pass: process.env.NATS_MOT_DE_PASSE, name: 'essai-droits' });
let refuse = false;
(async () => {
  for await (const s of nc.status()) {
    if (s.type === 'error' && /Permissions Violation/i.test(String(s.error?.message ?? s.data ?? ''))) refuse = true;
  }
})();
nc.publish('whatsapp.evenements.boutique-3', '{"faux":true}');
await nc.flush().catch(() => {});
await new Promise((ok) => setTimeout(ok, 1500));
console.log(`${refuse ? 'OK   ' : 'ÉCHEC'} S-02 un canal ne peut pas écrire de faux événement sur le bus`);
await nc.close();
process.exit(refuse ? 0 : 1);
