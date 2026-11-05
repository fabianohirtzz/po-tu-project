# Parte A · O robô entende a frase — Plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** O robô passa a ler a frase do cliente: reconhece pedido de atendente, reconhece um roteiro à venda pelo nome do destino, e reconhece um destino da casa que não está cadastrado, respondendo "ainda não temos data para a Itália" e oferecendo a lista de espera.

**Architecture:** Cada card que espera resposta ganha em `config.atalhos` uma lista ordenada de três tipos (`palavras`, `roteiro`, `destino`). Eles são avaliados em **dois pontos**, porque a frase do cliente não chega num só: no card inicial de conversa nova (antes de executá-lo) e nos cards que esperam resposta (depois de as opções falharem, antes do `fallback`). O casamento de roteiro reaproveita `wa_match_roteiros`, que já existe, tem 20 testes e nenhum chamador em produção. A lista de destinos e os sinônimos de roteiro saem de código para o banco.

**Tech Stack:** PHP 8 no cPanel (sem Composer, sem build) · painel em HTML/CSS/JS puro · Supabase via PostgREST · testes com `php tests/run.php`.

**Spec:** `docs/superpowers/specs/2026-11-04-robo-entende-frase-e-roteiro-semanal-design.md` (Parte A, mais os achados 4, 5 e 6)

**Plano irmão:** a Parte C (`po_wa_espera` lida pelo painel e pela transmissão) consome o que este plano escreve. Este plano **cria a tabela e escreve nela**; a Parte C lê. Sem a Parte C, a Parte A captura dado que ninguém lê, que é anti-objetivo declarado da spec.

---

## Global Constraints

- **O repositório é PÚBLICO.** Nenhum dado real de cliente em fixture, comentário ou migration. Telefone de teste: `+5548900000001` em diante.
- **A migration vem ANTES do primeiro arquivo.** Lição medida na Parte B: arquivo novo que lê coluna inexistente derruba a tela inteira com erro cru do PostgREST.
- **A seta de dependência é `db/send/po-data <- fluxo <- sim/motor <- timeout`.** `lib/wa-fluxo.php` nunca requer `lib/wa-motor.php`. Arquivo novo de reconhecimento é **folha** ou depende só de `wa-roteiro.php`.
- **Escape de string não se escreve apertando Enter.** `tests/test-fonte-escapes.php` recusa string cujo conteúdo é só quebra de linha crua, nos 104 PHP.
- **Teste que lê fonte normaliza o fim de linha NA LEITURA** (`core.autocrlf=true` neste repo). Exceção: `test-fonte-escapes.php`, que mede o byte de propósito.
- **Trava tem que ser no PONTO DE CHAMADA, não só na função pura.** Três defeitos deste projeto foram a função travada e o chamador livre.
- **As nove leis do motor continuam no motor.** `wa-fluxo.php` só roteia.
- **O eco silencia o robô para sempre naquele contato.** Nada neste plano relaxa isso.
- **Estado só avança se o envio saiu** (`ok=true`, que garante `wamid`).
- **Rótulo de botão recusa acima de 20 caracteres, não trunca** (`mb_strlen`). Corpo interativo e legenda de documento param em 1024; texto em 4096. Acima disso a Meta recusa a mensagem **inteira**, em silêncio.
- **`WA_FLUXO_VARS` é lista fechada e o motor apaga chave desconhecida.** Variável nova custa 3 constantes em `lib/wa-fluxo.php` + o espelho `poFluxoVariaveis` em `painel/fluxo.js`, com teste dos dois lados.
- **`WA_FLUXO_CAMPOS_MARCAVEIS` é a trava estrutural do importador.** `status`, `venda`, `venda_at`, `notas` e `qualif_*` nunca entram em `preenche`.
- **Publicar e Ligar são atos separados.** `wa_fluxo_publica` nunca escreve `ativo` (há trava de fonte).
- **Nada liga o robô.** O webhook segue não inscrito, os dois fluxos seguem `ativo = false`, nenhum cron no cPanel. Este plano não muda isso.
- **TDD, revisão por tarefa e rodada de mutação**, como o resto do projeto. Nenhum mutante sobrevive.

### Decisões já tomadas pelo dono (05/11/2026)

- **A espera fictícia entra SEM TELEFONE.** Fichas `EXEMPLO` sem telefone, para a Parte C ter o que filtrar sem nenhuma chance de alcançar uma pessoa real. Desde 26/10 `WA_PHONE_ID` está preenchido, então celular fictício plausível é risco de verdade.
- **Os 11 textos reescritos no rascunho são PRESERVADOS, e publicados no fim.** A migration de v3 **nunca** escreve `texto` de card que já existe. Medido em 05/11: 11 dos 15 cards da triagem têm texto no rascunho diferente do publicado, reescritos por pessoa. Sobrescrevê-los é perda de trabalho real.

---

## Review Focus

As cinco classes de entrada que a spec implica e que nenhum teste de tarefa exercita por si. Cada uma tem seu teste pendurado na tarefa dona.

