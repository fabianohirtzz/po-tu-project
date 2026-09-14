<?php
/* ============================================================
   Transmissao: a fila. Reserva os destinatarios e drena em lotes.

   A REGRA CENTRAL: a linha de po_wa_envios e RESERVADA antes do envio, e o
   indice unico (campanha_id, lead_id) e o cadeado. Registrar so depois do
   envio deixaria passar o retry disparado enquanto a primeira requisicao
   ainda roda - que e exatamente o que um timeout produz, e e a mesma licao
   que o lote de importacao do plano 3.1 aprendeu caro.

   Esse cadeado impede a LINHA de duplicar, mas nao impede o ENVIO de
   repetir: a linha continua 'reservado' durante a chamada a Meta, entao
   dois drenos ao mesmo tempo (o cron e o botao do painel, ambos existem na
   Task 8) leem as mesmas linhas e os dois mandam. Por isso o dreno tambem
   TOMA POSSE de cada linha (estado 'enviando') antes de chamar a Meta, com
   um PATCH condicional que so um dos dois processos ganha. Ver
   wa_camp_drena e wa_camp_recupera_orfas.

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
   JA esta nesta campanha: nao e erro, e o cadeado funcionando.

   wa_db_insert_status, e nao wa_db_insert: precisamos do STATUS HTTP para
   separar 409 (o cadeado) de 500/rede-fora (erro de verdade). Confundir os
   dois faz uma reserva que nao escreveu nada parecer uma reserva completa -
   a campanha e encerrada como "concluida" sem um unico envio, e o painel
   mostra sucesso (achado I1 da revisao). */
function wa_camp_reserva($campanha_id, $publico) {
    $reservados = 0; $ja = 0; $erros = 0;
    foreach ($publico as $p) {
        $r = wa_db_insert_status('po_wa_envios', [
            'campanha_id' => $campanha_id,
            'lead_id'     => $p['lead_id'],
            'wa_id'       => $p['wa_id'],
            // Fotografado agora: e o nome que a cliente viu na previa, e e o
            // parametro {{1}} do template. Ler do lead na hora do dreno
            // mandaria um nome que ninguem revisou.
            'nome'        => $p['nome'] ?? '',
            'status'      => 'reservado',
        ]);
        $s = $r['status'];
        if ($s === 409)             { $ja++;         continue; }   // o cadeado
        if ($s >= 200 && $s < 300)  { $reservados++;  continue; }
        // Tudo o mais e erro de verdade e NAO pode se parecer com "ja existia".
        $erros++;
        error_log('wa_camp_reserva: falha ao reservar status ' . $s . ' | campanha ' . $campanha_id);
    }
    return ['reservados' => $reservados, 'ja_existiam' => $ja, 'erros' => $erros];
}

/* Prazo da posse de uma linha em 'enviando'. Generoso de proposito: a janela
   real entre o envio sair e a gravacao acontecer e de milissegundos, entao
   30 minutos so alcanca processo que realmente morreu no meio (php-fpm
   derrubado, timeout de rede que nunca retorna). O risco aceito e reenviar
   uma linha cujo envio JA SAIU e cuja gravacao falhou (ver o log dentro de
   wa_camp_drena, que marca esse caso exato com o wamid); a alternativa -
   deixar a linha presa para sempre - e pior: o destinatario nunca recebe a
   mensagem e ninguem fica sabendo que aquela pessoa ficou de fora. */
const WA_CAMP_POSSE_LEASE = 1800;   // 30 min

/* Devolve para 'reservado' toda linha 'enviando' mais velha que o prazo da
   posse. Sem isto, um processo que morre entre tomar posse e gravar o
   resultado deixa a linha presa para sempre, e a campanha nunca conclui -
   o indice unico (campanha_id, lead_id) impede ate re-reservar a pessoa. */
