# Transmissão (plano 4 de 4) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Repor a lista de transmissão que a coexistência desativa, com segmentação, custo na tela antes de confirmar, relatório de entrega e as três defesas obrigatórias da spec 8.1.

**Architecture:** Uma campanha é uma linha em `po_wa_campanhas`; cada destinatário é uma linha em `po_wa_envios` **reservada antes do envio** (o índice único é o cadeado, mesmo padrão do lote de importação do plano 3.1). O painel monta o público, mostra contagem e custo, e confirma; o `wa-cron.php` drena a fila em lotes. Todo envio sai por **template** aprovado pela Meta, porque fora da janela de 24h não existe outro caminho. O recibo de entrega chega pelo webhook e atualiza `po_wa_envios` pelo `wamid`.

**Tech Stack:** PHP 8 no cPanel (sem Composer, sem build), Supabase REST, WhatsApp Cloud API Graph v21, painel em HTML/JS puro.

**Spec:** `docs/superpowers/specs/2026-09-11-automacao-whatsapp-crm-design.md` — seções 8, 8.1, 8.2, e 4.2 para as tabelas.

## Global Constraints

- **Nenhum teste toca a rede.** Use os transportes injetáveis que já existem: `wa_set_transport()` (lib/wa-send.php), `wa_db_set_transport()` (lib/wa-db.php), `po_auth_set_verificador()` (lib/po-auth.php).
- **Runner da suíte:** `php tests/run.php`. Testes PHP usam `ok($cond,$msg)` + `exit(1)`; testes `.mjs` usam `node:assert/strict`. Funções são extraídas do fonte por regex nos testes `.mjs`.
- **Sem Composer, sem npm, sem etapa de build.** Só PHP 8 e JS de navegador.
- **Deploy é FTP manual.** Todo arquivo alterado em `painel/` exige subir o `?v=` em `painel/index.html` **e** atualizar o sha em `painel/assets-lock.json`, senão `tests/test-cache-buster.mjs` fica vermelho.
- **Nunca usar dado real de cliente em fixture.** O repositório é público. O CPF sintético do projeto é `111.444.777-35`; telefones de teste devem ser claramente inventados.
- **As três regras do Armando (dono), critério de aceitação:** (1) registro com histórico é o dono e nunca é sobrescrito; (2) a fusão só soma, nunca zera; (3) nunca duplicar.
- **Segredo vazio ou com menos de 16 caracteres nunca autoriza** (`hash_equals('','')` é `true`).
- **`po_config()` não expõe segredo.** Segredo vem de `wa_config()` (lib/wa-config.php).
- **Limites da Cloud API:** 1024 caracteres para legenda de documento e corpo de lista, 4096 para texto. Corpo de template também para em 1024. Nada trunca: acima disso a Meta devolve 400 e o cliente não recebe nada, em silêncio.
- **Copy da Freela para texto que NÓS escrevemos na UI:** português, sem travessões, sem emojis, números concretos. (Os textos do robô são escritos pela cliente e seguem o gosto dela.)
- **O eco (`smb_message_echoes`) silencia o robô para sempre naquele contato.** Robô e humana falando por cima uma da outra é o pior modo de falha do sistema.
- **Estado só avança se o envio saiu** (`ok=true`, que garante `wamid`).

---

## Decisões de arquitetura tomadas antes do plano

Estas três decisões divergem da letra da spec. Elas foram tomadas contra o estado **real** do banco e do código, e o implementador não deve revertê-las sem falar com o controlador.

**1. O público da transmissão sai de `po_leads`, não de `po_wa_contatos`.**
A spec 4.2 desenhou `po_wa_contatos` como "a base, um registro por pessoa". A tabela existe no banco, está **vazia (0 linhas)** e é referenciada por **zero linhas de código**. Os planos 1 a 3 construíram tudo sobre `po_leads`: o importador escreveu as 775 fichas do CRM lá, o painel lê de lá, e `wa_lead()` do motor insere lá. Criar agora uma segunda tabela de pessoas forkaria a identidade e violaria a regra 3 do Armando (nunca duplicar). `po_wa_contatos` fica abandonada; `opt_out_at` vai para `po_leads`.

**2. Fora da janela de 24h só existe template.** A transmissão nunca alcança um contato dentro da janela, então `wa_send_text` não serve. É por isso que a Task 2 existe antes de qualquer coisa de campanha.

**3. A idempotência de ENTREGA mora em `po_wa_envios`, por `wamid`.**
O `wamid` de um evento `status` **é** o `wamid` da mensagem original, e `po_wa_mensagens.wamid` é `unique`. Passar status pela idempotência de `po_wa_mensagens` faz recibo e eco competirem pela mesma linha, e o eco vira `duplicado` — **o robô não se cala**. Isso já foi implementado por engano uma vez e revertido (faxina de 13/09, Ruling 7). Não repita.

---

## Estrutura de arquivos

| Arquivo | Responsabilidade |
|---|---|
| `supabase/migrations/2026-09-14-transmissao.sql` | `po_wa_campanhas`, `po_wa_envios`, `po_leads.opt_out_at`, índices e RLS |
| `lib/wa-send.php` (modificar) | ganha `wa_send_template()` |
| `lib/wa-camp.php` (criar) | funções **puras**: elegibilidade, público, custo, números suspeitos, escada de lotes |
| `lib/wa-camp-fila.php` (criar) | reserva e drenagem da fila (toca banco, transporte injetável) |
| `lib/wa-motor.php` (modificar) | detecção de SAIR e roteamento do recibo de entrega |
| `campanha.php` (criar) | endpoint autenticado: prévia e confirmação |
| `wa-cron.php` (modificar) | drena a fila junto com a varredura de timeouts |
| `painel/campanhas.js` (criar) | aba Transmissão |
| `painel/index.html` (modificar) | a aba, o `?v=` e o `assets-lock.json` |

Os módulos puros (`wa-camp.php`) ficam separados dos que tocam banco (`wa-camp-fila.php`) porque é o que permite testar a regra de negócio inteira sem simulador.

---

### Task 1: Migrations, `opt_out_at` e as travas de unicidade

**Files:**
- Create: `supabase/migrations/2026-09-14-transmissao.sql`
- Test: `tests/test-transmissao-schema.mjs`

**Interfaces:**
- Consumes: nada.
- Produces: as tabelas `po_wa_campanhas` e `po_wa_envios`, a coluna `po_leads.opt_out_at`, e os dois índices únicos de que as Tasks 5 e 7 dependem (`po_wa_envios_camp_lead_uniq`, `po_wa_envios_wamid_uniq`).

- [ ] **Step 1: Escrever o teste que falha**

O teste lê o **texto** da migration. Não há como rodar SQL offline neste projeto, e um teste que tocasse o Supabase quebraria a regra global. O que ele tranca é o que o código das tasks seguintes assume: os dois índices únicos e a coluna de saída. Se alguém apagar o índice, a Task 5 perde o cadeado e a campanha passa a poder enviar duas vezes para a mesma pessoa.

Criar `tests/test-transmissao-schema.mjs`:

```js
/* A reserva-antes-do-envio da Task 5 usa o INDICE UNICO como cadeado: o
   insert que conflita devolve null e o destinatario e pulado. Sem o indice,
   dois drenos simultaneos (o cron e o botao do painel) mandam a mesma
   campanha duas vezes para a mesma pessoa, a R$ 0,31 cada, e nada acusa.
   Por isso o indice e testado, e nao so escrito. */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const raiz = join(dirname(fileURLToPath(import.meta.url)), '..');
const sql = readFileSync(
  join(raiz, 'supabase/migrations/2026-09-14-transmissao.sql'), 'utf8').toLowerCase();

assert.ok(/create table if not exists public\.po_wa_campanhas/.test(sql),
  'cria po_wa_campanhas');
assert.ok(/create table if not exists public\.po_wa_envios/.test(sql),
  'cria po_wa_envios');

// O cadeado da Task 5.
assert.ok(/create unique index if not exists po_wa_envios_camp_lead_uniq[\s\S]*?\(campanha_id, lead_id\)/.test(sql),
  'po_wa_envios tem unique (campanha_id, lead_id), que e o cadeado da reserva');

// A chave do recibo de entrega da Task 7.
assert.ok(/create unique index if not exists po_wa_envios_wamid_uniq[\s\S]*?\(wamid\)[\s\S]*?where wamid is not null/.test(sql),
  'po_wa_envios tem unique parcial em wamid, que e por onde o recibo acha o envio');

// A defesa 3 da spec 8.1 mora em po_leads, nao em po_wa_contatos (ver as
// decisoes de arquitetura do plano).
assert.ok(/alter table public\.po_leads[\s\S]*?add column if not exists opt_out_at/.test(sql),
  'opt_out_at entra em po_leads');
assert.ok(!/po_wa_contatos/.test(sql),
  'a migration NAO toca po_wa_contatos, que esta abandonada');

// FK com o tipo certo: todas as tabelas do projeto usam uuid/gen_random_uuid.
assert.ok(/lead_id uuid not null references public\.po_leads\(id\)/.test(sql),
  'lead_id e uuid e referencia po_leads');

// O nome e o parametro {{1}} do template. Sem a coluna, o dreno manda vazio
// e o cliente recebe "Ola ,".
assert.ok(/nome\s+text\s+not null default ''/.test(sql),
  'po_wa_envios guarda o nome fotografado na reserva');

/* Contador copiado desanda em silencio: enviados/entregues/lidos/falhas sao
   CONTADOS de po_wa_envios, nunca guardados em po_wa_campanhas. */
assert.ok(!/^\s*(enviados|entregues|lidos|falhas)\s+integer/m.test(sql),
  'po_wa_campanhas NAO tem contador copiado de entrega');

// RLS ligada, no padrao das tabelas existentes (<tabela>_auth).
assert.ok(/alter table public\.po_wa_campanhas enable row level security/.test(sql),
  'RLS ligada em po_wa_campanhas');
assert.ok(/alter table public\.po_wa_envios enable row level security/.test(sql),
  'RLS ligada em po_wa_envios');

console.log('test-transmissao-schema OK');
```

- [ ] **Step 2: Rodar para ver falhar**

Run: `node tests/test-transmissao-schema.mjs`
Expected: FAIL com `ENOENT` — o arquivo da migration ainda não existe.

- [ ] **Step 3: Escrever a migration**

Criar `supabase/migrations/2026-09-14-transmissao.sql`:

