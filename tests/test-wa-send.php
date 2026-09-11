<?php
require __DIR__ . '/../lib/wa-send.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

$capt = [];
wa_set_transport(function ($url, $payload, $headers) use (&$capt) {
    $capt = compact('url', 'payload', 'headers');
    return ['status' => 200, 'body' => '{"messages":[{"id":"wamid.TESTE"}]}'];
});

// --- texto
$r = wa_send_text('+5548996048882', 'Bom dia');
ok($r['ok'] === true, 'texto devolve ok');
ok($r['wamid'] === 'wamid.TESTE', 'texto devolve o wamid');
$p = json_decode($capt['payload'], true);
ok($p['messaging_product'] === 'whatsapp', 'messaging_product obrigatorio');
ok($p['type'] === 'text', 'tipo text');
ok($p['text']['body'] === 'Bom dia', 'corpo do texto');
// O "+" nao vai para a Graph API: ela quer so digitos.
ok($p['to'] === '5548996048882', 'destinatario sem o mais');

// --- documento (o PDF do roteiro)
$r = wa_send_document('+5548996048882', 'https://x/docs/turquia-ab12.pdf', 'Turquia.pdf', 'Segue o roteiro');
$p = json_decode($capt['payload'], true);
ok($p['type'] === 'document', 'tipo document');
ok($p['document']['link'] === 'https://x/docs/turquia-ab12.pdf', 'link do PDF');
ok($p['document']['filename'] === 'Turquia.pdf', 'nome do arquivo');
ok($p['document']['caption'] === 'Segue o roteiro', 'legenda');
ok($r['ok'] === true, 'documento devolve ok');

// --- menu de lista
$itens = [
    ['id' => 'turquia',      'titulo' => 'Turquia com Antalia'],
    ['id' => 'escandinavia', 'titulo' => 'O melhor da Escandinavia'],
];
$r = wa_send_list('+5548996048882', 'Sobre qual viagem?', 'Ver roteiros', $itens);
$p = json_decode($capt['payload'], true);
ok($p['type'] === 'interactive', 'tipo interactive');
ok($p['interactive']['type'] === 'list', 'interactive do tipo list');
ok(count($p['interactive']['action']['sections'][0]['rows']) === 2, 'duas linhas no menu');
ok($p['interactive']['action']['sections'][0]['rows'][0]['id'] === 'turquia', 'id da linha e o slug');
ok($r['ok'] === true, 'lista devolve ok');

// O WhatsApp corta titulo de linha em 24 caracteres: cortar aqui evita o
// erro 400 que devolveria a mensagem inteira sem enviar.
$longo = [['id' => 'x', 'titulo' => 'Um titulo absurdamente longo que o WhatsApp recusa']];
wa_send_list('+5548996048882', 'c', 'b', $longo);
$p = json_decode($capt['payload'], true);
ok(mb_strlen($p['interactive']['action']['sections'][0]['rows'][0]['title']) <= 24, 'titulo de linha cortado em 24');

// --- limite de 10 linhas por lista
$onze = [];
for ($i = 0; $i < 11; $i++) $onze[] = ['id' => "s$i", 'titulo' => "Roteiro $i"];
wa_send_list('+5548996048882', 'c', 'b', $onze);
$p = json_decode($capt['payload'], true);
ok(count($p['interactive']['action']['sections'][0]['rows']) === 10, 'lista corta em 10 linhas');

// --- erro da API nao pode virar excecao
wa_set_transport(function () { return ['status' => 400, 'body' => '{"error":{"message":"Invalid parameter"}}']; });
$r = wa_send_text('+5548996048882', 'x');
ok($r['ok'] === false, 'erro 400 devolve ok=false');
ok(strpos($r['erro'], 'Invalid parameter') !== false, 'erro traz a mensagem da Meta');

// --- rede fora do ar idem
wa_set_transport(function () { return null; });
$r = wa_send_text('+5548996048882', 'x');
ok($r['ok'] === false, 'rede fora devolve ok=false');

// --- telefone invalido nem chega a sair
$r = wa_send_text('telefone ruim', 'x');
ok($r['ok'] === false, 'destinatario invalido nao envia');

echo "test-wa-send OK\n";
