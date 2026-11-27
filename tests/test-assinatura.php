<?php
/* Assinatura animada da Freela In Home no rodape (27/11/2026).
   Toda pagina com rodape tem a pilula e carrega o assinatura.css (o CSS e um
   arquivo so, como o cadastur.css). A curva do aviao existe em TRES lugares
   (o trilho e o rastro do SVG e o offset-path do CSS) e tem que ser a mesma,
   senao o aviao voa fora da rota. Sem emoji (regra de copy da casa). */
function ok($c, $m) { if (!$c) { fwrite(STDERR, "ASSERT: $m\n"); exit(1); } }
$raiz = __DIR__ . '/..';
$le = function ($p) use ($raiz) { return str_replace("\r\n", "\n", file_get_contents("$raiz/$p")); };

$html = ['contato.html', 'nossa-historia.html', 'politica-de-privacidade.html'];
$php  = ['faq.php', 'roteiro.php', 'roteiros.php'];
foreach ($html as $p) {
    $s = $le($p);
    ok(strpos($s, 'class="assina"') !== false, "$p tem a assinatura");
    ok(preg_match('#<link rel="stylesheet" href="assets/css/assinatura\.css\?v=\d+">#', $s), "$p carrega assinatura.css");
}
foreach ($php as $p) {
    $s = $le($p);
    ok(strpos($s, 'po_footer()') !== false && preg_match("#'/assets/css/assinatura\.css\?v=\d+'#", $s), "$p carrega assinatura.css");
}
$view = $le('lib/po-view.php');
ok(strpos($view, 'class="assina"') !== false, 'po_footer tem a assinatura');

$css = $le('assets/css/assinatura.css');
ok(preg_match('/offset-path:path\("([^"]+)"\)/', $css, $m), 'o aviao anda por offset-path');
$curva = $m[1];
foreach (array_merge($html, ['lib/po-view.php']) as $p) {
    $s = $le($p);
    ok(substr_count($s, 'd="' . $curva . '"') === 2, "$p: trilho e rastro na mesma curva do aviao");
    ok(strpos($s, "\u{2708}") === false, "$p sem o emoji de aviao");
    ok(strpos($s, 'freelainhome.com.br') !== false, "$p linka a Freela");
}
ok(strpos($css, 'prefers-reduced-motion') !== false, 'respeita prefers-reduced-motion');
echo "test-assinatura OK\n";
