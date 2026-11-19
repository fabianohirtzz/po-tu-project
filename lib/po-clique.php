<?php
/* ============================================================
   O clique no WhatsApp do site, transformado na linha do po_origem_cliques.
   Puro (sem rede, sem banco): o clique.php so cola isto no Supabase, e o
   tests/test-origem-whatsapp.php testa aqui.

   O codigo tem formato FECHADO, o mesmo do assets/js/origem.js: G, M ou S,
   hifen, cinco consoantes. Qualquer outra coisa e recusada, porque este
   endpoint e publico e a tabela nao pode virar deposito de texto livre.
============================================================ */

const PO_CLIQUE_CODIGO = '/^[GMS]-[BCDFGHJKLMNPQRSTVWXZ]{5}$/';
const PO_CLIQUE_CAMPOS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
                          'gclid', 'gbraid', 'wbraid', 'fbclid', 'referrer', 'landing_page', 'pagina'];
const PO_CLIQUE_MAX = 500;   // o mesmo teto do origem.js e do enviar.php

function po_clique_texto($v) {
    $v = trim(is_string($v) ? $v : '');
    return function_exists('mb_substr') ? mb_substr($v, 0, PO_CLIQUE_MAX, 'UTF-8') : substr($v, 0, PO_CLIQUE_MAX);
}

/* A linha para o upsert, ou null se o codigo nao presta. $agora entra por
   parametro para o teste nao depender do relogio. */
function po_clique_linha(array $post, $agora) {
    $codigo = is_string($post['codigo'] ?? null) ? trim($post['codigo']) : '';
    if (!preg_match(PO_CLIQUE_CODIGO, $codigo)) return null;
    $linha = ['codigo' => $codigo, 'ultimo_clique_at' => gmdate('c', (int) $agora)];
    foreach (PO_CLIQUE_CAMPOS as $k) {
        $v = po_clique_texto($post[$k] ?? '');
        $linha[$k] = $v === '' ? null : $v;
    }
    return $linha;
}
