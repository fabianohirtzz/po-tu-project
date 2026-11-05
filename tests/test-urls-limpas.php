<?php
/* URLs sem .html (22/10/2026, pedido do cliente): /nossa-historia, /contato,
   /politica-de-privacidade, /link e / no lugar de /x.html e /index.html.

   O .htaccess redireciona (301) quem chega pelo .html antigo e serve a pagina
   pelo endereco limpo. Este teste trava o resto: nenhum link interno,
   canonical, og:url, JSON-LD, sitemap ou llms.txt pode apontar para o .html
   (senao cada clique e cada rastreio passa por um redirect), e o .htaccess nao
   pode ter regra mandando o endereco limpo de volta para o .html (era o caso
   antes: /nossa-historia -> /nossa-historia.html, que viraria loop). */
define('PO_TEST', 1);
require __DIR__ . '/../lib/po-data.php';
require __DIR__ . '/../lib/po-view.php';
require __DIR__ . '/../sitemap.php';
require __DIR__ . '/../llms.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

$PAGS = '(?:index|nossa-historia|contato|politica-de-privacidade|link)\.html';
$arquivos = ['index.html', 'contato.html', 'nossa-historia.html', 'politica-de-privacidade.html', 'link.html', '404.html',
             'lib/po-view.php', 'faq.php', 'roteiros.php', 'roteiro.php', 'lib/po-social.php'];
foreach ($arquivos as $a) {
    $s = file_get_contents(__DIR__ . '/../' . $a);
    ok(!preg_match('~(?:href|content|action)="[^"]*' . $PAGS . '~', $s, $m), "$a: link para .html (" . ($m[0] ?? '') . ')');
    ok(!preg_match('~"(?:url|item|@id)":"[^"]*' . $PAGS . '~', $s, $m), "$a: JSON-LD com .html (" . ($m[0] ?? '') . ')');
}
ok(strpos(po_sitemap_xml([]), '.html') === false, 'sitemap sem .html');
ok(strpos(po_sitemap_xml([]), PO_BASE . '/nossa-historia</loc>') !== false, 'sitemap com o endereco limpo');
ok(strpos(po_llms_txt([]), '.html') === false, 'llms.txt sem .html');

$ht = str_replace("\r", '', file_get_contents(__DIR__ . '/../.htaccess'));
// nada pode mandar o endereco limpo de volta para o .html
ok(!preg_match('~^(?:Redirect|RedirectMatch|RewriteRule)\s.*\s/?(?:nossa-historia|contato|politica-de-privacidade)\.html\s*\[?R~mi', $ht), 'nenhum redirect para .html');
ok(!preg_match('~^RedirectMatch\s+301\s+\S*nossa-historia\S*\s+/nossa-historia\.html~m', $ht), 'o redirect antigo /nossa-historia -> .html saiu');
// .html antigo -> 301 para o limpo, so em requisicao externa (THE_REQUEST evita loop com o rewrite interno)
ok(preg_match('~RewriteCond %\{THE_REQUEST\} .*\(nossa-historia\|contato\|politica-de-privacidade\|link\)\\\\\.html~', $ht) === 1, '301 do .html para o limpo');
ok(preg_match('~RewriteCond %\{THE_REQUEST\} .*index\\\\\.html~', $ht) === 1, '/index.html -> /');
// endereco limpo serve a pagina
ok(preg_match('~^RewriteRule \^nossa-historia\$\s+/nossa-historia\.php \[L\]~m', $ht) === 1, '/nossa-historia serve o php');
ok(preg_match('~^RewriteRule \^contato\$\s+/contato\.html \[L\]~m', $ht) === 1, '/contato serve o html');
ok(preg_match('~^RewriteRule \^politica-de-privacidade\$\s+/politica-de-privacidade\.html \[L\]~m', $ht) === 1, '/politica-de-privacidade serve o html');
// o redirect do WordPress /sobre-nos segue valendo, agora para o limpo
ok(preg_match('~^RedirectMatch 301 \^/sobre-nos/\?\$\s+/nossa-historia$~m', $ht) === 1, '/sobre-nos -> /nossa-historia');

echo "urls-limpas ok\n";
