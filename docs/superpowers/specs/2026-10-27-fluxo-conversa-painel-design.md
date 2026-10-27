# Fluxo de conversa administrável no painel

**Data:** 27/10/2026
**Cliente:** Pereira Oliveira Consultoria em Turismo e Viagens LTDA
**Quem usa:** Laís (atendimento), pelo painel, no computador e no celular
**Origem:** reunião de 26/10/2026 com a cliente, revisando todos os textos do robô
**Rascunho visual aprovado:** https://claude.ai/artifact/GpReoxR53ki3MgqbqRZaGG

---

## 1. Intenção, e por que agora

O motor de conversa do WhatsApp existe, está publicado e está inerte. Ele funciona
como uma máquina de estados escrita à mão em `lib/wa-motor.php`: os estados
(`novo`, `aguardando_roteiro`, `enviado_roteiro`, `qualificado`, `desqualificado`,
`encerrado`, `humano`) e as transições entre eles são código. A aba "Textos do robô"
deixa a cliente trocar **o que o robô fala** em sete pontos fixos, e nada mais. Para
mudar **por onde o robô passa** é preciso um deploy.

Na reunião de 26/10 a cliente revisou os textos e o que apareceu não foi correção de
frase, foi caminho que não existe:

- O fluxo de hoje **não tem boas-vindas**. Ele pressupõe que a pessoa já quer um
  roteiro específico, o que é verdade só para quem vem de anúncio. Quem escreve
  "boa tarde" cai num fluxo que já está perguntando data.
- Falta triagem entre quem quer **um roteiro** e quem quer conhecer **a programação**.
- Falta **lista de espera** para destino que não está programado naquele ano
  (o exemplo dela foi Chile em 2027).
- Falta caminho para quem **não tem flexibilidade de data**: reconhecer a
  dificuldade, guardar o contato, e perguntar se é só aquele destino ou a
  programação toda.
- Falta **marcador no funil por roteiro de interesse**, que é o que permite a
  transmissão sair para um grupo pequeno e certo em vez da base inteira.
- Falta tratar quem está **comparando dois roteiros** (Grécia e Itália, no exemplo).
- Falta **lembrete dentro da janela de 24h** para quem não respondeu e para quem
  recebeu o menu e não escolheu nada.

Nenhuma dessas coisas é um texto novo. São caminhos novos. E a cada reunião vão
aparecer outros, porque o negócio muda de roteiro todo ano. O pedido do cliente,
nas palavras dele, foi: **"adicionar um novo fluxo, editar um fluxo, isso tem que
ser bem administrável"**, e **"tem que ser bem simples de usar"**.

Então a intenção é esta: **tirar o desenho da conversa do código e colocá-lo no
painel**, num formato que a Laís consiga ler, mudar e estender sozinha, sem mim e
sem deploy. Referência que o cliente deu: ManyChat e BotConversa.

### A tensão central, e como ela se resolve

"Simples de usar" e "bem administrável" brigam. Um editor genérico de fluxo é
poderoso e é também uma máquina de produzir conversa quebrada.

O que resolve a briga é **o cliente responder por botão, não por texto livre**.
Decisão do cliente, nas palavras dele: *"se der pra colocar botão eu acho melhor,
o botão não abre margem pra erro de digitação ou querer mandar algum texto que não
condiza com as perguntas"*.

Com botão, a resposta volta para o servidor como um identificador, não como frase.
Cada saída de um card é um destino inequívoco. Isso é o que torna seguro deixar a
Laís desenhar: ela não precisa prever como alguém escreve "não sei ainda", porque
ninguém escreve nada. O robô deixa de adivinhar.

O preço, que o desenho precisa pagar explicitamente: **o botão não impede a pessoa
de digitar ou mandar áudio por cima dele**, e o público de 60+ faz isso muito. O
robô não transcreve áudio e não vai transcrever. Logo, **todo card que espera
resposta tem uma saída obrigatória "respondeu outra coisa"**, e é por ela que entra
quem mandou áudio, quem escreveu fora das opções, e quem mandou foto.

---

## 2. Objetivos

1. A Laís vê a conversa inteira desenhada, em cards ligados por setas, e entende
   qual caminho o cliente percorre conforme o que ele responde.
2. A Laís edita o texto de um card no próprio card.
3. A Laís **cria um fluxo novo** e **edita um fluxo existente**, sem deploy.
4. Nenhum caminho termina sem resposta. Sempre existe um fim, uma volta, ou a
   passagem para atendimento humano.
5. O painel **recusa publicar** um fluxo com ponta solta, e diz qual é.
6. O fluxo cobre o conteúdo que a reunião pediu: boas-vindas com triagem,
   programação do ano e do seguinte, lista de espera, marcador de interesse no
   funil, caminho para quem a data não serve, qualificação depois do PDF, e
   lembrete dentro da janela de 24h.
7. A Laís consegue **simular** o fluxo antes de publicar, sem mandar mensagem para
   ninguém e sem gastar.
8. Nada que o motor já garante hoje pode ser enfraquecido por um fluxo desenhado
   no painel.

## 3. Não-objetivos

- **Sem IA e sem interpretação de linguagem natural.** O robô não vai entender
  frase livre. Os cards de botão substituem o `wa_sim_nao` / `wa_oracoes` /
  `wa_assunto_oracao` de hoje no caminho principal.
- **Sem transcrição de áudio.** Áudio cai na saída "respondeu outra coisa".
- **Sem editor de canvas no celular.** Abaixo de 980px a tela mostra a mesma
  informação como lista, com as ligações escritas, e deixa editar o texto do card.
  Arrastar card por toque exigiria biblioteca de front-end, que o projeto não
  carrega. Precedente: o kanban do Funil fez exatamente isso.
- **Sem edição simultânea por duas pessoas.** Uma pessoa edita o painel.
- **Sem teste A/B de fluxo.**
- **Não reescreve as abas Transmissão nem Modelos.** Template aprovado pela Meta
  continua sendo assunto da aba Modelos. O fluxo opera dentro da janela de 24h. A
  aba Transmissão ganha **um filtro** de público por interesse (§6.12), e nada além
  disso.
