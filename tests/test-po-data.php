<?php
require __DIR__ . '/../lib/po-data.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

// --- po_ano: extrai o ano dos formatos reais do banco
ok(po_ano(['data_label' => 'De 27/10/26 à 12/11/26']) === '2026', 'po_ano dd/mm/yy com prefixo');
ok(po_ano(['data_label' => '22/02/27 à 15/03/27'])    === '2027', 'po_ano dd/mm/yy sem prefixo');
ok(po_ano(['data_label' => '', 'periodo' => '10/12/2025 a 21/12/2025']) === '2025', 'po_ano cai no periodo, ano com 4 digitos');
ok(po_ano(['data_label' => 'Datas a definir']) === '', 'po_ano sem data devolve vazio');

// --- fetcher injetado: nao toca a rede
$fake = json_encode([[ 'slug' => 'turquia', 'titulo' => 'Turquia', 'ativo' => true ]]);
$capturado = '';
po_set_fetcher(function ($url) use ($fake, &$capturado) { $capturado = $url; return $fake; });

$r = po_fetch_roteiro('turquia');
ok($r !== null && $r['slug'] === 'turquia', 'po_fetch_roteiro devolve o registro');

// O ponto critico: o banco tem 0 linhas inativas, entao o teste de RLS e
// inconclusivo. O filtro TEM que estar na query, nao na confianca.
ok(strpos($capturado, 'ativo=eq.true') !== false, 'po_fetch_roteiro filtra ativo=eq.true na URL');
ok(strpos($capturado, 'slug=eq.turquia') !== false, 'po_fetch_roteiro filtra por slug');

po_set_fetcher(function ($url) use (&$capturado) { $capturado = $url; return '[]'; });
ok(po_fetch_roteiro('nao-existe') === null, 'slug inexistente devolve null');
ok(po_fetch_roteiros() === [], 'lista vazia devolve array vazio');
ok(strpos($capturado, 'ativo=eq.true') !== false, 'po_fetch_roteiros filtra ativo=eq.true');

// --- rede fora do ar nao pode explodir
po_set_fetcher(function ($url) { return null; });
ok(po_fetch_roteiro('turquia') === null, 'fetcher nulo devolve null, nao excecao');
ok(po_fetch_roteiros() === [], 'fetcher nulo devolve [], nao excecao');

// --- json invalido tambem nao
po_set_fetcher(function ($url) { return 'isto nao e json'; });
ok(po_fetch_roteiro('turquia') === null, 'json invalido devolve null');

po_set_fetcher(null);
echo "po-data ok\n";
