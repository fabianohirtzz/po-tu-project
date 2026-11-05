# Status semanal e datas do mês · Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Um roteiro que sai toda semana (o Peru) pode ser cadastrado com as datas de cada mês, editáveis pela cliente, e aparece como "Saídas semanais" com a próxima saída em todo lugar que mostra roteiro, inclusive na lista que o robô manda.

**Architecture:** Valor novo `semanal` em `po_roteiros.status_viagem` (a lista de status já vive em três arquivos que andam juntos, com teste cruzado) mais coluna nova `datas_texto` em jsonb, agrupada por mês. A derivação de "próxima saída" nasce num arquivo folha novo e puro (`lib/po-datas.php`), que PHP e o motor do robô passam a requerer, com gêmeos em JavaScript no `roteiros-shared.js` e no painel, pinados por teste cruzado — o mesmo arranjo que a lista de status já usa. Os nove pontos que hoje imprimem `data_label ?: periodo` inline passam a chamar o helper.

**Tech Stack:** PHP 8 sem Composer e sem build · HTML/CSS/JS puro no painel · Supabase via PostgREST · testes por `php tests/run.php`

**Spec:** `docs/superpowers/specs/2026-11-04-robo-entende-frase-e-roteiro-semanal-design.md` — **Parte B**, e o achado 3 (que é o que torna o item 4 do cliente possível só para o semanal). As Partes A e C têm planos próprios.

## Global Constraints

- PHP 8 no cPanel, **sem Composer e sem build**. Painel é HTML/CSS/JS puro, sem framework e sem biblioteca de front.
- `php tests/run.php` fica **verde ao fim de cada tarefa**. Hoje roda 76 arquivos.
- **Nada liga nada.** Os dois fluxos seguem `ativo = false` e o app segue não inscrito no webhook da WABA. Nenhuma tarefa aqui toca essas travas.
- **O repositório é PÚBLICO.** Nenhum dado real de cliente em fixture, comentário ou migration. Telefones de teste a partir de `+5548900000001`.
- **Arquivo listado em `painel/assets-lock.json` que muda exige subir o `?v=` no `painel/index.html` na MESMA tarefa**, senão `tests/test-cache-buster.mjs` fica vermelho. O teste imprime o sha novo para colar no lock.
- **Teste que lê fonte normaliza o fim de linha na LEITURA**, não em cada regex (`core.autocrlf=true` neste repo: arquivo novo nasce LF e vira CRLF no primeiro checkout que o recria).
- **`esc()` do painel não escapa aspa simples**: todo atributo HTML emitido usa aspas duplas.
- Copy em português, **sem travessões e sem emojis**, números concretos.
- Cada tarefa termina com **rodada de mutação** sobre o código que ela escreveu, e nenhum mutante pode sobreviver.
- `po_roteiros` é lido com `select=*` em todo lugar, então coluna nova chega sozinha ao site e ao painel.

## Review Focus

Cinco entradas que a spec implica e que nenhuma tarefa exercitaria por conta própria. Cada linha tem seu teste pinado na tarefa que é dona do código.

1. **Data impossível ou texto no lugar da data** (`32/13/99`, `dezembro`, `02-12-26`) digitada pela cliente: a página não pode quebrar e o roteiro não pode ir para o lugar errado da fila. → Tarefa 2.
2. **Todas as datas no passado** (ninguém atualizou há dois meses): tem que cair em `periodo` e **nunca** anunciar "Próxima saída" de data que já passou. → Tarefa 2.
3. **`semanal` com `datas_texto` vazio** (`[]`): a tag aparece e não há data nenhuma; os nove renderizadores têm que sobreviver e não imprimir rótulo órfão. → Tarefa 4.
4. **Quatro meses de datas estourando os 1024 caracteres da legenda do PDF**: a Meta recusa a mensagem inteira em silêncio. → Tarefa 6.
5. **`datas_texto` com forma errada** (objeto em vez de lista, `datas` como string, null) por edição à mão no SQL Editor: nenhum leitor pode dar fatal. → Tarefa 2 (PHP) e Tarefa 3 (JS).

---

## Estrutura de arquivos

