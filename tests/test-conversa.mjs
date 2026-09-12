import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const src = readFileSync(new URL('../painel/conversa.js', import.meta.url), 'utf8');
// Mesmo padrao de tests/test-funil.mjs (pega('PO_COLUNAS')): poBolha depende
// da const PO_AUTOR declarada fora dela, entao o arnes precisa extrair as
// duas, senao o teste isolado nao enxerga PO_AUTOR e explode com
// ReferenceError mesmo com o conversa.js correto.
function pega(nome) {
  const m = src.match(new RegExp('(?:const|function) ' + nome + '[\\s\\S]*?\\n(?:\\};?|\\];)'));
  assert.ok(m, nome + ' encontrada no conversa.js');
  return m[0];
}
const poBolha = new Function(
  pega('PO_AUTOR') + pega('poBolha') +
  'function esc(s){return (s==null?"":String(s)).replace(/[&<>"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;","\\"":"&quot;"}[c]));}' +
  '; return poBolha;'
)();

// --- os tres autores tem tratamento visual distinto: sem isso nao da
// para saber se foi o robo ou a dona que falou, que e o que ela mais
// precisa saber ao abrir a conversa.
assert.ok(poBolha({autor:'cliente', texto:'oi', ts:'2026-09-01T12:00:00Z'}).includes('cv--in'),
  'cliente tem bolha de entrada');
assert.ok(poBolha({autor:'robo', texto:'segue o roteiro', ts:'2026-09-01T12:00:00Z'}).includes('cv--bot'),
  'robo tem bolha propria');
assert.ok(poBolha({autor:'humano', texto:'bom dia', ts:'2026-09-01T12:00:00Z'}).includes('cv--out'),
  'a dona tem bolha de saida');

// --- texto do cliente e dado de terceiro
const mal = poBolha({autor:'cliente', texto:'<img src=x onerror=alert(1)>', ts:'2026-09-01T12:00:00Z'});
assert.ok(!mal.includes('<img src=x'), 'texto do cliente e escapado');
assert.ok(mal.includes('&lt;img'), 'aparece escapado, nao sumido');

// --- quebra de linha vira <br>: as duas perguntas do robo sao enviadas
// num texto so com linhas em branco, e sem isso saem todas grudadas.
const multi = poBolha({autor:'robo', texto:'1. tem data?\n\n2. ja viajou?', ts:'2026-09-01T12:00:00Z'});
assert.ok(multi.includes('<br>'), 'quebra de linha preservada');

// --- mensagem sem texto (audio, imagem) nao pode sair em branco
const audio = poBolha({autor:'cliente', texto:'', tipo:'audio', ts:'2026-09-01T12:00:00Z'});
assert.ok(audio.includes('udio') || audio.includes('nexo'), 'mensagem nao textual e descrita');

console.log('test-conversa OK');
