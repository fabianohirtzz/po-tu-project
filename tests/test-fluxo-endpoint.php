<?php
/* tests/test-fluxo-endpoint.php — o fluxo.php inteiro, sem rede.

   Mesmo porte do tests/test-templates-endpoint.php: o endpoint termina em
   exit(), entao cada caso roda num PROCESSO proprio. O banco e um duble
   injetado em wa_fluxo_set_deps('select_estrito'); ele registra cada consulta
   (tabela + query), nunca cabecalho. Dados de teste sao todos inventados. */

$RAIZ = dirname(__DIR__);
require_once $RAIZ . '/lib/po-auth.php';
require_once $RAIZ . '/lib/wa-fluxo.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

ini_set('error_log', sys_get_temp_dir() . '/po-test-fluxo.log');

/* ============================================================
   DADOS: dois fluxos. f1 foi publicado (versao v1); f2 nunca foi.
============================================================ */
function fx_card($id, $fluxo, $tipo, $titulo, $texto, $extra = []) {
    return $extra + ['id' => $id, 'fluxo_id' => $fluxo, 'tipo' => $tipo, 'titulo' => $titulo,
        'texto' => $texto, 'opcoes' => [], 'config' => [], 'proximo' => null, 'fallback' => null,
        'sem_resposta' => null, 'lembrete_ativo' => false, 'lembrete_texto' => null, 'ordem' => 0];
}

function fx_dados($caso) {
    $f1 = ['id' => 'f1', 'nome' => 'Boas-vindas', 'gatilho' => 'mensagem_nova', 'gatilho_valor' => null,
           'ativo' => true, 'card_inicial' => 'bv', 'versao_atual' => 'v1'];
    $f2 = ['id' => 'f2', 'nome' => 'Lista de espera', 'gatilho' => 'chamado', 'gatilho_valor' => null,
           'ativo' => true, 'card_inicial' => 'mk', 'versao_atual' => null];
    $c1 = [
        fx_card('bv', 'f1', 'botoes', 'Boas-vindas', 'Oi {nome}, como ajudo?', [
            'opcoes' => [['id' => 'b1', 'rotulo' => 'Falar com a equipe', 'destino' => 'hum']],
            'fallback' => 'ops', 'sem_resposta' => 'fim', 'lembrete_ativo' => true,
            'lembrete_texto' => 'Oi {nome}?', 'ordem' => 1]),
        fx_card('ops', 'f1', 'botoes', 'Nao entendi', 'Nao entendi. Quer falar com a equipe?', [
            'opcoes' => [['id' => 'v', 'rotulo' => 'Falar com a equipe', 'destino' => 'hum']],
            'fallback' => 'hum', 'lembrete_ativo' => true, 'lembrete_texto' => 'Oi?', 'ordem' => 2]),
        fx_card('hum', 'f1', 'humano', 'Atendimento', 'Vou chamar a equipe.', ['ordem' => 3]),
        fx_card('fim', 'f1', 'fim', 'Fim', 'Ate logo.', ['ordem' => 4]),
    ];
    $c2 = [
        fx_card('mk', 'f2', 'marca', 'Anota', null, ['config' => ['espera' => '+destino'], 'proximo' => 'f2fim', 'ordem' => 1]),
        fx_card('f2fim', 'f2', 'fim', 'Fim', 'Anotado.', ['ordem' => 2]),
    ];
    if ($caso === 'ler-utf8') $c1[0]['texto'] = 'Oi ' . chr(255);   // nem o retrato nem o rascunho codificam
    // O retrato publicado e tirado ANTES de qualquer mutacao do caso. Chaves
    // embaralhadas de proposito: o jsonb do banco nao guarda a ordem delas.
    $g = wa_fluxo_grafo_de($f1, $c1);
    foreach ($g['cards'] as $k => $c) krsort($g['cards'][$k]);
    $g['cards'] = array_reverse($g['cards'], true);
    krsort($g);
    $versoes = [['id' => 'v1', 'fluxo_id' => 'f1', 'versao' => 3, 'grafo' => $g]];
    if ($caso === 'ler') { $f2['ativo'] = false; $f2['gatilho_valor'] = 'portugal'; }
    if ($caso === 'ler-nada-publicado') { $f1['versao_atual'] = null; $versoes = []; }
    if ($caso === 'conf-aviso') {
        // sem card de fim: so aviso, nenhum bloqueio novo
        $c1 = array_values(array_filter($c1, fn($c) => $c['id'] !== 'fim'));
        $c1[0]['sem_resposta'] = null;
    }

    if ($caso === 'ler-mudou') $c1[0]['texto'] = 'Texto alterado depois de publicar.';
    if ($caso === 'conf-r11') {
        $f2['ativo'] = false; $f2['versao_atual'] = 'v2';
        $c1[] = fx_card('salto', 'f1', 'ir_para_fluxo', 'Salto', null, ['config' => ['fluxo' => 'f2'], 'ordem' => 5]);
        $c1[1]['proximo'] = 'salto';
    }
    if ($caso === 'conf-r9') { $f2['gatilho'] = 'mensagem_nova'; $f2['gatilho_valor'] = null; }
    return ['fluxos' => [$f1, $f2], 'cards' => array_merge($c1, $c2), 'versoes' => $versoes];
}

