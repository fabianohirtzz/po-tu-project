# Revisão de contatos, verdade do preview e relatórios — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Destravar o uso real do importador antes das duas agendas chegarem: dar à cliente uma tela para revisar e marcar contatos, mostrar no preview quais fusões preenchem quais campos, impedir que reaplicar o mesmo arquivo duplique a base, e tirar as 775 fichas importadas do denominador dos relatórios.

**Architecture:** Quatro correções independentes sobre o que o plano 3 entregou. Uma tabela nova (`po_import_lotes`) dá idempotência ao endpoint; o resto é painel em JS puro lendo dados que a `po_leads` já tem. Nenhuma mexe no motor de fusão, que está revisado e travado por teste.

**Tech Stack:** PHP 8 no cPanel (sem Composer, sem build), Supabase REST, JS puro no painel, runner próprio `tests/run.php` (`test-*.php` e `test-*.mjs`).

**Spec:** `docs/superpowers/specs/2026-09-11-automacao-whatsapp-crm-design.md` (seções 9.5 e 8.1)

## Global Constraints

- **"Contato não revisado nunca entra em campanha" é defesa obrigatória** (spec 8.1). Hoje não existe caminho no painel para um contato virar `revisado=true` — é isso que a Task 4 conserta.
- **A spec 9.5 exige, com todas as letras:** mostrar "**quais fusões vão preencher campos** — para a decisão ser vista, não adivinhada", e "lista em lotes, com ação em massa para marcar cliente".
- **As três regras do dono continuam valendo** e nenhuma task pode afrouxá-las: o registro com histórico é o dono; a fusão só soma, nunca zera; nunca duplicar.
- **`WA_IMPORT_CAMPOS_PREENCHIVEIS` é a trava estrutural.** Nenhuma escrita nova pode tocar `status`, `venda`, `venda_at`, `notas` ou `qualif_*`.
- **Dado de terceiro é sempre escapado** no painel com `esc()` — o nome vem da agenda de outra pessoa.
- **Nunca usar dado real de cliente em fixture.** O repositório é público; já houve dois incidentes. O CPF sintético do projeto é `111.444.777-35`.
- **Nenhum teste toca a rede.** Escrita e leitura de banco entram por transporte injetável (`wa_db_set_transport`).
- **`service_role` só no servidor** (`wa_config()`); o painel usa `anon key` + login.
- **Migração em banco compartilhado (NOX/hd360) é aplicada pelo controlador**, nunca por subagente.
- **JS puro, sem build e sem npm.** Testes JS extraem a função do fonte por regex, então **nenhuma constante de topo de arquivo de que a função dependa** (já quebrou três vezes neste projeto; ponha dentro da função).
- **Todo `<script>`/`<link>` alterado precisa de `?v=` novo** — o `.htaccess` cacheia JS/CSS por 1 mês.
- **Edições em `painel/index.html` são ADITIVAS:** os scripts existentes (`config.js`, `video-encode.js`, `fone.js`, `app.js`, `clientes.js`, `funil.js`, `conversa.js`, `importar-contatos.js`) são preservados.
- PHP 8 sem Composer; testes com `ok($cond,$msg)`+`exit(1)` e `node:assert/strict`; a suíte (`php tests/run.php`, hoje **31 arquivos**) fica verde.

---

### Task 1: Tabela de lotes de importação e `wa_id` no insert

**Files:**
- Create: `supabase/migrations/2026-09-12-import-lotes.sql`
- Modify: `lib/wa-import.php` (a função `wa_import_linha_nova`)
- Test: `tests/test-import-lotes-schema.php`, `tests/test-wa-import-aplica.php` (acrescentar asserções)

**Interfaces:**
- Consumes: `WA_IMPORT_CAMPOS_PREENCHIVEIS`, `wa_import_eh_crm()` (já existem em `lib/wa-import.php`).
- Produces: tabela `po_import_lotes` (`id`, `hash` único, `origem`, `arquivo`, `total`, `novos`, `preenchidos`, `created_at`); e `wa_import_linha_nova()` passa a gravar `wa_id` quando o candidato tem celular.

> **Por que `wa_id` no insert:** o motor do WhatsApp casa lead por `wa_id`
> (`wa_lead()` em `lib/wa-motor.php` consulta `wa_id=eq.` e **insere** quando não
> acha). O importador gravava só `telefone`, então a ficha rica (CPF, passaporte)
> duplicaria na primeira mensagem que a pessoa mandasse. As 22 fichas que já
> entraram foram corrigidas por backfill (`2026-09-12-pos-carga-crm.sql`); isto
> fecha para as próximas importações.

