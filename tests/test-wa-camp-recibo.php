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

/* wamid que nao e de campanha (o PDF do roteiro, a pergunta do motor) devolve
   null e nao escreve nada. E o caso mais comum de todos. */
ok(wa_camp_recibo('wamid.NAOEXISTE', 'delivered') === null,
   'wamid que nao e de campanha devolve null');
ok(wa_camp_recibo('', 'delivered') === null, 'wamid vazio devolve null');

/* Status que a Meta inventar amanha nao pode virar escrita silenciosa. */
ok(wa_camp_recibo('wamid.AAA', 'inventado') === null, 'status desconhecido nao grava nada');

echo "test-wa-camp-recibo OK\n";
