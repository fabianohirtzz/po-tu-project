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
============================================================ */

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
  return '<tr>' +
    '<td><input type="checkbox" class="rev-chk" value="' + esc(l.id) + '"></td>' +
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

/* A frase da confirmacao. Diz a CONTAGEM e a consequencia, porque e o unico
   momento em que a cliente ainda pode voltar atras sem custo. */
function poRevConfirmaTexto(n, cliente) {
  const quantos = n + (n === 1 ? ' contato' : ' contatos');
  return cliente
    ? 'Marcar ' + quantos + ' como cliente?\n\n' +
      'Eles passam a poder receber transmissão, que é paga por destinatário.'
    : 'Marcar ' + quantos + ' como "não é cliente"?\n\n' +
      'Ninguém é apagado: eles continuam na base, só ficam fora dos disparos.';
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
  // Confirmacao com a contagem, no mesmo padrao do importador: o "marcar
  // todos" faz um clique valer por centenas de contatos.
  if (!window.confirm(poRevConfirmaTexto(ids.length, !!cliente))) return;
  // revisado=true sempre: a revisao ACONTECEU, com qualquer resposta. O que
  // varia e se a pessoa e cliente (entra em campanha) ou nao (fica na base,
  // fora de disparo).
  //
  // O update manda EXATAMENTE dois campos. Nada de status, venda, venda_at
  // ou notas: recarimbar venda_at num salvamento de revisao apagaria a data
  // real do fechamento, que alimenta o ciclo de venda - o mesmo modo de
  // falha que ja atingiu a gaveta do lead.
  const { error } = await sb.from('po_leads')
    .update({ revisado: true, cliente: !!cliente })
    .in('id', ids);
  if (error) { toast('Erro ao salvar: ' + error.message, true); return; }
  ids.forEach(id => {
    const l = (typeof LEADS !== 'undefined' ? LEADS : []).find(x => String(x.id) === String(id));
    if (l) { l.revisado = true; l.cliente = !!cliente; }
  });
  poRenderRevisao();
  toast(ids.length + (ids.length === 1 ? ' contato revisado.' : ' contatos revisados.'));
}
