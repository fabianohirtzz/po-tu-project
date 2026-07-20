<?php
require __DIR__ . '/../lib/po-data.php';
require __DIR__ . '/../lib/po-view.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

ok(po_e('<script>&"') === '&lt;script&gt;&amp;&quot;', 'po_e escapa < > & aspas');
ok(po_e(null) === '', 'po_e aceita null');
ok(po_url('/roteiros') === 'https://pereiraoliveiraturismo.com.br/roteiros', 'po_url absolutiza');
ok(po_roteiro_href(['slug' => 'turquia']) === '/roteiros/turquia', 'href do roteiro');

$r = ['titulo' => 'Turquia com Antália', 'data_label' => 'De 27/10/26 à 12/11/26'];
$t = po_titulo_seo($r);
ok(strpos($t, 'Excursão') === 0, 'titulo comeca com Excursao (a palavra que o publico busca)');
ok(strpos($t, '2026') !== false, 'titulo traz o ano');
ok(strpos($t, 'Florianópolis') !== false, 'titulo traz a origem, que e o diferencial sem concorrencia');

$semdata = po_titulo_seo(['titulo' => 'Turquia', 'data_label' => '']);
ok(strpos($semdata, '  ') === false, 'sem ano nao deixa espaco duplo');

$h = po_head([
    'title' => 'X', 'description' => 'D', 'canonical' => po_url('/roteiros'),
    'og_image' => po_url('/assets/images/roteiro-grecia.jpg'), 'css' => ['assets/css/roteiro.css?v=7'],
    'jsonld' => ['@context' => 'https://schema.org', '@type' => 'TouristTrip'],
]);
ok(strpos($h, '<title>X</title>') !== false, 'head tem title');
ok(strpos($h, 'rel="canonical"') !== false, 'head tem canonical');
ok(strpos($h, 'og:image') !== false, 'head tem og:image');
ok(strpos($h, 'TouristTrip') !== false, 'head embute o json-ld');
ok(strpos($h, 'GT-TNH4L3BV') !== false, 'head mantem a Google Tag');
ok(strpos($h, 'noindex') === false, 'head sem noindex por padrao');

$hn = po_head(['title' => 'X', 'description' => '', 'canonical' => '', 'og_image' => '', 'css' => [], 'noindex' => true]);
ok(strpos($hn, 'noindex') !== false, 'noindex quando pedido');

/* Um titulo de roteiro com "</script>" (colado do Word, sem querer, ou pelo
   importador com IA) nao pode fechar a tag e injetar HTML. O parser de HTML
   encerra <script> na sequencia literal "</script" sem diferenciar
   maiuscula/minuscula, por isso a checagem usa stripos. */
$xss = po_head([
    'title' => 'X', 'description' => 'D', 'canonical' => '', 'og_image' => '', 'css' => [],
    'jsonld' => ['@context' => 'https://schema.org', '@type' => 'TouristTrip', 'name' => 'Turquia</script><script>alert(1)</script>'],
]);
ok(stripos($xss, '</script><script>') === false, 'jsonld nao fecha a tag script e injeta html');
ok(preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $xss, $m) === 1, 'acha o bloco jsonld no head');
$decoded = json_decode($m[1] ?? '', true);
ok(json_last_error() === JSON_ERROR_NONE, 'jsonld continua parseavel depois do escape (nao virou lixo)');
ok(($decoded['name'] ?? null) === 'Turquia</script><script>alert(1)</script>', 'o valor original sobrevive ao decode');

ok(strpos(po_header(), 'wa.me/5548996048882') !== false, 'header tem o whatsapp com o 55');
ok(strpos(po_footer(), 'wa.me/5548996048882') !== false, 'footer tem o whatsapp com o 55');
echo "po-view ok\n";
