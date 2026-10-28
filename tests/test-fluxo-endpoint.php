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
    if ($caso === 'pub-invalido' || $caso === 'desl-invalido') $f1['card_inicial'] = 'nao-existe';   // grafo quebrado
    if ($caso === 'pub-aviso') {
        $c1 = array_values(array_filter($c1, fn($c) => $c['id'] !== 'fim'));
        $c1[0]['sem_resposta'] = null;
    }
    if ($caso === 'pub-desligado') $f1['ativo'] = false;
    if (strpos($caso, 'lig-') === 0 || $caso === 'ligar-ja-ligado') {
        // f2 foi publicado e esta desligado; na mesma porta de f1 (mensagem_nova), salvo nos casos "livre" e "falha".
        $f2['ativo'] = false; $f2['versao_atual'] = 'v2';
        if ($caso === 'lig-falha-outro-valor') { $f2['gatilho'] = 'mensagem_nova'; $f2['gatilho_valor'] = 'portugal'; }
        elseif ($caso !== 'lig-livre' && $caso !== 'lig-falha' && $caso !== 'lig-falha-ligado') { $f2['gatilho'] = 'mensagem_nova'; $f2['gatilho_valor'] = null; }
        if ($caso === 'lig-vazio-igual-nulo') { $f1['gatilho_valor'] = ''; }
        if ($caso === 'lig-nunca-publicado') $f2['versao_atual'] = null;
    }
    if ($caso === 'lig-falha-ligado') { $f2['ativo'] = true; $f2['gatilho'] = 'chamado'; }
    if ($caso === 'ligar-ja-ligado') { $f2['ativo'] = true; $f2['gatilho'] = 'chamado'; }
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

    if ($caso === 'pub-fora-cards') $fora = 'po_wa_cards';
    $dados = fx_dados($caso);
    $falha_escrita = in_array($caso, ['lig-falha', 'desl-falha', 'lig-falha-ligado', 'lig-falha-outro-valor'], true);
    wa_fluxo_set_deps(['select_estrito' => function ($tabela, $query) use (&$chamadas, &$dados, $fora) {
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
    },
    /* Escrita: registra o que foi pedido e aplica no estado do duble. O indice
       unico parcial do banco (gatilho, coalesce(gatilho_valor,'')) onde ativo
       e imitado: ligar numa porta ocupada devolve false, como wa_db_update. */
    'insert' => function ($tabela, $linha) use (&$chamadas, &$dados) {
        $chamadas[] = ['tabela' => $tabela, 'op' => 'insert', 'linha' => $linha];
        if ($tabela !== 'po_wa_fluxo_versoes') return null;
        $linha['id'] = 'v-novo-' . (count($dados['versoes']) + 1);
        $dados['versoes'][] = $linha;
        return $linha;
    },
    'update' => function ($tabela, $query, $campos) use (&$chamadas, &$dados, $falha_escrita) {
        $chamadas[] = ['tabela' => $tabela, 'op' => 'update', 'query' => $query, 'campos' => $campos];
        if ($falha_escrita || $tabela !== 'po_wa_fluxos') return false;
        parse_str($query, $q);
        $alvo = substr($q['id'] ?? '', 3);
        foreach ($dados['fluxos'] as $i => $f) {
            if ((string) $f['id'] !== $alvo) continue;
            $novo = $campos + $f;
            if (!empty($novo['ativo'])) {
                foreach ($dados['fluxos'] as $o) {
                    if ($o['id'] !== $f['id'] && !empty($o['ativo']) && $o['gatilho'] === $novo['gatilho']
                        && (string) ($o['gatilho_valor'] ?? '') === (string) ($novo['gatilho_valor'] ?? '')) return false;
                }
            }
            $dados['fluxos'][$i] = $novo;
        }
        return true;
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
        case 'pub-invalido':
        case 'pub-aviso':
        case 'pub-desligado':
        case 'pub-ok':
        case 'pub-fora-cards':   $_POST = ['modo' => 'publicar'] + $conf; break;
        case 'pub-sem-id':       $_POST = ['modo' => 'publicar', 'fluxo_id' => ''] + $conf; break;
        case 'pub-id-array':     $_POST = ['modo' => 'publicar', 'fluxo_id' => ['f1']] + $conf; break;
        case 'pub-inexistente':  $_POST = ['modo' => 'publicar', 'fluxo_id' => 'nao-existe'] + $conf; break;
        case 'modo-livre-com-id': $_POST = ['modo' => 'qualquer-coisa'] + $conf; break;
        case 'lig-livre':
        case 'lig-falha':
        case 'lig-falha-ligado':
        case 'lig-falha-outro-valor':
        case 'lig-ocupada':
        case 'lig-vazio-igual-nulo':
        case 'lig-nunca-publicado':
        case 'ligar-ja-ligado':  $_POST = ['modo' => 'ligar', 'fluxo_id' => 'f2'] + $conf; break;
        case 'lig-sem-id':       $_POST = ['modo' => 'ligar', 'fluxo_id' => ''] + $conf; break;
        case 'lig-inexistente':  $_POST = ['modo' => 'ligar', 'fluxo_id' => 'nao-existe'] + $conf; break;
        case 'desl-ok':
        case 'desl-invalido':
        case 'desl-falha':       $_POST = ['modo' => 'desligar'] + $conf; break;
        case 'desl-sem-id':      $_POST = ['modo' => 'desligar', 'fluxo_id' => ''] + $conf; break;
        case 'desl-inexistente': $_POST = ['modo' => 'desligar', 'fluxo_id' => 'nao-existe'] + $conf; break;
    }

    /* O cache e o do site de verdade (po_cache_dir). Um arquivo-sentinela diz se
       a limpeza aconteceu. */
    $sentinela = po_cache_dir() . '/fluxo-test-sentinela.json';
    file_put_contents($sentinela, '{}');

    register_shutdown_function(function () use (&$chamadas, &$dados, $sentinela) {
        $c = http_response_code();
        $limpou = !is_file($sentinela);
        @unlink($sentinela);
        echo "\n@@CODIGO@@" . ($c === false ? 0 : (int) $c)
           . "\n@@CHAMADAS@@" . json_encode($chamadas)
           . "\n@@ESTADO@@" . json_encode(['fluxos' => $dados['fluxos'], 'versoes' => count($dados['versoes']), 'cache_limpo' => $limpou]);
    });

    require $RAIZ . '/fluxo.php';
    exit;
}

if (PHP_SAPI === 'cli' && isset($argv[1])) { fx_filho($argv[1]); }

/* O 409 da porta ocupada cita o NOME do fluxo que ocupa a porta. Nome de fluxo
   e texto que a cliente escreveu no painel, nao dado de cliente; por isso este
   e o unico erro fora do conjunto de literais, e mesmo assim so no formato
   exato abaixo (nada de telefone ou ficha cabe nessa frase). */
const FX_ERRO_PORTA = '/^Já existe outro fluxo ligado nesta porta de entrada: "[^"]{1,200}"\. Desligue esse fluxo antes ou mude a porta deste\.$/u';

function fx_roda($caso) {
    $out = []; $cod = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' .
         escapeshellarg($caso) . ' 2>&1', $out, $cod);
    $txt = implode("\n", $out);
    ok(strpos($txt, '@@CODIGO@@') !== false,
       "caso $caso respondeu (saida: " . substr($txt, 0, 300) . ')');
    list($corpo, $resto)  = explode('@@CODIGO@@', $txt, 2);
    list($codigo, $resto2) = explode('@@CHAMADAS@@', $resto, 2);
    list($chams, $estado)  = explode('@@ESTADO@@', $resto2, 2);
    $c = (int) trim($codigo);
    $json = json_decode(trim($corpo), true);
    ok(is_array($json),
       "caso $caso respondeu JSON limpo, sem aviso do PHP junto (veio: " . substr(trim($corpo), 0, 200) . ')');
    /* A mensagem de erro e um literal de um conjunto fechado: nunca ecoa o
       que veio no POST (ela vaza para log, print e suporte). */
    if (($json['ok'] ?? null) === false && !isset($json['problemas'])) {
        ok(in_array($json['error'] ?? null, [
            'Método não permitido.', 'Sessão expirada. Entre de novo no painel.', 'Modo inválido.',
            'Não consegui ler o banco agora. Tente de novo em instantes.',
            'Não foi dito qual fluxo conferir.', 'Este fluxo não existe.',
            'Não foi dito qual fluxo publicar.', 'Não foi dito qual fluxo ligar.', 'Não foi dito qual fluxo desligar.',
            'Publique o fluxo antes de ligar.',
            'Não consegui ligar o fluxo agora. Tente de novo em instantes.',
            'Não consegui desligar o fluxo agora. Tente de novo em instantes.',
        ], true) || preg_match(FX_ERRO_PORTA, (string) ($json['error'] ?? '')) === 1,
           "caso $caso: erro fora do conjunto fechado (veio: " . json_encode($json['error'] ?? null) . ')');
    }
    return ['json' => $json, 'codigo' => $c === 0 ? 200 : $c,
            'chamadas' => json_decode(trim($chams), true) ?: [],
            'estado'   => json_decode(trim($estado), true)];
}

