<?php
/* ============================================================
   Venda de volta para a Meta (fase 3B): API de Conversoes para Mensagens.
   O painel chama ao abrir (modo=enviar); aqui sai um evento Purchase para
   cada lead com ctwa_clid que virou "Contrato assinado" nos ultimos 7 dias,
   uma vez so (reserva em po_meta_conversoes: a Meta nao deduplica).
   modo=diagnostico so LE o conjunto de dados ligado a conta do WhatsApp,
   para preencher o META_DATASET_ID. Logica em lib/po-meta.php.
   So com login do painel. Sem configuracao, responde o que falta.
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
$modo = (string) ($_POST['modo'] ?? 'enviar');
if ($modo === 'enviar') {
    [$code, $resp] = po_meta_conversao_run($cfg, time());
} elseif ($modo === 'diagnostico') {
    [$code, $resp] = po_meta_diagnostico_run($cfg);
} else {
    $code = 400;
    $resp = ['ok' => false, 'erro' => 'modo desconhecido'];
}
http_response_code($code);
echo json_encode($resp, JSON_UNESCAPED_UNICODE);
