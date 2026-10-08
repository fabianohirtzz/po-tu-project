<?php
/* tests/test-templates-endpoint.php — o templates.php inteiro, sem rede.

   Mesmo porte do tests/test-campanha-endpoint.php: o endpoint termina em
   exit(), entao cada caso roda num PROCESSO proprio. O transporte falso
   registra url e corpo, NUNCA os cabecalhos, que carregam o Bearer. */

$RAIZ = dirname(__DIR__);
require_once $RAIZ . '/lib/po-auth.php';
require_once $RAIZ . '/lib/wa-template.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

ini_set('error_log', sys_get_temp_dir() . '/po-test-templates.log');

const TE_BOM = 'Oi {{1}}, saiu roteiro novo para Portugal. Responda SAIR para não receber mais.';

/* ============================================================
   FILHO
============================================================ */
function te_filho($caso) {
    global $RAIZ;
    $chamadas = [];

    ini_set('error_log', sys_get_temp_dir() . '/po-test-templates.log');

    $_SERVER['REQUEST_METHOD'] = 'POST';

    $GLOBALS['SUPABASE_URL']      = 'https://teste.local';
    $GLOBALS['SUPABASE_ANON_KEY'] = 'anon-de-teste';
    $GLOBALS['WA_TOKEN']          = 'token-de-teste';
    $GLOBALS['WA_WABA_ID']        = ($caso === 'sem-conta') ? '' : '555000111';
    /* O numero da AGENCIA continua vazio em todos os cenarios menos um: e ele
       que mantem o campanha.php inerte, e o ensaio nao pode depender dele. O
       cenario 'ensaio-so-agencia' prova o outro lado - sem numero de teste, o
       ensaio cai no da agencia, que e o ensaio exigido antes do disparo pago. */
    $GLOBALS['WA_PHONE_ID']       =
        in_array($caso, ['ensaio-so-agencia', 'ensaio-ambos'], true) ? '777agencia' : '';
    $GLOBALS['WA_TEST_PHONE_ID']  = in_array($caso, ['ensaio-sem-numero', 'ensaio-so-agencia'], true)
                                  ? '' : '888teste';

    // Transporte do ENVIO (lib/wa-send.php), separado do de template.
    wa_set_transport(function ($url, $payload, $h) use (&$chamadas, $caso) {
        $chamadas[] = ['url' => $url, 'payload' => $payload];
        if ($caso === 'ensaio-recusado') {
            return ['status' => 400, 'body' => json_encode(
                ['error' => ['message' => 'Recipient not in allowed list']])];
        }
        return ['status' => 200, 'body' => json_encode(
            ['messages' => [['id' => 'wamid.TESTE123']]])];
    });

    po_auth_set_verificador(fn($u, $a, $t) => $caso !== 'sem-auth' && $t === 'tok.valido');

    wa_tpl_set_transport(function ($url, $payload, $h) use (&$chamadas, $caso) {
        $chamadas[] = ['url' => $url, 'payload' => $payload];
        if ($caso === 'meta-recusa') {
            return ['status' => 400, 'body' => json_encode(
                ['error' => ['error_user_msg' => 'Já existe um modelo com esse nome.']])];
        }
        if ($caso === 'listar') {
            return ['status' => 200, 'body' => json_encode(['data' => [
                ['name' => 'novidade', 'status' => 'APPROVED', 'category' => 'MARKETING',
                 'language' => 'pt_BR', 'components' => [['type' => 'BODY']]],
            ]])];
        }
        if ($caso === 'listar-fora') return ['status' => 500, 'body' => 'erro'];
        return ['status' => 200, 'body' => json_encode(['id' => '9988', 'status' => 'PENDING'])];
    });

    $base = ['sb_token' => 'tok.valido', 'titulo' => 'Novidade Portugal 2027',
             'categoria' => 'MARKETING', 'corpo' => TE_BOM, 'exemplo' => 'Maria'];

    switch ($caso) {
        case 'get':            $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = []; break;
        case 'sem-auth':       $_POST = ['modo' => 'criar'] + $base; break;
        case 'modo-invalido':  $_POST = ['modo' => 'apagar'] + $base; break;
        case 'modo-array':     $_POST = ['modo' => ['criar']] + $base; break;
        case 'sem-titulo':     $_POST = ['modo' => 'criar'] + $base; $_POST['titulo'] = ''; break;
        case 'duas-variaveis': $_POST = ['modo' => 'criar'] + $base;
                               $_POST['corpo'] = 'Oi {{1}}, o {{2}} saiu agora.'; break;
        case 'conferir-bom':   $_POST = ['modo' => 'conferir'] + $base; break;
        case 'conferir-ruim':  $_POST = ['modo' => 'conferir'] + $base;
                               $_POST['corpo'] = ' {{1}} '; break;
        /* Texto cujo UNICO defeito e o espaco nas pontas. Sem um caso assim,
           um trim() no endpoint passa despercebido: o texto chegaria valido
           na tela e a recusa voltaria da Meta horas depois. */
        case 'conferir-espaco':$_POST = ['modo' => 'conferir'] + $base;
                               $_POST['corpo'] = ' ' . TE_BOM . ' '; break;
        case 'listar':
        case 'listar-fora':    $_POST = ['modo' => 'listar', 'sb_token' => 'tok.valido']; break;
        case 'ensaio':
        case 'ensaio-recusado':
        case 'ensaio-so-agencia':
        case 'ensaio-ambos':
        case 'ensaio-sem-numero':
            $_POST = ['modo' => 'ensaio', 'sb_token' => 'tok.valido',
                      'template' => 'hello_world', 'destino' => '48999990001',
                      'nome_var' => 'Maria']; break;
        case 'ensaio-sem-modelo':
            $_POST = ['modo' => 'ensaio', 'sb_token' => 'tok.valido',
                      'template' => '', 'destino' => '48999990001']; break;
        case 'ensaio-fone-ruim':
            $_POST = ['modo' => 'ensaio', 'sb_token' => 'tok.valido',
                      'template' => 'hello_world', 'destino' => 'nao e telefone']; break;
        default:               $_POST = ['modo' => 'criar'] + $base; break;
    }

    register_shutdown_function(function () use (&$chamadas) {
        $c = http_response_code();
        echo "\n@@CODIGO@@" . ($c === false ? 0 : (int) $c)
           . "\n@@CHAMADAS@@" . json_encode($chamadas);
    });

    require $RAIZ . '/templates.php';
    exit;
}

