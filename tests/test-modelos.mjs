/* Aba Modelos: os modelos de mensagem submetidos a Meta.
 *
 * As funcoes puras sao extraidas do fonte por regex, sem carregar o painel
 * inteiro, no mesmo padrao de tests/test-textos.mjs. */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const src = readFileSync(new URL('../painel/modelos.js', import.meta.url), 'utf8');
function pega(nome) {
  const m = src.match(new RegExp('function ' + nome + '\\([\\s\\S]*?\\n\\}'));
  assert.ok(m, nome + ' encontrada no modelos.js');
  return m[0];
}
const ESC = 'function esc(s){return (s==null?"":String(s)).replace(/[&<>"]/g,' +
            'c=>({"&":"&amp;","<":"&lt;",">":"&gt;","\\"":"&quot;"}[c]));}';

const PURAS = ESC + pega('poModeloStatus') + pega('poModeloUsavel') +
              pega('poModeloLinha') + pega('poModeloProblemasHtml');

const { poModeloStatus, poModeloUsavel, poModeloLinha, poModeloProblemasHtml } =
  new Function(PURAS +
    '; return {poModeloStatus, poModeloUsavel, poModeloLinha, poModeloProblemasHtml};')();

/* ============================================================
   STATUS
============================================================ */
assert.equal(poModeloStatus('APPROVED').rotulo, 'Aprovado');
assert.equal(poModeloStatus('PENDING').rotulo, 'Em análise');
assert.equal(poModeloStatus('REJECTED').rotulo, 'Recusado');
assert.equal(poModeloStatus('approved').rotulo, 'Aprovado',
  'a Meta ja mudou a caixa de valores antes; comparar sem normalizar quebraria a tela');

/* Status que a Meta inventar depois aparece CRU, nao some. Uma tela que
   esconde o que nao conhece faria um modelo existente parecer inexistente, e
   alguem criaria um duplicado - que nao da para renomear nem apagar. */
assert.equal(poModeloStatus('IN_APPEAL').rotulo, 'IN_APPEAL');
assert.equal(poModeloStatus('').rotulo, 'desconhecido');
assert.equal(poModeloStatus(null).rotulo, 'desconhecido');
assert.equal(poModeloStatus(undefined).rotulo, 'desconhecido');

/* ============================================================
   USAVEL
   So o aprovado serve. Mandar campanha com modelo em analise faz a Graph
   recusar TODO destinatario, e falha e terminal: a campanha inteira queima.
============================================================ */
assert.equal(poModeloUsavel({ status: 'APPROVED' }), true);
assert.equal(poModeloUsavel({ status: 'PENDING' }), false, 'em analise NAO serve');
assert.equal(poModeloUsavel({ status: 'REJECTED' }), false);
assert.equal(poModeloUsavel({ status: 'PAUSED' }), false);
assert.equal(poModeloUsavel({}), false, 'sem status, nao serve');
assert.equal(poModeloUsavel(null), false, 'nulo nao serve');

/* ============================================================
   LINHA
============================================================ */
const aprovado = poModeloLinha(
  { nome: 'novidade_portugal', status: 'APPROVED', categoria: 'MARKETING', idioma: 'pt_BR' });
assert.ok(aprovado.includes('novidade_portugal'), 'a linha mostra o nome');
assert.ok(aprovado.includes('Aprovado'), 'e o status traduzido');
assert.ok(aprovado.includes('mdl-usar'), 'aprovado ganha o botao de usar na transmissao');
assert.ok(aprovado.includes('data-nome="novidade_portugal"'),
  'o botao carrega o nome que a transmissao precisa');

const pendente = poModeloLinha({ nome: 'rascunho', status: 'PENDING' });
assert.ok(!pendente.includes('mdl-usar'),
  'modelo em analise NAO ganha botao de usar: a campanha queimaria inteira');

/* O nome vem da Meta: aqui dentro e dado, nunca marcacao. */
const malicioso = poModeloLinha({ nome: '<img src=x onerror=alert(1)>', status: 'APPROVED' });
assert.ok(!malicioso.includes('<img'), 'o nome e escapado');
assert.ok(malicioso.includes('&lt;img'), 'e aparece escapado, nao sumido');

/* ============================================================
   PROBLEMAS
============================================================ */
const vazio = poModeloProblemasHtml([]);
assert.ok(vazio.trim() !== '',
  'lista vazia vira um visto explicito: area em branco e ambigua entre ' +
  '"esta certo" e "ainda nao conferi"');