1. **Frase que casa DOIS destinos ou DOIS roteiros** ("quero Itália e Grécia"). A regra é responder só com exatamente um; duas devolve `null` e vai ao menu. Errar aqui manda o PDF errado, que é pior que perguntar. (Tarefa 2)
2. **Atalho cujo destino é um card de outro fluxo, ou um card que foi apagado.** Atalho é aresta do grafo: destino inexistente tem que **bloquear a publicação**, não estourar em tempo de conversa com o cliente esperando. (Tarefa 3)
3. **Texto digitado em card de botões que casa uma opção E um atalho** ("turquia" quando a lista tem "Turquia"). Opção e atalho nunca disputam a mesma string: opção primeiro, sempre. (Tarefa 4)
4. **Atalho que dispara no card inicial com `saudacao` vazia** e aponta para um card `pdf`. A pessoa receberia um PDF como primeira mensagem da vida. (Tarefa 5)
5. **`{destino}` num card alcançável sem ter passado por atalho `destino`.** A variável viraria string vazia no meio da frase ("ainda não temos data para a ."). Mesma regra que já vale para `{roteiro}`. (Tarefa 6)

---

## File Structure

| Arquivo | Responsabilidade |
|---|---|
| `supabase/migrations/2026-11-05-destinos-espera.sql` | **Novo.** `po_destinos` + seed de 29, `po_roteiros.palavras_chave`, `po_wa_espera`, e as 12 fichas `EXEMPLO` da espera. |
| `lib/wa-destinos.php` | **Novo.** Reconhecer um destino da casa pela frase. Depende só de `wa-roteiro.php` (reaproveita a normalização). |
| `lib/wa-espera.php` | **Novo.** Escrever e remover interesse em `po_wa_espera`. Folha, depende de `wa-db.php`. |
| `lib/wa-roteiro.php` | `palavras_chave` do banco no lugar de `WA_APELIDOS`; helper de "exatamente um roteiro". |
| `lib/wa-fluxo.php` | Forma e validação do atalho; avaliação no ponto 2; `{destino}`; `destino_nome` no contexto; `+destino` na marcação. |
| `lib/wa-motor.php` | Avaliação no ponto 1 (conversa nova) e a saudação do atalho. |
| `lib/wa-sim.php` | O simulador exercita atalhos, incluindo o ponto 1. |
| `painel/fluxo.js` | Editor dos atalhos na gaveta do card; `{destino}` na ajuda de variáveis. |
| `supabase/migrations/2026-11-05-fluxo-v3.sql` | **Novo.** Cards novos, religação dos dois botões, listas de atalhos. Preserva `texto` existente. |

---

## Task 1: Migration — destinos, sinônimos, espera e as fichas de exemplo

**Files:**
- Create: `supabase/migrations/2026-11-05-destinos-espera.sql`
- Create: `tests/test-destinos-schema.php`

**Interfaces:**
- Produces: `po_destinos(id, nome, palavras, ativo, ordem)` · `po_roteiros.palavras_chave text not null default ''` · `po_wa_espera(id, lead_id, destino, roteiro_slug, criado_at, removido_at)` com índice único parcial `(lead_id, destino) where removido_at is null`.
- Consumes: nada.

**Convenção das palavras:** `palavras` e `palavras_chave` são **`text` separado por vírgula**, não array nem jsonb. Motivo: no painel é um campo de texto só, e quem compara é o normalizador, que já quebra em palavras. Um parser compartilhado (`wa_palavras_lista`) é a única leitura.

- [ ] **Step 1: Escrever a migration**

Três blocos. `po_destinos` com RLS no porte de `po_roteiros` (leitura anônima só de `ativo`, escrita autenticada). Seed dos **29 destinos**, nesta forma (nome = o que o robô escreve de volta; palavras = o que o cliente digita):

Grécia (`grecia, atenas, santorini, mykonos`) · Peru (`peru, machu picchu, machupicchu, cusco, lima`) · Vietnã (`vietna, vietnam, hanoi, halong`) · Itália (`italia, roma, veneza, florenca, toscana`) · Turquia (`turquia, istambul, capadocia, antalia`) · Índia (`india, taj mahal, tajmahal, delhi`) · Chile (`chile, santiago, atacama`) · Japão (`japao, toquio, tokio, kyoto, quioto`) · Coreia do Sul (`coreia, seul`) · Dubai (`dubai, emirados`) · Doha (`doha, catar, qatar`) · China (`china, pequim, xangai, muralha`) · Egito (`egito, cairo, piramides, nilo`) · Marrocos (`marrocos, marraquexe, casablanca`) · África do Sul (`africa do sul, africa, cidade do cabo, joanesburgo`) · Austrália (`australia, sydney, melbourne`) · Malta (`malta, valletta`) · Lapônia (`laponia, aurora boreal, aurora, finlandia`) · Capitais Imperiais (`capitais imperiais, viena, praga, budapeste`) · Tailândia (`tailandia, bangkok, phuket`) · Escandinávia (`escandinavia, noruega, suecia, dinamarca, fiordes`) · Mercados de Natal (`mercados de natal, mercado de natal, natal na europa`) · Floração das Cerejeiras (`cerejeiras, floracao, sakura`) · Mediterrâneo (`mediterraneo, cruzeiro`) · Nepal (`nepal, katmandu`) · Paris (`paris, franca`) · Portugal (`portugal, lisboa, porto`) · Espanha (`espanha, madri, barcelona`) · Tesouros Asiáticos (`tesouros asiaticos, asia`)

As duas ambiguidades que o cliente resolveu em 04/11 estão aplicadas: `AFRICA 4K` virou **África do Sul**; `INDIROMA003` entra como **Índia** e como **Itália/Roma**, porque a pessoa pode escrever qualquer um dos dois.

