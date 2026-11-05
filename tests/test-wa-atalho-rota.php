<?php
/* O atalho no PONTO 2: card que espera resposta.
   Tarefa 4 do plano 2026-11-05-robo-entende-a-frase.

   A REGRA QUE NAO SE RELAXA: em card de botoes ou lista, texto digitado
   continua virando string vazia antes do roteamento. O comentario do motor
   diz por que, e a razao segue valida: "se 'turquia' valesse como slug, quem
   escreve em vez de tocar na lista escolheria um roteiro sem querer".

   O que muda e que o texto CRU passa a viajar num parametro separado, e os
   atalhos sao tentados so DEPOIS de as opcoes falharem, antes do fallback.
   Opcao e atalho nunca disputam a mesma string - e isso que o primeiro bloco
   deste arquivo prova. */
require __DIR__ . '/../lib/wa-fluxo.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

$ROTEIROS = [
    ['slug' => 'turquia',                    'titulo' => 'Turquia com Antalia', 'ativo' => true,
     'periodo' => 'De 01/06/40 a 15/06/40', 'preco_label' => 'R$ 1', 'capa_url' => '', 'pdf_url' => 'x.pdf'],
    ['slug' => 'chile-e-deserto-do-atacama', 'titulo' => 'Chile e Deserto do Atacama', 'ativo' => true,
     'periodo' => 'De 01/07/40 a 15/07/40', 'preco_label' => 'R$ 2', 'capa_url' => '', 'pdf_url' => 'y.pdf'],
];
$DESTINOS = [
    ['nome' => 'Itália', 'palavras' => 'italia, roma'],
    ['nome' => 'Egito',  'palavras' => 'egito, cairo'],
];
wa_fluxo_set_deps([
    'roteiros' => function () use ($ROTEIROS) { return $ROTEIROS; },
    'destinos' => function () use ($DESTINOS) { return $DESTINOS; },
]);

function card($id, $tipo, $extra = []) {
    return array_merge([
        'id' => $id, 'fluxo_id' => 'f1', 'tipo' => $tipo, 'titulo' => $id, 'texto' => 'T ' . $id,
        'opcoes' => [], 'config' => [], 'proximo' => null, 'fallback' => null, 'sem_resposta' => null,
        'lembrete_ativo' => false, 'lembrete_texto' => null,
    ], $extra);
}
$fx = ['id' => 'f1', 'nome' => 'T', 'gatilho' => 'mensagem_nova', 'gatilho_valor' => null,
       'ativo' => false, 'card_inicial' => 'bv'];

$ATALHOS = [
    ['tipo' => 'palavras', 'palavras' => ['atendente', 'humano'], 'destino' => 'hum', 'saudacao' => 'Claro!'],
    ['tipo' => 'roteiro',  'destino' => 'pdf'],
    ['tipo' => 'destino',  'destino' => 'semdata'],
];
$cards = [
    card('bv', 'botoes', [
        'opcoes'  => [['id' => 'b1', 'rotulo' => 'Ver roteiros', 'destino' => 'lst']],
        'config'  => ['atalhos' => $ATALHOS],
        'fallback' => 'ops', 'sem_resposta' => 'fim', 'lembrete_ativo' => true, 'lembrete_texto' => 'Oi?']),
    card('sem', 'botoes', [
        'opcoes'  => [['id' => 'b1', 'rotulo' => 'Ver roteiros', 'destino' => 'lst']],
        'fallback' => 'ops', 'sem_resposta' => 'fim', 'lembrete_ativo' => true, 'lembrete_texto' => 'Oi?']),
    card('lst', 'roteiros', ['proximo' => 'pdf', 'fallback' => 'ops', 'lembrete_ativo' => true, 'lembrete_texto' => 'Oi?']),
    card('pdf', 'pdf', ['proximo' => 'fim']),
    card('semdata', 'botoes', [
        'opcoes' => [['id' => 's', 'rotulo' => 'Sim', 'destino' => 'fim']],
        'fallback' => 'hum', 'lembrete_ativo' => true, 'lembrete_texto' => 'Oi?']),
    card('ops', 'botoes', [
        'opcoes' => [['id' => 'v', 'rotulo' => 'Falar', 'destino' => 'hum']],
        'fallback' => 'hum', 'lembrete_ativo' => true, 'lembrete_texto' => 'Oi?']),
    card('hum', 'humano'),
    card('fim', 'fim'),
];
$g = wa_fluxo_grafo_de($fx, $cards);
$ctx0 = ['roteiro_slug' => null, 'tentativas' => 0];

