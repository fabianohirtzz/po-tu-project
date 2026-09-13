/* ============================================================
   Fila de revisao de contatos importados.

   A spec 8.1 chama "contato nao revisado nunca entra em campanha" de defesa
   OBRIGATORIA: a agenda do celular nao e lista de consentimento, tem medico,
   dentista e fornecedor no meio. Sem esta tela, todo contato de agenda entra
   revisado=false e NAO HA CAMINHO para virar true - a importacao vira um beco
   sem saida e a transmissao nao tem lista legitima de onde sair.

   Os dois botoes dizem coisas diferentes:
     revisado = true  -> "eu olhei". Vale para os DOIS botoes.
     cliente  = true  -> "e cliente", entra em campanha.
     cliente  = false -> nao e cliente: FICA NA BASE, so nao recebe disparo.
   "Nao e cliente" nunca apaga ninguem: a cliente pode querer achar aquele
   contato depois.

   A marcacao e REVERSIVEL de proposito. Nenhuma outra tela do painel escreve
   'revisado' ou 'cliente' (a gaveta escreve status/valores, o funil escreve
   status), entao se a fila so mostrasse os pendentes um clique errado com o
   "marcar todos" ligado so teria conserto por SQL no banco - e o clique errado
   e caro nos dois sentidos: marcar o dentista como cliente o torna elegivel a
   transmissao PAGA por destinatario, e marcar um cliente real como "nao e"
   o tira das campanhas em silencio. Dai a caixa "mostrar ja revisados" e a
   confirmacao com a contagem antes de gravar.

   SO QUE REVERSIVEL NAO E BARATO. A base tem hoje 775 contatos importados JA
   revisados (todos cliente=true, vindos do CRM) e ZERO pendentes: com a caixa
   "mostrar ja revisados" ligada, um "marcar todos" que alcancasse a tela
   inteira poria os 775 clientes reais a UM clique de "nao e cliente" - fora
   das campanhas de uma vez so, e a confirmacao dizendo apenas "775 contatos",
   sem dizer quais. Por isso:
     - o "marcar todos" so alcanca as linhas PENDENTES (revisado !== true),
       mesmo com os revisados na tela; quem quer remarcar um ja revisado
       marca a caixa dele a mao, que e uma decisao por contato;
     - a confirmacao diz quantos dos selecionados JA estavam revisados.
============================================================ */

/* O tamanho do lote do PATCH. O .in('id',[...]) do PostgREST vira query
   string, e cada UUID custa ~43 caracteres depois do urlencode: ~180 ids ja
   estouram o buffer de 8 KB do gateway e a resposta volta 414 com a mensagem
   crua no toast; com os 775 da base sao ~33 KB, e nunca sairia. A spec 9.5
   pede, com estas palavras, "lista em lotes, com acao em massa para marcar
   cliente". 100 ids = ~4,3 KB de ids, com folga para o resto da URL. */
var PO_REV_LOTE = 100;

function poRevFiltra(leads, incluirRevisados) {
  return (leads || []).filter(l => {
    if (!l || typeof l !== 'object') return false;
    const origem = l.origemImport == null ? '' : String(l.origemImport);
    // So contato IMPORTADO entra na fila. Lead do formulario do site nunca
    // passou pela agenda e nao tem o que revisar.
    if (origem === '') return false;
    return incluirRevisados === true || l.revisado !== true;
  });
}

function poLinhaRevisaoContato(lead) {
  const l = lead || {};
  // A situacao e o que torna a revisao reversivel: sem ela a cliente nao ve
  // o que marcou e nao tem como corrigir. Texto fixo, nao vem de dado.
  const sit = l.revisado !== true ? 'Aguardando revisão'
    : (l.cliente === true ? 'Cliente' : 'Não é cliente');
  const cls = l.revisado !== true ? 'rev-sit--pend'
    : (l.cliente === true ? 'rev-sit--cli' : 'rev-sit--nao');
  // data-pend marca a linha que ainda espera decisao. E o que permite ao
  // "marcar todos" alcancar so os pendentes sem ler o LEADS de novo.
  const pend = l.revisado !== true ? ' data-pend="1"' : '';
  return '<tr>' +
    '<td><input type="checkbox" class="rev-chk"' + pend + ' value="' + esc(l.id) + '"></td>' +
    '<td class="c-name">' + esc(l.nome) + '</td>' +
    '<td class="mono">' + esc(l.tel) + '</td>' +
    '<td class="mono">' + esc(l.origemImport) + '</td>' +
    '<td><span class="rev-sit ' + cls + '">' + sit + '</span></td>' +
    '</tr>';
}

