<?php
/* ============================================================
   Prova social do site (22/10/2026): avaliacoes do Google e posts do
   Instagram, cadastrados pela cliente na aba "Prova social" do painel.

   Montado no servidor (nossa-historia.php e index.php trocam marcadores do
   HTML por estas secoes) para o texto das avaliacoes existir no HTML, e nao
   so depois de um JavaScript.

   SEM AggregateRating / microdata de review: avaliacao que a propria empresa
   escolhe e hospeda nao pode virar estrela na busca. E diretriz do Google, com
   risco de acao manual. Aqui a avaliacao e conversao, nao SEO de estrela.
============================================================ */

const PO_GOOGLE_PERFIL = 'https://www.google.com/maps?cid=10478700579601202326';
const PO_INSTAGRAM     = 'https://www.instagram.com/pereiraoliveiraturismo/';

const PO_MARCA_AVALIACOES       = '<!--PO:AVALIACOES-->';
const PO_MARCA_INSTAGRAM        = '<!--PO:INSTAGRAM-->';
const PO_MARCA_AVALIACOES_SOBRE = '<!--PO:AVALIACOES-SOBRE-->';

function po_fetch_avaliacoes() {
    $d = po_rest_tabela('po_avaliacoes', 'select=nome,texto,nota,data_label&ativo=eq.true&order=ordem.asc,created_at.asc');
    return $d === null ? [] : $d;
}

/* Os 12 primeiros na ordem do painel (setas), so os que tem video. */
function po_fetch_instagram() {
    $d = po_rest_tabela('po_instagram', 'select=video_url,poster_url,url&ativo=eq.true&video_url=not.is.null&order=ordem.asc,created_at.desc&limit=12');
    return $d === null ? [] : $d;
}

function po_estrelas($n = 5) {
    $n = max(1, min(5, (int) $n));
    $s = '<span class="po-stars" role="img" aria-label="' . $n . ' de 5 estrelas">';
    for ($i = 0; $i < 5; $i++) {
        $s .= '<svg viewBox="0 0 24 24" class="' . ($i < $n ? 'on' : 'off') . '" aria-hidden="true"><path d="M12 2.5l2.9 6 6.6.8-4.9 4.6 1.3 6.5L12 17.2l-5.9 3.2 1.3-6.5L2.5 9.3l6.6-.8z"/></svg>';
    }
    return $s . '</span>';
}

/* "G" colorido do Google. aria-label so quando ele substitui texto (nos
   cards); no selo o texto ao lado ja diz Google. */
function po_google_g($classe, $rotulo = '') {
    $a11y = $rotulo !== '' ? 'role="img" aria-label="' . $rotulo . '"' : 'aria-hidden="true"';
    return '<svg class="' . $classe . '" viewBox="0 0 24 24" ' . $a11y . '><path fill="#4285F4" d="M22.6 12.3c0-.8-.1-1.5-.2-2.3H12v4.3h6a5 5 0 0 1-2.2 3.3v2.7h3.5c2.1-1.9 3.3-4.7 3.3-8z"/><path fill="#34A853" d="M12 23c3 0 5.5-1 7.3-2.7l-3.5-2.7c-1 .7-2.3 1.1-3.8 1.1-2.9 0-5.4-2-6.3-4.6H2.1v2.8A11 11 0 0 0 12 23z"/><path fill="#FBBC05" d="M5.7 14.1a6.6 6.6 0 0 1 0-4.2V7.1H2.1a11 11 0 0 0 0 9.8z"/><path fill="#EA4335" d="M12 5.4c1.6 0 3.1.6 4.2 1.7l3.1-3.1A11 11 0 0 0 2.1 7.1l3.6 2.8C6.6 7.4 9.1 5.4 12 5.4z"/></svg>';
}

function po_avaliacao_html($a) {
    $data = isset($a['data_label']) && is_string($a['data_label']) && trim($a['data_label']) !== '' ? $a['data_label'] : '';
    return '<figure class="rev"><div class="rev__top">' . po_estrelas($a['nota'] ?? 5)
        . po_google_g('rev__g', 'Avaliação no Google') . '</div>'
        . '<blockquote class="rev__t"><p>' . po_e($a['texto'] ?? '') . '</p></blockquote>'
        . '<figcaption class="rev__a"><b>' . po_e($a['nome'] ?? '') . '</b>'
        . ($data !== '' ? '<span>' . po_e($data) . '</span>' : '')
        . '</figcaption></figure>';
}

function po_google_selo() {
    return '<a class="rev-selo" href="' . PO_GOOGLE_PERFIL . '" target="_blank" rel="noopener">'
        . po_google_g('rev-selo__g')
        . '<span><b>5 estrelas no Google</b>' . po_estrelas(5) . '</span></a>';
}

/* Nossa Historia: todas as ativas, em grade. */
function po_avaliacoes_secao_html($lista) {
    if (!count($lista)) return '';
    $h = '<section class="rev-sec" id="avaliacoes"><div class="his__wrap">'
        . '<div class="rev-sec__head his-reveal"><div>'
        . '<p class="his__tag">Quem viajou com a gente</p>'
        . '<h2>O que dizem nossos viajantes.</h2>'
        . '<p>Avaliações reais do nosso perfil no Google, escritas por quem já viajou em grupo com a Pereira Oliveira.</p>'
        . '</div>' . po_google_selo() . '</div><div class="rev-grid">';
    foreach ($lista as $a) $h .= po_avaliacao_html($a);
    return $h . '</div><p class="rev-sec__mais"><a class="btn btn--ghost-ink" href="' . PO_GOOGLE_PERFIL . '" target="_blank" rel="noopener">Ver todas as avaliações no Google</a></p>'
        . '</div></section>';
}