| Arquivo | Responsabilidade |
|---|---|
| `supabase/migrations/2026-11-04-status-semanal-datas.sql` | **Criar.** Check de `status_viagem` aceitando `semanal`; coluna `datas_texto jsonb not null default '[]'`. |
| `lib/po-datas.php` | **Criar.** Arquivo folha, puro, **sem nenhum `require`**: normaliza `datas_texto`, acha a próxima saída, monta a string de exibição. É o que impede a conta de data de existir em três versões no PHP. |
| `lib/po-view.php` | Modificar. `PO_STATUS_VIAGEM` ganha `semanal`; passa a requerer `lib/po-datas.php`. |
| `lib/wa-roteiro.php` | Modificar. `wa_roteiro_data_ini` consulta a próxima saída quando o status é `semanal`; passa a requerer `lib/po-datas.php`. |
| `assets/js/roteiros-shared.js` | Modificar. `STATUS_VIAGEM` ganha `semanal`; ganha `poProximaSaida` e `poQuando`, gêmeos em JS do helper PHP. |
| `painel/app.js` | Modificar. `STATUS_VIAGEM` ganha `semanal`; cópia própria de `poProximaSaida`/`poQuando`; editor de `datas_texto` na gaveta. |
| `assets/css/status-tag.css` · `painel/painel.css` | Modificar. Cor e ícone de `semanal`. |
| `index.php` · `roteiro.php` · `roteiros.php` · `llms.php` | Modificar. Os pontos que imprimem a data passam a chamar `po_quando`. |
| `assets/js/main.js` · `assets/js/historia.js` | Modificar. Os pontos que imprimem a data passam a chamar `poQuando`. |
| `lib/wa-fluxo.php` | Modificar. A lista numerada e a variável `{data}` passam pelo helper; teto declarado das datas. |

Testes: `tests/test-po-datas.php` (criar) · `tests/test-datas-semanal.mjs` (criar, cruzado PHP×JS×painel) · `tests/test-rot-datas.mjs` (criar, editor do painel) · `tests/test-status-tag.mjs`, `tests/test-wa-roteiro-ordem.php`, `tests/test-wa-fluxo-roteiros.php`, `tests/test-cache-buster.mjs` (estender).

---

### Task 1: O status `semanal` existe de ponta a ponta

**Files:**
- Create: `supabase/migrations/2026-11-04-status-semanal-datas.sql`
- Modify: `lib/po-view.php` (`PO_STATUS_VIAGEM`), `assets/js/roteiros-shared.js` (`STATUS_VIAGEM`), `painel/app.js` (`STATUS_VIAGEM`), `assets/css/status-tag.css`, `painel/painel.css`, `painel/index.html` (`?v=`), `painel/assets-lock.json`
- Test: `tests/test-status-tag.mjs`, `tests/test-wa-fluxo-roteiros.php`

**Interfaces:**
- Consome: nada.
- Produz: a chave `'semanal' => 'Saídas semanais'` nas três listas, **na primeira posição**; a coluna `po_roteiros.datas_texto` (jsonb, default `[]`), usada por todas as tarefas seguintes.

- [ ] **Step 1: Escrever a migration**

Dois comandos, e **os dois são aditivos** (nenhum `drop`, nenhum `delete`, nenhum `update`): o check de `status_viagem` passa a aceitar `semanal` ao lado dos quatro valores atuais, e entra `datas_texto jsonb not null default '[]'::jsonb`.

Trocar um check exige derrubar o antigo, que é DDL e não comando destrutivo de dado. Se o portão do MCP recusar o bloco, **o dono roda no SQL Editor** e não se procura uma terceira porta.

- [ ] **Step 2: Estender `tests/test-status-tag.mjs` e ver vermelho**

A constante `LISTA` do teste passa a ser, nesta ordem exata:

```js
const LISTA = [['semanal', 'Saídas semanais'], ['em_breve', 'Em breve'], ['ultimas_vagas', 'Últimas vagas'], ['esgotado', 'Vagas esgotadas'], ['em_viagem', 'Em viagem']];
```

Mais a asserção direta da tag:

```js
assert.equal(tag({ status_viagem: 'semanal' }), '<span class="st-tag st-tag--semanal">Saídas semanais</span>');
```

O laço que já existe sobre `LISTA` cobra sozinho a cor e o ícone no `status-tag.css` e a cor no `painel.css`.

Run: `node tests/test-status-tag.mjs`
Expected: FAIL nas três listas e nos dois CSS.

- [ ] **Step 3: Escrever o teste de que o robô NÃO exclui semanal, e ver vermelho**

