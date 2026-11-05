# O robô entende a frase do cliente · e o roteiro semanal

> Desenho de 04/11/2026. Seis ajustes pedidos pelo cliente, que se separam em
> dois subsistemas independentes (Parte A e Parte B).
>
> **Revisado por engenheiro independente contra o código.** A primeira versão
> deste documento tinha dois erros graves: punha a leitura da frase num lugar
> por onde a frase não passa, e mandava construir uma função que já existe.
> Ambos corrigidos abaixo, e registrados nos achados porque explicam o desenho.

## Intenção e o porquê

Hoje o robô do WhatsApp só entende três coisas: **clique em botão**, **número da
lista** e **nome digitado num card de roteiros**. Quem digita uma frase em
português num card de botões cai no "não entendi" e, se insistir, na despedida.

O público da casa tem 60+ e **digita**. Ele chega pelo Instagram, já viu um reels,
e escreve a frase inteira: *"olá, vi que vocês fazem viagens para a Itália, teria
alguma data para essa viagem?"*. Hoje essa pessoa recebe um menu de botões que não
responde nada do que ela perguntou.

A intenção é que **o robô leia a frase e responda o que foi perguntado**, e que um
roteiro que sai toda semana (o Peru) deixe de ser impossível de cadastrar.

## Objetivos

- Quem pede atendente/pessoa/humano na primeira mensagem vai direto para a Laís.
- Quem nomeia um roteiro à venda na frase recebe o PDF daquele roteiro.
- Quem nomeia um destino que a casa vende mas não está à venda hoje ouve que
  ainda não há data **para aquele destino, pelo nome** e é convidado à lista de
  espera.
- Quem recusa a lista de espera recebe uma despedida própria, não a de quem
  ficou calado.
- Um roteiro com saídas semanais pode ser cadastrado com suas datas do mês,
  editáveis pela cliente, e aparece como tal no site e no robô.
- **A Laís vê quem está esperando o quê, e transmite para o público de um
  destino específico.** Quando a Itália 2028 abre, a mensagem vai só para quem
  pediu a Itália.

## Não-objetivos

- **Não é IA.** Nada de modelo de linguagem no caminho da conversa. O
  reconhecimento é determinístico e testável.
- **Não é conversa livre.** O robô continua sendo um grafo de cards.
- **Não liga nada.** Entra inerte, como o resto do subsistema: os dois fluxos
  seguem `ativo = false` e o app segue não inscrito no webhook da WABA. São duas
  travas independentes e nenhuma é tocada aqui.

## Anti-objetivos (o que tornaria isto um fracasso mesmo funcionando)

- **Robô que adivinha.** Mandar o PDF do Vietnã para quem perguntou da Itália é
  pior que "não entendi": queima o único contato que a pessoa ia fazer. Toda
  regra aqui é conservadora: na dúvida, não escolhe.
- **Cliente-alvo descartado.** Quem pergunta de um destino que a casa vende não
  pode sair sem a oferta da lista de espera.
- **Dois lugares escrevendo a mesma regra.** Vale para a lista de status (três
  arquivos que andam juntos) e, principalmente, para o casamento de roteiro:
  ver achado 5.
- **Dado capturado que ninguém consegue ler.** Ver achado 6.

---

## Os achados que mudam o desenho

Seis coisas apareceram na leitura do código. As três primeiras confirmam o que o
cliente pediu; as três últimas mudam o que dá para prometer.

### 1. Link clicável já funciona, e já está ligado

O cliente pediu para verificar. Permite, e o projeto já faz: `wa_send_text` manda
`preview_url => true`. O app **transforma URL em link automaticamente**, sem
template e sem botão. A URL precisa começar com `http://` ou `https://`, e se
houver mais de uma **só a primeira ganha o cartão de prévia** (as outras seguem
clicáveis). Regra de copy: **nenhuma mensagem pode depender da prévia para fazer
sentido**, porque quem desenha é o aparelho do cliente.