- [ ] **Step 1: Write the failing schema test**

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-import-lotes-schema.php`
Expected: FAIL — arquivo de migração não existe.

- [ ] **Step 3: Write the migration**

```sql
-- supabase/migrations/2026-09-12-import-lotes.sql
-- Idempotencia da importacao. Sem isto, reaplicar o mesmo .vcf duplica toda
-- ficha que nao tem CPF nem celular para reconciliar (na base de hoje, 615 das
-- 775 nao tem telefone nenhum). O gatilho e realista: o fetch estoura o timeout
-- depois de o servidor ja ter gravado, e a reacao natural e clicar de novo.
create table if not exists public.po_import_lotes (
  id           uuid primary key default gen_random_uuid(),
  hash         text not null unique,
  origem       text not null,
  arquivo      text,
  total        integer not null default 0,
  novos        integer not null default 0,
  preenchidos  integer not null default 0,
  created_at   timestamptz not null default now()
);

alter table public.po_import_lotes enable row level security;

-- Mesmo padrao das demais: quem esta logado no painel le e escreve; o PHP usa a
-- service_role, que ignora RLS e so existe no servidor.
drop policy if exists po_import_lotes_rw_auth on public.po_import_lotes;
create policy po_import_lotes_rw_auth on public.po_import_lotes
  for all to authenticated using (true) with check (true);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test-import-lotes-schema.php`
Expected: `test-import-lotes-schema OK`.

- [ ] **Step 5: Write the failing test for `wa_id`**

Acrescente ao fim de `tests/test-wa-import-aplica.php`, antes do `echo` final:

```php
// O motor casa lead por wa_id e INSERE quando nao acha. Sem gravar wa_id aqui,
// a ficha rica duplica na primeira mensagem que a pessoa mandar.
$comCel = wa_import_linha_nova([
  'wa_id' => '+5548999990001', 'celulares' => ['+5548999990001'], 'fixos' => [],
  'nome' => 'Ana', 'email' => null, 'cpf' => null, 'data_nascimento' => null,
  'origem_import' => 'agenda-esposa', 'campos' => [], 'payload_import' => [],
]);
ok($comCel['wa_id'] === '+5548999990001', 'candidato com celular grava wa_id');
ok($comCel['telefone'] === '+5548999990001', 'e o telefone continua preenchido');

$soFixo = wa_import_linha_nova([
  'wa_id' => null, 'celulares' => [], 'fixos' => ['+554832220000'],
  'nome' => 'Loja', 'email' => null, 'cpf' => null, 'data_nascimento' => null,
  'origem_import' => 'agenda-esposa', 'campos' => [], 'payload_import' => [],
]);
ok(!array_key_exists('wa_id', $soFixo) || $soFixo['wa_id'] === null,
   'so com fixo NAO grava wa_id: fixo nao tem WhatsApp e nao casa identidade');
```

- [ ] **Step 6: Run test to verify it fails**

Run: `php tests/test-wa-import-aplica.php`
Expected: FAIL em `candidato com celular grava wa_id`.

- [ ] **Step 7: Implement**

Em `lib/wa-import.php`, dentro de `wa_import_linha_nova()`, acrescente `wa_id` ao array base (logo depois de `'telefone'`):

```php
        // O motor do WhatsApp casa lead por wa_id (wa_lead() consulta wa_id=eq.
        // e INSERE quando nao acha). Sem isto, a ficha rica duplicaria na
        // primeira mensagem. So celular vira wa_id: fixo nao tem WhatsApp.
        'wa_id'          => $c['wa_id'] ?? null,
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `php tests/run.php`
Expected: todos verdes (32 arquivos, com o novo de schema).

- [ ] **Step 9: Controlador aplica a migração (NÃO o subagente)**

O banco é compartilhado com NOX/hd360. O subagente **para aqui** e reporta DONE com a nota "migração pronta para o controlador aplicar".

- [ ] **Step 10: Commit**

```bash
git add supabase/migrations/2026-09-12-import-lotes.sql tests/test-import-lotes-schema.php lib/wa-import.php tests/test-wa-import-aplica.php
git commit -m "feat(import): tabela de lotes e wa_id no insert do importador"
```

---

### Task 2: Idempotência — reaplicar o mesmo arquivo não duplica

**Files:**
- Modify: `contatos-importar.php`, `lib/wa-import.php`
- Test: `tests/test-contatos-importar.php` (acrescentar casos)

