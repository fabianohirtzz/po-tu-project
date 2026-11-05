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

  // Status da viagem como tag colorida na capa. Mesma lista de PO_STATUS_VIAGEM
  // (lib/po-view.php). Valor fora da lista nao emite nada: a chave vira classe.
  var STATUS_VIAGEM = { semanal: 'Saídas semanais', em_breve: 'Em breve', ultimas_vagas: 'Últimas vagas', esgotado: 'Vagas esgotadas', em_viagem: 'Em viagem' };
  window.poStatusTag = function (r) {
    var k = r && r.status_viagem;
    if (typeof k !== 'string' || !Object.prototype.hasOwnProperty.call(STATUS_VIAGEM, k)) return '';
    return '<span class="st-tag st-tag--' + k + '">' + STATUS_VIAGEM[k] + '</span>';
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
      /* ORDEM E FILTRO AQUI, na unica leitura de lista do front: a home, a
         Nossa Historia e o /link saem todas desta funcao, entao tela nova de
         roteiro nasce cronologica e sem viagem vencida sem ninguem lembrar de
         chamar nada. O `.order('ordem')` continua na consulta porque ele e o
         criterio de EMPATE de poPorSaida. */
      return window.poPorSaida(window.poSemVencidos(res.data));
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

  /* ---------- datas de roteiro de SAIDAS SEMANAIS ----------
     Gemeo de lib/po-datas.php e de painel/datas.js.
     tests/test-datas-semanal.mjs roda a MESMA tabela de casos nas tres e
     exige que concordem caso a caso. Existe aqui porque as paginas publicas
     leem o banco NO NAVEGADOR: a home monta o carrossel por Supabase, sem
     passar pelo PHP, entao o servidor nao tem onde calcular isto. */
  window.poDatasGrupos = function (roteiro) {
    if (!roteiro || typeof roteiro !== 'object') return [];
    var v = roteiro.datas_texto;
    if (typeof v === 'string') { try { v = JSON.parse(v); } catch (e) { return []; } }
    /* A regra do PHP, nao a do JS: json_decode(..., true) transforma
       {"0":...} em [0 => ...], entao do lado do servidor um objeto de chave
       numerica E uma lista, e nao ha como distingui-los depois da decodagem.
       Array.isArray sozinho rejeitava o que o PHP aceita, e as duas pontas
       discordavam sobre o MESMO registro do banco - com o site anunciando a
       data e o painel abrindo o bloco vazio, de modo que o Salvar seguinte
       apagava o que o site ainda mostrava. Chave 0..n-1 passa; chave textual
       ou numeracao furada, nao. */
    if (!v || typeof v !== 'object') return [];
    if (!Array.isArray(v)) {
      var ks = Object.keys(v);
      for (var k = 0; k < ks.length; k++) if (ks[k] !== String(k)) return [];
      v = ks.map(function (x) { return v[x]; });
    }
    if (!v.length) return [];
    var out = [];
    for (var i = 0; i < v.length; i++) {
      var g = v[i];
      if (!g || typeof g !== 'object' || typeof g.titulo !== 'string') continue;
      if (!Array.isArray(g.datas)) continue;
      var datas = [];
      for (var j = 0; j < g.datas.length; j++) {
        if (typeof g.datas[j] !== 'string') continue;
        var d = g.datas[j].trim();
        if (d !== '') datas.push(d);
      }
      out.push({ titulo: g.titulo, datas: datas });
    }
    return out;
  };

  /* 'd/m/a' -> 'YYYY-MM-DD', ou null. Data impossivel (32/12, 31/02) e
     recusada: erro de digitacao no painel nao pode virar data valida. */
  window.poDataIso = function (txt) {
    if (typeof txt !== 'string') return null;
    var m = /^\s*(\d{1,2})\/(\d{1,2})\/(\d{4}|\d{2})\s*$/.exec(txt);
    if (!m) return null;
    var d = +m[1], mes = +m[2], ano = +m[3];
    if (m[3].length === 2) ano += 2000;
    if (mes < 1 || mes > 12 || d < 1) return null;
    var dt = new Date(Date.UTC(ano, mes - 1, d));
    if (dt.getUTCFullYear() !== ano || dt.getUTCMonth() !== mes - 1 || dt.getUTCDate() !== d) return null;
    return ano + '-' + (mes < 10 ? '0' : '') + mes + '-' + (d < 10 ? '0' : '') + d;
  };

  /* A MENOR data de hoje em diante. `>=` porque a saida de hoje ainda e uma
     saida. Data ilegivel e descartada e a busca CONTINUA. */
  window.poProximaSaida = function (roteiro, hoje) {
    var h = hoje || new Date().toISOString().slice(0, 10);
    var gs = window.poDatasGrupos(roteiro), melhor = null;
    for (var i = 0; i < gs.length; i++) {
      for (var j = 0; j < gs[i].datas.length; j++) {
        var iso = window.poDataIso(gs[i].datas[j]);
        if (iso === null || iso < h) continue;
        if (melhor === null || iso < melhor) melhor = iso;
      }
    }
    return melhor;
  };

  /* A string de data que a pessoa le, em todo card. Quem decide e o STATUS,
     nao a presenca do dado. Semanal sem data futura volta ao comportamento
     de sempre: anunciar "Proxima saida" de data vencida e pior que nao
     anunciar nada, e e o que acontece no mes em que ninguem atualiza o
     painel. A data sai EXATAMENTE como foi digitada. */
  window.poQuando = function (roteiro, hoje) {
    if (!roteiro || typeof roteiro !== 'object') return '';
    if (roteiro.status_viagem === 'semanal') {
      var iso = window.poProximaSaida(roteiro, hoje);
      if (iso !== null) {
        var gs = window.poDatasGrupos(roteiro);
        for (var i = 0; i < gs.length; i++) {
          for (var j = 0; j < gs[i].datas.length; j++) {
            if (window.poDataIso(gs[i].datas[j]) === iso) return 'Próxima saída: ' + gs[i].datas[j];
          }
        }
      }
    }
    var s = String(roteiro.data_label == null ? '' : roteiro.data_label).trim();
    if (s !== '') return s;
    return String(roteiro.periodo == null ? '' : roteiro.periodo).trim();
  };

  /* ---------- ORDEM CRONOLOGICA ----------
     Gemeos de lib/po-datas.php. tests/test-ordem-cronologica.mjs roda a MESMA
     tabela de casos nos dois lados e exige que concordem caso a caso: tabela
     unica, nunca uma lista por implementacao, que e exatamente como dois
     lados divergem com os dois testes verdes.

     Existem em JavaScript porque as paginas publicas (home, Nossa Historia,
     /link) leem o banco NO NAVEGADOR, com a consulta de poFetchRoteiros. */

  // UTC, o mesmo instante que o gmdate do PHP e o toISOString dos outros
  // gemeos. Hora local faria a ORDEM e o ROTULO discordarem entre 21h e
  // meia-noite em Sao Paulo.
  window.poHoje = function () { return new Date().toISOString().slice(0, 10); };

  /* As datas do PRIMEIRO campo que tiver alguma, em ISO, na ordem do texto.
     `periodo` primeiro: os 7 roteiros medidos tem data ali, e o cliente foi
     explicito em 05/11/2026. Data impossivel (31/02) e descartada e a busca
     continua; campo sem nenhuma data valida passa a vez ao seguinte.

     Regex NAO ancorada, ao contrario de poDataIso: aqui se varre um texto
     corrido ("De 27/10/26 a 12/11/26"), nao se valida um campo inteiro. */
  window.poDatasDoCampo = function (roteiro) {
    if (!roteiro || typeof roteiro !== 'object') return [];
    var campos = ['periodo', 'data_label', 'descricao_curta'];
    for (var c = 0; c < campos.length; c++) {
      var txt = String(roteiro[campos[c]] == null ? '' : roteiro[campos[c]]);
      if (txt === '') continue;
      var re = /(\d{1,2})\/(\d{1,2})\/(\d{4}|\d{2})/g, m, out = [];
      while ((m = re.exec(txt)) !== null) {
        var iso = window.poDataIso(m[1] + '/' + m[2] + '/' + m[3]);
        if (iso !== null) out.push(iso);
      }
      if (out.length) return out;
    }
    return [];
  };

  /* A data de PARTIDA. Semanal tira da lista de datas do mes (a proxima
     futura); sem data cadastrada, cai na regra de sempre e o roteiro vai para
     o fim da lista - que e o caso do Peru hoje. */
  window.poDataSaida = function (roteiro, hoje) {
    if (!roteiro || typeof roteiro !== 'object') return null;
    if (roteiro.status_viagem === 'semanal') {
      var prox = window.poProximaSaida(roteiro, hoje);
      if (prox !== null) return prox;
    }
    var d = window.poDatasDoCampo(roteiro);
    return d.length ? d[0] : null;
  };

  /* O ULTIMO dia da viagem: a ultima data do MESMO campo que deu a partida.
     Semanal nao tem fim - ele sai toda semana. */
  window.poDataFim = function (roteiro) {
    if (!roteiro || typeof roteiro !== 'object') return null;
    if (roteiro.status_viagem === 'semanal') return null;
    var d = window.poDatasDoCampo(roteiro);
    return d.length ? d[d.length - 1] : null;
  };

  /* A viagem ja terminou? Pelo ULTIMO dia, nao pelo primeiro: em 05/11/2026 a
     Turquia tinha partido em 27/10 e terminava em 12/11, ou seja estava EM
     CURSO. Pelo primeiro dia ela sumiria do site no meio do passeio do grupo.
     Roteiro sem data nunca vence. */
  window.poVencido = function (roteiro, hoje) {
    var fim = window.poDataFim(roteiro);
    if (fim === null) return false;
    return fim < (hoje || window.poHoje());
  };

  /* Ordem cronologica, sem data no fim. O empate e desfeito pela posicao de
     ORIGEM, que e a ordem manual do painel: ordem que varia entre duas
     leituras troca o roteiro debaixo do visitante. */
  window.poPorSaida = function (lista, hoje) {
    var h = hoje || window.poHoje();
    return (lista || []).map(function (r, i) {
      return { r: r, k: window.poDataSaida(r, h) || '9999-99-99', i: i };
    }).sort(function (a, b) {
      return a.k < b.k ? -1 : a.k > b.k ? 1 : a.i - b.i;
    }).map(function (x) { return x.r; });
  };

  window.poSemVencidos = function (lista, hoje) {
    var h = hoje || window.poHoje();
    return (lista || []).filter(function (r) { return !window.poVencido(r, h); });
  };

  // registro do banco -> card do slider/carrossel
  window.poCard = function (r) {
    return {
      slug: r.slug, titulo: r.titulo || '',
      subtitulo: r.subtitulo || r.local_label || '',
      local: r.local_label || r.subtitulo || '',
      badge: r.badge || (r.dias ? r.dias + ' dias' : ''),
      data: window.poQuando(r),
      desc: r.descricao_curta || '',
      img: r.capa_url || '',
      href: window.poRoteiroHref(r.slug)
    };
  };
})();
