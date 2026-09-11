<?php
require __DIR__ . '/../lib/wa-webhook.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

// --- assinatura
$segredo = 'segredo-de-teste';
$corpo   = '{"object":"whatsapp_business_account"}';
$boa     = 'sha256=' . hash_hmac('sha256', $corpo, $segredo);

ok(wa_verifica_assinatura($corpo, $boa, $segredo) === true, 'assinatura correta passa');
ok(wa_verifica_assinatura($corpo, 'sha256=00', $segredo) === false, 'assinatura errada barra');
ok(wa_verifica_assinatura($corpo, '', $segredo) === false, 'sem assinatura barra');
ok(wa_verifica_assinatura($corpo . ' ', $boa, $segredo) === false, 'corpo alterado barra');

// --- segredo ausente ou fraco nunca autoriza (revisao: Critical 1).
// Com WA_APP_SECRET vazio o HMAC e calculavel por qualquer um, e o
// atacante posta evento forjado que o motor grava com a service_role.
$forjada = 'sha256=' . hash_hmac('sha256', $corpo, '');
ok(wa_verifica_assinatura($corpo, $forjada, '') === false, 'segredo vazio recusa assinatura calculada com segredo vazio');
ok(wa_verifica_assinatura($corpo, 'sha256=' . hash_hmac('sha256', $corpo, 'curto'), 'curto') === false, 'segredo com menos de 16 caracteres nao e levado a serio, mesmo batendo');
ok(wa_segredo_util('') === false, 'segredo vazio nao serve');
ok(wa_segredo_util('123456789012345') === false, 'quinze caracteres nao bastam');
ok(wa_segredo_util('1234567890123456') === true, 'dezesseis caracteres bastam');

// --- token de verificacao do GET, mesma regra
ok(wa_verifica_token('', '') === false, 'token vazio nunca devolve o desafio, nem contra pedido tambem vazio');
ok(wa_verifica_token('', 'qualquer-coisa') === false, 'token vazio nunca autoriza');
ok(wa_verifica_token('token-curto', 'token-curto') === false, 'token curto demais nao autoriza, mesmo batendo');
ok(wa_verifica_token('token-de-verificacao-longo', 'outro-token-longo-qualquer') === false, 'token configurado longo, recebido errado, nao autoriza');
ok(wa_verifica_token('token-de-verificacao-longo', 'token-de-verificacao-longo') === true, 'token configurado longo e igual ao recebido autoriza');

// --- mensagem de texto do cliente
$msg = json_decode('{
 "entry":[{"changes":[{"value":{
   "contacts":[{"profile":{"name":"Maria Aparecida"},"wa_id":"5548996048882"}],
   "messages":[{"from":"5548996048882","id":"wamid.AAA","timestamp":"1757548800",
     "type":"text","text":{"body":"queria saber da Turquia"}}]
 }}]}]}', true);
$ev = wa_parse_evento($msg);
ok(count($ev) === 1, 'um evento');
ok($ev[0]['tipo'] === 'mensagem', 'tipo mensagem');
ok($ev[0]['wa_id'] === '+5548996048882', 'wa_id normalizado com o mais');
ok($ev[0]['texto'] === 'queria saber da Turquia', 'texto');
ok($ev[0]['nome'] === 'Maria Aparecida', 'nome do perfil');
ok($ev[0]['wamid'] === 'wamid.AAA', 'wamid');

// --- mensagem vinda de anuncio traz a atribuicao
$ad = json_decode('{
 "entry":[{"changes":[{"value":{
   "messages":[{"from":"5548996048882","id":"wamid.BBB","timestamp":"1757548800",
     "type":"text","text":{"body":"oi"},
     "referral":{"source_id":"120210000","ctwa_clid":"ARxyz"}}]
 }}]}]}', true);
