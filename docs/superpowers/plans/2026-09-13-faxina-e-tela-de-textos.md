# Faxina pré-agendas e tela de textos do robô — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fechar as cinco pendências que bloqueiam o uso real do subsistema antes de as duas agendas de celular chegarem, e dar à cliente a tela onde ela escreve o que o robô fala.

**Architecture:** Cinco correções independentes sobre o que já está em produção. Nenhuma mexe no motor de fusão nem na máquina de estados da conversa, que estão revisados e travados por teste. A tela de textos é JS puro lendo uma tabela que já existe (`po_wa_textos`, 8 linhas, RLS já configurada para o painel logado).

**Tech Stack:** PHP 8 no cPanel (sem Composer, sem build), Supabase REST, JS puro no painel, runner próprio `tests/run.php`.

**Spec:** `docs/superpowers/specs/2026-09-11-automacao-whatsapp-crm-design.md` (seções 4.2, 6 e 9.4)

## Global Constraints

- **As três regras do dono continuam valendo** e nenhuma task pode afrouxá-las: o registro com histórico é o dono; a fusão só soma, nunca zera; nunca duplicar.
- **`WA_IMPORT_CAMPOS_PREENCHIVEIS` é a trava estrutural.** Nenhuma escrita nova pode tocar `status`, `venda`, `venda_at`, `notas` ou `qualif_*`.
- **Normalizador de telefone é um só.** `wa_e164()` em PHP e `poE164()` em JS são porte 1:1 e leem a **mesma** fixture de casos (`tests/fixtures/fone.json`). Mexer num sem o outro é defeito — a fixture existe para pegar isso.
- **Dado de terceiro é sempre escapado** no painel com `esc()`.
- **Texto do robô é dado que a cliente escreve e que sai no WhatsApp.** Nunca interpretar como instrução; ao renderizar no painel, escapar.
- **Nenhum teste toca a rede.** Escrita e leitura de banco entram por transporte injetável (`wa_db_set_transport`).
- **`service_role` só no servidor** (`wa_config()`); o painel usa `anon key` + login.
- **Migração em banco compartilhado (NOX/hd360) é aplicada pelo controlador**, nunca por subagente.
- **JS puro, sem build e sem npm.** Testes JS extraem a função do fonte por regex, então **nenhuma constante de topo de arquivo de que a função dependa** — ponha dentro da função. Já quebrou quatro vezes neste projeto.
- **Todo `<script>`/`<link>` alterado precisa de `?v=` novo.** Estado em produção agora: `fone.js?v=1`, `app.js?v=7`, `clientes.js?v=2`, `funil.js?v=1`, `conversa.js?v=1`, `importar-contatos.js?v=5`, `revisao.js?v=2`, `painel.css?v=6`.
- **Edições em `painel/index.html` são ADITIVAS:** os 9 scripts existentes são preservados.
- **Nunca usar dado real de cliente em fixture.** O repositório é **público** e já houve dois incidentes. O CPF sintético do projeto é `111.444.777-35`; telefones de teste, `+55489999900xx`.
- PHP 8 sem Composer; testes com `ok($cond,$msg)`+`exit(1)` e `node:assert/strict`; a suíte (`php tests/run.php`, hoje **33 arquivos**) fica verde.

---

### Task 1: `wa_e164` aceita os formatos que a agenda realmente produz

**Files:**
- Modify: `lib/wa-fone.php`, `painel/fone.js`, `tests/fixtures/fone.json`
- Test: `tests/test-wa-fone.php`, `tests/test-fone.mjs` (leem a fixture)

**Interfaces:**
- Consumes: nada.
- Produces: `wa_e164()` e `poE164()` passam a aceitar o prefixo internacional `00` e o zero de operadora antes do DDD.

