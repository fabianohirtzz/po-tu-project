<?php
/* ATALHO NO PONTO 1: a PRIMEIRA frase da conversa passa pelos atalhos do
   card inicial, antes de o robo mandar as boas-vindas.

   Por que o ponto 1 existe separado do ponto 2 (tests/test-wa-atalho-rota.php):
   no ponto 2 a conversa ja esta parada num card que espera, e quem roteia e
   wa_fluxo_proximo. No ponto 1 nao ha card esperando - a conversa esta
   nascendo - entao o roteamento passa por wa_processar, que resolve a porta e
   escolhe por onde entrar. Sem isto, "quero saber do Chile" como primeira
   mensagem da vida receberia o menu de boas-vindas e so veria o PDF dois
   toques depois, que e justamente o pedido que originou a Parte A.

   A SAUDACAO E A RAZAO DE ESTA TAREFA NAO SER SO UMA LINHA. Pular o card
   inicial pula tambem a unica apresentacao que a agencia tem: a pessoa
   mandaria "quero o Chile" e a primeira mensagem que recebe na vida seria um
   PDF, sem um "oi" antes. Por isso cada atalho carrega uma `saudacao`,
   escrita pela cliente, enviada ANTES do salto - e vazia salta calado, que e
   decisao dela e nao acidente (Review Focus 4 abaixo mede exatamente isso).

   No ponto 2 a saudacao e IGNORADA: ja houve saudacao naquela conversa, e
   repetir "oi, tudo bem?" no meio do atendimento soa a robo quebrado. */
require __DIR__ . '/../lib/wa-motor.php';
require __DIR__ . '/fixtures/fluxo-motor.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }
/* Os casos de falha de envio registram no log de propósito; sem isto eles
   sujariam a saída da suíte inteira com avisos esperados. */
ini_set('error_log', sys_get_temp_dir() . '/po-test-wa-atalho-inicial.log');

$DB = fx_banco_inicial();
$ENVIADAS = [];
$QUERIES = [];

/* O catalogo deste arquivo tem o CHILE porque e o roteiro do pedido do
   cliente ("quero saber do Chile"). O fixture compartilhado nao o tem, e
   acrescenta-lo la mexeria nas contagens dos outros dois testes de motor. */
function ti_roteiros() {
    return [
        ['slug' => 'chile-e-deserto-do-atacama', 'titulo' => 'Chile e Deserto do Atacama',
         'pdf_url' => 'https://x/c.pdf', 'periodo' => 'De 20/09/27 a 02/10/27', 'ativo' => true],
        ['slug' => 'turquia', 'titulo' => 'Turquia com Antalia',
         'pdf_url' => 'https://x/t.pdf', 'periodo' => 'De 10/05/27 a 25/05/27', 'ativo' => true],
    ];
}

const TI_SAUD = 'Oi! Já te mando o roteiro.';
const TI_WA = '+5548900000001';

/* O evento do webhook, ja normalizado. Proprio deste arquivo: o `ev` de
   tests/test-wa-motor.php mora la e nao no fixture. */
function ev($tipo, $texto, $extra = []) {
    return array_merge(['tipo' => $tipo, 'wa_id' => TI_WA, 'wamid' => 'wamid.' . md5($texto . mt_rand()),
        'tipo_msg' => 'text', 'texto' => $texto, 'nome' => 'Maria',
        'ad_id' => null, 'ctwa_clid' => null, 'ts' => time()], $extra);
}

/* O fluxo de triagem do fixture, com atalhos no card INICIAL. A ordem e a
   recomendada pela spec: pedido explicito por uma pessoa ganha de palpite
   sobre a frase. */
function ti_grafo($saudacao = TI_SAUD) {
    $g = fx_grafo_triagem();
    $g['cards']['boas']['config']['atalhos'] = [
        ['tipo' => 'palavras', 'palavras' => ['atendente', 'falar com uma pessoa'],
         'destino' => 'hum', 'saudacao' => ''],
        ['tipo' => 'roteiro', 'palavras' => [], 'destino' => 'pdf', 'saudacao' => $saudacao],
    ];
    return $g;
}

/* Banco zerado, fluxo publicado com o grafo dado, catalogo deste arquivo. */
function ti_prep($grafo) {
    $db = fx_banco_inicial();
    $db['po_wa_fluxo_versoes'][0]['grafo'] = $grafo;
    $GLOBALS['DB'] = $db;
    $GLOBALS['ENVIADAS'] = [];
    $GLOBALS['QUERIES'] = [];
    fx_instala();
    wa_motor_set_deps(['roteiros' => 'ti_roteiros']);
    wa_motor_set_anuncios([]);
}

