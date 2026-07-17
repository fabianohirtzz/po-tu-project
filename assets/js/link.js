/* ============================================================
   Pereira Oliveira Turismo — Página de links da bio (/link)
   Contagem dos anos · cards de roteiro lidos do banco.
   Os cards NUNCA são escritos à mão: sem banco, sem card.
============================================================ */
(function () {
  'use strict';
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------- contagem (mesma regra da Nossa História) ----------
     data-count-from="1967" conta os anos ao vivo: o número nunca envelhece. */
  function countUp(el) {
    var from = parseInt(el.dataset.countFrom, 10);
    var target = from ? new Date().getFullYear() - from : parseInt(el.dataset.count, 10);
    if (!target || reduce) { el.textContent = target || el.textContent; return; }
    var dur = 1100, t0 = performance.now();
    (function tick(now) {
      var p = Math.min((now - t0) / dur, 1);
      el.textContent = Math.round(target * (1 - Math.pow(1 - p, 3)));
      if (p < 1) requestAnimationFrame(tick);
    })(t0);
  }
  document.querySelectorAll('[data-count]').forEach(countUp);

  /* ---------- cards de roteiro (do banco) ----------
     Sem banco ou sem roteiro ativo: NENHUM card, e o resto da página segue de
     pé. É deliberado — melhor card nenhum do que card vencido. Não existe
     fallback escrito à mão: foi o que causou o flash do roteiro excluído na
     home (CLAUDE.md, 16/07/2026). */
  var UTM = 'utm_source=instagram&utm_medium=bio&utm_campaign=link';

  function esc(s) {
    return (s == null ? '' : String(s)).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  // poRoteiroHref já pode devolver uma URL com query (roteiro.html?slug=…),
  // então o separador do UTM depende do que veio.
  function comUtm(href, slug) {
    var sep = href.indexOf('?') === -1 ? '?' : '&';
    return href + sep + UTM + '&utm_content=' + encodeURIComponent(slug);
  }

  function cardHtml(r) {
    var href = comUtm(window.poRoteiroHref(r.slug), r.slug);
    var meta = [r.badge, r.periodo].filter(Boolean).map(esc).join(' · ');
    return '<li class="lk-card">' +
      '<a class="lk-card__img" href="' + esc(href) + '" tabindex="-1" aria-hidden="true">' +
        '<img src="' + esc(r.capa_url || '') + '" alt="" loading="lazy" decoding="async">' +
      '</a>' +
      '<div class="lk-card__body">' +
        '<h3 class="lk-card__t">' + esc(r.titulo || '') + '</h3>' +
        (r.subtitulo ? '<p class="lk-card__s">' + esc(r.subtitulo) + '</p>' : '') +
        (meta ? '<p class="lk-card__m">' + meta + '</p>' : '') +
        '<a class="lk-card__go" href="' + esc(href) + '">Ver roteiro</a>' +
      '</div>' +
    '</li>';
  }

  var alvo = document.getElementById('link-roteiros');
  if (alvo && window.poFetchRoteiros) {
    window.poFetchRoteiros().then(function (rs) {
      if (!rs || !rs.length) return;              // sem banco = sem card
      alvo.innerHTML = rs.map(cardHtml).join('');
    }).catch(function (err) {
      // Sem banco a página segue sem card, isso é esperado e deliberado.
      // Erro inesperado precisa deixar rastro no console, senão um bug
      // (campo renomeado no banco, por exemplo) se disfarça de ausência de
      // roteiro. Página continua em pé: nenhum card, como sem banco.
      console.error('link.js: erro ao buscar roteiros', err);
    });
  }
})();
