/* ============================================================
   Pereira Oliveira Turismo — Home
   Focus-rail (coverflow) portado para vanilla + header mobile.
   Textos e imagens dos roteiros vindos do site atual.
============================================================ */
(function () {
  'use strict';

  /* ---------- dados dos roteiros (copy do site atual) ---------- */
  const ROTEIROS = [
    {
      titulo: 'Mercados de Natal',
      subtitulo: 'Suíça, França, Alemanha e Holanda',
      data: '11 dias · 10 a 21/12/2025',
      desc: 'A exclusiva oportunidade de viver o encanto do Natal em uma jornada única, visitando fascinantes Mercados de Natal que atraem milhares de pessoas do mundo inteiro.',
      img: 'assets/images/roteiro-mercados-natal.jpg',
      href: '#'
    },
    {
      titulo: 'Vietnã, Tailândia e Doha',
      subtitulo: 'Tesouros Asiáticos',
      data: '21 dias · 03 a 23/03/2026',
      desc: 'Uma jornada entre culturas milenares, paisagens exóticas e contrastes fascinantes: Doha, Vietnã e Tailândia em um só roteiro inesquecível.',
      img: 'assets/images/roteiro-tesouros-asiaticos.jpg',
      href: '#'
    },
    {
      titulo: 'Japão e Doha',
      subtitulo: 'Na Floração das Cerejeiras',
      data: '16 dias · 11 a 27/04/2026',
      desc: 'Uma viagem que celebra a floração das cerejeiras no Japão e todo o encanto da primavera oriental, e continua em Doha, onde a estação ganha tons dourados e o charme da modernidade árabe.',
      img: 'assets/images/roteiro-cerejeiras.jpg',
      href: '#'
    },
    {
      titulo: 'Grécia Terra e Mar',
      subtitulo: 'Grécia com Cruzeiro',
      data: '16 dias · 02 a 18/05/2026',
      desc: 'Embarque em uma jornada pelos monumentos de Meteora, a vibrante Tessalônica e as deslumbrantes Ilhas Gregas. A bordo do Celestyal Journey, explore Santorini, Mykonos, Creta e muito mais.',
      img: 'assets/images/roteiro-grecia.jpg',
      href: '#'
    },
    {
      titulo: 'Tunísia e Malta',
      subtitulo: 'Encantos do Mediterrâneo',
      data: '14 dias · 04 a 17/06/2026',
      desc: 'Dois mundos, uma viagem: da mítica Cartago às casinhas azuis de Sidi Bou Said, do anfiteatro de El Jem ao oásis de Tozeur, até Valletta (UNESCO), a charmosa Gozo e Marsaxlokk.',
      img: 'assets/images/roteiro-mediterraneo.jpg',
      href: '#'
    }
  ];

  const N = ROTEIROS.length;
  let active = 0;
  let autoTimer = null;
  const AUTOPLAY_MS = 5200;

  const track = document.getElementById('rail-track');
  const panel = document.getElementById('panel');
  const els = {
    sub: document.getElementById('p-sub'),
    title: document.getElementById('p-title'),
    date: document.getElementById('p-date'),
    desc: document.getElementById('p-desc'),
    btn: document.getElementById('p-btn')
  };

  /* ---------- monta os slides ---------- */
  const slides = ROTEIROS.map((r, i) => {
    const s = document.createElement('article');
    s.className = 'slide';
    s.dataset.index = i;
    s.innerHTML =
      '<img src="' + r.img + '" alt="' + r.subtitulo + '">' +
      '<div class="slide__label"><div class="t">' + r.subtitulo + '</div>' +
      '<div class="d">' + r.data + '</div></div>';
    s.addEventListener('click', () => {
      if (i === active) return;
      go(i);
    });
    track.appendChild(s);
    return s;
  });

  /* ---------- posiciona o coverflow (blur só nos vizinhos) ---------- */
  // geometria por distância ao centro
  const GEO = {
    0: { x: 0,   z: 0,    ry: 0,   sc: 1,   blur: 0, op: 1,   z_index: 40 },
    1: { x: 62,  z: -150, ry: 24,  sc: .82, blur: 5, op: .95, z_index: 30 },
    2: { x: 108, z: -300, ry: 30,  sc: .64, blur: 9, op: .55, z_index: 20 }
  };

  function render() {
    slides.forEach((s, i) => {
      let pos = i - active;
      if (pos > N / 2) pos -= N;
      if (pos < -N / 2) pos += N;

      const dist = Math.abs(pos);
      const g = GEO[Math.min(dist, 2)];
      const dir = pos < 0 ? -1 : 1;

      const x = dir * g.x;
      const ry = -dir * g.ry;

      s.style.transform =
        'translateX(' + x + '%) translateZ(' + g.z + 'px) rotateY(' + ry + 'deg) scale(' + g.sc + ')';
      s.style.filter = g.blur ? 'blur(' + g.blur + 'px) brightness(.8)' : 'none';
      s.style.opacity = dist > 2 ? 0 : g.op;
      s.style.zIndex = g.z_index;
      s.style.pointerEvents = dist > 2 ? 'none' : 'auto';
      s.classList.toggle('is-active', pos === 0);
    });
    updatePanel();
  }

  /* ---------- painel de texto ---------- */
  function updatePanel() {
    const r = ROTEIROS[active];
    panel.classList.add('is-switching');
    setTimeout(() => {
      els.sub.textContent = r.subtitulo;
      els.title.textContent = r.titulo;
      els.date.textContent = r.data;
      els.desc.textContent = r.desc;
      els.btn.href = r.href;
      panel.classList.remove('is-switching');
    }, 200);
  }

  /* ---------- navegação ---------- */
  function go(i) {
    active = ((i % N) + N) % N;
    render();
    restartAuto();
  }
  const next = () => go(active + 1);
  const prev = () => go(active - 1);

  function startAuto() { autoTimer = setInterval(next, AUTOPLAY_MS); }
  function stopAuto() { clearInterval(autoTimer); autoTimer = null; }
  function restartAuto() { stopAuto(); startAuto(); }

  document.getElementById('nav-next').addEventListener('click', next);
  document.getElementById('nav-prev').addEventListener('click', prev);

  // teclado
  window.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowRight') next();
    if (e.key === 'ArrowLeft') prev();
  });

  // pausa autoplay ao passar o mouse no rail
  const rail = document.getElementById('rail');
  rail.addEventListener('mouseenter', stopAuto);
  rail.addEventListener('mouseleave', startAuto);

  /* ---------- arraste / swipe ---------- */
  let dragX = null;
  rail.addEventListener('pointerdown', (e) => { dragX = e.clientX; stopAuto(); });
  window.addEventListener('pointerup', (e) => {
    if (dragX === null) return;
    const dx = e.clientX - dragX;
    dragX = null;
    if (Math.abs(dx) > 55) { dx < 0 ? next() : prev(); }
    else { startAuto(); }
  });

  /* ---------- menu mobile (sheet) ---------- */
  const sheet = document.getElementById('sheet');
  const setSheet = (open) => sheet.setAttribute('data-open', open ? 'true' : 'false');
  document.getElementById('burger').addEventListener('click', () => setSheet(true));
  document.getElementById('sheet-close').addEventListener('click', () => setSheet(false));
  sheet.querySelector('.sheet__scrim').addEventListener('click', () => setSheet(false));
  sheet.querySelectorAll('a').forEach((a) => a.addEventListener('click', () => setSheet(false)));

  /* ---------- boot ---------- */
  render();
  startAuto();
})();

