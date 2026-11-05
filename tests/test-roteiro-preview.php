<?php
/* Pre-visualizacao do roteiro pelo painel (22/10/2026).

   O bug: o botao "Ver" do painel abria /roteiros/<slug>, que so serve roteiro
   ATIVO (de proposito: rascunho nao vai pro Google). Todo roteiro oculto - que
   e exatamente o que a cliente quer conferir antes de publicar - dava 404.

   A saida: o painel manda por POST o token do login e o ID do roteiro. O
   servidor confere o login e le o roteiro COM O TOKEN DELA, entao quem libera
   o rascunho e a RLS do banco, sem service_role. Por ID e nao por slug: desde
   2026-10-22 duas edicoes ocultas podem ter o mesmo slug. */
define('PO_TEST', 1);
require __DIR__ . '/../lib/po-data.php';
require __DIR__ . '/../lib/po-view.php';
require __DIR__ . '/../roteiro.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

$ID = 'e91bfc1c-df67-4235-87d3-b3f79a196550';
$chamadas = [];
po_set_fetcher(function ($url, $token = null) use (&$chamadas) {
    $chamadas[] = [$url, $token];
    return '[{"id":"e91bfc1c-df67-4235-87d3-b3f79a196550","slug":"vietna","titulo":"Vietna","ativo":false}]';
});

$r = po_fetch_roteiro_preview($ID, 'tok-da-cliente');
ok(is_array($r) && $r['titulo'] === 'Vietna', 'devolve o roteiro oculto');
ok(count($chamadas) === 1, 'uma chamada');
[$url, $tok] = $chamadas[0];
ok($tok === 'tok-da-cliente', 'le com o token DELA (a RLS decide), nunca com a anon');
ok(strpos($url, 'id=eq.' . $ID) !== false, 'busca por id');
ok(strpos($url, 'ativo=') === false, 'sem filtro de ativo: e o ponto da pre-visualizacao');

// entrada torta nao vira consulta
$chamadas = [];
ok(po_fetch_roteiro_preview('nao-e-uuid', 'tok') === null, 'id que nao e uuid devolve null');
ok(po_fetch_roteiro_preview($ID . '&or=(ativo.eq.false)', 'tok') === null, 'id com injecao de filtro devolve null');
ok(po_fetch_roteiro_preview($ID, '') === null, 'sem token devolve null');
ok(po_fetch_roteiro_preview($ID, '   ') === null, 'token so de espaco devolve null');
ok(count($chamadas) === 0, 'nenhuma dessas chegou ao banco');

// banco sem a linha (RLS negou ou id nao existe)
po_set_fetcher(function ($url, $token = null) { return '[]'; });
ok(po_fetch_roteiro_preview($ID, 'tok') === null, 'sem linha devolve null');

// faixa de aviso: diz que e previa e, se oculto, que nao esta no site
$fo = po_preview_faixa(['ativo' => false, 'slug' => 'vietna']);
ok(strpos($fo, 'Pré-visualização') !== false, 'faixa diz que e previa');
ok(strpos($fo, 'oculto') !== false, 'oculto avisa que nao esta no site');
$fa = po_preview_faixa(['ativo' => true, 'slug' => 'turquia']);
ok(strpos($fa, 'href="/roteiros/turquia"') !== false, 'ativo aponta para a pagina publica');

// trava no ponto de chamada: o despacho usa login + previa, e responde noindex/no-store
$src = file_get_contents(__DIR__ . '/../roteiro.php');
ok(strpos($src, 'po_auth_ok(') !== false, 'roteiro.php confere o login antes da previa');
ok(strpos($src, 'po_fetch_roteiro_preview(') !== false, 'roteiro.php usa a busca da previa');
ok(strpos($src, "'noindex'") !== false && strpos($src, 'no-store') !== false, 'previa e noindex e sem cache');

// e os dois botoes "Ver" do painel vao pela previa, nao pela URL publica
$app = file_get_contents(__DIR__ . '/../painel/app.js');
ok(preg_match("/function rotVer\\(r\\)\\{[\\s\\S]*?f\\.method='POST'[\\s\\S]*?'preview_id',r\\.id[\\s\\S]*?'sb_token'/", $app) === 1, 'rotVer posta id e token');
ok(strpos($app, "window.open(pubHref(") === false, 'nenhum Ver abre a pagina publica direto');
ok(substr_count($app, 'rotVer(') >= 3, 'definicao + botao do card + botao da gaveta');

echo "roteiro-preview ok\n";
