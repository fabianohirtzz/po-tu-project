<?php
/* ============================================================
   Leitura do webhook da Cloud API: assinatura e normalizacao.

   Separado do whatsapp.php de proposito: o arquivo que recebe o POST nao
   da para testar, mas estas funcoes dao.
============================================================ */

require_once __DIR__ . '/wa-fone.php';

/* hash_equals e obrigatorio: comparar com == vaza o segredo por tempo de
   resposta, um byte de cada vez. */
function wa_verifica_assinatura($corpo, $header, $segredo) {
    if (!$header || strpos($header, 'sha256=') !== 0) return false;
    $esperado = 'sha256=' . hash_hmac('sha256', $corpo, $segredo);
    return hash_equals($esperado, $header);
}

function wa_norm_fone($digitos) {
    return wa_e164('+' . ltrim((string) $digitos, '+'));
}

/* Achata o payload aninhado da Meta numa lista simples de eventos. */
function wa_parse_evento($json) {
    $eventos = [];
    foreach ($json['entry'] ?? [] as $entry) {
        foreach ($entry['changes'] ?? [] as $change) {
            $v = $change['value'] ?? [];

            // Nome do perfil, quando vem, para batizar o contato.
            $nome = $v['contacts'][0]['profile']['name'] ?? null;

            // 1) mensagens do cliente
            foreach ($v['messages'] ?? [] as $m) {
                $wa = wa_norm_fone($m['from'] ?? '');
                if (!$wa) continue;
                $eventos[] = [
                    'tipo'      => 'mensagem',
                    'wa_id'     => $wa,
                    'wamid'     => $m['id'] ?? '',
                    'tipo_msg'  => wa_tipo_msg($m),
                    'texto'     => wa_texto_msg($m),
                    'nome'      => $nome,
                    'ad_id'     => $m['referral']['source_id'] ?? null,
                    'ctwa_clid' => $m['referral']['ctwa_clid'] ?? null,
                    'ts'        => (int) ($m['timestamp'] ?? time()),
                ];
            }

            // 2) eco: o que a cliente digitou no app do celular. Este e o
            //    sinal de handoff, e e o que faz o robo se calar.
            foreach ($v['message_echoes'] ?? [] as $m) {
                $wa = wa_norm_fone($m['to'] ?? '');
                if (!$wa) continue;
                $eventos[] = [
                    'tipo'      => 'eco',
                    'wa_id'     => $wa,
                    'wamid'     => $m['id'] ?? '',
                    'tipo_msg'  => wa_tipo_msg($m),
                    'texto'     => wa_texto_msg($m),
                    'nome'      => null,
                    'ad_id'     => null,
                    'ctwa_clid' => null,
                    'ts'        => (int) ($m['timestamp'] ?? time()),
                ];
            }

            // 3) status de entrega, para o relatorio de campanha
            foreach ($v['statuses'] ?? [] as $s) {
                $wa = wa_norm_fone($s['recipient_id'] ?? '');
                if (!$wa) continue;
                $eventos[] = [
                    'tipo'      => 'status',
                    'wa_id'     => $wa,
                    'wamid'     => $s['id'] ?? '',
                    'tipo_msg'  => 'status',
                    'texto'     => $s['status'] ?? '',
                    'nome'      => null,
                    'ad_id'     => null,
                    'ctwa_clid' => null,
                    'ts'        => (int) ($s['timestamp'] ?? time()),
                ];
            }
        }
    }
    return $eventos;
}

function wa_tipo_msg($m) {
    $t = $m['type'] ?? 'text';
    if ($t === 'interactive') {
        return $m['interactive']['type'] ?? 'interactive';
    }
    return $t;
}

/* O que conta como "texto" para o motor. Na resposta do menu, o que
   interessa e o id da linha escolhida, que e o slug do roteiro. */
function wa_texto_msg($m) {
    $t = $m['type'] ?? 'text';
    if ($t === 'text')        return $m['text']['body'] ?? '';
    if ($t === 'button')      return $m['button']['payload'] ?? '';
    if ($t === 'interactive') {
        $i = $m['interactive'] ?? [];
        if (($i['type'] ?? '') === 'list_reply')   return $i['list_reply']['id'] ?? '';
        if (($i['type'] ?? '') === 'button_reply') return $i['button_reply']['id'] ?? '';
    }
    if ($t === 'document') return $m['document']['filename'] ?? '';
    return '';
}
