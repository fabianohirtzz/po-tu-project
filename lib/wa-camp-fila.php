<?php
/* ============================================================
   Transmissao: a fila. Reserva os destinatarios e drena em lotes.

   A REGRA CENTRAL: a linha de po_wa_envios e RESERVADA antes do envio, e o
   indice unico (campanha_id, lead_id) e o cadeado. Registrar so depois do
   envio deixaria passar o retry disparado enquanto a primeira requisicao
   ainda roda - que e exatamente o que um timeout produz, e e a mesma licao
   que o lote de importacao do plano 3.1 aprendeu caro.

   O envio e injetavel (wa_camp_set_enviador) para que nenhum teste toque a
   rede, no mesmo padrao de wa_set_transport e wa_db_set_transport.
============================================================ */

require_once __DIR__ . '/wa-db.php';
require_once __DIR__ . '/wa-camp.php';
require_once __DIR__ . '/wa-send.php';

function wa_camp_set_enviador($f) { $GLOBALS['WA_CAMP_ENVIADOR'] = $f; }

/* O enviador padrao manda o template da campanha. Fora da janela de 24h nao
   existe outro caminho (ver Task 2). */
function wa_camp_envia($wa_id, $nome, $template) {
    if (isset($GLOBALS['WA_CAMP_ENVIADOR']) && $GLOBALS['WA_CAMP_ENVIADOR']) {
        return call_user_func($GLOBALS['WA_CAMP_ENVIADOR'], $wa_id, $nome);
    }
    return wa_send_template($wa_id, $template, [$nome]);
}

/* Reserva uma linha por destinatario. Conflito (409) significa que a pessoa
   JA esta nesta campanha: nao e erro, e o cadeado funcionando. */
function wa_camp_reserva($campanha_id, $publico) {
    $reservados = 0; $ja = 0;
    foreach ($publico as $p) {
        $linha = wa_db_insert('po_wa_envios', [
            'campanha_id' => $campanha_id,
            'lead_id'     => $p['lead_id'],
            'wa_id'       => $p['wa_id'],
            // Fotografado agora: e o nome que a cliente viu na previa, e e o
            // parametro {{1}} do template. Ler do lead na hora do dreno
            // mandaria um nome que ninguem revisou.
            'nome'        => $p['nome'] ?? '',
            'status'      => 'reservado',
        ], true);                          // true = 409 devolve null em vez de logar erro
        if ($linha === null) { $ja++; } else { $reservados++; }
    }
    return ['reservados' => $reservados, 'ja_existiam' => $ja];
}

/* Manda ate $limite reservados desta campanha.

   wa_db_select_estrito, e nao wa_db_select: com o Supabase fora do ar, o
   select frouxo devolve lista vazia e a drenagem reportaria "fila esvaziou"
   sem ter mandado nada - a campanha ficaria eternamente incompleta com a
   tela dizendo sucesso. Mesma licao do importador. */
function wa_camp_drena($campanha_id, $limite) {
    $limite = (int) $limite;
    if ($limite <= 0) return ['enviados' => 0, 'falhas' => 0, 'restam' => 0];

    $camp = wa_db_select_estrito('po_wa_campanhas', 'id=eq.' . rawurlencode($campanha_id));
    $template = ($camp && isset($camp[0]['template'])) ? $camp[0]['template'] : '';

    $fila = wa_db_select_estrito('po_wa_envios',
        'campanha_id=eq.' . rawurlencode($campanha_id) . '&status=eq.reservado&limit=' . $limite);
    if ($fila === null) {
        error_log('wa_camp_drena: leitura da fila falhou, nada enviado | campanha ' . $campanha_id);
        return ['enviados' => 0, 'falhas' => 0, 'restam' => -1];
    }

    $enviados = 0; $falhas = 0;
    foreach ($fila as $e) {
        $r = wa_camp_envia($e['wa_id'], $e['nome'] ?? '', $template);
        if (!empty($r['ok']) && !empty($r['wamid'])) {
            // Estado so avanca se o envio SAIU, e o wamid e o que prova isso:
            // e por ele que o recibo de entrega volta (Task 7).
            wa_db_update('po_wa_envios', 'id=eq.' . rawurlencode($e['id']), [
                'status'     => 'enviado',
                'wamid'      => $r['wamid'],
                'enviado_at' => gmdate('c'),
            ]);
            $enviados++;
        } else {
            // Falha NAO pode virar 'enviado': o relatorio mentiria e a pessoa
            // nunca receberia nada. O motivo fica gravado para a tela mostrar.
            wa_db_update('po_wa_envios', 'id=eq.' . rawurlencode($e['id']), [
                'status' => 'falha',
                'erro'   => substr((string) ($r['erro'] ?? 'erro desconhecido'), 0, 300),
            ]);
            $falhas++;
        }
    }

    $resto = wa_db_select_estrito('po_wa_envios',
        'campanha_id=eq.' . rawurlencode($campanha_id) . '&status=eq.reservado');
    return [
        'enviados' => $enviados,
        'falhas'   => $falhas,
        'restam'   => is_array($resto) ? count($resto) : -1,
    ];
}
