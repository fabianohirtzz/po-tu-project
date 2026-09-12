import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

/* O recorte de cada tela do painel. As tres abas (Leads, Funil e Clientes)
   liam do mesmo filtered(), e isso dava resultado errado em duas delas:
   filtrar por status dentro do quadro de funil faz o card sumir ao ser
   movido (as colunas JA sao o status), e o filtro de mes fazia a base de
   clientes abrir mostrando so quem deu sinal no mes corrente. */
const src = readFileSync(new URL('../painel/app.js', import.meta.url), 'utf8');
function pega(nome) {
  // Funcao de varias linhas primeiro; se nao casar, a de uma linha so
  // (o app.js tem as duas formas). Extrai do fonte de verdade em vez de
  // reimplementar aqui, senao o teste passa com o painel quebrado.
  const m = src.match(new RegExp('function ' + nome + '\\([\\s\\S]*?\\n\\}')) ||
            src.match(new RegExp('function ' + nome + '\\([^\\n]*'));
  assert.ok(m, nome + ' encontrada no app.js');
  return m[0] + '\n';
}
const ctx = new Function(
  pega('monthKey') + pega('poFiltraLeads') +
  '; return {poFiltraLeads};'
)();
const { poFiltraLeads } = ctx;

const LEADS = [
  {id:1, nome:'Maria',  cidade:'Florianopolis', roteiro:'Grecia',  status:'venda',      origem:'whatsapp', data:'2026-09-02T12:00:00Z'},
  {id:2, nome:'Joao',   cidade:'Sao Paulo',     roteiro:'Turquia', status:'negociacao', origem:'pago',     data:'2026-09-03T12:00:00Z'},
  {id:3, nome:'Ana',    cidade:'Curitiba',      roteiro:'Chile',   status:'novo',       origem:'whatsapp', data:'2026-03-10T12:00:00Z'},
  {id:4, nome:'Carlos', cidade:'Blumenau',      roteiro:'Japao',   status:'venda',      origem:'organico', data:'2025-12-01T12:00:00Z'},
];

// Os tres recortes, iguais aos do app.js. Ficam aqui como dado para o
// teste descrever exatamente o que cada tela usa.
const USA_LEADS    = {mes:true,  origem:true, status:true};
const USA_FUNIL    = {mes:true,  origem:true, status:false};
const USA_CLIENTES = {mes:false, origem:true, status:true};

const F = (over) => Object.assign({month:'2026-09', orig:'todos', status:'todos', q:''}, over || {});

// --- aba Leads: continua exatamente como era, com os quatro filtros
const setembro = poFiltraLeads(LEADS, F(), USA_LEADS);
assert.deepEqual(setembro.map(l => l.id), [1, 2], 'Leads mostra so o mes selecionado');

const soVenda = poFiltraLeads(LEADS, F({status:'venda'}), USA_LEADS);
assert.deepEqual(soVenda.map(l => l.id), [1], 'Leads respeita o filtro de status');

const soPago = poFiltraLeads(LEADS, F({orig:'pago'}), USA_LEADS);
assert.deepEqual(soPago.map(l => l.id), [2], 'Leads respeita o filtro de origem');

const busca = poFiltraLeads(LEADS, F({month:'all', q:'curitiba'}), USA_LEADS);
assert.deepEqual(busca.map(l => l.id), [3], 'a busca olha nome, cidade e roteiro');

// --- Funil: ignora o status, respeita o resto.
// Com o status ligado, a dona filtrava por "Em negociacao", arrastava o
// card para "Contrato assinado" e ele sumia do quadro inteiro.
const funil = poFiltraLeads(LEADS, F({status:'negociacao'}), USA_FUNIL);
assert.deepEqual(funil.map(l => l.id), [1, 2],
  'o quadro mostra as colunas todas mesmo com filtro de status escolhido');

const funilMes = poFiltraLeads(LEADS, F({month:'2026-03'}), USA_FUNIL);
assert.deepEqual(funilMes.map(l => l.id), [3], 'o quadro respeita o mes');

const funilOrig = poFiltraLeads(LEADS, F({month:'all', orig:'whatsapp'}), USA_FUNIL);
assert.deepEqual(funilOrig.map(l => l.id), [1, 3], 'o quadro respeita a origem');

const funilBusca = poFiltraLeads(LEADS, F({month:'all', q:'maria'}), USA_FUNIL);
assert.deepEqual(funilBusca.map(l => l.id), [1], 'o quadro respeita a busca');

// --- Clientes: ignora o mes, respeita o resto.
// O seletor de mes comeca no mes mais recente; com ele ligado, a aba que
// existe para responder "quem ja viajou com a gente" abria mostrando so
// quem deu sinal neste mes.
const clientes = poFiltraLeads(LEADS, F(), USA_CLIENTES);
assert.deepEqual(clientes.map(l => l.id), [1, 2, 3, 4],
  'a base de clientes traz todo mundo, nao so o mes corrente');

const clientesStatus = poFiltraLeads(LEADS, F({status:'venda'}), USA_CLIENTES);
assert.deepEqual(clientesStatus.map(l => l.id), [1, 4], 'a base respeita o filtro de status');

const clientesOrig = poFiltraLeads(LEADS, F({orig:'whatsapp'}), USA_CLIENTES);
assert.deepEqual(clientesOrig.map(l => l.id), [1, 3], 'a base respeita a origem');

const clientesBusca = poFiltraLeads(LEADS, F({q:'carlos'}), USA_CLIENTES);
assert.deepEqual(clientesBusca.map(l => l.id), [4], 'a base respeita a busca');

// --- "todos os meses" continua trazendo tudo
assert.equal(poFiltraLeads(LEADS, F({month:'all'}), USA_LEADS).length, 4, 'todos os meses');

// --- lista vazia nao estoura
assert.deepEqual(poFiltraLeads([], F(), USA_LEADS), [], 'lista vazia');
assert.deepEqual(poFiltraLeads(null, F(), USA_LEADS), [], 'lista ausente');

// --- as tres telas apontam para o recorte certo no codigo de verdade
assert.ok(/function filtered\(\)\{return poFiltraLeads\(LEADS,F,\{mes:true,origem:true,status:true\}\)/.test(src),
  'a aba Leads usa os quatro filtros');
assert.ok(/function filtradosFunil\(\)\{return poFiltraLeads\(LEADS,F,\{mes:true,origem:true,status:false\}\)/.test(src),
  'o Funil desliga o filtro de status');
assert.ok(/function filtradasPessoas\(\)\{return poFiltraLeads\(LEADS,F,\{mes:false,origem:true,status:true\}\)/.test(src),
  'a aba Clientes desliga o filtro de mes');

const funilSrc = readFileSync(new URL('../painel/funil.js', import.meta.url), 'utf8');
assert.ok(funilSrc.includes('filtradosFunil()'), 'o quadro le de filtradosFunil');
const cliSrc = readFileSync(new URL('../painel/clientes.js', import.meta.url), 'utf8');
assert.ok(cliSrc.includes('filtradasPessoas()'), 'a aba Clientes le de filtradasPessoas');

console.log('test-filtros OK');
