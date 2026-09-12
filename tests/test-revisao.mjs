import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

// Extrai as funcoes puras do revisao.js sem carregar o painel inteiro,
// no mesmo padrao de tests/test-clientes.mjs.
const src = readFileSync(new URL('../painel/revisao.js', import.meta.url), 'utf8');
function pega(nome) {
  const m = src.match(new RegExp('function ' + nome + '\\([\\s\\S]*?\\n\\}'));
  assert.ok(m, nome + ' encontrada no revisao.js');
  return m[0];
}
// A acao em massa e async: sem capturar o 'async' o corpo extraido teria
// 'await' dentro de funcao sincrona e nem compilaria.
function pegaAsync(nome) {
  const m = src.match(new RegExp('async function ' + nome + '\\([\\s\\S]*?\\n\\}'));
  assert.ok(m, nome + ' (async) encontrada no revisao.js');
  return m[0];
}
const ESC = 'function esc(s){return (s==null?"":String(s)).replace(/[&<>"]/g,' +
            'c=>({"&":"&amp;","<":"&lt;",">":"&gt;","\\"":"&quot;"}[c]));}';

const ctx = new Function(
  ESC + pega('poRevFiltra') + pega('poLinhaRevisaoContato') +
  '; return {poRevFiltra, poLinhaRevisaoContato};'
)();
const { poRevFiltra, poLinhaRevisaoContato } = ctx;

const base = [
  {id:'1', nome:'Ana',   origemImport:'agenda-esposa', revisado:false, cliente:false, tel:'+5548999990001'},
  {id:'2', nome:'Bruno', origemImport:'agenda-esposa', revisado:true,  cliente:true,  tel:'+5548999990002'},
  {id:'3', nome:'Carla', origemImport:'crm-toninho',   revisado:true,  cliente:true,  tel:'—'},
  {id:'4', nome:'Davi',  origemImport:'',              revisado:false, cliente:false, tel:'+5548999990004'},
];

// So entra quem veio de importacao E ainda nao foi revisado.
const pend = poRevFiltra(base);
assert.equal(pend.length, 1, 'so um contato pendente de revisao');
assert.equal(pend[0].nome, 'Ana', 'e o certo');

// Lead do formulario do site NAO entra aqui: ele nunca foi "importado" e a
// revisao existe para a agenda, que tem medico e fornecedor no meio.
assert.ok(!pend.some(p => p.nome === 'Davi'), 'lead do site nao vai para a fila de revisao');
// Nem quando o campo chega ausente/nulo do banco em vez de string vazia.
assert.deepEqual(
  poRevFiltra([{id:'9', nome:'Elis', revisado:false}, {id:'10', nome:'Fabio', origemImport:null, revisado:false}]),
  [], 'origem ausente ou nula tambem fica fora da fila');

// Contato do CRM ja revisado nao volta para a fila: a cliente ja decidiu
// sobre ele, e refazer a mesma triagem toda semana mataria o uso da tela.
assert.ok(!pend.some(p => p.origemImport === 'crm-toninho'), 'contato do CRM ja revisado nao reaparece');
// Vale tambem para quem foi revisado e marcado como NAO cliente: "revisado"
// e "eu olhei", nao "virou cliente". Marcar "nao e cliente" nao pode
// devolver a pessoa para a fila no proximo carregamento.
assert.deepEqual(
  poRevFiltra([{id:'5', nome:'Dentista', origemImport:'agenda-marido', revisado:true, cliente:false}]),
  [], 'revisado como nao-cliente sai da fila de vez');

// Entradas degeneradas nao derrubam a tela.
assert.deepEqual(poRevFiltra([]), [], 'lista vazia');
assert.deepEqual(poRevFiltra([null, undefined, 'lixo', 42]), [], 'lixo e ignorado');
assert.deepEqual(poRevFiltra(null), [], 'lista nula e ignorada');

// A linha carrega o id para a acao em massa, e escapa o nome.
const html = poLinhaRevisaoContato({id:'x1', nome:'<img src=x onerror=alert(1)>',
                                    tel:'+5548999990001', origemImport:'agenda-esposa'});
assert.ok(html.includes('value="x1"'), 'a linha carrega o id');
assert.ok(!html.includes('<img src=x'), 'nome de terceiro escapado');
assert.ok(html.includes('&lt;img'), 'aparece escapado, nao sumido');
assert.ok(/type="checkbox"/.test(html), 'tem caixa de selecao para a acao em massa');
// O id tambem vem de fora (uuid do banco, mas o painel nao pode confiar):
// aspas soltas no value quebrariam o atributo e plantariam HTML.
const htmlAspas = poLinhaRevisaoContato({id: 'a" onclick="alert(1)', nome:'Ana', tel:'', origemImport:'agenda-esposa'});
assert.ok(!htmlAspas.includes('onclick="alert(1)"'), 'id malicioso nao escapa do atributo');
assert.ok(htmlAspas.includes('&quot;'), 'aspas do id saem escapadas');
// Contato degenerado (sem nada) nao pode estourar a montagem da linha.
assert.ok(poLinhaRevisaoContato(null).includes('<tr>'), 'linha de contato nulo nao estoura');

