import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

// Extrai as funcoes puras do clientes.js sem carregar o painel inteiro,
// no mesmo padrao de tests/test-slugify.mjs.
const src = readFileSync(new URL('../painel/clientes.js', import.meta.url), 'utf8');
function pega(nome) {
  const m = src.match(new RegExp('function ' + nome + '\\([\\s\\S]*?\\n\\}'));
  assert.ok(m, nome + ' encontrada no clientes.js');
  return m[0];
}

// poChavePessoa agora delega para poE164 (painel/fone.js), o normalizador
// unico que espelha o wa_e164() do PHP. Sem injeta-lo aqui, a guarda
// `typeof poE164 === 'function'` do clientes.js falha e todo lead cai no
// ramo 'sem-tel:...', derrubando as asserções de agrupamento abaixo.
const foneSrc = readFileSync(new URL('../painel/fone.js', import.meta.url), 'utf8');
function pegaFone(nome) {
  const m = foneSrc.match(new RegExp('function ' + nome + '\\([\\s\\S]*?\\n\\}'));
  assert.ok(m, nome + ' encontrada no fone.js');
  return m[0];
}
const poE164Src = pegaFone('poE164');

const ctx = new Function(
  poE164Src + pega('poDigitos') + pega('poChavePessoa') + pega('poAgrupaPessoas') +
  '; return {poDigitos, poChavePessoa, poAgrupaPessoas};'
)();
const { poChavePessoa, poAgrupaPessoas } = ctx;

// --- a chave normaliza os formatos que o banco realmente tem hoje.
// O WhatsApp grava E.164; o enviar.php ainda grava o que o visitante
// digitou. As duas formas precisam cair na mesma pessoa.
assert.equal(poChavePessoa({tel: '+5548996048882'}), '5548996048882', 'E.164 do WhatsApp');
assert.equal(poChavePessoa({tel: '(48) 99604-8882'}), '5548996048882', 'formato do formulario');
assert.equal(poChavePessoa({tel: '48 99604-8882'}),   '5548996048882', 'sem DDI');
assert.equal(poChavePessoa({tel: '48996048882'}),     '5548996048882', 'so digitos');

// Sem telefone nao ha como agrupar: cada lead vira a propria pessoa,
// senao todos os leads sem telefone virariam uma ficha unica e errada.
const a = poChavePessoa({id: 'abc', tel: '—'});
const b = poChavePessoa({id: 'def', tel: ''});
assert.notEqual(a, b, 'leads sem telefone nao se agrupam entre si');
assert.ok(a.includes('abc'), 'a chave sem telefone usa o id do lead');

// --- agrupamento
const leads = [
  {id:1, tel:'(48) 99604-8882', nome:'Maria Aparecida', email:'maria@x.com', cidade:'Florianopolis',
   roteiro:'Grecia', status:'venda',      orc:0,     ven:22900, data:'2026-03-10T12:00:00Z', vendaAt:'2026-03-20T12:00:00Z', notas:[]},
  {id:2, tel:'+5548996048882',  nome:'Maria',          email:'',            cidade:'—',
   roteiro:'Turquia', status:'negociacao', orc:18000, ven:0,     data:'2026-09-01T12:00:00Z', vendaAt:null, notas:[]},
  {id:3, tel:'(11) 98888-7777', nome:'Joao Batista',   email:'joao@x.com',  cidade:'Sao Paulo',
   roteiro:'Chile',  status:'novo',       orc:0,     ven:0,     data:'2026-08-01T12:00:00Z', vendaAt:null, notas:[]},
];
const pessoas = poAgrupaPessoas(leads);

assert.equal(pessoas.length, 2, 'tres leads viram duas pessoas');

const maria = pessoas.find(p => p.tel.includes('99604'));
assert.equal(maria.leads.length, 2, 'os dois leads da Maria na mesma ficha');
assert.equal(maria.total, 2, 'contagem de interesses');
assert.equal(maria.vendas, 1, 'contagem de vendas');
assert.equal(maria.valorVendido, 22900, 'soma do que ela ja comprou');
assert.equal(maria.temVenda, true, 'e cliente, nao so lead');

// O nome mais completo ganha: o WhatsApp manda o nome do perfil, que as
// vezes e so "Maria", e o formulario do site manda o nome inteiro.
assert.equal(maria.nome, 'Maria Aparecida', 'fica o nome mais completo');
// Idem para os campos que um lead tem e o outro nao.
assert.equal(maria.email, 'maria@x.com', 'email preenchido vence o vazio');
assert.equal(maria.cidade, 'Florianopolis', 'cidade preenchida vence o travessao');

