# Motor da conversa de WhatsApp — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** O robô recebe a mensagem do lead no WhatsApp, identifica o roteiro, envia o PDF com as duas perguntas de qualificação, entende a resposta e move o lead no funil, calando-se assim que a cliente assume a conversa.

**Architecture:** Webhook PHP no cPanel da ereHost recebe os eventos da Cloud API. Uma camada de transporte injetável (`wa_set_transport`) permite construir e testar tudo contra um simulador local, sem a conta da Meta pronta. O estado da conversa vive no Supabase; o funil reaproveita `po_leads`.

**Tech Stack:** PHP 8 sem framework nem Composer (a hospedagem não tem), Supabase REST com `service_role`, WhatsApp Cloud API (Graph v21). Testes com o runner próprio em `tests/run.php`.

**Spec:** `docs/superpowers/specs/2026-09-11-automacao-whatsapp-crm-design.md`

## Global Constraints

- **Sem Composer, sem build.** Nada de dependência externa. PHP puro, arquivos soltos que sobem por FTP.
- **Testes no padrão do projeto:** arquivos `tests/test-*.php`, asserção `ok($cond, $msg)` que faz `exit(1)`, rodados por `php tests/run.php`. Sem PHPUnit.
- **Rede nunca é tocada em teste.** Toda função que sai para fora recebe transporte injetável, no padrão do `po_set_fetcher` em `lib/po-data.php`.
- **Segredos só em `config.local.php`**, que está no `.gitignore`. Nunca no Git, nunca em teste.
- **Toda saída de dado de terceiro é escapada** com `po_e`/`po_css_url` (padrão do `roteiro.php`). Texto vindo do WhatsApp é dado, nunca instrução.
- **Copy em português, sem travessões e sem emojis** (regra Freela), inclusive nas mensagens do robô.
- **Telefone sempre em E.164** (`+55DDNNNNNNNNN`) em todo o banco. Nunca gravar formato livre.
- **Commits em português, sem acento na mensagem**, seguindo o histórico do repo.

---

### Task 1: Migrations do schema de WhatsApp

**Files:**
- Create: `supabase/migrations/2026-09-11-whatsapp-motor.sql`
- Test: `tests/test-wa-schema.php`

**Interfaces:**
- Consumes: nada.
- Produces: tabelas `po_wa_contatos`, `po_wa_conversas`, `po_wa_mensagens`, `po_wa_anuncios`, `po_wa_textos`; colunas novas em `po_leads` (`wa_id`, `ctwa_clid`, `ad_id`, `qualif_data`, `qualif_grupo`, `qualif_at`, `proposta_at`). Todas usadas da Task 4 em diante.

- [ ] **Step 1: Escrever a migration**

Criar `supabase/migrations/2026-09-11-whatsapp-motor.sql`:

```sql
-- ============================================================
-- Pereira Oliveira — motor de conversa do WhatsApp.
-- Rodar no Supabase → SQL Editor. Idempotente.
--
-- Telefone SEMPRE em E.164 (+55DDNNNNNNNNN). O formato livre que vem da
-- agenda e do formulario e normalizado antes de gravar: sem isso o mesmo
-- cliente vira dois contatos e a conversa se perde.
-- ============================================================

-- Contatos: a base. Uma linha por pessoa.
create table if not exists public.po_wa_contatos (
  id                uuid primary key default gen_random_uuid(),
  wa_id             text not null unique,
  nome              text,
  origem            text not null default 'lead',
  cliente           boolean not null default false,
  revisado          boolean not null default false,
  opt_out_at        timestamptz,
  ultimo_contato_at timestamptz,
  roteiros          jsonb not null default '[]'::jsonb,
  payload           jsonb not null default '{}'::jsonb,
  created_at        timestamptz not null default now()
);

-- Conversa: o estado do fluxo. Uma linha por contato.
create table if not exists public.po_wa_conversas (
  id               uuid primary key default gen_random_uuid(),
  wa_id            text not null unique,
  estado           text not null default 'novo',
  roteiro_slug     text,
  lead_id          uuid references public.po_leads(id) on delete set null,
  aguardando_desde timestamptz,
  lembrete_at      timestamptz,
  silenciado_at    timestamptz,
  updated_at       timestamptz not null default now()
);

-- Log de mensagens. O wamid unico E a idempotencia do webhook: a Meta
-- reenvia o evento quando nao recebe 200 rapido, e sem esta restricao o
-- cliente receberia o roteiro duas vezes.
create table if not exists public.po_wa_mensagens (
  id        uuid primary key default gen_random_uuid(),
  wa_id     text not null,
  wamid     text not null unique,
  direcao   text not null,
  autor     text not null,
  tipo      text not null default 'text',
  texto     text,
  payload   jsonb not null default '{}'::jsonb,
  ts        timestamptz not null default now()
);

create index if not exists po_wa_mensagens_wa_id_ts on public.po_wa_mensagens (wa_id, ts desc);

-- Mapa anuncio -> roteiro. Preenchido pelo painel.
create table if not exists public.po_wa_anuncios (
  ad_id        text primary key,
  roteiro_slug text not null,
  rotulo       text,
  created_at   timestamptz not null default now()
);

-- Textos do robo, editaveis pelo painel sem deploy.
create table if not exists public.po_wa_textos (
  chave      text primary key,
  texto      text not null,
  updated_at timestamptz not null default now()
);

-- Colunas no funil que ja existe. Nada e removido nem renomeado: venda e
-- venda_at ficam intocados, entao ROAS, ciclo e conversao seguem valendo.
alter table public.po_leads add column if not exists wa_id        text;
alter table public.po_leads add column if not exists ctwa_clid    text;
alter table public.po_leads add column if not exists ad_id        text;
alter table public.po_leads add column if not exists qualif_data  boolean;
alter table public.po_leads add column if not exists qualif_grupo boolean;
alter table public.po_leads add column if not exists qualif_at    timestamptz;
alter table public.po_leads add column if not exists proposta_at  timestamptz;

create unique index if not exists po_leads_wa_id_uniq on public.po_leads (wa_id) where wa_id is not null;

-- RLS no padrao das demais: o painel le autenticado, o PHP escreve com
-- service_role (que ignora RLS e so existe no servidor).
alter table public.po_wa_contatos  enable row level security;
alter table public.po_wa_conversas enable row level security;
alter table public.po_wa_mensagens enable row level security;
alter table public.po_wa_anuncios  enable row level security;
alter table public.po_wa_textos    enable row level security;

do $$
declare t text;
begin
  foreach t in array array['po_wa_contatos','po_wa_conversas','po_wa_mensagens','po_wa_anuncios','po_wa_textos']
  loop
    execute format(
      'create policy if not exists %I on public.%I for all to authenticated using (true) with check (true)',
      t || '_auth', t);
  end loop;
end $$;

-- Textos iniciais. A cliente edita pelo painel depois.
insert into public.po_wa_textos (chave, texto) values
  ('saudacao',    'Boa tarde, {nome}. Que bom falar com voce.'),
  ('envio_pdf',   'Segue o roteiro completo do {roteiro}, com o dia a dia da viagem, tudo que esta incluido e o valor.'),
  ('perguntas',   'Para eu ja adiantar seu atendimento, me responde duas coisinhas:' || chr(10) || chr(10) || '1. A viagem sai em {data}. Voce tem disponibilidade nessa data?' || chr(10) || chr(10) || '2. Voce ja viajou em grupo alguma vez?'),
  ('qualificado', 'Perfeito, {nome}. Vou passar seu contato para a nossa equipe e em breve alguem fala com voce pessoalmente.'),
  ('menu',        'Sobre qual viagem voce quer saber?'),
  ('lembrete',    'Oi {nome}, tudo bem? So passando para saber se voce chegou a ver o roteiro que enviei.'),
  ('sem_data',    'Entendo, {nome}. Vou deixar seu contato registrado e aviso quando abrirmos novas datas.')
on conflict (chave) do nothing;
```

- [ ] **Step 2: Escrever o teste que lê o schema real**

Criar `tests/test-wa-schema.php`. Ele valida contra o banco de verdade, porque uma migration só está certa quando rodou:

```php
<?php
require __DIR__ . '/../lib/po-data.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

$cfg = po_config();
$base = rtrim($cfg['SUPABASE_URL'], '/') . '/rest/v1/';

/* Pergunta ao PostgREST se a tabela responde. 200 = existe e tem RLS
   coerente; 404 = a migration nao rodou. Usa limit=0 para nao trafegar
   dado nenhum. */
function tabela_existe($base, $tabela) {
    $body = po_http_get($base . $tabela . '?select=*&limit=0', 0);
    return $body !== null;
}

foreach (['po_wa_contatos','po_wa_conversas','po_wa_mensagens','po_wa_anuncios','po_wa_textos'] as $t) {
    ok(tabela_existe($base, $t), "tabela $t existe (rodou a migration?)");
}

// As colunas novas de po_leads: pedir a coluna por nome devolve 400 se nao existir.
foreach (['wa_id','ctwa_clid','ad_id','qualif_data','qualif_grupo','qualif_at','proposta_at'] as $c) {
    ok(po_http_get($base . 'po_leads?select=' . $c . '&limit=0', 0) !== null, "po_leads.$c existe");
}

// Os textos iniciais precisam estar la, senao o robo manda mensagem vazia.
$txt = json_decode(po_http_get($base . 'po_wa_textos?select=chave', 0) ?: '[]', true);
$chaves = array_column($txt ?: [], 'chave');
foreach (['saudacao','envio_pdf','perguntas','qualificado','menu','lembrete','sem_data'] as $k) {
    ok(in_array($k, $chaves, true), "texto inicial '$k' foi inserido");
}

echo "test-wa-schema OK\n";
```

- [ ] **Step 3: Rodar o teste e confirmar que falha**

Run: `php tests/test-wa-schema.php`
Expected: FAIL com `ASSERT: tabela po_wa_contatos existe (rodou a migration?)`

- [ ] **Step 4: Rodar a migration no Supabase**

Abrir o Supabase → SQL Editor do projeto `euzmbswywwhmicjlszqw`, colar o conteúdo de `supabase/migrations/2026-09-11-whatsapp-motor.sql` e executar.

**Atenção:** o projeto é compartilhado com NOX e hd360. Todas as tabelas têm prefixo `po_`. Não rodar nada que não esteja neste arquivo.

- [ ] **Step 5: Rodar o teste e confirmar que passa**

Run: `php tests/test-wa-schema.php`
Expected: `test-wa-schema OK`

- [ ] **Step 6: Commit**

```bash
git add supabase/migrations/2026-09-11-whatsapp-motor.sql tests/test-wa-schema.php
git commit -m "feat(db): schema do motor de conversa do WhatsApp

Cinco tabelas novas (contatos, conversas, mensagens, anuncios, textos) e
sete colunas em po_leads. Nada e removido nem renomeado: venda e venda_at
ficam intocados, entao os relatorios de ROAS e ciclo seguem valendo.

O unique em po_wa_mensagens.wamid e a idempotencia do webhook: a Meta
reenvia o evento quando nao recebe 200 rapido, e sem ele o cliente recebe
o roteiro duas vezes."
```

---

### Task 2: Normalização de telefone para E.164

**Files:**
- Create: `lib/wa-fone.php`
- Test: `tests/test-wa-fone.php`