if (PHP_SAPI === 'cli' && isset($argv[1])) { te_filho($argv[1]); }

function te_roda($caso) {
    $out = []; $cod = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' .
         escapeshellarg($caso) . ' 2>&1', $out, $cod);
    $txt = implode("\n", $out);
    ok(strpos($txt, '@@CODIGO@@') !== false,
       "caso $caso respondeu (saida: " . substr($txt, 0, 300) . ')');
    list($corpo, $resto)   = explode('@@CODIGO@@', $txt, 2);
    list($codigo, $chams)  = explode('@@CHAMADAS@@', $resto, 2);
    $c = (int) trim($codigo);
    /* A resposta inteira tem que ser JSON e nada mais. Um aviso do PHP
       impresso antes do corpo corrompe o JSON e o painel mostra "erro
       inesperado" sem nenhuma pista. Foi exatamente o que aconteceu aqui:
       um "Undefined array key" num campo opcional derrubava o modo listar. */
    $json = json_decode(trim($corpo), true);
    ok(is_array($json),
       "caso $caso respondeu JSON limpo, sem aviso do PHP junto (veio: "
       . substr(trim($corpo), 0, 200) . ')');
    return [
        'json'     => $json,
        'codigo'   => $c === 0 ? 200 : $c,
        'chamadas' => json_decode(trim($chams), true) ?: [],
    ];
}

/* ============================================================
   A PORTA
============================================================ */
$r = te_roda('get');
ok($r['codigo'] === 405, 'GET nao passa (deu: ' . $r['codigo'] . ')');
ok($r['chamadas'] === [], 'e nao chama a Meta');

$r = te_roda('sem-auth');
ok($r['codigo'] === 401, 'sem login do painel, 401 (deu: ' . $r['codigo'] . ')');
ok($r['chamadas'] === [], 'e nao chama a Meta');

$r = te_roda('modo-invalido');
ok($r['codigo'] === 400, 'modo fora da lista, 400 (deu: ' . $r['codigo'] . ')');
ok($r['chamadas'] === [], 'e nao chama a Meta');

/* "modo[]=criar" chegaria array e estouraria TypeError no PHP 8, que vira
   500 com a pagina em branco em vez de recusa limpa. */
$r = te_roda('modo-array');
ok($r['codigo'] === 400, 'modo como array vira recusa, nao erro de tipo (deu: ' . $r['codigo'] . ')');

/* ============================================================
   CRIAR
============================================================ */
$r = te_roda('sem-titulo');
ok($r['codigo'] === 400, 'sem titulo, 400 (deu: ' . $r['codigo'] . ')');
ok($r['chamadas'] === [], 'e nao chama a Meta');

/* A recusa que protege a campanha: duas variaveis, um exemplo. 422 porque o
   problema e o texto, e a tela mostra a lista em vez de "erro inesperado". */
