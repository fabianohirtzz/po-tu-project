/* ============================================================
   Pereira Oliveira Turismo: memoria de ORIGEM do visitante.

   Carregado em toda pagina publica. Guarda no navegador da pessoa, por 90
   dias, de onde ela chegou: a UTM inteira, os ids de clique (gclid, gbraid,
   wbraid do Google; fbclid da Meta), o site que a mandou e a pagina em que
   ela entrou. O formulario (lead-form.js) le daqui na hora de enviar.

   POR QUE: antes o formulario so lia a URL da PROPRIA pagina. Quem chegava
   pelo anuncio em /roteiros/turquia, navegava e preenchia em /contato, ou
   voltava dias depois, virava lead sem gclid e sem UTM: o anuncio pagou o
   clique e a venda nao aparecia no ROAS nem podia voltar para o Google Ads.

   AS REGRAS (decide(), testada em tests/test-origem-site.mjs):
   - Campanha (UTM ou id de clique) so e trocada por outra campanha, e a mais
     nova entra INTEIRA: nunca misturar a UTM de uma visita com o gclid de
     outra (o painel classificaria pelo pedaco errado).
   - Um site de fora (busca, ChatGPT) que mandou a visita conta, mas nunca
     apaga campanha: quem clicou no anuncio e voltou pela busca para fechar
     veio do anuncio.
   - Visita direta e navegacao interna nao apagam nada.
   - 90 dias: a janela em que o Google Ads ainda aceita o gclid.
   Sem armazenamento (aba anonima, bloqueio), vale o que a propria visita
   trouxe, que e o que o formulario sempre mandou. Nada aqui pode quebrar a
   pagina nem o envio do lead.

   WHATSAPP (fase 3A): o botao de WhatsApp abre o app sem nada da URL. Quando
   a visita tem CAMPANHA, o clique leva no texto um codigo curto,
   "(ref G-KTRBX)", e registra no /clique.php a origem inteira com esse
   codigo; o painel liga o codigo ao lead quando a mensagem chega. Visita sem
   campanha abre o WhatsApp exatamente como antes.
   O codigo e SO CONSOANTE de proposito: sem vogal ele nunca forma palavra
   (nao casa com destino, roteiro nem atalho do robo) e sem digito nunca e
   lido como "escolhi o roteiro 7" (tests/test-origem-whatsapp.php roda o
   texto contra as funcoes de verdade do robo).
============================================================ */
(function () {
  'use strict';
  var CHAVE = 'po_origem';
  var VALIDADE = 90 * 864e5;
  var MAX = 500;   // texto vindo de fora: teto por campo antes de ir ao banco
  var UTM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
  var CLIQUES = ['gclid', 'gbraid', 'wbraid', 'fbclid'];
  var CAMPOS = UTM.concat(CLIQUES, ['referrer', 'landing_page']);
  var CONSOANTES = 'BCDFGHJKLMNPQRSTVWXZ';
  var CODIGO = /^[GMS]-[BCDFGHJKLMNPQRSTVWXZ]{5}$/;
  var WHATSAPP = /^https:\/\/wa\.me\/5548996048882(?:[/?#]|$)/;
  var TEXTO = 'Olá! Vim pelo site e quero mais informações.';

  function corta(v) {
    v = String(v == null ? '' : v).trim();
    return v.length > MAX ? v.slice(0, MAX) : v;
  }
  function hostDe(url) {
    try { return new URL(String(url || '')).hostname.toLowerCase().replace(/^www\./, ''); }
    catch (e) { return ''; }
  }
  // O proprio site e o preview nao sao origem: e so a pessoa navegando.
  function interno(host) {
    return /(^|\.)(pereiraoliveiraturismo\.com\.br|github\.io)$/.test(host) || host === 'localhost' || host === '127.0.0.1';
  }

  /* O que ESTA visita traz. nivel 2 = campanha (UTM ou id de clique),
     1 = so um site de fora mandou, 0 = nada (direta ou interna). */
  function daVisita(href, ref, agora) {
    var q;
    try { q = new URL(String(href || '')).searchParams; } catch (e) { q = new URLSearchParams(''); }
    var o = { ts: agora };
    UTM.concat(CLIQUES).forEach(function (k) { o[k] = corta(q.get(k)); });
    var host = hostDe(ref);
    o.referrer = host && !interno(host) ? corta(ref) : '';
    o.landing_page = corta(href);
    var campanha = UTM.concat(CLIQUES).some(function (k) { return o[k] !== ''; });
    o.nivel = campanha ? 2 : (o.referrer ? 1 : 0);
    return o;
  }

  // Guardado que nao serve conta como vazio: corrompido, vencido ou do futuro.
  function valido(g, agora) {
    if (!g || typeof g !== 'object' || typeof g.ts !== 'number') return null;
    if (g.nivel !== 1 && g.nivel !== 2) return null;
    if (agora - g.ts > VALIDADE || g.ts - agora > 864e5) return null;
    return g;
  }

  // A mesma campanha aberta de novo (recarregar a pagina do anuncio) e a mesma.
  function mesmaCampanha(a, b) {
    return UTM.concat(CLIQUES).every(function (k) { return (a[k] || '') === (b[k] || ''); });
  }

  function decide(guardado, visita, agora) {
    var g = valido(guardado, agora);
    if (!visita || !visita.nivel) return g;
    if (!g || visita.nivel >= g.nivel) {
      // Mantem o codigo do WhatsApp quando a campanha e a mesma: um codigo
      // novo a cada recarga deixaria cliques orfaos para o casamento por horario.
      if (g && g.codigo && visita.nivel === 2 && g.nivel === 2 && mesmaCampanha(g, visita)) visita.codigo = g.codigo;
      return visita;
    }
    return g;
  }

  /* G = Google Ads, M = Meta Ads, S = outra campanha. So uma pista para quem
     le a conversa no celular: quem classifica de verdade e o painel, e o
     teste exige que os dois concordem caso a caso. */
  function plataforma(g) {
    var src = String(g.utm_source || '').toLowerCase(), med = String(g.utm_medium || '').toLowerCase();
    var pago = /cpc|ppc|paid|ads/.test(med);
    if (g.gclid || g.gbraid || g.wbraid || (pago && /google|adwords|youtube/.test(src))) return 'G';
    if (pago && (/facebook|instagram|meta/.test(src) || /^(fb|ig|msg|an)$/.test(src))) return 'M';
    return 'S';
  }
  function novoCodigo(g, sorteio) {
    var s = '';
    for (var i = 0; i < 5; i++) s += CONSOANTES[sorteio(i) % CONSOANTES.length];
    return plataforma(g) + '-' + s;
  }
  function sorteia() {
    var c = window.crypto && window.crypto.getRandomValues ? window.crypto.getRandomValues(new Uint32Array(5)) : null;
    return function (i) { return c ? c[i] : Math.floor(Math.random() * 4294967296); };
  }

  function ler() {
    try { return JSON.parse(window.localStorage.getItem(CHAVE) || 'null'); } catch (e) { return null; }
  }
  function gravar(o) {
    try { window.localStorage.setItem(CHAVE, JSON.stringify(o)); } catch (e) { /* sem memoria: vale a visita */ }
  }

  var visitaAtual = null;
  try {
    var agora = Date.now();
    visitaAtual = daVisita(window.location.href, document.referrer, agora);
    var antes = ler();
    var depois = decide(antes, visitaAtual, agora);
    if (depois && depois !== antes) gravar(depois);
  } catch (e) { /* a pagina segue; o formulario cai no que a URL tem */ }

  /* Os campos que vao com o lead, com os nomes das colunas do po_leads. */
  function campos() {
    var o = {}, g = null;
    try { g = valido(ler(), Date.now()); } catch (e) { g = null; }
    if (g) {
      CAMPOS.forEach(function (k) { o[k] = corta(g[k]); });
      return o;
    }
    /* Sem memoria (direto, vencida, ou armazenamento bloqueado): o que a
       pagina tem agora, como sempre foi. Na pagina de chegada do anuncio isso
       ja inclui o gclid da URL. */
    var cru = daVisita(window.location.href, '', 0);
    CAMPOS.forEach(function (k) { o[k] = cru[k] || ''; });
    o.referrer = corta(document.referrer);
    return o;
  }

  /* A origem com campanha que vale agora, ou null. Sem armazenamento, a da
     propria visita (o codigo fica so na memoria desta pagina). */
  function campanhaAtual() {
    var g = null;
    try { g = valido(ler(), Date.now()); } catch (e) { g = null; }
    if (!g && visitaAtual && visitaAtual.nivel === 2) g = visitaAtual;
    return g && g.nivel === 2 ? g : null;
  }
  function codigo() {
    var g = campanhaAtual();
    if (!g) return null;
    if (!CODIGO.test(String(g.codigo || ''))) {
      g.codigo = novoCodigo(g, sorteia());
      if (g !== visitaAtual) gravar(g);
    }
    return g.codigo;
  }

  /* O link do WhatsApp com o codigo no texto. Texto que o link ja tiver e
     mantido; o codigo vai no fim, entre parenteses. */
  function linkComCodigo(href, cod) {
    var t = '';
    try { t = new URL(href).searchParams.get('text') || ''; } catch (e) { t = ''; }
    if (!t) t = TEXTO;
    if (t.indexOf('(ref ') < 0) t += ' (ref ' + cod + ')';
    return 'https://wa.me/5548996048882?text=' + encodeURIComponent(t);
  }

  // A origem do clique vai para o servidor; a pagina nao espera resposta.
  function registra(cod) {
    try {
      var g = campanhaAtual() || {};
      var f = new FormData();
      f.append('codigo', cod);
      CAMPOS.forEach(function (k) { f.append(k, corta(g[k])); });
      f.append('pagina', corta(window.location.href));
      if (window.navigator && window.navigator.sendBeacon) window.navigator.sendBeacon('/clique.php', f);
      else if (window.fetch) window.fetch('/clique.php', { method: 'POST', body: f, keepalive: true });
    } catch (e) { /* o WhatsApp abre do mesmo jeito */ }
  }

  /* Na captura, antes da navegacao: troca o href do link clicado. Qualquer
     falha aqui deixa o link como estava, e o WhatsApp abre normalmente. */
  function aoClicar(ev) {
    try {
      var a = ev.target && ev.target.closest ? ev.target.closest('a[href]') : null;
      if (!a || !WHATSAPP.test(a.href)) return;
      var cod = codigo();
      if (!cod) return;   // visita sem campanha: o link como sempre foi
      a.href = linkComCodigo(a.href, cod);
      registra(cod);
    } catch (e) { /* segue o link original */ }
  }
  try { document.addEventListener('click', aoClicar, true); } catch (e) { /* sem DOM */ }

  window.poOrigem = {
    campos: campos, codigo: codigo, CAMPOS: CAMPOS.slice(), TEXTO: TEXTO,
    _nucleo: { daVisita: daVisita, decide: decide, valido: valido, plataforma: plataforma,
               novoCodigo: novoCodigo, linkComCodigo: linkComCodigo, CODIGO: CODIGO }
  };
})();