`po_roteiros.palavras_chave` nasce `''` e recebe, no mesmo bloco, a única entrada que `WA_APELIDOS` tinha hoje, mais as que o catálogo exige: o roteiro de slug `escandinavia` recebe `noruega, suecia, dinamarca, fiordes`, e o `tesouros-asiaticos` recebe `coreia, japao, dubai, seul, toquio` — sem isso ninguém que escreva "coreia" alcança um roteiro que não nomeia país nenhum no título.

`po_wa_espera` com `lead_id` fk para `po_leads` e **soft delete** (`removido_at`), pelas três regras do dono: o registro com histórico nunca é sobrescrito, a fusão só soma, e nunca duplicar.

Por último, as **12 fichas `EXEMPLO` sem telefone** em `po_leads` (nome no padrão `EXEMPLO Ana E.`, `telefone` e `wa_id` nulos, `status` `semresposta`) e suas entradas em `po_wa_espera`, espalhadas em 5 destinos: Itália 4, Egito 3, Japão 2, Portugal 2, Lapônia 1. Sem telefone é decisão do dono: a Parte C ganha o que filtrar e nenhuma delas pode alcançar pessoa nenhuma.

- [ ] **Step 2: Escrever o teste de schema**

```php
// tests/test-destinos-schema.php — le o arquivo da migration, nao o banco
ok(preg_match('/create table if not exists po_destinos/i', $sql), 'cria po_destinos');
ok(substr_count($sql, 'enable row level security') === 2, 'RLS nas duas tabelas novas');
ok(preg_match('/unique index .*po_wa_espera.*lead_id.*destino.*removido_at is null/is', $sql), 'indice unico parcial da espera');
ok(!preg_match('/\bdrop\s+(table|column)\b/i', $sql), 'nenhum destrutivo: o portao do MCP recusa e quem roda e o dono');
ok(substr_count($sql, 'EXEMPLO') >= 12, 'as 12 fichas de exemplo');
ok(!preg_match('/EXEMPLO[^;]*telefone[^;]*\+55/i', $sql), 'nenhuma ficha EXEMPLO com telefone');
```

E o censo dos destinos, que é o que impede um seed mutilado passar:

```php
ok(count($destinos) === 29, 'os 29 destinos');
ok(in_array('África do Sul', $nomes, true), 'AFRICA 4K resolvido como Africa do Sul');
foreach ($destinos as $d) ok(wa_palavras_lista($d['palavras']) !== [], $d['nome'] . ' tem ao menos uma palavra');
```

- [ ] **Step 3: Rodar o teste e ver falhar**

Run: `php tests/test-destinos-schema.php`
Expected: FAIL, "cria po_destinos" (o arquivo ainda não existe ou está vazio)

- [ ] **Step 4: Rodar a migration no Supabase e conferir o pós-estado**

Pelo MCP, projeto `euzmbswywwhmicjlszqw`. Nenhum comando destrutivo, então o portão não recusa. Conferir depois, por consulta: `po_destinos` com 29 linhas ativas, `po_wa_espera` com 12 linhas e `removido_at` nulo, `po_roteiros.palavras_chave` existindo com os 2 roteiros semeados, e **zero** ficha `EXEMPLO` com telefone ou `wa_id`.

- [ ] **Step 5: Rodar a suíte inteira**

Run: `php tests/run.php`
Expected: todos passam (83 arquivos)

- [ ] **Step 6: Commit**

```bash
git add supabase/migrations/2026-11-05-destinos-espera.sql tests/test-destinos-schema.php
git commit -m "feat(destinos): tabela de destinos, sinonimos no banco e a tabela da espera"
```

---

## Task 2: Reconhecer destino e roteiro pela frase

**Files:**
- Create: `lib/wa-destinos.php`
- Create: `tests/test-wa-destinos.php`
- Modify: `lib/wa-roteiro.php`
- Modify: `tests/test-wa-roteiro.php`

**Interfaces:**
- Consumes: `po_destinos.palavras` e `po_roteiros.palavras_chave` (Tarefa 1).
- Produces:
  - `wa_palavras_lista(string $csv): array` — parser único das duas colunas. Normaliza, descarta vazias e com menos de 4 caracteres, deduplica.
  - `wa_match_destinos(string $texto, array $destinos): array` — os destinos cujas palavras aparecem na frase, como `['nome' => ..., 'palavras' => ...]`.
  - `wa_destino_unico(string $texto, array $destinos): ?string` — o **nome** quando exatamente um casa, `null` quando zero ou dois ou mais.
  - `wa_roteiro_unico(string $texto, array $roteiros): ?string` — o **slug** quando exatamente um casa, `null` caso contrário.

**Onde mora o quê:** `wa-destinos.php` requer `wa-roteiro.php` e reaproveita `wa_normaliza`, `wa_mesma_palavra` e `wa_eh_vazia`. Não duplica normalização: duas normalizações divergem e os dois testes ficam verdes.

**`WA_APELIDOS` sai do código.** Sua única entrada foi semeada na Tarefa 1. `wa_tokens_do_roteiro` passa a somar `palavras_chave`. `WA_VAZIAS` **fica** em código: é vocabulário genérico de viagem, não dado do negócio.

- [ ] **Step 1: Escrever os testes**

Casos venenosos que os 20 testes existentes já protegem e que não podem regredir: `asia` não casa dentro de `asiaticos`, `cama` não casa dentro de `atacama`, `caminho` não casa dentro de `caminhos`. Mais os novos:

