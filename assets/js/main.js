/* Pereira Oliveira Turismo — scripts base.
   Ainda mínimo. Carrossel, formulário e interações serão construídos em conjunto. */
(function(){
  // header ganha fundo sólido ao rolar (placeholder de comportamento)
  const hd = document.querySelector('.hd');
  const onScroll = () => {
    if(!hd) return;
    hd.style.background = window.scrollY > 40
      ? 'rgba(20,20,20,.92)'
      : 'linear-gradient(to bottom,rgba(0,0,0,.45),transparent)';
  };
  window.addEventListener('scroll', onScroll, {passive:true});
  onScroll();
})();
