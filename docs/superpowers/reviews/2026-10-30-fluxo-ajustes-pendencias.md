# Ajustes do fluxo (Plano C1) — pendências e heranças

Mesclada em `main` por fast-forward em 02/11/2026 (24 commits de `3c470b7` a `7559aae`, mais o `99c9c13` abaixo). Plano:
`docs/superpowers/plans/2026-10-30-fluxo-ajustes-reuniao.md`.

**Nada está ligado.** O webhook segue não inscrito, os dois fluxos seguem `ativo = false`,
nenhum cron foi cadastrado, e **nenhuma migration desta branch foi rodada**.

Suíte na árvore mesclada: **72 passam, 1 falha**. A falha é `tests/test-wa-schema.php`, a
sonda que procura a coluna `po_wa_conversas.roteiros_enviados` no banco. Ela é vermelha
**de propósito** até a migration rodar no deploy: é assim que ela avisa que falta rodar.

O merge custou um vermelho a mais, consertado em `99c9c13`: a normalização de fim de linha
de `tests/test-wa-fluxo-versao.php` era `str_replace` com os **dois primeiros argumentos
iguais** (newline literal no fonte em vez das escapes de duas letras), ou seja um no-op.
Passava na branch porque lá o `lib/wa-fluxo.php` era LF e o regex ancorado em nova linha
casava sem normalização nenhuma; no merge os dois arquivos viraram CRLF e a trava de
`wa_fluxo_publica` caiu com o código idêntico. Mutação depois do conserto: injetar
`'ativo'` no corpo mata o teste. Varri a suíte inteira — era o único site.

---

## 1. O que BLOQUEIA ligar o robô

**Uma coisa só**, e ela já está consertada nesta branch (commit `ebf7e3f`), mas vale
saber por que existia: `wa_fluxo_casa_roteiro` recusava a forma de resposta que a própria
lista do card ensina. O card manda `2. Chile e Deserto do Atacama` e pede "responda com o
número ou o nome"; quem respondia `"2."` ou copiava a linha inteira caía no fallback.
Nunca escolhia errado — a falha era segura — mas era vazamento silencioso de conversão no
card que é o funil inteiro, invisível no log a não ser como fallback.

Hoje um marcador de lista (`2.`, `2)`, `2 -`, `2:`) é retirado antes do teste de dígitos,
então essas formas resolvem **pela posição**, que é o sinal mais forte disponível e não
pode escolher o roteiro errado.

**Deliberadamente NÃO foi alargada** a busca por nome nos dois sentidos. Se "a resposta
contém o título" passasse a valer, `"quero o chile e o peru"` casaria dois roteiros e a
regra de ambiguidade — que existe para impedir escolha errada silenciosa — começaria a
disparar em frases comuns.

---

## 2. Ordem de deploy (não inverter)

1. `supabase/migrations/2026-10-30-roteiros-enviados.sql` — a coluna.
2. `supabase/migrations/2026-10-30-fluxo-triagem-v2.sql` — o grafo.

A segunda **roda inteira, de uma vez**. Ela não tem DDL nenhum (seis comandos: insert,
insert, delete, insert, update, update), então a regra do `CLAUDE.md` de aplicar "em
pedaços" **não vale para ela** — e segui-la é o único jeito de chegar no estado
meio-aplicado: parando entre o `delete` irreversível e o redesenho do rascunho, o painel
passa a desenhar uma conversa que o motor não roda.

**Antes de rodar a segunda, rodar as duas consultas comentadas no cabeçalho dela.** Elas
medem o que a migration vai sobrescrever (dez campos semânticos) e o que a cliente
apagou. Medido em 30/10: **22 cards conferidos, zero editados, zero apagados** — rodar
hoje não perde nada. Isso pode mudar até o deploy, e é por isso que a consulta mora no
arquivo.

A migration também **reseta as posições dos cards no canvas**. É cosmético e está dito no
cabeçalho.

**FTP:** `lib/wa-fluxo.php`, `lib/wa-motor.php`, `lib/wa-timeout.php` (só comentário),
`painel/fluxo.js`, `painel/fluxo.css`, e `painel/index.html` **por último**. Conferir a
lista **completa** por hash, nunca a lista do que se lembra de ter subido.

---

## 3. Três perguntas de COPY para a cliente

Nenhuma é defeito. São voz de marca, e a dona da marca decide. Todas são uma frase numa
migration que ainda não rodou.

1. **"Ótimo."** abrindo o card `…012` (quando a data serve e a Laís assume a venda).
2. **"para não incomodar"** na despedida (card `…017`).
3. O card `…004` agora **pede algo ao cliente**: "escreva aqui o que a Laís precisa
   saber". Pode soar como tarefa para quem só queria falar com uma pessoa.

Uma quarta, de produto: hoje `"quero saber do chile"` (frase contendo o nome) **não**
escolhe, e cai no menu. O padrão conservador é o certo e a falha é segura; vale perguntar
se ela quer casamento mais frouxo, sabendo que o preço é uma resposta citando dois
roteiros deixar de casar qualquer um.

---

## 4. Dois itens de verificação que ficaram abertos

- **O campo de mensagem que nascia pequeno.** O conserto (`min-height` no
  `textarea.fl-campo`) está mecanicamente certo: item de flex com `overflow` pode ser
  esmagado abaixo do conteúdo, e um `min-height` explícito é o piso. Mas **o defeito não
  foi reproduzido** aqui — este Chrome dá 157px com e sem ele. Conferir no aparelho em
  que a cliente demonstrou.
