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

// As funcoes puras da acao em massa, extraidas juntas: o poRevMarcar chama
// todas, entao elas tambem entram na casca montada mais abaixo.
// O tamanho do lote sai do fonte, nao e reescrito aqui: se ele subir para um
// valor que estoura a URL de novo, o teste do limite abaixo reprova.
const LOTE_SRC = (src.match(/var PO_REV_LOTE\s*=\s*\d+;/) || [])[0];
assert.ok(LOTE_SRC, 'PO_REV_LOTE definido no revisao.js');

const PURAS = ESC + LOTE_SRC + pega('poRevFiltra') + pega('poLinhaRevisaoContato') +
  pega('poRevChkPendente') + pega('poRevAplicaTodos') + pega('poRevContaRevisados') +
  pega('poRevConfirmaTexto') + pega('poRevLotes') + pega('poRevResultadoTexto');

const ctx = new Function(
  PURAS +
  '; return {poRevFiltra, poLinhaRevisaoContato, poRevChkPendente, poRevAplicaTodos,' +
  ' poRevContaRevisados, poRevConfirmaTexto, poRevLotes, poRevResultadoTexto};'
)();
const { poRevFiltra, poLinhaRevisaoContato, poRevChkPendente, poRevAplicaTodos,
        poRevContaRevisados, poRevConfirmaTexto, poRevLotes, poRevResultadoTexto } = ctx;

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
      // 'erro' pode ser uma FUNCAO do indice da chamada: e o que permite
      // simular uma fatia falhando no meio de um lote grande.
      'const sb = { from(tabela){ return { update(campos){ return {' +
      '  in(coluna, ids){ chamadas.push({tabela, campos, coluna, ids});' +
      '    const e = typeof erro === "function" ? erro(chamadas.length - 1) : erro;' +
      '    return Promise.resolve({error: e}); }' +
      '}; } }; } };' +
      'function toast(msg, err){ avisos.push({msg, err: !!err}); }' +
      'function poRenderRevisao(){ renders.push(1); }' +
      PURAS + pega('poRevSelecionados') + pegaAsync('poRevMarcar') +
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

/* E a pergunta tem que LER o LEADS para dizer quantos dos selecionados JA
   estavam revisados. Sem uma asserção sobre o texto da pergunta, trocar o
   poRevContaRevisados(...) por 0 dentro do poRevMarcar deixava a suite verde
   e o aviso sumia - o mesmo gatilho que ja mordeu este projeto quando o
   filtro de wa_id da conversa sumiu num commit sobre outro assunto e a tela
   passou a mostrar as mensagens de todos os contatos.

   Selecao MISTA e o caso real: com "mostrar tambem os ja revisados" ligada, a
   tela tem pendente e decidido juntos, e remarcar o decidido e a parte cara. */
const misto = [
  {id:'m1', nome:'Pendente',  origemImport:'agenda-esposa', revisado:false, cliente:false},
  {id:'m2', nome:'Decidido',  origemImport:'crm-toninho',   revisado:true,  cliente:true},
  {id:'m3', nome:'Decidido2', origemImport:'crm-toninho',   revisado:true,  cliente:true},
];
const acaoMista = montaAcao(misto);
await acaoMista.rodar(false);
assert.equal(acaoMista.perguntas.length, 1, 'pergunta uma vez na selecao mista');
assert.ok(acaoMista.perguntas[0].includes('3 contatos'), 'diz o tamanho da selecao');
assert.ok(acaoMista.perguntas[0].includes('já tinham sido revisados'),
  'e AVISA que parte da selecao ja estava revisada (a contagem vem do LEADS)');
assert.ok(/Atenção: 2 deles/.test(acaoMista.perguntas[0]),
  'com o numero certo: 2 dos 3 ja estavam revisados');

// Um so ja revisado: singular, e o numero continua vindo do LEADS.
const mistoUm = montaAcao([
  {id:'u1', origemImport:'agenda-esposa', revisado:false, cliente:false},
  {id:'u2', origemImport:'crm-toninho',   revisado:true,  cliente:true},
]);
await mistoUm.rodar(true);
assert.ok(/Atenção: 1 dele[s]? já tinha sido revisado/.test(mistoUm.perguntas[0]),
  'singular no singular, com a contagem real');

