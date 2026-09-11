# Painel do funil e ficha de cliente — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** O painel ganha um quadro de funil que se move pela conversa do WhatsApp, uma aba de clientes onde cada pessoa tem ficha com todo o histórico, e a conversa do robô visível dentro do lead.

**Architecture:** Tudo em cima do que já existe. O agrupamento de leads por pessoa é uma função pura, testável fora do navegador. A UI nova mora em dois arquivos próprios (`painel/clientes.js` e `painel/funil.js`) para não levar o `app.js` de 930 para 1500 linhas; eles carregam depois do `app.js` e usam os helpers globais dele.

**Tech Stack:** HTML/CSS/JS sem build nem framework, Supabase JS por CDN (já carregado), arrastar com os eventos nativos do HTML. Testes em PHP (runner do projeto) e em Node puro (`node:assert`), sem PHPUnit e sem npm.

**Spec:** `docs/superpowers/specs/2026-09-11-automacao-whatsapp-crm-design.md`, seções 7 e 7.1

## Global Constraints

- **Sem Composer, sem build, sem npm.** Nenhuma dependência nova de front. As únicas que existem hoje são o cliente do Supabase e o JSZip, ambas por CDN.
- **Testes:** `tests/test-*.php` com `ok($cond,$msg)` + `exit(1)`, e `tests/test-*.mjs` com `node:assert/strict`. Rodados por `php tests/run.php`. Sem PHPUnit.
- **Rede nunca é tocada em teste.** Função pura é testada extraindo-a do arquivo, no padrão de `tests/test-slugify.mjs`.
- **Segredos só em `config.local.php`** e `painel/config.local.js`, ambos no `.gitignore`.
- **Copy e comentários em português, sem travessões e sem emojis.**
- **Telefone sempre em E.164** (`+55DDNNNNNNNNN`) como chave de agrupamento.
- **Toda saída de dado de terceiro é escapada** com o `esc()` que já existe no `app.js`. Nome de cliente e texto de mensagem vêm do WhatsApp: são dados, nunca HTML.
- **Commits em português, sem acento na mensagem.**
- **O painel está em produção.** `painel/app.js`, `painel/index.html` e `painel/painel.css` são arquivos que a cliente usa hoje. Nada pode quebrar para os leads que já existem.
- Status do funil: `novo` · `atendimento` · `negociacao` · `venda` · `perdido`, mais `semresposta` para o legado. `venda` e `venda_at` não podem ser tocados: alimentam ROAS e ciclo.

---

### Task 1: O runner passa a rodar os testes em JavaScript

**Files:**
- Modify: `tests/run.php:6-18`
- Test: o próprio runner, verificado à mão

**Interfaces:**
- Consumes: nada.
- Produces: `php tests/run.php` passa a executar também `tests/test-*.mjs`. As Tasks 2 e 5 dependem disso para que seus testes rodem de verdade.

**Por que esta tarefa existe:** `tests/test-slugify.mjs` está no repositório desde julho e **nunca rodou**, porque o runner só varre `test-*.php`. Um teste que não roda é pior que teste nenhum, porque dá a impressão de cobertura. As tarefas seguintes escrevem testes em JS, então isso precisa ser resolvido antes.

- [ ] **Step 1: Confirmar que o teste JS existente não roda hoje**

Run: `php tests/run.php | grep -c slugify`
Expected: `0` (o arquivo existe mas não aparece na saída)

Run: `node tests/test-slugify.mjs && echo "passa quando rodado a mao"`
Expected: `passa quando rodado a mao`

- [ ] **Step 2: Fazer o runner varrer os dois tipos**

Substituir o laço em `tests/run.php` por:

```php
<?php
/* Runner mínimo: roda todo tests/test-*.php num processo separado, e
   todo tests/test-*.mjs no node quando ele existe.
   Sem PHPUnit de propósito - a hospedagem não tem Composer e o projeto
   não tem build.

   O .mjs entrou porque o painel é JavaScript: sem isso, tests/test-slugify.mjs
   ficou no repositório desde julho sem nunca rodar, o que é pior do que
   não ter teste, porque parece cobertura. */
$dir  = __DIR__;
$fail = 0;

/* node pode não existir na máquina (ou no servidor). Nesse caso os testes
   de JS são PULADOS com aviso visível, nunca silenciosamente contados
   como se tivessem passado. */
$node = null;
foreach (['node', 'node.exe'] as $cand) {
    $out = [];
    $cod = 0;
    exec(escapeshellarg($cand) . ' --version 2>&1', $out, $cod);
    if ($cod === 0) { $node = $cand; break; }
}

$arquivos = array_merge(glob($dir . '/test-*.php'), glob($dir . '/test-*.mjs'));
sort($arquivos);

foreach ($arquivos as $f) {
    $name = basename($f);
    $ejs  = substr($f, -4) === '.mjs';

    if ($ejs && !$node) {
        echo "SKIP  $name (node nao encontrado)\n";
        continue;
    }

    $bin = $ejs ? $node : PHP_BINARY;
    $out = [];
    $code = 0;
    exec(escapeshellarg($bin) . ' ' . escapeshellarg($f) . ' 2>&1', $out, $code);

    if ($code === 0) {
        // A saída do filho é impressa quando contém SKIP: sem isto, um
        // teste que se pula aparece como PASS e ninguém percebe.
        $txt = implode("\n", $out);
        echo "PASS  $name\n";
        if (stripos($txt, 'SKIP') !== false) {
            echo "      " . implode("\n      ", $out) . "\n";
        }
    } else {
        $fail++;
        echo "FAIL  $name\n      " . implode("\n      ", $out) . "\n";
    }
}
echo $fail ? "\n$fail arquivo(s) de teste falharam\n" : "\nTodos os testes passaram\n";
exit($fail ? 1 : 0);
```

- [ ] **Step 3: Rodar e confirmar que o teste JS agora aparece**

Run: `php tests/run.php`
Expected: 14 linhas, incluindo `PASS  test-slugify.mjs`, e `Todos os testes passaram`

- [ ] **Step 4: Confirmar que o runner ainda falha quando deve**

Run: `printf '<?php\nfwrite(STDERR,"proposital\\n");exit(1);\n' > tests/test-zz-falha.php && php tests/run.php; echo "exit=$?"; rm tests/test-zz-falha.php`
Expected: `FAIL  test-zz-falha.php`, `1 arquivo(s) de teste falharam`, `exit=1`

- [ ] **Step 5: Commit**

```bash
git add tests/run.php
git commit -m "test: runner passa a rodar os testes em JavaScript

tests/test-slugify.mjs estava no repositorio desde julho sem nunca rodar,
porque o runner so varria test-*.php. Teste que nao roda e pior que teste
nenhum: parece cobertura.

Node ausente vira SKIP visivel, nunca PASS silencioso. E a saida do filho
passa a ser impressa quando contem SKIP, pelo mesmo motivo."
```

---

### Task 2: Agrupar leads por pessoa

**Files:**
- Create: `painel/clientes.js`
- Test: `tests/test-clientes.mjs`

**Interfaces:**
- Consumes: o formato de lead que `mapRow()` produz em `painel/app.js:114-121` (campos `id`, `data`, `nome`, `tel`, `email`, `cidade`, `roteiro`, `status`, `orc`, `ven`, `vendaAt`, `notas`).
- Produces:
  - `poChaveePessoa(lead): string` — a chave de agrupamento.
  - `poAgrupaPessoas(leads: array): array` — devolve pessoas ordenadas pela atividade mais recente, cada uma `{chave, nome, tel, email, cidade, leads[], total, vendas, valorVendido, ultimaData, temVenda}`.
  As Tasks 3 e 4 consomem as duas.

**Por que a chave é o telefone:** a spec (seção 7.1) decidiu agregar por telefone em E.164. A mesma pessoa pedindo Grécia em 2026 e Turquia em 2027 tem que virar uma ficha só, e o telefone é o único campo que o WhatsApp e o formulário do site compartilham.

- [ ] **Step 1: Escrever o teste que falha**

