import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { join } from 'node:path';
import { test } from 'node:test';

import { documentation } from './openapi.ts';

test('documentation OpenAPI à jour dans docs/api (sinon : npm run openapi)', async () => {
  for (const [nom, contenu] of Object.entries(await documentation())) {
    const enregistre = await readFile(join(import.meta.dirname, '..', '..', 'docs', 'api', `${nom}.json`), 'utf8').catch(() => '');
    assert.equal(enregistre, contenu, `docs/api/${nom}.json n'est plus à jour`);
  }
});
