/* ============================================================
   Painel: Meta Ads (fase 3B do rastreio).

   1. Nome do anuncio na ficha. O lead do anuncio que abre o WhatsApp chega
      so com o ad_id. Os nomes ficam em po_anuncios_meta: buscados na API de
      Marketing pelo anuncios-meta.php (com META_ADS_TOKEN) ou dados a mao
      aqui, no botao "Dar nome" da gaveta. O nome dado a mao vence.
   2. Venda de volta para a Meta. Ao abrir o painel, o meta-conversao.php
      envia as vendas com ctwa_clid dos ultimos 7 dias, uma vez cada. A linha
      de status fica nos Relatorios.
   Tudo em segundo plano e com guarda: sem as tabelas, sem os endpoints ou
   sem a configuracao, a ficha mostra "Anuncio <id>" como antes.
============================================================ */

let PO_ANUNCIOS = {};
let PO_META_STATUS = null;

/* "Campanha · Conjunto · Anuncio"; o nome dado a mao vence o da Meta. */
function poNomeAnuncio(a) {
  if (!a) return '';
  const manual = String(a.nome_manual || '').trim();
  if (manual) return manual;
  return [a.campanha, a.conjunto, a.anuncio].filter((x) => x && String(x).trim()).join(' · ');
}
// O texto da linha "Campanha" da gaveta.
function poCampanhaDoLead(l, anuncios) {
  if (l && l.adId) {
    const n = poNomeAnuncio((anuncios || PO_ANUNCIOS)[l.adId]);
    if (n) return n;
  }
  return l ? l.camp : '—';
}
// Os ad_id que ainda precisam de nome: sem nome nenhum e sem busca nas ultimas 24h.
function poAnunciosSemNome(ids, anuncios, agora) {
  return (ids || []).filter((id) => {
    const a = (anuncios || {})[id];
    if (!a) return true;
    if (poNomeAnuncio(a)) return false;
    return !a.buscado_at || agora - Date.parse(a.buscado_at) > 864e5;
  });
}
/* O que os Relatorios dizem sobre o envio. Sem resposta (endpoint ainda nao
   publicado, rede fora): nada, para nao assustar quem le. */
function poMetaStatusTexto(r) {
  if (!r) return '';
  if (r.configurado === false) {
    return 'Vendas de anúncio do WhatsApp ainda não voltam para a Meta: falta configurar ' + (r.falta || []).join(', ') + '.';
  }
  if (!r.ok) return 'Não consegui enviar as vendas para a Meta agora. Tenta de novo na próxima abertura do painel.';
  const pl = (n, s, p) => n + ' ' + (n === 1 ? s : p);
  const partes = [];
  if (r.enviados) partes.push(pl(r.enviados, 'venda enviada agora', 'vendas enviadas agora'));
  if (r.ja_enviados) partes.push(pl(r.ja_enviados, 'já enviada', 'já enviadas'));
  if (r.falhas) partes.push(pl(r.falhas, 'falhou (tenta de novo)', 'falharam (tenta de novo)'));
  return 'Vendas para a Meta (últimos 7 dias): ' + (partes.join(', ') || 'nenhuma venda de anúncio do WhatsApp') + '.';
}

async function poMetaPost(url, campos) {
  const { data: { session } } = await sb.auth.getSession();
  if (!session) return null;
  const fd = new FormData();
  fd.append('sb_token', session.access_token);
  Object.entries(campos || {}).forEach(([k, v]) => fd.append(k, v));
  const r = await fetch(url, { method: 'POST', body: fd });
  return await r.json().catch(() => null);
}

async function poMetaNomes() {
  const ids = [...new Set(LEADS.map((l) => l.adId).filter(Boolean))];
  if (!ids.length) return;
  const { data, error } = await sb.from('po_anuncios_meta').select('*').in('ad_id', ids.slice(0, 100));
  if (error) return;   // sem a tabela: a ficha segue com "Anuncio <id>"
  (data || []).forEach((a) => { PO_ANUNCIOS[a.ad_id] = a; });
  const faltam = poAnunciosSemNome(ids, PO_ANUNCIOS, Date.now());
  if (!faltam.length) return;
  const r = await poMetaPost('../anuncios-meta.php', { ids: faltam.slice(0, 25).join(',') });
  if (r && r.nomes) Object.entries(r.nomes).forEach(([id, n]) => { PO_ANUNCIOS[id] = Object.assign({}, PO_ANUNCIOS[id], n); });
}

function poMetaStatusRender() {
  const el = $('#meta-capi-info');
  if (el) el.textContent = poMetaStatusTexto(PO_META_STATUS);
}

async function poMetaCarrega() {
  if (typeof sb === 'undefined' || typeof LEADS === 'undefined') return;
  try { await poMetaNomes(); } catch (e) { console.warn('nomes dos anuncios:', e); }
  try { PO_META_STATUS = await poMetaPost('../meta-conversao.php', { modo: 'enviar' }); }
  catch (e) { PO_META_STATUS = null; }
  poMetaStatusRender();
}

/* "Dar nome" na gaveta: grava em po_anuncios_meta.nome_manual, que vale
   para todos os leads daquele anuncio. Vazio volta para o nome da Meta. */
async function poMetaNomear(adId) {
  const nome = prompt('Nome deste anúncio (aparece na ficha de todos os leads que vieram dele):',
    poNomeAnuncio(PO_ANUNCIOS[adId]) || '');
  if (nome == null) return false;
  const limpo = nome.trim() || null;
  const { error } = await sb.from('po_anuncios_meta')
    .upsert({ ad_id: adId, nome_manual: limpo, updated_at: new Date().toISOString() }, { onConflict: 'ad_id' });
  if (error) { toast('Erro ao salvar o nome: ' + error.message, true); return false; }
  PO_ANUNCIOS[adId] = Object.assign({}, PO_ANUNCIOS[adId], { ad_id: adId, nome_manual: limpo });
  toast('Nome do anúncio salvo.');
  return true;
}

/* Roda quando os leads chegam (evento do app.js) e, se eles ja chegaram
   antes deste arquivo carregar, roda agora. As duas ordens acontecem. */
document.addEventListener('po:leads', () => { poMetaCarrega(); });
if (window.PO_LEADS_PRONTOS) poMetaCarrega();