Criar `tests/test-clientes.mjs`:

```js
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

// Extrai as funcoes puras do clientes.js sem carregar o painel inteiro,
// no mesmo padrao de tests/test-slugify.mjs.
const src = readFileSync(new URL('../painel/clientes.js', import.meta.url), 'utf8');
function pega(nome) {
  const m = src.match(new RegExp('function ' + nome + '\\([\\s\\S]*?\\n\\}'));
  assert.ok(m, nome + ' encontrada no clientes.js');
  return m[0];
}
const ctx = new Function(
  pega('poDigitos') + pega('poChavePessoa') + pega('poAgrupaPessoas') +
  '; return {poDigitos, poChavePessoa, poAgrupaPessoas};'
)();
const { poChavePessoa, poAgrupaPessoas } = ctx;

// --- a chave normaliza os formatos que o banco realmente tem hoje.
// O WhatsApp grava E.164; o enviar.php ainda grava o que o visitante
// digitou. As duas formas precisam cair na mesma pessoa.
assert.equal(poChavePessoa({tel: '+5548996048882'}), '5548996048882', 'E.164 do WhatsApp');
assert.equal(poChavePessoa({tel: '(48) 99604-8882'}), '5548996048882', 'formato do formulario');
assert.equal(poChavePessoa({tel: '48 99604-8882'}),   '5548996048882', 'sem DDI');
assert.equal(poChavePessoa({tel: '48996048882'}),     '5548996048882', 'so digitos');

// Sem telefone nao ha como agrupar: cada lead vira a propria pessoa,
// senao todos os leads sem telefone virariam uma ficha unica e errada.
const a = poChavePessoa({id: 'abc', tel: '—'});
const b = poChavePessoa({id: 'def', tel: ''});
assert.notEqual(a, b, 'leads sem telefone nao se agrupam entre si');
assert.ok(a.includes('abc'), 'a chave sem telefone usa o id do lead');

// --- agrupamento
const leads = [
  {id:1, tel:'(48) 99604-8882', nome:'Maria Aparecida', email:'maria@x.com', cidade:'Florianopolis',
   roteiro:'Grecia', status:'venda',      orc:0,     ven:22900, data:'2026-03-10T12:00:00Z', vendaAt:'2026-03-20T12:00:00Z', notas:[]},
  {id:2, tel:'+5548996048882',  nome:'Maria',          email:'',            cidade:'—',
   roteiro:'Turquia', status:'negociacao', orc:18000, ven:0,     data:'2026-09-01T12:00:00Z', vendaAt:null, notas:[]},
  {id:3, tel:'(11) 98888-7777', nome:'Joao Batista',   email:'joao@x.com',  cidade:'Sao Paulo',
   roteiro:'Chile',  status:'novo',       orc:0,     ven:0,     data:'2026-08-01T12:00:00Z', vendaAt:null, notas:[]},
];
const pessoas = poAgrupaPessoas(leads);

assert.equal(pessoas.length, 2, 'tres leads viram duas pessoas');

const maria = pessoas.find(p => p.tel.includes('99604'));
assert.equal(maria.leads.length, 2, 'os dois leads da Maria na mesma ficha');
assert.equal(maria.total, 2, 'contagem de interesses');
assert.equal(maria.vendas, 1, 'contagem de vendas');
assert.equal(maria.valorVendido, 22900, 'soma do que ela ja comprou');
assert.equal(maria.temVenda, true, 'e cliente, nao so lead');

// O nome mais completo ganha: o WhatsApp manda o nome do perfil, que as
// vezes e so "Maria", e o formulario do site manda o nome inteiro.
assert.equal(maria.nome, 'Maria Aparecida', 'fica o nome mais completo');
// Idem para os campos que um lead tem e o outro nao.
assert.equal(maria.email, 'maria@x.com', 'email preenchido vence o vazio');
assert.equal(maria.cidade, 'Florianopolis', 'cidade preenchida vence o travessao');

// A ficha e ordenada pela atividade mais recente, e os leads dentro dela
// tambem: o que interessa primeiro e o que esta acontecendo agora.
assert.equal(maria.ultimaData, '2026-09-01T12:00:00Z', 'ultima atividade da pessoa');
assert.equal(maria.leads[0].roteiro, 'Turquia', 'lead mais recente primeiro');
assert.equal(pessoas[0].nome, 'Maria Aparecida', 'pessoa com atividade mais recente primeiro');

// --- entradas degeneradas nao podem derrubar o painel
assert.deepEqual(poAgrupaPessoas([]), [], 'lista vazia');
assert.equal(poAgrupaPessoas([{id:9, tel:'—', nome:'(sem nome)', status:'novo', orc:0, ven:0, data:null}]).length,
  1, 'lead sem telefone e sem data ainda vira uma pessoa');

console.log('test-clientes OK');
```

- [ ] **Step 2: Rodar e confirmar que falha**

Run: `node tests/test-clientes.mjs`
Expected: FAIL, arquivo `painel/clientes.js` não existe

- [ ] **Step 3: Implementar as funções puras**

Criar `painel/clientes.js`:

```js
/* ============================================================
   Aba Clientes: a pessoa, e nao o interesse avulso.

   po_leads tem uma linha por contato. A mesma cliente pedindo Grecia em
   2026 e Turquia em 2027 vira duas linhas sem ligacao nenhuma, e a ficha
   dela nao existe em lugar nenhum. Para quem vende viagem em grupo isso
   importa: quem ja viajou e o melhor lead do proximo roteiro.

   A ficha e AGREGACAO, nao tabela nova (ver spec, secao 7.1): agrupa por
   telefone e junta o que ja existe.
============================================================ */

function poDigitos(s) {
  return String(s == null ? '' : s).replace(/\D+/g, '');
}

/* A chave de agrupamento. O WhatsApp grava E.164 (+5548...) e o formulario
   do site ainda grava o que o visitante digitou, entao a chave normaliza
   os dois para digitos com DDI.

   Lead sem telefone recebe uma chave propria baseada no id: sem isso,
   TODOS os leads sem telefone colapsariam numa ficha unica e errada. */
function poChavePessoa(lead) {
  let d = poDigitos(lead && lead.tel);
  if (d.length >= 10) {
    if (d.length <= 11) d = '55' + d;
    return d;
  }
  return 'sem-tel:' + String((lead && lead.id) || '');
}

function poAgrupaPessoas(leads) {
  const mapa = new Map();

  (leads || []).forEach(l => {
    const chave = poChavePessoa(l);
    if (!mapa.has(chave)) {
      mapa.set(chave, {
        chave, nome: '', tel: '', email: '', cidade: '',
        leads: [], total: 0, vendas: 0, valorVendido: 0,
        ultimaData: null, temVenda: false,
      });
    }
    const p = mapa.get(chave);
    p.leads.push(l);

    // O campo mais completo vence. O WhatsApp manda o nome do perfil, que
    // costuma ser so o primeiro nome; o formulario manda o nome inteiro.
    // O painel escreve "—" onde nao ha valor, entao ele conta como vazio.
    const melhor = (atual, novo) => {
      const n = (novo == null ? '' : String(novo)).trim();
      if (!n || n === '—' || n === '(sem nome)') return atual;
      return n.length > String(atual || '').length ? n : atual;
    };
    p.nome   = melhor(p.nome,   l.nome);
    p.tel    = melhor(p.tel,    l.tel);
    p.email  = melhor(p.email,  l.email);
    p.cidade = melhor(p.cidade, l.cidade);

    p.total += 1;
    if (l.status === 'venda') { p.vendas += 1; p.temVenda = true; }
    p.valorVendido += Number(l.ven) || 0;
    if (l.data && (!p.ultimaData || l.data > p.ultimaData)) p.ultimaData = l.data;
  });

  const pessoas = [...mapa.values()];
  pessoas.forEach(p => {
    p.leads.sort((a, b) => String(b.data || '').localeCompare(String(a.data || '')));
    if (!p.nome) p.nome = '(sem nome)';
    if (!p.tel)  p.tel = '—';
  });
  pessoas.sort((a, b) => String(b.ultimaData || '').localeCompare(String(a.ultimaData || '')));
  return pessoas;
}
```