assert.ok(/dentro das regras/i.test(vazio), 'e o visto diz que o texto passou');

const com = poModeloProblemasHtml(['Falta a variável.', 'Texto longo demais.']);
assert.equal((com.match(/<li>/g) || []).length, 2, 'um item por problema');
assert.ok(com.includes('Falta a variável.'), 'o texto do problema aparece');

const xss = poModeloProblemasHtml(['<b>oi</b>']);
assert.ok(!xss.includes('<b>oi</b>'), 'problema tambem e escapado');

/* ============================================================
   FONTE: uma fonte de verdade so
============================================================ */

/* As regras da Meta moram em wa_tpl_problemas (PHP) e sao usadas pelo
   conferir E pelo criar. Reimplementar em JavaScript cria uma segunda
   verdade que envelhece em silencio: a tela aprovaria o que a Meta recusa, e
   o contrario. Por isso a conferencia e uma chamada ao servidor. */
assert.ok(src.includes("poModeloPost('conferir'"),
  'a tela confere o texto chamando o servidor');
assert.ok(!/\{\{\\?d|\\\{\\\{/.test(src),
  'a tela NAO reimplementa as regras de variavel em JavaScript');

/* Segundo clique cria um segundo modelo na conta, e modelo nao se apaga nem
   se renomeia na Meta. */
/* A trava tem que estar DENTRO do poModeloCria, nao so declarada no arquivo:
   com a variavel existindo e o criar ignorando ela, o teste passaria verde
   enquanto a tela cria modelo que ninguem conferiu. E a regra permanente do
   projeto, a trava no ponto de chamada. */
const corpoCria = src.match(/async function poModeloCria\([\s\S]*?\n\}/);
assert.ok(corpoCria, 'achei o corpo de poModeloCria');
assert.ok(corpoCria[0].includes('PO_MDL_OCUPADO'),
  'poModeloCria trava o segundo clique');
assert.ok(corpoCria[0].includes('PO_MDL_OK'),
  'poModeloCria exige uma conferencia previa do texto');

/* ============================================================
   FONTE: a aba no index.html
============================================================ */
const idx = readFileSync(new URL('../painel/index.html', import.meta.url), 'utf8');
assert.ok(idx.includes('data-view="modelos"'), 'o menu tem a aba Modelos');
assert.ok(idx.includes('id="view-modelos"'), 'e a secao existe');
assert.ok(idx.includes('modelos.js?v='), 'e o script e carregado, com cache-buster');

/* O botao NASCE desabilitado. Sem isto existe uma janela em que um clique
   cria um modelo que nunca foi conferido, e ele fica na conta para sempre. */
const botao = idx.match(/<button[^>]*id="mdl-criar"[^>]*>/);
assert.ok(botao, 'o botao de enviar para aprovacao existe');
assert.ok(/\bdisabled\b/.test(botao[0]),
  'o botao de enviar para aprovacao nasce desabilitado');

/* O ensaio: uma mensagem, um numero, longe da base. */
assert.ok(idx.includes('id="ens-enviar"'), 'a aba tem o botao de enviar teste');
assert.ok(idx.includes('id="ens-template"') && idx.includes('id="ens-destino"'),
  'e os campos de modelo e numero');
const corpoEns = src.match(/async function poModeloEnsaio\([\s\S]*?\n\}/);
assert.ok(corpoEns, 'achei o corpo de poModeloEnsaio');
/* A guarda, nao so a mencao: a variavel tambem e ATRIBUIDA dentro da funcao,
   entao procurar o nome dela deixava passar a remocao do if. */
assert.ok(/if\s*\(\s*PO_ENS_OCUPADO\s*\)\s*return/.test(corpoEns[0]),
  'o ensaio trava o segundo clique: dois cliques sao duas mensagens');
assert.ok(corpoEns[0].includes("poModeloPost('ensaio'"),
  'o ensaio vai pelo endpoint, que escolhe o numero de teste');

const app = readFileSync(new URL('../painel/app.js', import.meta.url), 'utf8');
assert.ok(app.includes("v==='modelos'"), 'o app.js tem a rota da aba');
assert.ok(app.includes('poRenderModelos'),
  'e a rota recarrega a lista: o status muda sozinho na Meta');

console.log('test-modelos OK');
