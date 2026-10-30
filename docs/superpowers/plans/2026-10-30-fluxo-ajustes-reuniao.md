# Ajustes do fluxo de conversa (Plano C1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Aplicar no fluxo de conversa e no editor do painel os 11 ajustes decididos na reunião de 29/10/2026 com a cliente, sem ligar nada.

**Architecture:** A conversa encurta (um menu de duas portas, escolha do roteiro por texto numerado, uma única pergunta de qualificação) e passa a aceitar resposta digitada no card novo `roteiros`, que é o único em que digitar vale como escolha. O grafo semeado não é editado: entra uma **migration nova** que publica a **versão 2** de "Boas-vindas e triagem" e redesenha os cards do rascunho. No painel, a gaveta ganha exclusão de opção com desfazer, o campo de texto deixa de nascer pequeno e o canvas passa a distinguir avanço de retorno.

**Tech Stack:** PHP 8 em cPanel (sem Composer, sem build), HTML/CSS/JS sem framework, Supabase (PostgREST + Auth), testes com `php tests/run.php` (`ok($cond,$msg)` + `exit(1)` nos `.php`, `node:assert/strict` nos `.mjs`).

**Spec:** a ata `Reunião 29/10/2026` (anotações do Gemini) mais as decisões do Fabiano de 30/10, transcritas item a item na seção Escopo abaixo. Não há documento de design: este plano é a fonte.

## Global Constraints

- **Nada é ligado.** Webhook não inscrito, `ativo = false` nos dois fluxos, nenhum cron. Nenhuma tarefa muda isso.
- **O motor só lê `po_wa_fluxo_versoes.grafo`** (retrato imutável). `po_wa_conversas.fluxo_versao_id` prende a conversa à versão em que entrou.
- **Publicar e Ligar são atos separados.** `wa_fluxo_publica` nunca escreve `ativo` (há trava de fonte).
- **`lib/wa-fluxo.php` nunca requer `lib/wa-motor.php`.** A seta é uma só: db/send/po-data ← fluxo ← motor ← timeout.
- **Limites da Cloud API:** 4096 caracteres para texto, 1024 para corpo interativo e legenda de documento. Rótulo de botão recusa acima de 20 caracteres (`mb_strlen`), **não trunca**. Botões: máximo 3. Lista: máximo 10 itens.
- **Trava no PONTO DE CHAMADA**, não só na função pura.
- **Teste que lê fonte normaliza o fim de linha na leitura** (`core.autocrlf=true` neste repo): `le(rel)` nos `.mjs`, `str_replace("\r\n", "\n", ...)` nos `.php`.
- **Arquivo alterado em `painel/`** exige subir o `?v=` em `painel/index.html` **e** o sha em `painel/assets-lock.json`. Criar e fiar são a mesma tarefa.
- **Nenhum teste toca a rede:** `wa_motor_set_deps`, `wa_db_set_transport`, `wa_set_transport`, `wa_timeout_set_deps`, `po_auth_set_verificador`.
- **Repositório PÚBLICO:** nenhum dado real de cliente em fixture. Telefones de teste a partir de `+5548900000001`.
- **A migration do fluxo semeado JÁ RODOU.** Mudança de estrutura é migration nova e idempotente, que não pode apagar edição feita pela cliente.

## Escopo (o que a reunião decidiu)

1. Boas-vindas com duas opções: "Roteiros" e "Falar com a Laís". Sai o card "A programação".
2. Escolha do roteiro vira **texto numerado** montado do banco (número + nome + período quando houver). Filtro: `ativo = true` e `status_viagem` fora de `esgotado` e `em_viagem`. `em_breve` entra mesmo sem data.
3. O motor casa a resposta por número, por nome completo ou por **parte** do nome.
4. Depois do PDF fica só "a data serve?", com Sim e Não.
5. Sim leva ao card humano. Não pergunta "quer entrar na lista de espera?" (Sim e Não); Sim vai para o fluxo Lista de espera, Não para a despedida.
6. Saem os cards de "já viajou em grupo?" (`…009`, `…010`, `…011`, `…019`).
7. ~~`{data}` ler `periodo`~~ **JÁ FEITO** em `wa-fluxo.php:88`. Fora do escopo.
8. `{nome}` é removida dos textos, de `WA_FLUXO_VARS` e de `WA_FLUXO_VAR_MAX`. `{roteiro}` e `{data}` ficam.
9. Despedidas reescritas, genéricas e profissionais.
11. Excluir opção na gaveta, com desfazer; aviso quando o destino ficar órfão.
12. Campo de mensagem que nasce pequeno e não redimensiona.
13. Avanço e retorno distintos no canvas.

(Item 10, lembretes configuráveis, é outro plano: mexe em schema e no `wa-timeout.php` e não aparece em tela com o robô desligado.)

