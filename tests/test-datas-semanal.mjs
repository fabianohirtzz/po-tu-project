import assert from 'node:assert/strict';
import { readFileSync, writeFileSync, mkdtempSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

/* ============================================================
   A conta da proxima saida existe em TRES implementacoes, e este teste e o
   que impede as tres de divergirem:

     lib/po-datas.php              o servidor (paginas PHP e o robo)
     assets/js/roteiros-shared.js  as paginas publicas, que leem o banco no
                                   navegador (a home monta o carrossel por
                                   Supabase, sem passar pelo PHP)
     painel/datas.js               o painel, que NAO carrega o
                                   roteiros-shared.js (conferido no
                                   painel/index.html: ele carrega apenas os
                                   seus proprios arquivos mais o config.js)

   Tres implementacoes e o mesmo arranjo que a lista de status de viagem ja
   usa neste projeto (tests/test-status-tag.mjs). Um arquivo compartilhado
   novo exigiria <script> em seis paginas publicas mais o painel, e o
   roteiros-shared.js nao serve ao painel porque define poClient e le
   window.PO_CONFIG, que no painel colidiriam com o config.js dele.

   A assercao deste arquivo e que as tres CONCORDAM caso a caso, de uma
   tabela unica - nao que cada uma passa na sua propria lista. Lista propria
   por implementacao e exatamente como duas implementacoes divergem com os
   dois testes verdes.

   Normalizacao do fim de linha na LEITURA de cada fonte, nunca em cada
   regex: com core.autocrlf=true neste repo, arquivo novo nasce LF e vira
   CRLF no primeiro checkout que o recria, que na pratica e o merge.
============================================================ */

const raiz = join(import.meta.dirname, '..');
const le = rel => readFileSync(join(raiz, rel), 'utf8').split('\r\n').join('\n');

/* ---------- a tabela unica ---------- */
const DEZ = [{ titulo: 'Datas de Dezembro', datas: ['02/12/39', '10/12/39', '18/12/39'] }];
const DOIS = [
  { titulo: 'Datas de Dezembro', datas: ['02/12/39', '10/12/39'] },
  { titulo: 'Datas de Janeiro', datas: ['07/01/40', '15/01/40'] },
];
const sem = (datas_texto, extra) => Object.assign(
  { status_viagem: 'semanal', datas_texto, data_label: '', periodo: '' }, extra || {});

const CASOS = [
  ['antes de todas, a primeira', sem(DEZ), '2039-11-01'],
  ['pula a que passou', sem(DEZ), '2039-12-05'],
  ['a saida de hoje conta', sem(DEZ), '2039-12-10'],
  ['todas no passado', sem(DEZ, { periodo: 'De 02/12/39 a 18/12/39' }), '2039-12-19'],
  ['atravessa a virada do ano', sem(DOIS), '2039-12-20'],
  ['lista fora de ordem', sem([{ titulo: 'D', datas: ['18/12/39', '02/12/39', '10/12/39'] }]), '2039-12-05'],
  ['data impossivel e texto descartados', sem([{ titulo: 'D', datas: ['32/12/39', '31/02/40', 'dezembro', '02-12-39', '10/12/39'] }]), '2039-11-01'],
  ['so data impossivel', sem([{ titulo: 'D', datas: ['32/13/99'] }]), '2039-01-01'],
  /* A impossivel ANTES da valida: aqui ela so e descartada se o codigo de
     fato recusar a data. Com '31/02/40' ela virava '2040-02-31', que e MAIOR
     que a valida e por isso nunca vencia o minimo - o caso passava sem
     provar nada. Com 31/02/39 contra 10/12/39, a impossivel seria a menor e
     o cliente receberia "Proxima saida: 31/02/39", um dia que nao existe. */
  ['impossivel ANTES da valida nao pode vencer o minimo', sem([{ titulo: 'D', datas: ['31/02/39', '10/12/39'] }]), '2039-01-01'],
  ['ano de 4 digitos', sem([{ titulo: 'D', datas: ['02/12/2039'] }]), '2039-11-01'],
  ['ilegivel numa lista vencida nao vira proxima saida', sem([{ titulo: 'D', datas: ['02/12/39', 'dezembro', '10/12/39'] }], { periodo: 'P' }), '2039-12-20'],
  ['semanal sem datas, com periodo', sem([], { periodo: 'P' }), '2039-01-01'],
  ['semanal sem datas e sem periodo', sem([]), '2039-01-01'],
  ['limpa as datas de dentro do grupo', sem([{ titulo: 'D', datas: ['02/12/39', '', null, 7, '10/12/39'] }]), '2039-11-01'],
  // forma errada: o jsonb pode ter sido editado a mao no SQL Editor
  ['datas_texto null', sem(null, { periodo: 'P' }), '2039-01-01'],
  ['datas_texto texto', sem('texto', { periodo: 'P' }), '2039-01-01'],
  ['datas como string', sem([{ titulo: 'x', datas: '02/12/39' }], { periodo: 'P' }), '2039-01-01'],
  ['grupo sem titulo', sem([{ datas: ['02/12/39'] }], { periodo: 'P' }), '2039-01-01'],
  ['grupo sem datas', sem([{ titulo: 'x' }], { periodo: 'P' }), '2039-01-01'],
  ['mapa com chaves, grupos validos dentro', sem({ dez: { titulo: 'D', datas: ['02/12/39'] } }, { periodo: 'P' }), '2039-01-01'],
  ['grupo bom no meio de lixo', sem([{ titulo: 'x', datas: 'nao' }, DEZ[0]]), '2039-11-01'],
  // o STATUS decide, nao a presenca do dado
  ['status comum ignora datas_texto', { status_viagem: 'em_breve', datas_texto: DEZ, data_label: '', periodo: 'De 1 a 2' }, '2039-11-01'],
  ['data_label ganha de periodo', { status_viagem: null, data_label: 'outubro de 2039', periodo: 'De 1 a 2' }, '2039-11-01'],
  ['data_label string vazia cai em periodo', { status_viagem: null, data_label: '', periodo: 'De 1 a 2' }, '2039-11-01'],
  ['roteiro sem nada', {}, '2039-11-01'],
];

/* ---------- lado PHP: EXECUTADO, nao lido ---------- */
const dir = mkdtempSync(join(tmpdir(), 'po-datas-'));
const arq = join(dir, 'casos.json');
writeFileSync(arq, JSON.stringify(CASOS.map(([, roteiro, hoje]) => ({ roteiro, hoje }))), 'utf8');
const php = JSON.parse(execFileSync('php', [join(raiz, 'tests/bridge-po-datas.php'), arq], { encoding: 'utf8' }));

/* ---------- os dois lados JS ---------- */
const carrega = rel => {
  const w = {};
  new Function('window', le(rel))(w);
  return w;
};
const pub = carrega('assets/js/roteiros-shared.js');
const pnl = carrega('painel/datas.js');

for (const [nome, api] of [['roteiros-shared.js', pub], ['painel/datas.js', pnl]]) {
  for (const fn of ['poDatasGrupos', 'poProximaSaida', 'poQuando']) {
    assert.equal(typeof api[fn], 'function', nome + ' expoe ' + fn);
  }
}
/* rotDatasNormaliza e o nome pelo qual a Tarefa 5 consome a normalizacao do
   painel: a mesma funcao, nao uma segunda. */
assert.equal(pnl.rotDatasNormaliza, pnl.poDatasGrupos, 'rotDatasNormaliza E poDatasGrupos, nao uma segunda normalizacao');

/* ---------- as tres concordam, caso a caso ---------- */
CASOS.forEach(([nome, roteiro, hoje], i) => {
  const esperado = php[i];
  for (const [onde, api] of [['roteiros-shared.js', pub], ['painel/datas.js', pnl]]) {
    assert.deepEqual(api.poDatasGrupos(roteiro), esperado.grupos, `grupos [${nome}] ${onde} x php`);
    assert.equal(api.poProximaSaida(roteiro, hoje), esperado.prox, `prox [${nome}] ${onde} x php`);
    assert.equal(api.poQuando(roteiro, hoje), esperado.quando, `quando [${nome}] ${onde} x php`);
  }
});

/* ---------- o rotulo, para a tabela nao poder ficar toda nula ---------- */
assert.equal(php[0].quando, 'Próxima saída: 02/12/39', 'o PHP de fato anuncia a proxima saida (a tabela nao esta toda vazia)');
assert.equal(pub.poQuando(sem(DEZ), '2039-11-01'), 'Próxima saída: 02/12/39', 'e o JS publico tambem');
assert.equal(pnl.poQuando(sem(DEZ), '2039-11-01'), 'Próxima saída: 02/12/39', 'e o painel tambem');

/* ---------- o painel realmente carrega o datas.js ---------- */
const idx = le('painel/index.html');
assert.ok(/<script src="datas\.js\?v=\d+">/.test(idx), 'painel/index.html carrega datas.js com ?v=');
assert.ok(idx.indexOf('datas.js') < idx.indexOf('app.js'), 'datas.js vem antes do app.js, que o consome');

console.log('datas-semanal ok (' + CASOS.length + ' casos x 3 implementacoes)');
