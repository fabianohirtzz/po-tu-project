/* ============================================================
   Pereira Oliveira Turismo — Home
   Focus-rail (coverflow) vanilla. Os roteiros vêm do Supabase
   (po_roteiros); se indisponível, usa o fallback fixo abaixo.
============================================================ */
(function () {
  'use strict';

  /* ---------- fallback (se o banco não responder) ---------- */
  const FALLBACK = [
    { titulo: 'Mercados de Natal', subtitulo: 'Suíça, França, Alemanha e Holanda', data: '11 dias · 10 a 21/12/2025', desc: 'A exclusiva oportunidade de viver o encanto do Natal em uma jornada única, visitando fascinantes Mercados de Natal que atraem milhares de pessoas do mundo inteiro.', img: 'assets/images/roteiro-mercados-natal.jpg', href: 'mercados-de-natal.html' },
    { titulo: 'Vietnã, Tailândia e Doha', subtitulo: 'Tesouros Asiáticos', data: '21 dias · 03 a 23/03/2026', desc: 'Uma jornada entre culturas milenares, paisagens exóticas e contrastes fascinantes: Doha, Vietnã e Tailândia em um só roteiro inesquecível.', img: 'assets/images/roteiro-tesouros-asiaticos.jpg', href: 'tesouros-asiaticos.html' },
    { titulo: 'Japão e Doha', subtitulo: 'Na Floração das Cerejeiras', data: '16 dias · 11 a 27/04/2026', desc: 'Uma viagem que celebra a floração das cerejeiras no Japão e todo o encanto da primavera oriental, e continua em Doha, onde a estação ganha tons dourados e o charme da modernidade árabe.', img: 'assets/images/roteiro-cerejeiras.jpg', href: 'floracao-das-cerejeiras.html' },
    { titulo: 'Grécia Terra e Mar', subtitulo: 'Grécia com Cruzeiro', data: '16 dias · 02 a 18/05/2026', desc: 'Embarque em uma jornada pelos monumentos de Meteora, a vibrante Tessalônica e as deslumbrantes Ilhas Gregas. A bordo do Celestyal Journey, explore Santorini, Mykonos, Creta e muito mais.', img: 'assets/images/roteiro-grecia.jpg', href: 'grecia-terra-mar.html' },
    { titulo: 'Tunísia e Malta', subtitulo: 'Encantos do Mediterrâneo', data: '14 dias · 04 a 17/06/2026', desc: 'Dois mundos, uma viagem: da mítica Cartago às casinhas azuis de Sidi Bou Said, do anfiteatro de El Jem ao oásis de Tozeur, até Valletta (UNESCO), a charmosa Gozo e Marsaxlokk.', img: 'assets/images/roteiro-mediterraneo.jpg', href: 'encantos-do-mediterraneo.html' }
  ];

  const track = document.getElementById('rail-track');
  const panel = document.getElementById('panel');
  const els = {
    sub: document.getElementById('p-sub'), title: document.getElementById('p-title'),
    date: document.getElementById('p-date'), desc: document.getElementById('p-desc'), btn: document.getElementById('p-btn')
  };

  /* ---------- menu mobile (independe dos dados) ---------- */
  const sheet = document.getElementById('sheet');
  if (sheet) {
    const setSheet = (open) => sheet.setAttribute('data-open', open ? 'true' : 'false');
    document.getElementById('burger').addEventListener('click', () => setSheet(true));
    document.getElementById('sheet-close').addEventListener('click', () => setSheet(false));
    sheet.querySelector('.sheet__scrim').addEventListener('click', () => setSheet(false));
    sheet.querySelectorAll('a').forEach((a) => a.addEventListener('click', () => setSheet(false)));
  }

  function mapDb(r) {
    return {
      titulo: r.titulo || '',
      subtitulo: r.local_label || r.subtitulo || '',
      data: (r.dias ? r.dias + ' dias · ' : '') + (r.data_label || r.periodo || ''),
      desc: r.descricao_curta || '',
      img: r.capa_url || '',
      href: window.poRoteiroHref ? window.poRoteiroHref(r.slug) : ('roteiro.html?slug=' + r.slug)
    };
  }

  function initSlider(ROTEIROS) {
    if (!track || !ROTEIROS.length) return;
    const N = ROTEIROS.length;
    let active = 0, autoTimer = null;
    const AUTOPLAY_MS = 5200;

    const slides = ROTEIROS.map((r, i) => {
      const s = document.createElement('article');
      s.className = 'slide'; s.dataset.index = i;
      s.innerHTML = '<img src="' + r.img + '" alt="' + (r.subtitulo || r.titulo) + '">' +
        '<div class="slide__label"><div class="t">' + (r.subtitulo || r.titulo) + '</div><div class="d">' + r.data + '</div></div>';
      s.addEventListener('click', () => { if (i !== active) go(i); });
      track.appendChild(s);
      return s;
    });

    const GEO = {
      0: { x: 0, z: 0, ry: 0, sc: 1, blur: 0, op: 1, z_index: 40 },
      1: { x: 62, z: -150, ry: 24, sc: .82, blur: 5, op: .95, z_index: 30 },
      2: { x: 108, z: -300, ry: 30, sc: .64, blur: 9, op: .55, z_index: 20 }
    };
    function render() {
      slides.forEach((s, i) => {
        let pos = i - active;
        if (pos > N / 2) pos -= N;
        if (pos < -N / 2) pos += N;
        const dist = Math.abs(pos), g = GEO[Math.min(dist, 2)], dir = pos < 0 ? -1 : 1;
        s.style.transform = 'translateX(' + (dir * g.x) + '%) translateZ(' + g.z + 'px) rotateY(' + (-dir * g.ry) + 'deg) scale(' + g.sc + ')';
        s.style.filter = g.blur ? 'blur(' + g.blur + 'px) brightness(.8)' : 'none';
        s.style.opacity = dist > 2 ? 0 : g.op;
        s.style.zIndex = g.z_index;
        s.style.pointerEvents = dist > 2 ? 'none' : 'auto';
        s.classList.toggle('is-active', pos === 0);
      });
      updatePanel();
    }
    function updatePanel() {
      const r = ROTEIROS[active];
      panel.classList.add('is-switching');
      setTimeout(() => {
        els.sub.textContent = r.subtitulo; els.title.textContent = r.titulo;
        els.date.textContent = r.data; els.desc.textContent = r.desc; els.btn.href = r.href;
        panel.classList.remove('is-switching');
      }, 200);
    }
    function go(i) { active = ((i % N) + N) % N; render(); restartAuto(); }
    const next = () => go(active + 1), prev = () => go(active - 1);
    function startAuto() { stopAuto(); autoTimer = setInterval(next, AUTOPLAY_MS); }
    function stopAuto() { if (autoTimer) { clearInterval(autoTimer); autoTimer = null; } }
    function restartAuto() { startAuto(); }

    document.getElementById('nav-next').addEventListener('click', next);
    document.getElementById('nav-prev').addEventListener('click', prev);
    window.addEventListener('keydown', (e) => { if (e.key === 'ArrowRight') next(); if (e.key === 'ArrowLeft') prev(); });

    const rail = document.getElementById('rail');
    rail.addEventListener('mouseenter', stopAuto);
    rail.addEventListener('mouseleave', startAuto);
    let dragX = null;
    rail.addEventListener('pointerdown', (e) => { if (e.target.closest('.rail__nav')) return; dragX = e.clientX; stopAuto(); });
    window.addEventListener('pointerup', (e) => {
      if (dragX === null) return;
      const dx = e.clientX - dragX; dragX = null;
      if (Math.abs(dx) > 55) { dx < 0 ? next() : prev(); } else { startAuto(); }
    });

    render(); startAuto();
  }

  /* ---------- boot: banco → fallback ---------- */
  (async function () {
    let list = null;
    if (window.poFetchRoteiros) {
      const db = await window.poFetchRoteiros();
      if (db && db.length) list = db.map(mapDb);
    }
    initSlider(list || FALLBACK);
  })();
})();
