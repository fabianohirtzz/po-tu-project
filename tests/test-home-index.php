<?php
/* Home montada pelo servidor (index.php), 22/10/2026.

   O painel "Sobre" da home lista as proximas viagens. Escrita a mao no
   index.html, a lista envelheceria (e a regra do projeto proibe dado de
   roteiro no index.html por isso). O index.php le o index.html e troca o
   marcador pela lista do banco, com o mesmo cache de 10 min que o painel limpa
   ao salvar: roteiro novo entra e roteiro oculto sai na hora. */
define('PO_TEST', 1);
require __DIR__ . '/../lib/po-data.php';
require __DIR__ . '/../lib/po-view.php';
require __DIR__ . '/../index.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

$html = file_get_contents(__DIR__ . '/../index.html');
ok(substr_count($html, PO_HOME_MARCA) === 1, 'index.html tem o marcador exatamente uma vez');
ok(strpos($html, PO_HOME_MARCA) > strpos($html, 'id="sobre"'), 'o marcador fica dentro do painel Sobre');

$lista = [
    ['slug' => 'turquia', 'titulo' => 'Turquia com Antália', 'dias' => 17, 'periodo' => 'De 27/10/26 à 12/11/26', 'status_viagem' => 'esgotado'],
    ['slug' => 'chile', 'titulo' => 'Chile <b>e</b> Atacama', 'dias' => 8, 'data_label' => '25/11/26 a 02/12/26'],
];
$out = po_home_render($html, $lista);
ok(strpos($out, PO_HOME_MARCA) === false, 'o marcador some da pagina servida');
ok(strpos($out, '<a href="/roteiros/turquia">Turquia com Antália</a>') !== false, 'cada viagem com link para a pagina dela');
ok(strpos($out, '17 dias, De 27/10/26 à 12/11/26') !== false, 'dias e periodo em texto');
ok(strpos($out, 'st-tag--esgotado') !== false, 'status da viagem na lista');
ok(strpos($out, 'Chile &lt;b&gt;e&lt;/b&gt; Atacama') !== false, 'titulo vem escapado (dado do painel/importador)');
ok(strlen($out) > strlen($html), 'a lista entrou');

// banco fora do ar (lista vazia): home inteira de pe, com caminho para os roteiros
$vazio = po_home_render($html, []);
ok(strpos($vazio, 'href="/roteiros"') !== false && strpos($vazio, PO_HOME_MARCA) === false, 'sem banco: aponta para /roteiros');
ok(strpos($vazio, 'id="p-title"') !== false && strpos($vazio, '</html>') !== false, 'sem banco: o resto da home intacto');

// marcador ausente (alguem apagou do index.html): serve a home como esta
ok(po_home_render('<html>sem marca</html>', $lista) === '<html>sem marca</html>', 'sem marcador nao mexe em nada');

// trava no ponto de chamada: a busca e a que filtra ativo=true
$src = file_get_contents(__DIR__ . '/../index.php');
ok(strpos($src, 'po_fetch_roteiros()') !== false, 'index.php usa po_fetch_roteiros (so ativos)');

// e o servidor entrega o PHP em / e em /index.html
$ht = str_replace("\r", "", file_get_contents(__DIR__ . "/../.htaccess")); // CRLF no Windows
ok(preg_match('/^DirectoryIndex index\.php index\.html$/m', $ht) === 1, '/ serve o index.php primeiro');
// desde as URLs limpas (22/10/2026), /index.html leva 301 para / (que e o index.php)
ok(preg_match('~RewriteCond %\{THE_REQUEST\} .*index\\\\\.html.*\n\s*RewriteRule \^ / \[R=301,L\]~', $ht) === 1, '/index.html redireciona para /');

// ordem cronologica pela data de saida (23/10/2026, pedido do cliente). O banco
// nao tem coluna de data: ela sai do texto do periodo, como a cliente escreve.
ok(po_data_saida(['periodo' => 'De 27/10/26 à 12/11/26']) === '2026-10-27', 'dd/mm/aa');
ok(po_data_saida(['periodo' => 'De 22/02/2027 a 15/03/2027']) === '2027-02-22', 'dd/mm/aaaa');
ok(po_data_saida(['data_label' => '', 'periodo' => '25/11/26 a 02/12/26']) === '2026-11-25', 'data_label vazio cai no periodo');
ok(po_data_saida(['periodo' => 'Novembro de 2026']) === null, 'sem data legivel = null');

