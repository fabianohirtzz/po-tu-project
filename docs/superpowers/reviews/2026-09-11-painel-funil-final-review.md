# Revisao final da branch `painel-funil`

Base `4b86a55` .. head `da294ad` — 11 commits, 10 arquivos, 911 linhas.
Revisao feita sobre o conjunto (primeira que ve as seis tarefas juntas), com leitura dos
arquivos finais, do diff por commit, da spec (secoes 7 e 7.1) e do plano.

**Veredicto: PRECISA DE CORRECAO.** Um achado Critical bloqueia o merge: a consulta da
conversa perdeu o filtro por contato no ultimo commit e passa a mostrar, dentro da gaveta
de um lead, as mensagens de TODOS os contatos. Os outros achados Important sao baratos e
moram nos mesmos arquivos.

---

## Pontos fortes

- **A costura entre os quatro arquivos esta correta.** Nenhum identificador de topo colide
  (conferido: `PO_COLUNAS`, `PO_AUTOR`, `poMovendo` e as 60+ declaracoes do `app.js` sao
  todas unicas). `clientes.js`/`funil.js`/`conversa.js` carregam depois do `app.js` e so
  consomem `esc`, `brl`, `fmtData`, `STATUS`, `LEADS`, `filtered`, `sb`, `openId`, `toast`
  e `openDrawer` — todos ja definidos quando qualquer handler roda.
- **O `#scrim` compartilhado sobrevive a analise.** Percorri as combinacoes dos cinco
  paineis (drawer, rdrawer, ndrawer, ficha, menu de etapas). Nao encontrei caminho
  alcancavel que deixe o fundo preso nem painel invisivel aberto: `closeDrawer()` remove o
  scrim sempre e e o primeiro do handler; `poFechaFicha()` so remove se o drawer nao
  estiver aberto. O `addEventListener` do `funil.js` (em vez de `onclick`) preserva o
  handler do `app.js`, e o comentario que explica isso esta no lugar certo.
- **A correcao do KPI inflado (commit 193808b) e a decisao certa** e esta bem explicada:
  sem ela, um card arrastado para fora de "Contrato assinado" inflaria faturamento e ROAS
  para sempre, em silencio. `renderLeadKpis`, `renderReports`, ticket e `venPago` foram
  todos ajustados de forma coerente.
- **`poPatchStatus` isolada da UI** foi a escolha certa: a regra do `venda_at` e a unica do
  funil que mexe em numero de relatorio, e a unica parte testavel sem navegador.
- **Escapamento consistente.** Todo dado de terceiro (nome de perfil, roteiro, texto de
  mensagem) passa por `esc()`, inclusive dentro de atributos; o nome no menu de etapas usa
  `textContent`. Os tres testes novos cobrem `<img onerror>` em cada superficie.
- **Task 1 (runner) e um ganho desproporcional ao tamanho:** `test-slugify.mjs` estava no
  repo desde julho sem nunca rodar. O SKIP visivel quando falta o node, em vez de PASS
  silencioso, e exatamente o cuidado certo para um projeto sem CI.
- **HTML validado:** conferi o aninhamento de `div/aside/section/table` do
  `painel/index.html` inteiro com um parser de pilha — zero desbalanceamento, apesar de a
  Task 6 ter envelopado metade da gaveta num `.dr-pane` novo.
- Os quatro testes `.mjs` passam quando rodados isolados.

---

## Critical (corrigir antes do merge)

### C1. A conversa mostra as mensagens de todos os contatos
`painel/conversa.js:59-68`

A consulta perdeu o `.eq('wa_id', waId)`:

```js
const {data, error} = await sb.from('po_wa_mensagens')
  .select('autor,direcao,tipo,texto,ts')
  .order('ts', {ascending: false})
  .limit(300);
```

O filtro existia no commit `c5cc1fc` e foi **removido junto com a troca de ordenacao no
commit `da294ad`** (o mesmo commit cuja mensagem promete "para de consultar sem wa_id"). O
plano traz o filtro explicitamente na linha 1199. A tabela existe e tem RLS
`for all to authenticated using (true)` (migration `2026-09-11-whatsapp-motor.sql`), entao
a consulta **funciona** e devolve as 300 mensagens mais recentes da base inteira.

