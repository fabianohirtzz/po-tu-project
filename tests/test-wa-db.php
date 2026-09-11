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

echo "test-wa-db OK\n";
