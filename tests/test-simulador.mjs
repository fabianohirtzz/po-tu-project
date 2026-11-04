import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

/* Le o fonte com o fim de linha NORMALIZADO. `core.autocrlf=true` neste repo:
   o mesmo arquivo chega LF no worktree e CRLF no checkout principal, e uma
   trava ancorada em $ deixa de casar sem nada ter mudado no codigo. TODA
   leitura de fonte deste arquivo passa por aqui. */
function le(rel) {
  return readFileSync(new URL(rel, import.meta.url), 'utf8').replace(/\r\n/g, '\n');
}

const src = le('../painel/simulador.js');
function pega(nome) {
  const m = src.match(new RegExp('(?:async )?function ' + nome + '\\([\\s\\S]*?\\n\\}'));
  assert.ok(m, nome + ' encontrada no simulador.js');
  return m[0];
}
const ESC = 'function esc(s){return (s==null?"":String(s)).replace(/[&<>"]/g,' +
            'c=>({"&":"&amp;","<":"&lt;",">":"&gt;","\\"":"&quot;"}[c]));}';

const PURAS_NOMES = ['poSimBolha', 'poSimEu', 'poSimSelo', 'poSimAvisos', 'poSimEstadoInicial', 'poSimFala', 'poSimPainel'];
const F = new Function(ESC + PURAS_NOMES.map(pega).join('\n') +
  '; return {' + PURAS_NOMES.join(',') + '};')();

const XSS = '<script>alert("x")</script> & "aspas"';
const ESCAPADO = '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; &quot;aspas&quot;';

/* ---------- a bolha do robo ---------- */

const bot = F.poSimBolha({ tipo: 'buttons', texto: 'Como posso ajudar?',
  botoes: [{ id: 'ver', rotulo: 'Ver roteiros' }, { id: 'falar', rotulo: 'Falar com voces' }],
  itens: [], arquivo: null });
assert.ok(bot.includes('Como posso ajudar?'), 'a bolha mostra o corpo da mensagem');
assert.equal(bot.split('<button').length - 1, 2, 'um botao por opcao: ' + bot);
assert.ok(bot.includes('data-id="ver"') && bot.includes('data-id="falar"'),
  'cada botao leva o id que volta para o servidor: ' + bot);
assert.ok(bot.includes('Ver roteiros'), 'com o rotulo visivel');

/* Escape. O esc() da casa NAO escapa a aspa simples, entao todo atributo aqui
   usa aspas DUPLAS: com aspa simples o texto da cliente fecharia o atributo. */
const mal = F.poSimBolha({ tipo: 'buttons', texto: XSS, botoes: [{ id: XSS, rotulo: XSS }], itens: [], arquivo: null });
assert.ok(!mal.includes('<script'), 'nenhum <script> cru na bolha');
assert.ok(!mal.includes('"aspas"'), 'aspa dupla crua nao sobra, nem no atributo nem no texto');
assert.ok(mal.includes(ESCAPADO), 'o rotulo e o corpo saem escapados: ' + mal);
assert.ok(!mal.includes('data-id="<'), 'o id malicioso nao escapa do atributo');
/* Nenhum atributo aberto com aspa SIMPLES em lugar nenhum do arquivo: o esc()
   da casa nao escapa a aspa simples, entao `class='...'` deixaria o texto da
   cliente fechar o atributo. Atributo em JS nasce como =" dentro da string;
   =' e sempre o erro. */
