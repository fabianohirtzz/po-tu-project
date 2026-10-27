<?php
require __DIR__ . '/../lib/wa-fluxo.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

/* Banco em memoria que responde como o PostgREST: lista de linhas, filtro por
   coluna=eq.valor, order=col.desc e limit. Nenhuma rede. */
function card($id, $tipo, $extra = []) {
    return array_merge(['id' => $id, 'tipo' => $tipo, 'titulo' => $id, 'texto' => 't',
        'opcoes' => [], 'config' => [], 'proximo' => null, 'fallback' => null,
        'sem_resposta' => null, 'lembrete_ativo' => true, 'lembrete_texto' => 'l'], $extra);
}
function cards_ok($fluxo_id, $destino_b2 = 'fim') {
    return [
        card('bv', 'botoes', ['fluxo_id' => $fluxo_id, 'texto' => 'Oi {nome}',
            'opcoes' => [['id' => 'b1', 'rotulo' => 'Falar com a Lais', 'destino' => 'hum'],
                         ['id' => 'b2', 'rotulo' => 'Encerrar', 'destino' => $destino_b2]],
            'fallback' => 'hum']),
        card('hum', 'humano', ['fluxo_id' => $fluxo_id]),
        card('fim', 'fim', ['fluxo_id' => $fluxo_id]),
    ];
}
$DB = [
    'po_wa_fluxos' => [
        ['id' => 'f1', 'nome' => 'Boas-vindas', 'gatilho' => 'mensagem_nova', 'gatilho_valor' => null,
         'ativo' => false, 'card_inicial' => 'bv', 'versao_atual' => null],
        ['id' => 'f-quebrado', 'nome' => 'Quebrado', 'gatilho' => 'palavra', 'gatilho_valor' => 'x',
         'ativo' => false, 'card_inicial' => 'bv', 'versao_atual' => null],
    ],
    'po_wa_cards' => array_merge(cards_ok('f1'), cards_ok('f-quebrado', 'nao-existe')),
    'po_wa_fluxo_versoes' => [],
];
// Os ids dos cards se repetem entre fluxos no fixture: o filtro por fluxo_id separa.
$ESCRITAS = [];
$FORA = false;      // Supabase fora do ar
$LEITURAS = 0;

$consulta = function ($tabela, $query) use (&$DB, &$LEITURAS) {
    $LEITURAS++;
    parse_str($query, $q);
    $linhas = $DB[$tabela] ?? [];
    foreach ($q as $col => $filtro) {
        if (is_string($filtro) && strpos($filtro, 'eq.') === 0) {
            $v = substr($filtro, 3);
            $linhas = array_values(array_filter($linhas, function ($l) use ($col, $v) { return (string) ($l[$col] ?? '') === $v; }));
        }
    }
    if (isset($q['order']) && preg_match('/^(\w+)\.desc$/', $q['order'], $m)) {
        usort($linhas, function ($a, $b) use ($m) { return $b[$m[1]] <=> $a[$m[1]]; });
    }
    if (isset($q['limit'])) $linhas = array_slice($linhas, 0, (int) $q['limit']);
    return $linhas;
};
wa_fluxo_set_deps([
    // `select` comum engole o erro e devolve lista vazia; `select_estrito` devolve null.
    'select' => function ($t, $q) use ($consulta, &$FORA) { return $FORA ? [] : $consulta($t, $q); },
    'select_estrito' => function ($t, $q) use ($consulta, &$FORA) { return $FORA ? null : $consulta($t, $q); },
    'insert' => function ($tabela, $linha) use (&$DB, &$ESCRITAS) {
        $ESCRITAS[] = [$tabela, $linha];
        $linha['id'] = $linha['id'] ?? ('v' . (count($DB[$tabela]) + 1));
        $DB[$tabela][] = $linha;
        return $linha;
    },
    'update' => function ($tabela, $query, $campos) use (&$DB, &$ESCRITAS) {
        $ESCRITAS[] = [$tabela, $query, $campos];
        parse_str($query, $q);
        $id = substr($q['id'] ?? '', 3);
        foreach ($DB[$tabela] as $i => $l) if ($l['id'] === $id) $DB[$tabela][$i] = array_merge($l, $campos);
        return true;
    },
]);