## Review Focus

- **Catálogo que muda entre a pergunta e a resposta.** O cliente lê "2 Chile", um roteiro esgota, ele responde "2" e o número 2 virou outro roteiro. Esperado: o número vale pela **posição na lista que foi enviada**, não pela lista de agora. A Task 3 guarda os slugs enviados no contexto e casa contra eles.
- **Resposta ambígua.** "tesouros" casando com dois roteiros não pode escolher o primeiro em silêncio. Esperado: nenhuma escolha, e o card cai no fallback (card 016, o menu de dois botoes, que devolve a lista em um toque). O fallback nao aponta para o proprio card de proposito: isso arriscaria as regras do motor. Task 3.
- **Catálogo vazio no card `roteiros`.** O Supabase fora do ar não pode virar "não temos viagens". Esperado: a mesma distinção que o `pdf` já faz (erro transitório, a próxima mensagem tenta de novo), nunca uma lista vazia afirmando que não há roteiro. Task 3.
- **Texto digitado em card de botões continua não sendo escolha.** Afrouxar a regra para o card `roteiros` não pode afrouxá-la para `botoes` e `lista`: lá, digitar "sim" não pode valer como o botão Sim. Task 4.
- **Desfazer depois de salvar.** A pessoa exclui a opção, salva, e só então clica em desfazer. Esperado: o desfazer some assim que a gaveta fecha ou salva, para não prometer o que não cumpre. Task 7.

---

## File Structure

| Arquivo | Responsabilidade | Tarefa |
|---|---|---|
| `lib/wa-fluxo.php` | tipo `roteiros`, `wa_fluxo_lista_roteiros()`, `wa_fluxo_casa_roteiro()`, fim do `{nome}` | 1, 2, 3 |
| `lib/wa-motor.php` | digitar vale como escolha **só** no card `roteiros` | 4 |
| `supabase/migrations/2026-10-30-fluxo-triagem-v2.sql` | versão 2 do grafo e redesenho do rascunho | 5 |
| `painel/fluxo.js` | tipo novo na lista, excluir opção, desfazer, aviso de órfão | 2, 7 |
| `painel/fluxo.css` | campo de texto, setas de retorno | 6, 8 |
| `tests/test-wa-fluxo-roteiros.php` | lista, casamento, catálogo vazio | 3 |
| `tests/test-wa-fluxo-*.php`, `tests/test-fluxo-painel.mjs` | travas existentes a estender | todas |

---

## Task 1: `{nome}` sai do sistema

**Files:**
- Modify: `lib/wa-fluxo.php` (`WA_FLUXO_VARS`, `WA_FLUXO_VARS_ROTEIRO`, `WA_FLUXO_VAR_MAX`, `wa_fluxo_vars_do_roteiro`)
- Test: `tests/test-wa-fluxo-texto.php`

**Interfaces:**
- Produces: `WA_FLUXO_VARS = ['roteiro','data']`. `wa_fluxo_vars_do_roteiro($r, $nome)` **mantém a assinatura** (o `$nome` segue chegando dos chamadores) e passa a ignorar o segundo parâmetro.

- [ ] **Step 1: Escrever o teste que falha** em `tests/test-wa-fluxo-texto.php`

```php
ok(!in_array('nome', WA_FLUXO_VARS, true), 'nome saiu de WA_FLUXO_VARS');
ok(!array_key_exists('nome', WA_FLUXO_VAR_MAX), 'nome saiu de WA_FLUXO_VAR_MAX');
$v = wa_fluxo_vars_do_roteiro(['titulo' => 'Chile', 'periodo' => '25/11/26 a 02/12/26'], 'Fulano');
ok(!array_key_exists('nome', $v), 'wa_fluxo_vars_do_roteiro nao devolve nome');
ok($v['roteiro'] === 'Chile' && $v['data'] === '25/11/26 a 02/12/26', 'roteiro e data continuam');
// o texto que AINDA tiver {nome} sai com a chave literal, nunca com o nome
ok(wa_fluxo_texto('Oi {nome}, segue o {roteiro}', $v) === 'Oi {nome}, segue o Chile',
   '{nome} deixa de ser substituida');
```

- [ ] **Step 2: Rodar e ver falhar.** `php tests/test-wa-fluxo-texto.php` — esperado: FAIL em "nome saiu de WA_FLUXO_VARS".

- [ ] **Step 3: Remover `'nome'`** das três constantes e a chave `'nome'` do array que `wa_fluxo_vars_do_roteiro` devolve. Manter o parâmetro `$nome` na assinatura para não mexer nos chamadores.

