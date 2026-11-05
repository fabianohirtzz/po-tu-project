<?php
/* Limpa o cache de roteiros do site (lib/po-data.php) depois que o painel
   salva ou exclui um roteiro. Sem isso, /roteiros e /roteiros/<slug> seguiam
   mostrando a versao anterior por ate 10 min.

   So obedece a login valido do painel (sb_token no POST, conferido no
   Supabase). Apagar cache nao vaza dado nenhum, mas um endpoint aberto
   deixaria qualquer um forcar o servidor a reler o banco a cada pedido. */
require_once __DIR__ . '/lib/po-data.php';
require_once __DIR__ . '/lib/po-auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}
$cfg = po_config();
if (!po_auth_ok($cfg['SUPABASE_URL'], $cfg['SUPABASE_ANON_KEY'])) {
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit;
}
echo json_encode(['ok' => true, 'apagados' => po_cache_limpa()]);