/* Painel "Sobre" da home: selo + as 3 primeiras. */
function po_avaliacoes_sobre_html($lista) {
    if (!count($lista)) return '';
    $h = '<h3>O que dizem nossos viajantes</h3>' . po_google_selo() . '<div class="about__revs">';
    foreach (array_slice($lista, 0, 3) as $a) $h .= po_avaliacao_html($a);
    return $h . '</div><p><a href="' . PO_GOOGLE_PERFIL . '" target="_blank" rel="noopener">Ver todas as avaliações no Google</a></p>';
}

/* Instagram com VIDEO (22/10/2026), no padrao da Casa Angelina: a cliente sobe o
   reels pelo painel (upload-video.php, tipo "feed") e o painel tira o primeiro
   quadro como capa. video_url, poster_url e url vem do banco: so entra video do
   nosso /videos, capa do nosso /images/insta ou do storage do projeto, e link de
   post do instagram.com. Sem a guarda, um valor torto viraria atributo injetado
   ou midia de outro site. */
const PO_INSTA_POSTER_STORAGE = 'https://euzmbswywwhmicjlszqw.supabase.co/storage/v1/object/public/po-imagens/';

function po_insta_item_ok($p) {
    $v = $p['video_url'] ?? ''; $c = $p['poster_url'] ?? ''; $u = $p['url'] ?? null;
    if (!is_string($v) || !preg_match('~^(https://pereiraoliveiraturismo\.com\.br)?/videos/[a-z0-9-]+\.mp4$~', $v)) return false;
    if (!is_string($c) || !(preg_match('~^/images/insta/[A-Za-z0-9_-]+\.(jpg|png|webp)$~', $c)
        || (strpos($c, PO_INSTA_POSTER_STORAGE) === 0 && preg_match('~^[A-Za-z0-9_./%-]+$~', substr($c, strlen(PO_INSTA_POSTER_STORAGE)))))) return false;
    if ($u !== null && $u !== '' && !(is_string($u) && preg_match('~^https://www\.instagram\.com/[A-Za-z0-9_./-]+$~', $u))) return false;
    return true;
}

/* Grade de capas, sem nenhum <video>: para o publico 60+ no 4G, nada pesado
   baixa antes da interacao. O assets/js/insta-feed.js toca a previa muda no
   hover (desktop) e abre o visualizador com som no clique. Sem JS, o botao
   abre o post (ou o perfil) no Instagram. */
function po_instagram_secao_html($lista) {
    $itens = array_slice(array_values(array_filter($lista, 'po_insta_item_ok')), 0, 12);
    if (!count($itens)) return '';
    $h = '<section class="ig" id="instagram"><div class="his__wrap">'
        . '<div class="ig__head his-reveal"><div><p class="his__tag">@pereiraoliveiraturismo</p>'
        . '<h2>Acompanhe nossas viagens no Instagram.</h2>'
        . '<p>Os grupos pelo mundo, os bastidores e as próximas saídas, contados por quem está lá.</p></div>'
        . '<div class="rot__nav ig__nav"><button type="button" data-ig="prev" aria-label="Vídeos anteriores"><svg viewBox="0 0 24 24"><path d="M15 6l-6 6 6 6"/></svg></button>'
        . '<button type="button" data-ig="next" aria-label="Próximos vídeos"><svg viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></svg></button></div></div>'
        . '<div class="ig__grid">';
    foreach ($itens as $p) {
        $post = (!empty($p['url'])) ? $p['url'] : PO_INSTAGRAM;
        $h .= '<a class="ig__item" href="' . po_e($post) . '" target="_blank" rel="noopener"'
            . ' data-video="' . po_e($p['video_url']) . '" data-post="' . po_e($post) . '" aria-label="Assistir ao vídeo">'
            . '<img src="' . po_e($p['poster_url']) . '" alt="Vídeo do Instagram da Pereira Oliveira Turismo" loading="lazy" decoding="async">'
            . '<span class="ig__play" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 5.5v13l11-6.5z"/></svg></span></a>';
    }
    $h .= '</div><p class="ig__follow"><a class="btn btn--ig" href="' . PO_INSTAGRAM . '" target="_blank" rel="noopener">Seguir no Instagram</a></p></div></section>';
    // visualizador (um so, preenchido pelo insta-feed.js)
    $h .= '<div class="ig-lb" id="ig-lb" role="dialog" aria-modal="true" aria-label="Vídeo do Instagram" hidden>'
        . '<button type="button" class="ig-lb__close" aria-label="Fechar">&times;</button>'
        . '<button type="button" class="ig-lb__nav ig-lb__nav--prev" aria-label="Vídeo anterior"><svg viewBox="0 0 24 24"><path d="M15 6l-6 6 6 6"/></svg></button>'
        . '<figure class="ig-lb__fig"><video class="ig-lb__video" controls playsinline></video>'
        . '<figcaption><a class="ig-lb__link" href="' . PO_INSTAGRAM . '" target="_blank" rel="noopener">Ver no Instagram</a></figcaption></figure>'
        . '<button type="button" class="ig-lb__nav ig-lb__nav--next" aria-label="Próximo vídeo"><svg viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></svg></button>'
        . '</div>';
    return $h;
}

/* Troca cada marcador pelo seu conteudo. Marcador ausente: nada muda. */
function po_social_render($html, array $trocas) {
    foreach ($trocas as $marca => $conteudo) {
        $html = str_replace($marca, $conteudo, $html);
    }
    return $html;
}
