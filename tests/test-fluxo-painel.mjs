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
                     'poFluxoCard', 'poFluxoLista', 'poFluxoSeletor', 'poFluxoCanvas'];
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
assert.ok(/left:40px;top:60px/.test(cv), 'o card usa o x/y gravado');
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
assert.ok(/left:120px;top:80px/.test(F.poFluxoCanvas({ cards: [{ id: 'a', tipo: 'fim', titulo: 'A', x: '120', y: '80' }] })), 'x/y em texto numerico valem');
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
const css = readFileSync(new URL('../painel/fluxo.css', import.meta.url), 'utf8');
assert.ok(/\.fl-vista--canvas\{display:none\}/.test(css), 'canvas escondido por padrao (celular)');
assert.ok(/@media \(min-width:980px\)\{[\s\S]*\.fl-vista--lista\{display:none\}[\s\S]*\.fl-vista--canvas\{display:block/.test(css), 'a partir de 980px o canvas substitui a lista');
assert.ok(/poFluxoCanvas\(/.test(src.replace(pega('poFluxoCanvas'), '')), 'a tela desenha pela poFluxoCanvas');
assert.ok(!/innerWidth|matchMedia|clientWidth|ResizeObserver/.test(semComentario), 'o fluxo.js nao le largura de tela');

console.log('test-fluxo-painel OK');
