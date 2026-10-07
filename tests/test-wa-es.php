<?php
/* tests/test-wa-es.php — a pagina que liga o numero em convivencia.

   O que esta travado aqui nao e aparencia de tela. E uma palavra:
   featureType = whatsapp_business_app_onboarding. Sem ela o popup da Meta
   abre o fluxo NORMAL, que tira o numero do celular da atendente, e isso
   nao se desfaz. Nao ha erro em tela nenhum avisando - o fluxo simplesmente
   parece outro.

   Mesmo porte do tests/test-campanha-endpoint.php: a pagina termina em
   exit(), entao cada cenario roda num PROCESSO proprio. */

$RAIZ = dirname(__DIR__);
require_once $RAIZ . '/lib/wa-es.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

/* O error_log do wa_es_autorizado iria para a stderr e se misturaria as
   mensagens de falha, fazendo um teste verde parecer vermelho. */
ini_set('error_log', sys_get_temp_dir() . '/po-test-wa-es.log');

const ES_CHAVE = 'chave-de-teste-com-32-caracteres';   // 32
const ES_CURTA = 'curta-demais';                       // 12, abaixo do piso

/* ============================================================
   FILHO: monta a requisicao e roda a pagina.
============================================================ */
function es_filho($caso) {
    global $RAIZ;

    register_shutdown_function(function () {
        $c = http_response_code();
        echo "\n@@CODIGO@@" . ($c === false ? 0 : (int) $c);
    });

    ini_set('error_log', sys_get_temp_dir() . '/po-test-wa-es.log');

    $GLOBALS['WA_ES_KEY']       = ($caso === 'chave-curta') ? ES_CURTA : ES_CHAVE;
    $GLOBALS['WA_ES_APP_ID']    = ($caso === 'sem-config') ? '' : '987654321098765';
    $GLOBALS['WA_ES_CONFIG_ID'] = ($caso === 'sem-config') ? '' : '123456789012345';

    switch ($caso) {
        case 'sem-chave':    unset($_GET['k']);                                   break;
        case 'chave-errada': $_GET['k'] = 'nao-e-a-chave-certa-mas-tem-tamanho';  break;
        case 'chave-curta':  $_GET['k'] = ES_CURTA;                               break;
        default:             $_GET['k'] = ES_CHAVE;                               break;
    }

    require $RAIZ . '/conectar-numero.php';
    exit;
}

if (PHP_SAPI === 'cli' && isset($argv[1])) { es_filho($argv[1]); }

function es_roda($caso) {
    $out = []; $cod = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' .
         escapeshellarg($caso) . ' 2>&1', $out, $cod);
    $txt = implode("\n", $out);
    ok(strpos($txt, '@@CODIGO@@') !== false,
       "caso $caso respondeu (saida: " . substr($txt, 0, 300) . ')');
    list($corpo, $codigo) = explode('@@CODIGO@@', $txt, 2);
    $c = (int) trim($codigo);
    return ['html' => $corpo, 'codigo' => $c === 0 ? 200 : $c];
}

/* ============================================================
   A PORTA
============================================================ */
$r = es_roda('sem-chave');
ok($r['codigo'] === 403, 'sem chave na url, 403 (deu: ' . $r['codigo'] . ')');
ok(trim($r['html']) === '',
   'e nem uma linha de html sai (veio: ' . substr($r['html'], 0, 200) . ')');

$r = es_roda('chave-errada');
ok($r['codigo'] === 403, 'chave errada, 403 (deu: ' . $r['codigo'] . ')');

/* O buraco que o projeto ja pagou duas vezes: hash_equals de dois vazios e
   true, e aqui a chave AINDA nao existe no servidor enquanto ninguem a
   cadastrar. Chave configurada curta demais nunca autoriza, mesmo quando
   quem chama acerta os caracteres. */
$r = es_roda('chave-curta');
ok($r['codigo'] === 403,
   'chave configurada curta demais nao autoriza nem com o valor certo (deu: ' . $r['codigo'] . ')');

/* ============================================================
   A PAGINA
============================================================ */
$r = es_roda('sem-config');
ok($r['codigo'] === 200, 'com a chave certa a pagina abre (deu: ' . $r['codigo'] . ')');
ok(strpos($r['html'], 'Falta configurar') !== false,
   'sem APP_ID e CONFIG_ID a pagina diz o que falta em vez de abrir fluxo que vai falhar');
ok(preg_match('/<button[^>]*\bdisabled\b/', $r['html']) === 1,
   'e o botao nasce desabilitado');

$r = es_roda('ok');
ok($r['codigo'] === 200, 'configurada, a pagina abre (deu: ' . $r['codigo'] . ')');
ok(preg_match('/<button[^>]*\bdisabled\b/', $r['html']) === 0,
   'o botao fica clicavel quando esta tudo configurado');
ok(strpos($r['html'], '123456789012345') !== false, 'o config_id chega ao navegador');
ok(strpos($r['html'], '987654321098765') !== false, 'o app_id chega ao navegador');
/* A palavra que separa convivencia de migracao, conferida no HTML SERVIDO,
   nao so na funcao pura: e o que o navegador manda para a Meta. */
ok(strpos($r['html'], 'whatsapp_business_app_onboarding') !== false,
   'o html servido leva featureType=whatsapp_business_app_onboarding (sem isto o numero sai do celular)');
