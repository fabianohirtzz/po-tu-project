<?php
require __DIR__ . '/../lib/wa-camp-fila.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

/* Acha a linha de po_wa_envios pelo lead_id, em vez de confiar em indice
   numerico fixo. Varias secoes novas deste arquivo inserem e removem linhas
   de campanhas diferentes; um indice fixo ($DB['po_wa_envios'][0]) quebraria
   silenciosamente assim que a ordem de insercao mudasse. */
function wa_test_acha_envio(&$DB, $lead_id) {
    foreach ($DB['po_wa_envios'] as $x) { if ($x['lead_id'] === $lead_id) return $x; }
    return null;
}

/* Simulador de banco em memoria. Nenhum teste toca a rede. O que ele precisa
   imitar de verdade e o INDICE UNICO (campanha_id, lead_id): e ele o cadeado
   da reserva, e um simulador sem cadeado deixaria passar o duplo envio que
   este teste existe para impedir.

   As campanhas C1, C2 e C4 ja nascem com template: a partir da correcao I2
   (campanha ilegivel nao pode queimar a fila), um dreno sem template legivel
   devolve cedo sem tocar a fila - se essas campanhas nao tivessem template
   aqui, os testes originais do brief parariam de exercitar o envio de
   verdade e passariam por coincidencia, nao por sustentar a regra. */
$DB = [
    'po_wa_envios'    => [],
    'po_wa_campanhas' => [
        ['id' => 'C1', 'template' => 'modelo_generico'],
        ['id' => 'C2', 'template' => 'modelo_generico'],
        ['id' => 'C4', 'template' => 'modelo_generico'],
    ],
];

/* Contador de chamadas ao transporte. So existe para o buraco 2 (limite <= 0
   tem que barrar ANTES de qualquer ida ao banco): sem ele, um "enviados===0"
   nao prova que o banco nao foi consultado, so que nada saiu. */
$CHAMADAS = 0;

/* Interpreta os pares campo=eq.valor / campo=lt.valor / campo=gt.valor da
   query string do PostgREST, e tambem campo=in.(a,b) (a correcao D1 da
   revisao r2 usa isso para contar 'reservado' e 'enviando' juntos em
   'restam'). Precisa entender MAIS de uma condicao ao mesmo tempo (ex:
   campanha_id=eq.X&status=eq.reservado): e exatamente essa combinacao que
   prova o achado C2 (isolamento entre campanhas) e a tomada de posse do
   achado C1 (id=eq.X&status=eq.reservado, a corrida do PATCH). */
function wa_test_condicoes($url) {
    $condicoes = [];
    // in.(...) primeiro: a lista pode conter varios valores separados por
    // virgula, e o parenteses nao pode ser confundido com o `&` que separa
    // os outros pares campo=op.valor.
    if (preg_match_all('/([a-z_]+)=in\.\(([^)]*)\)/', $url, $ms, PREG_SET_ORDER)) {
        foreach ($ms as $m) {
            $valores = array_map('rawurldecode', explode(',', $m[2]));
            $condicoes[] = [$m[1], 'in', $valores];
        }
    }
    if (preg_match_all('/([a-z_]+)=(eq|lt|gt)\.([^&]*)/', $url, $ms, PREG_SET_ORDER)) {
        foreach ($ms as $m) {
            $condicoes[] = [$m[1], $m[2], rawurldecode($m[3])];
        }
    }
    return $condicoes;
}

function wa_test_casa($linha, $condicoes) {
    foreach ($condicoes as [$campo, $op, $valor]) {
        $v = array_key_exists($campo, $linha) ? $linha[$campo] : null;
        if ($op === 'eq' && (string) $v !== $valor)                        return false;
        if ($op === 'lt' && ($v === null || strcmp((string) $v, $valor) >= 0)) return false;
        if ($op === 'gt' && ($v === null || strcmp((string) $v, $valor) <= 0)) return false;
        if ($op === 'in' && !in_array((string) $v, $valor, true))          return false;
    }
    return true;
}