// Retrato da versao v1 gravado na mao, para provar que ler NAO olha as tabelas de edicao.
$DB['po_wa_fluxo_versoes'][] = ['id' => 'v1', 'fluxo_id' => 'f1', 'versao' => 1,
    'grafo' => ['id' => 'f1', 'nome' => 'Boas-vindas', 'inicial' => 'bv', 'humano' => 'hum',
                'porta' => 'mensagem_nova', 'porta_valor' => null, 'cards' => ['bv' => card('bv', 'botoes')]]];
$DB['po_wa_cards'][0]['texto'] = 'MUDOU POR BAIXO';

// 1. wa_fluxo_grafo le o retrato, nao as tabelas de edicao
ok(wa_fluxo_grafo('v1')['inicial'] === 'bv', 'le o grafo da versao');
ok(wa_fluxo_grafo('v1')['cards']['bv']['texto'] === 't', 'o retrato nao acompanha a edicao do rascunho');
ok(wa_fluxo_grafo('nao-existe') === null, 'versao inexistente devolve null');
$antes = $LEITURAS;
ok(wa_fluxo_grafo('') === null, 'versao vazia devolve null');
ok($LEITURAS === $antes, 'versao vazia nao consulta o banco');
$FORA = true;
ok(wa_fluxo_grafo('v1') === null, 'banco fora do ar nao inventa grafo');
$FORA = false;
$DB['po_wa_fluxo_versoes'][0]['grafo'] = json_encode($DB['po_wa_fluxo_versoes'][0]['grafo']);
ok(wa_fluxo_grafo('v1')['inicial'] === 'bv', 'grafo que o banco devolve como texto tambem le');
$DB['po_wa_fluxo_versoes'][0]['grafo'] = '{lixo';
ok(wa_fluxo_grafo('v1') === null, 'grafo corrompido devolve null');
$DB['po_wa_fluxo_versoes'] = [];
$DB['po_wa_cards'][0]['texto'] = 'Oi {nome}';

// 2. publicar com problema NAO grava versao nenhuma
$r = wa_fluxo_publica('f-quebrado');
ok($r['ok'] === false, 'fluxo com ponta solta nao publica');
ok($r['versao'] === null && count($r['problemas']) > 0, 'devolve os problemas');
ok($ESCRITAS === [], 'nada e gravado quando a validacao reprova');
$r = wa_fluxo_publica('nao-existe');
ok($r['ok'] === false && $r['versao'] === null && $ESCRITAS === [], 'fluxo inexistente nao publica');

// 2b. banco fora do ar nao publica nem diz que o fluxo nao existe como se fosse vazio
$FORA = true;
$r = wa_fluxo_publica('f1');
ok($r['ok'] === false && $ESCRITAS === [], 'banco fora do ar: nada e gravado');
ok(count($r['problemas']) > 0 && $r['problemas'][0]['bloqueia'] === true, 'banco fora do ar: explica o motivo');
ok(strpos($r['problemas'][0]['texto'], 'Não consegui ler') === 0, 'banco fora do ar nao vira "o fluxo nao existe"');
$FORA = false;

// 3. publicar valido grava retrato e aponta versao_atual, e NAO liga o fluxo
$r = wa_fluxo_publica('f1');
ok($r['ok'] === true && $r['versao'] === 1, 'primeira publicacao e a versao 1');
$tabelas = array_column($ESCRITAS, 0);
ok(in_array('po_wa_fluxo_versoes', $tabelas, true), 'grava o retrato');
ok(in_array('po_wa_fluxos', $tabelas, true), 'aponta versao_atual');
foreach ($ESCRITAS as $e) {
    if ($e[0] === 'po_wa_fluxos') {
        ok(!array_key_exists('ativo', $e[2]), 'publicar NAO escreve ativo: ligar e outro ato');
        ok(array_keys($e[2]) === ['versao_atual'], 'publicar so mexe em versao_atual');
    }
    if ($e[0] === 'po_wa_fluxo_versoes') {
        ok(!array_key_exists('ativo', $e[1]), 'o retrato nao carrega ativo');
    }
}
ok($DB['po_wa_fluxos'][0]['ativo'] === false, 'o fluxo continua desligado depois de publicar');
$v1 = $DB['po_wa_fluxos'][0]['versao_atual'];
ok($v1 !== null && wa_fluxo_grafo($v1)['inicial'] === 'bv', 'versao_atual aponta o retrato gravado');
ok(wa_fluxo_grafo($v1)['cards']['bv']['texto'] === 'Oi {nome}', 'o retrato leva o texto da edicao');