**Interfaces:**
- Consumes: nada. Função pura.
- Produces: `wa_e164(string $bruto): ?string` devolve `+55DDNNNNNNNNN` ou `null` se inválido. `wa_e_celular(string $e164): bool`. Usadas pelo motor (Task 7), pelo funil (Task 8) e depois pelo importador de contatos do plano 3.

- [ ] **Step 1: Escrever o teste que falha**

Criar `tests/test-wa-fone.php`:

```php
<?php
require __DIR__ . '/../lib/wa-fone.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

// --- formatos que a agenda do celular realmente produz
ok(wa_e164('(48) 99604-8882')    === '+5548996048882', 'formato com parenteses e traco');
ok(wa_e164('48 99604 8882')      === '+5548996048882', 'formato com espacos');
ok(wa_e164('+55 48 99604-8882')  === '+5548996048882', 'ja com DDI');
ok(wa_e164('5548996048882')      === '+5548996048882', 'so digitos com DDI');
ok(wa_e164('48996048882')        === '+5548996048882', 'so digitos sem DDI');

// --- o nono digito: celular antigo de 8 digitos ganha o 9 na frente
ok(wa_e164('4896048882')         === '+5548996048882', 'celular antigo de 8 digitos ganha o nono');
ok(wa_e164('554896048882')       === '+5548996048882', 'celular antigo com DDI ganha o nono');

// --- fixo NAO ganha nono digito (comeca com 2..5)
ok(wa_e164('4832220000')         === '+554832220000',  'fixo de 8 digitos fica como esta');

// --- lixo da agenda
ok(wa_e164('')                   === null, 'vazio');
ok(wa_e164('123')                === null, 'curto demais');
ok(wa_e164('0800 123 4567')      === null, '0800 nao e telefone de pessoa');
ok(wa_e164('(01) 99999-9999')    === null, 'DDD 01 nao existe');
ok(wa_e164('+1 415 555 2671')    === null, 'numero estrangeiro fica de fora');
ok(wa_e164('nao tem telefone')   === null, 'texto puro');

// --- idempotencia: normalizar duas vezes da o mesmo
ok(wa_e164(wa_e164('(48) 99604-8882')) === '+5548996048882', 'normalizar de novo nao estraga');

// --- celular x fixo
ok(wa_e_celular('+5548996048882') === true,  'celular de 9 digitos');
ok(wa_e_celular('+554832220000')  === false, 'fixo nao e celular');

echo "test-wa-fone OK\n";
```

- [ ] **Step 2: Rodar e confirmar que falha**

Run: `php tests/test-wa-fone.php`
Expected: FAIL com erro de arquivo não encontrado (`lib/wa-fone.php`)

- [ ] **Step 3: Implementar**

Criar `lib/wa-fone.php`:

```php
<?php
/* ============================================================
   Normalizacao de telefone brasileiro para E.164 (+55DDNNNNNNNNN).

   POR QUE ISTO EXISTE: a base de contatos vem exportada da agenda do
   celular, onde o mesmo numero aparece como "(48) 99604-8882",
   "48996048882" e "+55 48 99604 8882". Gravar formato livre faz o mesmo
   cliente virar tres contatos e a conversa se perder no meio.
============================================================ */

/* DDDs que existem de fato no Brasil. A faixa 11..99 tem buracos (20, 23,
   25, 26, 29, 30...) e aceitar tudo deixaria lixo da agenda entrar. */
const WA_DDDS = [
    11,12,13,14,15,16,17,18,19, 21,22,24,27,28,
    31,32,33,34,35,37,38, 41,42,43,44,45,46,47,48,49,
    51,53,54,55, 61,62,63,64,65,66,67,68,69,
    71,73,74,75,77,79, 81,82,83,84,85,86,87,88,89,
    91,92,93,94,95,96,97,98,99,
];

function wa_e164($bruto) {
    $d = preg_replace('/\D+/', '', (string) $bruto);
    if ($d === '') return null;

    // DDI do Brasil, quando veio. 13 digitos = 55 + DDD + 9 digitos.
    if (strlen($d) >= 12 && substr($d, 0, 2) === '55') {
        $d = substr($d, 2);
    }

    // Sobrou coisa demais: e numero estrangeiro, nao nosso.
    if (strlen($d) > 11 || strlen($d) < 10) return null;

    $ddd   = (int) substr($d, 0, 2);
    $resto = substr($d, 2);
    if (!in_array($ddd, WA_DDDS, true)) return null;

    // Nono digito: celular antigo tem 8 digitos comecando em 6..9. Fixo
    // comeca em 2..5 e NAO leva o nono (se levasse, viraria numero que nao
    // existe e o envio falharia em silencio).
    if (strlen($resto) === 8 && $resto[0] >= '6') {
        $resto = '9' . $resto;
    }

    if (!preg_match('/^\d{8,9}$/', $resto)) return null;

    return '+55' . $ddd . $resto;
}

function wa_e_celular($e164) {
    return (bool) preg_match('/^\+55\d{2}9\d{8}$/', (string) $e164);
}
```

- [ ] **Step 4: Rodar e confirmar que passa**

Run: `php tests/test-wa-fone.php`
Expected: `test-wa-fone OK`

- [ ] **Step 5: Rodar a suíte inteira, para garantir que nada quebrou**

Run: `php tests/run.php`
Expected: `Todos os testes passaram`

- [ ] **Step 6: Commit**

```bash
git add lib/wa-fone.php tests/test-wa-fone.php
git commit -m "feat(wa): normalizacao de telefone para E.164

A base vem da agenda do celular, onde o mesmo numero aparece em tres
formatos diferentes. Sem normalizar, um cliente vira tres contatos.

Dois detalhes que o teste trava: o nono digito so entra em celular
(comeca em 6..9), nunca em fixo, senao o numero deixa de existir e o
envio falha calado; e a lista de DDD e explicita, porque a faixa 11..99
tem buracos e aceitar tudo deixa lixo entrar."
```

---

### Task 3: Identificação do roteiro pelo texto

**Files:**
- Create: `lib/wa-roteiro.php`
- Test: `tests/test-wa-roteiro.php`

**Interfaces:**
- Consumes: `po_fetch_roteiros()` de `lib/po-data.php` (já existe, devolve os roteiros ativos).
- Produces: `wa_match_roteiros(string $texto, array $roteiros): array` devolve a lista de slugs que casaram (0 = não achou, 1 = certeza, 2+ = ambíguo). `wa_slug_do_marcador(string $texto): ?string` lê o marcador `[r:<slug>]` embutido no link do site. Usadas pelo motor na Task 7.

- [ ] **Step 1: Escrever o teste que falha**

Criar `tests/test-wa-roteiro.php`:

```php
<?php
require __DIR__ . '/../lib/wa-roteiro.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

// Catalogo falso no formato que po_fetch_roteiros() devolve.
$cat = [
    ['slug' => 'turquia',                    'titulo' => 'Turquia com Antalia'],
    ['slug' => 'chile-e-deserto-do-atacama', 'titulo' => 'Chile, Santiago e Deserto do Atacama'],
    ['slug' => 'escandinavia',               'titulo' => 'O melhor da Escandinavia'],
    ['slug' => 'caminhos-da-india',          'titulo' => 'Caminhos da India'],
];

// --- o nome do destino no meio de uma frase
ok(wa_match_roteiros('oi queria saber da viagem pra Turquia', $cat) === ['turquia'], 'destino no meio da frase');
ok(wa_match_roteiros('bom dia, me fala do atacama',           $cat) === ['chile-e-deserto-do-atacama'], 'palavra do titulo composto');

// --- acento e caixa nao podem atrapalhar: o cliente digita como quiser
ok(wa_match_roteiros('ESCANDINÁVIA',   $cat) === ['escandinavia'], 'caixa alta e acento');
ok(wa_match_roteiros('india',          $cat) === ['caminhos-da-india'], 'sem acento casa com titulo acentuado');

// --- apelidos que o cliente usa e nao estao no titulo
ok(wa_match_roteiros('a viagem das cerejeiras', array_merge($cat, [['slug'=>'floracao-das-cerejeiras','titulo'=>'Floracao das Cerejeiras']])) === ['floracao-das-cerejeiras'], 'apelido cerejeira');

// --- nada reconhecivel devolve vazio, e o motor cai no menu
ok(wa_match_roteiros('oi boa tarde',        $cat) === [], 'saudacao sem destino');
ok(wa_match_roteiros('quanto custa?',       $cat) === [], 'pergunta sem destino');
ok(wa_match_roteiros('',                    $cat) === [], 'vazio');

// --- ambiguo devolve os dois, e o motor tambem cai no menu
$dois = wa_match_roteiros('quero saber da turquia e da india', $cat);
ok(count($dois) === 2, 'dois destinos citados devolvem dois slugs');

// --- palavra curta nao pode casar por pedaco: "ar" nao e "Antalia"
ok(wa_match_roteiros('ar', $cat) === [], 'fragmento curto nao casa');

// --- marcador do link do site tem prioridade e nunca vaza para o cliente
ok(wa_slug_do_marcador('Quero saber do roteiro [r:turquia]') === 'turquia', 'le o marcador');
ok(wa_slug_do_marcador('Quero saber do roteiro')             === null,      'sem marcador devolve null');
ok(wa_slug_do_marcador('[r:nao_existe_slug_com_underline]')  === null,      'marcador invalido e ignorado');

echo "test-wa-roteiro OK\n";
```

- [ ] **Step 2: Rodar e confirmar que falha**

Run: `php tests/test-wa-roteiro.php`
Expected: FAIL, arquivo `lib/wa-roteiro.php` não existe

- [ ] **Step 3: Implementar**

Criar `lib/wa-roteiro.php`:

