<?php
/* Desvio do roteiro de SAIDAS SEMANAIS (reuniao de 09/11/2026 com a Lais).

   O Peru sai toda semana e nao tem uma data para confirmar: o card "A data
   serve?" diria "programada para na data prevista". Um card que NAO espera
   resposta pode ter `config.se_semanal` (id de card): quando o roteiro da
   conversa e semanal, o robo segue por ali em vez do `proximo`.

   Quem sabe se o roteiro e semanal e o executor, que ja le o catalogo; ele
   anota `roteiro_semanal` no contexto e o roteador (wa_fluxo_proximo, que
   nao le catalogo fora do card `roteiros`) so le essa marca. */
require __DIR__ . '/../lib/wa-fluxo.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

function card($id, $tipo, $extra = []) {
    return array_merge(['id' => $id, 'tipo' => $tipo, 'titulo' => $id, 'texto' => 't',
        'opcoes' => [], 'config' => [], 'proximo' => null, 'fallback' => null,
        'sem_resposta' => null, 'lembrete_ativo' => true, 'lembrete_texto' => 'l'], $extra);
}

$grafo = ['id' => 'f1', 'nome' => 'Triagem', 'inicial' => 'mrc', 'humano' => 'hum', 'porta' => 'mensagem_nova', 'porta_valor' => null, 'cards' => [
    'mrc' => card('mrc', 'marca', ['texto' => '', 'config' => ['interesses' => '+roteiro', 'se_semanal' => 'sem'], 'proximo' => 'dt']),
    'dt'  => card('dt', 'botoes', ['opcoes' => [['id' => 's', 'rotulo' => 'Sim', 'destino' => 'hum']], 'fallback' => 'hum', 'sem_resposta' => 'fim']),
    'sem' => card('sem', 'botoes', ['opcoes' => [['id' => 'e', 'rotulo' => 'Falar com a equipe', 'destino' => 'hum']], 'fallback' => 'hum', 'sem_resposta' => 'fim']),
    'hum' => card('hum', 'humano'),
    'fim' => card('fim', 'fim'),
]];

// 1. Roteador: com a marca, o desvio; sem ela, o caminho de sempre.
$r = wa_fluxo_proximo($grafo, 'mrc', '', ['roteiro_slug' => 'peru', 'roteiro_semanal' => true]);
ok($r['card'] === 'sem' && $r['motivo'] === 'proximo', 'roteiro semanal segue o desvio (motivo proximo: motor e simulador seguem andando)');
$r = wa_fluxo_proximo($grafo, 'mrc', '', ['roteiro_slug' => 'turquia', 'roteiro_semanal' => false]);
ok($r['card'] === 'dt', 'roteiro com data segue o proximo');
$r = wa_fluxo_proximo($grafo, 'mrc', '', ['roteiro_slug' => 'turquia']);
ok($r['card'] === 'dt', 'sem a marca no contexto, nunca desvia');

// desvio apontando para card que nao existe: cai no proximo, nao trava a conversa
$g2 = $grafo; $g2['cards']['mrc']['config']['se_semanal'] = 'sumiu';
$r = wa_fluxo_proximo($g2, 'mrc', '', ['roteiro_semanal' => true]);
ok($r['card'] === 'dt', 'desvio para card inexistente cai no proximo');

// card que ESPERA nunca desvia por aqui: a resposta e quem decide
$g3 = $grafo; $g3['cards']['dt']['config']['se_semanal'] = 'sem';
$r = wa_fluxo_proximo($g3, 'dt', 's', ['roteiro_semanal' => true]);
ok($r['card'] === 'hum', 'card que espera ignora o desvio');

