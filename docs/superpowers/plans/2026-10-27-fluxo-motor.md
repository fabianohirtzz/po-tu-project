# Fluxo de conversa, Plano A: o motor

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Trocar a máquina de estados escrita à mão de `lib/wa-motor.php` por um interpretador que percorre um grafo de cards publicado no banco, sem tela nova e sem mudar nada para a cliente.

**Architecture:** Um novo `lib/wa-fluxo.php` guarda as listas fechadas, o roteamento puro (`wa_fluxo_proximo`), a validação pura (`wa_fluxo_valida`) e a execução de card (`wa_fluxo_executa`). `wa_processar` passa a resolver a porta de entrada, achar o card atual e delegar; as nove leis do motor (eco, SAIR, idempotência, estado só avança com envio, `humano` nunca volta) ficam onde estão, acima do fluxo. O motor lê **só** o retrato imutável em `po_wa_fluxo_versoes.grafo`, e cada conversa guarda em qual versão está rodando.

**Tech Stack:** PHP 8 em cPanel, sem Composer e sem build. Supabase via PostgREST. Testes por `tests/run.php`.

**Spec:** `docs/superpowers/specs/2026-10-27-fluxo-conversa-painel-design.md`

## Global Constraints

- **Nenhum teste toca a rede.** Usar os transportes injetáveis: `wa_motor_set_deps`, `wa_timeout_set_deps`, `wa_db_set_transport`, `wa_set_transport`, e o novo `wa_fluxo_set_deps`. Única exceção da suíte, já documentada: `tests/test-wa-schema.php`, que pula com SKIP sem `config.local.php`.
- **Repositório PÚBLICO.** Nenhum dado real de cliente em fixture, comentário ou migration. Telefone de teste: `+5548900000001` em diante.
- **Testes:** `.php` com `function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }` e `exit(1)`; `.mjs` com `node:assert/strict`. Rodar tudo com `php tests/run.php`.
- **Migration nunca é editada depois de rodar.** Toda migration é idempotente (`create table if not exists`, `add column if not exists`, `drop policy if exists` + `create policy`).
- **Limite de caracteres da Cloud API:** 3 botões por mensagem, rótulo de 20 **caracteres** (`mb_strlen`, nunca `strlen`); lista de 10 linhas, título 24, descrição 72; corpo interativo e legenda 1024; texto simples 4096.
- **Janela de 24h:** fora dela só sai template aprovado, e este plano não manda template nenhum.
- **`venda`, `venda_at`, `notas`, `cliente` e `revisado` nunca entram em nenhuma whitelist deste plano.** `status` escrito por fluxo aceita só `novo`, `atendimento`, `perdido`.
- **Copy em português, sem travessão e sem emoji** (regra Freela), inclusive nos textos da migration semeada e nas mensagens de erro da validação.
- Fora do Plano A: `painel/fluxo.js`, `fluxo.php`, `painel/fluxo.css`, a aba do painel, o simulador, o filtro de transmissão por interesse, e a remoção de `painel/textos.js` (tudo isso é Plano B ou C). Nenhum arquivo de `painel/` é tocado, portanto **nenhum `?v=` e nenhuma linha de `assets-lock.json` mudam neste plano**.
- **Direção das dependências, e ela é uma só:** `wa-db.php` / `wa-send.php` / `po-data.php` ← `wa-fluxo.php` ← `wa-motor.php` ← `wa-timeout.php`. `wa-fluxo.php` **nunca** faz `require` de `wa-motor.php`. Por isso a Task 3 move quatro funções de `wa-motor.php` para `wa-fluxo.php` (`wa_limpa_pontuacao`, `wa_registra_saida`, `wa_msg_do_robo`, `wa_acha_roteiro`): o interpretador precisa delas e o caminho inverso fecharia um ciclo de `require`. Nenhuma delas é duplicada; o motor passa a consumi-las de `wa-fluxo.php`.

## Review Focus

Cinco classes de entrada que o design implica e que nenhuma tarefa exercitaria por conta própria. Cada linha tem o teste que a prende, na tarefa que é dona do código.

1. **`card_atual` que não existe mais no retrato** (versão apontada à mão, id órfão, card apagado antes de republicar): a conversa não pode ficar muda nem estourar. Esperado: cai no `humano` do fluxo, ou encerra, e loga. → **Task 8**.
2. **Botão tocado numa mensagem antiga.** O cliente rola a conversa e toca num botão de duas mensagens atrás: o id não casa nenhuma opção do card atual. Esperado: `fallback`, nunca um card de outro ponto do fluxo. → **Task 4**.
3. **Card `lista` dinâmica com catálogo vazio** (Supabase fora, cache frio, nenhum roteiro ativo): a Graph recusa lista de zero linhas e a mensagem inteira não sai. Esperado: cai em texto puro, como `wa_processar` já faz hoje. → **Task 7**.
4. **Texto de card vazio ou só espaço** (a cliente apagou o conteúdo e publicou antes da validação existir, ou a variável sumiu). Esperado: o envio recusa e o estado **não avança**. → **Task 7**.
5. **`roteiro_slug` de roteiro que saiu do ar** entre a escolha e o card `pdf`. Esperado: não manda arquivo quebrado, segue para `humano` e loga. → **Task 7**.

---

## Estrutura de arquivos

**Criar**

| Arquivo | Responsabilidade |
|---|---|
| `supabase/migrations/2026-10-27-fluxo-conversa.sql` | tabelas, índice de porta, colunas novas, conversão de estado, RLS |
| `supabase/migrations/2026-10-27-fluxo-semeado.sql` | os dois fluxos semeados, publicados na versão 1, `ativo = false` |
| `lib/wa-fluxo.php` | listas fechadas, roteamento puro, validação pura, execução de card, versionamento |
| `tests/test-fluxo-migration.php` | o TEXTO das duas migrations (idempotência, índice, whitelist, conversão) |
| `tests/test-wa-send-buttons.php` | `wa_send_buttons`: recusas e payload |
| `tests/test-wa-fluxo-texto.php` | variáveis, `{data}` caindo em `periodo`, tamanho de pior caso, whitelist do `marca` |
| `tests/test-wa-fluxo-rota.php` | `wa_fluxo_proximo` e `wa_fluxo_sem_resposta` |
| `tests/test-wa-fluxo-valida.php` | as 14 regras que bloqueiam e as 2 que avisam |
| `tests/test-wa-fluxo-versao.php` | `wa_fluxo_grafo`, `wa_fluxo_publica`, conversa presa na versão |
| `tests/test-wa-fluxo-executa.php` | envio por tipo de card, e os itens 3, 4 e 5 do Review Focus |
| `tests/test-wa-fluxo-leis.php` | as nove leis sobrevivem ao interpretador |
| `tests/test-wa-fluxo-semeado.php` | o fluxo semeado valida limpo e é percorrível de ponta a ponta |

**Modificar**

| Arquivo | O quê |
|---|---|
| `lib/wa-send.php` | `wa_send_buttons()` |
| `lib/wa-motor.php` | `wa_processar` delega o roteamento; porta só fora de card que espera |
| `lib/wa-timeout.php` | `estado='aguardando'`, `WA_HORAS_LEMBRETE` 24 → 20, lembrete do card, guarda da janela, `sem_resposta`, carimbo `perdido` |
| `tests/test-wa-motor.php` | adaptar ao motor novo |
| `tests/test-wa-timeout.php` | adaptar ao relógio novo |
| `tests/test-wa-schema.php` | acrescentar as três tabelas novas à sonda de ambiente |

---

## Task 1: Migration das tabelas

**Files:**
- Create: `supabase/migrations/2026-10-27-fluxo-conversa.sql`
- Modify: `tests/test-wa-schema.php`
- Test: `tests/test-fluxo-migration.php`

**Interfaces:**
- Consumes: nada.
- Produces: as tabelas `po_wa_fluxos`, `po_wa_cards`, `po_wa_fluxo_versoes`; as colunas `po_wa_conversas.fluxo_versao_id`, `.card_atual`, `.tentativas`; `po_leads.interesses`, `.espera`; o índice `po_wa_fluxos_porta_ativa_uniq`.

- [ ] **Step 1: Write the failing test**

Em `tests/test-fluxo-migration.php`, lendo o SQL como texto (nenhuma rede):

```php
<?php
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }
$sql = file_get_contents(__DIR__ . '/../supabase/migrations/2026-10-27-fluxo-conversa.sql');
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
echo "test-fluxo-migration: ok\n";
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-fluxo-migration.php`
Expected: FAIL com "a migration de fluxo existe"

- [ ] **Step 3: Escrever `supabase/migrations/2026-10-27-fluxo-conversa.sql`**

O DDL está na seção 7 do spec e é para ser copiado dela literalmente, com três acréscimos que o spec descreve no corpo mas não no bloco SQL:

- `po_wa_cards` ganha `fallback uuid` (a saída "respondeu outra coisa", §6 do spec) e `ordem int not null default 0`.
- `po_wa_fluxo_versoes.grafo` é `jsonb not null`.
- As policies seguem o padrão da migration `2026-09-11-whatsapp-motor.sql`: `drop policy if exists` + `create policy <tabela>_auth on public.<tabela> for all to authenticated using (true) with check (true)`.

Cabeçalho com o comentário de sempre (o que é, que roda no SQL Editor, que é idempotente).

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test-fluxo-migration.php`
Expected: `test-fluxo-migration: ok`

- [ ] **Step 5: Acrescentar as três tabelas à sonda de ambiente**

Em `tests/test-wa-schema.php`, incluir `po_wa_fluxos`, `po_wa_cards` e `po_wa_fluxo_versoes` na lista de tabelas sondadas, no mesmo formato das que já estão lá. Não mudar a lógica de SKIP.

- [ ] **Step 6: Run the suite**

Run: `php tests/run.php`
Expected: todos PASS (o `test-wa-schema` sai SKIP sem `config.local.php`, que é o esperado)

- [ ] **Step 7: Commit**

```bash
git add supabase/migrations/2026-10-27-fluxo-conversa.sql tests/test-fluxo-migration.php tests/test-wa-schema.php
git commit -m "feat(fluxo): tabelas do grafo de conversa"
```

---

## Task 2: `wa_send_buttons`

**Files:**
- Modify: `lib/wa-send.php`
- Test: `tests/test-wa-send-buttons.php`

**Interfaces:**
- Consumes: `wa_destino($para)`, `wa_envia($mensagem, $phone_id = null)`, `wa_set_transport($f)` — todos já em `lib/wa-send.php`.
- Produces: `wa_send_buttons(string $para, string $corpo, array $botoes): array` com `['ok'=>bool, 'wamid'=>?string, 'erro'=>?string]`. `$botoes` é lista de `['id'=>string, 'rotulo'=>string]`.

- [ ] **Step 1: Write the failing test**

Em `tests/test-wa-send-buttons.php`. O transporte é injetado e guarda o payload; nenhuma rede.

```php
<?php
require __DIR__ . '/../lib/wa-send.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

$ENVIADO = null;
wa_set_transport(function ($url, $payload, $headers) use (&$ENVIADO) {
    $ENVIADO = json_decode($payload, true);
    return ['status' => 200, 'body' => '{"messages":[{"id":"wamid.TESTE"}]}'];
});

$fone = '+5548900000001';
$b2   = [['id' => 'sim', 'rotulo' => 'Sim, funciona'], ['id' => 'nao', 'rotulo' => 'Nao nessa data']];

// 1. caminho feliz, 2 botoes
$r = wa_send_buttons($fone, 'A data serve?', $b2);
ok($r['ok'] === true && $r['wamid'] === 'wamid.TESTE', 'dois botoes saem');
ok($ENVIADO['type'] === 'interactive', 'tipo interactive');
ok($ENVIADO['interactive']['type'] === 'button', 'interactive.type e button');
ok($ENVIADO['interactive']['body']['text'] === 'A data serve?', 'o corpo vai no body.text');
ok(count($ENVIADO['interactive']['action']['buttons']) === 2, 'dois botoes no payload');
ok($ENVIADO['interactive']['action']['buttons'][0]['type'] === 'reply', 'cada botao e type reply');
ok($ENVIADO['interactive']['action']['buttons'][0]['reply']['id'] === 'sim', 'o id vai no reply.id');
ok($ENVIADO['interactive']['action']['buttons'][1]['reply']['title'] === 'Nao nessa data', 'o rotulo vai no reply.title');