> **Por que é o item mais urgente:** os dois `.vcf` das agendas estão a caminho, e export
> de agenda produz telefone como `0048 99999-0001` (prefixo internacional discado) e
> `048 99999-0001` (zero de operadora). Hoje **os dois viram `null`** e o contato entra
> sem telefone — e telefone é o que faz a transmissão existir. Verificado:
> `wa_e164('0048999990001')` e `wa_e164('048 99999-0001')` devolvem `null`.

- [ ] **Step 1: Write the failing test**

Acrescente ao array de casos em `tests/fixtures/fone.json` (a fixture é lida pelos **dois** testes):

```json
["0048999990001", "+5548999990001"],
["00 55 48 99999-0001", "+5548999990001"],
["048 99999-0001", "+5548999990001"],
["0 48 99999 0001", "+5548999990001"],
["04832220000", "+554832220000"],
["0800 123 4567", null],
["000", null],
["0", null]
```

> **Atenção ao caso `0800`:** ele já está na fixture e tem que **continuar devolvendo
> `null`**. Um tratamento ingênuo do zero inicial transformaria `0800 123 4567` em
> `800 123 4567` e depois em algo que passa pela validação. Não enfraqueça esse caso.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php tests/test-wa-fone.php && node tests/test-fone.mjs`
Expected: FAIL nos casos novos (`0048999990001` devolve `null`, esperado `+5548999990001`).

- [ ] **Step 3: Implement in PHP**

Em `lib/wa-fone.php`, dentro de `wa_e164()`, **depois** de `$d = preg_replace(...)` e **antes** do corte do DDI `55`:

```php
    // Prefixo internacional discado: a agenda do celular exporta "0048 ..."
    // quando o contato foi salvo a partir de uma chamada internacional.
    // So tira quando sobra numero suficiente para ser brasileiro.
    if (strlen($d) >= 14 && substr($d, 0, 2) === '00') {
        $d = substr($d, 2);
    }
```

E, **depois** do corte do `55` e **antes** da validação de comprimento:

```php
    // Zero de operadora antes do DDD ("048 99999-0001"). So cai aqui quando
    // sobra exatamente um numero brasileiro depois de tirar o zero — assim
    // 0800 (que tem 11 digitos e comeca com 08) nao e confundido com DDD 80.
    if (strlen($d) === 11 && $d[0] === '0') {
        $d = substr($d, 1);
    } elseif (strlen($d) === 12 && $d[0] === '0') {
        $d = substr($d, 1);
    }
```

> **Nota ao implementador:** as duas guardas acima são o esqueleto do raciocínio, não
> necessariamente o código final. O que **precisa** valer é: os casos novos da fixture
> passam, e **todos os casos antigos continuam passando sem alteração** — em especial
> `0800 123 4567 → null`, `(01) 99999-9999 → null` (DDD 01 não existe) e
> `+1 415 555 2671 → null`. Se a sua implementação for diferente, tudo bem; a fixture é
> o contrato. Se algum caso antigo quebrar, **pare e reporte** em vez de mexer nele.

- [ ] **Step 4: Implement in JS, idêntico**

Em `painel/fone.js`, dentro de `poE164()`, aplique a **mesma** regra. As duas linguagens têm que concordar caso a caso — a fixture compartilhada é o que prova isso.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php tests/run.php`
Expected: todos verdes (33 arquivos).

- [ ] **Step 6: Commit**

```bash
git add lib/wa-fone.php painel/fone.js tests/fixtures/fone.json
git commit -m "fix(fone): aceita prefixo internacional 00 e zero de operadora antes do DDD"
```

---

### Task 2: `enviar.php` normaliza o telefone para E.164

**Files:**
- Modify: `enviar.php`
- Test: `tests/test-enviar-fone.php`

**Interfaces:**
- Consumes: `wa_e164()` (`lib/wa-fone.php`).
- Produces: o lead do formulário do site entra com telefone em E.164 quando o número for válido.

