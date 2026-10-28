# Fluxo do robô no painel (Plano B) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task.
> Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** dar à cliente a tela que lê o fluxo de conversa desenhado, edita os textos,
confere, publica e liga — hoje o motor existe e nada no painel o mostra.

**Architecture:** o painel já tem 12 abas e cada uma é um `painel/<nome>.js` com uma
`poCarrega<Nome>()` chamada pelo roteamento do `app.js`. Esta entra no mesmo porte. A
edição de card vai **direto ao Supabase pelo navegador** (sessão autenticada, a RLS da
migration já permite), como o resto do painel faz; o que precisa de PHP — conferir,
publicar, ligar — passa por `fluxo.php`, porque `wa_fluxo_valida` e `wa_fluxo_publica`
moram em `lib/wa-fluxo.php` e **não podem ser reimplementadas em JavaScript**.

**Tech Stack:** PHP 8 no cPanel (sem Composer, sem build) · HTML/CSS/JS sem framework ·
Supabase (PostgREST + Auth) · testes por `php tests/run.php`.

**Spec:** `docs/superpowers/specs/2026-10-27-fluxo-conversa-painel-design.md`
(as seções 6.2, 6.3, 6.6, 6.8, 8, 10 e 11 são as deste plano).

**Leitura obrigatória antes da Task 1:**
`docs/superpowers/reviews/2026-10-27-fluxo-motor-pendencias.md`, **seção 3** — nove
pontos que o Plano A deixou escritos para esta tela. Cada tarefa cita os que a tocam,
mas a lista inteira é contrato.

**O Plano A (o motor) está em `main` e NÃO está publicado:** as duas migrations nunca
rodaram e os quatro arquivos de `lib/` não subiram. Este plano termina com os dois indo
ao ar na mesma subida (receita no fim).

---

## Global Constraints

- **Repositório PÚBLICO.** Nenhum dado real de cliente em fixture, comentário ou teste.
  Telefone de teste: `+5548900000001` em diante.
- **Nenhum teste toca a rede.** O endpoint se testa com os transportes injetáveis que o
  Plano A já tem (`wa_fluxo_set_deps`, `wa_db_set_transport`, `wa_set_transport`).
- Teste `.php` usa `ok($cond, $msg)` com `exit(1)` na falha; teste `.mjs` usa
  `node:assert/strict`. Tudo roda por `php tests/run.php`.
- **Teste de painel extrai função pura do fonte por regex** e roda com `new Function`
  (porte de `tests/test-textos.mjs`). Consequência dura: **nenhuma constante de topo de
  arquivo pode ser dependência de função testada** — a lista de tipos mora DENTRO de uma
  função, como `poTextosCatalogo()` faz hoje.
- **A tela escapa HTML em todo texto que vem do banco ou da cliente**, incluindo as
  mensagens de `wa_fluxo_valida`. Elas interpolam texto da cliente sem escapar, o que está
  **correto** no PHP (lá é string) e seria injeção no DOM. Herança 1.
- **A tela NÃO reimplementa nenhuma das 16 regras de validação em JavaScript.** Herança 2.
  Precedente da casa: `wa_tpl_problemas` + o modo `conferir` do `templates.php`.
- **`fluxo.php` exige login** (`po_auth_ok`), como `templates.php` e `limpa-cache.php`.
- **Cache-buster obrigatório:** arquivo novo em `painel/` entra no `painel/assets-lock.json`
  com `v` e `sha`, e o `?v=` do `index.html` sobe junto. `tests/test-cache-buster.mjs` amarra
  o `?v=` ao SHA **normalizado** (este repo tem `core.autocrlf=true`).
- Tokens do painel: `--azul #1FA8DD`, `--verde #84C440`, Rubik (títulos) e DM Sans (corpo).
- Segredo vem de `wa_config()`, nunca de `po_config()`.
- **Nada liga o robô.** Este plano não inscreve webhook, não cadastra cron e não mexe no
  `config.local.php`. Fluxo nasce e continua `ativo = false` até a cliente clicar.