$r = te_roda('duas-variaveis');
ok($r['codigo'] === 422, 'texto com variavel a mais, 422 (deu: ' . $r['codigo'] . ')');
ok(!empty($r['json']['problemas']), 'e a tela recebe a lista de problemas');
ok($r['chamadas'] === [], 'e nada foi criado na Meta');

$r = te_roda('sem-conta');
ok($r['codigo'] === 502, 'sem conta conectada, nao cria (deu: ' . $r['codigo'] . ')');
ok($r['chamadas'] === [], 'e nao chama a Meta');

$r = te_roda('criar');
ok($r['codigo'] === 200, 'o caminho feliz cria (deu: ' . $r['codigo'] . ')');
ok($r['json']['id'] === '9988', 'devolve o id');
ok($r['json']['nome'] === 'novidade_portugal_2027', 'devolve o nome normalizado');
ok(count($r['chamadas']) === 1, 'uma chamada so');
$env = json_decode($r['chamadas'][0]['payload'], true);
ok($env['components'][0]['example']['body_text'] === [['Maria']], 'o exemplo sobe junto');

$r = te_roda('meta-recusa');
ok($r['codigo'] === 502, 'recusa da Meta vira 502 (deu: ' . $r['codigo'] . ')');
ok(strpos($r['json']['error'], 'Já existe') !== false,
   'e a mensagem dela chega na tela (veio: ' . ($r['json']['error'] ?? '') . ')');

/* ============================================================
   CONFERIR: o julgamento sem gastar chamada
============================================================ */
$r = te_roda('conferir-bom');
ok($r['codigo'] === 200, 'conferir responde (deu: ' . $r['codigo'] . ')');
ok($r['json']['problemas'] === [], 'texto bom nao tem problema');
ok($r['json']['nome'] === 'novidade_portugal_2027', 'conferir ja mostra o nome que vai valer');
ok($r['chamadas'] === [], 'conferir NUNCA chama a Meta');

$r = te_roda('conferir-ruim');
ok($r['codigo'] === 200, 'conferir com texto ruim ainda responde 200');
ok(count($r['json']['problemas']) >= 2,
   'aponta mais de um problema de uma vez (veio: ' . json_encode($r['json']['problemas']) . ')');
ok($r['chamadas'] === [], 'e segue sem chamar a Meta');

/* O endpoint NAO apara o corpo: espaco nas pontas e um defeito que a Meta
   recusa, entao aparar aqui esconderia o problema da tela. */
$r = te_roda('conferir-espaco');
ok($r['json']['problemas'] !== [],
   'texto bom com espaco nas pontas continua sendo problema (veio: '
   . json_encode($r['json']['problemas']) . ')');

/* ============================================================
   LISTAR
============================================================ */
$r = te_roda('listar');
ok($r['codigo'] === 200, 'listar responde (deu: ' . $r['codigo'] . ')');
ok($r['json']['templates'][0]['status'] === 'APPROVED', 'o status chega na tela');
ok(!isset($r['json']['templates'][0]['components']),
   'a listagem nao devolve o corpo do modelo, que a tela nao usa');

/* Erro de leitura nao pode virar lista vazia: a tela diria "nenhum modelo"
   com a Meta fora do ar, e alguem criaria um duplicado que fica para sempre
   (a Meta nao deixa renomear nem apagar de imediato). */
$r = te_roda('listar-fora');
ok($r['codigo'] === 502, 'Meta fora na listagem vira 502, nao lista vazia (deu: ' . $r['codigo'] . ')');

/* ============================================================
   ENSAIO: uma mensagem, um numero, longe da base
============================================================ */
$r = te_roda('ensaio');
ok($r['codigo'] === 200, 'o ensaio envia (deu: ' . $r['codigo'] . ')');
ok($r['json']['wamid'] === 'wamid.TESTE123', 'devolve o wamid, que prova a entrega');
ok(count($r['chamadas']) === 1, 'uma mensagem so');
/* A assercao que protege a base: o ensaio sai pelo numero de TESTE. Se ele
   dependesse do WA_PHONE_ID, alguem preencheria aquela chave para fazer o
   ensaio funcionar - e aquela chave vazia e o que mantem o campanha.php
   inerte. Campanha disparada pelo numero de teste falha para a base inteira
   de uma vez, e falha e terminal. */
ok(strpos($r['chamadas'][0]['url'], '/888teste/messages') !== false,
   'o ensaio sai pelo numero de TESTE (url: ' . $r['chamadas'][0]['url'] . ')');
ok(strpos($r['chamadas'][0]['url'], '777agencia') === false,
   'e nunca pelo numero da agencia');
