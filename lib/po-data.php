<?php
require_once __DIR__ . '/po-datas.php';
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
/* $token: le como o usuario logado do painel (pre-visualizacao) em vez da anon.
   Leitura com token NUNCA passa pelo cache em disco: o cache e compartilhado e
   serviria o rascunho para a proxima requisicao anonima da mesma URL. */
function po_http_get($url, $ttl = 600, $token = null) {
    if ($GLOBALS['PO_FETCHER']) {
        $f = $GLOBALS['PO_FETCHER'];
        return $token === null ? $f($url) : $f($url, $token);
    }
    $file = po_cache_dir() . '/' . sha1($url) . '.json';
    if ($token === null && is_file($file) && (time() - filemtime($file)) < $ttl) {
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
            'Authorization: Bearer ' . ($token === null ? $cfg['SUPABASE_ANON_KEY'] : $token),
            'Accept: application/json',
        ],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($token !== null) {
        return ($body === false || $code < 200 || $code >= 300) ? null : $body;
    }
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

/* Apaga o cache dos roteiros. Chamada por limpa-cache.php depois que o painel
   salva ou exclui um roteiro: sem isso o site levava ate 10 min para mostrar a
   mudanca (status novo, roteiro ocultado). Devolve quantos arquivos apagou. */
function po_cache_limpa() {
    $n = 0;
    foreach (glob(po_cache_dir() . '/*.json') ?: [] as $f) {
        if (@unlink($f)) $n++;
    }
    return $n;
}

function po_rest($query, $ttl = 600) {
    return po_rest_tabela('po_roteiros', $query, $ttl);
}

/* Mesma leitura (anon, cache em disco) para outras tabelas publicas do site:
   po_avaliacoes e po_instagram (lib/po-social.php). O nome da tabela vem do
   codigo, nunca de entrada. */
function po_rest_tabela($tabela, $query, $ttl = 600) {
    $cfg = po_config();
    $body = po_http_get(rtrim($cfg['SUPABASE_URL'], '/') . '/rest/v1/' . $tabela . '?' . $query, $ttl);
    if ($body === null) return null;
    $d = json_decode($body, true);
    return is_array($d) ? $d : null;
}

/* ativo=eq.true e OBRIGATORIO e explicito.
   Em 16/07/2026 o banco tinha 0 linhas inativas, entao "anon so ve ativos"
   passou por vacuidade: nao ha prova de que a RLS filtre. Se ela nao filtrar
   e a gente confiar, o primeiro rascunho da cliente vai pro ar e pro Google. */
/* A LISTA de roteiros a venda: ordem cronologica, sem as viagens que ja
   terminaram.

   Esta e a UNICA leitura de lista do lado do servidor - a home, /roteiros,
   "Outros roteiros", o llms.txt e o catalogo do robo todos saem daqui. Por
   isso a ordem e o filtro moram AQUI e nao em cada tela: superficie nova de
   roteiro nasce ordenada e sem viagem vencida, sem ninguem lembrar de
   chamar nada. Era o contrario ate 05/11/2026, e o resultado era que so a
   lista do painel Sobre e o robo ordenavam por data; a home, /roteiros e as
   outras saiam na ordem manual do painel.

   O `order=ordem.asc` continua na consulta de proposito: ele e o CRITERIO DE
   EMPATE de po_por_saida (dois roteiros na mesma data mantem a ordem que a
   cliente escolheu no painel), e sem ele o empate dependeria da ordem que o
   PostgREST devolvesse. */
function po_fetch_roteiros() {
    return po_por_saida(po_sem_vencidos(po_fetch_roteiros_todos()));
}

/* A mesma lista COM as viagens que ja terminaram. Um chamador so: o sitemap.

   O slug e a identidade estavel da viagem e a URL carrega a autoridade que a
   edicao seguinte herda. Tirar do sitemap no dia seguinte a viagem, somado a
   nenhum link interno apontar para ela, orfanaria a URL e jogaria fora o que
   os 301 de 20/07 existiram para recuperar. A pagina segue respondendo 200
   porque po_fetch_roteiro() e uma consulta separada desta lista. */
function po_fetch_roteiros_todos() {
    $d = po_rest('select=*&ativo=eq.true&order=ordem.asc,created_at.asc');
    return $d === null ? [] : $d;
}

function po_fetch_roteiro($slug) {
    $d = po_rest('select=*&ativo=eq.true&slug=eq.' . rawurlencode($slug) . '&limit=1');
    return (is_array($d) && count($d)) ? $d[0] : null;
}

/* Pre-visualizacao do painel: o roteiro por ID, ativo ou nao, lido com o
   token de quem esta logado. Quem libera o rascunho e a RLS do banco (o
   mesmo acesso que o painel ja tem para listar os ocultos), nao este codigo.
   O ID e conferido como UUID antes de entrar na URL do PostgREST: um valor
   livre ali poderia carregar filtros a mais. */
function po_fetch_roteiro_preview($id, $token) {
    if (!is_string($id) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) return null;
    if (!is_string($token) || trim($token) === '') return null;
    $cfg  = po_config();
    $body = po_http_get(rtrim($cfg['SUPABASE_URL'], '/') . '/rest/v1/po_roteiros?select=*&id=eq.' . $id . '&limit=1', 0, trim($token));
    $d    = $body === null ? null : json_decode($body, true);
    return (is_array($d) && count($d) && is_array($d[0])) ? $d[0] : null;
}

/* Ano da viagem, para o <title> ("Excursao Turquia 2026 ...").
   Formatos reais no banco: "De 27/10/26 a 12/11/26", "25/11/26 a 02/12/26",
   "10/12/2025 a 21/12/2025". Pega a PRIMEIRA data. */
function po_ano($r) {
    /* SAIDAS SEMANAIS: o ano vem da proxima saida, senao o <title> de um
       roteiro semanal nasce sem ano. */
    if (is_array($r) && ($r['status_viagem'] ?? null) === 'semanal') {
        $prox = po_proxima_saida($r);
        if ($prox !== null) return substr($prox, 0, 4);
    }
    $s = trim((string) (($r['data_label'] ?? '') !== '' ? $r['data_label'] : ($r['periodo'] ?? '')));
    if ($s === '') return '';
    if (preg_match('~\b\d{1,2}/\d{1,2}/(\d{4})\b~', $s, $m)) return $m[1];
    if (preg_match('~\b\d{1,2}/\d{1,2}/(\d{2})\b~', $s, $m)) return '20' . $m[1];
    if (preg_match('~\b(20\d{2})\b~', $s, $m)) return $m[1];
    return '';
}