---

## Review Focus

Cinco classes de entrada que a spec implica e que nenhuma tarefa exercitaria por acaso.
Cada linha ganhou teste na tarefa dona do código.

1. **Texto, rótulo ou título com `<`, `&` ou `"`.** A cliente escreve os textos. Um rótulo
   `A data serve <ou não>` tem que aparecer como texto no card, na opção, na gaveta e **na
   lista de problemas**. Herança 1. (Tasks 3, 5, 6)
2. **`opcoes` jsonb vazio, nulo ou malformado, e card de tipo que a tela não conhece.** O
   banco é editável e o Plano C vai criar card novo. Card torto não pode derrubar a view
   inteira: ele aparece marcado e o resto do fluxo segue legível. (Task 3)
3. **Fluxo sem `versao_atual`** (nunca publicado) **e rascunho divergente do publicado.** A
   tela tem que dizer em que pé está, porque neste desenho "publiquei" e "está no ar" são
   coisas diferentes. (Tasks 1, 6)
4. **Ligar fluxo cuja porta já está ocupada.** O índice único parcial devolve erro cru do
   Postgres. A tela tem que dizer **qual fluxo** ocupa a porta, no porte do `rotSaveErro`
   que já resolveu isso para slug de roteiro. (Task 2)
5. **Celular a 390px.** A Laís usa o painel no celular, e o ponto de quebra de 980px nunca
   foi visto por ninguém (CLAUDE.md, conferência visual de 08/10). Ler o fluxo inteiro e
   abrir a gaveta tem que funcionar **sem canvas**. (Tasks 3, 4)

---

## Decisões deste plano

Fixadas aqui porque a spec deixou em aberto ou porque a herança do Plano A as força.

- **D1. A tela alcança os DOIS fluxos semeados**, com um seletor simples. A spec põe "lista
  de fluxos" no Plano C, mas a herança 7 exige ligar a **Lista de espera antes** da
  Boas-vindas (a regra 11 recusa `ir_para_fluxo` para fluxo desligado). Sem alcançar o
  segundo fluxo a cliente não cumpre a ordem e vai concluir que o fluxo dela está quebrado.
  **Criar, apagar e editar porta de entrada seguem no Plano C.**
- **D2. A gaveta edita `texto`, `lembrete_ativo`, `lembrete_texto` e o `rotulo` das opções.
  Nada mais.** Não edita `id` de opção, `tipo`, setas (`proximo`, `fallback`,
  `sem_resposta`) nem estrutura. Razões: herança 8 (opção de id `sair`, `parar` ou
  `descadastrar` faria o toque no botão descadastrar o cliente de marketing, e nada no
  validador impede) e herança 4 (o `humano` do grafo é eleito pela ordem de bytes dos ids).
  Estrutura é Plano C, e lá os ids nascem gerados, não digitados.
- **D3. Edição de card vai direto ao Supabase; `fluxo.php` só confere, publica e liga.**
  Ver Architecture.
- **D4. Publicar limpa o cache do site** (`po_cache_limpa()`), pelo mesmo motivo que salvar
  roteiro limpa: o `po-data.php` tem cache de 10 minutos em disco.
- **D5. A LISTA é o caminho primário e o canvas é melhoria do desktop.** Mobile-first, por
  causa do Review Focus 5. O canvas desenha a partir do `x`/`y` que a semente já gravou.
- **D6. "Textos do robô" só sai no mesmo deploy em que as migrations rodam.** Tirar a aba
  antes faria a cliente perder a tela dos 7 textos sem ganhar os cards, porque é a migration
  da semente que transforma os 7 textos em `texto` de card (spec §6.6).

---

## Estrutura de arquivos

