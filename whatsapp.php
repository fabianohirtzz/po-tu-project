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
    if ($modo === 'subscribe' && hash_equals((string) ($cfg['WA_VERIFY_TOKEN'] ?? ''), (string) $token)) {
        header('Content-Type: text/plain');
        echo $desafio;
        exit;
    }
    http_response_code(403);
    exit;
}

$corpo = file_get_contents('php://input');

// 2) Assinatura. Sem isso, qualquer um posta evento falso no webhook.
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
        // Idempotencia: o unique em wamid barra o reenvio da Meta. Se a
        // linha ja existia, este evento ja foi tratado.
        if ($ev['wamid'] !== '' && $ev['tipo'] !== 'status') {
            $novo = wa_db_insert('po_wa_mensagens', [
                'wa_id'   => $ev['wa_id'],
                'wamid'   => $ev['wamid'],
                'direcao' => $ev['tipo'] === 'eco' ? 'out' : 'in',
                'autor'   => $ev['tipo'] === 'eco' ? 'humano' : 'cliente',
                'tipo'    => $ev['tipo_msg'],
                'texto'   => $ev['texto'],
                'ts'      => gmdate('c', $ev['ts']),
            ], true);
            if ($novo === null) continue; // ja processado, ou falha de rede
        }
        wa_processar($ev);
    }
} catch (Throwable $e) {
    error_log('whatsapp.php: ' . $e->getMessage());
}
