<?php
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

$sql = file_get_contents(__DIR__ . '/../supabase/migrations/2026-09-12-ficha-cliente.sql');
ok($sql !== false && $sql !== '', 'migracao existe e nao esta vazia');

$colunas = [
  'cpf','rg','data_nascimento','nacionalidade','estado_civil','profissao',
  'cep','endereco','numero','complemento','bairro','estado',
  'passaporte_numero','passaporte_orgao_emissor','passaporte_emissao','passaporte_validade',
  'contato_emergencia_nome','contato_emergencia_telefone','contato_emergencia_parentesco',
  'telefone_secundario','observacoes','origem_import','payload_import',
  'revisado','cliente',
];
foreach ($colunas as $c) {
  ok(preg_match('/add column if not exists\s+' . preg_quote($c, '/') . '\b/i', $sql) === 1,
     "migracao adiciona a coluna $c de forma idempotente");
}

// idempotencia: 'if not exists' em toda coluna, e 'if exists' na tabela.
ok(substr_count(strtolower($sql), 'add column if not exists') >= count($colunas),
   'toda coluna usa add column if not exists');

// o indice unico de cpf e PARCIAL: cpf vazio/null nao pode colidir entre
// centenas de leads do site que nao tem cpf.
ok(preg_match('/create unique index if not exists .*po_leads.*\(cpf\).*where/is', $sql) === 1,
   'indice unico de cpf e parcial (where cpf preenchido)');

echo "test-ficha-schema OK\n";
