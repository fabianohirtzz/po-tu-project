<?php
/* Pereira Oliveira: pagina mae "/roteiros", renderizada no servidor.

   Existe porque a home e uma tela cheia travada (position:fixed, sem
   rolagem) que so mostra um roteiro por vez num carrossel via JS. Isso
   e quase nenhum texto rastreavel: sem esta pagina, cada pagina de
   roteiro fica orfa, sem nada apontando pra ela com o texto certo, e o
   site nao disputa buscas genericas do tipo "viagem em grupo terceira
   idade" ou "excursao saindo de Florianopolis". Publico de 60+ busca
   "excursao", nao "roteiro" (jargao de blogueiro) - por isso o titulo
   e a descricao (abaixo, no po_head) usam "excursao"; o diferencial
   que a Pereira Oliveira disputa sem concorrencia direta nao e o
   destino (isso o mundo inteiro tem), e grupo + coordenador brasileiro
   + saida de Florianopolis, e essa combinacao esta no h1 e no lead. */

require_once __DIR__ . '/lib/po-data.php';
require_once __DIR__ . '/lib/po-view.php';

function po_roteiros_jsonld($lista) {
    $itens = [];
    foreach ($lista as $i => $r) {
        $itens[] = [
            '@type'    => 'ListItem',
            'position' => $i + 1,
            'name'     => (string) ($r['titulo'] ?? ''),
            'url'      => po_url(po_roteiro_href($r)),
        ];
    }
    return ['@context' => 'https://schema.org', '@type' => 'ItemList', 'itemListElement' => $itens];
}

/* Botao de WhatsApp reaproveitando o mesmo svg de po_header()/po_footer()
   (lib/po-view.php). Nao existe um po_btn_wa() compartilhado em po-view.php
   (o de roteiro.php e local aquele arquivo); duplicar aqui e mais simples
   do que criar acoplamento entre roteiros.php e roteiro.php so por causa
   de um botao. */
function po_roteiros_btn_wa($label) {
    return '<a class="btn btn--wa" href="https://wa.me/5548996048882" target="_blank" rel="noopener">' . po_e($label)
        . '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18a8 8 0 0 1-4.1-1.1l-.3-.2-3 .8.8-2.9-.2-.3A8 8 0 1 1 12 20Zm4.4-6c-.2-.1-1.4-.7-1.6-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.5 6.5 0 0 1-1.9-1.2 7.3 7.3 0 0 1-1.4-1.7c-.1-.2 0-.4.1-.5l.4-.5c.1-.2.2-.3.3-.5 0-.2 0-.3 0-.5l-.8-1.9c-.2-.5-.4-.4-.6-.4h-.5c-.2 0-.5.1-.7.3-.2.3-.9.9-.9 2.1 0 1.2.9 2.4 1 2.6.1.2 1.7 2.7 4.2 3.8.6.3 1 .4 1.4.5.6.2 1.1.2 1.5.1.5-.1 1.4-.6 1.6-1.1.2-.6.2-1 .1-1.1Z"/></svg></a>';
}

function po_roteiros_html($lista) {
    $h  = '<section class="rts-hero"><div class="wrap">';
    $h .= '<p class="tag sec__eyebrow"><span></span>Próximas viagens</p>';
    $h .= '<h1 class="rts-hero__h">Viagens em grupo com saída de Florianópolis</h1>';
    $h .= '<p class="rts-hero__lead">A Pereira Oliveira organiza viagens em grupo desde 1967. '
        . 'Um coordenador brasileiro acompanha o grupo desde a saída do Brasil, os hotéis e os '
        . 'traslados já estão contratados, e as datas são fixas. Você viaja sem montar roteiro, '
        . 'sem dirigir e sem se preocupar com idioma.</p>';
    // CTA sempre presente: o formulario de lead da home ainda nao existe
    // (ver CLAUDE.md, "Proximo"), entao o WhatsApp e o canal de contato
    // que funciona hoje, com ou sem roteiro cadastrado no banco.
    $h .= '<div class="rts-hero__actions">' . po_roteiros_btn_wa('Falar no WhatsApp') . '</div>';
    $h .= '</div></section>';

    $h .= '<section class="rts"><div class="wrap">';
    if (!count($lista)) {
        $h .= '<div class="rts__empty">'
            . '<p>Estamos montando as próximas saídas. Fale com a gente pelo WhatsApp para saber das novidades.</p>'
            . po_roteiros_btn_wa('Falar no WhatsApp')
            . '</div>';
    } else {
        $h .= '<div class="rts__grid">';
        foreach ($lista as $r) {
            $href  = po_e(po_roteiro_href($r));
            $badge = $r['badge'] ?? (!empty($r['dias']) ? $r['dias'] . ' dias' : '');
            // !empty(), nao ??: no banco data_label chega como string vazia
            // (nao null) quando so periodo esta preenchido, e '' ?? $x
            // devolve '' (o operador so cai no fallback com null/unset).
            // Confirmado nos 6 roteiros reais: todos tem data_label='' e
            // periodo com a data de verdade - com ?? o card ficava sem
            // data nenhuma, o ponto inteiro desta pagina.
            $data  = !empty($r['data_label']) ? $r['data_label'] : ($r['periodo'] ?? '');
            $h .= '<article class="rts__card reveal">'
                . '<a class="rts__link" href="' . $href . '">'
                . '<div class="rts__img"><img src="' . po_e($r['capa_url'] ?? '') . '" alt="' . po_e($r['titulo'] ?? '') . '" loading="lazy"></div>'
                . '<div class="rts__body">'
                . ($badge ? '<span class="rts__badge">' . po_e($badge) . '</span>' : '')
                . '<h2 class="rts__h">' . po_e($r['titulo'] ?? '') . '</h2>'
                . ($data ? '<p class="rts__date">' . po_e($data) . '</p>' : '')
                . ($r['descricao_curta'] ?? '' ? '<p class="rts__desc">' . po_e($r['descricao_curta']) . '</p>' : '')
                . '<span class="rts__cta">Ver roteiro</span>'
                . '</div></a></article>';
        }
        $h .= '</div>';
    }
    $h .= '</div></section>';
    return $h;
}

if (!defined('PO_TEST')) {
    $lista = po_fetch_roteiros();
    header('Content-Type: text/html; charset=utf-8');
    echo po_head([
        'title'       => 'Viagens em grupo saindo de Florianópolis · Pereira Oliveira Turismo',
        'description' => 'Excursões em grupo com coordenador brasileiro, datas fixas e saída de Florianópolis. A Pereira Oliveira organiza viagens desde 1967.',
        'canonical'   => po_url('/roteiros'),
        'og_image'    => po_url('/assets/images/roteiro-grecia.jpg'),
        'css'         => ['/assets/css/roteiro.css?v=7', '/assets/css/roteiros.css?v=1'],
        'jsonld'      => po_roteiros_jsonld($lista),
    ]);
    echo po_header();
    echo '<main>' . po_roteiros_html($lista) . '</main>';
    echo po_footer();
    echo po_wa_float();
    // Liga o menu mobile (burger/sheet) e o reveal-on-scroll dos cards
    // (.reveal fica opacity:0 ate um script adicionar .is-in). O resto do
    // arquivo (video do hero, lightbox da galeria, carrossel "outros
    // roteiros") e no-op aqui: initRoteiro() checa a existencia de cada
    // elemento antes de ligar qualquer coisa.
    echo '<script src="/assets/js/roteiro.js?v=4"></script>' . "\n";
    echo '</body></html>';
}