### 2. O item 1 é exatamente como o cliente descreveu

O botão "Não, obrigado" do card "Lista de espera?" aponta para o card **"Despedida
de quem não responde"**. O card de repetição faz o mesmo.

### 3. "Outra data para o mesmo roteiro" não existe hoje, por construção

O slug é a **identidade estável da viagem**, e um índice único parcial **proíbe
dois roteiros visíveis no mesmo slug**. Edição nova herda a URL e a autoridade da
anterior. Logo, nunca há duas datas da mesma viagem à venda ao mesmo tempo.

- **(a)** O robô responde "ainda não temos outra data" e oferece a lista de
  espera. Honesto, colapsa o item 4 no item 5, custa só o texto.
- **(b)** Permitir várias edições à venda por slug: derruba o índice único e
  obriga a decidir qual edição a URL `/roteiros/<slug>` serve, qual vai no
  canonical e no sitemap. Mexe em SEO já indexado.

**Este documento assume (a)**, com a exceção que resolve o caso que motivou o
pedido: roteiro **semanal** tem outras datas e as informa (Parte B).

### 4. A frase do cliente não chega onde eu tinha posto a leitura dela

Este era o erro central da primeira versão. Dois bloqueios, os dois deliberados:

- **Conversa nova:** `wa_processar` resolve a porta e chama `wa_fluxo_percorre`
  **direto no card inicial**. O texto da primeira mensagem é lido só para
  escolher o fluxo, nunca para rotear. A primeira frase, que é justamente a do
  cliente que chega do Instagram, não passa por nenhum roteamento.
- **Card de botões:** o motor faz `$digita = (tipo === 'roteiros')` e
  `$resposta = ($clique || $digita) ? $texto : ''`. Em card `botoes` ou `lista`,
  **texto digitado vira string vazia** antes do roteamento. O comentário no
  código diz por quê, e a razão é boa: *"se 'turquia' valesse como slug, quem
  escreve em vez de tocar na lista escolheria um roteiro sem querer"*.

Consequência: os atalhos não podem ser só uma regra dentro do roteamento. Ver
A.1, que foi reescrita por causa disto.

### 5. O casamento de roteiro por frase já está construído, testado, e desligado

`wa_match_roteiros` (`lib/wa-roteiro.php`) faz **exatamente** o que o item 3
pede: normaliza a frase, quebra em palavras, descarta as de menos de 4
caracteres, descarta as genéricas de viagem (`WA_VAZIAS`: `roteiro`, `viagem`,
`tesouros`, `caminhos`, `encantos`, `floracao`...), compara **palavra inteira**
com tolerância de plural, e consulta um mapa de sinônimos (`WA_APELIDOS`). Tem
20 testes em `tests/test-wa-roteiro.php`, incluindo os casos venenosos: `asia`
não casa dentro de `asiaticos`, `cama` não casa dentro de `atacama`, `caminho`
não casa dentro de `caminhos`.

E **não tem nenhum chamador em produção**: é resíduo do motor anterior ao grafo.

A primeira versão deste documento especificava essa função do zero, inclusive a
lista de genéricas e o mapa de sinônimos, sob outros nomes. Isso teria criado o
anti-objetivo que o próprio documento declara. **A.2 passa a ser "ligar e
estender o que existe", não "construir".**

### 6. A lista de espera é write-only, e está vazia

O card "Anota o destino na espera" grava em `po_leads.espera`, e **nenhuma tela
do painel lê essa coluna** — nem a aba Leads, nem relatórios, nem o filtro de
transmissão. Além disso, `wa_fluxo_campos_marca` trata `+destino` e `+roteiro`
pelo mesmo caminho e grava `ctx.roteiro_slug`: um atalho por destino não tem
slug, então gravaria **nada, em silêncio** — justamente o dado que justifica o
item 5.

