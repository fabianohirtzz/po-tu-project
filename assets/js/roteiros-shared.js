/* ============================================================
   Pereira Oliveira — dados de roteiros (compartilhado)
   Lê os roteiros do Supabase (po_roteiros) e resolve o link de
   cada um. Os 5 roteiros originais têm página própria (.html);
   os novos usam a página dinâmica roteiro.html?slug=.
   Requer @supabase/supabase-js + po-config.js carregados antes.
============================================================ */
(function () {
  'use strict';
  // roteiros originais com página estática dedicada
  window.PO_STATIC_ROTEIRO = {
    'mercados-de-natal': 'mercados-de-natal.html',
    'tesouros-asiaticos': 'tesouros-asiaticos.html',
    'floracao-das-cerejeiras': 'floracao-das-cerejeiras.html',
    'grecia-terra-mar': 'grecia-terra-mar.html',
    'encantos-do-mediterraneo': 'encantos-do-mediterraneo.html'
  };
  window.poRoteiroHref = function (slug) {
    return window.PO_STATIC_ROTEIRO[slug] || ('roteiro.html?slug=' + encodeURIComponent(slug));
  };

  var _client = null;
  window.poClient = function () {
    if (_client) return _client;
    if (window.supabase && window.PO_CONFIG) {
      try { _client = window.supabase.createClient(PO_CONFIG.SUPABASE_URL, PO_CONFIG.SUPABASE_ANON_KEY); } catch (e) {}
    }
    return _client;
  };

  // lista de roteiros ativos (ordenados). Retorna null se indisponível → caller usa fallback.
  window.poFetchRoteiros = async function () {
    var sb = window.poClient(); if (!sb) return null;
    try {
      var res = await sb.from('po_roteiros').select('*').eq('ativo', true).order('ordem').order('created_at');
      if (res.error || !res.data || !res.data.length) return null;
      return res.data;
    } catch (e) { return null; }
  };
  // um roteiro por slug (RLS: público vê só ativo; painel logado vê rascunho também)
  window.poFetchRoteiro = async function (slug) {
    var sb = window.poClient(); if (!sb) return null;
    try {
      var res = await sb.from('po_roteiros').select('*').eq('slug', slug).limit(1);
      if (res.error || !res.data || !res.data.length) return null;
      return res.data[0];
    } catch (e) { return null; }
  };

  // registro do banco → card do slider/carrossel
  window.poCard = function (r) {
    return {
      slug: r.slug, titulo: r.titulo || '',
      subtitulo: r.subtitulo || r.local_label || '',
      local: r.local_label || r.subtitulo || '',
      badge: r.badge || (r.dias ? r.dias + ' dias' : ''),
      data: r.data_label || r.periodo || '',
      desc: r.descricao_curta || '',
      img: r.capa_url || '',
      href: window.poRoteiroHref(r.slug)
    };
  };
})();
