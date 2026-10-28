import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

/* Extrai as funcoes puras do fluxo.js por regex e roda com new Function, no
   padrao de tests/test-textos.mjs. Por isso NENHUMA constante de topo de
   arquivo pode ser dependencia delas: a lista de tipos mora DENTRO de
   poFluxoTipos(). As Tasks 4, 5 e 6 ESTENDEM este arquivo: acrescente o nome
   da funcao nova em PURAS_NOMES (e so isso: o resto sai dali). */
const src = readFileSync(new URL('../painel/fluxo.js', import.meta.url), 'utf8');
function pega(nome) {
  const m = src.match(new RegExp('function ' + nome + '\\([\\s\\S]*?\\n\\}'));
  assert.ok(m, nome + ' encontrada no fluxo.js');
  return m[0];
}
const ESC = 'function esc(s){return (s==null?"":String(s)).replace(/[&<>"]/g,' +
            'c=>({"&":"&amp;","<":"&lt;",">":"&gt;","\\"":"&quot;"}[c]));}';

const PURAS_NOMES = ['poFluxoTipos', 'poFluxoTipo', 'poFluxoOpcoes', 'poFluxoPrevia',
                     'poFluxoCard', 'poFluxoLista', 'poFluxoSeletor'];
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

/* ---------- ponto de chamada: a entrada fala com o endpoint e desenha pela funcao pura ---------- */
assert.ok(/fluxo\.php/.test(src), 'poCarregaFluxo chama fluxo.php');
assert.ok(/append\('modo',\s*'ler'\)|poFluxoPost\('ler'/.test(src), 'em modo=ler');
assert.ok(/sb_token/.test(src), 'e manda o token do login');
assert.ok(/poFluxoLista\(/.test(src.replace(pega('poFluxoLista'), '')), 'a tela desenha pela poFluxoLista');
assert.ok(/poFluxoSeletor\(/.test(src.replace(pega('poFluxoSeletor'), '')), 'e escolhe o fluxo pelo poFluxoSeletor');

/* INERTE AO CARREGAR. O painel baixa o fluxo.js antes de existir a aba que o
   usa (a Task 7 liga o app.js): ele so pode DEFINIR coisas. Nada de
   DOMContentLoaded, listener, timer nem chamada solta no topo do arquivo. */
assert.ok(!/DOMContentLoaded|addEventListener|setTimeout|setInterval|onload/.test(src), 'sem gatilho de load');
const topo = src.split('\n').filter(l => /^\S/.test(l) && !/^(\/\*|\*|\}|\/\/|=+)/.test(l));
topo.forEach(l => assert.ok(/^(function |async function |let |const )/.test(l),
  'linha de topo so define: ' + l));
assert.ok(!topo.some(l => /^(let|const) \w+\s*=\s*[^;]*\(/.test(l) && !/Object\.create|\[\]|null/.test(l)),
  'nenhuma constante de topo chama algo');

console.log('test-fluxo-painel OK');
