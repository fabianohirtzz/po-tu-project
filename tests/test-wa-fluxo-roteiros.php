<?php
require __DIR__ . '/../lib/wa-fluxo.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }
ini_set('error_log', sys_get_temp_dir() . '/po-test-wa-fluxo-roteiros.log');

$cat = [
/* Datas BEM no futuro: a ordenacao por data usa o dia de hoje, e fixture com
   data perto do presente vira vermelho sozinho quando a data passa. */
  ['slug'=>'vietna','titulo'=>'Vietnã Completo','periodo'=>'De 22/02/40 a 15/03/40','ativo'=>true,'status_viagem'=>null],
  ['slug'=>'turquia','titulo'=>'Turquia com Antália','periodo'=>'De 27/10/39 a 12/11/39','ativo'=>true,'status_viagem'=>'esgotado'],
  ['slug'=>'chile','titulo'=>'Chile e Deserto do Atacama','periodo'=>'25/11/39 a 02/12/39','ativo'=>true,'status_viagem'=>'ultimas_vagas'],
  ['slug'=>'peru','titulo'=>'Peru','periodo'=>'','ativo'=>true,'status_viagem'=>'em_breve'],
  ['slug'=>'japao','titulo'=>'Japão','periodo'=>'x','ativo'=>true,'status_viagem'=>'em_viagem'],
  ['slug'=>'india','titulo'=>'Índia','periodo'=>'y','ativo'=>false,'status_viagem'=>null],
];
$v = wa_fluxo_roteiros_venda($cat);
/* A ordem e a de DATA, nao a de `ordem.asc` que o banco devolve: o Chile
   (25/11/26) parte antes do Vietna (22/02/27), e o Peru, sem data, vai para o
   fim. Ver tests/test-wa-roteiro-ordem.php. */
ok(array_column($v,'slug') === ['chile','vietna','peru'], 'so o que esta a venda, em ordem de data: ' . implode(',', array_column($v,'slug')));

$txt = wa_fluxo_lista_roteiros($v);
ok(strpos($txt, '1.') === 0, 'numera a partir de 1');
ok(strpos($txt, 'Peru') !== false && strpos($txt, '3.') !== false, 'em_breve entra mesmo sem data');
ok(substr_count($txt, "\n") === 2, 'uma linha por roteiro');
ok(strpos($txt, '1. Chile e Deserto do Atacama (25/11/39 a 02/12/39)') === 0, 'linha com titulo e periodo entre parenteses');
ok(substr($txt, -7) === '3. Peru', 'sem periodo, sem parenteses pendurados: ' . $txt);
ok(preg_match('/[\x{2014}\x{2013}]/u', $txt) === 0, 'a lista nao tem travessao nem meia-risca (regra de copy da casa)');

$slugs = array_column($v, 'slug');
ok(wa_fluxo_casa_roteiro('2', $slugs, $v) === 'vietna', 'casa por numero');
ok(wa_fluxo_casa_roteiro('Chile e Deserto do Atacama', $slugs, $v) === 'chile', 'casa por nome completo');
ok(wa_fluxo_casa_roteiro('chile', $slugs, $v) === 'chile', 'casa por parte do nome, sem caixa');
ok(wa_fluxo_casa_roteiro('VIETNA', $slugs, $v) === 'vietna', 'casa sem acento');
ok(wa_fluxo_casa_roteiro('  2  ', $slugs, $v) === 'vietna', 'ignora espaco em volta');
// as que NAO podem escolher
ok(wa_fluxo_casa_roteiro('9', $slugs, $v) === null, 'numero fora da lista nao escolhe');
ok(wa_fluxo_casa_roteiro('1.', $slugs, $v) === 'chile', 'numero com ponto resolve por posicao');
ok(wa_fluxo_casa_roteiro('1)', $slugs, $v) === 'chile', 'numero com parentese resolve por posicao');
ok(wa_fluxo_casa_roteiro('1 - Chile', $slugs, $v) === 'chile', 'numero, hifen e nome resolve por posicao');
ok(wa_fluxo_casa_roteiro('1. Chile e Deserto do Atacama (25/11/39 a 02/12/39)', $slugs, $v) === 'chile', 'a linha copiada inteira resolve por posicao');
ok(wa_fluxo_casa_roteiro('22', $slugs, $v) === null, 'dois digitos sem marcador seguem como numero e nao viram 2');
ok(wa_fluxo_casa_roteiro('3. Vietna', $slugs, $v) === 'peru', 'a posicao vence o nome (nunca escolhe o errado)');
ok(wa_fluxo_casa_roteiro('0', $slugs, $v) === null, 'zero nao escolhe');
ok(wa_fluxo_casa_roteiro('', $slugs, $v) === null, 'resposta vazia nao escolhe');
ok(wa_fluxo_casa_roteiro('bom dia', $slugs, $v) === null, 'frase qualquer nao escolhe');
ok(wa_fluxo_casa_roteiro('a', $slugs, $v) === null, 'uma letra que casa tudo nao escolhe');
// ambiguidade: duas viagens com "tesouros" no nome
$amb = [['slug'=>'t1','titulo'=>'Tesouros do Oriente','periodo'=>'','ativo'=>true,'status_viagem'=>null],
        ['slug'=>'t2','titulo'=>'Tesouros Asiáticos','periodo'=>'','ativo'=>true,'status_viagem'=>null]];