| Arquivo | Responsabilidade |
|---|---|
| `fluxo.php` (novo) | endpoint: `ler`, `conferir`, `publicar`, `ligar`, `desligar`. Autenticado. |
| `painel/fluxo.js` (novo) | espelho dos tipos, lista de cards, canvas, gaveta, botões |
| `painel/fluxo.css` (novo) | só desta tela (`painel.css` já é o maior arquivo do painel) |
| `painel/index.html` | aba "Fluxo do robô" no lugar de "Textos do robô"; `?v=` dos dois novos |
| `painel/app.js` | roteamento da view nova |
| `painel/assets-lock.json` | entram `fluxo.js` e `fluxo.css`; sai `textos.js` |
| `painel/textos.js` | **removido** |
| `tests/test-textos.mjs` | **removido** (o que ele travava do `index.html` migra para `test-fluxo-painel.mjs`) |

Testes novos: `tests/test-fluxo-endpoint.php`, `tests/test-wa-fluxo-tipos.mjs`,
`tests/test-fluxo-painel.mjs`. Alterado: `tests/test-cache-buster.mjs`.
A suíte vai de 56 para **58** arquivos (entram 3, sai 1).

---

### Task 1: `fluxo.php` — login, leitura do rascunho e conferência

**Files:**
- Create: `fluxo.php`
- Test: `tests/test-fluxo-endpoint.php`

**Interfaces:**
- Consome: `po_auth_ok($cfg['SUPABASE_URL'], $cfg['SUPABASE_ANON_KEY'])` (`lib/po-auth.php`,
  porte do `limpa-cache.php`) · `wa_fluxo_valida($fluxo, $cards, $outros = [])` e
  `wa_fluxo_grafo_de($fluxo, $cards)` (`lib/wa-fluxo.php`, puras) · a leitura estrita do
  Plano A para ler fluxos e cards.
- **`$outros` é load-bearing e o endpoint tem que passá-lo:** é a lista dos **demais**
  fluxos, e é com ela que `wa_fluxo_valida` avalia a regra 9 (porta de entrada já ocupada
  por outro fluxo ativo) e a regra 11 (`ir_para_fluxo` apontando para fluxo publicado **e**
  ativo). Chamar sem ele faz o `conferir` da tela aprovar exatamente o que o `publicar`
  recusa — a classe de defeito que este projeto já nomeou: lados que leem valores
  diferentes. O teste trava isso.
- Produz: `POST fluxo.php` com `modo` em `{ler, conferir}`.
  `ler` → `{ok:true, fluxos:[{id, nome, gatilho, gatilho_valor, ativo, card_inicial,
  versao_atual, versao_numero, publicado_igual, cards:[...]}]}`.
- **`publicado_igual` é bool e se calcula comparando `wa_fluxo_grafo_de($fluxo, $cards)` com
  o `grafo` da versão apontada por `versao_atual`.** Não sai de data: `po_wa_cards` **não
  tem `updated_at`**, então não existe carimbo para comparar com `publicado_at`. Comparar o
  retrato é exato e é a mesma função que o publicar usaria. Fluxo nunca publicado
  (`versao_atual` nulo) vem com `publicado_igual: false`.
  `conferir` (recebe `fluxo_id`) → `{ok:true, problemas:[...]}`, repassando **sem alterar** o
  que `wa_fluxo_valida` devolve.
- **A forma de um problema é `['regra'=>int, 'card'=>id|null, 'texto'=>string,
  'bloqueia'=>bool]`**, e é **uma lista só**: quem separa bloqueio de aviso é o campo
  `bloqueia`. As regras **1 a 14 bloqueiam; 15 e 16 avisam** (15 = card sem nenhuma entrada,
  16 = fluxo sem card de fim). Não achatar para lista de strings: o `regra` e o `card` são
  exatamente o que a Task 6 precisa para cumprir a herança 6.
  Erro → `{ok:false, error:string}` com HTTP 4xx/5xx.

