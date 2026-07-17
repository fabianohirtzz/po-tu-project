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

// $outros = [] (2o argumento) evita que po_roteiro_html() bata no Supabase de
// verdade via po_fetch_roteiros() (o "outros roteiros" tem cache de 600s e a
// query custa rede real). Mesmo padrao de tests/test-po-data.php, que usa
// po_set_fetcher em vez de deixar a funcao ir na rede.
$h = po_roteiro_html($r, []);
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

// campos faltando nao podem explodir. set_error_handler ANTES: o run.php
// julga pelo exit code, e um Warning do PHP ("Array to string conversion" e
// afins) nao muda o exit code sozinho - ele so ecoa no meio do HTML,
// quebrando header()/layout, e o teste passava verde do mesmo jeito. Com o
// handler, qualquer warning derruba o teste.
set_error_handler(function ($errno, $errstr) {
    fwrite(STDERR, "WARNING nao tratado: $errstr\n");
    exit(1);
});

$min = ['slug' => 'x', 'titulo' => 'X'];
$hm = po_roteiro_html($min, []);
ok(is_string($hm) && strlen($hm) > 0, 'roteiro minimo nao explode');

// Campos aninhados: o Gemini (importador com IA, PDF/DOCX anexado pela
// cliente) as vezes devolve um item como array onde a pagina espera texto.
// Reproduz exatamente os pontos que o review confirmou: hoteis.cidade,
// nao_inclui[i] e roteiro_dias[i].cidades, todos vindo como array em vez de
// string.
$aninhado = array_merge($r, [
    'hoteis'       => [['cidade' => ['Istambul', 'Capadócia'], 'hotel' => 'Hotel X']],
    'nao_inclui'   => ['Bebidas', ['isto' => 'nao deveria ser um array aqui']],
    'roteiro_dias' => [[
        'n' => 1, 'data' => ['27/10'], 'dia_semana' => 'terça',
        'cidades' => ['São Paulo'], 'descricao' => ['Embarque internacional.'],
    ]],
]);
$ha = po_roteiro_html($aninhado, []);
ok(is_string($ha) && strlen($ha) > 0, 'campos aninhados (array onde a pagina espera texto) nao disparam warning');

restore_error_handler();
echo "roteiro-page ok\n";