```php
<?php
/* ============================================================
   Descobre de qual roteiro o cliente esta falando.

   Ordem de confianca (quem chama decide): anuncio > marcador do link do
   site > casamento por palavra > menu. Aqui moram os dois ultimos.

   NAO usa IA de proposito: o catalogo tem 6 roteiros com nomes de
   destino bem distintos, o casamento por palavra resolve, e assim nao ha
   custo por conversa nem uma peca a mais para falhar.
============================================================ */

/* Apelidos que o cliente usa e que nao aparecem no titulo. Chave = palavra
   que ele digita, valor = pedaco que precisa estar no slug ou no titulo. */
const WA_APELIDOS = [
    'cerejeira'  => 'cerejeir',
    'cerejeiras' => 'cerejeir',
    'japao'      => 'cerejeir',
    'natal'      => 'natal',
    'mercados'   => 'natal',
    'grecia'     => 'grecia',
    'atacama'    => 'atacama',
    'deserto'    => 'atacama',
    'india'      => 'india',
    'turquia'    => 'turquia',
    'escandinavia' => 'escandinav',
    'noruega'    => 'escandinav',
];

/* Minusculas, sem acento. O cliente digita "ESCANDINÁVIA" e "escandinavia"
   e as duas formas precisam casar com o mesmo roteiro. */
function wa_normaliza($s) {
    $s = mb_strtolower((string) $s, 'UTF-8');
    $s = strtr($s, [
        'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a',
        'é'=>'e','ê'=>'e','è'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        'ó'=>'o','ô'=>'o','õ'=>'o','ò'=>'o','ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
        'ç'=>'c','ñ'=>'n',
    ]);
    return preg_replace('/[^a-z0-9]+/', ' ', $s);
}

/* Palavras do titulo que nao identificam destino nenhum. Sem esta lista,
   "melhor" casaria com "O melhor da Escandinavia" e qualquer elogio do
   cliente viraria escolha de roteiro. */
const WA_VAZIAS = ['de','da','do','das','dos','e','com','o','a','os','as','em','melhor','roteiro','viagem','pelo','pela'];

function wa_match_roteiros($texto, $roteiros) {
    $t = wa_normaliza($texto);
    if (trim($t) === '') return [];
    $palavras = array_filter(explode(' ', $t), function ($p) {
        // Fragmento curto casaria por acidente ("ar" dentro de "Antalia").
        return strlen($p) >= 4 && !in_array($p, WA_VAZIAS, true);
    });
    if (!$palavras) return [];

    $achados = [];
    foreach ($roteiros as $r) {
        $alvo = wa_normaliza(($r['slug'] ?? '') . ' ' . ($r['titulo'] ?? ''));
        foreach ($palavras as $p) {
            $casou = strpos($alvo, $p) !== false;
            if (!$casou && isset(WA_APELIDOS[$p])) {
                $casou = strpos($alvo, WA_APELIDOS[$p]) !== false;
            }
            if ($casou) { $achados[] = $r['slug']; break; }
        }
    }
    return array_values(array_unique($achados));
}

/* Marcador embutido no texto pre-preenchido do link wa.me do site. E a
   forma mais confiavel depois do anuncio, porque o link e nosso. */
function wa_slug_do_marcador($texto) {
    if (preg_match('/\[r:([a-z0-9-]+)\]/', (string) $texto, $m)) {
        return $m[1];
    }
    return null;
}
```

- [ ] **Step 4: Rodar e confirmar que passa**

Run: `php tests/test-wa-roteiro.php`
Expected: `test-wa-roteiro OK`

- [ ] **Step 5: Rodar a suíte inteira**

Run: `php tests/run.php`
Expected: `Todos os testes passaram`

- [ ] **Step 6: Commit**

```bash
git add lib/wa-roteiro.php tests/test-wa-roteiro.php
git commit -m "feat(wa): identificacao do roteiro por palavra e por marcador

Sem IA: o catalogo tem 6 destinos bem distintos e o casamento por palavra
resolve, sem custo por conversa e sem uma peca a mais para falhar.

Duas guardas que o teste trava: palavra com menos de 4 letras nao casa
(senao 'ar' acha 'Antalia'), e a lista de palavras vazias impede que
'melhor' selecione 'O melhor da Escandinavia' quando o cliente so estava
elogiando. Ambiguidade devolve os dois slugs, e quem chama cai no menu."
```

---

### Task 4: Acesso de escrita ao Supabase

**Files:**
- Create: `lib/wa-db.php`
- Test: `tests/test-wa-db.php`

**Interfaces:**
- Consumes: `po_config()` de `lib/po-data.php`.
- Produces: `wa_db_set_transport(?callable $fn)`, `wa_db_select(string $tabela, string $query): array`, `wa_db_insert(string $tabela, array $linha, bool $ignora_conflito = false): ?array`, `wa_db_update(string $tabela, string $query, array $campos): bool`, `wa_db_upsert(string $tabela, array $linha, string $chave): ?array`. Usadas pelas Tasks 6, 7, 8 e 9.

- [ ] **Step 1: Escrever o teste que falha**

Criar `tests/test-wa-db.php`:

```php
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
```

- [ ] **Step 2: Rodar e confirmar que falha**

Run: `php tests/test-wa-db.php`
Expected: FAIL, arquivo não existe

- [ ] **Step 3: Implementar**

Criar `lib/wa-db.php`:

```php
<?php
/* ============================================================
   Escrita e leitura no Supabase com a service_role.

   O lib/po-data.php so le (e com cache em disco, que aqui seria veneno:
   o estado da conversa muda a cada mensagem). Este arquivo e o caminho de
   escrita, sem cache nenhum.

   A service_role IGNORA a RLS e so existe no servidor, em
   config.local.php. Nunca no Git, nunca no painel.
============================================================ */

require_once __DIR__ . '/po-data.php';

function wa_db_set_transport($f) { $GLOBALS['WA_DB_TRANSPORT'] = $f; }

function wa_db_headers() {
    $cfg = po_config();
    $key = $cfg['SUPABASE_SERVICE_KEY'] ?? '';
    return [
        'apikey: ' . $key,
        'Authorization: Bearer ' . $key,
        'Content-Type: application/json',
        'Prefer: return=representation',
    ];
}

/* Um unico ponto de saida para a rede, para o teste conseguir substituir. */
function wa_db_http($metodo, $url, $corpo, $headers) {
    if (isset($GLOBALS['WA_DB_TRANSPORT']) && $GLOBALS['WA_DB_TRANSPORT']) {
        return call_user_func($GLOBALS['WA_DB_TRANSPORT'], $metodo, $url, $corpo, $headers);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 10,
    ]);
    if ($corpo !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $corpo);
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erro   = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        error_log('wa_db_http falhou: ' . $erro . ' | ' . $url);
        return null;
    }
    return ['status' => $status, 'body' => $body];
}

function wa_db_url($tabela, $query = '') {
    $cfg = po_config();
    return rtrim($cfg['SUPABASE_URL'], '/') . '/rest/v1/' . $tabela . ($query !== '' ? '?' . $query : '');
}

function wa_db_select($tabela, $query) {
    $r = wa_db_http('GET', wa_db_url($tabela, $query), null, wa_db_headers());
    if (!$r || $r['status'] >= 300) return [];
    $j = json_decode($r['body'], true);
    return is_array($j) ? $j : [];
}

/* $ignora_conflito: para o log de mensagens, onde o unique em wamid E a
   idempotencia. Um 409 ali significa "a Meta reenviou o mesmo evento", que
   e o comportamento esperado, nao erro. */
function wa_db_insert($tabela, $linha, $ignora_conflito = false) {
    $r = wa_db_http('POST', wa_db_url($tabela), json_encode($linha), wa_db_headers());
    if (!$r) return null;
    if ($r['status'] === 409 && $ignora_conflito) return null;
    if ($r['status'] >= 300) {
        error_log('wa_db_insert ' . $tabela . ' status ' . $r['status'] . ': ' . $r['body']);
        return null;
    }
    $j = json_decode($r['body'], true);
    return (is_array($j) && isset($j[0])) ? $j[0] : null;
}

function wa_db_update($tabela, $query, $campos) {
    $r = wa_db_http('PATCH', wa_db_url($tabela, $query), json_encode($campos), wa_db_headers());
    if (!$r) return false;
    if ($r['status'] >= 300) {
        error_log('wa_db_update ' . $tabela . ' status ' . $r['status'] . ': ' . $r['body']);
        return false;
    }
    return true;
}

/* Upsert pela coluna unica: cria se nao existe, atualiza se existe. */
function wa_db_upsert($tabela, $linha, $chave) {
    $h = wa_db_headers();
    $h[] = 'Prefer: resolution=merge-duplicates';
    $r = wa_db_http('POST', wa_db_url($tabela, 'on_conflict=' . $chave), json_encode($linha), $h);
    if (!$r || $r['status'] >= 300) return null;
    $j = json_decode($r['body'], true);
    return (is_array($j) && isset($j[0])) ? $j[0] : null;
}
```

- [ ] **Step 4: Rodar e confirmar que passa**

Run: `php tests/test-wa-db.php`
Expected: `test-wa-db OK`

- [ ] **Step 5: Rodar a suíte inteira**

Run: `php tests/run.php`
Expected: `Todos os testes passaram`

- [ ] **Step 6: Commit**

```bash
git add lib/wa-db.php tests/test-wa-db.php
git commit -m "feat(wa): camada de escrita no Supabase com service_role

O po-data.php so le e com cache em disco, que aqui seria veneno: o estado
da conversa muda a cada mensagem. Este e o caminho de escrita, sem cache.

Rede fora do ar devolve null/false/[] em vez de excecao, porque quem chama
e o webhook: se ele explodir, a Meta reenvia em loop e acaba derrubando a
inscricao. Conflito de unique com ignora_conflito tambem nao e erro, e a
propria idempotencia do log de mensagens."
```

---

### Task 5: Envio pela Graph API, com simulador

**Files:**
- Create: `lib/wa-send.php`
- Test: `tests/test-wa-send.php`

**Interfaces:**
- Consumes: `po_config()`.
- Produces: `wa_set_transport(?callable $fn)`, `wa_send_text(string $para, string $texto): array`, `wa_send_document(string $para, string $url, string $arquivo, string $legenda): array`, `wa_send_list(string $para, string $corpo, string $botao, array $itens): array`. Todas devolvem `['ok'=>bool, 'wamid'=>?string, 'erro'=>?string]`. Usadas pela Task 7 e pelo plano 3.

- [ ] **Step 1: Escrever o teste que falha**

Criar `tests/test-wa-send.php`:

```php
<?php
require __DIR__ . '/../lib/wa-send.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

$capt = [];
wa_set_transport(function ($url, $payload, $headers) use (&$capt) {
    $capt = compact('url', 'payload', 'headers');
    return ['status' => 200, 'body' => '{"messages":[{"id":"wamid.TESTE"}]}'];
});

// --- texto
$r = wa_send_text('+5548996048882', 'Bom dia');
ok($r['ok'] === true, 'texto devolve ok');
ok($r['wamid'] === 'wamid.TESTE', 'texto devolve o wamid');
$p = json_decode($capt['payload'], true);
ok($p['messaging_product'] === 'whatsapp', 'messaging_product obrigatorio');
ok($p['type'] === 'text', 'tipo text');
ok($p['text']['body'] === 'Bom dia', 'corpo do texto');
// O "+" nao vai para a Graph API: ela quer so digitos.
ok($p['to'] === '5548996048882', 'destinatario sem o mais');

// --- documento (o PDF do roteiro)
$r = wa_send_document('+5548996048882', 'https://x/docs/turquia-ab12.pdf', 'Turquia.pdf', 'Segue o roteiro');
$p = json_decode($capt['payload'], true);
ok($p['type'] === 'document', 'tipo document');
ok($p['document']['link'] === 'https://x/docs/turquia-ab12.pdf', 'link do PDF');
ok($p['document']['filename'] === 'Turquia.pdf', 'nome do arquivo');
ok($p['document']['caption'] === 'Segue o roteiro', 'legenda');
ok($r['ok'] === true, 'documento devolve ok');

// --- menu de lista
$itens = [
    ['id' => 'turquia',      'titulo' => 'Turquia com Antalia'],
    ['id' => 'escandinavia', 'titulo' => 'O melhor da Escandinavia'],
];
$r = wa_send_list('+5548996048882', 'Sobre qual viagem?', 'Ver roteiros', $itens);
$p = json_decode($capt['payload'], true);
ok($p['type'] === 'interactive', 'tipo interactive');
ok($p['interactive']['type'] === 'list', 'interactive do tipo list');
ok(count($p['interactive']['action']['sections'][0]['rows']) === 2, 'duas linhas no menu');
ok($p['interactive']['action']['sections'][0]['rows'][0]['id'] === 'turquia', 'id da linha e o slug');
ok($r['ok'] === true, 'lista devolve ok');

// O WhatsApp corta titulo de linha em 24 caracteres: cortar aqui evita o
// erro 400 que devolveria a mensagem inteira sem enviar.
$longo = [['id' => 'x', 'titulo' => 'Um titulo absurdamente longo que o WhatsApp recusa']];
wa_send_list('+5548996048882', 'c', 'b', $longo);
$p = json_decode($capt['payload'], true);
ok(mb_strlen($p['interactive']['action']['sections'][0]['rows'][0]['title']) <= 24, 'titulo de linha cortado em 24');

// --- limite de 10 linhas por lista
$onze = [];
for ($i = 0; $i < 11; $i++) $onze[] = ['id' => "s$i", 'titulo' => "Roteiro $i"];
wa_send_list('+5548996048882', 'c', 'b', $onze);
$p = json_decode($capt['payload'], true);
ok(count($p['interactive']['action']['sections'][0]['rows']) === 10, 'lista corta em 10 linhas');

// --- erro da API nao pode virar excecao
wa_set_transport(function () { return ['status' => 400, 'body' => '{"error":{"message":"Invalid parameter"}}']; });
$r = wa_send_text('+5548996048882', 'x');
ok($r['ok'] === false, 'erro 400 devolve ok=false');
ok(strpos($r['erro'], 'Invalid parameter') !== false, 'erro traz a mensagem da Meta');

// --- rede fora do ar idem
wa_set_transport(function () { return null; });
$r = wa_send_text('+5548996048882', 'x');
ok($r['ok'] === false, 'rede fora devolve ok=false');

// --- telefone invalido nem chega a sair
$r = wa_send_text('telefone ruim', 'x');
ok($r['ok'] === false, 'destinatario invalido nao envia');

echo "test-wa-send OK\n";
```

