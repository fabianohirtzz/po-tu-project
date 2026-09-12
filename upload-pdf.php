<?php
/* ============================================================
   Pereira Oliveira Turismo — upload do PDF do roteiro.

   Recebe do painel (/painel) o PDF de um roteiro e grava em
   /docs/<slug>-<hash>.pdf no docroot. Devolve a URL pública.

   Porte enxuto do upload-video.php (mesmo login, mesmas fatias),
   com três diferenças: sem "tipo" (há um só PDF por roteiro), grava
   em /docs e valida a assinatura %PDF em vez de ftyp do MP4.

   Hospedagem: cPanel   ·   Domínio: pereiraoliveiraturismo.com.br

   POR QUE NA EREHOST (e não no Supabase Storage, como as imagens):
   o Supabase é projeto COMPARTILHADO com NOX/hd360. O free tier dá
   1 GB de storage e 5 GB de egress/mês para o PROJETO INTEIRO. Um
   PDF de vários MB baixado muitas vezes comeria a cota dos outros
   clientes. Aqui não há esse teto.

   POR QUE EM FATIAS (chunks):
   o cPanel limita upload_max_filesize/post_max_size (tipicamente
   64 MB). Um roteiro em PDF com imagens passa disso fácil. O painel
   corta o arquivo em pedaços de 5 MB e nós remontamos aqui.

   ------------------------------------------------------------
   ENTRADA (POST, multipart/form-data):
     sb_token : access_token do Supabase (valida o login)
     action   : "chunk" (padrão) | "delete"
     slug     : slug do roteiro (define o nome do arquivo)
     uid      : id hex da sessão de upload (agrupa as fatias)
     offset   : byte em que esta fatia começa (garante a ordem)
     last     : "1" na última fatia
     chunk    : a fatia binária
   SAÍDA (JSON):
     fatia intermediária → { ok:true, received:<bytes> }
     última fatia        → { ok:true, url:"https://.../docs/x.pdf" }
============================================================ */

// ---------------------- CONFIG ----------------------
// Supabase — URL e anon key são PÚBLICAS (validam o login do painel).
$SUPABASE_URL      = 'https://euzmbswywwhmicjlszqw.supabase.co';
$SUPABASE_ANON_KEY = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImV1em1ic3d5d3dobWljamxzenF3Iiwicm9sZSI6ImFub24iLCJpYXQiOjE3ODA0NDEyODYsImV4cCI6MjA5NjAxNzI4Nn0.oSIv6fSKVxO9Umuii6xt98cT0yoSqepTIzVCdcocfuU';

$PDF_DIR   = __DIR__ . '/docs';
$MAX_BYTES = 200 * 1024 * 1024;  // 200 MB — trava de sanidade, não limite de uso
// ----------------------------------------------------

if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

header('Content-Type: application/json; charset=utf-8');

function fail($code, $msg) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail(405, 'Método não permitido.');
if (!function_exists('curl_init')) fail(500, 'cURL indisponível no servidor.');

/* -------- valida o login (Supabase Auth) --------
   As funcoes vivem em lib/po-auth.php: a mesma dupla estava copiada aqui,
   no importar.php e no upload-video.php, e copia de rotina de autorizacao
   envelhece torto - o dia em que uma delas ganhar um remendo de seguranca,
   as outras ficam para tras em silencio. Comportamento identico ao que
   estava aqui, com uma trava a mais: token so de espaco nunca autoriza. */
require_once __DIR__ . '/lib/po-auth.php';

if (!po_auth_ok($SUPABASE_URL, $SUPABASE_ANON_KEY)) {
    fail(401, 'Sessão inválida. Faça login no painel novamente.');
}

/* -------- entrada -------- */
// Só [a-z0-9-]: o slug entra no nome do arquivo, então nada de "../" ou barra.
$slug = strtolower(trim((string) ($_POST['slug'] ?? '')));
$slug = preg_replace('/[^a-z0-9\-]/', '', $slug);
$slug = trim((string) $slug, '-');
if ($slug === '') fail(400, 'Slug do roteiro ausente.');

$action = (string) ($_POST['action'] ?? 'chunk');

