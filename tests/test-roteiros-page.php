<?php
define('PO_TEST', 1);
require __DIR__ . '/../lib/po-data.php';
require __DIR__ . '/../lib/po-view.php';
require __DIR__ . '/../roteiros.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

$lista = [
    ['slug' => 'turquia', 'titulo' => 'Turquia com Antália', 'data_label' => 'De 27/10/26 à 12/11/26', 'dias' => 17, 'capa_url' => 'https://x/a.jpg', 'descricao_curta' => 'A.', 'local_label' => 'Turquia'],
    ['slug' => 'chile-e-deserto-do-atacama', 'titulo' => 'Chile e Deserto do Atacama', 'data_label' => '25/11/26 a 02/12/26', 'dias' => 8, 'capa_url' => 'https://x/b.jpg', 'descricao_curta' => 'B.', 'local_label' => 'Chile'],
];
$h = po_roteiros_html($lista);

ok(substr_count($h, '<h1') === 1, 'exatamente um h1');
ok(strpos($h, 'href="/roteiros/turquia"') !== false, 'link do roteiro no HTML, nao no JS');
ok(strpos($h, 'href="/roteiros/chile-e-deserto-do-atacama"') !== false, 'link do segundo roteiro');
ok(strpos($h, 'Turquia com Antália') !== false, 'titulo do roteiro em texto');
ok(strpos($h, 'De 27/10/26') !== false, 'data em texto, e nao so na imagem');
ok(strpos($h, 'wa.me/5548996048882') !== false, 'whatsapp presente');

$vazio = po_roteiros_html([]);
ok(strpos($vazio, '<h1') !== false, 'sem roteiro ainda renderiza a pagina');
ok(strpos($vazio, 'WhatsApp') !== false || strpos($vazio, 'wa.me') !== false, 'sem roteiro ainda oferece contato');

$j = po_roteiros_jsonld($lista);
ok($j['@type'] === 'ItemList', 'json-ld e ItemList');
ok(count($j['itemListElement']) === 2, 'ItemList com os 2 roteiros');
echo "roteiros-page ok\n";
