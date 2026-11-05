import assert from 'node:assert/strict';
import { readFileSync, writeFileSync, mkdtempSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

/* ============================================================
   ORDEM CRONOLOGICA EM TODO LUGAR QUE MOSTRA ROTEIRO, e a viagem vencida
   saindo de cena sozinha. Pedido do cliente em 05/11/2026.

   A conta existe em DOIS lados, e este arquivo e o que impede os dois de
   divergirem:

     lib/po-datas.php              o servidor: /roteiros, "Outros roteiros",
                                   o llms.txt, a lista do painel Sobre e o
                                   catalogo do robo
     assets/js/roteiros-shared.js  as paginas publicas que leem o banco NO
                                   NAVEGADOR: a home monta o carrossel por
                                   Supabase, e a Nossa Historia e o /link
                                   tambem

   O painel NAO entra: ele nao ordena lista publica nenhuma.

   A assercao e que os dois CONCORDAM caso a caso, de uma tabela unica. Lista
   propria por implementacao e exatamente como duas divergem com os dois
   testes verdes - e aqui ja houve uma divergencia real: ate 05/11/2026 o
   site lia `data_label` antes de `periodo` e o robo lia `periodo` antes, o
   que produzia ordens diferentes na home e na conversa.

   Normalizacao do fim de linha na LEITURA, nunca em cada regex.
============================================================ */

const raiz = join(import.meta.dirname, '..');
const le = rel => readFileSync(join(raiz, rel), 'utf8').split('\r\n').join('\n');

const r = (slug, extra) => Object.assign(
  { slug, periodo: '', data_label: '', descricao_curta: '', status_viagem: null, datas_texto: [] }, extra);

/* ---------- a tabela unica, por caso ---------- */
const CASOS = [
  ['periodo com ano de 2 digitos',   r('a', { periodo: 'De 27/10/26 à 12/11/26' }),            '2026-11-05'],
  ['periodo com ano de 4 digitos',   r('b', { periodo: 'De 22/02/2027 a 15/03/2027' }),        '2026-11-05'],
  ['periodo sem a palavra De',       r('c', { periodo: '25/11/26 a 02/12/26' }),               '2026-11-05'],
  ['uma data so: fim = inicio',      r('d', { periodo: '10/01/27' }),                          '2026-11-05'],
  ['tres datas: vale a ultima',      r('e', { periodo: '01/01/27 a 05/01/27 a 09/01/27' }),    '2026-11-05'],
  ['sem data nenhuma',               r('f', {}),                                               '2026-11-05'],
  ['texto sem data',                 r('g', { periodo: 'em breve' }),                          '2026-11-05'],
  ['periodo ganha de data_label',    r('h', { periodo: '10/01/27 a 20/01/27', data_label: '05/05/27' }), '2026-11-05'],
  ['data_label quando periodo vazio',r('i', { data_label: '05/05/27' }),                        '2026-11-05'],
  ['descricao_curta e o ultimo',     r('j', { descricao_curta: '07/07/27' }),                   '2026-11-05'],
  ['data impossivel e descartada',   r('k', { periodo: 'De 31/02/27 a 10/03/27' }),             '2026-11-05'],
  ['fim do MESMO campo do inicio',   r('l', { periodo: '10/01/27 a 20/01/27', descricao_curta: '31/12/27' }), '2026-11-05'],
  ['vencido: um dia depois do fim',  r('m', { periodo: 'De 27/10/26 à 12/11/26' }),             '2026-11-13'],
  ['no ultimo dia ainda vale',       r('n', { periodo: 'De 27/10/26 à 12/11/26' }),             '2026-11-12'],
  ['semanal sem data',               r('o', { status_viagem: 'semanal' }),                      '2026-11-05'],
  ['semanal com data futura',        r('p', { status_viagem: 'semanal',
      datas_texto: [{ titulo: 'Datas de Dezembro', datas: ['02/12/26', '09/12/26'] }] }),       '2026-11-05'],
  ['semanal nunca vence',            r('q', { status_viagem: 'semanal',
      datas_texto: [{ titulo: 'Antigas', datas: ['02/12/20'] }] }),                             '2030-01-01'],
];

/* ---------- lado PHP: EXECUTADO, nao lido por regex ---------- */
const dir = mkdtempSync(join(tmpdir(), 'po-ordem-'));
const arqCasos = join(dir, 'casos.json');
writeFileSync(arqCasos, JSON.stringify(CASOS.map(([, roteiro, hoje]) => ({ roteiro, hoje }))), 'utf8');
const php = JSON.parse(execFileSync('php', [join(raiz, 'tests/bridge-po-datas.php'), arqCasos], { encoding: 'utf8' }));

/* ---------- lado navegador ---------- */
const carrega = rel => { const w = {}; new Function('window', le(rel))(w); return w; };
const pub = carrega('assets/js/roteiros-shared.js');

for (const fn of ['poHoje', 'poDatasDoCampo', 'poDataSaida', 'poDataFim', 'poVencido', 'poPorSaida', 'poSemVencidos']) {
  assert.equal(typeof pub[fn], 'function', 'roteiros-shared.js expoe ' + fn);
}

/* ---------- os dois concordam, caso a caso ---------- */
CASOS.forEach(([nome, roteiro, hoje], i) => {
  assert.equal(pub.poDataSaida(roteiro, hoje), php[i].saida,   `saida [${nome}] js x php`);
  assert.equal(pub.poDataFim(roteiro),          php[i].fim,     `fim [${nome}] js x php`);
  assert.equal(pub.poVencido(roteiro, hoje),    php[i].vencido, `vencido [${nome}] js x php`);
});

/* ---------- e concordam na ORDEM da lista inteira ----------
   Os 7 roteiros reais, medidos no banco em 05/11/2026, na ordem em que o
   painel os devolvia (ordem manual), que estava completamente fora de ordem
   cronologica. */
const REAIS = [
  r('vietna-tailandia-paris',     { periodo: 'De 22/02/2027 a 15/03/2027' }),
  r('tesouros-da-grecia',         { periodo: 'De 18/05/27 a 03/06/27' }),
  r('peru-fascinante',            { status_viagem: 'semanal' }),
  r('escandinavia',               { periodo: 'De 29/07/27 à 14/08/27' }),
  r('turquia',                    { periodo: 'De 27/10/26 à 12/11/26' }),
  r('chile-e-deserto-do-atacama', { periodo: '25/11/26 a 02/12/26' }),
  r('coreia-do-sul-japao-dubai',  { periodo: 'De 18/03/27 à 09/04/27' }),
];

for (const hoje of ['2026-11-05', '2026-11-13', '2027-06-01']) {
  const arqOrdem = join(dir, 'ordem-' + hoje + '.json');
  writeFileSync(arqOrdem, JSON.stringify({ lista: REAIS, hoje }), 'utf8');
  const esperado = JSON.parse(execFileSync('php',
    [join(raiz, 'tests/bridge-po-datas.php'), arqOrdem, 'ordem'], { encoding: 'utf8' }));

  assert.deepEqual(pub.poPorSaida(REAIS, hoje).map(x => x.slug), esperado.ordem,
    'a ORDEM concorda js x php em ' + hoje);
  assert.deepEqual(pub.poSemVencidos(REAIS, hoje).map(x => x.slug), esperado.vivos,
    'os VIVOS concordam js x php em ' + hoje);

  /* E o Peru e sempre o ultimo, porque nao tem data definida. Foi o pedido
     literal do cliente. */
  const ordenado = pub.poPorSaida(pub.poSemVencidos(REAIS, hoje), hoje).map(x => x.slug);
  assert.equal(ordenado[ordenado.length - 1], 'peru-fascinante',
    'o Peru fica por ultimo em ' + hoje);
}

/* O caso do dia do pedido, por extenso: a Turquia esta EM CURSO (partiu em
   27/10, termina em 12/11), entao ela NAO esta vencida e abre a lista. */
assert.deepEqual(
  pub.poPorSaida(pub.poSemVencidos(REAIS, '2026-11-05'), '2026-11-05').map(x => x.slug),
  ['turquia', 'chile-e-deserto-do-atacama', 'vietna-tailandia-paris',
   'coreia-do-sul-japao-dubai', 'tesouros-da-grecia', 'escandinavia', 'peru-fascinante'],
  'os 7 reais em 05/11/2026');

/* Em 13/11 a Turquia sai de cena sozinha, sem ninguem mexer no painel. */
assert.deepEqual(
  pub.poPorSaida(pub.poSemVencidos(REAIS, '2026-11-13'), '2026-11-13').map(x => x.slug),
  ['chile-e-deserto-do-atacama', 'vietna-tailandia-paris', 'coreia-do-sul-japao-dubai',
   'tesouros-da-grecia', 'escandinavia', 'peru-fascinante'],
  'em 13/11 a Turquia se oculta sozinha');

/* ---------- a UNICA leitura de lista do front aplica as duas coisas ----------
   Trava no ponto de chamada: a funcao pura certa e o chamador livre ja foi
   defeito neste projeto. Tela nova de roteiro tem que nascer ordenada. */
const shared = le('assets/js/roteiros-shared.js');
assert.ok(/poPorSaida\(window\.poSemVencidos\(res\.data\)\)/.test(shared),
  'poFetchRoteiros devolve a lista ja ordenada e sem vencidos');

/* ---------- e o main.js nao ordena por conta propria ----------
   Ele tinha um tripTime(rotulo) que exigia ano de 4 digitos enquanto todos os
   periodos reais tem 2: a ordenacao da home era um no-op silencioso. Ordenar
   pelo ROTULO tambem e fragil, porque o rotulo muda com a copy. */
const main = le('assets/js/main.js');
assert.ok(!/function tripTime/.test(main), 'tripTime nao existe mais');
assert.ok(!/\.sort\(/.test(main.replace(/\/\*[\s\S]*?\*\//g, '')),
  'main.js nao ordena roteiro por conta propria: quem ordena e poFetchRoteiros');

console.log('ordem-cronologica ok (' + CASOS.length + ' casos, 2 implementacoes)');
