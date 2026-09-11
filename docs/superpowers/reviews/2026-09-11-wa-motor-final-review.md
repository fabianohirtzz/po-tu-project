# Revisao final da branch `wa-motor` (5184bc3..04c5166)

15 commits, 19 arquivos, 2.099 linhas novas. Nenhum arquivo existente foi
modificado (so `config.local.example.php`, que e modelo sem segredo), entao o
site que ja esta no ar nao e tocado por este merge. Revisado em tres passadas:
arquivo a arquivo, depois o caminho completo de uma conversa, depois as
costuras entre camadas.

**Veredicto: precisa de correcao.** Tres bloqueadores de merge (C1, C2, I1),
um item que precisa de verificacao antes de apontar a Meta para producao (C3),
e uma lista de divida que vai doer no plano do painel.

Testes: rodei isolados `test-wa-{fone,roteiro,db,send,webhook,motor,timeout}` e
todos passam. Nao rodei `test-wa-schema` de proposito (ele sai para a rede, ver I9).
Nenhuma chamada real de rede foi feita nesta revisao.

---

## Pontos fortes

- **A regra de silencio esta certa e testada dos dois lados.** `wa-motor.php:271`
  silencia mesmo quando o eco tambem disparou uma acao de funil, e os testes 13 e 14
  cobrem exatamente o caso que mais doeria (atalho a partir de `enviado_roteiro` e
  documento a partir de `qualificado`). Esse era o pior modo de falha do produto.
- **"Envio que falha nao avanca o estado"** virou disciplina do arquivo inteiro:
  roteiro (`wa-motor.php:186`), perguntas (`:200`), sem_data (`:310`), qualificado
  (`:320`), menu (`:373`) e lembrete (`wa-timeout.php:75`). E raro ver isso aplicado
  com consistencia.
- **`wa_registra_evento` distingue 409 de falha de infraestrutura** (`wa-webhook.php:198-222`)
  com a justificativa certa escrita no lugar certo: na duvida, atende.
- **`wa_e164` acerta o caso que quebra a maioria das integracoes brasileiras**: o wa_id
  da Meta sem o nono digito (`554896048882`) normaliza para o mesmo E.164 do numero com
  nono digito. Conferido.
- **Nenhum log emite corpo de resposta do Postgres** (`wa-db.php:216`, `:228`,
  `wa-webhook.php:218`), justamente porque `unique_violation` ecoa telefone de cliente.
- **O casamento por token** em vez de `strpos` (`wa-roteiro.php`) e a lista de apelidos
  podada estao entre as decisoes mais bem fundamentadas da branch.
- Os comentarios explicam **por que**, nao o que. Isso vai valer muito no plano seguinte.

---

## Critical

### C1. Assinatura do webhook aceita qualquer coisa enquanto `WA_APP_SECRET` estiver vazio
**Arquivo:** `whatsapp.php:130` (e `:117` para o mesmo defeito no GET)

Hoje `WA_APP_SECRET` e `''` (conferido: `wa_config()` devolve len 0 para as cinco
chaves de WhatsApp). Com segredo vazio o HMAC e calculavel por qualquer um:

```
php -r 'require "lib/wa-webhook.php";
  $c="{\"entry\":[]}";
  var_dump(wa_verifica_assinatura($c, "sha256=".hash_hmac("sha256",$c,""), ""));'
=> bool(true)
```

Quem souber a URL posta evento forjado e o `wa_processar` grava, com a
**service_role**, em `po_leads`, `po_wa_conversas` e `po_wa_mensagens`. A
`SUPABASE_SERVICE_KEY` ja esta preenchida em producao (len 219), entao o estrago
nao depende de a conta da Meta sair da verificacao: basta o `whatsapp.php` subir
por FTP. Depois, com `WA_TOKEN` preenchido, o forjador passa a conseguir fazer o
robo **enviar mensagem no numero da agencia**, que e caminho curto para ban.

E exatamente o defeito que a Task 8 ja corrigiu no `wa-cron.php`
(`WA_CRON_KEY_MIN`, `wa-timeout.php:36-43`): segredo ausente e ausencia de
permissao, nunca permissao. A correcao nao foi aplicada aqui.

