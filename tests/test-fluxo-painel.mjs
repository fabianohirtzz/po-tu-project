import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

/* Extrai as funcoes puras do fluxo.js por regex e roda com new Function, no
   padrao de tests/test-textos.mjs. Por isso NENHUMA constante de topo de
   arquivo pode ser dependencia delas: a lista de tipos mora DENTRO de
   poFluxoTipos(). As Tasks 4, 5 e 6 ESTENDEM este arquivo: acrescente o nome
   da funcao nova em PURAS_NOMES (e so isso: o resto sai dali). */
const src = readFileSync(new URL('../painel/fluxo.js', import.meta.url), 'utf8');
function pega(nome) {
  const m = src.match(new RegExp('(?:async )?function ' + nome + '\\([\\s\\S]*?\\n\\}'));
  assert.ok(m, nome + ' encontrada no fluxo.js');
  return m[0];
}
const ESC = 'function esc(s){return (s==null?"":String(s)).replace(/[&<>"]/g,' +
            'c=>({"&":"&amp;","<":"&lt;",">":"&gt;","\\"":"&quot;"}[c]));}';

const PURAS_NOMES = ['poFluxoTipos', 'poFluxoTipo', 'poFluxoOpcoes', 'poFluxoPrevia',
                     'poFluxoCard', 'poFluxoLista', 'poFluxoSeletor', 'poFluxoCanvas',
                     'poFluxoLimite', 'poFluxoTemTexto', 'poFluxoMaxOpcoes', 'poFluxoPiorCaso', 'poFluxoContador',
                     'poFluxoOpcoesComRotulos', 'poFluxoPatch', 'poFluxoQuem', 'poFluxoBloqueio', 'poFluxoProblemas',
                     'poFluxoEditavel', 'poFluxoGaveta',
                     'poFluxoPodePublicar', 'poFluxoOrdemLigar', 'poFluxoSemRepetido', 'poFluxoPainelProblemas',
                     'poFluxoEstado', 'poFluxoPorqueNao', 'poFluxoBarra', 'poFluxoResultado', 'poFluxoCardCulpado'];
const F = new Function(ESC + PURAS_NOMES.map(pega).join('\n') +
  '; return {' + PURAS_NOMES.join(',') + '};')();

const XSS = '<script>alert("x")</script> & "aspas"';
const ESCAPADO = '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; &quot;aspas&quot;';
const conta = (s, sub) => s.split(sub).length - 1;

/* ---------- escape: assertiva sobre a string de saida ---------- */
const h = F.poFluxoCard({ id: 'a', tipo: 'botoes', titulo: XSS, texto: XSS,
  opcoes: [{ id: 'o1', rotulo: XSS, destino: 'b' }] },
  { b: { id: 'b', tipo: 'texto', titulo: XSS } });
assert.ok(!h.includes('<script'), 'nenhum <script> cru na saida');
assert.ok(!h.includes('"aspas"'), 'aspas cruas nao sobram');
assert.equal(conta(h, ESCAPADO), 4, 'titulo, texto, rotulo e titulo do destino saem escapados: ' + h);

// titulo, texto e rotulo isolados: cada ponto de saida tem que escapar sozinho
assert.ok(F.poFluxoCard({ id: 'a', tipo: 'texto', titulo: XSS }).includes(ESCAPADO), 'titulo escapado');
assert.ok(F.poFluxoCard({ id: 'a', tipo: 'texto', titulo: 'T', texto: XSS }).includes(ESCAPADO), 'texto escapado');
assert.ok(F.poFluxoCard({ id: 'a', tipo: 'botoes', titulo: 'T', opcoes: [{ rotulo: XSS }] }).includes(ESCAPADO), 'rotulo escapado');
assert.ok(F.poFluxoCard({ id: 'a', tipo: 'texto', titulo: 'T', proximo: 'b' }, { b: { titulo: XSS } }).includes(ESCAPADO),
  'titulo do destino escapado');
assert.ok(F.poFluxoCard({ id: 'a', tipo: 'texto', titulo: 'T', proximo: XSS }, {}).includes(ESCAPADO),
  'id de destino que nao existe escapado');

// id de card nunca vira atributo cru
const hid = F.poFluxoCard({ id: '"><img src=x>', tipo: 'texto', titulo: 'x' });
assert.ok(!hid.includes('<img'), 'id malicioso nao vira tag');

// nome de fluxo no seletor
const sel = F.poFluxoSeletor([{ id: 'f"><b>', nome: XSS }], 'f"><b>');
assert.ok(!sel.includes('<script') && !sel.includes('<b>'), 'seletor escapa nome e id');
assert.ok(sel.includes(ESCAPADO), 'nome do fluxo escapado e presente');

// a lista inteira tambem
const lista = F.poFluxoLista({ nome: XSS, card_inicial: 'a', cards: [{ id: 'a', tipo: 'texto', titulo: XSS, texto: XSS }] });
assert.ok(!lista.includes('<script'), 'lista nao deixa <script> passar');
assert.ok(lista.includes(ESCAPADO), 'lista mostra o texto escapado');

/* ---------- opcoes malformadas: renderiza e nao lanca ---------- */
for (const opcoes of [[], null, undefined, 'texto solto', 42, { a: 1 }, '[{"id":"o","rotulo":"Sim"}]', [null, 'x', { rotulo: 7 }]]) {
  const r = F.poFluxoCard({ id: 'a', tipo: 'botoes', titulo: 'T', opcoes });
  assert.ok(typeof r === 'string' && r.includes('fl-card'), 'opcoes ' + JSON.stringify(opcoes) + ' renderiza');
}
assert.ok(F.poFluxoCard({ id: 'a', tipo: 'botoes', titulo: 'T', opcoes: [] }).includes('sem opções'),
  'botoes sem opcoes sai marcado');
assert.ok(F.poFluxoCard({ id: 'a', tipo: 'botoes', titulo: 'T', opcoes: null }).includes('sem opções'),
  'opcoes null sai marcado');
assert.ok(F.poFluxoCard({ id: 'a', tipo: 'botoes', titulo: 'T', opcoes: '[{"id":"o","rotulo":"Sim"}]' }).includes('Sim'),
  'opcoes que vieram como texto JSON sao lidas');

/* ---------- tipo desconhecido ---------- */
const desc = F.poFluxoCard({ id: 'a', tipo: 'inventado<b>', titulo: 'T' });
assert.ok(desc.includes('fl-card--desconhecido'), 'tipo desconhecido sai marcado');
assert.ok(!desc.includes('<b>'), 'e o tipo desconhecido nao vira html');
assert.ok(desc.includes('inventado&lt;b&gt;'), 'e a cliente ve o nome estranho');
for (const c of [null, undefined, {}, 'x', 7, { tipo: null }]) {
  assert.ok(typeof F.poFluxoCard(c) === 'string', 'card degenerado ' + JSON.stringify(c) + ' nao lanca');
}

/* ---------- botoes com 3 opcoes mostra as 3 ---------- */
const tres = F.poFluxoCard({ id: 'a', tipo: 'botoes', titulo: 'Escolha', opcoes: [
  { id: '1', rotulo: 'Quero PDF', destino: 'b' }, { id: '2', rotulo: 'Falar com a equipe', destino: 'c' },
  { id: '3', rotulo: 'Agora não', destino: null }] });
for (const r of ['Quero PDF', 'Falar com a equipe', 'Agora não']) assert.ok(tres.includes(r), r + ' aparece');
assert.equal(conta(tres, 'class="fl-saida"'), 3, 'tres saidas');
// o rotulo na tela e o da cliente, nao o tecnico
assert.ok(tres.includes('Botões') && !tres.includes('>botoes<'), 'tipo aparece com rotulo legivel');

// saida nomeada: destino mostra o titulo do card, nao o id
const nom = F.poFluxoCard({ id: 'a', tipo: 'texto', titulo: 'Oi', proximo: 'b' }, { b: { id: 'b', tipo: 'fim', titulo: 'Despedida' } });
assert.ok(nom.includes('Despedida') && !nom.includes('>b<'), 'saida mostra o titulo do destino');
const orfa = F.poFluxoCard({ id: 'a', tipo: 'texto', titulo: 'Oi', proximo: 'sumiu' }, {});
assert.ok(orfa.includes('não existe'), 'destino que nao existe sai marcado');

// previa curta
assert.ok(F.poFluxoPrevia('a'.repeat(500)).length < 200, 'previa e cortada');
assert.equal(F.poFluxoPrevia(null), '', 'previa de nulo e vazia');

/* ---------- a lista ---------- */
const fl = F.poFluxoLista({ nome: 'Boas-vindas', card_inicial: 'b', cards: [
  { id: 'a', tipo: 'texto', titulo: 'A' }, { id: 'b', tipo: 'fim', titulo: 'B' }] });
assert.equal(conta(fl, 'class="fl-card '), 2, 'um card por item');
assert.equal(conta(fl, 'fl-card--inicial'), 1, 'so o card inicial e marcado');
for (const x of [null, {}, { cards: null }, { cards: 'x' }]) {
  assert.ok(F.poFluxoLista(x).includes('fl-vazio'), 'fluxo sem cards: ' + JSON.stringify(x));
}

/* ---------- seletor: os dois fluxos alcancaveis ---------- */
const dois = F.poFluxoSeletor([{ id: 'f1', nome: 'Boas-vindas e triagem' }, { id: 'f2', nome: 'Lista de espera' }], 'f2');
assert.ok(dois.includes('Boas-vindas e triagem') && dois.includes('Lista de espera'), 'os dois aparecem');
assert.ok(/value="f2" selected/.test(dois), 'o escolhido vem marcado');
assert.equal(conta(dois, 'selected'), 1, 'so um marcado');
assert.equal(F.poFluxoSeletor([], null), '', 'sem fluxos, sem seletor');

/* m1: card sem titulo / opcao sem rotulo saem MARCADOS, nao como <h3> vazio */
assert.ok(F.poFluxoCard({ id: 'a', tipo: 'texto' }).includes('(sem título)'), 'card sem titulo sai marcado');
assert.ok(F.poFluxoCard({ id: 'a', tipo: 'botoes', titulo: 'T', opcoes: [{ id: 'o' }] }).includes('(sem nome)'),
  'opcao sem rotulo sai marcada');
/* m2: saida sem destino sai marcada (a opcao 3 de `tres` tem destino null) */
assert.ok(tres.includes('sem destino'), 'saida sem destino aparece marcada');
/* m4: card que nao e objeto nao vira card vazio */
assert.ok(F.poFluxoLista({ cards: [null] }).includes('fl-vazio'), 'so card nulo: lista vazia');
assert.equal(conta(F.poFluxoLista({ cards: [null, { id: 'a', tipo: 'fim', titulo: 'F' }] }), 'class="fl-card '), 1,
  'card nulo no meio e ignorado');
/* m5: lista dinamica: o proximo vem DEPOIS DA ESCOLHA, nao sozinho */
const din = F.poFluxoCard({ id: 'a', tipo: 'lista', titulo: 'Qual roteiro?', config: { fonte: 'roteiros' }, opcoes: [], proximo: 'b' },
  { b: { id: 'b', tipo: 'pdf', titulo: 'Manda o PDF' } });
assert.ok(din.includes('Depois de escolher') && !din.includes('>Depois<'), 'lista dinamica: "Depois de escolher"');
assert.ok(F.poFluxoCard({ id: 'a', tipo: 'lista', titulo: 'x', config: '{"fonte":"roteiros"}', proximo: 'b' }, {}).includes('Depois de escolher'),
  'config que veio como texto JSON tambem vale');
assert.ok(F.poFluxoCard({ id: 'a', tipo: 'texto', titulo: 'x', proximo: 'b' }, {}).includes('>Depois<'),
  'card comum segue com "Depois"');
assert.ok(F.poFluxoCard({ id: 'a', tipo: 'lista', titulo: 'x', config: null, proximo: 'b' }, {}).includes('>Depois<'),
  'lista sem fonte dinamica segue com "Depois"');

