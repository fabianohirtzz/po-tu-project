<?php
/* ============================================================
   Escrita e leitura no Supabase com a service_role.

   O lib/po-data.php so le (e com cache em disco, que aqui seria veneno:
   o estado da conversa muda a cada mensagem). Este arquivo e o caminho de
   escrita, sem cache nenhum.

   A service_role IGNORA a RLS e so existe no servidor, em
   config.local.php. Nunca no Git, nunca no painel. Por isso os headers
   vem de wa_config() (lib/wa-config.php), nao de po_config(): aquele
   arquivo e carregado no render publico das paginas de roteiro e o
   cabecalho dele proibe a service_role de aparecer la.
============================================================ */

require_once __DIR__ . '/wa-config.php';

function wa_db_set_transport($f) { $GLOBALS['WA_DB_TRANSPORT'] = $f; }

function wa_db_headers() {
    $cfg = wa_config();
    $key = $cfg['SUPABASE_SERVICE_KEY'] ?? '';
    return [
        'apikey: ' . $key,
        'Authorization: Bearer ' . $key,
        'Content-Type: application/json',
        'Prefer: return=representation',
    ];
}

/* Um unico ponto de saida para a rede, para o teste conseguir substituir. */
function wa_db_http($metodo, $url, $corpo, $headers) {
    if (isset($GLOBALS['WA_DB_TRANSPORT']) && $GLOBALS['WA_DB_TRANSPORT']) {
        return call_user_func($GLOBALS['WA_DB_TRANSPORT'], $metodo, $url, $corpo, $headers);
    }
    // Sem curl no servidor isto seria fatal, e quem chama e o webhook: um
    // fatal derruba a resposta pra Meta, ela reenvia em loop e acaba
    // desinscrevendo o webhook. Mesmo contorno da rede fora do ar: null.
    if (!function_exists('curl_init')) {
        error_log('wa_db_http sem curl disponivel | ' . $url);
        return null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 10,
    ]);
    if ($corpo !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $corpo);
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erro   = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        error_log('wa_db_http falhou: ' . $erro . ' | ' . $url);
        return null;
    }
    return ['status' => $status, 'body' => $body];
}

function wa_db_url($tabela, $query = '') {
    $cfg = wa_config();
    return rtrim($cfg['SUPABASE_URL'], '/') . '/rest/v1/' . $tabela . ($query !== '' ? '?' . $query : '');
}

function wa_db_select($tabela, $query) {
    $r = wa_db_http('GET', wa_db_url($tabela, $query), null, wa_db_headers());
    if (!$r || $r['status'] >= 300) return [];
    $j = json_decode($r['body'], true);
    return is_array($j) ? $j : [];
}

/* $ignora_conflito: para o log de mensagens, onde o unique em wamid E a
   idempotencia. Um 409 ali significa "a Meta reenviou o mesmo evento", que
   e o comportamento esperado, nao erro. */
function wa_db_insert($tabela, $linha, $ignora_conflito = false) {
    $r = wa_db_http('POST', wa_db_url($tabela), json_encode($linha), wa_db_headers());
    if (!$r) return null;
    if ($r['status'] === 409 && $ignora_conflito) return null;
    if ($r['status'] >= 300) {
        // Sem o corpo de proposito: em unique_violation o Postgres ecoa o
        // valor em conflito, que aqui pode ser telefone de cliente.
        error_log('wa_db_insert ' . $tabela . ' status ' . $r['status']);
        return null;
    }
    $j = json_decode($r['body'], true);
    return (is_array($j) && isset($j[0])) ? $j[0] : null;
}

function wa_db_update($tabela, $query, $campos) {
    $r = wa_db_http('PATCH', wa_db_url($tabela, $query), json_encode($campos), wa_db_headers());
    if (!$r) return false;
    if ($r['status'] >= 300) {
        // Idem: sem o corpo, que pode carregar dado de cliente.
        error_log('wa_db_update ' . $tabela . ' status ' . $r['status']);
        return false;
    }
    return true;
}