```sql
-- Plano 4: transmissao (spec secao 8).
--
-- DECISAO REGISTRADA: o publico sai de po_leads, NAO de po_wa_contatos.
-- A spec 4.2 desenhou po_wa_contatos como "a base", mas a tabela esta vazia
-- e nao e lida por nenhuma linha de codigo: os planos 1 a 3 construiram tudo
-- sobre po_leads (as 775 fichas do CRM, o painel, o wa_lead() do motor).
-- Uma segunda tabela de pessoas forkaria a identidade e quebraria a regra 3
-- do Armando (nunca duplicar). Por isso opt_out_at entra em po_leads.

create table if not exists public.po_wa_campanhas (
  id                      uuid primary key default gen_random_uuid(),
  nome                    text        not null,
  roteiro_slug            text,
  template                text        not null,   -- nome do template aprovado na Meta
  corpo                   text        not null,   -- nossa copia do corpo, para a previa e para exigir o SAIR
  segmento                jsonb       not null default '{}'::jsonb,
  -- total e custo sao FOTOGRAFIA do momento da criacao, e por isso ficam aqui.
  -- Ja enviados/entregues/lidos/falhas NAO tem coluna de proposito: eles sao
  -- contados de po_wa_envios na hora de mostrar. Contador copiado desanda em
  -- silencio no primeiro retry ou webhook fora de ordem, e um relatorio de
  -- entrega errado e pior que nenhum - foi a mesma licao do somatorio de
  -- venda no painel, que inflava o faturamento para sempre.
  total                   integer     not null default 0,
  preco_centavos          integer     not null,   -- congelado na criacao: o preco da Meta muda
  custo_estimado_centavos integer     not null default 0,
  status                  text        not null default 'rascunho',
  created_at              timestamptz not null default now(),
  concluida_at            timestamptz,
  constraint po_wa_campanhas_status_chk
    check (status in ('rascunho','enviando','concluida','cancelada'))
);

create table if not exists public.po_wa_envios (
  id           uuid primary key default gen_random_uuid(),
  campanha_id  uuid        not null references public.po_wa_campanhas(id) on delete cascade,
  lead_id      uuid        not null references public.po_leads(id)        on delete cascade,
  wa_id        text        not null,
  -- Nome FOTOGRAFADO na reserva, nao lido do lead na hora do envio: e o nome
  -- que a cliente viu e aprovou na previa. Se a ficha for editada entre a
  -- confirmacao e o dreno, o template ainda sai com o nome revisado. E o
  -- parametro {{1}} do template, entao vazio aqui e "Ola ," no cliente.
  nome         text        not null default '',
  wamid        text,
  status       text        not null default 'reservado',
  erro         text,
  reservado_at timestamptz not null default now(),
  enviado_at   timestamptz,
  entregue_at  timestamptz,
  lido_at      timestamptz,
  constraint po_wa_envios_status_chk
    check (status in ('reservado','enviado','entregue','lido','falha'))
);

-- O CADEADO da reserva-antes-do-envio. O insert que conflita devolve 409, o
-- wa_db_insert(..., true) devolve null, e o destinatario e pulado. Sem isto,
-- dois drenos ao mesmo tempo (o cron e o botao do painel) mandam a mesma
-- campanha duas vezes para a mesma pessoa, a R$ 0,31 cada.
create unique index if not exists po_wa_envios_camp_lead_uniq
  on public.po_wa_envios (campanha_id, lead_id);

-- Por onde o recibo de entrega acha o envio. E aqui que a idempotencia de
-- ENTREGA vive, e nao em po_wa_mensagens: o wamid de um evento `status` E o
-- wamid da mensagem original, e po_wa_mensagens.wamid e unique, entao recibo
-- e eco competiriam pela mesma linha e o eco viraria `duplicado` - fazendo o
-- robo NAO se calar. Parcial porque a linha nasce reservada, sem wamid.
create unique index if not exists po_wa_envios_wamid_uniq
  on public.po_wa_envios (wamid) where wamid is not null;

create index if not exists po_wa_envios_campanha_status_idx
  on public.po_wa_envios (campanha_id, status);

-- Defesa 3 da spec 8.1: "Responda SAIR para nao receber mais", com baixa
-- automatica. Quem saiu e excluido de toda campanha futura, sem excecao.
alter table public.po_leads add column if not exists opt_out_at timestamptz;

create index if not exists po_leads_opt_out_idx
  on public.po_leads (opt_out_at) where opt_out_at is not null;

-- RLS no padrao das tabelas existentes (po_wa_mensagens_auth, po_wa_conversas_auth):
-- so usuario autenticado. O PHP escreve com a service_role, que ignora RLS.
alter table public.po_wa_campanhas enable row level security;
alter table public.po_wa_envios    enable row level security;

drop policy if exists po_wa_campanhas_auth on public.po_wa_campanhas;
create policy po_wa_campanhas_auth on public.po_wa_campanhas
  for all to authenticated using (true) with check (true);

-- Envios sao SO LEITURA para o painel: quem escreve e o dreno, com a
-- service_role. Painel que pudesse marcar 'enviado' na mao mentiria no
-- relatorio de entrega e no custo.
drop policy if exists po_wa_envios_auth on public.po_wa_envios;
create policy po_wa_envios_auth on public.po_wa_envios
  for select to authenticated using (true);
```

- [ ] **Step 4: Rodar o teste**

Run: `node tests/test-transmissao-schema.mjs`
Expected: PASS com `test-transmissao-schema OK`

- [ ] **Step 5: Rodar a suíte inteira**

Run: `php tests/run.php`
Expected: `Todos os testes passaram`

- [ ] **Step 6: Commit**

```bash
git add supabase/migrations/2026-09-14-transmissao.sql tests/test-transmissao-schema.mjs
git commit -m "feat(db): tabelas da transmissao e opt_out_at em po_leads"
```

> **Nota para o controlador:** a migration **não** é aplicada pelo implementador. Quem roda no Supabase é o controlador, como foi feito nos planos 3 e 3.1.

---

### Task 2: `wa_send_template`, o único caminho fora da janela de 24h

**Files:**
- Modify: `lib/wa-send.php` (acrescentar ao fim, junto de `wa_send_list`)
- Test: `tests/test-wa-send.php` (arquivo já existe, acrescentar ao fim antes do `echo`)

**Interfaces:**
- Consumes: `wa_envia($mensagem)`, `wa_destino($para)`, `wa_set_transport($f)` — já existem em `lib/wa-send.php`.
- Produces: `wa_send_template($para, $template, $params = [], $idioma = 'pt_BR')` devolvendo `['ok'=>bool,'wamid'=>?string,'erro'=>?string]`, a mesma forma de `wa_send_text`.

- [ ] **Step 1: Escrever os testes que falham**

Acrescentar em `tests/test-wa-send.php`, **antes** da linha `echo "test-wa-send OK\n";`:

```php
/* ---------- template ----------
   Fora da janela de 24h a Cloud API SO aceita template. A transmissao nunca
   alcanca alguem dentro da janela, entao este e o unico caminho de envio do
   plano 4. O corpo aprovado vive na Meta; o que mandamos sao os parametros
   posicionais, na ordem em que {{1}}, {{2}} aparecem no template aprovado. */
$capturado = null;
wa_set_transport(function ($url, $payload, $headers) use (&$capturado) {
    $capturado = ['url' => $url, 'payload' => json_decode($payload, true)];
    return ['status' => 200, 'body' => json_encode(['messages' => [['id' => 'wamid.TPL1']]])];
});

$r = wa_send_template('+5548999990001', 'roteiro_novo_2026', ['Marlene', 'Mercados de Natal']);
ok($r['ok'] === true,            'template com resposta 200 devolve ok');
ok($r['wamid'] === 'wamid.TPL1', 'template devolve o wamid da Meta');

$p = $capturado['payload'];
ok($p['type'] === 'template',                  "type e 'template' (veio: {$p['type']})");
ok($p['template']['name'] === 'roteiro_novo_2026', 'o nome do template vai no payload');
ok($p['template']['language']['code'] === 'pt_BR', 'idioma padrao e pt_BR');

$corpo = $p['template']['components'][0];
ok($corpo['type'] === 'body', 'o primeiro componente e o body');
ok(count($corpo['parameters']) === 2, 'os dois parametros foram enviados');
ok($corpo['parameters'][0]['text'] === 'Marlene',
   'a ORDEM dos parametros e preservada: o primeiro e o primeiro');
ok($corpo['parameters'][1]['text'] === 'Mercados de Natal',
   'a ordem dos parametros e preservada: o segundo e o segundo');

/* Template SEM parametros nao pode mandar components: a Graph API devolve
   132000 ("number of parameters does not match") e a mensagem inteira morre. */
wa_send_template('+5548999990001', 'aviso_simples');
ok(!isset($capturado['payload']['template']['components']),
   'template sem parametros nao manda o bloco components');

/* Nome de template vazio nunca pode virar chamada: a Meta devolveria 400 e
   o custo do erro e um destinatario que nao recebeu nada, em silencio. */
$antes = $capturado;
$r = wa_send_template('+5548999990001', '');
ok($r['ok'] === false,          'template sem nome nao e enviado');
ok($capturado === $antes,       'template sem nome nao chega a chamar a Graph API');

wa_set_transport(null);
```

- [ ] **Step 2: Rodar para ver falhar**

Run: `php tests/test-wa-send.php`
Expected: FAIL com `Call to undefined function wa_send_template()`

- [ ] **Step 3: Implementar**

Acrescentar ao fim de `lib/wa-send.php`:

```php
/* Envio por TEMPLATE. Fora da janela de 24h a Cloud API nao aceita outra
   coisa, e a transmissao por definicao alcanca quem nao escreveu hoje.

   O corpo do texto NAO vem daqui: ele vive aprovado na Meta. O que mandamos
   sao os parametros posicionais, na ordem em que {{1}}, {{2}} aparecem no
   template aprovado. Ordem trocada aqui manda o nome do roteiro no lugar do
   nome da pessoa, e o cliente recebe "Ola Mercados de Natal".

   Sem parametros, o bloco `components` e OMITIDO: mandar components vazio
   faz a Graph devolver 132000 ("number of parameters does not match") e a
   mensagem inteira nao sai. */
function wa_send_template($para, $template, $params = [], $idioma = 'pt_BR') {
    $template = trim((string) $template);
    if ($template === '') {
        // Nome vazio viraria 400 na Meta e um destinatario sem nada, em
        // silencio. Recusa aqui, antes de gastar a chamada.
        error_log('wa_send_template: nome de template vazio, nada enviado');
        return ['ok' => false, 'wamid' => null, 'erro' => 'template sem nome'];
    }
    $msg = [
        'messaging_product' => 'whatsapp',
        'to'                => wa_destino($para),
        'type'              => 'template',
        'template'          => [
            'name'     => $template,
            'language' => ['code' => $idioma],
        ],
    ];
    if ($params) {
        $msg['template']['components'] = [[
            'type'       => 'body',
            'parameters' => array_map(
                function ($v) { return ['type' => 'text', 'text' => (string) $v]; },
                array_values($params)          // array_values: chave nomeada viraria objeto no JSON
            ),
        ]];
    }
    return wa_envia($msg);
}
```

- [ ] **Step 4: Rodar o teste**

Run: `php tests/test-wa-send.php`
Expected: PASS com `test-wa-send OK`

- [ ] **Step 5: Verificar por mutação**

Trocar `array_values($params)` por `$params` e rodar de novo: os testes de ordem devem continuar passando (array já é lista), então essa mutação **não** é coberta — é aceitável, a defesa é contra chave nomeada. Agora trocar `'pt_BR'` por `'en_US'` e confirmar que o teste do idioma fica **vermelho**. Desfazer as duas mutações.

Run: `php tests/test-wa-send.php`
Expected: vermelho na mutação do idioma, verde depois de desfazer.

- [ ] **Step 6: Commit**

```bash
git add lib/wa-send.php tests/test-wa-send.php
git commit -m "feat(wa): envio por template, o unico caminho fora da janela de 24h"
```

---

### Task 3: Público, custo e números suspeitos (funções puras)

**Files:**
- Create: `lib/wa-camp.php`
- Test: `tests/test-wa-camp.php`

**Interfaces:**
- Consumes: `wa_e164($bruto)` e `wa_e_celular($e164)` de `lib/wa-fone.php`.
- Produces:
  - `WA_CAMP_PRECO_CENTAVOS` (int, 31)
  - `wa_camp_telefone($lead)` → `?string` E.164
  - `wa_camp_motivo_fora($lead)` → `?string` (`saiu`|`nao_cliente`|`nao_revisado`|`sem_telefone`|`sem_celular`|null)
  - `wa_camp_publico($leads)` → `['publico'=>array, 'resumo'=>array]`
  - `wa_camp_custo($n, $preco_centavos)` → int centavos
  - `wa_camp_ddi_suspeito($payload_import)` → `?string` (o DDI de dois dígitos, ou null)

- [ ] **Step 1: Escrever o teste que falha**

Criar `tests/test-wa-camp.php`:

