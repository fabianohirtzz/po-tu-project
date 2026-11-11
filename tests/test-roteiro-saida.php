<?php
/* Local de saida do roteiro (reuniao de 09/11/2026 com a Lais).

   Campo editavel na gaveta do roteiro ("Saida de Florianopolis, com
   acompanhamento desde o embarque"), mostrado em destaque SO na pagina do
   roteiro. Nada de tag na home nem nos cards: a decisao da reuniao foi nao
   poluir a home, principalmente no celular. */
define('PO_TEST', 1);
require __DIR__ . '/../lib/po-data.php';
require __DIR__ . '/../lib/po-view.php';
require __DIR__ . '/../roteiro.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

$r = ['slug' => 'peru-fascinante', 'titulo' => 'Peru Fascinante', 'dias' => 9, 'periodo' => '', 'ativo' => true,
      'saida' => 'Saída de Florianópolis, com acompanhamento desde o embarque'];
$h = po_roteiro_html($r, []);
ok(substr_count($h, 'class="hero__saida"') === 1, 'a saida aparece uma vez, no hero');
ok(strpos($h, 'Saída de Florianópolis, com acompanhamento desde o embarque') !== false, 'com o texto que a cliente escreveu');
$hero = explode('</section>', $h)[0];
ok(strpos($hero, 'hero__saida') !== false, 'dentro do hero');

$r['saida'] = '  ';
ok(strpos(po_roteiro_html($r, []), 'hero__saida') === false, 'vazia ou so espacos: a linha nao aparece');
unset($r['saida']);
ok(strpos(po_roteiro_html($r, []), 'hero__saida') === false, 'roteiro sem o campo (antes da migration) nao quebra');

$r['saida'] = '<script>x</script>';
ok(strpos(po_roteiro_html($r, []), '<script>x') === false, 'texto escapado');

// so na pagina do roteiro: nem home, nem /roteiros, nem os cards de "outros roteiros"
$raiz = __DIR__ . '/..';
foreach (['roteiros.php', 'index.php', 'assets/js/main.js', 'assets/js/roteiros-shared.js', 'assets/js/historia.js', 'assets/js/link.js'] as $f) {
    ok(strpos(file_get_contents("$raiz/$f"), "'saida'") === false && strpos(file_get_contents("$raiz/$f"), '.saida') === false,
       "$f nao mostra a saida (so a pagina do roteiro mostra)");
}

// painel: o campo existe na gaveta e vai no salvamento
$app = file_get_contents("$raiz/painel/app.js");
ok(strpos($app, 'id="r-saida"') !== false, 'campo na gaveta do roteiro');
ok(preg_match("/saida:\\\$\\('#r-saida'\\)\\.value\\.trim\\(\\)/", $app) === 1, 'o salvamento le o campo');

// a coluna vem de migration propria (o salvamento do painel manda a chave em
// todo roteiro: sem a coluna, a aba Roteiros inteira para de salvar)
$sql = file_get_contents("$raiz/supabase/migrations/2026-11-11-roteiro-saida.sql");
ok(preg_match('/add column if not exists saida text not null default \'\'/i', $sql) === 1, 'migration da coluna');

echo "test-roteiro-saida OK\n";