Efeito: abrir qualquer lead com `wa_id` mostra conversas de outras pessoas, misturadas,
como se fossem dele. Mistura dado de cliente com cliente, e a dona confia nessa tela para
saber o que o robo ja disse. E o pior tipo de defeito para esta feature: parece funcionar,
porque a tela enche de mensagens.

Correcao:

```js
  .select('autor,tipo,texto,ts')
  .eq('wa_id', waId)
  .order('ts', {ascending: false})
  .limit(300);
```

(o `direcao` do item 10 sai de graca no mesmo toque).

**Por que passou:** nenhum teste olha a forma da consulta — os `.mjs` extraem so funcoes
puras. Ver M9.

---

## Important (deveria entrar antes do merge)

### I1. Salvar a gaveta ressuscita a venda e reescreve a data do fechamento
`painel/app.js:263-269` x `painel/funil.js:87-89`

O funil decidiu, de proposito, **nao zerar `venda`** ao tirar o card de "Contrato
assinado" (so limpa `venda_at`). Mas a gaveta mantem a regra antiga "valor preenchido
conclui o lead":

```js
const ven=Number($('#e-ven').value)||0;
const status=ven>0?'venda':$('#e-status').value;
const vendaAt=fechado?(l.vendaAt||new Date().toISOString()):null;
```

Sequencia realista: a dona arrasta um card de "Contrato assinado" para "Proposta enviada"
(corrigindo um engano do atalho `#fechou`) — status vira `negociacao`, `venda_at` vira
null, `venda` fica em R$ 22.900. Dias depois ela abre esse lead para escrever uma nota e
clica em Salvar. O `ven>0` empurra o status de volta para `venda` e, como `l.vendaAt` ja e
null, **carimba `venda_at` com a data de hoje**. A data real do fechamento (que alimenta o
ciclo de venda, e portanto o relatorio) foi perdida sem aviso, e o funil voltou sozinho.

Antes desta branch o invariante "ven>0 implica status venda" valia em todo lugar; o funil
o quebrou sem ajustar o unico lugar que dependia dele.

Correcao mais barata que respeita as duas intencoes: so inferir `venda` quando o valor
mudou neste salvamento.

```js
const venMudou = ven !== l.ven;
const status = (ven>0 && venMudou) ? 'venda' : $('#e-status').value;
```

(alternativa: zerar `venda` no `poMoveLead` ao sair de venda — mas ai a correcao do KPI do
commit 193808b fica sem proposito, e um engano de arrasto destroi o numero.)

### I2. A aba Clientes ficou com o bug de faturamento inflado que o `app.js` corrigiu
`painel/clientes.js:71-72`, refletido em `:102`, `:123` e `:145`

```js
if (l.status === 'venda') { p.vendas += 1; p.temVenda = true; }
p.valorVendido += Number(l.ven) || 0;   // <- sem o filtro de status
```

O commit 193808b aplicou a regra "so soma `ven` de quem esta em status venda" no
`renderLeadKpis` e no `renderReports`, mas nao aqui. Resultado: um lead que saiu de venda
faz a pessoa aparecer com **"0 viagens" e "R$ 22.900 faturado"** na lista, no resumo da
ficha e no KPI "Faturado" da aba. Tres numeros novos contradizendo os dois numeros antigos,
na mesma tela.

Correcao:
`if (l.status === 'venda') { p.vendas += 1; p.temVenda = true; p.valorVendido += Number(l.ven) || 0; }`

### I3. A conversa abre no comeco, nunca no fim
`painel/conversa.js:76` x `painel/app.js:209-218` e `painel/painel.css:359-360`

`alvo.scrollTop = alvo.scrollHeight` roda enquanto `#pane-conversa` esta com
`display:none` (o `openDrawer` forca a aba "Dados" e so entao chama `poRenderConversa`).
Elemento sem caixa tem `scrollHeight` 0: a rolagem nao acontece. Quando a dona clica em
"Conversa", ela cai na **mensagem mais antiga das 300**.

Isso anula justamente o que o commit `da294ad` foi escrever (trazer o fim do dialogo): a
ordenacao descendente resolve o corte no banco, mas a tela continua abrindo no topo.

Correcao: rolar quando a aba e ativada, no handler que ja existe no `app.js:214-218`:

```js
if(t.dataset.tab==='conversa'){const c=$('#dr-conversa');c.scrollTop=c.scrollHeight;}
```

### I4. `poChavePessoa` e um segundo normalizador de telefone, divergente do que o projeto ja tem
`painel/clientes.js:26-35` x `lib/wa-fone.php:21-54`

