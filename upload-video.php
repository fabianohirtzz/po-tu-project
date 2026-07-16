<?php
/* ============================================================
   Pereira Oliveira Turismo — upload de vídeo do roteiro.

   Recebe do painel (/painel) um vídeo de um roteiro e grava em
   /videos/<slug>-<tipo>-<hash>.mp4 no docroot. Devolve a URL pública.

   SÃO DOIS VÍDEOS POR ROTEIRO, e o "tipo" é o que os separa:
     insta → reels 9:16 da seção "por que viajar". Com áudio, só toca
             no clique. Salvo em po_roteiros.video_insta_url.
     capa  → vídeo do hero. Sem áudio, autoplay, mudo, em loop.
             Salvo em po_roteiros.video_capa_url.

   O TIPO PRECISA ENTRAR NO NOME DO ARQUIVO: a limpeza dos vídeos
   antigos varre por glob. Se os dois usassem <slug>-*.mp4, subir um
   apagaria o outro sem avisar. Por isso o glob é <slug>-<tipo>-*.mp4.

   Hospedagem: cPanel   ·   Domínio: pereiraoliveiraturismo.com.br

   POR QUE NA EREHOST (e não no Supabase Storage, como as imagens):
   o Supabase é projeto COMPARTILHADO com NOX/hd360. O free tier dá
   1 GB de storage e 5 GB de egress/mês para o PROJETO INTEIRO. Um
   reels de 10 MB visto 500 vezes já come 5 GB e derruba a cota dos
   outros clientes junto. Aqui não há esse teto.

   POR QUE EM FATIAS (chunks):
   o cPanel limita upload_max_filesize/post_max_size (tipicamente
   64 MB). O painel corta o arquivo em pedaços de 5 MB e nós
   remontamos aqui. É isso que sustenta "aceita qualquer tamanho"
   mesmo quando o navegador não comprime.

   ------------------------------------------------------------
   ENTRADA (POST, multipart/form-data):
     sb_token : access_token do Supabase (valida o login)
     action   : "chunk" (padrão) | "delete"
     slug     : slug do roteiro (define o nome do arquivo)
     tipo     : "insta" | "capa" (isola os dois vídeos do roteiro)
     uid      : id hex da sessão de upload (agrupa as fatias)
     offset   : byte em que esta fatia começa (garante a ordem)
     last     : "1" na última fatia
     chunk    : a fatia binária
   SAÍDA (JSON):
     fatia intermediária → { ok:true, received:<bytes> }
     última fatia        → { ok:true, url:"https://.../videos/x.mp4" }
============================================================ */

// ---------------------- CONFIG ----------------------
// Supabase — URL e anon key são PÚBLICAS (validam o login do painel).
$SUPABASE_URL      = 'https://euzmbswywwhmicjlszqw.supabase.co';
$SUPABASE_ANON_KEY = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImV1em1ic3d5d3dobWljamxzenF3Iiwicm9sZSI6ImFub24iLCJpYXQiOjE3ODA0NDEyODYsImV4cCI6MjA5NjAxNzI4Nn0.oSIv6fSKVxO9Umuii6xt98cT0yoSqepTIzVCdcocfuU';

$VIDEO_DIR = __DIR__ . '/videos';
$MAX_BYTES = 2 * 1024 * 1024 * 1024;  // 2 GB — trava de sanidade, não limite de uso
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

/* -------- valida o login (Supabase Auth) — mesmo padrão do importar.php -------- */
function bearerToken() {
    if (!empty($_POST['sb_token'])) return trim((string) $_POST['sb_token']);
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($h === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strtolower($k) === 'authorization') { $h = $v; break; }
        }
    }
    return preg_match('/Bearer\s+(.+)/i', $h, $m) ? trim($m[1]) : '';
}