function fx_escritas($r) { return array_values(array_filter($r['chamadas'], fn($c) => isset($c['op']))); }
function fx_lidas($r, $tabela) { return array_values(array_filter($r['chamadas'], fn($c) => !isset($c['op']) && $c['tabela'] === $tabela)); }
function fx_estado_fluxo($r, $id) { foreach ($r['estado']['fluxos'] as $f) if ($f['id'] === $id) return $f; return null; }

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
   PUBLICAR
============================================================ */
/* Grafo invalido: 400, com a lista de problemas, e NADA gravado. */
$r = fx_roda('pub-invalido');
ok($r['codigo'] === 400, 'publicar grafo invalido, 400 (deu: ' . $r['codigo'] . ')');
ok(($r['json']['ok'] ?? null) === false, 'ok:false');
ok(!empty($r['json']['problemas']), 'e a lista de problemas vem junto');
$bloq = array_filter($r['json']['problemas'], fn($p) => $p['bloqueia'] === true);
ok(count($bloq) >= 1, 'com pelo menos um problema que bloqueia');
foreach ($r['json']['problemas'] as $p) ok(array_keys($p) === ['regra', 'card', 'texto', 'bloqueia'], 'forma regra/card/texto/bloqueia, sem achatar');
ok(fx_escritas($r) === [], 'nenhuma versao e gravada, nenhuma linha atualizada (veio: ' . json_encode(fx_escritas($r)) . ')');
ok($r['estado']['versoes'] === 1, 'o banco segue com a versao que tinha');
ok($r['estado']['cache_limpo'] === false, 'e o cache NAO e limpo: nada mudou');