- [ ] **Step 2: Rodar e confirmar que falha**

Run: `php tests/test-wa-send.php`
Expected: FAIL, arquivo não existe

- [ ] **Step 3: Implementar**

Criar `lib/wa-send.php`:

```php
<?php
/* ============================================================
   Envio pela Cloud API (Graph v21).

   O transporte e injetavel de proposito: e o que permite construir e
   testar o motor inteiro contra um simulador, antes de a conta da Meta
   estar verificada. Quando a verificacao sair, so o transporte muda.
============================================================ */

require_once __DIR__ . '/po-data.php';
require_once __DIR__ . '/wa-fone.php';

const WA_GRAPH = 'https://graph.facebook.com/v21.0/';

function wa_set_transport($f) { $GLOBALS['WA_TRANSPORT'] = $f; }

function wa_http($url, $payload, $headers) {
    if (isset($GLOBALS['WA_TRANSPORT']) && $GLOBALS['WA_TRANSPORT']) {
        return call_user_func($GLOBALS['WA_TRANSPORT'], $url, $payload, $headers);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erro   = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        error_log('wa_http falhou: ' . $erro);
        return null;
    }
    return ['status' => $status, 'body' => $body];
}

function wa_envia($mensagem) {
    $cfg = po_config();
    $url = WA_GRAPH . ($cfg['WA_PHONE_ID'] ?? '') . '/messages';
    $h   = [
        'Authorization: Bearer ' . ($cfg['WA_TOKEN'] ?? ''),
        'Content-Type: application/json',
    ];
    $r = wa_http($url, json_encode($mensagem, JSON_UNESCAPED_UNICODE), $h);
    if (!$r) return ['ok' => false, 'wamid' => null, 'erro' => 'sem resposta da Graph API'];

    $j = json_decode($r['body'], true);
    if ($r['status'] >= 300) {
        $msg = $j['error']['message'] ?? ('HTTP ' . $r['status']);
        error_log('wa_envia erro: ' . $r['body']);
        return ['ok' => false, 'wamid' => null, 'erro' => $msg];
    }
    return ['ok' => true, 'wamid' => $j['messages'][0]['id'] ?? null, 'erro' => null];
}

/* A Graph API quer o numero so em digitos, sem o "+". Passar com o mais
   devolve 400 e a mensagem nao sai. */
function wa_destino($para) {
    $e = wa_e164($para);
    return $e ? ltrim($e, '+') : null;
}

function wa_send_text($para, $texto) {
    $to = wa_destino($para);
    if (!$to) return ['ok' => false, 'wamid' => null, 'erro' => 'telefone invalido: ' . $para];
    return wa_envia([
        'messaging_product' => 'whatsapp',
        'to'                => $to,
        'type'              => 'text',
        'text'              => ['body' => $texto, 'preview_url' => true],
    ]);
}

function wa_send_document($para, $url, $arquivo, $legenda = '') {
    $to = wa_destino($para);
    if (!$to) return ['ok' => false, 'wamid' => null, 'erro' => 'telefone invalido: ' . $para];
    return wa_envia([
        'messaging_product' => 'whatsapp',
        'to'                => $to,
        'type'              => 'document',
        'document'          => ['link' => $url, 'filename' => $arquivo, 'caption' => $legenda],
    ]);
}

/* Menu de lista. O WhatsApp impoe: no maximo 10 linhas, titulo de linha
   com ate 24 caracteres e descricao com ate 72. Estourar qualquer um
   devolve 400 e a mensagem inteira nao sai, entao cortamos aqui. */
function wa_send_list($para, $corpo, $botao, $itens) {
    $to = wa_destino($para);
    if (!$to) return ['ok' => false, 'wamid' => null, 'erro' => 'telefone invalido: ' . $para];

    $rows = [];
    foreach (array_slice($itens, 0, 10) as $i) {
        $row = [
            'id'    => substr($i['id'], 0, 200),
            'title' => mb_substr($i['titulo'], 0, 24, 'UTF-8'),
        ];
        if (!empty($i['descricao'])) {
            $row['description'] = mb_substr($i['descricao'], 0, 72, 'UTF-8');
        }
        $rows[] = $row;
    }

    return wa_envia([
        'messaging_product' => 'whatsapp',
        'to'                => $to,
        'type'              => 'interactive',
        'interactive'       => [
            'type'   => 'list',
            'body'   => ['text' => mb_substr($corpo, 0, 1024, 'UTF-8')],
            'action' => [
                'button'   => mb_substr($botao, 0, 20, 'UTF-8'),
                'sections' => [['title' => 'Roteiros', 'rows' => $rows]],
            ],
        ],
    ]);
}
```

- [ ] **Step 4: Rodar e confirmar que passa**

Run: `php tests/test-wa-send.php`
Expected: `test-wa-send OK`

- [ ] **Step 5: Rodar a suíte inteira**

Run: `php tests/run.php`
Expected: `Todos os testes passaram`

- [ ] **Step 6: Commit**

```bash
git add lib/wa-send.php tests/test-wa-send.php
git commit -m "feat(wa): envio pela Graph API com transporte injetavel

O transporte injetavel e o que destrava o cronograma: da para construir e
testar o motor inteiro contra um simulador antes de a conta da Meta estar
verificada, e trocar so o transporte depois.

Os limites do menu de lista (10 linhas, titulo de 24, descricao de 72)
sao cortados aqui porque estourar qualquer um devolve 400 e a mensagem
inteira nao sai. O destinatario vai sem o '+', que a Graph API recusa."
```

---

### Task 6: Webhook com verificação, assinatura e idempotência

**Files:**
- Create: `whatsapp.php`
- Create: `lib/wa-webhook.php`
- Test: `tests/test-wa-webhook.php`
- Modify: `config.local.example.php`

**Interfaces:**
- Consumes: `wa_db_insert()` (Task 4).
- Produces: `wa_verifica_assinatura(string $corpo, string $header, string $segredo): bool`, `wa_parse_evento(array $json): array` que devolve a lista de eventos normalizados no formato `['tipo'=>'mensagem'|'eco'|'status', 'wa_id'=>string, 'wamid'=>string, 'texto'=>string, 'tipo_msg'=>string, 'nome'=>?string, 'ad_id'=>?string, 'ctwa_clid'=>?string, 'ts'=>int]`. Consumido pelo motor na Task 7.

- [ ] **Step 1: Escrever o teste que falha**

Criar `tests/test-wa-webhook.php`:

```php
<?php
require __DIR__ . '/../lib/wa-webhook.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

// --- assinatura
$segredo = 'segredo-de-teste';
$corpo   = '{"object":"whatsapp_business_account"}';
$boa     = 'sha256=' . hash_hmac('sha256', $corpo, $segredo);

ok(wa_verifica_assinatura($corpo, $boa, $segredo) === true, 'assinatura correta passa');
ok(wa_verifica_assinatura($corpo, 'sha256=00', $segredo) === false, 'assinatura errada barra');
ok(wa_verifica_assinatura($corpo, '', $segredo) === false, 'sem assinatura barra');
ok(wa_verifica_assinatura($corpo . ' ', $boa, $segredo) === false, 'corpo alterado barra');

// --- mensagem de texto do cliente
$msg = json_decode('{
 "entry":[{"changes":[{"value":{
   "contacts":[{"profile":{"name":"Maria Aparecida"},"wa_id":"5548996048882"}],
   "messages":[{"from":"5548996048882","id":"wamid.AAA","timestamp":"1757548800",
     "type":"text","text":{"body":"queria saber da Turquia"}}]
 }}]}]}', true);
$ev = wa_parse_evento($msg);
ok(count($ev) === 1, 'um evento');
ok($ev[0]['tipo'] === 'mensagem', 'tipo mensagem');
ok($ev[0]['wa_id'] === '+5548996048882', 'wa_id normalizado com o mais');
ok($ev[0]['texto'] === 'queria saber da Turquia', 'texto');
ok($ev[0]['nome'] === 'Maria Aparecida', 'nome do perfil');
ok($ev[0]['wamid'] === 'wamid.AAA', 'wamid');

// --- mensagem vinda de anuncio traz a atribuicao
$ad = json_decode('{
 "entry":[{"changes":[{"value":{
   "messages":[{"from":"5548996048882","id":"wamid.BBB","timestamp":"1757548800",
     "type":"text","text":{"body":"oi"},
     "referral":{"source_id":"120210000","ctwa_clid":"ARxyz"}}]
 }}]}]}', true);
$ev = wa_parse_evento($ad);
ok($ev[0]['ad_id'] === '120210000', 'ad_id do referral');
ok($ev[0]['ctwa_clid'] === 'ARxyz', 'ctwa_clid do referral');

// --- resposta do menu de lista vira o slug escolhido
$lista = json_decode('{
 "entry":[{"changes":[{"value":{
   "messages":[{"from":"5548996048882","id":"wamid.CCC","timestamp":"1757548800",
     "type":"interactive","interactive":{"type":"list_reply",
       "list_reply":{"id":"turquia","title":"Turquia com Antalia"}}}]
 }}]}]}', true);
$ev = wa_parse_evento($lista);
ok($ev[0]['tipo_msg'] === 'list_reply', 'tipo da mensagem e list_reply');
ok($ev[0]['texto'] === 'turquia', 'texto da lista e o id escolhido');

// --- ECO: o que ela digita no celular. E o sinal de handoff.
$eco = json_decode('{
 "entry":[{"changes":[{"field":"smb_message_echoes","value":{
   "message_echoes":[{"to":"5548996048882","id":"wamid.DDD","timestamp":"1757548800",
     "type":"text","text":{"body":"Bom dia Maria, aqui e a Simone"}}]
 }}]}]}', true);
$ev = wa_parse_evento($eco);
ok(count($ev) === 1, 'eco vira um evento');
ok($ev[0]['tipo'] === 'eco', 'tipo eco');
ok($ev[0]['wa_id'] === '+5548996048882', 'eco usa o destinatario como wa_id');

// --- documento enviado por ela (sinal de proposta)
$doc = json_decode('{
 "entry":[{"changes":[{"field":"smb_message_echoes","value":{
   "message_echoes":[{"to":"5548996048882","id":"wamid.EEE","timestamp":"1757548800",
     "type":"document","document":{"filename":"proposta.pdf"}}]
 }}]}]}', true);
$ev = wa_parse_evento($doc);
ok($ev[0]['tipo'] === 'eco' && $ev[0]['tipo_msg'] === 'document', 'eco de documento');

// --- status de entrega
$st = json_decode('{
 "entry":[{"changes":[{"value":{
   "statuses":[{"id":"wamid.FFF","status":"delivered","recipient_id":"5548996048882","timestamp":"1757548800"}]
 }}]}]}', true);
$ev = wa_parse_evento($st);
ok($ev[0]['tipo'] === 'status' && $ev[0]['texto'] === 'delivered', 'status de entrega');

// --- payload que nao interessa nao pode explodir
ok(wa_parse_evento([]) === [], 'json vazio devolve []');
ok(wa_parse_evento(['entry' => [['changes' => [['value' => []]]]]]) === [], 'change sem mensagem devolve []');

echo "test-wa-webhook OK\n";
```

