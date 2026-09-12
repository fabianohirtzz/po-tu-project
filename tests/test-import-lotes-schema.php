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

echo "test-import-lotes-schema OK\n";