/* SAIDAS SEMANAIS: a data esta na lista de datas do mes, nao em texto. Sem
   este caminho a funcao devolve null, quem chama cai no '9999' e o roteiro
   ordena no FIM da lista - um roteiro que sai na semana que vem anunciado
   depois de todos. Datas em 2039/2040: fixture perto do presente vira
   vermelho sozinho na virada do mes. */
$semanal = ['status_viagem' => 'semanal', 'data_label' => '', 'periodo' => '',
    'datas_texto' => [['titulo' => 'D', 'datas' => ['02/12/39', '10/12/39']]]];
ok(po_data_saida($semanal) === '2039-12-02', 'semanal le a proxima saida de datas_texto');
ok(po_data_saida(array_merge($semanal, ['datas_texto' => [], 'periodo' => '25/11/39 a 02/12/39'])) === '2039-11-25', 'semanal sem datas cai na regra de sempre');
ok(po_data_saida(array_merge($semanal, ['status_viagem' => 'em_breve'])) === null, 'status comum ignora datas_texto');
$ord = po_por_saida([
    ['titulo' => 'Vietna', 'periodo' => 'De 22/02/2027 a 15/03/2027'],
    ['titulo' => 'Sem data', 'periodo' => 'a definir'],
    ['titulo' => 'Turquia', 'periodo' => 'De 27/10/26 à 12/11/26'],
    ['titulo' => 'Oriente', 'periodo' => 'De 18/03/27 à 09/04/27'],
    ['titulo' => 'Chile', 'periodo' => '25/11/26 a 02/12/26'],
]);
ok(array_column($ord, 'titulo') === ['Turquia', 'Chile', 'Vietna', 'Oriente', 'Sem data'],
   'mais proxima primeiro; sem data vai para o fim. Veio: ' . implode(',', array_column($ord, 'titulo')));
/* A trava era pelo NOME da funcao no fonte do index.php
   ('po_por_saida(po_fetch_roteiros())'), e em 05/11/2026 a ordenacao passou
   para dentro de po_fetch_roteiros, onde serve TODAS as superficies. A trava
   ficou vermelha sem haver defeito - nome de funcao num fonte envelhece.

   Agora ela mede o COMPORTAMENTO: injeta uma lista fora de ordem e confere
   que o que chega ao painel Sobre saiu cronologico, com o sem-data no fim.
   Isso vale qualquer que seja a funcao que faca o trabalho, e vermelho aqui
   significa que a home voltou a listar fora de ordem. */
po_set_fetcher(function ($q) {
    return json_encode([
        ['slug' => 'sem-data', 'titulo' => 'Sem data', 'ativo' => true, 'periodo' => ''],
        ['slug' => 'grecia',   'titulo' => 'Grecia',   'ativo' => true, 'periodo' => 'De 18/05/27 a 03/06/27'],
        ['slug' => 'chile',    'titulo' => 'Chile',    'ativo' => true, 'periodo' => '25/11/26 a 02/12/26'],
    ]);
});
$lista = po_fetch_roteiros();
ok(array_column($lista, 'slug') === ['chile', 'grecia', 'sem-data'],
   'a lista que alimenta o painel Sobre vem cronologica, sem-data no fim. Veio: '
   . implode(',', array_column($lista, 'slug')));

/* E o vencido nao chega la. */
po_set_fetcher(function ($q) {
    return json_encode([
        ['slug' => 'passou', 'titulo' => 'Passou', 'ativo' => true, 'periodo' => 'De 01/01/26 a 10/01/26'],
        ['slug' => 'futuro', 'titulo' => 'Futuro', 'ativo' => true, 'periodo' => 'De 01/01/28 a 10/01/28'],
    ]);
});
$vivos = array_column(po_fetch_roteiros(), 'slug');
ok($vivos === ['futuro'], 'viagem que ja terminou nao chega as telas de venda. Veio: ' . implode(',', $vivos));

echo "home-index ok\n";