/* ============================================================
   Nossa História — reveal ao rolar, header adaptativo, contagem
============================================================ */
(function () {
  'use strict';
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* header escurece ao sair do hero */
  const hd = document.querySelector('.hd');
  const onScroll = () => {
    if (!hd) return;
    hd.classList.toggle('hd--solid', window.scrollY > window.innerHeight * 0.62);
  };
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  /* contagem regressiva de anos a partir de 1967 (sempre atual) */
  function countUp(el) {
    const from = parseInt(el.dataset.countFrom, 10);
    const target = from ? new Date().getFullYear() - from : parseInt(el.dataset.count, 10);
    if (!target || reduce) { el.textContent = target || el.textContent; return; }
    const dur = 1100, t0 = performance.now();
    (function tick(now) {
      const p = Math.min((now - t0) / dur, 1);
      const eased = 1 - Math.pow(1 - p, 3);
      el.textContent = Math.round(target * eased);
      if (p < 1) requestAnimationFrame(tick);
    })(t0);
  }

  /* reveal ao entrar na viewport */
  const reveals = document.querySelectorAll('.his-reveal');
  if (reduce || !('IntersectionObserver' in window)) {
    reveals.forEach((el) => el.classList.add('is-in'));
    document.querySelectorAll('[data-count]').forEach(countUp);
    return;
  }
  const io = new IntersectionObserver((entries, obs) => {
    entries.forEach((e) => {
      if (!e.isIntersecting) return;
      e.target.classList.add('is-in');
      e.target.querySelectorAll('[data-count]').forEach(countUp);
      obs.unobserve(e.target);
    });
  }, { threshold: 0.16, rootMargin: '0px 0px -8% 0px' });
  reveals.forEach((el) => io.observe(el));
})();
