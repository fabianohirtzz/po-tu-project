# Ordem por data e simulador do chat — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A lista de roteiros que o robô manda sai da viagem mais próxima para a mais distante, e a Laís percorre o fluxo inteiro dentro do painel, sem mandar mensagem para ninguém.

**Architecture:** A data vem do texto que já existe (`periodo`, com `data_label` e `descricao_curta` como última tentativa), lida por uma função pura, e a ordenação entra num ponto só: `wa_fluxo_roteiros_venda`, que é a mesma lista que vira texto numerado e que casa a resposta por número. O simulador roda o **motor de verdade** — `wa_fluxo_executa` + `wa_fluxo_proximo`, mais as funções `wa_send_*` reais com `wa_set_transport()` falso — então ele enxerga os limites da Cloud API e as recusas que o cliente enxergaria, sem rede, sem gravar e sem tocar o funil. O estado da conversa simulada vive no navegador e volta em cada passo: nenhuma linha nova no banco.

**Tech Stack:** PHP 8 no cPanel (sem Composer, sem build), painel HTML/JS sem framework, Supabase via PostgREST, testes `php tests/run.php`.

**Spec:** `docs/superpowers/specs/2026-10-27-fluxo-conversa-painel-design.md` (§6.9 Simulador; §7 item 7; §10 Plano C). Pendências herdadas: `docs/superpowers/reviews/2026-10-27-fluxo-painel-pendencias.md` (§3, as três pré-condições).

## Global Constraints

- **Nada é ligado nesta branch.** O webhook segue não inscrito, os dois fluxos seguem `ativo = false`, nenhum cron é cadastrado. O simulador não muda isso e não depende disso.
- **O motor só lê `po_wa_fluxo_versoes.grafo`** em produção. O simulador é a única exceção e lê o **rascunho**, montado por `wa_fluxo_grafo_de()`, que é a mesma função que o Publicar usa. Não existe segunda montagem.
- **`wa_fluxo_publica` nunca escreve `ativo`** (há trava de fonte). Nada neste plano a toca.
- **`lib/wa-fluxo.php` nunca requer `lib/wa-motor.php`.** A seta é uma só: db/send/po-data ← fluxo ← sim/motor ← timeout. `lib/wa-sim.php` pode requerer `wa-fluxo.php`; o contrário é proibido.
- **Trava no PONTO DE CHAMADA, não só na função pura.**
- **Teste que lê fonte normaliza o fim de linha na LEITURA** (`core.autocrlf=true` neste repo): em PHP, `str_replace("\r\n", "\n", file_get_contents(...))` com as escapes de duas letras, conferidas com `cat -A`; em `.mjs`, uma função `le(rel)` por onde passam todas as leituras.
- **Nenhum teste toca a rede:** `wa_motor_set_deps`, `wa_fluxo_set_deps`, `wa_db_set_transport`, `wa_set_transport`, `wa_timeout_set_deps`, `po_auth_set_verificador`.
- **Arquivo novo ou alterado em `painel/` exige bump do `?v=` em `painel/index.html` E do sha em `painel/assets-lock.json`** (`tests/test-cache-buster.mjs` trava). Criar arquivo em `painel/` e fiá-lo são a **mesma** tarefa.
- **Repositório PÚBLICO:** nenhum dado real de cliente em fixture. Telefones de teste a partir de `+5548900000001`.
- Copy: português, sem travessão, sem emoji.
- Limites da Cloud API, que o simulador deve respeitar porque usa as funções reais: 4096 para texto, 1024 para corpo interativo e legenda de documento, 3 botões, rótulo de botão recusado (não truncado) acima de 20 caracteres em `mb_strlen`, lista com no máximo 10 itens.

## Review Focus

1. **Período sem data que o parser leia** ("Fevereiro de 2027", vazio, `null`) — o roteiro vai para o **fim** da lista e os outros continuam na ordem certa; nunca cai no meio nem derruba a ordenação. Teste na Task 1.
2. **Dois roteiros com a mesma data** — a ordem entre eles é sempre a mesma em requisições diferentes (empate desfeito pela posição de origem), senão o número que a pessoa leu muda de roteiro entre o envio e a resposta. Teste na Task 1.
3. **Rascunho que não publica** (4 botões, rótulo de 21 caracteres, texto acima do limite) — o simulador mostra a **recusa real** do `wa_send_*`, com o motivo, e não uma bolha vazia. Teste na Task 2.
4. **`ir_para_fluxo` para um fluxo sem cards, inexistente ou desligado** — o simulador diz o que aconteceria e para; não entra em laço nem devolve 500. Teste na Task 2.
5. **Estado vindo do navegador com lixo** (`card_id` fora do grafo, slug inventado, `roteiros_enviados` gigante, `ctx` que não é objeto) — o endpoint recusa com 400 ou segue o caminho de fallback do próprio grafo, e em nenhum caso grava. Teste na Task 3.

