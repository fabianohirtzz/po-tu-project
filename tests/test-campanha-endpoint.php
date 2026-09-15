<?php
/* tests/test-campanha-endpoint.php — o campanha.php inteiro, sem rede.

   Este e o endpoint que gasta dinheiro da cliente: cerca de R$ 0,31 por
   destinatario. O que precisa estar travado aqui nao e o feliz caminho, e o
   que impede uma cobranca que ninguem pediu - login, texto sem a saida,
   campanha em duplicata e publico vazio.

   Mesmo porte do tests/test-contatos-importar.php: o endpoint termina em
   exit(), entao cada caso roda num PROCESSO proprio. O transporte falso
   registra metodo, url e corpo - NUNCA os cabecalhos, que carregam a
   service_role. */

$RAIZ = dirname(__DIR__);
require_once $RAIZ . '/lib/wa-db.php';      // carrega wa-config -> po-data -> config.local
require_once $RAIZ . '/lib/po-auth.php';
require_once $RAIZ . '/lib/wa-camp-fila.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

const CE_CORPO = 'Roteiro novo para Portugal. Responda SAIR para não receber mais.';

/* Tres leads: um entra (cliente revisado com celular), um saiu (opt_out) e um
   e so fixo. Assim o resumo tem mais de um balde e a previa nao passa por
   coincidencia de lista de um elemento so. */
function ce_base() {
    return [
        ['id'=>'L1', 'nome'=>'Um',   'telefone'=>'+5548999990001', 'wa_id'=>null,
         'cliente'=>true, 'revisado'=>true, 'opt_out_at'=>null, 'payload_import'=>null],
        ['id'=>'L2', 'nome'=>'Dois', 'telefone'=>'+5548999990002', 'wa_id'=>null,
         'cliente'=>true, 'revisado'=>true, 'opt_out_at'=>'2026-09-01T00:00:00Z', 'payload_import'=>null],
        ['id'=>'L3', 'nome'=>'Tres', 'telefone'=>'+554832221111',  'wa_id'=>null,
         'cliente'=>true, 'revisado'=>true, 'opt_out_at'=>null, 'payload_import'=>null],
    ];
}

/* ============================================================
   FILHO: monta a requisicao e roda o endpoint.
============================================================ */
function ce_filho($caso) {
    global $RAIZ;
    $chamadas = [];

    // O error_log do wa_db_* iria para a stderr e se misturaria ao JSON.
    ini_set('error_log', sys_get_temp_dir() . '/po-test-campanha.log');

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['sb_token' => 'tok.valido', 'modo' => 'previa'];

    // Segredos de teste: nem a chave real nem a URL real desta maquina entram.
    $GLOBALS['SUPABASE_URL']         = 'https://teste.local';
    $GLOBALS['SUPABASE_ANON_KEY']    = 'anon-de-teste';
    $GLOBALS['SUPABASE_SERVICE_KEY'] = 'service-de-teste';

    po_auth_set_verificador(fn($u, $a, $t) => $caso !== 'sem-auth' && $t === 'tok.valido');

    $criar = ['modo'=>'criar', 'nome'=>'Portugal 2027',
              'template'=>'po_roteiro_novo', 'corpo'=>CE_CORPO];
    switch ($caso) {
        case 'sem-auth':        $_POST = array_merge($_POST, $criar); break;
        case 'metodo-get':      $_SERVER['REQUEST_METHOD'] = 'GET'; break;
        case 'modo-invalido':   $_POST['modo'] = 'apagar-tudo'; break;
        case 'previa':          break;
        case 'criar':           $_POST = array_merge($_POST, $criar); break;
        case 'criar-sem-sair':  $_POST = array_merge($_POST, $criar, ['corpo'=>'Roteiro novo sem saida.']); break;
        case 'criar-longo':     $_POST = array_merge($_POST, $criar, ['corpo'=>str_repeat('x', 1025) . ' SAIR']); break;
        case 'criar-sem-nome':  $_POST = array_merge($_POST, $criar, ['nome'=>'']); break;
        case 'criar-duplicada': $_POST = array_merge($_POST, $criar); break;
        case 'criar-vazio':     $_POST = array_merge($_POST, $criar); break;
        case 'base-fora':       $_POST = array_merge($_POST, $criar); break;
    }

    wa_db_set_transport(function ($metodo, $url, $corpo, $headers) use ($caso, &$chamadas) {
        $chamadas[] = ['metodo'=>$metodo, 'url'=>$url, 'corpo'=>$corpo];   // headers NUNCA

        if (strpos($url, 'po_wa_campanhas') !== false) {
            if ($metodo === 'GET') {
                // A conferencia de "uma campanha por vez".
                if ($caso === 'criar-duplicada') {
                    return ['status'=>200, 'body'=>json_encode([[
                        'id'=>'CJA', 'nome'=>'Ja rodando', 'status'=>'enviando', 'total'=>10]])];
                }
                return ['status'=>200, 'body'=>'[]', 'headers'=>['content-range'=>'*/0']];
            }
            if ($metodo === 'POST') return ['status'=>201, 'body'=>'[{"id":"CNOVA"}]'];
            return ['status'=>204, 'body'=>''];      // PATCH: fecha a reserva
        }

        if (strpos($url, 'po_wa_envios') !== false) {
            if ($metodo === 'GET') {
                return ['status'=>200, 'body'=>'[]', 'headers'=>['content-range'=>'*/0']];
            }
            return ['status'=>201, 'body'=>'[{"id":"E1"}]'];
        }

        // po_leads: a base.
        if ($caso === 'base-fora') return ['status'=>503, 'body'=>'{"message":"fora"}'];
        preg_match('/offset=(\d+)/', $url, $m);
        $off = (int) ($m[1] ?? 0);
        if ($off > 0) return ['status'=>200, 'body'=>'[]'];
        // 'criar-vazio': base sem ninguem elegivel.
        $linhas = $caso === 'criar-vazio' ? [] : ce_base();
        return ['status'=>200, 'body'=>json_encode($linhas)];
    });

    register_shutdown_function(function () use (&$chamadas) {
        echo "\n@@CODIGO@@" . (int) http_response_code()
           . "\n@@CHAMADAS@@" . json_encode($chamadas) . "\n";
    });

    require $RAIZ . '/campanha.php';
}