- [ ] **Step 1: escrever o teste que falha** — `tests/test-fluxo-endpoint.php`, com
  transporte dublê. Casos: sem login devolve 401 e **não** chega a ler o banco · `modo` fora
  da lista devolve 400 · `modo[]=ler` (array no POST) não estoura TypeError no PHP 8 · `ler`
  devolve os dois fluxos com os cards de cada um · fluxo sem `versao_atual` vem com
  `versao_numero: null` e **não** é erro (Review Focus 3) · `conferir` com `fluxo_id`
  inexistente devolve 404 · **erro de leitura do banco vira 502, nunca lista vazia** (regra
  da casa, `wa_db_select_estrito`: Supabase fora do ar lido como vazio faz a tela dizer que
  o fluxo não tem card nenhum) · `publicado_igual` é `true` quando o rascunho bate com o
  retrato publicado, `false` depois de mudar o texto de um card, e `false` em fluxo nunca
  publicado · **`conferir` passa `$outros`**: um fluxo com
  `ir_para_fluxo` para destino desligado tem que vir com o problema da regra 11, e a mesma
  porta de outro fluxo ativo com o da regra 9. É esta asserção que impede o `conferir`
  aprovar o que o `publicar` recusa.
- [ ] **Step 2: rodar e ver falhar** — `php tests/test-fluxo-endpoint.php` → ASSERT.
- [ ] **Step 3: escrever o `fluxo.php`** no porte do `templates.php`: helpers `ffail`/`fok`/
  `fpost`, `Content-Type: application/json`, POST obrigatório, `modo` numa lista fechada,
  login **antes** de qualquer leitura. A mensagem de erro nunca carrega nome, telefone nem
  trecho de ficha (ela vaza para log, print e suporte).
- [ ] **Step 4: rodar e ver passar.**
- [ ] **Step 5: trava de fonte no mesmo arquivo de teste** — `fluxo.php` chama
  `wa_fluxo_valida` e **não** contém nenhuma mensagem de problema escrita por extenso
  (herança 2, lado do PHP).
- [ ] **Step 6: commit.**

---

### Task 2: `fluxo.php` — publicar, ligar e desligar

**Files:**
- Modify: `fluxo.php`
- Test: `tests/test-fluxo-endpoint.php`

**Interfaces:**
- Consome: `wa_fluxo_publica($fluxo_id)` · `po_cache_limpa()` (`lib/po-data.php`).
- `wa_fluxo_publica` devolve `['ok'=>bool, 'versao'=>int|null, 'problemas'=>[...]]`, **lê os
  outros fluxos por conta própria** (então aqui não se passa `$outros`), **valida dentro** —
  não existe caminho que publique sem validar — e **não toca em `ativo`**. O endpoint
  repassa; não reinterpreta.
- Produz: `modo` em `{publicar, ligar, desligar}`.
  `publicar` → `{ok:true, versao:int}`, ou `{ok:false, problemas:[...]}` com 400.
  `ligar` / `desligar` → `{ok:true, ativo:bool}`.

- [ ] **Step 1: escrever o teste que falha.** Casos: `publicar` com grafo inválido devolve
  400 **com a lista de problemas** e não grava versão · `publicar` válido grava a versão e
  **chama a limpeza de cache** (o dublê conta a chamada) · `publicar` **não** mexe em
  `ativo` (fluxo desligado continua desligado: é a trava do "publicar ≠ ligar") · `ligar`
  com a porta ocupada por outro fluxo ativo devolve 409 e a mensagem **nomeia o fluxo que
  ocupa a porta** (Review Focus 4) · `desligar` funciona mesmo com o grafo inválido, senão
  um fluxo quebrado fica ligado sem saída.
- [ ] **Step 2: rodar e ver falhar.**
- [ ] **Step 3: implementar os três modos.** Para o 409: o PostgREST devolve violação do
  índice `po_wa_fluxos_porta_ativa_uniq`; traduzir consultando qual fluxo ativo tem a mesma
  `(gatilho, coalesce(gatilho_valor, ''))` e citar o nome dele. Porte: `rotSaveErro`.
