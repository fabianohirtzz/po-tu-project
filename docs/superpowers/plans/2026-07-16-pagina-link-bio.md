# Página de links da bio (`/link`) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Criar `pereiraoliveiraturismo.com.br/link`, a página de destino da bio do Instagram: coluna única no tom de papel da Nossa História, com fundo de mapa-múndi em SVG, botão de lead, WhatsApp, os roteiros lidos do banco ao vivo e o selo de avaliações do Google.

**Architecture:** Página estática standalone (`link.html` + `assets/css/link.css` + `assets/js/link.js`), no mesmo padrão da `nossa-historia.html`. Não toca em nenhum arquivo existente exceto `.htaccess` (rewrite de `/link`) e `CLAUDE.md`. Os cards de roteiro vêm do Supabase via `roteiros-shared.js`, que a home já usa.

**Tech Stack:** HTML/CSS/JS vanilla, sem build. `@supabase/supabase-js@2` via CDN. Google Fonts (Rubik, DM Sans, DM Mono). Apache/cPanel.

**Spec:** `docs/superpowers/specs/2026-07-16-pagina-link-bio-design.md`

## Global Constraints

- **Este projeto não tem runner de teste.** Não existe `package.json`, `playwright.config`, nem pasta `tests/`. Não crie um. A verificação é browser (Playwright MCP) + `curl`, como no resto do projeto.
- **Copy:** português, sem travessão, sem emoji, números concretos, tom de confiança e tradição sem exagero vazio. Os textos estão fixados na spec — **não invente copy**.
- **NUNCA escreva dado de roteiro no HTML.** Nem como fallback. Isso causou o flash do roteiro excluído na home (CLAUDE.md, 16/07/2026). Sem banco = sem card.
- **WhatsApp:** sempre `https://wa.me/5548996048882`, **com o 55**. Nunca `wa.me/48996048882`.
- **Link do Google:** `https://www.google.com/maps?cid=10478700579601202326`. Nunca `share.google/...`.
- **`prefers-reduced-motion: reduce`** desliga toda animação. Regra do site inteiro.
- **Toda `<img>` precisa de `alt`.** Todo link externo: `target="_blank" rel="noopener"`.
- **Testar com latência.** `localhost` responde instantâneo e esconde bug de ordem de pintura. Use CDP `Network.emulateNetworkConditions` com 400ms.
- **Armadilha do Playwright:** o perfil do Chrome já teve mock interceptando o Supabase e fez teste passar com dado falso. Se o browser divergir do `curl`, rode `context.unrouteAll()` e confira se a resposta tem os cabeçalhos `sb-project-ref`/`CF-Ray`.
- **Servidor local:** `python -m http.server 8080` na raiz do repo. `http://localhost:8080/link.html`.

## File Structure

| Arquivo | Responsabilidade |
|---|---|
| `link.html` (criar) | Marcação da página: head/SEO, SVG do mapa, cabeçalho, selo Google, números, botões, `<ul id="link-roteiros">` vazia, redes, rodapé |
| `assets/css/link.css` (criar) | Todo o estilo da página. Standalone, não importa `historia.css` |
| `assets/js/link.js` (criar) | Contagem dos anos, fetch dos roteiros, render dos cards, UTM |
| `.htaccess` (modificar) | Rewrite `/link` → `/link.html` |
| `CLAUDE.md` (modificar) | Seção Estado atual |

`sitemap.xml` **não muda** — a página é `noindex` e fica fora dele de propósito.

---

### Task 1: Esqueleto, cabeçalho, selo do Google e números

Entrega: a página abre no papel morno, com logo, selo do Google, headline e os números contando. Sem botões e sem cards ainda.

**Files:**
- Create: `link.html`
- Create: `assets/css/link.css`
- Create: `assets/js/link.js`

**Interfaces:**
- Consumes: nada
- Produces: `link.html` com `<div class="lk">` como coluna; `assets/js/link.js` como IIFE com a função `countUp(el)`, que lê `data-count` e `data-count-from` e é reusada na Task 4

- [ ] **Step 1: Criar `link.html`**

```html
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <!-- Google tag (gtag.js) -->
  <script async src="https://www.googletagmanager.com/gtag/js?id=GT-TNH4L3BV"></script>
  <script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','GT-TNH4L3BV');</script>
  <title>Links — Pereira Oliveira Turismo</title>
  <meta name="description" content="Roteiros internacionais em grupo, com data marcada. Reserve pelo formulário ou fale com a equipe no WhatsApp.">
  <!-- noindex: pagina de destino de bio, conteudo fino e duplicado do site.
       Indexar so criaria concorrencia com a home e as paginas de roteiro. -->
  <meta name="robots" content="noindex, follow">
  <meta name="theme-color" content="#F3F0E9">
  <link rel="icon" href="assets/images/favicon.png">
  <link rel="apple-touch-icon" href="assets/images/favicon.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Rubik:wght@400;500;600;700&family=DM+Sans:wght@400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/link.css?v=1">
</head>
<body>

  <main class="lk">

    <header class="lk__head">
      <a href="index.html" class="lk__logo">
        <img src="assets/images/logo.png" alt="Pereira Oliveira Turismo" width="180" height="44">
      </a>
      <p class="lk__tag">Realizando sonhos desde 1967</p>

      <a class="lk__google" href="https://www.google.com/maps?cid=10478700579601202326" target="_blank" rel="noopener" aria-label="Ver avaliações no Google, nota 5,0">
        <svg class="lk__google-ic" viewBox="0 0 24 24" aria-hidden="true">
          <path fill="#4285F4" d="M23.5 12.3c0-.8-.1-1.6-.2-2.3H12v4.5h6.5a5.6 5.6 0 0 1-2.4 3.6v3h3.9c2.3-2.1 3.5-5.2 3.5-8.8Z"/>
          <path fill="#34A853" d="M12 24c3.2 0 5.9-1.1 7.9-2.9l-3.9-3a7.2 7.2 0 0 1-10.7-3.8H1.3v3.1A12 12 0 0 0 12 24Z"/>
          <path fill="#FBBC05" d="M5.3 14.3a7.1 7.1 0 0 1 0-4.6V6.6H1.3a12 12 0 0 0 0 10.8l4-3.1Z"/>
          <path fill="#EA4335" d="M12 4.8c1.8 0 3.4.6 4.6 1.8l3.5-3.5A12 12 0 0 0 1.3 6.6l4 3.1A7.2 7.2 0 0 1 12 4.8Z"/>
        </svg>
        <span class="lk__google-sep" aria-hidden="true"></span>
        <span class="lk__stars" aria-hidden="true">★★★★★</span>
        <b class="lk__google-n">5,0</b>
      </a>

      <h1 class="lk__h1">Viaje em grupo.<br><em>Desde 1967.</em></h1>
      <p class="lk__sub">Roteiros internacionais com data marcada e grupo acompanhado.</p>

      <ul class="lk__nums">
        <li><b><span data-count="58" data-count-from="1967">58</span></b><span>anos</span></li>
        <li><b>3</b><span>gerações</span></li>
        <li><b>+35</b><span>países</span></li>
      </ul>
    </header>

  </main>

  <script src="assets/js/link.js?v=1"></script>
</body>
</html>
```