function poRevSelecionados(container) {
  const raiz = container || document;
  return [...raiz.querySelectorAll('.rev-chk:checked')].map(c => c.value);
}

/* A caixa de selecao e pendente? Le so o data-pend da propria linha. */
function poRevChkPendente(chk) {
  if (!chk) return false;
  if (chk.dataset && typeof chk.dataset.pend !== 'undefined') return chk.dataset.pend === '1';
  return typeof chk.getAttribute === 'function' && chk.getAttribute('data-pend') === '1';
}

/* O "marcar todos" do cabecalho. MARCAR so alcanca os pendentes (ver o topo
   do arquivo: senao um clique tira os 775 clientes reais das campanhas).
   DESMARCAR limpa tudo, inclusive o ja revisado que a cliente marcou a mao -
   senao a caixa deixaria selecao presa sem caminho de volta. */
function poRevAplicaTodos(caixas, marcar) {
  (caixas || []).forEach(chk => {
    if (!chk) return;
    if (!marcar) { chk.checked = false; return; }
    if (poRevChkPendente(chk)) chk.checked = true;
  });
}

/* Quantos dos selecionados JA estavam revisados. Com a caixa "mostrar ja
   revisados" ligada a tela mistura pendente e decidido, e remarcar quem ja
   foi decidido e a acao cara: a frase tem que dizer isso antes de gravar. */
function poRevContaRevisados(leads, ids) {
  const alvo = {};
  (ids || []).forEach(id => { alvo[String(id)] = true; });
  return (leads || []).filter(l => l && typeof l === 'object' &&
    alvo[String(l.id)] === true && l.revisado === true).length;
}

/* A frase da confirmacao. Diz a CONTAGEM e a consequencia, porque e o unico
   momento em que a cliente ainda pode voltar atras sem custo. */
function poRevConfirmaTexto(n, cliente, jaRevisados) {
  const quantos = n + (n === 1 ? ' contato' : ' contatos');
  const ja = Number(jaRevisados) > 0
    ? '\n\nAtenção: ' + jaRevisados + (Number(jaRevisados) === 1
        ? ' deles já tinha sido revisado e vai ser remarcado.'
        : ' deles já tinham sido revisados e vão ser remarcados.')
    : '';
  return (cliente
    ? 'Marcar ' + quantos + ' como cliente?\n\n' +
      'Eles passam a poder receber transmissão, que é paga por destinatário.'
    : 'Marcar ' + quantos + ' como "não é cliente"?\n\n' +
      'Ninguém é apagado: eles continuam na base, só ficam fora dos disparos.') + ja;
}

/* Parte os ids em fatias que cabem na URL do PATCH (ver PO_REV_LOTE).
   Funcao pura: a casca so itera as fatias e soma o que deu certo. */
function poRevLotes(ids, tam) {
  const n = Number(tam) > 0 ? Math.floor(Number(tam)) : PO_REV_LOTE;
  const lista = Array.isArray(ids) ? ids : [];
  const out = [];
  for (let i = 0; i < lista.length; i += n) out.push(lista.slice(i, i + n));
  return out;
}

/* O aviso do fim. Com lote, "deu certo" deixou de ser binario: uma fatia
   pode falhar e as outras gravarem. Reportar sucesso total nesse caso
   esconderia contatos que continuam revisado=false no banco - sumidos da
   fila na tela e fora da defesa obrigatoria da spec 8.1. */