> **Por que agora:** `enviar.php` grava o que o visitante digitou. A mesma pessoa que
> preenche o formulário e depois chama no WhatsApp vira **duas fichas**, e o motor casa
> lead por `wa_id`/telefone normalizado. A auditoria de fusão já normaliza os dois lados
> do índice, então o estrago é menor do que era — mas `po_leads_wa_id_uniq` e a aba
> Clientes continuam vendo dois formatos do mesmo número. **`enviar.php` está em
> produção:** a mudança tem que ser aditiva e nunca perder o lead.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/test-enviar-fone.php
require __DIR__ . '/../lib/wa-fone.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

// A funcao que o enviar.php vai usar. Isolada para ser testavel sem subir o
// formulario inteiro (o enviar.php manda e-mail e grava no Supabase).
require __DIR__ . '/../lib/po-fone-lead.php';

// Numero valido vira E.164.
ok(po_fone_lead('(48) 99999-0001') === '+5548999990001', 'formato do formulario vira E.164');
ok(po_fone_lead('48999990001')     === '+5548999990001', 'so digitos vira E.164');

// O que NAO da para normalizar e PRESERVADO como o visitante digitou. Perder o
// telefone de um lead e pior que guardar num formato torto: sem ele a cliente
// nao consegue ligar de volta.
ok(po_fone_lead('+1 415 555 2671') === '+1 415 555 2671', 'estrangeiro fica como veio');
ok(po_fone_lead('nao tenho')       === 'nao tenho',       'texto livre fica como veio');
ok(po_fone_lead('')                === '',                'vazio continua vazio');
ok(po_fone_lead('   ')             === '',                'so espaco vira vazio');

echo "test-enviar-fone OK\n";
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-enviar-fone.php`
Expected: FAIL — `lib/po-fone-lead.php` não existe.

- [ ] **Step 3: Implement**

```php
<?php
// lib/po-fone-lead.php
/* Normaliza o telefone do lead do formulario para E.164 quando der, e
   PRESERVA o que o visitante digitou quando nao der.

   Perder o telefone de um lead e pior que guardar num formato torto: sem ele
   a cliente nao consegue ligar de volta. Por isso nunca devolve vazio para
   uma entrada nao vazia. */
require_once __DIR__ . '/wa-fone.php';

function po_fone_lead($bruto) {
    $s = trim((string) $bruto);
    if ($s === '') return '';
    $e = wa_e164($s);
    return $e !== null ? $e : $s;
}
```

- [ ] **Step 4: Wire it into `enviar.php`**

Em `enviar.php`, logo depois de `$telefone = campo('telefone');`:

```php
require_once __DIR__ . '/lib/po-fone-lead.php';
// E.164 quando der; o que o visitante digitou quando nao der (ver lib/po-fone-lead.php).
$telefone = po_fone_lead($telefone);
```

> **Cuidado:** o `$telefone` também é usado no corpo do e-mail que a cliente recebe.
> Confira no arquivo se o e-mail passa a mostrar `+5548999990001` em vez de
> `(48) 99999-0001` — se ficar pior de ler, use o normalizado só na gravação do
> Supabase e mantenha o digitado no e-mail. Diga no relatório o que você escolheu.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php tests/run.php` e `php -l enviar.php`
Expected: todos verdes, sem erro de sintaxe.

- [ ] **Step 6: Commit**

```bash
git add lib/po-fone-lead.php enviar.php tests/test-enviar-fone.php
git commit -m "fix(lead): telefone do formulario do site entra em E.164"
```

---

### Task 3: O teste tranca o filtro `wa_id` da conversa no ponto de chamada

**Files:**
- Modify: `tests/test-conversa.mjs`, `tests/test-gaveta.mjs`

**Interfaces:**
- Consumes: `poFiltroConversa`, `poMontaConsulta`, `poRenderConversa` (`painel/conversa.js`).
- Produces: asserções de fonte que impedem a consulta inline voltar.