**Medido em 04/11/2026: `espera` está vazia nos 797 leads.** Nenhuma linha usa a
forma atual, então trocá-la não custa migração de dado nenhuma. Isto é o que
libera a decisão da Parte C: a lista de espera vira **tabela própria**, não array
dentro do lead.

No mesmo censo: **só 22 leads têm `wa_id`**, que são os mesmos 22 celulares que o
plano 3 mediu. A lista de espera é justamente o mecanismo que enche esse número
com gente que **pediu** para ser avisada.

### 7. A transmissão manda exatamente um parâmetro

`wa_camp_envia` chama `wa_send_template(..., [$nome], ...)`, e o `campanha.php`
recusa criar campanha cujo template não tenha exatamente uma variável — com
razão, porque número errado de parâmetros faz a Graph devolver 132000 e a
campanha inteira virar `falha`, que é **terminal**.

O encanamento, porém, já é genérico: `wa_send_template` recebe um **array** de
parâmetros. O que está preso em um é a chamada e a conferência. Ver C.3.

---

# Parte A · O robô entende a frase

Subsistema do motor de conversa. O item 4 fica completo só quando a Parte B
existir.

## A.1 Atalhos: onde a frase é lida

Cada card que espera resposta ganha em `config.atalhos` uma lista ordenada. Três
tipos, lista fechada:

```json
{"tipo":"palavras","palavras":["atendente","humano","pessoa"],"destino":"<card_id>","saudacao":""}
{"tipo":"roteiro","destino":"<card_id>","saudacao":""}
{"tipo":"destino","destino":"<card_id>","saudacao":""}
```

Por causa do achado 4, eles são avaliados em **dois pontos**, não um:

**Ponto 1, conversa nova.** Depois de a porta escolher o fluxo e antes de
executar o card inicial, o motor avalia os atalhos **do card inicial** contra o
texto da primeira mensagem. Se um casar, a conversa **começa no destino dele**.
Se nenhum casar, começa no card inicial, como hoje.

**Ponto 2, card que espera resposta.** O casamento de opção continua recebendo
`$resposta` exatamente como hoje — a supressão de texto digitado em card de
botões **não é relaxada**, porque a razão dela segue válida. O **texto cru** passa
a viajar num parâmetro separado, e os atalhos são tentados **só depois** de as
opções falharem, antes do `fallback`. Opção e atalho nunca disputam a mesma
string, que é o que impede "turquia" de virar escolha de lista por acidente.

**O campo `saudacao`:** quando um atalho dispara no **card inicial**, a pessoa
pularia a saudação e receberia um PDF como primeira mensagem da vida. O texto do
card de boas-vindas também não serve, porque termina perguntando *"Como podemos
ajudar você hoje?"* e seria seguido do PDF. Então o atalho carrega uma saudação
curta, escrita pela cliente, enviada antes do salto. Vazia = salta calado. No
ponto 2 ela é ignorada (já houve saudação).

**Por que atalho dentro do card e não card novo:** a pergunta *"o que essa frase
quer?"* não é um passo da conversa, é uma forma de responder ao passo que já
existe. Como card novo, cada card que espera precisaria de um satélite e o grafo
dobraria sem ganhar mensagem nenhuma.

**Ordem recomendada no card de boas-vindas:** `palavras` (humano), depois
`roteiro`, depois `destino`. Pedido explícito por uma pessoa ganha de palpite;
roteiro à venda é resposta melhor que "não temos data". A ordem é dado, então a
cliente muda.

### Validação: os atalhos são arestas do grafo

Isto não é detalhe, é o que decide se a cliente consegue publicar. O validador
(`wa_fluxo_valida`) percorre o grafo por `opcoes`/`proximo`/`fallback`/
`sem_resposta`. Sem contar os atalhos:

- O card novo "Sem data para este destino" é alcançável **só por atalho**, então
  a regra de inalcançável e a de beco sem saída o reprovam, e **o fluxo desta
  spec não publica**.
