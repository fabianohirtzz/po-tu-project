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
  ESC + pega('poRevFiltra') + pega('poLinhaRevisaoContato') + pega('poRevConfirmaTexto') +
  '; return {poRevFiltra, poLinhaRevisaoContato, poRevConfirmaTexto};'
)();
const { poRevFiltra, poLinhaRevisaoContato, poRevConfirmaTexto } = ctx;

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

// Caminho de volta: com o segundo argumento a fila inclui os ja revisados,
// que e a UNICA forma de a cliente corrigir um clique errado. Nenhuma outra
// tela do painel escreve 'revisado'/'cliente'; sem isto a marcacao seria de
// mao unica e so teria conserto por SQL no banco.
const comRevisados = poRevFiltra(base, true);
assert.equal(comRevisados.length, 3, 'ver revisados traz os tres contatos importados');
assert.deepEqual(comRevisados.map(p => p.nome), ['Ana', 'Bruno', 'Carla'], 'na ordem da lista');
assert.ok(!comRevisados.some(p => p.nome === 'Davi'), 'e o lead do site continua fora, mesmo assim');
// So 'true' liga o modo de correcao: um argumento solto (um evento de clique,
// por exemplo) nao pode escancarar a fila sem querer.
assert.equal(poRevFiltra(base, 'sim').length, 1, 'so o booleano true inclui os revisados');
assert.equal(poRevFiltra(base, false).length, 1, 'false mantem so os pendentes');

// A situacao aparece na linha: sem ela a cliente nao ve o que marcou e nao
// tem como corrigir.
assert.ok(poLinhaRevisaoContato({id:'1', origemImport:'agenda-esposa', revisado:false}).includes('Aguardando revisão'),
  'pendente aparece como aguardando');
assert.ok(poLinhaRevisaoContato({id:'2', origemImport:'agenda-esposa', revisado:true, cliente:true}).includes('Cliente'),
  'revisado como cliente aparece assim');
assert.ok(poLinhaRevisaoContato({id:'3', origemImport:'agenda-esposa', revisado:true, cliente:false}).includes('Não é cliente'),
  'revisado como nao-cliente aparece assim');

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

// TODOS os campos da linha vem da agenda de outra pessoa, nao so o nome: um
// vCard e texto livre e o telefone e a origem tambem chegam como o contato
// (ou o arquivo) mandou. Os tres vetores ficam trancados aqui.
const htmlTel = poLinhaRevisaoContato({id:'t1', nome:'Ana',
  tel:'<img src=x onerror=alert(1)>', origemImport:'agenda-esposa'});
assert.ok(!htmlTel.includes('<img src=x'), 'telefone de terceiro escapado');
assert.ok(htmlTel.includes('&lt;img'), 'telefone aparece escapado, nao sumido');
const htmlOrig = poLinhaRevisaoContato({id:'o1', nome:'Ana', tel:'+5548999990001',
  origemImport:'<img src=x onerror=alert(1)>'});
assert.ok(!htmlOrig.includes('<img src=x'), 'origem escapada');
assert.ok(htmlOrig.includes('&lt;img'), 'origem aparece escapada, nao sumida');

/* ---------- a confirmacao antes de gravar ---------- */
// O "marcar todos" faz um clique valer por centenas de contatos, e o erro e
// caro nos dois sentidos: cliente=true torna o dentista elegivel a
// transmissao PAGA por destinatario; cliente=false tira cliente real das
// campanhas em silencio. A frase precisa dizer a CONTAGEM e a consequencia.
const conf40 = poRevConfirmaTexto(40, true);
assert.ok(conf40.includes('40'), 'a confirmacao diz quantos contatos');
assert.ok(/transmiss/i.test(conf40), 'e diz o que "cliente" libera');
assert.ok(poRevConfirmaTexto(1, true).includes('1 contato?') ||
          /\b1 contato\b/.test(poRevConfirmaTexto(1, true)), 'singular no singular');
