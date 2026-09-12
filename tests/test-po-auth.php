<?php
// tests/test-po-auth.php — login do painel, sem tocar a rede.
require __DIR__ . '/../lib/po-auth.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

error_reporting(E_ALL);
set_error_handler(function ($n, $s, $f, $l) { fwrite(STDERR, "PHP ERROR: $s em $f:$l\n"); exit(1); });

/* Verificador falso: registra o que recebeu e SEMPRE aprova. Se um token
   vazio passar por ele, o teste abaixo pega - e esse e o ponto. */
$vistos = [];
po_auth_set_verificador(function ($url, $anon, $token) use (&$vistos) {
    $vistos[] = [$url, $anon, $token];
    return true;
});

// --- token vazio reprova ANTES de chegar ao verificador
ok(po_auth_usuario_valido('https://x.supabase.co', 'anon', '') === false, 'token vazio reprova');
ok(po_auth_usuario_valido('https://x.supabase.co', 'anon', '   ') === false, 'token so de espaco reprova');
ok(po_auth_usuario_valido('https://x.supabase.co', 'anon', null) === false, 'token null reprova');
ok(po_auth_usuario_valido('https://x.supabase.co', 'anon', ['a']) === false, 'token nao-escalar reprova');
ok($vistos === [], 'token vazio nem chega ao verificador');

// --- token preenchido chega ao verificador com url e anon
ok(po_auth_usuario_valido('https://x.supabase.co', 'anon', 'tok.123') === true, 'token valido aprova');
ok($vistos[0] === ['https://x.supabase.co', 'anon', 'tok.123'], 'verificador recebe url, anon e token');

// --- verificador que reprova, reprova
po_auth_set_verificador(fn($u, $a, $t) => false);
ok(po_auth_usuario_valido('https://x.supabase.co', 'anon', 'tok.123') === false, 'verificador manda no resultado');

// --- leitura do token: POST sb_token tem prioridade
po_auth_set_verificador(fn($u, $a, $t) => $t === 'do-post');
$_POST['sb_token'] = ' do-post ';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer do-header';
ok(po_auth_token() === 'do-post', 'sb_token do POST, aparado');
ok(po_auth_ok('https://x.supabase.co', 'anon') === true, 'po_auth_ok le o token da requisicao');

// --- sem POST, cai no cabecalho Authorization
unset($_POST['sb_token']);
ok(po_auth_token() === 'do-header', 'cabecalho Authorization: Bearer');

// --- sem nada, token vazio e po_auth_ok reprova (mesmo com verificador liberal)
unset($_SERVER['HTTP_AUTHORIZATION']);
po_auth_set_verificador(fn($u, $a, $t) => true);
ok(po_auth_token() === '', 'sem POST e sem cabecalho, token vazio');
ok(po_auth_ok('https://x.supabase.co', 'anon') === false, 'requisicao sem token nunca autoriza');

// --- token explicito vence a requisicao
ok(po_auth_ok('https://x.supabase.co', 'anon', 'tok.abc') === true, 'token explicito e usado');

/* ============================================================
   NINGUEM volta a ter copia propria da rotina de autorizacao. O extrato
   para lib/po-auth.php existiu porque cada endpoint tinha a sua dupla
   bearerToken()/usuarioValido(), e copia de autorizacao envelhece torto: o
   dia em que uma delas ganha um remendo de seguranca, as outras ficam para
   tras em silencio. Foi assim que 'token so de espaco' continuou
   autorizando em dois endpoints depois de ser fechado no terceiro.
============================================================ */
foreach (['importar.php', 'upload-video.php', 'upload-pdf.php', 'contatos-importar.php'] as $endpoint) {
    $src = file_get_contents(__DIR__ . '/../' . $endpoint);
    ok(strpos($src, 'po_auth_ok(') !== false, "$endpoint valida o login com po_auth_ok()");
    ok(strpos($src, "lib/po-auth.php") !== false, "$endpoint carrega lib/po-auth.php");
    ok(!preg_match('/function\s+usuarioValido\s*\(/', $src), "$endpoint nao tem copia propria de usuarioValido()");
    ok(!preg_match('/function\s+bearerToken\s*\(/', $src), "$endpoint nao tem copia propria de bearerToken()");
}

echo "test-po-auth OK\n";
