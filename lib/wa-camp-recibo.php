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

/* A escada completa, com os seis status que o check de po_wa_envios aceita.
   `falha` fica no TOPO e e terminal: a Meta reenvia webhook fora de ordem, e
   sem posto proprio ela caia no degrau zero (`?? 0`), onde um `delivered`
   reentregue depois de um `failed` RESSUSCITAVA o envio - o relatorio
   escondia a falha e a coluna `erro` ficava contradizendo o status.
   `enviando` entra por completude: ele so sobe.

   Com falha no topo, a comparacao generica la embaixo ja garante sozinha o
   "falha entra de qualquer lugar, e nao sai de lugar nenhum": entrar sempre
   tem $para=5, maior que qualquer $de abaixo; sair sempre tem $para<=5, que
   e o proprio $de quando $atual='falha'. Por isso o tratamento especial que
   existia antes (pular a escada quando $novo==='falha') foi removido: ele
   so era necessario porque falha nao tinha posto. */
const WA_RECIBO_ORDEM = [
    'reservado' => 0, 'enviando' => 1, 'enviado' => 2,
    'entregue'  => 3, 'lido'     => 4, 'falha'   => 5,
];

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
    if ($linhas === null) {
        // Erro de leitura, nao ausencia. A Meta ja recebeu 200 e NAO
        // reenvia este recibo, entao o rastro no log e a unica chance de
        // reconciliar depois.
        error_log('wa_camp_recibo: leitura de po_wa_envios falhou | wamid ' . $wamid);
        return null;
    }
    // Lista vazia e o caso mais comum de todos: o PDF do roteiro e as
    // perguntas do motor tambem geram status, e nenhum deles e de campanha.
    if (!$linhas) return null;

    $e     = $linhas[0];
    $atual = $e['status'] ?? 'enviado';

    // SO PARA FRENTE. Com falha no topo da escada (WA_RECIBO_ORDEM), esta
    // unica comparacao ja cobre a regra 1 (a Meta reentrega fora de ordem,
    // ninguem rebaixa) e a regra 2 (falha entra de qualquer ponto abaixo e
    // nunca sai).
    $de   = WA_RECIBO_ORDEM[$atual] ?? 0;
    $para = WA_RECIBO_ORDEM[$novo]  ?? 0;
    if ($para <= $de) return $atual;         // ja estava igual ou adiante

    $campos = ['status' => $novo];
    // O carimbo so e escrito se ainda nao existe: recibo repetido e o caso
    // NORMAL (a Meta reenvia quando nao recebe 200 rapido) e nao pode
    // reescrever a hora real do evento.
    if ($novo === 'entregue' && empty($e['entregue_at'])) $campos['entregue_at'] = gmdate('c');
    if ($novo === 'lido'     && empty($e['lido_at']))     $campos['lido_at']     = gmdate('c');

    wa_db_update('po_wa_envios', 'id=eq.' . rawurlencode($e['id']), $campos);
    return $novo;
}
