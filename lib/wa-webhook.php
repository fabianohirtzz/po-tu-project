<?php
/* ============================================================
   Leitura do webhook da Cloud API: assinatura e normalizacao.

   Separado do whatsapp.php de proposito: o arquivo que recebe o POST nao
   da para testar, mas estas funcoes dao.
============================================================ */

require_once __DIR__ . '/wa-fone.php';
require_once __DIR__ . '/wa-db.php';

/* Minimo pra um segredo do webhook ser levado a serio. Mesmo numero e
   mesma regra de WA_CRON_KEY_MIN (lib/wa-timeout.php): segredo ausente ou
   fraco e ausencia de permissao, nunca permissao. Sem isto o HMAC de um
   WA_APP_SECRET vazio e calculavel por qualquer um, e hash_equals('','')
   devolve true no token de verificacao - as duas portas abertas enquanto
   as chaves nao estao no config.local.php, que e o estado de hoje. */
const WA_SEGREDO_MIN = 16;

/* Funcao pura (sem $_GET, sem rede) pra dar pra testar: o segredo
   configurado precisa existir e ter tamanho de segredo ANTES de qualquer
   comparacao. */
function wa_segredo_util($segredo) {
    return strlen((string) $segredo) >= WA_SEGREDO_MIN;
}

/* hash_equals e obrigatorio: comparar com == vaza o segredo por tempo de
   resposta, um byte de cada vez. */
function wa_verifica_assinatura($corpo, $header, $segredo) {
    if (!wa_segredo_util($segredo)) {
        error_log('wa_verifica_assinatura: WA_APP_SECRET ausente ou curto demais, POST recusado');
        return false;
    }
    if (!$header || strpos($header, 'sha256=') !== 0) return false;
    $esperado = 'sha256=' . hash_hmac('sha256', $corpo, $segredo);
    return hash_equals($esperado, $header);
}

/* Verificacao do webhook (o GET que a Meta faz no cadastro). Mesma regra
   da assinatura: sem WA_VERIFY_TOKEN configurado, ninguem passa - senao um
   GET com hub_verify_token vazio devolvia o hub_challenge. */
function wa_verifica_token($configurado, $recebido) {
    if (!wa_segredo_util($configurado)) {
        error_log('wa_verifica_token: WA_VERIFY_TOKEN ausente ou curto demais, verificacao recusada');
        return false;
    }
    return hash_equals((string) $configurado, (string) $recebido);
}

function wa_norm_fone($digitos) {
    return wa_e164('+' . ltrim((string) $digitos, '+'));
}

/* Grava o evento em po_wa_mensagens ANTES de processar, para a idempotencia
   pelo wamid. Nao usa wa_db_insert() aqui de proposito: aquela funcao
   colapsa em null tres causas bem diferentes (rede fora, 409 de duplicata
   real, e qualquer outro erro >=300 como chave errada, coluna ausente ou
   RLS mal configurada), e tratar as tres do mesmo jeito faz o webhook
   descartar mensagem de cliente em silencio quando o erro e de
   infraestrutura, nao duplicata.

   Devolve um status legivel:
   - 'novo':       gravou, pode processar.
   - 'duplicado':  409, a Meta reenviou o mesmo wamid. Pula em silencio,
                   este e o caminho feliz da idempotencia.
   - 'falha':      rede fora, ou erro do lado do banco. Processa mesmo
                   assim: entre o cliente receber o roteiro duas vezes e o
                   cliente nunca ser atendido, o duplicado e constrangedor
                   e recuperavel, a mensagem perdida e um lead que some sem
                   ninguem saber que existiu. Na duvida, atende. */
function wa_registra_evento($ev) {
    $linha = [
        'wa_id'   => $ev['wa_id'],
        'wamid'   => $ev['wamid'],
        'direcao' => $ev['tipo'] === 'eco' ? 'out' : 'in',
        'autor'   => $ev['tipo'] === 'eco' ? 'humano' : 'cliente',
        'tipo'    => $ev['tipo_msg'],
        'texto'   => $ev['texto'],
        'ts'      => gmdate('c', $ev['ts']),
    ];
    $r = wa_db_http('POST', wa_db_url('po_wa_mensagens'), json_encode($linha), wa_db_headers());

    if (!$r) {
        error_log('wa_registra_evento: rede fora, processando sem idempotencia | wamid ' . $ev['wamid']);
        return 'falha';
    }
    if ($r['status'] === 409) return 'duplicado';
    if ($r['status'] >= 300) {
        // Sem o corpo de proposito, como em wa_db_insert: pode ecoar dado
        // de cliente.
        error_log('wa_registra_evento: status ' . $r['status'] . ', processando sem idempotencia | wamid ' . $ev['wamid']);
        return 'falha';
    }
    return 'novo';
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