- **Não mexe em `po_leads.venda`, `venda_at`, `notas`, nem em `status='venda'`.**

## 4. Anti-objetivos

Coisas que, se acontecerem, fazem este trabalho ter falhado mesmo que a tela
funcione:

1. A Laís conseguir publicar um fluxo que deixa um cliente sem resposta.
2. Um fluxo conseguir escrever em `venda`, `venda_at` ou `notas`, ou carimbar
   `status='venda'`. Isso apagaria faturamento, ROAS e ciclo de venda em silêncio.
3. Um fluxo conseguir destravar o robô num contato onde o eco já disparou. Robô e
   a dona falando por cima uma da outra é o pior modo de falha deste sistema, e a
   regra existente é que **o eco silencia o robô para sempre naquele contato**.
4. A tela validar diferente do motor. Tela que aprova o que o motor recusa faz a
   cliente caçar erro que não existe, e vice-versa.
5. Uma edição em fluxo publicado quebrar conversa que já está em andamento.
6. Um fluxo mandar mensagem fora da janela de 24h sem template aprovado, e o
   cliente não receber nada, em silêncio.

---

## 5. Restrições que já existem e continuam valendo

Estas não são escolhas deste projeto. São o terreno.

### Plataforma
- Front-end do painel: HTML/CSS/JS puro, **sem build e sem biblioteca de front-end**.
- Back-end: PHP 8 em cPanel na ereHost. Deploy **manual por FTP**, arquivo a arquivo.
- Banco: Supabase (projeto compartilhado com NOX/hd360), com RLS. O painel lê
  autenticado com `anon key`; o PHP escreve com `service_role`, que só existe no
  servidor.
- Testes: `tests/run.php`, `.php` com `ok($cond,$msg)` + `exit(1)`, `.mjs` com
  `node:assert/strict`. Hoje são **61 arquivos**. **Nenhum teste toca a rede**: usar
  os transportes injetáveis que já existem (`wa_motor_set_deps`, `wa_db_set_transport`,
  `wa_set_transport`, `wa_timeout_set_deps`).
- Revisão por **teste de mutação** é o método do projeto.
- **Cache-buster obrigatório:** o `.htaccess` cacheia JS/CSS por um mês. Todo arquivo
  novo ou alterado em `painel/` entra em `painel/assets-lock.json` e tem o `?v=`
  subido em `painel/index.html`. `tests/test-cache-buster.mjs` amarra o `?v=` ao SHA
  do conteúdo normalizado. No FTP, **os JS sobem antes do `index.html`**.
- O repositório é **público**: nenhum dado real de cliente em fixture, comentário
  ou migration.

### WhatsApp Cloud API
- **Botões de resposta: no máximo 3 por mensagem, rótulo de no máximo 20 caracteres.**
- **Lista: no máximo 10 linhas**, título da linha até 24 caracteres, descrição até 72.
- Corpo de mensagem interativa e legenda de documento: **1024 caracteres**. Texto
  simples: **4096**. `wa_send_text` e `wa_send_document` **não truncam**: acima do
  limite a Meta devolve 400 e o cliente não recebe nada, em silêncio.
- A Graph API **recusa lista com zero linhas**. Catálogo vazio precisa cair em texto.
- **Janela de 24h:** fora dela só sai template aprovado pela Meta. Template é por
  WABA e **não se transfere entre contas**.
- O webhook **já normaliza `button_reply`** (`lib/wa-webhook.php`, `wa_texto_msg`
  devolve o `id` do botão e `wa_tipo_msg` devolve `button_reply`). O lado de entrada
  está pronto. O que falta é o lado de saída.
- `WA_GRAPH` está em `v26.0` e a validade é travada por `tests/test-wa-graph.php`.

### Leis do motor, que ficam ACIMA do fluxo
Um card desenhado no painel não pode desligar nenhuma destas. Elas vivem em
`wa_processar` e continuam lá:

1. **O eco silencia o robô para sempre** naquele contato, inclusive quando o eco é
   atalho ou PDF.
2. **SAIR vem antes de tudo**, carimba sempre, responde só se não estiver silenciado.
3. **Idempotência por `wamid`**, gravando antes de processar. `duplicado` pula;
   `falha` processa assim mesmo (perder mensagem de cliente é pior que duplicar).
   **Evento de `status` não passa por essa idempotência**, porque o `wamid` de um
   status é o da mensagem original e `po_wa_mensagens.wamid` é `unique`.
4. **O estado só avança se o envio saiu** (`ok=true`, que garante `wamid`). Senão o
   lead fica "aguardando resposta" de mensagem que nunca chegou.
5. **`humano` nunca volta para o robô.**
6. Qualquer mensagem do cliente reseta `lembrete_at` **e** `aguardando_desde` juntos.
7. O webhook responde **200 sempre**, inclusive em erro interno.
8. **Segredo vazio ou com menos de 16 caracteres nunca autoriza.**
9. `po_config()` não expõe segredo. Segredo vem de `wa_config()`.

### Estado da conexão com a Meta
A convivência está estabelecida (26/10/2026). `WA_PHONE_ID = 444498328748420`,
WABA real `1150774113972883`. **Três coisas ainda bloqueiam envio e não são deste
projeto:** forma de pagamento na conta (erro 141006, `can_send_message: BLOCKED`),
`subscribed_apps` vazio (o webhook não está inscrito, então nenhuma mensagem de
cliente chega), e `message_templates` vazio. Inscrever o webhook **liga o robô para
os clientes reais** e é decisão da cliente.

Consequência prática para este projeto: **ele pode ser construído e testado inteiro
sem a Meta**, pelo simulador e pelos transportes injetáveis. O go-live é separado.

---

## 6. O desenho

### 6.1 O que é um fluxo

Um **fluxo** é uma conversa inteira, com uma **porta de entrada** própria. Essa é a
unidade que a Laís cria e edita. Criar um fluxo novo não mexe em nenhum dos que já
estão rodando.

Portas de entrada (lista fechada):

| Porta | Quando dispara |
|---|---|
| `mensagem_nova` | primeira mensagem de um contato que não está em conversa |
| `anuncio` | mensagem com `referral.source_id` que casa com `po_wa_anuncios` |
| `palavra` | a mensagem do cliente contém uma das palavras configuradas |
| `chamado` | nunca dispara sozinho; outro fluxo manda para ele |

