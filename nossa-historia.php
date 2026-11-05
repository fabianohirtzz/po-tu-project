<?php
/* Nossa Historia servida pelo servidor (22/10/2026), no mesmo esquema da home:
   o nossa-historia.html continua sendo a pagina (editada como sempre), e este
   arquivo so troca os marcadores pelas avaliacoes do Google e pelos posts do
   Instagram cadastrados no painel. Assim o texto das avaliacoes esta no HTML,
   e nao so depois de um JavaScript. Banco fora do ar: as duas secoes somem e o
   resto da pagina sai igual. */

require_once __DIR__ . '/lib/po-data.php';
require_once __DIR__ . '/lib/po-view.php';
require_once __DIR__ . '/lib/po-social.php';

if (!defined('PO_TEST')) {
    header('Content-Type: text/html; charset=utf-8');
    echo po_social_render(file_get_contents(__DIR__ . '/nossa-historia.html'), [
        PO_MARCA_AVALIACOES => po_avaliacoes_secao_html(po_fetch_avaliacoes()),
        PO_MARCA_INSTAGRAM  => po_instagram_secao_html(po_fetch_instagram()),
    ]);
}
