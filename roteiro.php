<?php
/* Pereira Oliveira: pagina do roteiro, renderizada no servidor.

   Porte 1:1 do antigo renderizador client-side (que montava a mesma pagina
   no navegador, via fetch no Supabase, e ficava noindex; apagado na Task 7).
   As classes CSS sao copiadas ao pe da letra: assets/css/roteiro.css ja
   existe e nao muda nesta task, entao uma classe diferente do original
   quebra o layout em producao sem erro nenhum no console. */

require_once __DIR__ . '/lib/po-data.php';
require_once __DIR__ . '/lib/po-view.php';

function po_arr($a) { return is_array($a) ? $a : []; }

/* Converte para string so quando o valor JA E escalar. Existe pro mesmo
   motivo do is_array em po_e(): campos vindos do painel/importador com IA
   as vezes chegam aninhados (array) onde a pagina espera texto, e uma
   concatenacao direta (ex.: $h['cidade'] . ': ') dispara "Array to string
   conversion" antes mesmo de o valor passar por po_e(). */
function po_str($v) { return is_scalar($v) ? (string) $v : ''; }

const PO_WHY = [
    'Cada aspecto da viagem é meticulosamente planejado por uma equipe dedicada, com profundo conhecimento dos destinos.',
    'Nossa experiência em viagens de grupo cria uma dinâmica harmoniosa e uma convivência agradável entre os viajantes.',
    'Um coordenador da Pereira Oliveira acompanha o grupo desde a saída do Brasil, com suporte contínuo em todo o roteiro.',
    'Trabalhamos com uma rede de hotéis e fornecedores criteriosamente selecionados, para garantir conforto e bom atendimento.',
];
const PO_CHECK = '<span class="why__ic"><svg viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5" stroke-linecap="round" stroke-linejoin="round"/></svg></span>';

/* botao de reserva pelo WhatsApp, reaproveitado no hero e no investimento
   (mesmo svg do original, porte 1:1). */
function po_btn_wa() {
    return '<a class="btn btn--wa" href="https://wa.me/5548996048882" target="_blank" rel="noopener">Reserve pelo WhatsApp'
        . '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18a8 8 0 0 1-4.1-1.1l-.3-.2-3 .8.8-2.9-.2-.3A8 8 0 1 1 12 20Zm4.4-6c-.2-.1-1.4-.7-1.6-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.5 6.5 0 0 1-1.9-1.2 7.3 7.3 0 0 1-1.4-1.7c-.1-.2 0-.4.1-.5l.4-.5c.1-.2.2-.3.3-.5 0-.2 0-.3 0-.5l-.8-1.9c-.2-.5-.4-.4-.6-.4h-.5c-.2 0-.5.1-.7.3-.2.3-.9.9-.9 2.1 0 1.2.9 2.4 1 2.6.1.2 1.7 2.7 4.2 3.8.6.3 1 .4 1.4.5.6.2 1.1.2 1.5.1.5-.1 1.4-.6 1.6-1.1.2-.6.2-1 .1-1.1Z"/></svg></a>';
}

function po_btn_ir($href, $label) {
    return '<a class="btn" href="' . po_e($href) . '">' . po_e($label)
        . '<svg viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/></svg></a>';
}

/* chips do hero (dias, data, local). Porte do antigo renderizador client-side. */
function po_hero_chips($r) {
    $chips = '';
    if (!empty($r['dias'])) {
        $chips .= '<span class="hero__chip"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>' . po_e($r['dias']) . ' dias</span>';
    }
    $per = !empty($r['data_label']) ? $r['data_label'] : ($r['periodo'] ?? '');
    if (!empty($per)) {
        $chips .= '<span class="hero__chip"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>' . po_e($per) . '</span>';
    }
    if (!empty($r['local_label'])) {
        $chips .= '<span class="hero__chip"><svg viewBox="0 0 24 24"><path d="M12 21s-7-6.3-7-11a7 7 0 0 1 14 0c0 4.7-7 11-7 11Z"/><circle cx="12" cy="10" r="2.5"/></svg>' . po_e($r['local_label']) . '</span>';
    }
    return $chips;
}