ok(wa_fluxo_casa_roteiro('tesouros', array_column($amb,'slug'), $amb) === null, 'parte ambigua nao escolhe');
ok(wa_fluxo_casa_roteiro('asiáticos', array_column($amb,'slug'), $amb) === 't2', 'parte que distingue escolhe');
// o numero vale pela lista ENVIADA, nao pela de agora
$agora = [$cat[2]];  // so o Chile sobrou
ok(wa_fluxo_casa_roteiro('1', ['vietna','chile','peru'], $agora) === 'vietna',
   'o numero 1 e o que foi enviado como 1, ainda que o catalogo tenha mudado');
// nome so casa com o que foi enviado: roteiro que a pessoa nunca viu nao vale
ok(wa_fluxo_casa_roteiro('peru', ['vietna','chile'], $v) === null, 'nome de roteiro que nao estava na lista enviada nao escolhe');

// exato antes de contem: "Peru" e "Peru e Bolivia"
$pb = [['slug'=>'p1','titulo'=>'Peru','periodo'=>'','ativo'=>true,'status_viagem'=>null],
       ['slug'=>'p2','titulo'=>'Peru e Bolívia','periodo'=>'','ativo'=>true,'status_viagem'=>null]];
ok(wa_fluxo_casa_roteiro('peru', ['p1','p2'], $pb) === 'p1', 'titulo igual vence o que so contem');
ok(wa_fluxo_casa_roteiro('bolivia', ['p1','p2'], $pb) === 'p2', 'trecho do titulo maior ainda casa');
// linha de catalogo sem titulo nao gera warning
$av = []; set_error_handler(function ($n, $m) use (&$av) { $av[] = $m; return true; });
wa_fluxo_lista_roteiros([['slug' => 'x']]);
restore_error_handler();
ok($av === [], 'linha sem titulo nao gera warning: ' . implode('|', $av));

// quebras de linha escritas como escape, nunca como quebra literal no fonte (autocrlf)
$fonte = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../lib/wa-fluxo.php'));
ok(strpos($fonte, 'implode("\n", $linhas)') !== false, 'a lista junta com \n escapado');
ok(substr_count($fonte, '"\n\n" . wa_fluxo_lista_roteiros(') === 2, 'corpo e lista separados por \n\n escapado, nos dois pontos');
ok(strpos($fonte, 'WA_ROTEIROS_TEXTO_MAX') !== false && strpos($fonte, "mb_strlen(\$texto, 'UTF-8') > 4096") === false, 'o teto do envio tem nome, sem numero magico');

