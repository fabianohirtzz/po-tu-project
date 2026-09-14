<?php
require __DIR__ . '/../lib/wa-db.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

// Transporte falso: captura o que seria enviado e nunca toca a rede.
$capt = [];
wa_db_set_transport(function ($metodo, $url, $corpo, $headers) use (&$capt) {
    $capt = compact('metodo', 'url', 'corpo', 'headers');
    return ['status' => 201, 'body' => '[{"id":"abc"}]'];
});

// --- insert
$r = wa_db_insert('po_wa_contatos', ['wa_id' => '+5548996048882', 'nome' => 'Maria']);
ok($capt['metodo'] === 'POST', 'insert usa POST');
ok(strpos($capt['url'], '/rest/v1/po_wa_contatos') !== false, 'insert aponta a tabela');
ok(json_decode($capt['corpo'], true)['wa_id'] === '+5548996048882', 'insert manda o corpo');
ok($r !== null && $r['id'] === 'abc', 'insert devolve a linha criada');

// A service_role nunca pode faltar, senao a RLS derruba a escrita em silencio.
$h = implode("\n", $capt['headers']);
ok(strpos($h, 'apikey:') !== false,        'manda apikey');
ok(strpos($h, 'Authorization: Bearer') !== false, 'manda Authorization');
ok(strpos($h, 'Prefer: return=representation') !== false, 'pede a linha de volta');

// --- insert idempotente: conflito de unique nao pode virar excecao
wa_db_set_transport(function ($metodo, $url, $corpo, $headers) use (&$capt) {
    $capt = compact('metodo', 'url', 'corpo', 'headers');
    return ['status' => 409, 'body' => '{"code":"23505","message":"duplicate key"}'];
});
$dup = wa_db_insert('po_wa_mensagens', ['wamid' => 'wamid.HBg'], true);
ok($dup === null, 'conflito com ignora_conflito devolve null, nao explode');

// --- update
wa_db_set_transport(function ($metodo, $url, $corpo, $headers) use (&$capt) {
    $capt = compact('metodo', 'url', 'corpo', 'headers');
    return ['status' => 204, 'body' => ''];
});
$u = wa_db_update('po_wa_conversas', 'wa_id=eq.%2B5548996048882', ['estado' => 'humano']);
ok($u === true, 'update devolve true em 204');
ok($capt['metodo'] === 'PATCH', 'update usa PATCH');
ok(strpos($capt['url'], 'wa_id=eq.') !== false, 'update carrega o filtro');

// --- select
wa_db_set_transport(function () { return ['status' => 200, 'body' => '[{"wa_id":"+5548996048882","estado":"novo"}]']; });
$s = wa_db_select('po_wa_conversas', 'wa_id=eq.x');
ok(count($s) === 1 && $s[0]['estado'] === 'novo', 'select devolve array associativo');

// --- rede fora do ar nao pode derrubar o webhook
wa_db_set_transport(function () { return null; });
ok(wa_db_select('po_wa_conversas', '') === [], 'select com rede fora devolve []');
ok(wa_db_insert('po_wa_contatos', ['wa_id' => 'x']) === null, 'insert com rede fora devolve null');
ok(wa_db_update('po_wa_conversas', 'id=eq.1', ['estado' => 'x']) === false, 'update com rede fora devolve false');

// --- json invalido idem
wa_db_set_transport(function () { return ['status' => 200, 'body' => 'isto nao e json']; });
ok(wa_db_select('po_wa_conversas', '') === [], 'json invalido devolve []');

// --- update_linhas: usada pela tomada de posse da fila de transmissao.
// Precisa devolver as LINHAS (nao um booleano), para o chamador distinguir
// "eu tomei posse" de "outro processo pegou primeiro".
wa_db_set_transport(function () { return ['status' => 200, 'body' => '[{"id":"E1","status":"enviando"}]']; });
$posse = wa_db_update_linhas('po_wa_envios', 'id=eq.E1&status=eq.reservado', ['status' => 'enviando']);
ok(is_array($posse) && count($posse) === 1 && $posse[0]['id'] === 'E1',
   'update_linhas devolve a linha quando o PATCH casa');

// Ninguem casou (outro dreno ja tinha tomado posse): PostgREST responde 200
// com lista vazia, nao erro. Isso NAO pode virar null, senao o chamador
// trataria "perdi a corrida" como "a escrita falhou".
wa_db_set_transport(function () { return ['status' => 200, 'body' => '[]']; });
$posse2 = wa_db_update_linhas('po_wa_envios', 'id=eq.E1&status=eq.reservado', ['status' => 'enviando']);
ok($posse2 === [], 'update_linhas devolve lista vazia quando ninguem casa, nao null');

// Erro de verdade (rede fora ou 5xx) tem que ser null, DIFERENTE de "ninguem
// casou": e a distincao que impede o dreno de reenviar uma linha cujo status
// real e desconhecido.
wa_db_set_transport(function () { return ['status' => 500, 'body' => '{}']; });
ok(wa_db_update_linhas('po_wa_envios', 'id=eq.E1', ['status' => 'enviando']) === null,
   'update_linhas devolve null em erro, nunca lista vazia');
wa_db_set_transport(function () { return null; });
ok(wa_db_update_linhas('po_wa_envios', 'id=eq.E1', ['status' => 'enviando']) === null,
   'update_linhas devolve null com a rede fora');

// --- insert_status: a reserva da transmissao usa isto para separar 409 (o
// cadeado, "ja existia") de erro de verdade (banco fora do ar).
wa_db_set_transport(function () { return ['status' => 201, 'body' => '[{"id":"E9"}]']; });
$ins = wa_db_insert_status('po_wa_envios', ['campanha_id' => 'C1', 'lead_id' => 'L1']);
ok($ins['status'] === 201 && $ins['linha']['id'] === 'E9',
   'insert_status devolve o status HTTP e a linha criada');

wa_db_set_transport(function () { return ['status' => 409, 'body' => '{}']; });
$ins409 = wa_db_insert_status('po_wa_envios', ['campanha_id' => 'C1', 'lead_id' => 'L1']);
ok($ins409['status'] === 409 && $ins409['linha'] === null,
   'insert_status devolve 409 sem inventar linha (409 e o cadeado, quem decide o que fazer e o chamador)');

wa_db_set_transport(function () { return ['status' => 500, 'body' => '{}']; });
$ins500 = wa_db_insert_status('po_wa_envios', ['campanha_id' => 'C1', 'lead_id' => 'L1']);
ok($ins500['status'] === 500, 'insert_status devolve o status de erro de verdade, nao 409');

wa_db_set_transport(function () { return null; });
$insRede = wa_db_insert_status('po_wa_envios', ['campanha_id' => 'C1', 'lead_id' => 'L1']);
ok($insRede['status'] === 0 && $insRede['linha'] === null,
   'insert_status com a rede fora devolve status 0, nao 409');

echo "test-wa-db OK\n";