> **Por que esta é a pendência mais antiga em aberto:** o filtro `.eq('wa_id', waId)`
> **já sumiu uma vez**, num commit que prometia outra coisa, e a aba de conversa passou a
> mostrar **as mensagens de todos os contatos** — vazamento de dado entre clientes. O
> teste de hoje olha só as funções puras: reescrever a chamada inline em
> `poRenderConversa`, deixando as funções intactas, **reintroduz o defeito com o teste
> verde**. É exatamente a forma do incidente original.

- [ ] **Step 1: Write the failing test**

Acrescente a `tests/test-conversa.mjs`:

```js
// O filtro por wa_id JA SUMIU UMA VEZ num commit que prometia outra coisa, e a
// tela passou a mostrar as mensagens de todos os contatos. As funcoes puras
// abaixo continuam testadas, mas elas nao impedem alguem de escrever a consulta
// inline no render e contornar o descritor. Estas duas linhas impedem.
assert.ok(src.includes('poMontaConsulta(sb, poFiltroConversa(waId))'),
  'o render usa o descritor, nao uma consulta inline');
assert.ok(!/sb\.from\(['"]po_wa_mensagens['"]\)/.test(src),
  'nenhuma consulta inline a po_wa_mensagens fora do poMontaConsulta');
```

E a `tests/test-gaveta.mjs`, para a regra do `venda_at` (mesmo buraco, outra regra):

```js
// Mesma forma de defeito: a regra de so recarimbar venda_at quando o valor
// mudou vive numa funcao pura, e nada impede alguem de montar o patch inline.
assert.ok(!/venda_at\s*:\s*new Date\(\)/.test(src),
  'venda_at nunca e carimbado inline, so pela regra de poPatchGaveta');
```

- [ ] **Step 2: Run tests to verify they fail (ou passam por acaso)**

Run: `node tests/test-conversa.mjs && node tests/test-gaveta.mjs`
Expected: se o código atual já satisfaz as asserções, elas passam — **isso é esperado**. O valor está em travar o futuro. **Prove que elas prendem:** reescreva `poRenderConversa` para consultar `sb.from('po_wa_mensagens')` inline, rode, confirme **vermelho**, e desfaça. Cole as duas saídas no relatório.

- [ ] **Step 3: Commit**

```bash
git add tests/test-conversa.mjs tests/test-gaveta.mjs
git commit -m "test(conversa): tranca o filtro wa_id e o venda_at no ponto de chamada"
```

---

### Task 4: Eventos de entrega passam pela idempotência

**Files:**
- Modify: `whatsapp.php`
- Test: `tests/test-wa-webhook.php`

**Interfaces:**
- Consumes: `wa_registra_evento()` (`lib/wa-db.php`), `wa_parse_evento()` (`lib/wa-webhook.php`).
- Produces: eventos de tipo `status` (entregue, lido, falhou) também gravados com idempotência por `wamid`.

> **Por que agora:** `whatsapp.php:64` pula a idempotência quando `tipo === 'status'`.
> Hoje é inofensivo porque nada é feito com esses eventos — mas **o relatório de campanha
> do plano 4 vive deles**: quantos foram entregues, quantos lidos. A Meta reenvia webhook
> quando não recebe 200 rápido, então sem idempotência o relatório contaria a mesma
> entrega várias vezes e o número de alcance da transmissão ficaria inflado.

- [ ] **Step 1: Write the failing test**

Acrescente a `tests/test-wa-webhook.php`, no formato dos casos que o arquivo já usa (transporte injetado, sem rede):

