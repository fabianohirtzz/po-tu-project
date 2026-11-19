/* ============================================================
   Pereira Oliveira Turismo: eventos de ANUNCIO do site (fase 4 do rastreio).

   Conta, para quem paga o anuncio, as duas coisas do site que valem
   dinheiro:
     - o formulario ENVIADO de verdade (o lead-form.js avisa com o evento
       'po:lead', e so depois que o lead gravou);
     - o toque no WhatsApp da agencia.
   E manda para tres destinos, cada um independente dos outros:
     - Pixel da Meta: PageView em toda pagina, ViewContent na pagina de
       roteiro, Lead no formulario, Contact no WhatsApp;
     - Google Ads: uma conversao para o formulario e outra para o WhatsApp,
       cada uma pelo seu rotulo;
     - GA4: generate_lead e clique_whatsapp, para o G-MBJHCTJZWZ que ja vem
       dentro da tag GT-TNH4L3BV de toda pagina (conferido no container em
       13/11/2026: o unico destino dela e esse GA4, nenhuma conta do Google
       Ads). O Google Ads tambem sabe importar esses dois como conversao.

   INERTE ATE OS IDS EXISTIREM. Com os IDS vazios nada da Meta carrega
   (nenhum fbevents.js, nenhum cookie _fbp) e nada vai para o Google Ads; so
   o GA4, que ja estava na pagina e ja esta na politica de privacidade,
   recebe os dois eventos.

   SO NO DOMINIO DE PRODUCAO. localhost, o preview do GitHub Pages e
   qualquer outro endereco nao medem nada: um teste do formulario na maquina
   de quem desenvolve viraria conversao, e o lance automatico do Google Ads
   aprende com conversao falsa.

   Nada aqui pode quebrar a pagina, o formulario nem o WhatsApp.
   Teste: tests/test-rastreio.mjs.
============================================================ */
(function () {
  'use strict';

  /* ---- PREENCHER AQUI --------------------------------------------------
     Vazio = desligado, e cada um liga sozinho, sem depender dos outros.
     Formato errado tambem fica desligado no site, e a suite fica VERMELHA
     dizendo o formato certo.
     ANTES de preencher: a politica de privacidade tem que declarar o Pixel
     da Meta e o Google Ads (hoje ela so cita o Google Analytics). A suite
     recusa ID preenchido sem isso.
     DEPOIS: suba o ?v= do rastreio.js em TODAS as paginas e suba o arquivo
     no FTP (tests/test-cache-buster-site.mjs diz o sha e as paginas). */
  var IDS = {
    META_PIXEL: '',            // Gerenciador de Eventos > o conjunto de dados > ID (so numeros)
    GOOGLE_ADS: '',            // a parte ANTES da barra no send_to da tag de conversao: 'AW-123456789'
    GOOGLE_ADS_LEAD: '',       // a parte DEPOIS da barra, na conversao do formulario
    GOOGLE_ADS_WHATSAPP: ''    // a parte DEPOIS da barra, na conversao do clique no WhatsApp
  };
  /* --------------------------------------------------------------------- */

  var FORMATO = {
    META_PIXEL: /^\d{10,20}$/,
    GOOGLE_ADS: /^AW-\d{6,12}$/,
    GOOGLE_ADS_LEAD: /^[\w-]{6,40}$/,
    GOOGLE_ADS_WHATSAPP: /^[\w-]{6,40}$/
  };
  // O WhatsApp da agencia: a MESMA expressao do origem.js (o teste confere).
  var WHATSAPP = /^https:\/\/wa\.me\/5548996048882(?:[/?#]|$)/;
  var ROTEIRO = /^\/roteiros\/([a-z0-9-]+)\/?$/;
  var PRODUCAO = /^(www\.)?pereiraoliveiraturismo\.com\.br$/;
  var MAX = 150;

  if (window.poRastreio) return;   // pagina que carregou o arquivo duas vezes mede uma vez so

  function id(nome) {
    var v = String(IDS[nome] == null ? '' : IDS[nome]).trim();
    return FORMATO[nome].test(v) ? v : '';
  }
  function corta(v) {
    v = String(v == null ? '' : v).replace(/\s+/g, ' ').trim();
    return v.length > MAX ? v.slice(0, MAX) : v;
  }

  var host = '';
  try { host = String(window.location.hostname || '').toLowerCase(); } catch (e) { host = ''; }
  var producao = PRODUCAO.test(host);

  var PIXEL = producao ? id('META_PIXEL') : '';
  var AW = producao ? id('GOOGLE_ADS') : '';
  // Rotulo sem a conta nao mede nada: o send_to precisa das duas partes.
  var LEAD = AW ? id('GOOGLE_ADS_LEAD') : '';
  var WA = AW ? id('GOOGLE_ADS_WHATSAPP') : '';

  /* Para o teste e para conferir no console do navegador (F12):
     poRastreio.ligado diz o que esta medindo nesta pagina. */
  window.poRastreio = {
    IDS: { META_PIXEL: IDS.META_PIXEL, GOOGLE_ADS: IDS.GOOGLE_ADS,
           GOOGLE_ADS_LEAD: IDS.GOOGLE_ADS_LEAD, GOOGLE_ADS_WHATSAPP: IDS.GOOGLE_ADS_WHATSAPP },
    FORMATO: FORMATO,
    ligado: { producao: producao, meta: !!PIXEL, googleAds: !!AW,
              conversaoFormulario: !!LEAD, conversaoWhatsapp: !!WA }
  };
  if (!producao) return;

  function meta(evento, params) {
    if (!PIXEL) return;
    try { window.fbq('track', evento, params || {}); } catch (e) { /* a pagina segue */ }
  }
  function google(nome, params) {
    try { if (typeof window.gtag === 'function') window.gtag('event', nome, params || {}); } catch (e) { /* a pagina segue */ }
  }
  function conversao(rotulo) {
    if (AW && rotulo) google('conversion', { send_to: AW + '/' + rotulo });
  }

  /* ---- ao abrir a pagina ---- */

  // Pixel da Meta: o mesmo carregador do codigo oficial (sem o <noscript>).
  if (PIXEL) {
    try {
      if (typeof window.fbq !== 'function') {
        var n = window.fbq = function () {
          if (n.callMethod) n.callMethod.apply(n, arguments); else n.queue.push(arguments);
        };
        if (!window._fbq) window._fbq = n;
        n.push = n; n.loaded = true; n.version = '2.0'; n.queue = [];
        var s = document.createElement('script');
        s.async = true;
        s.src = 'https://connect.facebook.net/en_US/fbevents.js';
        (document.head || document.documentElement).appendChild(s);
      }
      window.fbq('init', PIXEL);
      window.fbq('track', 'PageView');
    } catch (e) { PIXEL = ''; }
  }

  /* Google Ads: a tag GT-TNH4L3BV nao traz conta do Google Ads, entao a
     conta entra aqui. Isso tambem liga o vinculador de conversao, que guarda
     o gclid da chegada num cookie proprio do site. */
  if (AW) {
    try { if (typeof window.gtag === 'function') window.gtag('config', AW); } catch (e) { /* segue */ }
  }

  // Pagina de roteiro: o slug e a identidade da viagem; o nome e o do <h1>.
  try {
    var m = ROTEIRO.exec(String(window.location.pathname || ''));
    if (m) {
      var h1 = document.querySelector('h1');
      meta('ViewContent', { content_name: corta(h1 && h1.textContent) || m[1], content_ids: [m[1]], content_category: 'roteiro' });
    }
  } catch (e) { /* segue */ }

  /* ---- o que vale dinheiro ---- */
  try {
    // Lead que gravou. O aviso traz so o roteiro: nenhum dado pessoal sai daqui.
    document.addEventListener('po:lead', function (ev) {
      try {
        var roteiro = corta(ev && ev.detail && ev.detail.roteiro);
        meta('Lead', roteiro ? { content_name: roteiro } : {});
        conversao(LEAD);
        google('generate_lead', roteiro ? { roteiro: roteiro } : {});
      } catch (e) { /* o lead ja gravou: a medicao nunca atrapalha */ }
    });

    // Toque no WhatsApp da agencia (captura: conta antes de o app abrir).
    document.addEventListener('click', function (ev) {
      try {
        var a = ev.target && ev.target.closest ? ev.target.closest('a[href]') : null;
        if (!a || !WHATSAPP.test(String(a.href || ''))) return;
        meta('Contact', {});
        conversao(WA);
        google('clique_whatsapp', {});
      } catch (e) { /* o WhatsApp abre do mesmo jeito */ }
    }, true);
  } catch (e) { /* sem DOM */ }
})();