function wa_test_transport_normal($metodo, $url, $corpo, &$DB) {
    $tabela = preg_match('#/rest/v1/([a-z_]+)#', $url, $m) ? $m[1] : '';
    $linha  = $corpo ? json_decode($corpo, true) : null;

    if ($metodo === 'POST') {
        foreach ($DB[$tabela] as $e) {
            if (($e['campanha_id'] ?? null) === $linha['campanha_id']
                && ($e['lead_id'] ?? null) === $linha['lead_id']) {
                return ['status' => 409, 'body' => '{}'];        // unique_violation
            }
        }
        $linha['id'] = 'E' . (count($DB[$tabela]) + 1) . '_' . $linha['lead_id'];
        $DB[$tabela][] = $linha;
        return ['status' => 201, 'body' => json_encode([$linha])];
    }
    if ($metodo === 'GET') {
        $condicoes = wa_test_condicoes($url);
        $out = array_values(array_filter($DB[$tabela], fn($e) => wa_test_casa($e, $condicoes)));
        if (preg_match('/limit=(\d+)/', $url, $m)) {
            $out = array_slice($out, 0, (int) $m[1]);
        }
        return ['status' => 200, 'body' => json_encode($out)];
    }
    if ($metodo === 'PATCH') {
        // O PATCH real (Prefer: return=representation) devolve so as linhas
        // que CASARAM a condicao. E o que sustenta a tomada de posse: quem
        // patcheia `status=eq.reservado` e ganha a corrida recebe a linha de
        // volta; quem chega depois recebe [].
        $condicoes = wa_test_condicoes($url);
        $afetadas = [];
        foreach ($DB[$tabela] as &$e) {
            if (wa_test_casa($e, $condicoes)) {
                $e = array_merge($e, $linha);
                $afetadas[] = $e;
            }
        }
        unset($e);
        return ['status' => 200, 'body' => json_encode($afetadas)];
    }
    return ['status' => 500, 'body' => '{}'];
}

function wa_test_usa_transporte_normal() {
    global $DB, $CHAMADAS;
    wa_db_set_transport(function ($metodo, $url, $corpo) use (&$DB, &$CHAMADAS) {
        $CHAMADAS++;
        return wa_test_transport_normal($metodo, $url, $corpo, $DB);
    });
}
wa_test_usa_transporte_normal();

$publico = [
    ['lead_id'=>'L1', 'wa_id'=>'+5548999990001', 'nome'=>'Um'],
    ['lead_id'=>'L2', 'wa_id'=>'+5548999990002', 'nome'=>'Dois'],
    ['lead_id'=>'L3', 'wa_id'=>'+5548999990003', 'nome'=>'Tres'],
];

/* ---------- reserva ----------
   A linha e RESERVADA antes do envio, e o unique e o cadeado. Registrar so
   depois do envio deixaria passar o retry disparado enquanto a primeira
   requisicao ainda roda - que e exatamente o que um timeout produz. Mesma
   licao do lote de importacao do plano 3.1. */
$r = wa_camp_reserva('C1', $publico);
ok($r['reservados'] === 3,  'reserva as tres linhas (deu: ' . $r['reservados'] . ')');
ok($r['ja_existiam'] === 0, 'nada existia antes');
ok(count($DB['po_wa_envios']) === 3, 'tres linhas no banco');
ok($DB['po_wa_envios'][0]['status'] === 'reservado', 'a linha nasce reservada, sem wamid');
ok($DB['po_wa_envios'][0]['nome'] === 'Um',
   'o nome e fotografado na reserva: e o parametro {{1}} do template');

// Reaplicar a MESMA campanha nao pode criar linha nova: e o segundo clique
// no botao, ou o cron entrando junto com o painel.
$r = wa_camp_reserva('C1', $publico);
ok($r['reservados'] === 0,  'a segunda reserva nao cria nada');
ok($r['ja_existiam'] === 3, 'e reporta que as tres ja existiam');
ok(count($DB['po_wa_envios']) === 3, 'o banco continua com tres linhas');

/* ---------- drenagem ----------
   So sai quem esta 'reservado'. O envio e injetado: nenhum teste toca a rede. */
$mandados = [];
wa_camp_set_enviador(function ($wa_id, $nome) use (&$mandados) {
    $mandados[] = $wa_id;
    return ['ok' => true, 'wamid' => 'wamid.' . count($mandados), 'erro' => null];
});

$d = wa_camp_drena('C1', 2);
ok($d['enviados'] === 2, 'o limite do lote e respeitado (deu: ' . $d['enviados'] . ')');
ok($d['restam']   === 1, 'sobra um na fila');
ok(count($mandados) === 2, 'so dois envios aconteceram');
ok($DB['po_wa_envios'][0]['status'] === 'enviado', 'a linha vira enviado');
ok($DB['po_wa_envios'][0]['wamid']  === 'wamid.1', 'o wamid e gravado, e por onde o recibo volta');
// m2: enviado_at nunca era assertado, e e exatamente o que a Task 8 conta
// para o teto diario contra a Meta.
ok(!empty($DB['po_wa_envios'][0]['enviado_at']), 'enviado_at e gravado no envio bem sucedido');
ok($DB['po_wa_envios'][2]['status'] === 'reservado', 'o terceiro continua reservado');

$d = wa_camp_drena('C1', 10);
ok($d['enviados'] === 1, 'a segunda drenagem manda so o que restava');
ok($d['restam']   === 0, 'a fila esvazia');