- [ ] **Step 2: Criar `assets/css/link.css`**

```css
/* ============================================================
   Pereira Oliveira Turismo — Página de links da bio (/link)
   Coluna única, mobile-first. Tom de papel morno da Nossa História
   (contraste deliberado com a home cinematográfica). Standalone.
============================================================ */
:root{
  --brand-azul:#1FA8DD;
  --brand-verde:#84C440;
  --brand-grad:linear-gradient(135deg,var(--brand-azul),var(--brand-verde));
  --wa:#25D366;
  --font-display:"Rubik",system-ui,sans-serif;
  --font-body:"DM Sans",system-ui,sans-serif;
  --font-mono:"DM Mono","SFMono-Regular",ui-monospace,monospace;
  --ease:cubic-bezier(.22,.61,.36,1);

  --his-paper:#F3F0E9;
  --his-print:#ffffff;
  --his-ink:#22242B;
  --his-ink-soft:#6C6E75;
  --his-line:rgba(34,36,43,.14);
  --his-kraft:#E3D8C3;
}

*,*::before,*::after{box-sizing:border-box}
body{
  margin:0;font-family:var(--font-body);color:var(--his-ink);background:var(--his-paper);
  -webkit-font-smoothing:antialiased;overflow-x:hidden;
}
img{display:block;max-width:100%}
a{color:inherit;text-decoration:none}
h1,h2,h3{font-family:var(--font-display);line-height:1.05;margin:0}
ul{margin:0;padding:0;list-style:none}

/* ---------- coluna ---------- */
.lk{
  position:relative;z-index:1;
  width:min(100% - 32px,420px);margin:0 auto;
  padding:36px 0 48px;
}

/* ---------- cabeçalho ---------- */
.lk__head{text-align:center}
.lk__logo img{height:44px;width:auto;margin:0 auto}
.lk__tag{
  margin:10px 0 0;font-family:var(--font-mono);font-size:.72rem;letter-spacing:.14em;
  text-transform:uppercase;color:var(--his-ink-soft);
}

/* selo do Google. As 5 estrelas sao elemento VISUAL: nada de AggregateRating
   no JSON-LD (avaliacao que a propria empresa hospeda sobre si e violacao de
   politica do Google e nao gera estrela na busca). Ver spec de SEO. */
.lk__google{
  display:inline-flex;align-items:center;gap:9px;margin-top:18px;
  padding:8px 16px;border-radius:999px;
  background:var(--his-print);border:1px solid var(--his-line);
  box-shadow:0 2px 10px rgba(34,36,43,.06);
  transition:.18s var(--ease);
}
.lk__google:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(34,36,43,.1)}
.lk__google-ic{width:17px;height:17px;flex:none}
.lk__google-sep{width:1px;height:15px;background:var(--his-line)}
.lk__stars{color:#F5A623;font-size:.9rem;letter-spacing:.06em}
.lk__google-n{font-family:var(--font-mono);font-size:.92rem;font-weight:500}

.lk__h1{
  margin:24px 0 0;font-size:clamp(1.9rem,8vw,2.4rem);font-weight:700;letter-spacing:-.02em;
}
.lk__h1 em{
  font-style:normal;
  background:var(--brand-grad);-webkit-background-clip:text;background-clip:text;color:transparent;
}
.lk__sub{
  margin:12px auto 0;max-width:32ch;font-size:.98rem;line-height:1.5;color:var(--his-ink-soft);
}

/* ---------- números ---------- */
.lk__nums{
  display:flex;justify-content:center;gap:8px;margin-top:22px;
  padding:14px 0;border-top:1px solid var(--his-line);border-bottom:1px solid var(--his-line);
}
.lk__nums li{flex:1;display:flex;flex-direction:column;gap:2px}
.lk__nums b{font-family:var(--font-mono);font-size:1.35rem;font-weight:500;line-height:1}
.lk__nums span{font-size:.72rem;color:var(--his-ink-soft)}
```

- [ ] **Step 3: Criar `assets/js/link.js`**

```js
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
```

- [ ] **Step 4: Subir o servidor e verificar no browser**

```bash
cd "e:/Clientes/Pereira Oliveira Turismo/po-tu-project" && python -m http.server 8080
```

Abrir `http://localhost:8080/link.html` no Playwright, redimensionar para **390x844** (iPhone), tirar screenshot.

Esperado: fundo `#F3F0E9`; logo centralizado; selo branco com o G colorido, 5 estrelas laranja e `5,0`; "Viaje em grupo." em preto e "Desde 1967." no gradiente azul→verde; três números entre filetes, o primeiro tendo contado até **58**. Zero erro no console.

- [ ] **Step 5: Verificar o noindex e o link do Google**