Em `tests/test-wa-fluxo-roteiros.php`: um roteiro `ativo` com `status_viagem = 'semanal'` **continua** no retorno de `wa_fluxo_roteiros_venda`, ao lado dos sem status, enquanto `esgotado` e `em_viagem` seguem fora.

Este é o teste mais importante da tarefa: um roteiro que sai toda semana desaparecer da lista do robô é o pior defeito possível desta parte. Ele passa sem mudar código (a função só exclui dois valores), e existe para travar — se alguém um dia acrescentar `semanal` à lista de exclusão, ele fica vermelho.

Run: `php tests/test-wa-fluxo-roteiros.php`
Expected: PASS (trava, não correção).

- [ ] **Step 4: Acrescentar `semanal` nas três listas e nos dois CSS**

`semanal` entra **primeiro** nas três: as outras quatro estão em ordem de ciclo da viagem e semanal não tem ciclo, é condição permanente.

Cor: azul da marca `#1FA8DD`, texto branco. Ícone: calendário com seta de repetição, no mesmo porte dos quatro existentes (SVG em `mask` no `.st-tag::before`, pintado com `currentColor`).

- [ ] **Step 5: Subir o `?v=` do `painel/app.js` e o `painel/assets-lock.json`**

`painel/app.js` está no lock. Rodar `php tests/run.php`, pegar o sha que o teste imprime, colar no lock e subir o `?v=` no `painel/index.html` na mesma tarefa.

- [ ] **Step 6: Suíte verde e rodada de mutação**

Run: `php tests/run.php`
Expected: tudo verde.

Mutantes obrigatórios: tirar `semanal` de cada uma das três listas (3 mutantes), movê-lo para o fim de uma delas (1), tirar a cor do `status-tag.css` (1), tirar o ícone (1), e acrescentá-lo à lista de exclusão de `wa_fluxo_roteiros_venda` (1). Os 7 têm que morrer.

- [ ] **Step 7: Commit**

```bash
git add supabase/migrations/2026-11-04-status-semanal-datas.sql lib/po-view.php assets/js/roteiros-shared.js assets/css/status-tag.css painel/app.js painel/painel.css painel/index.html painel/assets-lock.json tests/test-status-tag.mjs tests/test-wa-fluxo-roteiros.php
git commit -m "feat(roteiro): status semanal nas tres listas, no CSS e no banco"
```

---

### Task 2: `lib/po-datas.php`, e a ordenação do robô deixa de mandar o semanal para o fim

**Files:**
- Create: `lib/po-datas.php`, `tests/test-po-datas.php`
- Modify: `lib/po-view.php` (require), `lib/wa-roteiro.php` (require + `wa_roteiro_data_ini`)
- Test: `tests/test-po-datas.php`, `tests/test-wa-roteiro-ordem.php`

**Interfaces:**
- Consome: a coluna `datas_texto` da Tarefa 1.
- Produz, todas puras e em `lib/po-datas.php`:
  - `po_datas_grupos($roteiro): array` — lista de `['titulo' => string, 'datas' => string[]]`, descartando o que não tem a forma certa. Entrada que não é lista devolve `[]`.
  - `po_proxima_saida($roteiro, $hoje = null): ?string` — a primeira data **de hoje em diante** em `'YYYY-MM-DD'`, ou `null`. `$hoje` null = `date('Y-m-d')`.
  - `po_quando($roteiro, $hoje = null): string` — a string de exibição. Status `semanal` com próxima saída devolve `'Próxima saída: ' . <a data como a cliente digitou>`; qualquer outro caso devolve `data_label ?: periodo` (o comportamento de hoje).

**`lib/po-datas.php` não tem nenhum `require`.** É o que permite `lib/po-view.php` e `lib/wa-roteiro.php` requererem os dois sem criar ciclo, e mantém a seta de dependência do subsistema do robô intacta.

- [ ] **Step 1: Escrever `tests/test-po-datas.php` e ver vermelho**

Fixtures com datas em 2039/2040, nunca no ano corrente: fixture com data próxima do presente vira teste que muda de cor sozinho na virada do mês.

Asserções, com `$hoje` sempre passado explícito:

- `po_datas_grupos` com dois grupos bem formados devolve os dois, na ordem de entrada.
- `po_datas_grupos` com `datas_texto` = `null`, `'texto'`, `{"a":1}`, `[{"titulo":"x","datas":"02/12/39"}]` devolve `[]` em todos (**Review Focus 5**).
- `po_proxima_saida` com `['02/12/39','10/12/39','18/12/39']` e `$hoje='2039-12-05'` devolve `'2039-12-10'` — pula a que passou, pega a próxima, não a primeira da lista.
- `po_proxima_saida` com todas no passado devolve `null` (**Review Focus 2**).
- `po_proxima_saida` ignora `'32/13/99'`, `'dezembro'`, `'02-12-39'` e **continua procurando**, achando a data válida que vem depois na mesma lista (**Review Focus 1**).
- `po_proxima_saida` com ano de 2 dígitos (`'02/12/39'`) e de 4 (`'02/12/2039'`) dá o mesmo resultado.
- `po_quando` com status `semanal` e próxima saída devolve `'Próxima saída: 02/12/39'`, preservando exatamente o que foi digitado.
- `po_quando` com status `semanal` e **nenhuma** data futura devolve `data_label ?: periodo` (**Review Focus 2**).
- `po_quando` com status diferente de `semanal` devolve `data_label ?: periodo` **mesmo havendo `datas_texto` preenchido**: quem decide é o status, não a presença do dado.
- `po_quando` com `data_label` string vazia cai em `periodo` (é o caso real de todos os roteiros do banco).

Run: `php tests/test-po-datas.php`
Expected: FAIL com função não definida.

- [ ] **Step 2: Implementar as três funções em `lib/po-datas.php`**

A leitura de data reaproveita o critério que `wa_roteiro_data_ini` já usa: regex `d/m/a` com ano de 2 ou 4 dígitos, 2 dígitos virando `20xx`, e `checkdate` descartando data impossível sem interromper a busca.

- [ ] **Step 3: Rodar e ver verde**

Run: `php tests/test-po-datas.php`
Expected: PASS.

- [ ] **Step 4: Escrever o teste da ordenação e ver vermelho**

Em `tests/test-wa-roteiro-ordem.php`, com `$hoje` fixo: um roteiro `semanal` com `periodo` **vazio** e próxima saída em `02/12/39` ordena **pela próxima saída**, na frente de um roteiro comum com data em `03/12/39`, e não no fim da lista junto dos sem data.

Mais: roteiro `semanal` **sem nenhuma data futura** volta à regra atual (lê `periodo`/`data_label`/`descricao_curta`) e, não achando nada, vai para o fim.

Run: `php tests/test-wa-roteiro-ordem.php`
Expected: FAIL — hoje o semanal cai no fim.

- [ ] **Step 5: Fazer `wa_roteiro_data_ini` consultar a próxima saída quando o status é `semanal`**

Status `semanal` tenta `po_proxima_saida` primeiro; `null` cai na busca por campo de texto que já existe, sem mudança. Qualquer outro status não muda em nada.

- [ ] **Step 6: Suíte verde e rodada de mutação**

Run: `php tests/run.php`
Expected: tudo verde.

Mutantes obrigatórios: trocar `>=` por `>` na comparação com hoje (a saída de hoje tem que contar); tirar o `checkdate`; devolver a primeira data em vez da primeira futura; fazer `po_quando` olhar `datas_texto` sem olhar o status; tirar a volta à regra antiga quando não há data futura; inverter a ordem de `data_label ?: periodo`.

- [ ] **Step 7: Commit**

```bash
git add lib/po-datas.php lib/po-view.php lib/wa-roteiro.php tests/test-po-datas.php tests/test-wa-roteiro-ordem.php
git commit -m "feat(datas): proxima saida do roteiro semanal, e o robo ordena por ela"
```

---

### Task 3: Os gêmeos em JavaScript, pinados por teste cruzado

**Files:**
- Create: `tests/test-datas-semanal.mjs`
- Modify: `assets/js/roteiros-shared.js`, `painel/app.js`, `painel/index.html` (`?v=`), `painel/assets-lock.json`
- Test: `tests/test-datas-semanal.mjs`