```php
ok(wa_destino_unico('quero saber da italia', $D) === 'Itália', 'uma palavra casa o destino');
ok(wa_destino_unico('queria roma', $D) === 'Itália', 'sinonimo casa e devolve o NOME, nao a palavra');
ok(wa_destino_unico('quero italia e grecia', $D) === null, 'DOIS destinos devolve null: melhor perguntar que errar');
ok(wa_destino_unico('bom dia', $D) === null, 'frase sem destino');
ok(wa_destino_unico('quero uma viagem', $D) === null, 'palavra generica de viagem nao casa destino');
ok(wa_destino_unico('MACHU PICCHU!!', $D) === 'Peru', 'maiuscula, acento e pontuacao nao atrapalham');
ok(wa_destino_unico('fui pra italia ano passado', $D) === 'Itália', 'negacao e passado NAO sao entendidos: limitacao aceita e documentada');
ok(wa_palavras_lista('italia, roma , , ita') === ['italia','roma'], 'parser descarta vazio e palavra de menos de 4');
ok(wa_roteiro_unico('quero o chile', $R) === 'chile-e-deserto-do-atacama', 'roteiro por palavra do titulo');
ok(wa_roteiro_unico('quero coreia', $R) === 'tesouros-asiaticos', 'roteiro por palavras_chave do banco: o titulo nao nomeia pais');
ok(wa_roteiro_unico('chile e turquia', $R) === null, 'dois roteiros devolve null');
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php tests/test-wa-destinos.php`
Expected: FAIL, função `wa_palavras_lista` não definida

- [ ] **Step 3: Implementar**

`lib/wa-destinos.php` com as quatro funções acima. Em `lib/wa-roteiro.php`: remover `WA_APELIDOS`, somar `palavras_chave` em `wa_tokens_do_roteiro`, e acrescentar `wa_roteiro_unico`.

- [ ] **Step 4: Rodar os dois arquivos de teste**

Run: `php tests/test-wa-destinos.php && php tests/test-wa-roteiro.php`
Expected: PASS nos dois. Os 20 testes antigos continuam verdes com a fixture ganhando `palavras_chave`.

- [ ] **Step 5: Rodada de mutação**

No mínimo: tirar o descarte de palavra curta; tirar o `wa_eh_vazia`; trocar "exatamente um" por "o primeiro da lista"; fazer `wa_destino_unico` devolver a palavra em vez do nome. Nenhum sobrevive.

- [ ] **Step 6: Commit**

```bash
git add lib/wa-destinos.php lib/wa-roteiro.php tests/test-wa-destinos.php tests/test-wa-roteiro.php
git commit -m "feat(robo): reconhece destino e roteiro pela frase, com sinonimo no banco"
```

---

## Task 3: A forma do atalho e a validação

**Files:**
- Modify: `lib/wa-fluxo.php`
- Create: `tests/test-wa-atalho-valida.php`
- Modify: `tests/test-wa-fluxo-valida.php`

**Interfaces:**
- Produces:
  - `const WA_ATALHO_TIPOS = ['palavras','roteiro','destino'];`
  - `wa_atalhos_do_card(array $card): array` — a lista normalizada de `config.atalhos`, ou `[]`.
  - `wa_fluxo_valida` passa a reprovar as cinco formas erradas, e a **contar aresta de atalho na travessia**.

**Por que isto antes da avaliação:** é o que decide se a cliente consegue publicar. Sem aresta de atalho na travessia, o card "Sem data para este destino" é alcançável só por atalho, as regras de inalcançável e de beco sem saída o reprovam, e **o fluxo desta spec não publica**.

- [ ] **Step 1: Escrever os testes das cinco regras de forma**

Todas **bloqueio de publicação**:

```php
ok(temProblema($g, 'tipo de atalho'),        'tipo fora da lista fechada');
ok(temProblema($g, 'destino inexistente'),   'atalho apontando para card que nao existe');
ok(temProblema($g, 'sem palavra'),           'atalho palavras com lista vazia');
ok(temProblema($g, 'palavra curta'),         'palavra com menos de 4 caracteres normalizados');
ok(temProblema($g, 'mais de um atalho'),     'dois atalhos roteiro, ou dois destino, no mesmo card');
```

E as duas regras de travessia, que são o coração da tarefa:

```php
ok(!temProblema($g, 'inalcançável'), 'card alcancavel SO por atalho nao e inalcancavel');
ok(!temProblema($g, 'beco'),         'nem beco sem saida');
ok(!temProblema($g, 'roteiro escolhido'), 'atalho tipo roteiro conta como "escolheu um roteiro" antes do PDF');
ok(temProblema($g2, 'roteiro escolhido'), 'atalho tipo destino NAO conta: o PDF sairia sem ter o que mandar');
ok(temProblema($g3, 'roteiro escolhido'), 'nem o tipo palavras conta');
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php tests/test-wa-atalho-valida.php`
Expected: FAIL na primeira regra de forma

- [ ] **Step 3: Implementar**

As cinco regras de forma e as duas de travessia em `wa_fluxo_valida`. O CLAUDE.md diz que não se acrescenta regra ao validador sem decisão: estas sete estão decididas na spec A.1 e ficam registradas no comentário da função.

- [ ] **Step 4: Rodar os testes do validador**

