/* ============================================================
   Aba Clientes: a pessoa, e nao o interesse avulso.

   po_leads tem uma linha por contato. A mesma cliente pedindo Grecia em
   2026 e Turquia em 2027 vira duas linhas sem ligacao nenhuma, e a ficha
   dela nao existe em lugar nenhum. Para quem vende viagem em grupo isso
   importa: quem ja viajou e o melhor lead do proximo roteiro.

   A ficha e AGREGACAO, nao tabela nova (ver spec, secao 7.1): agrupa por
   telefone e junta o que ja existe.
============================================================ */

function poDigitos(s) {
  return String(s == null ? '' : s).replace(/\D+/g, '');
}

/* A chave de agrupamento. O WhatsApp grava E.164 (+5548...) e o formulario
   do site ainda grava o que o visitante digitou; poE164 (painel/fone.js,
   espelho do wa_e164() do PHP) normaliza os dois formatos para a mesma
   pessoa, inclusive o celular antigo de 8 digitos (que so o ramo antigo,
   sem a regra do nono digito, tratava como ficha separada).

   Lead sem telefone recebe uma chave propria: pelo id quando ha um, ou
   pela posicao na lista (idx) quando nao ha nem telefone nem id. So usar
   o id nao bastava: dois leads sem telefone e sem id caiam os dois em
   'sem-tel:' e colapsavam na mesma pessoa, misturando gente diferente
   numa ficha so. */
function poChavePessoa(lead, idx) {
  // Normalizador unico (fone.js): mesma regra do wa_e164 do PHP. Um celular
  // antigo de 8 digitos agora cai na mesma pessoa, em vez de virar ficha nova.
  const e = (typeof poE164 === 'function') ? poE164(lead && lead.tel) : null;
  if (e) return poDigitos(e);
  const id = lead && lead.id;
  if (id != null && id !== '') return 'sem-tel:' + String(id);
  return 'sem-tel:pos:' + String(idx);
}

function poAgrupaPessoas(leads) {
  const mapa = new Map();

  (leads || []).forEach((l, idx) => {
    // Registro que nao e objeto (null, undefined, string ou numero soltos)
    // nao pode derrubar o painel inteiro por causa de um dado estranho no
    // meio dos leads reais: ignora e segue agrupando o resto.
    if (!l || typeof l !== 'object') return;

    const chave = poChavePessoa(l, idx);
    if (!mapa.has(chave)) {
      mapa.set(chave, {
        chave, nome: '', tel: '', email: '', cidade: '',
        leads: [], total: 0, vendas: 0, valorVendido: 0,
        ultimaData: null, temVenda: false,
      });
    }
    const p = mapa.get(chave);
    p.leads.push(l);

    // O campo mais completo vence. O WhatsApp manda o nome do perfil, que
    // costuma ser so o primeiro nome; o formulario manda o nome inteiro.
    // O painel escreve "—" onde nao ha valor, entao ele conta como vazio.
    const melhor = (atual, novo) => {
      const n = (novo == null ? '' : String(novo)).trim();
      if (!n || n === '—' || n === '(sem nome)') return atual;
      return n.length > String(atual || '').length ? n : atual;
    };
    p.nome   = melhor(p.nome,   l.nome);
    p.tel    = melhor(p.tel,    l.tel);
    p.email  = melhor(p.email,  l.email);
    p.cidade = melhor(p.cidade, l.cidade);

    p.total += 1;
    // O valor so entra no faturamento se o lead esta EM status venda, a
    // mesma regra dos KPIs e dos relatorios. O quadro do funil tira o card
    // de "Contrato assinado" mantendo o valor no lead de proposito; sem o
    // filtro, a pessoa aparecia com "0 viagens" e "R$ 22.900 faturado" na
    // mesma linha, e o KPI "Faturado" da aba ficava inflado para sempre.
    if (l.status === 'venda') {
      p.vendas += 1;
      p.temVenda = true;
      p.valorVendido += Number(l.ven) || 0;
    }
    if (l.data && (!p.ultimaData || l.data > p.ultimaData)) p.ultimaData = l.data;
  });

  const pessoas = [...mapa.values()];
  pessoas.forEach(p => {
    p.leads.sort((a, b) => String(b.data || '').localeCompare(String(a.data || '')));
    if (!p.nome) p.nome = '(sem nome)';
    if (!p.tel)  p.tel = '—';
  });
  pessoas.sort((a, b) => String(b.ultimaData || '').localeCompare(String(a.ultimaData || '')));
  return pessoas;
}

