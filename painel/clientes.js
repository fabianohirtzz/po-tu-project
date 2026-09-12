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
   do site ainda grava o que o visitante digitou, entao a chave normaliza
   os dois para digitos com DDI.

   Lead sem telefone recebe uma chave propria: pelo id quando ha um, ou
   pela posicao na lista (idx) quando nao ha nem telefone nem id. So usar
   o id nao bastava: dois leads sem telefone e sem id caiam os dois em
   'sem-tel:' e colapsavam na mesma pessoa, misturando gente diferente
   numa ficha so. */
function poChavePessoa(lead, idx) {
  let d = poDigitos(lead && lead.tel);
  if (d.length >= 10) {
    if (d.length <= 11) d = '55' + d;
    return d;
  }
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

/* ---------- lista de pessoas ---------- */

/* Separada do render para poder ser testada: o nome vem do perfil do
   WhatsApp, que e dado de terceiro, e precisa sair escapado. */
function poLinhaPessoa(p) {
  const etiqueta = p.temVenda
    ? '<span class="cli-tag cli-tag--cliente">Cliente</span>'
    : '<span class="cli-tag">Lead</span>';
  const viagens = p.vendas === 1 ? '1 viagem' : p.vendas + ' viagens';
  return '<tr data-chave="' + esc(p.chave) + '">' +
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
  const pessoas = poPessoas();
  const tb = document.querySelector('#cli-rows');
  if (!tb) return;
  tb.innerHTML = pessoas.length
    ? pessoas.map(poLinhaPessoa).join('')
    : '<tr><td colspan="7"><div class="empty">Nenhum cliente com esses filtros.</div></td></tr>';
  tb.querySelectorAll('tr[data-chave]').forEach(tr => {
    tr.onclick = () => poAbreFicha(tr.dataset.chave);
  });

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

/* ---------- ficha ---------- */
function poAbreFicha(chave) {
  const p = poPessoas().find(x => x.chave === chave);
  if (!p) return;

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
