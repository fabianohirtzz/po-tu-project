<?php
/* Prova social (22/10/2026): avaliacoes do Google e posts do Instagram, montados
   no servidor na Nossa Historia e no painel "Sobre" da home.

   O que este teste trava:
   - o texto da avaliacao e do painel/importador: sempre escapado;
   - so URL do Instagram e so capa guardada no nosso /images/insta/ viram <a>/<img>
     (capa_url e url vem do banco, nao podem levar a outro site);
   - NENHUM AggregateRating: nota que a propria empresa escolhe e hospeda nao pode
     virar estrela na busca (diretriz do Google, risco de acao manual);
   - as paginas recebem as secoes pelos marcadores, e sem dado nada quebra. */
define('PO_TEST', 1);
require __DIR__ . '/../lib/po-data.php';
require __DIR__ . '/../lib/po-view.php';
require __DIR__ . '/../lib/po-social.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

$avs = [
    ['nome' => 'Humberto V.', 'texto' => 'A viagem à <b>Índia</b> foi espetacular.', 'nota' => 5, 'data_label' => 'outubro de 2026'],
    ['nome' => 'Solange A.', 'texto' => 'Sem correria.', 'nota' => 5, 'data_label' => null],
    ['nome' => 'Joana H.', 'texto' => 'Super recomendo!', 'nota' => 5, 'data_label' => 'junho de 2026'],
    ['nome' => 'Sandra S.', 'texto' => 'Recomendo.', 'nota' => 5, 'data_label' => 'maio de 2026'],
];

// --- Nossa Historia: secao de avaliacoes
$s = po_avaliacoes_secao_html($avs);
ok(substr_count($s, '<blockquote') === 4, 'uma <blockquote> por avaliacao');
ok(strpos($s, 'A viagem à &lt;b&gt;Índia&lt;/b&gt;') !== false, 'texto escapado');
ok(strpos($s, 'Humberto V.') !== false && strpos($s, 'outubro de 2026') !== false, 'autor e data visiveis');
ok(strpos($s, 'href="' . PO_GOOGLE_PERFIL . '"') !== false, 'link para o perfil no Google');
ok(strpos($s, 'aria-label="5 de 5 estrelas"') !== false, 'estrelas com texto para leitor de tela');
ok(stripos($s, 'AggregateRating') === false && stripos($s, 'itemprop') === false, 'sem nota agregada nem microdata de review');
ok(po_avaliacoes_secao_html([]) === '', 'sem avaliacao: a secao nem aparece');
// o "Google" de cada card e o logo, com texto para leitor de tela (pedido do cliente)
ok(substr_count($s, 'class="rev__g" viewBox="0 0 24 24" role="img" aria-label="Avaliação no Google"') === 4, 'logo do Google em cada card');
ok(strpos($s, '· Google') === false && strpos($s, '<span>Google</span>') === false, 'sem a palavra Google no rodape do card');

// --- painel Sobre da home: 3 avaliacoes e o selo
$b = po_avaliacoes_sobre_html($avs);
ok(substr_count($b, '<blockquote') === 3, 'o Sobre mostra so 3');
ok(strpos($b, 'Sandra S.') === false, 'as 3 primeiras, na ordem do painel');
ok(strpos($b, '5 estrelas no Google') !== false && strpos($b, PO_GOOGLE_PERFIL) !== false, 'selo e link');
ok(po_avaliacoes_sobre_html([]) === '', 'sem avaliacao: nada');