/* --reels-bg e uma custom property lida DENTRO de assets/css/roteiro.css
   (.intro__media:fullscreen::before), entao uma URL relativa ali seria
   resolvida contra a pasta do CSS (assets/css/) e daria 404 (mesmo bug que
   window.poIntroMedia evitava no navegador com new URL(...,document.baseURI)).
   Aqui nao ha DOM; se capa_url ja vier absoluta (o normal, e sempre do
   Supabase Storage), a funcao e um no-op. */
function po_abs_url($u) {
    $u = (string) $u;
    if ($u === '' || preg_match('~^[a-z][a-z0-9+.-]*://~i', $u)) return $u;
    return po_url($u);
}

/* A figura do "por que viajar" (capa ou reels do Instagram).
   Porte de roteiros-shared.js:61-85 (window.poIntroMedia). Mesma regra:
   tem video_insta_url -> video; nao tem -> capa parada com o selo de dias. */
function po_intro_media($r) {
    $capa    = po_e($r['capa_url'] ?? '');
    $capaAbsCss = po_css_url(po_abs_url($r['capa_url'] ?? ''));
    if (empty($r['video_insta_url'])) {
        $out = '<figure class="intro__photo reveal" data-delay="1"><img src="' . $capa . '" alt="' . po_e($r['titulo'] ?? '') . '">';
        if (!empty($r['dias'])) {
            $out .= '<figcaption class="intro__stamp"><b>' . po_e($r['dias']) . '</b><span>dias de viagem</span></figcaption>';
        }
        return $out . '</figure>';
    }
    return '<figure class="intro__media reveal" data-delay="1" style="--reels-bg:url(\'' . $capaAbsCss . '\')">'
        . '<video src="' . po_e($r['video_insta_url']) . '" poster="' . $capa . '" preload="none" playsinline '
        . 'controlslist="nofullscreen nodownload" disablepictureinpicture disableremoteplayback '
        . 'aria-label="Vídeo do roteiro ' . po_e($r['titulo'] ?? '') . '"></video>'
        . '<button class="intro__play" type="button" aria-label="Assistir ao vídeo do roteiro">'
        . '<span><svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg></span></button>'
        . '<button class="intro__fs" type="button" aria-label="Ver em tela cheia">'
        . '<svg viewBox="0 0 24 24"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/></svg></button>'
        . '<figcaption class="intro__stamp intro__stamp--video"><span>Assista ao vídeo do roteiro</span></figcaption>'
        . '</figure>';
}

/* O video do hero. Porte de roteiros-shared.js:99-107 (window.poHeroVideo),
   com uma diferenca deliberada: o JS consultava matchMedia('prefers-reduced-
   motion') no navegador e omitia o <video> quando o visitante pedia menos
   animacao. O servidor nao sabe a preferencia de quem esta pedindo a pagina,
   entao aqui o <video> SEMPRE sai; quem tem a preferencia ativa fica sem ver
   o video via CSS (assets/css/roteiro.css, regra @media prefers-reduced-motion
   escondendo .hero__vid). Custo aceito: quem pede menos animacao baixa o
   video (~1,3 MB) e nao o ve, em troca de a pagina existir para o Google. */
function po_hero_video($r) {
    if (empty($r['video_capa_url'])) return '';
    return '<video class="hero__vid" src="' . po_e($r['video_capa_url']) . '" '
        . 'autoplay muted loop playsinline preload="auto" '
        . 'disablepictureinpicture disableremoteplayback tabindex="-1" aria-hidden="true"></video>';
}

/* Faixa "Baixar roteiro em PDF". So sai quando o roteiro tem pdf_url
   (sem PDF a secao nao existe, como na home sem banco). O botao ABRE EM
   NOVA ABA (decisao do cliente: o publico 60+ prefere ver antes de baixar).
   O "documento" usa a capa do roteiro como imagem da folha. */