function wa_camp_recupera_orfas($campanha_id) {
    $limite = gmdate('c', time() - WA_CAMP_POSSE_LEASE);
    $orfas = wa_db_update_linhas('po_wa_envios',
        'campanha_id=eq.' . rawurlencode($campanha_id)
        . '&status=eq.enviando&enviando_at=lt.' . rawurlencode($limite),
        ['status' => 'reservado', 'enviando_at' => null]);
    if ($orfas) {
        error_log('wa_camp_recupera_orfas: ' . count($orfas)
            . ' linha(s) presas em enviando voltaram para a fila | campanha ' . $campanha_id);
    }
    return is_array($orfas) ? count($orfas) : 0;
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
    if ($camp === null) {
        error_log('wa_camp_drena: leitura da campanha falhou, nada enviado | ' . $campanha_id);
        return ['enviados' => 0, 'falhas' => 0, 'restam' => -1];
    }
    $template = ($camp && isset($camp[0]['template'])) ? trim((string) $camp[0]['template']) : '';
    /* Template vazio (campanha nao encontrada, ou cadastrada sem template) NAO
       pode queimar a fila: sem esta guarda, cada linha reservada vira 'falha'
       com "template sem nome" (wa_send_template recusa), e falha e terminal -
       o indice unico impede ate re-reservar. Melhor nao fazer nada e deixar a
       fila intacta para a proxima varredura, depois que a campanha for
       corrigida. */
    if ($template === '') {
        error_log('wa_camp_drena: campanha sem template, fila intacta | ' . $campanha_id);
        return ['enviados' => 0, 'falhas' => 0, 'restam' => -1];
    }

    // Antes de ler a fila: solta quem ficou preso em 'enviando' de um dreno
    // anterior que morreu no meio, senao essas linhas ficam invisiveis para
    // sempre (nao sao mais 'reservado' e nunca viram outra coisa sozinhas).
    wa_camp_recupera_orfas($campanha_id);

    $fila = wa_db_select_estrito('po_wa_envios',
        'campanha_id=eq.' . rawurlencode($campanha_id) . '&status=eq.reservado&limit=' . $limite);
    if ($fila === null) {
        error_log('wa_camp_drena: leitura da fila falhou, nada enviado | campanha ' . $campanha_id);
        return ['enviados' => 0, 'falhas' => 0, 'restam' => -1];
    }

    $enviados = 0; $falhas = 0;
    foreach ($fila as $e) {
        /* TOMADA DE POSSE. O `status=eq.reservado` na query e a corrida: quem
           patcheia primeiro recebe a linha de volta, quem chega depois recebe
           lista vazia e pula. Sem isto, o cron e o painel drenando juntos
           mandam a mesma mensagem duas vezes, a R$ 0,31 cada, e nada acusa. */
        $posse = wa_db_update_linhas('po_wa_envios',
            'id=eq.' . rawurlencode($e['id']) . '&status=eq.reservado',
            ['status' => 'enviando', 'enviando_at' => gmdate('c')]);
        if ($posse === null) {
            // Erro de verdade na tomada de posse (rede fora, 5xx): a linha
            // continua 'reservado' e volta na proxima varredura. Nao e a
            // mesma coisa que "outro processo ganhou a corrida".
            error_log('wa_camp_drena: falha ao tomar posse, pulando | envio ' . $e['id']);
            continue;
        }
        if (!$posse) continue;   // outro dreno pegou esta linha primeiro

        $r = wa_camp_envia($e['wa_id'], $e['nome'] ?? '', $template);
        if (!empty($r['ok']) && !empty($r['wamid'])) {
            // Estado so avanca se o envio SAIU, e o wamid e o que prova isso:
            // e por ele que o recibo de entrega volta (Task 7).
            $gravou = wa_db_update('po_wa_envios', 'id=eq.' . rawurlencode($e['id']), [
                'status'     => 'enviado',
                'wamid'      => $r['wamid'],
                'enviado_at' => gmdate('c'),
            ]);
            /* O envio JA SAIU e ja foi cobrado. Se a gravacao falhar aqui, a
               linha fica presa em 'enviando' (nao existe como desfazer o
               envio) e wa_camp_recupera_orfas a devolve para 'reservado'
               depois do prazo, arriscando reenviar. Nao ha correcao melhor
               dentro desta funcao: o que resta e gritar no log com o
               identificador da mensagem, que e por onde se reconcilia na mao. */
            if (!$gravou) {
                error_log('wa_camp_drena: ENVIO SAIU E A GRAVACAO FALHOU, risco de reenvio | envio '
                    . $e['id'] . ' wamid ' . $r['wamid']);
            }
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
