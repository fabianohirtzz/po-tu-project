<?php
/* A migration 2026-11-05-destinos-espera.sql: po_destinos, palavras_chave e
   po_wa_espera. Tarefa 1 do plano 2026-11-05-robo-entende-a-frase.

   Le o ARQUIVO da migration, nao o banco: o teste roda em qualquer clone, sem
   credencial, e o que ele guarda e a forma do que vai ser aplicado. O
   pos-estado do banco e conferido por consulta na hora de rodar.

   Leitura NORMALIZADA no fim de linha: com core.autocrlf=true neste repo o
   mesmo arquivo nasce LF e vira CRLF no primeiro checkout, e regex ancorada
   em fim de linha falharia num dos dois estados. */
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

$sql = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../supabase/migrations/2026-11-05-destinos-espera.sql'));
ok($sql !== '' && $sql !== false, 'a migration existe e nao esta vazia');

/* ---------- as tres coisas que ela cria ---------- */
ok(preg_match('/create table if not exists po_destinos/i', $sql), 'cria po_destinos');
ok(preg_match('/create table if not exists po_wa_espera/i', $sql), 'cria po_wa_espera');
ok(preg_match('/alter table po_roteiros add column if not exists palavras_chave text not null default/i', $sql),
    'cria palavras_chave com default, para nenhum leitor precisar tratar null');

/* ---------- ADITIVA: nada de destrutivo ----------
   O portao do MCP recusa destrutivo e quem roda destrutivo e o dono, no SQL
   Editor. Uma migration que precisa dele nao roda pelo caminho normal. */
ok(!preg_match('/\bdrop\s+table\b/i', $sql), 'nenhum drop table');
ok(!preg_match('/\bdrop\s+column\b/i', $sql), 'nenhum drop column (po_leads.espera fica, como divida)');
ok(!preg_match('/\bdelete\s+from\b/i', $sql), 'nenhum delete');
ok(!preg_match('/\btruncate\b/i', $sql), 'nenhum truncate');
/* NENHUM `drop`, nem o de policy. O portao do MCP recusa qualquer `drop` -
   medido em 05/11/2026, esta migration foi recusada por causa de dois
   `drop policy if exists`. As tabelas sao novas, entao a policy nao pode
   pre-existir e o drop era ruido. A regra que isto guarda: o que roda tem de
   ser IDENTICO ao que esta versionado, senao o arquivo deixa de ser o
   registro do que foi aplicado. */
ok(!preg_match('/drop/i', $sql), 'nenhum drop de nenhum tipo: o portao do MCP recusa');

/* ---------- RLS: as duas tabelas, e SEM leitura anonima ----------
   O robo le com service_role, que IGNORA a RLS, entao a RLS aqui governa so o
   painel. E po_wa_espera guarda dado de lead: anonimo nao pode ler. */
ok(substr_count(strtolower($sql), 'enable row level security') === 2, 'RLS ligada nas duas tabelas novas');
ok(!preg_match('/to\s+anon/i', $sql), 'nenhuma policy para anon: a espera guarda dado de lead');
ok(preg_match('/create policy po_destinos_auth[\s\S]*?to authenticated/i', $sql), 'po_destinos so autenticado');
ok(preg_match('/create policy po_wa_espera_auth[\s\S]*?to authenticated/i', $sql), 'po_wa_espera so autenticado');

/* ---------- a espera: soft delete e o indice parcial ---------- */
ok(preg_match('/removido_at\s+timestamptz/i', $sql), 'a espera tem removido_at: a remocao e SOFT');
ok(preg_match('/create unique index[\s\S]{0,200}po_wa_espera[\s\S]{0,200}\(lead_id,\s*destino\)[\s\S]{0,60}where removido_at is null/i', $sql),
    'indice unico PARCIAL (lead_id, destino) onde removido_at is null');
ok(preg_match('/lead_id\s+uuid not null references po_leads\(id\)/i', $sql),
    'lead_id e uuid e aponta para po_leads: po_leads.id e uuid, conferido em 05/11');

/* ---------- os 29 destinos ----------
   O censo e o que impede um seed mutilado passar verde. */
if (!preg_match('/insert into po_destinos[\s\S]*?on conflict/i', $sql, $m)) {
    ok(false, 'achou o insert dos destinos');
}
preg_match_all("/\(\s*'([^']+)',\s*'([^']*)',\s*(\d+)\)/", $m[0], $linhas, PREG_SET_ORDER);
$destinos = [];
foreach ($linhas as $l) $destinos[$l[1]] = $l[2];

ok(count($destinos) === 29, 'os 29 destinos, e nao 28 nem 30: ' . count($destinos));
ok(isset($destinos['África do Sul']), 'AFRICA 4K resolvido como Africa do Sul (cliente, 04/11)');
ok(isset($destinos['Itália']), 'Italia existe: e o destino do pedido que originou tudo');
ok(isset($destinos['Peru']), 'Peru existe');