- [ ] **Step 4: Rodar a suíte inteira.** `php tests/run.php` — esperado: verde. Qualquer teste que afirmasse a substituição de `{nome}` é atualizado aqui, não contornado.

- [ ] **Step 5: Commit.** `git add lib/wa-fluxo.php tests/test-wa-fluxo-texto.php && git commit -m "feat(fluxo): a variavel {nome} sai do sistema"`

---

## Task 2: o tipo de card `roteiros`

**Files:**
- Modify: `lib/wa-fluxo.php` (`WA_CARD_TIPOS`, `WA_CARD_ESPERA`, `WA_FLUXO_LIMITE`, validador)
- Modify: `painel/fluxo.js` (`poFluxoTipos`), `painel/index.html`, `painel/assets-lock.json`
- Test: `tests/test-wa-fluxo-valida.php`, `tests/test-wa-fluxo-tipos.mjs`

**Interfaces:**
- Produces: tipo `'roteiros'` em `WA_CARD_TIPOS` e em `WA_CARD_ESPERA`; limite de texto 4096 (é mensagem de texto); no painel, `{tipo:'roteiros', rotulo:'Lista de roteiros', manda:'Lista as viagens à venda numeradas e espera a pessoa responder o número ou o nome.', espera:true}`.

- [ ] **Step 1: Escrever o teste que falha** em `tests/test-wa-fluxo-valida.php`

```php
ok(in_array('roteiros', WA_CARD_TIPOS, true), 'o tipo roteiros existe');
ok(in_array('roteiros', WA_CARD_ESPERA, true), 'e espera resposta');
ok(WA_FLUXO_LIMITE['roteiros'] === 4096, 'limite de texto simples, nao de corpo interativo');
// card roteiros nao usa opcoes: quem as escreve esta enganado
$g = ['id'=>'f','nome'=>'F','inicial'=>'a','porta'=>'mensagem_nova','cards'=>[
  'a'=>['id'=>'a','tipo'=>'roteiros','titulo'=>'Qual viagem?','texto'=>'Escolha:','proximo'=>'b',
        'opcoes'=>[['id'=>'x','rotulo'=>'X','destino'=>'b']]],
  'b'=>['id'=>'b','tipo'=>'fim','titulo'=>'Fim','texto'=>'Tchau'],
]];
$p = wa_fluxo_valida($g);
ok(count(array_filter($p, fn($x) => $x['card'] === 'a')) > 0, 'card roteiros com opcoes vira problema');
// e precisa de proximo, senao a escolha nao leva a lugar nenhum
$g2 = $g; unset($g2['cards']['a']['opcoes'], $g2['cards']['a']['proximo']);
ok(count(array_filter(wa_fluxo_valida($g2), fn($x) => $x['bloqueia'])) > 0, 'roteiros sem proximo bloqueia');
```

- [ ] **Step 2: Rodar e ver falhar.** `php tests/test-wa-fluxo-valida.php`

- [ ] **Step 3: Acrescentar o tipo** nas constantes e no validador de `lib/wa-fluxo.php`. As duas regras novas: card `roteiros` com `opcoes` preenchido é problema (as opções vêm do banco), e sem `proximo` bloqueia.

- [ ] **Step 4: Espelhar no painel.** Acrescentar o tipo em `poFluxoTipos()` (`painel/fluxo.js`), subir `fluxo.js?v=12` em `painel/index.html` e o sha em `painel/assets-lock.json`.

- [ ] **Step 5: Rodar.** `php tests/run.php` — verde, incluindo `test-wa-fluxo-tipos.mjs` (que confere PHP x painel) e `test-cache-buster.mjs`.

- [ ] **Step 6: Commit.** `git commit -m "feat(fluxo): o tipo de card roteiros, que lista as viagens por texto"`

---

## Task 3: montar a lista e casar a resposta

**Files:**
- Modify: `lib/wa-fluxo.php` (execução do card `roteiros`, `wa_fluxo_proximo`)
- Modify: `lib/wa-motor.php` (montar e gravar o contexto, linhas 551 e 669)
- Create: `supabase/migrations/2026-10-30-roteiros-enviados.sql`
- Create: `tests/test-wa-fluxo-roteiros.php`
- Test: `tests/test-wa-schema.php` (a sonda que confere o schema contra o banco)

**Por que a coluna:** a lista vai numa mensagem e a resposta vem na seguinte, então a ordem enviada **tem que sobreviver entre as duas**. O contexto é montado de colunas de `po_wa_conversas` (`wa-motor.php:551`) e gravado de volta (`:669`); sem coluna, `roteiros_enviados` morre no fim da requisição e o número passaria a valer pela lista de agora, que é justamente o defeito do Review Focus. A migration acrescenta `po_wa_conversas.roteiros_enviados jsonb not null default '[]'::jsonb`, aditiva e idempotente (`add column if not exists`).