assert.ok(!/='/.test(src), 'nenhum atributo com aspa simples no simulador.js');

/* Lista (card de roteiros dinamico): mostra os itens, nao os botoes. */
const lst = F.poSimBolha({ tipo: 'list', texto: 'Qual viagem?', botoes: [],
  itens: [{ id: 'chile', titulo: 'Chile', descricao: '25/11/26 a 02/12/26' }], arquivo: null });
assert.ok(lst.includes('Chile') && lst.includes('25/11/26'), 'a lista mostra titulo e descricao de cada item');
assert.ok(lst.includes('data-id="chile"'), 'e cada item e clicavel pelo id');

/* Documento: o nome do arquivo, nunca um PDF embutido. A conversa simulada nao
   baixa nem renderiza o roteiro: ela mostra que o PDF seria enviado. */
const doc = F.poSimBolha({ tipo: 'document', texto: 'Segue o roteiro',
  botoes: [], itens: [], arquivo: 'Turquia.pdf' });
assert.ok(doc.includes('Turquia.pdf'), 'o documento mostra o nome do arquivo: ' + doc);
assert.ok(!doc.includes('<iframe') && !doc.includes('<embed') && !doc.includes('<object'),
  'e nao embute o PDF');
assert.ok(doc.includes('Segue o roteiro'), 'com a legenda');
assert.ok(F.poSimBolha({ tipo: 'document', texto: '', botoes: [], itens: [], arquivo: XSS }).includes(ESCAPADO),
  'nome de arquivo escapado');

/* Texto simples, e o caso degenerado. */
assert.ok(F.poSimBolha({ tipo: 'text', texto: 'Bom dia' }).includes('Bom dia'), 'texto simples');
assert.ok(!F.poSimBolha({ tipo: 'text', texto: 'Bom dia' }).includes('<button'), 'texto simples nao tem botao');
assert.ok(typeof F.poSimBolha(null) === 'string', 'mensagem nula nao estoura');
assert.ok(typeof F.poSimBolha({}) === 'string', 'mensagem vazia nao estoura');

/* ---------- a bolha do cliente ---------- */

const eu = F.poSimEu('quero o chile');
assert.ok(eu.includes('quero o chile'), 'a fala do cliente aparece');
assert.ok(F.poSimEu(XSS).includes(ESCAPADO), 'e sai escapada');
assert.ok(!F.poSimEu(XSS).includes('<script'), 'sem script cru');
assert.notEqual(eu, F.poSimBolha({ tipo: 'text', texto: 'quero o chile' }),
  'cliente e robo nao saem com o mesmo HTML (um fica a direita, o outro a esquerda)');

/* ---------- o que a bolha do cliente MOSTRA ---------- */

/* O que vai para o servidor e o id da opcao; o que ela le na tela tem que ser
   o rotulo que clicou. Mostrar o id faria a conversa de teste nao parecer a
   conversa - e o id e nome tecnico que a cliente nunca viu. */
assert.equal(F.poSimFala('ver', 'Ver as viagens'), 'Ver as viagens', 'clicou: mostra o rotulo, nao o id');
assert.equal(F.poSimFala('2', ''), '2', 'digitou: mostra o que digitou');
assert.equal(F.poSimFala('2', null), '2', 'sem rotulo, mostra a resposta');
assert.equal(F.poSimFala('', ''), '', 'nada digitado, nada mostrado');

/* ---------- o selo de estado ---------- */

const ESTADOS = ['aguardando', 'humano', 'encerrado', 'recusado', 'teto_passos'];
for (const e of ESTADOS) {
  const s = F.poSimSelo(e);
  assert.ok(typeof s === 'string' && s.trim() !== '', 'o estado ' + e + ' tem rotulo');
  assert.ok(!/[a-z]+_[a-z]+/.test(s), 'o rotulo de ' + e + ' nao mostra nome tecnico: ' + s);
  assert.ok(!s.includes('<'), 'o rotulo de ' + e + ' e texto, nao HTML: ' + s);
}
assert.notEqual(F.poSimSelo('aguardando'), F.poSimSelo('humano'), 'esperando e entregar sao coisas diferentes');
assert.ok(F.poSimSelo('xpto').trim() !== '', 'estado desconhecido tambem tem rotulo, nunca vazio');
/* Os 5 estados do envelope do servidor (lib/wa-sim.php) e os 5 daqui sao a
   mesma lista: estado novo la sem rotulo aqui apareceria em branco na tela. */
const php = le('../lib/wa-sim.php');
const doPhp = [...php.matchAll(/\$fim\('([a-z_]+)'/g)].map(m => m[1]);
assert.deepEqual([...new Set(doPhp)].sort(), [...ESTADOS].sort(),
  'os estados que o wa-sim.php devolve sao exatamente os que a tela sabe rotular: ' + doPhp.join(','));

/* ---------- a faixa de avisos ---------- */

assert.equal(F.poSimAvisos([]), '', 'sem aviso, sem faixa');
assert.equal(F.poSimAvisos(null), '', 'lista nula tambem');
const av = F.poSimAvisos(['No ar este salto nao aconteceria.', XSS]);
assert.ok(av.includes('No ar este salto nao aconteceria.'), 'o aviso aparece');
assert.ok(av.includes(ESCAPADO) && !av.includes('<script'), 'e sai escapado');

/* ---------- o estado inicial ---------- */

const st = F.poSimEstadoInicial('f1');
assert.equal(st.fluxo_id, 'f1', 'guarda o fluxo');
assert.equal(st.card_id, null, 'sem card: o primeiro passo comeca pelo inicial do fluxo');
assert.deepEqual(st.ctx, {}, 'contexto vazio');
assert.deepEqual(st.linhas, [], 'conversa vazia');
assert.notEqual(F.poSimEstadoInicial('f1'), F.poSimEstadoInicial('f1'), 'cada comeco e um objeto novo');

/* ---------- o painel inteiro ---------- */

const p = F.poSimPainel({ fluxo_id: 'f1', fluxo_nome: 'Boas-vindas', card_id: 'bv', ctx: {},
  estado: 'aguardando', erro: null, avisos: [],
  linhas: [{ lado: 'robo', m: { tipo: 'text', texto: 'Bom dia' } }, { lado: 'eu', texto: 'oi' }] });
assert.ok(p.includes('Bom dia') && p.includes('oi'), 'o painel desenha a conversa inteira');
assert.ok(p.includes('Boas-vindas'), 'e diz de qual fluxo e');
assert.ok(p.includes(F.poSimSelo('aguardando')), 'com o selo do estado');
assert.ok(/data-sim="recomeca"/.test(p), 'tem o "Comecar de novo"');
assert.ok(/data-sim="fecha"/.test(p), 'e o fechar');
/* O publico 60+ digita: todo card que espera precisa de caminho para quem
   escreve, entao o campo de texto existe sempre que a conversa espera. */
assert.ok(p.includes('<form') && p.includes('data-sim="digita"'), 'e o campo para digitar');

const pr = F.poSimPainel({ fluxo_id: 'f1', fluxo_nome: 'F', card_id: null, ctx: {},
  estado: 'recusado', erro: 'mais de 3 botoes', avisos: [], linhas: [] });
assert.ok(pr.includes('mais de 3 botoes'), 'na recusa, o painel mostra o motivo real da Cloud API: ' + pr);
assert.ok(!pr.includes('data-sim="digita"'), 'e nao oferece campo de texto: a conversa nao anda');
assert.ok(F.poSimPainel({ fluxo_id: 'f', fluxo_nome: XSS, card_id: null, ctx: {}, estado: 'humano',
  erro: XSS, avisos: [], linhas: [] }).includes(ESCAPADO), 'nome do fluxo e motivo escapados');
assert.ok(typeof F.poSimPainel(null) === 'string', 'estado nulo nao estoura');

/* Conversa sem nenhuma bolha (a recusa acontece antes do primeiro envio) nao
   pode virar um retangulo cinza vazio do tamanho da tela: ela tem que dizer o
   que houve. */
const pv = F.poSimPainel({ fluxo_id: 'f', fluxo_nome: 'F', card_id: null, ctx: {},
  estado: 'recusado', erro: 'mais de 3 botoes', avisos: [], linhas: [] });
assert.ok(/sim-conversa[^>]*>\s*<p/.test(pv), 'conversa vazia traz uma linha explicando, nao um vazio: ' + pv);
assert.ok(pv.includes('Nenhuma mensagem'), 'e a linha diz que nada seria enviado');
assert.ok(!p.includes('Nenhuma mensagem'), 'e ela some assim que ha bolha');

/* ---------- trava: o roteamento e do SERVIDOR ---------- */

/* A tela nao decide para qual card ir. Se ela decidisse, divergiria do motor
   no primeiro caso de fallback, e o simulador deixaria de servir para o que
   existe. Ela manda o que a pessoa fez e desenha o que voltou. */
for (const proibido of ['proximo', 'fallback', 'opcoes', 'WA_CARD', 'tentativas']) {
  assert.ok(!src.includes(proibido),
    'painel/simulador.js nao menciona ' + proibido + ': quem roteia e o servidor');
}
assert.ok(!src.includes('service_role'), 'e nao tem service_role');
/* O ctx volta inteiro, opaco, como veio: a tela nao o interpreta. */
assert.ok(/ctx: *[a-z]/i.test(src) || /ctx\b/.test(src), 'o ctx trafega');
assert.ok(!/ctx\.[a-z_]+/.test(src), 'e a tela nunca le dentro dele: ' + (src.match(/ctx\.[a-z_]+/) || [''])[0]);
assert.ok(src.includes("'simular'") || src.includes('"simular"'), 'a tela chama o modo simular do endpoint');

/* ---------- trava: fiacao e cache-buster ---------- */

const html = le('../painel/index.html');
const pedidos = new Map(
  [...html.matchAll(/(?:src|href)="([a-z0-9.-]+\.(?:js|css))\?v=(\d+)"/g)].map(m => [m[1], Number(m[2])])
);
assert.ok(pedidos.has('simulador.js'), 'o index.html carrega simulador.js com ?v=');

const lock = JSON.parse(le('../painel/assets-lock.json'));
/* O sha de cada arquivo e conferido pelo tests/test-cache-buster.mjs, que e o
   dono dessa trava; aqui so se confere que o arquivo novo ENTROU nela. */
/* Os TRES arquivos de painel/ que esta tarefa mexe. Subir o index.html com
   versao nova sem subir um deles carimba o conteudo velho na versao nova por
   um mes: e o bug de 13/09/2026. */
for (const arq of ['simulador.js', 'fluxo.css', 'fluxo.js']) {
  assert.ok(lock[arq], arq + ' tem entrada no assets-lock.json');
  assert.equal(pedidos.get(arq), lock[arq].v, arq + ': o ?v= do index.html bate com o lock');
}
/* O sha do lock e calculado pelo tests/test-cache-buster.mjs sobre o conteudo
   normalizado; aqui so se confere que o arquivo novo entrou na trava. */
assert.equal(lock['simulador.js'].v, 1, 'simulador.js nasce em ?v=1');

/* O botao que abre a tela mora na barra do fluxo. */
const fjs = le('../painel/fluxo.js');
assert.ok(/data-bar="simular"/.test(fjs), 'a barra do fluxo tem o botao Simular');
assert.ok(/poSimAbre\(/.test(fjs), 'e o clique chama poSimAbre');

/* ---------- trava: ordem na cascata do CSS ---------- */

/* Mesma classe de defeito que deixou a barra de zoom invisivel com dois testes
   verdes: o padrao declarado DEPOIS do @media perde, porque com a mesma
   especificidade vence a ultima regra declarada. Ali os dois testes conferiam
   presenca dentro e fora do @media, e nenhum conferia ORDEM.

   O @media que interessa e o que contem as regras .sim, nao o primeiro do
   arquivo: o fluxo.css ja tem o bloco do canvas antes. */
/* Sem os comentarios: a palavra @media aparece dentro deles (inclusive na
   explicacao desta propria regra), e um comentario nao e bloco de media. As
   posicoes abaixo sao todas medidas neste texto limpo. */
const css = le('../painel/fluxo.css').replace(/\/\*[\s\S]*?\*\//g, '');

function blocosMedia(txt) {
  const out = [];
  for (const m of txt.matchAll(/@media[^{]*\{/g)) {
    let i = m.index + m[0].length;
    let n = 1;
    while (i < txt.length && n > 0) {
      if (txt[i] === '{') n++;
      else if (txt[i] === '}') n--;
      i++;
    }
    out.push({ ini: m.index, fim: i, corpo: txt.slice(m.index, i) });
  }
  return out;
}

const medias = blocosMedia(css);
assert.ok(medias.length > 0, 'o fluxo.css tem @media');

const padrao = [...css.matchAll(/^(\.sim[a-z-]*)\s*\{/gm)];
assert.ok(padrao.length > 0, 'o CSS do simulador existe, declarado fora de @media');

const comSim = medias.filter(b => /\.sim[a-z-]*\s*\{/.test(b.corpo));
assert.ok(comSim.length > 0, 'e ha um @media que ajusta o simulador no desktop');
for (const b of comSim) {
  assert.ok(/min-width:\s*980px/.test(b.corpo),
    'o ponto de quebra do simulador e o mesmo do resto do painel (980px)');
  for (const m of b.corpo.matchAll(/(\.sim[a-z-]*)\s*\{/g)) {
    const cls = m[1];
    const antes = padrao.find(x => x[1] === cls && x.index < b.ini);
    assert.ok(antes,
      cls + ' e redefinida dentro de @media sem o padrao declarado ANTES do bloco: ' +
      'com a mesma especificidade vence a ultima declarada, e a regra do celular perderia');
  }
}

console.log('test-simulador OK');