function poRevResultadoTexto(gravados, falhos, erros) {
  const vistos = [];
  (erros || []).forEach(e => {
    const m = String(e == null ? '' : e);
    if (m !== '' && vistos.indexOf(m) === -1) vistos.push(m);
  });
  const motivo = vistos.length ? vistos.join(' · ') : 'erro desconhecido';
  if (!falhos) return gravados + (gravados === 1 ? ' contato revisado.' : ' contatos revisados.');
  if (!gravados) return 'Nada foi salvo: ' + motivo;
  return gravados + ' de ' + (gravados + falhos) + ' salvos. ' + falhos +
    ' ficaram de fora: ' + motivo;
}

/* ---------- render e acao em massa (casca de DOM) ---------- */

function poRenderRevisao() {
  const todosLeads = typeof LEADS !== 'undefined' ? LEADS : [];
  const verRevisados = !!(document.querySelector('#rev-revisados') || {}).checked;
  // O contador da navegacao conta SEMPRE so os pendentes: ele e a pendencia,
  // nao o tamanho da lista que esta na tela.
  const pend = poRevFiltra(todosLeads);
  const lista = verRevisados ? poRevFiltra(todosLeads, true) : pend;
  const tb = document.querySelector('#rev-rows');
  if (tb) {
    tb.innerHTML = lista.length
      ? lista.map(poLinhaRevisaoContato).join('')
      : '<tr><td colspan="5"><div class="empty">' +
        (verRevisados ? 'Nenhum contato importado ainda.' : 'Nenhum contato aguardando revisão.') +
        '</div></td></tr>';
  }
  // A caixa "marcar todos" precisa voltar ao normal: senao ela fica marcada
  // sobre uma lista que acabou de trocar, dizendo que tudo esta selecionado
  // quando nao esta.
  const todos = document.querySelector('#rev-todos');
  if (todos) todos.checked = false;
  // O contador vive na navegacao: e ele que avisa que existe fila, mesmo
  // com a aba fechada.
  const n = document.querySelector('#rev-contagem');
  if (n) { n.textContent = pend.length; n.hidden = !pend.length; }
}

async function poRevMarcar(cliente) {
  const ids = poRevSelecionados(document.querySelector('#rev-rows'));
  if (!ids.length) { toast('Selecione ao menos um contato.', true); return; }
  const todosLeads = typeof LEADS !== 'undefined' ? LEADS : [];
  // Confirmacao com a contagem, no mesmo padrao do importador: o "marcar
  // todos" faz um clique valer por centenas de contatos. E ela diz quantos
  // dos selecionados JA estavam revisados, que e a parte cara.
  if (!window.confirm(poRevConfirmaTexto(ids.length, !!cliente,
      poRevContaRevisados(todosLeads, ids)))) return;
  // revisado=true sempre: a revisao ACONTECEU, com qualquer resposta. O que
  // varia e se a pessoa e cliente (entra em campanha) ou nao (fica na base,
  // fora de disparo).
  //
  // O update manda EXATAMENTE dois campos. Nada de status, venda, venda_at
  // ou notas: recarimbar venda_at num salvamento de revisao apagaria a data
  // real do fechamento, que alimenta o ciclo de venda - o mesmo modo de
  // falha que ja atingiu a gaveta do lead.
  //
  // Em SERIE, fatia por fatia: paralelo aqui seria 8 PATCH simultaneos na
  // mesma tabela, e um erro no meio deixaria a memoria adiantada em relacao
  // ao banco sem ordem definida. As fatias que falham sao somadas e
  // relatadas; as que passam contam como gravadas.
  const gravados = [];
  const erros = [];
  const lotes = poRevLotes(ids, PO_REV_LOTE);
  for (let i = 0; i < lotes.length; i++) {
    const { error } = await sb.from('po_leads')
      .update({ revisado: true, cliente: !!cliente })
      .in('id', lotes[i]);
    if (error) { erros.push(error.message || String(error)); continue; }
    lotes[i].forEach(id => gravados.push(id));
  }
  // So o que o banco confirmou anda na memoria: a fatia que falhou continua
  // revisado=false la e tem que continuar aparecendo na fila aqui.
  gravados.forEach(id => {
    const l = todosLeads.find(x => String(x.id) === String(id));
    if (l) { l.revisado = true; l.cliente = !!cliente; }
  });
  if (gravados.length) poRenderRevisao();
  toast(poRevResultadoTexto(gravados.length, ids.length - gravados.length, erros),
        erros.length > 0);
}