```php
// Evento de entrega tambem passa pela idempotencia: o relatorio de campanha
// do plano 4 conta entregues e lidos, e a Meta reenvia o mesmo evento quando
// nao recebe 200 rapido. Sem isto, o alcance da transmissao vem inflado.
$gravados = [];
wa_db_set_transport(function ($metodo, $url, $corpo, $h) use (&$gravados) {
    if ($metodo === 'POST' && strpos($url, 'po_wa_mensagens') !== false) {
        $linha = json_decode($corpo, true);
        if (in_array($linha['wamid'] ?? '', $gravados, true)) {
            return ['status' => 409, 'body' => '{}'];   // unique violation
        }
        $gravados[] = $linha['wamid'] ?? '';
    }
    return ['status' => 201, 'body' => '[{}]'];
});

$ev = ['wamid' => 'wamid.STATUS1', 'tipo' => 'status', 'wa_id' => '+5548999990001',
       'status' => 'delivered'];
ok(wa_registra_evento($ev) === 'novo',      'primeiro evento de entrega e novo');
ok(wa_registra_evento($ev) === 'duplicado', 'o reenvio da Meta e duplicado');
ok(count($gravados) === 1,                  'so uma linha gravada para o mesmo wamid');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-wa-webhook.php`
Expected: depende do estado atual — se `wa_registra_evento` já trata o `status`, o teste passa e o defeito está só no `whatsapp.php`. **Leia os dois antes de implementar** e diga no relatório onde está a lacuna.

- [ ] **Step 3: Implement**

Em `whatsapp.php`, troque a condição da linha 64:

```php
        // Idempotencia por wamid, INCLUSIVE para eventos de entrega: o relatorio
        // de campanha do plano 4 conta entregues e lidos, e a Meta reenvia o
        // mesmo evento quando nao recebe 200 rapido. Sem isto o alcance vem
        // inflado. 'duplicado' pula; 'falha' processa assim mesmo, porque
        // perder mensagem de cliente e pior que duplicar.
        if ($ev['wamid'] !== '') {
            if (wa_registra_evento($ev) === 'duplicado') continue;
        }
```

> **Cuidado, e é o ponto central desta task:** confirme que `wa_processar()` sabe o que
> fazer com um evento de tipo `status` — hoje ele podia contar com o fato de que esses
> eventos nunca chegavam por aqui. Se `wa_processar` não tratar `status`, ele tem que
> **ignorar em silêncio**, nunca tentar responder ao cliente. Um robô respondendo a um
> recibo de entrega é o pior desfecho possível. Trave isso por teste.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php tests/run.php`
Expected: todos verdes.

- [ ] **Step 5: Commit**

```bash
git add whatsapp.php tests/test-wa-webhook.php
git commit -m "fix(webhook): eventos de entrega tambem passam pela idempotencia"
```

---

### Task 5: Tela de textos do robô

**Files:**
- Create: `painel/textos.js`
- Modify: `painel/index.html`, `painel/app.js`, `painel/painel.css`
- Test: `tests/test-textos.mjs`

**Interfaces:**
- Consumes: `esc()`, `sb`, `toast()`, `$()`/`$$()` (globais de `painel/app.js`); tabela `po_wa_textos` (`chave` texto único, `texto` texto, `updated_at`), com RLS já configurada (`po_wa_textos_auth`, ALL para `authenticated`).
- Produces: `poTextosCatalogo()` (a lista das 7 chaves que o motor usa, com rótulo e variáveis), `poLinhaTexto(item)` (HTML escapado), `poTextoValida(chave, texto)` (validação antes de salvar).

> **Por que esta tela é o que destrava a cliente:** os textos que o robô manda estão hoje
> no banco como **rascunho meu, sem acento**, e mudá-los exige rodar SQL. São eles que
> viram os **templates submetidos à Meta** — ou seja, escrever agora é ganhar tempo duas
> vezes. São 7 chaves que o motor realmente usa (`envio_pdf`, `menu`, `perguntas`,
> `qualificado`, `sem_data`, `lembrete`, `lembrete_menu`); existe uma oitava linha,
> `saudacao`, que o motor **nunca lê** — resíduo do seed, e esta task a remove do banco.

- [ ] **Step 1: Write the failing test**

```js
// tests/test-textos.mjs
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const src = readFileSync(new URL('../painel/textos.js', import.meta.url), 'utf8');
function pega(nome) {
  const m = src.match(new RegExp('function ' + nome + '\\([\\s\\S]*?\\n\\}'));
  assert.ok(m, nome + ' encontrada no textos.js');
  return m[0];
}
const { poTextosCatalogo, poLinhaTexto, poTextoValida } = new Function(
  'function esc(s){return (s==null?"":String(s)).replace(/[&<>"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;","\\"":"&quot;"}[c]));}' +
  pega('poTextosCatalogo') + pega('poLinhaTexto') + pega('poTextoValida') +
  '; return {poTextosCatalogo, poLinhaTexto, poTextoValida};'
)();

