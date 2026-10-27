<?php
require __DIR__ . '/../lib/wa-send.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

$ENVIADO = null;
wa_set_transport(function ($url, $payload, $headers) use (&$ENVIADO) {
    $ENVIADO = json_decode($payload, true);
    return ['status' => 200, 'body' => '{"messages":[{"id":"wamid.TESTE"}]}'];
});

$fone = '+5548900000001';
$b2   = [['id' => 'sim', 'rotulo' => 'Sim, funciona'], ['id' => 'nao', 'rotulo' => 'Nao nessa data']];

// 1. caminho feliz, 2 botoes
$r = wa_send_buttons($fone, 'A data serve?', $b2);
ok($r['ok'] === true && $r['wamid'] === 'wamid.TESTE', 'dois botoes saem');
ok($ENVIADO['type'] === 'interactive', 'tipo interactive');
ok($ENVIADO['interactive']['type'] === 'button', 'interactive.type e button');
ok($ENVIADO['interactive']['body']['text'] === 'A data serve?', 'o corpo vai no body.text');
ok(count($ENVIADO['interactive']['action']['buttons']) === 2, 'dois botoes no payload');
ok($ENVIADO['interactive']['action']['buttons'][0]['type'] === 'reply', 'cada botao e type reply');
ok($ENVIADO['interactive']['action']['buttons'][0]['reply']['id'] === 'sim', 'o id vai no reply.id');
ok($ENVIADO['interactive']['action']['buttons'][1]['reply']['title'] === 'Nao nessa data', 'o rotulo vai no reply.title');

// 2. um e tres botoes tambem saem
$b1 = [['id' => 'a', 'rotulo' => 'Um']];
$b3 = array_merge($b2, [['id' => 'outro', 'rotulo' => 'Ver outro roteiro']]);
ok(wa_send_buttons($fone, 'x', $b1)['ok'] === true, 'um botao sai');
ok(wa_send_buttons($fone, 'x', $b3)['ok'] === true, 'tres botoes saem');

// 3. RECUSAS. Em cada uma, nada e enviado e a razao vem no erro.
$casos = [
  'zero botoes'        => [],
  'quatro botoes'      => array_merge($b3, [['id' => 'd', 'rotulo' => 'Quarto']]),
  'rotulo vazio'       => [['id' => 'a', 'rotulo' => '  ']],
  'rotulo com 21'      => [['id' => 'a', 'rotulo' => str_repeat('a', 21)]],
  'rotulos repetidos'  => [['id' => 'a', 'rotulo' => 'Sim'], ['id' => 'b', 'rotulo' => 'Sim']],
  'id vazio'           => [['id' => '', 'rotulo' => 'Sim']],
  'ids repetidos'      => [['id' => 'a', 'rotulo' => 'Sim'], ['id' => 'a', 'rotulo' => 'Nao']],
];
foreach ($casos as $nome => $bot) {
    $ENVIADO = null;
    $r = wa_send_buttons($fone, 'x', $bot);
    ok($r['ok'] === false, "recusa: $nome");
    ok($r['wamid'] === null, "recusa sem wamid: $nome");
    ok(trim((string) $r['erro']) !== '', "recusa com motivo: $nome");
    ok($ENVIADO === null, "recusa sem tocar o transporte: $nome");
}

// 4. o limite e em CARACTERES, nao em bytes: 20 letras acentuadas passam
$ENVIADO = null;
$r = wa_send_buttons($fone, 'x', [['id' => 'a', 'rotulo' => str_repeat('ç', 20)]]);
ok($r['ok'] === true, '20 caracteres acentuados passam (mb_strlen, nao strlen)');

// 5. corpo vazio recusa, e corpo acima de 1024 recusa (a Meta devolveria 400 e o cliente nada)
foreach (['corpo vazio' => '   ', 'corpo acima de 1024' => str_repeat('a', 1025)] as $nome => $corpo) {
    $ENVIADO = null;
    ok(wa_send_buttons($fone, $corpo, $b2)['ok'] === false, "recusa: $nome");
    ok($ENVIADO === null, "recusa sem tocar o transporte: $nome");
}

// 6. telefone que nao normaliza recusa antes de gastar a chamada, como as irmas
$ENVIADO = null;
ok(wa_send_buttons('nao-e-telefone', 'x', $b2)['ok'] === false, 'telefone invalido recusa');
ok($ENVIADO === null, 'telefone invalido nao chega ao transporte');

echo "test-wa-send-buttons: ok\n";
