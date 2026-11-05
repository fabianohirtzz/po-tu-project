<?php
/* Ordem cronologica em TODO lugar que mostra roteiro, e o roteiro vencido
   saindo de cena sozinho. Pedido do cliente em 05/11/2026:

     "em todo site, todos roteiros devem seguir a ordem cronologica de suas
      datas, e o roteiro do peru fica por ultimo pois nao tem data definida,
      puxa a data do campo Periodo (texto)"
     "se venceu ja entra como oculto automaticamente, tem como claude?
      considerar dai o ultimo dia da viagem se possivel"

   DUAS DECISOES DE DESENHO QUE ESTE ARQUIVO GUARDA:

   1. UMA funcao de data para o site e para o robo. Antes havia duas, e elas
      DIVERGIAM na ordem dos campos: po_data_saida (no index.php) lia
      `data_label` primeiro, e wa_roteiro_data_ini lia `periodo` primeiro.
      Ordem diferente = ordens diferentes na home e na conversa do robo. O
      cliente pediu `periodo`, e a medicao dos 7 roteiros concorda: os 7 tem
      data ali.

   2. Vencido e OCULTADO POR DERIVACAO, nunca escrevendo ativo=false no banco.
      Assim a cliente continua vendo o roteiro no painel para reativar na
      proxima edicao, nao ha cron, e nao ha como o site e o painel
      discordarem sobre o que esta publicado.

   O QUE VENCE E O ULTIMO DIA, nao o primeiro: "De 27/10/26 a 12/11/26" em
   05/11/2026 e uma viagem EM CURSO, nao uma viagem vencida. Medido nos 7
   roteiros reais no dia do pedido. */
require __DIR__ . '/../lib/po-datas.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

function rot($extra = []) {
    return array_merge(['slug' => 's', 'periodo' => '', 'data_label' => '', 'descricao_curta' => '',
                        'status_viagem' => null, 'datas_texto' => []], $extra);
}

/* ---------- po_data_saida: a PRIMEIRA data, de `periodo` primeiro ---------- */
ok(po_data_saida(rot(['periodo' => 'De 27/10/26 à 12/11/26'])) === '2026-10-27', 'primeira data do periodo');
ok(po_data_saida(rot(['periodo' => 'De 22/02/2027 a 15/03/2027'])) === '2027-02-22', 'ano de 4 digitos');
ok(po_data_saida(rot(['periodo' => '25/11/26 a 02/12/26'])) === '2026-11-25', 'periodo sem a palavra "De"');
ok(po_data_saida(rot([])) === null, 'sem data nenhuma devolve null');
ok(po_data_saida(rot(['periodo' => 'em breve'])) === null, 'texto sem data devolve null');
ok(po_data_saida('nao e array') === null, 'entrada que nao e roteiro nao estoura');

/* `periodo` GANHA de `data_label`. Era aqui que as duas implementacoes
   discordavam, e o cliente foi explicito sobre qual vale. */
ok(po_data_saida(rot(['periodo' => '10/01/27 a 20/01/27', 'data_label' => '05/05/27'])) === '2027-01-10',
    'periodo vence data_label: a divergencia que existia entre o site e o robo');
ok(po_data_saida(rot(['data_label' => '05/05/27'])) === '2027-05-05', 'data_label serve quando periodo nao tem data');
ok(po_data_saida(rot(['descricao_curta' => '07/07/27'])) === '2027-07-07', 'descricao_curta e o ultimo recurso');

/* Data impossivel e descartada e a busca continua - sem isso um erro de
   digitacao no painel manda a viagem para o lugar errado da fila. */
ok(po_data_saida(rot(['periodo' => 'De 31/02/27 a 10/03/27'])) === '2027-03-10', '31/02 e descartada, vale a seguinte');