```php
<?php
require __DIR__ . '/../lib/wa-camp.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

/* ---------- quem entra e quem fica de fora ----------
   Defesa 1 da spec 8.1: "contato nao revisado nunca entra em campanha".
   Defesa 3: quem respondeu SAIR e excluido de toda campanha futura, sem
   excecao - por isso opt_out e a PRIMEIRA checagem, antes ate de cliente:
   alguem que saiu e depois foi marcado como nao-cliente tem que aparecer no
   balde 'saiu', que e o que a cliente olha para conferir a defesa. */
$base = ['cliente'=>true, 'revisado'=>true, 'opt_out_at'=>null,
         'telefone'=>'+5548999990001', 'wa_id'=>null, 'id'=>'L1', 'nome'=>'Teste Um'];

ok(wa_camp_motivo_fora($base) === null, 'cliente revisado com celular entra');

$x = $base; $x['opt_out_at'] = '2026-09-14T10:00:00Z';
ok(wa_camp_motivo_fora($x) === 'saiu', 'quem pediu SAIR nunca entra');

$x = $base; $x['opt_out_at'] = '2026-09-14T10:00:00Z'; $x['cliente'] = false;
ok(wa_camp_motivo_fora($x) === 'saiu',
   'opt_out e checado ANTES de cliente, senao quem saiu some no balde errado');

$x = $base; $x['cliente'] = false;
ok(wa_camp_motivo_fora($x) === 'nao_cliente', 'nao-cliente fica de fora');

$x = $base; $x['revisado'] = false;
ok(wa_camp_motivo_fora($x) === 'nao_revisado', 'nao revisado fica de fora (defesa 1)');

$x = $base; $x['telefone'] = null;
ok(wa_camp_motivo_fora($x) === 'sem_telefone', 'sem telefone fica de fora');

/* Fixo NUNCA entra no disparo: a transmissao manda so para celular (spec 8).
   Um template mandado para fixo e cobrado e nao entrega. */
$x = $base; $x['telefone'] = '+554832220000';
ok(wa_camp_motivo_fora($x) === 'sem_celular', 'fixo fica de fora do disparo');

/* wa_id vence telefone: e o numero com que o WhatsApp JA falou. */
$x = $base; $x['wa_id'] = '+5548988880002'; $x['telefone'] = '+5548999990001';
ok(wa_camp_telefone($x) === '+5548988880002', 'wa_id tem prioridade sobre telefone');

$x = $base; $x['wa_id'] = null; $x['telefone'] = '48 99999-0001';
ok(wa_camp_telefone($x) === '+5548999990001', 'telefone cru e normalizado');

/* ---------- deduplicacao ----------
   Regra 3 do Armando: nunca duplicar. Duas fichas da mesma pessoa (uma do
   CRM, uma da agenda) com o mesmo celular nao podem virar dois envios
   cobrados. A PRIMEIRA vence, que e a ordem em que a consulta entrega. */
$leads = [
  ['id'=>'A', 'cliente'=>true, 'revisado'=>true, 'opt_out_at'=>null, 'telefone'=>'+5548999990001', 'wa_id'=>null, 'nome'=>'Um'],
  ['id'=>'B', 'cliente'=>true, 'revisado'=>true, 'opt_out_at'=>null, 'telefone'=>'48999990001',   'wa_id'=>null, 'nome'=>'Um de novo'],
  ['id'=>'C', 'cliente'=>true, 'revisado'=>true, 'opt_out_at'=>null, 'telefone'=>'+5548999990002', 'wa_id'=>null, 'nome'=>'Dois'],
  ['id'=>'D', 'cliente'=>false,'revisado'=>true, 'opt_out_at'=>null, 'telefone'=>'+5548999990003', 'wa_id'=>null, 'nome'=>'Fora'],
];
$r = wa_camp_publico($leads);
ok(count($r['publico']) === 2, 'duas pessoas distintas no publico (deu: ' . count($r['publico']) . ')');
ok($r['publico'][0]['lead_id'] === 'A', 'a primeira ficha vence a duplicata');
ok($r['publico'][0]['wa_id']   === '+5548999990001', 'o publico carrega o E.164 ja normalizado');
ok($r['resumo']['duplicados']  === 1, 'a duplicata e contada');
ok($r['resumo']['nao_cliente'] === 1, 'quem ficou de fora e contado por motivo');
ok($r['resumo']['total']       === 2, 'o total do resumo e o tamanho do publico');

/* ---------- custo ----------
   Spec 8.2: a tela mostra quantas pessoas e quanto custa ANTES do envio.
   Em centavos inteiros: float de dinheiro acumula erro e o numero que a
   cliente le tem que bater com a fatura da Meta. */
ok(wa_camp_custo(0, 31)   === 0,    'publico vazio custa zero');
ok(wa_camp_custo(100, 31) === 3100, '100 destinatarios a 31 centavos = R$ 31,00');
ok(WA_CAMP_PRECO_CENTAVOS === 31,   'o preco padrao e 31 centavos');

/* ---------- estrangeiro discado com 00 ----------
   Limite conhecido e permanente de wa_e164: "0045 3314-1414" e Copenhague E
   e fixo de Cascavel na mesma string, digito por digito. Espanha (+34),
   Belgica (+32), Dinamarca (+45), Noruega (+47) e Tailandia (+66) tem DDI de
   dois digitos que tambem e DDD valido. Cuba, Espanha, Peru e Coreia estao no
   catalogo de roteiros da casa, entao hotel ou receptivo salvo na agenda a
   partir de uma ligacao e cenario real. Nao da para separar pelo numero
   normalizado: so o CRU, guardado em payload_import, ainda tem o "00". */
ok(wa_camp_ddi_suspeito(['telefone' => '0045 3314 1414']) === '45',
   'numero salvo com 00 + DDI estrangeiro e marcado');
ok(wa_camp_ddi_suspeito(['fone' => '004733112233']) === '47',
   'a varredura acha o telefone em qualquer chave do payload');
ok(wa_camp_ddi_suspeito(['telefone' => '+55 48 99999-0001']) === null,
   'numero brasileiro normal nao e marcado');
ok(wa_camp_ddi_suspeito(['telefone' => '0055 48 99999-0001']) === null,
   '00 seguido do DDI do Brasil nao e suspeito');
ok(wa_camp_ddi_suspeito(['telefone' => '0012125551234']) === null,
   '00 + DDI que NAO vira numero brasileiro valido nao e marcado');
ok(wa_camp_ddi_suspeito(null) === null, 'payload nulo nao quebra');

echo "test-wa-camp OK\n";
```

- [ ] **Step 2: Rodar para ver falhar**

Run: `php tests/test-wa-camp.php`
Expected: FAIL — `lib/wa-camp.php` não existe.

- [ ] **Step 3: Implementar**

Criar `lib/wa-camp.php`:

```php
<?php
/* ============================================================
   Transmissao: as regras puras de quem entra, quanto custa e o que
   merece conferencia antes do primeiro disparo pago.

   Nada aqui toca banco nem rede, de proposito: e o que permite testar a
   regra de negocio inteira sem simulador. Quem toca banco e
   lib/wa-camp-fila.php.
============================================================ */

require_once __DIR__ . '/wa-fone.php';

/* ~R$ 0,31 por destinatario (template de marketing, Brasil). E o UNICO custo
   relevante do sistema: mensagem recebida e mensagem que a cliente digita no
   app sao sempre gratis. O preco e congelado na campanha na hora da criacao,
   porque o da Meta muda e o relatorio antigo tem que continuar batendo. */
const WA_CAMP_PRECO_CENTAVOS = 31;

/* O numero com que a pessoa e alcancada. wa_id vence telefone: e o numero
   com que o WhatsApp JA falou, entao e o que sabidamente existe la. */
function wa_camp_telefone($lead) {
    $wa = wa_e164($lead['wa_id'] ?? '');
    if ($wa !== null) return $wa;
    return wa_e164($lead['telefone'] ?? '');
}

/* Devolve null quando a pessoa entra, ou o MOTIVO de ficar de fora.
   A ordem das checagens e o que define em qual balde a pessoa aparece no
   resumo, e o balde e o que a cliente le para conferir as defesas:

   1) opt_out primeiro, sempre. Defesa 3 da spec 8.1 diz "sem excecao", e
      quem saiu tem que aparecer como 'saiu' mesmo que tambem nao seja
      cliente - senao a conferencia da defesa nao tem o que olhar.
   2) revisado/cliente antes de telefone, porque e a defesa 1 e e o numero
      que decide se a fila de revisao ja foi trabalhada.
   3) telefone por ultimo. */
function wa_camp_motivo_fora($lead) {
    if (!empty($lead['opt_out_at']))            return 'saiu';
    if (($lead['cliente']  ?? null) !== true)   return 'nao_cliente';
    if (($lead['revisado'] ?? null) !== true)   return 'nao_revisado';
    $f = wa_camp_telefone($lead);
    if ($f === null)                            return 'sem_telefone';
    // Fixo entra na ficha mas NAO no disparo (spec 8): template para fixo e
    // cobrado e nao entrega.
    if (!wa_e_celular($f))                      return 'sem_celular';
    return null;
}

/* Monta o publico e o resumo por motivo. A deduplicacao e por E.164, e a
   PRIMEIRA ficha vence: regra 3 do Armando, nunca duplicar. Duas fichas da
   mesma pessoa (uma do CRM, uma da agenda) com o mesmo celular sao um
   destinatario so, cobrado uma vez so. */
function wa_camp_publico($leads) {
    $publico = [];
    $vistos  = [];
    $resumo  = ['total' => 0, 'duplicados' => 0, 'saiu' => 0, 'nao_cliente' => 0,
                'nao_revisado' => 0, 'sem_telefone' => 0, 'sem_celular' => 0];

    foreach ($leads as $l) {
        $motivo = wa_camp_motivo_fora($l);
        if ($motivo !== null) { $resumo[$motivo]++; continue; }
        $fone = wa_camp_telefone($l);
        if (isset($vistos[$fone])) { $resumo['duplicados']++; continue; }
        $vistos[$fone] = true;
        $publico[] = [
            'lead_id' => $l['id']   ?? null,
            'wa_id'   => $fone,
            'nome'    => $l['nome'] ?? '',
        ];
    }
    $resumo['total'] = count($publico);
    return ['publico' => $publico, 'resumo' => $resumo];
}

/* Centavos inteiros, nunca float: o numero que a cliente le antes de
   confirmar tem que bater com a fatura da Meta (spec 8.2). */
function wa_camp_custo($n, $preco_centavos = WA_CAMP_PRECO_CENTAVOS) {
    return (int) $n * (int) $preco_centavos;
}

/* Estrangeiro discado com 00, o limite conhecido e permanente de wa_e164.
   "0045 3314-1414" e Copenhague E e fixo de Cascavel na mesma string, digito
   por digito - nao ha informacao no numero que separe os dois. So o valor
   CRU, guardado em payload_import na importacao, ainda tem o "00" na frente.

   Devolve o DDI de dois digitos suspeito, ou null. Varre o payload inteiro
   em vez de olhar uma chave fixa porque o formato varia por origem (vCard da
   agenda, CSV do CRM) e uma chave errada aqui devolveria "nada suspeito"
   silenciosamente, que e o pior resultado possivel para uma conferencia. */
function wa_camp_ddi_suspeito($payload_import) {
    if (is_string($payload_import)) $payload_import = json_decode($payload_import, true);
    if (!is_array($payload_import)) return null;

    $textos = [];
    $achata = function ($v) use (&$achata, &$textos) {
        if (is_array($v))       { foreach ($v as $x) $achata($x); return; }
        if (is_string($v))      { $textos[] = $v; }
        elseif (is_numeric($v)) { $textos[] = (string) $v; }
    };
    $achata($payload_import);

    foreach ($textos as $t) {
        $d = preg_replace('/\D+/', '', $t);
        if (strlen($d) < 12)               continue;   // o corte do 00 exige 12+
        if (substr($d, 0, 2) !== '00')     continue;
        if (substr($d, 2, 2) === '55')     continue;   // 00 + Brasil nao e suspeito
        // So e suspeito se wa_e164 REALMENTE o aceita: um 00+DDI que morre na
        // normalizacao nunca chegou a virar destinatario, entao nao ha o que
        // conferir. Marcar esses so encheria a tela de ruido.
        if (wa_e164($t) === null)          continue;
        return substr($d, 2, 2);
    }
    return null;
}
```

- [ ] **Step 4: Rodar o teste**

Run: `php tests/test-wa-camp.php`
Expected: PASS com `test-wa-camp OK`

- [ ] **Step 5: Verificar por mutação**

Aplicar, uma de cada vez, e confirmar que **cada uma deixa a suíte vermelha**:

1. Mover a checagem de `opt_out_at` para depois de `cliente` em `wa_camp_motivo_fora` → o teste "opt_out e checado ANTES de cliente" fica vermelho.
2. Trocar `if (isset($vistos[$fone]))` por `if (false)` → "duas pessoas distintas no publico" fica vermelho.
3. Tirar a guarda `if (wa_e164($t) === null) continue;` → o teste `0012125551234` fica vermelho.
4. Trocar `!wa_e_celular($f)` por `false` → "fixo fica de fora do disparo" fica vermelho.

