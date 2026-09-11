<?php
/* ============================================================
   Webhook da Cloud API do WhatsApp.

   REGRA DE OURO: responder 200 SEMPRE, mesmo em erro interno. Devolver
   erro faz a Meta reenviar em loop e, na repeticao, derrubar a inscricao
   do webhook. O erro vai para o log, nao para a resposta.
============================================================ */

require_once __DIR__ . '/lib/wa-webhook.php';
require_once __DIR__ . '/lib/wa-db.php';
require_once __DIR__ . '/lib/wa-motor.php';

$cfg = wa_config();

// 1) Verificacao do webhook (a Meta chama uma vez, no cadastro).
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $modo     = $_GET['hub_mode']         ?? '';
    $token    = $_GET['hub_verify_token'] ?? '';
    $desafio  = $_GET['hub_challenge']    ?? '';
    // wa_verifica_token (lib/wa-webhook.php) recusa sempre quando o token
    // configurado esta vazio ou e curto demais.
    if ($modo === 'subscribe' && wa_verifica_token($cfg['WA_VERIFY_TOKEN'] ?? '', $token)) {
        header('Content-Type: text/plain');
        echo $desafio;
        exit;
    }
    http_response_code(403);
    exit;
}

$corpo = file_get_contents('php://input');

// 2) Assinatura. Sem isso, qualquer um posta evento falso no webhook - e o
//    processamento grava no Supabase com a service_role. Segredo vazio
//    recusa tudo (ver wa_segredo_util).
$assinatura = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
if (!wa_verifica_assinatura($corpo, $assinatura, $cfg['WA_APP_SECRET'] ?? '')) {
    error_log('whatsapp.php: assinatura invalida');
    http_response_code(403);
    exit;
}

// A partir daqui a resposta e sempre 200.
http_response_code(200);
header('Content-Type: application/json');
echo '{"ok":true}';

// Fecha a conexao com a Meta antes de processar: o relogio dela nao fica
// correndo enquanto falamos com o Supabase e com a Graph API.
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    @ob_end_flush();
    @flush();
}

try {
    $json = json_decode($corpo, true);
    foreach (wa_parse_evento(is_array($json) ? $json : []) as $ev) {
        // Idempotencia: o unique em wamid barra o reenvio da Meta. So o
        // 'duplicado' (409 de verdade) pula o processamento; 'falha' (rede
        // ou erro do banco) processa mesmo assim, ver wa_registra_evento().
        if ($ev['wamid'] !== '' && $ev['tipo'] !== 'status') {
            if (wa_registra_evento($ev) === 'duplicado') continue;
        }
        wa_processar($ev);
    }
} catch (Throwable $e) {
    error_log('whatsapp.php: ' . $e->getMessage());
}
