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

   Lead sem telefone recebe uma chave propria baseada no id: sem isso,
   TODOS os leads sem telefone colapsariam numa ficha unica e errada. */
function poChavePessoa(lead) {
  let d = poDigitos(lead && lead.tel);
  if (d.length >= 10) {
    if (d.length <= 11) d = '55' + d;
    return d;
  }
  return 'sem-tel:' + String((lead && lead.id) || '');
}

function poAgrupaPessoas(leads) {
  const mapa = new Map();

  (leads || []).forEach(l => {
    const chave = poChavePessoa(l);
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
    if (l.status === 'venda') { p.vendas += 1; p.temVenda = true; }
    p.valorVendido += Number(l.ven) || 0;
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