// --- Instagram (videos enviados pelo painel, padrao Casa Angelina)
$V = 'https://pereiraoliveiraturismo.com.br/videos/';
$P = 'https://euzmbswywwhmicjlszqw.supabase.co/storage/v1/object/public/po-imagens/instagram/';
$ig = [];
for ($i = 1; $i <= 14; $i++) $ig[] = ['video_url' => $V . "ig-0000000$i-feed-abcd1234.mp4", 'poster_url' => "/images/insta/ig-$i.jpg", 'url' => "https://www.instagram.com/reel/C$i/"];
$ig[0]['poster_url'] = $P . '1700-x.jpg';                        // capa no storage do projeto: vale
$ig[1]['url'] = null;                                             // sem link do post: vai pro perfil
$ig[] = ['video_url' => 'https://evil.example/x.mp4', 'poster_url' => '/images/insta/ok.jpg', 'url' => null];
$ig[] = ['video_url' => $V . 'ig-1-feed-1.mp4', 'poster_url' => 'https://evil.example/y.jpg', 'url' => null];
$ig[] = ['video_url' => $V . 'ig-1-feed-1.mp4" onmouseover="alert(1)', 'poster_url' => '/images/insta/z.jpg', 'url' => null];
array_unshift($ig, $ig[14], $ig[15], $ig[16]);                    // os ruins primeiro: nao podem ocupar vaga
$g = po_instagram_secao_html($ig);
ok(substr_count($g, 'class="ig__item"') === 12, 'no maximo 12 videos');
ok(strpos($g, 'evil.example') === false && strpos($g, 'onmouseover') === false, 'video ou capa de fora nao entra');
ok(strpos($g, 'data-video="' . $V . 'ig-00000001-feed-abcd1234.mp4"') !== false, 'o video vai no data-video (so baixa no clique)');
$grade = substr($g, 0, strpos($g, 'id="ig-lb"'));
ok(strpos($grade, '<video') === false, 'nenhum <video> na grade: nada baixa antes da interacao');
ok(preg_match('~<video class="ig-lb__video"[^>]*src=~', $g) === 0, 'o visualizador nasce sem video (src so no clique)');
ok(strpos($g, '<img src="' . $P . '1700-x.jpg"') !== false, 'capa do storage do projeto');
ok(strpos($g, 'data-post="' . PO_INSTAGRAM . '"') !== false, 'sem link do post: o Ver no Instagram leva ao perfil');
ok(substr_count($g, 'id="ig-lb"') === 1, 'um visualizador so');
ok(strpos($g, 'href="' . PO_INSTAGRAM . '"') !== false && strpos($g, 'Seguir no Instagram') !== false, 'botao seguir');
ok(po_instagram_secao_html([]) === '', 'sem video: a secao nem aparece');

// --- marcadores nas paginas e o render
$hist = file_get_contents(__DIR__ . '/../nossa-historia.html');
ok(substr_count($hist, PO_MARCA_AVALIACOES) === 1 && substr_count($hist, PO_MARCA_INSTAGRAM) === 1, 'Nossa Historia tem os dois marcadores');
$home = file_get_contents(__DIR__ . '/../index.html');
ok(substr_count($home, PO_MARCA_AVALIACOES_SOBRE) === 1, 'home tem o marcador do Sobre');
ok(strpos($home, PO_MARCA_AVALIACOES_SOBRE) > strpos($home, 'id="sobre"'), 'marcador dentro do painel Sobre');
$r = po_social_render($hist, [PO_MARCA_AVALIACOES => 'AAA', PO_MARCA_INSTAGRAM => 'III']);
ok(strpos($r, 'AAA') !== false && strpos($r, 'III') !== false && strpos($r, '<!--PO:') === false, 'render troca os marcadores');

// --- leitura do banco: so ativos, Instagram mais recente primeiro
$urls = [];
po_set_fetcher(function ($url) use (&$urls) { $urls[] = $url; return '[]'; });
po_fetch_avaliacoes(); po_fetch_instagram();
ok(strpos($urls[0], '/po_avaliacoes?') !== false && strpos($urls[0], 'ativo=eq.true') !== false, 'avaliacoes: so ativas');
ok(strpos($urls[1], '/po_instagram?') !== false && strpos($urls[1], 'ativo=eq.true') !== false
   && strpos($urls[1], 'video_url=not.is.null') !== false && strpos($urls[1], 'order=ordem.asc,created_at.desc') !== false
   && strpos($urls[1], 'limit=12') !== false, 'instagram: ativos com video, na ordem do painel, 12');
po_set_fetcher(null);

// --- a pagina e o servidor
$ht = str_replace("\r", "", file_get_contents(__DIR__ . '/../.htaccess'));
ok(preg_match('~^RewriteRule \^nossa-historia\$\s+/nossa-historia\.php \[L\]~m', $ht) === 1, '/nossa-historia passa pelo PHP');
ok(strpos(file_get_contents(__DIR__ . '/../index.php'), 'po_fetch_avaliacoes()') !== false, 'home busca as avaliacoes');

echo "social ok\n";
