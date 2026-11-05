<?php
/* Limpeza do cache de roteiros ao salvar no painel (22/10/2026).

   As paginas /roteiros e /roteiros/<slug> leem o banco com cache em disco de
   10 min (po-data.php). A cliente marcou um roteiro como esgotado e a tag nao
   apareceu: o cache servia a versao anterior. Agora o painel, depois de
   salvar ou excluir, chama limpa-cache.php, que so obedece a login valido. */
define('PO_TEST', 1);
require __DIR__ . '/../lib/po-data.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

$d = po_cache_dir();
@file_put_contents($d . '/teste-a.json', '[]');
@file_put_contents($d . '/teste-b.json', '[]');
@file_put_contents($d . '/nao-apaga.txt', 'x');
$n = po_cache_limpa();
ok($n >= 2, 'apagou os json (apagou ' . $n . ')');
ok(!glob($d . '/*.json'), 'nenhum json sobra');
ok(is_file($d . '/nao-apaga.txt'), 'so mexe em *.json');
@unlink($d . '/nao-apaga.txt');

// endpoint: login antes de tudo, so POST, nao vaza nada
$src = file_get_contents(__DIR__ . '/../limpa-cache.php');
ok(strpos($src, "require_once __DIR__ . '/lib/po-auth.php'") !== false, 'endpoint carrega a auth');
$pAuth = strpos($src, 'po_auth_ok(');
$pLimpa = strpos($src, 'po_cache_limpa(');
ok($pAuth !== false && $pLimpa !== false && $pAuth < $pLimpa, 'confere login ANTES de limpar');
ok(strpos($src, "'POST'") !== false, 'so aceita POST');

// e o painel chama depois de salvar e de excluir (trava no ponto de chamada)
$app = file_get_contents(__DIR__ . '/../painel/app.js');
ok(substr_count($app, 'limpaCacheSite()') >= 3, 'salvar + excluir (gaveta) + excluir (card) limpam o cache');
ok(preg_match("/\\$\\('#rdr-save'\\)\\.onclick=[\\s\\S]*?limpaCacheSite\\(\\)[\\s\\S]*?\\n\\};/", $app) === 1, 'o salvar chama a limpeza');

echo "limpa-cache ok\n";