**Duas portas iguais não podem ficar ativas ao mesmo tempo.** O painel recusa
publicar e diz qual fluxo já ocupa a porta. Precedente direto: o índice parcial
`po_roteiros_slug_ativo_uniq` e a mensagem de `rotSaveErro`.

A ordem de resolução quando mais de uma porta poderia casar é fixa e não
configurável: `anuncio` > `palavra` > `mensagem_nova`. Anúncio é o sinal mais forte
porque já traz o roteiro.

### 6.2 O que é um card

Um card faz até três coisas: **manda algo**, **espera algo**, e **escreve no funil**.
Os tipos são uma lista fechada:

| Tipo | Manda | Espera | Saídas |
|---|---|---|---|
| `texto` | texto | não | 1 (`proximo`) |
| `botoes` | texto + até 3 botões | sim | 1 por botão + `fallback` + `sem_resposta` |
| `lista` | texto + até 10 linhas | sim | 1 por linha, ou 1 única (`escolheu`) quando a fonte é dinâmica, + `fallback` + `sem_resposta` |
| `pdf` | o PDF do roteiro escolhido | não | 1 (`proximo`) |
| `marca` | nada | não | 1 (`proximo`) |
| `ir_para_fluxo` | nada | não | o card inicial do fluxo destino |
| `humano` | texto opcional | terminal | nenhuma |
| `fim` | texto opcional | terminal | nenhuma |

A lista de tipos **vive em três lugares que têm que andar juntos**: `WA_CARD_TIPOS`
(PHP), `WA_CARD_TIPOS` (`painel/fluxo.js`), e um teste que confere PHP contra JS.
É o mesmo porte de `PO_STATUS_VIAGEM` / `poStatusTag` / `STATUS_VIAGEM`, e o teste
é o mesmo porte de `tests/test-status-tag.mjs`. Valor fora da lista não executa.

**O card `lista` com fonte dinâmica** lê os roteiros do banco pelo
`po_fetch_roteiros()` que já existe, e aceita um filtro de **ano**. O ano sai de
`po_ano($r)` (`lib/po-data.php`), que já deriva o ano do `data_label` em texto
livre. **Não é preciso coluna nova em `po_roteiros` para atender o pedido de
"campos para 2027 e 2028"**: é um filtro na fonte do card. Roteiro novo cadastrado
no painel entra na lista sozinho, sem tocar no fluxo.

**O card `marca`** é o que resolve o pedido de marcador de interesse e de lista de
espera. Ele escreve em `po_leads` e os campos que pode escrever são uma **whitelist
fechada**, `WA_FLUXO_CAMPOS_MARCAVEIS`:

```
status (só 'novo' | 'atendimento' | 'perdido')
qualif_data, qualif_grupo, qualif_at
interesses   (jsonb, lista de slugs de roteiro)
espera       (jsonb, lista de destinos que a pessoa quer e não estão programados)
```

**`venda`, `venda_at`, `notas` NÃO estão na whitelist, e `status` não aceita
`'venda'` nem `'negociacao'`.** Esta é a trava estrutural do anti-objetivo 2. A
escrita leva **exatamente** as chaves da whitelist, nada mais. É o mesmo porte de
`WA_IMPORT_CAMPOS_PREENCHIVEIS`, e pela mesma razão: lá, se `status` ou `venda`
entrassem no `preenche`, uma importação de agenda apagaria o funil.

**`cliente` e `revisado` também ficam fora da whitelist**, e isso é deliberado por
uma razão diferente: são as duas colunas que decidem quem recebe disparo pago.
Um fluxo desenhado no painel nunca se autoriza a entrar numa campanha. Quem decide
elegibilidade é a campanha, não o fluxo (§6.12).

### 6.2.1 O contexto da conversa

Alguns cards precisam saber de que roteiro se está falando: o `pdf` manda o arquivo
daquele roteiro, o `marca` grava o slug em `interesses`, e o texto que usa `{roteiro}`
ou `{data}` precisa do roteiro para se completar.

O contexto é **uma coisa só: `po_wa_conversas.roteiro_slug`**, que já existe. Não se
cria saco de variáveis genérico. Quem o escreve:

- o card `lista` com fonte dinâmica, ao receber a escolha (o `id` da linha é o slug);
- a porta `anuncio`, quando o anúncio está mapeado em `po_wa_anuncios` (§6.11).

`wa_fluxo_proximo($card, $resposta, $ctx)` recebe o contexto e continua **pura**:
devolve o próximo card e o contexto atualizado, sem tocar em banco nem em rede.

**Card `pdf` alcançável sem escolha anterior é beco sem saída**, e a validação recusa
(regra 13). Em tempo de execução, roteiro sem `pdf_url` cai no link em texto, que é o
caminho que `wa_envia_roteiro` já tem hoje.

### 6.2.2 Variáveis no texto do card

O texto do card **interpola variáveis**, como os sete textos de hoje já fazem. A lista
é fechada e depende do tipo de card, em `WA_FLUXO_VARS`:

| Variável | De onde sai | Disponível em |
|---|---|---|
| `{nome}` | `po_leads.nome`, ou o nome do perfil do WhatsApp | todo card |
| `{roteiro}` | título do roteiro do contexto | só onde o contexto tem slug |
| `{data}` | `data_label` **ou** `periodo` **ou** "na data prevista" | só onde o contexto tem slug |

**O `{data}` de hoje sai vazio, e isso é um defeito em produção que este projeto
corrige.** `wa_envia_roteiro` faz `$r['data_label'] ?? 'na data prevista'`, mas `??`
não pega string vazia, e **nenhum dos roteiros do banco tem `data_label` preenchido**:
todos usam `periodo`. Então a pergunta "a viagem sai em {data}, você tem
disponibilidade nessa data?" chega ao cliente sem data. A resolução passa a ser
`data_label ?: periodo ?: 'na data prevista'`, com `?:` e não `??`, e tem teste.