// Selecao so de pendentes: a frase NAO ganha o aviso (nao ha o que avisar).
const soPend = montaAcao([
  {id:'p1', origemImport:'agenda-esposa', revisado:false, cliente:false},
  {id:'p2', origemImport:'agenda-esposa', revisado:false, cliente:false},
]);
await soPend.rodar(true);
assert.ok(!/já tinha/.test(soPend.perguntas[0]),
  'sem nenhum ja revisado na selecao, a pergunta nao ganha ruido');

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

/* ============================================================
   O "MARCAR TODOS" SO ALCANCA OS PENDENTES.

   REPRODUCAO do buraco: hoje a base tem 775 contatos importados JA revisados
   (todos cliente=true, do CRM antigo) e ZERO pendentes. Com a caixa "mostrar
   tambem os ja revisados" ligada, esses 775 vao para a tabela; o "marcar
   todos" do cabecalho marcava TUDO o que estivesse na tela, sem distinguir
   pendente de decidido; e um clique em "Nao e cliente" mandava cliente=false
   para os 775 clientes reais - fora das campanhas de uma vez so, com a
   confirmacao dizendo apenas "Marcar 775 contatos", sem dizer quais.
============================================================ */

// A linha carrega o data-pend que o "marcar todos" le. Sem ele, o botao nao
// tem como distinguir pendente de ja revisado sem reler o LEADS.
const linhaPend = poLinhaRevisaoContato({id:'p1', nome:'Ana', tel:'', origemImport:'agenda-esposa',
                                         revisado:false});
const linhaJa = poLinhaRevisaoContato({id:'j1', nome:'Bia', tel:'', origemImport:'crm-toninho',
                                       revisado:true, cliente:true});
assert.ok(/data-pend="1"/.test(linhaPend), 'a linha pendente vem marcada como pendente');
assert.ok(!/data-pend/.test(linhaJa), 'a linha ja revisada nao');

const chk = (pend) => ({ checked:false, dataset: pend ? {pend:'1'} : {} });
assert.equal(poRevChkPendente(chk(true)), true, 'caixa de linha pendente');
assert.equal(poRevChkPendente(chk(false)), false, 'caixa de linha ja revisada');
assert.equal(poRevChkPendente(null), false, 'caixa ausente nao estoura');

// MARCAR: so os pendentes, mesmo com os revisados na tela.
const tela = [chk(true), chk(false), chk(false), chk(true)];
poRevAplicaTodos(tela, true);
assert.deepEqual(tela.map(c => c.checked), [true, false, false, true],
  'marcar todos alcanca so as linhas pendentes');

// Os 775 do CRM: todos ja revisados. "Marcar todos" nao seleciona nenhum.
const so775 = Array.from({length: 775}, () => chk(false));
poRevAplicaTodos(so775, true);
assert.equal(so775.filter(c => c.checked).length, 0,
  'a base ja revisada inteira nao pode ser selecionada de uma vez');

// DESMARCAR limpa TUDO, inclusive o ja revisado que foi marcado a mao -
// senao a caixa deixaria selecao presa sem caminho de volta.
const presa = [chk(true), chk(false)];
presa.forEach(c => { c.checked = true; });
poRevAplicaTodos(presa, false);
assert.deepEqual(presa.map(c => c.checked), [false, false], 'desmarcar todos limpa tudo');
poRevAplicaTodos(null, true);   // nao estoura sem linha nenhuma
poRevAplicaTodos([null, undefined], true);

/* ---------- a confirmacao diz quantos JA estavam revisados ---------- */
const baseMista = [
  {id:'a', revisado:false}, {id:'b', revisado:true}, {id:'c', revisado:true}, {id:'d', revisado:false},
];
assert.equal(poRevContaRevisados(baseMista, ['a','b','c']), 2, 'conta os ja revisados do lote');
assert.equal(poRevContaRevisados(baseMista, ['a','d']), 0, 'so pendentes: nenhum');
assert.equal(poRevContaRevisados(baseMista, [1, 'a']), 0, 'id que nao existe nao conta');
assert.equal(poRevContaRevisados(null, ['a']), 0, 'sem leads nao estoura');
assert.equal(poRevContaRevisados(baseMista, null), 0, 'sem ids nao estoura');

const conf775 = poRevConfirmaTexto(775, false, 775);
assert.ok(conf775.includes('775'), 'a confirmacao segue dizendo a contagem');
assert.ok(/já tinham sido revisados/.test(conf775),
  'e avisa que eles JA estavam revisados e vao ser remarcados');
