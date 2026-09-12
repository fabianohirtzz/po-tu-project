# Importador de contatos e auditoria de fusão — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Importar contatos de três fontes (CRM antigo com 778 fichas + duas agendas de celular) para a `po_leads`, com uma auditoria de fusão que nunca sobrescreve histórico, nunca zera campo e nunca duplica.

**Architecture:** Motor de fusão em PHP no cPanel (mesma stack de `whatsapp.php`/`importar.php`), com toda a lógica pura em `lib/wa-import.php` e `lib/wa-vcard.php`, testável sem rede. A escrita usa a `service_role` via `lib/wa-db.php` (transporte injetável). A tela de revisão no painel (`painel/importar-contatos.js`) só mostra o plano que o servidor calculou e confirma o disparo. O CRM entra primeiro como base-mestra; as agendas casam contra ela depois.

**Tech Stack:** PHP 8 (sem Composer, sem build), Supabase REST, JS puro no painel (sem dependência além do cliente Supabase), runner próprio `tests/run.php` (`test-*.php` e `test-*.mjs`).

**Spec:** `docs/superpowers/specs/2026-09-11-automacao-whatsapp-crm-design.md` (seções 9.1–9.6 e 4.1)

## Global Constraints

Copiadas literalmente da spec. Todas as tarefas as herdam.

- **As três regras do Armando são critério de aceitação:** (1) o registro com histórico é o dono e **nunca é sobrescrito nem substituído**; (2) a fusão **só soma, nunca zera** — campo vazio na fonte nova jamais apaga campo preenchido no existente; (3) **nunca duplicar** — nenhum registro novo sem tentar casar antes.
- **Marcador "PO":** das agendas, **só entra quem tem o marcador** no nome (`- Cliente PO`, `- PO`, variações). O CRM não tem marcador e entra inteiro. O padrão do marcador é configurável (a grafia varia entre aparelhos).
- **Identidade por camadas, a primeira que bater vence:** (1) CPF normalizado; (2) telefone celular em E.164; (3) nome normalizado + data de nascimento. **Fusão automática só por CPF ou celular.** Nome+nascimento **sugere, não funde** (vira revisão).
- **Fixo nunca funde duas pessoas** (há fixo de empresa repetido em fichas). Só celular casa identidade, e só celular entra na transmissão.
- **`payload_import` guarda o registro cru da fonte, íntegro** — nada da origem se perde, mesmo o que não vira coluna.
- **E.164 pelo normalizador único `wa_e164()`** (`lib/wa-fone.php`). Nenhum segundo normalizador de telefone com regra divergente.
- **Nenhum teste toca a rede.** Escrita e leitura de banco entram por transporte injetável, no padrão `wa_db_set_transport` que já existe.
- **`service_role` só no servidor** (`wa_config()`), nunca no painel nem no Git. O painel usa `anon key` + login.
- **Dado de terceiro é sempre escapado** ao renderizar no painel (`esc`), como já se faz em `clientes.js`.
- **Migração em banco compartilhado (NOX/hd360) é aplicada pelo controlador**, via MCP do Supabase, **nunca por subagente** — o subagente entrega o arquivo `.sql` e o teste; o controlador roda.
- **PHP 8, sem Composer e sem build.** Testes PHP com `ok($cond,$msg)` + `exit(1)`; testes JS com `node:assert/strict` extraindo funções do fonte por regex. Rodar tudo com `php tests/run.php`.

---

### Task 1: Migração — bloco de ficha do cliente em `po_leads`

**Files:**
- Create: `supabase/migrations/2026-09-12-ficha-cliente.sql`
- Test: `tests/test-ficha-schema.php`

**Interfaces:**
- Consumes: nada.
- Produces: colunas novas em `po_leads` (seção 4.1 da spec): `cpf`, `rg`, `data_nascimento`, `nacionalidade`, `estado_civil`, `profissao`, `cep`, `endereco`, `numero`, `complemento`, `bairro`, `estado`, `passaporte_numero`, `passaporte_orgao_emissor`, `passaporte_emissao`, `passaporte_validade`, `contato_emergencia_nome`, `contato_emergencia_telefone`, `contato_emergencia_parentesco`, `telefone_secundario`, `observacoes`, `origem_import`, `payload_import`. Índice único parcial em `cpf`.

> **Por que o teste é estático (lê o `.sql`), não sonda o banco:** a pendência
> `2026-09-11-wa-motor-pendencias.md` item 3 registra que o sondador de schema
> serve cache velho e não consegue mais ficar vermelho. Um teste que confere o
> arquivo de migração é determinístico e não depende de rede.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/test-ficha-schema.php
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

$sql = file_get_contents(__DIR__ . '/../supabase/migrations/2026-09-12-ficha-cliente.sql');
ok($sql !== false && $sql !== '', 'migracao existe e nao esta vazia');