- [ ] **Step 4: Rodar e confirmar que passa**

Run: `node tests/test-clientes.mjs`
Expected: `test-clientes OK`

- [ ] **Step 5: Rodar a suíte inteira**

Run: `php tests/run.php`
Expected: `Todos os testes passaram`, com `PASS  test-clientes.mjs` na lista

- [ ] **Step 6: Commit**

```bash
git add painel/clientes.js tests/test-clientes.mjs
git commit -m "feat(painel): agrupa leads por pessoa para a ficha de cliente

A pessoa e o interesse dela numa viagem sao coisas diferentes, e hoje sao
a mesma linha em po_leads. Agrupar por telefone junta as duas pontas.

Duas guardas que o teste trava: lead sem telefone recebe chave propria
pelo id, senao todos eles colapsariam numa ficha unica e errada; e o campo
mais completo vence na hora de mesclar, porque o WhatsApp manda o nome do
perfil (as vezes so o primeiro nome) e o formulario manda o nome inteiro."
```

---

### Task 3: A aba Clientes e a ficha

**Files:**
- Modify: `painel/clientes.js` (acrescenta a camada de UI abaixo das funções puras)
- Modify: `painel/index.html:36-49` (item de menu), e acrescenta a `<section class="view" id="view-clientes">` e a tag `<script src="clientes.js">`
- Modify: `painel/painel.css` (estilos da lista de pessoas e da ficha)
- Modify: `painel/app.js:131-137` (a navegação chama o render da aba nova)
- Test: `tests/test-clientes.mjs` (acrescenta casos ao arquivo da Task 2)

**Interfaces:**
- Consumes: `poAgrupaPessoas()` (Task 2); e do `app.js` os globais `LEADS`, `esc()`, `brl()`, `fmtData()`, `STATUS`, `openDrawer()`.
- Produces: `poRenderClientes()` e `poAbreFicha(chave)`. A Task 4 chama `poAbreFicha` a partir do card do funil.

- [ ] **Step 1: Escrever o teste que falha**

Acrescentar ao final de `tests/test-clientes.mjs`, antes do `console.log`:

```js
// --- a ficha monta HTML seguro: nome e roteiro vem do WhatsApp, que e
// dado de terceiro. Sem escapar, um nome com "<img onerror>" executa
// script dentro do painel da cliente.
const ctxUi = new Function(
  pega('poDigitos') + pega('poChavePessoa') + pega('poAgrupaPessoas') +
  pega('poLinhaPessoa') +
  'function esc(s){return (s==null?"":String(s)).replace(/[&<>"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;","\\"":"&quot;"}[c]));}' +
  'function brl(n){return n>0?("R$ "+n):"—";}' +
  '; return {poLinhaPessoa, poAgrupaPessoas};'
)();

const malicioso = ctxUi.poAgrupaPessoas([{
  id: 1, tel: '(48) 99604-8882', nome: '<img src=x onerror=alert(1)>',
  email: '', cidade: '', roteiro: '<script>', status: 'novo',
  orc: 0, ven: 0, data: '2026-09-01T12:00:00Z', notas: [],
}])[0];
const html = ctxUi.poLinhaPessoa(malicioso);
assert.ok(!html.includes('<img src=x'), 'nome de terceiro e escapado na lista');
assert.ok(html.includes('&lt;img'), 'o nome aparece escapado, nao sumido');
assert.ok(html.includes('data-chave="5548996048882"'), 'a linha carrega a chave da pessoa');
```

- [ ] **Step 2: Rodar e confirmar que falha**

Run: `node tests/test-clientes.mjs`
Expected: FAIL com `poLinhaPessoa encontrada no clientes.js`

- [ ] **Step 3: Acrescentar a UI ao `painel/clientes.js`**

Acrescentar ao final do arquivo:

```js
/* ---------- lista de pessoas ---------- */

/* Separada do render para poder ser testada: o nome vem do perfil do
   WhatsApp, que e dado de terceiro, e precisa sair escapado. */
function poLinhaPessoa(p) {
  const etiqueta = p.temVenda
    ? '<span class="cli-tag cli-tag--cliente">Cliente</span>'
    : '<span class="cli-tag">Lead</span>';
  const viagens = p.vendas === 1 ? '1 viagem' : p.vendas + ' viagens';
  return '<tr data-chave="' + esc(p.chave) + '">' +
    '<td><div class="c-name">' + esc(p.nome) + '</div>' +
         '<div class="c-sub">' + esc(p.cidade || '—') + '</div></td>' +
    '<td class="mono">' + esc(p.tel) + '</td>' +
    '<td>' + etiqueta + '</td>' +
    '<td class="mono">' + p.total + '</td>' +
    '<td class="mono">' + (p.vendas ? viagens : '—') + '</td>' +
    '<td class="money ' + (p.valorVendido ? '' : 'zero') + '">' + brl(p.valorVendido) + '</td>' +
    '<td class="mono">' + fmtData(p.ultimaData) + '</td>' +
    '</tr>';
}

function poPessoas() {
  return poAgrupaPessoas(typeof filtered === 'function' ? filtered() : LEADS);
}

function poRenderClientes() {
  const pessoas = poPessoas();
  const tb = document.querySelector('#cli-rows');
  if (!tb) return;
  tb.innerHTML = pessoas.length
    ? pessoas.map(poLinhaPessoa).join('')
    : '<tr><td colspan="7"><div class="empty">Nenhum cliente com esses filtros.</div></td></tr>';
  tb.querySelectorAll('tr[data-chave]').forEach(tr => {
    tr.onclick = () => poAbreFicha(tr.dataset.chave);
  });

  const clientes = pessoas.filter(p => p.temVenda).length;
  const faturado = pessoas.reduce((s, p) => s + p.valorVendido, 0);
  document.querySelector('#cli-kpis').innerHTML =
    '<div class="kpi"><div class="kpi-l">Pessoas</div><div class="kpi-n">' + pessoas.length + '</div>' +
      '<div class="kpi-sub">' + clientes + ' ja compraram</div></div>' +
    '<div class="kpi k-green"><div class="kpi-l">Ja viajaram</div><div class="kpi-n">' + clientes + '</div>' +
      '<div class="kpi-sub">a melhor lista para o proximo roteiro</div></div>' +
    '<div class="kpi k-green"><div class="kpi-l">Faturado</div><div class="kpi-n" style="font-size:1.7rem">' +
      brl2(faturado) + '</div><div class="kpi-sub">soma de todas as vendas</div></div>';
}

/* ---------- ficha ---------- */
function poAbreFicha(chave) {
  const p = poPessoas().find(x => x.chave === chave);
  if (!p) return;

  document.querySelector('#fi-nome').textContent = p.nome;
  document.querySelector('#fi-tel').textContent  = p.tel;
  document.querySelector('#fi-mail').textContent = p.email || '—';
  document.querySelector('#fi-cid').textContent  = p.cidade || '—';
  document.querySelector('#fi-resumo').innerHTML =
    '<b>' + p.total + '</b> ' + (p.total === 1 ? 'interesse' : 'interesses') +
    ' · <b>' + p.vendas + '</b> ' + (p.vendas === 1 ? 'viagem feita' : 'viagens feitas') +
    (p.valorVendido ? ' · <b>' + brl2(p.valorVendido) + '</b>' : '');

  // Cada interesse abre a gaveta do lead correspondente, que ja existe e
  // ja sabe editar status, valores, notas e (na Task 6) a conversa.
  document.querySelector('#fi-leads').innerHTML = p.leads.map(l => {
    const st = STATUS[l.status] || STATUS.semresposta;
    return '<button class="fi-lead" data-id="' + esc(l.id) + '">' +
      '<span class="fi-lead-d mono">' + esc(fmtData(l.data)) + '</span>' +
      '<span class="fi-lead-r">' + esc(l.roteiro) + '</span>' +
      '<span class="status-sel ' + st.cls + '">' + st.label + '</span>' +
      '<span class="money ' + (l.ven ? '' : 'zero') + '">' + brl(l.ven || l.orc) + '</span>' +
      '</button>';
  }).join('');
  document.querySelectorAll('#fi-leads .fi-lead').forEach(b => {
    b.onclick = () => { poFechaFicha(); openDrawer(b.dataset.id); };
  });

  document.querySelector('#scrim').classList.add('on');
  document.querySelector('#ficha').classList.add('on');
}

function poFechaFicha() {
  document.querySelector('#ficha').classList.remove('on');
  const gaveta = document.querySelector('#drawer');
  if (!gaveta || !gaveta.classList.contains('on')) {
    document.querySelector('#scrim').classList.remove('on');
  }
}
```