- A regra que exige "existe roteiro escolhido antes do card de PDF" reprova o
  atalho `roteiro` apontando para o PDF, porque ela só reconhece como "escolheu"
  o `proximo` de um card `roteiros`.

Então: **aresta de atalho conta na travessia**, e **atalho do tipo `roteiro`
conta como "escolheu um roteiro"** — os tipos `palavras` e `destino` não contam,
senão o PDF sairia sem ter o que mandar.

O CLAUDE.md diz que não se acrescenta regra ao validador sem decisão. As regras
novas são as cinco de forma do atalho (tipo fora da lista; destino inexistente;
`palavras` vazia; palavra com menos de 4 caracteres normalizados; mais de um
atalho `roteiro` ou `destino` no mesmo card), todas **bloqueio de publicação**.

## A.2 Reconhecer um roteiro: ligar o que existe

`wa_match_roteiros` já faz o trabalho (achado 5). O que falta:

1. **Ligar** ao atalho `roteiro`, que é seu primeiro chamador em produção.
2. **Só responder quando exatamente um roteiro casa.** A função já devolve a
   lista de todos os que casaram, e já existe teste provando que uma frase com
   dois destinos devolve dois. A regra nova é do chamador: lista com um elemento
   responde, com dois ou mais devolve `null` e a conversa vai ao menu. Melhor
   perguntar que errar.
3. **Mover `WA_APELIDOS` para o banco**, como `po_roteiros.palavras_chave`,
   editável no painel. Hoje é constante em código com uma entrada
   (`noruega → escandinavia`), e sinônimo novo exige deploy por FTP. Isto não é
   capricho: o catálogo tem o **"Tesouros do Oriente"**, que não nomeia país
   nenhum — sem sinônimo, ninguém que escreva "coreia", "japão" ou "dubai"
   alcança esse roteiro, e são exatamente as palavras que a pessoa digita.
4. **`WA_VAZIAS` fica em código.** É vocabulário genérico de viagem, não dado do
   negócio: não muda quando o catálogo muda.

**Limitação aceita:** negação não é entendida. *"Não quero a Itália"* é lido como
interesse. Resolver exige análise de linguagem que este desenho recusa de
propósito; o custo é baixo (a Laís vê a conversa) e o risco do contrário é alto.

## A.3 A lista de destinos, e onde o nome é gravado

Para o robô dizer *"ainda não temos data para a Itália"* ele precisa reconhecer
"Itália" como destino da casa. Essa lista não existe hoje: o banco só tem o que
está cadastrado agora.

**De onde ela vem:** da pasta de reels do Drive, o material de divulgação de tudo
que a agência já vendeu. Dos 53 arquivos, deduplicando cópias e versões, saem
**23 destinos**:

Grécia · Peru (Machu Picchu) · Vietnã · Itália · Turquia · Índia · Chile ·
Japão · Coreia do Sul · Dubai · Doha · China · Egito · Marrocos · África ·
Austrália · Malta · Lapônia (aurora boreal) · Capitais Imperiais · Tesouros
Asiáticos · Roma · Tailândia · Escandinávia

Mais o que o histórico do site registra: Mercados de Natal, Floração das
Cerejeiras, Mediterrâneo, Nepal, Atacama, Paris.

**Tabela `po_destinos`:** `id`, `nome` (o que o robô escreve de volta, "Itália"),
`palavras` (as formas que o cliente digita: `italia, roma, veneza, toscana`),
`ativo`, `ordem`. RLS no porte de `po_roteiros`. Em tabela e não em constante
porque a lista cresce todo ano e, em código, cada destino novo viraria deploy.

**As duas entradas ambíguas do Drive foram resolvidas pelo cliente em 04/11:**
`AFRICA 4K` é **África do Sul**; `INDIROMA003` é **Índia com uma parada especial
em Roma** (entra como Índia e como Roma, porque o cliente pode escrever
qualquer um dos dois). O cliente confirmou a lista e avisará se faltar algum.