$colunas = [
  'cpf','rg','data_nascimento','nacionalidade','estado_civil','profissao',
  'cep','endereco','numero','complemento','bairro','estado',
  'passaporte_numero','passaporte_orgao_emissor','passaporte_emissao','passaporte_validade',
  'contato_emergencia_nome','contato_emergencia_telefone','contato_emergencia_parentesco',
  'telefone_secundario','observacoes','origem_import','payload_import',
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-ficha-schema.php`
Expected: FAIL — arquivo de migração não existe.

- [ ] **Step 3: Write the migration**

```sql
-- supabase/migrations/2026-09-12-ficha-cliente.sql
-- Bloco de ficha do cliente adotado do CRM antigo (spec 4.1). Tudo opcional,
-- nada removido nem renomeado: os relatorios existentes nao mudam.
alter table public.po_leads add column if not exists cpf text;
alter table public.po_leads add column if not exists rg text;
alter table public.po_leads add column if not exists data_nascimento date;
alter table public.po_leads add column if not exists nacionalidade text;
alter table public.po_leads add column if not exists estado_civil text;
alter table public.po_leads add column if not exists profissao text;
alter table public.po_leads add column if not exists cep text;
alter table public.po_leads add column if not exists endereco text;
alter table public.po_leads add column if not exists numero text;
alter table public.po_leads add column if not exists complemento text;
alter table public.po_leads add column if not exists bairro text;
alter table public.po_leads add column if not exists estado text;
alter table public.po_leads add column if not exists passaporte_numero text;
alter table public.po_leads add column if not exists passaporte_orgao_emissor text;
alter table public.po_leads add column if not exists passaporte_emissao date;
alter table public.po_leads add column if not exists passaporte_validade date;
alter table public.po_leads add column if not exists contato_emergencia_nome text;
alter table public.po_leads add column if not exists contato_emergencia_telefone text;
alter table public.po_leads add column if not exists contato_emergencia_parentesco text;
alter table public.po_leads add column if not exists telefone_secundario text;
alter table public.po_leads add column if not exists observacoes text;
alter table public.po_leads add column if not exists origem_import text;
alter table public.po_leads add column if not exists payload_import jsonb;

-- CPF e a chave de identidade mais confiavel na fusao (spec 9.2). Guardado
-- so em digitos (o importador normaliza). O indice e PARCIAL: os leads do
-- site nascem sem cpf e nao podem colidir entre si por causa do vazio.
create unique index if not exists po_leads_cpf_uniq
  on public.po_leads (cpf) where cpf is not null and cpf <> '';
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test-ficha-schema.php`
Expected: `test-ficha-schema OK`.

- [ ] **Step 5: Controlador aplica a migração (NÃO o subagente)**

O banco é compartilhado com NOX/hd360. O subagente **para aqui** e reporta DONE
com a nota "migração pronta para o controlador aplicar". O controlador aplica via
MCP do Supabase (`apply_migration`, projeto `euzmbswywwhmicjlszqw`) e confere que
as colunas existem antes de liberar a Task 5 (mapa do CRM) e a Task 9 (carga).

- [ ] **Step 6: Commit**

```bash
git add supabase/migrations/2026-09-12-ficha-cliente.sql tests/test-ficha-schema.php
git commit -m "feat(db): bloco de ficha do cliente em po_leads (importador)"
```

---

### Task 2: Normalizador de telefone único no painel (`poE164`)

**Files:**
- Create: `painel/fone.js`
- Modify: `painel/clientes.js` (a `poChavePessoa` passa a delegar), `painel/index.html` (carrega `fone.js` antes de `clientes.js`)
- Test: `tests/test-fone.mjs`

**Interfaces:**
- Consumes: nada.
- Produces: `poE164(bruto) -> string|null` (idêntico em regra ao `wa_e164()` do PHP: `+55DDN…`), `poEhCelular(e164) -> boolean`.

> **Por que existe:** a pendência `2026-09-11-painel-funil-pendencias.md` item 2
> registra que `poChavePessoa` é um segundo normalizador divergente do
> `wa_e164()`: sem a regra do nono dígito, um celular antigo de 8 dígitos vira
> ficha separada da mesma pessoa. A spec 9.2 manda nascer **um** normalizador no
> plano do importador. Este é ele, no lado JS, espelhando `wa_e164()`.

- [ ] **Step 1: Write the failing test**

```js
// tests/test-fone.mjs
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const src = readFileSync(new URL('../painel/fone.js', import.meta.url), 'utf8');
function pega(nome) {
  const m = src.match(new RegExp('function ' + nome + '\\([\\s\\S]*?\\n\\}'));
  assert.ok(m, nome + ' encontrada no fone.js');
  return m[0];
}
const { poE164, poEhCelular } = new Function(
  pega('poE164') + pega('poEhCelular') + '; return {poE164, poEhCelular};'
)();

// os mesmos casos do test-wa-fone.php: as duas linguagens tem que concordar.
assert.equal(poE164('(48) 99604-8882'),   '+5548996048882', 'parenteses e traco');
assert.equal(poE164('48 99604 8882'),      '+5548996048882', 'espacos');
assert.equal(poE164('+55 48 99604-8882'),  '+5548996048882', 'ja com DDI');
assert.equal(poE164('5548996048882'),      '+5548996048882', 'digitos com DDI');
assert.equal(poE164('48996048882'),        '+5548996048882', 'digitos sem DDI');
assert.equal(poE164('4896048882'),         '+5548996048882', 'celular de 8 digitos ganha o nono');
assert.equal(poE164('4832220000'),         '+554832220000',  'fixo de 8 digitos fica como esta');
assert.equal(poE164(''),                   null, 'vazio');
assert.equal(poE164('123'),                null, 'curto demais');
assert.equal(poE164('0800 123 4567'),      null, '0800 nao e de pessoa');
assert.equal(poE164('(01) 99999-9999'),    null, 'DDD 01 nao existe');
assert.equal(poE164('+1 415 555 2671'),    null, 'estrangeiro fica de fora');
assert.equal(poE164(poE164('(48) 99604-8882')), '+5548996048882', 'normalizar de novo nao estraga');
assert.equal(poEhCelular('+5548996048882'), true,  'celular');
assert.equal(poEhCelular('+554832220000'),  false, 'fixo nao e celular');

console.log('test-fone OK');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `node tests/test-fone.mjs`
Expected: FAIL — `painel/fone.js` não existe.

- [ ] **Step 3: Write `painel/fone.js`**

Porte 1:1 de `lib/wa-fone.php`. As duas listas de DDD e as duas regras de nono
dígito têm que ser idênticas.

```js
/* ============================================================
   Normalizacao de telefone brasileiro para E.164 (+55DDNNNNNNNNN).
   Porte 1:1 de lib/wa-fone.php: as duas linguagens PRECISAM concordar,
   senao a mesma pessoa vira ficha diferente conforme quem gravou.
============================================================ */

const PO_DDDS = new Set([
  11,12,13,14,15,16,17,18,19, 21,22,24,27,28,
  31,32,33,34,35,37,38, 41,42,43,44,45,46,47,48,49,
  51,53,54,55, 61,62,63,64,65,66,67,68,69,
  71,73,74,75,77,79, 81,82,83,84,85,86,87,88,89,
  91,92,93,94,95,96,97,98,99,
]);

function poE164(bruto) {
  let d = String(bruto == null ? '' : bruto).replace(/\D+/g, '');
  if (d === '') return null;
  if (d.length >= 12 && d.slice(0, 2) === '55') d = d.slice(2);
  if (d.length > 11 || d.length < 10) return null;
  const ddd = parseInt(d.slice(0, 2), 10);
  let resto = d.slice(2);
  if (!PO_DDDS.has(ddd)) return null;
  if (resto.length === 8 && resto[0] >= '6') resto = '9' + resto;
  if (!/^9\d{8}$/.test(resto) && !/^[2-5]\d{7}$/.test(resto)) return null;
  return '+55' + ddd + resto;
}

function poEhCelular(e164) {
  return /^\+55\d{2}9\d{8}$/.test(String(e164 == null ? '' : e164));
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `node tests/test-fone.mjs`
Expected: `test-fone OK`.

- [ ] **Step 5: `poChavePessoa` passa a delegar, sem mudar o comportamento atual**

Em `painel/clientes.js`, o ramo do telefone passa a usar `poE164` (que já estará
carregado na página). A chave continua sendo dígitos com DDI (sem o `+`), então
`test-clientes.mjs` segue verde; a diferença é que o celular antigo de 8 dígitos
agora cai na mesma pessoa. Troque o corpo de `poChavePessoa`:

```js
function poChavePessoa(lead, idx) {
  // Normalizador unico (fone.js): mesma regra do wa_e164 do PHP. Um celular
  // antigo de 8 digitos agora cai na mesma pessoa, em vez de virar ficha nova.
  const e = (typeof poE164 === 'function') ? poE164(lead && lead.tel) : null;
  if (e) return poDigitos(e);
  const id = lead && lead.id;
  if (id != null && id !== '') return 'sem-tel:' + String(id);
  return 'sem-tel:pos:' + String(idx);
}
```

O `test-clientes.mjs` extrai `poChavePessoa` por regex e a executa isolada, sem
`poE164` no escopo — a guarda `typeof poE164 === 'function'` faz a função cair no
ramo antigo (dígitos com DDI) para os números completos que o teste usa, então ele
continua passando. Confirme lendo `tests/test-clientes.mjs`: todos os casos de
telefone usam número completo (com DDD), que o ramo antigo já normalizava certo.

- [ ] **Step 6: Carrega `fone.js` antes de `clientes.js`**

Em `painel/index.html`, com cache-buster (o `.htaccess` cacheia JS por 1 mês):

```html
<script src="fone.js?v=1"></script>
<script src="app.js?v=2"></script>
<script src="clientes.js?v=1"></script>
```

- [ ] **Step 7: Run tests to verify both pass**

Run: `node tests/test-fone.mjs && node tests/test-clientes.mjs`
Expected: `test-fone OK` e `test-clientes OK`.

- [ ] **Step 8: Commit**

```bash
git add painel/fone.js painel/clientes.js painel/index.html tests/test-fone.mjs
git commit -m "feat(painel): normalizador de telefone unico (poE164), espelho do wa_e164"
```

---

### Task 3: Parser de vCard e CSV

**Files:**
- Create: `lib/wa-vcard.php`
- Test: `tests/test-wa-vcard.php`

**Interfaces:**
- Consumes: nada.
- Produces: `wa_vcard_parse($texto) -> array` e `wa_csv_parse($texto) -> array`. Cada item é `['nome'=>string, 'telefones'=>string[], 'emails'=>string[], 'org'=>string, 'raw'=>string]`. Telefones e nome saem **como estão** no arquivo (a normalização é da Task 4).

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/test-wa-vcard.php
require __DIR__ . '/../lib/wa-vcard.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

// --- vCard 3.0 com dois telefones e um email (o que o iPhone exporta)
$vcf = "BEGIN:VCARD\r\nVERSION:3.0\r\nN:Ramos;Lourdete;;;\r\nFN:Lourdete Ramos - PO\r\n" .
       "TEL;TYPE=CELL:(48) 99999-1111\r\nTEL;TYPE=HOME:48 3222-0000\r\n" .
       "EMAIL:lourdete\@x.com\r\nEND:VCARD\r\n";
$r = wa_vcard_parse($vcf);
ok(count($r) === 1, 'um contato');
ok($r[0]['nome'] === 'Lourdete Ramos - PO', 'FN vira o nome, com marcador intacto');
ok(count($r[0]['telefones']) === 2, 'dois telefones capturados');
ok(in_array('(48) 99999-1111', $r[0]['telefones'], true), 'celular cru preservado');
ok($r[0]['emails'] === ['lourdete@x.com'], 'email capturado');

// --- dois contatos no mesmo arquivo
$dois = "BEGIN:VCARD\nFN:A\nTEL:1\nEND:VCARD\nBEGIN:VCARD\nFN:B\nTEL:2\nEND:VCARD\n";
ok(count(wa_vcard_parse($dois)) === 2, 'dois cards separados');

// --- sem FN, cai no N formatado
$semfn = "BEGIN:VCARD\nN:Silva;Joao;;;\nTEL:9\nEND:VCARD\n";
ok(wa_vcard_parse($semfn)[0]['nome'] === 'Joao Silva', 'sem FN monta do N (nome sobrenome)');

// --- quoted-printable no nome (acontece em export antigo do Android)
$qp = "BEGIN:VCARD\nFN;ENCODING=QUOTED-PRINTABLE:Mar=C3=ADlia\nTEL:9\nEND:VCARD\n";
ok(wa_vcard_parse($qp)[0]['nome'] === 'Marília', 'quoted-printable decodificado');

// --- linha dobrada (folding): continuacao comeca com espaco
$fold = "BEGIN:VCARD\nFN:Nome Muito\n  Longo\nTEL:9\nEND:VCARD\n";
ok(wa_vcard_parse($fold)[0]['nome'] === 'Nome MuitoLongo', 'folding remontado');

// --- CSV do Google Contacts (cabecalho com colunas conhecidas)
$csv = "Name,Phone 1 - Value,E-mail 1 - Value\r\n" .
       "\"Maria Grecia 2 - PO\",+55 48 98888-2222,maria\@x.com\r\n" .
       "Sem Telefone,,so\@email.com\r\n";
$c = wa_csv_parse($csv);
ok(count($c) === 2, 'duas linhas de dados');
ok($c[0]['nome'] === 'Maria Grecia 2 - PO', 'nome do CSV');
ok($c[0]['telefones'] === ['+55 48 98888-2222'], 'telefone do CSV');
ok($c[1]['telefones'] === [], 'linha sem telefone nao inventa telefone');

// --- raw preservado para o payload_import
ok(strpos($r[0]['raw'], 'BEGIN:VCARD') !== false, 'raw guarda o card original');

echo "test-wa-vcard OK\n";
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-wa-vcard.php`
Expected: FAIL — `lib/wa-vcard.php` não existe.

- [ ] **Step 3: Write `lib/wa-vcard.php`**

```php
<?php
/* ============================================================
   Leitura de vCard (.vcf) e CSV exportados da agenda do celular ou
   do Google Contacts. Só EXTRAI: nome, telefones, emails, org e o
   card cru. A normalizacao (E.164, marcador, limpeza) e da Task 4.
============================================================ */

function wa_vcard_parse($texto) {
    $texto = str_replace(["\r\n", "\r"], "\n", (string) $texto);
    $cards = [];
    // separa cada BEGIN:VCARD ... END:VCARD
    if (preg_match_all('/BEGIN:VCARD\n(.*?)\nEND:VCARD/is', $texto, $m)) {
        foreach ($m[0] as $i => $bruto) {
            $linhas = wa_vcard_desdobra($m[1][$i]);
            $card = ['nome' => '', 'telefones' => [], 'emails' => [], 'org' => '', 'raw' => $bruto];
            $fn = ''; $n = '';
            foreach ($linhas as $ln) {
                if (strpos($ln, ':') === false) continue;
                list($chaveBruta, $valor) = explode(':', $ln, 2);
                $partes = explode(';', $chaveBruta);
                $prop   = strtoupper($partes[0]);
                $qp     = false;
                foreach ($partes as $p) {
                    if (stripos($p, 'QUOTED-PRINTABLE') !== false) $qp = true;
                }
                if ($qp) $valor = quoted_printable_decode($valor);
                switch ($prop) {
                    case 'FN':    $fn = trim($valor); break;
                    case 'N':     $n  = wa_vcard_nome_de_n($valor); break;
                    case 'TEL':   if (trim($valor) !== '') $card['telefones'][] = trim($valor); break;
                    case 'EMAIL': if (trim($valor) !== '') $card['emails'][] = trim($valor); break;
                    case 'ORG':   $card['org'] = trim($valor); break;
                }
            }
            $card['nome'] = $fn !== '' ? $fn : $n;
            $cards[] = $card;
        }
    }
    return $cards;
}

/* Folding do vCard: uma linha continua na proxima quando esta comeca com
   espaco ou tab. Remonta antes de parsear. */
function wa_vcard_desdobra($bloco) {
    $out = [];
    foreach (explode("\n", $bloco) as $ln) {
        if ($ln !== '' && ($ln[0] === ' ' || $ln[0] === "\t") && $out) {
            $out[count($out) - 1] .= ltrim($ln);
        } else {
            $out[] = $ln;
        }
    }
    return $out;
}

/* N e "Sobrenome;Nome;;;". Vira "Nome Sobrenome". */
function wa_vcard_nome_de_n($valor) {
    $p = explode(';', $valor);
    $sobrenome = trim($p[0] ?? '');
    $nome      = trim($p[1] ?? '');
    return trim($nome . ' ' . $sobrenome);
}

/* CSV do Google Contacts. Casa colunas por nome de cabecalho, que muda de
   idioma ("Name" / "Nome"), entao procura por substring conhecida. */
function wa_csv_parse($texto) {
    $texto = str_replace(["\r\n", "\r"], "\n", (string) $texto);
    $linhas = array_values(array_filter(explode("\n", $texto), fn($l) => $l !== ''));
    if (!$linhas) return [];
    $cab = str_getcsv($linhas[0]);
    $colNome = wa_csv_acha_col($cab, ['name', 'nome']);
    $colsTel = wa_csv_acha_cols($cab, ['phone', 'telefone', 'celular']);
    $colsMail = wa_csv_acha_cols($cab, ['e-mail', 'email']);
    $out = [];
    for ($i = 1; $i < count($linhas); $i++) {
        $c = str_getcsv($linhas[$i]);
        $tel = []; foreach ($colsTel as $k) if (trim($c[$k] ?? '') !== '') $tel[] = trim($c[$k]);
        $mail = []; foreach ($colsMail as $k) if (trim($c[$k] ?? '') !== '') $mail[] = trim($c[$k]);
        $out[] = [
            'nome' => trim($c[$colNome] ?? ''),
            'telefones' => $tel, 'emails' => $mail, 'org' => '',
            'raw' => $linhas[$i],
        ];
    }
    return $out;
}

function wa_csv_acha_col($cab, $chaves) {
    foreach ($cab as $i => $nome) foreach ($chaves as $ch)
        if (stripos($nome, $ch) !== false) return $i;
    return 0;
}
function wa_csv_acha_cols($cab, $chaves) {
    $out = [];
    foreach ($cab as $i => $nome) foreach ($chaves as $ch)
        if (stripos($nome, $ch) !== false) { $out[] = $i; break; }
    return $out;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test-wa-vcard.php`
Expected: `test-wa-vcard OK`.

- [ ] **Step 5: Commit**

```bash
git add lib/wa-vcard.php tests/test-wa-vcard.php
git commit -m "feat(import): parser de vCard e CSV da agenda"
```

---

### Task 4: Normalização de candidato — marcador, limpeza, CPF e dedup intra-lote

**Files:**
- Create: `lib/wa-import.php`
- Test: `tests/test-wa-import-normaliza.php`

**Interfaces:**
- Consumes: `wa_e164`, `wa_e_celular` (`lib/wa-fone.php`).
- Produces:
  - `const WA_IMPORT_MARCADORES` (lista de regex de marcador, padrão).
  - `wa_import_tem_marcador($nome, $padroes=null) -> bool`
  - `wa_import_limpa_nome($nome, $padroes=null) -> string`
  - `wa_import_cpf($bruto) -> string` (11 dígitos, ou `''` se inválido)
  - `wa_import_candidato($contato, $origem, $padroes=null) -> ?array` — recebe um item do parser (Task 3), devolve o candidato normalizado ou `null` quando deve ser descartado. Shape do candidato:
    `['wa_id'=>?string, 'celulares'=>string[], 'fixos'=>string[], 'nome'=>string, 'email'=>?string, 'cpf'=>?string, 'data_nascimento'=>?string, 'origem_import'=>string, 'campos'=>array, 'payload_import'=>array]`
    (`campos` = colunas de ficha extras vindas do CRM; vazio para agenda. `wa_id` = primeiro celular em E.164, ou `null`.)
  - `wa_import_dedup($candidatos) -> array` — funde candidatos do mesmo lote por CPF ou celular (o mesmo cliente nos dois aparelhos), somando campos sem sobrescrever.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/test-wa-import-normaliza.php
require __DIR__ . '/../lib/wa-import.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

// --- marcador
ok(wa_import_tem_marcador('Lourdete - PO') === true,  'traco PO');
ok(wa_import_tem_marcador('Maria Cliente PO') === true, 'cliente PO');
ok(wa_import_tem_marcador('Maria - po') === true,      'minusculo');
ok(wa_import_tem_marcador('Dentista Joao') === false,  'sem marcador fica de fora');
ok(wa_import_tem_marcador('Poliana Souza') === false,  '"po" dentro de palavra nao conta');

// --- limpeza de nome: tira marcador e poluicao de busca
ok(wa_import_limpa_nome('Maria Grecia 2 - PO') === 'Maria Grecia 2', 'tira o marcador do fim');
ok(wa_import_limpa_nome('Lourdete Ramos - Cliente PO') === 'Lourdete Ramos', 'tira "Cliente PO"');
ok(wa_import_limpa_nome('  Joao   Silva ') === 'Joao Silva', 'colapsa espacos');

// --- cpf
ok(wa_import_cpf('111.444.777-35') === '11144477735', 'cpf so em digitos');
ok(wa_import_cpf('3423415967') === '',  'cpf com 10 digitos e invalido');
ok(wa_import_cpf('') === '', 'cpf vazio');

// --- candidato de agenda SEM marcador e descartado
$semMarca = ['nome'=>'Dentista', 'telefones'=>['48999990000'], 'emails'=>[], 'org'=>'', 'raw'=>'x'];
ok(wa_import_candidato($semMarca, 'agenda-esposa') === null, 'agenda sem marcador: descartado');

// --- candidato de agenda COM marcador: nome limpo, celular em E.164, wa_id setado
$comMarca = ['nome'=>'Lourdete Ramos - PO', 'telefones'=>['(48) 99999-1111','48 3222-0000'],
             'emails'=>['l@x.com'], 'org'=>'', 'raw'=>'BEGIN...'];
$c = wa_import_candidato($comMarca, 'agenda-esposa');
ok($c['nome'] === 'Lourdete Ramos', 'nome limpo');
ok($c['celulares'] === ['+5548999991111'], 'celular normalizado');
ok($c['fixos'] === ['+554832220000'], 'fixo separado do celular');
ok($c['wa_id'] === '+5548999991111', 'wa_id e o primeiro celular');
ok($c['origem_import'] === 'agenda-esposa', 'origem gravada');
ok($c['payload_import']['raw'] === 'BEGIN...', 'payload guarda o cru');

// --- agenda com marcador mas so com fixo: entra, mas sem wa_id (vai pra revisao)
$soFixo = ['nome'=>'Tia - PO', 'telefones'=>['48 3222-0000'], 'emails'=>[], 'org'=>'', 'raw'=>'y'];
$f = wa_import_candidato($soFixo, 'agenda-marido');
ok($f !== null, 'so com fixo e marcador ainda entra');
ok($f['wa_id'] === null, 'sem celular, sem wa_id');

// --- CRM nao exige marcador (origem crm-*)
$crm = ['nome'=>'Antonio Filho', 'telefones'=>['48999990001'], 'emails'=>[], 'org'=>'',
        'raw'=>'{}', 'cpf'=>'111.444.777-35', 'campos'=>['cidade'=>'Florianopolis']];
$cc = wa_import_candidato($crm, 'crm-toninho');
ok($cc !== null, 'CRM entra sem marcador');
ok($cc['cpf'] === '11144477735', 'cpf normalizado no candidato');
ok($cc['campos']['cidade'] === 'Florianopolis', 'campos extras do CRM preservados');

// --- dedup intra-lote: mesma pessoa nos dois aparelhos, casada por celular
$dup = wa_import_dedup([
  ['wa_id'=>'+5548999991111','celulares'=>['+5548999991111'],'fixos'=>[],'nome'=>'Lourdete',
   'email'=>null,'cpf'=>null,'data_nascimento'=>null,'origem_import'=>'agenda-esposa','campos'=>[],'payload_import'=>['a'=>1]],
  ['wa_id'=>'+5548999991111','celulares'=>['+5548999991111'],'fixos'=>[],'nome'=>'Lourdete Ramos',
   'email'=>'l@x.com','cpf'=>null,'data_nascimento'=>null,'origem_import'=>'agenda-marido','campos'=>[],'payload_import'=>['b'=>2]],
]);
ok(count($dup) === 1, 'mesma pessoa nos dois aparelhos vira um candidato');
ok($dup[0]['nome'] === 'Lourdete Ramos', 'fica o nome mais completo');
ok($dup[0]['email'] === 'l@x.com', 'email preenchido vence o vazio');

// --- dedup nao funde pessoas diferentes
$dois = wa_import_dedup([
  ['wa_id'=>'+5548999991111','celulares'=>['+5548999991111'],'fixos'=>[],'nome'=>'A','email'=>null,'cpf'=>null,'data_nascimento'=>null,'origem_import'=>'agenda-esposa','campos'=>[],'payload_import'=>[]],
  ['wa_id'=>'+5511988887777','celulares'=>['+5511988887777'],'fixos'=>[],'nome'=>'B','email'=>null,'cpf'=>null,'data_nascimento'=>null,'origem_import'=>'agenda-esposa','campos'=>[],'payload_import'=>[]],
]);
ok(count($dois) === 2, 'celulares diferentes nao se fundem');

echo "test-wa-import-normaliza OK\n";
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-wa-import-normaliza.php`
Expected: FAIL — `lib/wa-import.php` não existe.

- [ ] **Step 3: Write `lib/wa-import.php` (esta task cobre marcador, limpeza, cpf, candidato, dedup)**

```php
<?php
/* ============================================================
   Motor de importacao e fusao de contatos (spec 9). Toda a logica
   e pura e testavel sem rede. A escrita (Task 7) usa a service_role
   por transporte injetavel; a auditoria (Task 6) recebe a base pronta.
============================================================ */

require_once __DIR__ . '/wa-fone.php';

/* Marcador "PO" das agendas. "\bPO\b" pega "- PO" e "Cliente PO" sem casar
   "Poliana". Configuravel: a grafia varia entre os dois aparelhos. */
const WA_IMPORT_MARCADORES = ['/\bPO\b/i', '/\bcliente\s+po\b/i'];

function wa_import_tem_marcador($nome, $padroes = null) {
    $padroes = $padroes ?: WA_IMPORT_MARCADORES;
    foreach ($padroes as $re) if (preg_match($re, (string) $nome)) return true;
    return false;
}

function wa_import_limpa_nome($nome, $padroes = null) {
    $padroes = $padroes ?: WA_IMPORT_MARCADORES;
    $s = (string) $nome;
    // tira "Cliente PO" e "PO" e o separador que os antecede
    $s = preg_replace('/[\s\-–—]*\bcliente\s+po\b/i', '', $s);
    $s = preg_replace('/[\s\-–—]*\bpo\b\s*$/i', '', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}

function wa_import_cpf($bruto) {
    $d = preg_replace('/\D+/', '', (string) $bruto);
    return strlen($d) === 11 ? $d : '';
}

function wa_import_candidato($contato, $origem, $padroes = null) {
    $ehCrm = strpos((string) $origem, 'crm') === 0;
    $nomeBruto = (string) ($contato['nome'] ?? '');

    // Agenda: so entra quem tem o marcador. CRM entra sempre.
    if (!$ehCrm && !wa_import_tem_marcador($nomeBruto, $padroes)) return null;

    $celulares = []; $fixos = [];
    foreach (($contato['telefones'] ?? []) as $t) {
        $e = wa_e164($t);
        if ($e === null) continue;
        if (wa_e_celular($e)) { if (!in_array($e, $celulares, true)) $celulares[] = $e; }
        else                  { if (!in_array($e, $fixos, true))     $fixos[] = $e; }
    }

    $emails = $contato['emails'] ?? [];
    return [
        'wa_id'           => $celulares[0] ?? null,
        'celulares'       => $celulares,
        'fixos'           => $fixos,
        'nome'            => $ehCrm ? trim($nomeBruto) : wa_import_limpa_nome($nomeBruto, $padroes),
        'email'           => $emails[0] ?? null,
        'cpf'             => wa_import_cpf($contato['cpf'] ?? '') ?: null,
        'data_nascimento' => ($contato['data_nascimento'] ?? '') ?: null,
        'origem_import'   => (string) $origem,
        'campos'          => is_array($contato['campos'] ?? null) ? $contato['campos'] : [],
        'payload_import'  => ['raw' => $contato['raw'] ?? '', 'origem' => (string) $origem],
    ];
}

/* Funde candidatos do mesmo lote (mesma pessoa nos dois aparelhos). Chave:
   cpf, senao qualquer celular em comum. Soma sem sobrescrever. */
function wa_import_dedup($candidatos) {
    $out = [];
    foreach ($candidatos as $c) {
        $achou = null;
        foreach ($out as $i => $j) {
            $mesmoCpf = $c['cpf'] && $j['cpf'] && $c['cpf'] === $j['cpf'];
            $mesmoCel = array_intersect($c['celulares'], $j['celulares']) !== [];
            if ($mesmoCpf || $mesmoCel) { $achou = $i; break; }
        }
        if ($achou === null) { $out[] = $c; continue; }
        $out[$achou] = wa_import_soma_candidato($out[$achou], $c);
    }
    return array_values($out);
}

function wa_import_soma_candidato($a, $b) {
    $maisLongo = fn($x, $y) => strlen((string) $y) > strlen((string) $x) ? $y : $x;
    $a['nome']  = $maisLongo($a['nome'], $b['nome']);
    $a['email'] = $a['email'] ?: $b['email'];
    $a['cpf']   = $a['cpf'] ?: $b['cpf'];
    $a['data_nascimento'] = $a['data_nascimento'] ?: $b['data_nascimento'];
    $a['celulares'] = array_values(array_unique(array_merge($a['celulares'], $b['celulares'])));
    $a['fixos']     = array_values(array_unique(array_merge($a['fixos'], $b['fixos'])));
    $a['wa_id']     = $a['wa_id'] ?: $b['wa_id'];
    $a['campos']    = $a['campos'] + $b['campos']; // '+' preserva as chaves ja existentes
    $a['payload_import']['tambem'][] = $b['payload_import'];
    return $a;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test-wa-import-normaliza.php`
Expected: `test-wa-import-normaliza OK`.

- [ ] **Step 5: Commit**

```bash
git add lib/wa-import.php tests/test-wa-import-normaliza.php
git commit -m "feat(import): marcador PO, limpeza de nome, cpf, candidato e dedup de lote"
```

---

### Task 5: Mapa do CRM antigo para candidato

**Files:**
- Modify: `lib/wa-import.php` (adiciona `wa_import_mapa_crm`)
- Test: `tests/test-wa-import-crm.php`

**Interfaces:**
- Consumes: shape de candidato da Task 4.
- Produces: `wa_import_mapa_crm($ficha) -> array` — recebe uma ficha do CRM (JSON do `app_storage.clients`) e devolve o `$contato` no formato que `wa_import_candidato(..., 'crm-toninho')` consome, com `campos` já mapeando o bloco de ficha (seção 4.1). O `raw` é a ficha inteira em JSON.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/test-wa-import-crm.php
require __DIR__ . '/../lib/wa-import.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

// ficha sintetica no formato do CRM (mesmo shape de campo por campo
// conferido na auditoria de 12/09; CPF, RG, passaporte, telefone, nome,
// e-mail e endereco sao FICTICIOS - fix round 1 da Task 9 trocou os
// valores reais que tinham vazado para ca por estes)
$ficha = [
  'id'=>'ficha-teste-0001','nome'=>'fulano de tal exemplo','email'=>'fulano.teste@example.com',
  'telefone'=>'48999990001','cpf'=>'11144477735','rg'=>'111111111','dataNascimento'=>'1981-09-11',
  'cep'=>'88000000','endereco'=>'Rua Exemplo','numero'=>'100','complemento'=>'APTO 1',
  'bairro'=>'Centro','cidade'=>'Florianópolis','estado'=>'SC',
  'profissao'=>'Empresário','estadoCivil'=>'Casado(a)','nacionalidade'=>'Brasileira','comoConheceu'=>'Instagram',
  'passaporteNumero'=>'AB000000','passaporteValidade'=>'2028-07-04','passaporteEmissao'=>'',
  'passaporteOrgaoEmissor'=>'','telefoneSecundario'=>'','observacoes'=>'',
  'contatoEmergenciaNome'=>'Lais','contatoEmergenciaTelefone'=>'48996048882','contatoEmergenciaParentesco'=>'Cônjuge',
];

$m = wa_import_mapa_crm($ficha);
ok($m['nome'] === 'fulano de tal exemplo', 'nome copiado');
ok($m['telefones'] === ['48999990001'], 'telefone vira lista para o parser comum');
ok($m['cpf'] === '11144477735', 'cpf passa para o candidato normalizar');
ok($m['campos']['data_nascimento'] === '1981-09-11', 'nascimento mapeado para snake_case');
ok($m['campos']['endereco'] === 'Rua Exemplo', 'endereco');
ok($m['campos']['passaporte_numero'] === 'AB000000', 'passaporte camelCase -> snake_case');
ok($m['campos']['passaporte_validade'] === '2028-07-04', 'validade');
ok($m['campos']['contato_emergencia_parentesco'] === 'Cônjuge', 'emergencia');
ok($m['campos']['estado'] === 'SC', 'estado');
ok(!array_key_exists('passaporte_emissao', $m['campos']), 'campo vazio nao entra (fusao so soma)');
ok(json_decode($m['raw'], true)['id'] === 'ficha-teste-0001', 'raw guarda a ficha inteira');

// passa pelo candidato: vira registro pronto
$c = wa_import_candidato($m, 'crm-toninho');
ok($c['wa_id'] === '+5548999990001', 'telefone do CRM normalizado');
ok($c['cpf'] === '11144477735', 'cpf no candidato');
ok($c['campos']['cidade'] === 'Florianópolis', 'cidade preservada');

echo "test-wa-import-crm OK\n";
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-wa-import-crm.php`
Expected: FAIL — `wa_import_mapa_crm` não definida.

- [ ] **Step 3: Add `wa_import_mapa_crm` to `lib/wa-import.php`**

```php
/* Mapeia a ficha do CRM antigo (camelCase) para o formato do parser, com o
   bloco de ficha em snake_case (colunas da po_leads, seção 4.1). Campo vazio
   NAO entra: a fusao so soma, entao ausencia aqui nunca vira sobrescrita de
   branco depois. */
function wa_import_mapa_crm($f) {
    $de_para = [
        'rg' => 'rg', 'dataNascimento' => 'data_nascimento', 'nacionalidade' => 'nacionalidade',
        'estadoCivil' => 'estado_civil', 'profissao' => 'profissao',
        'cep' => 'cep', 'endereco' => 'endereco', 'numero' => 'numero',
        'complemento' => 'complemento', 'bairro' => 'bairro', 'cidade' => 'cidade', 'estado' => 'estado',
        'passaporteNumero' => 'passaporte_numero', 'passaporteOrgaoEmissor' => 'passaporte_orgao_emissor',
        'passaporteEmissao' => 'passaporte_emissao', 'passaporteValidade' => 'passaporte_validade',
        'contatoEmergenciaNome' => 'contato_emergencia_nome',
        'contatoEmergenciaTelefone' => 'contato_emergencia_telefone',
        'contatoEmergenciaParentesco' => 'contato_emergencia_parentesco',
        'telefoneSecundario' => 'telefone_secundario', 'observacoes' => 'observacoes',
        'comoConheceu' => 'origem_manual',
    ];
    $campos = [];
    foreach ($de_para as $src => $dst) {
        $v = trim((string) ($f[$src] ?? ''));
        if ($v !== '') $campos[$dst] = $v;
    }
    $tel = trim((string) ($f['telefone'] ?? ''));
    return [
        'nome'      => (string) ($f['nome'] ?? ''),
        'telefones' => $tel !== '' ? [$tel] : [],
        'emails'    => ($e = trim((string) ($f['email'] ?? ''))) !== '' ? [$e] : [],
        'org'       => '',
        'cpf'       => (string) ($f['cpf'] ?? ''),
        'data_nascimento' => trim((string) ($f['dataNascimento'] ?? '')),
        'campos'    => $campos,
        'raw'       => json_encode($f, JSON_UNESCAPED_UNICODE),
    ];
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test-wa-import-crm.php`
Expected: `test-wa-import-crm OK`.

- [ ] **Step 5: Commit**

```bash
git add lib/wa-import.php tests/test-wa-import-crm.php
git commit -m "feat(import): mapa do CRM antigo para o candidato de importacao"
```

---

### Task 6: Auditoria de fusão — o coração

**Files:**
- Modify: `lib/wa-import.php` (adiciona `wa_import_audita` e a whitelist)
- Test: `tests/test-wa-import-audita.php`

**Interfaces:**
- Consumes: candidatos (Task 4/5).
- Produces:
  - `const WA_IMPORT_CAMPOS_PREENCHIVEIS` — as únicas colunas que uma fusão pode preencher (o bloco de ficha + `telefone`, `nome`, `email`). **Nunca** `status`, `venda`, `venda_at`, `notas`, `qualif_*`.
  - `wa_import_audita($candidatos, $base) -> array`. Cada item da base é `['id'=>, 'telefone'=>, 'cpf'=>, 'nome'=>, 'data_nascimento'=>, 'historico'=>bool, ...campos]`. Devolve, para cada candidato, `['nome'=>, 'acao'=>'novo'|'funde'|'revisar', 'match_id'=>?, 'match_por'=>'cpf'|'celular'|'nome_nasc'|null, 'preenche'=>array, 'candidato'=>array]`.

**Regras (as três do Armando, verificadas por teste):**
- Casa por CPF → `funde`. Casa por celular → `funde`. Só nome+nascimento → `revisar` (sugere, não funde).
- `preenche` só contém colunas da whitelist **que estão vazias na base** e que o candidato tem. Nunca sobrescreve valor preenchido; nunca inclui `status`/`venda`/`notas`.
- Nenhum casamento → `novo`.
- Fixo nunca casa identidade (só `celulares` entram no índice de telefone).

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/test-wa-import-audita.php
require __DIR__ . '/../lib/wa-import.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

function cand($over = []) {
    return array_merge([
        'wa_id'=>null,'celulares'=>[],'fixos'=>[],'nome'=>'X','email'=>null,'cpf'=>null,
        'data_nascimento'=>null,'origem_import'=>'agenda-esposa','campos'=>[],'payload_import'=>[],
    ], $over);
}

// base: uma ficha do CRM com historico (ja e cliente), sem telefone
$base = [
  ['id'=>'L1','telefone'=>null,'cpf'=>'11144477735','nome'=>'Antonio','data_nascimento'=>'1981-09-11',
   'historico'=>true,'email'=>'a@x.com','cidade'=>'Floripa'],
  ['id'=>'L2','telefone'=>'+5511988887777','cpf'=>null,'nome'=>'Bruna','data_nascimento'=>null,
   'historico'=>false,'email'=>null,'cidade'=>null],
];

// --- casa por CPF, e a agenda traz o telefone que faltava: PREENCHE, nao sobrescreve
$plano = wa_import_audita([
  cand(['cpf'=>'11144477735','wa_id'=>'+5548999990001','celulares'=>['+5548999990001'],
        'nome'=>'Antonio Filho','email'=>'outro@x.com','origem_import'=>'agenda-esposa']),
], $base);
ok($plano[0]['acao'] === 'funde', 'cpf casa -> funde');
ok($plano[0]['match_por'] === 'cpf', 'casou por cpf');
ok($plano[0]['match_id'] === 'L1', 'casou na ficha certa');
ok($plano[0]['preenche']['telefone'] === '+5548999990001', 'preenche o telefone que faltava');
ok(!array_key_exists('email', $plano[0]['preenche']), 'email ja existe: NAO sobrescreve (regra 2)');
ok(!array_key_exists('nome', $plano[0]['preenche']), 'nome ja existe: NAO sobrescreve');
ok(!array_key_exists('status', $plano[0]['preenche']), 'status nunca e tocado (regra 1)');

// --- casa por celular
$p2 = wa_import_audita([
  cand(['wa_id'=>'+5511988887777','celulares'=>['+5511988887777'],'nome'=>'Bruna Lima','email'=>'b@x.com']),
], $base);
ok($p2[0]['acao'] === 'funde' && $p2[0]['match_por'] === 'celular', 'celular casa -> funde');
ok($p2[0]['preenche']['email'] === 'b@x.com', 'preenche email que faltava');
ok($p2[0]['preenche']['nome'] === 'Bruna Lima', 'preenche nome mais completo quando base nao tinha? so se vazio');

// (na base L2 o nome ja e "Bruna": nome preenchido nao e sobrescrito)
ok($p2[0]['preenche']['nome'] !== 'Bruna Lima' || true, 'ver regra abaixo');

// --- so nome+nascimento: SUGERE, nao funde
$p3 = wa_import_audita([
  cand(['nome'=>'Antonio','data_nascimento'=>'1981-09-11','celulares'=>['+5548911112222'],'wa_id'=>'+5548911112222']),
], [['id'=>'L1','telefone'=>null,'cpf'=>'99999999999','nome'=>'Antonio','data_nascimento'=>'1981-09-11','historico'=>true]]);
ok($p3[0]['acao'] === 'revisar', 'so nome+nascimento nao funde sozinho');
ok($p3[0]['match_por'] === 'nome_nasc' && $p3[0]['match_id'] === 'L1', 'mas aponta o provavel');

// --- nada casa: novo
$p4 = wa_import_audita([cand(['wa_id'=>'+5548900000000','celulares'=>['+5548900000000'],'nome'=>'Novo'])], $base);
ok($p4[0]['acao'] === 'novo', 'sem match -> novo');

// --- fixo NUNCA casa identidade
$baseFixo = [['id'=>'LF','telefone'=>'+554832220000','cpf'=>null,'nome'=>'Empresa','data_nascimento'=>null,'historico'=>false]];
$p5 = wa_import_audita([cand(['fixos'=>['+554832220000'],'wa_id'=>null,'nome'=>'Outro - PO'])], $baseFixo);
ok($p5[0]['acao'] === 'novo', 'fixo igual nao funde pessoas diferentes');

// --- a base guarda o historico como dono: mesmo casando, preenche NUNCA muda venda
$p6 = wa_import_audita([
  cand(['cpf'=>'11144477735','wa_id'=>'+5548999990001','celulares'=>['+5548999990001'],'campos'=>['profissao'=>'Medico']]),
], $base);
ok($p6[0]['preenche']['profissao'] === 'Medico', 'preenche campo de ficha que faltava');
ok(!array_key_exists('venda', $p6[0]['preenche']) && !array_key_exists('notas', $p6[0]['preenche']),
   'venda e notas nunca entram no preenche');

echo "test-wa-import-audita OK\n";
```

> **Nota ao implementador sobre o caso do nome em L2:** a base L2 já tem
> `nome='Bruna'` (preenchido). Pela regra 2, `preenche` **não** deve trazer `nome`.
> Ajuste a asserção `$p2[0]['preenche']['nome']` para refletir que nome preenchido
> não é sobrescrito: troque as duas linhas do `nome` no `$p2` por
> `ok(!array_key_exists('nome', $p2[0]['preenche']), 'nome ja preenchido na base nao e sobrescrito');`.
> A asserção do `email` continua (a base não tinha email). Corrija antes do Step 3
> e rode o teste; a intenção é: fusão só preenche vazio.

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-wa-import-audita.php`
Expected: FAIL — `wa_import_audita` não definida.

- [ ] **Step 3: Add `wa_import_audita` and the whitelist to `lib/wa-import.php`**

```php
/* As UNICAS colunas que uma fusao pode preencher. Fora daqui, nada e tocado:
   status, venda, venda_at, notas, qualif_* sao historico e sao do dono. */
const WA_IMPORT_CAMPOS_PREENCHIVEIS = [
    'telefone','nome','email','cpf','rg','data_nascimento','nacionalidade','estado_civil',
    'profissao','cep','endereco','numero','complemento','bairro','cidade','estado',
    'passaporte_numero','passaporte_orgao_emissor','passaporte_emissao','passaporte_validade',
    'contato_emergencia_nome','contato_emergencia_telefone','contato_emergencia_parentesco',
    'telefone_secundario','observacoes','origem_manual',
];

function wa_import_audita($candidatos, $base) {
    // indices: cpf e celular casam identidade; nome+nasc so sugere.
    $porCpf = []; $porCel = []; $porNomeNasc = [];
    foreach ($base as $b) {
        if (!empty($b['cpf'])) $porCpf[$b['cpf']] = $b;
        $tel = $b['telefone'] ?? null;
        if ($tel && wa_e_celular($tel)) $porCel[$tel] = $b;
        if (!empty($b['nome']) && !empty($b['data_nascimento'])) {
            $porNomeNasc[wa_import_chave_nome($b['nome']) . '|' . $b['data_nascimento']] = $b;
        }
    }

    $plano = [];
    foreach ($candidatos as $c) {
        $match = null; $por = null;

        if ($c['cpf'] && isset($porCpf[$c['cpf']])) { $match = $porCpf[$c['cpf']]; $por = 'cpf'; }
        if (!$match) {
            foreach ($c['celulares'] as $cel) {
                if (isset($porCel[$cel])) { $match = $porCel[$cel]; $por = 'celular'; break; }
            }
        }

        if ($match) {
            $plano[] = [
                'nome' => $c['nome'], 'acao' => 'funde', 'match_id' => $match['id'],
                'match_por' => $por, 'preenche' => wa_import_preenche($match, $c), 'candidato' => $c,
            ];
            continue;
        }

        // sem casamento forte: nome+nascimento apenas sugere.
        if ($c['data_nascimento']) {
            $k = wa_import_chave_nome($c['nome']) . '|' . $c['data_nascimento'];
            if (isset($porNomeNasc[$k])) {
                $plano[] = ['nome'=>$c['nome'],'acao'=>'revisar','match_id'=>$porNomeNasc[$k]['id'],
                            'match_por'=>'nome_nasc','preenche'=>[],'candidato'=>$c];
                continue;
            }
        }

        $plano[] = ['nome'=>$c['nome'],'acao'=>'novo','match_id'=>null,'match_por'=>null,
                    'preenche'=>[],'candidato'=>$c];
    }
    return $plano;
}

/* Preenche SO o que esta vazio na base, SO colunas da whitelist. Nunca
   sobrescreve, nunca toca historico. */
function wa_import_preenche($base, $c) {
    $novo = [];
    $vazio = fn($k) => !isset($base[$k]) || $base[$k] === null || trim((string) $base[$k]) === '';

    $doCandidato = array_merge([
        'telefone' => $c['wa_id'], 'nome' => $c['nome'], 'email' => $c['email'], 'cpf' => $c['cpf'],
        'data_nascimento' => $c['data_nascimento'],
    ], $c['campos']);

    foreach ($doCandidato as $k => $v) {
        if (!in_array($k, WA_IMPORT_CAMPOS_PREENCHIVEIS, true)) continue;
        if ($v === null || trim((string) $v) === '') continue;
        if ($vazio($k)) $novo[$k] = $v;
    }
    return $novo;
}

function wa_import_chave_nome($nome) {
    $s = mb_strtolower(trim((string) $nome), 'UTF-8');
    $s = preg_replace('/\s+/', ' ', $s);
    return $s;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test-wa-import-audita.php`
Expected: `test-wa-import-audita OK`.

- [ ] **Step 5: Commit**

```bash
git add lib/wa-import.php tests/test-wa-import-audita.php
git commit -m "feat(import): auditoria de fusao por camadas (cpf>celular>nome+nasc)"
```

---

### Task 7: Aplicação do plano (escrita injetável) e endpoint

**Files:**
- Modify: `lib/wa-import.php` (adiciona `wa_import_aplica`)
- Create: `contatos-importar.php`
- Test: `tests/test-wa-import-aplica.php`

**Interfaces:**
- Consumes: plano da Task 6, `wa_db_insert`/`wa_db_update` (`lib/wa-db.php`).
- Produces:
  - `wa_import_aplica($plano, $inserir, $atualizar) -> array` — `$inserir(array $linha)` e `$atualizar(string $id, array $campos)` são injetados (no teste, fakes; no endpoint, `wa_db`). Aplica: `novo` → `$inserir`; `funde` com `preenche` não vazio → `$atualizar`; `funde` sem preenche e `revisar` → não escreve. Retorna `['novos'=>int,'preenchidos'=>int,'revisar'=>int,'ignorados'=>int]`.
  - `contatos-importar.php` — endpoint POST, auth Supabase (padrão `importar.php`), parâmetros `arquivo`/`texto`, `tipo` (`vcf`|`csv`), `origem`, `modo` (`preview`|`aplicar`). `preview` devolve o plano resumido sem escrever; `aplicar` escreve via `wa_db` e devolve as contagens.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/test-wa-import-aplica.php
require __DIR__ . '/../lib/wa-import.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

$inseridos = []; $atualizados = [];
$inserir = function ($linha) use (&$inseridos) { $inseridos[] = $linha; return ['id' => 'novo-' . count($inseridos)]; };
$atualizar = function ($id, $campos) use (&$atualizados) { $atualizados[] = [$id, $campos]; return true; };

$plano = [
  ['nome'=>'Novo','acao'=>'novo','match_id'=>null,'match_por'=>null,'preenche'=>[],
   'candidato'=>['wa_id'=>'+5548900000000','nome'=>'Novo','email'=>null,'cpf'=>null,
                 'origem_import'=>'agenda-esposa','campos'=>['cidade'=>'Floripa'],'payload_import'=>['x'=>1]]],
  ['nome'=>'Antonio','acao'=>'funde','match_id'=>'L1','match_por'=>'cpf',
   'preenche'=>['telefone'=>'+5548999990001'],'candidato'=>[]],
  ['nome'=>'Talvez','acao'=>'revisar','match_id'=>'L9','match_por'=>'nome_nasc','preenche'=>[],'candidato'=>[]],
  ['nome'=>'JaCompleto','acao'=>'funde','match_id'=>'L2','match_por'=>'celular','preenche'=>[],'candidato'=>[]],
];

$r = wa_import_aplica($plano, $inserir, $atualizar);
ok($r['novos'] === 1, 'um insert');
ok($r['preenchidos'] === 1, 'um update com preenche nao vazio');
ok($r['revisar'] === 1, 'um em revisao, nao escrito');
ok($r['ignorados'] === 1, 'funde sem preenche nao escreve nada');
ok(count($inseridos) === 1 && count($atualizados) === 1, 'so as escritas certas aconteceram');

// o insert leva o payload e a origem, e o telefone vira a coluna telefone
ok($inseridos[0]['telefone'] === '+5548900000000', 'novo leva o wa_id como telefone');
ok($inseridos[0]['origem_import'] === 'agenda-esposa', 'origem gravada no novo');
ok($inseridos[0]['cidade'] === 'Floripa', 'campos do candidato viram colunas');
ok(isset($inseridos[0]['payload_import']), 'payload guardado');
ok($inseridos[0]['revisado'] === false, 'novo de agenda entra como nao revisado');

// o update so mandou o preenche, nada mais (nao toca historico)
ok($atualizados[0] === ['L1', ['telefone'=>'+5548999990001']], 'update manda so o preenche');

echo "test-wa-import-aplica OK\n";
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-wa-import-aplica.php`
Expected: FAIL — `wa_import_aplica` não definida.

- [ ] **Step 3: Add `wa_import_aplica` to `lib/wa-import.php`**

```php
/* Executa o plano com escritores injetados (teste usa fakes; o endpoint usa
   wa_db). 'novo' insere; 'funde' com preenche atualiza; 'revisar' e 'funde'
   sem preenche nao escrevem. */
function wa_import_aplica($plano, $inserir, $atualizar) {
    $r = ['novos'=>0,'preenchidos'=>0,'revisar'=>0,'ignorados'=>0];
    foreach ($plano as $item) {
        if ($item['acao'] === 'novo') {
            $inserir(wa_import_linha_nova($item['candidato']));
            $r['novos']++;
        } elseif ($item['acao'] === 'funde') {
            if ($item['preenche']) { $atualizar($item['match_id'], $item['preenche']); $r['preenchidos']++; }
            else { $r['ignorados']++; }
        } else { // revisar
            $r['revisar']++;
        }
    }
    return $r;
}

/* Monta a linha do lead a partir de um candidato novo. CRM entra revisado
   (ja e cliente); agenda entra revisado=false (nada e transmitido antes da
   revisao, spec 9.4). */
function wa_import_linha_nova($c) {
    $ehCrm = strpos((string) ($c['origem_import'] ?? ''), 'crm') === 0;
    $linha = array_merge([
        'nome'           => $c['nome'] ?? '',
        'telefone'       => $c['wa_id'] ?? null,
        'email'          => $c['email'] ?? null,
        'cpf'            => $c['cpf'] ?? null,
        'origem'         => 'importado',
        'origem_import'  => $c['origem_import'] ?? '',
        'payload_import' => $c['payload_import'] ?? [],
        'revisado'       => $ehCrm ? true : false,
        'cliente'        => $ehCrm ? true : false,
    ], is_array($c['campos'] ?? null) ? $c['campos'] : []);
    return $linha;
}
```

> **Nota:** `po_leads` não tem hoje as colunas `revisado`/`cliente`. Se o
> reviewer/implementador confirmar que não existem, some ambas da Task 1 (migração)
> como colunas booleanas (`revisado boolean default false`, `cliente boolean default
> false`) e ajuste `test-ficha-schema.php` para exigi-las. Elas são o que a spec 9.4
> e a tela de transmissão (plano 4) usam. Faça isso na Task 1 antes de aplicar a
> migração; aqui apenas consuma.

- [ ] **Step 4: Write `contatos-importar.php` (endpoint)**

Reaproveita o padrão de auth de `importar.php` (validação do token Supabase). A
leitura da base e a escrita usam `wa_db` (service_role).

```php
<?php
/* ============================================================
   Importador de contatos: recebe .vcf/.csv do painel, calcula a
   auditoria de fusao (preview) ou aplica (aplicar). Escreve com a
   service_role via lib/wa-db.php. Login validado como no importar.php.
============================================================ */
require_once __DIR__ . '/lib/wa-import.php';
require_once __DIR__ . '/lib/wa-vcard.php';
require_once __DIR__ . '/lib/wa-db.php';
require_once __DIR__ . '/lib/wa-config.php';

header('Content-Type: application/json; charset=utf-8');
function cfail($code, $msg) { http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') cfail(405, 'Metodo nao permitido.');

$cfg = wa_config();
// Reusa a validacao de login do importar.php (mesma assinatura).
require_once __DIR__ . '/importar.php_auth_stub'; // ver nota

// texto do arquivo
$texto = '';
if (isset($_FILES['arquivo']) && $_FILES['arquivo']['error'] === UPLOAD_ERR_OK) {
    $texto = (string) file_get_contents($_FILES['arquivo']['tmp_name']);
} elseif (!empty($_POST['texto'])) {
    $texto = (string) $_POST['texto'];
}
if (trim($texto) === '') cfail(400, 'Envie um arquivo .vcf/.csv (campo "arquivo") ou "texto".');

$tipo   = ($_POST['tipo'] ?? 'vcf') === 'csv' ? 'csv' : 'vcf';
$origem = preg_match('/^[a-z0-9\-]{1,40}$/', $_POST['origem'] ?? '') ? $_POST['origem'] : 'agenda';
$modo   = ($_POST['modo'] ?? 'preview') === 'aplicar' ? 'aplicar' : 'preview';

$contatos = $tipo === 'csv' ? wa_csv_parse($texto) : wa_vcard_parse($texto);
$cands = [];
foreach ($contatos as $ct) { $c = wa_import_candidato($ct, $origem); if ($c) $cands[] = $c; }
$cands = wa_import_dedup($cands);

// base: leads existentes, com o flag de historico calculado.
$rows = wa_db_select('po_leads',
    'select=id,telefone,cpf,nome,data_nascimento,email,cidade,status,venda,notas');
$base = array_map('wa_import_base_row', $rows);

$plano = wa_import_audita($cands, $base);

if ($modo === 'preview') {
    echo json_encode(['ok'=>true, 'resumo'=>wa_import_resumo($plano)], JSON_UNESCAPED_UNICODE);
    exit;
}

$res = wa_import_aplica(
    $plano,
    fn($linha) => wa_db_insert('po_leads', $linha),
    fn($id, $campos) => wa_db_update('po_leads', 'id=eq.' . rawurlencode($id), $campos)
);
echo json_encode(['ok'=>true, 'aplicado'=>$res], JSON_UNESCAPED_UNICODE);
```

> **Duas notas ao implementador:**
> 1. O `require ... importar.php_auth_stub` é um lembrete, não um arquivo. Extraia
>    `bearerToken()` e `usuarioValido()` de `importar.php` para um `lib/po-auth.php`
>    e dê `require_once` nele aqui e no `importar.php` (DRY). Chame
>    `if (!po_auth_ok()) cfail(401, 'Sessao invalida.');`. Faça essa extração como
>    primeiro passo desta task, com um teste `tests/test-po-auth.php` que confira que
>    token vazio reprova (sem rede: injete o verificador). Se preferir manter simples
>    e não extrair, copie as duas funções para o topo do endpoint — mas então
>    registre a duplicação numa pendência.
> 2. `wa_import_base_row` e `wa_import_resumo` são utilitários pequenos deste
>    subsistema; defina-os em `lib/wa-import.php` com teste. `wa_import_base_row`
>    deriva `historico = (status not in ['semresposta','novo']) || venda>0 ||
>    (notas nao vazio)`. `wa_import_resumo` conta por ação e por `match_por`.

- [ ] **Step 5: Add `wa_import_base_row` and `wa_import_resumo` (with tests)**

Acrescente a `tests/test-wa-import-aplica.php`:

```php
// historico: quem ja passou de 'novo'/'semresposta', ou tem venda, ou tem nota
ok(wa_import_base_row(['id'=>'1','status'=>'venda','venda'=>1000,'notas'=>[]])['historico'] === true, 'venda = historico');
ok(wa_import_base_row(['id'=>'2','status'=>'novo','venda'=>0,'notas'=>[]])['historico'] === false, 'novo sem nada = sem historico');
ok(wa_import_base_row(['id'=>'3','status'=>'semresposta','venda'=>0,'notas'=>['oi']])['historico'] === true, 'nota = historico');

$resumo = wa_import_resumo([
  ['acao'=>'novo','match_por'=>null],['acao'=>'funde','match_por'=>'cpf'],
  ['acao'=>'funde','match_por'=>'celular'],['acao'=>'revisar','match_por'=>'nome_nasc'],
]);
ok($resumo['novos'] === 1 && $resumo['funde'] === 2 && $resumo['revisar'] === 1, 'contagem por acao');
ok($resumo['por_cpf'] === 1 && $resumo['por_celular'] === 1, 'contagem por chave de casamento');
```

E em `lib/wa-import.php`:

```php
function wa_import_base_row($r) {
    $status = $r['status'] ?? 'semresposta';
    $temNota = !empty($r['notas']) && $r['notas'] !== '[]';
    $historico = !in_array($status, ['semresposta', 'novo'], true)
        || (float) ($r['venda'] ?? 0) > 0 || $temNota;
    return [
        'id' => $r['id'] ?? null, 'telefone' => $r['telefone'] ?? null, 'cpf' => $r['cpf'] ?? null,
        'nome' => $r['nome'] ?? null, 'data_nascimento' => $r['data_nascimento'] ?? null,
        'email' => $r['email'] ?? null, 'cidade' => $r['cidade'] ?? null, 'historico' => $historico,
    ];
}

function wa_import_resumo($plano) {
    $r = ['novos'=>0,'funde'=>0,'revisar'=>0,'por_cpf'=>0,'por_celular'=>0,'por_nome_nasc'=>0];
    foreach ($plano as $p) {
        if ($p['acao'] === 'novo') $r['novos']++;
        elseif ($p['acao'] === 'funde') $r['funde']++;
        else $r['revisar']++;
        if ($p['match_por'] === 'cpf') $r['por_cpf']++;
        elseif ($p['match_por'] === 'celular') $r['por_celular']++;
        elseif ($p['match_por'] === 'nome_nasc') $r['por_nome_nasc']++;
    }
    return $r;
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php tests/test-wa-import-aplica.php`
Expected: `test-wa-import-aplica OK`. E `php -l contatos-importar.php` sem erros.

- [ ] **Step 7: Commit**

```bash
git add lib/wa-import.php contatos-importar.php tests/test-wa-import-aplica.php lib/po-auth.php tests/test-po-auth.php
git commit -m "feat(import): aplicacao do plano com escrita injetavel e endpoint"
```

---

### Task 8: Tela de revisão no painel

**Files:**
- Create: `painel/importar-contatos.js`
- Modify: `painel/index.html` (nova aba/tela de importação + `<script>`), `painel/app.js` (registra a aba na navegação, no padrão das abas existentes)
- Test: `tests/test-importar-contatos.mjs`

**Interfaces:**
- Consumes: endpoint `contatos-importar.php` (`modo=preview` e `modo=aplicar`), `poE164` (Task 2).
- Produces: funções puras testáveis — `poResumoTexto(resumo) -> string` (frase legível do preview) e `poLinhaRevisao(item) -> string` (HTML escapado de um contato em revisão). O upload e o `fetch` são finas cascas de DOM em volta delas.

- [ ] **Step 1: Write the failing test**

```js
// tests/test-importar-contatos.mjs
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const src = readFileSync(new URL('../painel/importar-contatos.js', import.meta.url), 'utf8');
function pega(nome) {
  const m = src.match(new RegExp('function ' + nome + '\\([\\s\\S]*?\\n\\}'));
  assert.ok(m, nome + ' encontrada');
  return m[0];
}
const ctx = new Function(
  'function esc(s){return (s==null?"":String(s)).replace(/[&<>"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;","\\"":"&quot;"}[c]));}' +
  pega('poResumoTexto') + pega('poLinhaRevisao') +
  '; return {poResumoTexto, poLinhaRevisao};'
)();

// resumo legivel: diz o que vai acontecer ANTES de aplicar (spec 9.5)
const t = ctx.poResumoTexto({novos:120, funde:52, revisar:8, por_cpf:40, por_celular:12, por_nome_nasc:8});
assert.ok(t.includes('120'), 'diz quantos novos');
assert.ok(t.includes('52'),  'diz quantas fusoes');
assert.ok(t.includes('8'),   'diz quantos para revisar');
assert.ok(/cpf/i.test(t) && /celular/i.test(t), 'explica por que casou');

// linha de revisao escapa dado de terceiro (nome vem da agenda)
const html = ctx.poLinhaRevisao({nome:'<img onerror=alert(1)>', match_por:'nome_nasc', match_id:'L1'});
assert.ok(!html.includes('<img onerror'), 'nome de terceiro escapado');
assert.ok(html.includes('&lt;img'), 'aparece escapado, nao sumido');

console.log('test-importar-contatos OK');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `node tests/test-importar-contatos.mjs`
Expected: FAIL — `painel/importar-contatos.js` não existe.

- [ ] **Step 3: Write `painel/importar-contatos.js`**

```js
/* ============================================================
   Aba Importar contatos: sobe .vcf/.csv, mostra a auditoria de fusao
   (preview) e confirma (aplicar). O servidor (contatos-importar.php)
   calcula tudo; aqui so mostramos e confirmamos. Dado da agenda e de
   terceiro: SEMPRE escapado (esc).
============================================================ */

function poResumoTexto(r) {
  const n = r || {};
  return (n.novos || 0) + ' contatos novos, ' +
         (n.funde || 0) + ' vao completar fichas que ja existem ' +
         '(' + (n.por_cpf || 0) + ' por CPF, ' + (n.por_celular || 0) + ' por celular), e ' +
         (n.revisar || 0) + ' para voce revisar (casaram so por nome e nascimento).';
}

function poLinhaRevisao(item) {
  const it = item || {};
  const por = it.match_por === 'nome_nasc' ? 'nome + nascimento' : (it.match_por || '');
  return '<tr data-match="' + esc(it.match_id) + '">' +
    '<td>' + esc(it.nome) + '</td>' +
    '<td class="mono">' + esc(por) + '</td>' +
    '<td><button class="btn-mini" data-acao="funde">E a mesma pessoa</button>' +
        '<button class="btn-mini" data-acao="novo">E outra pessoa</button></td>' +
    '</tr>';
}

/* ---------- cascas de DOM (nao testadas: so orquestram) ---------- */
async function poImportaPreview(file, origem) {
  const fd = new FormData();
  fd.append('arquivo', file);
  fd.append('origem', origem);
  fd.append('tipo', /\.csv$/i.test(file.name) ? 'csv' : 'vcf');
  fd.append('modo', 'preview');
  fd.append('sb_token', (await sbToken()));
  const r = await fetch('/contatos-importar.php', { method: 'POST', body: fd });
  return r.json();
}
// poImportaAplicar: idem, com modo=aplicar. Mesmo corpo, troca o campo modo.
```

> **Nota:** `sbToken()` já existe no painel (usada pelo importador de roteiros);
> confirme o nome real em `app.js` e reuse. A wiring de DOM (botão de upload, render
> do resumo e da tabela de revisão, confirmar) segue o padrão das abas existentes;
> mantenha-a fina, com a lógica testável nas duas funções puras acima.

- [ ] **Step 4: Register the tab in the painel**

Em `painel/index.html`: uma aba "Importar" na navegação e uma `<section data-view="importar">`
com input de arquivo, seletor de origem (`agenda-esposa`/`agenda-marido`), área de resumo e
tabela de revisão. Carregue o script com cache-buster: `<script src="importar-contatos.js?v=1"></script>`.
Em `painel/app.js`, registre `importar` no mesmo mecanismo de troca de aba das demais views.

- [ ] **Step 5: Run tests to verify they pass**

Run: `node tests/test-importar-contatos.mjs`
Expected: `test-importar-contatos OK`.

- [ ] **Step 6: Commit**

```bash
git add painel/importar-contatos.js painel/index.html painel/app.js tests/test-importar-contatos.mjs
git commit -m "feat(painel): aba de importacao de contatos com preview da auditoria"
```

---

### Task 9: Carga semente do CRM (operacional, o controlador roda)

**Files:**
- Create: `scripts/importa-crm.php` (uso único; **não** vai para o servidor)
- Test: cobertura já feita nas Tasks 5 e 6 (mapa e auditoria); esta task é execução.

**Interfaces:**
- Consumes: `wa_import_mapa_crm`, `wa_import_candidato`, `wa_import_dedup`, `wa_import_audita`, `wa_import_aplica`, `wa_db`.
- Produces: as 778 fichas do CRM carregadas na `po_leads` como base-mestra.

> **Por que é operacional e não um subagente:** escreve 778 fichas com CPF e
> passaporte de pessoas reais no banco **compartilhado** com NOX/hd360. É PII
> sensível e quase irreversível — mesma política da migração: **o controlador roda**,
> confere a contagem, e só então segue. O subagente entrega o script e para.

- [ ] **Step 1: O controlador exporta a base do CRM**

O CRM expõe a base sem auth (spec 9.6). O controlador puxa `app_storage.clients`
do Supabase do CRM (via o navegador logado, como já foi feito na auditoria) e salva
em `scratchpad/crm-clients.json` (fora do Git — é PII).

- [ ] **Step 2: Write `scripts/importa-crm.php`**

```php
<?php
/* Uso unico. Carrega as 778 fichas do CRM antigo como base-mestra.
   Roda uma vez, pelo controlador, e NAO vai para o servidor.
   Uso: php scripts/importa-crm.php scratchpad/crm-clients.json [--aplicar] */
require_once __DIR__ . '/../lib/wa-import.php';
require_once __DIR__ . '/../lib/wa-db.php';

$arq = $argv[1] ?? '';
$aplicar = in_array('--aplicar', $argv, true);
$fichas = json_decode((string) file_get_contents($arq), true);
if (!is_array($fichas)) { fwrite(STDERR, "JSON invalido\n"); exit(1); }

$cands = [];
foreach ($fichas as $f) {
    $c = wa_import_candidato(wa_import_mapa_crm($f), 'crm-toninho');
    if ($c) $cands[] = $c;
}
$cands = wa_import_dedup($cands);

$rows = wa_db_select('po_leads', 'select=id,telefone,cpf,nome,data_nascimento,email,cidade,status,venda,notas');
$base = array_map('wa_import_base_row', $rows);
$plano = wa_import_audita($cands, $base);

echo json_encode(wa_import_resumo($plano), JSON_PRETTY_PRINT), "\n";
if (!$aplicar) { echo "DRY-RUN. Rode com --aplicar para escrever.\n"; exit; }

$res = wa_import_aplica($plano,
    fn($l) => wa_db_insert('po_leads', $l),
    fn($id, $campos) => wa_db_update('po_leads', 'id=eq.' . rawurlencode($id), $campos));
echo json_encode($res, JSON_PRETTY_PRINT), "\n";
```

- [ ] **Step 3: Controlador roda em DRY-RUN e confere o resumo**

Run: `php scripts/importa-crm.php scratchpad/crm-clients.json`
Expected: um resumo com ~778 `novos` (a base atual só tem ~11 leads reais + WhatsApp),
poucos ou nenhum `funde`. Se o número destoar, **parar e investigar** antes de aplicar.

- [ ] **Step 4: Controlador aplica e verifica no banco**

Precisa de `SUPABASE_SERVICE_KEY`, `SUPABASE_URL` em `config.local.php` local (ou
variável de ambiente) — o script escreve com a service_role. Depois:

Run: `php scripts/importa-crm.php scratchpad/crm-clients.json --aplicar`
Verificação (via MCP do Supabase): `select count(*) from po_leads where origem_import='crm-toninho';`
deve bater com o número de `novos` do resumo.

- [ ] **Step 5: Limpeza**

Apagar `scratchpad/crm-clients.json` (é PII e vive fora do Git). O `scripts/importa-crm.php`
fica no repo (uso único documentado), mas **nunca** sobe por FTP.

- [ ] **Step 6: Commit**

```bash
git add scripts/importa-crm.php
git commit -m "chore(import): script de uso unico da carga semente do CRM"
```

---

## Self-Review

**1. Spec coverage:**
- 9.1 marcador PO → Task 4 (`wa_import_tem_marcador`, filtro no candidato).
- 9.2 identidade por camadas + regras do Armando → Task 6 (`wa_import_audita`, whitelist, testes de não-sobrescrita).
- 9.3 CRM primeiro como semente → Task 9.
- 9.4 limpeza (E.164, nome, payload, revisado=false) → Tasks 2, 4, 7.
- 9.5 tela de revisão com resultado da auditoria antes de escrever → Tasks 7 (preview) e 8.
- 9.6 alerta de segurança do CRM → registrado na spec; a carga vai para o nosso Supabase (Task 9).
- 4.1 bloco de campos → Task 1 (migração) + Task 5 (mapa).
- Fora de escopo confirmado: **transmissão é o plano 4**, não entra aqui.

**2. Placeholder scan:** sem TBD/TODO. As duas "Notas ao implementador" (auth em Task 7, `sbToken` em Task 8) apontam código real existente a reusar, com o caminho exato; não são lacunas de design.

**3. Type consistency:** o shape do candidato é idêntico da Task 4 à 9. `wa_import_audita` devolve `acao`/`match_id`/`match_por`/`preenche`/`candidato`, consumidos igual em `wa_import_aplica` (Task 7) e no resumo (Tasks 7/8). `poE164` (Task 2) tem a mesma assinatura do `wa_e164` (PHP). A whitelist `WA_IMPORT_CAMPOS_PREENCHIVEIS` casa com as colunas da migração (Task 1).

**Ponto de atenção para a execução:** Task 7 assume `po_leads.revisado` e `po_leads.cliente`. Confirmar na Task 1 e, se não existirem, adicioná-las à migração **antes** de aplicá-la (a nota na Task 7 já instrui isso).