// Drenar de novo nao pode reenviar nada: e o cron rodando de hora em hora.
$antes = count($mandados);
$d = wa_camp_drena('C1', 10);
ok($d['enviados'] === 0,        'fila vazia nao envia nada');
ok(count($mandados) === $antes, 'e nao chama o enviador de novo');

/* ---------- falha de envio ----------
   Envio que falhou NAO pode virar 'enviado': o relatorio de entrega mentiria
   e a pessoa nunca receberia nada. Vira 'falha', com o motivo. */
$DB['po_wa_envios'] = [];
wa_camp_reserva('C2', [['lead_id'=>'L9', 'wa_id'=>'+5548999990009', 'nome'=>'Nove']]);
wa_camp_set_enviador(function ($wa_id, $nome) {
    return ['ok' => false, 'wamid' => null, 'erro' => 'template nao aprovado'];
});
$d = wa_camp_drena('C2', 10);
ok($d['enviados'] === 0, 'envio que falhou nao conta como enviado');
ok($d['falhas']   === 1, 'a falha e contada');
ok($DB['po_wa_envios'][0]['status'] === 'falha', 'a linha vira falha');
// m1: a forma antiga ($DB[...]['wamid'] === null) disparava "undefined array
// key" toda vez que a linha nunca ganhou a chave wamid, e o warning mascara
// warning de verdade quando um aparecer.
ok((wa_test_acha_envio($DB, 'L9')['wamid'] ?? null) === null, 'falha nao inventa wamid');
ok($DB['po_wa_envios'][0]['erro']   === 'template nao aprovado', 'o motivo fica gravado');

/* ---------- buraco 1: leitura da fila tem que distinguir erro de vazio ----------
   Se a FILA fosse lida com wa_db_select (o frouxo, que devolve [] tanto em
   erro quanto em vazio), um Supabase fora do ar pareceria "fila vazia" e a
   drenagem devolveria enviados=0 mas seguiria em frente como se so nao
   houvesse mais ninguem a mandar - a campanha ficaria eternamente incompleta
   com a tela dizendo sucesso, no lugar de sinalizar erro (restam=-1).

   O truque para expor isso de verdade: falhar SO a leitura da fila (a que
   carrega "limit="), e deixar a leitura final de "resto" funcionando normal.
   Se as duas leituras falhassem juntas (como um 500 geral faria), a leitura
   de "resto" tambem devolveria null e restam sairia -1 de qualquer jeito,
   MESMO com a mutacao (select em vez de select_estrito) na leitura da fila -
   e o buraco passaria despercebido por coincidencia. */
wa_camp_reserva('C4', [
    ['lead_id'=>'L20', 'wa_id'=>'+5548999990020', 'nome'=>'Vinte'],
    ['lead_id'=>'L21', 'wa_id'=>'+5548999990021', 'nome'=>'Vinte e um'],
]);
wa_db_set_transport(function ($metodo, $url, $corpo) use (&$DB, &$CHAMADAS) {
    $CHAMADAS++;
    // So a consulta com "limit=" (a leitura da fila) sofre a queda do banco.
    // A leitura final de "resto" (sem limit) e a de po_wa_campanhas seguem
    // respondendo normal, para a mutacao nao se esconder atras delas.
    if ($metodo === 'GET' && strpos($url, 'limit=') !== false) {
        return ['status' => 500, 'body' => '{}'];
    }
    return wa_test_transport_normal($metodo, $url, $corpo, $DB);
});
$mandados_antes = count($mandados);
$d = wa_camp_drena('C4', 10);
ok($d['enviados'] === 0,  'leitura da fila fora do ar nao envia nada (deu: ' . $d['enviados'] . ')');
ok($d['restam']   === -1, 'leitura da fila fora do ar reporta restam=-1, nao fila vazia (deu: ' . $d['restam'] . ')');
ok(count($mandados) === $mandados_antes, 'o enviador nao e chamado quando a leitura da fila falha');
// A prova de que o truque funciona: os dois reservados de C4 continuam la,
// intocados - se a mutacao tivesse passado por cima do erro, eles teriam
// sido "enviados" e a linha abaixo veria menos de 2.
ok(count(array_filter($DB['po_wa_envios'], fn($e) => $e['campanha_id'] === 'C4' && $e['status'] === 'reservado')) === 2,
   'os reservados de C4 continuam intocados apos a falha de leitura');

wa_test_usa_transporte_normal();