**Interfaces:**
- Consumes: o tipo `'roteiros'` da Task 2; `po_fetch_roteiros` pelo injetor `wa_fl_call`/`$roteiros()`, como o card `lista` já faz.
- Produces:
  - `wa_fluxo_roteiros_venda(array $roteiros): array` — pura. Filtra `ativo` verdadeiro e `status_viagem` fora de `['esgotado','em_viagem']`, preserva a ordem recebida.
  - `wa_fluxo_lista_roteiros(array $venda): string` — pura. Uma linha por roteiro: `"1. Título — período"`, sem o travessão e o período quando não houver período.
  - `wa_fluxo_casa_roteiro(string $resposta, array $slugs_enviados, array $venda): ?string` — pura. Devolve o slug ou `null`.
  - No contexto da conversa passa a existir `roteiros_enviados`: a lista de slugs, na ordem em que foi enviada.

- [ ] **Step 1: Escrever o teste que falha** em `tests/test-wa-fluxo-roteiros.php`

```php
$cat = [
  ['slug'=>'vietna','titulo'=>'Vietnã Completo','periodo'=>'De 22/02/27 a 15/03/27','ativo'=>true,'status_viagem'=>null],
  ['slug'=>'turquia','titulo'=>'Turquia com Antália','periodo'=>'De 27/10/26 a 12/11/26','ativo'=>true,'status_viagem'=>'esgotado'],
  ['slug'=>'chile','titulo'=>'Chile e Deserto do Atacama','periodo'=>'25/11/26 a 02/12/26','ativo'=>true,'status_viagem'=>'ultimas_vagas'],
  ['slug'=>'peru','titulo'=>'Peru','periodo'=>'','ativo'=>true,'status_viagem'=>'em_breve'],
  ['slug'=>'japao','titulo'=>'Japão','periodo'=>'x','ativo'=>true,'status_viagem'=>'em_viagem'],
  ['slug'=>'india','titulo'=>'Índia','periodo'=>'y','ativo'=>false,'status_viagem'=>null],
];
$v = wa_fluxo_roteiros_venda($cat);
ok(array_column($v,'slug') === ['vietna','chile','peru'], 'so o que esta a venda, na ordem: ' . implode(',', array_column($v,'slug')));

$txt = wa_fluxo_lista_roteiros($v);
ok(strpos($txt, '1.') === 0, 'numera a partir de 1');
ok(strpos($txt, 'Peru') !== false && strpos($txt, '3.') !== false, 'em_breve entra mesmo sem data');
ok(substr_count($txt, "\n") === 2, 'uma linha por roteiro');

$slugs = array_column($v, 'slug');
ok(wa_fluxo_casa_roteiro('2', $slugs, $v) === 'chile', 'casa por numero');
ok(wa_fluxo_casa_roteiro('Chile e Deserto do Atacama', $slugs, $v) === 'chile', 'casa por nome completo');
ok(wa_fluxo_casa_roteiro('chile', $slugs, $v) === 'chile', 'casa por parte do nome, sem caixa');
ok(wa_fluxo_casa_roteiro('VIETNA', $slugs, $v) === 'vietna', 'casa sem acento');
ok(wa_fluxo_casa_roteiro('  2  ', $slugs, $v) === 'chile', 'ignora espaco em volta');
// as que NAO podem escolher
ok(wa_fluxo_casa_roteiro('9', $slugs, $v) === null, 'numero fora da lista nao escolhe');
ok(wa_fluxo_casa_roteiro('0', $slugs, $v) === null, 'zero nao escolhe');
ok(wa_fluxo_casa_roteiro('', $slugs, $v) === null, 'resposta vazia nao escolhe');
ok(wa_fluxo_casa_roteiro('bom dia', $slugs, $v) === null, 'frase qualquer nao escolhe');
ok(wa_fluxo_casa_roteiro('a', $slugs, $v) === null, 'uma letra que casa tudo nao escolhe');
// ambiguidade: duas viagens com "tesouros" no nome
$amb = [['slug'=>'t1','titulo'=>'Tesouros do Oriente','periodo'=>'','ativo'=>true,'status_viagem'=>null],
        ['slug'=>'t2','titulo'=>'Tesouros Asiáticos','periodo'=>'','ativo'=>true,'status_viagem'=>null]];
ok(wa_fluxo_casa_roteiro('tesouros', array_column($amb,'slug'), $amb) === null, 'parte ambigua nao escolhe');
ok(wa_fluxo_casa_roteiro('asiáticos', array_column($amb,'slug'), $amb) === 't2', 'parte que distingue escolhe');
// o numero vale pela lista ENVIADA, nao pela de agora
$agora = [$cat[2]];  // so o Chile sobrou
ok(wa_fluxo_casa_roteiro('1', ['vietna','chile','peru'], $agora) === 'vietna',
   'o numero 1 e o que foi enviado como 1, ainda que o catalogo tenha mudado');
```

