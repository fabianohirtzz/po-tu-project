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
assert.ok(!/venda_at\s*[:=]\s*new Date\(\)/.test(src),
  'venda_at nunca e carimbado inline, nem por objeto literal nem por atribuicao');


/* "Perdeu so pela data" (23/10/2026): marcado na gaveta de um lead Perdido,
   grava qualif_data=false, a MESMA coluna que o robo grava quando a pessoa
   responde que a data nao serve. E o que poe o lead na transmissao. */
const marcou = poPatchGaveta({ven:'0', orc:'0', status:'perdido', origem:'site', pdata:true},
  {ven:0, vendaAt:null, perdeuData:false}, AGORA);
assert.equal(marcou.qualif_data, false, 'marcado: grava qualif_data=false');
const desmarcou = poPatchGaveta({ven:'0', orc:'0', status:'perdido', origem:'site', pdata:false},
  {ven:0, vendaAt:null, perdeuData:true}, AGORA);
assert.equal(desmarcou.qualif_data, null, 'desmarcado: limpa o motivo');
const outro = poPatchGaveta({ven:'0', orc:'0', status:'atendimento', origem:'site', pdata:true},
  {ven:0, vendaAt:null, perdeuData:false}, AGORA);
assert.ok(!('qualif_data' in outro), 'fora de Perdido nao toca qualif_data (o robo grava true no qualificado)');
const nada = poPatchGaveta({ven:'0', orc:'0', status:'perdido', origem:'site', pdata:false},
  {ven:0, vendaAt:null, perdeuData:false}, AGORA);
assert.ok(!('qualif_data' in nada), 'perdido sem marcar e sem marca antiga: nao mexe');
const html = readFileSync(new URL('../painel/index.html', import.meta.url), 'utf8');
assert.ok(/id="e-pdata"/.test(html), 'a caixa existe na gaveta');
assert.ok(/pdata:\$\('#e-pdata'\)\.checked/.test(src), 'o Salvar le a caixa (trava no ponto de chamada)');
assert.ok(/perdeuData:r\.qualif_data===false/.test(src), 'o lead carrega a marca do banco');

/* ---------- a gaveta preenchida pelo robo (11/11/2026) ----------
   Lead do WhatsApp chega sem o "Roteiro" do formulario: o robo guarda o slug
   em `interesses`. A gaveta mostra o NOME dos roteiros (do catalogo do painel)
   e o WhatsApp como link. Lead antigo, gravado antes de o robo preencher o
   nome, cai no primeiro interesse. */
{
  const poGavetaRoteiros = new Function(pega('poGavetaRoteiros') + '; return poGavetaRoteiros;')();
  const poWaLink = new Function(pega('poWaLink') + '; return poWaLink;')();
  const cat = [{slug:'chile', titulo:'Chile e Deserto do Atacama'}, {slug:'peru-fascinante', titulo:'Peru Fascinante'}];
  let g = poGavetaRoteiros({roteiro:'—', interesses:['peru-fascinante','chile']}, cat);
  assert.equal(g.roteiro, 'Peru Fascinante', 'sem roteiro gravado, o primeiro interesse vira o roteiro');
  assert.deepEqual(g.interesses, ['Peru Fascinante', 'Chile e Deserto do Atacama'], 'os interesses saem pelo nome');
  g = poGavetaRoteiros({roteiro:'Chile e Deserto do Atacama', interesses:['chile']}, cat);
  assert.equal(g.roteiro, 'Chile e Deserto do Atacama', 'roteiro gravado manda');
  g = poGavetaRoteiros({roteiro:'—', interesses:['saiu-do-ar']}, cat);
  assert.deepEqual(g.interesses, ['saiu-do-ar'], 'slug fora do catalogo aparece cru, nunca some');
  g = poGavetaRoteiros({roteiro:'Grécia', interesses:null}, null);
  assert.equal(g.roteiro, 'Grécia'); assert.deepEqual(g.interesses, []);

  assert.equal(poWaLink('5548999990000'), 'https://wa.me/5548999990000');
  assert.equal(poWaLink('+55 (48) 99999-0000'), 'https://wa.me/5548999990000', 'so os digitos');
  assert.equal(poWaLink('48 99999-0000'), 'https://wa.me/5548999990000', 'sem DDI ganha o 55');
  assert.equal(poWaLink('—'), null); assert.equal(poWaLink(''), null);
  assert.equal(poWaLink('4832220000'), null, 'fixo nao vira link de WhatsApp');

  const app = readFileSync(new URL('../painel/app.js', import.meta.url), 'utf8');
  const html = readFileSync(new URL('../painel/index.html', import.meta.url), 'utf8');
  assert.ok(/poGavetaRoteiros\(l,\s*ROTEIROS\)/.test(app), 'a gaveta usa o catalogo do painel (trava no ponto de chamada)');
  assert.ok(/id="dr-wa"/.test(html) && /id="dr-int"/.test(html) && /id="dr-espera"/.test(html), 'linhas de WhatsApp, interesses e lista de espera na gaveta');
  assert.ok(/from\('po_wa_espera'\)[\s\S]{0,200}removido_at/.test(app), 'a lista de espera so mostra entradas ativas');
  for (const sel of ['status-filter', 'e-status']) {
    const bloco = html.split('id="' + sel + '"')[1].split('</select>')[0];
    assert.ok(/value="espera"/.test(bloco), 'opcao "Lista de espera" em #' + sel);
  }
  assert.ok(/espera:\{label:'Lista de espera'/.test(app), 'rotulo do status');
}
console.log('test-gaveta (ponto de chamada) OK');
