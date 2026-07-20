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
ok(strpos($x, '/nossa-historia.html') !== false, 'lista a historia');
ok(strpos($x, '/contato.html') !== false, 'lista o contato');
ok(strpos($x, '<lastmod>2026-07-16</lastmod>') !== false, 'lastmod vem do updated_at');

// as 5 estaticas morreram: nao podem reaparecer
foreach (['mercados-de-natal', 'grecia-terra-mar', 'floracao-das-cerejeiras', 'encantos-do-mediterraneo'] as $morto) {
    ok(strpos($x, $morto) === false, "sitemap nao lista o roteiro extinto $morto");
}
ok(strpos($x, 'roteiro.html') === false, 'sitemap nao lista o template antigo');
ok(strpos($x, '?slug=') === false, 'sitemap nao usa URL com query');
echo "sitemap ok\n";