**Interfaces:**
- Consumes: `po_import_lotes` (Task 1), `wa_db_select_estrito`, `wa_db_insert`.
- Produces: `wa_import_hash_lote($texto, $origem) -> string` (sha256 do conteúdo + origem); o endpoint recusa com **409** um lote já aplicado, e aceita `forcar=1` para aplicar mesmo assim.

> **Por que hash do conteúdo e não do nome do arquivo:** a cliente vai exportar a
> agenda de dois aparelhos, e os dois arquivos podem se chamar `contatos.vcf`.
> O nome não identifica nada; o conteúdo sim. E a origem entra no hash porque o
> **mesmo** arquivo aplicado como `agenda-esposa` e depois como `agenda-marido`
> é erro do operador, não uma segunda importação legítima.

- [ ] **Step 1: Write the failing test**

Acrescente a `tests/test-contatos-importar.php`, no mesmo formato dos casos existentes (cada caso roda o endpoint num processo próprio, com `wa_db_set_transport` e `po_auth_set_verificador` injetados):

```php
// --- lote repetido: o segundo aplicar e recusado, sem escrever nada
$r = ci_post('lote-repetido', ['modo' => 'aplicar', 'origem' => 'agenda-esposa', 'tipo' => 'vcf']);
ok($r['codigo'] === 409, 'lote ja aplicado responde 409');
ok($r['json']['ok'] === false, 'e nao diz ok');
ok(!in_array('POST', ci_metodos($r), true), 'nenhuma escrita de lead');
ok(strpos(strtolower($r['json']['error'] ?? ''), 'ja foi importado') !== false,
   'a mensagem explica que o arquivo ja foi importado');

// --- com forcar=1, aplica mesmo assim
$r = ci_post('lote-repetido-forcado', ['modo' => 'aplicar', 'origem' => 'agenda-esposa',
                                       'tipo' => 'vcf', 'forcar' => '1']);
ok($r['codigo'] === 200, 'forcar=1 aplica mesmo com lote repetido');

// --- o preview NUNCA e bloqueado pelo lote: ver de novo nao escreve nada
$r = ci_post('lote-repetido', ['modo' => 'preview', 'origem' => 'agenda-esposa', 'tipo' => 'vcf']);
ok($r['codigo'] === 200, 'preview de lote repetido continua funcionando');

// --- o hash muda com o conteudo e com a origem
ok(wa_import_hash_lote('AAA', 'agenda-esposa') === wa_import_hash_lote('AAA', 'agenda-esposa'),
   'mesmo conteudo e mesma origem dao o mesmo hash');
ok(wa_import_hash_lote('AAA', 'agenda-esposa') !== wa_import_hash_lote('BBB', 'agenda-esposa'),
   'conteudo diferente muda o hash');
ok(wa_import_hash_lote('AAA', 'agenda-esposa') !== wa_import_hash_lote('AAA', 'agenda-marido'),
   'origem diferente muda o hash: o mesmo arquivo em duas origens e engano do operador');
```

No transporte falso do arquivo de teste, o caso `lote-repetido` responde à consulta de `po_import_lotes` com uma linha existente; `lote-repetido-forcado` idem. Siga o formato do `switch` que já existe nos outros casos.

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-contatos-importar.php`
Expected: FAIL — `wa_import_hash_lote` não definida.

- [ ] **Step 3: Implement the hash**

Em `lib/wa-import.php`:

```php
/* Identidade de um lote de importacao. O conteudo, nao o nome do arquivo: a
   cliente vai exportar dois aparelhos e os dois podem se chamar contatos.vcf.
   A origem entra no hash porque o MESMO arquivo aplicado como agenda-esposa e
   depois como agenda-marido e engano do operador, nao segunda importacao. */
function wa_import_hash_lote($texto, $origem) {
    return hash('sha256', (string) $origem . "\0" . (string) $texto);
}
```

- [ ] **Step 4: Implement the guard in the endpoint**

Em `contatos-importar.php`, **depois** da leitura do texto e da validação de `origem`, e **antes** de qualquer escrita (ou seja, dentro do ramo `modo === 'aplicar'`):

```php
$hash   = wa_import_hash_lote($texto, $origem);
$forcar = cpost('forcar') === '1';

