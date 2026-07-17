<?php
/* Chrome compartilhado das paginas PHP. O markup e porte 1:1 do
   roteiro.html (header e menu mobile) e das paginas estaticas
   (head/footer/botao flutuante, ex. grecia-terra-mar.html), para o
   CSS existente (assets/css/roteiro.css) continuar valendo sem uma
   linha nova.

   Os href e src internos foram trocados de relativos (index.html,
   assets/images/logo.png) para absolutos de raiz (/, /assets/...):
   as paginas novas vivem em /roteiros/<slug>, um nivel a mais de
   path, e um link relativo ali resolveria para /roteiros/index.html
   (404). */

define('PO_BASE', 'https://pereiraoliveiraturismo.com.br');

/* is_array a mais evita "Array to string conversion": campos do banco vem do
   painel e do importador com IA (Gemini lendo PDF/DOCX), que as vezes devolve
   um item aninhado (array) onde o formulario esperava texto. Sem a guarda,
   um so campo torto derruba a pagina inteira com um Warning ecoado no meio
   do HTML. */
function po_e($s) {
    if (is_array($s)) $s = '';
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
}
function po_url($path) { return PO_BASE . '/' . ltrim((string) $path, '/'); }
function po_roteiro_href($r) { return '/roteiros/' . rawurlencode($r['slug']); }

/* Sanitiza uma URL antes dela entrar num atributo style="...url('...')".
   po_e() escapa aspas para a entidade &#039;, mas o parser de HTML DECODIFICA
   a entidade antes do CSS ler o atributo style - ou seja, a entidade nao
   protege contra fuga do url(). Confirmado com sonda: capa_url =
   "a.jpg'); background:red; x:url('b" produz style="...url('a.jpg&#039;);
   background:red; x:url(&#039;b')" no HTML, que o navegador decodifica de
   volta para url('a.jpg'); background:red; x:url('b') - declaracoes do
   atacante aplicadas. capa_url vem do painel/importador, dado nao confiavel.
   Remove os caracteres que permitem escapar de url(...) ou encerrar a
   declaracao (aspas, parenteses, barra invertida); o restante passa por
   po_e() como sempre. */
function po_css_url($u) {
    $u = str_replace(["'", '(', ')', '\\'], '', (string) $u);
    return po_e($u);
}

/* O <title> mira como o publico de 60+ escreve: "excursao" (nao "roteiro",
   que e jargao de blogueiro), o ano (data fixa e o produto) e a saida de
   Florianopolis (o diferencial que ela disputa com quase ninguem). */
function po_titulo_seo($r) {
    $ano  = po_ano($r);
    $part = 'Excursão ' . trim((string) $r['titulo']);
    if ($ano !== '') $part .= ' ' . $ano;
    return $part . ' · Viagem em grupo saindo de Florianópolis';
}

