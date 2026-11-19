<?php
/* ============================================================
   Meta Ads (fase 3B do rastreio, 13/11/2026). Duas coisas, nenhuma do robo:

   1. NOME DO ANUNCIO. O lead do anuncio que abre o WhatsApp chega so com o
      ad_id (um numero). A API de Marketing devolve o nome do anuncio, do
      conjunto e da campanha, guardados em po_anuncios_meta. Token com
      ads_read na conta de anuncios: META_ADS_TOKEN no config.local.php.

   2. A VENDA DE VOLTA PARA A META (API de Conversoes para Mensagens). Lead
      com ctwa_clid que vira "Contrato assinado" vira um evento Purchase, para
      a Meta aprender quem COMPRA e nao so quem manda mensagem. Precisa de
      META_DATASET_ID (o conjunto de dados ligado a conta do WhatsApp) e de um
      token com whatsapp_business_manage_events (META_CAPI_TOKEN; vazio = o
      WA_TOKEN do sistema, que e o que a Meta recomenda reaproveitar).

   A META NAO DEDUPLICA esses eventos (guia oficial). Por isso cada venda e
   RESERVADA em po_meta_conversoes antes do envio (o lead_id e chave primaria:
   quem insere primeiro leva) e nunca sai duas vezes. Falha volta a ser
   tentada ate 3 vezes; "enviando" que ficou para tras NAO e reenviado (a
   Meta pode ter recebido: duplicar venda e pior que perder uma).

   O token nunca vai em URL nem em log: vai no cabecalho Authorization.
   Toda chamada de rede passa por po_meta_req(), trocavel no teste
   ($GLOBALS['PO_META_HTTP']).
============================================================ */
require_once __DIR__ . '/po-data.php';   // carrega o config.local.php

/* A mesma versao do WA_GRAPH (lib/wa-send.php), cuja validade o
   tests/test-wa-graph.php vigia. O tests/test-meta.php exige que as duas
   andem juntas. */
const PO_META_GRAPH = 'https://graph.facebook.com/v26.0/';
const PO_META_JANELA = 7 * 86400;      // so venda dos ultimos 7 dias vai para a Meta
const PO_META_TENTATIVAS = 3;

function po_meta_config() {
    $g = function ($k) { return isset($GLOBALS[$k]) && is_string($GLOBALS[$k]) ? trim($GLOBALS[$k]) : ''; };
    return [
        'SUPABASE_URL'         => $g('SUPABASE_URL'),
        'SUPABASE_ANON_KEY'    => $g('SUPABASE_ANON_KEY'),
        'SUPABASE_SERVICE_KEY' => $g('SUPABASE_SERVICE_KEY'),
        'META_ADS_TOKEN'       => $g('META_ADS_TOKEN'),
        'META_CAPI_TOKEN'      => $g('META_CAPI_TOKEN') !== '' ? $g('META_CAPI_TOKEN') : $g('WA_TOKEN'),
        'META_DATASET_ID'      => $g('META_DATASET_ID'),
        'WA_WABA_ID'           => $g('WA_WABA_ID'),
    ];
}

/* ---------------- funcoes puras ---------------- */

/* Id de anuncio da Meta e so digito. Qualquer outra coisa nunca entra numa
   URL da Graph. Sem repetidos, no maximo 25 por chamada. */
function po_meta_ids_validos($lista) {
    $out = [];
    foreach ((array) $lista as $id) {
        $id = trim((string) $id);
        if (preg_match('/^\d{5,25}$/', $id) && !in_array($id, $out, true)) $out[] = $id;
        if (count($out) >= 25) break;
    }
    return $out;
}

// {id, name, adset{name}, campaign{name}} da Graph -> as tres colunas.
function po_meta_nomes_da_resposta($json) {
    if (!is_array($json) || empty($json['id'])) return null;
    $t = function ($v) { return is_string($v) && trim($v) !== '' ? trim($v) : null; };
    return [
        'anuncio'  => $t($json['name'] ?? null),
        'conjunto' => $t($json['adset']['name'] ?? null),
        'campanha' => $t($json['campaign']['name'] ?? null),
    ];
}

// A mensagem de erro da Graph, curta, sem nada alem do texto da Meta.
function po_meta_erro($json, $code) {
    $m = is_array($json) ? ($json['error']['message'] ?? '') : '';
    return mb_substr('HTTP ' . (int) $code . ($m !== '' ? ': ' . $m : ''), 0, 300, 'UTF-8');
}

/* Venda que pode ir para a Meta: em status venda, com ctwa_clid, valor e data
   de fechamento, e fechada dentro da janela. */
function po_meta_venda_elegivel(array $lead, $agora) {
    if (($lead['status'] ?? '') !== 'venda') return false;
    if (trim((string) ($lead['ctwa_clid'] ?? '')) === '') return false;
    if ((float) ($lead['venda'] ?? 0) <= 0) return false;
    $t = strtotime((string) ($lead['venda_at'] ?? ''));
    if ($t === false) return false;
    return $t <= $agora + 300 && $agora - $t <= PO_META_JANELA;
}

