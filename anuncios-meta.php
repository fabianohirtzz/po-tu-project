<?php
/* ============================================================
   Nome dos anuncios da Meta (fase 3B). O painel manda os ad_id que ainda
   nao tem nome; aqui a API de Marketing devolve anuncio, conjunto e
   campanha, e eles ficam em po_anuncios_meta. Logica em lib/po-meta.php.
   So com login do painel. Sem META_ADS_TOKEN responde "nao configurado".
============================================================ */
require_once __DIR__ . '/lib/po-meta.php';
require_once __DIR__ . '/lib/po-auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}
$cfg = po_meta_config();
if (!po_auth_ok($cfg['SUPABASE_URL'], $cfg['SUPABASE_ANON_KEY'])) {
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit;
}
[$code, $resp] = po_meta_nomes_run(explode(',', (string) ($_POST['ids'] ?? '')), $cfg, time());
http_response_code($code);
echo json_encode($resp, JSON_UNESCAPED_UNICODE);