Desfazer todas as quatro.

Run: `php tests/test-wa-camp.php`
Expected: vermelho em cada mutação, verde depois de desfazer todas.

- [ ] **Step 6: Commit**

```bash
git add lib/wa-camp.php tests/test-wa-camp.php
git commit -m "feat(campanha): publico, custo e conferencia de numero estrangeiro"
```

---

### Task 4: A escada de lotes (defesa 2 da spec 8.1)

**Files:**
- Modify: `lib/wa-camp.php` (acrescentar ao fim)
- Test: `tests/test-wa-camp.php` (acrescentar antes do `echo`)

**Interfaces:**
- Consumes: nada de outras tasks.
- Produces:
  - `WA_CAMP_ESCADA` (array de int)
  - `WA_CAMP_TETO_DIARIO` (int, 1000)
  - `wa_camp_degrau($concluidas, $falhas_ultima, $total_ultima)` → int
  - `wa_camp_lote_permitido($degrau, $enviados_hoje, $teto = WA_CAMP_TETO_DIARIO)` → int

- [ ] **Step 1: Escrever o teste que falha**

Acrescentar em `tests/test-wa-camp.php`, antes de `echo "test-wa-camp OK\n";`:

```php
/* ---------- escada de lotes ----------
   Defesa 2 da spec 8.1: "o primeiro envio vai para um grupo pequeno; o
   tamanho sobe conforme o numero mantem boa qualidade". Numero novo que
   dispara 800 templates de uma vez e numero que a Meta rebaixa ou bloqueia,
   e a cliente perde o WhatsApp que usa para trabalhar todo dia. */
ok(wa_camp_degrau(0, 0, 0)   === 50,   'a primeira campanha da casa vai para 50');
ok(wa_camp_degrau(1, 0, 50)  === 150,  'sem falha, a segunda sobe para 150');
ok(wa_camp_degrau(2, 0, 150) === 400,  'a terceira sobe para 400');
ok(wa_camp_degrau(9, 0, 999) === 2000, 'a escada para no topo e nao passa dele');

/* Qualidade ruim nao sobe degrau: DESCE um. 5% de falha em template e sinal
   de lista velha, e insistir e o caminho para o numero ser rebaixado. */
ok(wa_camp_degrau(2, 20, 150) === 150,
   'com mais de 5% de falha a campanha seguinte desce um degrau');
ok(wa_camp_degrau(1, 20, 50)  === 50,
   'do primeiro degrau nao se desce mais');
ok(wa_camp_degrau(2, 7, 150)  === 400,
   'exatamente 4,6% de falha ainda sobe: o corte e ACIMA de 5%');

/* Divisao por zero: campanha anterior sem nenhum envio. */
ok(wa_camp_degrau(3, 0, 0) === 1000, 'campanha anterior vazia nao derruba o degrau');

/* ---------- teto diario ----------
   O teto da Meta e por dia e por numero (250 antes da verificacao da empresa,
   2.000 depois - a empresa foi verificada em 11/09/2026). Estourar devolve
   erro por destinatario, e cada erro ja e uma mensagem perdida. */
ok(wa_camp_lote_permitido(400, 0, 1000)   === 400, 'com o dia livre, o lote e o degrau inteiro');
ok(wa_camp_lote_permitido(400, 800, 1000) === 200, 'perto do teto, o lote encolhe');
ok(wa_camp_lote_permitido(400, 1000, 1000) === 0,  'no teto, nao sai nada');
ok(wa_camp_lote_permitido(400, 1200, 1000) === 0,  'acima do teto nunca devolve negativo');
ok(WA_CAMP_TETO_DIARIO === 1000, 'o teto proprio padrao e 1000 por dia');
```

- [ ] **Step 2: Rodar para ver falhar**

Run: `php tests/test-wa-camp.php`
Expected: FAIL com `Call to undefined function wa_camp_degrau()`

- [ ] **Step 3: Implementar**

Acrescentar ao fim de `lib/wa-camp.php`:

```php
/* Defesa 2 da spec 8.1: lotes crescentes. Numero novo que dispara centenas de
   templates de uma vez e numero que a Meta rebaixa ou bloqueia - e o numero
   dela e o que a agencia usa para trabalhar todo dia, entao o custo de errar
   aqui nao e a campanha, e o telefone da empresa. */
const WA_CAMP_ESCADA = [50, 150, 400, 1000, 2000];

/* Teto NOSSO, por dia. O teto real e da Meta (250/dia antes da verificacao da
   empresa, 2.000 depois; a empresa foi verificada em 11/09/2026), mas ele nao
   e legivel pela API de forma confiavel, entao mantemos um cinto proprio
   abaixo dele. */
const WA_CAMP_TETO_DIARIO = 1000;

/* O degrau da PROXIMA campanha. Sobe com o numero de campanhas concluidas e
   desce um quando a ultima passou de 5% de falha. */
function wa_camp_degrau($concluidas, $falhas_ultima, $total_ultima) {
    $i = (int) $concluidas;
    if ($i < 0) $i = 0;
    if ($i > count(WA_CAMP_ESCADA) - 1) $i = count(WA_CAMP_ESCADA) - 1;

    // Guarda de divisao por zero: campanha anterior sem nenhum envio nao diz
    // nada sobre qualidade, entao nao derruba o degrau.
    if ($i > 0 && (int) $total_ultima > 0
        && ((int) $falhas_ultima / (int) $total_ultima) > 0.05) {
        $i = $i - 1;
    }
    return WA_CAMP_ESCADA[$i];
}

/* Quantos podem sair AGORA: o degrau, limitado pelo que sobra do dia.
   Nunca negativo - um lote negativo viraria array_slice ao contrario e
   mandaria para o fim da fila. */
function wa_camp_lote_permitido($degrau, $enviados_hoje, $teto = WA_CAMP_TETO_DIARIO) {
    $resta = (int) $teto - (int) $enviados_hoje;
    if ($resta < 0) $resta = 0;
    return min((int) $degrau, $resta);
}
```

- [ ] **Step 4: Rodar o teste**

Run: `php tests/test-wa-camp.php`
Expected: PASS com `test-wa-camp OK`

- [ ] **Step 5: Verificar por mutação**

1. Trocar `> 0.05` por `> 0.5` → "com mais de 5% de falha a campanha seguinte desce um degrau" fica vermelho.
2. Tirar `if ($resta < 0) $resta = 0;` → "acima do teto nunca devolve negativo" fica vermelho.
3. Tirar a guarda `(int) $total_ultima > 0` → o PHP emite `DivisionByZeroError` no teste "campanha anterior vazia".

Desfazer as três.

Run: `php tests/test-wa-camp.php`
Expected: vermelho em cada mutação, verde depois de desfazer.

- [ ] **Step 6: Commit**

```bash
git add lib/wa-camp.php tests/test-wa-camp.php
git commit -m "feat(campanha): escada de lotes crescentes e teto diario"
```

---

### Task 5: Reserva e drenagem da fila

**Files:**
- Create: `lib/wa-camp-fila.php`
- Test: `tests/test-wa-camp-fila.php`

**Interfaces:**
- Consumes: `wa_db_insert($tabela, $linha, $ignora_conflito)`, `wa_db_select_estrito($tabela, $query)`, `wa_db_update($tabela, $query, $campos)` de `lib/wa-db.php`; `wa_camp_lote_permitido()` da Task 4.
- Produces:
  - `wa_camp_set_enviador($f)` — injeta o enviador nos testes
  - `wa_camp_reserva($campanha_id, $publico)` → `['reservados'=>int, 'ja_existiam'=>int]`
  - `wa_camp_drena($campanha_id, $limite)` → `['enviados'=>int, 'falhas'=>int, 'restam'=>int]`

- [ ] **Step 1: Escrever o teste que falha**

Criar `tests/test-wa-camp-fila.php`:

```php
<?php
require __DIR__ . '/../lib/wa-camp-fila.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

/* Simulador de banco em memoria. Nenhum teste toca a rede. O que ele precisa
   imitar de verdade e o INDICE UNICO (campanha_id, lead_id): e ele o cadeado
   da reserva, e um simulador sem cadeado deixaria passar o duplo envio que
   este teste existe para impedir. */
$DB = ['po_wa_envios' => [], 'po_wa_campanhas' => []];

wa_db_set_transport(function ($metodo, $url, $corpo) use (&$DB) {
    $tabela = preg_match('#/rest/v1/([a-z_]+)#', $url, $m) ? $m[1] : '';
    $linha  = $corpo ? json_decode($corpo, true) : null;

    if ($metodo === 'POST') {
        foreach ($DB[$tabela] as $e) {
            if ($e['campanha_id'] === $linha['campanha_id'] && $e['lead_id'] === $linha['lead_id']) {
                return ['status' => 409, 'body' => '{}'];        // unique_violation
            }
        }
        $linha['id'] = 'E' . (count($DB[$tabela]) + 1);
        $DB[$tabela][] = $linha;
        return ['status' => 201, 'body' => json_encode([$linha])];
    }
    if ($metodo === 'GET') {
        $out = $DB[$tabela];
        if (preg_match('/status=eq\.([a-z]+)/', $url, $m)) {
            $out = array_values(array_filter($out, fn($e) => $e['status'] === $m[1]));
        }
        if (preg_match('/limit=(\d+)/', $url, $m)) {
            $out = array_slice($out, 0, (int) $m[1]);
        }
        return ['status' => 200, 'body' => json_encode($out)];
    }
    if ($metodo === 'PATCH') {
        preg_match('/id=eq\.([A-Za-z0-9]+)/', $url, $m);
        foreach ($DB[$tabela] as &$e) {
            if ($e['id'] === ($m[1] ?? '')) $e = array_merge($e, $linha);
        }
        return ['status' => 200, 'body' => '[]'];
    }
    return ['status' => 500, 'body' => '{}'];
});

$publico = [
    ['lead_id'=>'L1', 'wa_id'=>'+5548999990001', 'nome'=>'Um'],
    ['lead_id'=>'L2', 'wa_id'=>'+5548999990002', 'nome'=>'Dois'],
    ['lead_id'=>'L3', 'wa_id'=>'+5548999990003', 'nome'=>'Tres'],
];

/* ---------- reserva ----------
   A linha e RESERVADA antes do envio, e o unique e o cadeado. Registrar so
   depois do envio deixaria passar o retry disparado enquanto a primeira
   requisicao ainda roda - que e exatamente o que um timeout produz. Mesma
   licao do lote de importacao do plano 3.1. */
$r = wa_camp_reserva('C1', $publico);
ok($r['reservados'] === 3,  'reserva as tres linhas (deu: ' . $r['reservados'] . ')');
ok($r['ja_existiam'] === 0, 'nada existia antes');
ok(count($DB['po_wa_envios']) === 3, 'tres linhas no banco');
ok($DB['po_wa_envios'][0]['status'] === 'reservado', 'a linha nasce reservada, sem wamid');
ok($DB['po_wa_envios'][0]['nome'] === 'Um',
   'o nome e fotografado na reserva: e o parametro {{1}} do template');

// Reaplicar a MESMA campanha nao pode criar linha nova: e o segundo clique
// no botao, ou o cron entrando junto com o painel.
$r = wa_camp_reserva('C1', $publico);
ok($r['reservados'] === 0,  'a segunda reserva nao cria nada');
ok($r['ja_existiam'] === 3, 'e reporta que as tres ja existiam');
ok(count($DB['po_wa_envios']) === 3, 'o banco continua com tres linhas');

/* ---------- drenagem ----------
   So sai quem esta 'reservado'. O envio e injetado: nenhum teste toca a rede. */
$mandados = [];
wa_camp_set_enviador(function ($wa_id, $nome) use (&$mandados) {
    $mandados[] = $wa_id;
    return ['ok' => true, 'wamid' => 'wamid.' . count($mandados), 'erro' => null];
});

$d = wa_camp_drena('C1', 2);
ok($d['enviados'] === 2, 'o limite do lote e respeitado (deu: ' . $d['enviados'] . ')');
ok($d['restam']   === 1, 'sobra um na fila');
ok(count($mandados) === 2, 'so dois envios aconteceram');
ok($DB['po_wa_envios'][0]['status'] === 'enviado', 'a linha vira enviado');
ok($DB['po_wa_envios'][0]['wamid']  === 'wamid.1', 'o wamid e gravado, e por onde o recibo volta');
ok($DB['po_wa_envios'][2]['status'] === 'reservado', 'o terceiro continua reservado');

$d = wa_camp_drena('C1', 10);
ok($d['enviados'] === 1, 'a segunda drenagem manda so o que restava');
ok($d['restam']   === 0, 'a fila esvazia');

// Drenar de novo nao pode reenviar nada: e o cron rodando de hora em hora.
$antes = count($mandados);
$d = wa_camp_drena('C1', 10);
ok($d['enviados'] === 0,        'fila vazia nao envia nada');
ok(count($mandados) === $antes, 'e nao chama o enviador de novo');

/* ---------- falha de envio ----------
   Envio que falhou NAO pode virar 'enviado': o relatorio de entrega mentiria
   e a pessoa nunca receberia nada. Vira 'falha', com o motivo. */
$DB['po_wa_envios'] = [];
wa_camp_reserva('C2', [['lead_id'=>'L9', 'wa_id'=>'+5548999990009', 'nome'=>'Nove']]);
wa_camp_set_enviador(function ($wa_id, $nome) {
    return ['ok' => false, 'wamid' => null, 'erro' => 'template nao aprovado'];
});
$d = wa_camp_drena('C2', 10);
ok($d['enviados'] === 0, 'envio que falhou nao conta como enviado');
ok($d['falhas']   === 1, 'a falha e contada');
ok($DB['po_wa_envios'][0]['status'] === 'falha', 'a linha vira falha');
ok($DB['po_wa_envios'][0]['wamid']  === null,    'falha nao inventa wamid');
ok($DB['po_wa_envios'][0]['erro']   === 'template nao aprovado', 'o motivo fica gravado');

echo "test-wa-camp-fila OK\n";
```