/* ---------- buraco 2: limite zero nao pode nem consultar o banco ----------
   $limite <= 0 tem que barrar tanto zero quanto negativo. Zero e o caso
   real (degrau ja no teto do dia, wa_camp_lote_permitido devolvendo 0).

   "enviados===0" sozinho nao prova nada aqui: com $limite=0, a propria
   consulta a fila leva "limit=0" e um GET com limit=0 ja devolve lista
   vazia no simulador (e no PostgREST real) INDEPENDENTE da guarda - entao
   um "enviados===0" seria verdade mesmo se a guarda virasse "$limite < 0".
   Quem realmente expoe a mutacao e o CONTADOR DE CHAMADAS: com a guarda
   certa, limite 0 devolve sem consultar nada; com "< 0", o codigo desce ate
   ler po_wa_campanhas e a fila, gastando duas idas ao banco a toa. */
$chamadas_antes = $CHAMADAS;
$mandados_antes = count($mandados);
$d = wa_camp_drena('C1', 0);
ok($d['enviados'] === 0, 'limite zero nao envia nada (deu: ' . $d['enviados'] . ')');
ok(count($mandados) === $mandados_antes, 'limite zero nao chama o enviador');
ok($CHAMADAS === $chamadas_antes,
   'limite zero nao consulta o banco (chamadas antes: ' . $chamadas_antes . ', depois: ' . $CHAMADAS . ')');

/* ============================================================
   Rodada de correcao 1 - achados da revisao (task-5-findings.md).
   Todos os defeitos abaixo sao do PLANO, nao do commit original: o revisor
   confirmou que o commit era fiel ao brief.
============================================================ */

/* ---------- C1 (Critical): tomada de posse contra dreno concorrente ----------
   A reserva (indice unico) impede a LINHA de duplicar. Ela nao impede o
   ENVIO de repetir: a linha continua 'reservado' durante a chamada a Meta,
   entao dois drenos ao mesmo tempo (o cron e o botao do painel, Task 8) que
   leem a MESMA fotografia da fila mandam a mesma mensagem duas vezes.

   Para provar isso num teste de processo unico, o transporte simula a
   corrida: na hora em que o dreno CORRENTE le a fila (a query com "limit="),
   o transporte primeiro deixa um "outro processo" (o cron) drenar a MESMA
   campanha ate o fim - tomando posse de verdade e mandando a mensagem -
   e SO DEPOIS devolve ao dreno corrente a fotografia de ANTES disso
   acontecer. Sem a tomada de posse, o dreno corrente veria a linha ainda
   'reservado' na sua copia e mandaria de novo. */
$DB['po_wa_envios'] = [];
$DB['po_wa_campanhas'][] = ['id' => 'CRACE', 'template' => 'modelo_generico'];
wa_test_usa_transporte_normal();
wa_camp_reserva('CRACE', [['lead_id'=>'LR1', 'wa_id'=>'+5548999990140', 'nome'=>'Corrida']]);

$mandados_race = [];
wa_camp_set_enviador(function ($wa_id, $nome) use (&$mandados_race) {
    $mandados_race[] = $wa_id;
    return ['ok' => true, 'wamid' => 'wamid.race.' . count($mandados_race), 'erro' => null];
});

$fila_congelada = false;
$transporte_race = function ($metodo, $url, $corpo) use (&$DB, &$CHAMADAS, &$fila_congelada, &$transporte_race) {
    $CHAMADAS++;
    if (!$fila_congelada && $metodo === 'GET'
        && strpos($url, '/po_wa_envios') !== false && strpos($url, 'limit=') !== false) {
        $fila_congelada = true;
        // A FOTOGRAFIA que o dreno corrente vai usar, tirada AGORA.
        $resposta = wa_test_transport_normal($metodo, $url, $corpo, $DB);
        // O "outro processo" (cron) drena a mesma campanha ate o fim, de
        // verdade, tomando posse pelo PATCH condicional.
        wa_test_usa_transporte_normal();
        wa_camp_drena('CRACE', 10);
        // Devolve este transporte especial para o resto da chamada corrente.
        wa_db_set_transport($transporte_race);
        return $resposta;
    }
    return wa_test_transport_normal($metodo, $url, $corpo, $DB);
};
wa_db_set_transport($transporte_race);

$d = wa_camp_drena('CRACE', 10);
wa_test_usa_transporte_normal();

ok(count($mandados_race) === 1,
   'dois drenos leem a mesma fotografia, mas so UM envio sai (deu: ' . count($mandados_race) . ')');
ok($d['enviados'] === 0,
   'o dreno corrente nao pode contar como seu um envio que a corrida ja tinha levado');
$linha_race = wa_test_acha_envio($DB, 'LR1');
ok($linha_race && $linha_race['status'] === 'enviado',
   'a linha termina enviado (por quem tomou posse primeiro), nao presa nem duplicada');