const confNao = poRevConfirmaTexto(40, false);
assert.ok(confNao.includes('40'), 'a confirmacao de "nao e cliente" tambem conta');
assert.ok(/apagad|continuam na base/i.test(confNao), 'e deixa claro que ninguem e apagado');

/* ---------- acao em massa: o que sobe para o banco ---------- */

function montaAcao(leads, opts) {
  const o = opts || {};
  const chamadas = [];
  const avisos = [];
  const perguntas = [];
  const renders = [];
  const caixas = { querySelectorAll: () => leads.map(l => ({ value: l.id })) };
  const janela = { confirm(msg) { perguntas.push(msg); return o.confirma !== false; } };
  const erro = o.erro || null;
  return {
    chamadas, avisos, perguntas, renders, leads,
    rodar: new Function(
      'chamadas', 'avisos', 'perguntas', 'renders', 'caixas', 'LEADS', 'window', 'erro',
      'const document = { querySelector: () => caixas };' +
      'const sb = { from(tabela){ return { update(campos){ return {' +
      '  in(coluna, ids){ chamadas.push({tabela, campos, coluna, ids}); return Promise.resolve({error: erro}); }' +
      '}; } }; } };' +
      'function toast(msg, err){ avisos.push({msg, err: !!err}); }' +
      'function poRenderRevisao(){ renders.push(1); }' +
      pega('poRevConfirmaTexto') + pega('poRevSelecionados') + pegaAsync('poRevMarcar') +
      '; return poRevMarcar;'
    )(chamadas, avisos, perguntas, renders, caixas, leads, janela, erro),
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
assert.equal(vazio.perguntas.length, 0, 'e nem pergunta nada');

// A confirmacao acontece ANTES de escrever, e diz a contagem.
const perguntou = montaAcao([{id:'1'}, {id:'2'}, {id:'3'}]);
await perguntou.rodar(true);
assert.equal(perguntou.perguntas.length, 1, 'pergunta uma vez');
assert.ok(perguntou.perguntas[0].includes('3'), 'a pergunta traz a contagem do lote');

// Cancelar no "tem certeza?" nao escreve nada. Sem isto a confirmacao seria
// enfeite, e o clique errado com o "marcar todos" ligado continuaria caro.
const cancelou = montaAcao([{id:'1'}, {id:'2'}], { confirma: false });
await cancelou.rodar(true);
assert.equal(cancelou.perguntas.length, 1, 'perguntou');
assert.equal(cancelou.chamadas.length, 0, 'cancelou: nada foi escrito no banco');
assert.equal(cancelou.avisos.length, 0, 'e nao finge que salvou');

// Banco falhou: avisa o erro e NAO mente dizendo que revisou. A lista em
// memoria nao pode andar sozinha, senao o contato sai da fila na tela e
// continua revisado=false no banco - sumido da defesa obrigatoria sem nunca
// ter sido revisado de verdade.
const falhou = montaAcao(
  [{id:'1', nome:'Ana', origemImport:'agenda-esposa', revisado:false, cliente:false}],
  { erro: { message: 'permission denied' } });
await falhou.rodar(true);
assert.equal(falhou.chamadas.length, 1, 'tentou escrever');
assert.equal(falhou.leads[0].revisado, false, 'o lead em memoria NAO fica revisado quando o banco falha');
assert.equal(falhou.renders.length, 0, 'nem redesenha a fila como se tivesse dado certo');
assert.equal(falhou.avisos.length, 1, 'avisa uma vez');
assert.equal(falhou.avisos[0].err, true, 'e o aviso e de erro');
assert.ok(falhou.avisos[0].msg.includes('permission denied'), 'repassa a mensagem do banco');
assert.ok(!/revisad[oa]s?\./.test(falhou.avisos[0].msg), 'nao diz "contato revisado" depois de falhar');

console.log('test-revisao OK');
