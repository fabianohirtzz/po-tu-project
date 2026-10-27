<?php
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }
$sql = @file_get_contents(__DIR__ . '/../supabase/migrations/2026-10-27-fluxo-conversa.sql');
ok($sql !== false, 'a migration de fluxo existe');

// 1. idempotencia: nenhum create/alter sem guarda
ok(!preg_match('/create table (?!if not exists)/i', $sql), 'todo create table tem if not exists');
ok(!preg_match('/add column (?!if not exists)/i', $sql), 'todo add column tem if not exists');
ok(substr_count(strtolower($sql), 'drop policy if exists') >= 3, 'as policies sao drop+create, idempotentes');

// 2. as tres tabelas e as colunas que o motor le
foreach (['po_wa_fluxos', 'po_wa_cards', 'po_wa_fluxo_versoes'] as $t) {
    ok(strpos($sql, 'public.' . $t) !== false, "cria $t");
    ok(preg_match('/alter table public\.' . $t . '\s+enable row level security/i', $sql), "RLS em $t");
}
foreach (['fluxo_versao_id', 'card_atual', 'tentativas'] as $c) {
    ok(preg_match('/po_wa_conversas add column if not exists\s+' . $c . '\b/i', $sql), "po_wa_conversas.$c");
}
foreach (['interesses', 'espera'] as $c) {
    ok(preg_match('/po_leads add column if not exists\s+' . $c . '\s+jsonb/i', $sql), "po_leads.$c e jsonb");
}

// 3. o indice de porta e PARCIAL: duas portas iguais podem coexistir se uma estiver desligada
ok(preg_match('/create unique index if not exists po_wa_fluxos_porta_ativa_uniq.*?where ativo/is', $sql),
   'o indice unico da porta vale so onde ativo');
ok(strpos($sql, "coalesce(gatilho_valor, '')") !== false,
   'gatilho_valor nulo entra no indice como string vazia, senao dois "qualquer anuncio" coexistiriam');

// 4. ativo nasce desligado: publicar nao liga
ok(preg_match('/ativo\s+boolean not null default false/i', $sql), 'ativo nasce false');

// 5. a conversao dos estados antigos
ok(strpos($sql, "'aguardando'") !== false && strpos($sql, 'aguardando_roteiro') !== false,
   'converte os estados antigos para aguardando');
ok(preg_match("/update public\.po_wa_conversas set estado = 'encerrado'/i", $sql),
   'converte qualificado/desqualificado/novo para encerrado');

// 6. repositorio publico: nenhum telefone que nao seja da faixa de teste
ok(!preg_match('/\+55(?!48900000)\d{10,11}/', $sql), 'nenhum telefone real na migration');
// 7. o seed (2026-10-27-fluxo-semeado.sql): idempotente, desligado, na versao 1, sem telefone real
$seed = file_get_contents(__DIR__ . '/../supabase/migrations/2026-10-27-fluxo-semeado.sql');
ok($seed !== false, 'a migration semeada existe');
ok(preg_match('/on conflict .* do nothing/i', $seed), 'o seed e idempotente');
ok(!preg_match('/ativo\s*=\s*true/i', $seed), 'o seed NAO liga nenhum fluxo');
ok(preg_match('/po_wa_fluxo_versoes/i', $seed) && preg_match('/\bversao\b.*\b1\b/i', $seed),
   'o seed publica a versao 1');
ok(!preg_match('/\+55(?!48900000)\d{10,11}/', $seed), 'nenhum telefone real no seed');
echo "test-fluxo-migration: ok\n";