/* ---------- C2 (Critical): o dreno tem que respeitar a fronteira da campanha ----------
   Removendo campanha_id=eq. da consulta da fila, um dreno passaria a puxar
   linhas 'reservado' de QUALQUER campanha e mandar a elas o template errado.
   Duas campanhas reservadas ao mesmo tempo: drenar uma nao pode tocar a outra. */
$DB['po_wa_envios'] = [];
$DB['po_wa_campanhas'][] = ['id' => 'CISO_A', 'template' => 'modelo_generico'];
$DB['po_wa_campanhas'][] = ['id' => 'CISO_B', 'template' => 'modelo_generico'];
wa_camp_reserva('CISO_A', [['lead_id'=>'LA1', 'wa_id'=>'+5548999990100', 'nome'=>'Iso A']]);
wa_camp_reserva('CISO_B', [['lead_id'=>'LB1', 'wa_id'=>'+5548999990101', 'nome'=>'Iso B']]);

$mandados_iso = [];
wa_camp_set_enviador(function ($wa_id, $nome) use (&$mandados_iso) {
    $mandados_iso[] = $wa_id;
    return ['ok' => true, 'wamid' => 'wamid.iso.' . count($mandados_iso), 'erro' => null];
});

$d = wa_camp_drena('CISO_A', 10);
ok($d['enviados'] === 1, 'drenar CISO_A manda so quem e de CISO_A (deu: ' . $d['enviados'] . ')');
ok(count($mandados_iso) === 1 && $mandados_iso[0] === '+5548999990100',
   'o enviador so recebeu o destinatario de CISO_A');
$linha_b = wa_test_acha_envio($DB, 'LB1');
ok($linha_b && $linha_b['status'] === 'reservado',
   'o reservado de CISO_B continua intocado depois de drenar CISO_A');

/* ---------- I1 (Important): reserva confunde erro de banco com conflito ----------
   wa_db_insert (o antigo caminho) devolve null em 409, em 500, em rede caida
   e em corpo malformado; wa_camp_reserva chamava tudo isso de "ja existia".
   Com transporte fora do ar, a reserva tem que contar 'erros', nao inflar
   'ja_existiam' - senao a campanha e encerrada como concluida sem enviar. */
wa_test_usa_transporte_normal();
wa_db_set_transport(function ($metodo, $url, $corpo) use (&$CHAMADAS) {
    $CHAMADAS++;
    return ['status' => 500, 'body' => '{}'];
});
$rerro = wa_camp_reserva('CINSERTERR', [
    ['lead_id'=>'LI1', 'wa_id'=>'+5548999990150', 'nome'=>'Erro Um'],
    ['lead_id'=>'LI2', 'wa_id'=>'+5548999990151', 'nome'=>'Erro Dois'],
]);
ok($rerro['reservados'] === 0,  'banco fora do ar nao reserva nada (deu: ' . $rerro['reservados'] . ')');
ok($rerro['ja_existiam'] === 0,
   'banco fora do ar NAO pode virar "ja existia" (deu: ' . $rerro['ja_existiam'] . ')');
ok($rerro['erros'] === 2, 'as duas falhas de verdade sao contadas em erros (deu: ' . $rerro['erros'] . ')');
wa_test_usa_transporte_normal();

/* ---------- I2 (Important): campanha ilegivel nao pode queimar a fila ----------
   Hoje, se a leitura de po_wa_campanhas falhar (ou a campanha nao tiver
   template), $template vira '' e toda a fila reservada vira 'falha'
   permanente, sem nenhum envio - o indice unico impede ate re-reservar.
   Duas causas reais de "campanha ilegivel", as duas testadas: a leitura
   falhar de verdade (rede/500), e a campanha existir mas sem template. Nos
   dois casos a fila tem que ficar INTACTA, e o enviador nunca e chamado. */
$DB['po_wa_envios'] = [];
$mandados_ileg = [];
wa_camp_set_enviador(function ($wa_id, $nome) use (&$mandados_ileg) {
    $mandados_ileg[] = $wa_id;
    return ['ok' => true, 'wamid' => 'wamid.ileg.' . count($mandados_ileg), 'erro' => null];
});

// (a) leitura da campanha falha de verdade (500). CCAMPERR de proposito NAO
// entra em $DB['po_wa_campanhas']: o transporte abaixo intercepta a consulta
// antes mesmo de o banco em memoria ser olhado.
wa_camp_reserva('CCAMPERR', [['lead_id'=>'LE1', 'wa_id'=>'+5548999990110', 'nome'=>'Erro Leitura']]);
wa_db_set_transport(function ($metodo, $url, $corpo) use (&$DB, &$CHAMADAS) {
    $CHAMADAS++;
    if ($metodo === 'GET' && strpos($url, '/po_wa_campanhas') !== false) {
        return ['status' => 500, 'body' => '{}'];
    }
    return wa_test_transport_normal($metodo, $url, $corpo, $DB);
});
$d = wa_camp_drena('CCAMPERR', 10);
ok($d['enviados'] === 0 && $d['restam'] === -1,
   'leitura da campanha fora do ar nao envia nada (deu enviados=' . $d['enviados'] . ' restam=' . $d['restam'] . ')');