// --- o catalogo tem as 7 chaves que o motor usa, e NAO tem a saudacao
const cat = poTextosCatalogo();
const chaves = cat.map(c => c.chave).sort();
assert.deepEqual(chaves,
  ['envio_pdf','lembrete','lembrete_menu','menu','perguntas','qualificado','sem_data'],
  'as 7 chaves que o motor le, sem a saudacao que ele ignora');
cat.forEach(c => {
  assert.ok(c.rotulo && c.rotulo.length > 3, c.chave + ' tem rotulo legivel');
  assert.ok(Array.isArray(c.vars), c.chave + ' declara as variaveis que aceita');
});

// --- a validacao protege as variaveis
// {roteiro} no envio_pdf e o nome do roteiro; sem ele a mensagem sai sem dizer
// de qual viagem e.
assert.equal(poTextoValida('envio_pdf', 'Segue o roteiro completo do {roteiro}.'), null,
  'texto com a variavel obrigatoria passa');
assert.ok(poTextoValida('envio_pdf', 'Segue o roteiro completo.'),
  'texto sem {roteiro} e recusado com mensagem');
assert.ok(poTextoValida('envio_pdf', 'Segue o {destino} completo.'),
  'variavel que nao existe e recusada');
assert.ok(poTextoValida('menu', ''), 'texto vazio e recusado');
assert.ok(poTextoValida('menu', '   '), 'so espaco e recusado');

// {nome} e opcional em todas: a cliente pode preferir nao usar o nome do perfil
assert.equal(poTextoValida('menu', 'Sobre qual viagem voce quer saber?'), null,
  'nao usar {nome} e permitido');

// --- a linha escapa o texto, que a cliente escreve e sai no WhatsApp
const html = poLinhaTexto({chave:'menu', rotulo:'Menu de roteiros',
                           texto:'<img src=x onerror=alert(1)>', vars:['nome']});
assert.ok(!html.includes('<img src=x'), 'texto escapado');
assert.ok(html.includes('&lt;img'), 'aparece escapado, nao sumido');
assert.ok(html.includes('data-chave="menu"'), 'a linha carrega a chave');

console.log('test-textos OK');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `node tests/test-textos.mjs`
Expected: FAIL — `painel/textos.js` não existe.

- [ ] **Step 3: Write `painel/textos.js`**