ok(strpos($r['html'], '"setup":{}') !== false,
   'setup vai como objeto vazio, nao como lista');

/* ============================================================
   AS FUNCOES PURAS
============================================================ */
ok(wa_es_autorizado('', '') === false, 'chave vazia nunca autoriza');
ok(wa_es_autorizado(null, null) === false, 'chave nula nunca autoriza');
ok(wa_es_autorizado(ES_CURTA, ES_CURTA) === false, 'chave abaixo do piso nunca autoriza');
ok(strlen(ES_CURTA) < WA_ES_KEY_MIN, 'a chave curta do teste esta mesmo abaixo do piso');
ok(wa_es_autorizado(ES_CHAVE, ES_CHAVE) === true, 'chave certa autoriza');
ok(wa_es_autorizado(ES_CHAVE, ES_CHAVE . 'x') === false, 'chave com sufixo nao autoriza');

$ex = wa_es_extras();
ok(($ex['featureType'] ?? '') === 'whatsapp_business_app_onboarding',
   'o extras pede o fluxo de convivencia');
/* 'coexistence' foi invalidado pela Meta em 29/05/2025. */
ok(!in_array('coexistence', $ex, true), 'e nao usa o valor antigo coexistence');
/* sessionInfoVersion pertence as versoes 2 e 3, que a Meta desliga em
   15/10/2026. Mandar isso na v4 e pedir um fluxo que vai deixar de existir. */
ok(!array_key_exists('sessionInfoVersion', $ex),
   'o extras da v4 nao leva sessionInfoVersion');

ok(wa_es_faltando(['WA_ES_APP_ID'=>'1','WA_ES_CONFIG_ID'=>'2','WA_ES_KEY'=>ES_CHAVE]) === [],
   'configuracao completa nao falta nada');
ok(array_key_exists('WA_ES_KEY',
     wa_es_faltando(['WA_ES_APP_ID'=>'1','WA_ES_CONFIG_ID'=>'2','WA_ES_KEY'=>ES_CURTA])),
   'chave curta conta como faltando');
ok(array_key_exists('WA_ES_APP_ID',
     wa_es_faltando(['WA_ES_APP_ID'=>'   ','WA_ES_CONFIG_ID'=>'2','WA_ES_KEY'=>ES_CHAVE])),
   'app id so com espacos conta como faltando');

/* O fecha-script dentro de um valor fecharia o bloco e o resto da pagina
   viraria html solto. Ja aconteceu neste projeto, no JSON-LD dos roteiros. */
ok(strpos(wa_es_js('</scr' . 'ipt>'), '</scr' . 'ipt>') === false,
   'wa_es_js escapa o fecha-script');

/* ============================================================
   ASSERCAO DE FONTE
   A regra permanente do projeto: a trava fica no PONTO DE CHAMADA, nao so
   na funcao pura.
============================================================ */
$src = file_get_contents(__DIR__ . '/../conectar-numero.php');

/* O extras tem que VIR de wa_es_extras(). Escrito a mao no JavaScript, as
   assercoes puras acima continuariam verdes enquanto a pagina manda outra
   coisa para a Meta. */
ok(strpos($src, 'wa_es_extras()') !== false,
   'a pagina monta o extras com wa_es_extras(), nao escrito a mao no JS');

/* A porta antes do conteudo: a conferencia da chave precisa acontecer ANTES
   de qualquer valor de configuracao ser impresso. */
$p_porta = strpos($src, 'wa_es_autorizado(');
$p_valor = strpos($src, 'WA_ES_APP_ID');
ok($p_porta !== false && $p_valor !== false && $p_porta < $p_valor,
   'a chave e conferida antes de imprimir qualquer configuracao');
ok(strpos($src, 'http_response_code(403)') !== false, 'a recusa e 403');

/* Sem o ouvinte, o fluxo conecta e o phone_number_id morre com a janela:
   ele NAO vem no retorno do FB.login. */
ok(strpos($src, 'WA_EMBEDDED_SIGNUP') !== false,
   'a pagina escuta o evento que traz o phone_number_id');
ok(strpos($src, 'phone_number_id') !== false, 'e colhe o phone_number_id');

/* O exemplo da propria Meta compara so o fim do texto da origem, e um
   dominio como naofacebook.com satisfaz aquela comparacao. A pagina confere
   com o ponto antes do dominio. */
ok(strpos($src, 'endsWith(') === false,
   'a origem da mensagem nao e conferida por sufixo cru');
ok(strpos($src, 'facebook\\.com$') !== false,
   'a origem e conferida por padrao ancorado no dominio da Meta');

ok(strpos($src, 'override_default_response_type') !== false,
   'o fluxo pede codigo em vez do token padrao do login');
ok(strpos($src, 'sessionInfoVersion') === false,
   'a pagina nao carrega sessionInfoVersion (v2 e v3 morrem em 15/10/2026)');
/* po_config() e carregada no render publico das paginas de roteiro e o
   cabecalho dela proibe segredo ali. */
ok(strpos($src, 'po_config()') === false,
   'a pagina le segredo por wa_config(), nunca po_config()');
ok(strpos($src, 'noindex') !== false, 'a pagina fica fora de buscador');

echo "test-wa-es OK\n";