- [ ] **Step 2: Rodar para ver falhar**

Run: `php tests/test-wa-camp-fila.php`
Expected: FAIL — `lib/wa-camp-fila.php` não existe.

- [ ] **Step 3: Implementar**

Criar `lib/wa-camp-fila.php`:

```php
<?php
/* ============================================================
   Transmissao: a fila. Reserva os destinatarios e drena em lotes.

   A REGRA CENTRAL: a linha de po_wa_envios e RESERVADA antes do envio, e o
   indice unico (campanha_id, lead_id) e o cadeado. Registrar so depois do
   envio deixaria passar o retry disparado enquanto a primeira requisicao
   ainda roda - que e exatamente o que um timeout produz, e e a mesma licao
   que o lote de importacao do plano 3.1 aprendeu caro.

   O envio e injetavel (wa_camp_set_enviador) para que nenhum teste toque a
   rede, no mesmo padrao de wa_set_transport e wa_db_set_transport.
============================================================ */

require_once __DIR__ . '/wa-db.php';
require_once __DIR__ . '/wa-camp.php';
require_once __DIR__ . '/wa-send.php';

function wa_camp_set_enviador($f) { $GLOBALS['WA_CAMP_ENVIADOR'] = $f; }

/* O enviador padrao manda o template da campanha. Fora da janela de 24h nao
   existe outro caminho (ver Task 2). */
function wa_camp_envia($wa_id, $nome, $template) {
    if (isset($GLOBALS['WA_CAMP_ENVIADOR']) && $GLOBALS['WA_CAMP_ENVIADOR']) {
        return call_user_func($GLOBALS['WA_CAMP_ENVIADOR'], $wa_id, $nome);
    }
    return wa_send_template($wa_id, $template, [$nome]);
}

/* Reserva uma linha por destinatario. Conflito (409) significa que a pessoa
   JA esta nesta campanha: nao e erro, e o cadeado funcionando. */
function wa_camp_reserva($campanha_id, $publico) {
    $reservados = 0; $ja = 0;
    foreach ($publico as $p) {
        $linha = wa_db_insert('po_wa_envios', [
            'campanha_id' => $campanha_id,
            'lead_id'     => $p['lead_id'],
            'wa_id'       => $p['wa_id'],
            // Fotografado agora: e o nome que a cliente viu na previa, e e o
            // parametro {{1}} do template. Ler do lead na hora do dreno
            // mandaria um nome que ninguem revisou.
            'nome'        => $p['nome'] ?? '',
            'status'      => 'reservado',
        ], true);                          // true = 409 devolve null em vez de logar erro
        if ($linha === null) { $ja++; } else { $reservados++; }
    }
    return ['reservados' => $reservados, 'ja_existiam' => $ja];
}

/* Manda ate $limite reservados desta campanha.

   wa_db_select_estrito, e nao wa_db_select: com o Supabase fora do ar, o
   select frouxo devolve lista vazia e a drenagem reportaria "fila esvaziou"
   sem ter mandado nada - a campanha ficaria eternamente incompleta com a
   tela dizendo sucesso. Mesma licao do importador. */
function wa_camp_drena($campanha_id, $limite) {
    $limite = (int) $limite;
    if ($limite <= 0) return ['enviados' => 0, 'falhas' => 0, 'restam' => 0];

    $camp = wa_db_select_estrito('po_wa_campanhas', 'id=eq.' . rawurlencode($campanha_id));
    $template = ($camp && isset($camp[0]['template'])) ? $camp[0]['template'] : '';

    $fila = wa_db_select_estrito('po_wa_envios',
        'campanha_id=eq.' . rawurlencode($campanha_id) . '&status=eq.reservado&limit=' . $limite);
    if ($fila === null) {
        error_log('wa_camp_drena: leitura da fila falhou, nada enviado | campanha ' . $campanha_id);
        return ['enviados' => 0, 'falhas' => 0, 'restam' => -1];
    }

    $enviados = 0; $falhas = 0;
    foreach ($fila as $e) {
        $r = wa_camp_envia($e['wa_id'], $e['nome'] ?? '', $template);
        if (!empty($r['ok']) && !empty($r['wamid'])) {
            // Estado so avanca se o envio SAIU, e o wamid e o que prova isso:
            // e por ele que o recibo de entrega volta (Task 7).
            wa_db_update('po_wa_envios', 'id=eq.' . rawurlencode($e['id']), [
                'status'     => 'enviado',
                'wamid'      => $r['wamid'],
                'enviado_at' => gmdate('c'),
            ]);
            $enviados++;
        } else {
            // Falha NAO pode virar 'enviado': o relatorio mentiria e a pessoa
            // nunca receberia nada. O motivo fica gravado para a tela mostrar.
            wa_db_update('po_wa_envios', 'id=eq.' . rawurlencode($e['id']), [
                'status' => 'falha',
                'erro'   => substr((string) ($r['erro'] ?? 'erro desconhecido'), 0, 300),
            ]);
            $falhas++;
        }
    }

    $resto = wa_db_select_estrito('po_wa_envios',
        'campanha_id=eq.' . rawurlencode($campanha_id) . '&status=eq.reservado');
    return [
        'enviados' => $enviados,
        'falhas'   => $falhas,
        'restam'   => is_array($resto) ? count($resto) : -1,
    ];
}
```

- [ ] **Step 4: Rodar o teste**

Run: `php tests/test-wa-camp-fila.php`
Expected: PASS com `test-wa-camp-fila OK`

- [ ] **Step 5: Verificar por mutação**

1. Trocar `wa_db_insert(..., true)` por `wa_db_insert(..., false)` e fazer o simulador retornar `null` em 409 mesmo assim — a reserva ainda conta certo; **essa mutação não é coberta e está certo**, o `true` só evita log de erro. Registre isso no relatório.
2. Mover a gravação de `status=enviado` para **antes** da checagem de `$r['ok']` → "envio que falhou nao conta como enviado" fica vermelho.
3. Trocar `wa_db_select_estrito` por `wa_db_select` na leitura da fila → nenhum teste fica vermelho (o simulador nunca falha). **Isso é um buraco de teste: acrescente um caso** em que o transporte devolve `['status'=>500]` e a drenagem tem que devolver `restam === -1` e `enviados === 0`.
4. Trocar `$limite <= 0` por `$limite < 0` → acrescente um caso `wa_camp_drena('C1', 0)` que deve devolver `enviados === 0` sem chamar o enviador.

Implemente os casos que faltam (3 e 4) e confirme vermelho antes de verde.

- [ ] **Step 6: Commit**

```bash
git add lib/wa-camp-fila.php tests/test-wa-camp-fila.php
git commit -m "feat(campanha): fila com reserva antes do envio e drenagem em lotes"
```

---

### Task 6: SAIR, a baixa automática (defesa 3 da spec 8.1)

**Files:**
- Modify: `lib/wa-motor.php` (acrescentar a função perto de `wa_resposta_data`, e o desvio dentro de `wa_processar`)
- Test: `tests/test-wa-motor.php` (acrescentar antes do `echo`)

**Interfaces:**
- Consumes: `wa_lead_set($wa_id, $campos)`, `wa_conversa_set($wa_id, $campos)`, `wa_envia_texto($wa_id, $texto)` — já existem em `lib/wa-motor.php`.
- Produces: `wa_e_saida($texto)` → bool; `wa_processar()` passa a devolver `'opt_out'`.

- [ ] **Step 1: Escrever o teste que falha**

Acrescentar em `tests/test-wa-motor.php`, antes de `echo "test-wa-motor OK\n";`:

```php
/* ---------- SAIR ----------
   Defesa 3 da spec 8.1. O texto da transmissao promete "Responda SAIR para
   nao receber mais", e a promessa tem que valer.

   O casamento e ESTRITO: so a palavra sozinha. "cancelar" ficou de fora de
   proposito - numa agencia de viagem "quero cancelar" quase sempre e
   cancelar uma RESERVA, e tratar isso como descadastro tiraria da lista
   justamente quem esta em negociacao. */
ok(wa_e_saida('SAIR') === true,        'SAIR maiusculo sai');
ok(wa_e_saida('sair') === true,        'sair minusculo sai');
ok(wa_e_saida('  Sair. ') === true,    'com espaco e ponto ainda sai');
ok(wa_e_saida('parar') === true,       'parar tambem sai');
ok(wa_e_saida('descadastrar') === true,'descadastrar tambem sai');
ok(wa_e_saida('quero sair do grupo') === false,
   'a palavra no meio da frase NAO desinscreve: "sair do grupo" e outra coisa');
ok(wa_e_saida('quero cancelar minha reserva') === false,
   'cancelar reserva nunca e descadastro');
ok(wa_e_saida('') === false,           'texto vazio nao desinscreve');

$DB['po_wa_conversas'] = []; $DB['po_leads'] = []; $DB['po_wa_mensagens'] = []; $ENVIADAS = [];
$acao = wa_processar([
    'tipo' => 'mensagem', 'wa_id' => $WA, 'wamid' => 'wamid.SAIR1',
    'tipo_msg' => 'text', 'texto' => 'SAIR', 'nome' => 'Marlene',
    'ad_id' => null, 'ctwa_clid' => null, 'ts' => time(),
]);
ok($acao === 'opt_out', "SAIR devolve 'opt_out' (deu: $acao)");
ok(!empty($DB['po_leads'][0]['opt_out_at']), 'opt_out_at e carimbado no lead');
ok(count($ENVIADAS) === 1, 'uma confirmacao e enviada');
ok(stripos($ENVIADAS[0]['texto'], 'não') !== false
   || stripos($ENVIADAS[0]['texto'], 'nao') !== false,
   'a confirmacao diz que a pessoa nao recebera mais');

/* SAIR tem que valer mesmo com o robo JA silenciado naquele contato: o
   silencio existe para o robo nao falar por cima da humana, nao para a
   pessoa perder o direito de sair da lista. O carimbo acontece sempre; a
   RESPOSTA e que nao sai, para nao atropelar a conversa humana. */
$DB['po_wa_conversas'] = []; $DB['po_leads'] = []; $ENVIADAS = [];
wa_conversa_set($WA, ['estado' => 'humano', 'silenciado_at' => gmdate('c')]);
$acao = wa_processar([
    'tipo' => 'mensagem', 'wa_id' => $WA, 'wamid' => 'wamid.SAIR2',
    'tipo_msg' => 'text', 'texto' => 'sair', 'nome' => 'Marlene',
    'ad_id' => null, 'ctwa_clid' => null, 'ts' => time(),
]);
ok($acao === 'opt_out', 'SAIR vale mesmo com o robo silenciado');
ok(!empty($DB['po_leads'][0]['opt_out_at']), 'o carimbo acontece mesmo silenciado');
ok(count($ENVIADAS) === 0, 'mas o robo NAO responde por cima da humana');
```