if (!is_dir($PDF_DIR) && !@mkdir($PDF_DIR, 0755, true)) {
    fail(500, 'Não foi possível criar a pasta /docs no servidor.');
}

function baseUrl() {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'pereiraoliveiraturismo.com.br';
    return ($https ? 'https://' : 'http://') . $host;
}

// Apaga os PDFs anteriores DESTE roteiro. Sem isso, cada troca deixaria o
// arquivo antigo ocupando disco para sempre. Há um só PDF por roteiro, então
// o glob <slug>-*.pdf é seguro (não há um segundo tipo para colidir, como
// acontecia com os dois vídeos).
function limpaAntigos($dir, $slug, $manter = null) {
    foreach (glob($dir . '/' . $slug . '-*.pdf') ?: [] as $f) {
        if ($manter !== null && realpath($f) === realpath($manter)) continue;
        @unlink($f);
    }
}

/* -------- action=delete: remove o PDF do roteiro -------- */
if ($action === 'delete') {
    limpaAntigos($PDF_DIR, $slug);
    echo json_encode(['ok' => true]);
    exit;
}

/* -------- action=chunk: remonta o arquivo fatia a fatia -------- */
$uid = preg_replace('/[^a-f0-9]/', '', strtolower((string) ($_POST['uid'] ?? '')));
if (strlen($uid) < 8 || strlen($uid) > 40) fail(400, 'Identificador de upload inválido.');

$offset = (int) ($_POST['offset'] ?? -1);
$last   = ((string) ($_POST['last'] ?? '')) === '1';

if (!isset($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
    fail(400, 'Fatia não recebida (o servidor pode ter recusado o tamanho).');
}

$tmpDir = $PDF_DIR . '/.tmp';
if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0755, true)) {
    fail(500, 'Não foi possível criar a pasta temporária.');
}
// As fatias soltas não são conteúdo público — o .htaccess barra o acesso a .tmp.
if (!is_file($tmpDir . '/.htaccess')) {
    @file_put_contents($tmpDir . '/.htaccess', "Require all denied\nDeny from all\n");
}

$part = $tmpDir . '/' . $uid . '.part';
$have = is_file($part) ? filesize($part) : 0;

// O offset declarado tem que bater com o que já está gravado. É o que garante
// que as fatias entrem na ordem certa — fora de ordem, o PDF sairia corrompido.
if ($offset !== $have) {
    @unlink($part);
    fail(409, 'Fatias fora de ordem. Envie o arquivo novamente.');
}
if ($have + $_FILES['chunk']['size'] > $MAX_BYTES) {
    @unlink($part);
    fail(413, 'PDF acima de 200 MB.');
}

$in = @fopen($_FILES['chunk']['tmp_name'], 'rb');
if (!$in) fail(500, 'Falha ao ler a fatia.');
$out = @fopen($part, 'ab');
if (!$out) { fclose($in); fail(500, 'Falha ao gravar a fatia.'); }
stream_copy_to_stream($in, $out);
fclose($in);
fclose($out);

if (!$last) {
    clearstatcache(true, $part);
    echo json_encode(['ok' => true, 'received' => filesize($part)]);
    exit;
}

/* -------- última fatia: valida e publica -------- */
clearstatcache(true, $part);
if (filesize($part) < 64) { @unlink($part); fail(400, 'PDF vazio ou incompleto.'); }

// Confere a assinatura do PDF ("%PDF-" nos primeiros bytes). Como o endpoint
// grava um arquivo servível no docroot, não basta confiar na extensão.
$fh = fopen($part, 'rb');
$head = fread($fh, 5);
fclose($fh);
if ($head !== '%PDF-') {
    @unlink($part);
    fail(415, 'Arquivo não é um PDF válido.');
}

$final = $PDF_DIR . '/' . $slug . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.pdf';
if (!@rename($part, $final)) { @unlink($part); fail(500, 'Falha ao publicar o PDF.'); }
@chmod($final, 0644);
limpaAntigos($PDF_DIR, $slug, $final);

echo json_encode(['ok' => true, 'url' => baseUrl() . '/docs/' . basename($final)]);