Run: `php tests/test-wa-atalho-valida.php && php tests/test-wa-fluxo-valida.php && php tests/test-wa-fluxo-leis.php`
Expected: PASS nos três

- [ ] **Step 5: Rodada de mutação**

No mínimo: tirar cada uma das cinco regras de forma; fazer o tipo `destino` contar como roteiro escolhido; tirar a aresta de atalho da travessia (tem que ficar vermelho pela regra de inalcançável).

- [ ] **Step 6: Commit**

```bash
git commit -am "feat(fluxo): a forma do atalho e as duas regras de travessia no validador"
```

---

## Task 4: Atalho no ponto 2 — card que espera resposta

**Files:**
- Modify: `lib/wa-fluxo.php`
- Create: `tests/test-wa-atalho-rota.php`
- Modify: `tests/test-wa-fluxo-rota.php`

**Interfaces:**
- Consumes: `wa_atalhos_do_card` (Tarefa 3), `wa_destino_unico`/`wa_roteiro_unico` (Tarefa 2).
- Produces: `wa_fluxo_proximo($grafo, $card_id, $resposta, $ctx, $texto_cru = '')` — **quinto parâmetro novo**. E `wa_fluxo_atalho($card, $texto_cru, $ctx): ?array`, devolvendo `['card' => ..., 'tipo' => ..., 'destino_nome' => ?string, 'roteiro_slug' => ?string]`.

**A regra que não se relaxa:** a supressão de texto digitado em card de botões (`$resposta = ($clique || $digita) ? $texto : ''`) **continua exatamente como é**. O texto cru viaja no parâmetro novo. Opção e atalho nunca disputam a mesma string, que é o que impede "turquia" de virar escolha de lista por acidente.

**A ordem é contrato:** opções primeiro, atalhos depois, `fallback` por último.

- [ ] **Step 1: Escrever os testes**

```php
// Review Focus 3: a disputa que nao pode existir
ok(rota($gBotoes, 'turquia')['motivo'] === 'opcao', 'texto que casa opcao vai pela OPCAO, nunca pelo atalho');
ok(rota($gBotoes, 'quero falar com atendente')['card'] === 'humano_id', 'atalho palavras dispara quando a opcao falha');
ok(rota($gBotoes, 'blablabla')['motivo'] === 'fallback', 'atalho nao rouba o fallback de quem nao casa nada');
ok(rota($gBotoes, 'quero a italia')['card'] === 'sem_data_id', 'atalho destino');
ok(rota($gBotoes, 'quero a italia')['ctx']['destino_nome'] === 'Itália', 'o nome do destino entra no contexto');
ok(rota($gBotoes, 'quero o chile')['ctx']['roteiro_slug'] === 'chile-e-deserto-do-atacama', 'atalho roteiro grava o slug');
ok(rota($gBotoes, 'italia e grecia')['motivo'] === 'fallback', 'frase ambigua nao dispara atalho (Review Focus 1)');
ok(rota($gSemAtalho, 'quero a italia')['motivo'] === 'fallback', 'card sem atalho se comporta exatamente como hoje');
ok(ordemTentada($gTodos) === ['opcao','atalho','fallback'], 'a ordem e contrato');
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php tests/test-wa-atalho-rota.php`
Expected: FAIL, o atalho não é tentado

- [ ] **Step 3: Implementar**

`wa_fluxo_atalho` e a fiação dentro de `wa_fluxo_proximo`, entre as opções e o `fallback`.

- [ ] **Step 4: Travar o PONTO DE CHAMADA**

Não basta a função pura: `wa_fluxo_percorre` e `wa_fluxo_executa` têm que passar o texto cru. Teste de fonte, lendo o arquivo **normalizado**, exigindo que nenhuma chamada a `wa_fluxo_proximo` fique com quatro argumentos.

Run: `php tests/test-wa-atalho-rota.php && php tests/test-wa-motor.php`
Expected: PASS

- [ ] **Step 5: Rodada de mutação**

No mínimo: inverter a ordem (atalho antes da opção) — tem que ficar vermelho pelo teste da disputa; relaxar a supressão de texto digitado; deixar o chamador com quatro argumentos.

- [ ] **Step 6: Commit**

```bash
git commit -am "feat(fluxo): atalhos no card que espera resposta, depois das opcoes"
```

---

## Task 5: Atalho no ponto 1 — conversa nova, e a saudação

**Files:**
- Modify: `lib/wa-motor.php`
- Modify: `lib/wa-sim.php`
- Create: `tests/test-wa-atalho-inicial.php`

**Interfaces:**
- Consumes: `wa_fluxo_atalho` (Tarefa 4).
- Produces: a conversa nova pode **começar no destino do atalho** em vez do card inicial.

**Onde:** `wa_processar`, entre resolver a porta (linha ~585) e chamar `wa_fluxo_percorre` no inicial (linha ~618). Avalia os atalhos **do card inicial** contra o texto da primeira mensagem.

**A saudação:** quando um atalho dispara no card inicial, a pessoa pularia a saudação e receberia um PDF como primeira mensagem da vida. O atalho carrega `saudacao`, escrita pela cliente, enviada **antes** do salto. Vazia = salta calado. No ponto 2 ela é **ignorada**, porque já houve saudação.

- [ ] **Step 1: Escrever os testes**