function po_pdf_section($r) {
    if (empty($r['pdf_url'])) return '';
    $pdf     = po_e($r['pdf_url']);
    $capaCss = po_css_url($r['capa_url'] ?? '');
    return '<section class="sec rpdf" id="pdf"><div class="wrap rpdf__grid">'
        . '<figure class="rpdf__doc reveal">'
        . '<div class="rpdf__sheet" style="background-image:url(\'' . $capaCss . '\')"></div>'
        . '<span class="rpdf__badge"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>PDF</span>'
        . '</figure>'
        . '<div class="rpdf__body reveal" data-delay="1">'
        . '<p class="tag sec__eyebrow"><span></span>Roteiro completo</p>'
        . '<h2 class="sec__h">Leve este roteiro com você.</h2>'
        . '<p class="rpdf__lead">Baixe o roteiro completo em PDF, com o dia a dia, os hotéis previstos, o que está incluído e os valores, no documento oficial da Pereira Oliveira. Guarde no celular ou imprima para decidir com calma.</p>'
        . '<a class="btn btn--dark rpdf__btn" href="' . $pdf . '" target="_blank" rel="noopener">Baixar roteiro em PDF'
        . '<svg viewBox="0 0 24 24"><path d="M12 3v12m0 0l-4-4m4 4l4-4M5 21h14" stroke-linecap="round" stroke-linejoin="round"/></svg></a>'
        . '<p class="rpdf__meta"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>Documento em PDF · preparado por nossa equipe</p>'
        . '</div></div></section>';
}

/* Corpo inteiro da pagina de roteiro. Porte 1:1 do antigo renderizador
   client-side (funcao render), na mesma ordem de secoes. */