Variável fora da lista, ou mal escrita (`{Nome}`, `{ nome }`, `{nome2}`), é recusada na
publicação. A regra é a de `poTextoValida` em `painel/textos.js`, que casa
`/\{[^}]*\}/g` de propósito mais largo do que o motor consome: ela **migra para
`wa_fluxo_valida`** quando o `textos.js` sair, e não se perde.

O **limite de caracteres é conferido depois da expansão de pior caso**, não no texto
cru: cada variável é contada pelo maior valor plausível (60 para `{roteiro}`, 40 para
`{nome}`, 40 para `{data}`). Conferir o texto cru deixaria passar uma frase que estoura
1024 só quando o título do roteiro é comprido, e aí a Meta devolve 400 e o cliente não
recebe nada, em silêncio.

### 6.2.3 Quem está comparando dois roteiros

A reunião trouxe o caso de quem compara dois destinos (Grécia e Itália, no exemplo).
Duas coisas resolvem isso sem card novo:

1. O card `marca` **acrescenta** a `interesses`, nunca substitui. Dois roteiros vistos
   viram dois interesses.
2. O card "A data serve?" nasce com um **terceiro botão, "Ver outro roteiro"**, que
   volta para "Qual roteiro?". São três botões, no limite da Cloud API.

### 6.3 Rascunho e publicado: por que são separados

A Laís edita enquanto o robô atende. Se a edição valesse na hora, uma conversa
parada num card recém-apagado quebraria no meio.

- As **tabelas de edição** (`po_wa_fluxos`, `po_wa_cards`) são o rascunho. O motor
  nunca as lê.
- **Publicar** valida o grafo e grava um **retrato imutável** em
  `po_wa_fluxo_versoes.grafo` (jsonb), com número de versão.
- O motor lê **só o retrato**, e `po_wa_conversas.fluxo_versao_id` registra em qual
  versão aquela conversa está rodando. **Conversa em andamento termina na versão em
  que começou.** Conversa nova pega a versão mais recente.

Isso dá, de graça: uma leitura por conversa em vez de montar o grafo a cada
mensagem, cache simples, trilha de auditoria, e volta atrás para a versão anterior
(que é só apontar `versao_atual` para o retrato de antes, sem reeditar nada).

**Publicar e ligar são dois atos separados**, e o botão é um em cada lugar:

- **Publicar** valida, grava o retrato e aponta `versao_atual` para ele. O fluxo
  continua com `ativo = false` se estava desligado.
- **Ligar** põe `ativo = true`, e é aí que o índice único da porta de entrada morde.

A razão de não ser um clique só: a porta `mensagem_nova` pega **todo contato novo**.
Publicar-igual-a-ligar significa que um clique redireciona o atendimento de todos os
contatos novos no mesmo instante, sem ensaio. A postura defensiva do resto do projeto
é a mesma (`campanha.php` recusa drenar enquanto o número não está conectado,
`WA_PHONE_ID` vazio mantém a transmissão inerte). Fluxo publicado e desligado é um
estado legítimo e é o estado em que o fluxo semeado nasce.

A ordem de ligar o fluxo semeado é, portanto: publicar e ligar a **Lista de espera**
primeiro (porta `chamado`, não dispara sozinha), depois publicar e ligar as
**Boas-vindas**, que é quem aponta para ela pela regra 11.

### 6.4 Lembrete e fim

Mantém a máquina de dois relógios que já existe (`lib/wa-timeout.php`): lembrete,
e depois encerramento (`WA_HORAS_ENCERRA = 24` contadas **depois** do lembrete). O
que muda:

- O **texto do lembrete sai do card**, não de `po_wa_textos`. Cada card que espera
  tem `lembrete_ativo` (padrão ligado) e `lembrete_texto`.
- A varredura passa a selecionar por `estado = 'aguardando'` em vez da lista de
  estados antigos, e lê `card_atual` para saber qual lembrete mandar.
- **`WA_HORAS_LEMBRETE` cai de 24 para 20 horas.** Com 24, o lembrete sai em cima ou
  depois da borda da janela de 24h, e o cron roda de hora em hora: fora da janela a
  Meta recusa e o cliente não recebe nada, em silêncio, que é o anti-objetivo 6. Vinte
  horas dão quatro de folga para um cron horário. Isto é o mecanismo do anti-objetivo
  6, e tem teste.
- Por cima disso, o interpretador **recusa enviar fora da janela de 24h** (medida de
  `aguardando_desde`) e, nesse caso, encerra em vez de tentar. Mensagem que não sai
  nunca avança estado, pela lei 4.

**`sem_resposta` é alternativa ao ENCERRAMENTO, não ao lembrete.** A sequência é
sempre: 20h sem resposta manda o lembrete (se `lembrete_ativo`); 24h depois do
lembrete, ou o fluxo encerra, ou segue pelo `sem_resposta` se o card tiver um.

E porque quem executa esse passo é o **cron, sem ninguém olhando**, o `sem_resposta`
só pode apontar para card de tipo `texto`, `marca`, `humano` ou `fim` (regra 12).
Nunca `lista`, `botoes`, `pdf` nem `ir_para_fluxo`: o cron mandando lista e PDF de
madrugada é superfície de falha que ninguém pediu, e um card que espera, alcançado
por falta de resposta, só reinicia o relógio.

**O carimbo de `po_leads.status = 'perdido'` acontece nos dois caminhos**, a não ser
que um card `marca` no caminho diga outra coisa. Isto não é detalhe: `perdido` junto
com `qualif_data = false` é exatamente o público "perdeu por data" que a cliente pediu
em 23/10 (`wa_camp_perdeu_data`, `lib/wa-camp.php`). Deixar de carimbar esvaziaria essa
audiência em silêncio.

**`lembrete_at` só é carimbado se o envio saiu**, como hoje. E um card que espera
precisa de `lembrete_ativo` **ou** de um `sem_resposta`: sem nenhum dos dois, a
conversa morre em silêncio, e o painel recusa publicar (regra 2).