```bash
curl -s http://localhost:8080/link.html | grep -c 'name="robots" content="noindex, follow"'
curl -s http://localhost:8080/link.html | grep -c 'maps?cid=10478700579601202326'
```

Esperado: `1` nas duas.

- [ ] **Step 6: Commit**

```bash
git add link.html assets/css/link.css assets/js/link.js
git commit -m "feat: esqueleto da pagina de links da bio (/link)

Coluna no papel morno da Nossa Historia, com selo do Google e os
numeros da Historia. As 5 estrelas sao so visuais: AggregateRating de
avaliacao propria e violacao de politica do Google.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 2: Botões, redes sociais e rodapé

Entrega: a página tem os dois CTAs principais, o botão do Google, as redes e o rodapé. Tudo HTML puro, funciona sem JS.

**Files:**
- Modify: `link.html` (dentro de `<main class="lk">`, após `</header>`)
- Modify: `assets/css/link.css` (append)

**Interfaces:**
- Consumes: `.lk`, `--brand-grad`, `--wa`, `--his-*` da Task 1
- Produces: a classe `.lk__btn` (linha de botão: ícone + título + subtítulo + seta), reusada pelo Google; a constante de UTM `?utm_source=instagram&utm_medium=bio&utm_campaign=link`, reusada pelos cards na Task 3

- [ ] **Step 1: Inserir o bloco no `link.html`, logo depois de `</header>`**

O UTM vai **escrito na URL** dos links estáticos (não injetado por JS), para funcionar sem JS. O `lead-form.js` já captura UTM em campo oculto e o `enviar.php` já grava: o lead cai no painel dizendo que veio da bio, sem backend novo.

```html
    <nav class="lk__links" aria-label="Atalhos">

      <a class="lk__btn lk__btn--pri" href="contato.html?utm_source=instagram&amp;utm_medium=bio&amp;utm_campaign=link">
        <span class="lk__btn-ic" aria-hidden="true">
          <svg viewBox="0 0 24 24"><path d="M4 6h16v12H4z"/><path d="m4 7 8 6 8-6"/></svg>
        </span>
        <span class="lk__btn-txt">
          <b>Reservar passeio</b>
          <small>Conte seu destino e retornamos com o roteiro</small>
        </span>
        <svg class="lk__btn-go" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
      </a>

      <a class="lk__btn lk__btn--wa" href="https://wa.me/5548996048882" target="_blank" rel="noopener">
        <span class="lk__btn-ic" aria-hidden="true">
          <svg class="lk__ic-fill" viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18a8 8 0 0 1-4.1-1.1l-.3-.2-3 .8.8-2.9-.2-.3A8 8 0 1 1 12 20Zm4.4-6c-.2-.1-1.4-.7-1.6-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.5 6.5 0 0 1-1.9-1.2 7.3 7.3 0 0 1-1.4-1.7c-.1-.2 0-.4.1-.5l.4-.5c.1-.2.2-.3.3-.5 0-.2 0-.3 0-.5l-.8-1.9c-.2-.5-.4-.4-.6-.4h-.5c-.2 0-.5.1-.7.3-.2.3-.9.9-.9 2.1 0 1.2.9 2.4 1 2.6.1.2 1.7 2.7 4.2 3.8.6.3 1 .4 1.4.5.6.2 1.1.2 1.5.1.5-.1 1.4-.6 1.6-1.1.2-.6.2-1 .1-1.1Z"/></svg>
        </span>
        <span class="lk__btn-txt">
          <b>Falar no WhatsApp</b>
          <small>Atendimento direto com a equipe</small>
        </span>
        <svg class="lk__btn-go" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
      </a>

    </nav>

    <section class="lk__roteiros" aria-labelledby="lk-rot-t">
      <h2 class="lk__h2" id="lk-rot-t">Próximas viagens</h2>
      <!-- Preenchido pelo link.js a partir do banco. Nasce vazio de propósito:
           roteiro escrito à mão no HTML envelhece e pisca (ver CLAUDE.md). -->
      <ul class="lk__cards" id="link-roteiros"></ul>
    </section>

    <nav class="lk__links" aria-label="Avaliações">
      <a class="lk__btn" href="https://www.google.com/maps?cid=10478700579601202326" target="_blank" rel="noopener">
        <span class="lk__btn-ic" aria-hidden="true">
          <svg viewBox="0 0 24 24"><path d="m12 3 2.6 5.3 5.9.9-4.2 4.1 1 5.8-5.3-2.8-5.3 2.8 1-5.8L3.5 9.2l5.9-.9z"/></svg>
        </span>
        <span class="lk__btn-txt">
          <b>Ver avaliações no Google</b>
          <small>5,0 estrelas no Google</small>
        </span>
        <svg class="lk__btn-go" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
      </a>
    </nav>

    <footer class="lk__foot">
      <p class="lk__foot-t">Siga na rede</p>
      <div class="lk__social">
        <a href="https://instagram.com/pereiraoliveiraturismo" target="_blank" rel="noopener" aria-label="Instagram">
          <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.3" cy="6.7" r="1"/></svg>
        </a>
        <a href="https://wa.me/5548996048882" target="_blank" rel="noopener" aria-label="WhatsApp">
          <svg class="lk__ic-fill" viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18a8 8 0 0 1-4.1-1.1l-.3-.2-3 .8.8-2.9-.2-.3A8 8 0 1 1 12 20Zm4.4-6c-.2-.1-1.4-.7-1.6-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.5 6.5 0 0 1-1.9-1.2 7.3 7.3 0 0 1-1.4-1.7c-.1-.2 0-.4.1-.5l.4-.5c.1-.2.2-.3.3-.5 0-.2 0-.3 0-.5l-.8-1.9c-.2-.5-.4-.4-.6-.4h-.5c-.2 0-.5.1-.7.3-.2.3-.9.9-.9 2.1 0 1.2.9 2.4 1 2.6.1.2 1.7 2.7 4.2 3.8.6.3 1 .4 1.4.5.6.2 1.1.2 1.5.1.5-.1 1.4-.6 1.6-1.1.2-.6.2-1 .1-1.1Z"/></svg>
        </a>
      </div>
      <p class="lk__copy">© 2026 Pereira Oliveira Turismo · Florianópolis, SC</p>
      <p class="lk__copy lk__copy--sm"><a href="index.html">pereiraoliveiraturismo.com.br</a></p>
    </footer>