```js
/* ============================================================
   Textos do robo: o que ele manda no WhatsApp, editavel sem deploy.

   Ate agora so dava para mudar rodando SQL, e o que esta no banco e rascunho,
   sem acento. Sao estes textos que viram os TEMPLATES submetidos a Meta, entao
   escrever aqui adianta duas etapas.

   O catalogo vive DENTRO da funcao de proposito: o teste extrai a funcao do
   fonte por regex e uma constante de topo de arquivo daria ReferenceError.
============================================================ */

function poTextosCatalogo() {
  return [
    {chave:'menu', rotulo:'Menu de roteiros',
     ajuda:'Mandado quando o robo nao descobre de qual viagem a pessoa fala.',
     vars:['nome'], obrigatorias:[]},
    {chave:'envio_pdf', rotulo:'Envio do roteiro em PDF',
     ajuda:'Legenda do PDF do roteiro.',
     vars:['nome','roteiro'], obrigatorias:['roteiro']},
    {chave:'perguntas', rotulo:'As duas perguntas de qualificacao',
     ajuda:'Vai logo depois do PDF. {data} e a data de saida da viagem.',
     vars:['nome','data'], obrigatorias:['data']},
    {chave:'qualificado', rotulo:'Quando a pessoa se qualifica',
     ajuda:'Avisa que a equipe assume daqui.',
     vars:['nome'], obrigatorias:[]},
    {chave:'sem_data', rotulo:'Quando a pessoa nao tem a data',
     ajuda:'Resposta de quem nao pode viajar nessa data.',
     vars:['nome'], obrigatorias:[]},
    {chave:'lembrete', rotulo:'Lembrete apos 24h sem resposta',
     ajuda:'Para quem recebeu o roteiro e nao respondeu.',
     vars:['nome'], obrigatorias:[]},
    {chave:'lembrete_menu', rotulo:'Lembrete de quem nao escolheu roteiro',
     ajuda:'Para quem recebeu o menu e nao escolheu.',
     vars:['nome'], obrigatorias:[]},
  ];
}

/* Devolve null quando o texto pode ser salvo, ou a mensagem do problema. */
function poTextoValida(chave, texto) {
  const cat = poTextosCatalogo().find(c => c.chave === chave);
  if (!cat) return 'Texto desconhecido.';
  const t = (texto == null ? '' : String(texto)).trim();
  if (t === '') return 'O texto nao pode ficar vazio.';

  const usadas = (t.match(/\{[a-z_]+\}/g) || []).map(v => v.slice(1, -1));
  const desconhecida = usadas.find(v => cat.vars.indexOf(v) === -1);
  if (desconhecida) {
    return 'A variavel {' + desconhecida + '} nao existe aqui. Disponiveis: ' +
           cat.vars.map(v => '{' + v + '}').join(', ') + '.';
  }
  const faltando = cat.obrigatorias.find(v => usadas.indexOf(v) === -1);
  if (faltando) {
    return 'Falta a variavel {' + faltando + '}, que e obrigatoria nesta mensagem.';
  }
  return null;
}

function poLinhaTexto(item) {
  const it = item || {};
  const vars = (it.vars || []).map(v => '<code>{' + esc(v) + '}</code>').join(' ');
  return '<div class="txt-card" data-chave="' + esc(it.chave) + '">' +
    '<div class="txt-cab"><b>' + esc(it.rotulo) + '</b>' +
      '<span class="txt-vars">' + vars + '</span></div>' +
    '<div class="txt-ajuda">' + esc(it.ajuda) + '</div>' +
    '<textarea class="txt-area" data-chave="' + esc(it.chave) + '" rows="3">' +
      esc(it.texto) + '</textarea>' +
    '<div class="txt-erro" hidden></div>' +
    '</div>';
}
```

E a casca de DOM (não testada, só orquestra), no mesmo arquivo:

```js
/* ---------- render e salvar ---------- */
let TEXTOS = {};

async function poCarregaTextos() {
  const { data, error } = await sb.from('po_wa_textos').select('chave,texto');
  if (error) { toast('Erro ao ler os textos: ' + error.message, true); return; }
  TEXTOS = {};
  (data || []).forEach(r => { TEXTOS[r.chave] = r.texto; });
  poRenderTextos();
}

function poRenderTextos() {
  const box = document.querySelector('#txt-lista');
  if (!box) return;
  box.innerHTML = poTextosCatalogo()
    .map(c => poLinhaTexto(Object.assign({}, c, { texto: TEXTOS[c.chave] || '' })))
    .join('');
}

async function poSalvaTextos() {
  const areas = [...document.querySelectorAll('.txt-area')];
  let erro = false;
  areas.forEach(a => {
    const msg = poTextoValida(a.dataset.chave, a.value);
    const cx = a.parentElement.querySelector('.txt-erro');
    if (msg) { cx.textContent = msg; cx.hidden = false; erro = true; }
    else { cx.hidden = true; }
  });
  if (erro) { toast('Corrija os textos marcados antes de salvar.', true); return; }

  const linhas = areas.map(a => ({ chave: a.dataset.chave, texto: a.value.trim() }));
  const { error } = await sb.from('po_wa_textos').upsert(linhas, { onConflict: 'chave' });
  if (error) { toast('Erro ao salvar: ' + error.message, true); return; }
  linhas.forEach(l => { TEXTOS[l.chave] = l.texto; });
  toast('Textos salvos. O robo passa a usar na proxima mensagem.');
}
```