$env = json_decode($r['chamadas'][0]['payload'], true);
ok($env['type'] === 'template', 'o ensaio manda um modelo, que e o que a campanha manda');
ok($env['template']['name'] === 'hello_world', 'o modelo escolhido');
ok($env['template']['components'][0]['parameters'][0]['text'] === 'Maria',
   'com UM parametro, igual a campanha');

/* Depois da convivencia nao havera numero de teste, e o ensaio com um
   destinatario e justamente o que o projeto exige antes do primeiro disparo
   pago. Sem este caminho ele deixaria de existir na hora que mais importa. */
$r = te_roda('ensaio-so-agencia');
ok($r['codigo'] === 200, 'sem numero de teste, o ensaio usa o da agencia');
ok(strpos($r['chamadas'][0]['url'], '/777agencia/messages') !== false,
   'e sai por ele (url: ' . $r['chamadas'][0]['url'] . ')');

/* O cenario que decide a ORDEM, e o unico em que ela aparece: com os dois
   numeros configurados, o ensaio tem que sair pelo da AGENCIA. E o estado do
   servidor depois da convivencia, com o WA_TEST_PHONE_ID ainda no arquivo. */
$r = te_roda('ensaio-ambos');
ok($r['codigo'] === 200, 'com os dois numeros, o ensaio envia');
ok(strpos($r['chamadas'][0]['url'], '/777agencia/messages') !== false,
   'e sai pelo numero da AGENCIA, nao pelo de teste (url: ' . $r['chamadas'][0]['url'] . ')');

$r = te_roda('ensaio-sem-numero');
ok($r['codigo'] === 409, 'sem numero nenhum, 409 (deu: ' . $r['codigo'] . ')');
ok($r['chamadas'] === [], 'e nada e enviado');

$r = te_roda('ensaio-sem-modelo');
ok($r['codigo'] === 400, 'ensaio sem modelo, 400 (deu: ' . $r['codigo'] . ')');
ok($r['chamadas'] === [], 'e nada e enviado');

/* Telefone que nao normaliza viraria "to": null no payload e a Graph
   aceitaria a chamada assim mesmo. */
$r = te_roda('ensaio-fone-ruim');
ok($r['codigo'] === 400, 'telefone invalido, 400 (deu: ' . $r['codigo'] . ')');
ok($r['chamadas'] === [], 'e nada e enviado');

$r = te_roda('ensaio-recusado');
ok($r['codigo'] === 502, 'recusa da Meta no ensaio vira 502 (deu: ' . $r['codigo'] . ')');

/* ============================================================
   ASSERCAO DE FONTE
============================================================ */
$src = file_get_contents(__DIR__ . '/../templates.php');
/* O conferir e o criar precisam julgar IGUAL. Uma tela que valide diferente
   do envio aprova o que a Meta recusa, e o contrario. */
ok(strpos($src, 'wa_tpl_problemas(') !== false,
   'o conferir usa a mesma funcao de julgamento do criar');
ok(substr_count($src, 'wa_tpl_cria(') === 1,
   'existe um unico caminho de criacao');
ok(strpos($src, 'po_auth_ok(') !== false, 'o login do painel e exigido');
$p_auth = strpos($src, 'po_auth_ok(');
$p_modo = strpos($src, "\$modo = tpost('modo')");
ok($p_auth !== false && $p_modo !== false && $p_auth < $p_modo,
   'o login e conferido antes de olhar o que foi pedido');
ok(strpos($src, 'po_config()') === false,
   'o endpoint le segredo por wa_config(), nunca po_config()');

/* O ensaio manda para UM numero digitado na hora. Carregar a camada de banco
   aqui abriria caminho para o ensaio varrer a base, que e exatamente o que
   este modo existe para evitar. */
ok(preg_match('/require(_once)?[^;]*wa-db/', $src) !== 1,
   'templates.php NAO carrega a camada de banco');
ok(strpos($src, 'po_leads') === false, 'e nao conhece a tabela de leads');

/* O numero de teste existe como QUEDA, nunca como preferencia: ele so e lido
   depois de o da agencia ter vindo vazio. O cenario 'ensaio-ambos' cobre o
   comportamento; esta assercao cobre a forma, porque inverter as duas linhas
   nao quebra nenhum outro caso. */
ok(preg_match("/\\\$pid = trim\\(\\(string\\) \\(\\\$cfg\\['WA_PHONE_ID'\\]/", $src) === 1,
   'o ensaio le PRIMEIRO o numero da agencia');
ok(preg_match("/if \\(\\\$pid === ''\\) \\\$pid = trim\\(\\(string\\) \\(\\\$cfg\\['WA_TEST_PHONE_ID'\\]/", $src) === 1,
   'e o de teste so como queda, quando o da agencia veio vazio');

echo "test-templates-endpoint OK\n";