**Onde o nome é gravado** (achado 6): chave nova `destino_nome` no contexto da
conversa, e campo novo na whitelist de campos marcáveis. A whitelist é a trava
estrutural do subsistema, então isto é mudança consciente, não acidental: hoje
`+destino` grava o slug do roteiro, que num atalho por destino é nulo. O destino
de fato é gravado na tabela da Parte C, não em `po_leads.espera`.

## A.4 A variável `{destino}`

`WA_FLUXO_VARS` é lista fechada `['roteiro','data']`, e o motor **apaga** qualquer
outra chave entre chaves, com o validador bloqueando a publicação de quem
escrever uma. Ou seja: *"ainda não temos data para a **Itália**"* é impossível
hoje.

**Decisão: acrescentar `{destino}`**, com seu teto de caracteres, sua entrada na
análise de pior caso, na regra que exige que a variável só apareça depois de ela
existir, na ajuda do painel e no simulador. O custo é real, e a alternativa é o
texto genérico "para esse destino" — que desperdiça justamente o trabalho de
reconhecer o nome. Reconhecer a Itália e não dizer "Itália" entrega metade do
valor pelo preço inteiro.

## A.5 Os cards novos, e quem os cria

| Card | Tipo | O que muda |
|---|---|---|
| Despedida de quem recusou a lista | `fim` | Novo. O "Não, obrigado" dos **dois** cards de lista de espera passa a apontar para cá. Leva link de `/roteiros`. |
| Sem data para este destino | `botoes` | Novo. Destino do atalho `destino`. Usa `{destino}`. Pergunta da lista de espera; o "sim" vai para o fluxo de espera que já existe. |
| Atendimento humano | `humano` | Já existe. Ganha o atalho `palavras` apontando para ele. |
| Manda o PDF | `pdf` | Já existe. Ganha o atalho `roteiro` apontando para ele. |

Para roteiro **não semanal**, o item 4 cai no card "Sem data para este destino"
(não há outra data, por construção). Para **semanal**, o card de PDF inclui as
datas do mês (Parte B).

**Quem cria isto é uma migration de versão 3 do fluxo**, não a cliente. Os textos
ela escreve na aba Fluxo, mas cards novos, religação de dois botões e listas de
atalhos são **estrutura**. O precedente é a v2: retrato jsonb imutável, derivação
dos cards do rascunho, e **conferência por hash canônico** entre o arquivo e o
que foi gravado, que é ritual obrigatório da casa. É uma tarefa grande e de
risco, e precisa estar no plano como tal.

**Atenção herdada da v2:** semear versão nova **sobrescreve** os cards do
rascunho. Se a cliente tiver editado textos antes do deploy, eles se perdem.
Conferir com ela antes de rodar.

---

# Parte B · Roteiro semanal e suas datas

Subsistema do painel e do site. Não toca o motor, exceto por duas funções de
leitura. Pode ser construído e publicado antes da Parte A.

## B.1 O status `semanal`

Valor novo em `po_roteiros.status_viagem`. Rótulo: **"Saídas semanais"**.

**A lista vive em três lugares que têm que andar juntos**, mais o check do banco:
`PO_STATUS_VIAGEM` (`lib/po-view.php`), `STATUS_VIAGEM`
(`assets/js/roteiros-shared.js`) e `STATUS_VIAGEM` (`painel/app.js`). O teste
existente exige a mesma lista, na mesma ordem, nos três, mais cor e ícone no
`status-tag.css` e no `painel.css`. (`llms.php` herda o rótulo do PHP, então não
é um quarto lugar.)

**Ordem:** `semanal` entra **primeiro**. As outras quatro estão em ordem de ciclo
da viagem; semanal não tem ciclo, é condição permanente, então abre a lista em
vez de se intrometer no meio dela.

**Cor e ícone:** azul da marca (`#1FA8DD`), ícone de calendário repetido. Não
reusa nenhuma das quatro cores, porque a tag é o único sinal visual e duas
condições na mesma cor é pior que nenhuma tag.