---

### Task 1: A lista do chat sai em ordem de data

**Files:**
- Create: `tests/test-wa-roteiro-ordem.php`
- Modify: `lib/wa-roteiro.php` (a função de leitura da data), `lib/wa-fluxo.php` (`wa_fluxo_roteiros_venda`, e a closure `$roteiros` dentro de `wa_fluxo_executa`, hoje na linha ~926)

**Interfaces:**
- Produces:
  - `wa_roteiro_data_ini(array $roteiro): ?string` — `'YYYY-MM-DD'` ou `null`. Procura a **primeira** data `d/m/a` válida, nesta ordem de campos: `periodo`, `data_label`, `descricao_curta`. Ano de 2 dígitos vira `20xx`. Data impossível (`31/02/26`) é descartada e a busca continua na mesma string e depois no campo seguinte.
  - `wa_fluxo_ordena_roteiros(array $roteiros): array` — data crescente; sem data vai para o fim; empate desfeito pela posição de origem (ordenação estável e determinística).
- Consumes: nada.

- [ ] **Step 1: Escrever o teste `tests/test-wa-roteiro-ordem.php`**

Com os 4 formatos reais medidos no banco em 03/11/2026 (sem nome de cliente, só os campos do roteiro):

```php
// wa_roteiro_data_ini
['periodo' => 'De 22/02/2027 a 15/03/2027']        => '2027-02-22'
['periodo' => 'De 27/10/26 à 12/11/26']            => '2026-10-27'
['periodo' => '25/11/26 a 02/12/26']               => '2026-11-25'
['periodo' => 'De 18/03/27 à 09/04/27']            => '2027-03-18'
['periodo' => '', 'descricao_curta' => 'De 22/02/27 à 15/03/27'] => '2027-02-22'
['periodo' => 'Fevereiro de 2027']                 => null
['periodo' => '31/02/26 a 02/03/26']               => '2026-03-02'   // data impossivel descartada
[]                                                  => null

// wa_fluxo_ordena_roteiros: ordem e estabilidade
// entrada na ordem de `ordem.asc` que o banco devolve hoje
[vietna(2027-02-22), chile(2026-11-25)]            => [chile, vietna]
[a(sem data), b(2026-11-25), c(sem data)]          => [b, a, c]      // sem data no fim, na ordem de origem
[x(2026-11-25), y(2026-11-25)]                     => [x, y]         // empate: posicao de origem
```

Mais o invariante que amarra número e slug: `wa_fluxo_roteiros_venda` devolve a lista **já ordenada**, e `wa_fluxo_lista_roteiros` + `wa_fluxo_casa_roteiro('2', $slugs, $venda)` concordam sobre qual slug é o "2".

- [ ] **Step 2: Rodar e ver falhar**

Run: `php tests/test-wa-roteiro-ordem.php`
Expected: FAIL com "Call to undefined function wa_roteiro_data_ini".

- [ ] **Step 3: Implementar `wa_roteiro_data_ini` em `lib/wa-roteiro.php` e `wa_fluxo_ordena_roteiros` em `lib/wa-fluxo.php`**

A ordem dos campos é a do bloco de Interfaces, e o comentário no código tem que dizer **por que** `periodo` vem primeiro: medido em 03/11/2026, os 7 roteiros têm data em `periodo` e só 1 tem data em `descricao_curta` (nos outros 6 é texto de venda). Validar com `checkdate`.

- [ ] **Step 4: Chamar a ordenação nos dois pontos, e só neles**

`wa_fluxo_roteiros_venda` ordena antes de devolver (é a lista que a pessoa lê numerada e a mesma que casa a resposta por número, então as duas não podem divergir). A closure `$roteiros` de `wa_fluxo_executa` devolve o catálogo já ordenado, para o card `lista` legado herdar a mesma regra sem uma segunda chamada espalhada.