/* Publicar valido: grava a versao seguinte, aponta versao_atual, limpa o cache. */
$r = fx_roda('pub-ok');
ok($r['codigo'] === 200, 'publicar valido responde 200 (deu: ' . $r['codigo'] . ' ' . json_encode($r['json']) . ')');
ok($r['json']['ok'] === true && $r['json']['versao'] === 4, 'versao 4, depois da 3 (veio: ' . json_encode($r['json']) . ')');
$esc = fx_escritas($r);
ok(count($esc) === 2 && $esc[0]['op'] === 'insert' && $esc[0]['tabela'] === 'po_wa_fluxo_versoes', 'grava a versao primeiro');
ok($esc[0]['linha']['versao'] === 4 && $esc[0]['linha']['fluxo_id'] === 'f1', 'versao 4 do fluxo certo');
ok($esc[1]['op'] === 'update' && $esc[1]['campos'] === ['versao_atual' => 'v-novo-2'], 'e so aponta versao_atual para ela (veio: ' . json_encode($esc[1]['campos']) . ')');
ok($r['estado']['cache_limpo'] === true, 'a publicacao LIMPA o cache do site');
ok(fx_estado_fluxo($r, 'f1')['ativo'] === true, 'publicar tambem nao desliga o que estava ligado');

/* Publicar nao liga: nenhuma escrita carrega `ativo`, e o estado final nao muda. */
$r = fx_roda('pub-desligado');
ok($r['codigo'] === 200, 'fluxo desligado publica normalmente');
foreach (fx_escritas($r) as $e) {
    ok(!array_key_exists('ativo', $e['campos'] ?? []) && !array_key_exists('ativo', $e['linha'] ?? []), 'nenhuma escrita toca em ativo');
}
ok(fx_estado_fluxo($r, 'f1')['ativo'] === false, 'publicar NAO liga: o fluxo desligado continua desligado');