/* ---------- busca, selecao e exclusao (reuniao de 11/11/2026) ----------
   A agenda importada traz empresas (CVC, LATAM, Receita Federal) que a Lais
   precisa achar e tirar de uma vez. A busca e PROPRIA desta aba: a do topo
   pertence a aba Leads, que agora e do Carlos. */
function poSemAcento(s) {
  return String(s == null ? '' : s).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

function poFiltraPessoas(pessoas, q) {
  const termo = poSemAcento(q).trim();
  if (!termo) return pessoas || [];
  const dig = termo.replace(/\D+/g, '');
  return (pessoas || []).filter(p => {
    if (poSemAcento((p.nome || '') + ' ' + (p.cidade || '') + ' ' + (p.email || '')).includes(termo)) return true;
    // telefone: so quando o que se digitou e numero ("9604-8882" ou "96048882")
    return dig.length >= 4 && dig.length === termo.replace(/[\s\-().+]/g, '').length &&
      String(p.tel || '').replace(/\D+/g, '').includes(dig);
  });
}

/* Excluir a PESSOA e excluir todos os interesses dela: senao a ficha
   reaparece na proxima carga, montada com o que sobrou. */
function poIdsDasPessoas(pessoas, chaves) {
  const ids = [];
  (pessoas || []).forEach(p => {
    if (chaves.has(p.chave)) (p.leads || []).forEach(l => { if (l && l.id != null) ids.push(l.id); });
  });
  return ids;
}

function poTextoExclusao(pessoas) {
  const n = pessoas.length;
  const nomes = pessoas.slice(0, 5).map(p => '- ' + p.nome).join('\n') + (n > 5 ? '\n- e mais ' + (n - 5) : '');
  const viajaram = pessoas.filter(p => p.temVenda).length;
  return 'Excluir ' + n + (n === 1 ? ' contato' : ' contatos') + ' da base?\n\n' + nomes + '\n\n' +
    (viajaram ? 'Atenção: ' + viajaram + (viajaram === 1 ? ' deles já viajou' : ' deles já viajaram') +
      ' com a agência (tem venda registrada).\n\n' : '') +
    'Isso apaga a ficha e todo o histórico, e não pode ser desfeito.';
}

const PO_CLI_SEL = new Set();

async function poExcluiPessoas(pessoas) {
  if (!pessoas.length) return;
  if (!confirm(poTextoExclusao(pessoas))) return;
  const ids = poIdsDasPessoas(pessoas, new Set(pessoas.map(p => p.chave)));
  const lotes = poRevLotes(ids, 100);
  const apagados = new Set();
  for (let i = 0; i < lotes.length; i++) {
    const { error } = await sb.from('po_leads').delete().in('id', lotes[i]);
    if (error) {
      alert('Parte da exclusão falhou (' + apagados.size + ' de ' + ids.length +
        ' registros apagados). Recarregue a página e confira antes de tentar de novo.');
      break;
    }
    lotes[i].forEach(id => apagados.add(String(id)));
  }
  // tira da memoria so o que o banco confirmou
  for (let i = LEADS.length - 1; i >= 0; i--) if (apagados.has(String(LEADS[i].id))) LEADS.splice(i, 1);
  pessoas.forEach(p => PO_CLI_SEL.delete(p.chave));
  poRenderClientes();
}

/* ---------- lista de pessoas ---------- */

/* Separada do render para poder ser testada: o nome vem do perfil do
   WhatsApp, que e dado de terceiro, e precisa sair escapado. */
function poLinhaPessoa(p) {
  const etiqueta = p.temVenda
    ? '<span class="cli-tag cli-tag--cliente">Cliente</span>'
    : '<span class="cli-tag">Lead</span>';
  const viagens = p.vendas === 1 ? '1 viagem' : p.vendas + ' viagens';
  const marcado = typeof PO_CLI_SEL !== 'undefined' && PO_CLI_SEL.has(p.chave);
  return '<tr data-chave="' + esc(p.chave) + '">' +
    '<td class="cli-ck"><input type="checkbox" aria-label="Selecionar" data-ck="' + esc(p.chave) + '"' +
      (marcado ? ' checked' : '') + '></td>' +
    '<td><div class="c-name">' + esc(p.nome) + '</div>' +
         '<div class="c-sub">' + esc(p.cidade || '—') + '</div></td>' +
    '<td class="mono">' + esc(p.tel) + '</td>' +
    '<td>' + etiqueta + '</td>' +
    '<td class="mono">' + p.total + '</td>' +
    '<td class="mono">' + (p.vendas ? viagens : '—') + '</td>' +
    '<td class="money ' + (p.valorVendido ? '' : 'zero') + '">' + brl(p.valorVendido) + '</td>' +
    '<td class="mono">' + fmtData(p.ultimaData) + '</td>' +
    '</tr>';
}

function poPessoas() {
  // filtradasPessoas, nao filtered: a base de clientes ignora o filtro de
  // mes, senao a aba abre mostrando so quem deu sinal no mes corrente.
  return poAgrupaPessoas(typeof filtradasPessoas === 'function' ? filtradasPessoas() : LEADS);
}

function poRenderClientes() {
  const qEl = document.querySelector('#cli-q');
  const pessoas = poFiltraPessoas(poPessoas(), qEl ? qEl.value : '');
  const tb = document.querySelector('#cli-rows');
  if (!tb) return;
  tb.innerHTML = pessoas.length
    ? pessoas.map(poLinhaPessoa).join('')
    : '<tr><td colspan="8"><div class="empty">Nenhum cliente com esses filtros.</div></td></tr>';
  tb.querySelectorAll('tr[data-chave]').forEach(tr => {
    tr.onclick = () => poAbreFicha(tr.dataset.chave);
  });
  tb.querySelectorAll('input[data-ck]').forEach(ck => {
    // a caixa nao abre a ficha: o clique e na selecao, nao na linha
    ck.onclick = e => e.stopPropagation();
    ck.onchange = () => {
      if (ck.checked) PO_CLI_SEL.add(ck.dataset.ck); else PO_CLI_SEL.delete(ck.dataset.ck);
      poCliBarra(pessoas);
    };
  });
  poCliLigaBarra();
  poCliBarra(pessoas);

  const clientes = pessoas.filter(p => p.temVenda).length;
  const faturado = pessoas.reduce((s, p) => s + p.valorVendido, 0);
  document.querySelector('#cli-kpis').innerHTML =
    '<div class="kpi"><div class="kpi-l">Pessoas</div><div class="kpi-n">' + pessoas.length + '</div>' +
      '<div class="kpi-sub">' + clientes + ' ja compraram</div></div>' +
    '<div class="kpi k-green"><div class="kpi-l">Ja viajaram</div><div class="kpi-n">' + clientes + '</div>' +
      '<div class="kpi-sub">a melhor lista para o proximo roteiro</div></div>' +
    '<div class="kpi k-green"><div class="kpi-l">Faturado</div><div class="kpi-n" style="font-size:1.7rem">' +
      brl2(faturado) + '</div><div class="kpi-sub">soma de todas as vendas</div></div>';
}

/* O "marcar todos" e o botao de excluir olham so o que esta NA TELA (o
   resultado da busca): buscar "turismo", marcar todos e excluir nao pode
   levar junto quem a busca escondeu. */
function poCliBarra(visiveis) {
  const todos = document.querySelector('#cli-todos');
  const btn = document.querySelector('#cli-excluir');
  // a selecao que vale e a que esta na tela
  const naTela = visiveis.filter(p => PO_CLI_SEL.has(p.chave));
  if (todos) {
    todos.checked = visiveis.length > 0 && naTela.length === visiveis.length;
    todos.indeterminate = naTela.length > 0 && naTela.length < visiveis.length;
    todos.onchange = () => {
      visiveis.forEach(p => { if (todos.checked) PO_CLI_SEL.add(p.chave); else PO_CLI_SEL.delete(p.chave); });
      poRenderClientes();
    };
  }
  if (btn) {
    btn.disabled = naTela.length === 0;
    btn.textContent = naTela.length ? 'Excluir selecionados (' + naTela.length + ')' : 'Excluir selecionados';
    btn.onclick = () => poExcluiPessoas(naTela);
  }
}

function poCliLigaBarra() {
  const q = document.querySelector('#cli-q');
  if (q && !q.dataset.ligado) {
    q.dataset.ligado = '1';
    q.oninput = () => poRenderClientes();
  }
}

/* ---------- ficha ---------- */
function poAbreFicha(chave) {
  const p = poPessoas().find(x => x.chave === chave);
  if (!p) return;

  const ex = document.querySelector('#fi-excluir');
  if (ex) ex.onclick = async () => {
    const antes = LEADS.length;
    await poExcluiPessoas([p]);
    if (LEADS.length < antes) poFechaFicha();
  };

  document.querySelector('#fi-nome').textContent = p.nome;
  document.querySelector('#fi-tel').textContent  = p.tel;
  document.querySelector('#fi-mail').textContent = p.email || '—';
  document.querySelector('#fi-cid').textContent  = p.cidade || '—';
  document.querySelector('#fi-resumo').innerHTML =
    '<b>' + p.total + '</b> ' + (p.total === 1 ? 'interesse' : 'interesses') +
    ' · <b>' + p.vendas + '</b> ' + (p.vendas === 1 ? 'viagem feita' : 'viagens feitas') +
    (p.valorVendido ? ' · <b>' + brl2(p.valorVendido) + '</b>' : '');

  // Cada interesse abre a gaveta do lead correspondente, que ja existe e
  // ja sabe editar status, valores, notas e (na Task 6) a conversa.
  document.querySelector('#fi-leads').innerHTML = p.leads.map(l => {
    const st = STATUS[l.status] || STATUS.semresposta;
    return '<button class="fi-lead" data-id="' + esc(l.id) + '">' +
      '<span class="fi-lead-d mono">' + esc(fmtData(l.data)) + '</span>' +
      '<span class="fi-lead-r">' + esc(l.roteiro) + '</span>' +
      '<span class="status-sel ' + st.cls + '">' + st.label + '</span>' +
      '<span class="money ' + (l.ven ? '' : 'zero') + '">' + brl(l.ven || l.orc) + '</span>' +
      '</button>';
  }).join('');
  document.querySelectorAll('#fi-leads .fi-lead').forEach(b => {
    b.onclick = () => { poFechaFicha(); openDrawer(b.dataset.id); };
  });

  document.querySelector('#scrim').classList.add('on');
  document.querySelector('#ficha').classList.add('on');
}

function poFechaFicha() {
  document.querySelector('#ficha').classList.remove('on');
  const gaveta = document.querySelector('#drawer');
  if (!gaveta || !gaveta.classList.contains('on')) {
    document.querySelector('#scrim').classList.remove('on');
  }
}