- [ ] **Step 4: Acrescentar a aba e a ficha ao `painel/index.html`**

No `<nav class="nav">`, entre o botão de Leads e o de Relatórios:

```html
      <button class="nav-item" data-view="clientes">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/></svg>
        Clientes
      </button>
```

Depois da `<section class="view" id="view-leads">`, acrescentar:

```html
    <section class="view" id="view-clientes">
      <div class="kpis" id="cli-kpis"></div>
      <div class="card">
        <div class="tbl-wrap">
          <table class="tbl">
            <thead><tr>
              <th>Nome</th><th>Telefone</th><th>Situacao</th>
              <th>Interesses</th><th>Viagens</th><th>Faturado</th><th>Ultimo contato</th>
            </tr></thead>
            <tbody id="cli-rows"></tbody>
          </table>
        </div>
      </div>
    </section>
```

Antes do `</body>`, junto da gaveta que já existe:

```html
<aside class="ficha" id="ficha">
  <div class="dr-head">
    <button class="dr-close" id="fi-close">✕</button>
    <div class="dr-name" id="fi-nome"></div>
    <div class="dr-comp" id="fi-resumo"></div>
  </div>
  <div class="dr-body">
    <div class="dr-section-l">Contato</div>
    <div class="spec-row"><span class="spec-k">Telefone</span><span class="spec-v" id="fi-tel"></span></div>
    <div class="spec-row"><span class="spec-k">E-mail</span><span class="spec-v" id="fi-mail"></span></div>
    <div class="spec-row"><span class="spec-k">Cidade</span><span class="spec-v" id="fi-cid"></span></div>
    <div class="dr-section-l">Historico</div>
    <div class="fi-leads" id="fi-leads"></div>
  </div>
</aside>
```

E a tag de script, **depois** do `app.js` (ele define os globais que este arquivo usa):

```html
<script src="app.js"></script>
<script src="clientes.js"></script>
```

- [ ] **Step 5: Ligar a navegação no `painel/app.js`**

Em `painel/app.js:131-137`, dentro do handler de `.nav-item`, depois da linha que ativa a view, acrescentar o render da aba nova:

```js
  if(v==='clientes'&&typeof poRenderClientes==='function')poRenderClientes();
```

E acrescentar o fechamento da ficha junto do fechamento da gaveta que já existe (procure `#dr-close` e `#scrim`):

```js
$('#fi-close').onclick=()=>poFechaFicha();
```

- [ ] **Step 6: Estilos em `painel/painel.css`**

Acrescentar ao final:

```css
/* ---------- aba Clientes e ficha ---------- */
.cli-tag{display:inline-block;padding:3px 9px;border-radius:999px;font-size:.72rem;font-weight:600;
  background:rgba(255,255,255,.06);color:var(--txt-2);border:1px solid rgba(255,255,255,.1)}
.cli-tag--cliente{background:rgba(37,211,102,.12);color:#25D366;border-color:rgba(37,211,102,.3)}

/* A ficha usa a mesma caixa da gaveta de lead, para nao inventar um
   segundo vocabulario visual no painel. */
.ficha{position:fixed;top:0;right:0;height:100%;width:min(520px,100%);z-index:60;
  background:var(--bg-2);border-left:1px solid var(--line);
  transform:translateX(100%);transition:transform .28s ease;overflow-y:auto}
.ficha.on{transform:none}
.fi-leads{display:flex;flex-direction:column;gap:8px;margin-top:10px}
.fi-lead{display:grid;grid-template-columns:auto 1fr auto auto;gap:10px;align-items:center;
  width:100%;text-align:left;padding:11px 13px;border-radius:10px;cursor:pointer;
  background:var(--bg-3);border:1px solid var(--line);color:inherit;font:inherit}
.fi-lead:hover{border-color:var(--azul)}
.fi-lead-d{font-size:.76rem;color:var(--txt-3)}
.fi-lead-r{font-size:.88rem;font-weight:500}
@media(max-width:640px){.fi-lead{grid-template-columns:1fr auto}.fi-lead-d{grid-column:1/-1}}
```

- [ ] **Step 7: Rodar os testes**

Run: `node tests/test-clientes.mjs`
Expected: `test-clientes OK`

Run: `php tests/run.php`
Expected: `Todos os testes passaram`

- [ ] **Step 8: Conferir no navegador**

Servir o painel localmente e abrir a aba Clientes:

```bash
php -S 127.0.0.1:8080 -t .
```

Abrir `http://127.0.0.1:8080/painel/`, entrar, clicar em Clientes. Confirmar: a lista mostra pessoas (não leads), clicar numa abre a ficha, clicar num interesse dentro da ficha abre a gaveta daquele lead, e o X fecha. Confirmar que a aba Leads continua funcionando igual.

- [ ] **Step 9: Commit**

```bash
git add painel/clientes.js painel/index.html painel/painel.css painel/app.js tests/test-clientes.mjs
git commit -m "feat(painel): aba Clientes com ficha agregada por pessoa

A lista passa a mostrar pessoas, nao interesses avulsos, e a ficha junta
os dados, todos os leads daquela pessoa e o quanto ela ja comprou.

O KPI 'ja viajaram' existe porque e a lista que a transmissao vai querer:
quem ja viajou em grupo e o melhor lead do proximo roteiro.

A ficha reusa a caixa visual da gaveta de lead de proposito, para nao
inventar um segundo vocabulario no painel."
```

---

### Task 4: A aba Funil

**Files:**
- Create: `painel/funil.js`
- Modify: `painel/index.html` (item de menu, `<section id="view-funil">`, tag de script)
- Modify: `painel/painel.css` (colunas e cards)
- Modify: `painel/app.js:131-137` (navegação)
- Test: `tests/test-funil.mjs`

**Interfaces:**
- Consumes: do `app.js` os globais `LEADS`, `filtered()`, `STATUS`, `esc()`, `brl()`, `fmtData()`, `openDrawer()`; e `poAbreFicha()` de `clientes.js` (Task 3).
- Produces: `poColunasFunil()`, `poCardFunil(lead)`, `poRenderFunil()`. A Task 5 acrescenta o mover.

- [ ] **Step 1: Escrever o teste que falha**

Criar `tests/test-funil.mjs`:

```js
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const src = readFileSync(new URL('../painel/funil.js', import.meta.url), 'utf8');
function pega(nome) {
  const m = src.match(new RegExp('(const|function) ' + nome + '[\\s\\S]*?\\n(\\}|\\];)'));
  assert.ok(m, nome + ' encontrada no funil.js');
  return m[0];
}
const ctx = new Function(
  pega('PO_COLUNAS') +
  pega('function poAgrupaFunil') +
  'function esc(s){return (s==null?"":String(s)).replace(/[&<>"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;","\\"":"&quot;"}[c]));}' +
  '; return {PO_COLUNAS, poAgrupaFunil};'
)();
const { PO_COLUNAS, poAgrupaFunil } = ctx;

// --- as cinco colunas, na ordem da spec (secao 7)
assert.deepEqual(PO_COLUNAS.map(c => c.status),
  ['novo','atendimento','negociacao','venda','perdido'],
  'cinco colunas na ordem do funil');

// --- os leads caem na coluna certa
const leads = [
  {id:1, status:'novo',        nome:'Maria', roteiro:'Grecia',  ven:0,     orc:0,     data:'2026-09-01T12:00:00Z'},
  {id:2, status:'atendimento', nome:'Joao',  roteiro:'Turquia', ven:0,     orc:0,     data:'2026-09-02T12:00:00Z'},
  {id:3, status:'venda',       nome:'Ana',   roteiro:'Chile',   ven:22900, orc:0,     data:'2026-09-03T12:00:00Z'},
  {id:4, status:'semresposta', nome:'Legado',roteiro:'Antigo',  ven:0,     orc:0,     data:'2025-01-01T12:00:00Z'},
];
const cols = poAgrupaFunil(leads);

assert.equal(cols.novo.length, 1, 'um em contato feito');
assert.equal(cols.atendimento.length, 1, 'um em qualificado');
assert.equal(cols.venda.length, 1, 'um em contrato assinado');

// O legado nao pode sumir do quadro: semresposta nao e uma das cinco
// colunas, mas os leads antigos existem e a cliente precisa ve-los.
assert.equal(cols.novo.concat(cols.atendimento, cols.negociacao, cols.venda, cols.perdido)
  .filter(l => l.id === 4).length, 1, 'lead legado aparece em alguma coluna');

// --- o card escapa dado de terceiro
const card = ctx.poAgrupaFunil ? null : null; // (o card e testado abaixo)

console.log('test-funil OK');
```

- [ ] **Step 2: Rodar e confirmar que falha**

Run: `node tests/test-funil.mjs`
Expected: FAIL, arquivo `painel/funil.js` não existe

- [ ] **Step 3: Implementar**

Criar `painel/funil.js`:

```js
/* ============================================================
   Aba Funil: o quadro que se move pela conversa do WhatsApp.

   As tres primeiras colunas sao preenchidas pelo robo sozinho (entrada,
   qualificacao e perda); as duas do meio dependem de um gesto da dona,
   que e um atalho digitado no proprio WhatsApp (#proposta, #fechou).
   Arrastar aqui e a rede de seguranca para corrigir, nao o caminho
   principal: o pedido do projeto foi justamente nao ter que preencher
   CRM na mao.
============================================================ */

const PO_COLUNAS = [
  {status:'novo',        titulo:'Contato feito',    modo:'auto'},
  {status:'atendimento', titulo:'Qualificado',      modo:'auto'},
  {status:'negociacao',  titulo:'Proposta enviada', modo:'atalho'},
  {status:'venda',       titulo:'Contrato assinado',modo:'atalho'},
  {status:'perdido',     titulo:'Perdido',          modo:'auto'},
];

/* O status legado 'semresposta' nao tem coluna propria: ele e anterior ao
   WhatsApp e some com o tempo. Cai em 'novo' para o lead continuar
   visivel, em vez de desaparecer do quadro. */
function poAgrupaFunil(leads) {
  const cols = {};
  PO_COLUNAS.forEach(c => { cols[c.status] = []; });
  (leads || []).forEach(l => {
    const alvo = cols[l.status] ? l.status : 'novo';
    cols[alvo].push(l);
  });
  return cols;
}

function poCardFunil(l) {
  const valor = l.ven || l.orc;
  return '<article class="fn-card" draggable="true" data-id="' + esc(l.id) + '">' +
    '<div class="fn-card-n">' + esc(l.nome) + '</div>' +
    '<div class="fn-card-r">' + esc(l.roteiro) + '</div>' +
    '<div class="fn-card-f">' +
      '<span class="mono">' + esc(fmtData(l.data)) + '</span>' +
      (valor ? '<span class="money">' + brl(valor) + '</span>' : '') +
    '</div></article>';
}

function poRenderFunil() {
  const alvo = document.querySelector('#fn-board');
  if (!alvo) return;
  const cols = poAgrupaFunil(typeof filtered === 'function' ? filtered() : LEADS);

  alvo.innerHTML = PO_COLUNAS.map(c => {
    const lista = cols[c.status] || [];
    const etiqueta = c.modo === 'auto'
      ? '<span class="fn-modo fn-modo--auto">automatico</span>'
      : '<span class="fn-modo fn-modo--atalho">atalho</span>';
    return '<section class="fn-col" data-status="' + c.status + '">' +
      '<header class="fn-col-h"><span class="fn-col-t">' + c.titulo + '</span>' +
        '<span class="fn-col-n">' + lista.length + '</span></header>' +
      etiqueta +
      '<div class="fn-col-b">' + (lista.length ? lista.map(poCardFunil).join('') :
        '<div class="fn-vazio">nenhum</div>') + '</div>' +
      '</section>';
  }).join('');

  if (typeof poLigaMover === 'function') poLigaMover();
}
```

- [ ] **Step 4: Acrescentar o teste do card ao `tests/test-funil.mjs`**

Substituir a linha `const card = ...` por:

```js
// --- o card escapa dado de terceiro. O nome vem do perfil do WhatsApp.
const ctxCard = new Function(
  pega('function poCardFunil') +
  'function esc(s){return (s==null?"":String(s)).replace(/[&<>"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;","\\"":"&quot;"}[c]));}' +
  'function fmtData(){return "01 set";}function brl(n){return "R$ "+n;}' +
  '; return poCardFunil;'
)();
const html = ctxCard({id:7, nome:'<img src=x onerror=alert(1)>', roteiro:'Grecia', ven:0, orc:0, data:null});
assert.ok(!html.includes('<img src=x'), 'nome de terceiro escapado no card');
assert.ok(html.includes('data-id="7"'), 'o card carrega o id do lead');
assert.ok(html.includes('draggable="true"'), 'o card e arrastavel');
```

- [ ] **Step 5: Acrescentar a aba ao `painel/index.html`**

No `<nav>`, depois do botão de Clientes:

```html
      <button class="nav-item" data-view="funil">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="4" width="5" height="16"/><rect x="10" y="4" width="5" height="11"/><rect x="17" y="4" width="4" height="7"/></svg>
        Funil
      </button>
```

Depois da `<section id="view-clientes">`:

```html
    <section class="view" id="view-funil">
      <div class="fn-board" id="fn-board"></div>
    </section>
```

E a tag de script, depois de `clientes.js`:

```html
<script src="funil.js"></script>
```

- [ ] **Step 6: Ligar a navegação no `painel/app.js`**

Junto da linha da Task 3:

```js
  if(v==='funil'&&typeof poRenderFunil==='function')poRenderFunil();
```

- [ ] **Step 7: Estilos em `painel/painel.css`**

```css
/* ---------- aba Funil ---------- */
.fn-board{display:grid;grid-template-columns:repeat(5,minmax(190px,1fr));gap:12px;
  overflow-x:auto;padding-bottom:8px}
@media(max-width:1100px){.fn-board{grid-template-columns:repeat(5,minmax(180px,1fr));width:max-content;min-width:100%}}
.fn-col{background:var(--bg-2);border:1px solid var(--line);border-radius:12px;padding:12px 10px;
  display:flex;flex-direction:column;gap:8px;min-height:180px}
.fn-col.fn-col--alvo{border-color:var(--azul);background:rgba(31,168,221,.06)}
.fn-col-h{display:flex;align-items:center;justify-content:space-between;gap:8px}
.fn-col-t{font-size:.84rem;font-weight:600}
.fn-col-n{font-family:var(--mono,monospace);font-size:.78rem;color:var(--txt-3)}
.fn-modo{font-size:.64rem;letter-spacing:.08em;text-transform:uppercase;padding:2px 7px;
  border-radius:5px;align-self:flex-start}
.fn-modo--auto{background:rgba(31,168,221,.12);color:var(--azul)}
.fn-modo--atalho{background:rgba(255,176,32,.12);color:#ffb020}
.fn-col-b{display:flex;flex-direction:column;gap:8px}
.fn-card{background:var(--bg-3);border:1px solid var(--line);border-radius:9px;padding:9px 10px;
  cursor:pointer;display:flex;flex-direction:column;gap:3px}
.fn-card:hover{border-color:var(--azul)}
.fn-card.fn-card--arrastando{opacity:.45}
.fn-card-n{font-size:.86rem;font-weight:600}
.fn-card-r{font-size:.76rem;color:var(--txt-2)}
.fn-card-f{display:flex;align-items:center;justify-content:space-between;gap:8px;
  font-size:.72rem;color:var(--txt-3);margin-top:2px}
.fn-vazio{font-size:.74rem;color:var(--txt-3);padding:6px 2px}
```