function rota($g, $card, $resposta, $ctx, $cru = '') {
    return wa_fluxo_proximo($g, $card, $resposta, $ctx, $cru);
}

/* ---------- a DISPUTA que nao pode existir (Review Focus 3) ----------
   O clique carrega o id da opcao. Ele ganha SEMPRE, mesmo quando o texto cru
   da mesma mensagem casaria um atalho. */
$r = rota($g, 'bv', 'b1', $ctx0, 'quero a turquia');
ok($r['motivo'] === 'opcao' && $r['card'] === 'lst',
    'clique numa opcao ganha do atalho, mesmo com texto cru que casaria roteiro');

/* ---------- o atalho dispara quando a opcao falha ---------- */
$r = rota($g, 'bv', '', $ctx0, 'quero falar com um atendente');
ok($r['motivo'] === 'atalho' && $r['card'] === 'hum', 'atalho de palavras');

$r = rota($g, 'bv', '', $ctx0, 'quero a turquia');
ok($r['motivo'] === 'atalho' && $r['card'] === 'pdf', 'atalho de roteiro');
ok($r['ctx']['roteiro_slug'] === 'turquia', 'o atalho de roteiro grava o slug no contexto');

$r = rota($g, 'bv', '', $ctx0, 'queria saber da italia');
ok($r['motivo'] === 'atalho' && $r['card'] === 'semdata', 'atalho de destino');
ok($r['ctx']['destino_nome'] === 'Itália', 'o atalho de destino grava o NOME no contexto');
ok(!isset($r['ctx']['roteiro_slug']) || $r['ctx']['roteiro_slug'] === null,
    'atalho de destino NAO inventa slug: o PDF nao teria o que mandar');

/* ---------- a ORDEM e contrato ----------
   palavras, depois roteiro, depois destino. Pedido explicito por uma pessoa
   ganha de palpite; roteiro a venda e resposta melhor que "nao temos data". */
$r = rota($g, 'bv', '', $ctx0, 'quero falar com atendente sobre a turquia');
ok($r['card'] === 'hum', 'com palavras E roteiro na mesma frase, vence o primeiro da lista');

$invertido = $cards;
foreach ($invertido as $i => $c) {
    if ($c['id'] === 'bv') $invertido[$i]['config'] = ['atalhos' => array_reverse($ATALHOS)];
}
$gi = wa_fluxo_grafo_de($fx, $invertido);
$r = rota($gi, 'bv', '', $ctx0, 'quero falar com atendente sobre a turquia');
ok($r['card'] === 'pdf', 'a ordem e DADO: invertida, vence o roteiro. A cliente muda isso no painel');

/* ---------- o fallback nao e roubado ---------- */
$r = rota($g, 'bv', '', $ctx0, 'quanto custa tudo isso');
ok($r['motivo'] === 'fallback' && $r['card'] === 'ops', 'frase que nao casa nada vai ao fallback');
ok($r['ctx']['tentativas'] === 1, 'e conta tentativa, como antes');

$r = rota($g, 'bv', '', $ctx0, 'quero a turquia');
ok(($r['ctx']['tentativas'] ?? 0) === 0,
    'atalho que dispara NAO conta tentativa: foi rota certa, nao resposta fora do esperado');

/* ---------- Review Focus 1: ambiguidade nao dispara atalho ---------- */
$r = rota($g, 'bv', '', $ctx0, 'quero italia e egito');
ok($r['motivo'] === 'fallback', 'dois destinos na frase nao disparam o atalho de destino');
$r = rota($g, 'bv', '', $ctx0, 'quero turquia e chile');
ok($r['motivo'] === 'fallback', 'dois roteiros na frase tambem nao');

/* ---------- card sem atalho se comporta exatamente como antes ---------- */
$r = rota($g, 'sem', '', $ctx0, 'quero a turquia');
ok($r['motivo'] === 'fallback', 'card sem atalho: o texto cru nao muda nada');
$r = rota($g, 'sem', 'b1', $ctx0, 'quero a turquia');
ok($r['motivo'] === 'opcao', 'e a opcao segue valendo');