**Interfaces:**
- Consome: `po_proxima_saida` e `po_quando` da Tarefa 2 (como referência de comportamento, não de código).
- Produz: `window.poProximaSaida(r, hoje)` e `window.poQuando(r, hoje)` em `assets/js/roteiros-shared.js`, com as mesmas assinaturas e os mesmos retornos das irmãs em PHP. Cópias de nome idêntico dentro de `painel/app.js`, porque **o painel não carrega `roteiros-shared.js`** (conferido no `painel/index.html`).
- Produz também, **no painel**, `rotDatasNormaliza(v): array` — a normalização da forma de `datas_texto`, gêmea de `po_datas_grupos`. Ela nasce aqui, e **o `poQuando` do painel a usa**, para a normalização não existir duas vezes dentro do mesmo arquivo. A Tarefa 5 a consome e não a redefine.

São três implementações pinadas por teste, que é exatamente o arranjo que a lista de status já usa neste projeto. Um arquivo compartilhado novo exigiria `<script>` em seis páginas públicas mais o painel e entrada nova no lock, para economizar oito linhas.

- [ ] **Step 1: Escrever `tests/test-datas-semanal.mjs` e ver vermelho**

Uma **tabela única de casos** — os mesmos do `tests/test-po-datas.php`, incluindo os malformados e os todos-no-passado — rodada contra as três implementações: o PHP por `exec` de um script de uma linha, o `roteiros-shared.js` por `new Function`, e a cópia do painel extraída do fonte de `painel/app.js`.

Uma tabela só é o ponto: a asserção é que **os três concordam caso a caso**, não que cada um passa na sua própria lista.

Normalizar o fim de linha **na leitura** de cada fonte.

Run: `node tests/test-datas-semanal.mjs`
Expected: FAIL — as funções em JS não existem.

- [ ] **Step 2: Implementar em `assets/js/roteiros-shared.js` e em `painel/app.js`**

- [ ] **Step 3: Rodar e ver verde**

Run: `node tests/test-datas-semanal.mjs`
Expected: PASS.

- [ ] **Step 4: Subir o `?v=` e o lock**

`painel/app.js` e `assets/js/roteiros-shared.js` mudaram. O `app.js` está no lock; o `roteiros-shared.js` é das páginas públicas e pede `?v=` novo nas seis que o carregam (`.htaccess` cacheia JS por um mês).

- [ ] **Step 5: Suíte verde e rodada de mutação**

Mutantes obrigatórios, **um em cada implementação de cada vez**: mudar o `>=` para `>` só no JS; fazer só a cópia do painel devolver a primeira data em vez da primeira futura; tirar o descarte de data inválida só no `roteiros-shared.js`. Cada um tem que morrer — é o que prova que o teste cruzado morde os três lados, e não só o PHP.

- [ ] **Step 6: Commit**

```bash
git add assets/js/roteiros-shared.js painel/app.js painel/index.html painel/assets-lock.json tests/test-datas-semanal.mjs
git commit -m "feat(datas): gemeos em JS de poQuando, pinados por teste cruzado"
```

---

### Task 4: Os nove pontos que imprimem a data passam pelo helper

**Files:**
- Modify: `index.php:29,42`, `roteiro.php:64,310`, `roteiros.php:77`, `llms.php:34`, `lib/po-data.php` (`po_ano`), `assets/js/main.js:73`, `assets/js/historia.js:73`, `assets/js/roteiros-shared.js:121` (`poCard`), `painel/app.js:582`, mais `?v=` e lock
- Test: `tests/test-roteiro-page.php`, `tests/test-roteiros-page.php`, `tests/test-home-index.php`, `tests/test-status-tag.mjs`

**Interfaces:**
- Consome: `po_quando` (Tarefa 2) e `poQuando` (Tarefa 3).
- Produz: nenhuma função nova. Depois desta tarefa **não existe mais `data_label ?: periodo` inline** em renderizador.

- [ ] **Step 1: Escrever os testes de ponto de chamada e ver vermelho**

Trava no ponto de chamada, não só na função pura: é a regra que este projeto já aprendeu duas vezes por defeito real.

Para cada renderizador em PHP, o teste lê o fonte e exige `po_quando(` no corpo da função que desenha, no mesmo porte que `tests/test-status-tag.mjs` já faz com `po_status_tag(`. Para os três em JS, exige `poQuando(`. E um teste a mais, negativo: **nenhum** desses arquivos contém mais a expressão inline.

Mais o comportamento de **Review Focus 3**: roteiro `semanal` com `datas_texto` igual a `[]` renderiza sem fatal e **sem o rótulo "Próxima saída" órfão** — a função cai em `periodo`, que para o Peru é vazio, então a data sai vazia como já sai hoje para qualquer roteiro sem data.