A restricao do plano diz "telefone sempre em E.164 como chave de agrupamento". O projeto ja
tem o normalizador canonico, `wa_e164()`, com tabela de DDD, regra do nono digito e prefixo
`+55`, coberto por `tests/test-wa-fone.php`. O `clientes.js` implementou outro, mais fraco,
que produz **so digitos** e aplica uma regra unica (`10..11 digitos ganha 55`).

Duas divergencias reais, nao teoricas:

- **Celular antigo de 8 digitos.** Um lead antigo do formulario com `(48) 9604-8882` vira a
  chave `554896048882`; o mesmo numero pelo WhatsApp vira `+5548996048882`, ou seja digitos
  `5548996048882`. **Duas fichas para a mesma pessoa.** O `wa_e164()` resolve isso inserindo
  o nono digito.
- **DDD com zero.** `(048) 99604-8882` da 12 digitos, nao entra na regra do `55` e vira a
  chave `048996048882`. Terceira ficha.

Alem do defeito de hoje, e a divida que mais vai doer nos dois proximos planos: o importador
de contatos vai normalizar em PHP com `wa_e164()` (com `+`), a transmissao vai cruzar
`po_wa_contatos.wa_id` (E.164 com `+`) com essa chave de digitos, e as duas listas nao vao
casar.

Correcao: portar `wa_e164()` para JS num arquivo proprio (`painel/po-tel.js`), reusar os
casos de `tests/test-wa-fone.php` como teste `.mjs`, e fazer `poChavePessoa` chamar isso.
Sao ~40 linhas e mata a divergencia antes que os dois planos seguintes a herdem.

### I5. Filtrar por status dentro do Funil faz o card sumir ao ser movido
`painel/funil.js:55` (via `filtered()`, `painel/app.js:150-155`)

O quadro respeita `F.status`. Com o filtro em "Em negociacao", mover um card para "Contrato
assinado" faz `poRenderFunil()` re-renderizar a partir de `filtered()` e o card
**desaparece do quadro inteiro**, restando so o toast. Um kanban de status filtrado por
status se anula.

Correcao: o Funil ignora `F.status` (mantendo mes, busca e origem), com uma funcao propria
em vez de `filtered()`. Mes e busca fazem sentido ali; status nao.

### I6. A aba Clientes nasce filtrada pelo mes corrente
`painel/clientes.js:107` (mesma `filtered()`)

`buildMonths()` seleciona o mes mais recente por padrao. A aba que existe para responder
"quem ja viajou com a gente" abre mostrando **so quem apareceu neste mes** — com 11 leads em
producao, provavelmente duas pessoas, e os KPIs "Pessoas" e "Ja viajaram" contando so elas.
A justificativa da spec ("quem ja viajou e o melhor lead do proximo roteiro") pede o oposto:
a base inteira.

Correcao: a aba Clientes ignora `inMonth` (mantendo busca e origem), ou o clique na aba muda
o seletor para "Todos os meses".

### I7. No celular o quadro nao rola: a pagina inteira e que anda de lado
`painel/painel.css:341`

```css
@media(max-width:1100px){.fn-board{...;width:max-content;min-width:100%}}
```

`overflow-x:auto` e `width:max-content` na **mesma caixa** se cancelam: a caixa cresce ate
caber o conteudo, entao ela nunca rola; quem transborda e a `.view`, e o painel todo passa a
rolar na horizontal (arrastando o `.top` sticky junto). O padrao correto ja existe no
arquivo: `.table-wrap` + `.tscroll{overflow-x:auto}` (linhas 108-109).

