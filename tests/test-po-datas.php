<?php
/* lib/po-datas.php: a proxima saida de um roteiro de saidas semanais.
   Tarefa 2 do plano 2026-11-04-status-semanal-e-datas.

   TODAS as datas de fixture estao em 2039/2040. Fixture com data perto do
   presente vira vermelho sozinho na virada do mes, e `$hoje` vai sempre
   EXPLICITO nas assercoes que dependem dele - sem isso o teste mede o
   calendario da maquina, nao o codigo. */
require __DIR__ . '/../lib/po-datas.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

$dez = [['titulo' => 'Datas de Dezembro', 'datas' => ['02/12/39', '10/12/39', '18/12/39']]];
$doisMeses = [
    ['titulo' => 'Datas de Dezembro', 'datas' => ['02/12/39', '10/12/39']],
    ['titulo' => 'Datas de Janeiro',  'datas' => ['07/01/40', '15/01/40']],
];
$sem = function ($datas_texto, $extra = []) {
    return array_merge(['status_viagem' => 'semanal', 'datas_texto' => $datas_texto,
        'data_label' => '', 'periodo' => ''], $extra);
};

/* ---------- po_datas_grupos: a forma ---------- */
ok(po_datas_grupos($sem($doisMeses)) === $doisMeses, 'dois grupos bem formados passam inteiros, na ordem de entrada');
ok(po_datas_grupos($sem([])) === [], 'lista vazia');

/* Forma errada nao pode dar fatal nem virar grupo pela metade: o jsonb pode
   ter sido editado a mao no SQL Editor. */
foreach ([null, 'texto', 42, ['a' => 1], [['titulo' => 'x', 'datas' => '02/12/39']],
          [['titulo' => 'x']], ['02/12/39'], [['datas' => ['02/12/39']]]] as $i => $ruim) {
    ok(po_datas_grupos($sem($ruim)) === [], 'forma errada #' . $i . ' devolve lista vazia');
}
ok(po_datas_grupos('nem array') === [], 'roteiro que nao e array');
/* Mapa com chaves em vez de lista, com valores BEM FORMADOS: e o que uma
   edicao a mao no SQL Editor produz, e e o unico caso em que a guarda de
   "tem de ser lista" decide algo (valor que nem e array ja cai sozinho). */
ok(po_datas_grupos($sem(['dez' => ['titulo' => 'D', 'datas' => ['02/12/39']]])) === [], 'mapa com chaves nao e lista, mesmo com grupos validos dentro');
ok(po_datas_grupos($sem([0 => ['titulo' => 'D', 'datas' => ['02/12/39']], 2 => ['titulo' => 'J', 'datas' => ['07/01/40']]])) === [], 'lista com indice furado tambem nao passa');
/* Grupo bom no meio de lixo: o bom sobrevive, o lixo e descartado. */
ok(po_datas_grupos($sem([['titulo' => 'x', 'datas' => 'nao'], $dez[0]])) === [$dez[0]], 'descarta o grupo ruim e mantem o bom');
/* Data vazia e data que nao e string saem de dentro do grupo. */
ok(po_datas_grupos($sem([['titulo' => 'D', 'datas' => ['02/12/39', '', null, 7, '10/12/39']]]))
   === [['titulo' => 'D', 'datas' => ['02/12/39', '10/12/39']]], 'limpa as datas de dentro do grupo');

/* ---------- po_proxima_saida: a primeira FUTURA ---------- */
ok(po_proxima_saida($sem($dez), '2039-11-01') === '2039-12-02', 'antes de todas, pega a primeira');
ok(po_proxima_saida($sem($dez), '2039-12-05') === '2039-12-10', 'pula a que passou e pega a proxima, nao a primeira da lista');
ok(po_proxima_saida($sem($dez), '2039-12-10') === '2039-12-10', 'a saida de HOJE conta');
ok(po_proxima_saida($sem($dez), '2039-12-19') === null, 'todas no passado devolve null');
ok(po_proxima_saida($sem($doisMeses), '2039-12-20') === '2040-01-07', 'atravessa a virada do ano para o grupo seguinte');
ok(po_proxima_saida($sem([]), '2039-01-01') === null, 'sem datas, null');

