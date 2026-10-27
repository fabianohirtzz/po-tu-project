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

/* 2b. O comentario do contrato de po_wa_cards.opcoes. Quem construir o painel le
   AQUELE comentario: se ele esconder o `id`, a tela produz opcao sem id, e
   wa_send_buttons recusa a mensagem inteira (id vazio ou repetido), entao o card
   nunca sai e o cliente fica mudo. O `id` e load-bearing, nao enfeite. */
ok(preg_match('/opcoes\s+jsonb.*--\s*\[\{\s*id\s*,\s*rotulo\s*,\s*destino/i', $sql),
   'o comentario de po_wa_cards.opcoes declara o id antes de rotulo e destino');

/* 2c. A conversao de estado e de RODADA UNICA. Reaplicada depois do go-live, ela
   colapsa o marcador de cadeia parada ('novo' + card_atual) e as conversas
   recem-criadas em 'encerrado': a porta seria resolvida de novo e o PDF
   reenviado. O cabecalho tem que avisar, senao alguem roda "para garantir". */
$cabeca = substr($sql, 0, strpos($sql, 'create table'));
ok(preg_match('/(rodada unica|uma vez so|uma rodada so|nao reaplicar)/iu', $cabeca),
   'o cabecalho avisa que a conversao de estado e de rodada unica');
ok(stripos($cabeca, 'go-live') !== false || stripos($cabeca, 'no ar') !== false,
   'e diz que o limite e o go-live (depois dele, nao reaplicar)');

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