/* ---------- execucao e roteamento ---------- */
function card($id, $tipo, $extra = []) {
    return array_merge(['id' => $id, 'tipo' => $tipo, 'titulo' => $id, 'texto' => 'Qual viagem?',
        'opcoes' => [], 'config' => [], 'proximo' => null, 'fallback' => null,
        'sem_resposta' => null, 'lembrete_ativo' => false, 'lembrete_texto' => null], $extra);
}
$g = ['id' => 'f1', 'nome' => 'T', 'inicial' => 'rot', 'humano' => 'hum', 'porta' => 'mensagem_nova', 'porta_valor' => null, 'cards' => [
  'rot'  => card('rot', 'roteiros', ['proximo' => 'pdf', 'fallback' => 'erro']),
  // opcao largada cujo id/rotulo casaria com a resposta: nunca pode valer
  'rotop' => card('rotop', 'roteiros', ['proximo' => 'pdf', 'fallback' => 'erro',
      'opcoes' => [['id' => 'sim', 'rotulo' => 'sim', 'destino' => 'longe']]]),
  'pdf'  => card('pdf', 'pdf', ['proximo' => 'hum']),
  'erro' => card('erro', 'texto', ['texto' => 'Nao entendi', 'proximo' => 'rot']),
  'longe' => card('longe', 'texto', ['texto' => 'longe', 'proximo' => 'hum']),
  'hum'  => card('hum', 'humano', ['texto' => 'Ja chamo']),
]];
$ENV = []; $CAT = $cat;
wa_fluxo_set_deps([
  'roteiros'  => function () use (&$CAT) { return $CAT; },
  'send_text' => function ($p, $t) use (&$ENV) { $ENV[] = $t; return ['ok' => true, 'wamid' => 'w1']; },
  'insert'    => function () { return true; },
  'update'    => function () { return true; },
  'select'    => function () { return []; },
  'select_estrito' => function () { return []; },
]);
$fone = '+5548900000001';
$c0 = ['roteiro_slug' => null, 'tentativas' => 0];

$r = wa_fluxo_executa($g, 'rot', $fone, $c0, 'Marta');
ok($r['ok'] === true && $r['espera'] === true, 'card roteiros espera a resposta');
ok(strpos($ENV[0], 'Qual viagem?') === 0, 'corpo do card vem primeiro');
ok(strpos($ENV[0], $txt) !== false, 'seguido da lista numerada');
ok(strpos($ENV[0], 'Turquia') === false, 'esgotado nao aparece');
ok($r['ctx']['roteiros_enviados'] === ['chile','vietna','peru'], 'grava a ordem enviada no contexto, que e a de data');

// teto de 4096: corta ITENS INTEIROS do fim, e a ordem enviada acompanha
$ENV = [];
$item = function ($i) { return "$i. Roteiro numero $i com nome razoavelmente comprido (De 01/01/27 a 15/01/27)"; };
$grande = [];
for ($i = 1; $i <= 200; $i++) $grande[] = ['slug'=>"r$i",'titulo'=>"Roteiro numero $i com nome razoavelmente comprido",'periodo'=>'De 01/01/27 a 15/01/27','ativo'=>true,'status_viagem'=>null];
$CAT = $grande;
$r = wa_fluxo_executa($g, 'rot', $fone, $c0, 'Marta');
$env = $ENV[0] ?? '';
ok($r['ok'] === true && mb_strlen($env, 'UTF-8') <= 4096, 'catalogo enorme cabe em 4096: ' . mb_strlen($env, 'UTF-8'));
$n = count($r['ctx']['roteiros_enviados']);
ok($n >= 50 && $n <= 52,'cortou ate caber, nem mais nem menos: ' . $n);
$linhas = explode("\n", $env);
ok(end($linhas) === $item($n), 'termina num item inteiro, o ultimo de roteiros_enviados: ' . end($linhas));
ok($r['ctx']['roteiros_enviados'] === array_map(function ($i) { return "r$i"; }, range(1, $n)), 'os cortados saem de roteiros_enviados, numeros e slugs alinhados');
ok(mb_strlen($env . "\n" . $item($n + 1), 'UTF-8') > 4096, 'cortou so o necessario: mais um item estouraria');
$CAT = $cat; $ENV = [];
$r = wa_fluxo_executa($g, 'rot', $fone, $c0, 'Marta');
ok(count($r['ctx']['roteiros_enviados']) === 3, 'catalogo normal nao perde nenhum item');

// corpo do card que sozinho nao deixa espaco: permanente, vai para a humana
$gg = $g; $gg['cards']['rot']['texto'] = str_repeat('x', 4090);
$ENV = [];
$r = wa_fluxo_executa($gg, 'rot', $fone, $c0, 'Marta');
ok($r['ok'] === false && $r['terminal'] === 'humano' && $ENV === [], 'nem um item cabe: recusa permanente, humana, nada enviado');