// 2. um e tres botoes tambem saem
$b1 = [['id' => 'a', 'rotulo' => 'Um']];
$b3 = array_merge($b2, [['id' => 'outro', 'rotulo' => 'Ver outro roteiro']]);
ok(wa_send_buttons($fone, 'x', $b1)['ok'] === true, 'um botao sai');
ok(wa_send_buttons($fone, 'x', $b3)['ok'] === true, 'tres botoes saem');

// 3. RECUSAS. Em cada uma, nada e enviado e a razao vem no erro.
$casos = [
  'zero botoes'        => [],
  'quatro botoes'      => array_merge($b3, [['id' => 'd', 'rotulo' => 'Quarto']]),
  'rotulo vazio'       => [['id' => 'a', 'rotulo' => '  ']],
  'rotulo com 21'      => [['id' => 'a', 'rotulo' => str_repeat('a', 21)]],
  'rotulos repetidos'  => [['id' => 'a', 'rotulo' => 'Sim'], ['id' => 'b', 'rotulo' => 'Sim']],
  'id vazio'           => [['id' => '', 'rotulo' => 'Sim']],
  'ids repetidos'      => [['id' => 'a', 'rotulo' => 'Sim'], ['id' => 'a', 'rotulo' => 'Nao']],
];
foreach ($casos as $nome => $bot) {
    $ENVIADO = null;
    $r = wa_send_buttons($fone, 'x', $bot);
    ok($r['ok'] === false, "recusa: $nome");
    ok($r['wamid'] === null, "recusa sem wamid: $nome");
    ok(trim((string) $r['erro']) !== '', "recusa com motivo: $nome");
    ok($ENVIADO === null, "recusa sem tocar o transporte: $nome");
}

// 4. o limite e em CARACTERES, nao em bytes: 20 letras acentuadas passam
$ENVIADO = null;
$r = wa_send_buttons($fone, 'x', [['id' => 'a', 'rotulo' => str_repeat('ç', 20)]]);
ok($r['ok'] === true, '20 caracteres acentuados passam (mb_strlen, nao strlen)');

// 5. corpo vazio recusa, e corpo acima de 1024 recusa (a Meta devolveria 400 e o cliente nada)
ok(wa_send_buttons($fone, '   ', $b2)['ok'] === false, 'corpo vazio recusa');
ok(wa_send_buttons($fone, str_repeat('a', 1025), $b2)['ok'] === false, 'corpo acima de 1024 recusa');

// 6. telefone que nao normaliza recusa antes de gastar a chamada, como as irmas
$ENVIADO = null;
ok(wa_send_buttons('nao-e-telefone', 'x', $b2)['ok'] === false, 'telefone invalido recusa');
ok($ENVIADO === null, 'telefone invalido nao chega ao transporte');

echo "test-wa-send-buttons: ok\n";
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-wa-send-buttons.php`
Expected: FAIL com "Call to undefined function wa_send_buttons()"

- [ ] **Step 3: Implementar `wa_send_buttons(string $para, string $corpo, array $botoes): array` em `lib/wa-send.php`**

No mesmo porte de `wa_send_list` (mesma ordem: normaliza o destino, monta, chama `wa_envia`), com uma diferença deliberada que vai comentada no código: **`wa_send_list` trunca com `mb_substr`, esta recusa**. Truncar rótulo de botão muda o que o cliente lê sem mudar o destino, e o botão passa a dizer uma coisa e levar a outra. Medir com `mb_strlen($s, 'UTF-8')`, nunca `strlen`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test-wa-send-buttons.php`
Expected: `test-wa-send-buttons: ok`

- [ ] **Step 5: Commit**

```bash
git add lib/wa-send.php tests/test-wa-send-buttons.php
git commit -m "feat(fluxo): wa_send_buttons, que recusa em vez de truncar"
```

---

## Task 3: Listas fechadas, variáveis e a whitelist do `marca`

**Files:**
- Create: `lib/wa-fluxo.php`
- Test: `tests/test-wa-fluxo-texto.php`

**Interfaces:**
- Consumes: `po_ano($r)` de `lib/po-data.php` (só como referência do defeito do `data_label`; esta task não o chama). De `lib/wa-motor.php`, as quatro funções que esta task **move** para cá: `wa_limpa_pontuacao($t)`, `wa_registra_saida($wa_id, $envio, $tipo, $texto)`, `wa_msg_do_robo($wamid)`, `wa_acha_roteiro($slug, $roteiros)`.
- Produces: as quatro funções movidas, agora definidas em `lib/wa-fluxo.php` e consumidas pelo motor, mais:
  - `WA_CARD_TIPOS = ['texto','botoes','lista','pdf','marca','ir_para_fluxo','humano','fim']`
  - `WA_CARD_ESPERA = ['botoes','lista']`
  - `WA_CARD_TERMINAL = ['humano','fim']`
  - `WA_SEM_RESPOSTA_TIPOS = ['texto','marca','humano','fim']`
  - `WA_FLUXO_VARS = ['nome','roteiro','data']`
  - `WA_FLUXO_VARS_ROTEIRO = ['roteiro','data']`
  - `WA_FLUXO_VAR_MAX = ['nome'=>40,'roteiro'=>60,'data'=>40]`
  - `WA_FLUXO_CAMPOS_MARCAVEIS = ['status','qualif_data','qualif_grupo','qualif_at','interesses','espera']`
  - `WA_FLUXO_STATUS_PERMITIDOS = ['novo','atendimento','perdido']`
  - `WA_FLUXO_LIMITE = ['texto'=>4096,'botoes'=>1024,'lista'=>1024,'pdf'=>1024,'humano'=>4096,'fim'=>4096]`
  - `wa_fluxo_vars_do_roteiro(?array $r, string $nome): array` devolve `['nome'=>, 'roteiro'=>, 'data'=>]`
  - `wa_fluxo_texto(string $texto, array $vars): string`
  - `wa_fluxo_tam_pior_caso(string $texto): int`
  - `wa_fluxo_campos_marca(array $config, array $ctx): array`
  - `wa_fluxo_set_deps(array $d)` e `wa_fl_call(string $nome, ...$args)`, no porte de `wa_motor_set_deps`/`wa_call`, com os padrões `['select'=>'wa_db_select','insert'=>'wa_db_insert','update'=>'wa_db_update','roteiros'=>'po_fetch_roteiros','send_text'=>'wa_send_text','send_buttons'=>'wa_send_buttons','send_list'=>'wa_send_list','send_doc'=>'wa_send_document']`

- [ ] **Step 1: Write the failing test**

```php
<?php
require __DIR__ . '/../lib/wa-fluxo.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

// 1. as listas fechadas existem e tem exatamente o conteudo esperado, na ordem
ok(WA_CARD_TIPOS === ['texto','botoes','lista','pdf','marca','ir_para_fluxo','humano','fim'], 'WA_CARD_TIPOS');
ok(WA_CARD_ESPERA === ['botoes','lista'], 'WA_CARD_ESPERA');
ok(WA_CARD_TERMINAL === ['humano','fim'], 'WA_CARD_TERMINAL');
ok(WA_SEM_RESPOSTA_TIPOS === ['texto','marca','humano','fim'], 'sem_resposta so aceita card que nao espera');
ok(WA_FLUXO_VARS === ['nome','roteiro','data'], 'WA_FLUXO_VARS');

// 2. a whitelist NAO contem o que apagaria faturamento nem o que autoriza disparo pago
foreach (['venda','venda_at','notas','cliente','revisado','telefone','opt_out_at'] as $proibido) {
    ok(!in_array($proibido, WA_FLUXO_CAMPOS_MARCAVEIS, true), "$proibido fora da whitelist");
}
ok(!in_array('venda', WA_FLUXO_STATUS_PERMITIDOS, true), 'fluxo nao carimba status venda');
ok(!in_array('negociacao', WA_FLUXO_STATUS_PERMITIDOS, true), 'fluxo nao carimba status negociacao');

// 3. VARIAVEIS. O {data} de hoje sai vazio porque data_label e string vazia e ?? nao pega.
$r_periodo = ['titulo' => 'Turquia com Antalia', 'data_label' => '', 'periodo' => '27/10/27 a 12/11/27'];
$v = wa_fluxo_vars_do_roteiro($r_periodo, 'Marta');
ok($v['data'] === '27/10/27 a 12/11/27', 'data_label vazio cai em periodo (?: e nao ??)');
ok($v['roteiro'] === 'Turquia com Antalia', 'roteiro vem do titulo');
ok($v['nome'] === 'Marta', 'nome vem do lead');

$r_label = ['titulo' => 'Chile', 'data_label' => '10/03/27 a 20/03/27', 'periodo' => 'ignorar'];
ok(wa_fluxo_vars_do_roteiro($r_label, 'X')['data'] === '10/03/27 a 20/03/27', 'data_label ganha quando existe');

$r_nada = ['titulo' => 'Sem data', 'data_label' => '', 'periodo' => ''];
ok(wa_fluxo_vars_do_roteiro($r_nada, 'X')['data'] === 'na data prevista', 'sem nenhuma das duas, texto de recurso');
ok(wa_fluxo_vars_do_roteiro(null, 'X')['roteiro'] === '', 'sem roteiro no contexto, roteiro vazio');

// 4. substituicao, e nenhum {buraco} sobra na mensagem do cliente
ok(wa_fluxo_texto('Oi {nome}, a {roteiro} sai em {data}.', $v) === 'Oi Marta, a Turquia com Antalia sai em 27/10/27 a 12/11/27.', 'substitui as tres');
ok(strpos(wa_fluxo_texto('Oi {nome}. {inexistente} fim', $v), '{') === false, 'buraco desconhecido e removido, nao vai para o cliente');
ok(wa_fluxo_texto('Perfeito, {nome}.', ['nome' => '', 'roteiro' => '', 'data' => '']) === 'Perfeito.', 'nome vazio nao deixa pontuacao orfa');

// 5. tamanho conferido na expansao de PIOR CASO, nao no texto cru
ok(wa_fluxo_tam_pior_caso('abc') === 3, 'texto sem variavel conta o proprio tamanho');
ok(wa_fluxo_tam_pior_caso('{roteiro}') === 60, 'roteiro conta 60');
ok(wa_fluxo_tam_pior_caso('{nome} e {data}') === 40 + 3 + 40, 'soma as variaveis pelo maximo e o literal');

// 6. wa_fluxo_campos_marca leva EXATAMENTE a whitelist, nada mais
$ctx = ['roteiro_slug' => 'turquia'];
$cfg = ['status' => 'atendimento', 'qualif_data' => true, 'venda' => 99999,
        'notas' => 'apagar tudo', 'cliente' => true, 'revisado' => true, 'interesses' => '+roteiro'];
$campos = wa_fluxo_campos_marca($cfg, $ctx);
ok(!array_key_exists('venda', $campos), 'venda nao passa');
ok(!array_key_exists('notas', $campos), 'notas nao passa');
ok(!array_key_exists('cliente', $campos), 'cliente nao passa');
ok(!array_key_exists('revisado', $campos), 'revisado nao passa');
ok($campos['status'] === 'atendimento', 'status permitido passa');
ok($campos['qualif_data'] === true, 'qualif_data passa');
ok($campos['interesses'] === ['turquia'], 'interesses vira a lista com o slug do contexto');
ok(array_diff(array_keys($campos), WA_FLUXO_CAMPOS_MARCAVEIS) === [], 'nenhuma chave fora da whitelist');

// status fora da lista e DESCARTADO, nao gravado
ok(!array_key_exists('status', wa_fluxo_campos_marca(['status' => 'venda'], $ctx)), 'status venda e descartado');
ok(!array_key_exists('status', wa_fluxo_campos_marca(['status' => 'negociacao'], $ctx)), 'status negociacao e descartado');

// interesses sem slug no contexto nao grava lista com vazio dentro
ok(!array_key_exists('interesses', wa_fluxo_campos_marca(['interesses' => '+roteiro'], [])), 'sem slug, interesses nao e gravado');

echo "test-wa-fluxo-texto: ok\n";
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-wa-fluxo-texto.php`
Expected: FAIL ao abrir `lib/wa-fluxo.php`, que ainda não existe