- [ ] **Step 2: Rodar e ver falhar.** `php tests/test-wa-fluxo-roteiros.php` — esperado: função não definida.

- [ ] **Step 3: Implementar as três funções** em `lib/wa-fluxo.php`.
  Normalização para o casamento por nome: minúsculas e sem acento, pelos dois lados. O casamento por parte do nome só escolhe quando **exatamente um** título contém o trecho, e trecho com menos de 3 caracteres não casa por nome (evita "a" valendo tudo). Número fora de `1..count` devolve `null`.

- [ ] **Step 4: Ligar na execução do card.** No `case 'roteiros':` de `wa_fluxo_executa`: montar a lista pelo catálogo, mandar `send_text` com o corpo do card seguido da lista, e gravar `roteiros_enviados` no contexto. Catálogo vazio segue a regra que o `pdf` já usa: erro transitório registrado em `error_log`, **nunca** uma lista vazia.

- [ ] **Step 5: Ligar no roteamento.** Em `wa_fluxo_proximo`, o ramo do card `roteiros` usa `wa_fluxo_casa_roteiro` com os `roteiros_enviados` do contexto; achou, grava `roteiro_slug` e vai para `proximo`; não achou, cai no `fallback` como qualquer resposta que não casa.

- [ ] **Step 6: Persistir a ordem enviada.** Escrever a migration com `add column if not exists`, e passar `roteiros_enviados` a ida e volta no contexto do motor (`wa-motor.php:551` e `:669`), ao lado de `roteiro_slug` e `tentativas`. Acrescentar a coluna à sonda `tests/test-wa-schema.php`, que fica **vermelha até a migration rodar** — é o comportamento esperado dela, e não bloqueia as outras tarefas.

- [ ] **Step 7: Rodar.** `php tests/run.php` — verde **exceto** `test-wa-schema.php`, que acusa a coluna que falta no banco. Confirmar que é só ela.

- [ ] **Step 8: Commit.** `git commit -m "feat(fluxo): o card roteiros monta a lista do banco e casa numero ou nome"`

---

## Task 4: digitar vale como escolha, e só neste card

**Files:**
- Modify: `lib/wa-motor.php:558-562`
- Test: `tests/test-wa-motor.php`

**Interfaces:**
- Consumes: o tipo `'roteiros'` (Task 2).

- [ ] **Step 1: Escrever o teste que falha** em `tests/test-wa-motor.php`, com o motor em dublê (`wa_motor_set_deps`), cobrindo os dois lados da regra:

```php
// no card roteiros, texto digitado ESCOLHE
// conversa parada em card 'roteiros', cliente manda "2" como texto comum
ok($rota === 'escolheu' && $slug_gravado === 'chile', 'no card roteiros, digitar escolhe');
// no card botoes, texto digitado NAO escolhe (segue caindo no fallback)
ok($rota_botoes === 'fallback', 'no card de botoes, digitar "sim" nao vale como o botao Sim');
// e o clique continua valendo nos dois
ok($rota_clique === 'opcao', 'o clique no botao segue escolhendo');
```

- [ ] **Step 2: Rodar e ver falhar.** `php tests/test-wa-motor.php`

- [ ] **Step 3: Trocar a regra** por uma que olha o tipo do card atual: o texto digitado passa adiante quando o card é `roteiros`, e segue virando string vazia nos demais. **O comentário das três linhas tem que ser reescrito**: a razão antiga ("quem escreve em vez de tocar na lista escolheria sem querer") continua valendo para `botoes` e `lista` e deixa de valer onde não há o que tocar.

- [ ] **Step 4: Rodar.** `php tests/run.php` — verde.

- [ ] **Step 5: Commit.** `git commit -m "fix(motor): digitar vale como escolha no card roteiros, e so nele"`

---

## Task 5: a versão 2 do fluxo de triagem

**Files:**
- Create: `supabase/migrations/2026-10-30-fluxo-triagem-v2.sql`
- Test: `tests/test-wa-fluxo-semeado.php` (que já lê o SQL como texto e passa o grafo pelo validador e pelo roteador)

**Interfaces:**
- Consumes: o tipo `roteiros` (Task 2) e a ausência de `{nome}` (Task 1).
- Produces: `po_wa_fluxo_versoes` ganha a **versão 2** de `a1000000-…-0001`; `po_wa_fluxos.versao_atual` passa a apontar para ela; os cards do rascunho são redesenhados.

**O grafo da versão 2** (os ids que somem: `…003` programação, `…009`/`…010`/`…011` grupo, `…019` repete grupo):

