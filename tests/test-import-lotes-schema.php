<?php
// tests/test-import-lotes-schema.php
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

$sql = file_get_contents(__DIR__ . '/../supabase/migrations/2026-09-12-import-lotes.sql');
ok($sql !== false && $sql !== '', 'migracao existe e nao esta vazia');

ok(preg_match('/create table if not exists\s+public\.po_import_lotes/i', $sql) === 1,
   'cria a tabela de forma idempotente');
foreach (['hash','origem','arquivo','total','novos','preenchidos','created_at'] as $c) {
  ok(preg_match('/\b' . $c . '\b/', $sql) === 1 || substr_count($sql, $c) >= 1,
     "a tabela tem a coluna $c");
}
// O hash e a chave da idempotencia: sem unique, reaplicar o mesmo arquivo
// gravaria um segundo lote e a guarda nunca dispararia.
ok(preg_match('/hash[^,]*\bunique\b/i', $sql) === 1
   || preg_match('/create unique index if not exists[^;]*po_import_lotes[^;]*\(hash/is', $sql) === 1,
   'hash e unico');
// RLS ligada, no padrao das outras tabelas do subsistema.
ok(preg_match('/alter table\s+public\.po_import_lotes\s+enable row level security/i', $sql) === 1,
   'RLS ligada');

/* ------------------------------------------------------------
   Estado do lote (migracao de 12/09). Sem a coluna, o registro so pode ser
   gravado DEPOIS da escrita, e a guarda de idempotencia deixa passar o retry
   disparado com a primeira requisicao ainda rodando - que e exatamente o que
   o timeout do fetch produz. Com ela o registro tem duas fases: reserva antes
   de escrever (o unique vira lock) e conclusao depois.
------------------------------------------------------------ */
$est = file_get_contents(__DIR__ . '/../supabase/migrations/2026-09-12-import-lotes-estado.sql');
ok($est !== false && $est !== '', 'migracao do estado do lote existe e nao esta vazia');

ok(preg_match('/add column if not exists\s+concluido_at\s+timestamptz/i', $est) === 1,
   'acrescenta concluido_at de forma idempotente');
// 'add column' sem 'if not exists' derruba a migracao na segunda execucao, e
// no banco compartilhado quem aplica nao e quem escreveu.
ok(preg_match('/alter table[^;]*add column(?!\s+if not exists)/is', $est) !== 1,
   'nenhum add column sem if not exists');
// Nulo e o estado "em andamento": um NOT NULL DEFAULT now() aqui daria todo
// lote como concluido e a guarda bloquearia retry legitimo.
ok(preg_match('/concluido_at\s+timestamptz\s+not null/i', $est) !== 1,
   'concluido_at aceita nulo: nulo E o estado de importacao em andamento');

echo "test-import-lotes-schema OK\n";
