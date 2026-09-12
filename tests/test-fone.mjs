import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const src = readFileSync(new URL('../painel/fone.js', import.meta.url), 'utf8');
function pega(nome) {
  const m = src.match(new RegExp('function ' + nome + '\\([\\s\\S]*?\\n\\}'));
  assert.ok(m, nome + ' encontrada no fone.js');
  return m[0];
}
const { poE164, poEhCelular } = new Function(
  pega('poE164') + pega('poEhCelular') + '; return {poE164, poEhCelular};'
)();

/* Os casos sao lidos de tests/fixtures/fone.json — a MESMA lista que
   tests/test-wa-fone.php usa para o wa_e164 do PHP. Duas listas escritas a
   mao ja tinham divergido, e ai uma correcao de um lado nao deixava o teste
   do outro vermelho: os dois normalizadores podiam separar em silencio, e a
   mesma pessoa viraria ficha diferente conforme quem gravou. */
const fx = JSON.parse(readFileSync(new URL('./fixtures/fone.json', import.meta.url), 'utf8'));
assert.ok(Array.isArray(fx.casos) && fx.casos.length, 'fixture de telefone carregada');

for (const [entrada, esperado] of fx.casos) {
  assert.equal(poE164(entrada), esperado, 'fixture: ' + JSON.stringify(entrada));
}

// --- asserções que nao cabem em par entrada/saida
assert.equal(poE164(poE164('(48) 99604-8882')), '+5548996048882', 'normalizar de novo nao estraga');
assert.equal(poE164(null), null, 'null nao estoura (o JS recebe campo vazio do DOM)');
assert.equal(poE164(undefined), null, 'undefined nao estoura');

assert.equal(poEhCelular('+5548996048882'), true,  'celular');
assert.equal(poEhCelular('+554832220000'),  false, 'fixo nao e celular');
assert.equal(poEhCelular(null),             false, 'null nao e celular');

console.log('test-fone OK');