if (isset($argv[1]) && $argv[1] !== '') { ce_filho($argv[1]); exit; }

/* ============================================================
   PAI: roda cada caso num processo e confere o que saiu.
============================================================ */
function ce_roda($caso) {
    $out = []; $cod = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($caso) . ' 2>&1', $out, $cod);
    $txt = implode("\n", $out);
    ok(strpos($txt, '@@CODIGO@@') !== false, "caso $caso respondeu (saida: " . substr($txt, 0, 300) . ')');
    list($corpo, $resto)  = explode('@@CODIGO@@', $txt, 2);
    list($codigo, $chams) = explode('@@CHAMADAS@@', $resto, 2);
    /* Na linha de comando, http_response_code() devolve false enquanto
       ninguem a chamou - e quem so responde ok nunca chama. Zero aqui e
       "seguiu o padrao", ou seja, 200. */
    $cod = (int) trim($codigo);
    return [
        'json'     => json_decode(trim($corpo), true),
        'codigo'   => $cod === 0 ? 200 : $cod,
        'chamadas' => json_decode(trim($chams), true) ?: [],
    ];
}
/* Escrita = POST ou PATCH. E o que nenhum caso de recusa pode ter deixado
   acontecer: recusar DEPOIS de ja ter criado a campanha seria pior que nao
   recusar, porque a campanha meia-criada bloqueia a proxima. */
function ce_escreveu($r) {
    foreach ($r['chamadas'] as $c) {
        if ($c['metodo'] === 'POST' || $c['metodo'] === 'PATCH') return true;
    }
    return false;
}

/* ---------- login ---------- */
$r = ce_roda('sem-auth');
ok($r['codigo'] === 401, 'sem login o endpoint recusa com 401 (deu: ' . $r['codigo'] . ')');
ok($r['chamadas'] === [], 'e nao toca o banco antes de conferir o login');

$r = ce_roda('metodo-get');
ok($r['codigo'] === 405, 'GET nao e aceito (deu: ' . $r['codigo'] . ')');

$r = ce_roda('modo-invalido');
ok($r['codigo'] === 400 && $r['json']['ok'] === false, 'modo desconhecido e recusado');
ok(!ce_escreveu($r), 'modo desconhecido nao escreve nada');

