/* ============================================================
   Pereira Oliveira Turismo — Página Nossa História
   Menu mobile · reveal on scroll · contagem de anos ·
   carrossel de roteiros (card destino, porte do card-21).
============================================================ */
(function () {
  'use strict';
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------- menu mobile ---------- */
  const sheet = document.getElementById('sheet');
  if (sheet) {
    const setSheet = (open) => sheet.setAttribute('data-open', open ? 'true' : 'false');
    document.getElementById('burger').addEventListener('click', () => setSheet(true));
    document.getElementById('sheet-close').addEventListener('click', () => setSheet(false));
    sheet.querySelector('.sheet__scrim').addEventListener('click', () => setSheet(false));
    sheet.querySelectorAll('a').forEach((a) => a.addEventListener('click', () => setSheet(false)));
  }

  /* ---------- contagem de anos desde 1967 ---------- */
  function countUp(el) {
    const from = parseInt(el.dataset.countFrom, 10);
    const target = from ? new Date().getFullYear() - from : parseInt(el.dataset.count, 10);
    if (!target || reduce) { el.textContent = target || el.textContent; return; }
    const dur = 1100, t0 = performance.now();
    (function tick(now) {
      const p = Math.min((now - t0) / dur, 1);
      el.textContent = Math.round(target * (1 - Math.pow(1 - p, 3)));
      if (p < 1) requestAnimationFrame(tick);
    })(t0);
  }

  /* ---------- reveal on scroll ---------- */
  const reveals = document.querySelectorAll('.his-reveal');
  if (reduce || !('IntersectionObserver' in window)) {
    reveals.forEach((el) => el.classList.add('is-in'));
    document.querySelectorAll('[data-count]').forEach(countUp);
  } else {
    const io = new IntersectionObserver((entries, obs) => {
      entries.forEach((e) => {
        if (!e.isIntersecting) return;
        e.target.classList.add('is-in');
        e.target.querySelectorAll('[data-count]').forEach(countUp);
        obs.unobserve(e.target);
      });
    }, { threshold: 0.16, rootMargin: '0px 0px -8% 0px' });
    reveals.forEach((el) => io.observe(el));
  }

  /* ============================================================
     Roteiros — carrossel de cards destino
  ============================================================ */
  const ROTEIROS = [
    { titulo: 'Mercados de Natal', badge: '11 dias', local: 'Suíça, França, Alemanha e Holanda', data: '10 a 21/12/2025', img: 'assets/images/roteiro-mercados-natal.jpg', href: 'mercados-de-natal.html' },
    { titulo: 'Tesouros Asiáticos', badge: '21 dias', local: 'Vietnã, Tailândia e Doha', data: '03 a 23/03/2026', img: 'assets/images/roteiro-tesouros-asiaticos.jpg', href: 'tesouros-asiaticos.html' },
    { titulo: 'Floração das Cerejeiras', badge: '16 dias', local: 'Japão e Doha', data: '11 a 27/04/2026', img: 'assets/images/roteiro-cerejeiras.jpg', href: 'floracao-das-cerejeiras.html' },
    { titulo: 'Grécia Terra e Mar', badge: '16 dias', local: 'Grécia com Cruzeiro', data: '02 a 18/05/2026', img: 'assets/images/roteiro-grecia.jpg', href: 'grecia-terra-mar.html' },
    { titulo: 'Encantos do Mediterrâneo', badge: '14 dias', local: 'Tunísia e Malta', data: '04 a 17/06/2026', img: 'assets/images/roteiro-mediterraneo.jpg', href: 'encantos-do-mediterraneo.html' }
  ];

  const track = document.getElementById('rot-track');
  if (!track) return;
  const viewport = track.parentElement;
  const prevBtn = document.getElementById('rot-prev');
  const nextBtn = document.getElementById('rot-next');
  const counter = document.getElementById('rot-count');

  const arrow =
    '<svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';

  const cards = ROTEIROS.map((r) => {
    const a = document.createElement('article');
    a.className = 'rot-card';
    a.innerHTML =
      '<div class="rot-card__img" style="background-image:url(\'' + r.img + '\')"></div>' +
      '<div class="rot-card__body">' +
        '<div class="rot-card__title"><h3>' + r.titulo + '</h3><span class="rot-card__badge">' + r.badge + '</span></div>' +
        '<div class="rot-card__sub"><b></b>' + r.local + ' · ' + r.data + '</div>' +
        '<a class="rot-card__cta" href="' + r.href + '">Ver roteiro' + arrow + '</a>' +
      '</div>';
    track.appendChild(a);
    return a;
  });

  let index = 0;

  function metrics() {
    const gap = parseFloat(getComputedStyle(track).columnGap) || 0;
    const step = cards[0].getBoundingClientRect().width + gap;
    const maxScroll = Math.max(0, track.scrollWidth - viewport.clientWidth);
    const maxIndex = step ? Math.ceil((maxScroll - 1) / step) : 0;
    return { step, maxScroll, maxIndex };
  }

  function update() {
    const { step, maxScroll, maxIndex } = metrics();
    index = Math.max(0, Math.min(index, maxIndex));
    const x = Math.min(index * step, maxScroll);
    track.style.transform = 'translateX(' + (-x) + 'px)';
    if (prevBtn) prevBtn.disabled = x <= 0;
    if (nextBtn) nextBtn.disabled = x >= maxScroll - 1;
    if (counter) counter.textContent = (Math.min(index + 1, maxIndex + 1)) + ' / ' + (maxIndex + 1);
  }

  nextBtn && nextBtn.addEventListener('click', () => { index++; update(); });
  prevBtn && prevBtn.addEventListener('click', () => { index--; update(); });

  // arraste / swipe
  let dragX = null;
  viewport.addEventListener('pointerdown', (e) => { dragX = e.clientX; });
  window.addEventListener('pointerup', (e) => {
    if (dragX === null) return;
    const dx = e.clientX - dragX; dragX = null;
    if (Math.abs(dx) > 55) { dx < 0 ? index++ : index--; update(); }
  });

  // tilt 3D no hover (porte do efeito do card-21)
  if (!reduce && window.matchMedia('(hover: hover)').matches) {
    cards.forEach((card) => {
      card.addEventListener('pointermove', (e) => {
        const r = card.getBoundingClientRect();
        const px = (e.clientX - r.left) / r.width - 0.5;
        const py = (e.clientY - r.top) / r.height - 0.5;
        card.style.setProperty('--ry', (px * 9).toFixed(2) + 'deg');
        card.style.setProperty('--rx', (-py * 9).toFixed(2) + 'deg');
      });
      card.addEventListener('pointerleave', () => {
        card.style.setProperty('--ry', '0deg');
        card.style.setProperty('--rx', '0deg');
      });
    });
  }

  let rt;
  window.addEventListener('resize', () => { clearTimeout(rt); rt = setTimeout(update, 150); });
  update();
})();
