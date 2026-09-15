<?php
require __DIR__ . '/../lib/wa-camp-recibo.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

/* POR QUE ESTE ARQUIVO EXISTE, e a armadilha que ele guarda:

   O wamid de um evento `status` E o wamid da mensagem original. E
   po_wa_mensagens.wamid e UNIQUE. Passar status pela idempotencia de
   po_wa_mensagens faz recibo e eco competirem pela MESMA linha: o eco vira
   'duplicado', o wa_processar pula, e O ROBO NAO SE CALA - que e o pior modo
   de falha do sistema inteiro. Isso ja foi implementado por engano uma vez
   (faxina de 13/09) e revertido.

   A idempotencia de ENTREGA e esta: po_wa_envios, por wamid, movendo SO para
   frente. */
$DB = ['po_wa_envios' => [
    ['id'=>'E1', 'campanha_id'=>'C1', 'lead_id'=>'L1', 'wa_id'=>'+5548999990001',
     'wamid'=>'wamid.AAA', 'status'=>'enviado', 'entregue_at'=>null, 'lido_at'=>null],
]];

wa_db_set_transport(function ($metodo, $url, $corpo) use (&$DB) {
    if ($metodo === 'GET') {
        $out = $DB['po_wa_envios'];
        if (preg_match('/wamid=eq\.([A-Za-z0-9.]+)/', $url, $m)) {
            $out = array_values(array_filter($out, fn($e) => $e['wamid'] === $m[1]));
        }
        return ['status' => 200, 'body' => json_encode($out)];
    }
    if ($metodo === 'PATCH') {
        preg_match('/id=eq\.([A-Za-z0-9]+)/', $url, $m);
        $campos = json_decode($corpo, true);
        foreach ($DB['po_wa_envios'] as &$e) {
            if ($e['id'] === ($m[1] ?? '')) $e = array_merge($e, $campos);
        }
        return ['status' => 200, 'body' => '[]'];
    }
    return ['status' => 500, 'body' => '{}'];
});

ok(wa_camp_recibo('wamid.AAA', 'delivered') === 'entregue', 'delivered vira entregue');
ok($DB['po_wa_envios'][0]['status'] === 'entregue', 'a linha do envio e atualizada');
ok(!empty($DB['po_wa_envios'][0]['entregue_at']), 'a hora da entrega e carimbada');

ok(wa_camp_recibo('wamid.AAA', 'read') === 'lido', 'read vira lido');
ok($DB['po_wa_envios'][0]['status'] === 'lido', 'lido sobrepoe entregue');

/* SO PARA FRENTE. A Meta reentrega webhook, e fora de ordem: um `delivered`
   que chega depois de um `read` nao pode rebaixar o status, senao o relatorio
   de leitura encolhe sozinho e ninguem entende por que. */
ok(wa_camp_recibo('wamid.AAA', 'delivered') === 'lido',
   'delivered atrasado NAO rebaixa um envio ja lido');
ok($DB['po_wa_envios'][0]['status'] === 'lido', 'a linha continua lida');

/* Recibo repetido e o caso NORMAL, nao a excecao: a Meta reenvia o mesmo
   evento quando nao recebe 200 rapido. Tem que ser inofensivo. */
$antes = $DB['po_wa_envios'][0];
wa_camp_recibo('wamid.AAA', 'read');
ok($DB['po_wa_envios'][0]['lido_at'] === $antes['lido_at'],
   'recibo repetido nao mexe no carimbo que ja existia');

/* failed vira falha, e vale mesmo depois de enviado: a Meta pode recusar
   depois de aceitar (numero invalido, bloqueio). */
$DB['po_wa_envios'][0]['status'] = 'enviado';
ok(wa_camp_recibo('wamid.AAA', 'failed') === 'falha', 'failed vira falha');

/* --- falha e terminal: nem um "delivered" reentregue nem um "read"
   posterior tiram o envio da falha. A Meta reentrega webhook fora de
   ordem (regra 1), e o caso mais caro de errar e exatamente este: sem
   posto proprio na escada, `falha` cairia no degrau mais baixo e um
   `delivered` reentregue DEPOIS do `failed` ressuscitaria o envio - o
   relatorio de uma campanha paga esconderia a falha, com a coluna `erro`
   (que este arquivo nao escreve, mas o painel le) contradizendo o status. */
$DB['po_wa_envios'][] = ['id'=>'E2', 'campanha_id'=>'C1', 'lead_id'=>'L2', 'wa_id'=>'+5548999990002',
    'wamid'=>'wamid.BBB', 'status'=>'enviado', 'entregue_at'=>null, 'lido_at'=>null];

function linha_bbb($DB) {
    foreach ($DB['po_wa_envios'] as $e) if ($e['wamid'] === 'wamid.BBB') return $e;
    return null;
}