| Card | Tipo | Papel |
|---|---|---|
| `…001` Boas-vindas | `botoes` | 2 opções: Roteiros → `…002`, Falar com a Laís → `…004` |
| `…002` Qual viagem | `roteiros` | lista numerada → `…005` |
| `…004` Atendimento humano | `humano` | fim |
| `…005` Manda o PDF | `pdf` | → `…006` |
| `…006` Marca interesse | `marca` | → `…007` |
| `…007` A data serve? | `botoes` | Sim → `…008`, Não → `…013` |
| `…008` Marca: data serve | `marca` | `status=atendimento`, `qualif_data=true` → `…012` |
| `…012` A Laís assume | `humano` | fim |
| `…013` Marca: data não serve | `marca` | `status=perdido`, `qualif_data=false` → `…014` |
| `…014` Lista de espera? | `botoes` | Sim → `…015`, Não → `…017` |
| `…015` Lista de espera | `ir_para_fluxo` | vai para `a1000000-…-0002` |
| `…016` Respondeu outra coisa | `botoes` | fallback do menu, 2 opções |
| `…017` Despedida | `fim` | despedida reescrita |
| `…018` Repete: a data serve? | `botoes` | fallback de `…007` |
| `…020` Repete: lista de espera? | `botoes` | fallback de `…014` |

- [ ] **Step 1: Escrever o teste que falha** em `tests/test-wa-fluxo-semeado.php`, lendo o SQL novo como texto:

```php
// a versao 2 existe e o validador nao acha bloqueio nela
ok(count(array_filter(wa_fluxo_valida($g2), fn($p) => $p['bloqueia'])) === 0, 'versao 2 sem bloqueio');
// as duas portas do menu
ok(count($g2['cards']['c1000000-0000-4000-8000-000000000001']['opcoes']) === 2, 'o menu tem 2 opcoes');
// os cards que sairam
foreach (['…003','…009','…010','…011','…019'] as $morto) {
    ok(!isset($g2['cards'][$morto]), 'o card ' . $morto . ' saiu do fluxo');
}
// a pergunta do grupo nao existe em texto nenhum
ok(stripos(json_encode($g2), 'em grupo') === false, 'a pergunta do grupo saiu');
// o {nome} nao existe em texto nenhum
ok(strpos(json_encode($g2), '{nome}') === false, 'nenhum texto usa {nome}');
// o caminho do Nao: data -> lista de espera -> fluxo 2
ok($g2['cards']['…014']['tipo'] === 'botoes' && count($g2['cards']['…014']['opcoes']) === 2, 'a lista de espera pergunta Sim/Nao');
// idempotencia: o SQL nao apaga edicao da cliente
ok(strpos($sql, 'on conflict') !== false && strpos($sql, 'delete from public.po_wa_cards') === false,
   'a migration nao apaga card');
```

- [ ] **Step 2: Rodar e ver falhar.** `php tests/test-wa-fluxo-semeado.php`

- [ ] **Step 3: Escrever a migration.** Insere a versão 2 (`on conflict do nothing`), aponta `versao_atual` para ela, e redesenha o rascunho: `update` nos cards que mudam de texto ou de saída, `insert … on conflict do nothing` nos novos, e `delete` **apenas** dos 5 ids listados acima, nomeados um a um. Cabeçalho explicando que ela é de rodada única para o `versao_atual` e que apagar card por id não alcança card que a cliente tenha criado.

- [ ] **Step 4: Rodar.** `php tests/run.php` — verde. **Não rodar a migration no banco:** isso é passo de deploy, e o Fabiano decide quando.

- [ ] **Step 5: Commit.** `git commit -m "feat(fluxo): versao 2 da triagem, com menu de duas portas e uma pergunta so"`

---

## Task 6: o campo de mensagem que nasce pequeno

**Files:**
- Modify: `painel/fluxo.css` (`.fl-campo`), `painel/index.html`, `painel/assets-lock.json`
- Test: `tests/test-fluxo-painel.mjs`

- [ ] **Step 1: Escrever o teste que falha** em `tests/test-fluxo-painel.mjs`, sobre o CSS lido com `le()`:

```js
const campo = regrasTodas(css).find(r => r.sel === '.fl-campo');
assert.ok(/min-height:\s*\d+/.test(campo.corpo), '.fl-campo tem altura minima, senao nasce com uma linha');
assert.ok(/resize:\s*vertical/.test(campo.corpo), 'e segue redimensionavel na vertical');
assert.ok(/field-sizing|rows/.test(css + src) === true || /min-height/.test(campo.corpo),
  'a altura inicial nao depende do atributo rows, que a gaveta nao emite');
```

- [ ] **Step 2: Rodar e ver falhar.** `node tests/test-fluxo-painel.mjs`