function po_roteiro_html($r, ?array $outros = null) {
    $capa    = po_e($r['capa_url'] ?? '');
    $capaCss = po_css_url($r['capa_url'] ?? '');
    $days    = po_arr($r['roteiro_dias'] ?? null);
    $inclui  = po_arr($r['inclui'] ?? null);
    $naoInc  = po_arr($r['nao_inclui'] ?? null);
    $hoteis  = array_map(function ($h) {
        if (is_string($h)) return $h;
        if (is_array($h) && !empty($h['cidade'])) return po_str($h['cidade']) . ': ' . po_str($h['hotel'] ?? '');
        return '';
    }, po_arr($r['hoteis'] ?? null));
    $valores = po_arr($r['valores'] ?? null);
    $galeria = po_arr($r['galeria'] ?? null);
    $titulo  = (string) ($r['titulo'] ?? '');
    $slug    = (string) ($r['slug'] ?? '');
    $h = '';

    /* HERO. Breadcrumb trocado de relativo (index.html) para absoluto de
       raiz: esta pagina vive em /roteiros/<slug>, um nivel a mais de path. */
    $h .= '<section class="hero" id="topo"><div class="hero__media">'
        . '<div class="hero__poster" style="background-image:url(\'' . $capaCss . '\')"></div>'
        . po_hero_video($r)
        . '</div><div class="hero__scrim"></div><div class="hero__inner">'
        . '<p class="hero__crumb"><a href="/">Início</a><svg viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></svg><a href="/roteiros">Roteiros</a><svg viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></svg><span>' . po_e($titulo) . '</span></p>'
        . '<h1 class="hero__title">' . po_e($titulo) . '</h1>'
        . (!empty($r['subtitulo']) ? '<p class="hero__sub">' . po_e($r['subtitulo']) . '</p>' : '')
        . '<div class="hero__meta">' . po_hero_chips($r) . '</div>'
        . '<div class="hero__actions">' . po_btn_ir('#contato', 'Quero este roteiro') . po_btn_wa()
        . (count($valores) ? '<a class="btn btn--ghost" href="#investimento">Ver investimento</a>' : '') . '</div>'
        . '</div><a class="hero__scroll" href="#sobre" aria-label="Rolar"><svg viewBox="0 0 24 24"><path d="M6 9l6 6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg></a></section>';

    /* INTRO / por que viajar */
    $why = '';
    foreach (PO_WHY as $w) {
        $why .= '<li>' . PO_CHECK . '<p>' . $w . '</p></li>';
    }
    $h .= '<section class="sec intro" id="sobre"><div class="wrap intro__grid"><div class="reveal">'
        . '<p class="tag sec__eyebrow"><span></span>Por que viajar com a Pereira Oliveira</p>'
        . '<h2 class="sec__h">Cada detalhe pensado para você só aproveitar.</h2>'
        . (!empty($r['descricao_curta']) ? '<p class="intro__lead">' . po_e($r['descricao_curta']) . '</p>' : '')
        . '<ul class="why">' . $why . '</ul></div>'
        . po_intro_media($r) . '</div></section>';

    /* DIA A DIA */
    if (count($days)) {
        $h .= '<section class="sec days" id="roteiro"><div class="wrap"><div class="days__head reveal">'
            . '<p class="tag sec__eyebrow"><span></span>Roteiro dia a dia</p><h2 class="sec__h">O passo a passo da sua viagem.</h2></div><div class="tl">';
        foreach ($days as $d) {
            $d = is_array($d) ? $d : [];
            $dateline = implode(' · ', array_filter([po_str($d['data'] ?? ''), po_str($d['dia_semana'] ?? '')]));
            $n = $d['n'] ?? null;
            $h .= '<div class="day reveal"><div class="day__rail"><div class="day__n">' . ($n ? po_e($n) . 'º dia' : '') . '</div>'
                . ($dateline !== '' ? '<span class="day__date">' . po_e($dateline) . '</span>' : '') . '</div>'
                . '<div class="day__body">' . (!empty($d['cidades']) ? '<h3 class="day__city">' . po_e($d['cidades']) . '</h3>' : '')
                . (!empty($d['descricao']) ? '<p class="day__p">' . po_e($d['descricao']) . '</p>' : '')
                . (!empty($d['refeicoes']) ? '<div class="day__meals"><span class="pill"><b></b>' . po_e($d['refeicoes']) . '</span></div>' : '')
                . '</div></div>';
        }
        $h .= '</div></div></section>';
    }

    /* INFORMAÇÕES (inclui / não inclui / hotéis) */
    if (count($inclui) || count($naoInc) || count($hoteis)) {
        $stack = '';
        foreach (array_slice($galeria, 0, 3) as $u) {
            $stack .= '<img src="' . po_e($u) . '" alt="' . po_e($titulo) . '" loading="lazy">';
        }
        $h .= '<section class="sec info"><div class="wrap info__grid"><div class="reveal">'
            . '<p class="tag sec__eyebrow"><span></span>Informações do roteiro</p><h2 class="sec__h" style="margin-bottom:22px">O que está incluído.</h2>';
        if (count($inclui)) {
            $li = '';
            foreach ($inclui as $i) $li .= '<li>' . PO_CHECK . '<p>' . po_e($i) . '</p></li>';
            $h .= '<ul class="why">' . $li . '</ul>';
        }
        if (count($hoteis)) {
            $li = '';
            foreach (array_filter($hoteis) as $i) $li .= '<li>' . PO_CHECK . '<p>' . po_e($i) . '</p></li>';
            $h .= '<h3 class="sec__h" style="font-size:1.15rem;margin:28px 0 12px">Hotéis previstos</h3><ul class="why">' . $li . '</ul>';
        }
        if (count($naoInc)) {
            $h .= '<h3 class="sec__h" style="font-size:1.15rem;margin:28px 0 12px">O pacote não inclui</h3><p class="intro__lead" style="font-size:1rem">' . implode(' · ', array_map('po_e', $naoInc)) . '</p>';
        }
        $h .= '</div>' . ($stack !== '' ? '<div class="info__stack reveal" data-delay="1">' . $stack . '</div>' : '') . '</div></section>';
    }

    /* INVESTIMENTO */
    if (count($valores)) {
        $h .= '<section class="sec invest" id="investimento"><div class="wrap"><div class="invest__head reveal">'
            . '<p class="tag" style="margin-bottom:14px">Investimento</p><h2>Valor por pessoa.</h2><p>Investimento aproximado por pessoa. Valores sujeitos a alteração, apenas cotação.</p></div>'
            . '<div class="invest__cards' . (count($valores) < 2 ? ' invest__cards--single' : '') . '">';
        foreach ($valores as $i => $v) {
            $v = is_array($v) ? $v : [];
            $h .= '<div class="pcard' . ($i === 0 ? ' pcard--feat' : '') . ' reveal">'
                . (!empty($v['tag']) ? '<div class="pcard__tag">' . po_e($v['tag']) . '</div>' : '')
                . (!empty($v['de']) ? '<div class="pcard__from">' . po_e($v['de']) . '</div>' : '')
                . '<div class="pcard__val">' . po_e($v['valor'] ?? '') . '</div>'
                . (!empty($v['extra']) ? '<p class="pcard__extra">' . po_e($v['extra']) . '</p>' : '') . '</div>';
        }
        $h .= '</div><div class="invest__foot reveal"><p class="invest__note">Valores sujeitos a alteração sem aviso prévio. Apenas cotação.</p>'
            . '<div class="invest__actions">' . po_btn_ir('#contato', 'Quero este roteiro') . po_btn_wa()
            . '</div></div></div></section>';
    }

    /* PDF (download). So aparece quando o roteiro tem pdf_url; a faixa
       fica logo apos o INVESTIMENTO (o visitante viu o preco, agora leva
       o roteiro inteiro para decidir com calma). */
    $h .= po_pdf_section($r);

    /* GALERIA */
    if (count($galeria)) {
        $items = '';
        foreach ($galeria as $i => $u) {
            $items .= '<figure class="gal__item reveal"><img src="' . po_e($u) . '" alt="' . po_e($titulo) . ', imagem ' . ($i + 1) . '" loading="lazy"></figure>';
        }
        $h .= '<section class="sec gal" id="galeria"><div class="wrap"><div class="gal__head reveal"><div>'
            . '<p class="tag sec__eyebrow"><span></span>Galeria</p><h2 class="sec__h">Um aperitivo do que espera por você.</h2></div></div>'
            . '<div class="gal__grid" id="gal-grid">' . $items . '</div></div></section>';
    }

    /* OUTROS ROTEIROS. Diferenca deliberada do JS original: o markup e
       server-side (link interno e sinal de SEO e nao pode depender de
       JavaScript). O JS deixava #more-track vazio e preenchia depois via
       fetch; aqui os <a> de cada outro roteiro ja saem no HTML. */
    $h .= '<section class="more" id="outros"><div class="wrap"><div class="more__head"><div class="reveal">'
        . '<p class="tag sec__eyebrow"><span></span>Outros roteiros</p><h2>Talvez o próximo destino seja outro.</h2></div>'
        . '<div class="more__nav reveal" data-delay="1"><button id="more-prev" aria-label="Anteriores"><svg viewBox="0 0 24 24"><path d="M15 6l-6 6 6 6"/></svg></button>'
        . '<button id="more-next" aria-label="Próximos"><svg viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></svg></button></div></div>'
        . '<div class="rot-viewport"><div class="rot-track" id="more-track" data-current="' . po_e($slug) . '">'
        . po_outros_roteiros($slug, $outros) . '</div></div>'
        . '<p class="more__count" id="more-count" aria-hidden="true"></p></div></section>';

    /* MODAL do lead. No JS original ele vivia num mount point separado
       (#rt-modal-mount, fora do <main>) porque render() e renderModal()
       eram populados em dois innerHTML distintos. No servidor nao ha
       essa necessidade: o modal e position:fixed (cobre a tela inteira
       via CSS independente de onde mora no DOM), entao ele sai aqui
       dentro do proprio corpo, junto do resto da pagina. */
    $h .= po_roteiro_modal($r);

    return $h;
}