```

- [ ] **Step 2: Append no `assets/css/link.css`**

```css
/* ---------- linhas de botão ---------- */
.lk__links{display:flex;flex-direction:column;gap:10px;margin-top:26px}
.lk__btn{
  display:flex;align-items:center;gap:13px;
  padding:14px 15px;border-radius:15px;
  background:var(--his-print);border:1px solid var(--his-line);
  box-shadow:0 2px 10px rgba(34,36,43,.05);
  transition:.18s var(--ease);
}
.lk__btn:hover{transform:translateY(-2px);box-shadow:0 10px 24px rgba(34,36,43,.1)}
.lk__btn-ic{
  flex:none;display:grid;place-items:center;width:40px;height:40px;border-radius:11px;
  background:var(--his-paper);
}
.lk__btn-ic svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round}
.lk__ic-fill{fill:currentColor;stroke:none}
.lk__btn-txt{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px;text-align:left}
.lk__btn-txt b{font-family:var(--font-display);font-size:1rem;font-weight:600;line-height:1.2}
.lk__btn-txt small{font-size:.78rem;line-height:1.3;color:var(--his-ink-soft)}
.lk__btn-go{flex:none;width:17px;height:17px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;opacity:.4;transition:.18s var(--ease)}
.lk__btn:hover .lk__btn-go{opacity:.9;transform:translateX(2px)}

