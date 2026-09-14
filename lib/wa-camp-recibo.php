<?php
/* ============================================================
   Recibo de entrega de campanha.

   AQUI mora a idempotencia de ENTREGA, e NAO em po_wa_mensagens. O wamid de
   um evento `status` E o wamid da mensagem original, e po_wa_mensagens.wamid
   e unique: passar status por la faz recibo e eco competirem pela mesma
   linha, o eco vira 'duplicado', o wa_processar pula e O ROBO NAO SE CALA.
   Ja foi implementado por engano uma vez (faxina de 13/09) e revertido.
============================================================ */

require_once __DIR__ . '/wa-db.php';

/* A escada de status. So se anda PARA FRENTE: a Meta reentrega webhook fora
   de ordem, e um `delivered` que chega depois de um `read` nao pode rebaixar
   o envio - o relatorio de leitura encolheria sozinho. */
const WA_RECIBO_ORDEM = ['reservado' => 0, 'enviado' => 1, 'entregue' => 2, 'lido' => 3];

const WA_RECIBO_MAPA = [
    'delivered' => 'entregue',
    'read'      => 'lido',
    'failed'    => 'falha',
];

function wa_camp_recibo($wamid, $status_meta) {
    $wamid = (string) $wamid;
    if ($wamid === '') return null;

    // Status que a Meta inventar amanha nao pode virar escrita silenciosa.
    $novo = WA_RECIBO_MAPA[$status_meta] ?? null;
    if ($novo === null) return null;

    $linhas = wa_db_select_estrito('po_wa_envios', 'wamid=eq.' . rawurlencode($wamid));
    // null = erro de leitura; [] = nao e wamid de campanha (o caso mais comum
    // de todos: o PDF do roteiro e as perguntas do motor tambem geram status).
    if (!$linhas) return null;

    $e     = $linhas[0];
    $atual = $e['status'] ?? 'enviado';

    /* 'falha' e a excecao a escada: a Meta pode recusar DEPOIS de aceitar
       (numero invalido, bloqueio), e isso e informacao nova em qualquer
       ponto. Os demais so avancam. */
    if ($novo !== 'falha') {
        $de   = WA_RECIBO_ORDEM[$atual] ?? 0;
        $para = WA_RECIBO_ORDEM[$novo]  ?? 0;
        if ($para <= $de) return $atual;         // ja estava igual ou adiante
    }

    $campos = ['status' => $novo];
    // O carimbo so e escrito se ainda nao existe: recibo repetido e o caso
    // NORMAL (a Meta reenvia quando nao recebe 200 rapido) e nao pode
    // reescrever a hora real do evento.
    if ($novo === 'entregue' && empty($e['entregue_at'])) $campos['entregue_at'] = gmdate('c');
    if ($novo === 'lido'     && empty($e['lido_at']))     $campos['lido_at']     = gmdate('c');

    wa_db_update('po_wa_envios', 'id=eq.' . rawurlencode($e['id']), $campos);
    return $novo;
}