- **Larguras abaixo de 980px** não foram vistas. É onde o canvas some e a barra lateral
  vira gaveta.

---

## 5. Achado ORTOGONAL a esta branch, e importante

**O runner executa 72 arquivos de teste, e só 58 estão no Git.** Os 14 que faltam existem
só no disco desta máquina, e agora sustentam várias das garantias desta branch. O verde
medido aqui **não é reproduzível por outra pessoa**, e quem clonar o repo roda menos
testes sem ter como saber. Isso já estava registrado no `CLAUDE.md` e segue valendo.

---

## 6. Achados menores, deliberadamente não consertados

A revisão final triou todos e concluiu que **nenhum bloqueia o merge**.

| Onde | O quê |
|---|---|
| `lib/wa-timeout.php:35-36` | SELECT em `po_leads` só para ler um `$nome` que já não é usado. Provadamente morto; duas linhas a menos quando o arquivo for tocado. |
| `lib/wa-fluxo.php` (log do roteador) | A mensagem diz "não pode ser casada por nome", mas também dispara com número digitado e com catálogo vazio por esgotamento. |
| `lib/wa-fluxo.php` (`roteiros_enviados`) | `(string) $r['slug']` sem guarda. Inalcançável: o filtro já rejeita linha sem slug. |
| `tests/test-wa-motor.php` (20d) | A 2ª asserção passa com a chave ausente; a de cima é que trava. |
| `tests/fixtures/fluxo-motor.php` | As linhas de catálogo não têm campo `ativo`; o helper contorna sobrescrevendo a dependência. Armadilha para a próxima pessoa. |
| migration v2 (teste) | A asserção do `where v.id in (...)` depende do texto do SQL casar com a regex: reformatar quebra por formatação. |
| migration v2 (teste) | `foreach ($ups ...)` usa "último casado vence"; "casa exatamente um" fecharia. |
| migration v2 | As guardas `exists` não têm teste automatizado. Intestáveis sem banco. |
| `painel/fluxo.js` | O texto do limite de botões vive em dois lugares (`poFluxoContagem` e `poFluxoBloqueio`) sem nada amarrando os dois. |
| `painel/fluxo.js` | Não há teste para "save falhou mantém as remoções pendentes". Correto por construção, só sem teste. |
| `painel/fluxo.js:565` | O aviso de órfão fica **mudo** quando TODAS as opções de um card de botões são apagadas. A cliente descobre no Publicar. **O primeiro a pegar se a gaveta for mexida de novo.** |
| `painel/fluxo.js` | Normalização de cards duplicada; falta `aria-live` na área de aviso. |
| `painel/fluxo.css` | `min-height:150px` também veste o textarea de lembrete de 4 linhas. Cosmético. |
| `tests/test-fluxo-painel.mjs` | A guarda de frase fixa o português ("o maior roteiro e a maior data"): ajuste de copy quebra o teste mesmo com texto certo. |
| `tests/test-fluxo-painel.mjs` | `art` exige só "string não vazia", não que seja artigo de verdade. |
| `lib/wa-fluxo.php:272-280` | O roteador busca o catálogo mesmo quando a resposta é um número, que resolve sem ele. Cache de 10 min amortece. |
| matcher | `"1.5"` resolve para a posição 1 (antes era null). Entrada que ninguém digita como escolha. |

---

## 7. Regras permanentes que esta branch estabeleceu

- **O número vale pela lista ENVIADA, não pela de agora.** `po_wa_conversas.roteiros_enviados`
  guarda a ordem dos slugs, e a guarda de 4096 caracteres descarta item da mensagem **e**
  da coluna no mesmo laço, então o número que a pessoa lê nunca descola do slug.
- **Catálogo vazio é erro transitório, nunca "não temos viagens".** `po_fetch_roteiros`
  devolve `[]` tanto para vazio quanto para Supabase fora, e a agência sempre tem roteiro
  à venda: vazio é sempre falha.
- **"Nem um item cabe" é permanente, não transitório** — depende só do texto do card,
  então repete igual para sempre. Vai para a humana.
- **Digitar vale como escolha SÓ no card `roteiros`.** Em `botoes` e `lista` a razão
  antiga continua valendo: quem escreve em vez de tocar escolheria sem querer.
- **O validador e o runtime têm de concordar sobre opção largada.** Um predicado só
  (`$listaDin`) serve quatro costuras: saídas, regra 7, o ponto de escolha da regra 13 e a
  dependência de roteiro da regra 14.
- **A gaveta nunca escreve o id de uma opção.** Remoção é por posição. Um id `sair`
  descadastraria o cliente do marketing.
- **A versão 1 do grafo carrega `{nome}` para sempre e isso é inofensivo:** o validador é
  portão do Publicar, não da execução. Conferido: `wa_fluxo_valida` só é chamada de
  `wa_fluxo_publica` e de `fluxo.php`.
- **A migration é tudo-ou-nada POR FLUXO, de propósito.** Acoplar os dois faria uma
  colisão na triagem deixar a espera sem versão 2 e portanto impublicável — e a espera
  tem de estar ligada antes da triagem.
- **Teste que lê a ordem das linhas prova que o programador escreveu na ordem, não que o
  programa se comporta.** Caso nomeado pela cliente exige percurso executado.