if ($modo === 'aplicar' && !$forcar) {
    // Leitura estrita: se a consulta falhar, NAO seguimos achando que o lote e
    // novo — isso era exatamente o buraco que duplicava a base inteira.
    $ja = wa_db_select_estrito('po_import_lotes',
        'select=id,created_at&hash=eq.' . rawurlencode($hash) . '&limit=1');
    if ($ja === null) cfail(503, 'Nao consegui conferir se este arquivo ja foi importado. Nada foi gravado.');
    if (!empty($ja)) {
        cfail(409, 'Este arquivo ja foi importado nesta origem. Se quiser importar mesmo assim, marque "importar novamente".');
    }
}
```

E, **depois** do `wa_import_aplica`, registre o lote:

```php
// Grava o lote DEPOIS da escrita: se a importacao falhou no meio, o lote nao
// fica registrado e a cliente consegue tentar de novo sem precisar do forcar.
wa_db_insert('po_import_lotes', [
    'hash'        => $hash,
    'origem'      => $origem,
    'arquivo'     => substr((string) ($_FILES['arquivo']['name'] ?? ''), 0, 120),
    'total'       => $resumo['total'] ?? 0,
    'novos'       => $res['novos'] ?? 0,
    'preenchidos' => $res['preenchidos'] ?? 0,
], true);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php tests/run.php`
Expected: todos verdes.

- [ ] **Step 6: Commit**

```bash
git add lib/wa-import.php contatos-importar.php tests/test-contatos-importar.php
git commit -m "feat(import): reaplicar o mesmo arquivo nao duplica mais a base"
```

---

### Task 3: O preview mostra quais fusões preenchem quais campos

**Files:**
- Modify: `painel/importar-contatos.js`, `painel/index.html`, `painel/painel.css`
- Test: `tests/test-importar-contatos.mjs` (acrescentar casos)

**Interfaces:**
- Consumes: a resposta de `modo=preview`, que **já traz** `itens[]` com `nome`, `acao`, `match_por`, `match_id` e `preenche` (lista de **nomes de coluna**) — ver `contatos-importar.php`, onde `preenche` é montado com `array_keys`.
- Produces: `poLinhaFusao(item) -> string` (HTML escapado de uma fusão, com os campos que serão preenchidos).

> **Por que existe:** a spec 9.5 exige mostrar "quais fusões vão preencher campos
> — para a decisão ser vista, não adivinhada". O dado já vem na resposta; a tela
> filtra `acao === 'revisar'` e **descarta todos os `funde`**. A cliente autoriza
> uma escrita sem ver o que ela preenche.

- [ ] **Step 1: Write the failing test**

Acrescente a `tests/test-importar-contatos.mjs`:

```js
const { poLinhaFusao } = new Function(
  'function esc(s){return (s==null?"":String(s)).replace(/[&<>"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;","\\"":"&quot;"}[c]));}' +
  pega('poLinhaFusao') + '; return {poLinhaFusao};'
)();

// a fusao diz QUAIS campos vai preencher, com nome legivel
const h = poLinhaFusao({nome:'Maria', match_por:'cpf', match_id:'L1',
                        preenche:['telefone','cidade','passaporte_numero']});
assert.ok(h.includes('Maria'), 'mostra o nome');
assert.ok(/cpf/i.test(h), 'diz por que casou');
assert.ok(/telefone/i.test(h) && /cidade/i.test(h), 'lista os campos que vai preencher');
assert.ok(/passaporte/i.test(h), 'inclusive os do bloco de ficha');

// fusao sem campo nenhum precisa dizer isso, senao a cliente acha que vai mudar algo
const vazio = poLinhaFusao({nome:'Joao', match_por:'celular', match_id:'L2', preenche:[]});
assert.ok(/nada a preencher|nenhum campo/i.test(vazio),
  'fusao sem campo diz que nao preenche nada');

// dado de terceiro escapado: o nome vem da agenda de outra pessoa
const mal = poLinhaFusao({nome:'<img src=x onerror=alert(1)>', match_por:'cpf',
                          match_id:'L3', preenche:['cidade']});
assert.ok(!mal.includes('<img src=x'), 'nome de terceiro escapado');
assert.ok(mal.includes('&lt;img'), 'aparece escapado, nao sumido');

// nome de coluna tambem e escapado: vem do servidor, mas nao custa
const colMal = poLinhaFusao({nome:'Ana', match_por:'cpf', match_id:'L4',
                             preenche:['<script>']});
assert.ok(!colMal.includes('<script>'), 'nome de coluna escapado');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `node tests/test-importar-contatos.mjs`
Expected: FAIL — `poLinhaFusao` não encontrada.

- [ ] **Step 3: Implement**

Em `painel/importar-contatos.js`:

```js
/* A spec 9.5 exige que as fusoes sejam VISTAS, nao adivinhadas: a cliente
   autoriza a escrita, entao ela precisa ver o que cada fusao vai preencher.
   O rotulo legivel fica DENTRO da funcao de proposito — o teste extrai a
   funcao do fonte por regex e uma constante de topo de arquivo daria
   ReferenceError (ja aconteceu tres vezes neste projeto). */
function poLinhaFusao(item) {
  const it = item || {};
  const rotulos = {
    telefone: 'telefone', telefone_secundario: 'telefone fixo', email: 'e-mail',
    nome: 'nome', cpf: 'CPF', rg: 'RG', data_nascimento: 'nascimento',
    nacionalidade: 'nacionalidade', estado_civil: 'estado civil', profissao: 'profissao',
    cep: 'CEP', endereco: 'endereco', numero: 'numero', complemento: 'complemento',
    bairro: 'bairro', cidade: 'cidade', estado: 'estado',
    passaporte_numero: 'passaporte', passaporte_orgao_emissor: 'orgao do passaporte',
    passaporte_emissao: 'emissao do passaporte', passaporte_validade: 'validade do passaporte',
    contato_emergencia_nome: 'contato de emergencia',
    contato_emergencia_telefone: 'telefone de emergencia',
    contato_emergencia_parentesco: 'parentesco de emergencia',
    observacoes: 'observacoes', origem_manual: 'origem',
  };
  const campos = Array.isArray(it.preenche) ? it.preenche : [];
  const por = it.match_por === 'cpf' ? 'CPF' : (it.match_por === 'celular' ? 'celular' : (it.match_por || ''));
  const lista = campos.length
    ? campos.map(c => '<span class="ic-campo">' + esc(rotulos[c] || c) + '</span>').join(' ')
    : '<span class="ic-campo ic-campo--vazio">nenhum campo a preencher</span>';
  return '<tr>' +
    '<td>' + esc(it.nome) + '</td>' +
    '<td class="mono">' + esc(por) + '</td>' +
    '<td>' + lista + '</td>' +
    '</tr>';
}
```

- [ ] **Step 4: Render it**

Em `painel/index.html`, dentro da seção `data-view="importar"`, acrescente uma tabela irmã da de revisão:

```html
<h3 class="ic-h3">Fichas que vao ser completadas</h3>
<p class="ic-sub">Estes contatos ja existem no painel. A importacao so preenche o
que esta em branco: nada que ja tem valor e substituido.</p>
<table class="tbl"><thead><tr><th>Contato</th><th>Casou por</th><th>Vai preencher</th></tr></thead>
<tbody id="ic-fusoes"></tbody></table>
```

E no `painel/importar-contatos.js`, no ponto onde hoje se filtra `acao === 'revisar'`, acrescente o irmão:

```js
  const fusoes = (j.itens || []).filter(i => i.acao === 'funde');
  const tbF = $('#ic-fusoes');
  if (tbF) tbF.innerHTML = fusoes.length
    ? fusoes.map(poLinhaFusao).join('')
    : '<tr><td colspan="3"><div class="empty">Nenhuma ficha existente sera completada.</div></td></tr>';
```

Em `painel/painel.css`, no bloco de importação:

```css
.ic-campo{display:inline-block;padding:1px 7px;margin:1px 2px 1px 0;border-radius:10px;
  background:var(--surface);border:1px solid var(--border);font-size:.78rem;color:var(--ink)}
.ic-campo--vazio{color:var(--muted);font-style:italic}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `node tests/test-importar-contatos.mjs && php tests/run.php`
Expected: todos verdes.

- [ ] **Step 6: Bump the cache-busters**

Em `painel/index.html`: `importar-contatos.js?v=3` e `painel.css?v=4`. Preserve todas as outras tags de script.

- [ ] **Step 7: Commit**

```bash
git add painel/importar-contatos.js painel/index.html painel/painel.css tests/test-importar-contatos.mjs
git commit -m "feat(painel): o preview mostra quais fusoes preenchem quais campos"
```

---

### Task 4: Tela de revisão — marcar contato como cliente revisado

**Files:**
- Create: `painel/revisao.js`
- Modify: `painel/index.html`, `painel/app.js`, `painel/painel.css`
- Test: `tests/test-revisao.mjs`

**Interfaces:**
- Consumes: `esc()`, `sb` (cliente Supabase), `toast()` — todos globais já existentes em `painel/app.js`.
- Produces: `poRevFiltra(leads) -> array` (só os não revisados vindos de importação), `poLinhaRevisaoContato(lead) -> string` (HTML escapado com checkbox), `poRevSelecionados(container) -> array` de ids.

> **Por que esta tela é o item mais importante do plano:** a spec 8.1 diz que
> "contato não revisado nunca entra em campanha" é **defesa obrigatória**. Hoje
> todo contato de agenda entra `revisado=false` e **não existe caminho nenhum no
> painel para virar `true`** — ou seja, a importação de agenda é um beco sem
> saída e o plano 4 (transmissão) não tem de onde tirar uma lista legítima.

- [ ] **Step 1: Write the failing test**

```js
// tests/test-revisao.mjs
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const src = readFileSync(new URL('../painel/revisao.js', import.meta.url), 'utf8');
function pega(nome) {
  const m = src.match(new RegExp('function ' + nome + '\\([\\s\\S]*?\\n\\}'));
  assert.ok(m, nome + ' encontrada no revisao.js');
  return m[0];
}
const ctx = new Function(
  'function esc(s){return (s==null?"":String(s)).replace(/[&<>"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;","\\"":"&quot;"}[c]));}' +
  pega('poRevFiltra') + pega('poLinhaRevisaoContato') +
  '; return {poRevFiltra, poLinhaRevisaoContato};'
)();
const { poRevFiltra, poLinhaRevisaoContato } = ctx;