/* Toda palavra que o cliente digita tem de valer: vazia ou de menos de 4
   caracteres nunca casaria nada, e um destino sem nenhuma palavra util e um
   destino que o robo nunca reconhece - seed mutilado em silencio. */
foreach ($destinos as $nome => $palavras) {
    $uteis = 0;
    foreach (explode(',', $palavras) as $p) {
        $p = trim($p);
        if ($p !== '' && mb_strlen($p, 'UTF-8') >= 4) $uteis++;
    }
    ok($uteis >= 1, $nome . ' tem ao menos uma palavra util');
}

/* `nome` e unico: dois destinos de mesmo nome dividiriam o publico da
   transmissao em dois grupos que a tela mostraria como se fossem diferentes. */
ok(preg_match('/create unique index[\s\S]{0,120}po_destinos[\s\S]{0,60}\(nome\)/i', $sql), 'nome do destino e unico');

/* ---------- palavras_chave: so o que slug+titulo NAO cobrem ----------
   Ha teste em test-wa-roteiro.php exigindo que `asia` nao case dentro de
   `asiaticos`. Semear a palavra `asia` como sinonimo contradiria aquele
   teste, e os dois ficariam verdes medindo coisas opostas. */
if (preg_match_all('/update po_roteiros set palavras_chave = \'([^\']*)\'/i', $sql, $pc)) {
    foreach ($pc[1] as $lista) {
        foreach (explode(',', $lista) as $p) {
            ok(trim($p) !== 'asia', 'palavras_chave nunca semeia `asia`');
        }
    }
    ok(count($pc[1]) >= 5, 'semeia sinonimo nos roteiros do catalogo: ' . count($pc[1]));
} else {
    ok(false, 'achou os updates de palavras_chave');
}
/* Toda escrita de palavras_chave e condicionada a estar vazia: rodar a
   migration duas vezes nao pode apagar o que a cliente escreveu no painel. */
ok(substr_count($sql, "palavras_chave = ''") === substr_count(strtolower($sql), 'update po_roteiros set palavras_chave'),
    'todo update de palavras_chave so escreve se estiver vazio (reaplicar nao apaga a edicao dela)');

/* ---------- as 12 fichas de exemplo, e o que elas NAO podem ter ---------- */
preg_match_all("/\('EXEMPLO [^']+'\)/", $sql, $fichas);
ok(count($fichas[0]) === 12, 'as 12 fichas EXEMPLO: ' . count($fichas[0]));
ok(preg_match('/insert into po_leads \(nome, status, revisado, cliente\)/i', $sql),
    'as fichas nascem com as colunas explicitas');
ok(preg_match('/select v\.nome, \'semresposta\', true, false/i', $sql),
    'revisado = true de proposito: com o default false elas cairiam na fila da aba Revisao');

/* A regra que nao pode cair, e ela e de seguranca, nao de estilo: desde
   26/10 o WA_PHONE_ID esta preenchido, entao celular ficticio plausivel pode
   alcancar um estranho de verdade quando a conta puder disparar. */
ok(!preg_match('/insert into po_leads[\s\S]*?\btelefone\b/i', $sql), 'nenhuma ficha EXEMPLO com telefone');
ok(!preg_match('/insert into po_leads[\s\S]*?\bwa_id\b/i', $sql), 'nenhuma ficha EXEMPLO com wa_id');
ok(!preg_match('/\+55\d/', $sql), 'nenhum numero de telefone na migration inteira');

/* E elas tem de estar na espera, senao a Parte C nao tem o que filtrar. */
preg_match_all("/\('EXEMPLO [^']+',\s*'([^']+)'\)/", $sql, $pares);
ok(count($pares[0]) === 12, 'as 12 entradas na espera: ' . count($pares[0]));
$porDestino = array_count_values($pares[1]);
ok(count($porDestino) === 5, 'espalhadas em 5 destinos, para o seletor ter o que mostrar: ' . count($porDestino));
foreach ($porDestino as $d => $n) {
    ok(isset($destinos[$d]), 'o destino "' . $d . '" da espera existe em po_destinos (o seletor casa pelo nome)');
}
ok(max($porDestino) >= 3, 'ao menos um destino com 3 ou mais, para a contagem do grupo ficar visivel');

/* O insert da espera e idempotente: reaplicar nao duplica. */
ok(preg_match('/insert into po_wa_espera[\s\S]*?where not exists/i', $sql), 'o insert da espera e idempotente');
ok(preg_match('/insert into po_leads[\s\S]*?where not exists/i', $sql), 'o insert das fichas e idempotente');

echo "OK test-destinos-schema (" . count($destinos) . " destinos, " . count($pares[0]) . " na espera)\n";