- [ ] **Step 4: rodar e ver passar.**
- [ ] **Step 5: commit.**

---

### Task 3: `painel/fluxo.js` — espelho dos tipos e a lista do fluxo

**Files:**
- Create: `painel/fluxo.js`
- Test: `tests/test-wa-fluxo-tipos.mjs`, `tests/test-fluxo-painel.mjs`

**Interfaces:**
- Produz: `poFluxoTipos()` → `[{tipo, rotulo, manda, espera}]`, **na mesma ordem** de
  `WA_CARD_TIPOS` do PHP · `poFluxoCard(card)` → HTML de um card, escapado ·
  `poFluxoLista(fluxo)` → HTML da coluna de cards · `poCarregaFluxo()`, a entrada que o
  `app.js` chama.
- A lista de tipos mora **dentro** de `poFluxoTipos()` (Global Constraints).

- [ ] **Step 1: escrever `tests/test-wa-fluxo-tipos.mjs`** — extrai `poFluxoTipos` do fonte,
  lê `WA_CARD_TIPOS` de `lib/wa-fluxo.php` por regex e compara: mesmos valores, **mesma
  ordem**, cada tipo com rótulo em português legível. Porte de `tests/test-status-tag.mjs`.
- [ ] **Step 2: escrever `tests/test-fluxo-painel.mjs`** com os casos do Review Focus:
  título, texto e rótulo com `<script>`, `&` e `"` saem escapados (assertiva sobre a string
  de saída, não sobre "não quebrou") · card com `opcoes: []`, `opcoes: null` e `opcoes` que
  não é lista renderiza marcado e **não lança** · card de `tipo` desconhecido renderiza
  marcado e não lança · card `botoes` com 3 opções mostra as 3.
- [ ] **Step 3: rodar os dois e ver falhar.**
- [ ] **Step 4: escrever o `painel/fluxo.js`** com as quatro funções, o `esc()` do porte da
  casa, e o `poCarregaFluxo()` chamando `fluxo.php` em `modo=ler`. Render mobile-first: uma
  coluna de cards, cada um com tipo, título, prévia do texto e as saídas nomeadas.
- [ ] **Step 5: rodar e ver passar.**
- [ ] **Step 6: commit.**

---

### Task 4: o canvas do desktop

**Files:**
- Modify: `painel/fluxo.js`
- Create: `painel/fluxo.css`
- Test: `tests/test-fluxo-painel.mjs`

**Interfaces:**
- Produz: `poFluxoCanvas(fluxo)` → HTML dos cards posicionados mais um `<svg>` com um
  segmento por seta. Lê `card.x` e `card.y`, que a semente já gravou.

- [ ] **Step 1: escrever o teste que falha** — `poFluxoCanvas` emite um nó por card e um
  segmento por saída **existente**; saída `null` não vira segmento (senão a seta aponta para
  o canto); card sem `x`/`y` cai numa posição derivada da ordem, nunca em `NaN`.
- [ ] **Step 2: rodar e ver falhar.**
- [ ] **Step 3: implementar `poFluxoCanvas` e o `painel/fluxo.css`.** O canvas é **melhoria
  do desktop**: a partir de 980px ele substitui a lista, abaixo disso a lista é o que
  aparece, e **quem decide é o CSS**, não JavaScript lendo largura (D5).
- [ ] **Step 4: rodar e ver passar.**
- [ ] **Step 5: commit.**

---

### Task 5: a gaveta do card

**Files:**
- Modify: `painel/fluxo.js`, `painel/fluxo.css`
- Test: `tests/test-fluxo-painel.mjs`

**Interfaces:**
- Produz: `poFluxoGaveta(card)` → HTML da gaveta · `poFluxoLimite(tipo)` → 1024 para
  `botoes`, `lista` e legenda de `pdf`, 4096 para `texto` · `poFluxoPiorCaso(texto)` → o
  tamanho do texto **depois** da expansão de pior caso (`{roteiro}` 60, `{nome}` 40,
  `{data}` 40) · `poFluxoSalva(card)` → o PATCH no Supabase.