- [ ] **Step 2: Rodar e confirmar que falha**

Run: `php tests/test-wa-webhook.php`
Expected: FAIL, arquivo não existe

- [ ] **Step 3: Implementar o parser**

Criar `lib/wa-webhook.php`:

```php
<?php
/* ============================================================
   Leitura do webhook da Cloud API: assinatura e normalizacao.

   Separado do whatsapp.php de proposito: o arquivo que recebe o POST nao
   da para testar, mas estas funcoes dao.
============================================================ */

require_once __DIR__ . '/wa-fone.php';

/* hash_equals e obrigatorio: comparar com == vaza o segredo por tempo de
   resposta, um byte de cada vez. */
function wa_verifica_assinatura($corpo, $header, $segredo) {
    if (!$header || strpos($header, 'sha256=') !== 0) return false;
    $esperado = 'sha256=' . hash_hmac('sha256', $corpo, $segredo);
    return hash_equals($esperado, $header);
}

function wa_norm_fone($digitos) {
    return wa_e164('+' . ltrim((string) $digitos, '+'));
}

/* Achata o payload aninhado da Meta numa lista simples de eventos. */
function wa_parse_evento($json) {
    $eventos = [];
    foreach ($json['entry'] ?? [] as $entry) {
        foreach ($entry['changes'] ?? [] as $change) {
            $v = $change['value'] ?? [];

            // Nome do perfil, quando vem, para batizar o contato.
            $nome = $v['contacts'][0]['profile']['name'] ?? null;

            // 1) mensagens do cliente
            foreach ($v['messages'] ?? [] as $m) {
                $wa = wa_norm_fone($m['from'] ?? '');
                if (!$wa) continue;
                $eventos[] = [
                    'tipo'      => 'mensagem',
                    'wa_id'     => $wa,
                    'wamid'     => $m['id'] ?? '',
                    'tipo_msg'  => wa_tipo_msg($m),
                    'texto'     => wa_texto_msg($m),
                    'nome'      => $nome,
                    'ad_id'     => $m['referral']['source_id'] ?? null,
                    'ctwa_clid' => $m['referral']['ctwa_clid'] ?? null,
                    'ts'        => (int) ($m['timestamp'] ?? time()),
                ];
            }

            // 2) eco: o que a cliente digitou no app do celular. Este e o
            //    sinal de handoff, e e o que faz o robo se calar.
            foreach ($v['message_echoes'] ?? [] as $m) {
                $wa = wa_norm_fone($m['to'] ?? '');
                if (!$wa) continue;
                $eventos[] = [
                    'tipo'      => 'eco',
                    'wa_id'     => $wa,
                    'wamid'     => $m['id'] ?? '',
                    'tipo_msg'  => wa_tipo_msg($m),
                    'texto'     => wa_texto_msg($m),
                    'nome'      => null,
                    'ad_id'     => null,
                    'ctwa_clid' => null,
                    'ts'        => (int) ($m['timestamp'] ?? time()),
                ];
            }

            // 3) status de entrega, para o relatorio de campanha
            foreach ($v['statuses'] ?? [] as $s) {
                $wa = wa_norm_fone($s['recipient_id'] ?? '');
                if (!$wa) continue;
                $eventos[] = [
                    'tipo'      => 'status',
                    'wa_id'     => $wa,
                    'wamid'     => $s['id'] ?? '',
                    'tipo_msg'  => 'status',
                    'texto'     => $s['status'] ?? '',
                    'nome'      => null,
                    'ad_id'     => null,
                    'ctwa_clid' => null,
                    'ts'        => (int) ($s['timestamp'] ?? time()),
                ];
            }
        }
    }
    return $eventos;
}

function wa_tipo_msg($m) {
    $t = $m['type'] ?? 'text';
    if ($t === 'interactive') {
        return $m['interactive']['type'] ?? 'interactive';
    }
    return $t;
}

/* O que conta como "texto" para o motor. Na resposta do menu, o que
   interessa e o id da linha escolhida, que e o slug do roteiro. */
function wa_texto_msg($m) {
    $t = $m['type'] ?? 'text';
    if ($t === 'text')        return $m['text']['body'] ?? '';
    if ($t === 'button')      return $m['button']['payload'] ?? '';
    if ($t === 'interactive') {
        $i = $m['interactive'] ?? [];
        if (($i['type'] ?? '') === 'list_reply')   return $i['list_reply']['id'] ?? '';
        if (($i['type'] ?? '') === 'button_reply') return $i['button_reply']['id'] ?? '';
    }
    if ($t === 'document') return $m['document']['filename'] ?? '';
    return '';
}
```

- [ ] **Step 4: Rodar e confirmar que passa**

Run: `php tests/test-wa-webhook.php`
Expected: `test-wa-webhook OK`

- [ ] **Step 5: Criar o endpoint**

Criar `whatsapp.php` na raiz:

```php
<?php
/* ============================================================
   Webhook da Cloud API do WhatsApp.

   REGRA DE OURO: responder 200 SEMPRE, mesmo em erro interno. Devolver
   erro faz a Meta reenviar em loop e, na repeticao, derrubar a inscricao
   do webhook. O erro vai para o log, nao para a resposta.
============================================================ */

require_once __DIR__ . '/lib/wa-webhook.php';
require_once __DIR__ . '/lib/wa-db.php';
require_once __DIR__ . '/lib/wa-motor.php';

$cfg = po_config();

// 1) Verificacao do webhook (a Meta chama uma vez, no cadastro).
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $modo     = $_GET['hub_mode']         ?? '';
    $token    = $_GET['hub_verify_token'] ?? '';
    $desafio  = $_GET['hub_challenge']    ?? '';
    if ($modo === 'subscribe' && hash_equals((string) ($cfg['WA_VERIFY_TOKEN'] ?? ''), (string) $token)) {
        header('Content-Type: text/plain');
        echo $desafio;
        exit;
    }
    http_response_code(403);
    exit;
}

$corpo = file_get_contents('php://input');

// 2) Assinatura. Sem isso, qualquer um posta evento falso no webhook.
$assinatura = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
if (!wa_verifica_assinatura($corpo, $assinatura, $cfg['WA_APP_SECRET'] ?? '')) {
    error_log('whatsapp.php: assinatura invalida');
    http_response_code(403);
    exit;
}

// A partir daqui a resposta e sempre 200.
http_response_code(200);
header('Content-Type: application/json');
echo '{"ok":true}';

// Fecha a conexao com a Meta antes de processar: o relogio dela nao fica
// correndo enquanto falamos com o Supabase e com a Graph API.
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    @ob_end_flush();
    @flush();
}

try {
    $json = json_decode($corpo, true);
    foreach (wa_parse_evento(is_array($json) ? $json : []) as $ev) {
        // Idempotencia: o unique em wamid barra o reenvio da Meta. Se a
        // linha ja existia, este evento ja foi tratado.
        if ($ev['wamid'] !== '' && $ev['tipo'] !== 'status') {
            $novo = wa_db_insert('po_wa_mensagens', [
                'wa_id'   => $ev['wa_id'],
                'wamid'   => $ev['wamid'],
                'direcao' => $ev['tipo'] === 'eco' ? 'out' : 'in',
                'autor'   => $ev['tipo'] === 'eco' ? 'humano' : 'cliente',
                'tipo'    => $ev['tipo_msg'],
                'texto'   => $ev['texto'],
                'ts'      => gmdate('c', $ev['ts']),
            ], true);
            if ($novo === null) continue; // ja processado, ou falha de rede
        }
        wa_processar($ev);
    }
} catch (Throwable $e) {
    error_log('whatsapp.php: ' . $e->getMessage());
}
```

- [ ] **Step 6: Documentar os segredos novos**

Acrescentar ao final de `config.local.example.php`:

```php
// --- WhatsApp Cloud API -------------------------------------------
// Painel do app em developers.facebook.com. Todos sao SEGREDO: este
// arquivo tem uma copia real (config.local.php) que nunca vai pro Git.
$WA_TOKEN        = '';  // token permanente do System User
$WA_PHONE_ID     = '';  // Phone Number ID (nao e o telefone)
$WA_APP_SECRET   = '';  // App Secret, usado para validar a assinatura
$WA_VERIFY_TOKEN = '';  // string inventada por nos, repetida no cadastro do webhook
```

- [ ] **Step 7: Rodar a suíte inteira**

Run: `php tests/run.php`
Expected: `Todos os testes passaram`

- [ ] **Step 8: Commit**

```bash
git add whatsapp.php lib/wa-webhook.php tests/test-wa-webhook.php config.local.example.php
git commit -m "feat(wa): webhook com verificacao, assinatura e idempotencia

Tres decisoes que o teste trava:

- Assinatura conferida com hash_equals, nao ==: comparar com == vaza o
  segredo pelo tempo de resposta, um byte por vez.
- Responde 200 SEMPRE, mesmo em erro interno. Devolver erro faz a Meta
  reenviar em loop e acabar derrubando a inscricao do webhook.
- Idempotencia pelo unique em wamid, gravando antes de processar. A Meta
  reenvia o evento quando nao recebe 200 rapido; sem isso o cliente
  receberia o roteiro duas vezes.

O parser fica separado do endpoint porque o endpoint nao da para testar."
```

---

### Task 7: A máquina de estados

**Files:**
- Create: `lib/wa-motor.php`
- Test: `tests/test-wa-motor.php`

**Interfaces:**
- Consumes: `wa_db_*` (Task 4), `wa_send_*` (Task 5), `wa_match_roteiros`/`wa_slug_do_marcador` (Task 3), `wa_e164` (Task 2).
- Produces: `wa_processar(array $evento): string` devolve o nome da ação tomada (para teste e log). `wa_texto(string $chave, array $vars): string` monta o texto a partir de `po_wa_textos`. `wa_resposta_sim(string $t): ?bool` interpreta sim/não.