/* ---------- previa: mostra e NAO escreve ---------- */
$r = ce_roda('previa');
ok($r['codigo'] === 200 && $r['json']['ok'] === true, 'a previa responde ok');
ok($r['json']['resumo']['total'] === 1, 'so o lead com celular entra (deu: ' . $r['json']['resumo']['total'] . ')');
ok($r['json']['resumo']['saiu'] === 1, 'quem respondeu SAIR aparece no proprio balde');
ok($r['json']['resumo']['sem_celular'] === 1, 'o fixo aparece no balde do fixo');
ok($r['json']['custo_centavos'] === WA_CAMP_PRECO_CENTAVOS,
   'o custo e o preco vezes o publico (deu: ' . $r['json']['custo_centavos'] . ')');
ok(!ce_escreveu($r), 'a previa NAO escreve nada: ver o que aconteceria nao pode custar nada');

/* ---------- as recusas do modo criar ---------- */
$r = ce_roda('criar-sem-sair');
ok($r['codigo'] === 400, 'texto sem SAIR e recusado (deu: ' . $r['codigo'] . ')');
ok(strpos($r['json']['error'], 'SAIR') !== false, 'e o erro diz o que falta');
ok(!ce_escreveu($r), 'texto sem SAIR nao cria campanha nenhuma');

$r = ce_roda('criar-longo');
ok($r['codigo'] === 400 && !ce_escreveu($r),
   'texto acima de 1024 e recusado antes de escrever (deu: ' . $r['codigo'] . ')');

$r = ce_roda('criar-sem-nome');
ok($r['codigo'] === 400 && !ce_escreveu($r), 'campanha sem nome e recusada antes de escrever');

$r = ce_roda('criar-duplicada');
ok($r['codigo'] === 409, 'campanha em andamento bloqueia outra (deu: ' . $r['codigo'] . ')');
ok(!ce_escreveu($r),
   'e o segundo clique nao cria campanha gemea, que seria a base inteira cobrada em dobro');

$r = ce_roda('criar-vazio');
ok($r['codigo'] === 400 && !ce_escreveu($r), 'publico vazio nao vira campanha');

$r = ce_roda('base-fora');
ok($r['codigo'] === 502, 'base ilegivel devolve erro (deu: ' . $r['codigo'] . ')');
ok(!ce_escreveu($r),
   'base ilegivel NAO pode virar campanha de zero pessoa nem campanha parcial');

/* ---------- criar de verdade ---------- */
$r = ce_roda('criar');
ok($r['codigo'] === 200 && $r['json']['ok'] === true, 'o caminho feliz responde ok');
ok($r['json']['reservados'] === 1, 'reserva o publico inteiro (deu: ' . $r['json']['reservados'] . ')');
ok($r['json']['completo'] === true, 'e a reserva sai completa');

$posts = array_values(array_filter($r['chamadas'],
    fn($c) => $c['metodo'] === 'POST' && strpos($c['url'], 'po_wa_campanhas') !== false));
ok(count($posts) === 1, 'uma campanha criada, uma so');
$linha = json_decode($posts[0]['corpo'], true);
/* NASCE 'rascunho'. O dreno (cron e painel) so olha 'enviando', entao uma
   campanha cuja lista ainda esta sendo montada nunca comeca a enviar pela
   metade - que e o desfecho de reservar centenas de pessoas dentro de uma
   requisicao HTTP que o cPanel corta no meio. */
ok($linha['status'] === 'rascunho',
   'a campanha nasce rascunho, nao enviando (deu: ' . $linha['status'] . ')');
ok($linha['preco_centavos'] === WA_CAMP_PRECO_CENTAVOS,
   'o preco e congelado na criacao, para o relatorio antigo bater com a fatura daquele mes');
ok($linha['corpo'] === CE_CORPO, 'o corpo aprovado fica guardado junto da campanha');

$patches = array_values(array_filter($r['chamadas'],
    fn($c) => $c['metodo'] === 'PATCH' && strpos($c['url'], 'po_wa_campanhas') !== false));
ok(count($patches) === 1, 'a lista completa fecha a campanha');
ok(json_decode($patches[0]['corpo'], true)['status'] === 'enviando',
   'e so entao ela vira enviando, liberada para o dreno');

echo "test-campanha-endpoint OK\n";