```php
ok(primeira('quero falar com uma pessoa')['card'] === 'humano_id', 'a primeira frase da vida dispara o atalho');
ok(primeira('bom dia')['card'] === 'inicial_id', 'sem casar, comeca no inicial como hoje');
ok(enviadas('quero o chile')[0] === 'Oi! Já te mando o roteiro.', 'a saudacao do atalho sai ANTES do salto');
ok(count(enviadas('quero o chile')) === 2, 'saudacao + o card de destino, nessa ordem');
// Review Focus 4
ok(enviadas('quero o chile', $semSaudacao)[0] !== '', 'atalho com saudacao vazia salta calado, e o teste prova que o PDF nao e a primeira mensagem quando ela existe');
ok(primeiraNoPonto2('quero o chile')['saudacao_enviada'] === false, 'no ponto 2 a saudacao e ignorada: ja houve saudacao');
ok(primeira('quero o chile')['estado_avancou'] === true, 'estado so avanca se o envio saiu');
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php tests/test-wa-atalho-inicial.php`
Expected: FAIL, a conversa começa sempre no inicial

- [ ] **Step 3: Implementar no motor, e no simulador**

O simulador roda o motor de verdade atrás de um transporte falso, então o ponto 1 tem que existir nele também, senão a tela aprova rascunho que a Meta recusa.

- [ ] **Step 4: Rodar**

Run: `php tests/test-wa-atalho-inicial.php && php tests/test-wa-sim.php && php tests/run.php`
Expected: PASS

- [ ] **Step 5: Rodada de mutação**

No mínimo: mandar a saudação depois do salto; mandar a saudação também no ponto 2; avançar o estado sem conferir `ok=true`.

- [ ] **Step 6: Commit**

```bash
git commit -am "feat(motor): a primeira frase da conversa passa pelos atalhos do card inicial"
```

---

## Task 6: A variável `{destino}` e a marcação do interesse

**Files:**
- Modify: `lib/wa-fluxo.php`
- Create: `lib/wa-espera.php`
- Create: `tests/test-wa-espera.php`
- Modify: `tests/test-wa-fluxo-texto.php`, `tests/test-wa-fluxo-valida.php`, `painel/fluxo.js`, `tests/test-fluxo-painel.mjs`

**Interfaces:**
- Produces:
  - `WA_FLUXO_VARS = ['roteiro','data','datas','destino']`, com `WA_FLUXO_VAR_MAX['destino'] = 40`.
  - `WA_FLUXO_CAMPOS_MARCAVEIS` ganha `espera_destino`.
  - `wa_espera_anota(string $lead_id, string $destino, ?string $roteiro_slug): bool` e `wa_espera_remove(string $lead_id, string $destino): bool` (soft delete). **`po_leads.id` é `uuid`**, conferido em 05/11, então `lead_id` é string em todo o caminho e `po_wa_espera.lead_id` é `uuid references po_leads(id)`.
- Consumes: `destino_nome` do contexto (Tarefa 4), `po_wa_espera` (Tarefa 1).

**O achado 6, corrigido:** hoje `wa_fluxo_campos_marca` trata `+destino` e `+roteiro` pelo mesmo caminho e exige `$slug !== ''`. Um atalho por destino não tem slug, então grava **nada, em silêncio** — justamente o dado que justifica o pedido. Agora `+destino` lê `destino_nome` e a escrita vai para `po_wa_espera`, não para `po_leads.espera`, que **para de ser escrita**.

**`po_leads.espera` não é derrubada aqui.** `drop column` é destrutivo, o portão do MCP recusa, e quem roda destrutivo é o dono no SQL Editor. Fica como dívida de uma linha no CLAUDE.md.

- [ ] **Step 1: Escrever os testes**

```php
ok(texto('data para {destino}', ['destino' => 'Itália']) === 'data para Itália', 'a variavel imprime');
ok(texto('data para {destino}', []) === 'data para ', 'sem destino ela some, como as outras');
// Review Focus 5
ok(temProblema($g, '{destino}'), '{destino} em card alcancavel sem atalho destino BLOQUEIA a publicacao');
ok(wa_fluxo_corta(str_repeat('a', 60), WA_FLUXO_VAR_MAX['destino']) === str_repeat('a', 40), 'teto de 40');
ok(piorCaso('{destino}') === 40, 'entra na analise de pior caso');
ok(wa_fluxo_campos_marca(['espera' => '+destino'], ['destino_nome' => 'Itália'])['espera_destino'] === 'Itália', '+destino grava o NOME');
ok(!isset(wa_fluxo_campos_marca(['espera' => '+destino'], [])['espera_destino']), 'sem destino no contexto nao grava nada');
// A invariante de verdade: `status` e `qualif_*` ESTAO na whitelist hoje e
// devem continuar. O que nunca pode entrar e o que uma importacao de agenda
// apagaria: faturamento e anotacao.
foreach (['venda','venda_at','notas'] as $c)
    ok(!in_array($c, WA_FLUXO_CAMPOS_MARCAVEIS, true), $c . ' nunca entra na whitelist');
ok(count(WA_FLUXO_CAMPOS_MARCAVEIS) === 7, 'a whitelist cresceu de 6 para 7, e so por espera_destino');
ok(wa_espera_anota($uid, 'Itália', null) && !wa_espera_anota($uid, 'Itália', null), 'pedir duas vezes nao cria duas linhas');
ok(wa_espera_remove($uid, 'Itália') && consultaRemovidoAt($uid, 'Itália') !== null, 'remocao e SOFT: nunca apaga que a pessoa pediu');
ok(wa_espera_anota('nao-e-uuid', 'Itália', null) === false, 'lead_id que nao e uuid nao vira linha orfa');
```