- [ ] **Step 1: Escrever o teste que falha**

Criar `tests/test-wa-motor.php`:

```php
<?php
require __DIR__ . '/../lib/wa-motor.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

/* Simulador: o banco vira um array em memoria e o envio vira uma lista.
   Nenhum teste toca a rede. */
$DB = ['po_wa_conversas' => [], 'po_wa_contatos' => [], 'po_leads' => [],
       'po_wa_textos' => [
           ['chave'=>'envio_pdf','texto'=>'Segue o roteiro completo do {roteiro}.'],
           ['chave'=>'perguntas','texto'=>'1. Tem disponibilidade? 2. Ja viajou em grupo?'],
           ['chave'=>'qualificado','texto'=>'Perfeito, {nome}.'],
           ['chave'=>'menu','texto'=>'Sobre qual viagem?'],
           ['chave'=>'sem_data','texto'=>'Entendo, {nome}.'],
       ]];
$ENVIADAS = [];

wa_motor_set_deps([
    'roteiros' => function () {
        return [['slug'=>'turquia','titulo'=>'Turquia com Antalia','pdf_url'=>'https://x/t.pdf','data_label'=>'10/05/27'],
                ['slug'=>'escandinavia','titulo'=>'O melhor da Escandinavia','pdf_url'=>'https://x/e.pdf','data_label'=>'02/06/27']];
    },
    'select' => function ($tabela, $query) use (&$DB) {
        if ($tabela === 'po_wa_textos') return $DB['po_wa_textos'];
        if (preg_match('/wa_id=eq\.([^&]+)/', $query, $m)) {
            $wa = urldecode($m[1]);
            return array_values(array_filter($DB[$tabela], fn($l) => ($l['wa_id'] ?? '') === $wa));
        }
        return $DB[$tabela] ?? [];
    },
    'insert' => function ($tabela, $linha) use (&$DB) {
        $linha['id'] = $tabela . '-' . count($DB[$tabela] ?? []);
        $DB[$tabela][] = $linha;
        return $linha;
    },
    'update' => function ($tabela, $query, $campos) use (&$DB) {
        preg_match('/wa_id=eq\.([^&]+)/', $query, $m);
        $wa = isset($m[1]) ? urldecode($m[1]) : null;
        foreach ($DB[$tabela] as $i => $l) {
            if ($wa === null || ($l['wa_id'] ?? '') === $wa) $DB[$tabela][$i] = array_merge($l, $campos);
        }
        return true;
    },
    'send_text' => function ($para, $texto) use (&$ENVIADAS) { $ENVIADAS[] = ['text', $para, $texto]; return ['ok'=>true,'wamid'=>'w'.count($ENVIADAS),'erro'=>null]; },
    'send_doc'  => function ($para, $url, $arq, $leg) use (&$ENVIADAS) { $ENVIADAS[] = ['doc', $para, $url, $leg]; return ['ok'=>true,'wamid'=>'w'.count($ENVIADAS),'erro'=>null]; },
    'send_list' => function ($para, $corpo, $botao, $itens) use (&$ENVIADAS) { $ENVIADAS[] = ['list', $para, count($itens)]; return ['ok'=>true,'wamid'=>'w'.count($ENVIADAS),'erro'=>null]; },
]);

$WA = '+5548996048882';
function ev($tipo, $texto, $extra = []) {
    global $WA;
    return array_merge(['tipo'=>$tipo,'wa_id'=>$WA,'wamid'=>'wamid.'.md5($texto.mt_rand()),
        'tipo_msg'=>'text','texto'=>$texto,'nome'=>'Maria','ad_id'=>null,'ctwa_clid'=>null,'ts'=>time()], $extra);
}

// --- 1. primeira mensagem com destino reconhecivel: manda PDF + perguntas
$acao = wa_processar(ev('mensagem', 'oi queria saber da Turquia'));
ok($acao === 'enviou_roteiro', "primeira mensagem com destino manda roteiro (deu: $acao)");
ok($ENVIADAS[0][0] === 'doc', 'mandou o PDF primeiro');
ok(strpos($ENVIADAS[0][2], 't.pdf') !== false, 'o PDF e o do roteiro certo');
ok($ENVIADAS[1][0] === 'text', 'depois o texto');
ok(strpos($ENVIADAS[1][2], 'disponibilidade') !== false, 'as duas perguntas vao junto, sem pedir licenca');
ok($DB['po_wa_conversas'][0]['estado'] === 'enviado_roteiro', 'estado avancou');
ok($DB['po_leads'][0]['status'] === 'novo', 'lead entra como novo');
ok($DB['po_leads'][0]['roteiro'] === 'Turquia com Antalia', 'lead guarda o roteiro');

// --- 2. sim para as duas perguntas: qualifica
$ENVIADAS = [];
$acao = wa_processar(ev('mensagem', 'tenho sim, e ja viajei em grupo pra Portugal'));
ok($acao === 'qualificou', "sim e sim qualifica (deu: $acao)");
ok($DB['po_wa_conversas'][0]['estado'] === 'qualificado', 'estado qualificado');
ok($DB['po_leads'][0]['status'] === 'atendimento', 'lead vai para atendimento');
ok($DB['po_leads'][0]['qualif_data'] === true, 'carimba disponibilidade');

// --- 3. o eco cala o robo naquele contato, para sempre
$ENVIADAS = [];
$acao = wa_processar(ev('eco', 'Bom dia Maria, aqui e a Simone'));
ok($acao === 'silenciou', "eco silencia (deu: $acao)");
ok($DB['po_wa_conversas'][0]['estado'] === 'humano', 'estado humano');
ok($ENVIADAS === [], 'o robo nao respondeu nada');

$acao = wa_processar(ev('mensagem', 'e quanto custa?'));
ok($acao === 'silenciado', "com humano no comando o robo continua calado (deu: $acao)");
ok($ENVIADAS === [], 'nada enviado mesmo com pergunta nova');

// --- 4. atalho de proposta, vindo do eco
$acao = wa_processar(ev('eco', '#proposta'));
ok($acao === 'proposta', "atalho #proposta move o funil (deu: $acao)");
ok($DB['po_leads'][0]['status'] === 'negociacao', 'lead em negociacao');
ok(!empty($DB['po_leads'][0]['proposta_at']), 'carimba proposta_at');

// --- 5. atalho de fechamento com valor
$acao = wa_processar(ev('eco', '#fechou 22900'));
ok($acao === 'venda', "atalho #fechou registra venda (deu: $acao)");
ok($DB['po_leads'][0]['status'] === 'venda', 'lead em venda');
ok((float) $DB['po_leads'][0]['venda'] === 22900.0, 'valor da venda gravado');
ok(!empty($DB['po_leads'][0]['venda_at']), 'carimba venda_at, que alimenta o ciclo');

// --- 6. PDF enviado por ela tambem marca proposta
$DB['po_wa_conversas'] = []; $DB['po_leads'] = []; $ENVIADAS = [];
wa_processar(ev('mensagem', 'me fala da escandinavia'));
wa_processar(ev('mensagem', 'sim, e ja viajei em grupo'));
$acao = wa_processar(ev('eco', 'proposta.pdf', ['tipo_msg' => 'document']));
ok($acao === 'proposta', "PDF enviado por ela marca proposta (deu: $acao)");

// --- 7. texto sem destino reconhecivel cai no menu
$DB['po_wa_conversas'] = []; $DB['po_leads'] = []; $ENVIADAS = [];
$acao = wa_processar(ev('mensagem', 'oi boa tarde'));
ok($acao === 'menu', "sem destino manda o menu (deu: $acao)");
ok($ENVIADAS[0][0] === 'list', 'mandou lista');
ok($ENVIADAS[0][2] === 2, 'com os dois roteiros ativos');

// --- 8. escolha no menu retoma o fluxo
$ENVIADAS = [];
$acao = wa_processar(ev('mensagem', 'turquia', ['tipo_msg' => 'list_reply']));
ok($acao === 'enviou_roteiro', "escolha no menu manda o roteiro (deu: $acao)");

// --- 9. nao tem data: desqualifica sem gastar o tempo dela
$DB['po_wa_conversas'] = []; $DB['po_leads'] = []; $ENVIADAS = [];
wa_processar(ev('mensagem', 'quero saber da turquia'));
$acao = wa_processar(ev('mensagem', 'nessa data nao consigo, infelizmente'));
ok($acao === 'desqualificou', "sem data desqualifica (deu: $acao)");
ok($DB['po_leads'][0]['status'] === 'perdido', 'lead vai para perdido');

// --- 10. anuncio define o roteiro sem perguntar nada
$DB['po_wa_conversas'] = []; $DB['po_leads'] = []; $ENVIADAS = [];
wa_motor_set_anuncios(['120210000' => 'escandinavia']);
$acao = wa_processar(ev('mensagem', 'oi', ['ad_id' => '120210000', 'ctwa_clid' => 'ARxyz']));
ok($acao === 'enviou_roteiro', "anuncio identifica o roteiro sem menu (deu: $acao)");
ok(strpos($ENVIADAS[0][2], 'e.pdf') !== false, 'mandou o PDF do roteiro do anuncio');
ok($DB['po_leads'][0]['ctwa_clid'] === 'ARxyz', 'atribuicao do anuncio gravada no lead');

// --- 11. marcador do link do site tem prioridade sobre o texto
$DB['po_wa_conversas'] = []; $DB['po_leads'] = []; $ENVIADAS = [];
$acao = wa_processar(ev('mensagem', 'Quero saber sobre a escandinavia [r:turquia]'));
ok(strpos($ENVIADAS[0][2], 't.pdf') !== false, 'marcador ganha do texto');

// --- 12. roteiro sem PDF nao pode travar: manda o link da pagina
$DB['po_wa_conversas'] = []; $DB['po_leads'] = []; $ENVIADAS = [];
wa_motor_set_deps(['roteiros' => function () { return [['slug'=>'turquia','titulo'=>'Turquia','pdf_url'=>null,'data_label'=>'10/05/27']]; }]);
$acao = wa_processar(ev('mensagem', 'quero saber da turquia'));
ok($acao === 'enviou_roteiro', 'roteiro sem PDF ainda responde');
ok($ENVIADAS[0][0] === 'text', 'sem PDF manda texto com o link');
ok(strpos($ENVIADAS[0][2], '/roteiros/turquia') !== false, 'o link e o da pagina do roteiro');

// --- interpretacao de sim e nao
ok(wa_resposta_sim('sim')                    === true,  'sim');
ok(wa_resposta_sim('Tenho sim!')             === true,  'tenho sim');
ok(wa_resposta_sim('claro, pode ser')        === true,  'claro');
ok(wa_resposta_sim('nao consigo nessa data') === false, 'nao');
ok(wa_resposta_sim('infelizmente nao')       === false, 'infelizmente nao');
ok(wa_resposta_sim('qual o valor?')          === null,  'pergunta nao e sim nem nao');

echo "test-wa-motor OK\n";
```

- [ ] **Step 2: Rodar e confirmar que falha**

Run: `php tests/test-wa-motor.php`
Expected: FAIL, arquivo não existe

- [ ] **Step 3: Implementar o motor**

Criar `lib/wa-motor.php`:

