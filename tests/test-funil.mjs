import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const src = readFileSync(new URL('../painel/funil.js', import.meta.url), 'utf8');
function pega(nome) {
  const m = src.match(new RegExp('(?:const|function) ' + nome + '[\\s\\S]*?\\n(?:\\}|\\];)'));
  assert.ok(m, nome + ' encontrada no funil.js');
  return m[0];
}
const ctx = new Function(
  pega('PO_COLUNAS') +
  pega('poAgrupaFunil') +
  'function esc(s){return (s==null?"":String(s)).replace(/[&<>"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;","\\"":"&quot;"}[c]));}' +
  '; return {PO_COLUNAS, poAgrupaFunil};'
)();
const { PO_COLUNAS, poAgrupaFunil } = ctx;

// --- as seis colunas. "Lista de espera" entrou em 11/11/2026 (reuniao de
// 09/11): quem toca em "Sim, tenho interesse" no robo cai nela sozinho.
assert.deepEqual(PO_COLUNAS.map(c => c.status),
  ['novo','atendimento','negociacao','venda','espera','perdido'],
  'seis colunas na ordem do funil, a espera antes de perdido');
assert.equal(PO_COLUNAS.find(c => c.status === 'espera').modo, 'auto', 'a espera e preenchida pelo robo');
assert.equal(poAgrupaFunil([{id:5, status:'espera'}]).espera.length, 1, 'lead em espera cai na coluna da espera');

// --- clicar no card abre a gaveta do lead (no desktop o clique simples; antes
// era so duplo clique e a cliente achava que nada acontecia). No celular o
// toque segue abrindo o menu de etapas, que tem "Ver o lead completo".
{
  const liga = pega('poLigaMover');
  assert.ok(/card\.onclick\s*=\s*\(\)\s*=>\s*openDrawer\(card\.dataset\.id\)/.test(liga), 'clique simples abre a gaveta no desktop');
  assert.ok(/poAbreMenuEtapa\(card\.dataset\.id\)/.test(liga), 'o toque no celular segue abrindo o menu de etapas');
}

// --- os leads caem na coluna certa
const leads = [
  {id:1, status:'novo',        nome:'Maria', roteiro:'Grecia',  ven:0,     orc:0,     data:'2026-09-01T12:00:00Z'},
  {id:2, status:'atendimento', nome:'Joao',  roteiro:'Turquia', ven:0,     orc:0,     data:'2026-09-02T12:00:00Z'},
  {id:3, status:'venda',       nome:'Ana',   roteiro:'Chile',   ven:22900, orc:0,     data:'2026-09-03T12:00:00Z'},
  {id:4, status:'semresposta', nome:'Do site',roteiro:'Antigo', ven:0,     orc:0,     data:'2025-01-01T12:00:00Z'},
];
const cols = poAgrupaFunil(leads);

// 'semresposta' nao e legado: e o DEFAULT da coluna no banco, entao todo
// lead que chega pelo formulario do site nasce assim. Ele cai em "Contato
// feito" porque e literalmente isso: o lead entrou e ninguem respondeu
// ainda, tenha vindo pelo WhatsApp (novo) ou pelo site (semresposta).
assert.equal(cols.novo.length, 2, 'contato feito junta o lead do WhatsApp e o do formulario do site');
assert.equal(cols.atendimento.length, 1, 'um em qualificado');
assert.equal(cols.venda.length, 1, 'um em contrato assinado');

// O lead 4 (semresposta) precisa estar em 'novo', nao sumido do quadro.
assert.equal(cols.novo.concat(cols.atendimento, cols.negociacao, cols.venda, cols.perdido)
  .filter(l => l.id === 4).length, 1, 'lead do site aparece em alguma coluna');