- [ ] **Step 3: Criar `lib/wa-fluxo.php` com as constantes e as quatro funções puras**

`require_once` de `wa-db.php`, `wa-send.php` e `po-data.php`, no porte de `lib/wa-motor.php`, e **nunca** de `wa-motor.php` (ver a direção das dependências nas Global Constraints). Quatro pontos que o teste não determina:

- **Mover** `wa_limpa_pontuacao`, `wa_registra_saida`, `wa_msg_do_robo` e `wa_acha_roteiro` de `lib/wa-motor.php` para cá, com os comentários que elas já carregam, e acrescentar `require_once __DIR__ . '/wa-fluxo.php'` no topo de `wa-motor.php`. Rodar `php tests/run.php` depois do movimento: as três primeiras são usadas pelo motor e pelo `wa-timeout.php`, e a suíte acusa qualquer chamada que tenha ficado sem definição.
- `wa_fluxo_texto` usa `wa_limpa_pontuacao()` para o caso do nome vazio ("Perfeito, {nome}." com nome vazio chegava ao cliente como "Perfeito,.").
- `wa_fluxo_campos_marca` trata `interesses` e `espera` como **acréscimo**: o valor `'+roteiro'` na config significa "acrescenta o slug do contexto", e `'+destino'` idem. Quem faz o merge com o que já está na coluna é a Task 7; aqui a função devolve só a lista do que acrescentar.
- O cabeçalho do arquivo leva o comentário que explica por que `cliente` e `revisado` estão fora da whitelist: são as duas colunas que decidem quem recebe disparo pago, e um fluxo desenhado no painel nunca se autoriza a entrar numa campanha.

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test-wa-fluxo-texto.php`
Expected: `test-wa-fluxo-texto: ok`

- [ ] **Step 5: Commit**

```bash
git add lib/wa-fluxo.php tests/test-wa-fluxo-texto.php
git commit -m "feat(fluxo): listas fechadas, variaveis e a whitelist do card marca"
```

---

## Task 4: Roteamento puro

**Files:**
- Modify: `lib/wa-fluxo.php`
- Test: `tests/test-wa-fluxo-rota.php`

**Interfaces:**
- Consumes: `WA_CARD_TIPOS`, `WA_CARD_ESPERA`, `WA_SEM_RESPOSTA_TIPOS` da Task 3.
- Produces:
  - `WA_FLUXO_MAX_TENTATIVAS = 2`
  - `wa_fluxo_proximo(array $grafo, string $card_id, string $resposta, array $ctx): array` → `['card'=>?string, 'ctx'=>array, 'motivo'=>string]`, `motivo` ∈ `opcao|escolheu|fallback|socorro|invalido`
  - `wa_fluxo_sem_resposta(array $grafo, string $card_id): ?string`
  - Formato do `$grafo`: `['id'=>string, 'nome'=>string, 'inicial'=>string, 'humano'=>?string, 'porta'=>string, 'porta_valor'=>?string, 'cards'=>[id => card]]`, onde cada card é `['id','tipo','titulo','texto','opcoes','config','proximo','fallback','sem_resposta','lembrete_ativo','lembrete_texto']` e cada opção é `['id','rotulo','destino']`.
  - Formato do `$ctx`: `['roteiro_slug'=>?string, 'tentativas'=>int]`

- [ ] **Step 1: Write the failing test**

```php
<?php
require __DIR__ . '/../lib/wa-fluxo.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

function card($id, $tipo, $extra = []) {
    return array_merge(['id' => $id, 'tipo' => $tipo, 'titulo' => $id, 'texto' => 't',
        'opcoes' => [], 'config' => [], 'proximo' => null, 'fallback' => null,
        'sem_resposta' => null, 'lembrete_ativo' => true, 'lembrete_texto' => 'l'], $extra);
}

$grafo = ['id' => 'f1', 'nome' => 'Boas-vindas', 'inicial' => 'bv', 'humano' => 'hum',
          'porta' => 'mensagem_nova', 'porta_valor' => null, 'cards' => [
  'bv'   => card('bv', 'botoes', ['opcoes' => [
              ['id' => 'b1', 'rotulo' => 'Um roteiro', 'destino' => 'lst'],
              ['id' => 'b2', 'rotulo' => 'Programacao', 'destino' => 'prog'],
              ['id' => 'b3', 'rotulo' => 'Falar com a Lais', 'destino' => 'hum']],
            'fallback' => 'ops']),
  'lst'  => card('lst', 'lista', ['config' => ['fonte' => 'roteiros'], 'proximo' => 'pdf', 'fallback' => 'ops']),
  'est'  => card('est', 'lista', ['config' => ['fonte' => 'estatica'], 'opcoes' => [
              ['id' => 'a', 'rotulo' => '2027', 'destino' => 'lst'],
              ['id' => 'b', 'rotulo' => '2028', 'destino' => 'lst']], 'fallback' => 'ops']),
  'pdf'  => card('pdf', 'pdf', ['proximo' => 'mrc']),
  'mrc'  => card('mrc', 'marca', ['proximo' => 'dt']),
  'dt'   => card('dt', 'botoes', ['opcoes' => [['id' => 's', 'rotulo' => 'Sim', 'destino' => 'grp']],
              'fallback' => 'ops', 'sem_resposta' => 'fim']),
  'prog' => card('prog', 'lista', ['config' => ['fonte' => 'roteiros', 'ano' => '2027'], 'proximo' => 'lst']),
  'grp'  => card('grp', 'botoes', ['opcoes' => [['id' => 'j', 'rotulo' => 'Ja viajei', 'destino' => 'hum']], 'fallback' => 'ops']),
  'ops'  => card('ops', 'botoes', ['opcoes' => [['id' => 'v', 'rotulo' => 'Ver roteiros', 'destino' => 'lst']], 'fallback' => 'hum']),
  'hum'  => card('hum', 'humano'),
  'fim'  => card('fim', 'fim'),
  'vai'  => card('vai', 'ir_para_fluxo', ['config' => ['fluxo' => 'espera-id']]),
]];
$ctx0 = ['roteiro_slug' => null, 'tentativas' => 0];

// 1. botao casa a opcao pelo id
$r = wa_fluxo_proximo($grafo, 'bv', 'b2', $ctx0);
ok($r['card'] === 'prog' && $r['motivo'] === 'opcao', 'botao vai para o destino da opcao');
ok($r['ctx']['tentativas'] === 0, 'acerto zera tentativas');

// 2. lista dinamica: a resposta E o slug, vai para o unico destino e grava o contexto
$r = wa_fluxo_proximo($grafo, 'lst', 'turquia', $ctx0);
ok($r['card'] === 'pdf' && $r['motivo'] === 'escolheu', 'lista dinamica vai para proximo');
ok($r['ctx']['roteiro_slug'] === 'turquia', 'lista dinamica grava o slug no contexto');

// 3. lista estatica casa por id de linha, e NAO grava slug
$r = wa_fluxo_proximo($grafo, 'est', 'b', $ctx0);
ok($r['card'] === 'lst' && $r['motivo'] === 'opcao', 'lista estatica casa a linha');
ok($r['ctx']['roteiro_slug'] === null, 'lista estatica nao grava slug');

// 4. REVIEW FOCUS 2: botao de mensagem antiga. O id existe no grafo, mas nao neste card.
$r = wa_fluxo_proximo($grafo, 'dt', 'b2', $ctx0);
ok($r['card'] === 'ops' && $r['motivo'] === 'fallback',
   'id de botao de outro card cai no fallback, nunca no destino daquele outro card');

// 5. texto livre em card que espera cai no fallback, e conta a tentativa
$r = wa_fluxo_proximo($grafo, 'dt', 'quanto custa?', $ctx0);
ok($r['card'] === 'ops' && $r['motivo'] === 'fallback', 'texto livre cai no fallback');
ok($r['ctx']['tentativas'] === 1, 'fallback conta tentativa');

// 6. rede de seguranca: na tentativa WA_FLUXO_MAX_TENTATIVAS vai para o humano do fluxo
ok(WA_FLUXO_MAX_TENTATIVAS === 2, 'o teto de tentativas e 2');
$r = wa_fluxo_proximo($grafo, 'dt', 'bla', ['roteiro_slug' => null, 'tentativas' => 2]);
ok($r['card'] === 'hum' && $r['motivo'] === 'socorro', 'no teto, socorro vai para o humano do fluxo');
ok($r['ctx']['tentativas'] === 0, 'socorro zera o contador');

// 7. card que NAO espera nunca consulta a resposta: segue por proximo
foreach ([['pdf', 'mrc'], ['mrc', 'dt'], ['prog', 'lst']] as [$de, $para]) {
    $r = wa_fluxo_proximo($grafo, $de, 'qualquer coisa', $ctx0);
    ok($r['card'] === $para, "card $de segue por proximo, ignorando a resposta");
}

// 8. terminal nao tem proximo
foreach (['hum', 'fim'] as $t) {
    ok(wa_fluxo_proximo($grafo, $t, 'x', $ctx0)['card'] === null, "card $t e terminal");
}

// 9. ir_para_fluxo se anuncia pelo motivo, e o destino sai da config
$r = wa_fluxo_proximo($grafo, 'vai', '', $ctx0);
ok($r['motivo'] === 'ir_para_fluxo', 'ir_para_fluxo tem motivo proprio');

// 10. card inexistente e tipo fora da lista nao estouram: devolvem invalido
ok(wa_fluxo_proximo($grafo, 'nao-existe', 'x', $ctx0)['motivo'] === 'invalido', 'card inexistente e invalido');
$g2 = $grafo; $g2['cards']['zz'] = card('zz', 'inventado');
ok(wa_fluxo_proximo($g2, 'zz', 'x', $ctx0)['motivo'] === 'invalido', 'tipo fora de WA_CARD_TIPOS e invalido');

// 11. fallback ausente num card que espera: cai no humano, nunca em null
$g3 = $grafo; $g3['cards']['dt']['fallback'] = null;
ok(wa_fluxo_proximo($g3, 'dt', 'bla', $ctx0)['card'] === 'hum', 'sem fallback, socorro imediato');

// 12. wa_fluxo_sem_resposta
ok(wa_fluxo_sem_resposta($grafo, 'dt') === 'fim', 'sem_resposta do card');
ok(wa_fluxo_sem_resposta($grafo, 'bv') === null, 'card sem sem_resposta devolve null');
ok(wa_fluxo_sem_resposta($grafo, 'nao-existe') === null, 'card inexistente devolve null');

// 13. a funcao e PURA: o grafo e o ctx que entraram nao mudam
$antes = $grafo; $ctx = $ctx0;
wa_fluxo_proximo($grafo, 'lst', 'turquia', $ctx);
ok($grafo === $antes, 'o grafo nao e modificado');
ok($ctx === $ctx0, 'o ctx de entrada nao e modificado');

echo "test-wa-fluxo-rota: ok\n";
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-wa-fluxo-rota.php`
Expected: FAIL com "Call to undefined function wa_fluxo_proximo()"

- [ ] **Step 3: Implementar `wa_fluxo_proximo` e `wa_fluxo_sem_resposta` em `lib/wa-fluxo.php`**

Puras: nenhum `wa_fl_call`, nenhum `gmdate`, nenhum `$GLOBALS`. A ordem de decisão que o teste fixa: tipo fora da lista ou card inexistente → `invalido`; terminal → `card` nulo; tipo que não espera → `proximo`; `lista` com `config.fonte === 'roteiros'` → grava o slug e vai por `proximo`; senão casa `$resposta` contra `opcoes[].id`; sem casar, `fallback` se existir e `tentativas` abaixo do teto, senão `socorro` para `$grafo['humano']`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test-wa-fluxo-rota.php`
Expected: `test-wa-fluxo-rota: ok`