// 2. Executor: quem anota a marca, a partir do catalogo.
$ROTEIROS = [
    ['slug' => 'peru',    'titulo' => 'Peru',    'status_viagem' => 'semanal', 'periodo' => ''],
    ['slug' => 'turquia', 'titulo' => 'Turquia', 'status_viagem' => 'em_breve', 'periodo' => 'De 27/10/27 a 12/11/27'],
];
$ESCRITAS = [];
wa_fluxo_set_deps([
    'roteiros'       => function () use (&$ROTEIROS) { return $ROTEIROS; },
    'update'         => function ($t, $q, $c) use (&$ESCRITAS) { $ESCRITAS[] = $c; return true; },
    'select_estrito' => function () { return [['interesses' => []]]; },
]);
$e = wa_fluxo_executa($grafo, 'mrc', '5548999990000', ['roteiro_slug' => 'peru', 'tentativas' => 0], 'Ana');
ok($e['ok'] && $e['ctx']['roteiro_semanal'] === true, 'executor marca o roteiro semanal');
ok(wa_fluxo_proximo($grafo, 'mrc', '', $e['ctx'])['card'] === 'sem', 'e o contexto devolvido leva ao desvio');
$e = wa_fluxo_executa($grafo, 'mrc', '5548999990000', ['roteiro_slug' => 'turquia', 'tentativas' => 0], 'Ana');
ok($e['ctx']['roteiro_semanal'] === false, 'roteiro com data nao e semanal');
ok(wa_fluxo_proximo($grafo, 'mrc', '', $e['ctx'])['card'] === 'dt', 'e segue para a pergunta da data');
$e = wa_fluxo_executa($grafo, 'mrc', '5548999990000', ['roteiro_slug' => null, 'tentativas' => 0], 'Ana');
ok($e['ctx']['roteiro_semanal'] === false, 'sem roteiro, nao e semanal');

// 3. Validador.
$fluxo = ['id' => 'f1', 'nome' => 'Triagem', 'card_inicial' => 'mrc', 'gatilho' => 'mensagem_nova', 'gatilho_valor' => null];
$regras = function ($cards) use ($fluxo) {
    $out = [];
    foreach (wa_fluxo_valida($fluxo, array_values($cards)) as $p) $out[] = [$p['regra'], $p['card'], $p['bloqueia']];
    return $out;
};
$tem = function ($lista, $regra, $card, $bloq = true) {
    foreach ($lista as $p) if ($p[0] === $regra && $p[1] === $card && $p[2] === $bloq) return true;
    return false;
};
$base = $grafo['cards'];
$v = $regras($base);
foreach ($v as $p) ok(!($p[1] === 'sem' && in_array($p[0], [3, 15], true)), 'card alcancado so pelo desvio NAO conta como inalcancavel nem esquecido');
foreach ($v as $p) ok($p[0] !== 18, 'desvio correto nao gera a regra 18');
/* O desvio mora no config do card `marca`, que a regra 10 confere chave a
   chave contra os campos que um fluxo pode gravar. `se_semanal` e caminho,
   nao campo da ficha: nao pode ser lido como tentativa de gravar. Achado
   rodando o validador no rascunho real, nao neste teste. */
foreach ($v as $p) ok($p[0] !== 10, 'o desvio no card marca nao e campo proibido (regra 10)');
ok(!isset(wa_fluxo_campos_marca(['se_semanal' => 'sem', 'interesses' => '+roteiro'], ['roteiro_slug' => 'peru'])['se_semanal']),
   'e nunca vai para o banco como campo da ficha');

$c = $base; $c['mrc']['config']['se_semanal'] = 'sumiu';
ok($tem($regras($c), 18, 'mrc'), 'desvio para card inexistente bloqueia a publicacao');
$c = $base; $c['dt']['config']['se_semanal'] = 'sem';
ok($tem($regras($c), 18, 'dt'), 'desvio em card que espera bloqueia (seria ignorado em silencio)');
$c = $base; $c['mrc']['config']['se_semanal'] = 'mrc';
ok($tem($regras($c), 18, 'mrc'), 'desvio para o proprio card bloqueia');

echo "test-wa-fluxo-semanal OK\n";
