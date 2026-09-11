<?php
/* ============================================================
   Lembrete de 24h e encerramento de 48h.

   So mexe em conversa que esta ESPERANDO resposta. Conversa entregue a
   humana ou ja qualificada nunca e tocada: encerrar atendimento em
   andamento por relogio seria pior do que nao ter cron nenhum.
============================================================ */

require_once __DIR__ . '/wa-db.php';
require_once __DIR__ . '/wa-send.php';
require_once __DIR__ . '/wa-motor.php';

const WA_HORAS_LEMBRETE = 24;
const WA_HORAS_ENCERRA  = 24; // contadas DEPOIS do lembrete

// Minimo pra uma WA_CRON_KEY ser levada a serio. Abaixo disso conta como
// "nao configurada": chave ausente ou fraca nunca autoriza, nem contra um
// pedido tambem vazio - hash_equals('', '') === true, e e exatamente o
// defeito que deixava wa-cron.php aberto pela web (revisao: Critical).
const WA_CRON_KEY_MIN = 16;

function wa_timeout_set_deps($d) { $GLOBALS['WA_TO_DEPS'] = array_merge($GLOBALS['WA_TO_DEPS'] ?? [], $d); }
function wa_to_call($nome, ...$args) {
    $padrao = ['select' => 'wa_db_select', 'update' => 'wa_db_update', 'send_text' => 'wa_send_text'];
    $f = $GLOBALS['WA_TO_DEPS'][$nome] ?? $padrao[$nome];
    return call_user_func_array($f, $args);
}

/* Segredo nao configurado (ou curto demais pra contar como segredo) e
   ausencia de permissao, nunca permissao - por isso a checagem de
   tamanho vem ANTES do hash_equals, nao depois. Isolada em funcao pura
   (sem $_GET, sem rede) pra dar pra testar sem tocar em nada. Usada por
   wa-cron.php, que fica no docroot e por isso precisa de segredo na
   query quando chamado pela web. */
function wa_cron_autorizado($chave_configurada, $chave_recebida) {
    $chave_configurada = (string) $chave_configurada;
    if (strlen($chave_configurada) < WA_CRON_KEY_MIN) {
        error_log('wa-cron: chamado pela web sem WA_CRON_KEY configurada (ou chave curta demais)');
        return false;
    }
    return hash_equals($chave_configurada, (string) $chave_recebida);
}

function wa_varre_timeouts($agora = null) {
    $agora = $agora ?: time();
    $lembretes = 0; $encerrados = 0;

    $abertas = wa_to_call('select', 'po_wa_conversas',
        'estado=in.(enviado_roteiro,aguardando_roteiro)&limit=500');

    foreach ($abertas as $c) {
        // Guarda dupla: mesmo que a query mude, estado fora da lista nao entra.
        if (!in_array($c['estado'] ?? '', ['enviado_roteiro', 'aguardando_roteiro'], true)) continue;

        $desde = strtotime($c['aguardando_desde'] ?? '') ?: null;
        if (!$desde) continue;
        $wa = $c['wa_id'];
        $lembrete = !empty($c['lembrete_at']) ? strtotime($c['lembrete_at']) : null;

        if (!$lembrete) {
            if (($agora - $desde) >= WA_HORAS_LEMBRETE * 3600) {
                // Nome de verdade, vindo do lead - sem isto {nome} some do
                // texto e sobra pontuacao orfa ("Oi , tudo bem?") na
                // mensagem que chega pro cliente.
                $lead = wa_to_call('select', 'po_leads', 'wa_id=eq.' . rawurlencode($wa));
                $nome = $lead[0]['nome'] ?? '';
                $envio = wa_to_call('send_text', $wa, wa_texto('lembrete', ['nome' => $nome]));
                // So grava lembrete_at se o envio realmente saiu. Sem isto,
                // uma falha de rede/token marcava o lead como "avisado" e o
                // encerramento vinha 24h depois sem o cliente ter recebido
                // nada - mesmo defeito que a Task 7 corrigiu no envio do
                // roteiro. Falha aqui so loga e deixa a conversa como esta,
                // pro cron tentar de novo na proxima varredura.
                if (!empty($envio['ok'])) {
                    wa_to_call('update', 'po_wa_conversas', 'wa_id=eq.' . rawurlencode($wa),
                        ['lembrete_at' => gmdate('c', $agora)]);
                    $lembretes++;
                } else {
                    error_log('wa_varre_timeouts: falha ao enviar lembrete, tenta de novo na proxima varredura | wa_id ' . $wa);
                }
            }
            continue;
        }

        if (($agora - $lembrete) >= WA_HORAS_ENCERRA * 3600) {
            wa_to_call('update', 'po_wa_conversas', 'wa_id=eq.' . rawurlencode($wa),
                ['estado' => 'encerrado']);
            wa_to_call('update', 'po_leads', 'wa_id=eq.' . rawurlencode($wa),
                ['status' => 'perdido']);
            $encerrados++;
        }
    }

    return ['lembretes' => $lembretes, 'encerrados' => $encerrados];
}