/* ---------- po_data_fim: a ULTIMA data do mesmo campo ---------- */
ok(po_data_fim(rot(['periodo' => 'De 27/10/26 à 12/11/26'])) === '2026-11-12', 'ultima data do periodo');
ok(po_data_fim(rot(['periodo' => 'De 22/02/2027 a 15/03/2027'])) === '2027-03-15', 'ano de 4 digitos');
ok(po_data_fim(rot(['periodo' => '10/01/27'])) === '2027-01-10', 'uma data so: o fim e o proprio inicio');
ok(po_data_fim(rot([])) === null, 'sem data devolve null');
ok(po_data_fim(rot(['periodo' => 'De 01/01/27 a 05/01/27 a 09/01/27'])) === '2027-01-09', 'com tres datas vale a ultima');
/* Mesmo CAMPO do inicio: nao se mistura o inicio do periodo com uma data
   perdida na descricao de venda. */
ok(po_data_fim(rot(['periodo' => '10/01/27 a 20/01/27', 'descricao_curta' => '31/12/27'])) === '2027-01-20',
    'o fim sai do MESMO campo que deu o inicio');

/* ---------- po_roteiro_vencido: vence depois do ULTIMO dia ---------- */
$turquia = rot(['periodo' => 'De 27/10/26 à 12/11/26']);
ok(po_roteiro_vencido($turquia, '2026-11-05') === false, 'viagem EM CURSO nao esta vencida (foi o caso real em 05/11/2026)');
ok(po_roteiro_vencido($turquia, '2026-11-12') === false, 'no ULTIMO dia ainda nao venceu');
ok(po_roteiro_vencido($turquia, '2026-11-13') === true,  'no dia seguinte ao ultimo, venceu');
ok(po_roteiro_vencido($turquia, '2026-10-01') === false, 'antes de comecar, claro que nao');
ok(po_roteiro_vencido(rot([]), '2030-01-01') === false, 'roteiro SEM data nunca vence: nao ha o que vencer');

/* ---------- saidas semanais: nunca vencem, e sem data vao para o fim ---------- */
$peru_sem_data = rot(['status_viagem' => 'semanal']);
ok(po_data_saida($peru_sem_data) === null, 'semanal sem data cadastrada nao tem data');
ok(po_roteiro_vencido($peru_sem_data, '2030-01-01') === false, 'semanal nunca vence: ele sai toda semana');

$peru_com_data = rot(['status_viagem' => 'semanal', 'datas_texto' => [
    ['titulo' => 'Datas de Dezembro', 'datas' => ['02/12/26', '09/12/26']],
]]);
ok(po_data_saida($peru_com_data, '2026-11-05') === '2026-12-02', 'semanal COM data usa a proxima saida');
ok(po_roteiro_vencido($peru_com_data, '2030-01-01') === false, 'e mesmo com datas antigas ele nao vence');

/* E o caso que a rodada de mutacao achou sem cobertura: semanal cujo campo
   Periodo TEM datas escritas. A cliente pode escrever "saidas de 02/12/26 a
   30/12/26" ali, e sem a guarda de `semanal` em po_data_fim o roteiro
   ganharia um fim e sumiria do site depois dele - um roteiro que sai TODA
   SEMANA desaparecendo porque o texto de um mes passado venceu. */
$peru_periodo = rot(['status_viagem' => 'semanal', 'periodo' => 'saidas de 02/12/26 a 30/12/26']);
ok(po_data_fim($peru_periodo) === null, 'semanal nao tem fim nem quando o Periodo tem datas');
ok(po_roteiro_vencido($peru_periodo, '2027-06-01') === false, 'e por isso nao vence');
ok(po_data_saida($peru_periodo) === '2026-12-02', 'mas a PARTIDA ainda sai do Periodo quando nao ha datas do mes');

/* ---------- po_por_saida: a ordem ---------- */
$lista = [
    rot(['slug' => 'grecia',       'periodo' => 'De 18/05/27 a 03/06/27']),
    rot(['slug' => 'peru',         'status_viagem' => 'semanal']),
    rot(['slug' => 'turquia',      'periodo' => 'De 27/10/26 à 12/11/26']),
    rot(['slug' => 'vietna',       'periodo' => 'De 22/02/2027 a 15/03/2027']),
    rot(['slug' => 'chile',        'periodo' => '25/11/26 a 02/12/26']),
];
$ordem = array_column(po_por_saida($lista), 'slug');
ok($ordem === ['turquia', 'chile', 'vietna', 'grecia', 'peru'],
    'cronologica, e o sem data por ultimo: ' . implode(', ', $ordem));