function po_head($o) {
    $noindex = !empty($o['noindex']);
    $css = '';
    foreach (($o['css'] ?? []) as $c) $css .= '  <link rel="stylesheet" href="' . po_e($c) . '">' . "\n";
    $jsonld = '';
    if (!empty($o['jsonld'])) {
        /* JSON_HEX_TAG e obrigatorio aqui. O parser de HTML encerra um
           bloco <script> na sequencia literal "</script" (sem diferenciar
           maiuscula/minuscula), e o titulo/descricao do roteiro vem de
           texto livre - digitado ou lido pelo importador com IA de um PDF/
           DOCX que a cliente anexa. Um "</script>" nesse texto sai intacto
           no JSON e fecha a tag antes da hora, injetando o que vier depois.
           JSON_UNESCAPED_SLASHES (usada abaixo pelas URLs legiveis no
           JSON-LD) e justamente o que permite a barra de "</script>"
           sobreviver sem virar "\/" - sem HEX_TAG, essa flag e o buraco.
           HEX_TAG troca "<" e ">" por "<"/">", o que neutraliza
           o vetor independente da flag de barras, produz JSON-LD valido e
           nao custa SEO (o Google decodifica < normalmente). */
        $jsonld = '  <script type="application/ld+json">'
            . json_encode($o['jsonld'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG)
            . '</script>' . "\n";
    }
    $robots = $noindex ? 'noindex, nofollow' : 'index, follow, max-image-preview:large';
    $h  = '<!DOCTYPE html>' . "\n" . '<html lang="pt-BR">' . "\n" . '<head>' . "\n";
    $h .= '  <meta charset="UTF-8">' . "\n";
    $h .= '  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">' . "\n";
    $h .= '  <script async src="https://www.googletagmanager.com/gtag/js?id=GT-TNH4L3BV"></script>' . "\n";
    $h .= '  <script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag(\'js\',new Date());gtag(\'config\',\'GT-TNH4L3BV\');</script>' . "\n";
    $h .= '  <meta name="robots" content="' . $robots . '">' . "\n";
    $h .= '  <title>' . po_e($o['title']) . '</title>' . "\n";
    $h .= '  <meta name="description" content="' . po_e($o['description'] ?? '') . '">' . "\n";
    if (!empty($o['canonical'])) $h .= '  <link rel="canonical" href="' . po_e($o['canonical']) . '">' . "\n";
    $h .= '  <meta property="og:type" content="website">' . "\n";
    $h .= '  <meta property="og:site_name" content="Pereira Oliveira Turismo">' . "\n";
    $h .= '  <meta property="og:locale" content="pt_BR">' . "\n";
    $h .= '  <meta property="og:title" content="' . po_e($o['title']) . '">' . "\n";
    $h .= '  <meta property="og:description" content="' . po_e($o['description'] ?? '') . '">' . "\n";
    if (!empty($o['canonical'])) $h .= '  <meta property="og:url" content="' . po_e($o['canonical']) . '">' . "\n";
    if (!empty($o['og_image'])) {
        $h .= '  <meta property="og:image" content="' . po_e($o['og_image']) . '">' . "\n";
        $h .= '  <meta property="og:image:alt" content="' . po_e($o['title']) . '">' . "\n";
    }
    $h .= '  <meta name="twitter:card" content="summary_large_image">' . "\n";
    $h .= '  <meta name="twitter:title" content="' . po_e($o['title']) . '">' . "\n";
    $h .= '  <meta name="twitter:description" content="' . po_e($o['description'] ?? '') . '">' . "\n";
    if (!empty($o['og_image'])) $h .= '  <meta name="twitter:image" content="' . po_e($o['og_image']) . '">' . "\n";
    $h .= '  <meta name="theme-color" content="#1FA8DD">' . "\n";
    $h .= '  <link rel="icon" href="/assets/images/favicon.png">' . "\n";
    $h .= '  <link rel="apple-touch-icon" href="/assets/images/favicon.png">' . "\n";
    $h .= '  <link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
    $h .= '  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
    $h .= '  <link href="https://fonts.googleapis.com/css2?family=Rubik:wght@400;500;600;700&family=DM+Sans:wght@400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">' . "\n";
    $h .= $css . $jsonld;
    $h .= '</head>' . "\n" . '<body>' . "\n";
    return $h;
}

/* Header glass fixo + menu mobile (sheet), porte 1:1 de roteiro.html
   linhas 20-66. O sheet vem junto porque o burger so funciona com ele
   no DOM (assets/js/roteiro.js liga #burger a #sheet). */
function po_header() {
    return <<<HTML
  <header class="hd">
    <a href="/" class="hd__logo" aria-label="Pereira Oliveira Turismo"><img src="/assets/images/logo.png" alt="Pereira Oliveira Turismo"></a>
    <nav class="hd__nav">
      <a href="/">Início</a>
      <a href="/nossa-historia.html">Nossa História</a>
      <a href="/roteiros">Roteiros</a>
      <a href="/contato.html">Contato</a>
    </nav>
    <div class="hd__actions">
      <a class="hd__cta" href="#contato">
      <svg viewBox="0 0 24 24"><path d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2Zm0 4-8 5-8-5V6l8 5 8-5v2Z"/></svg>
      Contato
    </a>
      <a class="hd__wa" href="https://wa.me/5548996048882" target="_blank" rel="noopener">
        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18a8 8 0 0 1-4.1-1.1l-.3-.2-3 .8.8-2.9-.2-.3A8 8 0 1 1 12 20Zm4.4-6c-.2-.1-1.4-.7-1.6-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.5 6.5 0 0 1-1.9-1.2 7.3 7.3 0 0 1-1.4-1.7c-.1-.2 0-.4.1-.5l.4-.5c.1-.2.2-.3.3-.5 0-.2 0-.3 0-.5l-.8-1.9c-.2-.5-.4-.4-.6-.4h-.5c-.2 0-.5.1-.7.3-.2.3-.9.9-.9 2.1 0 1.2.9 2.4 1 2.6.1.2 1.7 2.7 4.2 3.8.6.3 1 .4 1.4.5.6.2 1.1.2 1.5.1.5-.1 1.4-.6 1.6-1.1.2-.6.2-1 .1-1.1Z"/></svg>
        WhatsApp
      </a>
    </div>
    <button class="hd__burger" id="burger" aria-label="Abrir menu"><svg viewBox="0 0 24 24" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
  </header>

  <div class="sheet" id="sheet" data-open="false">
    <div class="sheet__scrim"></div>
    <nav class="sheet__panel">
      <button class="sheet__close" id="sheet-close" aria-label="Fechar menu">×</button>
      <a href="/">Início</a>
      <a href="/nossa-historia.html">Nossa História</a>
      <a href="/roteiros">Roteiros</a>
      <a href="/contato.html">Contato</a>
      <a class="hd__cta" href="#contato">
        <svg viewBox="0 0 24 24"><path d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2Zm0 4-8 5-8-5V6l8 5 8-5v2Z"/></svg>
        Contato
      </a>
      <a class="hd__wa" href="https://wa.me/5548996048882" target="_blank" rel="noopener">
        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18a8 8 0 0 1-4.1-1.1l-.3-.2-3 .8.8-2.9-.2-.3A8 8 0 1 1 12 20Zm4.4-6c-.2-.1-1.4-.7-1.6-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.5 6.5 0 0 1-1.9-1.2 7.3 7.3 0 0 1-1.4-1.7c-.1-.2 0-.4.1-.5l.4-.5c.1-.2.2-.3.3-.5 0-.2 0-.3 0-.5l-.8-1.9c-.2-.5-.4-.4-.6-.4h-.5c-.2 0-.5.1-.7.3-.2.3-.9.9-.9 2.1 0 1.2.9 2.4 1 2.6.1.2 1.7 2.7 4.2 3.8.6.3 1 .4 1.4.5.6.2 1.1.2 1.5.1.5-.1 1.4-.6 1.6-1.1.2-.6.2-1 .1-1.1Z"/></svg>
        Reserve pelo WhatsApp
      </a>
      <div class="sheet__social">
        <a href="https://instagram.com/pereiraoliveiraturismo" target="_blank" rel="noopener" aria-label="Instagram">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.3" cy="6.7" r="1"/></svg>
        </a>
        <a href="https://wa.me/5548996048882" target="_blank" rel="noopener" aria-label="WhatsApp">
          <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18a8 8 0 0 1-4.1-1.1l-.3-.2-3 .8.8-2.9-.2-.3A8 8 0 1 1 12 20Zm4.4-6c-.2-.1-1.4-.7-1.6-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.5 6.5 0 0 1-1.9-1.2 7.3 7.3 0 0 1-1.4-1.7c-.1-.2 0-.4.1-.5l.4-.5c.1-.2.2-.3.3-.5 0-.2 0-.3 0-.5l-.8-1.9c-.2-.5-.4-.4-.6-.4h-.5c-.2 0-.5.1-.7.3-.2.3-.9.9-.9 2.1 0 1.2.9 2.4 1 2.6.1.2 1.7 2.7 4.2 3.8.6.3 1 .4 1.4.5.6.2 1.1.2 1.5.1.5-.1 1.4-.6 1.6-1.1.2-.6.2-1 .1-1.1Z"/></svg>
        </a>
      </div>
    </nav>
  </div>

HTML;
}

/* Footer institucional, porte 1:1 do fim de grecia-terra-mar.html. */
function po_footer() {
    return <<<HTML
  <footer class="ft">
    <div class="ft__wrap">
      <div>
        <a href="/" class="ft__logo"><img loading="lazy" decoding="async" src="/assets/images/logo.png" alt="Pereira Oliveira Turismo"></a>
        <p class="ft__tag">Realizando sonhos desde 1967</p>
        <p>Operadora de viagens em grupo com roteiros internacionais e datas fixas. Atendimento presencial em Florianópolis, com hora marcada.</p>
        <div class="ft__social">
          <a href="https://instagram.com/pereiraoliveiraturismo" target="_blank" rel="noopener" aria-label="Instagram">
            <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.3" cy="6.7" r="1"/></svg>
          </a>

          <a href="https://wa.me/5548996048882" target="_blank" rel="noopener" aria-label="WhatsApp">
            <svg class="ic-fill" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18a8 8 0 0 1-4.1-1.1l-.3-.2-3 .8.8-2.9-.2-.3A8 8 0 1 1 12 20Zm4.4-6c-.2-.1-1.4-.7-1.6-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.5 6.5 0 0 1-1.9-1.2 7.3 7.3 0 0 1-1.4-1.7c-.1-.2 0-.4.1-.5l.4-.5c.1-.2.2-.3.3-.5 0-.2 0-.3 0-.5l-.8-1.9c-.2-.5-.4-.4-.6-.4h-.5c-.2 0-.5.1-.7.3-.2.3-.9.9-.9 2.1 0 1.2.9 2.4 1 2.6.1.2 1.7 2.7 4.2 3.8.6.3 1 .4 1.4.5.6.2 1.1.2 1.5.1.5-.1 1.4-.6 1.6-1.1.2-.6.2-1 .1-1.1Z"/></svg>
          </a>
        </div>
      </div>
      <div>
        <h4>Navegar</h4>
        <div class="ft__links">
          <a href="/">Início</a>
          <a href="/nossa-historia.html">Nossa História</a>
          <a href="/roteiros">Roteiros</a>
          <a href="/contato.html">Contato</a>
        </div>
      </div>
      <div>
        <h4>Contato</h4>
        <div class="ft__links">
          <a href="/contato.html">Fale conosco</a>
          <a href="tel:+5548996048882">(48) 99604-8882</a>
          <a href="https://instagram.com/pereiraoliveiraturismo" target="_blank" rel="noopener">@pereiraoliveiraturismo</a>
          <a href="https://maps.google.com/?q=Rua+Almirante+Lamego+1090+Florianópolis" target="_blank" rel="noopener">Rua Almirante Lamego 1090, sala 801, Centro, Florianópolis/SC</a>
        </div>
      </div>
    </div>
    <div class="ft__bottom">
      <span>© Pereira Oliveira Consultoria em Turismo e Viagens LTDA · CNPJ 05.622.878/0001-82</span>
      <span class="ft__credit">Desenvolvido por <a href="https://www.freelainhome.com.br/" target="_blank" rel="noopener">Freela In Home</a></span>
      <span>Florianópolis · Santa Catarina</span>
    </div>
  </footer>

HTML;
}

/* Botao flutuante de WhatsApp, porte 1:1 do fim de grecia-terra-mar.html. */
function po_wa_float() {
    return <<<HTML
  <a class="wa-float" href="https://wa.me/5548996048882" target="_blank" rel="noopener" aria-label="Falar no WhatsApp">
    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18a8 8 0 0 1-4.1-1.1l-.3-.2-3 .8.8-2.9-.2-.3A8 8 0 1 1 12 20Zm4.4-6c-.2-.1-1.4-.7-1.6-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.5 6.5 0 0 1-1.9-1.2 7.3 7.3 0 0 1-1.4-1.7c-.1-.2 0-.4.1-.5l.4-.5c.1-.2.2-.3.3-.5 0-.2 0-.3 0-.5l-.8-1.9c-.2-.5-.4-.4-.6-.4h-.5c-.2 0-.5.1-.7.3-.2.3-.9.9-.9 2.1 0 1.2.9 2.4 1 2.6.1.2 1.7 2.7 4.2 3.8.6.3 1 .4 1.4.5.6.2 1.1.2 1.5.1.5-.1 1.4-.6 1.6-1.1.2-.6.2-1 .1-1.1Z"/></svg>
  </a>

HTML;
}
