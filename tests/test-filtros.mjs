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

// Os relatorios nao podem contar a base importada no denominador: as 775 fichas
// do CRM entraram com created_at=hoje e derrubariam a conversao do mes para ~0.
// A extracao tem que fechar no \n} da propria funcao (o mesmo padrao usado
// abaixo para renderLeads): sem ancora de fechamento, a regex casa com
// qualquer origemImport depois do nome da funcao em qualquer ponto do
// arquivo, e renderReports() logo abaixo ja usa l.origemImport no calculo
// do contador — o teste ficava verde mesmo com o filtro removido.
const rowsForReportsFn = src.match(/function rowsForReports\(\)[\s\S]*?\n\}/);
assert.ok(rowsForReportsFn, 'rowsForReports encontrada');
assert.ok(/poCorteImportados\(/.test(rowsForReportsFn[0]),
  'rowsForReports passa pelo corte da base importada');
assert.ok(/F\.importados/.test(src),
  'existe um estado de filtro para incluir ou nao os importados');

/* O corte em si, exercitado como funcao: e o UNICO lugar onde origemImport
   decide quem conta, e agora DUAS telas o usam (Relatorios e o KPI da aba
   Leads). Testar o comportamento, e nao so a presenca da palavra no fonte. */
const { poCorteImportados } = new Function(pega('poCorteImportados') + '; return {poCorteImportados};')();
const MISTO = [
  {id:1, origemImport:''},               // lead normal do site
  {id:2, origemImport:null},             // idem, campo nulo do banco
  {id:3, origemImport:'crm-toninho'},    // base importada
  {id:4, origemImport:'agenda-esposa'},  // base importada
];
assert.deepEqual(poCorteImportados(MISTO, {importados:false}).map(l => l.id), [1, 2],
  'por padrao a base importada fica fora da conta');
assert.deepEqual(poCorteImportados(MISTO, {importados:true}).map(l => l.id), [1, 2, 3, 4],
  'com o controle ligado, entra todo mundo');
assert.deepEqual(poCorteImportados(null, {importados:false}), [], 'lista ausente nao estoura');

/* E a TABELA da aba Leads NAO muda: ela sempre mostrou tudo, e continuar
   mostrando e o que permite a cliente achar a ficha de um cliente antigo.
   O que mudou foi so o KPI, que abria dizendo "Leads no periodo: 775" com 0%
   de conversao enquanto os Relatorios, do mesmo mes, diziam 0 leads. As duas
   asserceos abaixo separam as duas coisas: a tabela recebe 'rows' inteiro, o
   KPI recebe o corte. */
const leads = src.match(/function renderLeads\(\)[\s\S]*?\n\}/);
assert.ok(leads, 'renderLeads encontrada');
assert.ok(!/origemImport/.test(leads[0]),
  'a aba Leads nao filtra por origemImport na mao');
assert.ok(/tb\.innerHTML=rows\.map/.test(leads[0]),
  'a TABELA da aba Leads recebe as linhas inteiras, sem corte');
assert.ok(/const doKpi=poCorteImportados\(rows,F\);/.test(leads[0]) &&
          /renderLeadKpis\(doKpi,rows\.length-doKpi\.length\)/.test(leads[0]),
  'e o KPI recebe o mesmo corte dos Relatorios, mais quantos ficaram de fora');

/* O KPI explica o proprio numero. A aba Leads nao tem o controle
   #rep-importados (ele mora so em Relatorios), entao "Leads no periodo: 0"
   sobre uma tabela com 775 linhas precisa dizer por que - senao a correcao
   so mudou a contradicao de lugar, de duas abas para a mesma tela. */
const kpis = src.match(/function renderLeadKpis\([\s\S]*?\n\}/);
assert.ok(kpis, 'renderLeadKpis encontrada');
const { renderLeadKpis } = new Function(
  'let alvo=null;' +
  'const $=()=>({set innerHTML(v){alvo=v;}});' +
  'function isPago(o){return o==="pago";}' +
  'function brl2(v){return "R$ "+v;}' +
  kpis[0] + '; return {renderLeadKpis:(r,f)=>{renderLeadKpis(r,f);return alvo;}};'
)();
const linha = (over) => Object.assign({status:'novo', origem:'organico', ven:0, orc:0}, over||{});

const comFora = renderLeadKpis([], 775);
assert.ok(/Leads no período/.test(comFora), 'o KPI e o de "Leads no periodo"');
assert.ok(/775 contatos importados fora da conta/.test(comFora),
  'o subtexto explica quantos a tabela mostra e o KPI nao conta');
assert.ok(/<div class="kpi-n">0<\/div>/.test(comFora),
  'e o total em si segue sendo o do recorte (0), nao os 775 da tabela');
// O numero do subtexto vem do argumento, nao e fixo no fonte.
assert.ok(/42 contatos importados fora da conta/.test(renderLeadKpis([], 42)),
  'o numero do subtexto vem da conta');
assert.ok(/1 contato importado fora da conta/.test(renderLeadKpis([], 1)),
  'singular no singular');

// Sem importado no recorte, o subtexto volta ao que sempre foi.
const semFora = renderLeadKpis([linha({origem:'pago'}), linha()], 0);
assert.ok(/1 pago · 1 orgânico/.test(semFora), 'caso normal nao ganha ruido');
assert.ok(!/fora da conta/.test(semFora), 'e nao fala de importados quando nao ha nenhum');
assert.ok(/1 pago · 1 orgânico/.test(renderLeadKpis([linha({origem:'pago'}), linha()])),
  'sem o argumento (chamada antiga) tambem cai no caso normal');
assert.ok(!/poCorteImportados/.test((src.match(/function filtered\(\)[^\n]*/) || [''])[0]),
  'filtered() — a fonte da tabela — segue sem o corte');

/* ---------- o controle "incluir a base importada" comeca desmarcado.
   Mesmo padrao do checkbox #ic-forcar em test-importar-contatos.mjs: se um
   dia nascer marcado, os relatorios abrem contando as 775 fichas do CRM e a
   conversao do mes despenca de novo, em silencio. ---------- */
const painel = readFileSync(new URL('../painel/index.html', import.meta.url), 'utf8');
const repImportadosTag = (painel.match(/<input[^>]*id="rep-importados"[^>]*>/) || [''])[0];
assert.ok(repImportadosTag, 'existe o checkbox #rep-importados');
assert.ok(!/checked/.test(repImportadosTag),
  'a caixa "incluir a base importada" comeca desmarcada');

console.log('test-filtros OK');