/* Empate desfeito pela posicao de ORIGEM, que e a ordem manual do painel.
   Sem isso a ordem muda a cada leitura e o numero que o robo manda na lista
   aponta um roteiro diferente do que ele enviou. */
$empate = [
    rot(['slug' => 'b', 'periodo' => '10/01/27']),
    rot(['slug' => 'a', 'periodo' => '10/01/27']),
];
ok(array_column(po_por_saida($empate), 'slug') === ['b', 'a'], 'empate mantem a ordem do painel');

/* Dois sem data mantem a ordem do painel entre si. */
$semData = [rot(['slug' => 'x']), rot(['slug' => 'y'])];
ok(array_column(po_por_saida($semData), 'slug') === ['x', 'y'], 'dois sem data: ordem do painel');
ok(po_por_saida([]) === [], 'lista vazia');

/* ---------- po_sem_vencidos: quem sai de cena ---------- */
$comVencido = [
    rot(['slug' => 'passou', 'periodo' => 'De 01/01/26 a 10/01/26']),
    rot(['slug' => 'futuro', 'periodo' => 'De 01/01/28 a 10/01/28']),
    rot(['slug' => 'peru',   'status_viagem' => 'semanal']),
];
$vivos = array_column(po_sem_vencidos($comVencido, '2026-11-05'), 'slug');
ok($vivos === ['futuro', 'peru'], 'o vencido sai, o futuro e o semanal ficam: ' . implode(', ', $vivos));
ok(count(po_sem_vencidos($comVencido, '2026-01-05')) === 3, 'antes de vencer, os tres ficam');

/* ---------- a conta dos 7 roteiros REAIS, medidos no banco em 05/11/2026 ----------
   Este bloco e o que prova o pedido do cliente de ponta a ponta. */
$reais = [
    rot(['slug' => 'vietna-tailandia-paris',     'periodo' => 'De 22/02/2027 a 15/03/2027']),
    rot(['slug' => 'tesouros-da-grecia',         'periodo' => 'De 18/05/27 a 03/06/27']),
    rot(['slug' => 'peru-fascinante',            'periodo' => '', 'status_viagem' => 'semanal']),
    rot(['slug' => 'escandinavia',               'periodo' => 'De 29/07/27 à 14/08/27']),
    rot(['slug' => 'turquia',                    'periodo' => 'De 27/10/26 à 12/11/26']),
    rot(['slug' => 'chile-e-deserto-do-atacama', 'periodo' => '25/11/26 a 02/12/26']),
    rot(['slug' => 'coreia-do-sul-japao-dubai',  'periodo' => 'De 18/03/27 à 09/04/27']),
];
$hoje = '2026-11-05';
$final = array_column(po_por_saida(po_sem_vencidos($reais, $hoje)), 'slug');
ok($final === ['turquia', 'chile-e-deserto-do-atacama', 'vietna-tailandia-paris',
               'coreia-do-sul-japao-dubai', 'tesouros-da-grecia', 'escandinavia', 'peru-fascinante'],
    'os 7 reais em ordem, Peru por ultimo: ' . implode(', ', $final));
ok(count($final) === 7, 'nenhum dos 7 esta vencido em 05/11/2026: a Turquia esta EM CURSO, nao vencida');

/* E em 13/11 a Turquia sai de cena sozinha, sem ninguem mexer no painel. */
$depois = array_column(po_por_saida(po_sem_vencidos($reais, '2026-11-13')), 'slug');
ok(!in_array('turquia', $depois, true), 'no dia seguinte ao fim da viagem, a Turquia se oculta sozinha');
ok($depois[0] === 'chile-e-deserto-do-atacama', 'e o Chile assume a frente');
ok(end($depois) === 'peru-fascinante', 'o Peru segue por ultimo');

echo "OK test-po-ordem-cronologica\n";