$ev = wa_parse_evento($ad);
ok($ev[0]['ad_id'] === '120210000', 'ad_id do referral');
ok($ev[0]['ctwa_clid'] === 'ARxyz', 'ctwa_clid do referral');

// --- resposta do menu de lista vira o slug escolhido
$lista = json_decode('{
 "entry":[{"changes":[{"value":{
   "messages":[{"from":"5548996048882","id":"wamid.CCC","timestamp":"1757548800",
     "type":"interactive","interactive":{"type":"list_reply",
       "list_reply":{"id":"turquia","title":"Turquia com Antalia"}}}]
 }}]}]}', true);
$ev = wa_parse_evento($lista);
ok($ev[0]['tipo_msg'] === 'list_reply', 'tipo da mensagem e list_reply');
ok($ev[0]['texto'] === 'turquia', 'texto da lista e o id escolhido');

// --- ECO: o que ela digita no celular. E o sinal de handoff.
$eco = json_decode('{
 "entry":[{"changes":[{"field":"smb_message_echoes","value":{
   "message_echoes":[{"to":"5548996048882","id":"wamid.DDD","timestamp":"1757548800",
     "type":"text","text":{"body":"Bom dia Maria, aqui e a Simone"}}]
 }}]}]}', true);
$ev = wa_parse_evento($eco);
ok(count($ev) === 1, 'eco vira um evento');
ok($ev[0]['tipo'] === 'eco', 'tipo eco');
ok($ev[0]['wa_id'] === '+5548996048882', 'eco usa o destinatario como wa_id');

// --- documento enviado por ela (sinal de proposta)
$doc = json_decode('{
 "entry":[{"changes":[{"field":"smb_message_echoes","value":{
   "message_echoes":[{"to":"5548996048882","id":"wamid.EEE","timestamp":"1757548800",
     "type":"document","document":{"filename":"proposta.pdf"}}]
 }}]}]}', true);
$ev = wa_parse_evento($doc);
ok($ev[0]['tipo'] === 'eco' && $ev[0]['tipo_msg'] === 'document', 'eco de documento');

// --- status de entrega
$st = json_decode('{
 "entry":[{"changes":[{"value":{
   "statuses":[{"id":"wamid.FFF","status":"delivered","recipient_id":"5548996048882","timestamp":"1757548800"}]
 }}]}]}', true);
$ev = wa_parse_evento($st);
ok($ev[0]['tipo'] === 'status' && $ev[0]['texto'] === 'delivered', 'status de entrega');

// --- payload que nao interessa nao pode explodir
ok(wa_parse_evento([]) === [], 'json vazio devolve []');
ok(wa_parse_evento(['entry' => [['changes' => [['value' => []]]]]]) === [], 'change sem mensagem devolve []');

// --- wa_registra_evento: distingue duplicado (409) de falha (rede/5xx/RLS).
// Um so vale idempotencia de verdade; o outro tem que processar mesmo
// assim, senao vira mensagem de cliente descartada em silencio.
$evento = ['wa_id' => '+5548996048882', 'wamid' => 'wamid.GGG', 'tipo' => 'mensagem',
           'tipo_msg' => 'text', 'texto' => 'oi', 'ts' => 1757548800];

wa_db_set_transport(function () { return ['status' => 201, 'body' => '[{"id":"1"}]']; });
ok(wa_registra_evento($evento) === 'novo', 'gravou: novo');

wa_db_set_transport(function () { return ['status' => 409, 'body' => '{"code":"23505"}']; });
ok(wa_registra_evento($evento) === 'duplicado', '409 e duplicado, nao falha');

wa_db_set_transport(function () { return null; });
ok(wa_registra_evento($evento) === 'falha', 'rede fora e falha, nao duplicado');

wa_db_set_transport(function () { return ['status' => 500, 'body' => 'erro interno']; });
ok(wa_registra_evento($evento) === 'falha', '5xx do banco e falha, nao duplicado');

echo "test-wa-webhook OK\n";