/* ---------- ponto de chamada ---------- */
const semComentario = src.replace(/^\/\*[\s\S]*?\*\/[ \t]*/gm, '').replace(/\/\*[\s\S]*?\*\//g, '')
  .replace(/^[ \t]*\/\/.*$/gm, '');
// I1: ancorado no fetch, nao em comentario
assert.ok(/fetch\(\s*'\.\.\/fluxo\.php'/.test(semComentario), 'o fetch vai para ../fluxo.php');
assert.ok(/append\('modo',\s*modo\)/.test(semComentario) && /poFluxoPost\('ler'\)/.test(semComentario), 'em modo=ler');
assert.ok(/append\('sb_token'/.test(semComentario), 'e manda o token do login');
// contrato da resposta: a chave e `fluxos`, e erro nunca vira "nenhum"
assert.ok(/Array\.isArray\(j\.fluxos\)\s*\?\s*j\.fluxos\s*:\s*\[\]/.test(semComentario), 'le a chave `fluxos` da resposta');
assert.ok(/if \(!j\.ok\) throw new Error\(j\.error/.test(semComentario), 'ok:false vira erro com o motivo do servidor');
assert.ok(/catch \(e\) \{[^}]*fl-aviso">' \+ esc\(e\.message\)/.test(semComentario), 'o motivo aparece na tela, escapado');
assert.ok(/poFluxoLista\(/.test(src.replace(pega('poFluxoLista'), '')), 'a tela desenha pela poFluxoLista');
assert.ok(/poFluxoSeletor\(/.test(src.replace(pega('poFluxoSeletor'), '')), 'e escolhe o fluxo pelo poFluxoSeletor');

/* INERTE AO CARREGAR. O painel baixa o fluxo.js antes de existir a aba que o
   usa (a Task 7 liga o app.js): ele so pode DEFINIR coisas. A regra e sobre
   STATEMENT DE TOPO, nao sobre o texto: dentro de funcao pode ter
   addEventListener e setTimeout (a gaveta da Task 5 vai precisar). Allowlist
   de formas, nao denylist, e sobre o fonte sem comentarios. */
const PERMITIDA = [/^(?:async )?function \w+\(.*\{$/,/^(?:let|const) \w+ = (?:\[\]|null|\{\}|''|0|false|true);$/, /^\}$/];
semComentario.split('\n').filter(l => /^\S/.test(l)).forEach(l =>
  assert.ok(PERMITIDA.some(r => r.test(l)), 'linha de topo fora das formas permitidas (carregar nao pode executar nada): ' + l));

/* m7: o esc() da casa NAO escapa a aspa simples. Todo atributo emitido usa
   aspas DUPLAS; um atributo com aspa simples deixaria o texto da cliente fechar o atributo. */
assert.ok(!/=\\'/.test(semComentario), 'nenhum atributo com aspa simples');
assert.ok(/aspas DUPLAS/.test(src), 'o aviso sobre o esc() esta no fonte');

/* H2: o fallback "(sem titulo)" do card de DESTINO (dentro de nomeDe) */
assert.ok(F.poFluxoCard({ id: 'a', tipo: 'texto', titulo: 'Oi', proximo: 'b' }, { b: { id: 'b', tipo: 'fim' } }).includes('(sem título)</li>'),
  'destino sem titulo sai marcado, na saida do card de origem');

/* H3: a allowlist de topo exige fechar a chave na linha: nada de segundo statement colado */
assert.ok(!PERMITIDA[0].test('function q(){} poCarregaFluxo();'), 'allowlist recusa statement colado na declaracao');
assert.ok(PERMITIDA[0].test('function poFluxoTipos() {') && PERMITIDA[0].test('async function poCarregaFluxo() {'), 'e aceita a declaracao normal');

/* ---------- o canvas ---------- */
const nSetas = s => conta(s, 'class="fl-seta"');
const nNos = s => conta(s, 'class="fl-no"');
const base = { card_inicial: 'a', cards: [
  { id: 'a', tipo: 'botoes', titulo: 'Escolha', x: 40, y: 60,
    opcoes: [{ id: '1', rotulo: 'Sim', destino: 'b' }, { id: '2', rotulo: 'Nao', destino: null }, { id: '3', rotulo: 'Sei la', destino: 'sumiu' }],
    fallback: 'c', sem_resposta: null },
  { id: 'b', tipo: 'texto', titulo: 'B', x: 400, y: 60, proximo: 'c' },
  { id: 'c', tipo: 'fim', titulo: 'C', x: 400, y: 260 }] };
const cv = F.poFluxoCanvas(base);
assert.equal(nNos(cv), 3, 'um no por card');
assert.equal(conta(cv, 'class="fl-card '), 3, 'cada no leva o card');
// saidas existentes: a->b, a->c (fallback), b->c. nulo (opcao 2), id inexistente (opcao 3) e sem_resposta null NAO viram segmento
assert.equal(nSetas(cv), 3, 'um segmento por saida que existe: ' + nSetas(cv));
assert.ok(/<svg[^>]*class="fl-setas"/.test(cv), 'um svg de setas');
const posDe = html => [...html.matchAll(/class="fl-no" style="left:(-?\d+)px;top:(-?\d+)px/g)].map(m => [Number(m[1]), Number(m[2])]);
const pa = posDe(cv);
assert.equal(pa[1][0] - pa[0][0], 360, 'a distancia em x entre os cards e a gravada (400 - 40)');
assert.equal(pa[1][1], pa[0][1], 'e o y igual continua igual');
assert.ok(pa[2][1] - pa[0][1] >= 200, 'e a distancia em y nunca diminui (260 - 60)');
assert.equal(conta(cv, 'fl-card--inicial'), 1, 'o inicial e marcado tambem no canvas');
assert.ok(!/NaN|undefined|Infinity/.test(cv), 'nenhum NaN/undefined no canvas');
// um fluxo sem nenhuma saida: nenhum segmento
assert.equal(nSetas(F.poFluxoCanvas({ cards: [{ id: 'a', tipo: 'fim', titulo: 'F', x: 0, y: 0 }] })), 0, 'sem saida, sem segmento');
// so saida nula
assert.equal(nSetas(F.poFluxoCanvas({ cards: [{ id: 'a', tipo: 'texto', titulo: 'F', x: 0, y: 0, proximo: null, fallback: '', sem_resposta: null, opcoes: [{ destino: null }] }] })), 0,
  'saida nula nao vira segmento');
// destino que nao existe
assert.equal(nSetas(F.poFluxoCanvas({ cards: [{ id: 'a', tipo: 'texto', titulo: 'F', x: 0, y: 0, proximo: 'sumiu' }] })), 0, 'destino inexistente nao vira segmento');
// x/y ruins ou ausentes: posicao derivada da ordem, nunca NaN
const ruins = [undefined, null, '', 'abc', NaN, Infinity, {}, [], -5];
for (const v of ruins) {
  const r = F.poFluxoCanvas({ cards: [{ id: 'a', tipo: 'texto', titulo: 'A', x: v, y: v, proximo: 'b' }, { id: 'b', tipo: 'fim', titulo: 'B', x: v, y: v }] });
  assert.ok(!/NaN|undefined|Infinity|null/.test(r), 'x/y = ' + String(v) + ' nao vaza NaN: ' + r);
  assert.equal(nNos(r), 2, 'x/y ruim: os dois cards continuam la');
  assert.equal(nSetas(r), 1, 'x/y ruim: a seta continua');
}
const sem = F.poFluxoCanvas({ cards: [{ id: 'a', tipo: 'fim', titulo: 'A' }, { id: 'b', tipo: 'fim', titulo: 'B' }, { id: 'c', tipo: 'fim', titulo: 'C' }, { id: 'd', tipo: 'fim', titulo: 'D' }] });
const pos = [...sem.matchAll(/left:(\d+)px;top:(\d+)px/g)].map(m => m[1] + ',' + m[2]);
assert.equal(pos.length, 4, 'card sem posicao ganha posicao');
assert.equal(new Set(pos).size, 4, 'e cada um numa posicao diferente (nao empilham): ' + pos);
// x numerico em texto (jsonb pode devolver assim) vale
const pt = posDe(F.poFluxoCanvas({ cards: [{ id: 'a', tipo: 'fim', titulo: 'A', x: '120', y: '80' }, { id: 'b', tipo: 'fim', titulo: 'B', x: '220', y: '80' }] }));
assert.equal(pt[1][0] - pt[0][0], 100, 'x/y em texto numerico valem');

/* CRITICO: y NEGATIVO. A semente grava uma faixa superior com y < 0; clampar em 0
   empilhava cards de y diferente na mesma posicao e escondia um atras do outro. */
const neg = posDe(F.poFluxoCanvas({ cards: [
  { id: 'a', tipo: 'fim', titulo: 'A', x: 1280, y: 0 }, { id: 'b', tipo: 'fim', titulo: 'B', x: 1280, y: -240 }] }));
assert.notDeepEqual(neg[0], neg[1], 'y negativo diferente sai em posicao diferente');
assert.equal(neg[0][1] - neg[1][1], 240, 'e a distancia gravada se preserva');
assert.ok(neg.flat().every(n => n >= 0), 'e nada sai com coordenada negativa na tela');
const negx = posDe(F.poFluxoCanvas({ cards: [
  { id: 'a', tipo: 'fim', titulo: 'A', x: -300, y: 0 }, { id: 'b', tipo: 'fim', titulo: 'B', x: -100, y: 0 }] }));
assert.equal(negx[1][0] - negx[0][0], 200, 'x negativo tambem preserva a distancia');
assert.ok(negx.flat().every(n => n >= 0), 'e tambem nao sai negativo');
// as 20 posicoes da semente (fluxo de boas-vindas, ordem 1..20) dao 20 posicoes distintas
const SEMENTE = [[0,200],[320,0],[320,200],[320,400],[640,0],[960,0],[1280,0],[1600,-120],[1920,-120],[2240,-200],
  [2240,-40],[2560,-120],[1600,160],[1920,160],[2240,160],[640,400],[640,600],[1280,-240],[1920,-300],[1920,360]];
const sem20 = posDe(F.poFluxoCanvas({ cards: SEMENTE.map((p, i) => ({ id: 'c' + i, tipo: 'fim', titulo: 'C' + i, x: p[0], y: p[1] })) }));
assert.equal(sem20.length, 20, 'os 20 cards estao no canvas');
assert.equal(new Set(sem20.map(p => p.join(','))).size, 20, 'e em 20 posicoes distintas');
const alturas = [...F.poFluxoCanvas({ cards: SEMENTE.map((p, i) => ({ id: 'c' + i, tipo: 'fim', titulo: 'C' + i, x: p[0], y: p[1] })) })
  .matchAll(/width:260px;height:(\d+)px/g)].map(m => Number(m[1]));
assert.ok(alturas.length === 20 && alturas.every(n => n >= 150), 'cada no tem altura estimada');

/* A SEMENTE DE VERDADE (a migration le o mesmo arquivo que vai ao banco): os 20 cards do
   fluxo de boas-vindas, com titulo, texto, opcoes e saidas reais, nas posicoes reais. A
   semente espaca 200px e o card estimado mede de 150 a ~470, entao sem o empurrao 8 dos
   12 pares da mesma coluna se cobriam. Folga >= 24px (respiro) em TODA coluna, e a mesma folga minima. */
const mig = readFileSync(new URL('../supabase/migrations/2026-10-27-fluxo-semeado.sql', import.meta.url), 'utf8');
const grafo = JSON.parse(mig.split('$grafo$')[1]);
const posSem = Object.fromEntries([...mig.matchAll(/\('(c1000000-[0-9a-f-]+)', \d+, (-?\d+), (-?\d+)\)/g)].map(m => [m[1], [Number(m[2]), Number(m[3])]]));
const cardsSem = Object.values(grafo.cards).map(c => Object.assign({}, c, { x: posSem[c.id][0], y: posSem[c.id][1] }));
assert.equal(cardsSem.length, 20, 'a semente tem 20 cards');
const htmlSem = F.poFluxoCanvas({ card_inicial: grafo.inicial, cards: cardsSem });
const nosSem = [...htmlSem.matchAll(/class="fl-no" style="left:(-?\d+)px;top:(-?\d+)px;width:260px;height:(\d+)px/g)]
  .map(m => ({ x: Number(m[1]), y: Number(m[2]), h: Number(m[3]) }));
assert.equal(nosSem.length, 20, 'os 20 nos da semente saem');
assert.equal(new Set(nosSem.map(n => n.x + ',' + n.y)).size, 20, 'em 20 posicoes distintas');
const porColuna = {};
nosSem.forEach(n => (porColuna[n.x] = porColuna[n.x] || []).push(n));
let menorFolga = Infinity;
Object.values(porColuna).forEach(col => {
  col.sort((m, n) => m.y - n.y);
  for (let j = 1; j < col.length; j++) menorFolga = Math.min(menorFolga, col[j].y - (col[j - 1].y + col[j - 1].h));
});
assert.ok(isFinite(menorFolga), 'H1: houve ao menos um par na mesma coluna (senao a folga abaixo passaria vazia)');
assert.ok(menorFolga >= 24, 'nenhum no cobre ou encosta no o de baixo na mesma coluna da semente (menor folga: ' + menorFolga + ')');
const altSvg = Number(htmlSem.match(/<svg[^>]*height="(\d+)"/)[1]);
assert.ok(nosSem.every(n => n.y + n.h <= altSvg), 'e o svg e alto o bastante para o no mais baixo (' + altSvg + ')');
// as setas da semente continuam saindo do meio do no DESLOCADO
const noPorId = {};
[...htmlSem.matchAll(/class="fl-no" style="left:(-?\d+)px;top:(-?\d+)px;width:260px;height:(\d+)px"><article[^>]*data-card="([^"]+)"/g)]
  .forEach(m => { noPorId[m[4]] = { x: Number(m[1]), y: Number(m[2]), h: Number(m[3]) }; });
const setasSem = [...htmlSem.matchAll(/data-de="([^"]+)" data-para="([^"]+)" d="M(\d+) (\d+) C\d+ \d+ \d+ (\d+) (\d+) (\d+)"/g)];
assert.ok(setasSem.length >= 20, 'a semente tem as setas');
setasSem.forEach(m => {
  const de = noPorId[m[1]], para = noPorId[m[2]];
  assert.equal(Number(m[4]), de.y + Math.round(de.h / 2), 'seta ' + m[1].slice(-2) + '->' + m[2].slice(-2) + ' sai do meio do no de origem ja deslocado');
  assert.equal(Number(m[7]), para.y + Math.round(para.h / 2), 'e chega no meio do no de destino ja deslocado');
});

// XY-13: so um dos dois (x bom, y ausente) cai na grade; nunca "top:nullpx"
for (const c of [{ x: 100 }, { y: 100 }, { x: 100, y: null }, { x: null, y: 100 }]) {
  const r = F.poFluxoCanvas({ cards: [Object.assign({ id: 'a', tipo: 'fim', titulo: 'A' }, c)] });
  assert.ok(!/null|NaN|undefined/.test(r) && /left:\d+px;top:\d+px/.test(r), 'posicao pela metade nao vaza null: ' + JSON.stringify(c));
}

// a altura do no cresce com as saidas (nada e cortado): 5 saidas pedem mais que 0
const alt = html => Number(html.match(/width:260px;height:(\d+)px/)[1]);
const poucas = F.poFluxoCanvas({ cards: [{ id: 'a', tipo: 'texto', titulo: 'A', x: 0, y: 0 }] });
const muitas = F.poFluxoCanvas({ cards: [{ id: 'a', tipo: 'botoes', titulo: 'Boas-vindas ao robo', texto: 'x'.repeat(130), x: 0, y: 0,
  opcoes: [1, 2, 3].map(n => ({ id: 'o' + n, rotulo: 'Opcao numero ' + n, destino: 'b' })), fallback: 'b', sem_resposta: 'b' },
  { id: 'b', tipo: 'fim', titulo: 'Fim', x: 400, y: 0 }] });
assert.ok(alt(muitas) > alt(poucas) + 100, 'card com 5 saidas e texto longo ganha altura: ' + alt(poucas) + ' -> ' + alt(muitas));
assert.equal(conta(muitas, 'class="fl-saida"'), 5, 'e as 5 saidas estao no HTML');

// seta "para tras" (origem a direita do destino): a curva inteira cabe no svg
const tras = F.poFluxoCanvas({ cards: [
  { id: 'a', tipo: 'texto', titulo: 'A', x: 0, y: 0 }, { id: 'b', tipo: 'texto', titulo: 'B', x: 2560, y: -120, sem_resposta: 'a', fallback: 'a' }] });
const larg = Number(tras.match(/<svg[^>]*width="(\d+)"/)[1]);
const alt2 = Number(tras.match(/<svg[^>]*height="(\d+)"/)[1]);
const d = tras.match(/class="fl-seta"[^>]* d="(M[^"]+)"/)[1];
const nums = d.match(/-?\d+/g).map(Number);
assert.ok(nums.length >= 8, 'a seta tem os 8 pontos');
nums.forEach((n, i) => assert.ok(n >= 0 && n <= (i % 2 === 0 ? larg : alt2), 'ponto ' + i + ' (' + n + ') dentro do svg ' + larg + 'x' + alt2 + ': ' + d));

// card com so um dos dois cai EXATAMENTE onde cairia um card sem posicao nenhuma, e nao entra no minimo
const semPos = posDe(F.poFluxoCanvas({ cards: [{ id: 'a', tipo: 'fim', titulo: 'A' }] }))[0];
for (const c of [{ x: 100 }, { y: 100 }, { x: 100, y: null }, { x: null, y: 100 }]) {
  const m = posDe(F.poFluxoCanvas({ cards: [Object.assign({ id: 'a', tipo: 'fim', titulo: 'A' }, c)] }))[0];
  assert.deepEqual(m, semPos, 'posicao pela metade = posicao derivada: ' + JSON.stringify(c));
}
const comMeia = posDe(F.poFluxoCanvas({ cards: [
  { id: 'a', tipo: 'fim', titulo: 'A', x: 500, y: 500 }, { id: 'b', tipo: 'fim', titulo: 'B', x: 600, y: 500 },
  { id: 'c', tipo: 'fim', titulo: 'C', x: -1000 }, { id: 'd', tipo: 'fim', titulo: 'D', y: -1000 }] }));
assert.deepEqual(comMeia[0], [120, 120], 'o card sem posicao completa nao puxa o minimo: o primeiro valido fica na margem');
assert.equal(comMeia[1][0] - comMeia[0][0], 100, 'e a distancia entre os validos se preserva');

// a seta sai e chega no MEIO da altura dos nos
const una = F.poFluxoCanvas({ cards: [{ id: 'a', tipo: 'texto', titulo: 'A', x: 0, y: 0, proximo: 'b' }, { id: 'b', tipo: 'fim', titulo: 'B', x: 400, y: 0 }] });
const [ha, hb] = [...una.matchAll(/width:260px;height:(\d+)px/g)].map(m => Number(m[1]));
const [, topo] = posDe(una)[0];
const dd = una.match(/class="fl-seta"[^>]* d="M(\d+) (\d+) C\d+ \d+ \d+ (\d+) (\d+) (\d+)"/);
assert.equal(Number(dd[2]), topo + Math.round(ha / 2), 'a seta nasce no meio do card de origem');
assert.equal(Number(dd[5]), topo + Math.round(hb / 2), 'e chega no meio do card de destino');
// vazios e degenerados nao lancam
for (const x of [null, undefined, {}, { cards: null }, { cards: 'x' }, { cards: [null, 3] }]) {
  assert.ok(F.poFluxoCanvas(x).includes('fl-vazio'), 'canvas de fluxo sem cards: ' + JSON.stringify(x));
}
assert.equal(nNos(F.poFluxoCanvas({ cards: [null, { id: 'a', tipo: 'fim', titulo: 'A' }] })), 1, 'card nulo no meio e ignorado');
// o id nunca vira atributo cru
const cvx = F.poFluxoCanvas({ cards: [{ id: '"><img src=x>', tipo: 'texto', titulo: 'x', x: 1, y: 1, proximo: '"><img src=x>' }] });
assert.ok(!cvx.includes('<img'), 'id malicioso nao vira tag no canvas');

/* H1: a trava do atributo com aspa simples olha a SAIDA renderizada, nao a forma
   de escrever (aspas duplas, template literal, ...). Fixture com texto malicioso
   em tudo que a cliente escreve. */
const FX = { nome: XSS, card_inicial: XSS, cards: [
  { id: XSS, tipo: 'botoes', titulo: XSS, texto: XSS, x: 10, y: 10, opcoes: [{ id: XSS, rotulo: XSS, destino: XSS + 'b' }], proximo: XSS + 'b', fallback: XSS, sem_resposta: XSS + 'b' },
  { id: XSS + 'b', tipo: XSS, titulo: XSS, texto: XSS, x: 300, y: 10 }] };
const saidas = {
  card: F.poFluxoCard(FX.cards[0], { [XSS]: FX.cards[0], [XSS + 'b']: FX.cards[1] }, true),
  lista: F.poFluxoLista(FX),
  seletor: F.poFluxoSeletor([{ id: XSS, nome: XSS }], XSS),
  canvas: F.poFluxoCanvas(FX),
};
for (const [nome, s] of Object.entries(saidas)) {
  assert.ok(s.length > 50, nome + ' renderizou');
  assert.ok(!s.includes("='") && !s.includes("=\\'"), nome + ' nao emite atributo com aspa simples');
  assert.ok(!s.includes('<script'), nome + ' nao deixa <script> passar');
}

/* Quem escolhe lista x canvas e o CSS (D5), no ponto de chamada: a tela monta as
   duas vistas e o fluxo.css as alterna a 980px. JS nao le largura. */
const cssBruto = readFileSync(new URL('../painel/fluxo.css', import.meta.url), 'utf8');
const css = cssBruto.replace(/\/\*[\s\S]*?\*\//g, '');   // regra virada em comentario nao conta
const render = pega('poFluxoRender');
assert.ok(render.includes('class="fl-vista fl-vista--lista">\' + poFluxoLista(f)'), 'o render embrulha a lista em .fl-vista--lista');
assert.ok(render.includes('class="fl-vista fl-vista--canvas">\' + poFluxoCanvas(f)'), 'e o canvas em .fl-vista--canvas');
// bloco do @media por contagem de chaves, para a regra valer DENTRO dele
const iMq = css.indexOf('@media (min-width:980px){');
assert.ok(iMq >= 0, 'existe o @media (min-width:980px)');
let prof = 0, fim = -1;
for (let i = css.indexOf('{', iMq); i < css.length; i++) {
  if (css[i] === '{') prof++;
  if (css[i] === '}' && --prof === 0) { fim = i; break; }
}
assert.ok(fim > 0, 'o @media fecha');
const dentro = css.slice(iMq, fim), fora = css.slice(0, iMq) + css.slice(fim);
assert.ok(/\.fl-vista--lista\{display:none\}/.test(dentro), 'a partir de 980px a lista some');
assert.ok(/\.fl-vista--canvas\{display:block/.test(dentro), 'e o canvas aparece');
assert.ok(/\.fl-no\{position:absolute\}/.test(dentro), 'o no do canvas e absoluto');
assert.ok(/\.fl-canvas\{position:relative\}/.test(dentro), 'e o canvas e o referencial dele');
assert.ok(/\.fl-vista--canvas\{display:none\}/.test(fora), 'fora do @media o canvas esta escondido (celular)');
const regrasFora = [...fora.matchAll(/([^{}]+)\{([^}]*)\}/g)].map(m => ({ sel: m[1].trim(), corpo: m[2] }));
/* H2: CSS e case-insensitive (DISPLAY:NONE esconde igual) e esconder a lista tambem se faz com
   visibility:hidden ou pelo conteiner interno (.fl-lista). Fora do @media a lista tem que aparecer. */
const ESCONDE = /display\s*:\s*none|visibility\s*:\s*hidden/i;
const escondeNoCelular = regrasFora.filter(r => /fl-vista|fl-lista/i.test(r.sel) && !/^\.fl-vista--canvas$/.test(r.sel) && ESCONDE.test(r.corpo));
assert.deepEqual(escondeNoCelular, [], 'fora do @media (celular) nenhuma regra com seletor de .fl-vista (composto ou nao) esconde a lista');
assert.ok(regrasFora.some(r => r.sel === '.fl-vista--canvas' && /display\s*:\s*none/.test(r.corpo)), 'e o canvas segue escondido ali');
assert.ok(!/transition/.test(css) || !/prefers-reduced-motion/.test(css), 'sem regra de reduced-motion para animacao que nao existe');
assert.ok(/poFluxoCanvas\(/.test(src.replace(pega('poFluxoCanvas'), '')), 'a tela desenha pela poFluxoCanvas');
assert.ok(!/\.fl-no[^{]*\{[^}]*overflow/.test(css), 'o card do canvas nao corta o conteudo (as saidas so existem dentro dele)');
// nada de ler largura de tela no JS (denylist ampla; comentario de fim de linha nao conta)
const semTudo = semComentario.split('\n').map(l => l.replace(/\s\/\/\s.*$/, '')).join('\n');
assert.ok(!/innerWidth|innerHeight|outerWidth|matchMedia|clientWidth|offsetWidth|scrollWidth|screen\.|getBoundingClientRect|ResizeObserver|visualViewport/.test(semTudo),
  'o fluxo.js nao le largura de tela');

/* H2: a propria guarda pega os jeitos de esconder a lista (provada contra CSS plantado) */
for (const ruim of ['.fl-vista--lista{DISPLAY:NONE}', '.fl-vista--lista{visibility:hidden}', '.fl-lista{display:none}', '.fl-lista{Display : None}']) {
  const rr = [...ruim.matchAll(/([^{}]+)\{([^}]*)\}/g)].map(m => ({ sel: m[1].trim(), corpo: m[2] }));
  assert.equal(rr.filter(r => /fl-vista|fl-lista/i.test(r.sel) && !/^\.fl-vista--canvas$/.test(r.sel) && ESCONDE.test(r.corpo)).length, 1, 'a guarda de CSS pega: ' + ruim);
}

/* ====================== TASK 5: a gaveta do card ====================== */

for (const [t, n] of [['botoes', 1024], ['lista', 1024], ['pdf', 1024], ['texto', 4096], ['humano', 4096], ['fim', 4096]]) {
  assert.equal(F.poFluxoLimite(t), n, 'limite de ' + t);
}
for (const t of ['marca', 'ir_para_fluxo', 'inventado', null, undefined, 'toString', '__proto__']) {
  assert.equal(F.poFluxoLimite(t), null, 'tipo sem texto nao tem limite: ' + t);
}

/* pior caso: a expansao, nao o texto cru */
assert.equal(F.poFluxoPiorCaso('abc'), 3, 'texto sem variavel vale o proprio tamanho');
assert.equal(F.poFluxoPiorCaso('{roteiro}'), 60, '{roteiro} vale 60');
assert.equal(F.poFluxoPiorCaso('{nome}'), 40, '{nome} vale 40');
assert.equal(F.poFluxoPiorCaso('{data}'), 40, '{data} vale 40');
assert.equal(F.poFluxoPiorCaso('oi {nome}, {roteiro} em {data}'), 3 + 40 + 2 + 60 + 4 + 40, 'mistura soma cada uma');
assert.equal(F.poFluxoPiorCaso('{nome}{nome}'), 80, 'repetida conta cada vez');
assert.equal(F.poFluxoPiorCaso('a {inventada} b'), 4, 'variavel desconhecida e removida no envio: vale 0');
assert.equal(F.poFluxoPiorCaso(null), 0);
assert.equal(F.poFluxoPiorCaso('ção ✈'), 5, 'conta caracteres, nao bytes');
assert.equal(F.poFluxoPiorCaso('\u{1F600}'), 1, 'caractere fora do plano basico conta 1');
const mil = 'a'.repeat(1000 - '{roteiro}'.length) + '{roteiro}';
assert.equal(mil.length, 1000, 'o texto cru tem 1000');
assert.equal(F.poFluxoPiorCaso(mil), 1051, 'mas pode chegar a 1051 depois da expansao');
assert.ok(F.poFluxoContador(mil, 1024).estoura, 'o contador ACENDE: 1000 cru com {roteiro} estoura 1024');
assert.ok(!F.poFluxoContador('a'.repeat(1000), 1024).estoura, 'sem variavel, 1000 cabe');
assert.ok(!F.poFluxoContador('a'.repeat(1024), 1024).estoura, 'exatamente o limite nao estoura');
assert.ok(F.poFluxoContador('a'.repeat(1025), 1024).estoura, 'um a mais estoura');

/* a gaveta usa o pior caso e o limite do TIPO */
const gTxt = F.poFluxoGaveta({ id: 'a', tipo: 'botoes', titulo: 'T', texto: mil, opcoes: [{ id: 'o1', rotulo: 'Sim' }] });
assert.ok(gTxt.includes('fl-cont--estouro'), 'gaveta de botoes com 1000 + {roteiro} abre com o aviso aceso');
assert.ok(gTxt.includes('1051 de 1024'), 'e mostra o pior caso contra o limite do tipo');
const gOk = F.poFluxoGaveta({ id: 'a', tipo: 'texto', titulo: 'T', texto: mil });
assert.ok(!gOk.includes('fl-cont--estouro') && gOk.includes('1051 de 4096'), 'o mesmo texto num card de texto (4096) nao acende');
assert.ok(F.poFluxoGaveta({ id: 'a', tipo: 'pdf', titulo: 'T', texto: mil }).includes('1051 de 1024'), 'legenda de pdf: 1024');
assert.ok(F.poFluxoGaveta({ id: 'a', tipo: 'lista', titulo: 'T', texto: mil }).includes('1051 de 1024'), 'lista: 1024');
assert.ok(gOk.includes('data-limite="4096"') && gTxt.includes('data-limite="1024"'), 'o limite viaja no textarea para o contador ao vivo');

/* lembrete so em card que ESPERA */
for (const t of ['texto', 'pdf', 'humano', 'fim']) {
  assert.ok(!F.poFluxoGaveta({ id: 'a', tipo: t, titulo: 'T', texto: 'x', lembrete_ativo: true }).includes('lembrete'), t + ' nao espera: sem lembrete na gaveta');
}
for (const t of ['botoes', 'lista']) {
  const gl = F.poFluxoGaveta({ id: 'a', tipo: t, titulo: 'T', texto: 'x', lembrete_ativo: true, lembrete_texto: 'Oi?', opcoes: [{ id: 'o', rotulo: 'A' }] });
  assert.ok(gl.includes('data-campo="lembrete_ativo" checked') && gl.includes('data-campo="lembrete_texto"'), t + ' espera: oferece lembrete');
  assert.ok(!F.poFluxoGaveta({ id: 'a', tipo: t, titulo: 'T', texto: 'x', lembrete_ativo: false, opcoes: [] }).includes('lembrete_ativo" checked'), t + ': desligado nao vem marcado');
}
for (const t of ['marca', 'ir_para_fluxo', 'xyz']) {
  assert.ok(!F.poFluxoEditavel({ id: 'a', tipo: t }), t + ' nao e editavel');
  assert.ok(!F.poFluxoCard({ id: 'a', tipo: t, titulo: 'T' }).includes('fl-card__editar'), t + ': sem botao de editar');
  assert.ok(!F.poFluxoGaveta({ id: 'a', tipo: t, titulo: 'T' }).includes('<textarea'), t + ': gaveta sem campo');
}
assert.ok(F.poFluxoCard({ id: 'a', tipo: 'texto', titulo: 'T' }).includes('fl-card__editar'), 'card de texto tem botao de editar');

/* 3 botoes no maximo */
assert.equal(F.poFluxoMaxOpcoes('botoes'), 3);
assert.equal(F.poFluxoMaxOpcoes('lista'), null);
const op = n => Array.from({ length: n }, (_, i) => ({ id: 'o' + i, rotulo: 'B' + i }));
assert.ok(F.poFluxoGaveta({ id: 'a', tipo: 'botoes', titulo: 'T', texto: 'x', opcoes: op(3) }).includes('3 de 3 botões'), 'mostra o limite de 3');
assert.ok(!F.poFluxoGaveta({ id: 'a', tipo: 'botoes', titulo: 'T', texto: 'x', opcoes: op(3) }).includes('Não dá para salvar'), '3 botoes passa');
assert.ok(F.poFluxoGaveta({ id: 'a', tipo: 'botoes', titulo: 'T', texto: 'x', opcoes: op(4) }).includes('Não dá para salvar'), '4 botoes avisa');
assert.equal(F.poFluxoBloqueio({ tipo: 'botoes', opcoes: op(3) }), '', '3 botoes salva');
assert.ok(F.poFluxoBloqueio({ tipo: 'botoes', opcoes: op(4) }).includes('no máximo 3'), '4 botoes BLOQUEIA o salvar');
assert.ok(F.poFluxoBloqueio({ tipo: 'botoes', opcoes: JSON.stringify(op(5)) }).includes('5 botões'), '5 vindo como texto JSON tambem');
assert.equal(F.poFluxoBloqueio({ tipo: 'lista', opcoes: op(8) }), '', 'lista nao tem esse teto');

/* a gaveta nunca emite campo de estrutura */
const gTudo = F.poFluxoGaveta({ id: 'a', tipo: 'botoes', titulo: 'T', texto: 'x', proximo: 'b', fallback: 'b', sem_resposta: 'b',
  opcoes: [{ id: 'sair', rotulo: 'Sim', destino: 'b' }] });
assert.ok(!/data-campo="(?!texto"|lembrete_ativo"|lembrete_texto"|rotulo")/.test(gTudo), 'so os 4 campos tem data-campo');
assert.ok(!gTudo.includes('value="sair"') && !gTudo.includes('value="b"'), 'identificacao de opcao e destino nao viram campo');

/* o patch: exatamente as 4 chaves, mesmo com lixo em v */
const lixo = { texto: ' Oi ', lembrete_ativo: true, lembrete_texto: ' L ', rotulos: [' Novo '],
  id: 'x', tipo: 'fim', proximo: 'z', fallback: 'z', sem_resposta: 'z', x: 9, y: 9, titulo: 'hack', config: {}, fluxo_id: 'q', ordem: 3 };
const cardP = { id: 'a', tipo: 'botoes', opcoes: [{ id: 'sair', rotulo: 'Velho', destino: 'b', descricao: 'd' }] };
const pt0 = F.poFluxoPatch(cardP, lixo);
assert.deepEqual(Object.keys(pt0).sort(), ['lembrete_ativo', 'lembrete_texto', 'opcoes', 'texto'], 'o patch tem EXATAMENTE as quatro chaves');
assert.equal(pt0.texto, 'Oi'); assert.equal(pt0.lembrete_ativo, true); assert.equal(pt0.lembrete_texto, 'L');
assert.deepEqual(pt0.opcoes, [{ id: 'sair', rotulo: 'Novo', destino: 'b', descricao: 'd' }], 'so o rotulo muda; id, destino e descricao passam intactos');
assert.equal(cardP.opcoes[0].rotulo, 'Velho', 'e o card original nao e mutado');
assert.deepEqual(Object.keys(F.poFluxoPatch(cardP, {})), [], 'sem campos oferecidos, patch vazio');
assert.deepEqual(Object.keys(F.poFluxoPatch(cardP, { texto: 'a' })), ['texto'], 'so o que veio');
assert.equal(F.poFluxoPatch(cardP, { texto: '   ' }).texto, null, 'texto vazio vira null');
assert.equal(F.poFluxoPatch(cardP, { lembrete_ativo: 'sim' }).lembrete_ativo, false, 'lembrete_ativo so vale true de verdade');
assert.deepEqual(Object.keys(F.poFluxoPatch(null, null)), []);
const tres3 = [{ id: 'a', rotulo: '1', destino: 'x' }, { id: 'b', rotulo: '2' }, { id: 'c', rotulo: '3' }];
assert.equal(F.poFluxoOpcoesComRotulos(tres3, ['A', 'B', 'C', 'D', 'E']).length, 3, 'rotulo a mais nao cria opcao');
assert.deepEqual(F.poFluxoOpcoesComRotulos(tres3, ['A']).map(o => o.rotulo), ['A', '2', '3'], 'rotulo a menos deixa o resto');
assert.deepEqual(F.poFluxoOpcoesComRotulos(tres3, ['A', 'B', 'C']).map(o => o.id), ['a', 'b', 'c'], 'os ids seguem na ordem');
assert.equal(F.poFluxoOpcoesComRotulos(JSON.stringify(tres3), ['A']).length, 3, 'opcoes como texto JSON');
assert.deepEqual(F.poFluxoOpcoesComRotulos(null, ['A']), [], 'null vira lista vazia');
assert.deepEqual(F.poFluxoQuem({ id: ' abc ' }), { id: 'abc' });
for (const c of [null, undefined, {}, { id: '' }, { id: '  ' }, { id: null }]) assert.throws(() => F.poFluxoQuem(c), /identificação/, 'sem id nao filtra: ' + JSON.stringify(c));

/* TRAVA DE FONTE: o que poFluxoSalva/poFluxoPatch escrevem. Comentario nao conta. */
const semCom = t => t.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '');
const corpoSalva = semCom(pega('poFluxoSalva')), corpoPatch = semCom(pega('poFluxoPatch'));
const PROIBIDAS = /\b(id|tipo|proximo|fallback|sem_resposta|x|y)\b/;
assert.ok(!PROIBIDAS.test(corpoSalva), 'poFluxoSalva nao cita id, tipo, setas nem posicao: ' + (corpoSalva.match(PROIBIDAS) || [])[0]);
assert.ok(!PROIBIDAS.test(corpoPatch), 'poFluxoPatch tambem nao: ' + (corpoPatch.match(PROIBIDAS) || [])[0]);
assert.deepEqual([...corpoPatch.matchAll(/patch\.(\w+)\s*=/g)].map(m => m[1]).sort(), ['lembrete_ativo', 'lembrete_texto', 'opcoes', 'texto'],
  'poFluxoPatch atribui exatamente essas quatro chaves');
assert.ok(/\.from\('po_wa_cards'\)\.update\(patch\)\.match\(poFluxoQuem\(card\)\)\.select\(\)/.test(corpoSalva),
  'o PATCH grava SO o patch, na linha de poFluxoQuem(card), e confere a linha devolvida');
assert.ok(/poFluxoPatch\(card, v\)/.test(corpoSalva) && /poFluxoBloqueio\(card\)/.test(corpoSalva), 'e passa pelo bloqueio e pelo patch');
assert.ok(/if \(bloqueio\) throw new Error\(bloqueio\);/.test(corpoSalva), 'e o bloqueio (mais de 3 botoes) INTERROMPE o salvar');
assert.ok(/!r\.data \|\| !r\.data\.length/.test(corpoSalva), 'RLS que nega (zero linhas) vira erro, nao "salvo"');
assert.ok(!/\.(insert|upsert|delete)\(/.test(semComentario), 'o fluxo.js nunca insere, apaga nem faz upsert');
assert.equal(conta(semComentario, '.update('), 1, 'um unico update em todo o fluxo.js');

/* o aviso de variavel desconhecida vem do ENDPOINT */
assert.ok(/poFluxoPost\('conferir', \{ fluxo_id: f\.id \}\)/.test(semComentario), 'depois de salvar, confere pelo endpoint (modo=conferir)');
assert.ok(/poFluxoProblemas\(j\.problemas, id\)/.test(semComentario), 'e mostra os problemas que ELE devolveu');
assert.ok(!/vari[aá]vel desconhecida/i.test(semComentario), 'a tela nao escreve o proprio aviso de variavel desconhecida');
const prob = F.poFluxoProblemas([{ regra: 6, card: 'a', texto: XSS, bloqueia: true }, { regra: 2, card: 'b', texto: 'x', bloqueia: false }, null], 'a');
assert.ok(prob.includes(ESCAPADO) && !prob.includes('<script'), 'o texto do problema sai escapado');
assert.ok(prob.includes('fl-problema--bloqueia') && prob.includes('E mais 1 aviso em outro card'), 'so os do card; o resto vira contagem');
assert.ok(F.poFluxoProblemas([], 'a').includes('sem problemas'), 'fluxo limpo diz que esta limpo');

/* A ARMADILHA DO data-card DUPLICADO: o card sai na lista E no canvas. */
const dup = F.poFluxoLista({ cards: [{ id: 'a', tipo: 'texto', titulo: 'A' }] }) + F.poFluxoCanvas({ cards: [{ id: 'a', tipo: 'texto', titulo: 'A' }] });
assert.equal(conta(dup, 'data-card="a"'), 2, 'o data-card sai DUAS vezes (lista e canvas)');
const clique = semComentario.match(/function poFluxoClique[\s\S]*?\n\}/)[0];
assert.ok(/ev\.target\.closest\('\[data-card\]'\)/.test(clique), 'o clique acha o card pelo no clicado (closest), que e o da vista visivel');
assert.ok(!/querySelector\('\[data-card/.test(semComentario), 'ninguem acha o card por querySelector (pegaria a vista escondida)');
assert.ok(/querySelectorAll\('\[data-card\]'\)/.test(semComentario) && /getAttribute\('data-card'\) === String\(id\)/.test(semComentario),
  'marcar o card aberto trata as DUAS ocorrencias');
assert.ok(/box\.onclick = poFluxoClique/.test(semComentario), 'o clique e ligado no contentor');
assert.ok(/id="fl-gaveta" class="fl-gaveta" hidden/.test(semComentario), 'a gaveta nasce escondida');
assert.ok(/!poFluxoEditavel\(card\)\) return;/.test(semComentario), 'card sem texto nao abre gaveta');

/* escape: todo ponto de saida novo */
const gx = F.poFluxoGaveta({ id: XSS, tipo: 'botoes', titulo: XSS, texto: XSS, lembrete_ativo: true, lembrete_texto: XSS,
  opcoes: [{ id: XSS, rotulo: XSS, destino: XSS }] });
assert.ok(!gx.includes('<script') && !gx.includes('"aspas"'), 'gaveta nao deixa <script> nem aspa crua');
assert.equal(conta(gx, ESCAPADO), 4, 'titulo, texto, lembrete e rotulo saem escapados: ' + conta(gx, ESCAPADO));
assert.ok(!gx.includes("='"), 'gaveta nao emite atributo com aspa simples');
assert.ok(!F.poFluxoCard({ id: XSS, tipo: 'texto', titulo: XSS, texto: XSS }).includes("='"), 'card com botao de editar tambem');

/* ====================== RODADA DE CORRECAO 1 ====================== */

/* (1) As chaves EFETIVAS que o poFluxoSalva manda ao banco. O fonte INTEIRO roda com um
   `sb` duble (sem rede): qualquer auxiliar que enriqueca o patch (poFluxoCarimba...) entra
   no caminho e aparece aqui. Lista de palavras no corpo nao enxerga isso. */
async function salvaCom(card, v, resposta = { data: [{}], error: null }) {
  const reg = { tabela: null, enviado: null, filtro: null };
  const sb = { from: t => { reg.tabela = t; return { update: p => { reg.enviado = p; return { match: m => { reg.filtro = m; return { select: () => Promise.resolve(resposta) }; } }; } }; } };
  const salva = new Function('sb', 'esc', src + '; return poFluxoSalva;')(sb, s => s);
  await salva(card, v);
  return reg;
}
const QUATRO = ['lembrete_ativo', 'lembrete_texto', 'opcoes', 'texto'];
const regLixo = await salvaCom(cardP, lixo);
assert.deepEqual(Object.keys(regLixo.enviado).sort(), QUATRO, 'o que VAI AO BANCO tem exatamente as quatro chaves, mesmo com lixo em v');
assert.equal(regLixo.tabela, 'po_wa_cards');
assert.deepEqual(regLixo.filtro, { id: 'a' }, 'e o filtro e o id do card');
assert.deepEqual(Object.keys((await salvaCom(cardP, { texto: 'x' })).enviado), ['texto'], 'so o que a gaveta ofereceu');
for (const ruim of [{ ...cardP, opcoes: op(4) }]) await assert.rejects(() => salvaCom(ruim, { texto: 'x' }), /no máximo 3/, '4 botoes nao chega ao banco');
await assert.rejects(() => salvaCom({ tipo: 'texto' }, { texto: 'x' }), /identificação/, 'sem id nao chega ao banco');
/* COMPORTAMENTO da guarda: a RLS que nega nao da erro, so afeta zero linhas. Se isto virar
   "salvo", a cliente edita, le "salvo" e o robo segue mandando o texto ANTIGO a cliente real. */
await assert.rejects(() => salvaCom(cardP, { texto: 'x' }, { data: [], error: null }), /não gravou nada/, 'zero linhas (data: []) e erro, nao "salvo"');
await assert.rejects(() => salvaCom(cardP, { texto: 'x' }, { data: null, error: null }), /não gravou nada/, 'data: null tambem');
await assert.rejects(() => salvaCom(cardP, { texto: 'x' }, { data: [{}], error: { message: 'boom' } }), /Erro ao salvar: boom/, 'r.error vence, mesmo com linha devolvida, e traz o motivo');
await assert.rejects(() => salvaCom(cardP, { texto: 'x' }, { data: null, error: { message: 'boom' } }), /Erro ao salvar: boom/, 'r.error sem dados traz o motivo (nao a mensagem de zero linhas)');
assert.ok((await salvaCom(cardP, { texto: 'x' }, { data: [{}, {}], error: null })).enviado, 'resposta com linha passa');

/* (2) Lixo no opcoes nao desalinha rotulo e opcao */
const suja = ['lixo', { id: 'o1', rotulo: 'A', destino: 'dA' }, null, { id: 'o2', rotulo: 'B', destino: 'dB' }];
const gSuja = F.poFluxoGaveta({ id: 'a', tipo: 'botoes', titulo: 'T', texto: 'x', opcoes: suja });
assert.deepEqual([...gSuja.matchAll(/data-campo="rotulo" value="([^"]*)"/g)].map(m => m[1]), ['A', 'B'], 'a gaveta desenha as duas validas');
const ptSuja = F.poFluxoPatch({ opcoes: suja }, { rotulos: ['A2', 'B2'] }).opcoes;
assert.equal(ptSuja.length, 4, 'a lista crua segue com 4 entradas');
assert.equal(ptSuja[1].rotulo, 'A2', 'o1 recebe A2'); assert.equal(ptSuja[3].rotulo, 'B2', 'o2 recebe B2');
assert.equal(ptSuja[0], 'lixo'); assert.equal(ptSuja[2], null);
assert.deepEqual([ptSuja[1].id, ptSuja[1].destino, ptSuja[3].id, ptSuja[3].destino], ['o1', 'dA', 'o2', 'dB'], 'id e destino intactos');

/* (3) o botao de editar entra na altura do no */
const hDe = c => alt(F.poFluxoCanvas({ cards: [Object.assign({ id: 'a', titulo: 'T', texto: 'x'.repeat(130), x: 0, y: 0 }, c)] }));
assert.equal(hDe({ tipo: 'texto' }) - hDe({ tipo: 'marca' }), 44, 'card com botao de editar ganha 44px sobre o mesmo card sem botao');

/* (4) CSS da gaveta */
const regrasCss = [...css.matchAll(/([^{}]+)\{([^}]*)\}/g)].map(m => ({ sel: m[1].trim(), corpo: m[2] }));
const rg = sel => regrasCss.filter(r => r.sel === sel);
assert.ok(rg('.fl-gaveta').length === 1, 'existe a regra .fl-gaveta');
assert.ok(!ESCONDE.test(rg('.fl-gaveta')[0].corpo), '.fl-gaveta nao se esconde sozinha (so pelo [hidden])');
assert.ok(/position\s*:\s*fixed/i.test(rg('.fl-gaveta')[0].corpo) && /display\s*:\s*flex/i.test(rg('.fl-gaveta')[0].corpo), 'a gaveta e fixa e em flex');
assert.ok(rg('.fl-gaveta[hidden]').length === 1 && /display\s*:\s*none/i.test(rg('.fl-gaveta[hidden]')[0].corpo), '.fl-gaveta[hidden] esconde');
assert.ok(regrasCss.every(r => !/fl-gaveta/.test(r.sel) || r.sel === '.fl-gaveta[hidden]' || r.sel.startsWith('.fl-gaveta__') || r.sel === '.fl-gaveta' || !/display\s*:/i.test(r.corpo)),
  'nenhuma outra regra da gaveta mexe em display (o [hidden] fica de pe)');
const cor = sel => ((rg(sel)[0] || { corpo: '' }).corpo.match(/(?:^|;)\s*color\s*:\s*([^;]+)/i) || [])[1];
assert.ok(cor('.fl-cont') && cor('.fl-cont--estouro') && cor('.fl-cont') !== cor('.fl-cont--estouro'), 'o aviso de estouro tem cor diferente do contador comum');
assert.ok(/width\s*:\s*100%/.test((rg('.fl-campo')[0] || { corpo: '' }).corpo), 'o campo ocupa a largura da gaveta');

/* (5) espelho PHP <-> JS dos limites */
const php = readFileSync(new URL('../lib/wa-fluxo.php', import.meta.url), 'utf8');
const lim = Object.fromEntries([...php.match(/const WA_FLUXO_LIMITE\s*=\s*\[([\s\S]*?)\];/)[1].matchAll(/'(\w+)'\s*=>\s*(\d+)/g)].map(m => [m[1], Number(m[2])]));
assert.ok(Object.keys(lim).length >= 6, 'WA_FLUXO_LIMITE lido do PHP');
for (const [t, n] of Object.entries(lim)) assert.equal(F.poFluxoLimite(t), n, 'limite de ' + t + ' igual ao do PHP');
for (const t of F.poFluxoTipos ? ['marca', 'ir_para_fluxo'] : []) assert.ok(!(t in lim) && F.poFluxoLimite(t) === null, t + ' sem limite nos dois lados');
const vmax = Object.fromEntries([...php.match(/const WA_FLUXO_VAR_MAX\s*=\s*\[([^\]]*)\]/)[1].matchAll(/'(\w+)'\s*=>\s*(\d+)/g)].map(m => [m[1], Number(m[2])]));
assert.deepEqual(Object.keys(vmax).sort(), ['data', 'nome', 'roteiro'], 'as tres variaveis do PHP');
for (const [k, n] of Object.entries(vmax)) assert.equal(F.poFluxoPiorCaso('{' + k + '}'), n, '{' + k + '} vale o mesmo no PHP e no JS');
const limLemb = Number(php.match(/wa_fluxo_tam_pior_caso\(\$c\['lembrete_texto'\]\)\) > (\d+)/)[1]);
assert.ok(F.poFluxoGaveta({ id: 'a', tipo: 'botoes', titulo: 'T', texto: 'x', opcoes: op(1) }).includes('data-campo="lembrete_texto" data-limite="' + limLemb + '"'),
  'o limite do lembrete na gaveta e o do PHP (' + limLemb + ')');
const maxBot = Number(php.match(/\$c\['tipo'\] === 'botoes' && count\(\$c\['opcoes'\]\) > (\d+)/)[1]);
assert.equal(F.poFluxoMaxOpcoes('botoes'), maxBot, 'o maximo de botoes e o do PHP');

/* (6) o contador ao vivo, no ponto de chamada */
assert.ok(/g\.querySelectorAll\('textarea\[data-limite\]'\)\.forEach\(function \(ta\) \{/.test(semComentario), 'o contador percorre todo textarea com limite');
assert.ok(/ta\.addEventListener\('input'/.test(semComentario), 'cada textarea ouve a digitacao');
assert.ok(/poFluxoContador\(ta\.value, Number\(ta\.getAttribute\('data-limite'\)\)\)/.test(semComentario), 'e recalcula o pior caso com o limite do PROPRIO campo');
assert.ok(/classList\.toggle\('fl-cont--estouro', ct\.estoura\)/.test(semComentario) && /el\.textContent = ct\.texto/.test(semComentario), 'e atualiza texto e aviso');

/* (7) variavel: so no pior caso e na dica. Fora disso, nenhum nome de variavel no fonte */
const fora7 = semComentario.replace(pega('poFluxoPiorCaso'), '').split('\n').filter(l => !l.includes('class="fl-dica">Use {nome}')).join('\n');
assert.ok(!/\{(nome|roteiro|data)\}/.test(fora7), 'nenhum {variavel} fora do pior caso e da dica');
assert.ok(!/['"](nome|roteiro|data)['"]/.test(fora7), 'nenhuma lista propria de variaveis conhecidas');
assert.ok(!/\b(nome|roteiro)\s*:\s*\d/.test(fora7), 'nenhuma tabela propria de tamanhos de variavel');

/* (9) a leitura do DOM, no ponto de chamada: TODOS os rotulos, e so o que foi desenhado */
const leGaveta = new Function(src + '; return poFluxoLeGaveta;')();
const campo = (v, extra) => Object.assign({ value: v }, extra);
const mk = (mapa, todos) => ({ querySelector: s => mapa[s] || null, querySelectorAll: s => todos[s] || [] });
const lido = leGaveta(mk({ '[data-campo="texto"]': campo('T'), '[data-campo="lembrete_ativo"]': campo('', { checked: true }), '[data-campo="lembrete_texto"]': campo('L') },
  { '[data-campo="rotulo"]': [campo('A'), campo('B'), campo('C')] }));
assert.deepEqual(lido, { texto: 'T', lembrete_ativo: true, lembrete_texto: 'L', rotulos: ['A', 'B', 'C'] }, 'le os 3 rotulos e os demais campos');
assert.deepEqual(leGaveta(mk({}, {})), {}, 'campo que nao foi desenhado nao entra');

/* ====================== TASK 6: publicar, ligar e a lista de problemas ====================== */

const PB = (regra, card, texto, bloqueia) => ({ regra, card, texto, bloqueia });
const LIMPO = { conferido: true, problemas: [], editadoDesde: false };

/* ---- poFluxoPodePublicar: funcao pura, e ela decide o botao ---- */
assert.equal(F.poFluxoPodePublicar(LIMPO), true, 'conferido, limpo e sem edicao: pode');
assert.equal(F.poFluxoPodePublicar({ conferido: false, problemas: [], editadoDesde: false }), false, 'sem conferencia: nao pode');
assert.equal(F.poFluxoPodePublicar({ conferido: true, problemas: [], editadoDesde: true }), false, 'editou depois de conferir: nao pode');
assert.equal(F.poFluxoPodePublicar({ conferido: true, problemas: [PB(1, 'a', 'x', true)], editadoDesde: false }), false, 'problema que bloqueia: nao pode');
assert.equal(F.poFluxoPodePublicar({ conferido: true, problemas: [PB(15, 'a', 'x', false), PB(16, null, 'y', false)], editadoDesde: false }), true,
  'so aviso (bloqueia:false) NAO impede publicar');
assert.equal(F.poFluxoPodePublicar({ conferido: true, problemas: [PB(16, null, 'y', false), PB(2, 'a', 'z', true)], editadoDesde: false }), false, 'um bloqueio no meio de avisos impede');
assert.equal(F.poFluxoPodePublicar({ conferido: true, problemas: [PB(0, null, 'banco fora', false)], editadoDesde: false }), false, 'regra 0 (sistema) nunca libera');
for (const e of [null, undefined, {}, 'x', 7, [], { conferido: true }, { conferido: true, problemas: null, editadoDesde: false },
  { conferido: true, problemas: 'x', editadoDesde: false }, { conferido: 'true', problemas: [], editadoDesde: false }]) {
  assert.equal(F.poFluxoPodePublicar(e), false, 'estado degenerado nao libera: ' + JSON.stringify(e));
}
assert.equal(F.poFluxoPodePublicar({ conferido: true, problemas: [null, 3, PB(16, null, 'y', false)], editadoDesde: false }), true, 'lixo na lista e ignorado');

/* ---- poFluxoOrdemLigar: a espera ANTES da triagem ---- */
const fxs = (ativoEspera, ativoTriagem) => [{ id: 't', nome: 'Boas-vindas e triagem', ativo: !!ativoTriagem }, { id: 'e', nome: 'Lista de espera', ativo: !!ativoEspera }];
const ordem = F.poFluxoOrdemLigar(fxs(false, false));
assert.ok(ordem.includes('Lista de espera') && ordem.includes('Boas-vindas e triagem'), 'a frase cita os dois fluxos');
assert.ok(ordem.indexOf('Lista de espera') < ordem.indexOf('Boas-vindas e triagem'), 'e manda a espera ANTES');
assert.ok(F.poFluxoOrdemLigar(fxs(false, true)).includes('Lista de espera'), 'espera desligada: fala, mesmo com a triagem ligada');
assert.equal(F.poFluxoOrdemLigar(fxs(true, false)), '', 'espera ja ligada: cala');
assert.equal(F.poFluxoOrdemLigar(fxs(true, true)), '', 'os dois ligados: cala');
for (const x of [null, undefined, [], 'x', [null], [{ nome: 'Outro', ativo: false }]]) assert.equal(F.poFluxoOrdemLigar(x), '', 'sem a espera na lista, cala: ' + JSON.stringify(x));
assert.ok(F.poFluxoOrdemLigar([{ nome: 'Lista de espera', ativo: false }]) !== '', 'a espera sozinha, desligada, ja basta');

/* ---- regra 15 some quando a MESMA lista traz a regra 3 do mesmo card ---- */
const orfao = [PB(3, 'a', 'o card "a" nao e alcancado', true), PB(15, 'a', 'card a solto', false), PB(15, 'b', 'card b solto', false), PB(15, null, 'sem card', false)];
const semRep = F.poFluxoSemRepetido(orfao);
assert.deepEqual(semRep.map(p => p.regra + ':' + p.card), ['3:a', '15:b', '15:null'], '15 do card com 3 some; o 15 de outro card e o sem card ficam');
assert.equal(F.poFluxoSemRepetido([PB(15, 'a', 'x', false)]).length, 1, '15 sozinho continua (nao ha 3 para repetir)');
assert.equal(F.poFluxoSemRepetido([PB(3, 'a', 'x', true), PB(15, 'a', 'y', false), PB(15, 'a', 'z', false)]).length, 1, 'os dois 15 do card somem');
assert.equal(F.poFluxoSemRepetido([PB(3, 1, 'x', true), PB(15, '1', 'y', false)]).length, 1, 'id numero x texto: mesmo card');
assert.equal(F.poFluxoSemRepetido([PB(3, null, 'x', true), PB(15, null, 'y', false)]).length, 2, 'card nulo nao e "o mesmo card"');
assert.equal(F.poFluxoSemRepetido([PB(2, 'a', 'x', true), PB(15, 'a', 'y', false)]).length, 2, 'so a regra 3 esconde o 15');
assert.deepEqual(F.poFluxoSemRepetido(null), []);
const htmlOrf = F.poFluxoPainelProblemas(orfao, []);
assert.ok(htmlOrf.includes('nao e alcancado') && !htmlOrf.includes('card a solto'), 'a lista do painel: o card orfao aparece UMA vez');
assert.ok(htmlOrf.includes('card b solto'), 'e o aviso de outro card segue');
const gOrf = F.poFluxoProblemas(orfao, 'a');
assert.ok(gOrf.includes('nao e alcancado') && !gOrf.includes('card a solto'), 'na gaveta tambem: sem a linha repetida');

/* ---- a lista do painel: bloqueios x avisos, escapada, com atalho para o card ---- */
const cardsP = [{ id: 'a', tipo: 'texto', titulo: 'A' }, { id: 'm', tipo: 'marca', titulo: 'M' }, { id: XSS, tipo: 'texto', titulo: 'X' }];
const lp = F.poFluxoPainelProblemas([PB(6, 'a', XSS, true), PB(16, null, 'sem fim', false), PB(2, 'm', 'marca ruim', true), PB(15, XSS, XSS, false)], cardsP);
assert.equal(conta(lp, 'fl-problema--bloqueia'), 2, 'dois bloqueios marcados');
assert.ok(lp.includes('Impede de publicar') && lp.includes('Avisos'), 'bloqueios e avisos em blocos separados');
assert.ok(lp.indexOf('Impede de publicar') < lp.indexOf('sem fim'), 'bloqueios primeiro');
assert.ok(lp.includes('data-abrir="a"'), 'card editavel ganha o atalho para abrir');
assert.ok(!lp.includes('data-abrir="m"'), 'card que a gaveta nao edita nao ganha atalho morto');
assert.ok(!/data-abrir="null"|data-abrir=""/.test(lp), 'problema do fluxo (card nulo) nao tem atalho');
assert.ok(lp.includes('data-abrir="' + ESCAPADO.slice(0, 20)), 'o id do atalho sai escapado');
assert.ok(!lp.includes('<script') && !lp.includes("='"), 'a lista do painel nao deixa <script> nem aspa simples');
assert.ok(conta(lp, ESCAPADO) >= 2, 'texto do problema escapado');
assert.ok(F.poFluxoPainelProblemas([], []).includes('sem problemas'), 'lista vazia: sem problemas');
assert.ok(F.poFluxoPainelProblemas(null, null).includes('sem problemas'), 'e entrada degenerada nao lanca');
const soAviso = F.poFluxoPainelProblemas([PB(16, null, 'aviso', false)], []);
assert.ok(!soAviso.includes('fl-problema--bloqueia') && !soAviso.includes('Impede de publicar'), 'so aviso: nada de bloqueio na tela');

/* regra 0 = falha de INFRAESTRUTURA, nao defeito do fluxo dela */
const r0 = F.poFluxoPainelProblemas([PB(0, null, 'O fluxo não existe ou o banco está fora.', true)], []);
assert.ok(r0.includes('fl-sistema'), 'regra 0 ganha moldura de sistema');
assert.ok(!r0.includes('fl-problema--bloqueia') && !r0.includes('Impede de publicar'), 'e NAO vira "problema do seu fluxo"');
assert.ok(/não é um defeito do seu fluxo/.test(r0), 'e a tela diz que nao e o fluxo dela');
assert.ok(F.poFluxoPainelProblemas([PB(0, null, XSS, true)], []).includes(ESCAPADO), 'o detalhe do sistema sai escapado');
assert.ok(F.poFluxoResultado('publicar', { http: 200, j: { ok: true, versao: XSS, problemas: [] } }, []).includes(ESCAPADO), 'a versao do 200 sai escapada');
assert.ok(F.poFluxoResultado('ligar', { http: 400, j: { ok: false, error: XSS } }, []).includes(ESCAPADO), 'o erro generico do ligar sai escapado');
assert.ok(F.poFluxoEstado({ ...{ id: 'f', nome: 'N', ativo: true, versao_atual: 'v', publicado_igual: false }, versao_numero: XSS }).includes(ESCAPADO), 'a versao do estado sai escapada');
assert.ok(F.poFluxoPainelProblemas([PB(0, null, 'infra', true), PB(2, 'a', 'real', true)], cardsP).includes('fl-sistema'),
  'regra 0 junto de outros: ainda e falha de sistema (nao ha como confiar na lista)');

/* problema de nivel de FLUXO (card nulo) tem lugar proprio, nao e "aviso em outro card" */
const nivel = F.poFluxoProblemas([PB(7, null, 'porta repetida', true), PB(16, null, 'sem fim', false)], 'a');
assert.ok(nivel.includes('porta repetida') && nivel.includes('sem fim'), 'os problemas do fluxo aparecem na gaveta');
assert.ok(!/em outros? cards?/.test(nivel), 'e NAO como "aviso em outro card"');
assert.ok(nivel.includes('fl-problemas--fluxo'), 'num bloco proprio');
const misto = F.poFluxoProblemas([PB(7, null, 'porta repetida', true), PB(2, 'b', 'x', false), PB(2, 'c', 'y', false)], 'a');
assert.ok(misto.includes('E mais 2 avisos em outros cards') && misto.includes('porta repetida'), 'o card nulo nao entra na contagem de outros cards');
assert.ok(F.poFluxoProblemas([PB(7, null, XSS, true)], 'a').includes(ESCAPADO), 'o bloco do fluxo escapa');

/* a gaveta liga ao Publicar: estouro avisa que o fluxo nao publica assim */
assert.ok(F.poFluxoContador('a'.repeat(1025), 1024).texto.includes('não pode ser publicado'), 'estouro: diz que o fluxo nao pode ser publicado assim');
assert.ok(!F.poFluxoContador('abc', 1024).texto.includes('publicado'), 'e so quando estoura');

/* ---- poFluxoCardCulpado: qual gaveta abrir quando o Publicar recusa ---- */
assert.equal(F.poFluxoCardCulpado([PB(16, null, 'x', false), PB(6, 'a', 'x', true)], cardsP), 'a', 'o primeiro bloqueio de card editavel');
assert.equal(F.poFluxoCardCulpado([PB(15, 'a', 'x', false)], cardsP), '', 'aviso nao e culpado');
assert.equal(F.poFluxoCardCulpado([PB(2, 'm', 'x', true), PB(6, 'a', 'x', true)], cardsP), 'a', 'pula o card que a gaveta nao edita');
assert.equal(F.poFluxoCardCulpado([PB(2, 'm', 'x', true)], cardsP), '', 'so card nao editavel: nada a abrir');
assert.equal(F.poFluxoCardCulpado([PB(6, 'sumiu', 'x', true)], cardsP), '', 'card que nao existe: nada a abrir');
assert.equal(F.poFluxoCardCulpado([PB(0, 'a', 'x', true)], cardsP), '', 'regra 0 nao aponta card nenhum');
assert.equal(F.poFluxoCardCulpado(null, null), '');

/* ---- poFluxoEstado: "tem edicao nao publicada" e o que esta no ar ---- */
const base6 = { id: 'f1', nome: 'Boas-vindas', ativo: false, versao_atual: 'v1', versao_numero: 3, publicado_igual: true };
const eOk = F.poFluxoEstado(base6);
assert.ok(eOk.includes('Desligado') && eOk.includes('sem edição pendente') && eOk.includes('versão 3'), 'publicado e igual');
const eEd = F.poFluxoEstado({ ...base6, publicado_igual: false });
assert.ok(eEd.includes('Tem edição não publicada'), 'publicado_igual:false = tem edicao nao publicada');
assert.ok(!eEd.includes('versão anterior'), 'desligado: nada no ar, entao nao fala do que esta no ar');
const eLig = F.poFluxoEstado({ ...base6, ativo: true, publicado_igual: false });
assert.ok(eLig.includes('Ligado') && eLig.includes('Tem edição não publicada') && /o que está no ar é a versão anterior/i.test(eLig),
  'LIGADO com edicao: avisa que o que esta no ar e a versao anterior');
assert.ok(!F.poFluxoEstado({ ...base6, ativo: true }).includes('versão anterior'), 'ligado e igual: sem esse aviso');
assert.ok(F.poFluxoEstado({ ...base6, versao_atual: null, versao_numero: null, publicado_igual: false }).includes('Nunca foi publicado'), 'nunca publicado');
assert.ok(F.poFluxoEstado({ ...base6, publicado_igual: null }).includes('Não consegui confirmar'), 'estado desconhecido nao finge que esta tudo igual');
assert.ok(!F.poFluxoEstado({ ...base6, publicado_igual: undefined }).includes('sem edição pendente'), 'e ausente tambem nao');
assert.equal(F.poFluxoEstado(null), '');
assert.ok(F.poFluxoEstado({ ...base6, nome: XSS }).includes(ESCAPADO), 'nome do fluxo escapado no estado');

/* ---- a barra: Publicar nasce desabilitado; Ligar e botao SEPARADO ---- */
const tagBotao = (html, acao) => (html.match(new RegExp('<button[^>]*data-bar="' + acao + '"[^>]*>')) || [''])[0];
const flx = { ...base6 };
const barra = (e, f = flx) => F.poFluxoBarra(f, e, [f]);
assert.ok(/ disabled/.test(tagBotao(barra(undefined), 'publicar')), 'sem estado nenhum o Publicar NASCE desabilitado');
assert.ok(/ disabled/.test(tagBotao(barra({}), 'publicar')), 'estado vazio: desabilitado');
assert.ok(!/ disabled/.test(tagBotao(barra(LIMPO), 'publicar')), 'conferencia limpa habilita');
assert.ok(/ disabled/.test(tagBotao(barra({ ...LIMPO, editadoDesde: true }), 'publicar')), 'e uma edicao DESABILITA de novo');
assert.ok(!/ disabled/.test(tagBotao(barra({ ...LIMPO, problemas: [PB(16, null, 'aviso', false)] }), 'publicar')), 'aviso nao desabilita');
assert.ok(/ disabled/.test(tagBotao(barra({ ...LIMPO, problemas: [PB(1, 'a', 'x', true)] }), 'publicar')), 'bloqueio desabilita');
/* a barra SEGUE a funcao em TODA combinacao: nenhuma outra condicao escreve o disabled */
for (const conferido of [true, false, undefined]) for (const editadoDesde of [true, false, undefined])
  for (const problemas of [[], [PB(16, null, 'a', false)], [PB(1, 'a', 'x', true)], [PB(0, null, 's', true)], undefined, null])
    for (const f of [flx, { ...flx, ativo: true }, { ...flx, publicado_igual: false }, { ...flx, versao_atual: null }]) {
      const e = { conferido, editadoDesde, problemas };
      assert.equal(/ disabled/.test(tagBotao(barra(e, f), 'publicar')), !F.poFluxoPodePublicar(e), 'Publicar = !poFluxoPodePublicar: ' + JSON.stringify(e) + ' / ' + f.ativo);
    }
const tPub = tagBotao(barra(LIMPO), 'publicar'), tLig = tagBotao(barra(LIMPO), 'ligar');
assert.ok(tPub && tLig && tPub !== tLig, 'Publicar e Ligar sao dois botoes');
assert.ok(!/ disabled/.test(tLig), 'Ligar de fluxo publicado e desligado esta habilitado');
assert.ok(/ disabled/.test(tagBotao(barra(LIMPO, { ...flx, versao_atual: null, versao_numero: null, publicado_igual: false }), 'ligar')), 'Ligar de fluxo nunca publicado: desabilitado');
assert.ok(!tagBotao(barra(LIMPO, { ...flx, ativo: true }), 'ligar') && tagBotao(barra(LIMPO, { ...flx, ativo: true }), 'desligar'), 'fluxo ligado oferece Desligar, nao Ligar');
assert.ok(tagBotao(barra(LIMPO), 'conferir') && !/ disabled/.test(tagBotao(barra({}), 'conferir')), 'Conferir sempre disponivel');
assert.ok(barra({}, flx).includes('Confira'), 'desabilitado diz o que falta fazer');
assert.ok(F.poFluxoPorqueNao({ conferido: true, problemas: [], editadoDesde: true }).includes('depois'), 'editou: pede nova conferencia');
assert.equal(F.poFluxoPorqueNao(LIMPO), '', 'liberado: nada a explicar');
assert.ok(F.poFluxoPorqueNao({ conferido: true, problemas: [PB(1, 'a', 'x', true)], editadoDesde: false }).includes('impedem'), 'bloqueio: diz que ha problema');
/* a ordem de ligar aparece na barra, e some quando a espera esta ligada */
const bOrdem = F.poFluxoBarra(flx, LIMPO, fxs(false, false));
assert.ok(bOrdem.includes('fl-ordem') && bOrdem.includes('Lista de espera'), 'a barra mostra a ordem de ligar');
assert.ok(!F.poFluxoBarra(flx, LIMPO, fxs(true, false)).includes('fl-ordem'), 'e some com a espera ligada');
const bx = F.poFluxoBarra({ ...flx, nome: XSS }, LIMPO, [{ nome: XSS, ativo: false }]);
assert.ok(bx.includes(ESCAPADO) && !bx.includes('<script') && !bx.includes("='"), 'a barra escapa');
assert.ok(F.poFluxoBarra(null, null, null).length >= 0, 'barra degenerada nao lanca');

/* ---- poFluxoResultado: o que a tela diz de cada resposta do endpoint ---- */
const RP = (http, j) => ({ http, j });
const rc = F.poFluxoResultado('conferir', RP(200, { ok: true, problemas: [] }), []);
assert.ok(rc.includes('sem problemas'), 'conferir limpo');
// publicar 200: os problemas sao AVISOS e a cliente PRECISA ve-los
const rp = F.poFluxoResultado('publicar', RP(200, { ok: true, versao: 4, problemas: [PB(16, null, 'Este fluxo não tem nenhum card de fim.', false)] }), []);
assert.ok(rp.includes('versão 4'), 'publicado: diz a versao');
assert.ok(rp.includes('Este fluxo não tem nenhum card de fim'), 'e MOSTRA o aviso que veio no 200');
assert.ok(!rp.includes('fl-res--erro') && !rp.includes('fl-sistema') && !rp.includes('fl-problema--bloqueia'), 'e o 200 nao e tratado como erro');
assert.ok(/não liga/.test(rp), 'diz que publicar nao liga o fluxo');
assert.ok(F.poFluxoResultado('publicar', RP(200, { ok: true, versao: 4, problemas: [] }), []).includes('versão 4'), '200 sem aviso');
// publicar 400: bloqueios
const rr = F.poFluxoResultado('publicar', RP(400, { ok: false, problemas: [PB(6, 'a', 'texto estoura', true), PB(16, null, 'sem fim', false)] }), cardsP);
assert.ok(rr.includes('não foi publicado') && rr.includes('texto estoura') && rr.includes('fl-problema--bloqueia'), 'recusa mostra o motivo');
assert.ok(rr.includes('data-abrir="a"') && rr.includes('sem fim'), 'com o atalho para o card e os avisos junto');
// publicar 400 com regra 0: infraestrutura
const r0p = F.poFluxoResultado('publicar', RP(400, { ok: false, problemas: [PB(0, null, 'banco fora', true)] }), cardsP);
assert.ok(r0p.includes('fl-sistema') && /não é um defeito do seu fluxo/.test(r0p), 'regra 0 no 400: falha de sistema');
assert.ok(!r0p.includes('fl-problema--bloqueia') && !r0p.includes('não foi publicado'), 'sem a moldura de "seu fluxo tem erro"');
// 400/502 sem problemas: o erro do servidor
const r502 = F.poFluxoResultado('publicar', RP(502, { ok: false, error: 'Não consegui ler o banco agora.' }), []);
assert.ok(r502.includes('Não consegui ler o banco agora') && r502.includes('fl-sistema'), 'erro sem problemas: moldura de sistema com o motivo');
// 409 da porta ocupada
const r409 = F.poFluxoResultado('ligar', RP(409, { ok: false, error: 'Já existe outro fluxo ligado nesta porta de entrada: "' + XSS + '". Desligue esse fluxo antes ou mude a porta deste.' }), []);
assert.ok(r409.includes(ESCAPADO), 'o 409 mostra o NOME do fluxo que ocupa a porta (escapado)');
assert.ok(r409.includes('A porta de entrada já está ocupada.') && r409.includes('Desligue esse fluxo'), 'a moldura diz que a porta esta ocupada, e o que fazer');
assert.ok(F.poFluxoResultado('ligar', RP(409, { ok: false, error: 'ocupado por "Fluxo X"' }), []).includes('Fluxo X'), 'nome comum tambem aparece');
assert.ok(F.poFluxoResultado('ligar', RP(400, { ok: false, error: 'Publique o fluxo antes de ligar.' }), []).includes('Publique o fluxo antes de ligar.'), 'ligar sem publicar: o motivo');
assert.ok(F.poFluxoResultado('ligar', RP(200, { ok: true, ativo: true }), []).includes('ligado'), 'ligar ok');
assert.ok(F.poFluxoResultado('desligar', RP(200, { ok: true, ativo: false }), []).includes('desligado'), 'desligar ok');
assert.ok(F.poFluxoResultado('publicar', { http: 0, j: null, erro: XSS }, []).includes(ESCAPADO), 'falha de rede: o motivo, escapado');
for (const x of [null, undefined, {}, { http: 200 }, { http: 200, j: {} }, { http: 400, j: { ok: false, problemas: 'x' } }])
  for (const m of ['conferir', 'publicar', 'ligar', 'desligar', 'xyz']) assert.ok(typeof F.poFluxoResultado(m, x, null) === 'string', 'resposta degenerada nao lanca: ' + m + JSON.stringify(x));
const fxr = F.poFluxoResultado('publicar', RP(400, { ok: false, problemas: [PB(6, XSS, XSS, true), PB(15, XSS, XSS, false)] }), cardsP);
assert.ok(!fxr.includes('<script') && !fxr.includes("='") && !fxr.includes('"aspas"'), 'resultado nao deixa <script>, aspa simples nem aspa crua');

/* ---- trava de FONTE ---- */
const corpo = nome => semCom(pega(nome));
// 1. o disabled do Publicar sai da poFluxoPodePublicar e de nenhuma outra condicao
assert.ok(/data-bar="publicar"'\s*\+\s*\(poFluxoPodePublicar\(estado\) \? '' : ' disabled'\)/.test(corpo('poFluxoBarra')),
  'o disabled do Publicar e escrito a partir de poFluxoPodePublicar(estado)');
assert.ok(!/\.conferido|\.editadoDesde|\.problemas/.test(corpo('poFluxoBarra')), 'a barra nao le o estado por conta propria');
for (const n of ['poFluxoPublicar', 'poFluxoConfere', 'poFluxoLiga', 'poFluxoBarraAcao'])
  assert.ok(!/\.disabled\s*=|setAttribute\('disabled'/.test(corpo(n)), n + ' nao escreve disabled na mao');
// 2. Publicar e Ligar sao dois atos
assert.ok(/poFluxoChama\('publicar'/.test(corpo('poFluxoPublicar')) && !/ligar|desligar/.test(corpo('poFluxoPublicar')), 'Publicar so chama publicar');
assert.ok(/poFluxoChama\(modo,/.test(corpo('poFluxoLiga')) && !/publicar/.test(corpo('poFluxoLiga')), 'Ligar nao publica');
assert.ok(/\['ligar', 'desligar'\]\.indexOf\(modo\) < 0/.test(corpo('poFluxoLiga')), 'e so aceita os dois modos de ligar');
assert.ok(/poFluxoPodePublicar\(poFluxoConf\(/.test(corpo('poFluxoPublicar')), 'o proprio Publicar confere a funcao antes de enviar');
// 3. depois de salvar, o publicado_igual vem do `ler`, nunca da memoria
assert.ok(!/publicado_igual\s*=(?!=)(?!\s*null\b)/.test(semComentario), 'ninguem escreve publicado_igual na memoria (so null, que significa desconhecido)');
assert.ok(/await poFluxoRecarrega\(\)/.test(corpo('poFluxoGavetaSalva')), 'a gaveta recarrega pelo `ler` depois de salvar');
assert.ok(/poFluxoPost\('ler'\)/.test(corpo('poFluxoRecarrega')), 'e o recarregar chama o modo ler');
// 4. nenhuma das 16 regras e reimplementada aqui: so 0 (infra), 3 e 15 (a deduplicacao)
const regrasCitadas = [...semComentario.matchAll(/regra\s*[=!]==?\s*(\d+)|Number\(\w+\.regra\)\s*[=!]==?\s*(\d+)/g)].map(m => Number(m[1] || m[2]));
assert.ok(regrasCitadas.length > 0, 'a dedupe e a falha de sistema citam regras');
assert.ok(regrasCitadas.every(n => [0, 3, 15].includes(n)), 'so as regras 0, 3 e 15 aparecem no fonte: ' + [...new Set(regrasCitadas)]);
// 5. cliques: atalho de problema e barra
assert.ok(/closest\('\[data-bar\]'\)/.test(corpo('poFluxoClique')) && /closest\('\[data-abrir\]'\)/.test(corpo('poFluxoClique')), 'o clique trata a barra e o atalho dos problemas');

/* ---- o ponto de chamada, com o DOM e a rede em duble ---- */
const mkFluxo = extra => Object.assign({ id: 'f1', nome: 'Boas-vindas e triagem', ativo: false, versao_atual: 'v1', versao_numero: 3, publicado_igual: true,
  card_inicial: 'a', cards: [{ id: 'a', tipo: 'texto', titulo: 'Oi', texto: 'x', proximo: 'b' }, { id: 'b', tipo: 'marca', titulo: 'Nota' }] }, extra);
function ambiente(tabela, fluxos, confirma = true) {
  const pedidos = [], els = {};
  const novo = () => ({ innerHTML: '', hidden: true, onclick: null, classList: { toggle() {} }, querySelector: () => null, querySelectorAll: () => [], addEventListener() {} });
  const document = { querySelector: s => (els[s] = els[s] || novo()), querySelectorAll: () => [] };
  class FD { constructor() { this.m = {}; } append(k, v) { this.m[k] = v; } }
  const fetchD = async (url, o) => {
    pedidos.push(o.body.m.modo);
    if (tabela.__antes) tabela.__antes(o.body.m.modo);
    const r = tabela[o.body.m.modo] || (o.body.m.modo === 'ler' ? { status: 200, json: { ok: true, fluxos: JSON.parse(JSON.stringify(fluxos)) } } : null);
    if (!r) throw new Error('modo nao previsto no teste: ' + o.body.m.modo);
    return { status: r.status, json: async () => { if (r.semJson) throw new Error('x'); return r.json; } };
  };
  const sbD = { auth: { getSession: async () => ({ data: { session: { access_token: 't' } } }) },
    from: () => ({ update: () => ({ match: () => ({ select: () => Promise.resolve({ data: [{}], error: null }) }) }) }) };
  let pediuConfirm = 0;
  const api = new Function('sb', 'fetch', 'FormData', 'document', 'confirm', ESC + src +
    '; return {poFluxoPublicar, poFluxoConfere, poFluxoLiga, poFluxoGavetaSalva, poFluxoChama, poFluxoBarraAcao, poFluxoAbre, poFluxoRender,' +
    ' fixa(l){PO_FLUXOS=l;PO_FLUXO_SEL=l[0].id}, fluxos(){return PO_FLUXOS}, conf(id){return poFluxoConf(id)}, res(){return PO_FLUXO_RES}, ocupado(){return PO_FLUXO_OCUPADO}};')(
    sbD, fetchD, FD, document, () => { pediuConfirm++; return confirma; });
  api.fixa(JSON.parse(JSON.stringify(fluxos)));
  return Object.assign(api, { pedidos, els, confirmou: () => pediuConfirm });
}
const JSON_ = (status, json) => ({ status, json });

// poFluxoChama devolve o 400 e o 409 (nao lanca): sao respostas, nao falhas
{
  const a = ambiente({ publicar: JSON_(400, { ok: false, problemas: [PB(6, 'a', 'x', true)] }), ligar: JSON_(409, { ok: false, error: 'ocupado por "Y"' }),
    conferir: { status: 500, json: null, semJson: true } }, [mkFluxo()]);
  const r4 = await a.poFluxoChama('publicar', { fluxo_id: 'f1' });
  assert.equal(r4.http, 400); assert.equal(r4.j.ok, false); assert.equal(r4.j.problemas.length, 1, 'o 400 chega com os problemas');
  assert.equal((await a.poFluxoChama('ligar', {})).http, 409, 'o 409 chega com o status');
  const rs = await a.poFluxoChama('conferir', {});
  assert.equal(rs.j, null); assert.ok(rs.erro, 'corpo que nao e JSON vira erro legivel, sem lancar');
}

// PUBLICAR recusado: mostra a lista e ABRE a gaveta do card culpado
{
  const a = ambiente({ publicar: JSON_(400, { ok: false, problemas: [PB(16, null, 'sem fim', false), PB(6, 'a', 'texto estoura o limite', true)] }) }, [mkFluxo()]);
  Object.assign(a.conf('f1'), { conferido: true, problemas: [], editadoDesde: false });
  await a.poFluxoPublicar();
  assert.deepEqual(a.pedidos, ['publicar'], 'so chamou publicar (nao ligou nada, nem leu de novo)');
  assert.ok(a.res().includes('texto estoura o limite') && a.res().includes('fl-problema--bloqueia'), 'a recusa lista os problemas');
  assert.equal(a.els['#fl-gaveta'].hidden, false, 'e a gaveta do card culpado ABRIU');
  assert.ok(a.els['#fl-gaveta'].innerHTML.includes('Oi'), 'com o card certo');
  assert.ok(a.els['#fl-gaveta [data-status]'].innerHTML.includes('texto estoura o limite'), 'e o problema aparece dentro da gaveta');
  assert.equal(a.ocupado(), false, 'e a tela volta a responder');
}
// PUBLICAR recusado por regra 0: falha de sistema, nenhuma gaveta
{
  const a = ambiente({ publicar: JSON_(400, { ok: false, problemas: [PB(0, null, 'banco fora', true)] }) }, [mkFluxo()]);
  Object.assign(a.conf('f1'), { conferido: true, problemas: [], editadoDesde: false });
  await a.poFluxoPublicar();
  assert.ok(a.res().includes('fl-sistema') && !a.res().includes('fl-problema--bloqueia'), 'regra 0 nao e mostrada como defeito do fluxo');
  assert.equal(a.els['#fl-gaveta'], undefined, 'e nenhuma gaveta e aberta');
}
// PUBLICAR com sucesso: os avisos do 200 aparecem, o estado vem do `ler`, e nada e ligado
{
  const a = ambiente({ publicar: JSON_(200, { ok: true, versao: 4, problemas: [PB(16, null, 'Este fluxo não tem nenhum card de fim.', false)] }) },
    [mkFluxo({ versao_numero: 4, publicado_igual: true })]);
  a.fixa([mkFluxo({ publicado_igual: false })]);
  Object.assign(a.conf('f1'), { conferido: true, problemas: [], editadoDesde: false });
  await a.poFluxoPublicar();
  assert.deepEqual(a.pedidos, ['publicar', 'ler'], 'publicou e releu: nao liga');
  assert.ok(a.res().includes('Este fluxo não tem nenhum card de fim') && !a.res().includes('fl-sistema'), 'o aviso do 200 chega a cliente');
  assert.equal(a.fluxos()[0].publicado_igual, true, 'publicado_igual vem do ler');
  assert.equal(a.fluxos()[0].ativo, false, 'publicar nao liga');
  assert.equal(a.conf('f1').conferido, false, 'e o Publicar volta a exigir conferencia');
  const caixa = a.els['#fluxo-lista'].innerHTML;
  assert.ok(caixa.includes('id="fl-barra"') && caixa.includes('data-bar="publicar"'), 'o render desenha a barra');
  assert.ok(/<button[^>]*data-bar="publicar"[^>]* disabled/.test(caixa), 'e o Publicar volta apagado depois de publicar');
  assert.ok(caixa.includes('id="fl-resultado"') && caixa.includes('Este fluxo não tem nenhum card de fim'), 'e o render nao apaga o aviso do 200');
  assert.equal(a.ocupado(), false, 'tela livre depois de publicar');
}
// RENDER: a barra nasce com o estado da conferencia e a lista de fluxos; trocar de fluxo limpa o resultado
{
  const a = ambiente({ conferir: JSON_(200, { ok: true, problemas: [] }) }, [mkFluxo(), mkFluxo({ id: 'f2', nome: 'Lista de espera' })]);
  const sel = { onchange: null, value: 'f2' };
  a.els['#fluxo-lista'] = { innerHTML: '', onclick: null, querySelector: s => (s === '#fl-seletor' ? sel : null), querySelectorAll: () => [] };
  Object.assign(a.conf('f1'), { conferido: true, problemas: [], editadoDesde: false });
  a.poFluxoRender();
  const caixa = a.els['#fluxo-lista'].innerHTML;
  assert.ok(!/ disabled/.test(tagBotao(caixa, 'publicar')), 'o render desenha o Publicar conforme o estado da conferencia');
  assert.ok(caixa.includes('fl-ordem'), 'e a ordem de ligar, com a lista de TODOS os fluxos');
  await a.poFluxoConfere();
  assert.ok(a.res().includes('sem problemas'), 'ha um resultado na tela');
  sel.onchange();
  assert.equal(a.res(), '', 'trocar de fluxo limpa o resultado do anterior');
}
// duplo clique: um publicar so
{
  const a = ambiente({ publicar: JSON_(200, { ok: true, versao: 4, problemas: [] }) }, [mkFluxo()]);
  Object.assign(a.conf('f1'), { conferido: true, problemas: [], editadoDesde: false });
  await Promise.all([a.poFluxoPublicar(), a.poFluxoPublicar()]);
  assert.equal(a.pedidos.filter(m => m === 'publicar').length, 1, 'dois cliques seguidos publicam UMA vez');
}
// PUBLICAR sem conferencia limpa nao envia nada
{
  const a = ambiente({}, [mkFluxo()]);
  await a.poFluxoPublicar();
  assert.deepEqual(a.pedidos, [], 'sem conferencia, o Publicar nem chega ao servidor');
}
// CONFERIR: edicao DURANTE a conferencia nao libera o Publicar
{
  const a = ambiente({ conferir: JSON_(200, { ok: true, problemas: [] }) }, [mkFluxo()]);
  await a.poFluxoConfere();
  assert.deepEqual(a.conf('f1'), { conferido: true, problemas: [], editadoDesde: false, edicoes: 0 }, 'conferencia limpa guarda o estado');
  assert.ok(!/ disabled/.test(tagBotao(a.els['#fl-barra'].innerHTML, 'publicar')), 'e a barra e redesenhada com o Publicar habilitado');
  const d = ambiente({ conferir: JSON_(200, { ok: true, problemas: [] }), __antes: m => { if (m === 'conferir') d.conf('f1').edicoes += 1; } }, [mkFluxo()]);
  await d.poFluxoConfere();
  assert.equal(d.conf('f1').editadoDesde, true, 'salvar DURANTE a conferencia deixa o resultado como ja velho');
  assert.equal(F.poFluxoPodePublicar(d.conf('f1')), false, 'e nao libera o Publicar');
  const b = ambiente({ conferir: JSON_(200, { ok: true, problemas: [PB(1, 'a', 'x', true)] }) }, [mkFluxo()]);
  await b.poFluxoConfere();
  assert.equal(F.poFluxoPodePublicar(b.conf('f1')), false, 'conferencia com bloqueio nao libera');
  const c = ambiente({ conferir: { status: 502, json: { ok: false, error: 'banco' } } }, [mkFluxo()]);
  await c.poFluxoConfere();
  assert.equal(c.conf('f1').conferido, false, 'conferencia que falhou nao libera');
}
// LIGAR: 409 mostra quem ocupa a porta; nao muda o estado
{
  const a = ambiente({ ligar: JSON_(409, { ok: false, error: 'Já existe outro fluxo ligado nesta porta de entrada: "Fluxo Ocupante". Desligue esse fluxo antes ou mude a porta deste.' }) }, [mkFluxo()]);
  await a.poFluxoLiga('ligar');
  assert.deepEqual(a.pedidos, ['ligar'], 'so chamou ligar');
  assert.ok(a.res().includes('Fluxo Ocupante'), 'o 409 mostra o nome do fluxo ocupante');
  assert.equal(a.fluxos()[0].ativo, false, 'e o fluxo segue desligado');
  assert.equal(a.confirmou(), 1, 'ligar pede confirmacao (vai atender cliente de verdade)');
  const b = ambiente({ ligar: JSON_(200, { ok: true, ativo: true }), desligar: JSON_(200, { ok: true, ativo: false }) }, [mkFluxo()]);
  await b.poFluxoLiga('ligar');
  assert.equal(b.fluxos()[0].ativo, true, 'ligou');
  assert.ok(b.els['#fl-barra'].innerHTML.includes('data-bar="desligar"') && b.els['#fl-barra'].innerHTML.includes('Ligado'), 'a barra e redesenhada com o fluxo ligado');
  await b.poFluxoLiga('desligar');
  assert.equal(b.fluxos()[0].ativo, false, 'desligou');
  assert.equal(b.confirmou(), 1, 'desligar nao pede confirmacao (tirar do ar nunca pode travar)');
  await assert.rejects(() => b.poFluxoLiga('publicar'), /./, 'modo fora de ligar/desligar e recusado');
  assert.deepEqual(b.pedidos, ['ligar', 'desligar'], 'e nao chegou ao servidor');
  const c = ambiente({ ligar: JSON_(200, { ok: true, ativo: true }) }, [mkFluxo()], false);
  await c.poFluxoLiga('ligar');
  assert.deepEqual(c.pedidos, [], 'se a cliente nao confirma, nada e ligado');
  assert.equal(c.fluxos()[0].ativo, false);
  const d = ambiente({ ligar: JSON_(400, { ok: false, error: 'Publique o fluxo antes de ligar.' }) }, [mkFluxo()]);
  await d.poFluxoLiga('ligar');
  assert.equal(d.fluxos()[0].ativo, false, 'ligar recusado nao liga na tela');
  assert.ok(d.res().includes('Publique o fluxo antes de ligar.') && d.ocupado() === false, 'mostra o motivo e libera a tela');
}
// SALVAR NA GAVETA: o estado de publicacao vem do `ler`, e a edicao desabilita o Publicar
{
  const a = ambiente({ conferir: JSON_(200, { ok: true, problemas: [] }) }, [mkFluxo({ publicado_igual: true })]);
  a.els['#fl-gaveta'] = { innerHTML: '', hidden: true, classList: { toggle() {} }, querySelectorAll: () => [], addEventListener() {}, onclick: null,
    querySelector: s => (s === '[data-campo="texto"]' ? { value: 'novo texto' } : null) };
  Object.assign(a.conf('f1'), { conferido: true, problemas: [], editadoDesde: false });
  a.poFluxoAbre('a');
  await a.poFluxoGavetaSalva();
  assert.ok(a.pedidos.includes('ler'), 'depois de salvar a tela RELE o servidor');
  assert.ok(a.pedidos.includes('conferir'), 'e confere pelo endpoint');
  assert.equal(a.fluxos()[0].publicado_igual, true, 'publicado_igual e o que o ler devolveu, nao o que a memoria supoe');
  assert.equal(a.conf('f1').editadoDesde, true, 'a edicao marca editadoDesde');
  assert.equal(a.conf('f1').edicoes, 1, 'e conta a edicao');
  assert.equal(a.conf('f1').conferido, false, 'e tira a conferencia');
  assert.equal(F.poFluxoPodePublicar(a.conf('f1')), false, 'entao o Publicar desabilita');
  // se o ler falhar, o estado fica DESCONHECIDO (nunca "igual" por palpite)
  const b = ambiente({ conferir: JSON_(200, { ok: true, problemas: [] }), ler: { status: 502, json: { ok: false, error: 'banco' } } }, [mkFluxo({ publicado_igual: true })]);
  b.els['#fl-gaveta'] = { innerHTML: '', hidden: true, classList: { toggle() {} }, querySelectorAll: () => [], addEventListener() {}, onclick: null,
    querySelector: s => (s === '[data-campo="texto"]' ? { value: 'novo texto' } : null) };
  b.poFluxoAbre('a');
  await b.poFluxoGavetaSalva();
  assert.equal(b.fluxos()[0].publicado_igual, null, 'ler falhou: publicado_igual fica desconhecido');
}

console.log('test-fluxo-painel OK');
