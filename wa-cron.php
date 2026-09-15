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
require_once __DIR__ . '/lib/wa-camp-fila.php';

$cli = (php_sapi_name() === 'cli');
if (!$cli) {
    // wa_config(), nao po_config(): aquele arquivo e carregado no render
    // publico das paginas de roteiro e o cabecalho dele proibe segredo de
    // aparecer la (ver lib/wa-config.php).
    $cfg = wa_config();
    $k = $_GET['k'] ?? '';
    // wa_cron_autorizado (lib/wa-timeout.php) recusa sempre quando a chave
    // configurada esta vazia ou e curta demais: segredo nao configurado e
    // ausencia de permissao, nunca permissao (hash_equals('','') === true
    // deixaria isto aberto pra qualquer um enquanto WA_CRON_KEY nao existe).
    if (!wa_cron_autorizado($cfg['WA_CRON_KEY'] ?? '', $k)) {
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

    /* Drena a campanha em andamento. Vem DEPOIS dos timeouts de proposito:
       lembrete e encerramento sao gratis, a transmissao custa - se algo
       estourar aqui, o que ja era gratuito ja aconteceu.

       Uma campanha por vez (limit=1): duas drenando juntas dividiriam o teto
       diario sem saber uma da outra e estourariam o limite da Meta.

       So 'enviando'. Campanha em 'rascunho' ainda esta montando a lista (a
       reserva vem em pedacos, pelo painel) e nao pode comecar a enviar pela
       metade: quem sobrasse da lista nunca receberia, e nada diria quem foi. */
    $c = wa_db_select_estrito('po_wa_campanhas',
        'select=id&status=eq.enviando&order=created_at.asc&limit=1');
    if ($c) {
        /* wa_camp_drena_campanha e o MESMO caminho do botao do painel: a
           escada, o teto do dia e o encerramento da campanha ficam num lugar
           so. Duas telas decidindo sozinhas quando concluir uma campanha e
           como essa regra apodrece. */
        $r['campanha'] = wa_camp_drena_campanha($c[0]['id']);
    }

    error_log('wa-cron: ' . json_encode($r));
    echo json_encode($r);
} catch (Throwable $e) {
    error_log('wa-cron: ' . $e->getMessage());
    if (!$cli) http_response_code(500);
    echo json_encode(['ok' => false]);
}