- [ ] **Step 5: Commit**

```bash
git add lib/wa-fluxo.php tests/test-wa-fluxo-rota.php
git commit -m "feat(fluxo): roteamento puro do grafo"
```

---

## Task 5: Validação pura, as 14 regras

**Files:**
- Modify: `lib/wa-fluxo.php`
- Test: `tests/test-wa-fluxo-valida.php`

**Interfaces:**
- Consumes: todas as constantes da Task 3; o formato de `$grafo` da Task 4.
- Produces:
  - `wa_fluxo_grafo_de(array $fluxo, array $cards): array` — monta o retrato a partir das linhas de edição. Pura.
  - `wa_fluxo_valida(array $fluxo, array $cards, array $outros = []): array` — lista de `['regra'=>int, 'card'=>?string, 'texto'=>string, 'bloqueia'=>bool]`, em português. Pura. `$outros` é `[['id','gatilho','gatilho_valor','ativo','versao_atual'], ...]`, os demais fluxos.

- [ ] **Step 1: Write the failing test**

Um caso por regra. O teste monta um fluxo válido mínimo e, para cada regra, o quebra de um jeito só e exige **aquela** regra.

```php
<?php
require __DIR__ . '/../lib/wa-fluxo.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

/** @return array lista de numeros de regra que bloqueiam */
function bloqueios($fluxo, $cards, $outros = []) {
    $out = [];
    foreach (wa_fluxo_valida($fluxo, $cards, $outros) as $p) if ($p['bloqueia']) $out[] = $p['regra'];
    sort($out); return array_values(array_unique($out));
}
function avisos($fluxo, $cards, $outros = []) {
    $out = [];
    foreach (wa_fluxo_valida($fluxo, $cards, $outros) as $p) if (!$p['bloqueia']) $out[] = $p['regra'];
    sort($out); return array_values(array_unique($out));
}

$fx = ['id' => 'f1', 'nome' => 'Boas-vindas', 'gatilho' => 'mensagem_nova', 'gatilho_valor' => null,
       'ativo' => false, 'card_inicial' => 'bv'];
// fluxo valido minimo: botoes -> humano, com fallback e fim
$base = [
  ['id'=>'bv','fluxo_id'=>'f1','tipo'=>'botoes','titulo'=>'Boas-vindas','texto'=>'Oi {nome}, como ajudo?',
   'opcoes'=>[['id'=>'b1','rotulo'=>'Falar com a Lais','destino'=>'hum']],'config'=>[],
   'proximo'=>null,'fallback'=>'ops','sem_resposta'=>'fim','lembrete_ativo'=>true,'lembrete_texto'=>'Oi {nome}?'],
  ['id'=>'ops','fluxo_id'=>'f1','tipo'=>'botoes','titulo'=>'Nao entendi','texto'=>'Nao entendi. Quer falar com a Lais?',
   'opcoes'=>[['id'=>'v','rotulo'=>'Falar com a Lais','destino'=>'hum']],'config'=>[],
   'proximo'=>null,'fallback'=>'hum','sem_resposta'=>null,'lembrete_ativo'=>true,'lembrete_texto'=>'Oi?'],
  ['id'=>'hum','fluxo_id'=>'f1','tipo'=>'humano','titulo'=>'Atendimento','texto'=>'Vou chamar a Lais.',
   'opcoes'=>[],'config'=>[],'proximo'=>null,'fallback'=>null,'sem_resposta'=>null,'lembrete_ativo'=>false,'lembrete_texto'=>null],
  ['id'=>'fim','fluxo_id'=>'f1','tipo'=>'fim','titulo'=>'Fim','texto'=>'Ate logo.',
   'opcoes'=>[],'config'=>[],'proximo'=>null,'fallback'=>null,'sem_resposta'=>null,'lembrete_ativo'=>false,'lembrete_texto'=>null],
];
ok(bloqueios($fx, $base) === [], 'o fluxo minimo valido nao bloqueia nada');
ok(avisos($fx, $base) === [], 'e nao avisa nada');

// regra 1: saida apontando para card que nao existe
$c = $base; $c[0]['opcoes'][0]['destino'] = 'fantasma';
ok(in_array(1, bloqueios($fx, $c), true), 'regra 1: destino inexistente');

// regra 2: card que espera sem lembrete e sem sem_resposta
$c = $base; $c[0]['lembrete_ativo'] = false; $c[0]['sem_resposta'] = null;
ok(in_array(2, bloqueios($fx, $c), true), 'regra 2: espera sem lembrete e sem sem_resposta');
$c = $base; $c[0]['lembrete_ativo'] = false;  // mas tem sem_resposta
ok(!in_array(2, bloqueios($fx, $c), true), 'regra 2 aceita sem_resposta no lugar do lembrete');

// regra 3: card inalcancavel a partir do inicial
$c = $base; $c[] = ['id'=>'orfao','fluxo_id'=>'f1','tipo'=>'fim','titulo'=>'Orfao','texto'=>'x',
  'opcoes'=>[],'config'=>[],'proximo'=>null,'fallback'=>null,'sem_resposta'=>null,'lembrete_ativo'=>false,'lembrete_texto'=>null];
ok(in_array(3, bloqueios($fx, $c), true), 'regra 3: card orfao');

// regra 4: porta propria exige humano alcancavel; porta chamado basta terminar
$c = $base; $c[0]['opcoes'][0]['destino'] = 'fim'; $c[1]['fallback'] = 'fim';
array_splice($c, 2, 1); // remove o card humano
ok(in_array(4, bloqueios($fx, $c), true), 'regra 4: fluxo de porta propria sem humano bloqueia');
$fx_chamado = array_merge($fx, ['gatilho' => 'chamado']);
ok(!in_array(4, bloqueios($fx_chamado, $c), true), 'regra 4: fluxo de porta chamado nao exige humano');
$c2 = $c; $c2[0]['opcoes'][0]['destino'] = 'bv'; $c2[0]['sem_resposta'] = null; $c2[0]['fallback'] = 'bv'; $c2[1]['fallback'] = 'bv';
array_splice($c2, 3, 1); // remove tambem o fim: nenhum terminal
ok(in_array(4, bloqueios($fx_chamado, $c2), true), 'regra 4: nem chamado escapa de nao ter terminal nenhum');

// regra 5: rotulo de botao vazio, acima de 20, ou repetido no mesmo card
foreach ([['', 'vazio'], [str_repeat('a', 21), 'acima de 20']] as [$rot, $nome]) {
    $c = $base; $c[0]['opcoes'][0]['rotulo'] = $rot;
    ok(in_array(5, bloqueios($fx, $c), true), "regra 5: rotulo $nome");
}
$c = $base; $c[0]['opcoes'][] = ['id'=>'b2','rotulo'=>'Falar com a Lais','destino'=>'fim'];
ok(in_array(5, bloqueios($fx, $c), true), 'regra 5: rotulos repetidos no mesmo card');
$c = $base; $c[0]['opcoes'] = array_fill(0, 4, ['id'=>'x','rotulo'=>'Op','destino'=>'hum']);
ok(in_array(5, bloqueios($fx, $c), true), 'regra 5: mais de 3 botoes');
$c = $base; $c[0]['opcoes'][0]['rotulo'] = str_repeat('ç', 20);
ok(!in_array(5, bloqueios($fx, $c), true), 'regra 5: 20 caracteres acentuados passam');

// regra 6: texto acima do limite, medido na expansao de pior caso
$c = $base; $c[0]['texto'] = str_repeat('a', 1000) . '{roteiro}';  // 1000 + 60 > 1024
ok(in_array(6, bloqueios($fx, $c), true), 'regra 6: 1000 literais mais {roteiro} estouram 1024 do card botoes');
$c = $base; $c[0]['texto'] = '';
ok(in_array(6, bloqueios($fx, $c), true), 'regra 6: texto vazio em card que manda mensagem');

// regra 7: lista estatica sem linha, ou dinamica sem fonte
$lst = ['id'=>'lst','fluxo_id'=>'f1','tipo'=>'lista','titulo'=>'Qual roteiro','texto'=>'Qual?',
  'opcoes'=>[],'config'=>[],'proximo'=>'hum','fallback'=>'ops','sem_resposta'=>'fim','lembrete_ativo'=>true,'lembrete_texto'=>'Oi?'];
$c = $base; $c[0]['opcoes'][0]['destino'] = 'lst'; $c[] = $lst;
ok(in_array(7, bloqueios($fx, $c), true), 'regra 7: lista sem fonte e sem linha');
$c2 = $c; $c2[4]['config'] = ['fonte' => 'roteiros'];
ok(!in_array(7, bloqueios($fx, $c2), true), 'regra 7: lista com fonte roteiros passa');
$c3 = $c; $c3[4]['opcoes'] = array_fill(0, 11, ['id'=>'x','rotulo'=>'L','destino'=>'hum']); $c3[4]['config'] = ['fonte'=>'estatica'];
ok(in_array(7, bloqueios($fx, $c3), true), 'regra 7: mais de 10 linhas');

// regra 8: ciclo sem terminal alcancavel de dentro dele
$c = $base; $c[0]['opcoes'][0]['destino'] = 'ops'; $c[1]['opcoes'][0]['destino'] = 'bv'; $c[1]['fallback'] = 'bv'; $c[0]['fallback'] = 'ops';
array_splice($c, 2, 1);
ok(in_array(8, bloqueios($fx, $c), true), 'regra 8: ciclo fechado sem terminal');

// regra 9: porta de entrada ja ocupada por outro fluxo ativo
$outros = [['id'=>'f2','gatilho'=>'mensagem_nova','gatilho_valor'=>null,'ativo'=>true,'versao_atual'=>'v2']];
ok(in_array(9, bloqueios($fx, $base, $outros), true), 'regra 9: porta ocupada');
$outros_desligado = [['id'=>'f2','gatilho'=>'mensagem_nova','gatilho_valor'=>null,'ativo'=>false,'versao_atual'=>'v2']];
ok(!in_array(9, bloqueios($fx, $base, $outros_desligado), true), 'regra 9: fluxo desligado nao ocupa a porta');
ok(!in_array(9, bloqueios($fx, $base, [['id'=>'f1','gatilho'=>'mensagem_nova','gatilho_valor'=>null,'ativo'=>true,'versao_atual'=>'v']]), true),
   'regra 9: o proprio fluxo nao disputa a porta com ele mesmo');

// regra 10: marca tentando campo fora da whitelist
$mrc = ['id'=>'mrc','fluxo_id'=>'f1','tipo'=>'marca','titulo'=>'Marca','texto'=>null,
  'opcoes'=>[],'config'=>['venda'=>1000],'proximo'=>'hum','fallback'=>null,'sem_resposta'=>null,'lembrete_ativo'=>false,'lembrete_texto'=>null];
$c = $base; $c[0]['opcoes'][0]['destino'] = 'mrc'; $c[] = $mrc;
ok(in_array(10, bloqueios($fx, $c), true), 'regra 10: marca com venda na config');
$c2 = $c; $c2[4]['config'] = ['status' => 'venda'];
ok(in_array(10, bloqueios($fx, $c2), true), 'regra 10: marca com status venda');
$c3 = $c; $c3[4]['config'] = ['status' => 'perdido', 'qualif_data' => false];
ok(!in_array(10, bloqueios($fx, $c3), true), 'regra 10: marca dentro da whitelist passa');

// regra 11: ir_para_fluxo para fluxo inexistente, nao publicado ou desligado
$vai = ['id'=>'vai','fluxo_id'=>'f1','tipo'=>'ir_para_fluxo','titulo'=>'Vai','texto'=>null,
  'opcoes'=>[],'config'=>['fluxo'=>'f9'],'proximo'=>null,'fallback'=>null,'sem_resposta'=>null,'lembrete_ativo'=>false,'lembrete_texto'=>null];
$c = $base; $c[0]['opcoes'][0]['destino'] = 'vai'; $c[] = $vai;
ok(in_array(11, bloqueios($fx, $c, []), true), 'regra 11: fluxo destino inexistente');
$so_publicado = [['id'=>'f9','gatilho'=>'chamado','gatilho_valor'=>null,'ativo'=>false,'versao_atual'=>'v9']];
ok(in_array(11, bloqueios($fx, $c, $so_publicado), true), 'regra 11: fluxo destino desligado');
$ok9 = [['id'=>'f9','gatilho'=>'chamado','gatilho_valor'=>null,'ativo'=>true,'versao_atual'=>'v9']];
ok(!in_array(11, bloqueios($fx, $c, $ok9), true), 'regra 11: fluxo destino publicado e ligado passa');

// regra 12: sem_resposta apontando para card que ESPERA
$c = $base; $c[0]['sem_resposta'] = 'ops';  // ops e botoes
ok(in_array(12, bloqueios($fx, $c), true), 'regra 12: sem_resposta para card que espera');
ok(!in_array('ir_para_fluxo', WA_SEM_RESPOSTA_TIPOS, true), 'o cron nao pula de fluxo por falta de resposta');
foreach (WA_SEM_RESPOSTA_TIPOS as $t) {
    $c = $base; $c[] = ['id'=>'sr','fluxo_id'=>'f1','tipo'=>$t,'titulo'=>'SR','texto'=>'x',
      'opcoes'=>[],'config'=>[],'proximo'=>'hum','fallback'=>null,
      'sem_resposta'=>null,'lembrete_ativo'=>false,'lembrete_texto'=>null];
    $c[0]['sem_resposta'] = 'sr';
    ok(!in_array(12, bloqueios($fx, $c), true), "regra 12 aceita sem_resposta para card $t");
}

// regra 13: pdf alcancavel sem escolha de roteiro antes
$pdf = ['id'=>'pdf','fluxo_id'=>'f1','tipo'=>'pdf','titulo'=>'PDF','texto'=>'Segue a {roteiro}.',
  'opcoes'=>[],'config'=>[],'proximo'=>'hum','fallback'=>null,'sem_resposta'=>null,'lembrete_ativo'=>false,'lembrete_texto'=>null];
$c = $base; $c[0]['opcoes'][0]['destino'] = 'pdf'; $c[] = $pdf;
ok(in_array(13, bloqueios($fx, $c), true), 'regra 13: pdf sem lista dinamica no caminho');
$c2 = $c; $c2[] = $lst; $c2[4]['config'] = ['fonte'=>'roteiros']; $c2[0]['opcoes'][0]['destino'] = 'lst'; $c2[4]['proximo'] = 'pdf';
ok(!in_array(13, bloqueios($fx, $c2), true), 'regra 13: pdf depois de lista dinamica passa');
$fx_anuncio = array_merge($fx, ['gatilho' => 'anuncio']);
ok(!in_array(13, bloqueios($fx_anuncio, $c), true), 'regra 13: porta anuncio ja traz o roteiro');

// regra 14: variavel fora da lista, mal escrita, ou indisponivel naquele card
foreach (['{Nome}', '{ nome }', '{nome2}', '{inventada}'] as $v) {
    $c = $base; $c[0]['texto'] = 'Oi ' . $v;
    ok(in_array(14, bloqueios($fx, $c), true), "regra 14: $v recusada");
}
$c = $base; $c[0]['texto'] = 'A {roteiro} e boa.';  // bv e alcancavel sem roteiro no contexto
ok(in_array(14, bloqueios($fx, $c), true), 'regra 14: {roteiro} em card alcancavel sem roteiro');
$c = $base; $c[0]['lembrete_texto'] = 'Oi {Nome}';
ok(in_array(14, bloqueios($fx, $c), true), 'regra 14 vale tambem para o texto do lembrete');

// AVISOS, que nao bloqueiam
$c = $base; $c[] = ['id'=>'so','fluxo_id'=>'f1','tipo'=>'fim','titulo'=>'So','texto'=>'x',
  'opcoes'=>[],'config'=>[],'proximo'=>null,'fallback'=>null,'sem_resposta'=>null,'lembrete_ativo'=>false,'lembrete_texto'=>null];
$c[0]['sem_resposta'] = 'so';  // alcancavel, entao nao e regra 3
ok(bloqueios($fx, $c) === [], 'o card alcancavel por sem_resposta nao bloqueia');

// mensagens: toda em portugues, sem travessao, e nomeando o card
foreach (wa_fluxo_valida($fx, (function($b){ $b[0]['opcoes'][0]['destino']='fantasma'; return $b; })($base)) as $p) {
    ok(strpos($p['texto'], '—') === false, 'nenhuma mensagem usa travessao');
    ok(trim($p['texto']) !== '', 'toda mensagem tem texto');
}

// wa_fluxo_grafo_de monta o retrato no formato que a Task 4 consome
$g = wa_fluxo_grafo_de($fx, $base);
ok($g['id'] === 'f1' && $g['nome'] === 'Boas-vindas', 'o retrato guarda id e nome do fluxo');
ok($g['inicial'] === 'bv', 'o retrato guarda o card inicial');
ok($g['humano'] === 'hum', 'o retrato guarda o card humano do fluxo');
ok($g['porta'] === 'mensagem_nova', 'o retrato guarda a porta');
ok(array_keys($g['cards']) === ['bv','ops','hum','fim'], 'os cards viram mapa por id');

// a funcao e PURA
$antes = $base; wa_fluxo_valida($fx, $base); ok($base === $antes, 'wa_fluxo_valida nao modifica a entrada');

echo "test-wa-fluxo-valida: ok\n";
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-wa-fluxo-valida.php`
Expected: FAIL com "Call to undefined function wa_fluxo_valida()"

