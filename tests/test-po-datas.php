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
/* A impossivel ANTES da valida: 31/02/39 nao existe, e se o codigo a
   aceitasse ela seria a MENOR e viraria a proxima saida anunciada. Com uma
   impossivel depois da valida (31/02/40) o caso passa sem provar nada,
   porque ela nunca venceria o minimo. */
ok(po_proxima_saida($sem([['titulo' => 'D', 'datas' => ['31/02/39', '10/12/39']]]), '2039-01-01') === '2039-12-10', 'data impossivel anterior nao vence o minimo');
ok(po_quando($sem([['titulo' => 'D', 'datas' => ['31/02/39', '10/12/39']]]), '2039-01-01') === 'Próxima saída: 10/12/39', 'e nao e anunciada ao cliente');

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

/* ---------- po_datas_proximas: a lista que o robo manda ---------- */
/* So as FUTURAS: data vencida no texto do WhatsApp e ruido, e pior, parece
   oferta. Agrupadas pelo titulo que a cliente escreveu. */
ok(po_datas_proximas($sem($doisMeses), '2039-01-01', 300)
   === "Datas de Dezembro: 02/12/39, 10/12/39\nDatas de Janeiro: 07/01/40, 15/01/40", 'os dois meses, agrupados');
ok(po_datas_proximas($sem($doisMeses), '2039-12-05', 300)
   === "Datas de Dezembro: 10/12/39\nDatas de Janeiro: 07/01/40, 15/01/40", 'a que ja passou nao entra');
ok(po_datas_proximas($sem($doisMeses), '2040-01-10', 300) === 'Datas de Janeiro: 15/01/40', 'mes que ficou sem data futura sai inteiro');
ok(po_datas_proximas($sem($doisMeses), '2041-01-01', 300) === '', 'nada futuro, string vazia');
ok(po_datas_proximas($sem([]), '2039-01-01', 300) === '', 'sem datas');
ok(po_datas_proximas(['status_viagem' => 'em_breve', 'datas_texto' => $doisMeses], '2039-01-01', 300) === '', 'so para o status semanal');
ok(po_datas_proximas($sem([['titulo' => '', 'datas' => ['02/12/39']]]), '2039-01-01', 300) === '02/12/39', 'grupo sem titulo sai so com as datas');

/* O TETO, e por que ele corta em data INTEIRA: a legenda do card de PDF da
   Cloud API para em 1024 caracteres, e acima disso a Meta recusa a mensagem
   inteira EM SILENCIO. Cortar no meio de uma data afirmaria ao cliente um dia
   que nao existe, entao o corte cai sempre numa data completa. */
$quatroMeses = [];
foreach (['Dezembro' => '12/39', 'Janeiro' => '01/40', 'Fevereiro' => '02/40', 'Marco' => '03/40'] as $nome => $sufixo) {
    $ds = [];
    foreach ([2, 9, 16, 23, 28] as $d) $ds[] = sprintf('%02d/%s', $d, $sufixo);
    $quatroMeses[] = ['titulo' => 'Datas de ' . $nome, 'datas' => $ds];
}
$longo = po_datas_proximas($sem($quatroMeses), '2039-01-01', 300);
ok(mb_strlen($longo, 'UTF-8') <= 300, 'respeita o teto: ' . mb_strlen($longo, 'UTF-8'));
/* Em VARIOS tetos, e nao em um so: um teto sozinho pode cair, por sorte,
   logo depois de uma data inteira, e ai um corte por caractere passaria.
   A propriedade e que TODO pedaco continua sendo uma data completa. */