/* ---------- texto cru vazio nunca dispara atalho ----------
   E o que mantem compativel todo chamador que ainda nao passa o parametro, e
   o que impede o cron (que roteia sem mensagem do cliente) de disparar um
   atalho por conta propria. */
$r = rota($g, 'bv', '', $ctx0, '');
ok($r['motivo'] === 'fallback', 'sem texto cru nao ha atalho a tentar');
$r = wa_fluxo_proximo($g, 'bv', '', $ctx0);
ok($r['motivo'] === 'fallback', 'chamada sem o parametro novo se comporta como antes');

/* ---------- atalho para card que nao existe e ignorado em execucao ----------
   Quem reprova isso e o validador (regra 17), antes de publicar. Em execucao,
   a conversa nao pode parar num card fantasma. */
$quebrado = $cards;
foreach ($quebrado as $i => $c) {
    if ($c['id'] === 'bv') $quebrado[$i]['config'] = ['atalhos' => [['tipo' => 'roteiro', 'destino' => 'nao-existe']]];
}
$gq = wa_fluxo_grafo_de($fx, $quebrado);
$r = rota($gq, 'bv', '', $ctx0, 'quero a turquia');
ok($r['motivo'] === 'fallback', 'destino fantasma nao vira rota: cai no fallback');

/* ---------- card que nao espera nunca olha atalho ---------- */
$comTexto = array_merge($cards, [card('aviso', 'texto', ['proximo' => 'fim', 'config' => ['atalhos' => $ATALHOS]])]);
$gt = wa_fluxo_grafo_de($fx, $comTexto);
$r = rota($gt, 'aviso', '', $ctx0, 'quero a turquia');
ok($r['motivo'] === 'proximo', 'card de texto segue pelo proximo, sem olhar atalho');

/* ---------- o card `roteiros` tem seu proprio casamento, e ele vem primeiro ---------- */
$ctxR = ['roteiro_slug' => null, 'tentativas' => 0, 'roteiros_enviados' => ['turquia', 'chile-e-deserto-do-atacama']];
$cardsR = $cards;
foreach ($cardsR as $i => $c) {
    if ($c['id'] === 'lst') $cardsR[$i]['config'] = ['atalhos' => [['tipo' => 'destino', 'destino' => 'semdata']]];
}
$gr = wa_fluxo_grafo_de($fx, $cardsR);
$r = rota($gr, 'lst', 'turquia', $ctxR, 'turquia');
ok($r['card'] === 'pdf' && $r['ctx']['roteiro_slug'] === 'turquia',
    'no card de roteiros, o casamento de roteiro vem antes do atalho');
$r = rota($gr, 'lst', 'quero a italia', $ctxR, 'quero a italia');
ok($r['motivo'] === 'atalho' && $r['card'] === 'semdata',
    'e o atalho atende quem pede um destino que nao esta na lista');

/* ---------- wa_fluxo_atalho sozinha ---------- */
$a = wa_fluxo_atalho($g, $g['cards']['bv'], 'quero a turquia', $ctx0);
ok($a !== null && $a['tipo'] === 'roteiro' && $a['saudacao'] === '', 'devolve o tipo e a saudacao');
$a = wa_fluxo_atalho($g, $g['cards']['bv'], 'quero um atendente', $ctx0);
ok($a['saudacao'] === 'Claro!', 'a saudacao escrita pela cliente vem junto');
ok(wa_fluxo_atalho($g, $g['cards']['sem'], 'quero a turquia', $ctx0) === null, 'card sem atalho devolve null');
ok(wa_fluxo_atalho($g, $g['cards']['bv'], '', $ctx0) === null, 'texto vazio devolve null');

/* ---------- PALAVRA INTEIRA, nao pedaco ----------
   A classe de defeito que este projeto ja pagou duas vezes: "PO" casando
   dentro de "Poa" na importacao de agenda, e "natal" dentro de "natalidade"
   na porta por palavra. O atalho de `palavras` usa o MESMO wa_fluxo_porta_casa
   da porta justamente para herdar a borda de palavra. Sem ela, quem pergunta
   a taxa de natalidade seria mandado para o atendimento humano. */