/* ============================================================
   FILHO
============================================================ */
function fx_filho($caso) {
    global $RAIZ;
    $chamadas = [];
    ini_set('error_log', sys_get_temp_dir() . '/po-test-fluxo.log');

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $GLOBALS['SUPABASE_URL']      = 'https://teste.local';
    $GLOBALS['SUPABASE_ANON_KEY'] = 'anon-de-teste';

    po_auth_set_verificador(fn($u, $a, $t) => $caso !== 'sem-auth' && $t === 'tok.valido');

    /* "fora-X" derruba a tabela X; "conf-fora-X" faz o mesmo no modo conferir. */
    $fora = null;
    if (preg_match('/^(?:conf-)?fora-(fluxos|cards|versoes)$/', $caso, $mm)) {
        $fora = ['fluxos' => 'po_wa_fluxos', 'cards' => 'po_wa_cards', 'versoes' => 'po_wa_fluxo_versoes'][$mm[1]];
    }

    $dados = fx_dados($caso);
    wa_fluxo_set_deps(['select_estrito' => function ($tabela, $query) use (&$chamadas, $dados, $fora) {
        $chamadas[] = ['tabela' => $tabela, 'query' => $query];
        if ($tabela === $fora) return null;
        $chave = ['po_wa_fluxos' => 'fluxos', 'po_wa_cards' => 'cards', 'po_wa_fluxo_versoes' => 'versoes'][$tabela] ?? null;
        if ($chave === null) return null;
        parse_str($query, $q);
        /* PostgREST recusa lista vazia em in.() com 400, que wa_fl_ler le como null. */
        foreach ($q as $v) if (is_string($v) && strpos($v, 'in.()') === 0) return null;
        $linhas = [];
        foreach ($dados[$chave] as $l) {
            foreach (['id', 'fluxo_id'] as $k) {
                if (!isset($q[$k]) || !array_key_exists($k, $l)) continue;
                if (strpos($q[$k], 'eq.') === 0 && (string) $l[$k] !== substr($q[$k], 3)) continue 2;
                if (strpos($q[$k], 'in.(') === 0 &&
                    !in_array((string) $l[$k], explode(',', substr($q[$k], 4, -1)), true)) continue 2;
            }
            /* filtro de booleano (ativo=eq.true), como o PostgREST faria */
            if (isset($q['ativo']) && strpos($q['ativo'], 'eq.') === 0 &&
                (!empty($l['ativo']) ? 'true' : 'false') !== substr($q['ativo'], 3)) continue;
            $linhas[] = $l;
        }
        if (isset($q['limit'])) $linhas = array_slice($linhas, 0, (int) $q['limit']);
        return $linhas;
    }]);

    $_POST = ['modo' => 'ler', 'sb_token' => 'tok.valido'];
    $conf = ['modo' => 'conferir', 'sb_token' => 'tok.valido', 'fluxo_id' => 'f1'];
    switch ($caso) {
        case 'get':              $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = []; break;
        case 'modo-invalido':    $_POST = ['modo' => 'apagar'] + $conf; break;
        case 'modo-array':       $_POST = ['modo' => ['ler']] + $conf; break;
        case 'conf-inexistente': $_POST = ['fluxo_id' => '00000000-0000-4000-8000-000000000099'] + $conf; break;
        case 'conf-sem-id':      $_POST = ['fluxo_id' => ''] + $conf; break;
        case 'conf-id-array':    $_POST = ['fluxo_id' => ['f1']] + $conf; break;
        case 'conf-ok':
        case 'conf-aviso':
        case 'conf-r11':
        case 'conf-r9':
        case 'conf-fora-fluxos':
        case 'conf-fora-cards':  $_POST = $conf; break;
    }

    register_shutdown_function(function () use (&$chamadas) {
        $c = http_response_code();
        echo "\n@@CODIGO@@" . ($c === false ? 0 : (int) $c)
           . "\n@@CHAMADAS@@" . json_encode($chamadas);
    });

    require $RAIZ . '/fluxo.php';
    exit;
}