E o espelho do painel, dos dois lados:

```js
// tests/test-fluxo-painel.mjs
assert.ok(poFluxoVariaveis().some(v => v.nome === 'destino' && v.max === 40), '{destino} no espelho do painel');
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php tests/test-wa-espera.php`
Expected: FAIL, `wa_espera_anota` não definida

- [ ] **Step 3: Implementar**

As três constantes, o espelho do painel, `lib/wa-espera.php` e o ramo `+destino` da marcação.

- [ ] **Step 4: Rodar PHP e JS**

Run: `php tests/run.php`
Expected: todos passam

- [ ] **Step 5: Rodada de mutação**

No mínimo: tirar `destino` de cada uma das 3 constantes; tirar do espelho do painel; trocar o soft delete por delete de verdade; tirar o índice único parcial do caminho de escrita.

- [ ] **Step 6: Commit**

```bash
git commit -am "feat(robo): a variavel {destino} e o interesse gravado na tabela da espera"
```

---

## Task 7: O editor de atalhos na gaveta do painel

**Files:**
- Modify: `painel/fluxo.js`, `painel/fluxo.css`, `painel/index.html`, `painel/assets-lock.json`
- Modify: `tests/test-fluxo-painel.mjs`

**Interfaces:**
- Consumes: `WA_ATALHO_TIPOS` e a forma do atalho (Tarefa 3).
- Produces: a cliente monta atalhos sem SQL.

**As regras de desenho do subsistema continuam:** o `fluxo.js` **não lê largura de tela** (quem escolhe lista x canvas é o CSS), exceto dentro de `poFluxoZoomAplica`; o padrão do CSS vem **antes** do `@media` (mesma especificidade, vence a última declarada — foi o que deixou a barra de zoom invisível com dois testes verdes); e o limite de caracteres não bloqueia o Salvar, só o Publicar.

- [ ] **Step 1: Escrever os testes**

```js
assert.ok(/atalhos/.test(le('painel/fluxo.js')), 'a gaveta tem a seção de atalhos');
assert.equal(poAtalhoTipos().length, 3, 'os três tipos, e só eles');
assert.deepEqual(poAtalhoTipos(), ['palavras','roteiro','destino'], 'na mesma ordem do PHP');
assert.ok(ordemNoCss('.fl-atalho') === 'padrao-antes-do-media', 'o padrão vem antes do @media');
assert.ok(!/innerWidth|clientWidth/.test(foraDoZoom(le('painel/fluxo.js'))), 'o fluxo.js não lê largura de tela fora do zoom');
assert.ok(lock['fluxo.js'].v > vAnterior, 'cache-buster subiu');
```

- [ ] **Step 2: Rodar e ver falhar** · Run: `node tests/test-fluxo-painel.mjs` · Expected: FAIL
- [ ] **Step 3: Implementar** a seção de atalhos, o `?v=` e a entrada no lock
- [ ] **Step 4: Rodar** · Run: `php tests/run.php` · Expected: todos passam
- [ ] **Step 5: Ver a tela**, com o grafo real, a 1440 e a 390. Arquivo novo em `painel/` que ninguém carrega deixa a suíte vermelha na hora: criar e fiar são a **mesma** tarefa.
- [ ] **Step 6: Commit**

---

## Task 8: A migration de versão 3 do grafo

**Files:**
- Create: `supabase/migrations/2026-11-05-fluxo-v3.sql`
- Create: `tests/test-fluxo-v3.php`

**Interfaces:**
- Consumes: tudo acima.
- Produces: os cards novos, as duas religações e as listas de atalhos, no rascunho e num retrato de versão 3.

**É a tarefa grande e de risco deste plano**, e o precedente é a v2: retrato jsonb imutável, derivação dos cards do rascunho, e **conferência por hash canônico** entre o arquivo e o que foi gravado, com campos escalares só (`->>`, `jsonb_each_text`, booleano como `true`/`false`, ausente como `''`) e **calibrada na versão que já existe nos dois lados** antes de confiar no resultado.

**A regra que esta tarefa não pode violar:** a migration **nunca escreve `texto` de card que já existe**. Medido em 05/11: 11 dos 15 cards da triagem foram reescritos no rascunho pela cliente e não publicados. Sobrescrevê-los é perda de trabalho real, e a spec avisa disso.

| Card | Tipo | O que muda |
|---|---|---|
| Despedida de quem recusou a lista | `fim` | **Novo.** O "Não, obrigado" dos **dois** cards de lista de espera passa a apontar para cá. Leva link de `/roteiros` (link clicável já funciona: `wa_send_text` manda `preview_url => true`). |
| Sem data para este destino | `botoes` | **Novo.** Destino do atalho `destino`. Usa `{destino}`. O "sim" vai para o fluxo de espera que já existe. |
| Atendimento humano | `humano` | Já existe. Ganha o atalho `palavras` apontando para ele. |
| Manda o PDF | `pdf` | Já existe. Ganha o atalho `roteiro` apontando para ele. |

Ordem dos atalhos no card de boas-vindas: `palavras`, depois `roteiro`, depois `destino`. Pedido explícito por uma pessoa ganha de palpite; roteiro à venda é resposta melhor que "não temos data". A ordem é dado, então a cliente muda.