/* Cartoes de "outros roteiros", buscados no banco e emitidos no HTML
   (pula o roteiro atual). Mesmo markup que assets/js/roteiro.js:168-176
   gerava no navegador (article.rot-card com o link de verdade no
   .rot-card__cta), so que aqui ja sai pronto no HTML: link interno e
   sinal de SEO e nao pode depender de JavaScript para existir.

   $lista aceita injecao (null = busca no banco como em producao; um array,
   mesmo vazio, pula a rede). E o que permite tests/test-roteiro-page.php
   testar po_roteiro_html() sem bater no Supabase de verdade - o mesmo
   padrao de tests/test-po-data.php via po_set_fetcher(). */
function po_outros_roteiros($slugAtual, ?array $lista = null) {
    if ($lista === null) $lista = po_fetch_roteiros();
    $out = '';
    foreach ($lista as $r) {
        if (($r['slug'] ?? '') === $slugAtual) continue;
        $titulo = (string) ($r['titulo'] ?? '');
        $local  = !empty($r['local_label']) ? $r['local_label'] : (!empty($r['subtitulo']) ? $r['subtitulo'] : '');
        $data   = !empty($r['data_label']) ? $r['data_label'] : (!empty($r['periodo']) ? $r['periodo'] : '');
        $badge  = !empty($r['badge']) ? $r['badge'] : (!empty($r['dias']) ? $r['dias'] . ' dias' : '');
        $img    = (string) ($r['capa_url'] ?? '');
        $href   = po_roteiro_href($r);
        $out .= '<article class="rot-card">'
            . '<div class="rot-card__img" style="background-image:url(\'' . po_css_url($img) . '\')"></div>'
            . '<div class="rot-card__body">'
            . '<div class="rot-card__title"><h3>' . po_e($titulo) . '</h3><span class="rot-card__badge">' . po_e($badge) . '</span></div>'
            . '<div class="rot-card__sub"><b></b>' . po_e($local) . ' · ' . po_e($data) . '</div>'
            . '<a class="rot-card__cta" href="' . po_e($href) . '">Ver roteiro<svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>'
            . '</div></article>';
    }
    return $out;
}

