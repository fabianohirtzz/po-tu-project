/* ============================================================
   Pereira Oliveira Turismo — Home
   Focus-rail (coverflow) vanilla. Os roteiros vêm do Supabase
   (po_roteiros); se indisponível, usa o fallback fixo abaixo.
============================================================ */
(function () {
  'use strict';

  /* ---------- vídeo de fundo ----------
     Entra com fade só quando já dá para tocar; até lá quem preenche a tela é o
     poster, que é fundo da .video-bg. Quem pediu "reduzir animações" no sistema
     fica com o poster: o vídeo sai e não chega a baixar. */
  (function videoFundo() {
    const v = document.querySelector('.video-bg video');
    if (!v) return;
    let reduz = false;
    try { reduz = matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) {}
    if (reduz) { v.remove(); return; }
    const mostra = () => v.classList.add('is-ready');
    if (v.readyState >= 3) mostra();
    else v.addEventListener('canplay', mostra, { once: true });
    // Aba aberta em background faz o navegador rejeitar o play(). O poster segue
    // na tela e o autoplay pega quando a aba ganhar foco: não há o que tratar.
    const p = v.play();
    if (p && p.catch) p.catch(function () {});
  })();

  const track = document.getElementById('rail-track');
  const panel = document.getElementById('panel');
  const els = {
    sub: document.getElementById('p-sub'), title: document.getElementById('p-title'),
    date: document.getElementById('p-date'), desc: document.getElementById('p-desc'), btn: document.getElementById('p-btn')
  };

  /* O painel nasce com a chamada institucional e os roteiros só chegam depois da
     resposta do banco. Esconder o texto agora evita que a chamada apareça e troque
     na cara do visitante; o primeiro render revela já com o roteiro certo.
     Esconder daqui, e não no HTML, é de propósito: sem JS o texto continua visível. */
  if (panel) panel.classList.add('is-switching');

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

  /* extrai a data de partida do rótulo p/ ordenar o carrossel pela viagem.
     Ex.: "11 dias · 10 a 21/12/2025" → 10/12/2025. Sem data reconhecida vai pro fim. */
  const MESES = { jan:0, fev:1, mar:2, abr:3, mai:4, jun:5, jul:6, ago:7, set:8, out:9, nov:10, dez:11 };
  function tripTime(data) {
    const s = String(data || '');
    // "10 a 21/12/2025" → dia inicial com mês/ano do fim
    let m = s.match(/(\d{1,2})\s*a\s*\d{1,2}\/(\d{1,2})\/(\d{4})/);
    if (m) return new Date(+m[3], +m[2] - 1, +m[1]).getTime();
    // qualquer DD/MM/YYYY (usa o primeiro)
    m = s.match(/(\d{1,2})\/(\d{1,2})\/(\d{4})/);
    if (m) return new Date(+m[3], +m[2] - 1, +m[1]).getTime();
    // "25 de novembro ... de 2026" (formato periodo)
    m = s.match(/(\d{1,2})\s+de\s+([a-zç]+)\D+(\d{4})/i);
    if (m) {
      const mes = MESES[m[2].slice(0, 3).toLowerCase()];
      if (mes != null) return new Date(+m[3], mes, +m[1]).getTime();
    }
    return Infinity;
  }
  function ordenarPorData(list) {
    return list.slice().sort((a, b) => tripTime(a.data) - tripTime(b.data));
  }

  function initSlider(ROTEIROS) {
    // sem roteiros (banco fora do ar) a home fica só com a chamada institucional:
    // melhor não ter carrossel do que anunciar viagem que não existe mais.
    if (!track || !ROTEIROS.length) { if (panel) panel.classList.remove('is-switching'); return; }
    const N = ROTEIROS.length;
    let active = 0, autoTimer = null;
    const AUTOPLAY_MS = 5200;

    const slides = ROTEIROS.map((r, i) => {
      const s = document.createElement('article');
      s.className = 'slide'; s.dataset.index = i;
      // separa "N dias · data" em dias + data; o CSS decide o formato por tela
      // (desktop/tablet: subtítulo + "N dias · data" inline; celular: dias e data em 2 linhas)
      const parts = String(r.data || '').split(' · ');
      const days = parts.length > 1 ? parts[0] : '';
      const date = parts.length > 1 ? parts.slice(1).join(' · ') : (parts[0] || '');
      const dLabel = days
        ? '<span class="d-days">' + days + '</span><span class="d-sep"> · </span><span class="d-date">' + date + '</span>'
        : '<span class="d-date">' + date + '</span>';
      s.innerHTML = '<img src="' + r.img + '" alt="' + (r.subtitulo || r.titulo) + '">' +
        '<div class="slide__label"><div class="t">' + (r.subtitulo || r.titulo) + '</div><div class="d">' + dLabel + '</div></div>';
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
    let primeiroRender = true;
    function updatePanel() {
      const r = ROTEIROS[active];
      const preencher = () => {
        els.sub.textContent = r.subtitulo; els.title.textContent = r.titulo;
        els.date.textContent = r.data; els.desc.textContent = r.desc; els.btn.href = r.href;
        panel.classList.remove('is-switching');
      };
      // o painel já está escondido desde o boot: preenche e revela, sem fade-out
      if (primeiroRender) { primeiroRender = false; preencher(); return; }
      panel.classList.add('is-switching');
      setTimeout(preencher, 200);
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

  /* ---------- boot: o banco é a única fonte dos roteiros ----------
     Sem lista fixa aqui de propósito: um fallback escrito na mão envelhece sem
     ninguém perceber e reapresenta roteiro já excluído ou com data vencida. */
  (async function () {
    let list = [];
    if (window.poFetchRoteiros) {
      const db = await window.poFetchRoteiros();
      if (db && db.length) list = db.map(mapDb);
    }
    initSlider(ordenarPorData(list));
  })();
})();