- [ ] **Step 1: Escrever o teste** — a lista de cards, as duas arestas religadas, as listas de atalhos, e a trava de que nenhum `update` toca `texto` de card existente
- [ ] **Step 2: Rodar e ver falhar**
- [ ] **Step 3: Escrever a migration** com os textos **fictícios**, como o cliente pediu em 04/11: nenhum texto deste plano é definitivo, a Laís reescreve na aba Fluxo
- [ ] **Step 4: Rodar no Supabase, e conferir por hash canônico** — calibrar na v2 primeiro, que existe nos dois lados; hash nunca conferido contra dado conhecido não prova nada
- [ ] **Step 5: Validar o fluxo pelo endpoint `conferir`** — ele tem que publicar, que é o que a Tarefa 3 existe para garantir
- [ ] **Step 6: Publicar a v3** com as reescritas da cliente dentro (decisão do dono em 05/11). **Publicar não liga:** os dois fluxos seguem `ativo = false`
- [ ] **Step 7: Commit**

---

## Task 9: O simulador exercita os atalhos

**Files:**
- Modify: `painel/simulador.js`, `lib/wa-sim.php`
- Modify: `tests/test-simulador.mjs`, `tests/test-wa-sim.php`

**Interfaces:**
- Consumes: Tarefas 4, 5 e 8.
- Produces: a cliente testa "quero saber do Chile" e vê o PDF sair, sem mandar nada para ninguém.

**O simulador lê o RASCUNHO**, que é a única exceção à regra de o motor só ler `po_wa_fluxo_versoes.grafo`, e existe porque o pedido é testar **antes** de publicar. A montagem é a mesma função do Publicar (`wa_fluxo_grafo_de`): não existe segunda montagem.

- [ ] **Step 1: Escrever os testes** — frase que dispara cada um dos três tipos, a saudação aparecendo como bolha, e o aviso de que o simulador ignora `ativo` no salto
- [ ] **Step 2: Rodar e ver falhar**
- [ ] **Step 3: Implementar**
- [ ] **Step 4: Rodar a suíte inteira**
- [ ] **Step 5: Ver a tela** com a sessão real, que é a pendência que a Parte B deixou aberta
- [ ] **Step 6: Commit**

---

## Task 10: O template de duas variáveis, submetido cedo

**Files:** nenhum do repo. Esta tarefa usa a **aba Modelos**, que já existe e já submete direto para a Meta.

**Por que ela está na Parte A e não na Parte C:** é a única dependência deste trabalho que **não depende de nós**. A aprovação da Meta leva dias, modelo não se transfere entre contas, e modelo criado não se renomeia nem se apaga de imediato. Submeter no fim da Parte C significaria esperar a Meta depois de tudo pronto. A mudança de código que o acompanha (`campanha.php` passar de "exatamente uma variável" para "exatamente tantas quantas a campanha fornece") é da Parte C, C.4.

**Texto proposto:** `Oi {{1}}, abriu data para a {{2}}! Quer que eu te mande o roteiro completo?` com exemplos `Ana` e `Itália`.

**O que o `wa_tpl_problemas` já confere, e que vale aqui:** não começar nem terminar com variável, numeração em sequência a partir de `{{1}}`, chaves sem espaço, duas variáveis nunca coladas, exemplo obrigatório, e o limite de 1024 em **caracteres**. O modo `conferir` julga pela **mesma** função do `criar`, sem gastar chamada.

- [ ] **Step 1: Conferir o texto pelo modo `conferir`** da aba Modelos, que não gasta chamada à Meta
- [ ] **Step 2: PARAR e confirmar com o dono antes de submeter.** Submeter cria um modelo na conta real que não se apaga nem se renomeia de imediato. É ação externa e irreversível: não acontece sem o "pode" dele
- [ ] **Step 3: Submeter pela aba Modelos**, e registrar o nome e o idioma. **Modelo é identificado por nome + idioma**, não só por nome: nome certo com idioma errado devolve `#132001` e **não entrega**
- [ ] **Step 4: Registrar no CLAUDE.md** que a aprovação está pendente na Meta, com a data da submissão

**Lembrete que vale para quando a Parte C ligar isto:** `health_status.can_send_message` estava **`BLOCKED`** em 26/10 por erro de forma de pagamento (`141006`). Sem forma de pagamento na conta, nenhuma conversa iniciada pela empresa sai, template aprovado ou não.

---

## Deploy (fora das tarefas, depois da revisão de branch)

1. **As duas migrations primeiro**, nesta ordem: `2026-11-05-destinos-espera.sql`, depois `2026-11-05-fluxo-v3.sql`.
2. **FTP nesta ordem:** `lib/wa-destinos.php` e `lib/wa-espera.php` (folhas) → `lib/wa-roteiro.php` → `lib/wa-fluxo.php` → `lib/wa-sim.php` e `lib/wa-motor.php` → `painel/simulador.js` e `painel/fluxo.js` → `painel/fluxo.css` → `painel/index.html` **por último**.
3. **Conferir por HASH, nunca pelo código de saída do `curl`**, e a **lista completa**: `bash scripts/confere-deploy.sh` mais o comparador dos PHP de `lib/`.
4. `painel/assets-lock.json` **não vai** para o servidor.
5. **Nada liga o robô.** Conferir no ar: webhook 403, cron 403, campanha 401, `fluxo.php` 405 no GET e 401 em todo POST sem login.