/* Aviso nao bloqueia, mas CHEGA na resposta de sucesso. */
$r = fx_roda('pub-aviso');
ok($r['codigo'] === 200 && $r['json']['ok'] === true, 'so aviso: publica');
ok(in_array(16, fx_regras($r), true), 'e o aviso da regra 16 vem na resposta de sucesso, nao e filtrado');
foreach ($r['json']['problemas'] as $p) ok($p['bloqueia'] === false, 'todos os que vieram sao aviso');

/* Entradas ruins. */
foreach (['pub-sem-id', 'pub-id-array'] as $caso) {
    $r = fx_roda($caso);
    ok($r['codigo'] === 400, "$caso: 400 (deu: " . $r['codigo'] . ')');
    ok($r['chamadas'] === [], "$caso: nao le nem escreve nada");
}
$r = fx_roda('pub-inexistente');
ok($r['codigo'] === 400 && fx_escritas($r) === [] && $r['estado']['cache_limpo'] === false, 'fluxo inexistente: recusa, sem escrita');
$r = fx_roda('pub-fora-cards');
ok($r['codigo'] === 400 && fx_escritas($r) === [], 'leitura que falha nunca publica (deu: ' . $r['codigo'] . ')');
ok($r['estado']['cache_limpo'] === false, 'e nao limpa o cache');

/* modo fora da lista, com fluxo_id valido: nada acontece (a cauda publica). */
$r = fx_roda('modo-livre-com-id');
ok($r['codigo'] === 400 && $r['chamadas'] === [], 'modo desconhecido com fluxo_id valido nao publica nem le (deu: ' . $r['codigo'] . ')');

/* ============================================================
   LIGAR
============================================================ */
$r = fx_roda('lig-livre');
ok($r['codigo'] === 200 && $r['json'] === ['ok' => true, 'ativo' => true], 'ligar numa porta livre (veio: ' . json_encode($r['json']) . ')');
$esc = fx_escritas($r);
ok(count($esc) === 1 && $esc[0]['campos'] === ['ativo' => true] && $esc[0]['query'] === 'id=eq.f2', 'liga so o f2, so o campo ativo');
ok(fx_estado_fluxo($r, 'f2')['ativo'] === true && fx_estado_fluxo($r, 'f1')['ativo'] === true, 'e o f1 segue ligado');

$r = fx_roda('ligar-ja-ligado');
ok($r['codigo'] === 200, 'ligar o que ja esta ligado nao e conflito consigo mesmo');

/* Porta ocupada: 409 e a mensagem NOMEIA o fluxo que ocupa. */
$r = fx_roda('lig-ocupada');
ok($r['codigo'] === 409, 'porta ocupada, 409 (deu: ' . $r['codigo'] . ' ' . json_encode($r['json']) . ')');
ok(strpos($r['json']['error'], '"Boas-vindas"') !== false, 'a mensagem cita o nome de quem ocupa (veio: ' . $r['json']['error'] . ')');
ok(strpos($r['json']['error'], 'Lista de espera') === false, 'e nao cita o proprio fluxo');
ok(fx_estado_fluxo($r, 'f2')['ativo'] === false, 'e o f2 segue desligado');
/* "" e NULL na mesma porta: o indice usa coalesce(gatilho_valor, ''). */
$r = fx_roda('lig-vazio-igual-nulo');
ok($r['codigo'] === 409 && strpos($r['json']['error'], 'Boas-vindas') !== false, 'valor vazio e nulo sao a mesma porta (deu: ' . $r['codigo'] . ')');

/* A escrita falhou e ninguem ocupa a porta: erro de servidor, nunca 409 inventado. */
$r = fx_roda('lig-falha');
ok($r['codigo'] === 502, 'escrita que falha sem conflito, 502 (deu: ' . $r['codigo'] . ')');

/* A falha de escrita NAO e porta ocupada quando o "ocupante" e o proprio fluxo
   (ja ligado) ou outro de valor de porta diferente: nada de 409 inventado. */
