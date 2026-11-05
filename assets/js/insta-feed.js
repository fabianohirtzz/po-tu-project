/* ============================================================
   Instagram da Nossa Historia (22/10/2026), padrao Casa Angelina.

   A grade so tem capas (lib/po-social.php). Este arquivo:
   - no desktop (mouse), toca a previa MUDA do reels enquanto o mouse esta
     em cima, imitando o feed do Instagram;
   - no clique, abre o visualizador com o video COM SOM e controles, com
     setas para o anterior/proximo e o link "Ver no Instagram";
   - setas do carrossel.
   O video so baixa quando a pessoa interage: dez reels tocando sozinhos
   seriam dezenas de MB para quem esta no 4G.
   Sem JS, cada capa e um link para o post no Instagram.
============================================================ */
(function () {
  'use strict';
  var sec = document.getElementById('instagram');
  var lb = document.getElementById('ig-lb');
  if (!sec || !lb) return;

  var grade = sec.querySelector('.ig__grid');
  var itens = [].slice.call(sec.querySelectorAll('.ig__item'));
  var reduz = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  var comMouse = window.matchMedia && matchMedia('(hover: hover) and (pointer: fine)').matches;

  /* ---------- previa muda no hover (so desktop) ---------- */
  if (comMouse && !reduz) {
    itens.forEach(function (a) {
      var v = null;
      a.addEventListener('mouseenter', function () {
        if (!v) {
          v = document.createElement('video');
          v.className = 'ig__prev';
          v.muted = true; v.loop = true; v.playsInline = true; v.preload = 'auto';
          v.setAttribute('aria-hidden', 'true');
          v.src = a.getAttribute('data-video');
          a.appendChild(v);
        }
        var p = v.play(); if (p && p.catch) p.catch(function () {});
        a.classList.add('is-tocando');
      });
      a.addEventListener('mouseleave', function () {
        if (v) v.pause();
        a.classList.remove('is-tocando');
      });
    });
  }

  /* ---------- setas do carrossel ---------- */
  sec.querySelectorAll('[data-ig]').forEach(function (b) {
    b.addEventListener('click', function () {
      var passo = (itens[0] ? itens[0].getBoundingClientRect().width : 220) * 2;
      grade.scrollBy({ left: b.getAttribute('data-ig') === 'next' ? passo : -passo, behavior: reduz ? 'auto' : 'smooth' });
    });
  });

  /* ---------- visualizador ---------- */
  var video = lb.querySelector('.ig-lb__video');
  var link = lb.querySelector('.ig-lb__link');
  var atual = -1, voltarPara = null;

  function mostra(i) {
    atual = (i + itens.length) % itens.length;
    var a = itens[atual];
    video.src = a.getAttribute('data-video');
    video.poster = (a.querySelector('img') || {}).src || '';
    link.href = a.getAttribute('data-post');
    var p = video.play(); if (p && p.catch) p.catch(function () {});
  }
  function abre(i) {
    voltarPara = document.activeElement;
    lb.hidden = false;
    document.documentElement.classList.add('ig-lb-aberto');
    mostra(i);
    lb.querySelector('.ig-lb__close').focus();
  }
  function fecha() {
    video.pause();
    video.removeAttribute('src'); video.load(); // para o download
    lb.hidden = true;
    document.documentElement.classList.remove('ig-lb-aberto');
    if (voltarPara) voltarPara.focus();
  }

  itens.forEach(function (a, i) {
    a.addEventListener('click', function (e) {
      if (e.ctrlKey || e.metaKey || e.shiftKey) return; // abrir no Instagram em nova aba continua valendo
      e.preventDefault();
      var v = a.querySelector('.ig__prev'); if (v) v.pause();
      abre(i);
    });
  });
  lb.querySelector('.ig-lb__close').addEventListener('click', fecha);
  lb.querySelector('.ig-lb__nav--prev').addEventListener('click', function () { mostra(atual - 1); });
  lb.querySelector('.ig-lb__nav--next').addEventListener('click', function () { mostra(atual + 1); });
  lb.addEventListener('click', function (e) { if (e.target === lb) fecha(); });
  document.addEventListener('keydown', function (e) {
    if (lb.hidden) return;
    if (e.key === 'Escape') fecha();
    else if (e.key === 'ArrowRight') mostra(atual + 1);
    else if (e.key === 'ArrowLeft') mostra(atual - 1);
  });
})();
