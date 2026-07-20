/* ============================================================
   Pereira Oliveira: dados de roteiros (compartilhado)
   Lê os roteiros do Supabase (po_roteiros) e resolve o link de
   cada um. Toda página de roteiro é renderizada no servidor
   (roteiro.php), servida em /roteiros/<slug>.
   Requer @supabase/supabase-js + po-config.js carregados antes.
============================================================ */
(function () {
  'use strict';
  // Todo roteiro vive no banco e e servido por /roteiros/<slug> (roteiro.php).
  // O mapa PO_STATIC_ROTEIRO morreu com as 5 paginas estaticas: ele mandava um
  // roteiro do banco para a pagina estatica de mesmo slug, que e a origem do
  // slug 'tesouros-asiaticos2'.
  window.poRoteiroHref = function (slug) {
    return '/roteiros/' + encodeURIComponent(slug);
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

  var esc = function (s) {
    return (s == null ? '' : String(s)).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  };

  /* A figura da seção "por que viajar".
     REGRA ÚNICA (vale p/ página estática e dinâmica): tem reels do Instagram
     → vídeo; não tem → a capa com o selo de dias, como sempre foi.
     O vídeo nunca toca sozinho e não baixa nada até o clique (preload="none";
     o poster é a capa, que a página já carrega). */
  window.poIntroMedia = function (r) {
    var capa = esc(r.capa_url || '');
    // --reels-bg é lida DENTRO do roteiro.css, então uma URL relativa seria
    // resolvida contra a pasta do CSS (assets/css/) e daria 404. Absolutiza.
    var capaAbs = '';
    try { capaAbs = r.capa_url ? esc(new URL(r.capa_url, document.baseURI).href) : ''; } catch (e) { capaAbs = capa; }
    if (!r.video_insta_url) {
      return '<figure class="intro__photo reveal" data-delay="1"><img src="' + capa + '" alt="' + esc(r.titulo) + '">' +
        (r.dias ? '<figcaption class="intro__stamp"><b>' + esc(r.dias) + '</b><span>dias de viagem</span></figcaption>' : '') +
        '</figure>';
    }
    // --reels-bg alimenta o fundo borrado da tela cheia (reels 9:16 em tela 16:9).
    return '<figure class="intro__media reveal" data-delay="1" style="--reels-bg:url(\'' + capaAbs + '\')">' +
      // nofullscreen tira o botão de tela cheia NATIVO: ele abriria o vídeo direto
      // (tarjas pretas no desktop), competindo com a tela cheia própria do .intro__fs.
      '<video src="' + esc(r.video_insta_url) + '" poster="' + capa + '" preload="none" playsinline ' +
      'controlslist="nofullscreen nodownload" disablepictureinpicture disableremoteplayback ' +
      'aria-label="Vídeo do roteiro ' + esc(r.titulo) + '"></video>' +
      '<button class="intro__play" type="button" aria-label="Assistir ao vídeo do roteiro">' +
      '<span><svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg></span></button>' +
      '<button class="intro__fs" type="button" aria-label="Ver em tela cheia">' +
      '<svg viewBox="0 0 24 24"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/></svg></button>' +
      '<figcaption class="intro__stamp intro__stamp--video"><span>Assista ao vídeo do roteiro</span></figcaption>' +
      '</figure>';
  };

  /* O vídeo do topo (hero).
     REGRA ÚNICA (vale p/ página estática e dinâmica): tem vídeo capa → <video>
     por cima da capa; não tem → só a capa parada, como sempre foi.

     É textura de fundo, não conteúdo: toca sozinho, mudo, em loop, sem controle
     nenhum, e fica fora do foco e do leitor de tela. Os quatro atributos
     autoplay+muted+playsinline+loop juntos são o que destrava o autoplay no
     iOS/Android — era exatamente isso que o embed do YouTube nunca conseguiu,
     porque a plataforma bloqueia autoplay de iframe no celular.

     Sem poster no <video>: o .hero__poster atrás já mostra a capa, e é ele que
     segura a tela até o vídeo poder tocar (o LCP não espera vídeo). */
  window.poHeroVideo = function (r) {
    if (!r.video_capa_url) return '';
    // Quem pediu "reduzir animações" no sistema não deve receber vídeo tocando
    // sozinho na cara. Fica a capa parada — mesma regra do resto do site.
    try { if (matchMedia('(prefers-reduced-motion: reduce)').matches) return ''; } catch (e) {}
    return '<video class="hero__vid" src="' + esc(r.video_capa_url) + '" ' +
      'autoplay muted loop playsinline preload="auto" ' +
      'disablepictureinpicture disableremoteplayback tabindex="-1" aria-hidden="true"></video>';
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
