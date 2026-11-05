<?php
/* Redirecionamentos do site WordPress antigo (.htaccess), conferidos contra as
   URLs que o Search Console listou como 404 / "rastreada, nao indexada" em
   22/10/2026. Cada URL antiga que tinha historico no Google precisa terminar
   num destino que existe, nunca num 404.

   O teste le as linhas RedirectMatch e aplica as regex como o Apache faria
   (primeira que casa vence). */
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

$regras = [];
foreach (file(__DIR__ . '/../.htaccess') as $l) {
    if (preg_match('~^RedirectMatch\s+(\d{3})\s+(\S+)(?:\s+(\S+))?~', trim($l), $m)) {
        $regras[] = [(int) $m[1], $m[2], $m[3] ?? null];
    }
}
function destino($regras, $path) {
    foreach ($regras as [$cod, $re, $dest]) {
        if (preg_match('~' . $re . '~', $path)) return [$cod, $dest];
    }
    return [null, null];
}

// roteiros do WordPress que o Search Console mostrou em 404
foreach (['/grecia-terra-mar/', '/mercados-de-natal-com-cruzeiro-pelo-rio-reno-e-concerto-de-andre/', '/china-imperial/',
          '/encantos-da-italia/', '/corsega-joias-da-franca-e-italia/', '/marrocos-lisboa/', '/tunisia-e-malta/',
          '/escandinavia/', '/vietna-completo-e-singapura/', '/escandinavia'] as $p) {
    ok(destino($regras, $p) === [301, '/roteiros'], "$p vai para /roteiros");
}
// apontavam para roteiros que sairam do ar (404): agora vao para a lista
ok(destino($regras, '/india-nepal/') === [301, '/roteiros'], '/india-nepal nao termina em roteiro oculto');
ok(destino($regras, '/tesouros-asiaticos.html') === [301, '/roteiros'], '/tesouros-asiaticos.html nao termina em roteiro oculto');
// os que seguem valendo
ok(destino($regras, '/turquia/') === [301, '/roteiros/turquia'], '/turquia segue no roteiro');
// versao antiga do Tesouros do Oriente (a capa no banco ainda e japaodoha-capa.jpg)
ok(destino($regras, '/japao-e-doha/') === [301, '/roteiros/coreia-do-sul-japao-dubai'], '/japao-e-doha vai para o roteiro que o substituiu');
ok(destino($regras, '/wp-content/themes/hello-elementor/*')[0] === 410, 'tema do WordPress responde 410');

// restos tecnicos do WordPress: 410, removido para sempre
foreach (['/feed/', '/feed', '/author/poturismo/feed/', '/wp-includes/js/wp-emoji-release.min.js', '/wp-content/uploads/x.jpg',
          '/wp-json/wp/v2/posts', '/comments/feed/', '/xmlrpc.php', '/wp-login.php'] as $p) {
    ok(destino($regras, $p)[0] === 410, "$p responde 410");
}

// e nada do site atual pode cair numa dessas regras
foreach (['/', '/roteiros', '/roteiros/turquia', '/roteiros/escandinavia', '/perguntas-frequentes', '/nossa-historia.html',
          '/contato.html', '/link', '/llms.txt', '/sitemap.xml', '/assets/css/style.css', '/painel/', '/videos/home.mp4'] as $p) {
    ok(destino($regras, $p) === [null, null], "$p do site atual nao e redirecionado");
}

echo "htaccess-redirects ok\n";
