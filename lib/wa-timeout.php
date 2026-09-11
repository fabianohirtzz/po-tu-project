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

function wa_timeout_set_deps($d) { $GLOBALS['WA_TO_DEPS'] = array_merge($GLOBALS['WA_TO_DEPS'] ?? [], $d); }
function wa_to_call($nome, ...$args) {
    $padrao = ['select' => 'wa_db_select', 'update' => 'wa_db_update', 'send_text' => 'wa_send_text'];
    $f = $GLOBALS['WA_TO_DEPS'][$nome] ?? $padrao[$nome];
    return call_user_func_array($f, $args);
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
                $nome = '';
                wa_to_call('send_text', $wa, wa_texto('lembrete', ['nome' => $nome]));
                wa_to_call('update', 'po_wa_conversas', 'wa_id=eq.' . rawurlencode($wa),
                    ['lembrete_at' => gmdate('c', $agora)]);
                $lembretes++;
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
