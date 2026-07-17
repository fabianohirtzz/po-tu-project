/* ============================================================
   Pereira Oliveira Turismo: Página de Roteiro
   Menu mobile · reveal · vídeo capa (hero) · lightbox da galeria ·
   carrossel "outros roteiros" (lê os cards que o servidor já pintou no
   DOM, não inventa nenhum). O formulário fica no lead-form.js. Pode
   rodar sozinho (páginas estáticas) ou ser chamado por
   roteiro-dynamic.js (window.initRoteiro()).
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

  /* ---------- hero: vídeo capa (autoplay, mudo, loop, sem controles) ----------
     Só fica visível quando já dá para tocar: até lá quem segura a tela é o
     .hero__poster (a capa), então o LCP nunca espera o vídeo.
     O <video> já nasce com autoplay: o play() abaixo é só a rede de segurança
     para o caso de o atributo ser ignorado. */
  function wireHero(v) {
    const mostra = () => v.classList.add('is-ready');
    if (v.readyState >= 3) mostra();
    else v.addEventListener('canplay', mostra, { once: true });
    // Aba aberta em background faz o navegador rejeitar o play(). A capa fica na
    // tela e o autoplay pega quando a aba ganhar foco: não há o que tratar.
    const p = v.play();
    if (p && p.catch) p.catch(function () {});
  }
  document.querySelectorAll('.hero__vid').forEach(wireHero);

  /* ---------- reels do Instagram (seção "por que viajar") ----------
     Nunca toca sozinho: o poster é a capa e o vídeo só baixa no clique
     (preload="none"). A tag e o botão de play somem no play e voltam no
     pause. A tela cheia é própria: ver .intro__media:fullscreen no CSS. */
  const wireReels = (fig) => {
    const v = fig.querySelector('video');
    if (!v || fig.dataset.wired) return;
    fig.dataset.wired = '1';
    const btn = fig.querySelector('.intro__play');
    const fs  = fig.querySelector('.intro__fs');

    if (btn) btn.addEventListener('click', () => { v.paused ? v.play() : v.pause(); });

    v.addEventListener('play', () => {
      fig.classList.add('is-playing', 'has-played');
      v.controls = true;  // controles nativos: só depois do play, p/ não sujar o poster
    });
    v.addEventListener('pause', () => fig.classList.remove('is-playing'));
    v.addEventListener('ended', () => { fig.classList.remove('is-playing'); v.currentTime = 0; });

    if (fs) fs.addEventListener('click', () => {
      if (document.fullscreenElement || document.webkitFullscreenElement) {
        (document.exitFullscreen || document.webkitExitFullscreen).call(document);
        return;
      }
      const req = fig.requestFullscreen || fig.webkitRequestFullscreen;
      // iOS não deixa um <div> entrar em tela cheia; lá o próprio vídeo assume.
      if (req) req.call(fig);
      else if (v.webkitEnterFullscreen) v.webkitEnterFullscreen();
    });
  };
  document.querySelectorAll('.intro__media').forEach(wireReels);

  /* Páginas estáticas: o HTML nasce com a capa parada (bom p/ SEO e p/ o LCP) e
     recebe os vídeos que o painel tiver: o do topo e o reels. A dinâmica já
     nasce certa, então não entra aqui. Sem rede ou sem vídeo, a capa fica, que
     é exatamente o fallback desejado. Uma consulta só alimenta os dois. */
  const staticFig = document.querySelector('.intro__photo');
  const heroMedia = document.querySelector('.hero__media');
  const slugEl = document.getElementById('more-track');
  const slug = slugEl && slugEl.dataset.current;
  // #rt-main só existe na roteiro.html (dinâmica), que já renderiza tudo a
  // partir do banco. Sem esta guarda ela refaria a consulta à toa.
  const ehDinamica = !!document.getElementById('rt-main');
  if (slug && !ehDinamica && window.poFetchRoteiro) {
    window.poFetchRoteiro(slug).then((r) => {
      if (!r) return;

      // topo: o vídeo capa entra por cima da capa parada, se houver um
      if (heroMedia && !heroMedia.querySelector('.hero__vid') && window.poHeroVideo) {
        const html = window.poHeroVideo(r);
        if (html) {
          heroMedia.insertAdjacentHTML('beforeend', html);
          wireHero(heroMedia.querySelector('.hero__vid'));
        }
      }

      // "por que viajar": a capa vira o reels, se houver um
      if (staticFig && r.video_insta_url && window.poIntroMedia) {
        // Preserva a capa e o alt do HTML: o banco pode ter capa_url diferente.
        const img = staticFig.querySelector('img');
        const fig = document.createRange().createContextualFragment(window.poIntroMedia({
          video_insta_url: r.video_insta_url,
          capa_url: (img && img.getAttribute('src')) || r.capa_url,
          titulo: r.titulo
        })).firstElementChild;
        fig.classList.add('is-in');  // já está na tela; não espera o reveal
        staticFig.replaceWith(fig);
        wireReels(fig);
      }
    });
  }

  /* ---------- galeria: lightbox ---------- */
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

  /* ---------- outros roteiros: carrossel ----------
     Server-side agora (roteiro.php / cada página estática já pinta os
     <article class="rot-card"> reais dentro de #more-track, link interno é
     sinal de SEO e não pode depender de JavaScript). Este bloco não cria
     mais nenhum card: só lê os que o HTML já tem e liga setas/swipe/tilt/
     contador. Sem PHP/banco na página (ex.: preview estático sem os cards),
     o track fica vazio e o carrossel simplesmente não aparece, nunca mais
     inventa roteiro (um deles já foi excluído do catálogo e ficava
     reaparecendo escrito à mão aqui). */
  const track = document.getElementById('more-track');
  if (track && !track.dataset.wired) {
    track.dataset.wired = '1';
    const cards = Array.from(track.children);
    if (cards.length) {
      const viewport = track.parentElement;
      const prevBtn = document.getElementById('more-prev');
      const nextBtn = document.getElementById('more-next');
      const counter = document.getElementById('more-count');

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
    }
  }
}

window.initRoteiro = initRoteiro;
if (!window.__deferRoteiroInit) initRoteiro();