if (PHP_SAPI === 'cli' && isset($argv[1])) { fx_filho($argv[1]); }

function fx_roda($caso) {
    $out = []; $cod = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' .
         escapeshellarg($caso) . ' 2>&1', $out, $cod);
    $txt = implode("\n", $out);
    ok(strpos($txt, '@@CODIGO@@') !== false,
       "caso $caso respondeu (saida: " . substr($txt, 0, 300) . ')');
    list($corpo, $resto)  = explode('@@CODIGO@@', $txt, 2);
    list($codigo, $chams) = explode('@@CHAMADAS@@', $resto, 2);
    $c = (int) trim($codigo);
    $json = json_decode(trim($corpo), true);
    ok(is_array($json),
       "caso $caso respondeu JSON limpo, sem aviso do PHP junto (veio: " . substr(trim($corpo), 0, 200) . ')');
    /* A mensagem de erro e um literal de um conjunto fechado: nunca ecoa o
       que veio no POST (ela vaza para log, print e suporte). */
    if (($json['ok'] ?? null) === false) {
        ok(in_array($json['error'] ?? null, [
            'Método não permitido.', 'Sessão expirada. Entre de novo no painel.', 'Modo inválido.',
            'Não consegui ler o banco agora. Tente de novo em instantes.',
            'Não foi dito qual fluxo conferir.', 'Este fluxo não existe.',
        ], true), "caso $caso: erro fora do conjunto fechado (veio: " . json_encode($json['error'] ?? null) . ')');
    }
    return ['json' => $json, 'codigo' => $c === 0 ? 200 : $c,
            'chamadas' => json_decode(trim($chams), true) ?: []];
}

function fx_por_id($r) {
    $m = [];
    foreach ($r['json']['fluxos'] ?? [] as $f) $m[$f['id']] = $f;
    return $m;
}
function fx_esperado($caso) {
    $d = fx_dados($caso);
    $meus = array_values(array_filter($d['cards'], fn($c) => $c['fluxo_id'] === 'f1'));
    return json_decode(json_encode(wa_fluxo_valida($d['fluxos'][0], $meus, $d['fluxos'])), true);
}
function fx_regras($r) { return array_map(fn($p) => $p['regra'], $r['json']['problemas'] ?? []); }

/* ============================================================
   A PORTA
============================================================ */
$r = fx_roda('get');
ok($r['codigo'] === 405, 'GET nao passa (deu: ' . $r['codigo'] . ')');

/* Sem login: 401 e o banco NAO e consultado. */
$r = fx_roda('sem-auth');
ok($r['codigo'] === 401, 'sem login do painel, 401 (deu: ' . $r['codigo'] . ')');
ok($r['chamadas'] === [], 'e nao chega a ler o banco');

$r = fx_roda('modo-invalido');
ok($r['codigo'] === 400, 'modo fora da lista, 400 (deu: ' . $r['codigo'] . ')');
ok($r['chamadas'] === [], 'e nao le o banco');

