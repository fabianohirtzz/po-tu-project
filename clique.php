<?php
/* ============================================================
   Registra o clique no botao de WhatsApp do site (fase 3A do rastreio).

   Quem chama e o assets/js/origem.js, por sendBeacon, so quando a visita tem
   campanha: o texto do WhatsApp leva o codigo, e aqui fica a origem inteira
   (UTM, gclid, fbclid, pagina de entrada) com esse codigo. O painel liga o
   codigo ao lead quando a mensagem chega (painel/cliques.js).

   Publico de proposito (o visitante nao tem login), por isso:
   - so aceita POST e codigo no formato fechado (lib/po-clique.php);
   - grava com a service_role, e a tabela nao tem politica para anonimo;
   - nao devolve nada alem do status, e nunca devolve dado.
   O mesmo codigo clicado de novo so atualiza a linha (upsert por codigo).
============================================================ */
require_once __DIR__ . '/lib/po-data.php';   // carrega o config.local.php
require_once __DIR__ . '/lib/po-clique.php';

header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}
$linha = po_clique_linha($_POST, time());
if ($linha === null) {
    http_response_code(400);
    exit;
}

$url = (string) ($GLOBALS['SUPABASE_URL'] ?? '');
$key = (string) ($GLOBALS['SUPABASE_SERVICE_KEY'] ?? '');
if ($url === '' || $key === '' || !function_exists('curl_init')) {
    error_log('clique.php sem Supabase configurado');
    http_response_code(503);
    exit;
}

$ch = curl_init(rtrim($url, '/') . '/rest/v1/po_origem_cliques?on_conflict=codigo');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_HTTPHEADER     => [
        'apikey: ' . $key,
        'Authorization: Bearer ' . $key,
        'Content-Type: application/json',
        'Prefer: resolution=merge-duplicates,return=minimal',
    ],
    CURLOPT_POSTFIELDS     => json_encode($linha, JSON_UNESCAPED_UNICODE),
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($resp === false || $code < 200 || $code >= 300) {
    // Sem o corpo: pode ecoar a origem do visitante.
    error_log('clique.php: Supabase recusou (' . $code . ') codigo ' . $linha['codigo']);
    http_response_code(502);
    exit;
}
http_response_code(204);
