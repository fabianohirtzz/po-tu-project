/* ============================================================
   Pereira Oliveira — página de roteiro dinâmica (roteiro.html?slug=)
   Lê o roteiro do Supabase e monta a página com o mesmo template
   das páginas estáticas; depois reaproveita roteiro.js + lead-form.js.
============================================================ */
(function () {
  'use strict';
  const esc = (s) => (s == null ? '' : String(s)).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const arr = (a) => Array.isArray(a) ? a : [];
  const main = document.getElementById('rt-main');

  function notFound(msg) {
    return '<section class="sec" style="min-height:70vh;display:grid;place-items:center;text-align:center">' +
      '<div class="wrap"><p class="tag sec__eyebrow" style="justify-content:center"><span></span>Roteiro</p>' +
      '<h1 class="sec__h" style="margin-bottom:14px">' + esc(msg) + '</h1>' +
      '<p style="color:#3c3e45;margin-bottom:24px">Talvez ele ainda não esteja publicado.</p>' +
      '<a class="btn btn--dark" href="index.html#inicio">Ver todos os roteiros</a></div></section>';
  }

  function heroChips(r) {
    const chips = [];
    if (r.dias) chips.push('<span class="hero__chip"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>' + esc(r.dias) + ' dias</span>');
    const per = r.data_label || r.periodo;
    if (per) chips.push('<span class="hero__chip"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>' + esc(per) + '</span>');
    if (r.local_label) chips.push('<span class="hero__chip"><svg viewBox="0 0 24 24"><path d="M12 21s-7-6.3-7-11a7 7 0 0 1 14 0c0 4.7-7 11-7 11Z"/><circle cx="12" cy="10" r="2.5"/></svg>' + esc(r.local_label) + '</span>');
    return chips.join('');
  }
  const WHY = [
    'Cada aspecto da viagem é meticulosamente planejado por uma equipe dedicada, com profundo conhecimento dos destinos.',
    'Nossa experiência em viagens de grupo cria uma dinâmica harmoniosa e uma convivência agradável entre os viajantes.',
    'Um coordenador da Pereira Oliveira acompanha o grupo desde a saída do Brasil, com suporte contínuo em todo o roteiro.',
    'Trabalhamos com uma rede de hotéis e fornecedores criteriosamente selecionados, para garantir conforto e bom atendimento.'
  ];
  const CHECK = '<span class="why__ic"><svg viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5" stroke-linecap="round" stroke-linejoin="round"/></svg></span>';

  function render(r) {
    const capa = esc(r.capa_url || '');
    const days = arr(r.roteiro_dias);
    const inclui = arr(r.inclui), naoInc = arr(r.nao_inclui), hoteis = arr(r.hoteis).map(h => typeof h === 'string' ? h : (h.cidade ? h.cidade + ': ' + h.hotel : ''));
    const valores = arr(r.valores), galeria = arr(r.galeria);
    let h = '';

    /* HERO */
    h += '<section class="hero" id="topo"><div class="hero__media">' +
      '<div class="hero__poster" style="background-image:url(\'' + capa + '\')"></div>' +
      (r.video_id ? '<div class="hero__video" id="hero-video" data-yt="' + esc(r.video_id) + '"' + (r.video_list ? ' data-yt-list="' + esc(r.video_list) + '"' : '') + '></div>' : '') +
      '</div><div class="hero__scrim"></div><div class="hero__inner">' +
      '<p class="hero__crumb"><a href="index.html">Início</a><svg viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></svg><a href="index.html#inicio">Roteiros</a><svg viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></svg><span>' + esc(r.titulo) + '</span></p>' +
      '<h1 class="hero__title">' + esc(r.titulo) + '</h1>' +
      (r.subtitulo ? '<p class="hero__sub">' + esc(r.subtitulo) + '</p>' : '') +
      '<div class="hero__meta">' + heroChips(r) + '</div>' +
      '<div class="hero__actions"><a class="btn" href="#contato">Quero este roteiro<svg viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/></svg></a>' +
      (valores.length ? '<a class="btn btn--ghost" href="#investimento">Ver investimento</a>' : '') + '</div>' +
      '</div><a class="hero__scroll" href="#sobre" aria-label="Rolar"><svg viewBox="0 0 24 24"><path d="M6 9l6 6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg></a></section>';

    /* INTRO / por que viajar */
    h += '<section class="sec intro" id="sobre"><div class="wrap intro__grid"><div class="reveal">' +
      '<p class="tag sec__eyebrow"><span></span>Por que viajar com a Pereira Oliveira</p>' +
      '<h2 class="sec__h">Cada detalhe pensado para você só aproveitar.</h2>' +
      (r.descricao_curta ? '<p class="intro__lead">' + esc(r.descricao_curta) + '</p>' : '') +
      '<ul class="why">' + WHY.map(w => '<li>' + CHECK + '<p>' + w + '</p></li>').join('') + '</ul></div>' +
      '<figure class="intro__photo reveal" data-delay="1"><img src="' + capa + '" alt="' + esc(r.titulo) + '">' +
      (r.dias ? '<figcaption class="intro__stamp"><b>' + esc(r.dias) + '</b><span>dias de viagem</span></figcaption>' : '') +
      '</figure></div></section>';

    /* DIA A DIA */
    if (days.length) {
      h += '<section class="sec days" id="roteiro"><div class="wrap"><div class="days__head reveal">' +
        '<p class="tag sec__eyebrow"><span></span>Roteiro dia a dia</p><h2 class="sec__h">O passo a passo da sua viagem.</h2></div><div class="tl">';
      days.forEach(d => {
        const dateline = [d.data, d.dia_semana].filter(Boolean).join(' · ');
        h += '<div class="day reveal"><div class="day__rail"><div class="day__n">' + esc(d.n || '') + (d.n ? 'º dia' : '') + '</div>' +
          (dateline ? '<span class="day__date">' + esc(dateline) + '</span>' : '') + '</div>' +
          '<div class="day__body">' + (d.cidades ? '<h3 class="day__city">' + esc(d.cidades) + '</h3>' : '') +
          (d.descricao ? '<p class="day__p">' + esc(d.descricao) + '</p>' : '') +
          (d.refeicoes ? '<div class="day__meals"><span class="pill"><b></b>' + esc(d.refeicoes) + '</span></div>' : '') +
          '</div></div>';
      });
      h += '</div></div></section>';
    }

    /* INFORMAÇÕES (inclui / não inclui / hotéis) */
    if (inclui.length || naoInc.length || hoteis.length) {
      const stack = galeria.slice(0, 3).map(u => '<img src="' + esc(u) + '" alt="' + esc(r.titulo) + '" loading="lazy">').join('');
      h += '<section class="sec info"><div class="wrap info__grid"><div class="reveal">' +
        '<p class="tag sec__eyebrow"><span></span>Informações do roteiro</p><h2 class="sec__h" style="margin-bottom:22px">O que está incluído.</h2>';
      if (inclui.length) h += '<ul class="why">' + inclui.map(i => '<li>' + CHECK + '<p>' + esc(i) + '</p></li>').join('') + '</ul>';
      if (hoteis.length) h += '<h3 class="sec__h" style="font-size:1.15rem;margin:28px 0 12px">Hotéis previstos</h3><ul class="why">' + hoteis.filter(Boolean).map(i => '<li>' + CHECK + '<p>' + esc(i) + '</p></li>').join('') + '</ul>';
      if (naoInc.length) h += '<h3 class="sec__h" style="font-size:1.15rem;margin:28px 0 12px">O pacote não inclui</h3><p class="intro__lead" style="font-size:1rem">' + naoInc.map(esc).join(' · ') + '</p>';
      h += '</div>' + (stack ? '<div class="info__stack reveal" data-delay="1">' + stack + '</div>' : '') + '</div></section>';
    }

    /* INVESTIMENTO */
    if (valores.length) {
      h += '<section class="sec invest" id="investimento"><div class="wrap"><div class="invest__head reveal">' +
        '<p class="tag" style="margin-bottom:14px">Investimento</p><h2>Valor por pessoa.</h2><p>Investimento aproximado por pessoa. Valores sujeitos a alteração, apenas cotação.</p></div>' +
        '<div class="invest__cards' + (valores.length < 2 ? ' invest__cards--single' : '') + '">';
      valores.forEach((v, i) => {
        h += '<div class="pcard' + (i === 0 ? ' pcard--feat' : '') + ' reveal">' +
          (v.tag ? '<div class="pcard__tag">' + esc(v.tag) + '</div>' : '') +
          (v.de ? '<div class="pcard__from">' + esc(v.de) + '</div>' : '') +
          '<div class="pcard__val">' + esc(v.valor || '') + '</div>' +
          (v.extra ? '<p class="pcard__extra">' + esc(v.extra) + '</p>' : '') + '</div>';
      });
      h += '</div><div class="invest__foot reveal"><p class="invest__note">Valores sujeitos a alteração sem aviso prévio. Apenas cotação.</p>' +
        '<div class="invest__actions"><a class="btn" href="#contato">Quero este roteiro<svg viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/></svg></a></div></div></div></section>';
    }

    /* GALERIA */
    if (galeria.length) {
      h += '<section class="sec gal" id="galeria"><div class="wrap"><div class="gal__head reveal"><div>' +
        '<p class="tag sec__eyebrow"><span></span>Galeria</p><h2 class="sec__h">Um aperitivo do que espera por você.</h2></div></div>' +
        '<div class="gal__grid" id="gal-grid">' +
        galeria.map((u, i) => '<figure class="gal__item reveal"><img src="' + esc(u) + '" alt="' + esc(r.titulo) + ', imagem ' + (i + 1) + '" loading="lazy"></figure>').join('') +
        '</div></div></section>';
    }

    /* OUTROS ROTEIROS */
    h += '<section class="more" id="outros"><div class="wrap"><div class="more__head"><div class="reveal">' +
      '<p class="tag sec__eyebrow"><span></span>Outros roteiros</p><h2>Talvez o próximo destino seja outro.</h2></div>' +
      '<div class="more__nav reveal" data-delay="1"><button id="more-prev" aria-label="Anteriores"><svg viewBox="0 0 24 24"><path d="M15 6l-6 6 6 6"/></svg></button>' +
      '<button id="more-next" aria-label="Próximos"><svg viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></svg></button></div></div>' +
      '<div class="rot-viewport"><div class="rot-track" id="more-track" data-current="' + esc(r.slug) + '"></div></div>' +
      '<p class="more__count" id="more-count" aria-hidden="true"></p></div></section>';

    return h;
  }

  function renderModal(r) {
    return '<div class="lead-modal" id="lead-modal" data-open="false" aria-hidden="true"><div class="lead-modal__scrim" data-close></div>' +
      '<div class="lead-modal__card" role="dialog" aria-modal="true" aria-label="Quero este roteiro">' +
      '<button class="lead-modal__close" data-close aria-label="Fechar">&times;</button>' +
      '<p class="tag sec__eyebrow"><span></span>Fale com a gente</p><h2 class="lead-modal__h">Quero este roteiro</h2>' +
      '<p class="lead-modal__lead">Preencha seus dados e nossa equipe entra em contato com os valores atualizados, condições e disponibilidade deste roteiro.</p>' +
      '<form class="form" id="lead-form" novalidate><div class="form__grid">' +
      '<div class="field field--full"><label for="f-nome">Nome completo <b>*</b></label><input type="text" id="f-nome" name="nome" autocomplete="name" required></div>' +
      '<div class="field"><label for="f-tel">WhatsApp / telefone <b>*</b></label><input type="tel" id="f-tel" name="telefone" autocomplete="tel" required></div>' +
      '<div class="field"><label for="f-email">E-mail <b>*</b></label><input type="email" id="f-email" name="email" autocomplete="email" required></div>' +
      '<div class="field"><label for="f-cidade">Cidade</label><input type="text" id="f-cidade" name="cidade" autocomplete="address-level2"></div>' +
      '<div class="field"><label for="f-viajantes">Nº de viajantes</label><select id="f-viajantes" name="viajantes"><option value="">Selecione</option><option>1 pessoa</option><option>2 pessoas</option><option>3 a 4 pessoas</option><option>5 ou mais</option></select></div>' +
      '<div class="field field--full"><label for="f-roteiro">Roteiro de interesse</label><input type="text" id="f-roteiro" name="roteiro" value="' + esc(r.titulo) + '" readonly></div>' +
      '<div class="field field--full"><label for="f-msg">Mensagem</label><textarea id="f-msg" name="mensagem" placeholder="Conte pra gente o que você gostaria de saber."></textarea></div>' +
      '<div class="field--hp" aria-hidden="true"><label for="f-site">Não preencha este campo</label><input type="text" id="f-site" name="website" tabindex="-1" autocomplete="off"></div></div>' +
      '<input type="hidden" name="_t" id="f-t"><input type="hidden" name="origem" value="site-roteiro-' + esc(r.slug) + '">' +
      '<input type="hidden" name="utm_source" id="f-utm_source"><input type="hidden" name="utm_medium" id="f-utm_medium"><input type="hidden" name="utm_campaign" id="f-utm_campaign">' +
      '<input type="hidden" name="gclid" id="f-gclid"><input type="hidden" name="referrer" id="f-referrer"><input type="hidden" name="landing_page" id="f-landing">' +
      '<button type="submit" class="btn form__submit">Enviar roteiro<svg viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/></svg></button>' +
      '<p class="form__legal">Seus dados são usados apenas para este atendimento. Retornamos pelo WhatsApp ou e-mail informado.</p>' +
      '<div class="form__msg" id="form-msg"></div></form></div></div>';
  }

  (async function () {
    const slug = new URLSearchParams(location.search).get('slug');
    if (!slug) { main.innerHTML = notFound('Roteiro não informado.'); return; }
    const r = window.poFetchRoteiro ? await window.poFetchRoteiro(slug) : null;
    if (!r) { main.innerHTML = notFound('Roteiro não encontrado.'); return; }
    document.title = (r.titulo || 'Roteiro') + ' — Pereira Oliveira Turismo';
    main.innerHTML = render(r);
    document.getElementById('rt-modal-mount').innerHTML = renderModal(r);
    // cards do carrossel "outros roteiros"
    const list = await window.poFetchRoteiros();
    window.PO_ROTEIROS_CARDS = ((list && list.length) ? list : [r]).map(window.poCard);
    // reaproveita as interações
    if (window.initRoteiro) window.initRoteiro();
    if (window.initLeadForm) window.initLeadForm();
  })();
})();