Run: `php tests/run.php`
Expected: FAIL nos pontos de chamada.

- [ ] **Step 2: Trocar os nove pontos pela chamada ao helper**

Cada um passa a chamar `po_quando($r)` / `poQuando(r)`. `po_ano` (o `<title>` "Excursão Peru 2026") também: para `semanal` o ano vem da próxima saída, senão o título nasce sem ano.

- [ ] **Step 3: Suíte verde**

Run: `php tests/run.php`
Expected: tudo verde.

- [ ] **Step 4: Subir os `?v=` e o lock**

- [ ] **Step 5: Rodada de mutação**

Mutantes: devolver a expressão inline em cada um dos nove pontos, um a um. Os nove têm que morrer — se um sobreviver, aquele ponto não está travado e é por ali que a regressão volta.

- [ ] **Step 6: Commit**

```bash
git add index.php roteiro.php roteiros.php llms.php lib/po-data.php assets/js/main.js assets/js/historia.js assets/js/roteiros-shared.js painel/app.js painel/index.html painel/assets-lock.json tests/
git commit -m "refactor(datas): os nove renderizadores chamam po_quando, nenhum inline"
```

---

### Task 5: O editor das datas na gaveta do painel

**Files:**
- Create: `tests/test-rot-datas.mjs`
- Modify: `painel/app.js` (gaveta, perto do `#r-status` da linha ~628, e o payload de salvar da ~878), `painel/painel.css`, `painel/index.html` (`?v=`), `painel/assets-lock.json`
- Test: `tests/test-rot-datas.mjs`

**Interfaces:**
- Consome: `datas_texto` (Tarefa 1).
- Consome também `rotDatasNormaliza(v)`, que a Tarefa 3 já criou no painel. **Não redefinir.**
- Produz, funções **puras** em `painel/app.js`, testáveis sem DOM:
  - `rotDatasAddMes(gs): array` · `rotDatasDelMes(gs, i): array` · `rotDatasAddData(gs, i): array` · `rotDatasDelData(gs, i, j): array` — todas devolvem **lista nova**, nunca mutam a de entrada.
  - `rotDatasDoForm(root): array` — lê os campos e monta o que vai para o banco, descartando grupo sem nenhuma data e data em branco.

- [ ] **Step 1: Escrever `tests/test-rot-datas.mjs` e ver vermelho**

Asserções sobre as puras: cada `Add`/`Del` devolve lista nova e não mexe na original (comparar por identidade); `rotDatasDelData` e `rotDatasDelMes` com índice fora da faixa não quebram e devolvem a lista inalterada; `rotDatasDoForm` descarta grupo com título e zero datas, e descarta data em branco no meio.

Mais duas travas de ponto de chamada, lendo o fonte: o `select` de status tem a opção `semanal`, e o payload do salvar inclui `datas_texto`. Sem a segunda, a cliente edita, salva, e o dado não vai — com o teste das puras todo verde.

Run: `node tests/test-rot-datas.mjs`
Expected: FAIL.

- [ ] **Step 2: Implementar as puras e fiá-las na gaveta**

O bloco fica **visível só quando `#r-status` é `semanal`** (mostrar ou esconder no `change` do select, sem recriar a gaveta). Por grupo: um campo de título e uma lista de campos de data, cada um com botão de excluir, mais "adicionar data" e "adicionar mês".

Atributos HTML com **aspas duplas** (`esc()` não escapa aspa simples).

- [ ] **Step 3: Rodar e ver verde**

Run: `node tests/test-rot-datas.mjs`
Expected: PASS.

- [ ] **Step 4: Ver a tela com os próprios olhos, a 1440 e a 390**

Nenhuma tela deste painel pode ser julgada por screenshot imediato: a aba pinta a moldura na hora e preenche depois do fetch. **Esperar de 5 a 10 segundos.** E clique sintético de automação some durante o boot: para trocar de aba, usar `document.querySelector('[data-view="..."]').click()`.

A janela do Chrome desta máquina não encolhe abaixo de ~1440px; para o mobile, usar o Playwright MCP.

Conferir: o bloco aparece ao escolher Saídas semanais e desaparece ao escolher outro status; adicionar e excluir data e mês funcionam; salvar e reabrir a gaveta traz as datas de volta.

- [ ] **Step 5: Subir o `?v=` do `painel/app.js` e o `painel/assets-lock.json`**