assert.ok(/já tinha sido revisado\b/.test(poRevConfirmaTexto(2, true, 1)), 'singular no singular');
assert.ok(!/já tinha/.test(poRevConfirmaTexto(3, true, 0)),
  'sem nenhum ja revisado, a frase nao ganha ruido');
assert.ok(!/já tinha/.test(poRevConfirmaTexto(3, true)), 'nem quando o argumento nem vem');

/* ============================================================
   LOTE. O .in('id',[...]) vira query string: cada UUID custa ~43 caracteres
   depois do urlencode, e ~180 ids ja estouram o buffer de 8 KB do gateway -
   a resposta volta 414 com a mensagem crua no toast. Com os 775 da base sao
   ~33 KB: nunca sai. A spec 9.5 pede "lista em lotes, com acao em massa
   para marcar cliente", e o lote nunca tinha sido implementado.
============================================================ */
const idsN = (n) => Array.from({length: n}, (_, i) => 'id-' + i);
assert.deepEqual(poRevLotes(idsN(5), 2).map(l => l.length), [2, 2, 1], 'fatia pelo tamanho pedido');
assert.deepEqual(poRevLotes(idsN(4), 2).map(l => l.length), [2, 2], 'divisao exata nao gera fatia vazia');
assert.deepEqual(poRevLotes([], 2), [], 'lista vazia nao gera fatia');
assert.deepEqual(poRevLotes(null, 2), [], 'lista ausente nao estoura');
assert.deepEqual(poRevLotes(idsN(3)).map(l => l.length), [3], 'sem tamanho, usa o padrao');
assert.deepEqual(poRevLotes(idsN(3), 0).map(l => l.length), [3], 'tamanho invalido cai no padrao');
// Nenhum id se perde nem se repete no caminho.
const fatiado = poRevLotes(idsN(250), 100).flat();
assert.deepEqual(fatiado, idsN(250), 'as fatias remontam a lista original, na ordem');
// E o limite que motivou tudo: a fatia tem que caber na URL.
const tamLote = Number(LOTE_SRC.match(/\d+/)[0]);
assert.ok(tamLote > 0 && tamLote <= 150,
  'o lote cabe na URL de 8 KB (a ~43 caracteres por UUID, ~180 ja estouram)');

// Na acao em massa: 250 selecionados viram 3 PATCH, e nenhum id se perde.
const muitos = idsN(250).map(id => ({id, origemImport:'crm-toninho', revisado:false, cliente:false}));
const emLote = montaAcao(muitos);
await emLote.rodar(true);
assert.equal(emLote.chamadas.length, 3, '250 selecionados saem em 3 requisicoes, nao em uma so');
assert.deepEqual(emLote.chamadas.map(c => c.ids.length), [100, 100, 50], 'fatias de 100');
assert.deepEqual(emLote.chamadas.flatMap(c => c.ids), idsN(250), 'todos os ids sobem, sem repetir');
emLote.chamadas.forEach(c => assert.deepEqual(Object.keys(c.campos).sort(), ['cliente', 'revisado'],
  'cada fatia manda so os dois campos'));
assert.equal(muitos.every(l => l.revisado === true), true, 'os 250 ficam revisados em memoria');
assert.equal(emLote.perguntas.length, 1, 'uma confirmacao so para o lote inteiro');

/* Uma fatia falha no meio: as outras gravam, e o aviso NAO pode dizer
   sucesso total. Os 100 da fatia que falhou continuam revisado=false no
   banco; se a tela dissesse "250 contatos revisados" eles sumiriam da fila
   sem nunca terem sido revisados - fora da defesa obrigatoria da spec 8.1. */
const parcial = idsN(250).map(id => ({id, origemImport:'crm-toninho', revisado:false, cliente:false}));
const meio = montaAcao(parcial, { erro: (i) => (i === 1 ? { message: 'timeout' } : null) });
await meio.rodar(true);
assert.equal(meio.chamadas.length, 3, 'a falha de uma fatia nao aborta as seguintes');
assert.equal(parcial.filter(l => l.revisado).length, 150, 'so as fatias que gravaram andam na memoria');
assert.equal(parcial.slice(100, 200).every(l => l.revisado === false), true,
  'a fatia que falhou continua pendente na tela, como esta no banco');
