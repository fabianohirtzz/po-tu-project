/* ============================================================
   Painel: liga o clique no WhatsApp do site ao lead do WhatsApp (fase 3A).

   O site registra cada clique com campanha em po_origem_cliques, com um
   codigo que vai no texto da mensagem: "(ref G-KTRBX)". Aqui, ao abrir o
   painel, cada clique ainda livre procura o seu lead:

   1. POR CODIGO (certeza): o codigo apareceu numa mensagem recebida. O lead
      e o dono daquele numero de WhatsApp.
   2. POR HORARIO (provavel): a pessoa apagou o codigo. Liga SO quando ha
      exatamente UM clique livre na janela antes do primeiro contato de UM
      lead novo do robo, e esse clique nao serve a nenhum outro lead. Na
      duvida nao liga: provavel nao pode virar chute.

   O lead so recebe a origem do clique se ainda nao tem origem nenhuma
   (UTM, id de clique ou anuncio da Meta). O clique e marcado ANTES de o
   lead ser escrito, e so por quem o achou livre (.is('lead_id', null)):
   duas abas do painel abertas nunca ligam o mesmo clique duas vezes.
   Nada aqui toca no robo: e leitura das mensagens que ele ja grava.
============================================================ */

const PO_CLIQUE_JANELA_MIN = 15;
const PO_CLIQUE_ORIGEM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
  'gclid', 'gbraid', 'wbraid', 'fbclid', 'referrer', 'landing_page'];

/* Os codigos de um texto. Tolera o que a pessoa pode ter mexido: sem os
   parenteses, minusculas, sem o hifen. O formato segue fechado (G/M/S e
   cinco consoantes), senao qualquer "ref" de conversa viraria codigo. */
function poCodigosDoTexto(texto) {
  const re = /\bref\s*:?\s*([gms])\s*-?\s*([bcdfghjklmnpqrstvwxz]{5})\b/gi;
  const out = [];
  let m;
  while ((m = re.exec(String(texto || '')))) out.push((m[1] + '-' + m[2]).toUpperCase());
  return out;
}

// Lead que ja tem origem (do formulario, do anuncio da Meta) nao e sobrescrito.
function poLeadSemOrigem(raw) {
  return !['utm_source', 'utm_medium', 'gclid', 'gbraid', 'wbraid', 'fbclid', 'ad_id', 'ctwa_clid']
    .some((k) => raw && raw[k]);
}

/* Por codigo. As mensagens chegam em ordem de horario: vale a PRIMEIRA que
   trouxe o codigo, e cada clique casa com um lead so. */
function poCasaPorCodigo(cliques, mensagens, leads) {
  const porCodigo = new Map((cliques || []).map((c) => [c.codigo, c]));
  const porWa = new Map((leads || []).filter((l) => l.waId).map((l) => [l.waId, l]));
  const usados = new Set(), pares = [];
  (mensagens || []).forEach((m) => {
    poCodigosDoTexto(m.texto).forEach((cod) => {
      const c = porCodigo.get(cod), l = porWa.get(m.wa_id);
      if (!c || !l || usados.has(c.id)) return;
      usados.add(c.id);
      pares.push({ clique: c, lead: l, por: 'codigo', aplica: poLeadSemOrigem(l.raw) });
    });
  });
  return pares;
}

/* Por horario. O lead do robo nasce na primeira mensagem, entao a data dele
   e o primeiro contato. Janela: o clique ate 15 min ANTES (1 min depois, de
   folga para relogio). */
function poCasaPorHorario(cliques, leads, janelaMin) {
  const j = (janelaMin || PO_CLIQUE_JANELA_MIN) * 60000;
  const cands = (leads || []).filter((l) => l.waId && l.raw && l.raw.origem === 'whatsapp'
    && !l.origemCasada && poLeadSemOrigem(l.raw));
  const naJanela = (c, l) => {
    const tc = Date.parse(c.ultimo_clique_at), tl = Date.parse(l.data);
    return tc <= tl + 60000 && tl - tc <= j;
  };
  const pares = [];
  cands.forEach((l) => {
    const cs = (cliques || []).filter((c) => naJanela(c, l));
    if (cs.length !== 1) return;
    if (cands.filter((o) => naJanela(cs[0], o)).length !== 1) return;
    pares.push({ clique: cs[0], lead: l, por: 'horario', aplica: true });
  });
  return pares;
}

// O que o lead recebe do clique: a origem inteira e como ela foi ligada.
function poPatchDoClique(c, por) {
  const p = { origem_casada: por };
  PO_CLIQUE_ORIGEM.forEach((k) => { p[k] = (c && c[k]) || null; });
  return p;
}

/* Roda ao carregar o painel (app.js chama depois do loadData). Qualquer
   falha fica no console: o painel segue de pe e tenta de novo na proxima
   carga. */
async function poCasaCliques() {
  if (typeof sb === 'undefined' || typeof LEADS === 'undefined') return;
  try {
    const desde = new Date(Date.now() - 90 * 864e5).toISOString();
    const { data: cliques, error } = await sb.from('po_origem_cliques').select('*')
      .is('lead_id', null).gte('ultimo_clique_at', desde).limit(1000);
    if (error || !cliques || !cliques.length) return;
    const inicio = cliques.reduce((m, c) => (c.created_at < m ? c.created_at : m), cliques[0].created_at);
    const { data: msgs } = await sb.from('po_wa_mensagens').select('wa_id,texto,ts')
      .eq('direcao', 'in').gte('ts', inicio).ilike('texto', '%ref%')
      .order('ts', { ascending: true }).limit(1000);

    const porCodigo = poCasaPorCodigo(cliques, msgs || [], LEADS);
    const ligados = new Set(porCodigo.map((p) => p.clique.id));
    const ocupados = new Set(porCodigo.map((p) => p.lead.id));
    const porHorario = poCasaPorHorario(cliques.filter((c) => !ligados.has(c.id)),
      LEADS.filter((l) => !ocupados.has(l.id)));

    let mudou = 0;
    for (const p of porCodigo.concat(porHorario)) {
      // posse do clique primeiro: so quem o achou livre segue
      const { data: ganhou, error: e1 } = await sb.from('po_origem_cliques')
        .update({ lead_id: p.lead.id, casado_por: p.por, casado_at: new Date().toISOString() })
        .eq('id', p.clique.id).is('lead_id', null).select('id');
      if (e1 || !ganhou || !ganhou.length || !p.aplica) continue;
      const patch = poPatchDoClique(p.clique, p.por);
      const { error: e2 } = await sb.from('po_leads').update(patch).eq('id', p.lead.id);
      if (e2) { console.warn('origem do clique nao gravada no lead:', e2.message); continue; }
      Object.assign(p.lead.raw, patch);
      Object.assign(p.lead, mapRow(p.lead.raw));
      mudou++;
    }
    if (mudou) {
      renderLeads();
      if ($('#view-reports') && $('#view-reports').classList.contains('on')) renderReports();
    }
  } catch (e) { console.warn('casamento de cliques falhou (tenta de novo na proxima carga):', e); }
}

/* Roda quando os leads chegam (evento do app.js) e, se eles ja chegaram
   antes deste arquivo carregar, roda agora. As duas ordens acontecem. */
document.addEventListener('po:leads', () => { poCasaCliques(); });
if (window.PO_LEADS_PRONTOS) poCasaCliques();