A contagem de tentativas do `fallback` ("repete as opções uma vez, depois chama a
Laís") mora em `po_wa_conversas.tentativas` e **zera a cada troca de card**, o que a
torna na prática uma contagem por card sem precisar de linha por card.

### 6.5 Compatibilidade com o resto do painel

`po_wa_conversas.estado` **continua existindo** e continua sendo escrito. Quem
realmente o lê hoje é só `lib/wa-timeout.php`: as abas Funil, Clientes e Relatórios
leem `po_leads.status`, não este campo. Mesmo assim vale manter o espelho, porque é a
chave da varredura e porque a aba Conversa o mostra. O interpretador o mantém como
espelho do card atual:

| Card atual | `estado` |
|---|---|
| card que espera | `aguardando` |
| card `humano` | `humano` |
| card `fim`, ou fim do grafo | `encerrado` |

Os estados antigos (`novo`, `aguardando_roteiro`, `enviado_roteiro`, `qualificado`,
`desqualificado`) deixam de ser escritos. **Migration tem que converter as linhas
existentes**, senão a varredura de timeout para de enxergá-las.

`semresposta` continua sendo o `DEFAULT` de `po_leads.status` e continua caindo em
"Contato feito" no Funil. Nada disso muda.

### 6.6 O que acontece com os 7 textos de hoje

A aba **"Textos do robô" vira "Fluxo do robô"**. Os sete textos (`menu`,
`envio_pdf`, `perguntas`, `qualificado`, `sem_data`, `lembrete`, `lembrete_menu`)
viram o `texto` dos cards do fluxo semeado pela migration, para que nada que a
cliente escreveu se perca.

`po_wa_textos` **não é apagada**. O motor para de lê-la. Precedente: a linha
`saudacao` já é resíduo que o motor nunca lê, e a decisão registrada foi não apagar.

`painel/textos.js` é **removido** do `index.html` e do `assets-lock.json`, junto com
`tests/test-textos.mjs`. **O que não se perde na remoção:** a validação de variável de
`poTextoValida` e os limites de `poTextoLimite` migram para `wa_fluxo_valida` (§6.2.2),
onde passam a valer para todo card e não só para sete chaves.

### 6.7 Envio: o que falta no código

`lib/wa-send.php` tem `wa_send_text`, `wa_send_document`, `wa_send_list` e
`wa_send_template`. **Não tem envio de botão.** Entra:

```php
wa_send_buttons($para, $corpo, $botoes)   // $botoes = [['id'=>, 'rotulo'=>], ...]
```

no mesmo porte de `wa_send_list`: `type: interactive`, `interactive.type: button`,
`action.buttons[].reply.{id,title}`.

**Diferença deliberada em relação a `wa_send_list`:** `wa_send_list` trunca o título
da linha com `mb_substr`. `wa_send_buttons` **recusa** rótulo acima de 20 caracteres
em vez de truncar, devolvendo `['ok'=>false,...]`. Truncar um rótulo de botão muda o
que o cliente lê sem mudar o destino: o botão passa a dizer uma coisa e levar a
outra, e ninguém percebe. A validação do painel já impede que isso chegue ao
runtime; a recusa aqui é a trava no ponto de chamada, que é a regra do projeto.

Também recusa: zero botões, mais de 3, rótulo vazio, rótulos repetidos (dois botões
com o mesmo texto são indistinguíveis para quem lê).

### 6.8 Validação: uma função, dois chamadores

`wa_fluxo_valida($grafo)` é **pura**, mora em `lib/wa-fluxo.php`, e devolve a lista
de problemas em português, pronta para a tela.

**O painel não reimplementa nenhuma dessas regras em JavaScript.** Ele chama um
endpoint que roda a mesma função. Precedente exato: `wa_tpl_problemas` e o modo
`conferir` de `templates.php`, com teste de fonte proibindo a reimplementação. A
razão é a mesma: tela que valida diferente do envio aprova o que o motor recusa.

E a validação mora **dentro** de `wa_fluxo_publica()`, não só no endpoint, para não
existir caminho que publique sem ela.

**Bloqueiam a publicação:**

1. Saída de card apontando para card que não existe.
2. Card que espera sem `lembrete_ativo` e sem `sem_resposta`.
3. Card inalcançável a partir do card inicial.
4. Nenhum card terminal (`humano` ou `fim`) alcançável a partir do card inicial.
   **Para fluxo cuja porta não é `chamado`, exige-se especificamente um `humano`
   alcançável**, porque é a saída que o cliente pediu. Fluxo de porta `chamado`
   (a Lista de espera é um) basta terminar: obrigá-lo a ter `humano` encheria todo
   fluxo pequeno de um card que ninguém queria. **A alcançabilidade é avaliada
   dentro de um fluxo**, nunca atravessando `ir_para_fluxo`: `wa_fluxo_valida`
   recebe um fluxo e continua pura.
5. Rótulo de botão vazio, acima de 20 caracteres, ou repetido dentro do mesmo card.
6. Texto acima de 1024 (`botoes`, `lista`, legenda do `pdf`) ou 4096 (`texto`).
7. Card `lista` estática com zero linhas, ou dinâmica sem fonte.
8. Ciclo do qual não se alcança nenhum card terminal.
9. Porta de entrada já ocupada por outro fluxo ativo.
10. Card `marca` tentando escrever campo fora da whitelist.
11. Card `ir_para_fluxo` apontando para fluxo inexistente, não publicado ou desligado.
12. `sem_resposta` apontando para card que não é `texto`, `marca`, `humano` ou `fim`
    (§6.4: quem executa esse passo é o cron).
13. Card `pdf` alcançável por um caminho que não passa por escolha de roteiro, nem
    pela porta `anuncio` mapeada. Sem slug no contexto ele não tem o que mandar.
14. Variável fora de `WA_FLUXO_VARS`, mal escrita, ou indisponível naquele tipo de
    card (`{roteiro}` num card alcançável sem roteiro no contexto).

**Avisam sem bloquear:** card sem nenhuma entrada além dele mesmo; fluxo sem nenhum
card `fim`.

### 6.9 Simulador, no lugar de disparo de teste

O botão "Testar no meu número" do rascunho visual **vira "Simular"**. Decisão minha,
e a razão é a seguinte: a Laís precisa testar **o desenho**, e disparar no WhatsApp
para testar desenho custa mensagem, depende da Meta estar liberada, só funciona se o
número de destino tiver escrito nas últimas 24h, e arrisca sair para o contato
errado. Nada disso compra informação sobre o grafo.

O simulador abre a conversa falsa na própria tela, no formato da terceira prancha do
rascunho, e a Laís clica nos botões. Ele **não manda mensagem para ninguém**, não
grava em `po_wa_conversas` e não mexe no funil.

**O simulador roteia pelo servidor**, chamando a mesma `wa_fluxo_proximo()` que o
motor usa. Não há cópia da lógica de roteamento em JavaScript. Mesma regra da 6.8.

Ensaio de envio de verdade, com um destinatário, **continua onde já está**: a aba
Modelos (`templates.php`, `modo=ensaio`). Não se duplica aqui.

### 6.10 O fluxo semeado

A migration semeia o fluxo **"Boas-vindas e triagem"** com o desenho aprovado no
rascunho visual, **publicado na versão 1 e desligado** (`ativo = false`). Publicado,
para que o Plano A entregue um grafo que o motor consegue ler e o simulador consegue
percorrer. Desligado, porque ligar é o ato que troca o atendimento de todo contato
novo, e isso é clique da cliente depois de ela revisar os textos. Ele contém os cards:

```
Entrada (mensagem nova)
  └─ Boas-vindas [botoes]
       ├─ "Um roteiro específico" → Qual roteiro? [lista dinâmica]
       │                              └─ escolheu → Manda o PDF [pdf]
       │                                              └─ Marca interesse [marca]
       │                                                   └─ A data serve? [botoes]
       │                                                        ├─ "Sim, funciona" → Já viajou em grupo? [botoes]
       │                                                        │                      ├─ "Já viajei"    → A Laís assume [humano]
       │                                                        │                      └─ "Primeira vez" → A Laís assume [humano]
       │                                                        └─ "Não nessa data" → A data não serve [botoes]
       │                                                                                ├─ "Só esse destino"  → Lista de espera [ir_para_fluxo]
       │                                                                                └─ "Ver outras datas" → A programação
       ├─ "Ver a programação" → A programação [lista dinâmica, filtro de ano]
       │                           └─ escolheu → Qual roteiro?
       └─ "Falar com a Laís" → Atendimento humano [humano]

  fallback de todo card que espera → Respondeu outra coisa [botoes]
       (repete as opções uma vez; na segunda, Atendimento humano)
```

E o fluxo **"Lista de espera"** (porta `chamado`): marca o destino em
`po_leads.espera`, responde que avisa quando houver data nova, e termina.

**Os textos do rascunho visual são exemplo, não copy final.** A cliente revisa pelo
painel antes de publicar. Mas os **rótulos de botão do rascunho já respeitam o
limite de 20 caracteres** e podem ser usados como estão.

### 6.11 Quando cada porta de entrada é avaliada

**As portas são resolvidas só quando a conversa não está parada num card que espera.**
Mensagem que chega com a conversa esperando botão vai para o `fallback` daquele card,
que é a saída "respondeu outra coisa".

A razão é a premissa do projeto inteiro: botão existe para a resposta voltar sem
ambiguidade. Se a porta `palavra` valesse em toda mensagem, um "oi, e o cruzeiro?" no
meio da pergunta da data sequestraria o card que estava esperando, e a resposta
deixaria de ser inequívoca. Quem quer trocar de assunto cai no `fallback`, e o
`fallback` leva à Laís na segunda tentativa, que é o lugar certo para um desvio que o
robô não previu.

`estado = 'encerrado'` **conta como não estar em conversa**: a mesma pessoa escrevendo
um mês depois recomeça pela porta, não de onde parou.

A ordem de resolução, quando mais de uma porta casaria, é fixa: `anuncio` > `palavra` >
`mensagem_nova`.

**Porta `anuncio`:** `gatilho_valor` nulo significa **qualquer anúncio**, e é assim que
o fluxo semeado nasce. O roteiro, quando o anúncio está mapeado em `po_wa_anuncios`,
entra no contexto e o fluxo pode pular direto para o PDF; quando não está, o fluxo faz
a triagem normal e pergunta. **Este projeto não cria tela para cadastrar `ad_id`.**
`po_wa_anuncios` está vazia e não tem tela hoje; o caminho sem mapa funciona, e
construir uma tela que ninguém pediu é escopo que não se justifica até existir anúncio
rodando. Fica registrado como a próxima coisa óbvia se o Click-to-WhatsApp voltar.

### 6.12 Transmissão por interesse

Decisão do cliente, 27/10: **entra neste projeto, no Plano C.** O marcador só existe
porque a reunião pediu que a transmissão saísse para um grupo pequeno e certo; deixar
a coluna sem ninguém lendo seria entregar a tela e não o pedido.

O escopo é estreito e é **um filtro, não uma tela nova**: a seleção de público da aba
Transmissão ganha "só quem tem interesse em \<roteiro\>" e "só quem está na espera de
\<destino\>", lendo `po_leads.interesses` e `po_leads.espera`. O resto da aba fica como
está, com o teto diário, o cadeado por ficha e por número, o SAIR e o custo na tela
antes de confirmar.

**Quem é elegível a disparo pago continua sendo decisão da campanha, não do fluxo.** A
trava de hoje (`cliente = true` **e** `revisado = true`) é o que impede disparo pago
para contato de agenda que ninguém conferiu, e `falha` é terminal: um envio errado
queima a ficha para sempre. Ela fica.

A exceção é estreita e tem precedente: **lead com `wa_id` preenchido que passou por um
card `marca`** conta como conferido para fim de campanha. É gente que escreveu para a
empresa e percorreu o fluxo, não entrada de catálogo de telefone. É exatamente o porte
da exceção que a cliente abriu em 23/10 para o "perdeu só pela data", e pela mesma
razão registrada ali: *o lead veio da própria pessoa, não de agenda*.

A exceção mora numa função do lado da campanha, `wa_camp_fluxo_interesse()`, irmã de
`wa_camp_perdeu_data()`. **Não** mora na whitelist do card `marca`: se `cliente` e
`revisado` entrassem lá, um fluxo desenhado no painel passaria a se autorizar a receber
disparo pago, e a trava deixaria de ser trava.

---

## 7. Modelo de dados

Migration nova, idempotente, no padrão do projeto (nunca editar migration que já
rodou). RLS igual à das `po_wa_*`: `authenticated` lê e escreve, o PHP usa
`service_role`.

```sql
create table if not exists public.po_wa_fluxos (
  id             uuid primary key default gen_random_uuid(),
  nome           text not null,
  gatilho        text not null,            -- mensagem_nova | anuncio | palavra | chamado
  gatilho_valor  text,                     -- palavra(s) ou ad_id; null para os demais
  ativo          boolean not null default false,
  card_inicial   uuid,
  versao_atual   uuid,                     -- -> po_wa_fluxo_versoes.id
  created_at     timestamptz not null default now(),
  updated_at     timestamptz not null default now()
);

create table if not exists public.po_wa_cards (
  id                  uuid primary key default gen_random_uuid(),
  fluxo_id            uuid not null references public.po_wa_fluxos(id) on delete cascade,
  tipo                text not null,       -- WA_CARD_TIPOS
  titulo              text not null,
  texto               text,
  opcoes              jsonb not null default '[]'::jsonb,  -- [{rotulo, destino}]
  config              jsonb not null default '{}'::jsonb,  -- por tipo
  proximo             uuid,
  fallback            uuid,
  sem_resposta        uuid,
  lembrete_ativo      boolean not null default true,
  lembrete_texto      text,
  x                   int not null default 0,
  y                   int not null default 0
);

create table if not exists public.po_wa_fluxo_versoes (
  id           uuid primary key default gen_random_uuid(),
  fluxo_id     uuid not null references public.po_wa_fluxos(id) on delete cascade,
  versao       int  not null,
  grafo        jsonb not null,             -- retrato imutável
  publicado_at timestamptz not null default now(),
  unique (fluxo_id, versao)
);

-- uma porta de entrada por vez, entre os fluxos ATIVOS
create unique index if not exists po_wa_fluxos_porta_ativa_uniq
  on public.po_wa_fluxos (gatilho, coalesce(gatilho_valor, ''))
  where ativo;

-- a conversa passa a saber onde está
alter table public.po_wa_conversas add column if not exists fluxo_versao_id uuid;
alter table public.po_wa_conversas add column if not exists card_atual      uuid;
alter table public.po_wa_conversas add column if not exists tentativas      int not null default 0;

-- marcadores de interesse e de espera, no funil que já existe
alter table public.po_leads add column if not exists interesses jsonb not null default '[]'::jsonb;
alter table public.po_leads add column if not exists espera     jsonb not null default '[]'::jsonb;
```

E a conversão dos estados antigos, **na mesma migration**. Hoje ela é cosmética, e
isso foi conferido: `po_wa_conversas` e `po_wa_mensagens` estão **vazias**, porque o
webhook nunca foi inscrito. Não há conversa em andamento para quebrar. Vai na
migration de qualquer forma, porque o motor pode ser ligado antes de a migration
rodar numa ordem de deploy diferente, e uma linha com estado antigo sairia do radar da
varredura:

```sql
update public.po_wa_conversas set estado = 'aguardando'
  where estado in ('aguardando_roteiro','enviado_roteiro');
update public.po_wa_conversas set estado = 'encerrado'
  where estado in ('qualificado','desqualificado','novo');
```

Nota: `po_wa_contatos` está **vazia e não é usada por nenhuma linha de código**
(apurado no plano 4). Não é para ressuscitá-la aqui. O público real mora em
`po_leads`.

---

## 8. Arquivos

### Novos
| Arquivo | Responsabilidade |
|---|---|
| `lib/wa-fluxo.php` | interpretador: `wa_fluxo_grafo`, `wa_fluxo_proximo` (pura), `wa_fluxo_executa`, `wa_fluxo_valida` (pura), `wa_fluxo_publica`, `WA_CARD_TIPOS`, `WA_FLUXO_CAMPOS_MARCAVEIS` |
| `fluxo.php` | endpoint do painel: validar, publicar, simular. Autenticado por `po_auth_ok` |
| `painel/fluxo.js` | canvas, gaveta do card, lista de fluxos, simulador |
| `painel/fluxo.css` | só desta tela; `painel.css` já é o maior arquivo do painel |
| `supabase/migrations/2026-10-27-fluxo-conversa.sql` | tabelas, índice, colunas, conversão de estado |
| `supabase/migrations/2026-10-27-fluxo-semeado.sql` | o fluxo de boas-vindas e o de lista de espera, em rascunho |

### Alterados
| Arquivo | O quê |
|---|---|
| `lib/wa-send.php` | `wa_send_buttons()` |
| `lib/wa-motor.php` | `wa_processar` delega o roteamento ao interpretador; as nove leis ficam. Resolução de porta só fora de card que espera (§6.11) |
| `lib/wa-timeout.php` | varre por `estado='aguardando'`, `WA_HORAS_LEMBRETE` 24 → 20, lembrete sai do card, executa `sem_resposta`, carimba `perdido` |
| `lib/wa-camp.php` | `wa_camp_fluxo_interesse()`, irmã de `wa_camp_perdeu_data()` (§6.12) |
| `campanha.php` | aceita o recorte de público por interesse ou por espera |
| `painel/campanhas.js` | o filtro na seleção de público |
| `painel/index.html` | aba "Fluxo do robô" no lugar de "Textos do robô"; `fluxo.js` e `fluxo.css` com `?v=` |
| `painel/app.js` | roteamento da view nova |
| `painel/assets-lock.json` | `fluxo.js`, `fluxo.css`; sai `textos.js` |

### Removidos
`painel/textos.js`, `tests/test-textos.mjs`.

---

## 9. Testes

Nenhum toca a rede. A suíte vai de 61 para aproximadamente 70 arquivos.

| Arquivo | O que trava |
|---|---|
| `test-wa-fluxo-rota.php` | `wa_fluxo_proximo`: botão, lista estática, lista dinâmica, fallback, `sem_resposta`, `ir_para_fluxo`, tipo fora da lista. E §6.11: mensagem livre com a conversa esperando botão vai para o `fallback`, nunca para outra porta |
| `test-wa-fluxo-valida.php` | as 14 regras que bloqueiam e as 2 que avisam, uma a uma. Inclui o caso que motivou a regra 4: fluxo de porta `chamado` sem `humano` **publica**, fluxo de porta `mensagem_nova` sem `humano` **não** |
| `test-wa-fluxo-marca.php` | **a whitelist**: `venda`, `venda_at`, `notas`, `status='venda'`, `cliente` e `revisado` nunca passam, venha o que vier no card |
| `test-wa-fluxo-vars.php` | `{nome}`, `{roteiro}`, `{data}`; `{data}` cai em `periodo` quando `data_label` é string vazia; limite conferido na expansão de pior caso; `{Nome}` e `{ nome }` recusados |
| `test-wa-camp-interesse.php` | o recorte por interesse e por espera; e que a trava `cliente`+`revisado` segue valendo para quem não passou por card `marca` |
| `test-wa-fluxo-versao.php` | conversa em andamento termina na versão em que começou; publicar não a move |
| `test-wa-fluxo-leis.php` | as nove leis do motor sobrevivem ao interpretador: eco silencia, SAIR antes de tudo, estado só avança com envio, `humano` não volta |
| `test-wa-send-buttons.php` | recusa 0, 4, rótulo vazio, 21 caracteres, rótulos repetidos; payload correto para 1, 2 e 3 botões |
| `test-fluxo-endpoint.php` | `fluxo.php` exige login; valida pela mesma função; **teste de fonte**: `painel/fluxo.js` não reimplementa as regras |
| `test-wa-fluxo-tipos.mjs` | `WA_CARD_TIPOS` igual em PHP e em `painel/fluxo.js`, mesma ordem |
| `test-fluxo-painel.mjs` | gaveta, contador de caracteres, limite de 3 botões, lista de fluxos, recusa de publicar com ponta solta |
| `test-wa-timeout.php` (existente) | `estado='aguardando'`, lembrete vindo do card, lembrete **dentro** da janela de 24h, `sem_resposta` só para os quatro tipos permitidos, e o carimbo de `perdido` nos dois caminhos de encerramento |
| `test-cache-buster.mjs` (existente) | `fluxo.js` e `fluxo.css` travados por SHA |

---

## 10. Ordem do trabalho

Um design, **três planos**, cada um deixando software que roda e que dá para testar.

**Plano A, o motor.** Migration, `lib/wa-fluxo.php`, `wa_send_buttons`, adaptação de
`wa-motor.php` e `wa-timeout.php`, migration do fluxo semeado (publicado na versão 1,
desligado). **Critério de aceitação:** a suíte percorre o fluxo semeado de ponta a
ponta pelo interpretador, todas as saídas e exceções, com os transportes injetáveis;
as nove leis estão travadas por teste; e o motor, sem nenhum fluxo ligado, **não
responde nada** e registra no log. Sem tela nova: nada muda para a cliente ainda, e
nada chega a cliente nenhum, porque o webhook segue não inscrito.

**Plano B, a tela.** `fluxo.php`, `painel/fluxo.js`, `painel/fluxo.css`, a aba, a
gaveta do card, o canvas no desktop e a lista no celular, a validação, o Publicar
travado e o Ligar separado. No fim disto a Laís lê o fluxo desenhado, edita os textos,
publica e liga.

**Plano C, administrar.** Lista de fluxos, criar fluxo, portas de entrada, cards
`marca` e `ir_para_fluxo`, o fluxo de lista de espera, o simulador, e o filtro de
público por interesse na aba Transmissão (§6.12). No fim disto ela cria o fluxo do
cruzeiro sozinha, que é o pedido original, e a transmissão sai para quem se
interessou por um roteiro em vez de para a base inteira.

Nada disso depende da Meta estar liberada. O go-live (forma de pagamento,
`subscribed_apps`, templates) é assunto separado e é decisão da cliente.

---

## 11. O que fica para quem for construir

Decisões que um bom construtor resolve sozinho, e que eu de propósito não fixei:

- O formato exato do jsonb `grafo` no retrato publicado, desde que o interpretador
  seja a única coisa que o leia.
- Como o canvas desenha as setas. A referência de diagramas do projeto diz elementos
  posicionados mais um SVG por segmento, e isso serve, mas a tela é interativa e
  pode querer outra coisa.
- Onde fica o cache do grafo. `po-data.php` já tem cache em disco de 10 minutos em
  `/tmp/po-cache`, e `limpa-cache.php` já sabe limpá-lo com login. Reaproveitar ou
  não é escolha de implementação, mas **publicar um fluxo tem que limpar o cache**,
  pelo mesmo motivo que salvar roteiro limpa.
- O desenho visual da gaveta e do canvas, dentro dos tokens do painel
  (`--azul #1FA8DD`, `--verde #84C440`, Rubik e DM Sans).
- Os valores exatos de pior caso usados na contagem de caracteres da §6.2.2, desde que
  venham dos máximos reais de `po_roteiros.titulo` e `po_leads.nome` e não de chute.

Decisões que **não** ficam em aberto, e a razão de cada uma está no corpo deste
documento: a whitelist do card `marca` (com `cliente` e `revisado` fora dela), a
separação rascunho/publicado com versão por conversa e com Publicar separado de Ligar,
a validação única compartilhada entre tela e motor, a recusa (e não o truncamento) em
`wa_send_buttons`, o lembrete em 20 horas, o `sem_resposta` restrito a quatro tipos de
card porque é o cron que o executa, o carimbo de `perdido` nos dois caminhos de
encerramento, a resolução de porta só fora de card que espera, a elegibilidade de
campanha decidida pela campanha e nunca pelo fluxo, e as nove leis do motor acima do
fluxo.

---

## 12. Registro da revisão

Este documento passou por uma revisão de quem teria de planejar a construção, em
27/10/2026. Ela achou dez pontos. Nove foram decididos e estão incorporados ao corpo
do texto, nas seções indicadas; um foi decidido pelo cliente no mesmo dia (§6.12).
Três dados que a revisão apurou no banco e que mudaram afirmações deste documento:
`po_wa_conversas` e `po_wa_mensagens` estão vazias; nenhum arquivo de `painel/` lê
`po_wa_conversas.estado`; e nenhum roteiro tem `data_label` preenchido, que é a causa
do defeito do `{data}` descrito na §6.2.2.