const base = [
  {id:'1', nome:'Ana',   origemImport:'agenda-esposa', revisado:false, cliente:false, tel:'+5548999990001'},
  {id:'2', nome:'Bruno', origemImport:'agenda-esposa', revisado:true,  cliente:true,  tel:'+5548999990002'},
  {id:'3', nome:'Carla', origemImport:'crm-toninho',   revisado:true,  cliente:true,  tel:'—'},
  {id:'4', nome:'Davi',  origemImport:'',              revisado:false, cliente:false, tel:'+5548999990004'},
];

// So entra quem veio de importacao E ainda nao foi revisado.
const pend = poRevFiltra(base);
assert.equal(pend.length, 1, 'so um contato pendente de revisao');
assert.equal(pend[0].nome, 'Ana', 'e o certo');

// Lead do formulario do site NAO entra aqui: ele nunca foi "importado" e a
// revisao existe para a agenda, que tem medico e fornecedor no meio.
assert.ok(!pend.some(p => p.nome === 'Davi'), 'lead do site nao vai para a fila de revisao');

// Entradas degeneradas nao derrubam a tela.
assert.deepEqual(poRevFiltra([]), [], 'lista vazia');
assert.deepEqual(poRevFiltra([null, undefined, 'lixo', 42]), [], 'lixo e ignorado');

// A linha carrega o id para a acao em massa, e escapa o nome.
const html = poLinhaRevisaoContato({id:'x1', nome:'<img src=x onerror=alert(1)>',
                                    tel:'+5548999990001', origemImport:'agenda-esposa'});
assert.ok(html.includes('value="x1"'), 'a linha carrega o id');
assert.ok(!html.includes('<img src=x'), 'nome de terceiro escapado');
assert.ok(html.includes('&lt;img'), 'aparece escapado, nao sumido');
assert.ok(/type="checkbox"/.test(html), 'tem caixa de selecao para a acao em massa');

console.log('test-revisao OK');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `node tests/test-revisao.mjs`
Expected: FAIL — `painel/revisao.js` não existe.

- [ ] **Step 3: Write `painel/revisao.js`**