/* "modo[]=ler" chegaria array e estouraria TypeError no PHP 8. */
$r = fx_roda('modo-array');
ok($r['codigo'] === 400, 'modo como array vira recusa, nao erro de tipo (deu: ' . $r['codigo'] . ')');

/* ============================================================
   LER
============================================================ */
$r = fx_roda('ler');
ok($r['codigo'] === 200, 'ler responde (deu: ' . $r['codigo'] . ')');
ok($r['json']['ok'] === true, 'ok:true');
ok(count($r['json']['fluxos']) === 2, 'devolve os dois fluxos');
$m = fx_por_id($r);
ok(count($m['f1']['cards']) === 4, 'f1 chega com os 4 cards dele (veio: ' . count($m['f1']['cards']) . ')');
ok(count($m['f2']['cards']) === 2, 'f2 chega com os 2 cards dele, sem misturar');
foreach (['id', 'nome', 'gatilho', 'gatilho_valor', 'ativo', 'card_inicial', 'versao_atual',
          'versao_numero', 'publicado_igual', 'cards'] as $k) {
    ok(array_key_exists($k, $m['f1']), "o fluxo traz o campo $k");
}
ok($m['f1']['versao_numero'] === 3, 'versao_numero e o numero da versao apontada (veio: '
   . json_encode($m['f1']['versao_numero']) . ')');
ok($m['f1']['publicado_igual'] === true, 'rascunho igual ao retrato publicado: publicado_igual true');

ok($m['f1']['ativo'] === true && $m['f2']['ativo'] === false, 'ativo vem do banco, nao e fixo');
ok($m['f1']['card_inicial'] === 'bv' && $m['f1']['gatilho'] === 'mensagem_nova', 'card_inicial e gatilho vem do banco');
ok($m['f2']['gatilho'] === 'chamado' && $m['f2']['card_inicial'] === 'mk', 'e o f2 tem os dele');
ok($m['f1']['gatilho_valor'] === null && $m['f2']['gatilho_valor'] === 'portugal', 'gatilho_valor vem do banco');

/* Fluxo nunca publicado NAO e erro. */
ok($m['f2']['versao_atual'] === null, 'f2 nunca foi publicado');
ok($m['f2']['versao_numero'] === null, 'sem versao_atual, versao_numero e null');
ok($m['f2']['publicado_igual'] === false, 'e publicado_igual e false');

/* Primeiro dia: nenhum fluxo publicado. Sem a guarda, sairia id=in.() e a tela nao abriria. */
$r = fx_roda('ler-nada-publicado');
ok($r['codigo'] === 200, 'nada publicado ainda: ler responde 200 (deu: ' . $r['codigo'] . ')');
foreach (fx_por_id($r) as $f) ok($f['publicado_igual'] === false && $f['versao_numero'] === null, 'e nenhum vem como publicado');

/* Se nao da para codificar, nao da para afirmar que e igual. */
$r = fx_roda('ler-utf8');
ok($r['codigo'] === 200 && fx_por_id($r)['f1']['publicado_igual'] === false, 'retrato que nao codifica nunca vira "igual"');

$r = fx_roda('ler-mudou');
ok(fx_por_id($r)['f1']['publicado_igual'] === false,
   'mudar o texto de um card depois de publicar: publicado_igual false');

/* Erro de leitura nunca vira lista vazia. */
foreach (['fora-fluxos', 'fora-cards', 'fora-versoes'] as $caso) {
    $r = fx_roda($caso);
    ok($r['codigo'] === 502, "$caso: erro de leitura vira 502, nao lista vazia (deu: " . $r['codigo'] . ')');
    ok(($r['json']['ok'] ?? null) === false && !isset($r['json']['fluxos']),
       "$caso: e a resposta nao traz fluxos");
}

/* ============================================================
   CONFERIR
============================================================ */
$r = fx_roda('conf-inexistente');
ok($r['codigo'] === 404, 'conferir com fluxo inexistente, 404 (deu: ' . $r['codigo'] . ')');

