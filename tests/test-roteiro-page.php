<?php
define('PO_TEST', 1); // impede o arquivo de despachar sozinho
require __DIR__ . '/../lib/po-data.php';
require __DIR__ . '/../lib/po-view.php';
require __DIR__ . '/../roteiro.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

$r = [
    'slug' => 'turquia', 'titulo' => 'Turquia com Antália', 'subtitulo' => 'Istambul e Capadócia',
    'descricao_curta' => 'Uma viagem pela Turquia.', 'data_label' => 'De 27/10/26 à 12/11/26',
    'dias' => 17, 'local_label' => 'Turquia', 'capa_url' => 'https://x/capa.jpg',
    'roteiro_dias' => [['n' => 1, 'data' => '27/10', 'dia_semana' => 'terça', 'cidades' => 'São Paulo', 'descricao' => 'Embarque <b>internacional</b>.', 'refeicoes' => 'Jantar']],
    'inclui' => ['Passagem aérea'], 'nao_inclui' => ['Bebidas'], 'hoteis' => [['cidade' => 'Istambul', 'hotel' => 'Hotel X']],
    'valores' => [['tag' => 'Duplo', 'valor' => 'R$ 40.000', 'extra' => 'em 10x']],
    'galeria' => ['https://x/1.jpg', 'https://x/2.jpg'],
];

$h = po_roteiro_html($r);
ok(strpos($h, 'Turquia com Antália') !== false, 'corpo tem o titulo');
ok(strpos($h, 'class="day__p"') !== false, 'corpo tem o dia a dia em texto');
ok(strpos($h, 'Embarque &lt;b&gt;internacional&lt;/b&gt;') !== false, 'descricao do dia vem escapada (dado vem do painel)');
ok(strpos($h, 'wa.me/5548996048882') !== false, 'corpo tem o whatsapp');
ok(strpos($h, 'R$ 40.000') !== false, 'corpo tem o investimento');
ok(strpos($h, 'invest__cards--single') !== false, 'um valor so usa o modificador single');
ok(substr_count($h, 'gal__item') === 2, 'galeria com 2 itens');
ok(strpos($h, 'id="f-roteiro"') !== false, 'formulario de lead presente');
ok(strpos($h, 'value="site-roteiro-turquia"') !== false, 'origem do lead carrega o slug');

$j = po_roteiro_jsonld($r);
ok($j['@type'] === 'TouristTrip', 'json-ld e TouristTrip');
ok($j['name'] === 'Turquia com Antália', 'json-ld tem o nome');
ok(isset($j['offers']), 'json-ld tem offers');

// sem valores nao inventa oferta
$j2 = po_roteiro_jsonld(array_merge($r, ['valores' => []]));
ok(!isset($j2['offers']), 'sem valores nao emite offers falsa');

// campos faltando nao podem explodir
$min = ['slug' => 'x', 'titulo' => 'X'];
$hm = po_roteiro_html($min);
ok(is_string($hm) && strlen($hm) > 0, 'roteiro minimo nao explode');
echo "roteiro-page ok\n";