assert.equal(meio.avisos.length, 1, 'avisa uma vez');
assert.equal(meio.avisos[0].err, true, 'e o aviso e de erro');
assert.ok(meio.avisos[0].msg.includes('150') && meio.avisos[0].msg.includes('100'),
  'o aviso diz quantos foram gravados e quantos nao');
assert.ok(meio.avisos[0].msg.includes('timeout'), 'e repassa o motivo');
assert.ok(!/^250 contatos revisados/.test(meio.avisos[0].msg), 'nao reporta sucesso total');

// O texto do resultado, isolado.
assert.equal(poRevResultadoTexto(3, 0, []), '3 contatos revisados.', 'tudo certo');
assert.equal(poRevResultadoTexto(1, 0, []), '1 contato revisado.', 'singular');
assert.ok(/Nada foi salvo/.test(poRevResultadoTexto(0, 5, ['boom'])), 'nada gravado e dito assim');
assert.ok(!/revisad[oa]s?\./.test(poRevResultadoTexto(0, 5, ['boom'])),
  'e nao diz "revisados" quando nada foi revisado');
// Motivos repetidos (a mesma falha em tres fatias) aparecem uma vez so.
assert.equal((poRevResultadoTexto(0, 300, ['boom','boom','boom']).match(/boom/g) || []).length, 1,
  'o mesmo erro nao e repetido tres vezes no toast');

/* ============================================================
   A TELA: a ligacao dos botoes e o texto da aba.
============================================================ */
const app = readFileSync(new URL('../painel/app.js', import.meta.url), 'utf8');
const painel = readFileSync(new URL('../painel/index.html', import.meta.url), 'utf8');

// O "marcar todos" tem que passar por poRevAplicaTodos. A versao anterior
// marcava '.rev-chk' inteiro na mao, e era ai que os 775 entravam.
assert.ok(/#rev-todos'\)\.onchange=e=>poRevAplicaTodos\(\$\$\('#rev-rows \.rev-chk'\),e\.target\.checked\)/.test(app),
  'o "marcar todos" passa pelo poRevAplicaTodos');
assert.ok(!/#rev-rows \.rev-chk'\)\.forEach\(c=>\{c\.checked=/.test(app),
  'e nao marca a tabela inteira na mao');

/* Os quatro handlers da aba nova sao ligados COM GUARDA DE NULO. O deploy e
   FTP manual, arquivo a arquivo: subir o app.js antes do index.html faria
   $('#rev-cliente') devolver null, o .onclick estourar TypeError e o script
   parar - matando o painel INTEIRO, nao so a aba nova. */
for (const id of ['rev-cliente', 'rev-naocliente', 'rev-todos', 'rev-revisados']) {
  assert.ok(new RegExp("if\\(\\$\\('#" + id + "'\\)\\)\\$\\('#" + id + "'\\)\\.on").test(app),
    '#' + id + ' e ligado com guarda de nulo');
}

/* O texto da aba tem que descrever a regra REAL de entrada. Ele dizia que a
   agenda "tem medico, fornecedor e familia no meio", mas o importador so
   aceita contato com o marcador PO no nome (wa_import_tem_marcador) e recusa
   o arquivo inteiro com 422 quando nao ha nenhum. As duas frases nao podiam
   estar certas. */
const hintRev = (painel.match(/id="view-revisao"[\s\S]*?<div class="hint">([\s\S]*?)<\/div>/) || ['', ''])[1];
assert.ok(/<b>PO<\/b>/.test(hintRev), 'a aba diz que so entra quem tem PO no nome');
assert.ok(!/A agenda tem médico, fornecedor e família/.test(painel),
  'e nao promete mais importar a agenda inteira');

// Cache-buster: deploy e FTP manual e o .htaccess cacheia JS por 1 mes.
// Arquivo alterado sem ?v= novo chega velho no navegador da cliente.
// O numero do app.js anda a cada mudanca nele (a aba de textos do robo o
// levou de 7 para 8): o que o teste tranca e que ele NAO fique parado.
for (const [arq, v] of [['app.js', 8], ['revisao.js', 2], ['importar-contatos.js', 5]]) {
  assert.ok(painel.includes('src="' + arq + '?v=' + v + '"'),
    arq + ' subiu para ?v=' + v);
}

console.log('test-revisao OK');