/* A menor data futura, mesmo que a cliente tenha digitado fora de ordem. */
ok(po_proxima_saida($sem([['titulo' => 'D', 'datas' => ['18/12/39', '02/12/39', '10/12/39']]]), '2039-12-05')
   === '2039-12-10', 'escolhe a menor futura mesmo com a lista fora de ordem');

/* Data impossivel e texto no lugar da data sao descartados e a busca
   CONTINUA: sem isso um erro de digitacao no painel mandaria a viagem para o
   fim da fila em vez de para a data certa. */
ok(po_proxima_saida($sem([['titulo' => 'D', 'datas' => ['32/12/39', '31/02/40', 'dezembro', '02-12-39', '10/12/39']]]), '2039-11-01')
   === '2039-12-10', 'descarta data impossivel e texto, e acha a valida depois dela');
ok(po_proxima_saida($sem([['titulo' => 'D', 'datas' => ['32/13/99']]]), '2039-01-01') === null, 'so data impossivel devolve null');

/* Ano de 2 e de 4 digitos dao o mesmo resultado (o banco tem os dois). */
ok(po_proxima_saida($sem([['titulo' => 'D', 'datas' => ['02/12/39']]]), '2039-11-01')
   === po_proxima_saida($sem([['titulo' => 'D', 'datas' => ['02/12/2039']]]), '2039-11-01'), 'ano de 2 e de 4 digitos sao iguais');

/* ---------- po_quando: a string que a pessoa le ---------- */
ok(po_quando($sem($dez), '2039-11-01') === 'Próxima saída: 02/12/39', 'semanal com data futura anuncia a proxima saida');
ok(po_quando($sem($dez, ['datas_texto' => [['titulo' => 'D', 'datas' => ['02/12/2039']]]]), '2039-11-01')
   === 'Próxima saída: 02/12/2039', 'preserva exatamente o que a cliente digitou, sem reformatar');

/* Nenhuma data futura: volta ao comportamento de hoje. Anunciar "Proxima
   saida" de data que ja passou e pior que nao anunciar nada. */
ok(po_quando($sem($dez, ['periodo' => 'De 02/12/39 a 18/12/39']), '2039-12-19')
   === 'De 02/12/39 a 18/12/39', 'sem data futura, cai em periodo');
/* Todas vencidas E uma ilegivel no meio: a ilegivel nao pode ser anunciada
   como proxima saida. Sem a guarda, ela casa com o "nao achei" e o cliente
   recebe "Proxima saida: dezembro". */
ok(po_quando($sem([['titulo' => 'D', 'datas' => ['02/12/39', 'dezembro', '10/12/39']]], ['periodo' => 'P']), '2039-12-20')
   === 'P', 'data ilegivel numa lista vencida nao vira proxima saida');
ok(po_quando($sem([], ['periodo' => 'P']), '2039-01-01') === 'P', 'semanal sem datas cai em periodo');
ok(po_quando($sem([]), '2039-01-01') === '', 'semanal sem datas e sem periodo: string vazia, nunca rotulo orfao');

/* Quem decide e o STATUS, nao a presenca do dado: um roteiro comum com
   datas_texto preenchido por engano segue mostrando o periodo dele. */
ok(po_quando(['status_viagem' => 'em_breve', 'datas_texto' => $dez, 'data_label' => '', 'periodo' => 'De 1 a 2'], '2039-11-01')
   === 'De 1 a 2', 'status diferente de semanal ignora datas_texto');
ok(po_quando(['status_viagem' => null, 'data_label' => 'outubro de 2039', 'periodo' => 'De 1 a 2'], '2039-11-01')
   === 'outubro de 2039', 'data_label ganha de periodo quando preenchido');
/* No banco data_label chega como STRING VAZIA, nao null, nos 6 roteiros reais. */
ok(po_quando(['status_viagem' => null, 'data_label' => '', 'periodo' => 'De 1 a 2'], '2039-11-01')
   === 'De 1 a 2', 'data_label string vazia cai em periodo');
ok(po_quando([], '2039-11-01') === '', 'roteiro sem nada');
ok(po_quando('nem array', '2039-11-01') === '', 'roteiro que nao e array');

echo "OK test-po-datas\n";