- [ ] **Step 1: escrever o teste que falha.** Casos: `poFluxoPiorCaso` conta a expansão e não
  o texto cru (um texto de 1000 caracteres com `{roteiro}` estoura 1024, e é isso que a tela
  avisa; conferir o cru deixaria a Meta devolver 400 e o cliente não receber nada, em
  silêncio) · `poFluxoLimite` por tipo · a gaveta de card que **não** espera não oferece
  lembrete · a gaveta mostra o limite de 3 botões e não deixa passar de 3 · **trava de
  fonte: o que `poFluxoSalva` escreve é exatamente `texto`, `lembrete_ativo`,
  `lembrete_texto` e `opcoes[].rotulo`** — o corpo da função não cita `id`, `tipo`,
  `proximo`, `fallback` nem `sem_resposta` (D2, heranças 4 e 8).
- [ ] **Step 2: rodar e ver falhar.**
- [ ] **Step 3: implementar a gaveta**, com contador de caracteres ao vivo mostrando o pior
  caso, e o aviso de variável desconhecida **vindo do endpoint**, nunca reimplementado aqui.
- [ ] **Step 4: rodar e ver passar.**
- [ ] **Step 5: commit.**

---

### Task 6: Publicar, Ligar e a lista de problemas

**Files:**
- Modify: `painel/fluxo.js`, `painel/fluxo.css`
- Test: `tests/test-fluxo-painel.mjs`

**Interfaces:**
- Produz: `poFluxoProblemas(resposta)` → HTML da lista, escapada ·
  `poFluxoPodePublicar(estado)` → bool · `poFluxoOrdemLigar(fluxos)` → a frase que diz qual
  ligar primeiro, ou string vazia.

- [ ] **Step 1: escrever o teste que falha.** Casos: problema com `<` sai escapado (Review
  Focus 1) · **o botão Publicar nasce desabilitado e só uma conferência limpa o habilita**,
  voltando a desabilitar depois de cada edição (porte exato da aba Modelos) · `Ligar` é botão
  separado de `Publicar` · **o problema de `regra 15` é escondido quando a mesma lista já
  traz um de `regra 3` com o mesmo `card`** (herança 6: card órfão dispara os dois, um
  bloqueando e o outro avisando, e duas linhas para o mesmo problema confundem; a forma
  `{regra, card}` da Task 1 é o que torna isso uma linha de código) · `bloqueia:false`
  aparece como aviso e **não** impede publicar · `poFluxoOrdemLigar` manda ligar a **Lista
  de espera antes** da Boas-vindas
  enquanto a espera estiver desligada, e cala quando ela já está ligada (herança 7) · fluxo
  com `publicado_igual: false` aparece como "tem edição não publicada", e fluxo ligado nesse
  estado avisa que **o que está no ar é a versão anterior** (Review Focus 3).
- [ ] **Step 2: rodar e ver falhar.**
- [ ] **Step 3: implementar.** `Publicar` chama `modo=publicar` e a recusa mostra os
  problemas; `Ligar`/`Desligar` chamam os modos, e o 409 da porta aparece com o nome do
  fluxo que a ocupa.
- [ ] **Step 4: rodar e ver passar.**
- [ ] **Step 5: commit.**

---

### Task 7: a aba, o roteamento, a trava de cache e a saída do `textos.js`

**Files:**
- Modify: `painel/index.html`, `painel/app.js`, `painel/assets-lock.json`,
  `tests/test-cache-buster.mjs`, `tests/test-fluxo-painel.mjs`
- Delete: `painel/textos.js`, `tests/test-textos.mjs`