- [ ] **Step 4: Register the tab**

Em `painel/index.html`, uma aba `data-view="textos"` na navegação e a seção:

```html
<section class="view" data-view="textos" id="view-textos">
  <h2>Textos do robo</h2>
  <p class="ic-sub">E o que o robo manda no WhatsApp. As palavras entre chaves sao
  trocadas na hora do envio: <code>{nome}</code> pelo nome da pessoa,
  <code>{roteiro}</code> pelo nome da viagem, <code>{data}</code> pela data de saida.
  Estes textos tambem viram os modelos aprovados pela Meta.</p>
  <div id="txt-lista"></div>
  <button id="txt-salvar" class="btn btn-primary">Salvar textos</button>
</section>
<script src="textos.js?v=1"></script>
```

Em `painel/app.js`: ligue `$('#txt-salvar')` a `poSalvaTextos()` **com guarda de nulo** (no padrão dos handlers da aba Revisão), acrescente `textos` ao mecanismo de troca de aba, e chame `poCarregaTextos()` quando a aba for aberta pela primeira vez.

Em `painel/painel.css`, o estilo do cartão, usando **só** tokens existentes (`--surface`, `--border`, `--muted`, `--ink`).

- [ ] **Step 5: Run tests to verify they pass**

Run: `node tests/test-textos.mjs && php tests/run.php`
Expected: todos verdes (34 arquivos).

- [ ] **Step 6: Bump the cache-busters**

Em `painel/index.html`: `app.js?v=8`, `painel.css?v=7`, `textos.js?v=1`. **Preserve os 9 scripts existentes.**

- [ ] **Step 7: Commit**

```bash
git add painel/textos.js painel/index.html painel/app.js painel/painel.css tests/test-textos.mjs
git commit -m "feat(painel): tela de textos do robo, editaveis sem deploy"
```

- [ ] **Step 8: Controlador remove a linha morta do banco**

O subagente **para aqui**. A linha `saudacao` em `po_wa_textos` nunca é lida pelo motor (só o `tests/test-wa-schema.php` a menciona). O controlador roda o `delete` e ajusta o teste de schema, porque é escrita em banco compartilhado.

---

## Self-Review

**1. Cobertura.** Os cinco itens da faxina viraram cinco tasks, e cada um veio de uma pendência registrada e verificada: `wa_e164` recusando `00`/`0DDD` (medido), `enviar.php` sem normalizar (medido), teste do `wa_id` sem travar o ponto de chamada (medido), eventos `status` fora da idempotência (`whatsapp.php:64`), e a ausência da tela de textos (medido).

**2. Placeholders.** Nenhum "TBD". A Task 1 traz um esqueleto de implementação em vez de código final **de propósito**, com o contrato explícito (a fixture manda) e a instrução de parar se um caso antigo quebrar — é o item onde uma implementação ingênua quebra `0800`.

**3. Consistência.** `po_fone_lead()` (Task 2) é a única função nova consumida por outro arquivo (`enviar.php`). `poTextosCatalogo`/`poTextoValida`/`poLinhaTexto` (Task 5) são consumidas só dentro de `textos.js`. As 7 chaves do catálogo batem com o `grep` do motor: `envio_pdf`, `menu`, `perguntas`, `qualificado`, `sem_data` (em `wa-motor.php`) e `lembrete`, `lembrete_menu` (em `wa-timeout.php`).

**Ordem:** as tasks são independentes; a 1 é a mais urgente (bloqueia as agendas) e a 5 é a que destrava a cliente. Se for preciso cortar, a ordem de valor é 1, 5, 4, 3, 2.