**A tag aparece em TODO lugar que mostra roteiro** — regra já registrada do
cliente. O CSS é um arquivo só, então o valor novo chega a todos de uma vez.

**Não entra na lista de exclusão do robô.** O motor tira do catálogo quem está
`esgotado` ou `em_viagem`. Semanal está sempre à venda, e o teste precisa travar
isso: um roteiro que sai toda semana sumir da lista seria o pior defeito desta
parte.

**JSON-LD:** cai em `InStock` sozinho, sem mexer em nada (a página só trata
`esgotado` e `ultimas_vagas`).

## B.2 As datas: `po_roteiros.datas_texto`

Coluna `jsonb`. Lista de grupos, cada um com título e datas:

```json
[
  {"titulo":"Datas de Dezembro","datas":["02/12/26","10/12/26","18/12/26","26/12/26"]},
  {"titulo":"Datas de Janeiro","datas":["07/01/27","15/01/27"]}
]
```

**Por que agrupado e não linhas soltas:** o robô precisa distinguir cabeçalho de
data. Com linhas soltas, *"Datas de Dezembro:"* e *"02/12/26"* são a mesma coisa
para o código, e a Parte B deixa de poder ordenar o roteiro ou responder o item
4. A estrutura é o que torna o dado legível por máquina; a cliente só vê campos.

**No painel**, na gaveta do roteiro, visível **só quando o status é semanal**:
por grupo, um campo de título e uma lista de campos de data, cada um com botão de
excluir, mais "adicionar data" e "adicionar mês". É o que o cliente pediu.

**Teto de caracteres:** o card de PDF tem legenda limitada a 1024 caracteres, e a
análise de pior caso do validador precisa de um máximo declarado para as datas.
Sem isso, uma cliente que cadastre quatro meses faz a Meta recusar a mensagem
inteira, em silêncio. Declarar o máximo e cortar na origem.

## B.3 Onde "próxima saída" aparece, e a armadilha da ordenação

O par `data_label ?: periodo` é impresso em oito lugares: hero e "outros
roteiros" da página do roteiro, cards de `/roteiros`, carrossel da home, lista
"Próximas viagens", `/link`, card do painel e `llms.txt`. Mais um nono que não é
card: a **lista numerada que o robô manda**, que imprime `"1. Título (periodo)"`.

**Decisão:** para roteiro semanal, os renderizadores derivam **"Próxima saída:
<primeira data futura>"** de `datas_texto`, caindo em `periodo` quando não houver
data futura. Uma função em PHP e uma em JS, com teste cruzado como o de status faz
hoje. A alternativa — a cliente preencher `periodo` à mão além das datas — cria
dois campos que divergem no primeiro mês em que alguém esquecer de atualizar um.

**A armadilha:** a ordenação por data que entrou em 03/11 lê `periodo`, e roteiro
sem data **vai para o fim da lista** — que é onde ficam as viagens que já
partiram. Um semanal não tem data em `periodo`, então o Peru entraria no fim para
sempre, saindo na semana que vem. A data de ordenação dele é a primeira data
futura de `datas_texto`; sem nenhuma, volta a regra atual.

---

# Parte C · A lista de espera vira público de transmissão

Pedida pelo cliente em 04/11, e é a parte de maior valor comercial do pedido
inteiro: *"ali na transmissão a gente pode colocar um seletor pra escolher qual
país de lista de espera existem, e assim puxa os números de whatsapp de todos
aqueles leads que estão na espera, assim ela pode transmitir exatamente para o
público que pediu por aquele roteiro."*

Isto transforma a lista de espera de registro morto em **canal de venda
segmentado**: quando a Itália 2028 abre, a transmissão vai só para quem pediu a
Itália. Depende da Parte A para ter o que ler, e deve entrar junto com ela ou
imediatamente depois — sem a Parte C, a Parte A captura dado que ninguém lê
(o anti-objetivo declarado).

