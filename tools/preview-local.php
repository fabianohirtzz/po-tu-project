<?php
/* ============================================================
   Previa LOCAL do site e do painel, com o servidor embutido do PHP.

   Uso: dois cliques em tools\preview-local.cmd, ou, na raiz do projeto,
     php -S 127.0.0.1:8000 -t . tools/preview-local.php
   e abrir http://127.0.0.1:8000

   O que ele faz:
   - imita as URLs limpas do .htaccess (/contato, /roteiros/<slug>,
     /perguntas-frequentes...), que o php -S nao le;
   - FINGE o /enviar.php e o /clique.php: o formulario responde "Recebemos
     seus dados" e NENHUM lead e gravado. O banco e um so (o de producao),
     entao enviar o formulario de verdade aqui criaria um lead real;
   - roda as paginas PHP de verdade (index.php, roteiro.php, roteiros.php,
     faq.php, nossa-historia.php), lendo o banco publico como qualquer
     visitante.

   O que ele NAO isola: o PAINEL (/painel) entra com o login de verdade e
   grava no banco de verdade ao Salvar. Olhar e seguro; salvar e o mesmo que
   salvar no painel do ar. Sem o config.local.php aqui, nenhum endpoint do
   servidor tem a service_role, entao o que o PHP faria por conta propria
   (venda para a Meta, nome de anuncio, transmissao) responde "nao
   configurado".

   O rastreio de anuncio (assets/js/rastreio.js) fica DESLIGADO aqui de
   proposito: ele so mede no dominio de producao. No console (F12),
   poRastreio.ligado mostra producao: false.

   So roda no servidor embutido do PHP: em qualquer outro (o Apache do ar),
   sai na primeira linha.
============================================================ */
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }

$raiz = dirname(__DIR__);
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

/* Registro na janela do servidor: a pagina aberta (com o endereco inteiro,
   para ver a campanha que chegou) e o que o formulario mandou. E o que
   permite saber em que passo um teste parou. Arquivo de asset nao entra. */
if (!preg_match('#\.(css|js|png|jpe?g|webp|svg|gif|ico|mp4|woff2?|json|map|pdf)$#i', $path)) {
    error_log('[previa] ' . ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? ''));
}
if ($path === '/enviar.php') {
    $c = [];
    foreach (['nome', 'origem', 'utm_source', 'utm_medium', 'utm_campaign', 'gclid', 'fbclid', 'landing_page'] as $k) {
        $c[] = $k . '=' . substr((string) ($_POST[$k] ?? ''), 0, 120);
    }
    $c[] = 'campo_anti_robo=' . (trim((string) ($_POST['website'] ?? '')) === '' ? 'vazio' : 'PREENCHIDO');
    error_log('[previa] formulario chegou: ' . implode(' | ', $c));
}

/* Os dois pontos que gravariam no banco de producao: fingidos.

   Modo GRAVAR (servidor iniciado com PO_PREVIA_GRAVA=1): o /enviar.php
   responde que falhou, e o formulario cai na gravacao de reserva dele (o
   insert anonimo no Supabase). O lead entra no banco REAL, sem o e-mail de
   aviso, e aparece no painel com a origem que o site guardou. Serve para ver
   o caminho inteiro; o lead de teste tem que ser apagado depois. So no
   /contato: a pagina de roteiro nao carrega o supabase-js, entao la nao ha
   reserva e o formulario mostra erro. */
if ($path === '/enviar.php') {
    $grava = getenv('PO_PREVIA_GRAVA') === '1';
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($grava
        ? ['ok' => false, 'previa' => 'modo gravar: a reserva do navegador grava']
        : ['ok' => true, 'previa' => 'nada foi gravado']);
    return true;
}
if ($path === '/clique.php') {
    http_response_code(204);
    return true;
}

// Pasta sem a barra no fim: os caminhos relativos dela (o painel pede
// "app.js?v=...") resolveriam contra a raiz e dariam 404.
if ($path !== '/' && substr($path, -1) !== '/' && is_dir($raiz . $path)) {
    header('Location: ' . $path . '/', true, 301);
    return true;
}

$paginas = [
    '/'                        => 'index.php',
    '/index.html'              => 'index.php',
    '/contato'                 => 'contato.html',
    '/link'                    => 'link.html',
    '/politica-de-privacidade' => 'politica-de-privacidade.html',
    '/nossa-historia'          => 'nossa-historia.php',
    '/perguntas-frequentes'    => 'faq.php',
    '/roteiros'                => 'roteiros.php',
    '/sitemap.xml'             => 'sitemap.php',
    '/llms.txt'                => 'llms.php',
];
$chave = rtrim($path, '/');
$alvo = $paginas[$chave === '' ? '/' : $chave] ?? null;
if ($alvo === null && preg_match('#^/roteiros/([a-z0-9-]+)/?$#', $path, $m)) {
    $alvo = 'roteiro.php';
    $_GET['slug'] = $m[1];
}
if ($alvo === null) return false;   // arquivo do disco (assets, painel, imagens) como esta

if (substr($alvo, -5) === '.html') {
    header('Content-Type: text/html; charset=utf-8');
    readfile($raiz . '/' . $alvo);
    return true;
}
chdir($raiz);
require $raiz . '/' . $alvo;
return true;