foreach ([120, 150, 180, 211, 247, 300, 333] as $teto) {
    $t = po_datas_proximas($sem($quatroMeses), '2039-01-01', $teto);
    ok(mb_strlen($t, 'UTF-8') <= $teto, "teto $teto respeitado: " . mb_strlen($t, 'UTF-8'));
    foreach (explode("\n", $t) as $linha) {
        if ($linha === '') continue;
        $pos = mb_strpos($linha, ': ', 0, 'UTF-8');
        $corpo = ($pos === false) ? $linha : mb_substr($linha, $pos + 2, null, 'UTF-8');
        foreach (explode(', ', $corpo) as $d) {
            ok(po_datas_iso($d) !== null, "teto $teto: '$d' e uma data completa, nao um pedaco");
        }
    }
}
ok(strpos($longo, 'Datas de Dezembro') === 0, 'comeca pelo mes mais proximo');
ok(po_datas_proximas($sem($doisMeses), '2039-01-01', 300) === po_datas_proximas($sem($doisMeses), '2039-01-01', 4096), 'texto que cabe nao e tocado');

/* ---------- po_datas_iso sozinha, e o relogio ---------- */
/* po_datas_iso e publica e chamada direto (por este teste e pelo
   po_datas_proximas). A defesa dela contra espaco Unicode nao pode depender
   de quem chama ter aparado antes: a rodada de mutacao mostrou que, sem esta
   assercao, tirar o apara de dentro dela nao quebrava nada.

   O caractere e montado com mb_chr, nao com escape: escrito como "\xC2\xA0"
   ele atravessa heredoc e script de edicao e chega aqui como byte cru, e o
   teste passa a medir o editor em vez do codigo. */
$nbsp = mb_chr(0x00A0, 'UTF-8');     // espaco nao-quebravel
$fino = mb_chr(0x2009, 'UTF-8');     // espaco fino
ok(po_datas_iso($nbsp . '02/12/39') === '2039-12-02', 'NBSP antes, direto na po_datas_iso');
ok(po_datas_iso('02/12/39' . $nbsp) === '2039-12-02', 'NBSP depois');
ok(po_datas_iso($fino . '02/12/39') === '2039-12-02', 'espaco fino tambem');
ok(po_datas_iso(' 02/12/39 ') === '2039-12-02', 'espaco comum segue valendo');
ok(po_datas_iso('02 /12/39') === null, 'espaco NO MEIO continua invalidando');
ok(po_datas_apara($nbsp . 'x' . $nbsp) === 'x', 'po_datas_apara tira o NBSP das duas pontas');
ok(po_datas_apara('a' . $nbsp . 'b') === 'a' . $nbsp . 'b', 'e nao mexe no meio');

/* UM RELOGIO SO, e ele e UTC. Trava de fonte porque o padrao de $hoje nao e
   alcancavel por teste deterministico: todo teste passa $hoje explicito (que
   e a pratica certa), entao trocar gmdate por date nao quebrava nada.
   O custo de dois relogios: wa_fluxo_ordena_roteiros ja usa gmdate e os
   gemeos em JS usam toISOString, tambem UTC. Com date() aqui, entre 21h e
   meia-noite em Sao Paulo a ORDEM da lista do robo e o ROTULO impresso ao
   lado dela discordam, e o cliente recebe "Proxima saida: hoje" num roteiro
   que a ordenacao ja empurrou para depois do proximo. */
$fonte = file_get_contents(__DIR__ . '/../lib/po-datas.php');
/* UMA chamada, nao duas: os defaults de $hoje passaram a chamar po_hoje(),
   que e o unico ponto que le o relogio. Com uma chamada so, nao ha como
   acrescentar um segundo relogio sem esta contagem acusar. */
ok(substr_count($fonte, "gmdate('Y-m-d')") === 1, 'o relogio e lido em UM ponto so (po_hoje)');
ok(preg_match('/function po_hoje\(\) \{\s*return gmdate\(\x27Y-m-d\x27\);/', $fonte) === 1,
    'e esse ponto e po_hoje');
ok(substr_count($fonte, 'po_hoje()') >= 5, 'e todos os defaults de $hoje passam por ela');
ok(substr_count(str_replace("gmdate('Y-m-d')", '', $fonte), "date('Y-m-d')") === 0, 'nenhuma chamada a date() local sobrou');

echo "OK test-po-datas\n";
