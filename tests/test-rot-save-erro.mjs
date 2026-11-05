import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

/* Em 22/10/2026 a cliente viu, ao salvar um roteiro importado:
   "Erro ao salvar: duplicate key value violates unique constraint po_roteiros_slug_key".
   A causa (UNIQUE global no slug) saiu do banco na migration 2026-10-22. O que
   resta de unicidade e o indice parcial "um ATIVO por slug", e quando ele recusar
   a tela tem que dizer em portugues qual roteiro ocupa o endereco e o que fazer. */
const src = readFileSync(new URL('../painel/app.js', import.meta.url), 'utf8');
const m = src.match(/function rotSaveErro\([\s\S]*?\n\}/);
assert.ok(m, 'rotSaveErro encontrada no app.js');
const rotSaveErro = new Function(m[0] + '; return rotSaveErro;')();

const ROTEIROS = [
  { id: 'a', slug: 'turquia', titulo: 'Turquia com Antália', ativo: true },
  { id: 'b', slug: 'turquia', titulo: 'Turquia 2025', ativo: false },
];
const dup = { code: '23505', message: 'duplicate key value violates unique constraint "po_roteiros_slug_ativo_uniq"' };

// roteiro novo, visivel, no endereco de um ativo: nomeia o ativo, nao o arquivado
const msg = rotSaveErro(dup, { slug: 'turquia' }, ROTEIROS, null);
assert.ok(msg.includes('Turquia com Antália'), 'nomeia o roteiro ativo que ocupa o endereco');
assert.ok(!msg.includes('Turquia 2025'), 'ignora a edicao arquivada');
assert.ok(msg.includes('/roteiros/turquia'), 'mostra o endereco');
assert.ok(!/duplicate key|constraint/.test(msg), 'nada do texto cru do Postgres');

// editando o proprio ativo nao pode se acusar
const msgEdit = rotSaveErro(dup, { slug: 'turquia' }, ROTEIROS, 'a');
assert.ok(!msgEdit.includes('Turquia com Antália'), 'nao acusa o proprio roteiro');
assert.ok(msgEdit.includes('/roteiros/turquia'), 'mesmo sem achar o outro, explica o endereco');

// erro que nao e de duplicidade segue com a mensagem original
assert.equal(rotSaveErro({ code: '42501', message: 'permission denied' }, { slug: 'x' }, ROTEIROS, null),
  'Erro ao salvar: permission denied');

console.log('rotSaveErro ok');