## C.1 A tabela `po_wa_espera`

Tabela própria, não array em `po_leads.espera`. O achado 6 liberou a escolha
(zero linhas hoje), e três coisas que a Parte C precisa fazer são ruins em jsonb:
filtrar por destino, saber **quando** a pessoa pediu, e remover **uma** entrada
sem fazer read-modify-write sobre um array compartilhado.

Campos: `id`, `lead_id` (fk `po_leads`), `destino` (a chave, casa com
`po_destinos`), `roteiro_slug` (nulo, preenchido quando a pessoa esperava data
nova de um roteiro específico), `criado_at`, `removido_at`.

**Remoção é soft delete.** As três regras do dono valem aqui: *o registro com
histórico nunca é sobrescrito*, *a fusão só soma, nunca zera*, *nunca duplicar*.
Tirar alguém da espera não pode apagar que ela um dia pediu. E **índice único
parcial em `(lead_id, destino)` onde `removido_at is null`**: pedir a Itália duas
vezes não cria duas linhas, nem erra a contagem do público.

`po_leads.espera` **para de ser escrita** e fica morta. Não é derrubada aqui:
`drop column` é destrutivo, o portão do MCP recusa, e quem roda destrutivo é o
dono no SQL Editor. Fica registrada como dívida de uma linha.

## C.2 A aba "Lista de espera" no painel

Lista **agrupada por destino**, cada grupo com a contagem, e dentro dele as
pessoas: nome, telefone, quando pediu, e qual roteiro quando houver. Por linha,
remover. Por grupo, **"transmitir para este destino"**, que leva para a
Transmissão já com o destino escolhido.

Agrupar por destino e não por pessoa porque a pergunta que a Laís faz é *"quem
está esperando a Itália?"*, não *"o que o João está esperando?"* — e é a primeira
pergunta que o seletor da transmissão também faz. Uma tela, duas leituras do
mesmo recorte.

## C.3 O seletor na Transmissão

O seletor lista **só os destinos que têm alguém esperando**, com a contagem ao
lado. Não os 23 do catálogo: destino com zero pessoas é escolha que produz
campanha vazia.

O público passa a ser "quem tem entrada ativa de espera naquele destino", e as
regras estabelecidas do subsistema continuam **todas** valendo:

- **Cadeado por ficha E por número.** A Meta cobra por número e a base tem ficha
  repetida da mesma pessoa.
- **Quem pediu SAIR é excluído por NÚMERO, não por ficha.** Importa mais aqui:
  quem entra pela espera pode ter ficha sem `wa_id`.
- **"Não é cliente" marcado na Revisão continua excluindo.**
- **Reserve antes de enviar, tome posse antes de chamar a Meta.** `enviando`
  conta como pendente. `falha` é terminal e fica no topo da escada.
- **Teto diário de 250**, o piso da Meta para número novo.

**Entra sem passar pela Revisão**, pelo mesmo motivo que "perdeu por data" entra:
o contato veio da própria pessoa pedindo para ser avisada, não de agenda
importada. SAIR, fixo e "não é cliente" seguem valendo.

## C.4 O template com duas variáveis

Para a mensagem dizer *"Oi {{1}}, abriu data para a {{2}}"*, o template precisa
de **duas** variáveis. Hoje `wa_camp_envia` passa `[$nome]` e o `campanha.php`
exige exatamente uma (achado 7).

**A conferência deixa de ser "exatamente uma" e passa a ser "exatamente tantas
quantas a campanha vai fornecer".** Isso é mais seguro que hoje, não menos: hoje
o número está cravado numa constante e o contrato real fica implícito; depois, os
dois lados são conferidos um contra o outro antes de a campanha existir. Número
errado faz a Graph devolver 132000 e **a base inteira virar `falha` terminal**,
então a conferência acontece no `criar`, nunca no envio.

