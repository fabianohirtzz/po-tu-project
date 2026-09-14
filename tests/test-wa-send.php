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

// --- sucesso silencioso: status 200 mas o corpo diz erro (ex: token expirado)
wa_set_transport(function () { return ['status' => 200, 'body' => '{"error":{"message":"Token expirado"}}']; });
$r = wa_send_text('+5548996048882', 'x');
ok($r['ok'] === false, 'status 200 com erro no corpo nao pode virar sucesso');
ok($r['wamid'] === null, 'erro no corpo nao tem wamid');
ok(strpos($r['erro'], 'Token expirado') !== false, 'erro do corpo com status 200 e propagado');

// --- status 200 com corpo que nao decodifica para JSON
wa_set_transport(function () { return ['status' => 200, 'body' => 'isto nao e json']; });
$r = wa_send_text('+5548996048882', 'x');
ok($r['ok'] === false, 'corpo invalido nao pode virar sucesso');
ok($r['wamid'] === null, 'corpo invalido nao tem wamid');

// --- status 200 mas a resposta nao trouxe o wamid
wa_set_transport(function () { return ['status' => 200, 'body' => '{"messages":[{}]}']; });
$r = wa_send_text('+5548996048882', 'x');
ok($r['ok'] === false, 'sucesso exige wamid, mesmo com status 200');
ok($r['wamid'] === null, 'sem wamid na resposta, wamid fica null');

// --- rede fora do ar idem
wa_set_transport(function () { return null; });
$r = wa_send_text('+5548996048882', 'x');
ok($r['ok'] === false, 'rede fora devolve ok=false');

// --- telefone invalido nem chega a sair
$r = wa_send_text('telefone ruim', 'x');
ok($r['ok'] === false, 'destinatario invalido nao envia');

/* ---------- template ----------
   Fora da janela de 24h a Cloud API SO aceita template. A transmissao nunca
   alcanca alguem dentro da janela, entao este e o unico caminho de envio do
   plano 4. O corpo aprovado vive na Meta; o que mandamos sao os parametros
   posicionais, na ordem em que {{1}}, {{2}} aparecem no template aprovado. */
$capturado = null;
wa_set_transport(function ($url, $payload, $headers) use (&$capturado) {
    $capturado = ['url' => $url, 'payload' => json_decode($payload, true)];
    return ['status' => 200, 'body' => json_encode(['messages' => [['id' => 'wamid.TPL1']]])];
});

$r = wa_send_template('+5548999990001', 'roteiro_novo_2026', ['Marlene', 'Mercados de Natal']);
ok($r['ok'] === true,            'template com resposta 200 devolve ok');
ok($r['wamid'] === 'wamid.TPL1', 'template devolve o wamid da Meta');

$p = $capturado['payload'];
ok($p['type'] === 'template',                  "type e 'template' (veio: {$p['type']})");
ok($p['template']['name'] === 'roteiro_novo_2026', 'o nome do template vai no payload');
ok($p['template']['language']['code'] === 'pt_BR', 'idioma padrao e pt_BR');

$corpo = $p['template']['components'][0];
ok($corpo['type'] === 'body', 'o primeiro componente e o body');
ok(count($corpo['parameters']) === 2, 'os dois parametros foram enviados');
ok($corpo['parameters'][0]['text'] === 'Marlene',
   'a ORDEM dos parametros e preservada: o primeiro e o primeiro');
ok($corpo['parameters'][1]['text'] === 'Mercados de Natal',
   'a ordem dos parametros e preservada: o segundo e o segundo');

/* Template SEM parametros nao pode mandar components: a Graph API devolve
   132000 ("number of parameters does not match") e a mensagem inteira morre. */
wa_send_template('+5548999990001', 'aviso_simples');
ok(!isset($capturado['payload']['template']['components']),
   'template sem parametros nao manda o bloco components');

/* Nome de template vazio nunca pode virar chamada: a Meta devolveria 400 e
   o custo do erro e um destinatario que nao recebeu nada, em silencio. */
$antes = $capturado;
$r = wa_send_template('+5548999990001', '');
ok($r['ok'] === false,          'template sem nome nao e enviado');
ok($capturado === $antes,       'template sem nome nao chega a chamar a Graph API');

wa_set_transport(null);

echo "test-wa-send OK\n";
