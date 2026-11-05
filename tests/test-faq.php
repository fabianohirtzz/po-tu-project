<?php
/* Pagina de perguntas frequentes (/perguntas-frequentes) e /llms.txt.

   Existem para as buscas com IA (ChatGPT, Perplexity, Gemini, Resumos com IA
   do Google), que citam texto em formato pergunta-resposta. A regra que este
   teste trava: o FAQPage do JSON-LD sai da MESMA lista que o texto visivel.
   Marcacao que diz uma coisa e pagina que mostra outra e violacao das
   diretrizes de dados estruturados do Google. */
define('PO_TEST', 1);
require __DIR__ . '/../lib/po-data.php';
require __DIR__ . '/../lib/po-view.php';
require __DIR__ . '/../faq.php';
require __DIR__ . '/../llms.php';
require __DIR__ . '/../sitemap.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

$itens = po_faq_itens();
ok(count($itens) >= 8, 'pelo menos 8 perguntas');
foreach ($itens as $it) {
    ok(isset($it['p'], $it['r']) && substr($it['p'], -1) === '?', 'cada item tem pergunta (terminando em ?) e resposta: ' . ($it['p'] ?? ''));
    ok(strpos($it['r'], '—') === false && strpos($it['p'], '—') === false, 'sem travessao (regra de copy): ' . $it['p']);
}

$h = po_faq_html($itens);
ok(substr_count($h, '<h1') === 1, 'exatamente um h1');
ok(substr_count($h, '<details') === count($itens), 'um <details> por pergunta');
foreach ($itens as $it) {
    ok(strpos($h, po_e($it['p'])) !== false, 'pergunta visivel: ' . $it['p']);
    ok(strpos($h, po_e($it['r'])) !== false, 'resposta visivel: ' . $it['p']);
}

$j = po_faq_jsonld($itens);
ok($j['@type'] === 'FAQPage', 'json-ld e FAQPage');
ok(count($j['mainEntity']) === count($itens), 'uma Question por pergunta');
foreach ($j['mainEntity'] as $i => $q) {
    ok($q['@type'] === 'Question' && $q['name'] === $itens[$i]['p'], 'Question = pergunta visivel');
    ok($q['acceptedAnswer']['text'] === $itens[$i]['r'], 'Answer = resposta visivel');
}

// a pagina e alcancavel: rota, sitemap e rodape
ok(preg_match('~RewriteRule \^perguntas-frequentes/\?\$\s+/faq\.php~', file_get_contents(__DIR__ . '/../.htaccess')) === 1, '.htaccess tem a rota');
ok(strpos(po_sitemap_xml([]), PO_BASE . '/perguntas-frequentes</loc>') !== false, 'sitemap lista o FAQ');
ok(strpos(po_footer(), 'href="/perguntas-frequentes"') !== false, 'rodape das paginas PHP aponta para o FAQ');
foreach (['contato.html', 'nossa-historia.html', 'politica-de-privacidade.html'] as $pg) {
    ok(strpos(file_get_contents(__DIR__ . '/../' . $pg), 'href="/perguntas-frequentes"') !== false, $pg . ' aponta para o FAQ no rodape');
}

/* llms.txt: resumo em markdown para IA. Roteiros saem do banco (como o
   sitemap), entao roteiro novo entra sozinho e roteiro oculto nunca aparece. */
$lista = [
    ['slug' => 'turquia', 'titulo' => 'Turquia com Antália', 'dias' => 17, 'periodo' => 'De 27/10/26 à 12/11/26', 'status_viagem' => 'esgotado'],
    ['slug' => 'chile', 'titulo' => 'Chile', 'dias' => 8, 'descricao_curta' => "Linha 1\nlinha 2"],
];
$t = po_llms_txt($lista);
ok(strpos($t, "# Pereira Oliveira Turismo\n") === 0, 'comeca com o titulo H1 do formato llms.txt');
ok(strpos($t, "\n> ") !== false, 'tem o resumo em blockquote');
ok(strpos($t, '(' . PO_BASE . '/roteiros/turquia)') !== false, 'lista o roteiro com URL absoluta');
ok(strpos($t, 'Vagas esgotadas') !== false, 'informa o status da viagem');
ok(strpos($t, "Linha 1 linha 2") !== false, 'descricao em uma linha so (quebra nao estraga a lista)');
ok(strpos($t, '(' . PO_BASE . '/perguntas-frequentes)') !== false, 'aponta para o FAQ');
ok(strpos($t, '/painel') === false, 'nunca menciona o painel');
ok(strpos(po_llms_txt([]), 'Roteiros') !== false, 'sem banco ainda gera o arquivo');
ok(preg_match('~RewriteRule \^llms\\\\\.txt\$\s+/llms\.php~', file_get_contents(__DIR__ . '/../.htaccess')) === 1, '.htaccess serve /llms.txt pelo php');

echo "faq ok\n";