**Correcao:** antes do `hash_equals`, recusar quando o segredo configurado for
vazio ou curto demais, no mesmo porte de `wa_cron_autorizado`. Vale para o
`WA_APP_SECRET` no POST e para o `WA_VERIFY_TOKEN` no GET (`:117`: hoje um GET
com `hub_verify_token` vazio devolve o `hub_challenge`). O ideal e extrair a
mesma funcao pura testavel, que ja existe conceitualmente em `wa-timeout.php:36`.

**Ate isso existir, nao subir o `whatsapp.php` para o docroot.**

### C2. Uma resposta so decide duas perguntas, e "nunca viajei em grupo" marca o lead como perdido
**Arquivo:** `lib/wa-motor.php:302-336` (com `wa_resposta_sim`, `:129-135`)

As duas perguntas saem numa mensagem so, e a resposta do cliente passa por um
unico `wa_resposta_sim()` que devolve um veredicto plano. A lista de "nao"
(`:132`) inclui `nunca`. Medido:

```
"Tenho disponibilidade sim, mas nunca viajei em grupo"          => false
"Tenho a data sim. Nunca viajei em grupo, seria a primeira vez" => false
"1 sim 2 nao"                                                   => false
"Posso sim, e nao, nunca viajei em grupo"                       => false
```

Todos esses sao **leads bons**: tem a data. O robo responde o texto `sem_data`
("aviso quando abrirmos novas datas"), grava `estado=desqualificado`,
`status=perdido` e `qualif_data=false`. Para uma operadora que vende viagem em
grupo para 60+, "nunca viajei em grupo" e uma das respostas mais provaveis que
existe, e ela nao desqualifica nada. A spec (secao 5) diz "nao na data ->
DESQUALIFICADO": quem desqualifica e a pergunta 1, nao a 2.

O espelho tambem existe: um "sim" solto carimba `qualif_grupo => true`
(`:328`) sem nenhuma evidencia sobre a pergunta 2, entao o dado que o painel vai
exibir e inventado.

**Correcao (minima):** so a pergunta 1 decide. Ou separar as perguntas em dois
turnos, ou avaliar apenas o trecho da resposta que fala de data, ou (mais barato
e mais seguro) tratar resposta mista como `null` (fica para a humana) em vez de
deixar o "nao" ganhar. E parar de gravar `qualif_grupo` quando nao se sabe.

### C3. Eco pode ser eco do proprio robo (verificar antes do go-live)
**Arquivo:** `lib/wa-webhook.php:253-267` + `lib/wa-motor.php:249-277`

Nada no codigo distingue "ela digitou no celular" de "nos enviamos pela Cloud
API": o eco e identificado so pelo array `message_echoes`. Os `wamid` das
mensagens que o robo envia **nunca sao gravados** (`wa_envia` devolve o wamid e
ninguem o persiste; `po_wa_mensagens` so recebe entrada e eco).

Se, na configuracao de coexistencia que a agencia vai usar, o
`smb_message_echoes` tambem ecoar o que sai pela API, entao o primeiro PDF que o
robo enviar volta como eco, cai em `wa_processar` como handoff e **silencia o
robo naquele contato para sempre**. Em toda conversa. O produto nasce morto e o
sintoma parece "o robo so responde uma vez".

Nao da para confirmar isso sem a conta da Meta, e por isso nao estou chamando de
bug confirmado. Mas a guarda e barata e resolve tambem o I7: gravar em
`po_wa_mensagens` o wamid de cada envio (`direcao=out`, `autor=robo`) e ignorar
eco cujo wamid ja esteja registrado como nosso.

**Acao:** guarda implementada antes do go-live, e um teste manual de uma
mensagem real confirmando o comportamento do eco.

---

## Important

### I1. `lembrete_at` nao e zerado quando o menu e reenviado: conversa viva e encerrada como perdida
**Arquivo:** `lib/wa-motor.php:377` (compare com `:205-210`, que zera)

`wa_envia_roteiro` grava `'lembrete_at' => null` ao mudar de estado. O ramo do
menu grava so `estado` e `aguardando_desde`. E `wa_varre_timeouts`
(`wa-timeout.php:61-86`) decide pelo `lembrete_at` primeiro e **ignora o
`aguardando_desde` depois que o lembrete existe**.

Reproduzido: conversa com `lembrete_at` de ontem, cliente escreve hoje, o menu
sai de novo, `lembrete_at` continua o de ontem. Na proxima varredura que passar
das 24h do lembrete, a conversa vira `encerrado` e o lead vira `perdido`, com o
cliente tendo falado minutos antes.

