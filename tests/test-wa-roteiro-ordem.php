<?php
require __DIR__ . '/../lib/wa-fluxo.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

/* ============================================================
   A data do roteiro sai do texto que a cliente ja escreve, e a lista que o
   robo manda no chat vai da viagem mais proxima para a mais distante.

   Os formatos abaixo sao os 4 reais, medidos nos 7 roteiros do banco em
   03/11/2026. Nenhum dado de cliente: so os campos do roteiro.
============================================================ */

/* ---------- wa_roteiro_data_ini: le a primeira data d/m/a valida ---------- */

ok(wa_roteiro_data_ini(['periodo' => 'De 22/02/2027 a 15/03/2027']) === '2027-02-22', 'ano de 4 digitos');
ok(wa_roteiro_data_ini(['periodo' => 'De 27/10/26 à 12/11/26']) === '2026-10-27', 'ano de 2 digitos vira 20xx, e o "a" com acento nao estorva');
ok(wa_roteiro_data_ini(['periodo' => '25/11/26 a 02/12/26']) === '2026-11-25', 'sem a palavra "De" na frente');
ok(wa_roteiro_data_ini(['periodo' => 'De 18/03/27 à 09/04/27']) === '2027-03-18', 'o quarto formato do banco');

/* A ordem dos campos: periodo primeiro porque e onde a data esta nos 7
   roteiros; descricao_curta por ultimo porque nela a data existe em 1 so
   (nos outros 6 e texto de venda). */
ok(wa_roteiro_data_ini(['periodo' => '', 'descricao_curta' => 'De 22/02/27 à 15/03/27']) === '2027-02-22', 'cai na descricao curta quando o periodo nao tem data');
ok(wa_roteiro_data_ini(['periodo' => '', 'data_label' => '10/01/28', 'descricao_curta' => '01/02/28']) === '2028-01-10', 'data_label vem antes da descricao curta');
ok(wa_roteiro_data_ini(['periodo' => '25/11/26 a 02/12/26', 'descricao_curta' => '01/01/26']) === '2026-11-25', 'o periodo vence a descricao curta');

/* Sem data que o parser leia, e com data impossivel. */
ok(wa_roteiro_data_ini(['periodo' => 'Fevereiro de 2027']) === null, 'mes escrito em palavra nao e data');
ok(wa_roteiro_data_ini(['periodo' => '']) === null, 'periodo vazio');
ok(wa_roteiro_data_ini([]) === null, 'roteiro sem nenhum dos campos');
ok(wa_roteiro_data_ini(['periodo' => null]) === null, 'periodo nulo');
ok(wa_roteiro_data_ini(['periodo' => '31/02/26 a 02/03/26']) === '2026-03-02', 'data impossivel e descartada e a busca continua na mesma string');
ok(wa_roteiro_data_ini(['periodo' => '31/02/26', 'data_label' => '05/05/27']) === '2027-05-05', 'descartada a ultima da string, a busca passa para o campo seguinte');
ok(wa_roteiro_data_ini(['periodo' => '00/01/27 a 15/01/27']) === '2027-01-15', 'dia zero nao existe');
ok(wa_roteiro_data_ini(['periodo' => '15/13/27']) === null, 'mes 13 nao existe');

/* ---------- wa_fluxo_ordena_roteiros: crescente, sem data no fim, estavel ---------- */

function r($slug, $periodo) { return ['slug' => $slug, 'titulo' => $slug, 'periodo' => $periodo, 'ativo' => true, 'status_viagem' => null]; }
function slugs($lista) { return implode(',', array_column($lista, 'slug')); }

$vietna  = r('vietna',  'De 22/02/27 à 15/03/27');   // 2027-02-22
$chile   = r('chile',   '25/11/26 a 02/12/26');      // 2026-11-25
$turquia = r('turquia', 'De 27/10/26 à 12/11/26');   // 2026-10-27

// A entrada vem na ordem de ordem.asc, que e o que o banco devolve hoje.
ok(slugs(wa_fluxo_ordena_roteiros([$vietna, $chile])) === 'chile,vietna', 'a mais proxima vem primeiro');
ok(slugs(wa_fluxo_ordena_roteiros([$vietna, $chile, $turquia])) === 'turquia,chile,vietna', 'tres em ordem crescente');
ok(slugs(wa_fluxo_ordena_roteiros([$turquia, $chile, $vietna])) === 'turquia,chile,vietna', 'ja ordenada continua igual');

$a = r('a', 'Fevereiro de 2027');
$c = r('c', '');
ok(slugs(wa_fluxo_ordena_roteiros([$a, $chile, $c])) === 'chile,a,c', 'sem data vai para o fim, na ordem de origem');
ok(slugs(wa_fluxo_ordena_roteiros([$c, $a])) === 'c,a', 'todos sem data mantem a ordem de origem');

