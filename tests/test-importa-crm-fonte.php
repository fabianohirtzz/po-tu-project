<?php
/* tests/test-importa-crm-fonte.php — trava por FONTE duas garantias de
   scripts/importa-crm.php que a camada pura ja tinha testada, mas o SCRIPT
   em si nao: o revisor mutou o script (select curto do brief original, e
   wa_db_select() cru em vez de wa_db_select_estrito) e a suite inteira
   continuou verde, porque nada olhava para o texto do script de verdade -
   so para lib/wa-import.php e lib/wa-import-carga.php, que nao mudaram.

   Mesmo molde de tests/test-filtros.mjs (que ja le painel/app.js cru e
   confere com regex/strpos que a tela certa usa a funcao certa). */
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

$src = file_get_contents(__DIR__ . '/../scripts/importa-crm.php');
ok($src !== false, 'scripts/importa-crm.php existe e foi lido');

ok(strpos($src, 'wa_import_select_base()') !== false,
   'o script le a base com o select DERIVADO da whitelist (wa_import_select_base), nao um select escrito a mao');

ok(strpos($src, 'wa_db_select_estrito') !== false,
   'o script usa a leitura ESTRITA da base (distingue "base vazia" de "erro de leitura")');

/* 'wa_db_select(' cru (sem o '_estrito') devolve [] tanto pra "acabou" quanto
   pra "o Supabase recusou" - com Supabase fora do ar, as 778 fichas do CRM
   entrariam como leads NOVOS e duplicariam a base inteira em silencio. O
   '\b' antes evita casar com a propria wa_db_select_estrito(. */
ok(!preg_match('/\bwa_db_select\s*\(/', $src),
   'o script nunca chama wa_db_select() cru - so a variante _estrito');

/* O select curto do brief original ('select=id,telefone,cpf,nome,...') faz
   wa_import_preenche() tratar toda coluna fora da lista (observacoes, rg,
   passaporte_*, origem_manual, telefone_secundario...) como vazia, e a carga
   do CRM SOBRESCREVERIA ficha ja preenchida no painel. */
ok(!preg_match('/select=id,\s*telefone,\s*cpf,\s*nome/', $src),
   'o script nao tem um select de colunas escrito a mao (o do brief original)');

echo "test-importa-crm-fonte OK\n";
