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
})();
