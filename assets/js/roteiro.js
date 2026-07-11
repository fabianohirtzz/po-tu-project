/* ============================================================
   Pereira Oliveira Turismo — Página de Roteiro
   Menu mobile · reveal · hero vídeo (YouTube) · lightbox da galeria ·
   carrossel "outros roteiros" (lê do banco). O formulário fica no
   lead-form.js. Pode rodar sozinho (páginas estáticas) ou ser
   chamado por roteiro-dynamic.js (window.initRoteiro()).
============================================================ */
function initRoteiro() {
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

  /* ---------- reveal on scroll ---------- */
  const reveals = document.querySelectorAll('.reveal');
  if (reduce || !('IntersectionObserver' in window)) {
    reveals.forEach((el) => el.classList.add('is-in'));
  } else {
    const io = new IntersectionObserver((entries, obs) => {
      entries.forEach((e) => { if (!e.isIntersecting) return; e.target.classList.add('is-in'); obs.unobserve(e.target); });
    }, { threshold: 0.14, rootMargin: '0px 0px -8% 0px' });
    reveals.forEach((el) => io.observe(el));
  }

  /* ---------- hero: vídeo do YouTube em background ---------- */
  const heroVideo = document.getElementById('hero-video');
  if (heroVideo && !reduce && !heroVideo.querySelector('iframe')) {
    const id = heroVideo.dataset.yt;
    if (id) {
      const list = heroVideo.dataset.ytList;
      const loopPart = list ? ('loop=1&list=' + list) : ('loop=1&playlist=' + id);
      const params = 'autoplay=1&mute=1&controls=0&' + loopPart +
        '&modestbranding=1&showinfo=0&rel=0&iv_load_policy=3&playsinline=1&disablekb=1&fs=0&cc_load_policy=0';
      const iframe = document.createElement('iframe');
      iframe.src = 'https://www.youtube-nocookie.com/embed/' + id + '?' + params;
      const heroTitle = document.querySelector('.hero__title');
      iframe.title = (heroTitle && heroTitle.textContent.trim()) || 'Roteiro Pereira Oliveira';
      iframe.setAttribute('frameborder', '0');
      iframe.setAttribute('allow', 'autoplay; encrypted-media');
      iframe.setAttribute('allowfullscreen', '');
      iframe.addEventListener('load', () => heroVideo.classList.add('is-ready'));
      heroVideo.appendChild(iframe);
    }
  }

  /* ---------- galeria — lightbox ---------- */
  const grid = document.getElementById('gal-grid');
  const lb = document.getElementById('lb');
  if (grid && lb && !grid.dataset.lbWired) {
    grid.dataset.lbWired = '1';
    const imgs = Array.from(grid.querySelectorAll('img'));
    const lbImg = document.getElementById('lb-img');
    const lbCount = document.getElementById('lb-count');
    let cur = 0;
    const show = (i) => { cur = (i + imgs.length) % imgs.length; lbImg.src = imgs[cur].src; lbImg.alt = imgs[cur].alt; lbCount.textContent = (cur + 1) + ' / ' + imgs.length; };
    const open = (i) => { show(i); lb.setAttribute('data-open', 'true'); lb.setAttribute('aria-hidden', 'false'); };
    const close = () => { lb.setAttribute('data-open', 'false'); lb.setAttribute('aria-hidden', 'true'); };
    imgs.forEach((img, i) => img.addEventListener('click', () => open(i)));
    document.getElementById('lb-close').addEventListener('click', close);
    document.getElementById('lb-next').addEventListener('click', () => show(cur + 1));
    document.getElementById('lb-prev').addEventListener('click', () => show(cur - 1));
    lb.addEventListener('click', (e) => { if (e.target === lb) close(); });
    window.addEventListener('keydown', (e) => {
      if (lb.getAttribute('data-open') !== 'true') return;
      if (e.key === 'Escape') close();
      if (e.key === 'ArrowRight') show(cur + 1);
      if (e.key === 'ArrowLeft') show(cur - 1);
    });
  }

  /* ---------- outros roteiros — carrossel (do banco) ---------- */
  const ROTEIROS_ALL = [
    { slug: 'mercados-de-natal', titulo: 'Mercados de Natal', badge: '11 dias', local: 'Suíça, França, Alemanha e Holanda', data: '10 a 21/12/2025', img: 'assets/images/roteiro-mercados-natal.jpg', href: 'mercados-de-natal.html' },
    { slug: 'tesouros-asiaticos', titulo: 'Tesouros Asiáticos', badge: '21 dias', local: 'Vietnã, Tailândia e Doha', data: '03 a 23/03/2026', img: 'assets/images/roteiro-tesouros-asiaticos.jpg', href: 'tesouros-asiaticos.html' },
    { slug: 'floracao-das-cerejeiras', titulo: 'Floração das Cerejeiras', badge: '16 dias', local: 'Japão e Doha', data: '11 a 27/04/2026', img: 'assets/images/roteiro-cerejeiras.jpg', href: 'floracao-das-cerejeiras.html' },
    { slug: 'grecia-terra-mar', titulo: 'Grécia Terra e Mar', badge: '16 dias', local: 'Grécia com Cruzeiro', data: '02 a 18/05/2026', img: 'assets/images/roteiro-grecia.jpg', href: 'grecia-terra-mar.html' },
    { slug: 'encantos-do-mediterraneo', titulo: 'Encantos do Mediterrâneo', badge: '14 dias', local: 'Tunísia e Malta', data: '04 a 17/06/2026', img: 'assets/images/roteiro-mediterraneo.jpg', href: 'encantos-do-mediterraneo.html' }
  ];

  const track = document.getElementById('more-track');
  if (track && !track.dataset.wired) {
    track.dataset.wired = '1';
    const buildCarousel = (SRC) => {
      const current = track.dataset.current || '';
      const OUTROS = SRC.filter((r) => r.slug !== current);
      const viewport = track.parentElement;
      const prevBtn = document.getElementById('more-prev');
      const nextBtn = document.getElementById('more-next');
      const counter = document.getElementById('more-count');
      const arrow = '<svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
      const cards = OUTROS.map((r) => {
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
      if (!cards.length) return;

      let index = 0;
      const metrics = () => {
        const gap = parseFloat(getComputedStyle(track).columnGap) || 0;
        const step = cards[0].getBoundingClientRect().width + gap;
        const maxScroll = Math.max(0, track.scrollWidth - viewport.clientWidth);
        const maxIndex = step ? Math.ceil((maxScroll - 1) / step) : 0;
        return { step, maxScroll, maxIndex };
      };
      const update = () => {
        const { step, maxScroll, maxIndex } = metrics();
        index = Math.max(0, Math.min(index, maxIndex));
        const x = Math.min(index * step, maxScroll);
        track.style.transform = 'translateX(' + (-x) + 'px)';
        if (prevBtn) prevBtn.disabled = x <= 0;
        if (nextBtn) nextBtn.disabled = x >= maxScroll - 1;
        if (counter) counter.textContent = (Math.min(index + 1, maxIndex + 1)) + ' / ' + (maxIndex + 1);
      };
      nextBtn && nextBtn.addEventListener('click', () => { index++; update(); });
      prevBtn && prevBtn.addEventListener('click', () => { index--; update(); });
      let dragX = null;
      viewport.addEventListener('pointerdown', (e) => { dragX = e.clientX; });
      window.addEventListener('pointerup', (e) => {
        if (dragX === null) return;
        const dx = e.clientX - dragX; dragX = null;
        if (Math.abs(dx) > 55) { dx < 0 ? index++ : index--; update(); }
      });
      if (!reduce && window.matchMedia('(hover: hover)').matches) {
        cards.forEach((card) => {
          card.addEventListener('pointermove', (e) => {
            const r = card.getBoundingClientRect();
            card.style.setProperty('--ry', (((e.clientX - r.left) / r.width - 0.5) * 9).toFixed(2) + 'deg');
            card.style.setProperty('--rx', ((-((e.clientY - r.top) / r.height - 0.5)) * 9).toFixed(2) + 'deg');
          });
          card.addEventListener('pointerleave', () => { card.style.setProperty('--ry', '0deg'); card.style.setProperty('--rx', '0deg'); });
        });
      }
      let rt;
      window.addEventListener('resize', () => { clearTimeout(rt); rt = setTimeout(update, 150); });
      update();
    };

    if (window.PO_ROTEIROS_CARDS && window.PO_ROTEIROS_CARDS.length) buildCarousel(window.PO_ROTEIROS_CARDS);
    else if (window.poFetchRoteiros) window.poFetchRoteiros().then((db) => buildCarousel(db && db.length ? db.map(window.poCard) : ROTEIROS_ALL));
    else buildCarousel(ROTEIROS_ALL);
  }
}

window.initRoteiro = initRoteiro;
if (!window.__deferRoteiroInit) initRoteiro();