$cardsNatal = $cards;
foreach ($cardsNatal as $i => $c) {
    if ($c['id'] === 'bv') $cardsNatal[$i]['config'] = ['atalhos' => [
        ['tipo' => 'palavras', 'palavras' => ['natal'], 'destino' => 'hum', 'saudacao' => ''],
    ]];
}
$gn = wa_fluxo_grafo_de($fx, $cardsNatal);
ok(rota($gn, 'bv', '', $ctx0, 'qual a taxa de natalidade')['motivo'] === 'fallback',
    '`natal` NAO casa dentro de "natalidade"');
ok(rota($gn, 'bv', '', $ctx0, 'quero o mercado de natal')['card'] === 'hum',
    'e casa quando a palavra esta inteira');
/* A tolerancia de plural da casa e so o "s" simples (rtrim), nao plural
   irregular: "natais" NAO casa "natal", e isso e o comportamento existente,
   nao um defeito desta tarefa. O plural regular casa. */
ok(rota($gn, 'bv', '', $ctx0, 'quero os mercados de natais')['motivo'] === 'fallback',
    'plural irregular nao casa: a tolerancia e so o s simples');
ok(rota($g, 'bv', '', $ctx0, 'quero falar com os atendentes')['card'] === 'hum',
    'plural regular casa, pela tolerancia que a porta ja tinha');

/* ---------- a guarda de texto vazio NAO LE O BANCO ----------
   Ela nao muda o resultado (com texto vazio todo casamento falha de todo
   jeito), e por isso a rodada de mutacao a mostrou sobrevivendo. O que ela
   evita e CUSTO: sem ela, cada avanco de card que o robo faz sem mensagem do
   cliente - e o cron faz muitos - dispararia uma consulta a po_destinos para
   nada. */
$lidas = 0;
wa_fluxo_set_deps(['destinos' => function () use ($DESTINOS, &$lidas) { $lidas++; return $DESTINOS; }]);
rota($g, 'bv', '', $ctx0, '');
ok($lidas === 0, 'texto cru vazio nao chega a consultar os destinos');
rota($g, 'bv', '', $ctx0, 'quero a italia');
ok($lidas === 1, 'e com texto ele consulta');
wa_fluxo_set_deps(['destinos' => function () use ($DESTINOS) { return $DESTINOS; }]);

/* ---------- TRAVA NO PONTO DE CHAMADA ----------
   A funcao pura travada e o chamador livre ja foi defeito de verdade neste
   projeto duas vezes (o filtro `wa_id` da conversa, e a Task 4 do plano de
   13/09 que passou verde reintroduzindo o proprio bug). O parametro novo tem
   default '' para os ~60 chamadores de teste seguirem valendo sem reescrita,
   e e justamente o default que permite um chamador de PRODUCAO esquece-lo e
   o atalho nunca disparar, sem erro nenhum em tela.

   Leitura NORMALIZADA no fim de linha: com core.autocrlf=true o regex
   ancorado falharia num dos dois estados do worktree. */
foreach (['wa-motor.php', 'wa-sim.php'] as $arq) {
    $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../lib/' . $arq));
    $todas  = preg_match_all('/wa_fluxo_proximo\s*\(/', $src);
    $quatro = preg_match_all('/wa_fluxo_proximo\s*\([^;]*\$ctx\s*\)/', $src);
    ok($todas > 0, $arq . ' chama wa_fluxo_proximo');
    ok($quatro === 0, $arq . ' nao tem nenhuma chamada de 4 argumentos: toda rota de producao recebe o texto cru (achei ' . $quatro . ')');
}

/* E o motor passa o texto DO CLIENTE no ponto 2, nao string vazia: sem isto
   a trava acima passaria com um '' em todo lugar e o atalho nunca dispararia
   em producao, com a suite inteira verde. */
$motor = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../lib/wa-motor.php'));
ok(preg_match('/wa_fluxo_proximo\(\$grafo, \$atual, \$resposta, \$ctx, \$texto\)/', $motor) === 1,
   'no ponto 2 o motor passa $texto (a frase do cliente), nao vazio');

echo "OK test-wa-atalho-rota\n";