/* Modal do formulario de lead. Porte 1:1 do antigo renderizador client-side. */
function po_roteiro_modal($r) {
    $titulo = (string) ($r['titulo'] ?? '');
    $slug   = (string) ($r['slug'] ?? '');
    return '<div class="lead-modal" id="lead-modal" data-open="false" aria-hidden="true"><div class="lead-modal__scrim" data-close></div>'
        . '<div class="lead-modal__card" role="dialog" aria-modal="true" aria-label="Quero este roteiro">'
        . '<button class="lead-modal__close" data-close aria-label="Fechar">&times;</button>'
        . '<p class="tag sec__eyebrow"><span></span>Fale com a gente</p><h2 class="lead-modal__h">Quero este roteiro</h2>'
        . '<p class="lead-modal__lead">Preencha seus dados e nossa equipe entra em contato com os valores atualizados, condições e disponibilidade deste roteiro.</p>'
        . '<form class="form" id="lead-form" novalidate><div class="form__grid">'
        . '<div class="field field--full"><label for="f-nome">Nome completo <b>*</b></label><input type="text" id="f-nome" name="nome" autocomplete="name" required></div>'
        . '<div class="field"><label for="f-tel">WhatsApp / telefone <b>*</b></label><input type="tel" id="f-tel" name="telefone" autocomplete="tel" required></div>'
        . '<div class="field"><label for="f-email">E-mail <b>*</b></label><input type="email" id="f-email" name="email" autocomplete="email" required></div>'
        . '<div class="field"><label for="f-cidade">Cidade</label><input type="text" id="f-cidade" name="cidade" autocomplete="address-level2"></div>'
        . '<div class="field"><label for="f-viajantes">Nº de viajantes</label><select id="f-viajantes" name="viajantes"><option value="">Selecione</option><option>1 pessoa</option><option>2 pessoas</option><option>3 a 4 pessoas</option><option>5 ou mais</option></select></div>'
        . '<div class="field field--full"><label for="f-roteiro">Roteiro de interesse</label><input type="text" id="f-roteiro" name="roteiro" value="' . po_e($titulo) . '" readonly></div>'
        . '<div class="field field--full"><label for="f-msg">Mensagem</label><textarea id="f-msg" name="mensagem" placeholder="Conte pra gente o que você gostaria de saber."></textarea></div>'
        . '<div class="field--hp" aria-hidden="true"><label for="f-site">Não preencha este campo</label><input type="text" id="f-site" name="website" tabindex="-1" autocomplete="off"></div></div>'
        . '<input type="hidden" name="_t" id="f-t"><input type="hidden" name="origem" value="site-roteiro-' . po_e($slug) . '">'
        . '<input type="hidden" name="utm_source" id="f-utm_source"><input type="hidden" name="utm_medium" id="f-utm_medium"><input type="hidden" name="utm_campaign" id="f-utm_campaign">'
        . '<input type="hidden" name="gclid" id="f-gclid"><input type="hidden" name="referrer" id="f-referrer"><input type="hidden" name="landing_page" id="f-landing">'
        . '<button type="submit" class="btn form__submit">Enviar roteiro<svg viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/></svg></button>'
        . '<p class="form__legal">Seus dados são usados apenas para este atendimento. Retornamos pelo WhatsApp ou e-mail informado.</p>'
        . '<div class="form__msg" id="form-msg"></div></form></div></div>';
}

/* Lightbox da galeria, porte 1:1 das antigas paginas de roteiro (a Task 7
   apagou todas elas, estaticas e a dinamica em JS). assets/js/roteiro.js
   procura id="lb" e falha em silencio se nao encontrar (if (grid && lb ...))
   - sem este bloco a galeria fica sem clique, sem erro nenhum no console. */
