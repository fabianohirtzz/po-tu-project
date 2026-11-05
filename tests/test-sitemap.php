<?php
define('PO_TEST', 1);
require __DIR__ . '/../lib/po-data.php';
require __DIR__ . '/../lib/po-view.php';
require __DIR__ . '/../sitemap.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

$x = po_sitemap_xml([
    ['slug' => 'turquia', 'updated_at' => '2026-07-16T10:00:00+00:00'],
    ['slug' => 'chile-e-deserto-do-atacama', 'updated_at' => null],
]);

ok(strpos($x, '<?xml') === 0, 'comeca com a declaracao xml');
ok(strpos($x, 'https://pereiraoliveiraturismo.com.br/roteiros/turquia') !== false, 'lista o roteiro');
ok(strpos($x, 'https://pereiraoliveiraturismo.com.br/roteiros</loc>') !== false, 'lista a pagina mae');
ok(strpos($x, 'https://pereiraoliveiraturismo.com.br/</loc>') !== false, 'lista a home');
ok(strpos($x, '/nossa-historia</loc>') !== false, 'lista a historia (endereco limpo, sem .html)');
ok(strpos($x, '/contato</loc>') !== false, 'lista o contato (endereco limpo)');
ok(strpos($x, '<lastmod>2026-07-16</lastmod>') !== false, 'lastmod vem do updated_at');

/* O SITEMAP MANTEM A VIAGEM QUE JA TERMINOU, e esta e a excecao da regra de
   05/11/2026 ("se venceu ja entra como oculto automaticamente"). As telas de
   venda tiram o vencido; o sitemap nao.

   O slug e a identidade estavel da viagem e a URL carrega a autoridade que a
   edicao seguinte herda. Tirar do sitemap no dia seguinte a viagem, somado a
   nenhum link interno apontar para ela, orfanaria o endereco e jogaria fora o
   que os 301 de 20/07/2026 existiram para recuperar. A pagina continua
   respondendo 200 porque po_fetch_roteiro($slug) e consulta separada da
   lista.

   A rodada de mutacao mostrou que trocar po_fetch_roteiros_todos() por
   po_fetch_roteiros() no sitemap.php nao quebrava nada - o endpoint perdia os
   vencidos em silencio. */
$fonte = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../sitemap.php'));
ok(strpos($fonte, 'po_sitemap_xml(po_fetch_roteiros_todos())') !== false,
    'o sitemap pede a lista COM os vencidos (po_fetch_roteiros_todos)');
ok(strpos($fonte, 'po_sitemap_xml(po_fetch_roteiros())') === false,
    'e nunca a lista de venda, que tira os vencidos');

/* E a funcao em si lista o que recebe, vencido ou nao: ela nao filtra por
   data por conta propria. */
$xv = po_sitemap_xml([['slug' => 'ja-partiu', 'updated_at' => null, 'periodo' => 'De 01/01/20 a 10/01/20']]);
ok(strpos($xv, '/roteiros/ja-partiu') !== false, 'po_sitemap_xml nao descarta viagem vencida');

// as 5 estaticas morreram: nao podem reaparecer
foreach (['mercados-de-natal', 'grecia-terra-mar', 'floracao-das-cerejeiras', 'encantos-do-mediterraneo'] as $morto) {
    ok(strpos($x, $morto) === false, "sitemap nao lista o roteiro extinto $morto");
}
ok(strpos($x, 'roteiro.html') === false, 'sitemap nao lista o template antigo');
ok(strpos($x, '?slug=') === false, 'sitemap nao usa URL com query');
echo "sitemap ok\n";
