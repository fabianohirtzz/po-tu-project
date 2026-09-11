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
const ctx = new Function(
  pega('poDigitos') + pega('poChavePessoa') + pega('poAgrupaPessoas') +
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
  pega('poDigitos') + pega('poChavePessoa') + pega('poAgrupaPessoas') +
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

console.log('test-clientes OK');