**Correcao:** `'lembrete_at' => null` no `wa_conversa_set` do menu. Uma linha. E,
por seguranca, considerar zerar o lembrete em qualquer mensagem recebida do
cliente, que e o significado real de "ele voltou a falar".

### I2. O menu se repete em toda mensagem nao reconhecida
**Arquivo:** `lib/wa-motor.php:362-378`

Estando em `aguardando_roteiro`, qualquer mensagem que nao identifique roteiro
cai de novo no envio do menu. Medido:

```
"oi boa tarde"               => menu
"quanto custa?"              => menu
"e voces tem parcelamento?"  => menu
"obrigada"                   => menu
```

Quatro listas interativas em sequencia. O estado `aguardando_roteiro` existe
justamente para saber que o menu ja foi mandado, mas ninguem o consulta. Com o
publico da agencia isso le como robo travado.

**Correcao:** mandar o menu uma vez; a partir dai, se o cliente continuar sem
escolher, parar de responder (ou entregar para a humana). O estado ja distingue
os dois momentos.

### I3. O texto do lembrete afirma um roteiro que pode nunca ter sido enviado
**Arquivo:** `lib/wa-timeout.php:49-68` + migration linha 112

A varredura pega `enviado_roteiro` **e** `aguardando_roteiro`, e manda o mesmo
texto: "So passando para saber se voce chegou a ver o roteiro que enviei". Quem
esta em `aguardando_roteiro` recebeu um menu, nao um roteiro. O robo afirma ter
mandado algo que nao mandou, para um cliente real.

**Correcao:** um texto por estado (`lembrete` e `lembrete_menu`), ou uma frase
neutra que sirva aos dois.

### I4. Audio, imagem e figurinha viram silencio, e depois viram "perdido"
**Arquivo:** `lib/wa-webhook.php:300-311` + `lib/wa-motor.php:333-335`

`wa_texto_msg` devolve `''` para audio, imagem e sticker. Em `enviado_roteiro`,
texto vazio vira `wa_resposta_sim('') === null`, o motor devolve `aguardando` e
**nao acontece nada**: ninguem e avisado, o estado nao muda, e 48h depois o cron
encerra e marca `perdido`. O publico 60+ responde por audio com frequencia alta.

O mesmo vale para o `null` em geral (o cliente que faz uma pergunta em vez de
responder): a decisao de "deixar para a humana" esta certa, mas nao existe
nenhum caminho neste branch que avise a humana.

**Correcao:** mensagem nao textual (ou `null` repetido) em `enviado_roteiro`
deveria marcar a conversa como precisando de gente, e nunca cair na trilha que
termina em `perdido` automatico.

### I5. `status = 'novo'` nao existe no painel e corrompe o lead ao salvar
**Arquivos:** `lib/wa-motor.php:292` / `painel/app.js:12-18`, `:187`, `:237`, `:329`

O painel so conhece `venda`, `negociacao`, `atendimento`, `semresposta` e
`perdido`. Com `status='novo'`:

- a badge cai no fallback e mostra "Sem resposta" (`app.js:152`);
- o lead some do funil de barras, que itera uma lista fixa (`app.js:329`);
- a gaveta faz `$('#e-status').value = 'novo'` num `<select>` que nao tem essa
  opcao (`painel/index.html:221-227`), o select fica sem selecao, e o **salvar
  grava `status: ''`** (`app.js:237,242`). Isto e perda de dado silenciosa, na
  primeira vez que ela abrir um lead vindo do WhatsApp.

**Correcao:** usar `semresposta` no primeiro contato, ou acrescentar `novo` ao
`STATUS`, ao `<select>` e ao `order` do funil. Como o kanban e o proximo plano, o
mais barato agora e alinhar o valor que o motor grava.

### I6. Lead criado pelo eco nasce sem telefone e sem nome
**Arquivo:** `lib/wa-motor.php:154-160`, chamado de `:220`, `:230`, `:235`, `:260`

`wa_lead_set` insere `['wa_id' => $wa_id] + $campos`. No caminho do eco (ela
inicia a conversa pelo celular, ou digita `#proposta` numa conversa que o robo
nunca viu) os campos sao so `status` e um carimbo de data. O lead aparece no
painel como "(sem nome)" com telefone "-", e ela nao consegue nem ligar para a
pessoa a partir dali.