O passo de verificacao do plano (linha 844, "reduzir a janela para 400px e confirmar que o
quadro rola na horizontal") nao foi cumprido de fato.

Correcao: um `<div class="fn-scroll">` com `overflow-x:auto` em volta do `#fn-board`, e o
`max-content` fica so no board.

### I8. O caminho de toque do funil e inalcancavel no celular
`painel/painel.css:311-315` (pre-existente) x `painel/funil.js:139-145`

`@media(max-width:980px){.side{display:none}}` e o painel nao tem nenhuma outra navegacao
(nem hamburguer). Abaixo de 980px **nao existe jeito de chegar nas abas Clientes e Funil**.
O `poLigaMover` so entra no modo de toque quando `(hover: none)` casa, o que na pratica e um
celular ou tablet — exatamente onde a aba nao pode ser aberta. O `.fn-menu` "que vem de
baixo, onde o polegar alcanca" so e alcancavel num tablet em paisagem.

Nao e defeito desta branch (a media query e antiga), mas a branch construiu uma afordancia
mobile inteira em cima disso. Ou entra uma navegacao mobile, ou o menu de etapas devia ser
oferecido tambem no desktop (clique simples no card), que e mais acessivel que arrastar de
qualquer forma.

---

## Minor

- **M1.** `painel/app.js:258-259` chamam `poFechaFicha()` sem guarda, enquanto o handler de
  navegacao (`app.js:135-136`) usa `typeof ... === 'function'`. Se o `clientes.js` nao
  carregar, todo clique no scrim lanca `ReferenceError` (depois de fechar o drawer, entao o
  estrago e pequeno) e o `#fi-close` quebra. Mesma observacao para
  `poRenderClientes`/`poRenderFunil` nos quatro handlers de filtro (`app.js:142-145`).
- **M2.** `painel/funil.js:185`: o botao do menu de etapas remove `.on` do scrim
  incondicionalmente. Hoje e inalcancavel com outro painel aberto (o scrim cobre a sidebar,
  z-index 60 x 20, entao nao da para trocar de aba com a ficha aberta), mas e armadilha para
  a proxima tela que compartilhar o scrim. Usar o padrao condicional do `poFechaFicha`.
- **M3.** `painel/funil.js:200-203`: o IIFE depende de o `<script>` estar depois do `#scrim`
  no DOM. Esta certo hoje (scripts no fim do body), e o `if (scrim)` engole a falha em
  silencio se alguem mover para o `<head>` ou adicionar `defer`. Um `console.warn` no else
  custa nada.
- **M4.** `painel/clientes.js:124` acessa `#cli-kpis` sem a guarda que `:109` aplica ao
  `#cli-rows`.
- **M5.** `painel/conversa.js:60`: `direcao` selecionado e nunca usado (item 10 da lista).
- **M6.** `painel/clientes.js:107,135`: `poPessoas()` roda duas vezes ao abrir a ficha (item
  5). Irrelevante com 11 leads; vira O(2n) por clique quando o importador de contatos trouxer
  milhares.
- **M7.** `tests/run.php:19-21`: `$cod` x `$code` (item 1). Sao variaveis diferentes de
  proposito; renomear para `$codNode` deixa isso obvio.
- **M8.** Os tres arneses `.mjs` reimplementam `esc()`, `brl()` e `fmtData()` em vez de usar
  os reais. Uma regressao no `esc()` do `app.js` nao seria pega por nenhum deles — e ele e a
  unica defesa contra o nome vindo do WhatsApp.
- **M9.** Nenhum teste olha a forma das consultas ao Supabase, e foi exatamente por ai que o
  C1 passou pelas seis revisoes. Um teste de string sobre o fonte
  (`assert.ok(src.includes(".eq('wa_id'"))`) e feio, custa tres linhas, nao toca a rede e
  teria barrado o commit. Vale a pena para as consultas em que o filtro **e** a regra.
- **M10.** Desvio da spec 7.1: a ficha deveria juntar tambem `po_wa_contatos` ("os dados de
  `po_wa_contatos`, todos os leads ... a conversa ... e as anotacoes"). A implementacao usa
  so `po_leads`. Consequencia direta para o proximo plano: `opt_out_at` e a marca `cliente`
  moram la, e a transmissao em massa precisa dos dois.
- **M11.** Desvio da spec 7.1: a spec diz **quatro abas**, com Clientes sendo "a aba Leads de
  hoje, evoluida". Ficaram **cinco** (Leads foi mantida). O plano confirma isso na linha 594,
  entao e desvio deliberado — mas fica registrado que Leads e Clientes agora mostram os
  mesmos dados com dois recortes, e a spec terminara desatualizada.
- **M12.** `poChavePessoa` poe `55` em qualquer numero de 10-11 digitos, incluindo
  estrangeiro. Faz parte do I4.

---

## Triagem dos 10 itens ja conhecidos

| # | Item | Veredicto |
|---|---|---|
| 1 | `$cod` x `$code` no `run.php:19` | **Pode ficar.** Variaveis distintas; renomear para `$codNode` num commit de limpeza. (M7) |
| 2 | `typeof l !== 'object'` deixa passar `Array`/`Date` | **Pode ficar, definitivamente.** `poAgrupaPessoas` so recebe `LEADS`/`filtered()`, e todo elemento vem de `mapRow`, que devolve objeto literal. A guarda ja e cinto e suspensorio; endurecer mais seria defesa contra cenario que nao existe. |
| 3 | "Maria" + "Maria (nao atende)" resulta no mais longo | **Manter, concordo.** A decisao esta certa para o caso diario e o comentario no codigo explica o porque. |
| 4 | Telefone repetido funde duas pessoas | **Pode ficar.** Consequencia declarada da spec 7.1. Vale registrar no codigo que a agencia parceira e o caso conhecido, para ninguem "consertar" depois. |
| 5 | `poPessoas()` recalcula duas vezes | **Pode ficar.** Divida, nao defeito; revisitar no importador de contatos. (M6) |
| 6 | `'__proto__'` quebraria `poAgrupaFunil` | **Pode ficar.** Status vem de lista fechada. Se quiser fechar de graca: `const cols = Object.create(null)` em `funil.js:27` — uma palavra, zero risco. |
| 7 | Notebook hibrido cai no modo desktop | **Pode ficar.** O modo desktop e o fallback certo num notebook (o mouse funciona). O problema real e o inverso e esta no I8: no celular a aba nem abre. |
| 8 | `dblclick` convive com `draggable` | **Pode ficar.** Micro-movimento inicia um arrasto que, sem drop, nao faz nada; o `dblclick` continua chegando. Sem consequencia observavel. |
| 9 | `poMovendo` sem `try/finally` | **Pode ficar.** Confirmei o padrao: o `PostgrestBuilder` do supabase-js v2 devolve `{error}` e nao rejeita com `throwOnError` desligado, que e o caso de todo o `app.js`. Se um dia o projeto ligar `throwOnError`, esta linha trava o card para sempre — vale o comentario. |
| 10 | `direcao` selecionado e nunca usado | **Corrigir agora.** Sai de graca junto com o C1, que obriga a mexer nessa mesma consulta. |

---

## Recomendacoes

1. **Corrigir C1 antes de qualquer coisa** e, no mesmo commit, tirar o `direcao` (item 10) e
   adicionar o teste de forma da consulta (M9). Sem o teste, o mesmo erro volta na proxima
   vez que alguem mexer na ordenacao.
2. **I2 e I3 sao de uma linha cada** e moram nos arquivos que ja vao ser tocados. Entram
   juntos.
3. **I1 exige uma decisao de produto** (qual regra ganha: "valor preenchido conclui" ou "o
   funil manda"). Nao mergear sem escolher — e o unico caminho da branch que perde dado do
   banco de forma silenciosa.
4. **I4 vale um commit proprio antes dos dois proximos planos.** Portar `wa_e164()` para JS
   custa ~40 linhas e uma bateria de testes que ja existe, e impede que a transmissao em
   massa nasca com duas definicoes de "mesma pessoa".
5. **I5 e I6 sao a mesma conversa:** `filtered()` foi reusada nas tres telas porque era
   conveniente, mas cada aba quer um recorte diferente. Vale quebrar em `filtered()`,
   `filtradosFunil()` e `filtradasPessoas()` agora, enquanto sao tres linhas.
6. **Sobre regressao no painel que ja existia:** nao encontrei nenhuma. As abas Leads,
   Relatorios e Roteiros, a gaveta de edicao, os filtros, o cadastro manual e o importador
   seguem intactos; a unica mudanca de comportamento no que ja existia e a do KPI de
   faturamento, que e correcao, nao regressao. O `mapRow` novo le `wa_id` de um `select('*')`,
   entao nao ha risco de 400 mesmo se a coluna sumisse.

---

## Avaliacao

**Pronto para merge?** Nao — com correcoes.

**Raciocinio:** a arquitetura esta certa (funcoes puras testaveis, tres arquivos separados,
zero dependencia nova, escapamento consistente, scrim compartilhado sem estado preso), mas o
ultimo commit removeu o `.eq('wa_id')` da conversa e transformou uma feature de leitura de
dialogo num vazamento de conversa entre clientes. Com C1, I1, I2 e I3 corrigidos — quatro
edicoes pequenas nos arquivos que ja estao abertos — a branch entra com seguranca.
