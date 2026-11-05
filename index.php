<?php
/* Home servida pelo servidor (22/10/2026).

   O index.html continua sendo a home, editada como sempre. Este arquivo so le
   o index.html e troca PO_HOME_MARCA (dentro do painel "Sobre") pela lista das
   viagens visiveis no site, vinda do banco. Assim o texto que o Google e as IAs
   leem na home nunca anuncia roteiro oculto nem esquece roteiro novo, e o
   index.html segue sem nenhum dado de roteiro escrito a mao (regra do projeto:
   dado de roteiro em HTML estatico envelhece).

   Usa po_fetch_roteiros() (ativo=true) com o cache de 10 min do po-data.php,
   que o painel limpa ao salvar ou excluir (limpa-cache.php). Banco fora do ar:
   a lista vira um link para /roteiros e o resto da home sai igual. */

require_once __DIR__ . '/lib/po-data.php';
require_once __DIR__ . '/lib/po-view.php';
require_once __DIR__ . '/lib/po-social.php';

const PO_HOME_MARCA = '<!--PO:PROXIMAS-VIAGENS-->';

function po_home_viagens_html($lista) {
    if (!count($lista)) {
        return '<p>A lista atualizada das próximas saídas está na <a href="/roteiros">página de roteiros</a>.</p>';
    }
    $h = '<ul class="about__viagens">';
    foreach ($lista as $r) {
        $partes = [];
        if (!empty($r['dias'])) $partes[] = (int) $r['dias'] . ' dias';
        $data = po_quando($r);
        if (is_string($data) && trim($data) !== '') $partes[] = trim($data);
        $h .= '<li><a href="' . po_e(po_roteiro_href($r)) . '">' . po_e($r['titulo'] ?? '') . '</a>'
            . (count($partes) ? ', ' . po_e(implode(', ', $partes)) : '')
            . po_status_tag($r, 'about__st') . '</li>';
    }
    return $h . '</ul>';
}

/* Data de saida (AAAA-MM-DD) tirada do texto que a cliente escreve no painel
   ("De 27/10/26 à 12/11/26"): o banco nao tem coluna de data. A primeira data
   do texto e a saida; ano com 2 digitos vira 20xx. Sem data legivel: null. */
/* po_data_saida e po_por_saida viviam AQUI e so serviam a lista do painel
   "Sobre". Foram para lib/po-datas.php em 05/11/2026, quando o cliente pediu
   ordem cronologica em todo lugar que mostra roteiro.

   Elas tambem DIVERGIAM do robo: a versao daqui lia `data_label` antes de
   `periodo`, e wa_roteiro_data_ini lia `periodo` antes - ordem de campo
   diferente produz ordem de lista diferente na home e na conversa. Agora e
   uma funcao so, com `periodo` primeiro, que e o que o cliente pediu e o que
   a medicao dos 7 roteiros confirma. */

function po_home_render($html, $lista) {
    if (strpos($html, PO_HOME_MARCA) === false) return $html;
    return str_replace(PO_HOME_MARCA, po_home_viagens_html($lista), $html);
}

if (!defined('PO_TEST')) {
    header('Content-Type: text/html; charset=utf-8');
    // avaliacoes do Google no painel Sobre (lib/po-social.php)
    $html = po_social_render(file_get_contents(__DIR__ . '/index.html'), [
        PO_MARCA_AVALIACOES_SOBRE => po_avaliacoes_sobre_html(po_fetch_avaliacoes()),
    ]);
    echo po_home_render($html, po_fetch_roteiros());
}