- [ ] **Step 3: Implementar `wa_fluxo_grafo_de` e `wa_fluxo_valida` em `lib/wa-fluxo.php`**

Puras. Duas coisas que o teste não determina e que decidem o resto:

- **Alcançabilidade** é uma busca em largura a partir de `card_inicial`, seguindo `opcoes[].destino`, `proximo`, `fallback` e `sem_resposta`. A mesma travessia serve às regras 3, 4, 8 e 13: a 13 precisa saber, para cada card `pdf`, se **todo** caminho até ele passa por um card `lista` de fonte `roteiros` (ou se a porta do fluxo é `anuncio`), então marque na travessia se o roteiro já está no contexto e propague.
- A regra 8 é ciclo sem terminal alcançável **de dentro dele**: ache os componentes fortemente conexos, ou, mais simples e suficiente aqui, para cada card alcançável verifique se algum terminal é alcançável a partir dele.

Cada problema devolve `regra`, `card` (quando há um culpado), `texto` em português e `bloqueia`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test-wa-fluxo-valida.php`
Expected: `test-wa-fluxo-valida: ok`

- [ ] **Step 5: Commit**

```bash
git add lib/wa-fluxo.php tests/test-wa-fluxo-valida.php
git commit -m "feat(fluxo): validacao pura, as 14 regras que travam o publicar"
```

---

## Task 6: Retrato publicado e versão por conversa

**Files:**
- Modify: `lib/wa-fluxo.php`
- Test: `tests/test-wa-fluxo-versao.php`

**Interfaces:**
- Consumes: `wa_fluxo_grafo_de`, `wa_fluxo_valida` (Task 5); `wa_fl_call` (Task 3).
- Produces:
  - `wa_fluxo_grafo(string $versao_id): ?array` — lê `po_wa_fluxo_versoes`, devolve o `grafo` decodificado ou `null`.
  - `wa_fluxo_publica(string $fluxo_id): array` → `['ok'=>bool, 'versao'=>?int, 'problemas'=>array]`
  - `wa_fluxo_porta(array $ev, array $fluxos): ?array` — pura. Resolve a porta de entrada e devolve o fluxo que atende, ou `null`. Ordem fixa `anuncio` > `palavra` > `mensagem_nova`.

- [ ] **Step 1: Write the failing test**

Com `wa_fluxo_set_deps` servindo um banco em memória; nenhuma rede.

```php
<?php
require __DIR__ . '/../lib/wa-fluxo.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

$DB = ['po_wa_fluxos' => [], 'po_wa_cards' => [], 'po_wa_fluxo_versoes' => []];
$ESCRITAS = [];
wa_fluxo_set_deps([
  'select' => function ($tabela, $query) use (&$DB) { /* filtra por id=eq. e fluxo_id=eq. */ },
  'insert' => function ($tabela, $linha) use (&$DB, &$ESCRITAS) { $ESCRITAS[] = [$tabela, $linha]; },
  'update' => function ($tabela, $query, $campos) use (&$DB, &$ESCRITAS) { $ESCRITAS[] = [$tabela, $query, $campos]; },
]);

// 1. wa_fluxo_grafo le o retrato, nao as tabelas de edicao
// (o teste grava um grafo em po_wa_fluxo_versoes e muda po_wa_cards por baixo)
ok(wa_fluxo_grafo('v1')['inicial'] === 'bv', 'le o grafo da versao');
ok(wa_fluxo_grafo('nao-existe') === null, 'versao inexistente devolve null');
ok(wa_fluxo_grafo('') === null, 'versao vazia devolve null, sem consultar o banco');

// 2. publicar com problema NAO grava versao nenhuma
$r = wa_fluxo_publica('f-quebrado');
ok($r['ok'] === false, 'fluxo com ponta solta nao publica');
ok($r['versao'] === null && count($r['problemas']) > 0, 'devolve os problemas');
ok($ESCRITAS === [], 'nada e gravado quando a validacao reprova');

// 3. publicar valido grava retrato e aponta versao_atual, e NAO liga o fluxo
$ESCRITAS = [];
$r = wa_fluxo_publica('f1');
ok($r['ok'] === true && $r['versao'] === 1, 'primeira publicacao e a versao 1');
$tabelas = array_column($ESCRITAS, 0);
ok(in_array('po_wa_fluxo_versoes', $tabelas, true), 'grava o retrato');
ok(in_array('po_wa_fluxos', $tabelas, true), 'aponta versao_atual');
foreach ($ESCRITAS as $e) {
    if ($e[0] === 'po_wa_fluxos') {
        ok(!array_key_exists('ativo', $e[2]), 'publicar NAO escreve ativo: ligar e outro ato');
    }
}

// 4. publicar de novo cria a versao 2, e a 1 continua la
ok(wa_fluxo_publica('f1')['versao'] === 2, 'a segunda publicacao e a versao 2');
ok(wa_fluxo_grafo('v1') !== null, 'a versao anterior continua legivel');

// 5. a validacao roda DENTRO de wa_fluxo_publica, nao so no endpoint
$fonte = file_get_contents(__DIR__ . '/../lib/wa-fluxo.php');
ok(preg_match('/function wa_fluxo_publica.*?wa_fluxo_valida\s*\(/s', $fonte),
   'wa_fluxo_publica chama wa_fluxo_valida: nao existe caminho que publique sem validar');