foreach (['lig-falha-ligado', 'lig-falha-outro-valor'] as $caso) {
    $r = fx_roda($caso);
    ok($r['codigo'] === 502, "$caso: 502, nao acusa quem nao ocupa a porta (deu: " . $r['codigo'] . ' ' . json_encode($r['json']) . ')');
}

/* Nunca publicado nao liga. */
$r = fx_roda('lig-nunca-publicado');
ok($r['codigo'] === 400 && fx_escritas($r) === [], 'fluxo nunca publicado nao e ligado (deu: ' . $r['codigo'] . ')');

$r = fx_roda('lig-sem-id');
ok($r['codigo'] === 400 && $r['chamadas'] === [], 'ligar sem fluxo_id, 400, sem tocar no banco');
$r = fx_roda('lig-inexistente');
ok($r['codigo'] === 404 && fx_escritas($r) === [], 'ligar fluxo inexistente, 404, sem escrever');

/* ============================================================
   DESLIGAR
============================================================ */
$r = fx_roda('desl-ok');
ok($r['codigo'] === 200 && $r['json'] === ['ok' => true, 'ativo' => false], 'desligar (veio: ' . json_encode($r['json']) . ')');
$esc = fx_escritas($r);
ok(count($esc) === 1 && $esc[0]['campos'] === ['ativo' => false] && $esc[0]['query'] === 'id=eq.f1', 'desliga so o f1, so o campo ativo');
ok(fx_estado_fluxo($r, 'f1')['ativo'] === false, 'e ele esta desligado');

/* Desligar NAO depende de o grafo estar valido: fluxo quebrado e ligado
   atende cliente real, e tem que ter saida. */
$r = fx_roda('desl-invalido');
ok($r['codigo'] === 200 && $r['json']['ativo'] === false, 'desligar com grafo invalido funciona (deu: ' . $r['codigo'] . ')');
ok(fx_estado_fluxo($r, 'f1')['ativo'] === false, 'e o fluxo quebrado saiu do ar');
ok(fx_lidas($r, 'po_wa_cards') === [], 'e nem le os cards: nao julga nada');

$r = fx_roda('desl-falha');
ok($r['codigo'] === 502, 'escrita que falha ao desligar, 502, nunca "ok" (deu: ' . $r['codigo'] . ')');
$r = fx_roda('desl-sem-id');
ok($r['codigo'] === 400 && $r['chamadas'] === [], 'desligar sem fluxo_id, 400');
$r = fx_roda('desl-inexistente');
ok($r['codigo'] === 404 && fx_escritas($r) === [], 'desligar fluxo inexistente, 404');

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
/* A trava PROIBE ESCREVER problema, nao le-lo: o publicar le `bloqueia` para
   decidir o que fazer, e isso e legitimo. Fabricar um problema exige a forma
   'chave' => valor (regra/card/texto/bloqueia); por isso a trava procura essa
   sequencia. NAO a afrouxe: se o endpoint inventar problema, a tela e o
   publicar passam a divergir. */
ok(preg_match('/[\'"](regra|card|texto|bloqueia)[\'"]\s*=>/', $src) === 0,
   'fluxo.php nao fabrica problemas, so repassa os de wa_fluxo_valida');
ok(strpos($src, 'Este fluxo não tem nenhum card') === false, 'e nao escreve texto de problema por extenso');
ok(preg_match('/wa_fluxo_publica\(\s*\$fluxo_id\s*\)/', $src) === 1,
   'o publicar chama wa_fluxo_publica sem $outros: ela le e valida por conta propria');
ok(strpos($src, 'po_cache_limpa(') !== false, 'o publicar limpa o cache do site');
ok(preg_match('/in_array\(\$modo, \[[^\]]*\'publicar\'[^\]]*\'ligar\'[^\]]*\'desligar\'/', $src) === 1,
   'publicar, ligar e desligar entram na lista fechada de modo (uma so)');
ok(substr_count($src, 'in_array($modo') === 1, 'e nao existe uma segunda lista de modo');
ok(preg_match('/wa_fluxo_valida\([^)]*\$outros/', $src) === 1,
   'o conferir passa $outros para wa_fluxo_valida');

echo "test-fluxo-endpoint OK\n";
