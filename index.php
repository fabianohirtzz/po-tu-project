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
function po_data_saida($r) {
    /* SAIDAS SEMANAIS: a data esta na lista de datas do mes, nao em texto.
       Sem este caminho o roteiro devolve null, cai no '9999' de quem
       chamou e ordena no FIM da lista - um roteiro que sai na semana que
       vem anunciado depois de todos. Sem data futura cadastrada, volta a
       regra de sempre. */
    if (($r['status_viagem'] ?? null) === 'semanal') {
        $prox = po_proxima_saida($r);
        if ($prox !== null) return $prox;
    }
    foreach (['data_label', 'periodo'] as $k) {
        $t = $r[$k] ?? '';
        if (is_string($t) && preg_match('~(\d{1,2})/(\d{1,2})/(\d{4}|\d{2})\b~', $t, $m)) {
            $a = strlen($m[3]) === 2 ? '20' . $m[3] : $m[3];
            if (checkdate((int) $m[2], (int) $m[1], (int) $a)) return sprintf('%s-%02d-%02d', $a, $m[2], $m[1]);
        }
    }
    return null;
}

/* Ordem cronologica (pedido do cliente, 23/10/2026): a saida mais proxima
   primeiro; roteiro sem data legivel vai para o fim, na ordem do painel. */
function po_por_saida($lista) {
    $i = 0;
    $chave = [];
    foreach ($lista as $r) $chave[] = [po_data_saida($r) ?? '9999', $i++, $r];
    usort($chave, function ($a, $b) { return [$a[0], $a[1]] <=> [$b[0], $b[1]]; });
    return array_column($chave, 2);
}

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
    echo po_home_render($html, po_por_saida(po_fetch_roteiros()));
}