// 3b. fluxo ja ligado continua ligado ao republicar (publicar nunca desliga tambem)
$DB['po_wa_fluxos'][0]['ativo'] = true;

// 4. publicar de novo cria a versao 2, a 1 continua la, e a conversa antiga segue na dela
$DB['po_wa_cards'][0]['texto'] = 'Ola {nome}, versao nova';
$r = wa_fluxo_publica('f1');
ok($r['ok'] === true && $r['versao'] === 2, 'a segunda publicacao e a versao 2');
ok($DB['po_wa_fluxos'][0]['ativo'] === true, 'republicar nao desliga o fluxo');
$v2 = $DB['po_wa_fluxos'][0]['versao_atual'];
ok($v2 !== $v1, 'versao_atual andou para a nova');
ok(wa_fluxo_grafo($v1)['cards']['bv']['texto'] === 'Oi {nome}', 'conversa presa na versao 1 continua lendo a 1');
ok(wa_fluxo_grafo($v2)['cards']['bv']['texto'] === 'Ola {nome}, versao nova', 'a versao 2 leva a edicao');

// 5. a validacao roda DENTRO de wa_fluxo_publica, nao so no endpoint
$fonte = file_get_contents(__DIR__ . '/../lib/wa-fluxo.php');
ok(preg_match('/function wa_fluxo_publica.*?wa_fluxo_valida\s*\(/s', $fonte),
   'wa_fluxo_publica chama wa_fluxo_valida: nao existe caminho que publique sem validar');
// Travas de fonte no ponto de chamada: a leitura e estrita e publicar nunca cita `ativo`.
preg_match('/function wa_fluxo_publica\b.*?\n}\n/s', $fonte, $m);
$corpo = $m[0] ?? '';
ok($corpo !== '' && strpos($corpo, "'ativo'") === false && strpos($corpo, '"ativo"') === false,
   'wa_fluxo_publica nao escreve `ativo`');
ok(strpos($corpo, 'wa_fl_ler(') !== false && strpos($corpo, "'select'") === false,
   'wa_fluxo_publica le pelo leitor estrito');
ok(preg_match("/function wa_fl_ler\b[^{]*\{[^}]*'select_estrito'/s", $fonte), 'o leitor estrito usa select_estrito');
ok(strpos($fonte, "require_once __DIR__ . '/wa-motor.php'") === false, 'wa-fluxo nao requer o motor');

// 6. wa_fluxo_porta: a ordem anuncio > palavra > mensagem_nova
$fluxos = [
  ['id'=>'fn','gatilho'=>'mensagem_nova','gatilho_valor'=>null,'ativo'=>true,'versao_atual'=>'vn'],
  ['id'=>'fc','gatilho'=>'chamado','gatilho_valor'=>null,'ativo'=>true,'versao_atual'=>'vc'],
  ['id'=>'fp','gatilho'=>'palavra','gatilho_valor'=>'cruzeiro','ativo'=>true,'versao_atual'=>'vp'],
  ['id'=>'fa','gatilho'=>'anuncio','gatilho_valor'=>null,'ativo'=>true,'versao_atual'=>'va'],
];
ok(wa_fluxo_porta(['ad_id'=>'123','texto'=>'cruzeiro'], $fluxos)['id'] === 'fa', 'anuncio ganha de palavra, qualquer que seja a ordem da lista');
ok(wa_fluxo_porta(['ad_id'=>null,'texto'=>'queria um cruzeiro'], $fluxos)['id'] === 'fp', 'palavra ganha de mensagem_nova');
ok(wa_fluxo_porta(['ad_id'=>null,'texto'=>'boa tarde'], $fluxos)['id'] === 'fn', 'sobra mensagem_nova');
ok(wa_fluxo_porta(['ad_id'=>null,'texto'=>'boa tarde'], [$fluxos[1]]) === null, 'porta chamado nunca dispara sozinha');
ok(wa_fluxo_porta(['ad_id'=>null,'texto'=>'x'], [array_merge($fluxos[0], ['ativo'=>false])]) === null, 'fluxo desligado nao atende');
ok(wa_fluxo_porta(['ad_id'=>null,'texto'=>'x'], [array_merge($fluxos[0], ['versao_atual'=>null])]) === null,
   'fluxo ligado e nunca publicado nao atende');