// 6. wa_fluxo_porta: a ordem anuncio > palavra > mensagem_nova
$fluxos = [
  ['id'=>'fa','gatilho'=>'anuncio','gatilho_valor'=>null,'ativo'=>true,'versao_atual'=>'va'],
  ['id'=>'fp','gatilho'=>'palavra','gatilho_valor'=>'cruzeiro','ativo'=>true,'versao_atual'=>'vp'],
  ['id'=>'fn','gatilho'=>'mensagem_nova','gatilho_valor'=>null,'ativo'=>true,'versao_atual'=>'vn'],
  ['id'=>'fc','gatilho'=>'chamado','gatilho_valor'=>null,'ativo'=>true,'versao_atual'=>'vc'],
];
ok(wa_fluxo_porta(['ad_id'=>'123','texto'=>'cruzeiro'], $fluxos)['id'] === 'fa', 'anuncio ganha de palavra');
ok(wa_fluxo_porta(['ad_id'=>null,'texto'=>'queria um cruzeiro'], $fluxos)['id'] === 'fp', 'palavra ganha de mensagem_nova');
ok(wa_fluxo_porta(['ad_id'=>null,'texto'=>'boa tarde'], $fluxos)['id'] === 'fn', 'sobra mensagem_nova');
ok(wa_fluxo_porta(['ad_id'=>null,'texto'=>'boa tarde'], [$fluxos[3]]) === null, 'porta chamado nunca dispara sozinha');
ok(wa_fluxo_porta(['ad_id'=>null,'texto'=>'x'], [array_merge($fluxos[2], ['ativo'=>false])]) === null, 'fluxo desligado nao atende');
ok(wa_fluxo_porta(['ad_id'=>null,'texto'=>'x'], [array_merge($fluxos[2], ['versao_atual'=>null])]) === null,
   'fluxo ligado e nunca publicado nao atende');
// palavra casa por palavra inteira, com acento e caixa indiferentes, e nao dentro de outra palavra
ok(wa_fluxo_porta(['ad_id'=>null,'texto'=>'CRUZEIRO?'], $fluxos)['id'] === 'fp', 'palavra ignora caixa e pontuacao');
ok(wa_fluxo_porta(['ad_id'=>null,'texto'=>'cruzeirinho'], $fluxos)['id'] === 'fn', 'palavra nao casa dentro de outra palavra');

echo "test-wa-fluxo-versao: ok\n";
```

O dublê de `select` do Step 1 precisa responder como o PostgREST responde (lista de linhas, filtrando por `id=eq.` e `fluxo_id=eq.`). Montá-lo a partir do `$DB` em memória, no porte do dublê de `tests/test-wa-motor.php`.

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-wa-fluxo-versao.php`
Expected: FAIL com "Call to undefined function wa_fluxo_grafo()"

- [ ] **Step 3: Implementar `wa_fluxo_grafo`, `wa_fluxo_publica` e `wa_fluxo_porta` em `lib/wa-fluxo.php`**

- `wa_fluxo_publica`: lê o fluxo e seus cards, lê os outros fluxos (para as regras 9 e 11), chama `wa_fluxo_valida`, e **só então** monta o retrato com `wa_fluxo_grafo_de`, grava em `po_wa_fluxo_versoes` com `versao = max + 1`, e aponta `versao_atual`. Nunca escreve `ativo`.
- `wa_fluxo_porta`: pura. A normalização da palavra reusa `wa_normaliza()` de `lib/wa-roteiro.php` (tira acento e caixa) e casa por limite de palavra com `wa_mesma_palavra()`, que existe lá pelo mesmo motivo (o regex do marcador "PO" comia `Poá` sem o `/u`).
- O erro de leitura nunca vira "fluxo não existe": usar `wa_db_select_estrito` como `select` padrão, pela mesma razão de `wa_tpl_lista` e do importador. Supabase fora do ar devolvendo lista vazia faria o motor tratar todo cliente como se não houvesse fluxo nenhum.

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test-wa-fluxo-versao.php`
Expected: `test-wa-fluxo-versao: ok`

- [ ] **Step 5: Commit**

```bash
git add lib/wa-fluxo.php tests/test-wa-fluxo-versao.php
git commit -m "feat(fluxo): retrato publicado, versao por conversa e resolucao de porta"
```

---

## Task 7: Executar um card

**Files:**
- Modify: `lib/wa-fluxo.php`
- Test: `tests/test-wa-fluxo-executa.php`

**Interfaces:**
- Consumes: tudo das Tasks 3 a 6; `wa_send_buttons` (Task 2); `wa_registra_saida($wa_id, $envio, $tipo, $texto)` e `wa_acha_roteiro($slug, $roteiros)`, que a Task 3 já moveu para `lib/wa-fluxo.php`; `po_ano($r)` de `lib/po-data.php`.
- Produces: `wa_fluxo_executa(array $grafo, string $card_id, string $wa_id, array $ctx, string $nome): array` → `['ok'=>bool, 'wamid'=>?string, 'espera'=>bool, 'terminal'=>?string, 'ctx'=>array, 'erro'=>?string]`. `terminal` ∈ `null|'humano'|'fim'`.

- [ ] **Step 1: Write the failing test**

```php
<?php
require __DIR__ . '/../lib/wa-fluxo.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

$ENVIADAS = []; $ESCRITAS = []; $ROTEIROS = [
  ['slug'=>'turquia','titulo'=>'Turquia com Antalia','data_label'=>'','periodo'=>'27/10/27 a 12/11/27','pdf_url'=>'https://exemplo/t.pdf'],
];
wa_fluxo_set_deps([
  'roteiros'     => function () use (&$ROTEIROS) { return $ROTEIROS; },
  'send_text'    => function ($p, $t) use (&$ENVIADAS) { $ENVIADAS[] = ['text', $t]; return ['ok'=>true,'wamid'=>'w1']; },
  'send_buttons' => function ($p, $c, $b) use (&$ENVIADAS) { $ENVIADAS[] = ['buttons', $c, $b]; return ['ok'=>true,'wamid'=>'w2']; },
  'send_list'    => function ($p, $c, $bt, $i) use (&$ENVIADAS) { $ENVIADAS[] = ['list', $c, $i]; return ['ok'=>true,'wamid'=>'w3']; },
  'send_doc'     => function ($p, $u, $a, $l) use (&$ENVIADAS) { $ENVIADAS[] = ['doc', $u, $l]; return ['ok'=>true,'wamid'=>'w4']; },
  'update'       => function ($t, $q, $c) use (&$ESCRITAS) { $ESCRITAS[] = [$t, $c]; return true; },
  'select'       => function ($t, $q) { return []; },
]);
$fone = '+5548900000001';

// 1. card botoes: manda corpo e rotulos, e ESPERA
$g = /* grafo com os cards usados abaixo, no formato da Task 4 */;
$r = wa_fluxo_executa($g, 'dt', $fone, ['roteiro_slug'=>'turquia','tentativas'=>0], 'Marta');
ok($r['ok'] === true && $r['espera'] === true, 'card botoes espera resposta');
ok($ENVIADAS[0][0] === 'buttons', 'card botoes usa send_buttons');
ok(strpos($ENVIADAS[0][1], 'Turquia com Antalia') !== false, 'o {roteiro} foi substituido');
ok(strpos($ENVIADAS[0][1], '27/10/27') !== false, 'o {data} veio do periodo, nao vazio');
ok($ENVIADAS[0][2][0]['rotulo'] === 'Sim, funciona', 'os rotulos vao na ordem das opcoes');

// 2. card texto: manda e NAO espera
$ENVIADAS = [];
$r = wa_fluxo_executa($g, 'txt', $fone, ['roteiro_slug'=>null,'tentativas'=>0], 'Marta');
ok($r['espera'] === false, 'card texto nao espera');
ok($ENVIADAS[0][0] === 'text', 'card texto usa send_text');

// 3. card pdf: manda o documento com legenda, e nao espera
$ENVIADAS = [];
$r = wa_fluxo_executa($g, 'pdf', $fone, ['roteiro_slug'=>'turquia','tentativas'=>0], 'Marta');
ok($ENVIADAS[0][0] === 'doc' && $ENVIADAS[0][1] === 'https://exemplo/t.pdf', 'manda o pdf_url do roteiro');
ok($r['espera'] === false, 'card pdf nao espera');

// 4. REVIEW FOCUS 5: roteiro saiu do ar entre a escolha e o pdf
$ENVIADAS = []; $ROTEIROS = [];
$r = wa_fluxo_executa($g, 'pdf', $fone, ['roteiro_slug'=>'turquia','tentativas'=>0], 'Marta');
ok($r['ok'] === false, 'pdf de roteiro inexistente nao da ok');
ok($r['terminal'] === 'humano', 'vai para atendimento humano em vez de mandar arquivo quebrado');
ok(!in_array('doc', array_column($ENVIADAS, 0), true), 'nenhum documento e enviado');

// 5. REVIEW FOCUS 3: lista dinamica com catalogo vazio cai em TEXTO, nunca em lista de zero linhas
$ENVIADAS = []; $ROTEIROS = [];
$r = wa_fluxo_executa($g, 'lst', $fone, ['roteiro_slug'=>null,'tentativas'=>0], 'Marta');
ok($ENVIADAS[0][0] === 'text', 'catalogo vazio manda o corpo como texto puro');
ok($r['ok'] === true && $r['espera'] === true, 'e segue esperando a resposta');

// 6. lista dinamica com catalogo: no maximo 10 linhas, e o id da linha e o slug
$ENVIADAS = []; $ROTEIROS = [];
for ($i = 0; $i < 12; $i++) $ROTEIROS[] = ['slug'=>"r$i",'titulo'=>"R$i",'data_label'=>'','periodo'=>'01/01/27 a 10/01/27','pdf_url'=>'u'];
$r = wa_fluxo_executa($g, 'lst', $fone, ['roteiro_slug'=>null,'tentativas'=>0], 'Marta');
ok(count($ENVIADAS[0][2]) === 10, 'no maximo 10 linhas');
ok($ENVIADAS[0][2][0]['id'] === 'r0', 'o id da linha e o slug');

// 7. lista dinamica com filtro de ano usa po_ano
$ENVIADAS = [];
$ROTEIROS = [['slug'=>'a','titulo'=>'A','data_label'=>'','periodo'=>'01/01/27 a 10/01/27','pdf_url'=>'u'],
             ['slug'=>'b','titulo'=>'B','data_label'=>'','periodo'=>'01/01/28 a 10/01/28','pdf_url'=>'u']];
$r = wa_fluxo_executa($g, 'prog2027', $fone, ['roteiro_slug'=>null,'tentativas'=>0], 'Marta');
ok(count($ENVIADAS[0][2]) === 1 && $ENVIADAS[0][2][0]['id'] === 'a', 'o filtro de ano usa po_ano sobre periodo');

// 8. REVIEW FOCUS 4: texto do card vazio. O envio recusa e NADA avanca.
$ENVIADAS = []; $ESCRITAS = [];
$g2 = $g; $g2['cards']['txt']['texto'] = '   ';
$r = wa_fluxo_executa($g2, 'txt', $fone, ['roteiro_slug'=>null,'tentativas'=>0], 'Marta');
ok($r['ok'] === false, 'texto vazio nao da ok');
ok($ENVIADAS === [], 'texto vazio nao chega ao envio');

// 9. LEI 4: envio que falhou nunca devolve ok, e o chamador nao avanca
wa_fluxo_set_deps(['send_text' => function () { return ['ok'=>false,'wamid'=>null,'erro'=>'rede']; }]);
$r = wa_fluxo_executa($g, 'txt', $fone, ['roteiro_slug'=>null,'tentativas'=>0], 'Marta');
ok($r['ok'] === false && $r['wamid'] === null, 'envio falhado nao da ok nem wamid');

// 10. card marca: escreve em po_leads SO a whitelist, e acrescenta em interesses
wa_fluxo_set_deps([
  'send_text' => function ($p, $t) use (&$ENVIADAS) { $ENVIADAS[] = ['text', $t]; return ['ok'=>true,'wamid'=>'w1']; },
  'select'    => function ($t, $q) { return $t === 'po_leads' ? [['interesses' => ['chile'], 'espera' => []]] : []; },
]);
$ESCRITAS = [];
$r = wa_fluxo_executa($g, 'mrc', $fone, ['roteiro_slug'=>'turquia','tentativas'=>0], 'Marta');
$campos = null;
foreach ($ESCRITAS as $e) if ($e[0] === 'po_leads') $campos = $e[1];
ok($campos !== null, 'card marca escreve em po_leads');
ok($campos['interesses'] === ['chile','turquia'], 'interesses ACRESCENTA, nao substitui');
ok(array_diff(array_keys($campos), WA_FLUXO_CAMPOS_MARCAVEIS) === [], 'nenhuma chave fora da whitelist chega ao banco');
ok($r['espera'] === false, 'card marca nao espera');
// slug repetido nao duplica
wa_fluxo_set_deps(['select' => function ($t, $q) { return $t === 'po_leads' ? [['interesses' => ['turquia'], 'espera' => []]] : []; }]);
$ESCRITAS = [];
wa_fluxo_executa($g, 'mrc', $fone, ['roteiro_slug'=>'turquia','tentativas'=>0], 'Marta');
foreach ($ESCRITAS as $e) if ($e[0] === 'po_leads') ok($e[1]['interesses'] === ['turquia'], 'interesse repetido nao duplica');

