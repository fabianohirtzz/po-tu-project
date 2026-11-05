<?php
/* Selo do Cadastur (23/10/2026): rodape de todas as paginas, Contato e FAQ.

   Trava: o link e sempre a consulta publica oficial (a do QR code do
   certificado), toda pagina com o selo carrega o cadastur.css, o logo existe,
   e a validade do certificado nunca vai para o site (data vencida publicada
   e pior que nenhuma data). */
define('PO_TEST', 1);
require __DIR__ . '/../lib/po-data.php';
require __DIR__ . '/../lib/po-view.php';
require __DIR__ . '/../faq.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

$raiz = __DIR__ . '/..';
$url  = 'https://cadastur.turismo.gov.br/cadastur/#!/public/qrcode/05622878000182';
ok(PO_CADASTUR_URL === $url, 'url oficial do QR do certificado');
ok(is_file("$raiz/assets/images/cadastur.png"), 'logo existe');

// rodape: PHP e as tres copias estaticas
ok(strpos(po_footer(), 'class="ft__cad" href="' . $url . '"') !== false, 'rodape das paginas PHP');
foreach (['contato.html', 'nossa-historia.html', 'politica-de-privacidade.html'] as $p) {
    $s = file_get_contents("$raiz/$p");
    ok(strpos($s, 'class="ft__cad" href="' . $url . '"') !== false, "rodape de $p");
    ok(preg_match('#<link rel="stylesheet" href="assets/css/cadastur\.css\?v=\d+">#', $s), "$p carrega cadastur.css");
}
// toda pagina PHP que imprime o rodape carrega o css
foreach (['roteiro.php', 'roteiros.php', 'faq.php'] as $p) {
    $s = file_get_contents("$raiz/$p");
    ok(strpos($s, 'po_footer()') !== false && preg_match("#'/assets/css/cadastur\.css\?v=\d+'#", $s), "$p carrega cadastur.css");
}

// selo no Contato e no FAQ
ok(strpos(file_get_contents("$raiz/contato.html"), 'class="cad-selo cta__cad" href="' . $url . '"') !== false, 'selo na pagina de contato');
$itens = po_faq_itens();
ok(strpos(po_faq_html($itens), 'class="cad-selo faq__cad" href="' . $url . '"') !== false, 'selo no FAQ');
$tem = false;
foreach ($itens as $it) if (strpos($it['r'], 'Cadastur') !== false) $tem = true;
ok($tem, 'FAQ responde sobre o Cadastur (entra no FAQPage junto)');

// nada de validade publicada
foreach (['contato.html', 'nossa-historia.html', 'politica-de-privacidade.html', 'faq.php', 'lib/po-view.php'] as $p) {
    $s = file_get_contents("$raiz/$p");
    ok(!preg_match('#25/03/2027|2027#', preg_replace('#/\*.*?\*/#s', '', $s)), "sem validade do certificado em $p");
}

echo "cadastur ok\n";