- [ ] **Step 5: Trava de fonte no mesmo arquivo de teste**

Ler `lib/wa-fluxo.php` normalizando o fim de linha e exigir que `wa_fluxo_roteiros_venda` e a closure `$roteiros` sejam os **únicos** lugares que chamam `wa_fluxo_ordena_roteiros`, e que nenhum `wa_fl_call('roteiros')` fora da closure monte lista para o cliente sem passar por `wa_fluxo_roteiros_venda`. Matar um mutante (remover a chamada da closure) para provar que a trava morde.

- [ ] **Step 6: Suíte inteira**

Run: `php tests/run.php`
Expected: 100% verde. `tests/test-wa-fluxo-roteiros.php` e `tests/test-wa-fluxo-semeado.php` cobrem a lista hoje e podem ter expectativa presa à ordem antiga: se alguma ficar vermelha, a correção é a expectativa, e o commit diz qual e por quê.

- [ ] **Step 7: Commit**

```bash
git add lib/wa-roteiro.php lib/wa-fluxo.php tests/test-wa-roteiro-ordem.php
git commit -m "feat(fluxo): a lista de roteiros do chat sai da viagem mais proxima para a mais distante"
```

---

### Task 2: O laço do simulador, rodando o motor de verdade

**Files:**
- Create: `lib/wa-sim.php`, `tests/test-wa-sim.php`
- Modify: nada

**Interfaces:**
- Consumes: Task 1 (a ordenação já vale aqui, de graça); `wa_fluxo_executa($grafo, $card_id, $wa_id, $ctx, $nome)`, `wa_fluxo_proximo($grafo, $card_id, $resposta, $ctx)`, `wa_fluxo_grafo_de($fluxo, $cards)`, `wa_fluxo_set_deps`, `wa_set_transport`.
- Produces:
  - `wa_sim_deps(): array` — instala o transporte falso e as dependências que **não escrevem**, e devolve o coletor. Fixa: `wa_set_transport` devolve `['status' => 200, 'body' => '{"messages":[{"id":"sim-<n>"}]}']` e guarda cada `payload` decodificado; `insert` e `update` são no-op que **registram a intenção** e devolvem o que o chamador espera (`insert` um array com `id`, `update` `true`); `select` e `select_estrito` devolvem `[]`; `roteiros` continua o `po_fetch_roteiros` **real**, porque o catálogo é o que ela precisa ver.
  - `WA_SIM_FONE = '5548900000001'` — o destino falso, que precisa passar por `wa_e164` para as funções de envio reais aceitarem.
  - `WA_SIM_MAX_PASSOS` — o mesmo teto do motor (`WA_FLUXO_MAX_PASSOS`), lido dele, nunca um número novo.
  - `wa_sim_passo(array $grafos, string $fluxo_id, ?string $card_id, string $resposta, array $ctx): array` — um passo da conversa simulada. `card_id` nulo começa pelo card inicial do fluxo. Devolve:
    ```
    ['ok' => bool, 'fluxo_id' => string, 'card_id' => ?string, 'ctx' => array,
     'estado' => 'aguardando'|'humano'|'encerrado'|'recusado'|'teto_passos',
     'mensagens' => [ ['tipo' => 'text'|'buttons'|'list'|'document',
                       'texto' => string,
                       'botoes' => [['id' => string, 'rotulo' => string]],
                       'itens'  => [['id' => string, 'titulo' => string, 'descricao' => string]],
                       'arquivo' => ?string] ],
     'avisos' => [string], 'erro' => ?string]
    ```
    `mensagens` sai do **payload capturado**, não do texto do card: é o que a Meta receberia. `avisos` é a lista de diferenças entre simulador e produção que a tela precisa dizer em voz alta.
  - `wa_sim_grafos(array $fluxos, array $cards): array` — `fluxo_id => grafo`, montado com `wa_fluxo_grafo_de` a partir do **rascunho**.

**Decisão registrada, e ela tem que aparecer na tela:** no salto `ir_para_fluxo`, o simulador vai para o rascunho do fluxo de destino **ignorando `ativo`**. Em produção a regra 11 bloqueia saltar para fluxo desligado, e hoje os dois estão desligados: sem essa diferença ela nunca alcançaria a Lista de espera no simulador. Cada salto para fluxo desligado empilha um aviso ("no ar, este salto exige a Lista de espera ligada"), e o aviso é testado.