function po_lightbox_html() {
    return '<div class="lb" id="lb" data-open="false" aria-hidden="true">'
        . '<button class="lb__close" id="lb-close" aria-label="Fechar">×</button>'
        . '<button class="lb__btn lb__prev" id="lb-prev" aria-label="Anterior"><svg viewBox="0 0 24 24"><path d="M15 6l-6 6 6 6"/></svg></button>'
        . '<img id="lb-img" src="" alt="">'
        . '<button class="lb__btn lb__next" id="lb-next" aria-label="Próxima"><svg viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></svg></button>'
        . '<span class="lb__count" id="lb-count"></span></div>';
}

/* JSON-LD TouristTrip. offers so sai com preco de verdade: preco inventado
   no schema, ou divergente do que a pagina mostra, e violacao de diretriz
   do Google. */
function po_roteiro_jsonld($r) {
    $j = [
        '@context'    => 'https://schema.org',
        '@type'       => 'TouristTrip',
        'name'        => (string) ($r['titulo'] ?? ''),
        'description' => (string) ($r['descricao_curta'] ?? ''),
        'url'         => po_url(po_roteiro_href($r)),
        'provider'    => [
            '@type' => 'TravelAgency',
            'name'  => 'Pereira Oliveira Turismo',
            'url'   => PO_BASE,
        ],
    ];
    if (!empty($r['capa_url'])) $j['image'] = $r['capa_url'];
    $v = po_arr($r['valores'] ?? []);
    if (count($v) && !empty($v[0]['valor'])) {
        $raw   = (string) $v[0]['valor'];
        $moeda = (strpos($raw, '€') !== false) ? 'EUR' : ((strpos($raw, 'US$') !== false) ? 'USD' : 'BRL');
        $num   = preg_replace('~[^\d,\.]~', '', $raw);
        $num   = str_replace(['.', ','], ['', '.'], $num);
        if ($num !== '' && is_numeric($num)) {
            $j['offers'] = [
                '@type'         => 'Offer',
                'price'         => $num,
                'priceCurrency' => $moeda,
                'availability'  => 'https://schema.org/InStock',
                'url'           => po_url(po_roteiro_href($r)),
            ];
        }
    }
    return $j;
}

if (!defined('PO_TEST')) {
    $slug = isset($_GET['slug']) ? (string) $_GET['slug'] : '';
    $r    = $slug === '' ? null : po_fetch_roteiro($slug);
    if (!$r) {
        // 404 DE VERDADE. Servir 200 com "nao encontrado" cria soft-404:
        // o Google indexa a pagina de erro.
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        readfile(__DIR__ . '/404.html');
        exit;
    }
    $desc = trim((string) ($r['descricao_curta'] ?? ''));
    if ($desc === '') $desc = 'Viagem em grupo para ' . $r['titulo'] . ' com saída de Florianópolis e acompanhamento da Pereira Oliveira Turismo, desde 1967.';
    header('Content-Type: text/html; charset=utf-8');
    echo po_head([
        'title'       => po_titulo_seo($r),
        'description' => mb_substr($desc, 0, 155),
        'canonical'   => po_url(po_roteiro_href($r)),
        'og_image'    => $r['capa_url'] ?? po_url('/assets/images/roteiro-grecia.jpg'),
        'css'         => ['/assets/css/roteiro.css?v=8'],
        'jsonld'      => po_roteiro_jsonld($r),
    ]);
    echo po_header();
    // po_roteiro_html() ja embute o modal do lead no proprio corpo (ver
    // comentario dentro da funcao); nao chamar po_roteiro_modal() de novo
    // aqui, senao duplica id="lead-modal"/id="f-roteiro" na pagina.
    echo '<main id="rt-main">' . po_roteiro_html($r) . '</main>';
    echo po_footer();
    echo po_wa_float();
    // #lb (lightbox da galeria): assets/js/roteiro.js procura este id; sem ele
    // a galeria fica sem clique, em silencio (ver comentario em po_lightbox_html()).
    echo po_lightbox_html();
    echo '<script src="/assets/js/roteiro.js?v=5"></script>' . "\n";
    echo '<script src="/assets/js/lead-form.js?v=3"></script>' . "\n";
    echo '</body></html>';
}
