<?php
/* tests/test-wa-graph.php — a versao da Graph nao pode vencer em silencio.

   Versao expirada da Graph nao devolve erro: a Meta redireciona a chamada
   para outra versao e segue respondendo. Um motor que le `messages[0].id`
   pode entao receber um formato diferente, dar o envio como feito e avancar
   o estado da conversa com o cliente sem nada ter saido.

   Este arquivo e o despertador. Ele fica vermelho 90 dias antes do
   vencimento, com tempo de sobra para conferir os campos que o motor le e
   subir a versao com calma, em vez de descobrir isso por um cliente que
   parou de receber. */

$RAIZ = dirname(__DIR__);
require_once $RAIZ . '/lib/wa-send.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

const DIAS_DE_FOLGA = 90;

/* ------------------------------------------------------------
   1. O URL tem a forma que a concatenacao de wa_envia espera.
------------------------------------------------------------ */
$v = wa_graph_versao();

ok($v !== '', 'WA_GRAPH nao casa com https://graph.facebook.com/vNN.N/ - '
            . 'confira a BARRA FINAL, sem ela o id do numero cola na versao. Valor: ' . WA_GRAPH);

ok(preg_match('/^v\d+\.\d+$/', $v) === 1, 'versao em formato estranho: ' . $v);

// A prova de que a barra final existe: o URL montado de verdade.
$url = WA_GRAPH . '123456789/messages';
ok(strpos($url, '/' . $v . '/123456789/messages') !== false,
   'URL montado saiu errado: ' . $url);

/* ------------------------------------------------------------
   2. O catalogo de validade esta inteiro.

   Data torta faria o despertador tocar na hora errada - ou nunca.
------------------------------------------------------------ */
foreach (WA_GRAPH_VALIDADE as $ver => $data) {
    ok(preg_match('/^v\d+\.\d+$/', $ver) === 1,
       'chave estranha no catalogo de validade: ' . $ver);
    ok($data === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) === 1,
       'data de validade de ' . $ver . ' nao esta em AAAA-MM-DD: ' . $data);
    if ($data !== '') {
        ok(strtotime($data . ' UTC') !== false,
           'data de validade de ' . $ver . ' nao e uma data real: ' . $data);
    }
}

/* ------------------------------------------------------------
   3. A aritmetica do despertador, com datas fabricadas.

   Isto existe para o alarme ser EXERCITADO. A versao pinada hoje nao tem
   data publicada, entao sem estes casos a funcao passaria verde sem nunca
   ter calculado nada.
------------------------------------------------------------ */
ok(wa_graph_dias('2026-10-08', 'v21.0') === 105,
   'contagem errada para v21.0 em 08/10/2026: ' . var_export(wa_graph_dias('2026-10-08', 'v21.0'), true));

ok(wa_graph_dias('2027-01-21', 'v21.0') === 0,
   'no dia do vencimento a conta tem que dar 0');

ok(wa_graph_dias('2027-02-01', 'v21.0') < 0,
   'depois do vencimento a conta tem que ficar negativa');

// Versao fora do catalogo: false, e nao null nem zero. E o caso de alguem
// subir o numero da versao e esquecer de trazer a data nova.
ok(wa_graph_dias('2026-10-08', 'v99.0') === false,
   'versao fora do catalogo tinha que devolver false');

// Data nao publicada e diferente de "nao sei que versao e essa".
ok(wa_graph_dias('2026-10-08', 'v26.0') === null,
   'versao sem data publicada tinha que devolver null');

/* A conta corre em UTC, e este caso e o motivo.
   01/03/2027 -> 08/10/2027 sao 221 dias. Num fuso com horario de verao, as
   duas meia-noites locais ficam a 221 dias MENOS uma hora de distancia, e a
   divisao por 86400 arredonda para 220. Um dia a menos nao derruba uma folga
   de 90, mas quem mexer nesta funcao precisa ver o alarme errar. */
$fuso_antes = date_default_timezone_get();
date_default_timezone_set('America/New_York');   // 14/03/2027 adianta o relogio
ok(wa_graph_dias('2027-03-01', 'v23.0') === 221,
   'a conta saiu do UTC e perdeu um dia no horario de verao: '
 . var_export(wa_graph_dias('2027-03-01', 'v23.0'), true));
date_default_timezone_set($fuso_antes);

/* ------------------------------------------------------------
   4. O DESPERTADOR. Esta e a unica assercao que depende do dia de hoje.
------------------------------------------------------------ */
$dias = wa_graph_dias();

ok($dias !== false,
   'a versao pinada em WA_GRAPH (' . $v . ') nao esta em WA_GRAPH_VALIDADE. '
 . 'Subiu de versao? Traga a data de expiracao dela do changelog da Meta '
 . 'para o catalogo, no mesmo commit.');

if ($dias === null) {
    echo "$v: a Meta ainda nao publicou data de expiracao\n";
} else {
    ok($dias > DIAS_DE_FOLGA,
       'a versao ' . $v . ' da Graph expira em ' . $dias . ' dia(s) ('
     . WA_GRAPH_VALIDADE[$v] . '). HORA DE SUBIR DE VERSAO. Expirar nao da '
     . 'erro: a Meta redireciona em silencio. Antes de trocar o numero, '
     . 'confira no changelog que messages[0].id, error.message e os campos '
     . 'id/status/name/language de message_templates seguem iguais.');
}

echo "test-wa-graph: ok\n";
