import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

/* Os tipos de card vivem em dois lugares: WA_CARD_TIPOS (lib/wa-fluxo.php) e
   poFluxoTipos() (painel/fluxo.js). Tipo que existe no PHP e falta na tela
   vira card sem nome; tipo na tela que o PHP nao conhece o motor nunca
   executa. Porte de tests/test-status-tag.mjs. */
const php = readFileSync(new URL('../lib/wa-fluxo.php', import.meta.url), 'utf8');
const src = readFileSync(new URL('../painel/fluxo.js', import.meta.url), 'utf8');

const m = php.match(/const WA_CARD_TIPOS\s*=\s*\[([^\]]*)\]/);
assert.ok(m, 'WA_CARD_TIPOS encontrada no PHP');
const tiposPhp = [...m[1].matchAll(/'(\w+)'/g)].map(x => x[1]);
assert.ok(tiposPhp.length >= 8, 'a lista do PHP foi lida');

const f = src.match(/function poFluxoTipos\([\s\S]*?\n\}/);
assert.ok(f, 'poFluxoTipos encontrada no fluxo.js');
const tipos = new Function(f[0] + '; return poFluxoTipos();')();

assert.deepEqual(tipos.map(t => t.tipo), tiposPhp, 'mesmos tipos, na MESMA ordem do PHP');
for (const t of tipos) {
  assert.ok(t.rotulo && t.rotulo.length > 3, t.tipo + ' tem rotulo');
  assert.ok(!/_/.test(t.rotulo), t.tipo + ': rotulo sem underline (a cliente nao e programadora)');
  assert.notEqual(t.rotulo.toLowerCase(), t.tipo, t.tipo + ': rotulo diferente do nome tecnico');
  assert.equal(typeof t.espera, 'boolean', t.tipo + ' declara se espera');
  assert.ok(t.manda && t.manda.length > 3, t.tipo + ' diz o que faz');
}

// Quem espera o cliente e o que o PHP declara em WA_CARD_ESPERA
const e = php.match(/const WA_CARD_ESPERA\s*=\s*\[([^\]]*)\]/)[1];
const esperaPhp = [...e.matchAll(/'(\w+)'/g)].map(x => x[1]);
assert.deepEqual(tipos.filter(t => t.espera).map(t => t.tipo), esperaPhp,
  'espera == WA_CARD_ESPERA');

console.log('test-wa-fluxo-tipos OK');