// 11. terminais
ok(wa_fluxo_executa($g, 'hum', $fone, ['roteiro_slug'=>null,'tentativas'=>0], 'M')['terminal'] === 'humano', 'card humano e terminal humano');
ok(wa_fluxo_executa($g, 'fim', $fone, ['roteiro_slug'=>null,'tentativas'=>0], 'M')['terminal'] === 'fim', 'card fim e terminal fim');

// 12. toda saida do robo entra no log, senao o eco dela viraria handoff
$fonte = file_get_contents(__DIR__ . '/../lib/wa-fluxo.php');
ok(preg_match('/function wa_fluxo_executa.*?wa_registra_saida\s*\(/s', $fonte),
   'wa_fluxo_executa registra a saida: sem isso o eco do proprio robo o silencia');

echo "test-wa-fluxo-executa: ok\n";
```

O `$g` do Step 1 é um grafo com os cards `txt` (texto), `dt` (botões com `{roteiro}` e `{data}` no texto e opções `Sim, funciona` / `Nao nessa data`), `lst` (lista fonte `roteiros`), `prog2027` (lista fonte `roteiros` com `config.ano = '2027'`), `pdf`, `mrc` (marca com `config = ['interesses' => '+roteiro']`), `hum` e `fim`, no formato que a Task 4 fixou.

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-wa-fluxo-executa.php`
Expected: FAIL com "Call to undefined function wa_fluxo_executa()"

- [ ] **Step 3: Implementar `wa_fluxo_executa` em `lib/wa-fluxo.php`**

Um `switch` por tipo. Quatro pontos que o teste fixa e que são a razão de a função existir:

- O corpo da mensagem passa por `wa_fluxo_texto` com as variáveis de `wa_fluxo_vars_do_roteiro`, e **o envio é recusado antes de sair** se o resultado estiver vazio depois do `trim` (Review Focus 4).
- Catálogo vazio num `lista` dinâmica cai em `send_text` com o mesmo corpo, porque a Graph recusa lista de zero linhas; é o mesmo desvio que `wa_processar` já tem hoje, e `espera` continua `true`.
- `pdf` sem roteiro no catálogo devolve `terminal = 'humano'` e loga, em vez de mandar arquivo quebrado (Review Focus 5).
- `wa_registra_saida` é chamada para **toda** saída, com o tipo (`text`, `buttons`, `list`, `document`). É o log que separa o eco do próprio robô do eco da Laís; sem ele, o primeiro PDF que o robô manda o silencia para sempre naquele contato.

O filtro de ano do `lista` dinâmica usa `po_ano($r)` de `lib/po-data.php`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test-wa-fluxo-executa.php`
Expected: `test-wa-fluxo-executa: ok`

- [ ] **Step 5: Commit**

```bash
git add lib/wa-fluxo.php tests/test-wa-fluxo-executa.php
git commit -m "feat(fluxo): execucao de card, com os desvios de catalogo e pdf ausentes"
```

---

## Task 8: `wa_processar` delega o roteamento

**Files:**
- Modify: `lib/wa-motor.php`
- Modify: `tests/test-wa-motor.php`
- Test: `tests/test-wa-fluxo-leis.php`

**Interfaces:**
- Consumes: `wa_fluxo_porta`, `wa_fluxo_grafo`, `wa_fluxo_proximo`, `wa_fluxo_executa`.
- Produces: `wa_processar($ev)` com os retornos novos `'fluxo'`, `'sem_fluxo'`, `'card_perdido'`, além dos que já existem (`'status'`, `'eco_do_robo'`, `'silenciou'`, `'ja_humano'`, `'opt_out'`, `'silenciado'`, `'falha_envio'`, e os de atalho). `WA_FLUXO_MAX_PASSOS = 10`.

- [ ] **Step 1: Write the failing test**

`tests/test-wa-fluxo-leis.php` é o arquivo que prova que o interpretador não enfraqueceu nada. Monta o mesmo dublê de `tests/test-wa-motor.php` (banco em memória, envio em lista, `wa_db_set_transport` barrando qualquer escrita em `po_wa_mensagens`) e acrescenta um fluxo publicado e ligado no `$DB`.

```php
// LEI 1: o eco silencia para sempre, e o eco do PROPRIO robo nao silencia
ok(wa_processar($eco_da_lais) === 'silenciou', 'eco da Lais silencia');
ok(wa_conversa($fone)['estado'] === 'humano', 'e o estado vira humano');
ok(wa_processar($msg_do_cliente) === 'silenciado', 'depois do eco, o robo nao responde mais');
ok($ENVIADAS === [], 'e nada mais e enviado nesse contato');
ok(wa_processar($eco_do_proprio_robo) === 'eco_do_robo', 'o eco do proprio envio do robo nao silencia');

// LEI 2: SAIR vem antes de tudo, carimba sempre, responde so se nao silenciado
// LEI 5: humano nunca volta para o robo, nem com fluxo ligado
// LEI 4: envio que falha nao avanca card_atual nem estado
ok(wa_processar($msg) === 'falha_envio', 'envio falhado devolve falha_envio');
ok(wa_conversa($fone)['card_atual'] === $card_antes, 'card_atual nao avancou');

// LEI 6: mensagem do cliente reseta lembrete_at E aguardando_desde juntos

// PORTA SO FORA DE CARD QUE ESPERA (spec 6.11)
// conversa parada num card que espera + mensagem com a palavra de outro fluxo
ok(wa_processar($msg_cruzeiro_no_meio) === 'fluxo', 'a mensagem e processada');
ok(wa_conversa($fone)['fluxo_versao_id'] === $versao_original, 'nao trocou de fluxo no meio');
ok(wa_conversa($fone)['card_atual'] === 'ops', 'caiu no fallback do card que esperava');
// a mesma mensagem com a conversa encerrada ABRE o fluxo da palavra
ok(wa_conversa($fone2)['fluxo_versao_id'] === $versao_cruzeiro, 'encerrado conta como fora de conversa');

// REVIEW FOCUS 1: card_atual que nao existe mais no retrato
wa_conversa_set($fone, ['card_atual' => 'card-que-foi-apagado']);
ok(wa_processar($msg) === 'card_perdido', 'card orfao tem retorno proprio');
ok(in_array(wa_conversa($fone)['estado'], ['humano', 'encerrado'], true), 'nao fica mudo: vai para humano ou encerra');

// Nenhum fluxo ligado: o motor nao responde nada e loga
ok(wa_processar($msg_sem_fluxo) === 'sem_fluxo', 'sem fluxo ligado o motor nao responde');
ok($ENVIADAS === [], 'e nada e enviado');

// Cadeia de cards que nao esperam nao roda para sempre
ok(WA_FLUXO_MAX_PASSOS === 10, 'o teto de passos por mensagem e 10');
wa_processar($msg_em_cadeia_circular);
ok($passos_executados <= WA_FLUXO_MAX_PASSOS, 'a cadeia para no teto, nao em loop infinito');
```

Em `tests/test-wa-motor.php`, substituir as asserções que dependiam dos estados antigos (`enviado_roteiro`, `aguardando_roteiro`, `qualificado`, `desqualificado`) pelas equivalentes em `aguardando` / `card_atual`. **Não remover** nenhuma asserção das nove leis que já está lá, inclusive a do teste 19 (evento de status não escreve em `po_wa_mensagens`).

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-wa-fluxo-leis.php`
Expected: FAIL (o motor ainda não resolve porta nem card)

- [ ] **Step 3: Reescrever a segunda metade de `wa_processar` em `lib/wa-motor.php`**

Fica intacto, na ordem em que está hoje: o ramo de `status`, o ramo de `eco`, o SAIR, a checagem de `humano`, o reset do relógio, e a criação de lead no primeiro contato. Entra no lugar de tudo que vem depois (de "Já mandou o roteiro" até o fim da função):

1. Se a conversa está parada num card que **espera** (`estado === 'aguardando'` e `card_atual` presente): carrega o grafo por `fluxo_versao_id`, roteia com `wa_fluxo_proximo`. Card ausente do grafo → `card_perdido`.
2. Senão: `wa_fluxo_porta($ev, $fluxos_ativos)`. Nenhum fluxo → `sem_fluxo`, e **nada é enviado**.
3. Com o card de destino em mãos, executa em laço com `wa_fluxo_executa` enquanto o card não espera e não é terminal, no máximo `WA_FLUXO_MAX_PASSOS` vezes.
4. Grava `fluxo_versao_id`, `card_atual`, `tentativas`, `roteiro_slug`, `aguardando_desde` e o espelho de `estado` (§6.5 do spec) **só depois de o envio ter saído** (lei 4).

Deletar `wa_resposta_data`, `wa_resposta_grupo`, `wa_respostas_numeradas`, `wa_sim_nao`, `wa_oracoes`, `wa_assunto_oracao`, `wa_tem_duvida`, `WA_MARCAS_GRUPO`, `WA_MARCAS_DATA`, `WA_MESES` e `wa_envia_roteiro` **não** faz parte desta task: elas ficam até o Plano A estar no ar e verificado. Marcar com um comentário `@deprecated Plano A: substituidas pelos cards botoes` e deixar os testes delas de pé.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php tests/test-wa-fluxo-leis.php && php tests/test-wa-motor.php`
Expected: `test-wa-fluxo-leis: ok` e o `test-wa-motor` passando

- [ ] **Step 5: Run the suite**

Run: `php tests/run.php`
Expected: todos PASS

- [ ] **Step 6: Commit**

```bash
git add lib/wa-motor.php tests/test-wa-motor.php tests/test-wa-fluxo-leis.php
git commit -m "feat(fluxo): wa_processar delega o roteamento ao interpretador"
```

---

## Task 9: O relógio

**Files:**
- Modify: `lib/wa-timeout.php`
- Modify: `tests/test-wa-timeout.php`

**Interfaces:**
- Consumes: `wa_fluxo_grafo`, `wa_fluxo_sem_resposta`, `wa_fluxo_executa`.
- Produces: `WA_HORAS_LEMBRETE = 20`; `WA_HORAS_JANELA = 24`; `wa_varre_timeouts($agora = null)` com o retorno ampliado `['lembretes'=>int, 'encerrados'=>int, 'fora_da_janela'=>int]`. `wa_to_call` ganha os nomes `grafo` e `executa`.

- [ ] **Step 1: Write the failing test**

Em `tests/test-wa-timeout.php`, acrescentando às asserções que já existem:

```php
ok(WA_HORAS_LEMBRETE === 20, 'o lembrete sai em 20h, com 4h de folga antes da borda da janela de 24h');
ok(WA_HORAS_JANELA === 24, 'a janela gratis e de 24h');

// 1. seleciona por estado aguardando, nao pelos estados antigos
ok(strpos($QUERY_USADA, 'estado=eq.aguardando') !== false, 'varre por aguardando');
ok(strpos($QUERY_USADA, 'order=aguardando_desde.asc&limit=500') !== false, 'ordem e limite preservados');

// 2. o texto do lembrete vem do CARD, nao de po_wa_textos
ok($ENVIADAS[0][1] === 'Oi Marta, viu o roteiro da Turquia com Antalia?', 'lembrete_texto do card, com variavel substituida');