/* ---------- acao em massa: o que sobe para o banco ---------- */

function montaAcao(leads) {
  const chamadas = [];
  const avisos = [];
  const caixas = { querySelectorAll: () => leads.map(l => ({ value: l.id })) };
  return {
    chamadas, avisos, leads,
    rodar: new Function(
      'chamadas', 'avisos', 'caixas', 'LEADS',
      'const document = { querySelector: () => caixas };' +
      'const sb = { from(tabela){ return { update(campos){ return {' +
      '  in(coluna, ids){ chamadas.push({tabela, campos, coluna, ids}); return Promise.resolve({error:null}); }' +
      '}; } }; } };' +
      'function toast(msg, err){ avisos.push({msg, err: !!err}); }' +
      'function poRenderRevisao(){}' +
      pega('poRevSelecionados') + pegaAsync('poRevMarcar') +
      '; return poRevMarcar;'
    )(chamadas, avisos, caixas, leads),
  };
}

const alvo = [
  {id:'1', nome:'Ana',  origemImport:'agenda-esposa', revisado:false, cliente:false,
   status:'semresposta', ven:0, vendaAt:null, notas:[]},
  {id:'2', nome:'Bia',  origemImport:'agenda-esposa', revisado:false, cliente:false,
   status:'venda', ven:22900, vendaAt:'2026-03-20T12:00:00Z', notas:[{t:'nota'}]},
];
const acao = montaAcao(alvo);
await acao.rodar(true);

assert.equal(acao.chamadas.length, 1, 'um unico update para os selecionados');
const up = acao.chamadas[0];
assert.equal(up.tabela, 'po_leads', 'escreve em po_leads');
assert.equal(up.coluna, 'id', 'filtra pelos ids selecionados');
assert.deepEqual(up.ids, ['1', '2'], 'sobe os dois ids marcados');

// O update toca EXATAMENTE dois campos. Mandar status/venda/venda_at/notas
// junto e o modo de falha que ja assombrou o funil e o faturamento: um
// salvamento de revisao nao pode recarimbar a data do fechamento nem
// reescrever o valor da venda de quem ja e cliente.
assert.deepEqual(Object.keys(up.campos).sort(), ['cliente', 'revisado'],
  'o update manda so revisado e cliente');
for (const proibido of ['status', 'venda', 'venda_at', 'notas', 'orcamento']) {
  assert.ok(!(proibido in up.campos), proibido + ' nunca entra no update da revisao');
}
assert.equal(up.campos.revisado, true, 'revisado=true: a revisao aconteceu');
assert.equal(up.campos.cliente, true, 'marcado como cliente');

// "Nao e cliente" tambem e revisao: revisado=true, cliente=false. A pessoa
// continua na base (nao e exclusao), so fica fora de disparo.
const acao2 = montaAcao(alvo);
await acao2.rodar(false);
assert.equal(acao2.chamadas[0].campos.revisado, true, '"nao e cliente" tambem marca revisado');
assert.equal(acao2.chamadas[0].campos.cliente, false, 'e deixa cliente=false');
assert.deepEqual(Object.keys(acao2.chamadas[0].campos).sort(), ['cliente', 'revisado'],
  'o update de "nao e cliente" tambem manda so os dois campos');

// A lista em memoria acompanha o banco: sem isso o contato continuaria
// aparecendo na fila ate a proxima recarga da pagina.
assert.equal(alvo[0].revisado, true, 'o lead em memoria fica revisado');
assert.equal(alvo[1].cliente, false, 'e com a resposta da ultima acao');
assert.deepEqual(poRevFiltra(alvo), [], 'os revisados somem da fila sem recarregar a pagina');
// E nada mais do lead foi tocado de lado.
assert.equal(alvo[1].status, 'venda', 'status do lead intocado');
assert.equal(alvo[1].ven, 22900, 'valor de venda intocado');
assert.equal(alvo[1].vendaAt, '2026-03-20T12:00:00Z', 'carimbo do fechamento intocado');

// Sem nenhum selecionado, nao escreve nada no banco e avisa.
const vazio = montaAcao([]);
await vazio.rodar(true);
assert.equal(vazio.chamadas.length, 0, 'nada selecionado, nada escrito');
assert.equal(vazio.avisos.length, 1, 'avisa a cliente');
assert.equal(vazio.avisos[0].err, true, 'o aviso e de erro');

console.log('test-revisao OK');