// A ficha e ordenada pela atividade mais recente, e os leads dentro dela
// tambem: o que interessa primeiro e o que esta acontecendo agora.
assert.equal(maria.ultimaData, '2026-09-01T12:00:00Z', 'ultima atividade da pessoa');
assert.equal(maria.leads[0].roteiro, 'Turquia', 'lead mais recente primeiro');
assert.equal(pessoas[0].nome, 'Maria Aparecida', 'pessoa com atividade mais recente primeiro');

// --- faturamento so conta quem esta EM status venda.
// O quadro do funil tira o card de "Contrato assinado" mantendo o valor
// no lead de proposito (um engano de arrasto nao pode destruir o numero).
// Se a ficha somasse o valor mesmo assim, a pessoa apareceria com
// "0 viagens" e "R$ 22.900 faturado" na mesma linha, e o KPI "Faturado"
// da aba ficaria inflado para sempre. Mesma regra ja aplicada nos KPIs
// de leads e nos relatorios.
const saiuDeVenda = poAgrupaPessoas([
  {id:1, tel:'(48) 99604-8882', nome:'Maria', status:'negociacao', orc:0, ven:22900,
   data:'2026-03-10T12:00:00Z', vendaAt:null, notas:[]},
]);
assert.equal(saiuDeVenda[0].vendas, 0, 'lead fora de venda nao conta viagem');
assert.equal(saiuDeVenda[0].valorVendido, 0, 'e nao entra no faturamento');
assert.equal(saiuDeVenda[0].temVenda, false, 'segue como lead, nao cliente');

// A mesma pessoa com uma venda de verdade e um interesse que saiu de
// venda: soma so a venda.
const mistura = poAgrupaPessoas([
  {id:1, tel:'(48) 99604-8882', nome:'Maria', status:'venda',      orc:0, ven:22900,
   data:'2026-03-10T12:00:00Z', vendaAt:'2026-03-20T12:00:00Z', notas:[]},
  {id:2, tel:'(48) 99604-8882', nome:'Maria', status:'negociacao', orc:0, ven:31000,
   data:'2026-09-01T12:00:00Z', vendaAt:null, notas:[]},
]);
assert.equal(mistura[0].vendas, 1, 'uma viagem feita');
assert.equal(mistura[0].valorVendido, 22900, 'so o valor do lead que esta em venda');

// --- entradas degeneradas nao podem derrubar o painel
assert.deepEqual(poAgrupaPessoas([]), [], 'lista vazia');
assert.equal(poAgrupaPessoas([{id:9, tel:'—', nome:'(sem nome)', status:'novo', orc:0, ven:0, data:null}]).length,
  1, 'lead sem telefone e sem data ainda vira uma pessoa');

// Registro que nao e objeto (null, undefined, string ou numero soltos) nao
// pode estourar o painel: sao leads reais em producao e um dado estranho
// no meio da lista nao pode tirar a tela do ar.
assert.deepEqual(poAgrupaPessoas([null]), [], 'null sozinho e ignorado, sem estourar');
assert.deepEqual(poAgrupaPessoas([undefined]), [], 'undefined sozinho e ignorado, sem estourar');
const comLixo = poAgrupaPessoas([
  {id:1, tel:'(48) 99604-8882', nome:'Maria', status:'novo', orc:0, ven:0, data:'2026-01-01T00:00:00Z'},
  null, undefined, 'string solta', 42,
]);
assert.equal(comLixo.length, 1, 'lixo misturado nao impede o lead valido de ser agrupado');
assert.equal(comLixo[0].nome, 'Maria', 'o lead valido continua com seus dados certos');

// Dois leads sem telefone E sem id nao podem colapsar na mesma pessoa: o
// fallback ficaria 'sem-tel:' pros dois, fundindo gente diferente numa
// ficha so. A posicao na lista garante que cada um vira sua propria pessoa
// quando nao ha mais nada para diferencia-los.
const semTelSemId = poAgrupaPessoas([
  {tel:'—', nome:'Fulana', status:'novo', orc:0, ven:0, data:'2026-01-01T00:00:00Z'},
  {tel:'', nome:'Sicrana', status:'novo', orc:0, ven:0, data:'2026-01-02T00:00:00Z'},
]);
assert.equal(semTelSemId.length, 2, 'dois leads sem telefone e sem id viram duas pessoas, nao uma');