ok(wa_camp_recibo('wamid.BBB', 'delivered') === 'entregue', 'BBB: delivered vira entregue');
$entregue_at_original = linha_bbb($DB)['entregue_at'];
ok(!empty($entregue_at_original), 'BBB: entregue_at carimbado na primeira entrega');

ok(wa_camp_recibo('wamid.BBB', 'failed') === 'falha', 'BBB: failed vira falha mesmo depois de entregue');

ok(wa_camp_recibo('wamid.BBB', 'delivered') === 'falha',
   'BBB: delivered reentregue DEPOIS do failed nao ressuscita o envio');
ok(linha_bbb($DB)['status'] === 'falha', 'BBB: status continua falha apos o delivered reentregue');
// Aqui a escada ja bloqueia a chamada inteira (novo <= atual), entao esta
// asserção nao discrimina o empty() por si so - ela so prova que nada foi
// escrito, o que a escada garante sozinha. O cenario que discrimina o
// carimbo condicional de verdade e o de CCC, mais abaixo.
ok(linha_bbb($DB)['entregue_at'] === $entregue_at_original,
   'BBB: entregue_at original sobrevive ao vaivem');

ok(wa_camp_recibo('wamid.BBB', 'read') === 'falha',
   'BBB: read depois do failed tambem nao tira da falha');
ok(linha_bbb($DB)['status'] === 'falha', 'BBB: status continua falha apos o read');
ok(empty(linha_bbb($DB)['lido_at']), 'BBB: lido_at nunca e carimbado, a falha bloqueou a entrada');

/* --- m3: o carimbo condicional (`empty($e['entregue_at'])`) protege um
   estado que a escada sozinha NAO cobre - uma linha que chega com o
   `entregue_at` ja preenchido mas o status ainda ABAIXO de entregue (ex.:
   uma carga manual, ou uma corrida que gravou o carimbo antes do status).
   Um `delivered` sobre ela tem que levar o status a `entregue`, porque a
   escada permite (novo > atual), mas SEM reescrever o carimbo que ja
   existia - a hora real do evento nao pode virar a hora deste replay. */
$DB['po_wa_envios'][] = ['id'=>'E9', 'campanha_id'=>'C1', 'lead_id'=>'L9', 'wa_id'=>'+5548999990009',
    'wamid'=>'wamid.CCC', 'status'=>'enviado', 'entregue_at'=>'2020-01-01T00:00:00+00:00', 'lido_at'=>null];

ok(wa_camp_recibo('wamid.CCC', 'delivered') === 'entregue', 'CCC: delivered leva o status a entregue');
foreach ($DB['po_wa_envios'] as $e) {
    if ($e['wamid'] !== 'wamid.CCC') continue;
    ok($e['status'] === 'entregue', 'CCC: status avancou de enviado para entregue');
    ok($e['entregue_at'] === '2020-01-01T00:00:00+00:00',
       'CCC: entregue_at que ja existia nao e reescrito');
}

/* wamid que nao e de campanha (o PDF do roteiro, a pergunta do motor) devolve
   null e nao escreve nada. E o caso mais comum de todos. */
ok(wa_camp_recibo('wamid.NAOEXISTE', 'delivered') === null,
   'wamid que nao e de campanha devolve null');
ok(wa_camp_recibo('', 'delivered') === null, 'wamid vazio devolve null');

/* Status que a Meta inventar amanha nao pode virar escrita silenciosa. */
ok(wa_camp_recibo('wamid.AAA', 'inventado') === null, 'status desconhecido nao grava nada');

/* --- m4: erro de LEITURA de po_wa_envios tem que deixar rastro PROPRIO no
   log, distinto do generico da camada de banco (wa_db_select_estrito ja
   loga o status HTTP, mas nao o wamid nem que o chamador era o recibo).
   Isto importa porque a Meta NAO reenvia um evento de status que ja
   recebeu 200 - se a leitura falhar aqui, o log e a UNICA chance de
   reconciliar depois. Mesmo padrao de tests/test-wa-camp-fila.php (Task 6
   deste plano): redireciona error_log para um arquivo temporario,
   restaura, le e confere. */
$arq    = tempnam(sys_get_temp_dir(), 'walog');
$antigo = ini_get('error_log');
ini_set('error_log', $arq);
wa_db_set_transport(function ($metodo, $url, $corpo) {
    return ['status' => 500, 'body' => 'erro interno'];
});
$r = wa_camp_recibo('wamid.AAA', 'delivered');
ini_set('error_log', $antigo);
$saiu = file_get_contents($arq);
unlink($arq);

ok($r === null, 'erro de leitura devolve null, nunca escreve as cegas');
ok(strpos($saiu, 'wa_camp_recibo: leitura de po_wa_envios falhou') !== false,
   'o erro de leitura deixa o rastro PROPRIO do recibo, nao so o generico da camada de banco');

echo "test-wa-camp-recibo OK\n";
