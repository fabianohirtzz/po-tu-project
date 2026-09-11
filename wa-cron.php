<?php
/* ============================================================
   Cron do cPanel, de hora em hora:
     /usr/local/bin/php /home/USUARIO/public_html/wa-cron.php

   Protegido por segredo na query porque o arquivo fica no docroot e
   qualquer um poderia dispara-lo:
     wa-cron.php?k=<WA_CRON_KEY>
   Pela linha de comando (o cron real) o segredo nao e exigido.
============================================================ */

require_once __DIR__ . '/lib/wa-timeout.php';

$cli = (php_sapi_name() === 'cli');
if (!$cli) {
    // wa_config(), nao po_config(): aquele arquivo e carregado no render
    // publico das paginas de roteiro e o cabecalho dele proibe segredo de
    // aparecer la (ver lib/wa-config.php).
    $cfg = wa_config();
    $k = $_GET['k'] ?? '';
    if (!hash_equals((string) ($cfg['WA_CRON_KEY'] ?? ''), (string) $k)) {
        http_response_code(403);
        exit;
    }
    header('Content-Type: application/json');
}

// Nenhuma excecao escapa: isto roda sozinho, sem ninguem olhando a
// resposta na hora, e um fatal aqui derrubaria o cron do cPanel sem
// deixar rastro nenhum alem do proprio log de erro do PHP.
try {
    $r = wa_varre_timeouts();
    error_log('wa-cron: ' . json_encode($r));
    echo json_encode($r);
} catch (Throwable $e) {
    error_log('wa-cron: ' . $e->getMessage());
    if (!$cli) http_response_code(500);
    echo json_encode(['ok' => false]);
}