**Correcao:** o insert de `wa_lead_set` sempre com `telefone => $wa_id` (que ja
esta em E.164) e, quando houver, `nome`. So no insert, para nao sobrescrever
edicao feita no painel.

### I7. As mensagens que o robo envia nunca entram em `po_wa_mensagens`
**Arquivo:** `lib/wa-webhook.php:198-222` (unico ponto de escrita na tabela)

A tabela recebe mensagem do cliente e eco. Tudo que o robo manda (PDF, duas
perguntas, menu, lembrete, qualificado, sem_data) fica fora. A spec (secao 4)
define esta tabela como "log completo, alimenta a aba de conversa no lead", e a
aba de conversa e o item 5 do proximo plano: ela vai nascer mostrando metade do
dialogo, com as respostas do cliente sem as perguntas.

Isto e lacuna do plano, nao desvio da implementacao: o plano so previa gravar
evento de webhook. Vale corrigir agora porque a mesma escrita resolve C3.

### I8. Dois recipientes de injecao de dependencia, e `wa_texto` atravessa de um para o outro
**Arquivos:** `lib/wa-motor.php:24-42` (`WA_DEPS`) e `lib/wa-timeout.php:23-28` (`WA_TO_DEPS`)

`wa_varre_timeouts` usa `wa_to_call` para tudo, menos `wa_texto`, que resolve o
`select` pelo recipiente do motor. O proprio teste precisou de um comentario de
cinco linhas e de uma chamada a `wa_motor_set_deps` para nao sair para a rede
(`test-wa-timeout.php:30-38`). Quem chamar `wa_varre_timeouts` de um contexto
novo sem saber disso vai bater no Supabase de verdade.

Somando: `wa_set_transport`, `wa_db_set_transport`, `wa_motor_set_deps` e
`wa_timeout_set_deps` sao quatro mecanismos de injecao com formatos diferentes.
A transmissao em massa vai querer um quinto. Consolidar antes disso.

### I9. `tests/test-wa-schema.php` sai para a rede e exige segredo
**Arquivo:** `tests/test-wa-schema.php:15`, `:34-52`

A restricao global do plano e "rede nunca e tocada em teste". Este arquivo faz
GET real no Supabase com a anon key e, no trecho dos textos, com a
`service_role`. Sem `config.local.php` (que e gitignored) ou sem rede, ele falha
em vez de pular: `wa_http_get_service` devolve `null`, `$chaves` fica vazio e o
`ok()` derruba o processo. Ou seja, os "13 PASS" so reproduzem nesta maquina.

**Correcao:** transformar em verificacao explicita de ambiente que imprime SKIP
quando nao ha chave ou nao ha rede, ou tirar do glob `test-*.php` e mover para
`tools/`.

### I10. Catalogo vazio manda menu com zero linhas e o cliente fica sem resposta
**Arquivo:** `lib/wa-motor.php:362-376`

Se `po_fetch_roteiros()` devolver `[]` (Supabase fora, cache frio, `ativo` todos
falsos), o motor monta `$itens = []` e chama `send_list` com zero linhas.
Medido: `acao: menu, envios: [["list",0]]`. A Graph API recusa lista sem linhas,
entao em producao vira `falha_envio`: o cliente escreveu e ninguem respondeu,
sem nenhum aviso.