```js
/* ============================================================
   Fila de revisao de contatos importados.

   A spec 8.1 chama "contato nao revisado nunca entra em campanha" de defesa
   OBRIGATORIA: a agenda do celular nao e lista de consentimento, tem medico,
   dentista e fornecedor no meio. Sem esta tela, todo contato de agenda entra
   revisado=false e NAO HA CAMINHO para virar true — a importacao vira um beco
   sem saida e a transmissao nao tem lista legitima de onde sair.
============================================================ */

function poRevFiltra(leads) {
  return (leads || []).filter(l => {
    if (!l || typeof l !== 'object') return false;
    const origem = l.origemImport == null ? '' : String(l.origemImport);
    // So contato IMPORTADO entra na fila. Lead do formulario do site nunca
    // passou pela agenda e nao tem o que revisar.
    return origem !== '' && l.revisado !== true;
  });
}

function poLinhaRevisaoContato(lead) {
  const l = lead || {};
  return '<tr>' +
    '<td><input type="checkbox" class="rev-chk" value="' + esc(l.id) + '"></td>' +
    '<td>' + esc(l.nome) + '</td>' +
    '<td class="mono">' + esc(l.tel) + '</td>' +
    '<td class="mono">' + esc(l.origemImport) + '</td>' +
    '</tr>';
}

function poRevSelecionados(container) {
  const raiz = container || document;
  return [...raiz.querySelectorAll('.rev-chk:checked')].map(c => c.value);
}

/* ---------- render e acao em massa (casca de DOM) ---------- */

function poRenderRevisao() {
  const pend = poRevFiltra(typeof LEADS !== 'undefined' ? LEADS : []);
  const tb = document.querySelector('#rev-rows');
  if (!tb) return;
  tb.innerHTML = pend.length
    ? pend.map(poLinhaRevisaoContato).join('')
    : '<tr><td colspan="4"><div class="empty">Nenhum contato aguardando revisao.</div></td></tr>';
  const n = document.querySelector('#rev-contagem');
  if (n) n.textContent = pend.length;
}

async function poRevMarcar(cliente) {
  const ids = poRevSelecionados(document.querySelector('#rev-rows'));
  if (!ids.length) { toast('Selecione ao menos um contato.', true); return; }
  // revisado=true sempre: a revisao ACONTECEU, com qualquer resposta. O que
  // varia e se a pessoa e cliente (entra em campanha) ou nao (fica na base,
  // fora de disparo).
  const { error } = await sb.from('po_leads')
    .update({ revisado: true, cliente: !!cliente })
    .in('id', ids);
  if (error) { toast('Erro ao salvar: ' + error.message, true); return; }
  ids.forEach(id => {
    const l = (typeof LEADS !== 'undefined' ? LEADS : []).find(x => String(x.id) === String(id));
    if (l) { l.revisado = true; l.cliente = !!cliente; }
  });
  poRenderRevisao();
  toast(ids.length + (ids.length === 1 ? ' contato revisado.' : ' contatos revisados.'));
}
```

- [ ] **Step 4: `mapRow` passa a carregar os campos novos**

Em `painel/app.js`, dentro de `mapRow(r)`, acrescente ao objeto devolvido (o `select('*')` já traz as colunas):

```js
    origemImport:r.origem_import||'',revisado:r.revisado===true,cliente:r.cliente===true,
```

- [ ] **Step 5: Registre a aba**

Em `painel/index.html`, uma aba `data-view="revisao"` na navegação (com `<span id="rev-contagem">`), e a seção:

```html
<section data-view="revisao" id="view-revisao">
  <h2>Contatos aguardando revisao</h2>
  <p class="ic-sub">Vieram das agendas de celular. A agenda tem medico, fornecedor
  e familia no meio, entao ninguem entra em disparo antes de voce conferir.</p>
  <div class="rev-acoes">
    <button id="rev-cliente" class="btn">Marcar como cliente</button>
    <button id="rev-naocliente" class="btn btn--ghost">Nao e cliente</button>
  </div>
  <table class="tbl"><thead><tr>
    <th><input type="checkbox" id="rev-todos"></th><th>Nome</th><th>Telefone</th><th>Origem</th>
  </tr></thead><tbody id="rev-rows"></tbody></table>
</section>
<script src="revisao.js?v=1"></script>
```

Ligue os botões no mesmo lugar onde os outros são ligados em `painel/app.js`:

```js
$('#rev-cliente').onclick=()=>poRevMarcar(true);
$('#rev-naocliente').onclick=()=>poRevMarcar(false);
$('#rev-todos').onchange=e=>$$('#rev-rows .rev-chk').forEach(c=>{c.checked=e.target.checked;});
```

E acrescente `revisao` ao mecanismo de troca de aba, no mesmo padrão de `importar`.

- [ ] **Step 6: Run tests to verify they pass**

Run: `node tests/test-revisao.mjs && php tests/run.php`
Expected: todos verdes.

- [ ] **Step 7: Commit**

```bash
git add painel/revisao.js painel/index.html painel/app.js painel/painel.css tests/test-revisao.mjs
git commit -m "feat(painel): fila de revisao de contatos importados com acao em massa"
```

---

### Task 5: Relatórios param de contar a base importada

**Files:**
- Modify: `painel/app.js`, `painel/index.html`
- Test: `tests/test-filtros.mjs` (acrescentar casos)

**Interfaces:**
- Consumes: `origemImport` em cada lead (Task 4, Step 4).
- Produces: `rowsForReports()` passa a excluir contatos importados; um controle `#rep-importados` liga/desliga isso à vista.

> **O que aconteceu:** a carga do CRM entrou com `created_at = now()` e
> `status='semresposta'`, então as 775 fichas caíram no mês corrente e a taxa de
> conversão foi a ~0%. Elas não são leads captados este mês: são clientes antigos
> cadastrados em outro sistema. Contá-las no denominador de conversão mede a
> coisa errada.
>
> **Por que um controle e não simplesmente esconder:** faturamento histórico e
> base de clientes são perguntas legítimas. O padrão é **fora** (a pergunta comum
> é "como foi meu mês"), e o controle deixa a cliente ver o total quando quiser,
> em vez de ficar com um número que ela não consegue explicar.