- [ ] **Step 2: Rodar para ver falhar**

Run: `php tests/test-wa-motor.php`
Expected: FAIL com `Call to undefined function wa_e_saida()`

- [ ] **Step 3: Implementar**

Acrescentar em `lib/wa-motor.php`, logo depois de `wa_resposta_grupo()`:

```php
/* Defesa 3 da spec 8.1: "Responda SAIR para nao receber mais".

   O casamento e ESTRITO - a palavra sozinha, com pontuacao e espaco ao
   redor. Duas razoes:

   1) "quero sair do grupo" e uma pergunta sobre a viagem, nao um descadastro.
   2) "cancelar" ficou FORA da lista de proposito: numa agencia de viagem
      "quero cancelar" quase sempre e cancelar uma reserva, e trata-lo como
      descadastro tiraria da lista justamente quem esta em negociacao. */
function wa_e_saida($texto) {
    return (bool) preg_match('/^\s*(sair|parar|descadastrar)[\s.!]*$/iu', (string) $texto);
}
```

Dentro de `wa_processar()`, **logo depois** do bloco que trata `tipo === 'status'` e `tipo === 'eco'`, e **antes** da checagem de silêncio, acrescentar:

```php
    /* SAIR vem antes de tudo, inclusive do silencio. O silencio existe para
       o robo nao falar por cima da humana; nao existe para a pessoa perder o
       direito de sair da lista. Por isso o CARIMBO acontece sempre e so a
       RESPOSTA respeita o silencio. */
    if ($ev['tipo'] === 'mensagem' && wa_e_saida($ev['texto'] ?? '')) {
        wa_lead_set($wa_id, ['opt_out_at' => gmdate('c')]);
        $conv = wa_conversa($wa_id);
        if (empty($conv['silenciado_at'])) {
            wa_envia_texto($wa_id,
                'Tudo bem, você não vai mais receber nossas mensagens sobre viagens. ' .
                'Se um dia quiser voltar, é só escrever aqui.');
            wa_conversa_set($wa_id, ['estado' => 'encerrado']);
        }
        return 'opt_out';
    }
```

- [ ] **Step 4: Rodar o teste**

Run: `php tests/test-wa-motor.php`
Expected: PASS com `test-wa-motor OK`

- [ ] **Step 5: Verificar por mutação**

1. Trocar o regex por `/sair/iu` (sem âncoras) → "a palavra no meio da frase NAO desinscreve" fica vermelho.
2. Acrescentar `cancelar` à lista → "cancelar reserva nunca e descadastro" fica vermelho.
3. Mover o bloco do SAIR para **depois** da checagem de silêncio → "o carimbo acontece mesmo silenciado" fica vermelho.
4. Tirar o `if (empty($conv['silenciado_at']))` → "o robo NAO responde por cima da humana" fica vermelho.

Desfazer as quatro.

- [ ] **Step 6: Commit**

```bash
git add lib/wa-motor.php tests/test-wa-motor.php
git commit -m "feat(motor): SAIR desinscreve, e vale mesmo com o robo silenciado"
```

---

### Task 7: O recibo de entrega em `po_wa_envios`

**Files:**
- Create: `lib/wa-camp-recibo.php`
- Modify: `lib/wa-motor.php` (no ramo `tipo === 'status'`)
- Test: `tests/test-wa-camp-recibo.php`

**Interfaces:**
- Consumes: `wa_db_select_estrito()`, `wa_db_update()` de `lib/wa-db.php`.
- Produces: `wa_camp_recibo($wamid, $status)` → `?string` (o novo status gravado, ou null se o wamid não é de campanha).

- [ ] **Step 1: Escrever o teste que falha**

Criar `tests/test-wa-camp-recibo.php`:

```php
<?php
require __DIR__ . '/../lib/wa-camp-recibo.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

/* POR QUE ESTE ARQUIVO EXISTE, e a armadilha que ele guarda:

   O wamid de um evento `status` E o wamid da mensagem original. E
   po_wa_mensagens.wamid e UNIQUE. Passar status pela idempotencia de
   po_wa_mensagens faz recibo e eco competirem pela MESMA linha: o eco vira
   'duplicado', o wa_processar pula, e O ROBO NAO SE CALA - que e o pior modo
   de falha do sistema inteiro. Isso ja foi implementado por engano uma vez
   (faxina de 13/09) e revertido.

   A idempotencia de ENTREGA e esta: po_wa_envios, por wamid, movendo SO para
   frente. */
$DB = ['po_wa_envios' => [
    ['id'=>'E1', 'campanha_id'=>'C1', 'lead_id'=>'L1', 'wa_id'=>'+5548999990001',
     'wamid'=>'wamid.AAA', 'status'=>'enviado', 'entregue_at'=>null, 'lido_at'=>null],
]];

wa_db_set_transport(function ($metodo, $url, $corpo) use (&$DB) {
    if ($metodo === 'GET') {
        $out = $DB['po_wa_envios'];
        if (preg_match('/wamid=eq\.([A-Za-z0-9.]+)/', $url, $m)) {
            $out = array_values(array_filter($out, fn($e) => $e['wamid'] === $m[1]));
        }
        return ['status' => 200, 'body' => json_encode($out)];
    }
    if ($metodo === 'PATCH') {
        preg_match('/id=eq\.([A-Za-z0-9]+)/', $url, $m);
        $campos = json_decode($corpo, true);
        foreach ($DB['po_wa_envios'] as &$e) {
            if ($e['id'] === ($m[1] ?? '')) $e = array_merge($e, $campos);
        }
        return ['status' => 200, 'body' => '[]'];
    }
    return ['status' => 500, 'body' => '{}'];
});

ok(wa_camp_recibo('wamid.AAA', 'delivered') === 'entregue', 'delivered vira entregue');
ok($DB['po_wa_envios'][0]['status'] === 'entregue', 'a linha do envio e atualizada');
ok(!empty($DB['po_wa_envios'][0]['entregue_at']), 'a hora da entrega e carimbada');

ok(wa_camp_recibo('wamid.AAA', 'read') === 'lido', 'read vira lido');
ok($DB['po_wa_envios'][0]['status'] === 'lido', 'lido sobrepoe entregue');

/* SO PARA FRENTE. A Meta reentrega webhook, e fora de ordem: um `delivered`
   que chega depois de um `read` nao pode rebaixar o status, senao o relatorio
   de leitura encolhe sozinho e ninguem entende por que. */
ok(wa_camp_recibo('wamid.AAA', 'delivered') === 'lido',
   'delivered atrasado NAO rebaixa um envio ja lido');
ok($DB['po_wa_envios'][0]['status'] === 'lido', 'a linha continua lida');

/* Recibo repetido e o caso NORMAL, nao a excecao: a Meta reenvia o mesmo
   evento quando nao recebe 200 rapido. Tem que ser inofensivo. */
$antes = $DB['po_wa_envios'][0];
wa_camp_recibo('wamid.AAA', 'read');
ok($DB['po_wa_envios'][0]['lido_at'] === $antes['lido_at'],
   'recibo repetido nao mexe no carimbo que ja existia');

/* failed vira falha, e vale mesmo depois de enviado: a Meta pode recusar
   depois de aceitar (numero invalido, bloqueio). */
$DB['po_wa_envios'][0]['status'] = 'enviado';
ok(wa_camp_recibo('wamid.AAA', 'failed') === 'falha', 'failed vira falha');

/* wamid que nao e de campanha (o PDF do roteiro, a pergunta do motor) devolve
   null e nao escreve nada. E o caso mais comum de todos. */
ok(wa_camp_recibo('wamid.NAOEXISTE', 'delivered') === null,
   'wamid que nao e de campanha devolve null');
ok(wa_camp_recibo('', 'delivered') === null, 'wamid vazio devolve null');

/* Status que a Meta inventar amanha nao pode virar escrita silenciosa. */
ok(wa_camp_recibo('wamid.AAA', 'inventado') === null, 'status desconhecido nao grava nada');

echo "test-wa-camp-recibo OK\n";
```

- [ ] **Step 2: Rodar para ver falhar**

Run: `php tests/test-wa-camp-recibo.php`
Expected: FAIL — `lib/wa-camp-recibo.php` não existe.

- [ ] **Step 3: Implementar**

Criar `lib/wa-camp-recibo.php`:

```php
<?php
/* ============================================================
   Recibo de entrega de campanha.

   AQUI mora a idempotencia de ENTREGA, e NAO em po_wa_mensagens. O wamid de
   um evento `status` E o wamid da mensagem original, e po_wa_mensagens.wamid
   e unique: passar status por la faz recibo e eco competirem pela mesma
   linha, o eco vira 'duplicado', o wa_processar pula e O ROBO NAO SE CALA.
   Ja foi implementado por engano uma vez (faxina de 13/09) e revertido.
============================================================ */

require_once __DIR__ . '/wa-db.php';

/* A escada de status. So se anda PARA FRENTE: a Meta reentrega webhook fora
   de ordem, e um `delivered` que chega depois de um `read` nao pode rebaixar
   o envio - o relatorio de leitura encolheria sozinho. */
const WA_RECIBO_ORDEM = ['reservado' => 0, 'enviado' => 1, 'entregue' => 2, 'lido' => 3];

const WA_RECIBO_MAPA = [
    'delivered' => 'entregue',
    'read'      => 'lido',
    'failed'    => 'falha',
];

function wa_camp_recibo($wamid, $status_meta) {
    $wamid = (string) $wamid;
    if ($wamid === '') return null;

    // Status que a Meta inventar amanha nao pode virar escrita silenciosa.
    $novo = WA_RECIBO_MAPA[$status_meta] ?? null;
    if ($novo === null) return null;

    $linhas = wa_db_select_estrito('po_wa_envios', 'wamid=eq.' . rawurlencode($wamid));
    // null = erro de leitura; [] = nao e wamid de campanha (o caso mais comum
    // de todos: o PDF do roteiro e as perguntas do motor tambem geram status).
    if (!$linhas) return null;

    $e     = $linhas[0];
    $atual = $e['status'] ?? 'enviado';

    /* 'falha' e a excecao a escada: a Meta pode recusar DEPOIS de aceitar
       (numero invalido, bloqueio), e isso e informacao nova em qualquer
       ponto. Os demais so avancam. */
    if ($novo !== 'falha') {
        $de   = WA_RECIBO_ORDEM[$atual] ?? 0;
        $para = WA_RECIBO_ORDEM[$novo]  ?? 0;
        if ($para <= $de) return $atual;         // ja estava igual ou adiante
    }

    $campos = ['status' => $novo];
    // O carimbo so e escrito se ainda nao existe: recibo repetido e o caso
    // NORMAL (a Meta reenvia quando nao recebe 200 rapido) e nao pode
    // reescrever a hora real do evento.
    if ($novo === 'entregue' && empty($e['entregue_at'])) $campos['entregue_at'] = gmdate('c');
    if ($novo === 'lido'     && empty($e['lido_at']))     $campos['lido_at']     = gmdate('c');

    wa_db_update('po_wa_envios', 'id=eq.' . rawurlencode($e['id']), $campos);
    return $novo;
}
```

Em `lib/wa-motor.php`, no ramo que hoje devolve `'status'`, acrescentar a chamada **sem** mexer na idempotência:

```php
    if ($ev['tipo'] === 'status') {
        /* O recibo atualiza po_wa_envios, e SO ele. Nao passa por
           wa_registra_evento de proposito: o wamid de um status E o da
           mensagem original e po_wa_mensagens.wamid e unique, entao recibo e
           eco competiriam pela mesma linha e o eco viraria 'duplicado' -
           fazendo o robo nao se calar. */
        wa_camp_recibo($ev['wamid'] ?? '', $ev['texto'] ?? '');
        return 'status';
    }
```

E acrescentar no topo de `lib/wa-motor.php`: `require_once __DIR__ . '/wa-camp-recibo.php';`

- [ ] **Step 4: Rodar os testes**

Run: `php tests/test-wa-camp-recibo.php && php tests/test-wa-motor.php`
Expected: os dois PASS. O teste existente `evento de status nao cria nem altera conversa` continua verde.

- [ ] **Step 5: Verificar por mutação**

1. Trocar `if ($para <= $de) return $atual;` por `if (false) ...` → "delivered atrasado NAO rebaixa" fica vermelho.
2. Tirar o `empty($e['lido_at'])` do carimbo → "recibo repetido nao mexe no carimbo" fica vermelho.
3. Trocar `$novo === null` por `$novo = 'entregue'` no fallback → "status desconhecido nao grava nada" fica vermelho.
4. **A mutação mais importante:** fazer o ramo de status chamar `wa_registra_evento($ev)` antes do recibo → o teste de eco/silêncio de `tests/test-wa-webhook.php` deve ficar vermelho. Se ficar **verde**, pare e avise o controlador: significa que a proteção do Ruling 7 não está travada por teste.

Desfazer todas.

- [ ] **Step 6: Commit**

```bash
git add lib/wa-camp-recibo.php lib/wa-motor.php tests/test-wa-camp-recibo.php
git commit -m "feat(campanha): recibo de entrega em po_wa_envios, so para frente"
```

---

### Task 8: Endpoint `campanha.php` e a aba Transmissão

**Files:**
- Create: `campanha.php`
- Create: `painel/campanhas.js`
- Modify: `painel/index.html` (aba, `?v=`), `painel/assets-lock.json`, `painel/app.js` (roteamento da view), `wa-cron.php` (drenagem)
- Test: `tests/test-campanhas.mjs`

**Interfaces:**
- Consumes: tudo das Tasks 3, 4, 5.
- Produces: `poCampResumoTexto($resumo)`, `poCampCustoBRL($centavos)`, `poCampPodeEnviar($previa)` em `painel/campanhas.js`.

- [ ] **Step 1: Escrever o teste que falha**

Criar `tests/test-campanhas.mjs`:

```js
/* A tela da transmissao e o ultimo ponto antes de gastar dinheiro da cliente.
   Spec 8.2: "a tela mostra quantas pessoas e quanto vai custar antes do
   envio. Nada dispara sem esse aviso." */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const raiz = join(dirname(fileURLToPath(import.meta.url)), '..');
const src  = readFileSync(join(raiz, 'painel/campanhas.js'), 'utf8');
const pega = n => {
  const m = src.match(new RegExp(String.raw`function ${n}\([\s\S]*?\n\}`));
  if (!m) throw new Error('nao achei ' + n);
  return m[0];
};
const F = new Function(
  pega('poCampCustoBRL') + pega('poCampResumoTexto') + pega('poCampPodeEnviar') +
  pega('poCampValidaCorpo') +
  ';return {custo:poCampCustoBRL, resumo:poCampResumoTexto, pode:poCampPodeEnviar,' +
  ' corpo:poCampValidaCorpo};')();

// Centavos inteiros viram reais na tela, sem float.
assert.equal(F.custo(0),    'R$ 0,00');
assert.equal(F.custo(31),   'R$ 0,31');
assert.equal(F.custo(3100), 'R$ 31,00');
assert.equal(F.custo(24025),'R$ 240,25');

// O resumo nomeia CADA balde: e como a cliente confere as defesas.
const r = F.resumo({total:23, duplicados:2, saiu:1, nao_cliente:5,
                    nao_revisado:3, sem_telefone:615, sem_celular:138});
assert.ok(/23/.test(r),  'mostra quantos entram');
assert.ok(/615/.test(r), 'mostra quantos ficaram sem telefone');
assert.ok(/saiu|SAIR/i.test(r), 'nomeia o balde de quem pediu para sair');
assert.ok(/revis/i.test(r), 'nomeia o balde de quem falta revisar');

/* Nada dispara com publico vazio, nem sem o custo calculado. E a trava que
   impede o clique que gasta sem avisar. */
assert.equal(F.pode({total:0,  custo_centavos:0}),    false, 'publico vazio nao dispara');
assert.equal(F.pode({total:23, custo_centavos:713}),  true,  'publico com custo dispara');
assert.equal(F.pode({total:23, custo_centavos:null}), false, 'sem custo calculado nao dispara');
assert.equal(F.pode({total:23}),                      false, 'sem o campo de custo nao dispara');

/* O corpo da campanha TEM que trazer a saida: e a defesa 3 da spec 8.1, e a
   promessa que o motor cumpre na Task 6. Sem a frase, a pessoa nao sabe que
   pode sair e o descadastro vira denuncia na Meta. */
assert.equal(F.corpo('Novo roteiro para Portugal em maio.').includes('SAIR'), true,
  'texto sem a palavra SAIR e recusado, e o erro diz qual frase incluir');
assert.equal(
  F.corpo('Novo roteiro para Portugal em maio. Responda SAIR para não receber mais.'),
  null, 'texto com a saida e aceito');
assert.ok(F.corpo('').includes('Escreva'), 'texto vazio e recusado');
assert.ok(/1024/.test(F.corpo('x'.repeat(1025) + ' SAIR')),
  'acima de 1024 e recusado, porque a Meta recusa a mensagem inteira');
assert.ok(F.corpo('Vamos sair juntos nessa viagem!').includes('SAIR'),
  'a palavra minuscula no meio da frase NAO conta como aviso de saida');

// O painel e servido com cache de 1 mes: arquivo novo precisa de ?v=.
const painel = readFileSync(join(raiz, 'painel/index.html'), 'utf8');
assert.ok(/src="campanhas\.js\?v=\d+"/.test(painel), 'campanhas.js entra com cache-buster');
assert.ok(/data-view="campanhas"/.test(painel), 'a aba Transmissao existe no menu');

console.log('test-campanhas OK');
```

- [ ] **Step 2: Rodar para ver falhar**

Run: `node tests/test-campanhas.mjs`
Expected: FAIL com `ENOENT` — `painel/campanhas.js` não existe.

- [ ] **Step 3: Implementar `painel/campanhas.js`**

```js
/* ============================================================
   Aba Transmissao. O ultimo ponto antes de gastar dinheiro da cliente.

   Spec 8.2: a tela mostra QUANTAS PESSOAS e QUANTO CUSTA antes do envio, e
   nada dispara sem esse aviso. Custo invisivel em fatura de terceiro e como
   um projeto assim perde a confianca do cliente.
============================================================ */

/* Centavos inteiros viram reais. Sem float em dinheiro: o numero que ela le
   tem que bater com a fatura da Meta. */
function poCampCustoBRL(centavos) {
  const n = Math.round(Number(centavos) || 0);
  return 'R$ ' + String(Math.floor(n / 100)) + ',' + String(n % 100).padStart(2, '0');
}

/* Cada balde nomeado. E assim que ela confere as defesas: "615 sem telefone"
   explica por que a campanha e pequena, e "3 faltam revisar" e o empurrao
   para a aba Revisao. */
function poCampResumoTexto(r) {
  const p = [];
  p.push(r.total + ' pessoas recebem');
  if (r.duplicados)   p.push(r.duplicados + ' repetidas, contadas uma vez');
  if (r.saiu)         p.push(r.saiu + ' pediram SAIR e ficam de fora');
  if (r.nao_revisado) p.push(r.nao_revisado + ' ainda faltam revisar');
  if (r.nao_cliente)  p.push(r.nao_cliente + ' não são clientes');
  if (r.sem_celular)  p.push(r.sem_celular + ' têm só telefone fixo');
  if (r.sem_telefone) p.push(r.sem_telefone + ' estão sem telefone');
  return p.join(' · ');
}

/* A trava do clique que gasta. Publico vazio nunca dispara, e custo nao
   calculado tambem nao: um envio sem o aviso de custo quebra a spec 8.2. */
function poCampPodeEnviar(previa) {
  if (!previa) return false;
  if (!(Number(previa.total) > 0)) return false;
  if (previa.custo_centavos === null || previa.custo_centavos === undefined) return false;
  return true;
}
```

E, no mesmo arquivo, a validação do corpo e o render. `poCampValidaCorpo` é a defesa 3 da spec 8.1 no lado da tela:

```js
/* Defesa 3 da spec 8.1, cobrada ANTES de gastar. Sem a frase, a pessoa nao
   sabe que pode sair, e o descadastro vira denuncia na Meta - que e o que
   derruba a qualidade do numero que a agencia usa para trabalhar. */
function poCampValidaCorpo(corpo) {
  const t = (corpo == null ? '' : String(corpo)).trim();
  if (t === '') return 'Escreva o texto da mensagem.';
  if (t.length > 1024) {
    return 'O texto tem ' + t.length + ' caracteres e o limite do template é 1024. ' +
           'Acima disso a Meta recusa a mensagem inteira.';
  }
  if (!/\bSAIR\b/.test(t)) {
    return 'Inclua a frase "Responda SAIR para não receber mais" no texto. ' +
           'É o que permite a pessoa sair da lista.';
  }
  return null;
}

/* Monta a tela. Pede a previa, pinta resumo e custo, e so entao libera o
   botao. A ordem importa: o botao nasce desabilitado e so o retorno da previa
   o habilita, entao nao existe janela em que um clique dispare sem aviso. */
async function poRenderCampanhas() {
  const alvo = document.querySelector('#camp-resumo');
  const btn  = document.querySelector('#camp-enviar');
  btn.disabled = true;
  alvo.textContent = 'Calculando o público...';

  const fd = new FormData();
  fd.append('modo', 'previa');
  fd.append('sb_token', (await sb.auth.getSession()).data.session.access_token);

  let previa;
  try {
    const r = await fetch('campanha.php', { method: 'POST', body: fd });
    previa = await r.json();
    if (!previa.ok) throw new Error(previa.error || 'falha');
  } catch (e) {
    alvo.textContent = 'Não consegui calcular o público. Tente de novo.';
    return;
  }

  document.querySelector('#camp-custo').textContent =
    poCampCustoBRL(previa.custo_centavos);
  alvo.textContent = poCampResumoTexto(previa.resumo);

  if (previa.suspeitos > 0) {
    document.querySelector('#camp-alerta').textContent =
      previa.suspeitos + ' número(s) podem ser estrangeiros salvos com 00. ' +
      'Confira antes de enviar: cada um custa igual e não entrega.';
  }
  btn.disabled = !poCampPodeEnviar(previa);
}
```

O `<section>` da aba precisa conter `#camp-resumo`, `#camp-custo`, `#camp-alerta`, um `<textarea id="camp-corpo">`, um `<input id="camp-template">` e o `<button id="camp-enviar" disabled>`. O clique em `#camp-enviar` chama `poCampValidaCorpo` primeiro e só posta `modo=criar` se ela devolver `null`.

- [ ] **Step 4: Implementar `campanha.php`**

Criar `campanha.php` na raiz (o docroot do FTP é a raiz do repo):

