<?php
require __DIR__ . '/../lib/wa-camp-fila.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

/* Simulador de banco em memoria. Nenhum teste toca a rede. O que ele precisa
   imitar de verdade e o INDICE UNICO (campanha_id, lead_id): e ele o cadeado
   da reserva, e um simulador sem cadeado deixaria passar o duplo envio que
   este teste existe para impedir. */
$DB = ['po_wa_envios' => [], 'po_wa_campanhas' => []];

/* Contador de chamadas ao transporte. So existe para o buraco 2 (limite <= 0
   tem que barrar ANTES de qualquer ida ao banco): sem ele, um "enviados===0"
   nao prova que o banco nao foi consultado, so que nada saiu. */
$CHAMADAS = 0;

function wa_test_transport_normal($metodo, $url, $corpo, &$DB) {
    $tabela = preg_match('#/rest/v1/([a-z_]+)#', $url, $m) ? $m[1] : '';
    $linha  = $corpo ? json_decode($corpo, true) : null;

    if ($metodo === 'POST') {
        foreach ($DB[$tabela] as $e) {
            if ($e['campanha_id'] === $linha['campanha_id'] && $e['lead_id'] === $linha['lead_id']) {
                return ['status' => 409, 'body' => '{}'];        // unique_violation
            }
        }
        $linha['id'] = 'E' . (count($DB[$tabela]) + 1);
        $DB[$tabela][] = $linha;
        return ['status' => 201, 'body' => json_encode([$linha])];
    }
    if ($metodo === 'GET') {
        $out = $DB[$tabela];
        if (preg_match('/status=eq\.([a-z]+)/', $url, $m)) {
            $out = array_values(array_filter($out, fn($e) => $e['status'] === $m[1]));
        }
        if (preg_match('/limit=(\d+)/', $url, $m)) {
            $out = array_slice($out, 0, (int) $m[1]);
        }
        return ['status' => 200, 'body' => json_encode($out)];
    }
    if ($metodo === 'PATCH') {
        preg_match('/id=eq\.([A-Za-z0-9]+)/', $url, $m);
        foreach ($DB[$tabela] as &$e) {
            if ($e['id'] === ($m[1] ?? '')) $e = array_merge($e, $linha);
        }
        return ['status' => 200, 'body' => '[]'];
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
ok($DB['po_wa_envios'][0]['wamid']  === null,    'falha nao inventa wamid');
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

echo "test-wa-camp-fila OK\n";