- [ ] **Step 1: escrever as asserções que faltam** em `tests/test-fluxo-painel.mjs`,
  assumindo o que o `test-textos.mjs` travava do `index.html` e do `app.js`: a aba
  `data-view="fluxo"` existe e tem `#view-fluxo` · **não existe mais** `data-view="textos"`,
  nem `#view-textos`, nem `textos.js` em tag alguma · o `app.js` roteia `fluxo` chamando
  `poCarregaFluxo` · a lista de scripts do `index.html` é exatamente a esperada, agora com
  `fluxo.js` e sem `textos.js`.
- [ ] **Step 2: rodar e ver falhar.**
- [ ] **Step 3: trocar a aba** no `painel/index.html` ("Textos do robô" → "Fluxo do robô",
  com `fluxo.css?v=1` e `fluxo.js?v=1`), rotear no `app.js`, e **apagar** `painel/textos.js`
  e `tests/test-textos.mjs`.
- [ ] **Step 4: atualizar o `painel/assets-lock.json`** — entram `fluxo.js` e `fluxo.css`,
  sai `textos.js`. Rodar `php tests/run.php`: o teste imprime o SHA novo para colar.
- [ ] **Step 5: rodar a suíte inteira** e ver os 58 arquivos verdes. O `test-wa-schema.php`
  segue vermelho enquanto houver `config.local.php` e as migrations não tiverem rodado: é a
  sonda dizendo a verdade, e fica verde no passo 2 da receita.
- [ ] **Step 6: commit.**

---

## Receita de deploy (Plano A + Plano B juntos, numa subida)

O Plano A nunca foi publicado, então os dois vão juntos. **Nada liga o robô.**

1. **Rodar as duas migrations no Supabase, nesta ordem:** `2026-10-27-fluxo-conversa.sql`,
   depois `2026-10-27-fluxo-semeado.sql`. A conversão de estado do fim da primeira é de
   **rodada única** (está escrito no cabeçalho dela): reaplicada depois do go-live, ela
   colapsa em `encerrado` o marcador de cadeia parada (`novo` **com** `card_atual`) e as
   conversas recém-criadas, e essas pessoas voltariam a receber as boas-vindas e o PDF.
2. **Conferir as três TABELAS e as cinco COLUNAS**, não só as tabelas: se a parte de
   `alter table` falhar sozinha, a sonda de tabela passa verde e o motor quebra em produção.
   `select fluxo_versao_id, card_atual, tentativas from po_wa_conversas limit 1` e
   `select interesses, espera from po_leads limit 1`. Depois disto, `php tests/run.php` fica
   inteiro verde.
3. **Conferir que a semente nasceu desligada:**
   `select nome, ativo, versao_atual from po_wa_fluxos` → dois fluxos, `ativo = false` nos
   dois, `versao_atual` preenchido nos dois.
4. **FTP, nesta ordem:** `lib/wa-fluxo.php` → `lib/wa-send.php` → `lib/wa-motor.php` →
   `lib/wa-timeout.php` → `fluxo.php` → `painel/fluxo.css` → `painel/fluxo.js` →
   `painel/app.js` → **`painel/index.html` por último**. Duas regras na ordem:
   `wa-fluxo.php` antes do `wa-motor.php` porque o motor faz `require_once` dele (a janela
   invertida dá fatal error em `whatsapp.php` e `wa-cron.php`), e o `index.html` por último
   porque é ele quem passa a pedir os arquivos novos.
5. **Apagar `painel/textos.js` do servidor** depois de o `index.html` subir.
6. **Não** cadastrar cron e **não** inscrever o webhook. Com o webhook inscrito e nenhum
   fluxo ligado o robô não responde nada — a cliente precisa saber disso antes, porque o
   motor antigo mandava o menu.
7. Conferir no ar com **Ctrl+F5** e `bash scripts/confere-deploy.sh`.
8. **Avisar a cliente de duas coisas** antes de ela ligar: a ordem (Lista de espera
   primeiro, senão a triagem não republica) e que a triagem tem dois botões que hoje levam
   ao mesmo catálogo ("Roteiro específico" e "Ver a programação"), coisa que ela resolve
   pela própria tela com o filtro de ano.