```php
<?php
/* ============================================================
   A maquina de estados da conversa.

   Estados: novo -> aguardando_roteiro -> enviado_roteiro -> qualificado
            | desqualificado | encerrado | humano

   REGRA MAIS IMPORTANTE DO ARQUIVO: o eco (o que a cliente digita no
   celular) silencia o robo naquele contato para sempre. Sem isso, robo e
   humana falam por cima uma da outra com o cliente na linha.

   Toda dependencia externa (banco, envio, catalogo) entra por
   wa_motor_set_deps, para o teste rodar sem tocar a rede.
============================================================ */

require_once __DIR__ . '/po-data.php';
require_once __DIR__ . '/wa-db.php';
require_once __DIR__ . '/wa-send.php';
require_once __DIR__ . '/wa-roteiro.php';
require_once __DIR__ . '/wa-fone.php';

const WA_SITE = 'https://pereiraoliveiraturismo.com.br';

function wa_motor_set_deps($d) {
    $GLOBALS['WA_DEPS'] = array_merge($GLOBALS['WA_DEPS'] ?? [], $d);
}
function wa_motor_set_anuncios($mapa) { $GLOBALS['WA_ANUNCIOS'] = $mapa; }

function wa_dep($nome) {
    if (isset($GLOBALS['WA_DEPS'][$nome])) return $GLOBALS['WA_DEPS'][$nome];
    $padrao = [
        'roteiros'  => 'po_fetch_roteiros',
        'select'    => 'wa_db_select',
        'insert'    => 'wa_db_insert',
        'update'    => 'wa_db_update',
        'send_text' => 'wa_send_text',
        'send_doc'  => 'wa_send_document',
        'send_list' => 'wa_send_list',
    ];
    return $padrao[$nome];
}
function wa_call($nome, ...$args) { return call_user_func_array(wa_dep($nome), $args); }

/* ---------- textos ---------- */
function wa_texto($chave, $vars = []) {
    $linhas = wa_call('select', 'po_wa_textos', 'chave=eq.' . rawurlencode($chave));
    $t = $linhas[0]['texto'] ?? '';
    foreach ($vars as $k => $v) $t = str_replace('{' . $k . '}', (string) $v, $t);
    // Placeholder sem valor nao pode vazar para o cliente.
    return trim(preg_replace('/\{[a-z_]+\}/', '', $t));
}

/* ---------- interpretacao de sim/nao ----------
   Devolve true, false, ou null quando a mensagem nao e nenhum dos dois
   (uma pergunta, por exemplo). Null nunca avanca o funil: preferimos
   deixar o lead parado a classifica-lo errado. */
function wa_resposta_sim($texto) {
    $t = wa_normaliza($texto);
    if (preg_match('/\b(nao|nunca|infelizmente|impossivel|nenhuma)\b/', $t)) return false;
    if (preg_match('/\b(sim|claro|tenho|posso|consigo|pode ser|isso|certo|perfeito|ja fui|ja viajei|ok)\b/', $t)) return true;
    return null;
}

/* ---------- estado ---------- */
function wa_conversa($wa_id) {
    $l = wa_call('select', 'po_wa_conversas', 'wa_id=eq.' . rawurlencode($wa_id));
    return $l[0] ?? null;
}
function wa_conversa_set($wa_id, $campos) {
    $campos['updated_at'] = gmdate('c');
    if (wa_conversa($wa_id)) {
        wa_call('update', 'po_wa_conversas', 'wa_id=eq.' . rawurlencode($wa_id), $campos);
    } else {
        wa_call('insert', 'po_wa_conversas', array_merge(['wa_id' => $wa_id], $campos));
    }
}
function wa_lead($wa_id) {
    $l = wa_call('select', 'po_leads', 'wa_id=eq.' . rawurlencode($wa_id));
    return $l[0] ?? null;
}
function wa_lead_set($wa_id, $campos) {
    if (wa_lead($wa_id)) {
        wa_call('update', 'po_leads', 'wa_id=eq.' . rawurlencode($wa_id), $campos);
    } else {
        wa_call('insert', 'po_leads', array_merge(['wa_id' => $wa_id], $campos));
    }
}

function wa_acha_roteiro($slug, $roteiros) {
    foreach ($roteiros as $r) if (($r['slug'] ?? '') === $slug) return $r;
    return null;
}

/* ---------- envio do roteiro ---------- */
function wa_envia_roteiro($wa_id, $r, $nome) {
    $legenda = wa_texto('envio_pdf', ['roteiro' => $r['titulo'] ?? '', 'nome' => $nome]);

    if (!empty($r['pdf_url'])) {
        $arquivo = ($r['slug'] ?? 'roteiro') . '.pdf';
        wa_call('send_doc', $wa_id, $r['pdf_url'], $arquivo, $legenda);
    } else {
        // Sem PDF a conversa nao pode morrer: manda o link da pagina, que
        // sempre existe porque o roteiro esta ativo no banco.
        wa_call('send_text', $wa_id, $legenda . "\n\n" . WA_SITE . '/roteiros/' . ($r['slug'] ?? ''));
    }

    // As duas perguntas vao na sequencia, sem pedir licenca: e exatamente
    // como a cliente faz na mao.
    wa_call('send_text', $wa_id, wa_texto('perguntas', [
        'nome'    => $nome,
        'roteiro' => $r['titulo'] ?? '',
        'data'    => $r['data_label'] ?? 'na data prevista',
    ]));

    wa_conversa_set($wa_id, [
        'estado'           => 'enviado_roteiro',
        'roteiro_slug'     => $r['slug'] ?? null,
        'aguardando_desde' => gmdate('c'),
        'lembrete_at'      => null,
    ]);
    wa_lead_set($wa_id, ['roteiro' => $r['titulo'] ?? '']);
    return 'enviou_roteiro';
}

/* ---------- atalhos digitados por ela ---------- */
function wa_atalho($wa_id, $texto) {
    $t = trim(mb_strtolower($texto, 'UTF-8'));

    if (strpos($t, '#proposta') === 0) {
        wa_lead_set($wa_id, ['status' => 'negociacao', 'proposta_at' => gmdate('c')]);
        return 'proposta';
    }
    if (strpos($t, '#fechou') === 0) {
        $campos = ['status' => 'venda', 'venda_at' => gmdate('c')];
        // "#fechou 22900" ou "#fechou 22.900,50"
        if (preg_match('/#fechou\s+([\d.,]+)/', $t, $m)) {
            $n = str_replace(['.', ','], ['', '.'], $m[1]);
            if (is_numeric($n)) $campos['venda'] = (float) $n;
        }
        wa_lead_set($wa_id, $campos);
        return 'venda';
    }
    if (strpos($t, '#perdeu') === 0) {
        wa_lead_set($wa_id, ['status' => 'perdido']);
        return 'perdido';
    }
    return null;
}

/* ---------- ponto de entrada ---------- */
function wa_processar($ev) {
    $wa_id = $ev['wa_id'];
    $texto = (string) ($ev['texto'] ?? '');
    $nome  = $ev['nome'] ?? '';

    if ($ev['tipo'] === 'status') return 'status';

    /* ----- eco: ela falou pelo celular ----- */
    if ($ev['tipo'] === 'eco') {
        $acao = wa_atalho($wa_id, $texto);
        if ($acao) return $acao;

        // Documento numa conversa ja qualificada e proposta, com alta
        // confianca no fluxo dela. Cobre o esquecimento do atalho.
        $conv = wa_conversa($wa_id);
        if (($ev['tipo_msg'] ?? '') === 'document'
            && in_array($conv['estado'] ?? '', ['qualificado', 'humano'], true)) {
            wa_lead_set($wa_id, ['status' => 'negociacao', 'proposta_at' => gmdate('c')]);
            return 'proposta';
        }

        // Qualquer outra fala dela e handoff: o robo se cala aqui.
        if (($conv['estado'] ?? '') !== 'humano') {
            wa_conversa_set($wa_id, ['estado' => 'humano', 'silenciado_at' => gmdate('c')]);
            return 'silenciou';
        }
        return 'ja_humano';
    }

    /* ----- mensagem do cliente ----- */
    $conv = wa_conversa($wa_id);

    // Conversa entregue a humana nunca volta para o robo.
    if (($conv['estado'] ?? '') === 'humano') return 'silenciado';

    $roteiros = wa_call('roteiros');

    // Primeiro contato: cria lead e contato.
    if (!$conv) {
        wa_lead_set($wa_id, [
            'nome'      => $nome,
            'telefone'  => $wa_id,
            'status'    => 'novo',
            'origem'    => $ev['ad_id'] ? 'pago' : 'whatsapp',
            'ad_id'     => $ev['ad_id'],
            'ctwa_clid' => $ev['ctwa_clid'],
        ]);
        wa_conversa_set($wa_id, ['estado' => 'novo']);
        $conv = wa_conversa($wa_id);
    }

    // Já mandou o roteiro: o que chega agora e resposta das perguntas.
    if (($conv['estado'] ?? '') === 'enviado_roteiro') {
        $r = wa_resposta_sim($texto);
        if ($r === false) {
            wa_conversa_set($wa_id, ['estado' => 'desqualificado']);
            wa_lead_set($wa_id, ['status' => 'perdido', 'qualif_data' => false]);
            wa_call('send_text', $wa_id, wa_texto('sem_data', ['nome' => $nome]));
            return 'desqualificou';
        }
        if ($r === true) {
            wa_conversa_set($wa_id, ['estado' => 'qualificado']);
            wa_lead_set($wa_id, [
                'status'       => 'atendimento',
                'qualif_data'  => true,
                'qualif_grupo' => true,
                'qualif_at'    => gmdate('c'),
            ]);
            wa_call('send_text', $wa_id, wa_texto('qualificado', ['nome' => $nome]));
            return 'qualificou';
        }
        // Nem sim nem nao (uma pergunta, por exemplo): nao classifica
        // errado e nao responde bobagem. Deixa para a humana.
        return 'aguardando';
    }

    // Identificacao do roteiro, da fonte mais confiavel para a menos.
    $slug = null;

    if (!empty($ev['ad_id'])) {
        $mapa = $GLOBALS['WA_ANUNCIOS'] ?? null;
        if ($mapa === null) {
            $linhas = wa_call('select', 'po_wa_anuncios', 'ad_id=eq.' . rawurlencode($ev['ad_id']));
            $slug = $linhas[0]['roteiro_slug'] ?? null;
        } else {
            $slug = $mapa[$ev['ad_id']] ?? null;
        }
    }
    if (!$slug) $slug = wa_slug_do_marcador($texto);
    if (!$slug && ($ev['tipo_msg'] ?? '') === 'list_reply') $slug = trim($texto);
    if (!$slug) {
        $achados = wa_match_roteiros($texto, $roteiros);
        if (count($achados) === 1) $slug = $achados[0];
    }

    if ($slug) {
        $r = wa_acha_roteiro($slug, $roteiros);
        if ($r) return wa_envia_roteiro($wa_id, $r, $nome);
    }

    // Nada identificado (ou ambiguo): o menu resolve sem chutar.
    $itens = [];
    foreach ($roteiros as $r) {
        $itens[] = ['id' => $r['slug'], 'titulo' => $r['titulo'], 'descricao' => $r['data_label'] ?? ''];
    }
    wa_call('send_list', $wa_id, wa_texto('menu', ['nome' => $nome]), 'Ver roteiros', $itens);
    wa_conversa_set($wa_id, ['estado' => 'aguardando_roteiro', 'aguardando_desde' => gmdate('c')]);
    return 'menu';
}
```