/* A primeira mensagem da vida daquele contato. Devolve o que interessa:
   em que card a conversa parou, se o estado chegou a ser gravado, e o que
   saiu. */
function primeira($texto, $grafo = null) {
    ti_prep($grafo === null ? ti_grafo() : $grafo);
    $acao = wa_processar(ev('mensagem', $texto));
    $c = $GLOBALS['DB']['po_wa_conversas'][0] ?? null;
    return ['acao' => $acao, 'card' => $c === null ? null : ($c['card_atual'] ?? null),
            'estado_avancou' => $c !== null && ($c['card_atual'] ?? null) !== null,
            'enviadas' => $GLOBALS['ENVIADAS']];
}

function enviadas($texto, $grafo = null) {
    return primeira($texto, $grafo)['enviadas'];
}

/* O MESMO texto, mas no ponto 2: a conversa ja recebeu as boas-vindas e esta
   parada no card que espera. */
function ponto2($texto) {
    ti_prep(ti_grafo());
    wa_processar(ev('mensagem', 'oi'));          // boas-vindas saem e a conversa espera
    ok(($GLOBALS['DB']['po_wa_conversas'][0]['card_atual'] ?? '') === 'boas', 'cenario do ponto 2: a conversa parou no inicial');
    $GLOBALS['ENVIADAS'] = [];
    $acao = wa_processar(ev('mensagem', $texto));
    $env = $GLOBALS['ENVIADAS'];
    $saud = false;
    foreach ($env as $e) {
        if ($e[0] === 'text' && $e[2] === TI_SAUD) $saud = true;
    }
    return ['acao' => $acao, 'card' => $GLOBALS['DB']['po_wa_conversas'][0]['card_atual'] ?? null,
            'saudacao_enviada' => $saud, 'enviadas' => $env];
}

/* ---------- o atalho dispara na primeira frase ---------- */
ok(primeira('quero falar com uma pessoa')['card'] === 'hum',
    'a primeira frase da vida dispara o atalho de palavras e vai direto para a humana');
ok(primeira('quero falar com uma pessoa')['enviadas'][0][0] === 'text',
    'e o card humano manda o aviso dele (nao as boas-vindas)');
ok(primeira('bom dia')['card'] === 'boas',
    'sem casar atalho nenhum, a conversa comeca no card inicial como hoje');
ok(primeira('bom dia')['enviadas'][0][0] === 'buttons',
    'e recebe as boas-vindas em botoes');

/* Frase vazia (audio, foto, figurinha) nao pode disparar palpite nenhum: nao
   ha frase a interpretar, e o certo e o menu. */
ok(primeira('')['card'] === 'boas', 'mensagem sem texto comeca no inicial');

/* Frase que casa DOIS roteiros nao escolhe (wa_roteiro_unico devolve null):
   mandar o PDF errado e pior que perguntar. */
ok(primeira('quero o chile e a turquia')['card'] === 'boas',
    'frase que nomeia dois roteiros nao dispara o atalho: vai para o menu');

/* ---------- a saudacao sai ANTES do salto ---------- */
$e = enviadas('quero o chile');
ok($e[0][0] === 'text' && $e[0][2] === TI_SAUD, 'a saudacao do atalho sai ANTES do salto (veio: ' . $e[0][0] . ')');
ok($e[1][0] === 'doc' && strpos($e[1][2], 'c.pdf') !== false, 'e so depois dela sai o PDF do roteiro certo');
ok(count($e) === 3, 'saudacao + PDF + a pergunta seguinte da cadeia (veio: ' . count($e) . ')');
ok(primeira('quero o chile')['card'] === 'data', 'a cadeia segue do destino do atalho e para onde ela esperaria');
ok(primeira('quero o chile')['estado_avancou'] === true, 'o estado avancou: o envio saiu');

/* REVIEW FOCUS 4: saudacao VAZIA apontando para um card `pdf`. A pessoa
   recebe um PDF como primeira mensagem da vida. E decisao da cliente (vazia
   = salta calado), e o teste trava os DOIS lados: com saudacao o PDF nao e a
   primeira mensagem, sem ela e. Trocar o lugar da saudacao para depois do
   salto deixaria os dois iguais. */
$ev2 = enviadas('quero o chile', ti_grafo(''));
ok($ev2[0][0] === 'doc', 'saudacao vazia salta CALADO: a primeira mensagem e o PDF (veio: ' . $ev2[0][0] . ')');
ok(count($ev2) === 2, 'e sai uma mensagem menos que com saudacao (veio: ' . count($ev2) . ')');
ok($e[0][0] !== $ev2[0][0], 'com saudacao escrita, o PDF NAO e a primeira mensagem da vida da pessoa');