$linha_e = wa_test_acha_envio($DB, 'LE1');
ok($linha_e && $linha_e['status'] === 'reservado',
   'a linha continua reservada, a fila NAO e queimada quando a campanha nao le');
wa_test_usa_transporte_normal();

// (b) a campanha le normalmente, mas nao tem template configurado.
$DB['po_wa_campanhas'][] = ['id' => 'CSEMTPL', 'template' => ''];
wa_camp_reserva('CSEMTPL', [['lead_id'=>'LS1', 'wa_id'=>'+5548999990111', 'nome'=>'Sem Template']]);
$d = wa_camp_drena('CSEMTPL', 10);
ok($d['enviados'] === 0 && $d['restam'] === -1,
   'campanha sem template nao envia nada (deu enviados=' . $d['enviados'] . ' restam=' . $d['restam'] . ')');
$linha_s = wa_test_acha_envio($DB, 'LS1');
ok($linha_s && $linha_s['status'] === 'reservado',
   'a linha continua reservada, a fila NAO e queimada quando falta template');

ok(count($mandados_ileg) === 0,
   'em nenhum dos dois casos de campanha ilegivel o enviador chega a ser chamado');

/* ---------- I3 (Important): ok=true com wamid=null nao pode virar 'enviado' ----------
   A resposta 200-sem-identificador da Meta e um caso real (a documentacao da
   Graph API preve isso). A condicao tem que exigir os dois: ok E wamid. */
$DB['po_wa_envios'] = [];
$DB['po_wa_campanhas'][] = ['id' => 'COKNULL', 'template' => 'modelo_generico'];
wa_camp_reserva('COKNULL', [['lead_id'=>'LN1', 'wa_id'=>'+5548999990120', 'nome'=>'Ok Sem Wamid']]);
wa_camp_set_enviador(function ($wa_id, $nome) {
    return ['ok' => true, 'wamid' => null, 'erro' => null];
});
$d = wa_camp_drena('COKNULL', 10);
ok($d['enviados'] === 0 && $d['falhas'] === 1,
   'ok=true sem wamid NAO conta como enviado (deu enviados=' . $d['enviados'] . ' falhas=' . $d['falhas'] . ')');
$linha_n = wa_test_acha_envio($DB, 'LN1');
ok($linha_n && $linha_n['status'] === 'falha', 'a linha vira falha quando falta o wamid, mesmo com ok=true');
ok(($linha_n['wamid'] ?? null) === null, 'falha continua sem wamid');

/* ---------- I4 (Important): o que chega ao enviador tem que ser conferido ----------
   Guardar so o wa_id (como os testes originais faziam) deixa passar nome
   vazio ou os dois parametros trocados. Aqui guarda-se a TUPLA inteira. */
$DB['po_wa_envios'] = [];
$DB['po_wa_campanhas'][] = ['id' => 'CTUPLE', 'template' => 'modelo_generico'];
wa_camp_reserva('CTUPLE', [['lead_id'=>'LT1', 'wa_id'=>'+5548999990130', 'nome'=>'Nome Correto']]);
$tuplas = [];
wa_camp_set_enviador(function ($wa_id, $nome) use (&$tuplas) {
    $tuplas[] = [$wa_id, $nome];
    return ['ok' => true, 'wamid' => 'wamid.tuple.1', 'erro' => null];
});
$d = wa_camp_drena('CTUPLE', 10);
ok(count($tuplas) === 1, 'o enviador foi chamado exatamente uma vez');
ok($tuplas[0][0] === '+5548999990130', 'o enviador recebeu o wa_id certo');
ok($tuplas[0][1] === 'Nome Correto',
   'o enviador recebeu o NOME certo, nao vazio nem trocado pelo wa_id (deu: "' . $tuplas[0][1] . '")');

/* ============================================================
   Rodada de correcao 2 - achados da re-revisao (task-5-findings-r2.md).
   D1 e defeito de comportamento real, criado pela propria correcao da
   rodada 1 (o estado 'enviando'). D2 a D5 sao travas de teste sobre codigo
   que ja estava certo.
============================================================ */