**A ORDEM dos parâmetros vai travada por teste.** Já houve o acidente
documentado no próprio código: parâmetro na posição errada produziu *"Olá
Mercados de Natal"* no lugar do nome da pessoa.

**Dependência externa com prazo:** o template novo tem de ser **criado e aprovado
pela Meta**, e modelo não se transfere entre contas. A aprovação leva dias e é o
que separa "pronto" de "pode disparar". Começar cedo, pela aba Modelos, que já
submete direto.

---

## Decisões que tomei, para o cliente conferir

Qualquer uma pode ser revertida sem redesenhar nada:

1. **Item 4 vira "ainda não temos outra data" + lista de espera**, salvo para
   semanal. A alternativa mexia em SEO já indexado (achado 3).
2. **Atalho que dispara no card inicial manda uma saudação curta antes de
   saltar**, escrita pela cliente, porque senão a primeira mensagem da vida da
   pessoa seria um PDF.
3. **Humano ganha de roteiro e de destino** na ordem dos atalhos.
4. **A variável `{destino}` passa a existir**, com o custo que ela traz.
5. **`WA_APELIDOS` migra para coluna no banco**; `WA_VAZIAS` fica em código.
6. **O card "sem data para este destino" é separado** do "Lista de espera?" que
   já existe, porque o texto de abertura é diferente.
7. **`datas_texto` agrupado por mês**, não linhas soltas.
8. **`semanal` abre a lista de status**, no azul da marca.
9. **"Próxima saída" é derivada das datas**, não um segundo campo à mão.
10. **Destinos em tabela**, não em constante.

11. **A lista de espera é tabela própria**, não array em `po_leads.espera`, e a
    remoção é soft delete.
12. **O seletor da transmissão lista só destino com gente esperando**, não os 23.
13. **A conferência de variáveis do template passa a ser "tantas quantas a
    campanha fornece"**, em vez de "exatamente uma".

## Respondido pelo cliente em 04/11/2026

1. **Item 4: saída (a)** — o robô diz que ainda não há outra data e oferece a
   lista de espera. O modelo de dados de slug estável fica intacto.
2. **`AFRICA 4K` é África do Sul.**
3. **`INDIROMA003` é Índia com uma parada especial em Roma.** Entra reconhecendo
   os dois nomes.
4. **A lista de 23 destinos está confirmada.** Se faltar, o cliente avisa.
5. **A tela da lista de espera entra**, e com ela a segmentação da transmissão
   por destino (Parte C). Era a maior lacuna do pedido original.
6. **Os textos entram fictícios**, como exemplo, para a Laís reescrever na aba
   Fluxo depois. Nenhum texto deste desenho é definitivo.

## O que fica para quem construir

- Se a edição dos destinos merece aba própria ou cabe dentro da aba Fluxo. A
  tabela e o seed são obrigatórios; a tela é julgamento.
- O máximo declarado de caracteres das datas na legenda do PDF.
- Como o grupo de datas aparece no card do `/link`, onde o espaço é estreito.
- TDD, revisão por tarefa e rodada de mutação, como o resto do projeto.

## Ordem sugerida

Três partes, nesta ordem:

1. **Parte B (semanal).** Menor, autocontida, não toca o motor, e entrega o dado
   de que a Parte A precisa para responder o item 4 por completo em vez de
   provisoriamente.
2. **Parte A (o robô entende a frase).** Onde está a dor.
3. **Parte C (lista de espera e transmissão segmentada).** Lê o que a Parte A
   escreve. Junto com A ou imediatamente depois: A sem C captura dado que ninguém
   lê, que é anti-objetivo declarado.

**Começar o template de duas variáveis já na Parte A**, mesmo sem a Parte C
pronta: a aprovação da Meta leva dias e é a única dependência deste trabalho que
não depende de nós.

Pelo tamanho, isto são **três planos de implementação**, um por parte, cada um
entregando software que funciona e se testa por conta própria.