- [ ] **Step 6: Suíte verde**

Run: `php tests/run.php`
Expected: tudo verde.

- [ ] **Step 7: Rodada de mutação**

Mutantes: fazer um `Del` mutar a lista de entrada; fazer `rotDatasDoForm` manter grupo vazio; tirar `datas_texto` do payload; tirar a condição que esconde o bloco fora do status semanal.

- [ ] **Step 8: Commit**

```bash
git add painel/app.js painel/painel.css painel/index.html painel/assets-lock.json tests/test-rot-datas.mjs
git commit -m "feat(painel): editor das datas do mes na gaveta do roteiro semanal"
```

---

### Task 6: O robô diz as datas, e elas não estouram a legenda

**Files:**
- Modify: `lib/wa-fluxo.php` (`wa_fluxo_lista_roteiros` ~248, a variável `{data}` ~93, o ponto ~1036, e o teto)
- Test: `tests/test-wa-fluxo-roteiros.php`, `tests/test-wa-fluxo-limite.php` (ou o arquivo que já cobre `WA_FLUXO_LIMITE`)

**Interfaces:**
- Consome: `po_quando` e `po_proxima_saida` (Tarefa 2).
- Produz: `WA_DATAS_MAX`, o teto declarado de caracteres das datas no texto do robô.

- [ ] **Step 1: Escrever os testes e ver vermelho**

- A lista numerada de um roteiro `semanal` imprime `'1. Peru Completo (Próxima saída: 02/12/39)'`, não `'1. Peru Completo'` sem data. Hoje ela lê `data_label ?: periodo` inline, que para o semanal é vazio — a lista sairia sem data **enquanto a Tarefa 2 a ordena pela próxima saída**, e a lista contradiria a própria ordem.
- A variável `{data}` de um roteiro `semanal` traz a próxima saída, não `'na data prevista'`.
- **Review Focus 4:** `datas_texto` com quatro meses de datas, montado no texto, é **cortado em `WA_DATAS_MAX`** e o resultado cabe nos 1024 caracteres da legenda do card de PDF. Fixture com quatro grupos de oito datas, e a asserção é sobre o comprimento final do texto montado.

Cortar e não recusar, aqui, porque o texto é montado pelo sistema a partir do que a cliente digitou, e uma campanha de datas truncada ainda serve; o que não serve é a Meta recusar a mensagem inteira em silêncio. (Rótulo de botão, que é escrito à mão, segue sendo **recusado** e não truncado — regra diferente, motivo diferente.)

Run: `php tests/run.php`
Expected: FAIL.

- [ ] **Step 2: Implementar**

- [ ] **Step 3: Suíte verde e rodada de mutação**

Mutantes: tirar o corte em `WA_DATAS_MAX`; pôr o teto acima de 1024; fazer a lista numerada voltar ao inline; fazer `{data}` ignorar o status semanal.

- [ ] **Step 4: Atualizar o `CLAUDE.md`**

Seção nova com: o status `semanal` e as três listas mais o CSS; `datas_texto` e sua forma; **a armadilha da ordenação** (semanal sem tratamento vai para o fim da lista, que é onde ficam as viagens que já partiram); `lib/po-datas.php` como arquivo folha sem `require`; as três implementações pinadas por teste cruzado e por quê; o teto das datas na legenda; e a **ordem de deploy**: `lib/po-datas.php` **antes** de `lib/po-view.php` e `lib/wa-roteiro.php`, que passam a requerê-lo, e o `painel/index.html` **por último**.

- [ ] **Step 5: Commit**

```bash
git add lib/wa-fluxo.php tests/ CLAUDE.md
git commit -m "feat(robo): a lista e o {data} dizem a proxima saida do semanal, com teto"
```

---

## Depois do plano

Suíte verde **na árvore mesclada**, não só na branch: fim de linha muda no checkout do merge e já deixou um teste de fonte vermelho com o arquivo idêntico.

O deploy é FTP manual, na ordem do Step 4 da Tarefa 6, e **quem decide se o arquivo subiu é o hash**, nunca o código de saída do `curl`. Conferir com `bash scripts/confere-deploy.sh`, que monta a lista a partir do próprio `painel/index.html`. `painel/assets-lock.json` **não vai para o servidor**.

A migration é aditiva e toca só `po_roteiros`, que é tabela `po_*`: nada de NOX/hd360 no projeto compartilhado.