- [ ] **Step 1: Write the failing test**

Acrescente a `tests/test-filtros.mjs`, no molde das asserções de fonte que o arquivo já usa:

```js
// Os relatorios nao podem contar a base importada no denominador: as 775 fichas
// do CRM entraram com created_at=hoje e derrubariam a conversao do mes para ~0.
assert.ok(/function rowsForReports\(\)\{[\s\S]*?origemImport/.test(src),
  'rowsForReports leva origemImport em conta');
assert.ok(/F\.importados/.test(src),
  'existe um estado de filtro para incluir ou nao os importados');

// E a aba Leads NAO muda: ela sempre mostrou tudo, e continuar mostrando e o
// que permite a cliente achar a ficha de um cliente antigo.
const leads = src.match(/function renderLeads\(\)[\s\S]*?\n\}/);
assert.ok(leads, 'renderLeads encontrada');
assert.ok(!/origemImport/.test(leads[0]),
  'a aba Leads segue mostrando todo mundo, inclusive os importados');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `node tests/test-filtros.mjs`
Expected: FAIL — `rowsForReports` não menciona `origemImport`.

- [ ] **Step 3: Implement**

Em `painel/app.js`, no objeto de filtros `F`, acrescente `importados:false`. Depois troque `rowsForReports`:

```js
/* A base importada fica FORA do relatorio por padrao. As 775 fichas do CRM
   entraram com created_at=hoje e status=semresposta: contadas no denominador,
   derrubam a conversao do mes para perto de zero e medem a coisa errada — elas
   nao sao leads captados este mes, sao clientes antigos de outro sistema.
   O controle deixa ver o total quando a pergunta for essa. */
function rowsForReports(){
  return LEADS.filter(inMonth)
              .filter(l=>F.importados||!l.origemImport)
              .filter(l=>F.orig==='todos'||l.origem===F.orig);
}
```

- [ ] **Step 4: The control**

Em `painel/index.html`, dentro da seção de relatórios, perto dos filtros existentes:

```html
<label class="rep-opt"><input type="checkbox" id="rep-importados">
  Incluir a base importada (<span id="rep-imp-n">0</span> contatos)</label>
```

Em `painel/app.js`, junto dos outros handlers de filtro:

```js
$('#rep-importados').onchange=e=>{F.importados=e.target.checked;renderReports();};
```

E dentro de `renderReports()`, preencha a contagem:

```js
  const nImp=LEADS.filter(inMonth).filter(l=>l.origemImport).length;
  const elImp=$('#rep-imp-n'); if(elImp)elImp.textContent=nImp;
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `node tests/test-filtros.mjs && php tests/run.php`
Expected: todos verdes.

- [ ] **Step 6: Bump the cache-buster**

Em `painel/index.html`: `app.js?v=4`. Preserve todas as outras tags.

- [ ] **Step 7: Commit**

```bash
git add painel/app.js painel/index.html tests/test-filtros.mjs
git commit -m "fix(painel): relatorios deixam de contar a base importada no denominador"
```

---

## Self-Review

**1. Cobertura da spec.**
- 9.5 "mostrar quais fusões vão preencher campos" → Task 3.
- 9.5 "lista em lotes, com ação em massa para marcar cliente" → Task 4.
- 8.1 "contato não revisado nunca entra em campanha" → Task 4 é o que torna a defesa *usável*: sem ela não há caminho para `revisado=true`.
- O que **não** está aqui e continua pendência conhecida: os "ambíguos lado a lado" da 9.5 seguem como texto explicativo (decisão registrada no plano 3, aceita — botão sem endpoint é pior que texto honesto); e a decisão por item no `revisar` continua sem endpoint.

**2. Placeholders.** Nenhum "TBD"/"TODO". Todo passo de código traz o código.

**3. Consistência de tipos.** `origemImport`/`revisado`/`cliente` são criados em Task 4 Step 4 (`mapRow`) e consumidos em Task 4 (`poRevFiltra`) e Task 5 (`rowsForReports`) com o mesmo nome e tipo. `preenche` é lista de strings em Task 3, como `contatos-importar.php` já devolve (`array_keys`). `wa_import_hash_lote($texto,$origem)` é definida na Task 2 e usada só lá.

**Ordem importa:** a Task 5 depende do `mapRow` da Task 4 Step 4. Se as tasks forem executadas fora de ordem, mova aquele passo para a Task 5.