- [ ] **Step 3: Dar altura mínima ao `.fl-campo`** em `painel/fluxo.css`, mantendo `resize:vertical`. Subir `fluxo.css?v=6` e o sha.

- [ ] **Step 4: Ver na tela.** Montar a página local do grafo real (a receita está no `CLAUDE.md`, seção do zoom; **recopiar** os arquivos para o scratchpad antes de recarregar) e abrir a gaveta de um card com texto longo. Confirmar que o campo nasce com várias linhas e que a alça de redimensionar funciona.

- [ ] **Step 5: Rodar.** `php tests/run.php` — verde.

- [ ] **Step 6: Commit.** `git commit -m "fix(painel): o campo de mensagem da gaveta nasce com altura util"`

---

## Task 7: excluir opção, com desfazer e aviso de órfão

**Files:**
- Modify: `painel/fluxo.js` (gaveta, `poFluxoPatch`, `poFluxoGavetaSalva`), `painel/fluxo.css`, `painel/index.html`, `painel/assets-lock.json`
- Test: `tests/test-fluxo-painel.mjs`

**Interfaces:**
- Produces:
  - `poFluxoRemoveOpcao(opcoes, indice)` — pura. Devolve a lista sem aquela opção.
  - `poFluxoOrfaos(card_id, opcoes_depois, cards)` — pura. Devolve os ids de destino que, tirada a opção, ficam sem ninguém apontando para eles.
  - A gaveta guarda a opção removida só até fechar ou salvar.

- [ ] **Step 1: Escrever o teste que falha** em `tests/test-fluxo-painel.mjs`

```js
// remover devolve a lista sem a opcao, sem mexer nas outras
const ops = [{id:'a',rotulo:'A',destino:'x'},{id:'b',rotulo:'B',destino:'y'}];
assert.deepEqual(F.poFluxoRemoveOpcao(ops, 0), [ops[1]], 'remove a primeira');
assert.deepEqual(F.poFluxoRemoveOpcao(ops, 9), ops, 'indice fora nao muda nada');
// orfao: o destino que ninguem mais alcanca
const cards = {m:{id:'m',tipo:'botoes',opcoes:ops}, x:{id:'x',tipo:'fim'}, y:{id:'y',tipo:'fim'}};
assert.deepEqual(F.poFluxoOrfaos('m', [ops[1]], cards), ['x'], 'x ficou sem ninguem apontando');
// destino que OUTRO card tambem alcanca nao e orfao
const cards2 = Object.assign({}, cards, {n:{id:'n',tipo:'texto',proximo:'x'}});
assert.deepEqual(F.poFluxoOrfaos('m', [ops[1]], cards2), [], 'x segue alcancavel por n');
// a trava de seguranca continua: a tela nunca escreve o id da opcao
assert.ok(!/\bid\s*:/.test(pega('poFluxoRemoveOpcao')), 'remover nao escreve id de opcao');
```

- [ ] **Step 2: Rodar e ver falhar.** `node tests/test-fluxo-painel.mjs`

- [ ] **Step 3: Implementar as duas funções puras** em `painel/fluxo.js`.

- [ ] **Step 4: Ligar na gaveta.** Botão de excluir por opção; depois de excluir, a gaveta mostra "Opção removida" com **Desfazer**, e, quando `poFluxoOrfaos` devolver algo, o aviso nomeando os cards que ficarão sem entrada — **avisando, nunca apagando**. O desfazer some ao salvar e ao fechar.

- [ ] **Step 5: Trava no ponto de chamada.** Teste de que `poFluxoGavetaSalva` leva `opcoes` no conjunto de chaves que grava, e que continua **não** gravando `tipo`, `proximo`, `fallback`, `sem_resposta` nem posição. Esta é a trava das chaves efetivas que já existe: estendê-la, não reescrevê-la.

- [ ] **Step 6: Ver na tela.** Mesma receita da Task 6: excluir, desfazer, e conferir o aviso de órfão.

- [ ] **Step 7: Rodar.** `php tests/run.php` — verde, com `?v=` e sha subidos.

- [ ] **Step 8: Commit.** `git commit -m "feat(painel): excluir opcao com desfazer, e aviso quando o destino fica orfao"`

---

## Task 8: avanço e retorno no canvas

**Files:**
- Modify: `painel/fluxo.js` (`poFluxoCanvas`), `painel/fluxo.css`, `painel/index.html`, `painel/assets-lock.json`
- Test: `tests/test-fluxo-painel.mjs`

**Interfaces:**
- Produces: a seta ganha `class="fl-seta fl-seta--volta"` quando o destino está à esquerda do card de origem (`para.x <= de.x`), que é o que o desenho chama de retorno.