- [ ] **Step 1: Escrever o teste `tests/test-wa-sim.php`**

Com um grafo de teste montado à mão (cards `texto` → `botoes` → `humano`) e, à parte, o grafo real da migration semeada lido do arquivo, como `tests/test-wa-fluxo-semeado.php` já faz:

- `wa_sim_passo` no card inicial devolve `estado = 'aguardando'` e as bolhas do caminho até a primeira espera, em ordem.
- Responder o id de um botão anda para o destino daquela opção.
- Resposta que não casa nenhuma opção cai no `fallback` do card, e na terceira tentativa vai para `humano` (o teto `WA_FLUXO_MAX_TENTATIVAS` é do motor e vale aqui).
- Card `botoes` com 4 opções devolve `ok = false` com o motivo real `'mais de 3 botoes'` em `erro`, e **nenhuma** bolha. Mesmo teste com rótulo de 21 caracteres: `'rotulo acima de 20 caracteres: ...'`.
- Card `roteiros` devolve a lista numerada **na ordem de data** da Task 1, com o `roteiros_enviados` correspondente no `ctx` de volta.
- `ir_para_fluxo` para fluxo desligado anda e empilha o aviso; para um `config.fluxo` que não existe em `$grafos`, `estado = 'recusado'` com erro claro, sem laço.
- Grafo que encadeia cards sem nunca esperar para em `estado = 'teto_passos'`.
- **Nada escreveu:** o coletor de `insert`/`update` registra a intenção do card `marca`, e o teste exige que a lista de escritas reais esteja vazia.
- Trava de fonte (com normalização de fim de linha na leitura): `lib/wa-sim.php` não contém `wa_db_insert`, `wa_db_update`, `wa_conversa_set`, `wa_nota_lead` nem `require` de `wa-motor.php`.

- [ ] **Step 2: Rodar e ver falhar**

Run: `php tests/test-wa-sim.php`
Expected: FAIL, `lib/wa-sim.php` não existe.

- [ ] **Step 3: Implementar `lib/wa-sim.php`**

O laço é o mesmo desenho de `wa_fluxo_percorre` (`lib/wa-motor.php:659`), **sem** a persistência: executa, se `terminal` para, se `!ok` para com o erro, se `espera` para, senão `wa_fluxo_proximo` e continua, até o teto. `ir_para_fluxo` troca o grafo e segue. Ele **não** chama `wa_fluxo_percorre`, que é casado com `wa_conversa_set`/`wa_nota_lead`.

- [ ] **Step 4: Rodar e ver passar**

Run: `php tests/test-wa-sim.php` → PASS; `php tests/run.php` → 100% verde.

- [ ] **Step 5: Rodada de mutação**

Pelo menos 6 mutantes dentro de `wa_sim_passo` e `wa_sim_deps`: tirar o teto de passos, deixar `insert` escrever de verdade, ignorar `terminal`, usar o texto do card em vez do payload capturado, perder o aviso do salto, trocar o fone falso por um inválido. Todos mortos, ou o teste que falhou em matar é corrigido antes do commit.

- [ ] **Step 6: Commit**

```bash
git add lib/wa-sim.php tests/test-wa-sim.php
git commit -m "feat(sim): laco do simulador rodando o motor real com transporte falso"
```

---

### Task 3: `fluxo.php modo=simular`

**Files:**
- Modify: `fluxo.php`, `tests/test-fluxo-endpoint.php`

**Interfaces:**
- Consumes: Task 2 (`wa_sim_passo`, `wa_sim_grafos`, `wa_sim_deps`); `po_auth_ok($url, $anon)`, que já guarda os outros 5 modos.
- Produces: `POST modo=simular` com `fluxo_id`, e o estado que o navegador devolve: `card_id` (vazio = começar), `resposta`, `ctx` (JSON). Responde o envelope de `wa_sim_passo` mais `fluxo_nome`. Sem login: **401**, como os outros modos. `fluxo_id` vazio ou inexistente: 400 com frase em português.

- [ ] **Step 1: Escrever os testes novos em `tests/test-fluxo-endpoint.php`**