ok(wa_fluxo_porta(['ad_id'=>null,'texto'=>'x'], []) === null, 'sem fluxo nenhum, ninguem atende');
ok(wa_fluxo_porta(['texto'=>'boa tarde'], $fluxos)['id'] === 'fn', 'evento sem ad_id nao quebra');
// anuncio com valor so atende aquele anuncio; sem valor atende qualquer um
$especifico = [['id'=>'fa2','gatilho'=>'anuncio','gatilho_valor'=>'999','ativo'=>true,'versao_atual'=>'v']];
ok(wa_fluxo_porta(['ad_id'=>'123','texto'=>'oi'], $especifico) === null, 'anuncio com valor nao atende outro anuncio');
ok(wa_fluxo_porta(['ad_id'=>'999','texto'=>'oi'], $especifico)['id'] === 'fa2', 'anuncio com valor atende o proprio');
ok(wa_fluxo_porta(['ad_id'=>'','texto'=>'oi'], [$fluxos[3]]) === null, 'ad_id vazio nao e anuncio');
$dois = [$fluxos[3], ['id'=>'fa2','gatilho'=>'anuncio','gatilho_valor'=>'999','ativo'=>true,'versao_atual'=>'v']];
ok(wa_fluxo_porta(['ad_id'=>'999','texto'=>'oi'], $dois)['id'] === 'fa2', 'anuncio especifico ganha do generico');
// palavra casa por palavra inteira, com acento e caixa indiferentes, e nao dentro de outra palavra
ok(wa_fluxo_porta(['ad_id'=>null,'texto'=>'CRUZEIRO?'], $fluxos)['id'] === 'fp', 'palavra ignora caixa e pontuacao');
ok(wa_fluxo_porta(['ad_id'=>null,'texto'=>'cruzeirinho'], $fluxos)['id'] === 'fn', 'palavra nao casa dentro de outra palavra');
ok(wa_fluxo_porta(['ad_id'=>null,'texto'=>'xcruzeiro'], $fluxos)['id'] === 'fn', 'palavra nao casa colada no inicio');
$natal = [['id'=>'fnat','gatilho'=>'palavra','gatilho_valor'=>'Natal','ativo'=>true,'versao_atual'=>'v']];
ok(wa_fluxo_porta(['ad_id'=>null,'texto'=>'taxa de natalidade'], $natal) === null, 'natal nao casa dentro de natalidade');
ok(wa_fluxo_porta(['ad_id'=>null,'texto'=>'mercados de natal!'], $natal)['id'] === 'fnat', 'natal casa como palavra');
$acento = [['id'=>'fac','gatilho'=>'palavra','gatilho_valor'=>'Grécia','ativo'=>true,'versao_atual'=>'v']];
ok(wa_fluxo_porta(['ad_id'=>null,'texto'=>'quero ir pra GRECIA'], $acento)['id'] === 'fac', 'palavra ignora acento');
$varias = [['id'=>'fv','gatilho'=>'palavra','gatilho_valor'=>'cruzeiro, navio; tesouros asiaticos','ativo'=>true,'versao_atual'=>'v']];
ok(wa_fluxo_porta(['texto'=>'tem navio?'], $varias)['id'] === 'fv', 'varias palavras separadas por virgula');
ok(wa_fluxo_porta(['texto'=>'Tesouros Asiáticos'], $varias)['id'] === 'fv', 'expressao de duas palavras casa');
ok(wa_fluxo_porta(['texto'=>'tesouros de la'], $varias) === null, 'expressao so casa inteira');
$semvalor = [['id'=>'fz','gatilho'=>'palavra','gatilho_valor'=>null,'ativo'=>true,'versao_atual'=>'v']];
ok(wa_fluxo_porta(['texto'=>'qualquer coisa'], $semvalor) === null, 'palavra sem valor nunca casa');

echo "test-wa-fluxo-versao: ok\n";