/* O evento do guia oficial da Meta para anuncio que abre o WhatsApp:
   com ctwa_clid, o user_data leva a conta do WhatsApp e o ctwa_clid. */
function po_meta_evento_compra(array $lead, $waba) {
    return [
        'event_name'        => 'Purchase',
        'event_time'        => (int) strtotime((string) $lead['venda_at']),
        'action_source'     => 'business_messaging',
        'messaging_channel' => 'whatsapp',
        'user_data'         => [
            'whatsapp_business_account_id' => (string) $waba,
            'ctwa_clid'                    => (string) $lead['ctwa_clid'],
        ],
        'custom_data'       => ['currency' => 'BRL', 'value' => round((float) $lead['venda'], 2)],
    ];
}

/* ---------------- rede: um ponto so ---------------- */

/* Devolve [codigo_http, corpo_decodificado_ou_null]. Nunca loga. */
function po_meta_req($metodo, $url, $corpo = null, array $cabecalhos = []) {
    if (!empty($GLOBALS['PO_META_HTTP'])) {
        return call_user_func($GLOBALS['PO_META_HTTP'], $metodo, $url, $corpo, $cabecalhos);
    }
    if (!function_exists('curl_init')) return [0, null];
    $ch = curl_init($url);
    $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_HTTPHEADER => $cabecalhos];
    if ($corpo !== null) $opt[CURLOPT_POSTFIELDS] = json_encode($corpo, JSON_UNESCAPED_UNICODE);
    curl_setopt_array($ch, $opt);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, is_string($resp) ? json_decode($resp, true) : null];
}

function po_meta_graph($metodo, $caminho, $token, $corpo = null) {
    return po_meta_req($metodo, PO_META_GRAPH . ltrim($caminho, '/'), $corpo,
        ['Authorization: Bearer ' . $token, 'Content-Type: application/json']);
}

// Supabase com a service_role: o painel nao escreve po_meta_conversoes.
function po_meta_sb($metodo, $caminho, $corpo, array $cfg, $prefer = 'return=representation') {
    return po_meta_req($metodo, rtrim($cfg['SUPABASE_URL'], '/') . '/rest/v1/' . $caminho, $corpo, [
        'apikey: ' . $cfg['SUPABASE_SERVICE_KEY'],
        'Authorization: Bearer ' . $cfg['SUPABASE_SERVICE_KEY'],
        'Content-Type: application/json',
        'Prefer: ' . $prefer,
    ]);
}

/* ---------------- 1. nomes dos anuncios ---------------- */

/* Busca na Graph o nome dos anuncios pedidos e grava em po_anuncios_meta.
   Erro tambem e gravado (com a hora), para o painel nao pedir de novo o
   mesmo id quebrado a cada carga. Nunca apaga nome ja conhecido nem o nome
   dado a mao. Devolve [codigo_http, resposta]. */
function po_meta_nomes_run(array $ids, array $cfg, $agora) {
    if ($cfg['META_ADS_TOKEN'] === '') return [200, ['ok' => true, 'configurado' => false, 'nomes' => []]];
    if ($cfg['SUPABASE_SERVICE_KEY'] === '') return [503, ['ok' => false, 'erro' => 'sem service_role']];
    $nomes = [];
    foreach (po_meta_ids_validos($ids) as $id) {
        [$code, $json] = po_meta_graph('GET', $id . '?fields=' . rawurlencode('id,name,adset{name},campaign{name}'), $cfg['META_ADS_TOKEN']);
        $n = $code === 200 ? po_meta_nomes_da_resposta($json) : null;
        $linha = ['ad_id' => $id, 'buscado_at' => gmdate('c', $agora), 'updated_at' => gmdate('c', $agora)];
        if ($n) { $linha += $n; $linha['erro'] = null; $nomes[$id] = $n; }
        else    { $linha['erro'] = po_meta_erro($json, $code); }
        po_meta_sb('POST', 'po_anuncios_meta?on_conflict=ad_id', [$linha], $cfg, 'resolution=merge-duplicates,return=minimal');
    }
    return [200, ['ok' => true, 'configurado' => true, 'nomes' => $nomes]];
}

/* ---------------- 2. venda de volta para a Meta ---------------- */

function po_meta_falta_config(array $cfg) {
    $falta = [];
    foreach (['META_DATASET_ID', 'WA_WABA_ID', 'META_CAPI_TOKEN', 'SUPABASE_SERVICE_KEY'] as $k) {
        if ($cfg[$k] === '') $falta[] = $k;
    }
    return $falta;
}

/* Reserva a venda para envio. Devolve true so para quem ficou com ela:
   linha nova (o lead_id e chave primaria) ou falha anterior ainda com
   tentativa sobrando (o PATCH casa status E tentativas, entao so um leva). */
