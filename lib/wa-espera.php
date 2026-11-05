<?php
/* A LISTA DE ESPERA POR DESTINO: quem pediu para ser avisado de um lugar que
   a agencia ainda nao tem data para vender.
   Tarefa 6 do plano 2026-11-05-robo-entende-a-frase.

   Tabela propria (`po_wa_espera`), nao `po_leads.espera`. Filtrar por
   destino, saber QUANDO a pessoa pediu e remover UMA entrada sao as tres
   coisas que jsonb faz mal, e as tres sao o pedido do cliente de 04/11/2026
   (um seletor de destino na transmissao, puxando os leads daquele pais).

   DEPENDENCIA: so wa-db.php. Este arquivo e FOLHA, como wa-destinos.php: a
   seta do subsistema e `db/send/po-data <- fluxo <- sim/motor <- timeout`, e
   ele NAO requer wa-fluxo.php nem wa-motor.php.

   DUAS REGRAS QUE MORAM AQUI:

   1. A REMOCAO E SOFT (`removido_at`), nunca delete. Quem pediu para ser
      avisado da Italia e depois saiu da lista continua TENDO pedido, e esse
      fato e o que sustenta a base legal da transmissao - legitimo interesse
      pela relacao existente, nao consentimento (ver politica-de-privacidade).
      Apagar a linha apagaria a prova de que o interesse existiu.

   2. "PEDIR DUAS VEZES NAO CRIA DUAS LINHAS" e garantido pelo INDICE UNICO
      PARCIAL do banco (`(lead_id, destino) where removido_at is null`), NUNCA
      por uma consulta antes de escrever. Consultar-e-depois-escrever tem
      corrida: duas mensagens da mesma pessoa chegando juntas passariam as
      duas pela consulta e criariam as duas linhas. E a mesma licao da reserva
      da transmissao e do lote de importacao: reserve antes, o unique e o
      cadeado. O 409 e o cadeado funcionando - nao vira log de erro. */

require_once __DIR__ . '/wa-db.php';

/* `po_leads.id` e uuid (conferido em 05/11/2026), e `po_wa_espera.lead_id` e
   `uuid references po_leads(id)`. Um id que nao e uuid chegaria ao PostgREST
   como 400, entao a guarda nao e cosmetica: ela separa "entrada errada de
   quem chamou" de "banco fora", que tem desfechos diferentes. */
function wa_espera_uuid($id) {
    return is_string($id) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) === 1;
}

/* O destino e aparado antes de gravar. " Itália " e o MESMO destino: sem o
   trim o indice unico nao os reconheceria como iguais e o seletor da
   transmissao mostraria o mesmo pais duas vezes, com os leads divididos
   entre os dois. */
function wa_espera_destino($destino) {
    return trim((string) $destino);
}

/* Anota o pedido. Devolve true quando criou a linha.

   FALSE TEM DOIS SIGNIFICADOS, e os dois sao "nao criou agora": a pessoa ja
   estava na lista (409, o cadeado), ou a escrita falhou. Quem chama trata os
   dois igual - nao ha nada a fazer de diferente - mas so o segundo deixa log,
   para o 409 esperado nao virar ruido que esconde o erro de verdade. */
function wa_espera_anota($lead_id, $destino, $roteiro_slug = null) {
    $destino = wa_espera_destino($destino);
    if (!wa_espera_uuid($lead_id) || $destino === '') return false;

    $r = wa_db_insert_status('po_wa_espera', [
        'lead_id'      => $lead_id,
        'destino'      => $destino,
        'roteiro_slug' => ($roteiro_slug === null || $roteiro_slug === '') ? null : (string) $roteiro_slug,
    ]);
    if ($r['status'] === 409) return false;   // ja esta na lista: o indice parcial recusou
    if ($r['status'] < 200 || $r['status'] >= 300) {
        error_log('wa_espera_anota: status ' . $r['status'] . ' ao anotar espera de ' . $destino);
        return false;
    }
    return true;
}

/* Tira a pessoa da lista daquele destino, SEM apagar a linha. Devolve true
   quando a escrita passou - inclusive quando nada casou (o botao clicado duas
   vezes nao e erro). False e falha de banco.

   O filtro `removido_at=is.null` nao e enfeite: sem ele, uma remocao
   recarimbaria `removido_at` de linhas antigas e apagaria a data real em que
   a pessoa saiu da lista. */
function wa_espera_remove($lead_id, $destino) {
    $destino = wa_espera_destino($destino);
    if (!wa_espera_uuid($lead_id) || $destino === '') return false;

    return wa_db_update('po_wa_espera',
        'lead_id=eq.' . rawurlencode($lead_id) . '&destino=eq.' . rawurlencode($destino) . '&removido_at=is.null',
        ['removido_at' => gmdate('c')]);
}