- [ ] **Step 8: Rodar os testes**

Run: `node tests/test-funil.mjs`
Expected: `test-funil OK`

Run: `php tests/run.php`
Expected: `Todos os testes passaram`

- [ ] **Step 9: Conferir no navegador**

Com `php -S 127.0.0.1:8080 -t .` rodando, abrir a aba Funil. Confirmar: cinco colunas na ordem certa, os leads aparecem na coluna do seu status, as contagens batem com a aba Leads, e as etiquetas de "automatico" e "atalho" aparecem. Reduzir a janela para 400px e confirmar que o quadro rola na horizontal em vez de espremer.

- [ ] **Step 10: Commit**

```bash
git add painel/funil.js painel/index.html painel/painel.css painel/app.js tests/test-funil.mjs
git commit -m "feat(painel): aba Funil com as cinco colunas do kanban

As etiquetas de 'automatico' e 'atalho' na coluna existem para deixar
claro o que o robo preenche sozinho e o que depende de um gesto dela: as
duas colunas do meio so se movem com o atalho digitado no WhatsApp.

O status legado semresposta cai em 'Contato feito' em vez de sumir: ele e
anterior ao WhatsApp, mas os leads existem e precisam ficar visiveis."
```

---

### Task 5: Mover o card entre colunas

**Files:**
- Modify: `painel/funil.js` (acrescenta o mover ao final)
- Modify: `painel/painel.css` (menu de etapas do celular)
- Modify: `painel/index.html` (o `<div>` do menu de etapas)
- Test: `tests/test-funil.mjs`

**Interfaces:**
- Consumes: `PO_COLUNAS`, `poRenderFunil()` (Task 4); do `app.js` os globais `sb`, `LEADS`, `toast()`.
- Produces: `poLigaMover()`, `poMoveLead(id, status)`, `poPatchStatus(status, lead)`.

**Por que arrastar não é a única forma:** o HTML puro não tem arrastar por toque, e o projeto não usa biblioteca de front. No desktop o arrastar nativo funciona; no celular, tocar no card abre um menu com as cinco etapas.

- [ ] **Step 1: Escrever o teste que falha**

Acrescentar a `tests/test-funil.mjs`, antes do `console.log`:

```js
// --- a regra de negocio do mover, isolada da UI: mover para 'venda'
// precisa carimbar venda_at, e tirar de 'venda' precisa limpar, senao o
// relatorio de ciclo de venda mente.
const ctxMove = new Function(
  pega('function poPatchStatus') + '; return poPatchStatus;'
)();

const paraVenda = ctxMove('venda', {ven: 22900, vendaAt: null});
assert.equal(paraVenda.status, 'venda', 'grava o status');
assert.ok(paraVenda.venda_at, 'mover para venda carimba venda_at');

const jaTinha = ctxMove('venda', {ven: 22900, vendaAt: '2026-03-20T12:00:00Z'});
assert.equal(jaTinha.venda_at, '2026-03-20T12:00:00Z', 'venda que ja tinha carimbo mantem a data original');

const saiuDeVenda = ctxMove('negociacao', {ven: 22900, vendaAt: '2026-03-20T12:00:00Z'});
assert.equal(saiuDeVenda.status, 'negociacao', 'grava o status novo');
assert.equal(saiuDeVenda.venda_at, null, 'tirar de venda limpa o carimbo');

const comum = ctxMove('atendimento', {ven: 0, vendaAt: null});
assert.equal(comum.venda_at, undefined, 'movimento comum nao mexe em venda_at');
```

- [ ] **Step 2: Rodar e confirmar que falha**

Run: `node tests/test-funil.mjs`
Expected: FAIL com `function poPatchStatus encontrada no funil.js`

- [ ] **Step 3: Implementar o mover**

Acrescentar ao final de `painel/funil.js`:

```js
/* ---------- mover o card ---------- */

/* O que vai para o banco quando o status muda. Separado da UI porque a
   regra do venda_at e de negocio, nao de interface: ele alimenta o ciclo
   de venda dos relatorios, e um carimbo errado faz o relatorio mentir
   sem que ninguem perceba. */
function poPatchStatus(status, lead) {
  const patch = {status};
  if (status === 'venda') {
    // Venda que ja tinha carimbo mantem a data original: mover o card de
    // novo nao pode reescrever quando a venda aconteceu.
    if (!lead.vendaAt) patch.venda_at = new Date().toISOString();
    else patch.venda_at = lead.vendaAt;
  } else if (lead.vendaAt) {
    patch.venda_at = null;
  }
  return patch;
}

async function poMoveLead(id, status) {
  const l = LEADS.find(x => String(x.id) === String(id));
  if (!l || l.status === status) return;

  const antes = {status: l.status, vendaAt: l.vendaAt};
  const patch = poPatchStatus(status, l);

  // Move na tela primeiro: o quadro responde na hora e desfaz se o banco
  // recusar. Esperar a rede para so entao mover deixa a sensacao de travado.
  l.status = status;
  if ('venda_at' in patch) l.vendaAt = patch.venda_at;
  poRenderFunil();

  const {error} = await sb.from('po_leads').update(patch).eq('id', l.id);
  if (error) {
    l.status = antes.status;
    l.vendaAt = antes.vendaAt;
    poRenderFunil();
    toast('Nao consegui mover: ' + error.message, true);
    return;
  }
  const col = PO_COLUNAS.find(c => c.status === status);
  toast('Movido para ' + (col ? col.titulo : status) + '.');
}

/* Arrastar so existe no desktop: o HTML puro nao tem arrastar por toque, e
   o projeto nao carrega biblioteca de front. No celular o toque abre o
   menu de etapas, que e ate mais rapido do que arrastar com o dedo. */
function poLigaMover() {
  const board = document.querySelector('#fn-board');
  if (!board) return;
  const toque = window.matchMedia('(hover: none)').matches;

  board.querySelectorAll('.fn-card').forEach(card => {
    if (toque) {
      card.draggable = false;
      card.onclick = () => poAbreMenuEtapa(card.dataset.id);
      return;
    }
    card.ondblclick = () => openDrawer(card.dataset.id);
    card.ondragstart = e => {
      e.dataTransfer.setData('text/plain', card.dataset.id);
      e.dataTransfer.effectAllowed = 'move';
      card.classList.add('fn-card--arrastando');
    };
    card.ondragend = () => card.classList.remove('fn-card--arrastando');
  });

  board.querySelectorAll('.fn-col').forEach(col => {
    col.ondragover = e => { e.preventDefault(); col.classList.add('fn-col--alvo'); };
    col.ondragleave = () => col.classList.remove('fn-col--alvo');
    col.ondrop = e => {
      e.preventDefault();
      col.classList.remove('fn-col--alvo');
      const id = e.dataTransfer.getData('text/plain');
      if (id) poMoveLead(id, col.dataset.status);
    };
  });
}

function poAbreMenuEtapa(id) {
  const l = LEADS.find(x => String(x.id) === String(id));
  if (!l) return;
  const menu = document.querySelector('#fn-menu');
  menu.querySelector('#fn-menu-n').textContent = l.nome;
  menu.querySelector('#fn-menu-o').innerHTML = PO_COLUNAS.map(c =>
    '<button class="fn-menu-b' + (c.status === l.status ? ' on' : '') +
      '" data-status="' + c.status + '">' + c.titulo + '</button>').join('') +
    '<button class="fn-menu-b fn-menu-b--ver" data-ver="1">Ver o lead completo</button>';
  menu.querySelectorAll('.fn-menu-b').forEach(b => {
    b.onclick = () => {
      menu.classList.remove('on');
      document.querySelector('#scrim').classList.remove('on');
      if (b.dataset.ver) openDrawer(id);
      else if (b.dataset.status !== l.status) poMoveLead(id, b.dataset.status);
    };
  });
  document.querySelector('#scrim').classList.add('on');
  menu.classList.add('on');
}
```