/* Empate: a ordem entre dois roteiros da MESMA data tem que ser sempre a
   mesma, senao o numero que a pessoa leu muda de roteiro entre o envio e a
   resposta. Quem desfaz e a posicao de origem. */
$x = r('x', '25/11/26 a 02/12/26');
$y = r('y', '25/11/26 a 10/12/26');
ok(slugs(wa_fluxo_ordena_roteiros([$x, $y])) === 'x,y', 'empate desfeito pela posicao de origem');
ok(slugs(wa_fluxo_ordena_roteiros([$y, $x])) === 'y,x', 'e invertendo a entrada, inverte a saida (nao e alfabetica por acidente)');

ok(wa_fluxo_ordena_roteiros([]) === [], 'lista vazia');
ok(array_keys(wa_fluxo_ordena_roteiros([$vietna, $chile])) === [0, 1], 'devolve lista reindexada');

/* ---------- o invariante: numero e slug concordam ---------- */

$cat = [
  ['slug'=>'vietna','titulo'=>'Vietnã Completo','periodo'=>'De 22/02/27 a 15/03/27','ativo'=>true,'status_viagem'=>null],
  ['slug'=>'chile','titulo'=>'Chile e Deserto do Atacama','periodo'=>'25/11/26 a 02/12/26','ativo'=>true,'status_viagem'=>'ultimas_vagas'],
  ['slug'=>'turquia','titulo'=>'Turquia com Antália','periodo'=>'De 27/10/26 a 12/11/26','ativo'=>true,'status_viagem'=>null],
  ['slug'=>'peru','titulo'=>'Peru','periodo'=>'','ativo'=>true,'status_viagem'=>'em_breve'],
];
$venda = wa_fluxo_roteiros_venda($cat);
ok(slugs($venda) === 'turquia,chile,vietna,peru', 'roteiros_venda devolve a lista JA ordenada: ' . slugs($venda));

$txt = wa_fluxo_lista_roteiros($venda);
ok(strpos($txt, '1. Turquia com Antália') === 0, 'o 1 da lista e a viagem mais proxima: ' . $txt);
$slugs_enviados = array_column($venda, 'slug');
ok(wa_fluxo_casa_roteiro('2', $slugs_enviados, $venda) === 'chile', 'o 2 da lista e o mesmo slug que o texto numerou');
ok(wa_fluxo_casa_roteiro('3', $slugs_enviados, $venda) === 'vietna', 'e o 3 tambem');
/* O que nao tem data nunca furou a fila. */
ok(!in_array('peru', array_slice($slugs_enviados, 0, 3), true), 'sem data nao furou a fila');

/* ---------- trava de fonte: a ordenacao entra em dois pontos, e so neles ---------- */

$src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../lib/wa-fluxo.php'));
$n = preg_match_all('/wa_fluxo_ordena_roteiros\s*\(/', $src, $m);
// 1 na definicao + 1 em wa_fluxo_roteiros_venda + 1 na closure $roteiros.
ok($n === 3, 'wa_fluxo_ordena_roteiros aparece 3 vezes em wa-fluxo.php (definicao + os 2 pontos de chamada), achei ' . $n);
ok(preg_match('/function wa_fluxo_roteiros_venda\s*\([^)]*\)\s*\{(?:(?!\n\})[\s\S])*wa_fluxo_ordena_roteiros\s*\(/', $src) === 1,
   'wa_fluxo_roteiros_venda ordena antes de devolver');
ok(preg_match('/\$roteiros = function \(\) use \(&\$catalogo\) \{(?:(?!\n    \})[\s\S])*wa_fluxo_ordena_roteiros\s*\(/', $src) === 1,
   'a closure $roteiros de wa_fluxo_executa devolve o catalogo ja ordenado (o card lista legado herda a regra sem uma segunda chamada espalhada)');
/* Toda lista que vai para o cliente passa por wa_fluxo_roteiros_venda ou pela
   closure. Um wa_fl_call de roteiros solto montando lista seria uma terceira
   ordem possivel. */
$n_call = preg_match_all('/wa_fl_call\(\x27roteiros\x27\)/', $src, $m2);
ok($n_call === 2, 'wa_fl_call de roteiros aparece 2 vezes em wa-fluxo.php (uma em wa_fluxo_proximo, dentro de wa_fluxo_roteiros_venda, e uma na closure), achei ' . $n_call);
ok(preg_match('/wa_fluxo_roteiros_venda\(wa_fl_call\(\x27roteiros\x27\)\)/', $src) === 1,
   'o wa_fl_call de roteiros de wa_fluxo_proximo passa por wa_fluxo_roteiros_venda');

echo "ok\n";
