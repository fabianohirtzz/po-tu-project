import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

// Extrai a regra de gravacao da gaveta do app.js sem carregar o painel
// inteiro, no mesmo padrao dos outros testes .mjs.
const src = readFileSync(new URL('../painel/app.js', import.meta.url), 'utf8');
function pega(nome) {
  const m = src.match(new RegExp('function ' + nome + '\\([\\s\\S]*?\\n\\}'));
  assert.ok(m, nome + ' encontrada no app.js');
  return m[0];
}
const poPatchGaveta = new Function(pega('poPatchGaveta') + '; return poPatchGaveta;')();

const AGORA = '2026-09-11T12:00:00Z';

// --- preencher a venda agora conclui o lead e carimba a data
const novaVenda = poPatchGaveta(
  {ven: '22900', orc: '0', status: 'negociacao', origem: 'whatsapp'},
  {ven: 0, vendaAt: null}, AGORA);
assert.equal(novaVenda.status, 'venda', 'valor preenchido agora conclui o lead');
assert.equal(novaVenda.venda_at, AGORA, 'e carimba a data do fechamento');

// --- reeditar o valor de uma venda nao reinicia a contagem do ciclo
const reeditou = poPatchGaveta(
  {ven: '23900', orc: '0', status: 'venda', origem: 'whatsapp'},
  {ven: 22900, vendaAt: '2026-03-20T12:00:00Z'}, AGORA);
assert.equal(reeditou.status, 'venda', 'segue vendida');
assert.equal(reeditou.venda_at, '2026-03-20T12:00:00Z', 'a data original e preservada');

// --- O CASO QUE MOTIVOU ESTA FUNCAO: o quadro do funil tira o card de
// "Contrato assinado" (status vira negociacao, venda_at vira null) mas
// MANTEM o valor da venda de proposito. Dias depois a dona abre esse lead
// so para escrever uma anotacao e clica em Salvar. Isso nao pode
// ressuscitar a venda nem carimbar venda_at com a data de hoje, porque a
// data real do fechamento alimenta o ciclo de venda dos relatorios.
const soNota = poPatchGaveta(
  {ven: '22900', orc: '0', status: 'negociacao', origem: 'whatsapp'},
  {ven: 22900, vendaAt: null}, AGORA);
assert.equal(soNota.status, 'negociacao', 'salvar sem mexer no valor respeita o status da tela');
assert.equal(soNota.venda_at, null, 'e nao carimba venda_at com a data de hoje');
assert.equal(soNota.venda, 22900, 'o valor continua guardado no lead');

// O mesmo lead, com a data real ainda no banco: salvar uma nota nao pode
// reescrever nem apagar o que ja existe enquanto o status for venda.
const notaEmVenda = poPatchGaveta(
  {ven: '22900', orc: '0', status: 'venda', origem: 'whatsapp'},
  {ven: 22900, vendaAt: '2026-03-20T12:00:00Z'}, AGORA);
assert.equal(notaEmVenda.venda_at, '2026-03-20T12:00:00Z', 'data real do fechamento intacta');

// --- mudar o status na mao para venda continua funcionando
const naMao = poPatchGaveta(
  {ven: '0', orc: '18000', status: 'venda', origem: 'site'},
  {ven: 0, vendaAt: null}, AGORA);
assert.equal(naMao.status, 'venda', 'o select da gaveta fecha o lead sozinho');
assert.equal(naMao.venda_at, AGORA, 'com carimbo');

// --- desfazer a venda limpa o carimbo
const desfez = poPatchGaveta(
  {ven: '0', orc: '18000', status: 'perdido', origem: 'site'},
  {ven: 22900, vendaAt: '2026-03-20T12:00:00Z'}, AGORA);
assert.equal(desfez.status, 'perdido', 'zerar o valor e trocar o status desfaz a venda');
assert.equal(desfez.venda_at, null, 'e limpa o carimbo');

// --- campos vazios viram zero, nunca NaN no banco
const vazio = poPatchGaveta(
  {ven: '', orc: '', status: 'novo', origem: 'direto'},
  {ven: 0, vendaAt: null}, AGORA);
assert.equal(vazio.venda, 0, 'campo vazio vira zero');
assert.equal(vazio.orcamento, 0, 'orcamento vazio vira zero');

console.log('test-gaveta OK');

// Mesma forma de defeito: a regra de so recarimbar venda_at quando o valor
// mudou vive numa funcao pura, e nada impede alguem de montar o patch inline.
assert.ok(!/venda_at\s*:\s*new Date\(\)/.test(src),
  'venda_at nunca e carimbado inline, so pela regra de poPatchGaveta');

console.log('test-gaveta (ponto de chamada) OK');