- [ ] **Step 4: Rodar e confirmar que passa**

Run: `php tests/test-wa-motor.php`
Expected: `test-wa-motor OK`

- [ ] **Step 5: Rodar a suíte inteira**

Run: `php tests/run.php`
Expected: `Todos os testes passaram`

- [ ] **Step 6: Commit**

```bash
git add lib/wa-motor.php tests/test-wa-motor.php
git commit -m "feat(wa): maquina de estados da conversa

O coracao do robo: identifica o roteiro, manda o PDF com as duas
perguntas na sequencia (sem pedir licenca, como ela faz na mao), le a
resposta e move o funil.

A regra que mais importa: o eco silencia o robo naquele contato para
sempre. Sem isso, robo e humana falam por cima uma da outra com o cliente
na linha, que e o pior modo de falha possivel deste sistema.

wa_resposta_sim devolve null quando a mensagem nao e sim nem nao, e null
nunca avanca o funil: preferimos o lead parado a classificado errado.
Roteiro sem PDF manda o link da pagina em vez de travar."
```

---

### Task 8: Lembrete e encerramento por silêncio

**Files:**
- Create: `wa-cron.php`
- Create: `lib/wa-timeout.php`
- Test: `tests/test-wa-timeout.php`

**Interfaces:**
- Consumes: `wa_db_select`, `wa_db_update`, `wa_send_text`, `wa_texto`.
- Produces: `wa_varre_timeouts(int $agora): array` devolve `['lembretes'=>int, 'encerrados'=>int]`.

- [ ] **Step 1: Escrever o teste que falha**

Criar `tests/test-wa-timeout.php`:

```php
<?php
require __DIR__ . '/../lib/wa-timeout.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

$agora = strtotime('2026-09-11 12:00:00 UTC');
function h($agora, $horas) { return gmdate('c', $agora - $horas * 3600); }

$CONVERSAS = [
    // esperando ha 2h: cedo demais para incomodar
    ['wa_id'=>'+5548900000001','estado'=>'enviado_roteiro','aguardando_desde'=>h($agora,2),  'lembrete_at'=>null],
    // 25h em silencio: merece o lembrete
    ['wa_id'=>'+5548900000002','estado'=>'enviado_roteiro','aguardando_desde'=>h($agora,25), 'lembrete_at'=>null],
    // ja levou lembrete ha 25h (50h de silencio): encerra
    ['wa_id'=>'+5548900000003','estado'=>'enviado_roteiro','aguardando_desde'=>h($agora,50), 'lembrete_at'=>h($agora,25)],
    // ja levou lembrete ha 2h: espera mais
    ['wa_id'=>'+5548900000004','estado'=>'enviado_roteiro','aguardando_desde'=>h($agora,27), 'lembrete_at'=>h($agora,2)],
    // com a humana: o robo nao toca, por mais antigo que seja
    ['wa_id'=>'+5548900000005','estado'=>'humano','aguardando_desde'=>h($agora,300),'lembrete_at'=>null],
    // ja qualificado: idem
    ['wa_id'=>'+5548900000006','estado'=>'qualificado','aguardando_desde'=>h($agora,300),'lembrete_at'=>null],
];
$ENVIADAS = []; $UPDATES = [];

wa_timeout_set_deps([
    'select'    => function ($t, $q) use (&$CONVERSAS) { return $t === 'po_wa_textos' ? [['texto'=>'Oi {nome}, viu o roteiro?']] : $CONVERSAS; },
    'update'    => function ($t, $q, $c) use (&$UPDATES) { $UPDATES[] = [$t, $q, $c]; return true; },
    'send_text' => function ($para, $txt) use (&$ENVIADAS) { $ENVIADAS[] = $para; return ['ok'=>true,'wamid'=>'w','erro'=>null]; },
]);

$r = wa_varre_timeouts($agora);

ok($r['lembretes'] === 1, 'exatamente um lembrete (deu: ' . $r['lembretes'] . ')');
ok($ENVIADAS === ['+5548900000002'], 'lembrete so para quem esta em silencio ha mais de 24h');

ok($r['encerrados'] === 1, 'exatamente um encerrado (deu: ' . $r['encerrados'] . ')');
$perdidos = array_values(array_filter($UPDATES, fn($u) => ($u[2]['status'] ?? '') === 'perdido'));
ok(count($perdidos) === 1, 'um lead marcado perdido');
ok(strpos($perdidos[0][1], '5548900000003') !== false, 'o perdido e o de 50h');

// A conversa com a humana nao pode ser tocada nunca, e este e o teste que
// impede o cron de encerrar atendimento em andamento.
foreach ($UPDATES as $u) {
    ok(strpos($u[1], '5548900000005') === false, 'cron nao toca conversa com humano');
    ok(strpos($u[1], '5548900000006') === false, 'cron nao toca conversa qualificada');
}
ok(!in_array('+5548900000005', $ENVIADAS, true), 'nao manda lembrete em conversa humana');

echo "test-wa-timeout OK\n";
```

- [ ] **Step 2: Rodar e confirmar que falha**

Run: `php tests/test-wa-timeout.php`
Expected: FAIL, arquivo não existe

- [ ] **Step 3: Implementar**

Criar `lib/wa-timeout.php`:

```php
<?php
/* ============================================================
   Lembrete de 24h e encerramento de 48h.

   So mexe em conversa que esta ESPERANDO resposta. Conversa entregue a
   humana ou ja qualificada nunca e tocada: encerrar atendimento em
   andamento por relogio seria pior do que nao ter cron nenhum.
============================================================ */

require_once __DIR__ . '/wa-db.php';
require_once __DIR__ . '/wa-send.php';
require_once __DIR__ . '/wa-motor.php';

const WA_HORAS_LEMBRETE = 24;
const WA_HORAS_ENCERRA  = 24; // contadas DEPOIS do lembrete

function wa_timeout_set_deps($d) { $GLOBALS['WA_TO_DEPS'] = array_merge($GLOBALS['WA_TO_DEPS'] ?? [], $d); }
function wa_to_call($nome, ...$args) {
    $padrao = ['select' => 'wa_db_select', 'update' => 'wa_db_update', 'send_text' => 'wa_send_text'];
    $f = $GLOBALS['WA_TO_DEPS'][$nome] ?? $padrao[$nome];
    return call_user_func_array($f, $args);
}

function wa_varre_timeouts($agora = null) {
    $agora = $agora ?: time();
    $lembretes = 0; $encerrados = 0;

    $abertas = wa_to_call('select', 'po_wa_conversas',
        'estado=in.(enviado_roteiro,aguardando_roteiro)&limit=500');

    foreach ($abertas as $c) {
        // Guarda dupla: mesmo que a query mude, estado fora da lista nao entra.
        if (!in_array($c['estado'] ?? '', ['enviado_roteiro', 'aguardando_roteiro'], true)) continue;

        $desde = strtotime($c['aguardando_desde'] ?? '') ?: null;
        if (!$desde) continue;
        $wa = $c['wa_id'];
        $lembrete = !empty($c['lembrete_at']) ? strtotime($c['lembrete_at']) : null;

        if (!$lembrete) {
            if (($agora - $desde) >= WA_HORAS_LEMBRETE * 3600) {
                $nome = '';
                wa_to_call('send_text', $wa, wa_texto('lembrete', ['nome' => $nome]));
                wa_to_call('update', 'po_wa_conversas', 'wa_id=eq.' . rawurlencode($wa),
                    ['lembrete_at' => gmdate('c', $agora)]);
                $lembretes++;
            }
            continue;
        }

        if (($agora - $lembrete) >= WA_HORAS_ENCERRA * 3600) {
            wa_to_call('update', 'po_wa_conversas', 'wa_id=eq.' . rawurlencode($wa),
                ['estado' => 'encerrado']);
            wa_to_call('update', 'po_leads', 'wa_id=eq.' . rawurlencode($wa),
                ['status' => 'perdido']);
            $encerrados++;
        }
    }

    return ['lembretes' => $lembretes, 'encerrados' => $encerrados];
}
```

Criar `wa-cron.php` na raiz:

```php
<?php
/* ============================================================
   Cron do cPanel, de hora em hora:
     /usr/local/bin/php /home/USUARIO/public_html/wa-cron.php

   Protegido por segredo na query porque o arquivo fica no docroot e
   qualquer um poderia dispara-lo:
     wa-cron.php?k=<WA_CRON_KEY>
   Pela linha de comando (o cron real) o segredo nao e exigido.
============================================================ */

require_once __DIR__ . '/lib/wa-timeout.php';

$cli = (php_sapi_name() === 'cli');
if (!$cli) {
    $cfg = po_config();
    $k = $_GET['k'] ?? '';
    if (!hash_equals((string) ($cfg['WA_CRON_KEY'] ?? ''), (string) $k)) {
        http_response_code(403);
        exit;
    }
    header('Content-Type: application/json');
}

$r = wa_varre_timeouts();
error_log('wa-cron: ' . json_encode($r));
echo json_encode($r);
```

- [ ] **Step 4: Rodar e confirmar que passa**

Run: `php tests/test-wa-timeout.php`
Expected: `test-wa-timeout OK`

- [ ] **Step 5: Acrescentar o segredo do cron ao exemplo de config**

Em `config.local.example.php`, na seção do WhatsApp:

```php
$WA_CRON_KEY = '';  // string inventada por nos, protege wa-cron.php pela web
```

- [ ] **Step 6: Rodar a suíte inteira**

Run: `php tests/run.php`
Expected: `Todos os testes passaram`

- [ ] **Step 7: Commit**

```bash
git add wa-cron.php lib/wa-timeout.php tests/test-wa-timeout.php config.local.example.php
git commit -m "feat(wa): lembrete de 24h e encerramento de 48h

Lead que some vira perdido sozinho, com um lembrete no meio do caminho em
vez de encerrar direto.

O cron so toca conversa que esta ESPERANDO resposta. Conversa com a
humana ou ja qualificada nunca e tocada, com guarda dupla (na query e no
laco): encerrar atendimento em andamento por relogio seria pior do que
nao ter cron nenhum. O teste trava exatamente isso."
```

---

## Self-review

**Cobertura da spec:** seção 4 (modelo de dados) → Task 1. Seção 5 (máquina de estados, identificação, silêncio, atalhos) → Tasks 3 e 7. Seção 6 (webhook, assinatura, idempotência) → Task 6. Seção 7 (etapas do funil) → Task 7, nos `wa_lead_set`. Seção 10 (segurança) → Tasks 4 e 6. Timeouts da seção 5 → Task 8.

**Fora deste plano, por decisão de escopo:** seções 8 e 9 (transmissão e importador) vão para o plano 3; o kanban e a aba de conversa vão para o plano 2; a conexão real com a Meta e a homologação entram no fim, depois da verificação sair.

**Consistência de tipos:** `wa_e164` devolve `?string` com `+` e é usada como chave em todo lugar; `wa_send_*` devolvem sempre `['ok','wamid','erro']`; `wa_parse_evento` devolve sempre as mesmas dez chaves, inclusive quando nulas, para o motor nunca ter que testar existência.