No mesmo porte dos que já estão lá: `simular` entra na lista fechada de modos; sem login responde 401 **antes** de olhar o modo; `fluxo_id` vazio dá 400; `ctx` que não é JSON de objeto dá 400 e **não** chama `wa_sim_passo`; `card_id` que não existe no grafo dá 400; `roteiros_enviados` com mais de 50 itens é recusado (o número da pessoa é por posição, e uma lista enorme vinda do navegador não tem de onde ter nascido). Trava de fonte: `fluxo.php` não chama `wa_fluxo_publica` nem escreve `ativo` dentro do caminho de `simular`.

- [ ] **Step 2: Rodar e ver falhar**

Run: `php tests/test-fluxo-endpoint.php`
Expected: FAIL (modo desconhecido, 400).

- [ ] **Step 3: Implementar o modo em `fluxo.php`**

Acrescentar `'simular'` à lista do `in_array` da linha 75 e um bloco próprio, no padrão dos outros: lê os fluxos e os cards do rascunho com `wa_fl_ler` (como `wa_fluxo_publica` faz), monta os grafos, instala `wa_sim_deps()` e chama `wa_sim_passo`. A validação do `ctx` recebido fica numa função pura própria no mesmo arquivo, para o teste alcançá-la sem HTTP.

- [ ] **Step 4: Rodar e ver passar**

Run: `php tests/test-fluxo-endpoint.php` → PASS; `php tests/run.php` → 100% verde.

- [ ] **Step 5: Commit**

```bash
git add fluxo.php tests/test-fluxo-endpoint.php
git commit -m "feat(fluxo): modo simular no endpoint do painel"
```

---

### Task 4: A tela do simulador na aba Fluxo

**Files:**
- Create: `painel/simulador.js`, `tests/test-simulador.mjs`
- Modify: `painel/fluxo.css`, `painel/fluxo.js` (o botão na barra, hoje `poFluxoBarra`, linha ~782), `painel/index.html` (o `<script>` e o `?v=`), `painel/assets-lock.json`

**Interfaces:**
- Consumes: Task 3 (`modo=simular` e o envelope de resposta).
- Produces: `poSimAbre(fluxoId)`, `poSimFecha()`, e as funções puras que o teste alcança sem DOM: `poSimBolha(mensagem)` (uma mensagem do envelope → HTML da bolha, com os botões como `<button data-id>`), `poSimSelo(estado)` (o estado → rótulo em português), `poSimEstadoInicial(fluxoId)`.

A tela, no formato da terceira prancha do rascunho: conversa falsa em bolhas (robô à esquerda, cliente à direita), os botões do card como botões clicáveis de verdade, campo de texto para digitar (o público digita, e os cards que esperam têm caminho para quem digita), selo de estado ("Esperando o cliente" / "Entregaria para você" / "Encerrada" / "Recusada: &lt;motivo&gt;"), os `avisos` do envelope numa faixa, e "Começar de novo". Celular: ocupa a tela; desktop: ao lado do canvas. Dentro dos tokens do painel (`--azul #1FA8DD`, `--verde #84C440`, Rubik e DM Sans).

- [ ] **Step 1: Escrever o teste `tests/test-simulador.mjs`**

Com a função `le(rel)` normalizando o fim de linha na leitura, no porte de `tests/test-fluxo-painel.mjs`:

- `poSimBolha` com `tipo: 'buttons'` emite um `<button data-id="...">` por botão, e o rótulo escapado (`<`, `&`, `"` não escapam do atributo nem do texto).
- `poSimBolha` com `tipo: 'document'` mostra o nome do arquivo, não o `<iframe>` de um PDF.
- `poSimSelo` cobre os 5 estados do envelope e nenhum deles cai em rótulo vazio.
- Trava de fonte: `painel/simulador.js` **não** decide para qual card ir (nada de `proximo`, `fallback`, `opcoes[` fora de renderização), porque o roteamento é do servidor; e não contém `service_role`.
- Trava de fiação: `painel/index.html` carrega `simulador.js` com `?v=`, e a entrada existe em `painel/assets-lock.json` com o sha do arquivo normalizado.
- Ordem na cascata do CSS: o padrão de `.sim-*` vem **antes** de qualquer `@media` que o redefina (a mesma classe de defeito que deixou a barra de zoom invisível com dois testes verdes).

- [ ] **Step 2: Rodar e ver falhar**

Run: `node tests/test-simulador.mjs`
Expected: FAIL, `painel/simulador.js` não existe.

- [ ] **Step 3: Implementar `painel/simulador.js` e o CSS em `painel/fluxo.css`**

