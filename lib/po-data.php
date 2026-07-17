<?php
/* ============================================================
   Pereira Oliveira: camada de dados dos roteiros (server-side).

   POR QUE NO SERVIDOR: a pagina de roteiro precisa existir em HTML
   para o Google. Ate 16/07/2026 ela era montada no navegador e
   marcada noindex, ou seja, os roteiros a venda nao existiam na
   busca.

   ANON KEY: e publica por natureza (roda no navegador em
   assets/js/po-config.js). Nao e segredo. O que NAO pode aparecer
   aqui e a service_role.
============================================================ */

$SUPABASE_URL      = 'https://euzmbswywwhmicjlszqw.supabase.co';
$SUPABASE_ANON_KEY = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImV1em1ic3d5d3dobWljamxzenF3Iiwicm9sZSI6ImFub24iLCJpYXQiOjE3ODA0NDEyODYsImV4cCI6MjA5NjAxNzI4Nn0.oSIv6fSKVxO9Umuii6xt98cT0yoSqepTIzVCdcocfuU';

if (is_file(__DIR__ . '/../config.local.php')) {
    require __DIR__ . '/../config.local.php';
}

function po_config() {
    global $SUPABASE_URL, $SUPABASE_ANON_KEY;
    return ['SUPABASE_URL' => $SUPABASE_URL, 'SUPABASE_ANON_KEY' => $SUPABASE_ANON_KEY];
}

$GLOBALS['PO_FETCHER'] = null;
function po_set_fetcher($f) { $GLOBALS['PO_FETCHER'] = $f; }

function po_cache_dir() {
    // sys_get_temp_dir e gravavel no cPanel e NAO e servido pela web.
    $d = sys_get_temp_dir() . '/po-cache';
    if (!is_dir($d)) { @mkdir($d, 0700, true); }
    return $d;
}

/* GET no Supabase, com cache em disco. TTL curto: o painel edita e a
   cliente quer ver a mudanca no site sem esperar. */
function po_http_get($url, $ttl = 600) {
    if ($GLOBALS['PO_FETCHER']) {
        $f = $GLOBALS['PO_FETCHER'];
        return $f($url);
    }
    $file = po_cache_dir() . '/' . sha1($url) . '.json';
    if (is_file($file) && (time() - filemtime($file)) < $ttl) {
        $c = @file_get_contents($file);
        if ($c !== false) return $c;
    }
    if (!function_exists('curl_init')) return null;
    $cfg = po_config();
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => [
            'apikey: ' . $cfg['SUPABASE_ANON_KEY'],
            'Authorization: Bearer ' . $cfg['SUPABASE_ANON_KEY'],
            'Accept: application/json',
        ],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $code < 200 || $code >= 300) {
        error_log('PO roteiros fetch falhou (' . $code . ')');
        // Supabase fora do ar: serve cache velho em vez de pagina vazia.
        // Pagina vazia indexada e pior que pagina desatualizada.
        if (is_file($file)) { $c = @file_get_contents($file); if ($c !== false) return $c; }
        return null;
    }
    po_cache_write($file, $body);
    return $body;
}

/* Escrita atomica: grava num temporario no MESMO diretorio e faz rename().
   rename() e atomico no mesmo filesystem, entao um leitor concorrente sempre
   ve o arquivo antigo inteiro ou o novo inteiro, nunca JSON truncado. Sem
   isso, json_decode() de um arquivo pela metade devolve null, o que faz
   po_fetch_roteiro() devolver null, o que vira 404 pra um roteiro que existe. */
function po_cache_write($file, $body) {
    $tmp = $file . '.' . getmypid() . '.' . uniqid('', true) . '.tmp';
    if (@file_put_contents($tmp, $body, LOCK_EX) === false) return;
    if (!@rename($tmp, $file)) { @unlink($tmp); }
}

function po_rest($query, $ttl = 600) {
    $cfg = po_config();
    $body = po_http_get(rtrim($cfg['SUPABASE_URL'], '/') . '/rest/v1/po_roteiros?' . $query, $ttl);
    if ($body === null) return null;
    $d = json_decode($body, true);
    return is_array($d) ? $d : null;
}

/* ativo=eq.true e OBRIGATORIO e explicito.
   Em 16/07/2026 o banco tinha 0 linhas inativas, entao "anon so ve ativos"
   passou por vacuidade: nao ha prova de que a RLS filtre. Se ela nao filtrar
   e a gente confiar, o primeiro rascunho da cliente vai pro ar e pro Google. */
function po_fetch_roteiros() {
    $d = po_rest('select=*&ativo=eq.true&order=ordem.asc,created_at.asc');
    return $d === null ? [] : $d;
}

function po_fetch_roteiro($slug) {
    $d = po_rest('select=*&ativo=eq.true&slug=eq.' . rawurlencode($slug) . '&limit=1');
    return (is_array($d) && count($d)) ? $d[0] : null;
}

/* Ano da viagem, para o <title> ("Excursao Turquia 2026 ...").
   Formatos reais no banco: "De 27/10/26 a 12/11/26", "25/11/26 a 02/12/26",
   "10/12/2025 a 21/12/2025". Pega a PRIMEIRA data. */
function po_ano($r) {
    $s = trim((string) (($r['data_label'] ?? '') !== '' ? $r['data_label'] : ($r['periodo'] ?? '')));
    if ($s === '') return '';
    if (preg_match('~\b\d{1,2}/\d{1,2}/(\d{4})\b~', $s, $m)) return $m[1];
    if (preg_match('~\b\d{1,2}/\d{1,2}/(\d{2})\b~', $s, $m)) return '20' . $m[1];
    if (preg_match('~\b(20\d{2})\b~', $s, $m)) return $m[1];
    return '';
}