**Correcao:** com catalogo vazio, mandar um texto ("me diga o destino que voce
quer conhecer") em vez de uma lista impossivel.

### I11. O invariante de E.164 nao alcanca o lead que vem do site
**Arquivo:** `enviar.php:79`, `:151`

O plano declara "telefone sempre em E.164 em todo o banco", e a branch entrega
`wa_e164()`, mas o `enviar.php` continua gravando o que o visitante digitou.
Resultado pratico: a mesma pessoa que preencheu o formulario e depois chamou no
WhatsApp vira dois leads, e o indice unico em `wa_id` nao ajuda porque o lead do
site nao tem `wa_id`. O kanban vai mostrar os dois.

**Correcao:** normalizar com `wa_e164()` no `enviar.php` (cai para o valor
original quando devolver `null`, para nao perder telefone estrangeiro), e
gravar `wa_id` quando a normalizacao der certo.

---

## Minor

- **M1.** `wa_limpa_pontuacao` (`wa-motor.php:75-80`) come reticencias e enfase:
  `"tudo bem... Que bom falar com voce!!"` sai como `"tudo bem. Que bom falar com
  voce!"`. Os textos sao editados pela cliente no painel; ela nao vai entender por
  que o "..." some. Restringir a limpeza ao que ela existe para resolver (espaco
  orfao antes de pontuacao e virgula duplicada por placeholder vazio).
- **M2.** A chave `saudacao` e inserida pela migration e cobrada pelo teste de
  schema, mas nenhum codigo a le. Ou usar, ou tirar dos dois lugares.
- **M3.** `po_wa_contatos` e criada e nunca tocada por nenhum arquivo da branch.
  E do importador do proximo plano; so registrar para nao parecer esquecimento.
- **M4.** O motor grava `origem = 'whatsapp'|'pago'` em `po_leads`, mas o painel
  classifica canal por `origem_manual || classifyChannel(r)` (`app.js:96-103`), que
  so olha `gclid` e `utm_*`. Todo lead do robo vai aparecer como "Direto", embora
  exista um chip "WhatsApp" no filtro que nunca casa.
- **M5.** `wa-cron.php:177` usa `php_sapi_name() === 'cli'`. Se o cron do cPanel
  rodar por `php-cgi`, a execucao cai no ramo web, o `$_GET['k']` vem vazio e o
  cron morre com 403 **sem log** (a funcao so loga quando a chave configurada e
  fraca). Logar tambem a recusa por chave errada.
- **M6.** So `config.local.php` e negado no `.htaccess:75`. `lib/*.php` responde
  pela web; hoje sao arquivos que so declaram funcao e nao imprimem nada, mas um
  `<FilesMatch>` negando `/lib` e defesa barata.
- **M7.** Conferido e sem problema: nenhum log da branch imprime token, chave ou
  corpo de resposta do Postgres. `wa_envia` loga o corpo de erro da Graph API, que
  contem `error.message` e `fbtrace_id`, nao segredo.
- **M8.** `wa_resposta_sim('ok')` e `wa_resposta_sim('Bom dia, tudo bem sim')`
  devolvem `true`. Depois do PDF, "ok" quase sempre significa "recebi", nao "tenho
  a data". Fica menos grave se C2 for corrigido.

---

## Triagem dos 9 achados ja conhecidos

| # | Item | Veredicto |
|---|---|---|
| 1 | `$GLOBALS['X']` vs `global $X` | **Pode ficar.** Conferi que funciona: `config.local.php` e carregado em escopo de arquivo por `po-data.php`, entao as variaveis sao globais de verdade. A forma de `wa-config.php` e ate a mais robusta das duas, porque nao quebra se a chave nao existir. |
| 2 | `test-wa-schema` duplica o curl do `po_http_get` | **Pode ficar como duplicacao**, mas o arquivo precisa de outro conserto, e maior: ele sai para a rede e exige segredo (I9). Resolver os dois de uma vez. |
| 3 | O teste de schema nao verifica indices nem a FK de `lead_id` | **Pode ficar** como lacuna de teste. O que importa mais e que **nada popula `lead_id`** em lugar nenhum da branch: a FK e decoracao ate o painel existir. Registrar como divida do proximo plano (o kanban vai querer ligar conversa e lead). |
| 4 | `00` internacional e `0+DDD` caem em `null` | **Pode ficar agora** (a Meta sempre manda E.164 em digitos). **Vira bloqueador no importador de contatos**: a exportacao da agenda tem exatamente esses dois formatos, e hoje eles somem em silencio. Deixar anotado no plano do importador. |
| 5 | Evento `status` fora da idempotencia | **Pode ficar.** Hoje e inofensivo mesmo: o `status` nao passa pelo registro **nem pelo processamento** (`wa_processar` devolve na primeira linha). Quando o relatorio de campanha entrar, ele vai precisar de gravacao **e** de idempotencia; sao as duas metades do mesmo trabalho. |
| 6 | Sem `envio_pdf`, o `send_doc` sai com legenda vazia | **Pode ficar.** PDF sem legenda continua sendo um PDF util; e melhor que a alternativa. So e incoerente com a regra "texto vazio e falha" aplicada nos outros pontos, entao vale um comentario no `wa_envia_roteiro` dizendo que a assimetria e deliberada. |
| 7 | PDF sai, perguntas falham, estado nao registra nada | **Corrigir antes do go-live** (nao bloqueia o merge, porque nada esta em producao). Hoje o retorno e `falha_envio` com o estado ainda em `novo`, entao a **proxima mensagem do cliente reenvia o PDF inteiro**. Com a Graph API instavel isso vira PDF duplicado em serie. Correcao barata: gravar `estado=enviado_roteiro` + `roteiro_slug` assim que o documento sai, e tratar a falha das perguntas como reenvio so das perguntas. |
| 8 | `limit=500` sem `order=`, updates do encerramento nao atomicos | **Pode ficar**, com uma ressalva gratuita: acrescentar `&order=aguardando_desde.asc` e uma palavra e evita que, se um dia passar de 500 conversas abertas, as mais antigas nunca sejam varridas. A nao atomicidade dos dois updates e aceitavel no volume real. |
| 9 | `select`-depois-`insert` sem transacao | **Pode ficar.** Os indices unicos protegem o banco. Registrar so que, quando a corrida perde, `wa_db_insert` devolve `null` e ninguem olha o retorno: o estado daquela conversa se perde em silencio. Um `error_log` no caminho do `null` resolve a visibilidade. |

---

## Respostas diretas as perguntas do pedido

**Os contratos batem de ponta a ponta?** Sim, com duas costuras soltas: `wa_texto`
atravessa recipientes de dependencia (I8) e o valor `status='novo'` que o motor
grava nao existe no vocabulario do painel (I5). Nao ha funcao duplicada com nome
diferente entre os `lib/wa-*.php` (conferido por diff de nomes), mas ha tres
wrappers de curl quase iguais (`po_http_get`, `wa_http`, `wa_db_http`) e quatro
mecanismos de injecao diferentes.

**O caminho completo de uma conversa real.** Funciona: webhook assina, registra,
identifica o roteiro pelas quatro fontes na ordem certa, manda PDF e perguntas,
le a resposta, move o funil, e o eco silencia. O que se perde no meio: resposta
por audio (I4), resposta mista (C2), cliente que nao escolhe no menu (I2), e
conversa viva encerrada por um `lembrete_at` velho (I1).

**Primeiro dia em producao com `config.local.php` sem as chaves de WhatsApp.**
Nenhum arquivo fataliza; `wa_config()` devolve string vazia para as cinco chaves
e todos os caminhos degradam para `falha_envio` com log. O `wa-cron.php` recusa
tudo pela web (correto) e roda pelo CLI. **O site atual nao e afetado**: a branch
nao modifica nenhum arquivo existente, nao toca `.htaccess`, e `roteiro.php` e
`enviar.php` nao carregam nada de `lib/wa-*.php`. O unico risco do primeiro dia e
o C1, e ele e serio justamente porque as chaves estao vazias.

**Seguranca.** Um achado critico (C1). Fora ele: nenhum segredo vaza por log,
mensagem de erro ou resposta HTTP; `whatsapp.php` nunca imprime nada alem de
`{"ok":true}`; `wa-cron.php` devolve so a contagem. `lib/` e alcancavel pela web,
mas os arquivos so declaram funcoes (M6).

**Divida que vai doer no proximo plano.** Em ordem: I7 (a aba de conversa nasce
com metade do dialogo), I5 (o kanban nao sabe o que e `novo`), item 3 da triagem
(`lead_id` nunca preenchido), I11 e I6 (o mesmo cliente vira dois leads, um deles
sem telefone), I8 (a transmissao vai querer um quinto recipiente de injecao),
item 5 da triagem (o relatorio de campanha precisa dos `statuses`, que hoje sao
descartados) e item 4 (o importador de contatos vai perder numero com `00` e
`0+DDD`).

---

## Recomendacoes de processo

1. **Corrigir C1, C2 e I1 nesta branch.** Sao os tres baratos e os tres que
   custam caro depois: seguranca, lead perdido e lead perdido.
2. **Nao apontar o webhook da Meta para producao antes de**: C1 fechado, C3
   verificado com uma mensagem real, e o item 7 da triagem resolvido.
3. **Antes do go-live, existir algum aviso para a dona.** O texto `qualificado`
   promete ao cliente que alguem vai falar com ele, e hoje nenhuma linha da
   branch avisa ninguem. Ate o painel do proximo plano existir, um e-mail no
   `wa_processar` quando o lead qualifica (o `enviar.php` ja tem PHPMailer
   configurado) cobre o intervalo.
4. **Acrescentar tres testes** que travam o que foi encontrado: menu que nao se
   repete, `lembrete_at` zerado ao reenviar o menu, e resposta mista que nao
   desqualifica.