```php
<?php
/* ============================================================
   Pereira Oliveira Turismo — Transmissao.

     modo=previa   -> monta o publico, devolve resumo + custo. NAO escreve.
     modo=criar    -> cria a campanha e RESERVA os destinatarios. NAO envia.
     modo=drenar   -> manda o proximo lote (o wa-cron chama o mesmo caminho).

   Criar e drenar sao separados de proposito: centenas de envios numa
   requisicao estouram o tempo do cPanel, e o retry do navegador cairia no
   meio da fila - com as linhas ja reservadas, o dreno seguinte continua de
   onde parou em vez de recomecar.

   A escrita usa a service_role, que so existe em config.local.php. O login do
   painel e validado antes de qualquer leitura ou escrita.
============================================================ */

require_once __DIR__ . '/lib/po-auth.php';
require_once __DIR__ . '/lib/wa-camp.php';
require_once __DIR__ . '/lib/wa-camp-fila.php';
require_once __DIR__ . '/lib/wa-db.php';

const CAMP_PAGINA = 1000;   // paginacao da leitura da base

header('Content-Type: application/json; charset=utf-8');

/* A mensagem de erro NUNCA carrega nome, telefone nem trecho de ficha: ela
   vaza para log, print e suporte. */
function cfail($code, $msg) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function cpost($k) {
    $v = $_POST[$k] ?? '';
    return is_string($v) ? trim($v) : '';
}

$cfg = wa_config();
if (!po_auth_ok($cfg['SUPABASE_URL'] ?? '', $cfg['SUPABASE_ANON_KEY'] ?? '')) {
    cfail(401, 'Sessão expirada. Entre de novo no painel.');
}

/* Le a base inteira, paginada. wa_db_select_estrito e nao wa_db_select: com o
   Supabase fora do ar o select frouxo devolve lista vazia, e a previa diria
   "0 pessoas" em vez de dizer que falhou - a cliente concluiria que a base
   sumiu. */
function camp_leads() {
    $todos = [];
    for ($de = 0; ; $de += CAMP_PAGINA) {
        $p = wa_db_select_estrito('po_leads',
            'select=id,nome,telefone,wa_id,cliente,revisado,opt_out_at,payload_import'
            . '&order=created_at.asc&offset=' . $de . '&limit=' . CAMP_PAGINA);
        if ($p === null) return null;
        $todos = array_merge($todos, $p);
        if (count($p) < CAMP_PAGINA) break;
    }
    return $todos;
}

$modo = cpost('modo') ?: 'previa';

/* ---------------------------------------------------------- previa */
if ($modo === 'previa' || $modo === 'criar') {
    $leads = camp_leads();
    if ($leads === null) cfail(502, 'Não consegui ler a base agora. Tente de novo.');

    $r     = wa_camp_publico($leads);
    $custo = wa_camp_custo($r['resumo']['total'], WA_CAMP_PRECO_CENTAVOS);

    // Conferencia do estrangeiro discado com 00, so entre QUEM VAI RECEBER.
    $porLead   = [];
    foreach ($leads as $l) $porLead[$l['id']] = $l;
    $suspeitos = 0;
    foreach ($r['publico'] as $p) {
        $l = $porLead[$p['lead_id']] ?? null;
        if ($l && wa_camp_ddi_suspeito($l['payload_import'] ?? null) !== null) $suspeitos++;
    }

    if ($modo === 'previa') {
        echo json_encode([
            'ok'             => true,
            'resumo'         => $r['resumo'],
            'custo_centavos' => $custo,
            'suspeitos'      => $suspeitos,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ------------------------------------------------------ criar */
    $nome     = cpost('nome');
    $template = cpost('template');
    $corpo    = cpost('corpo');

    if ($nome === '')     cfail(400, 'Dê um nome para esta campanha.');
    if ($template === '') cfail(400, 'Informe o nome do template aprovado na Meta.');

    // Defesa 3 da spec 8.1, cobrada tambem no servidor: a tela pode ser
    // contornada, o endpoint nao.
    if (!preg_match('/\bSAIR\b/', $corpo)) {
        cfail(400, 'O texto precisa conter a frase com a palavra SAIR, '
                 . 'que é como a pessoa sai da lista.');
    }
    if (mb_strlen($corpo) > 1024) {
        cfail(400, 'O texto passa de 1024 caracteres e a Meta recusaria a mensagem inteira.');
    }
    // Publico vazio nunca vira campanha: uma linha 'enviando' sem
    // destinatario ficaria eternamente no caminho do dreno do cron.
    if ($r['resumo']['total'] === 0) {
        cfail(400, 'Nenhuma pessoa entra nesta campanha. Revise os contatos antes.');
    }

    $camp = wa_db_insert('po_wa_campanhas', [
        'nome'                    => mb_substr($nome, 0, 120),
        'roteiro_slug'            => cpost('roteiro_slug') ?: null,
        'template'                => $template,
        'corpo'                   => $corpo,
        'segmento'                => ['origem' => 'painel'],
        'total'                   => $r['resumo']['total'],
        // Congelados AGORA: o preco da Meta muda, e o relatorio de uma
        // campanha antiga tem que continuar batendo com a fatura daquele mes.
        'preco_centavos'          => WA_CAMP_PRECO_CENTAVOS,
        'custo_estimado_centavos' => $custo,
        'status'                  => 'enviando',
    ]);
    if (!$camp) cfail(502, 'Não consegui criar a campanha. Nada foi enviado.');

    $res = wa_camp_reserva($camp['id'], $r['publico']);
    echo json_encode([
        'ok'             => true,
        'campanha_id'    => $camp['id'],
        'reservados'     => $res['reservados'],
        'custo_centavos' => $custo,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------------------------------------------------------- drenar */
if ($modo === 'drenar') {
    $id = cpost('campanha_id');
    if ($id === '') cfail(400, 'Campanha não informada.');
    $m    = wa_camp_metricas();
    $lote = wa_camp_lote_permitido(
        wa_camp_degrau($m['concluidas'], $m['falhas_ultima'], $m['total_ultima']),
        $m['enviados_hoje']
    );
    echo json_encode(['ok' => true] + wa_camp_drena($id, $lote), JSON_UNESCAPED_UNICODE);
    exit;
}

cfail(400, 'Modo desconhecido.');
```

- [ ] **Step 5: Implementar `wa_camp_metricas()` e testá-la**

Acrescentar ao fim de `lib/wa-camp-fila.php`:

```php
/* Os numeros que decidem o tamanho do proximo lote. Todos CONTADOS de
   po_wa_envios, nunca lidos de contador guardado: contador copiado desanda no
   primeiro webhook fora de ordem e o lote passaria a crescer sobre um numero
   que ninguem conferiu. */
function wa_camp_metricas() {
    $vazio = ['concluidas' => 0, 'falhas_ultima' => 0, 'total_ultima' => 0, 'enviados_hoje' => 0];

    $conc = wa_db_select_estrito('po_wa_campanhas',
        'select=id&status=eq.concluida&order=concluida_at.desc');
    if ($conc === null) return $vazio;      // sem leitura confiavel, comeca do degrau 0

    $falhas = 0; $total = 0;
    if ($conc) {
        $env = wa_db_select_estrito('po_wa_envios',
            'select=status&campanha_id=eq.' . rawurlencode($conc[0]['id']));
        foreach (($env ?: []) as $e) {
            $total++;
            if (($e['status'] ?? '') === 'falha') $falhas++;
        }
    }

    $hoje = wa_db_select_estrito('po_wa_envios',
        'select=id&enviado_at=gte.' . gmdate('Y-m-d') . 'T00:00:00Z');

    return [
        'concluidas'    => count($conc),
        'falhas_ultima' => $falhas,
        'total_ultima'  => $total,
        'enviados_hoje' => is_array($hoje) ? count($hoje) : 0,
    ];
}
```

Acrescentar em `tests/test-wa-camp-fila.php`, antes do `echo`:

```php
/* ---------- metricas que decidem o lote ---------- */
$DB['po_wa_campanhas'] = [
    ['id'=>'CA', 'status'=>'concluida', 'concluida_at'=>'2026-09-10T00:00:00Z'],
    ['id'=>'CB', 'status'=>'concluida', 'concluida_at'=>'2026-09-12T00:00:00Z'],
];
$DB['po_wa_envios'] = [
    ['id'=>'X1','campanha_id'=>'CB','lead_id'=>'L1','wa_id'=>'+5548999990001','nome'=>'a','status'=>'enviado','enviado_at'=>gmdate('Y-m-d').'T10:00:00Z'],
    ['id'=>'X2','campanha_id'=>'CB','lead_id'=>'L2','wa_id'=>'+5548999990002','nome'=>'b','status'=>'falha','enviado_at'=>null],
];
$m = wa_camp_metricas();
ok($m['concluidas'] === 2,    'conta as campanhas concluidas (deu: ' . $m['concluidas'] . ')');
ok($m['total_ultima'] === 2,  'conta os envios da ULTIMA campanha concluida');
ok($m['falhas_ultima'] === 1, 'conta as falhas da ultima');
ok($m['enviados_hoje'] === 1, 'conta so o que saiu hoje');
```

O simulador de banco do arquivo precisa ganhar os filtros `status=eq.`, `campanha_id=eq.` e `enviado_at=gte.` sobre `po_wa_campanhas` e `po_wa_envios`, e ordenar por `concluida_at` decrescente quando a query pedir. Sem o `order`, o teste `total_ultima` passaria por acaso.

- [ ] **Step 6: Ligar a aba, o cron e o cache-buster**

Em `painel/index.html`: botão `data-view="campanhas"` no menu, `<section class="view" data-view="campanhas" id="view-campanhas">`, `<script src="campanhas.js?v=1"></script>` antes do `index.html` final, e **subir o `?v=` do `app.js`** (que ganha o ramo da view).

Em `painel/app.js`, no roteador de views, acrescentar antes do `else` final:

```js
  else if(v==='campanhas'){$('#view-campanhas').classList.add('on');$('#top-title').innerHTML='Transmissão<span>.</span>';if(typeof poRenderCampanhas==='function')poRenderCampanhas();}
```

E incluir `'campanhas'` na lista que esconde o `#month-box`.

Em `painel/assets-lock.json`: entrada para `campanhas.js` e sha novo de `app.js` e `index.html`. Rodar `php tests/run.php` — `test-cache-buster.mjs` imprime o sha a colar.

Em `wa-cron.php`, depois de `wa_varre_timeouts()`:

```php
    /* Drena a campanha em andamento. Vem DEPOIS dos timeouts de proposito:
       lembrete e encerramento sao gratis, a transmissao custa - se algo
       estourar aqui, o que ja era gratuito ja aconteceu.

       Uma campanha por vez (limit=1): duas drenando juntas dividiriam o teto
       diario sem saber uma da outra e estourariam o limite da Meta. */
    $c = wa_db_select_estrito('po_wa_campanhas',
        'select=id&status=eq.enviando&order=created_at.asc&limit=1');
    if ($c) {
        $m    = wa_camp_metricas();
        $lote = wa_camp_lote_permitido(
            wa_camp_degrau($m['concluidas'], $m['falhas_ultima'], $m['total_ultima']),
            $m['enviados_hoje']
        );
        $d = wa_camp_drena($c[0]['id'], $lote);
        $r['campanha'] = $d;
        /* Fila vazia encerra a campanha. Sem isto ela fica 'enviando' para
           sempre e bloqueia a proxima, porque o dreno so pega uma por vez. */
        if ($d['restam'] === 0) {
            wa_db_update('po_wa_campanhas', 'id=eq.' . rawurlencode($c[0]['id']),
                ['status' => 'concluida', 'concluida_at' => gmdate('c')]);
        }
    }
```

E acrescentar no topo de `wa-cron.php`: `require_once __DIR__ . '/lib/wa-camp-fila.php';`

- [ ] **Step 7: Rodar tudo**

Run: `php tests/run.php`
Expected: `Todos os testes passaram`, com `test-campanhas` e `test-cache-buster` verdes.

- [ ] **Step 8: Commit**

```bash
git add campanha.php painel/campanhas.js painel/index.html painel/app.js painel/assets-lock.json wa-cron.php tests/test-campanhas.mjs
git commit -m "feat(painel): aba Transmissao com custo antes de confirmar"
```

---

## Pendências que este plano NÃO fecha

Registradas para o controlador, não para o implementador:

1. **Os templates da Meta.** O corpo aprovado vive lá, não aqui. A cliente precisa submeter pelo menos um template de marketing em `pt_BR` com um parâmetro (`{{1}}` = nome) e a frase do SAIR. Aprovação leva de minutos a 24h.
2. **`lib/wa-motor.php` com a correção da vírgula órfã ainda não subiu por FTP.**
3. **Conferir a lista importada antes do primeiro disparo pago** usando `wa_camp_ddi_suspeito` — só vale depois que as duas agendas entrarem.
4. **Hoje o público real é de 23 pessoas.** A transmissão só fica interessante depois das agendas.
5. **Conferência visual do painel com login real** segue pendente desde o plano 2.