- [ ] **Step 4: Acrescentar o menu ao `painel/index.html`**

Junto da ficha, antes do `</body>`:

```html
<div class="fn-menu" id="fn-menu">
  <div class="fn-menu-h">Mover <b id="fn-menu-n"></b> para:</div>
  <div class="fn-menu-o" id="fn-menu-o"></div>
</div>
```

- [ ] **Step 5: Estilos em `painel/painel.css`**

```css
/* Menu de etapas do celular. Vem de baixo, que e onde o polegar alcanca. */
.fn-menu{position:fixed;left:0;right:0;bottom:0;z-index:70;background:var(--bg-2);
  border-top:1px solid var(--line);border-radius:16px 16px 0 0;padding:16px 14px 22px;
  transform:translateY(100%);transition:transform .25s ease}
.fn-menu.on{transform:none}
.fn-menu-h{font-size:.86rem;color:var(--txt-2);margin-bottom:12px}
.fn-menu-o{display:flex;flex-direction:column;gap:7px}
.fn-menu-b{width:100%;text-align:left;padding:13px 14px;border-radius:10px;cursor:pointer;
  background:var(--bg-3);border:1px solid var(--line);color:inherit;font:inherit;font-size:.9rem}
.fn-menu-b.on{border-color:var(--azul);color:var(--azul)}
.fn-menu-b--ver{margin-top:6px;background:transparent;color:var(--txt-3)}
```

- [ ] **Step 6: Rodar os testes**

Run: `node tests/test-funil.mjs`
Expected: `test-funil OK`

Run: `php tests/run.php`
Expected: `Todos os testes passaram`

- [ ] **Step 7: Conferir no navegador, nos dois modos**

Com o servidor rodando: no desktop, arrastar um card entre colunas e confirmar que ele fica na coluna nova depois de recarregar a página (foi ao banco). Arrastar um para "Contrato assinado" e conferir na aba Leads que o ciclo de venda passou a ter valor. Arrastar de volta e conferir que o ciclo voltou a "—".

Depois, abrir as ferramentas do navegador, ativar a emulação de dispositivo móvel, recarregar, e confirmar que tocar num card abre o menu de etapas em vez de arrastar.

- [ ] **Step 8: Commit**

```bash
git add painel/funil.js painel/index.html painel/painel.css tests/test-funil.mjs
git commit -m "feat(painel): mover card no funil, arrastando ou pelo menu

Arrastar so existe no desktop porque o HTML puro nao tem arrastar por
toque e o projeto nao carrega biblioteca de front. No celular o toque abre
um menu com as cinco etapas, que e ate mais rapido que arrastar com o dedo.

O card move na tela antes da resposta do banco e desfaz se o update
falhar: esperar a rede para so entao mover da sensacao de painel travado.

A regra do venda_at ficou separada da UI e testada: venda que ja tinha
carimbo mantem a data original, e tirar de venda limpa o carimbo. Errar
ali faz o relatorio de ciclo mentir sem ninguem perceber."
```

---

### Task 6: A conversa do WhatsApp dentro do lead

**Files:**
- Create: `painel/conversa.js`
- Modify: `painel/index.html` (abas na gaveta, tag de script)
- Modify: `painel/painel.css` (abas e bolhas)
- Modify: `painel/app.js` (a gaveta carrega a conversa ao abrir)
- Test: `tests/test-conversa.mjs`

**Interfaces:**
- Consumes: tabela `po_wa_mensagens` (criada no plano 1: colunas `wa_id`, `wamid`, `direcao`, `autor`, `tipo`, `texto`, `ts`); do `app.js` os globais `sb`, `esc()`.
- Produces: `poBolha(msg)`, `poRenderConversa(wa_id)`.

- [ ] **Step 1: Escrever o teste que falha**

Criar `tests/test-conversa.mjs`:

```js
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const src = readFileSync(new URL('../painel/conversa.js', import.meta.url), 'utf8');
function pega(nome) {
  const m = src.match(new RegExp('function ' + nome + '\\([\\s\\S]*?\\n\\}'));
  assert.ok(m, nome + ' encontrada no conversa.js');
  return m[0];
}
const poBolha = new Function(
  pega('poBolha') +
  'function esc(s){return (s==null?"":String(s)).replace(/[&<>"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;","\\"":"&quot;"}[c]));}' +
  '; return poBolha;'
)();

// --- os tres autores tem tratamento visual distinto: sem isso nao da
// para saber se foi o robo ou a dona que falou, que e o que ela mais
// precisa saber ao abrir a conversa.
assert.ok(poBolha({autor:'cliente', texto:'oi', ts:'2026-09-01T12:00:00Z'}).includes('cv--in'),
  'cliente tem bolha de entrada');
assert.ok(poBolha({autor:'robo', texto:'segue o roteiro', ts:'2026-09-01T12:00:00Z'}).includes('cv--bot'),
  'robo tem bolha propria');
assert.ok(poBolha({autor:'humano', texto:'bom dia', ts:'2026-09-01T12:00:00Z'}).includes('cv--out'),
  'a dona tem bolha de saida');

// --- texto do cliente e dado de terceiro
const mal = poBolha({autor:'cliente', texto:'<img src=x onerror=alert(1)>', ts:'2026-09-01T12:00:00Z'});
assert.ok(!mal.includes('<img src=x'), 'texto do cliente e escapado');
assert.ok(mal.includes('&lt;img'), 'aparece escapado, nao sumido');

// --- quebra de linha vira <br>: as duas perguntas do robo sao enviadas
// num texto so com linhas em branco, e sem isso saem todas grudadas.
const multi = poBolha({autor:'robo', texto:'1. tem data?\n\n2. ja viajou?', ts:'2026-09-01T12:00:00Z'});
assert.ok(multi.includes('<br>'), 'quebra de linha preservada');

// --- mensagem sem texto (audio, imagem) nao pode sair em branco
const audio = poBolha({autor:'cliente', texto:'', tipo:'audio', ts:'2026-09-01T12:00:00Z'});
assert.ok(audio.includes('udio') || audio.includes('nexo'), 'mensagem nao textual e descrita');

console.log('test-conversa OK');
```

- [ ] **Step 2: Rodar e confirmar que falha**

Run: `node tests/test-conversa.mjs`
Expected: FAIL, arquivo não existe

- [ ] **Step 3: Implementar**

Criar `painel/conversa.js`:

```js
/* ============================================================
   A conversa do WhatsApp dentro da gaveta do lead.

   Le po_wa_mensagens, que o motor alimenta com as tres pontas do dialogo:
   o cliente, o robo e a propria dona (pelo eco do celular). Saber QUEM
   falou e o que ela mais precisa ao abrir: se o robo ja mandou o roteiro,
   ela nao repete.
============================================================ */

const PO_AUTOR = {
  cliente: {cls:'cv--in',  nome:'Cliente'},
  robo:    {cls:'cv--bot', nome:'Automatico'},
  humano:  {cls:'cv--out', nome:'Voce'},
};

function poBolha(m) {
  const a = PO_AUTOR[m.autor] || PO_AUTOR.cliente;
  const hora = (function () {
    const d = new Date(m.ts);
    return isNaN(d) ? '' : d.toLocaleDateString('pt-BR', {day:'2-digit', month:'2-digit'}) +
      ' ' + d.toLocaleTimeString('pt-BR', {hour:'2-digit', minute:'2-digit'});
  })();

  // Mensagem sem texto e audio, imagem ou figurinha. Sair em branco faria
  // parecer que a conversa tem buracos.
  let corpo = String(m.texto == null ? '' : m.texto);
  if (!corpo.trim()) {
    const t = m.tipo || '';
    corpo = t === 'audio' ? '(audio)'
          : t === 'image' ? '(imagem)'
          : t === 'document' ? '(documento)'
          : '(anexo)';
    corpo = esc(corpo);
  } else {
    corpo = esc(corpo).replace(/\n/g, '<br>');
  }

  return '<div class="cv ' + a.cls + '">' +
    '<span class="cv-w">' + a.nome + ' · ' + esc(hora) + '</span>' +
    '<div class="cv-t">' + corpo + '</div></div>';
}

async function poRenderConversa(waId) {
  const alvo = document.querySelector('#dr-conversa');
  if (!alvo) return;

  if (!waId || waId === '—') {
    alvo.innerHTML = '<div class="empty">Este lead nao veio pelo WhatsApp.</div>';
    return;
  }
  alvo.innerHTML = '<div class="empty">Carregando...</div>';

  const {data, error} = await sb.from('po_wa_mensagens')
    .select('autor,direcao,tipo,texto,ts')
    .eq('wa_id', waId)
    .order('ts', {ascending: true})
    .limit(300);

  if (error) { alvo.innerHTML = '<div class="empty">Nao consegui carregar a conversa.</div>'; return; }
  if (!data || !data.length) { alvo.innerHTML = '<div class="empty">Nenhuma mensagem ainda.</div>'; return; }

  alvo.innerHTML = data.map(poBolha).join('');
  alvo.scrollTop = alvo.scrollHeight;
}
```

- [ ] **Step 4: Abas na gaveta, em `painel/index.html`**

Logo depois de `<div class="dr-body">`, abrir as abas:

```html
    <div class="dr-tabs">
      <button class="dr-tab on" data-tab="dados">Dados</button>
      <button class="dr-tab" data-tab="conversa">Conversa</button>
    </div>
    <div class="dr-pane on" id="pane-dados">
```

Fechar `</div>` antes do fim do `.dr-body` e acrescentar o painel da conversa:

```html
    </div>
    <div class="dr-pane" id="pane-conversa">
      <div class="cv-wrap" id="dr-conversa"></div>
    </div>
```

E a tag de script, depois de `funil.js`:

```html
<script src="conversa.js"></script>
```

- [ ] **Step 5: Ligar no `painel/app.js`**

Em `openDrawer()`, antes da linha que abre a gaveta (`$('#scrim').classList.add('on')`), acrescentar:

```js
  // A conversa carrega junto com a gaveta, mas o telefone do lead e a
  // chave: quem veio pelo formulario do site nao tem conversa.
  $$('.dr-tab').forEach(t=>t.classList.toggle('on',t.dataset.tab==='dados'));
  $('#pane-dados').classList.add('on');$('#pane-conversa').classList.remove('on');
  if(typeof poRenderConversa==='function')poRenderConversa(l.waId||l.tel);
```

E no `mapRow()` (`painel/app.js:114`), acrescentar o campo que hoje não é lido:

```js
    waId:r.wa_id||'',
```

E, junto dos outros handlers, a troca de aba:

```js
$$('.dr-tab').forEach(t=>t.onclick=()=>{
  $$('.dr-tab').forEach(x=>x.classList.remove('on'));t.classList.add('on');
  $('#pane-dados').classList.toggle('on',t.dataset.tab==='dados');
  $('#pane-conversa').classList.toggle('on',t.dataset.tab==='conversa');
});
```

- [ ] **Step 6: Estilos em `painel/painel.css`**

```css
/* ---------- abas da gaveta e conversa ---------- */
.dr-tabs{display:flex;gap:6px;margin-bottom:14px;border-bottom:1px solid var(--line)}
.dr-tab{background:none;border:none;color:var(--txt-3);font:inherit;font-size:.86rem;
  padding:8px 12px;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px}
.dr-tab.on{color:var(--azul);border-bottom-color:var(--azul)}
.dr-pane{display:none}
.dr-pane.on{display:block}
.cv-wrap{display:flex;flex-direction:column;gap:8px;max-height:60vh;overflow-y:auto;padding-right:4px}
.cv{max-width:85%;padding:8px 11px;border-radius:11px;font-size:.84rem;line-height:1.45}
.cv--in{align-self:flex-start;background:var(--bg-3);border:1px solid var(--line)}
.cv--out{align-self:flex-end;background:rgba(37,211,102,.12);border:1px solid rgba(37,211,102,.25)}
.cv--bot{align-self:flex-end;background:rgba(31,168,221,.1);border:1px solid rgba(31,168,221,.28)}
.cv-w{display:block;font-size:.66rem;letter-spacing:.06em;text-transform:uppercase;
  color:var(--txt-3);margin-bottom:4px}
```

- [ ] **Step 7: Rodar os testes**

Run: `node tests/test-conversa.mjs`
Expected: `test-conversa OK`

Run: `php tests/run.php`
Expected: `Todos os testes passaram`

- [ ] **Step 8: Conferir no navegador**

A tabela `po_wa_mensagens` está vazia (o motor ainda não rodou em produção), então insira duas mensagens de teste pelo SQL Editor do Supabase para ver a tela funcionando, usando o telefone de um lead que já existe:

```sql
insert into public.po_wa_mensagens (wa_id, wamid, direcao, autor, tipo, texto, ts) values
  ('+5548999999999','teste-1','in','cliente','text','oi, queria saber da Turquia', now() - interval '10 minutes'),
  ('+5548999999999','teste-2','out','robo','text','Segue o roteiro completo.' || chr(10) || chr(10) || '1. Tem disponibilidade?' || chr(10) || '2. Ja viajou em grupo?', now());
```

Abrir a gaveta de um lead com esse telefone, clicar em Conversa, confirmar as bolhas com autores distintos e as quebras de linha. Depois apagar as duas linhas de teste:

```sql
delete from public.po_wa_mensagens where wamid in ('teste-1','teste-2');
```

Confirmar também que um lead sem WhatsApp mostra "Este lead nao veio pelo WhatsApp" em vez de erro.

- [ ] **Step 9: Commit**

```bash
git add painel/conversa.js painel/index.html painel/painel.css painel/app.js tests/test-conversa.mjs
git commit -m "feat(painel): conversa do WhatsApp dentro da gaveta do lead

Le po_wa_mensagens com as tres pontas do dialogo: o cliente, o robo e a
propria dona pelo eco do celular. Saber quem falou e o que ela mais
precisa ao abrir, para nao repetir o que o robo ja mandou.

Mensagem sem texto (audio, imagem) e descrita em vez de sair em branco,
senao a conversa parece ter buracos. Quebra de linha vira <br> porque as
duas perguntas do robo vao num texto so, com linhas em branco."
```

---

## Self-review

**Cobertura da spec:** seção 7 (etapas do funil) → Tasks 4 e 5, com as cinco colunas e o mapeamento de status intactos. Seção 7.1 → Task 2 (agrupamento), Task 3 (aba Clientes e ficha), Task 5 (arrastar no desktop, menu no celular), Task 6 (conversa como aba da gaveta). A Task 1 não vem da spec: é dívida encontrada no caminho, e precisa vir antes porque quatro das cinco tarefas seguintes testam em JavaScript.

**Fora deste plano:** a transmissão e o importador de contatos (plano 3), e a conexão real com a Meta.

**Consistência de nomes:** `poChavePessoa` é a única chave de agrupamento e é usada em `poAgrupaPessoas` (Task 2), `poLinhaPessoa` e `poAbreFicha` (Task 3). `PO_COLUNAS` é a única fonte da ordem das colunas, usada no render (Task 4) e no menu de etapas (Task 5). Todos os globais novos levam prefixo `po`/`PO_` para não colidir com o que o `app.js` já define.

**Riscos que o plano assume:** o `app.js` é modificado em quatro tarefas diferentes, sempre em pontos distintos (navegação, `mapRow`, `openDrawer`, handlers) — conflito entre tarefas é improvável, mas a ordem importa e não deve ser embaralhada. E o painel está em produção: nenhuma tarefa remove ou renomeia o que já existe, só acrescenta.