- [ ] **Step 1: Escrever o teste que falha** em `tests/test-fluxo-painel.mjs`

```js
const f = {card_inicial:'a', cards:[
  {id:'a',tipo:'botoes',titulo:'A',x:0,y:0,opcoes:[{id:'1',rotulo:'vai',destino:'b'}]},
  {id:'b',tipo:'botoes',titulo:'B',x:600,y:0,opcoes:[{id:'2',rotulo:'volta',destino:'a'}]}]};
const h = F.poFluxoCanvas(f);
assert.ok(/data-de="b"[^>]*data-para="a"[^>]*fl-seta--volta|fl-seta--volta[^>]*data-de="b"/.test(h),
  'a seta que volta para a esquerda vem marcada');
assert.ok(!/data-de="a"[^>]*fl-seta--volta/.test(h), 'e a que avanca nao');
// o CSS distingue de verdade, dentro do @media do canvas
assert.ok(/\.fl-seta--volta\{[^}]*(stroke-dasharray|stroke:)/.test(dentro),
  'o retorno tem traco ou cor propria');
```

- [ ] **Step 2: Rodar e ver falhar.** `node tests/test-fluxo-painel.mjs`

- [ ] **Step 3: Marcar a seta** em `poFluxoCanvas` e dar a ela `stroke-dasharray` e cor própria em `painel/fluxo.css`, dentro do `@media (min-width:980px)` que já existe. Lembrar da regra da cascata: o padrão vem **antes** do `@media`.

- [ ] **Step 4: Ver na tela.** Mesma receita: confirmar que os retornos do grafo real aparecem tracejados e que o avanço segue sólido.

- [ ] **Step 5: Rodar.** `php tests/run.php` — verde.

- [ ] **Step 6: Commit.** `git commit -m "feat(painel): o canvas distingue o caminho de volta do de avanco"`

---

## Task 9: os textos, e a revisão com a cliente

**Files:**
- Modify: `supabase/migrations/2026-10-30-fluxo-triagem-v2.sql` (textos da versão 2)
- Test: `tests/test-wa-fluxo-semeado.php`

- [ ] **Step 1: Escrever o teste que falha**

```php
// despedida generica: sem nome, sem promessa de prazo que ninguem cumpre
foreach (['…017', '…004', '…012'] as $id) {
    $t = $g2['cards'][$id]['texto'];
    ok(strpos($t, '{nome}') === false, 'o card ' . $id . ' nao chama pelo nome');
    ok(!preg_match('/\b(volto|retorno|em breve|logo mais|assim que possivel)\b/iu', $t),
       'o card ' . $id . ' nao promete prazo');
}
// sem travessao e sem emoji (regra de copy da casa)
ok(!preg_match('/[\x{2014}\x{2013}]|[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', json_encode($g2)),
   'nenhum texto do fluxo tem travessao ou emoji');
```

O limite de caracteres por tipo **não** é reasserido aqui: `wa_fluxo_valida` já o confere com `wa_fluxo_tam_pior_caso` (`lib/wa-fluxo.php:467`), e a Task 5 já exige que o validador não ache bloqueio na versão 2.

- [ ] **Step 2: Rodar e ver falhar.** `php tests/test-wa-fluxo-semeado.php`

- [ ] **Step 3: Reescrever as despedidas** na migration, em português, sem travessão e sem emoji, em primeira pessoa do plural (a agência fala), sem prometer prazo.

- [ ] **Step 4: Rodar.** `php tests/run.php` — verde.

- [ ] **Step 5: Commit.** `git commit -m "feat(fluxo): despedidas genericas e profissionais"`

---

## Deploy (fora das tarefas, e só com o Fabiano mandando)

1. Rodar as duas migrations no projeto `hd-360` (`euzmbswywwhmicjlszqw`), nesta ordem: `2026-10-30-roteiros-enviados.sql` (a coluna) e depois `2026-10-30-fluxo-triagem-v2.sql` (o grafo). O portão recusa `drop`/`update` no mesmo bloco do DDL: aplicar em pedaços, **exceto `2026-10-30-fluxo-triagem-v2.sql`, que não tem DDL e roda inteiro de uma vez** (partir deixa o rascunho sem cards com o ponteiro na versão 1).
2. FTP, nesta ordem: `lib/wa-fluxo.php`, `lib/wa-motor.php`, `lib/wa-timeout.php` (so comentario, mas vai junto para a comparacao por hash ficar limpa), `painel/fluxo.js`, `painel/fluxo.css`, e `painel/index.html` **por último**.
3. Conferir os arquivos de `lib/` e `painel/` por hash contra o servidor, a lista completa.
4. **Nada é ligado.** O webhook segue não inscrito e os fluxos seguem `ativo = false`.