/* Saudacao que e so espaco conta como vazia: o campo vem de um input de
   painel, e " " ali salta calado em vez de mandar uma bolha em branco. */
ok(enviadas('quero o chile', ti_grafo('   '))[0][0] === 'doc', 'saudacao com so espacos salta calado');

/* ---------- no ponto 2 a saudacao e ignorada ---------- */
$p2 = ponto2('quero o chile');
ok($p2['saudacao_enviada'] === false, 'no ponto 2 a saudacao e ignorada: ja houve saudacao nesta conversa');
ok($p2['card'] === 'data', 'mas o atalho roteia igual: a cadeia do PDF roda');
ok($p2['enviadas'][0][0] === 'doc', 'e a primeira mensagem do passo e o PDF');

/* ---------- lei do envio: estado so avanca se a saudacao saiu ---------- */
ti_prep(ti_grafo());
wa_motor_set_deps(['send_text' => function ($para, $texto) {
    $GLOBALS['ENVIADAS'][] = ['text', $para, $texto];
    return ['ok' => false, 'wamid' => null, 'erro' => 'timeout'];
}]);
$acao = wa_processar(ev('mensagem', 'quero o chile'));
ok($acao === 'falha_envio', 'saudacao que nao saiu interrompe o passo (deu: ' . $acao . ')');
ok(count($ENVIADAS) === 1, 'e o PDF NAO sai depois dela: a cadeia nao comecou (veio: ' . count($ENVIADAS) . ')');
$c = $DB['po_wa_conversas'][0] ?? null;
ok($c !== null, 'a ficha do primeiro contato existe (ela e criada antes do roteamento)');
ok(($c['card_atual'] ?? null) === null, 'mas o card_atual NAO avancou para o destino do atalho');

/* `ok=true` SEM wamid tambem e falha. Hoje wa_envia ja converte resposta sem
   wamid em ok=false, entao isto e cinto e suspensorio - e fica para um
   send_* futuro que nao passe por ele, que e exatamente como um envio
   fantasma entraria sem ninguem notar. */
ti_prep(ti_grafo());
wa_motor_set_deps(['send_text' => function ($para, $texto) {
    $GLOBALS['ENVIADAS'][] = ['text', $para, $texto];
    return ['ok' => true, 'wamid' => null, 'erro' => null];
}]);
$acao = wa_processar(ev('mensagem', 'quero o chile'));
ok($acao === 'falha_envio', 'envio que diz ok mas nao traz wamid tambem interrompe (deu: ' . $acao . ')');
ok(($DB['po_wa_conversas'][0]['card_atual'] ?? null) === null, 'e o estado nao avanca por causa dele');

/* ---------- o atalho VENCE o slug semeado pelo anuncio ----------
   O lead vem de um anuncio do Chile e escreve pedindo a Turquia: ele acabou
   de dizer de qual viagem quer falar, e e essa que vale. Pelo caminho
   inverso, o PDF seria do roteiro do anuncio e nao do que ele pediu. */
ti_prep(ti_grafo());
wa_motor_set_anuncios(['ad-1' => 'chile-e-deserto-do-atacama']);
wa_processar(ev('mensagem', 'queria saber da turquia', ['ad_id' => 'ad-1']));
ok(($DB['po_wa_conversas'][0]['roteiro_slug'] ?? null) === 'turquia',
    'o roteiro nomeado na frase vence o slug do anuncio (veio: ' . (string) ($DB['po_wa_conversas'][0]['roteiro_slug'] ?? 'null') . ')');
ok(strpos((string) ($ENVIADAS[1][2] ?? ''), 't.pdf') !== false, 'e o PDF que sai e o do roteiro pedido');

/* Sem o atalho casar, o slug do anuncio segue valendo: ele e a unica coisa
   que semeia roteiro numa conversa nova. */
ti_prep(ti_grafo());
wa_motor_set_anuncios(['ad-1' => 'chile-e-deserto-do-atacama']);
wa_processar(ev('mensagem', 'bom dia', ['ad_id' => 'ad-1']));
ok(($DB['po_wa_conversas'][0]['roteiro_slug'] ?? null) === 'chile-e-deserto-do-atacama',
    'frase que nao casa atalho nao apaga o roteiro semeado pelo anuncio');

echo "OK test-wa-atalho-inicial\n";