// --- caso isolado: um unico lead recem-chegado do site (sem nenhum lead
// 'novo' de WhatsApp junto) cai sozinho em 'Contato feito', nunca em
// 'Perdido' ou em coluna nenhuma. Isto documenta a regra explicitamente
// para ninguem "consertar" isso depois achando que e bug.
const soSite = poAgrupaFunil([{id:9, status:'semresposta', nome:'Do site', roteiro:'Grecia', ven:0, orc:0, data:'2026-09-01T12:00:00Z'}]);
assert.equal(soSite.novo.length, 1, 'lead do formulario do site aparece em contato feito');
assert.equal(soSite.perdido.length, 0, 'e nao em perdido');

// --- o card escapa dado de terceiro. O nome vem do perfil do WhatsApp.
const ctxCard = new Function(
  pega('poCardFunil') +
  'function esc(s){return (s==null?"":String(s)).replace(/[&<>"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;","\\"":"&quot;"}[c]));}' +
  'function fmtData(){return "01 set";}function brl(n){return "R$ "+n;}' +
  '; return poCardFunil;'
)();
const html = ctxCard({id:7, nome:'<img src=x onerror=alert(1)>', roteiro:'Grecia', ven:0, orc:0, data:null});
assert.ok(!html.includes('<img src=x'), 'nome de terceiro escapado no card');
assert.ok(html.includes('data-id="7"'), 'o card carrega o id do lead');
assert.ok(html.includes('draggable="true"'), 'o card e arrastavel');

// --- a regra de negocio do mover, isolada da UI: mover para 'venda'
// precisa carimbar venda_at, e tirar de 'venda' precisa limpar, senao o
// relatorio de ciclo de venda mente.
const ctxMove = new Function(
  pega('poPatchStatus') + '; return poPatchStatus;'
)();

const paraVenda = ctxMove('venda', {ven: 22900, vendaAt: null});
assert.equal(paraVenda.status, 'venda', 'grava o status');
assert.ok(paraVenda.venda_at, 'mover para venda carimba venda_at');

const jaTinha = ctxMove('venda', {ven: 22900, vendaAt: '2026-03-20T12:00:00Z'});
assert.equal(jaTinha.venda_at, '2026-03-20T12:00:00Z', 'venda que ja tinha carimbo mantem a data original');

const saiuDeVenda = ctxMove('negociacao', {ven: 22900, vendaAt: '2026-03-20T12:00:00Z'});
assert.equal(saiuDeVenda.status, 'negociacao', 'grava o status novo');
assert.equal(saiuDeVenda.venda_at, null, 'tirar de venda limpa o carimbo');

const comum = ctxMove('atendimento', {ven: 0, vendaAt: null});
assert.equal(comum.venda_at, undefined, 'movimento comum nao mexe em venda_at');


// --- o card da Lista de espera mostra o DESTINO (reuniao de 11/11/2026) ---
const comDest = ctxCard({id:8, nome:'Marta', roteiro:'', ven:0, orc:0, data:null}, ['Egito', '<b>Japão']);
assert.ok(comDest.includes('fn-card-d') && comDest.includes('Egito'), 'o destino da espera aparece no card');
assert.ok(comDest.includes('&lt;b&gt;Japão') && !comDest.includes('<b>Jap'), 'destino escapado');
const semDest = ctxCard({id:9, nome:'Ana', roteiro:'Turquia', ven:0, orc:0, data:null});
assert.ok(!semDest.includes('fn-card-d'), 'sem espera, o card nao ganha a linha');
const fonteFn = readFileSync(new URL('../painel/funil.js', import.meta.url), 'utf8');
assert.ok(/poCardFunil\(l,\s*PO_ESPERA_FN\[/.test(fonteFn), 'o quadro passa os destinos de cada lead para o card');
assert.ok(/from\('po_wa_espera'\)[\s\S]{0,120}\.is\('removido_at',\s*null\)/.test(fonteFn),
  'o funil le so as esperas ATIVAS (a remocao e soft delete)');
console.log('test-funil OK');