O estado da conversa (`fluxo_id`, `card_id`, `ctx`, e as bolhas já mostradas) vive numa variável do módulo e vai inteiro em cada POST. Erro de rede aparece como aviso na faixa e **não** apaga a conversa da tela.

- [ ] **Step 4: Fiar na mesma tarefa**

Botão "Simular" em `poFluxoBarra` e `<script src="simulador.js?v=1">` em `painel/index.html`. **Três arquivos de `painel/` mudam nesta tarefa, e os três precisam do `?v=` subido:** `simulador.js` (nasce em 1), `fluxo.css` (o CSS do simulador entra nele) e `fluxo.js` (ganhou o botão). Os três com sha novo em `painel/assets-lock.json`. Subir o `index.html` sem subir um desses números carimba o arquivo velho por um mês, que é o bug de 13/09/2026.

- [ ] **Step 5: Rodar e ver passar**

Run: `node tests/test-simulador.mjs` → PASS; `node tests/test-cache-buster.mjs` → PASS; `php tests/run.php` → 100% verde.

- [ ] **Step 6: Ver a tela com os olhos**

Servir uma página no scratchpad que carregue `fluxo.css` e `simulador.js` e desenhe uma conversa a partir de um envelope gravado do teste, com `python -m http.server` (o Playwright bloqueia `file:`). **Usar os arquivos originais, não cópias** — cópia no scratchpad já esconderam uma correção por uma rodada inteira. Conferir a 1440px e a 390px, e que o clique num botão da bolha dispara o passo.

- [ ] **Step 7: Commit**

```bash
git add painel/simulador.js painel/fluxo.css painel/fluxo.js painel/index.html painel/assets-lock.json tests/test-simulador.mjs
git commit -m "feat(painel): simulador do chat na aba Fluxo"
```

---

### Task 5: Suíte na árvore mesclada e o documento de pendências

**Files:**
- Create: `docs/superpowers/reviews/2026-11-03-ordem-e-simulador-pendencias.md`
- Modify: `CLAUDE.md` (o bloco do fluxo de conversa)

- [ ] **Step 1: Rodar a suíte inteira e contar os arquivos**

Run: `php tests/run.php`
Expected: 100% verde. Registrar o número de arquivos que o runner executa **e** o número que está no Git: hoje são 73 executados contra 59 versionados, porque ~14 testes existem só no disco. A diferença é dívida conhecida, não erro deste plano, e vai no documento de pendências.

- [ ] **Step 2: Escrever o documento de pendências**

O que ficou de fora e por quê: a ordem por data **não** foi aplicada ao site (home, `/roteiros`, `/link` seguem em `ordem.asc`, que é a ordenação manual do painel, e ninguém pediu para mudar); o item 2 da reunião (filtrar o que a Laís recebe no WhatsApp) é **impossível** por desenho da convivência da Meta, com a medição e as fontes; e as três pré-condições do Plano C que este plano **não** resolve (lembrete em card que não espera, falta de paginação no `ler`, apagar opção de card de botões).

- [ ] **Step 3: Atualizar o `CLAUDE.md`**

As regras permanentes que nascem aqui: a data do roteiro vem de `periodo` (e por que, com a medição de 03/11/2026); a ordenação entra num ponto só; o simulador lê o **rascunho** e é a única exceção à regra de o motor só ler o retrato; o simulador ignora `ativo` no salto e isso aparece na tela; e que ele usa as funções de envio reais com transporte falso, então os limites da Cloud API valem nele.

- [ ] **Step 4: Commit**

```bash
git add docs/superpowers/reviews/2026-11-03-ordem-e-simulador-pendencias.md CLAUDE.md
git commit -m "docs: pendencias e regras permanentes da ordem por data e do simulador"
```

---

## O que este plano NÃO faz

- **Não liga nada.** Webhook não inscrito, fluxos `ativo = false`, nenhum cron.
- **Não deploya.** O deploy por FTP é passo seu, depois da revisão, com a conferência por hash da lista completa de `lib/` e `painel/`.
- **Não mexe no site.** A ordem por data vale para a lista que o robô manda no chat.
- **Não resolve o item 2 da reunião**, porque ele não tem solução técnica do nosso lado: na convivência, mensagem enviada pela Cloud API aparece no WhatsApp Business dela automaticamente, e não há chave para filtrar por lead qualificado.