// --- a ficha monta HTML seguro: nome e roteiro vem do WhatsApp, que e
// dado de terceiro. Sem escapar, um nome com "<img onerror>" executa
// script dentro do painel da cliente.
const ctxUi = new Function(
  poE164Src + pega('poDigitos') + pega('poChavePessoa') + pega('poAgrupaPessoas') +
  pega('poLinhaPessoa') +
  'function esc(s){return (s==null?"":String(s)).replace(/[&<>"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;","\\"":"&quot;"}[c]));}' +
  'function brl(n){return n>0?("R$ "+n):"—";}' +
  'function fmtData(d){return d?"01 set":"—";}' +
  '; return {poLinhaPessoa, poAgrupaPessoas};'
)();

const malicioso = ctxUi.poAgrupaPessoas([{
  id: 1, tel: '(48) 99604-8882', nome: '<img src=x onerror=alert(1)>',
  email: '', cidade: '', roteiro: '<script>', status: 'novo',
  orc: 0, ven: 0, data: '2026-09-01T12:00:00Z', notas: [],
}])[0];
const html = ctxUi.poLinhaPessoa(malicioso);
assert.ok(!html.includes('<img src=x'), 'nome de terceiro e escapado na lista');
assert.ok(html.includes('&lt;img'), 'o nome aparece escapado, nao sumido');
assert.ok(html.includes('data-chave="5548996048882"'), 'a linha carrega a chave da pessoa');


/* ---------- busca, selecao e exclusao (reuniao de 11/11/2026) ----------
   A agenda importada traz empresas (CVC, LATAM, Receita Federal) que a Lais
   precisa achar e tirar em lote. */
{
  const ctxB = new Function(
    pega('poSemAcento') + pega('poFiltraPessoas') + pega('poIdsDasPessoas') + pega('poTextoExclusao') +
    '; return {poFiltraPessoas, poIdsDasPessoas, poTextoExclusao};'
  )();
  const ps = [
    { chave: 'a', nome: 'CVC Turismo', tel: '+5548999990001', cidade: 'Floripa', leads: [{ id: 'L1' }], temVenda: false },
    { chave: 'b', nome: 'João da Silva', tel: '+5548996048882', cidade: 'São José', leads: [{ id: 'L2' }, { id: 'L3' }], temVenda: true },
    { chave: 'c', nome: 'Maria', tel: '—', cidade: '', leads: [{ id: 'L4' }], temVenda: false },
  ];
  assert.deepEqual(ctxB.poFiltraPessoas(ps, 'turismo').map(p => p.chave), ['a'], 'acha empresa pelo nome');
  assert.deepEqual(ctxB.poFiltraPessoas(ps, 'joao').map(p => p.chave), ['b'], 'busca ignora acento');
  assert.deepEqual(ctxB.poFiltraPessoas(ps, 'sao jose').map(p => p.chave), ['b'], 'acha pela cidade');
  assert.deepEqual(ctxB.poFiltraPessoas(ps, '9604-8882').map(p => p.chave), ['b'], 'acha pelo telefone, com ou sem traco');
  assert.equal(ctxB.poFiltraPessoas(ps, '  ').length, 3, 'busca vazia mostra todo mundo');

  assert.deepEqual(ctxB.poIdsDasPessoas(ps, new Set(['b', 'c'])), ['L2', 'L3', 'L4'],
    'excluir uma pessoa exclui todos os interesses dela');
  assert.deepEqual(ctxB.poIdsDasPessoas(ps, new Set()), [], 'nada selecionado, nada excluido');

  const t1 = ctxB.poTextoExclusao([ps[0]]);
  assert.ok(t1.includes('1 contato') && t1.includes('CVC Turismo') && t1.includes('não pode ser desfeito'), 'confirmacao de um');
  const t2 = ctxB.poTextoExclusao([ps[0], ps[1]]);
  assert.ok(t2.includes('2 contatos') && t2.includes('1 deles já viajou'), 'confirmacao avisa quem ja comprou');

  const fonte = readFileSync(new URL('../painel/clientes.js', import.meta.url), 'utf8');
  assert.ok(/\.from\('po_leads'\)\s*\.delete\(\)\s*\.in\('id',/.test(fonte), 'a exclusao apaga por id');
  assert.ok(/poRevLotes\(/.test(fonte), 'e em lotes de 100 (URL longa estoura o gateway)');
  assert.ok(/confirm\(poTextoExclusao\(/.test(fonte), 'nada e apagado sem confirmar');
  const html = readFileSync(new URL('../painel/index.html', import.meta.url), 'utf8');
  for (const id of ['cli-q', 'cli-todos', 'cli-excluir', 'fi-excluir']) {
    assert.ok(html.includes('id="' + id + '"'), 'a tela tem #' + id);
  }
}

console.log('test-clientes OK');