$r = fx_roda('conf-sem-id');
ok($r['codigo'] === 400, 'conferir sem fluxo_id, 400 (deu: ' . $r['codigo'] . ')');
$r = fx_roda('conf-id-array');
ok($r['codigo'] === 400, 'fluxo_id como array vira recusa, nao erro de tipo (deu: ' . $r['codigo'] . ')');

foreach (['conf-fora-fluxos', 'conf-fora-cards'] as $caso) {
    $r = fx_roda($caso);
    ok($r['codigo'] === 502, "$caso: banco fora vira 502 (deu: " . $r['codigo'] . ')');
}

/* Repasse SEM alterar: o que o endpoint devolve e exatamente o que a funcao devolve. */
$r = fx_roda('conf-ok');
ok($r['codigo'] === 200, 'conferir responde (deu: ' . $r['codigo'] . ')');
$esperado = fx_esperado('conf-ok');
ok($r['json']['problemas'] === $esperado, 'problemas repassados sem alterar (veio: '
   . json_encode($r['json']['problemas']) . ')');
foreach ($r['json']['problemas'] as $p) {
    ok(array_keys($p) === ['regra', 'card', 'texto', 'bloqueia'], 'cada problema tem a forma regra/card/texto/bloqueia');
}

/* Aviso chega, com bloqueia=false, na mesma lista. */
$r = fx_roda('conf-aviso');
$esperado = fx_esperado('conf-aviso');
ok($r['json']['problemas'] === $esperado, 'conf-aviso: lista repassada sem alterar (veio: ' . json_encode($r['json']['problemas']) . ')');
$avisos = array_filter($r['json']['problemas'], fn($p) => $p['bloqueia'] === false);
ok(count($avisos) >= 1 && in_array(16, fx_regras($r), true), 'o aviso da regra 16 chega a tela');
foreach ($r['json']['problemas'] as $p) {
    ok(array_keys($p) === ['regra', 'card', 'texto', 'bloqueia'], 'forma regra/card/texto/bloqueia');
}

/* $outros e load-bearing: sem ele o conferir aprova o que o publicar recusa. */
$r = fx_roda('conf-r11');
ok($r['codigo'] === 200, 'conf-r11 responde');
ok(in_array(11, fx_regras($r), true), 'ir_para_fluxo para destino desligado traz a regra 11');
ok($r['json']['problemas'] === fx_esperado('conf-r11'),
   'e o texto e o de "desligado", da lista inteira (veio: ' . json_encode($r['json']['problemas']) . ')');

$r = fx_roda('conf-r9');
ok(in_array(9, fx_regras($r), true), 'mesma porta de outro fluxo ativo traz a regra 9');
ok($r['json']['problemas'] === fx_esperado('conf-r9'), 'e a lista inteira bate com a funcao');

/* ============================================================
   ASSERCAO DE FONTE
============================================================ */
$src = file_get_contents(__DIR__ . '/../fluxo.php');
ok(strpos($src, 'wa_fluxo_valida(') !== false, 'o conferir usa a funcao de julgamento do publicar');
ok(strpos($src, 'wa_fluxo_grafo_de(') !== false, 'publicado_igual usa o mesmo retrato do publicar');
ok(strpos($src, 'po_auth_ok(') !== false, 'o login do painel e exigido');
$p_auth = strpos($src, 'po_auth_ok(');
$p_modo = strpos($src, "\$modo = fpost('modo')");
ok($p_auth !== false && $p_modo !== false && $p_auth < $p_modo,
   'o login e conferido antes de olhar o que foi pedido');
ok(strpos($src, 'po_config()') === false, 'o endpoint le segredo por wa_config(), nunca po_config()');
/* Nenhuma mensagem de problema escrita por extenso: o texto vem da funcao,
   para a tela e o publicar nunca divergirem. */
ok(strpos($src, "'regra'") === false && strpos($src, '"regra"') === false,
   'fluxo.php nao fabrica problemas, so repassa os de wa_fluxo_valida');
ok(preg_match('/wa_fluxo_valida\([^)]*\$outros/', $src) === 1,
   'o conferir passa $outros para wa_fluxo_valida');

echo "test-fluxo-endpoint OK\n";