function usuarioValido($url, $anon, $token) {
    if ($token === '') return false;
    $ch = curl_init(rtrim($url, '/') . '/auth/v1/user');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['apikey: ' . $anon, 'Authorization: Bearer ' . $token],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $code < 200 || $code >= 300) return false;
    $u = json_decode($resp, true);
    return is_array($u) && !empty($u['id']);
}

if (!usuarioValido($SUPABASE_URL, $SUPABASE_ANON_KEY, bearerToken())) {
    fail(401, 'Sessão inválida. Faça login no painel novamente.');
}

/* -------- entrada -------- */
// Só [a-z0-9-]: o slug entra no nome do arquivo, então nada de "../" ou barra.
$slug = strtolower(trim((string) ($_POST['slug'] ?? '')));
$slug = preg_replace('/[^a-z0-9\-]/', '', $slug);
$slug = trim((string) $slug, '-');
if ($slug === '') fail(400, 'Slug do roteiro ausente.');

// Lista fechada: o tipo entra no nome do arquivo e no glob da limpeza.
// Qualquer valor fora daqui poderia fazer um tipo apagar os vídeos do outro.
$tipo = strtolower(trim((string) ($_POST['tipo'] ?? 'insta')));
if (!in_array($tipo, ['insta', 'capa'], true)) fail(400, 'Tipo de vídeo inválido.');

$action = (string) ($_POST['action'] ?? 'chunk');

if (!is_dir($VIDEO_DIR) && !@mkdir($VIDEO_DIR, 0755, true)) {
    fail(500, 'Não foi possível criar a pasta /videos no servidor.');
}

function baseUrl() {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'pereiraoliveiraturismo.com.br';
    return ($https ? 'https://' : 'http://') . $host;
}

// Apaga os vídeos anteriores DESTE roteiro E DESTE TIPO. Sem isso, cada troca
// de vídeo deixaria o arquivo antigo ocupando disco para sempre.
// O $tipo no glob é essencial: com <slug>-*.mp4 o vídeo capa e o do Instagram
// casariam no mesmo padrão e um apagaria o outro.
function limpaAntigos($dir, $slug, $tipo, $manter = null) {
    foreach (glob($dir . '/' . $slug . '-' . $tipo . '-*.mp4') ?: [] as $f) {
        if ($manter !== null && realpath($f) === realpath($manter)) continue;
        @unlink($f);
    }
}

/* -------- action=delete: remove o vídeo do roteiro (só o tipo pedido) -------- */
if ($action === 'delete') {
    limpaAntigos($VIDEO_DIR, $slug, $tipo);
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

$tmpDir = $VIDEO_DIR . '/.tmp';
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
// que as fatias entrem na ordem certa — fora de ordem, o MP4 sairia corrompido.
if ($offset !== $have) {
    @unlink($part);
    fail(409, 'Fatias fora de ordem. Envie o vídeo novamente.');
}
if ($have + $_FILES['chunk']['size'] > $MAX_BYTES) {
    @unlink($part);
    fail(413, 'Vídeo acima de 2 GB.');
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
if (filesize($part) < 1024) { @unlink($part); fail(400, 'Vídeo vazio ou incompleto.'); }

// Confere a assinatura do MP4 ("ftyp" nos bytes 4..8). Como o endpoint grava
// um arquivo servível no docroot, não basta confiar na extensão declarada.
$fh = fopen($part, 'rb');
$head = fread($fh, 12);
fclose($fh);
if (substr($head, 4, 4) !== 'ftyp') {
    @unlink($part);
    fail(415, 'Arquivo não é um MP4 válido.');
}

$final = $VIDEO_DIR . '/' . $slug . '-' . $tipo . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.mp4';
if (!@rename($part, $final)) { @unlink($part); fail(500, 'Falha ao publicar o vídeo.'); }
@chmod($final, 0644);
limpaAntigos($VIDEO_DIR, $slug, $tipo, $final);

echo json_encode(['ok' => true, 'url' => baseUrl() . '/videos/' . basename($final)]);