/* ---------- D1 (Critical, defeito real): restam tem que contar 'enviando' ----------
   Uma linha presa em 'enviando' (processo morto entre a posse e a chamada a
   Meta) e alguem que NAO recebeu nada. Contar so 'reservado' em restam faz
   a Task 8 concluir a campanha com essa pessoa de fora - e como a
   recuperacao de orfas so roda dentro do dreno, e ninguem drena campanha
   concluida, a linha morre presa para sempre.

   Aqui a linha 'enviando' e RECENTE (dentro do prazo da posse), entao a
   recuperacao de orfas nao mexe nela - o teste isola so a CONTAGEM, nao a
   recuperacao (essa e o achado D2, logo abaixo). */
$DB['po_wa_envios'] = [];
$DB['po_wa_campanhas'][] = ['id' => 'CENVIANDO', 'template' => 'modelo_generico'];
$DB['po_wa_envios'][] = [
    'id' => 'EPRESA1', 'campanha_id' => 'CENVIANDO', 'lead_id' => 'LPR1',
    'wa_id' => '+5548999990190', 'nome' => 'Presa Recente', 'status' => 'enviando',
    'enviando_at' => gmdate('c', time() - 5),   // 5s atras: bem dentro do prazo de 30min
];
$d = wa_camp_drena('CENVIANDO', 10);
ok($d['restam'] === 1,
   'uma linha presa em enviando (recente, ainda sob posse) tem que contar em restam (deu: ' . $d['restam'] . ')');

/* ---------- D2 (Important): a recuperacao de orfas precisa de trava ----------
   Duas linhas presas em 'enviando' na MESMA campanha: uma "ativa" (posse ha
   so 5 segundos - outro processo pode estar dentro da chamada a Meta AGORA)
   e uma "orfa de verdade" (posse ha muito mais que o prazo - processo
   morto). A recuperacao tem que devolver SO a orfa para 'reservado' e
   deixar a ativa intocada: roubar a posse de quem ainda esta trabalhando
   manda de novo uma mensagem que ja esta a caminho - o mesmo envio
   duplicado que a tomada de posse (C1) fechou. */
$DB['po_wa_envios'] = [];
$DB['po_wa_campanhas'][] = ['id' => 'CORFA', 'template' => 'modelo_generico'];
$DB['po_wa_envios'][] = [
    'id' => 'EATIVA', 'campanha_id' => 'CORFA', 'lead_id' => 'LAT1',
    'wa_id' => '+5548999990200', 'nome' => 'Ativa', 'status' => 'enviando',
    'enviando_at' => gmdate('c', time() - 5),
];
$DB['po_wa_envios'][] = [
    'id' => 'EORFA', 'campanha_id' => 'CORFA', 'lead_id' => 'LOR1',
    'wa_id' => '+5548999990201', 'nome' => 'Orfa', 'status' => 'enviando',
    'enviando_at' => gmdate('c', time() - WA_CAMP_POSSE_LEASE - 120),
];
$mandados_orfa = [];
wa_camp_set_enviador(function ($wa_id, $nome) use (&$mandados_orfa) {
    $mandados_orfa[] = $wa_id;
    return ['ok' => true, 'wamid' => 'wamid.orfa.' . count($mandados_orfa), 'erro' => null];
});
$d = wa_camp_drena('CORFA', 10);
ok(count($mandados_orfa) === 1 && $mandados_orfa[0] === '+5548999990201',
   'so a orfa de verdade e recuperada e enviada, a ativa fica intocada (deu: ' . json_encode($mandados_orfa) . ')');
$linha_ativa = wa_test_acha_envio($DB, 'LAT1');
ok($linha_ativa && $linha_ativa['status'] === 'enviando',
   'a linha ativa continua em enviando: ninguem rouba a posse de quem ainda esta trabalhando');
$linha_orfa = wa_test_acha_envio($DB, 'LOR1');
ok($linha_orfa && $linha_orfa['status'] === 'enviado',
   'a orfa recuperada e enviada normalmente depois de voltar para reservado');

/* ---------- D4 (Important): a transicao para 'enviando' precisa ser travada ----------
   O teste da corrida (C1, acima) simula o PERDEDOR chegando DEPOIS de o
   vencedor ja ter enviado e marcado 'enviado' - o status=eq.reservado do
   perdedor falha por causa da escrita FINAL, nao da posse em si. Isso nao
   prova que a POSSE de fato muda o status para 'enviando': se o PATCH da
   posse so gravasse enviando_at (sem status), a linha continuaria
   parecendo 'reservado' para qualquer outro dreno, e dois processos
   tomariam "posse" da mesma linha ao mesmo tempo.

   Aqui o dreno CONCORRENTE roda por completo logo depois que o dreno
   corrente toma posse de verdade no banco (o PATCH ja aconteceu), mas
   ANTES de o dreno corrente chamar a Meta. Se a posse realmente marcou
   'enviando', o concorrente nao acha mais a linha (ela nao e mais
   'reservado') e nao manda nada. */