// 3. card com lembrete_ativo = false nao recebe lembrete, e espera o encerramento
ok($r['lembretes'] === 0, 'card com lembrete desligado nao manda lembrete');

// 4. so grava lembrete_at se o envio saiu (lei 4)
ok($ESCRITAS === [], 'envio falhado nao carimba lembrete_at');

// 5. GUARDA DA JANELA: conversa esperando ha mais de 24h nao recebe lembrete, encerra
$r = wa_varre_timeouts($t0 + 25 * 3600);
ok($r['lembretes'] === 0 && $r['fora_da_janela'] === 1, 'fora da janela nao tenta enviar');
ok($estado_final === 'encerrado', 'e encerra');
ok($status_lead === 'perdido', 'carimbando perdido');

// 6. encerramento normal: 20h + 24h
// 7. CARIMBO perdido nos DOIS caminhos de encerramento
foreach (['normal', 'sem_resposta', 'fora_da_janela'] as $caminho) {
    ok($status_lead_de[$caminho] === 'perdido', "carimba perdido no caminho $caminho");
}

// 8. sem_resposta: executa o card em vez de encerrar seco
ok($ENVIADAS_SR[0][0] === 'text', 'o card de sem_resposta e executado');
ok($conv['card_atual'] === 'fim', 'e a conversa segue para ele');

// 9. sem_resposta para card que ESPERA nunca e executado pelo cron, mesmo se chegar ao banco
// (a regra 12 impede publicar, mas o cron nao confia na validacao de quem publicou)
ok($ENVIADAS_ESPERA === [], 'o cron recusa executar card que espera');
ok($estado === 'encerrado', 'e encerra em vez de mandar botoes de madrugada');

// 10. conversa em humano ou terminal nunca e tocada
ok($r['encerrados'] === 0, 'conversa em humano nao e encerrada por relogio');

// 11. o lembrete entra no log de saida, senao o eco dele viraria handoff
$fonte = file_get_contents(__DIR__ . '/../lib/wa-timeout.php');
ok(strpos($fonte, 'wa_registra_saida') !== false, 'o lembrete e registrado como saida do robo');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-wa-timeout.php`
Expected: FAIL com "WA_HORAS_LEMBRETE === 20"

- [ ] **Step 3: Adaptar `lib/wa-timeout.php`**

A estrutura do laço fica. Mudam: a constante (com o comentário explicando que 24 caía na borda da janela e um cron horário não alcança), a query e a guarda dupla (`estado === 'aguardando'`), a origem do texto do lembrete (grafo + `card_atual` + `lembrete_ativo`/`lembrete_texto`, com as variáveis de `wa_fluxo_vars_do_roteiro`), a guarda de janela antes de qualquer envio, e o encerramento que consulta `wa_fluxo_sem_resposta` e, havendo card, executa-o por `wa_fluxo_executa` **se e só se** seu tipo estiver em `WA_SEM_RESPOSTA_TIPOS`.

`wa_to_call` ganha os padrões `'grafo' => 'wa_fluxo_grafo'` e `'executa' => 'wa_fluxo_executa'`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test-wa-timeout.php`
Expected: passando

- [ ] **Step 5: Run the suite**

Run: `php tests/run.php`
Expected: todos PASS

- [ ] **Step 6: Commit**

```bash
git add lib/wa-timeout.php tests/test-wa-timeout.php
git commit -m "feat(fluxo): o relogio em 20h, lembrete do card e sem_resposta"
```

---

## Task 10: A migration semeada

**Files:**
- Create: `supabase/migrations/2026-10-27-fluxo-semeado.sql`
- Modify: `tests/test-fluxo-migration.php`
- Test: `tests/test-wa-fluxo-semeado.php`

**Interfaces:**
- Consumes: tudo. Esta task é a prova de que as nove anteriores fecham.
- Produces: os dois fluxos do spec §6.10, publicados na versão 1, `ativo = false`.

- [ ] **Step 1: Write the failing test**

`tests/test-wa-fluxo-semeado.php` extrai os dois grafos do SQL da migration e os passa pelo validador e pelo roteador. Nenhuma rede.

```php
<?php
require __DIR__ . '/../lib/wa-fluxo.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

// O SQL grava o retrato como jsonb; o teste le os literais JSON da migration.
$sql = file_get_contents(__DIR__ . '/../supabase/migrations/2026-10-27-fluxo-semeado.sql');
preg_match_all("/jsonb_literal_marker:(\{.*?\})\s*END_MARKER/s", $sql, $m);  // ver Step 3
$grafos = array_map(fn($j) => json_decode($j, true), $m[1]);
ok(count($grafos) === 2, 'a migration semeia dois fluxos');

foreach ($grafos as $g) {
    ok(is_array($g) && isset($g['cards'], $g['inicial']), 'o grafo semeado e JSON valido no formato do interpretador');
    // 1. valida limpo: zero bloqueios
    $cards = array_values($g['cards']);
    $fluxo = ['id' => $g['id'], 'nome' => $g['nome'], 'gatilho' => $g['porta'],
              'gatilho_valor' => $g['porta_valor'], 'ativo' => false, 'card_inicial' => $g['inicial']];
    $outros = [['id' => 'espera-id', 'gatilho' => 'chamado', 'gatilho_valor' => null, 'ativo' => true, 'versao_atual' => 'v1']];
    foreach (wa_fluxo_valida($fluxo, $cards, $outros) as $p) {
        ok(!$p['bloqueia'], 'fluxo semeado sem bloqueio: ' . $p['texto']);
    }
    // 2. todo rotulo de botao cabe em 20 caracteres (o rascunho visual ja respeitava)
    foreach ($g['cards'] as $c) {
        foreach ($c['opcoes'] ?? [] as $o) {
            ok(mb_strlen($o['rotulo'], 'UTF-8') <= 20, 'rotulo "' . $o['rotulo'] . '" cabe em 20');
        }
        // 3. copy Freela: sem travessao, sem emoji
        ok(strpos((string) $c['texto'], '—') === false, 'nenhum travessao no texto do card ' . $c['id']);
        ok(!preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', (string) $c['texto']), 'nenhum emoji');
    }
}

// 4. o fluxo de boas-vindas percorre os caminhos do spec 6.10 de ponta a ponta
$bv = $grafos[0];
$ctx = ['roteiro_slug' => null, 'tentativas' => 0];
$r = wa_fluxo_proximo($bv, $bv['inicial'], 'b_roteiro', $ctx);
ok($bv['cards'][$r['card']]['tipo'] === 'lista', 'o primeiro botao leva a lista de roteiros');
$r = wa_fluxo_proximo($bv, $r['card'], 'turquia', $r['ctx']);
ok($r['ctx']['roteiro_slug'] === 'turquia', 'a escolha grava o slug');
$tipos = [];
$card = $r['card']; $c = $r['ctx'];
for ($i = 0; $i < 10 && $card !== null; $i++) {
    $tipos[] = $bv['cards'][$card]['tipo'];
    if (in_array($bv['cards'][$card]['tipo'], WA_CARD_ESPERA, true)) break;
    $r = wa_fluxo_proximo($bv, $card, '', $c); $card = $r['card']; $c = $r['ctx'];
}
ok(in_array('pdf', $tipos, true) && in_array('marca', $tipos, true),
   'depois da escolha vem o pdf e a marcacao do interesse, antes de qualquer pergunta');

// 5. todo card que espera tem fallback, e o terceiro botao de "a data serve" existe
$achou_terceiro = false;
foreach ($bv['cards'] as $cd) {
    if (in_array($cd['tipo'], WA_CARD_ESPERA, true)) ok(!empty($cd['fallback']), 'card ' . $cd['id'] . ' tem fallback');
    if (count($cd['opcoes'] ?? []) === 3 && $cd['tipo'] === 'botoes') $achou_terceiro = true;
}
ok($achou_terceiro, 'existe um card com tres botoes: quem compara dois roteiros tem por onde voltar');

// 6. o fluxo de lista de espera e porta chamado, nao tem humano, e termina
$esp = $grafos[1];
ok($esp['porta'] === 'chamado', 'o segundo fluxo e chamado por outro');
$tem_terminal = false;
foreach ($esp['cards'] as $cd) if (in_array($cd['tipo'], WA_CARD_TERMINAL, true)) $tem_terminal = true;
ok($tem_terminal, 'o fluxo de espera termina');

echo "test-wa-fluxo-semeado: ok\n";
```

Acrescentar a `tests/test-fluxo-migration.php`:

```php
$seed = file_get_contents(__DIR__ . '/../supabase/migrations/2026-10-27-fluxo-semeado.sql');
ok(preg_match('/on conflict .* do nothing/i', $seed), 'o seed e idempotente');
ok(!preg_match('/ativo\s*=\s*true/i', $seed), 'o seed NAO liga nenhum fluxo');
ok(preg_match('/po_wa_fluxo_versoes/i', $seed) && preg_match('/\bversao\b.*\b1\b/i', $seed),
   'o seed publica a versao 1');
ok(!preg_match('/\+55(?!48900000)\d{10,11}/', $seed), 'nenhum telefone real no seed');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-wa-fluxo-semeado.php`
Expected: FAIL com "a migration semeia dois fluxos"

- [ ] **Step 3: Escrever `supabase/migrations/2026-10-27-fluxo-semeado.sql`**

Os dois fluxos do spec §6.10, com ids fixos (`uuid` literais, não `gen_random_uuid()`, para o seed ser idempotente e para o `ir_para_fluxo` poder apontar para a Lista de espera). Cada fluxo: a linha em `po_wa_fluxos` com `ativo = false`, as linhas em `po_wa_cards`, e a linha em `po_wa_fluxo_versoes` com `versao = 1` e o `grafo` jsonb, tudo com `on conflict do nothing`.

Os textos dos cards vêm dos sete textos de hoje em `po_wa_textos` (`menu`, `envio_pdf`, `perguntas`, `qualificado`, `sem_data`, `lembrete`, `lembrete_menu`) para que nada que a cliente escreveu se perca, mais os textos novos do rascunho visual para os cards que não existiam (boas-vindas, "a data não serve", "respondeu outra coisa", lista de espera). `po_wa_textos` **não é apagada**.

Os rótulos de botão são os do rascunho visual, que já cabem em 20 caracteres: `Um roteiro específico`, `Ver a programação`, `Falar com a Laís`, `Sim, funciona`, `Não nessa data`, `Ver outro roteiro`, `Já viajei`, `Primeira vez`, `Só esse destino`, `Ver outras datas`.

Para o `preg_match_all` do teste encontrar os grafos, envolver cada literal jsonb em comentários SQL marcadores. Se isso ficar frágil, trocar a extração do teste por uma que case `insert into public.po_wa_fluxo_versoes` e pegue o argumento jsonb; o importante é o teste validar **o JSON que a migration grava**, não uma cópia dele.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php tests/test-wa-fluxo-semeado.php && php tests/test-fluxo-migration.php`
Expected: ambos ok

- [ ] **Step 5: Run the suite**

Run: `php tests/run.php`
Expected: todos PASS

- [ ] **Step 6: Commit**

```bash
git add supabase/migrations/2026-10-27-fluxo-semeado.sql tests/test-wa-fluxo-semeado.php tests/test-fluxo-migration.php
git commit -m "feat(fluxo): o fluxo semeado, publicado na versao 1 e desligado"
```

---

## Depois da última task

O Plano A **não vai para o ar nesta sequência**. O que fica pronto é código e suíte. Antes de publicar:

1. Rodar as duas migrations no Supabase, nesta ordem.
2. Conferir no banco que os dois fluxos existem com `ativo = false` e `versao_atual` apontando para a versão 1.
3. Subir por FTP: `lib/wa-fluxo.php`, `lib/wa-send.php`, `lib/wa-motor.php`, `lib/wa-timeout.php`. **Nenhum arquivo de `painel/`, então nenhum `?v=` a subir neste plano.**
4. **Não** cadastrar cron no cPanel e **não** inscrever o webhook: o robô segue inerte, e ligar é decisão da cliente.