function po_meta_reserva(array $lead, $reg, array $cfg, $agora) {
    $id = rawurlencode((string) $lead['id']);
    if ($reg === null) {
        [$c, $rows] = po_meta_sb('POST', 'po_meta_conversoes', [[
            'lead_id' => $lead['id'], 'status' => 'enviando', 'tentativas' => 1,
            'valor' => round((float) $lead['venda'], 2), 'updated_at' => gmdate('c', $agora),
        ]], $cfg, 'resolution=ignore-duplicates,return=representation');
        return $c >= 200 && $c < 300 && is_array($rows) && count($rows) === 1;
    }
    $t = (int) ($reg['tentativas'] ?? 0);
    if (($reg['status'] ?? '') !== 'falha' || $t >= PO_META_TENTATIVAS) return false;
    [$c, $rows] = po_meta_sb('PATCH', 'po_meta_conversoes?lead_id=eq.' . $id . '&status=eq.falha&tentativas=eq.' . $t,
        ['status' => 'enviando', 'tentativas' => $t + 1, 'updated_at' => gmdate('c', $agora)], $cfg);
    return $c >= 200 && $c < 300 && is_array($rows) && count($rows) === 1;
}

/* Envia as vendas pendentes. Devolve [codigo_http, resposta]. */
function po_meta_conversao_run(array $cfg, $agora) {
    $falta = po_meta_falta_config($cfg);
    if ($falta) return [200, ['ok' => true, 'configurado' => false, 'falta' => $falta]];

    $desde = gmdate('c', $agora - PO_META_JANELA);
    [$c, $leads] = po_meta_sb('GET', 'po_leads?select=id,status,ctwa_clid,venda,venda_at'
        . '&status=eq.venda&ctwa_clid=not.is.null&venda_at=gte.' . rawurlencode($desde), null, $cfg);
    if ($c !== 200 || !is_array($leads)) return [502, ['ok' => false, 'erro' => 'nao consegui ler as vendas']];
    $leads = array_values(array_filter($leads, function ($l) use ($agora) { return is_array($l) && po_meta_venda_elegivel($l, $agora); }));

    $regs = [];
    if ($leads) {
        $ids = implode(',', array_map(function ($l) { return rawurlencode((string) $l['id']); }, $leads));
        [$c2, $rows] = po_meta_sb('GET', 'po_meta_conversoes?select=lead_id,status,tentativas&lead_id=in.(' . $ids . ')', null, $cfg);
        // Erro lendo o que ja foi enviado NUNCA vira "nada foi enviado": reenviaria tudo.
        if ($c2 !== 200 || !is_array($rows)) return [502, ['ok' => false, 'erro' => 'nao consegui ler os envios anteriores']];
        foreach ($rows as $r) $regs[(string) $r['lead_id']] = $r;
    }

    $res = ['ok' => true, 'configurado' => true, 'elegiveis' => count($leads), 'enviados' => 0, 'falhas' => 0, 'ja_enviados' => 0];
    foreach ($leads as $l) {
        $reg = $regs[(string) $l['id']] ?? null;
        if ($reg !== null && ($reg['status'] ?? '') === 'enviado') { $res['ja_enviados']++; continue; }
        if (!po_meta_reserva($l, $reg, $cfg, $agora)) continue;

        [$ce, $je] = po_meta_graph('POST', rawurlencode($cfg['META_DATASET_ID']) . '/events', $cfg['META_CAPI_TOKEN'],
            ['data' => [po_meta_evento_compra($l, $cfg['WA_WABA_ID'])]]);
        $foi = $ce === 200 && is_array($je) && (int) ($je['events_received'] ?? 0) >= 1;
        $patch = $foi ? ['status' => 'enviado', 'enviado_at' => gmdate('c', $agora), 'erro' => null]
                      : ['status' => 'falha', 'erro' => po_meta_erro($je, $ce)];
        $patch['updated_at'] = gmdate('c', $agora);
        po_meta_sb('PATCH', 'po_meta_conversoes?lead_id=eq.' . rawurlencode((string) $l['id']), $patch, $cfg, 'return=minimal');
        $res[$foi ? 'enviados' : 'falhas']++;
    }
    return [200, $res];
}

/* O conjunto de dados ligado a conta do WhatsApp, so LEITURA (GET). Criar um
   (POST no mesmo endereco) fica para quem configura, de proposito. */
function po_meta_diagnostico_run(array $cfg) {
    if ($cfg['WA_WABA_ID'] === '' || $cfg['META_CAPI_TOKEN'] === '') {
        return [200, ['ok' => true, 'configurado' => false, 'falta' => po_meta_falta_config($cfg)]];
    }
    [$c, $j] = po_meta_graph('GET', rawurlencode($cfg['WA_WABA_ID']) . '/dataset', $cfg['META_CAPI_TOKEN']);
    return [200, ['ok' => $c === 200, 'http' => $c, 'resposta' => $j]];
}