/* CTA primário: gradiente da marca, texto branco */
.lk__btn--pri{background:var(--brand-grad);border-color:transparent;color:#fff}
.lk__btn--pri .lk__btn-ic{background:rgba(255,255,255,.2)}
.lk__btn--pri .lk__btn-txt small{color:rgba(255,255,255,.82)}
.lk__btn--pri .lk__btn-go{opacity:.85}

/* WhatsApp. Texto e ícone brancos por decisão do cliente (16/07/2026), para
   casar com o CTA ao lado. Fica o registro: branco sobre #25D366 dá ~1.9:1 de
   contraste (o mínimo AA para texto normal é 4.5:1). É o mesmo par que o próprio
   WhatsApp usa. Se um dia o contraste virar requisito, a saída é ESCURECER o
   verde e manter o branco, não voltar o texto para escuro. */
.lk__btn--wa{background:var(--wa);border-color:transparent;color:#fff}
.lk__btn--wa .lk__btn-ic{background:rgba(255,255,255,.2)}
.lk__btn--wa .lk__btn-txt small{color:rgba(255,255,255,.85)}
.lk__btn--wa .lk__btn-go{opacity:.85}

/* ---------- rodapé ---------- */
.lk__foot{margin-top:34px;text-align:center}
.lk__foot-t{
  margin:0;font-family:var(--font-mono);font-size:.66rem;letter-spacing:.18em;
  text-transform:uppercase;color:var(--his-ink-soft);
}
.lk__social{display:flex;justify-content:center;gap:10px;margin-top:12px}
.lk__social a{
  display:grid;place-items:center;width:42px;height:42px;border-radius:50%;
  background:var(--his-print);border:1px solid var(--his-line);
  transition:.18s var(--ease);
}
.lk__social a:hover{transform:translateY(-2px);border-color:var(--brand-azul);color:var(--brand-azul)}
.lk__social svg{width:19px;height:19px;fill:none;stroke:currentColor;stroke-width:1.7}
.lk__copy{margin:20px 0 0;font-size:.74rem;color:var(--his-ink-soft)}
.lk__copy--sm{margin-top:5px;opacity:.75}
.lk__copy a:hover{color:var(--brand-azul)}
```

- [ ] **Step 3: Verificar no browser**

Recarregar `http://localhost:8080/link.html` a 390px e tirar screenshot.

Esperado: o CTA "Reservar passeio" em gradiente azul→verde com texto branco; o WhatsApp verde logo abaixo; o título "Próximas viagens" seguido de **espaço vazio** (é o esperado — os cards chegam na Task 3); o botão do Google branco; dois ícones redondos de rede; o rodapé. Nada estoura na horizontal. Zero erro no console.

- [ ] **Step 4: Verificar os destinos dos links**

```bash
curl -s http://localhost:8080/link.html | grep -o 'href="[^"]*"' | sort -u
```

Esperado, entre outros: `contato.html?utm_source=instagram&amp;utm_medium=bio&amp;utm_campaign=link`, `https://wa.me/5548996048882` (**com 55**), `https://www.google.com/maps?cid=10478700579601202326`, `https://instagram.com/pereiraoliveiraturismo`. **Nenhum** `share.google`.

- [ ] **Step 5: Commit**

```bash
git add link.html assets/css/link.css
git commit -m "feat: botoes, redes e rodape da pagina de links

UTM escrito na URL e nao injetado por JS, para o rastreio da bio
funcionar mesmo sem JS. O lead-form.js ja captura UTM em campo oculto.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 3: Cards de roteiro lidos do banco

Entrega: os roteiros ativos do `po_roteiros` aparecem como cards de capa quadrada, com dias, período e botão "Ver roteiro".

**Files:**
- Modify: `link.html` (scripts no fim do `<body>`)
- Modify: `assets/js/link.js` (append dentro da IIFE)
- Modify: `assets/css/link.css` (append)

**Interfaces:**
- Consumes: `<ul class="lk__cards" id="link-roteiros">` da Task 2; de `roteiros-shared.js`: `poFetchRoteiros()` → `Promise<Array|null>` (null = indisponível) e `poRoteiroHref(slug)` → `string`
- Produces: nada para tasks posteriores

Campos do banco usados: `slug`, `titulo`, `subtitulo`, `badge` (ex. `"17 dias"`), `periodo` (ex. `"De 14/09/26 à 30/09/26"`), `capa_url`.

- [ ] **Step 1: Trocar o bloco de scripts no fim do `link.html`**

Substituir a linha `<script src="assets/js/link.js?v=1"></script>` por:

```html
  <script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2"></script>
  <script src="assets/js/po-config.js"></script>
  <script src="assets/js/roteiros-shared.js?v=3"></script>
  <script src="assets/js/link.js?v=1"></script>
```

- [ ] **Step 2: Append no `assets/js/link.js`, ANTES do `})();` final**

`poRoteiroHref(slug)` já resolve sozinho entre página estática e a dinâmica `roteiro.html?slug=`. Não reimplemente essa regra.

```js
  /* ---------- cards de roteiro (do banco) ----------
     Sem banco ou sem roteiro ativo: NENHUM card, e o resto da página segue de
     pé. É deliberado — melhor card nenhum do que card vencido. Não existe
     fallback escrito à mão: foi o que causou o flash do roteiro excluído na
     home (CLAUDE.md, 16/07/2026). */
  var UTM = 'utm_source=instagram&utm_medium=bio&utm_campaign=link';

  function esc(s) {
    return (s == null ? '' : String(s)).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  // poRoteiroHref já pode devolver uma URL com query (roteiro.html?slug=…),
  // então o separador do UTM depende do que veio.
  function comUtm(href, slug) {
    var sep = href.indexOf('?') === -1 ? '?' : '&';
    return href + sep + UTM + '&utm_content=' + encodeURIComponent(slug);
  }

  function cardHtml(r) {
    var href = comUtm(window.poRoteiroHref(r.slug), r.slug);
    var meta = [r.badge, r.periodo].filter(Boolean).map(esc).join(' · ');
    return '<li class="lk-card">' +
      '<a class="lk-card__img" href="' + esc(href) + '" tabindex="-1" aria-hidden="true">' +
        '<img src="' + esc(r.capa_url || '') + '" alt="" loading="lazy" decoding="async">' +
      '</a>' +
      '<div class="lk-card__body">' +
        '<h3 class="lk-card__t">' + esc(r.titulo || '') + '</h3>' +
        (r.subtitulo ? '<p class="lk-card__s">' + esc(r.subtitulo) + '</p>' : '') +
        (meta ? '<p class="lk-card__m">' + meta + '</p>' : '') +
        '<a class="lk-card__go" href="' + esc(href) + '">Ver roteiro</a>' +
      '</div>' +
    '</li>';
  }

  var alvo = document.getElementById('link-roteiros');
  if (alvo && window.poFetchRoteiros) {
    window.poFetchRoteiros().then(function (rs) {
      if (!rs || !rs.length) return;              // sem banco = sem card
      alvo.innerHTML = rs.map(cardHtml).join('');
    }).catch(function () {});                     // idem: silêncio, sem card
  }
```

- [ ] **Step 3: Append no `assets/css/link.css`**

```css
/* ---------- cards de roteiro ---------- */
.lk__roteiros{margin-top:34px}
.lk__h2{
  font-size:.72rem;font-family:var(--font-mono);font-weight:500;letter-spacing:.18em;
  text-transform:uppercase;color:var(--his-ink-soft);text-align:center;
}
.lk__cards{display:flex;flex-direction:column;gap:14px;margin-top:14px}

.lk-card{
  overflow:hidden;border-radius:16px;
  background:var(--his-print);border:1px solid var(--his-line);
  box-shadow:0 2px 12px rgba(34,36,43,.06);
  transition:.2s var(--ease);
}
.lk-card:hover{transform:translateY(-2px);box-shadow:0 12px 28px rgba(34,36,43,.12)}
/* capa quadrada: o formato do feed do Instagram, de onde a pessoa veio */
.lk-card__img{display:block;aspect-ratio:1;overflow:hidden;background:var(--his-kraft)}
.lk-card__img img{width:100%;height:100%;object-fit:cover;transition:transform .5s var(--ease)}
.lk-card:hover .lk-card__img img{transform:scale(1.04)}
.lk-card__body{padding:14px 15px 15px}
.lk-card__t{font-size:1.12rem;font-weight:600;letter-spacing:-.01em}
.lk-card__s{margin:4px 0 0;font-size:.85rem;color:var(--his-ink-soft)}
.lk-card__m{
  margin:9px 0 0;font-family:var(--font-mono);font-size:.74rem;color:var(--his-ink);
  padding-top:9px;border-top:1px solid var(--his-line);
}
.lk-card__go{
  display:block;margin-top:12px;padding:11px;border-radius:11px;
  background:var(--brand-grad);color:#fff;text-align:center;
  font-family:var(--font-display);font-weight:600;font-size:.9rem;
  transition:.18s var(--ease);
}
.lk-card__go:hover{filter:brightness(1.07)}

@media(prefers-reduced-motion:reduce){
  .lk-card,.lk-card__img img,.lk__btn,.lk__social a,.lk__google{transition:none}
  .lk-card:hover,.lk__btn:hover,.lk__social a:hover,.lk__google:hover{transform:none}
  .lk-card:hover .lk-card__img img{transform:none}
}
```

- [ ] **Step 4: Conferir a verdade no `curl` ANTES do browser**

O browser pode mentir (ver Global Constraints: mock do Supabase no perfil do Chrome). O `curl` é a referência.

```bash
cd "e:/Clientes/Pereira Oliveira Turismo/po-tu-project"
KEY=$(grep -o "eyJ[A-Za-z0-9._-]*" assets/js/po-config.js | head -1)
curl -s "https://euzmbswywwhmicjlszqw.supabase.co/rest/v1/po_roteiros?select=slug,titulo,badge,periodo&ativo=eq.true&order=ordem" -H "apikey: $KEY" -H "Authorization: Bearer $KEY" | python -c "import sys,json; d=json.load(sys.stdin); print(len(d),'roteiros'); [print(' -',r['slug'],'|',r['badge'],'|',r['periodo']) for r in d]"
```

Esperado hoje: **6 roteiros** — `caminhos-da-india`, `turquia-com-antalia`, `chile-santiago-e-deserto-do-atacama`, `tesouros-asiaticos2`, `coreia-do-sul-japao-dubai`, `um-roteiro-exclusivo-pelo-melhor-da-escandinavia-com-acompan`. Anote a contagem: o browser tem que bater com ela.

- [ ] **Step 5: Verificar no browser, com latência**

No Playwright, antes de navegar: `context.unrouteAll()`. Depois aplicar CDP `Network.emulateNetworkConditions` com `latency: 400`, `downloadThroughput: 1.5*1024*1024/8`. Navegar para `http://localhost:8080/link.html` a 390px, esperar os cards e tirar screenshot.

Esperado: a **mesma contagem** do Step 4 (6 cards); cada um com capa quadrada, título, subtítulo e a linha `17 dias · De 14/09/26 à 30/09/26`; botão "Ver roteiro" em gradiente. Zero erro no console.

Confirmar que a resposta do Supabase é real, não mock:

```js
// no browser_evaluate
() => performance.getEntriesByType('resource').filter(r => r.name.includes('supabase.co/rest')).map(r => r.name)
```

Esperado: uma entrada apontando para `euzmbswywwhmicjlszqw.supabase.co/rest/v1/po_roteiros`. Se a lista vier vazia mas os cards aparecerem, **há mock** — rode `context.unrouteAll()` e repita.

- [ ] **Step 6: Verificar o estado "sem banco"**

No Playwright, bloquear a rota `**/rest/v1/**` (`route.abort()`), recarregar e tirar screenshot.

Esperado: **zero card**. Logo, WhatsApp, formulário, números, Google e rodapé **todos de pé**. Nenhum roteiro escrito à mão aparece. Zero erro não tratado no console. Depois: `context.unrouteAll()`.

- [ ] **Step 7: Verificar que o HTML não tem roteiro escrito à mão**

```bash
curl -s http://localhost:8080/link.html | grep -ciE "india|turquia|atacama|vietna|escandinavia|dias ·"
```

Esperado: **`0`**. Qualquer coisa acima de zero significa dado de roteiro no fonte do HTML, o que é exatamente o bug do flash. Corrija antes de commitar.

- [ ] **Step 8: Commit**

```bash
git add link.html assets/js/link.js assets/css/link.css
git commit -m "feat: cards de roteiro na pagina de links, lidos do banco

Publicou no painel, aparece na bio; excluiu, some. Sem banco = sem
card, e o resto da pagina segue de pe: melhor card nenhum do que card
vencido. Capa quadrada, o formato do feed de onde a pessoa veio.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 4: Fundo de mapa-múndi com rotas e aviões

Entrega: o mapa em outline aparece atrás da coluna, com rotas pontilhadas e aviões percorrendo os traçados.

**Files:**
- Modify: `link.html` (SVG logo após `<body>`, antes de `<main class="lk">`)
- Modify: `assets/css/link.css` (append)

**Interfaces:**
- Consumes: `--his-ink`, `--brand-azul` da Task 1
- Produces: nada

**O mapa é TEXTURA, NÃO ILUSTRAÇÃO.** A opacidade fica em **6–8%**. Este é o ponto de falha mais provável do visual: acima disso ele briga com as capas dos roteiros e a página vira poluição. Se na tela parecer que o mapa "aparece", está errado.

As rotas são **decoração fixa**, desenhadas à mão. O banco não tem coordenadas, então elas não acompanham o banco: roteiro novo não ganha rota. Limite conhecido e aceito na spec.

- [ ] **Step 1: Inserir o SVG no `link.html`, logo depois de `<body>`**

O `viewBox="0 0 1000 500"` é a projeção equiretangular padrão: `x = (lon+180)/360*1000`, `y = (90-lat)/180*500`. Florianópolis (27,6°S / 48,5°O) cai em `x=365 y=327`.

```html
  <!-- Fundo: mapa-múndi + rotas. Textura, não ilustração — ver .lk-map no CSS.
       Decorativo: fora do foco e do leitor de tela. -->
  <div class="lk-map" aria-hidden="true">
    <svg viewBox="0 0 1000 500" preserveAspectRatio="xMidYMid slice">
      <g class="lk-map__land">
        <!-- América do Sul -->
        <path d="M300 300 l14-24 22-8 16 10 12-6 10 12-6 20-14 26-8 30-12 34-14 22-10-4-4-24 6-26-8-30-4-32Z"/>
        <!-- América do Norte -->
        <path d="M170 130 l40-24 60-6 54 8 30 18-10 22-34 10-20 26-26 16-18 30-16-6-8-28-24-18-28-16Z"/>
        <!-- África -->
        <path d="M470 250 l30-30 40-8 44 6 20 18-8 26-18 30-12 36-20 30-18 10-16-14-10-30-16-28-16-24Z"/>
        <!-- Europa -->
        <path d="M480 170 l30-16 40-4 34 8 12 14-16 12-30 6-24 14-26-4-20-14Z"/>
        <!-- Ásia -->
        <path d="M600 150 l50-24 70-6 80 12 50 26-16 26-44 12-36 26-44 10-40-16-30-24-24-24Z"/>
        <!-- Oceania -->
        <path d="M790 340 l40-16 44 4 26 18-10 24-38 14-40-6-26-18Z"/>
      </g>
      <g class="lk-map__routes">
        <path class="lk-map__route" id="lk-r1" d="M365 327 Q 460 200 640 190"/>
        <path class="lk-map__route" id="lk-r2" d="M365 327 Q 470 260 560 175"/>
        <path class="lk-map__route" id="lk-r3" d="M365 327 Q 400 260 520 140"/>
        <path class="lk-map__route" id="lk-r4" d="M365 327 Q 330 340 300 300"/>
      </g>
      <circle class="lk-map__home" cx="365" cy="327" r="4"/>
      <g class="lk-map__planes">
        <g class="lk-map__plane"><path d="M0-5 8 3 3 3 0 8-3 3-8 3Z"/>
          <animateMotion dur="14s" repeatCount="indefinite" rotate="auto" begin="0s"><mpath href="#lk-r1"/></animateMotion></g>
        <g class="lk-map__plane"><path d="M0-5 8 3 3 3 0 8-3 3-8 3Z"/>
          <animateMotion dur="17s" repeatCount="indefinite" rotate="auto" begin="3s"><mpath href="#lk-r2"/></animateMotion></g>
        <g class="lk-map__plane"><path d="M0-5 8 3 3 3 0 8-3 3-8 3Z"/>
          <animateMotion dur="20s" repeatCount="indefinite" rotate="auto" begin="7s"><mpath href="#lk-r3"/></animateMotion></g>
      </g>
    </svg>
  </div>
```

- [ ] **Step 2: Append no `assets/css/link.css`**

```css
/* ---------- fundo: mapa-múndi + rotas ----------
   TEXTURA, NÃO ILUSTRAÇÃO. A opacidade baixa é o ponto do design: acima disso
   o mapa briga com as capas dos roteiros e a página vira poluição. */
.lk-map{
  position:fixed;inset:0;z-index:0;pointer-events:none;
  opacity:.07;
}
.lk-map svg{width:100%;height:100%}
.lk-map__land{fill:none;stroke:var(--his-ink);stroke-width:1.2;stroke-linejoin:round}
.lk-map__route{
  fill:none;stroke:var(--brand-azul);stroke-width:1.6;
  stroke-dasharray:5 7;stroke-linecap:round;
  animation:lk-dash 2.4s linear infinite;
}
@keyframes lk-dash{to{stroke-dashoffset:-24}}
.lk-map__home{fill:var(--brand-azul)}
.lk-map__plane{fill:var(--his-ink)}

/* Quem pediu menos animação recebe o mapa parado: rotas e aviões congelam,
   mas o desenho continua lá. Mesma regra do resto do site. */
@media(prefers-reduced-motion:reduce){
  .lk-map__route{animation:none}
  .lk-map__planes{display:none}
}
```

- [ ] **Step 3: Verificar no browser**

Recarregar a 390px e tirar screenshot. Depois repetir a **1280px** de largura.

Esperado: o mapa **mal se nota** atrás do conteúdo, como uma marca-d'água no papel; rotas azuis pontilhadas saindo de um ponto no sul do Brasil; três aviõezinhos deslizando. Os cards e os botões continuam perfeitamente legíveis. Se o mapa competir com as capas, **baixe a opacidade** — o intervalo aprovado é 6–8%.

- [ ] **Step 4: Verificar o `prefers-reduced-motion`**

No Playwright, emular `reduced-motion: reduce`, recarregar, tirar screenshot.

Esperado: mapa e rotas desenhados, **parados**; **nenhum avião**; nada de hover-lift nos cards. A contagem dos anos mostra 58 direto, sem animar.

- [ ] **Step 5: Verificar que o SVG está fora da árvore de acessibilidade**

```js
// no browser_evaluate
() => document.querySelector('.lk-map').getAttribute('aria-hidden')
```

Esperado: `"true"`.

- [ ] **Step 6: Commit**

```bash
git add link.html assets/css/link.css
git commit -m "feat: mapa-mundi com rotas e avioes no fundo da pagina de links

SVG inline (~15 KB, sem imagem nem biblioteca). Opacidade 7%: e textura,
nao ilustracao — acima disso briga com as capas dos roteiros. Rotas sao
decoracao fixa: o banco nao tem coordenadas.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 5: Rewrite de `/link` e documentação

Entrega: a URL limpa `pereiraoliveiraturismo.com.br/link` funciona, e o CLAUDE.md registra a página.

**Files:**
- Modify: `.htaccess`
- Modify: `CLAUDE.md`

**Interfaces:**
- Consumes: `link.html` da Task 1
- Produces: nada

- [ ] **Step 1: Adicionar o rewrite no `.htaccess`, depois do bloco "Força HTTPS"**

O `RewriteEngine On` **já existe** no arquivo (bloco do HTTPS). Não repita.

```apache
# URL limpa da pagina de links da bio do Instagram: /link -> /link.html
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^link/?$ /link.html [L]
```

- [ ] **Step 2: Atualizar o `CLAUDE.md`**

Na seção **Estado atual**, adicionar após o item de "WhatsApp em todo o site":

```markdown
- **Página de links da bio `/link` (16/07/2026):** destino do link único da bio do
  Instagram. Standalone (`link.html` + `assets/css/link.css` + `assets/js/link.js`),
  coluna de 420px no **papel morno da Nossa História** (contraste com a home). Fundo de
  **mapa-múndi em SVG inline** com rotas pontilhadas e aviões — **opacidade 7%, é textura,
  não ilustração**; as rotas são decoração fixa (o banco não tem coordenadas, então roteiro
  novo não ganha rota). Tem: selo do Google 5,0 → `https://www.google.com/maps?cid=10478700579601202326`
  · números da História (58 anos ao vivo desde 1967, 3 gerações, +35 países) · CTA
  "Reservar passeio" → `contato.html` · WhatsApp · **cards de roteiro do banco** (capa
  quadrada, dias, período, "Ver roteiro" via `poRoteiroHref`) · redes · rodapé.
  **`noindex` e fora do `sitemap.xml`** — conteúdo fino e duplicado; indexar só criaria
  concorrência com a home e as páginas de roteiro. `.htaccess` faz o rewrite `/link`.
  UTM (`utm_source=instagram&utm_medium=bio&utm_campaign=link`, + `utm_content=<slug>` no
  card) vai **escrito na URL**, não injetado por JS, para rastrear mesmo sem JS; o
  `lead-form.js` já captura. **Sem banco = sem card**, e o resto da página segue de pé —
  a mesma regra da home, e nunca escrever roteiro à mão no HTML.
  **Selo do Google:** as 5 estrelas são **visuais**; nada de `AggregateRating` no JSON-LD
  (avaliação que a própria empresa hospeda sobre si é violação de política e não gera
  estrela na busca). **Link do Google:** usar o formato `?cid=`; o botão "Compartilhar" da
  página de resultados gera `share.google/...`, que compartilha a **pesquisa**, não a ficha.
  Spec: `docs/superpowers/specs/2026-07-16-pagina-link-bio-design.md`.
```

- [ ] **Step 3: Verificar a sintaxe do `.htaccess`**

Não há Apache local. Confira à vista que o bloco novo está **depois** do `RewriteEngine On` existente e que não há um segundo `RewriteEngine On`:

```bash
grep -n "RewriteEngine\|RewriteRule\|RewriteCond" .htaccess
```

Esperado: **um único** `RewriteEngine On`, e a `RewriteRule ^link/?$` aparecendo depois dele.

- [ ] **Step 4: Commit**

```bash
git add .htaccess CLAUDE.md
git commit -m "feat: rewrite de /link e registro da pagina no CLAUDE.md

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 6: Deploy e verificação em produção

Entrega: `pereiraoliveiraturismo.com.br/link` no ar e conferido.

**Files:** nenhum (deploy)

**Interfaces:**
- Consumes: tudo das tasks anteriores
- Produces: nada

**Pare e peça a senha do FTP ao dono antes de começar.** Ela não é versionada. Use `.netrc` no scratchpad, **nunca** inline no comando, e apague ao terminar. **Nunca sobrescreva `config.local.php`** — os segredos vivem só no servidor.

- [ ] **Step 1: Pedir a senha do FTP e montar o `.netrc`**

```bash
SCRATCH="C:/Users/fabia/AppData/Local/Temp/claude/e--Clientes-Pereira-Oliveira-Turismo-po-tu-project/c811222e-5721-4d89-907f-df8ce8fa74f9/scratchpad"
cat > "$SCRATCH/.netrc" <<'EOF'
machine ftp.pereiraoliveiraturismo.com.br
login sitepo@pereiraoliveiraturismo.com.br
password <SENHA_FORNECIDA_NA_HORA>
EOF
chmod 600 "$SCRATCH/.netrc"
```

- [ ] **Step 2: Subir os 4 arquivos**

A raiz do FTP já é o docroot. O `-k` é necessário: o certificado da hospedagem compartilhada tem nome divergente (a conexão segue criptografada).

```bash
cd "e:/Clientes/Pereira Oliveira Turismo/po-tu-project"
for f in link.html assets/css/link.css assets/js/link.js .htaccess; do
  curl -sS -k --ssl-reqd --netrc-file "$SCRATCH/.netrc" --ftp-pasv -T "$f" "ftp://ftp.pereiraoliveiraturismo.com.br/$f" && echo "ok: $f"
done
rm -f "$SCRATCH/.netrc"
```

- [ ] **Step 3: Verificar no ar**

```bash
curl -sSI https://pereiraoliveiraturismo.com.br/link | grep -i "^HTTP"
curl -s https://pereiraoliveiraturismo.com.br/link | grep -c 'noindex'
curl -s https://pereiraoliveiraturismo.com.br/link | grep -ciE "india|turquia|atacama|escandinavia"
curl -sSI https://pereiraoliveiraturismo.com.br/assets/css/link.css | grep -i "^HTTP"
curl -sSI https://pereiraoliveiraturismo.com.br/assets/js/link.js | grep -i "^HTTP"
```

Esperado: `HTTP/1.1 200` na URL limpa `/link` (sem `.html`); `1` para o noindex; **`0`** para nome de roteiro no HTML; `200` no CSS e no JS.

- [ ] **Step 4: Verificar em produção no browser, a 390px**

Navegar para `https://pereiraoliveiraturismo.com.br/link` e tirar screenshot. **Ctrl+F5** — o `.htaccess` cacheia JS/CSS por 1 mês.

Esperado: os 6 cards do banco; mapa discreto ao fundo; zero erro no console. Clicar em "Ver roteiro" do primeiro card e confirmar que abre a página daquele roteiro com `utm_content=<slug>` na URL.

- [ ] **Step 5: Confirmar que o `.netrc` sumiu**

```bash
ls "$SCRATCH/.netrc" 2>&1
```

Esperado: `No such file or directory`.

- [ ] **Step 6: Avisar o dono**

A URL para a bio do Instagram é **`pereiraoliveiraturismo.com.br/link`**.

---

## Self-Review

**Cobertura da spec:** Arquitetura → Task 1; estrutura e copy → Tasks 1-2; card de roteiro → Task 3; mapa → Task 4; tom visual → Task 1 (tokens); rastreamento → Tasks 2-3; estados → Task 3 (Steps 6-7) e Task 4 (Step 4); `noindex` → Task 1 (Step 5); rewrite `/link` → Task 5; fora de escopo (AggregateRating, mojibake) → não implementado, registrado; verificação → distribuída nas tasks e Task 6.

**Placeholders:** o único `<SENHA_FORNECIDA_NA_HORA>` é intencional — a senha não é versionada e o dono a fornece na hora, conforme o CLAUDE.md.

**Consistência de tipos:** `poFetchRoteiros()`/`poRoteiroHref(slug)` conferidos contra `roteiros-shared.js`. `countUp(el)` conferido contra `historia.js`. Campos `slug`/`titulo`/`subtitulo`/`badge`/`periodo`/`capa_url` conferidos contra a resposta real do `curl`. `#link-roteiros` e `.lk__roteiros` casam entre Tasks 2 e 3.

**Nota de decomposição:** `poCard(r)` existe em `roteiros-shared.js`, mas **não é usada** aqui. Ela normaliza para o card do carrossel (`badge`, `data`, `desc`, `img`) e o card da bio quer `periodo` cru, que ela não expõe. Ler os campos direto do registro é mais honesto do que esticar `poCard` para dois consumidores com formatos diferentes.