// catalogo vazio: erro transitorio, nunca lista vazia
$ENV = []; $CAT = [];
$r = wa_fluxo_executa($g, 'rot', $fone, $c0, 'Marta');
ok($r['ok'] === false && $r['terminal'] === null, 'catalogo vazio e falha transitoria, nao vai para a humana');
ok($ENV === [], 'catalogo vazio nao manda mensagem nenhuma');
// tudo esgotado: tambem nao manda lista vazia
$CAT = [$cat[1], $cat[4]];
$r = wa_fluxo_executa($g, 'rot', $fone, $c0, 'Marta');
ok($r['ok'] === false && $ENV === [], 'nada a venda nao manda lista vazia');
$CAT = $cat;

// catalogo vazio no roteamento com lista ja enviada: loga, nao e erro da pessoa
@unlink(sys_get_temp_dir() . '/po-test-wa-fluxo-roteiros.log');
$CAT = [];
$p = wa_fluxo_proximo($g, 'rot', 'chile', ['roteiro_slug' => null, 'tentativas' => 0, 'roteiros_enviados' => ['vietna','chile']]);
ok($p['motivo'] === 'fallback', 'catalogo fora do ar: cai no fallback');
ok(strpos((string) @file_get_contents(sys_get_temp_dir() . '/po-test-wa-fluxo-roteiros.log'), 'catalogo vazio') !== false, 'e registra que foi o catalogo, nao a pessoa');
$CAT = $cat;

// roteamento: o numero vale pela ordem enviada que esta no contexto
$ctx = ['roteiro_slug' => null, 'tentativas' => 0, 'roteiros_enviados' => ['vietna','chile','peru']];
$p = wa_fluxo_proximo($g, 'rot', '3', $ctx);
ok($p['card'] === 'pdf' && $p['ctx']['roteiro_slug'] === 'peru' && $p['motivo'] === 'escolheu', 'numero escolhe e grava roteiro_slug');
$p = wa_fluxo_proximo($g, 'rot', 'vietna', $ctx);
ok($p['card'] === 'pdf' && $p['ctx']['roteiro_slug'] === 'vietna', 'nome escolhe (o catalogo vem do injetor)');
$p = wa_fluxo_proximo($g, 'rot', 'bom dia', $ctx);
ok($p['card'] === 'erro' && $p['motivo'] === 'fallback', 'nao casou: fallback');
ok($p['ctx']['roteiros_enviados'] === ['vietna','chile','peru'], 'a ordem enviada sobrevive ao fallback, senao a segunda tentativa perde o numero');
// sem lista enviada no contexto nada casa
$p = wa_fluxo_proximo($g, 'rot', '1', ['roteiro_slug' => null, 'tentativas' => 0]);
ok($p['motivo'] === 'fallback', 'sem lista enviada, numero nao escolhe');

// a opcao largada NUNCA e consultada, nem como segunda tentativa
$p = wa_fluxo_proximo($g, 'rotop', 'sim', $ctx);
ok($p['card'] === 'erro' && $p['motivo'] === 'fallback', 'resposta que casa a opcao largada vai para o fallback, nao para o destino dela: ' . json_encode($p));
$p = wa_fluxo_proximo($g, 'rotop', '2', $ctx);
ok($p['card'] === 'pdf' && $p['ctx']['roteiro_slug'] === 'chile', 'com opcao largada, o roteiro ainda escolhe');

// o motor leva a coluna na ida e na volta
$motor = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../lib/wa-motor.php'));
ok(preg_match('/\$ctx = \[\'roteiro_slug\'.{0,300}?\'roteiros_enviados\'/s', $motor) === 1, 'o motor monta roteiros_enviados no contexto');
ok(preg_match('/\$grava = \[.{0,300}?\'roteiros_enviados\'/s', $motor) === 1, 'o motor grava roteiros_enviados de volta');
$mig = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../supabase/migrations/2026-10-30-roteiros-enviados.sql'));
ok(stripos($mig, 'add column if not exists roteiros_enviados jsonb not null default') !== false, 'a migration e aditiva e idempotente');
$sch = file_get_contents(__DIR__ . '/test-wa-schema.php');
ok(strpos($sch, "'roteiros_enviados'") !== false, 'a sonda de schema confere a coluna');

echo "OK test-wa-fluxo-roteiros\n";