$DB['po_wa_envios'] = [];
$DB['po_wa_campanhas'][] = ['id' => 'CPOSSE', 'template' => 'modelo_generico'];
wa_test_usa_transporte_normal();
wa_camp_reserva('CPOSSE', [['lead_id'=>'LP1', 'wa_id'=>'+5548999990170', 'nome'=>'Posse']]);

$mandados_posse = [];
wa_camp_set_enviador(function ($wa_id, $nome) use (&$mandados_posse) {
    $mandados_posse[] = $wa_id;
    return ['ok' => true, 'wamid' => 'wamid.posse.' . count($mandados_posse), 'erro' => null];
});

$posse_interceptada = false;
$transporte_posse = function ($metodo, $url, $corpo) use (&$DB, &$CHAMADAS, &$posse_interceptada, &$transporte_posse) {
    $CHAMADAS++;
    if (!$posse_interceptada && $metodo === 'PATCH'
        && strpos($url, 'id=eq.') !== false && strpos($url, 'status=eq.reservado') !== false) {
        $posse_interceptada = true;
        // Executa a tomada de posse de verdade primeiro, no banco real.
        $resposta = wa_test_transport_normal($metodo, $url, $corpo, $DB);
        // Agora, ANTES do dreno corrente sequer chamar a Meta, um dreno
        // concorrente roda por completo contra a MESMA campanha.
        wa_test_usa_transporte_normal();
        wa_camp_drena('CPOSSE', 10);
        wa_db_set_transport($transporte_posse);
        return $resposta;
    }
    return wa_test_transport_normal($metodo, $url, $corpo, $DB);
};
wa_db_set_transport($transporte_posse);

$d = wa_camp_drena('CPOSSE', 10);
wa_test_usa_transporte_normal();

ok(count($mandados_posse) === 1,
   'a posse tem que travar o concorrente que chega ENQUANTO o vencedor ainda esta enviando (deu: ' . count($mandados_posse) . ')');
$linha_posse = wa_test_acha_envio($DB, 'LP1');
ok($linha_posse && $linha_posse['status'] === 'enviado', 'a linha termina enviado por um so processo');

/* ---------- D5 (parte do C1 sem trava): log de gravacao falhada depois do envio ----------
   O envio JA SAIU e foi cobrado quando o PATCH final falha. Nao da pra
   desfazer, e o unico rastro e o log com o wamid - e por ele que se
   reconcilia na mao. Um log que ninguem testa e um log que some na
   primeira refatoracao. */
$DB['po_wa_envios'] = [];
$DB['po_wa_campanhas'][] = ['id' => 'CLOGFALHA', 'template' => 'modelo_generico'];
wa_test_usa_transporte_normal();
wa_camp_reserva('CLOGFALHA', [['lead_id'=>'LF1', 'wa_id'=>'+5548999990180', 'nome'=>'Log Falha']]);
wa_camp_set_enviador(function ($wa_id, $nome) {
    return ['ok' => true, 'wamid' => 'wamid.logfalha.1', 'erro' => null];
});

// So o PATCH FINAL (o que marca 'enviado', sem a condicao de posse) falha.
// A ancora [?&] evita casar "campanha_id=eq." (do recupera_orfas) so
// porque contem a substring "id=eq." no meio da palavra.
wa_db_set_transport(function ($metodo, $url, $corpo) use (&$DB, &$CHAMADAS) {
    $CHAMADAS++;
    $e_o_patch_final = $metodo === 'PATCH'
        && preg_match('/[?&]id=eq\./', $url)
        && strpos($url, 'status=eq.reservado') === false;
    if ($e_o_patch_final) {
        return ['status' => 500, 'body' => '{}'];
    }
    return wa_test_transport_normal($metodo, $url, $corpo, $DB);
});

$arq = tempnam(sys_get_temp_dir(), 'walog');
$antigo = ini_get('error_log');
ini_set('error_log', $arq);
$d = wa_camp_drena('CLOGFALHA', 10);
ini_set('error_log', $antigo);
$saiu = file_get_contents($arq);
unlink($arq);
wa_test_usa_transporte_normal();

ok($d['enviados'] === 1,
   'o envio ainda conta como enviado mesmo com a gravacao final falhando (deu: ' . $d['enviados'] . ')');
ok(strpos($saiu, 'ENVIO SAIU E A GRAVACAO FALHOU') !== false,
   'a gravacao falhada depois do envio deixa rastro no log');
ok(strpos($saiu, 'wamid.logfalha.1') !== false,
   'o rastro carrega o wamid, que e por onde se reconcilia na mao');

echo "test-wa-camp-fila OK\n";
