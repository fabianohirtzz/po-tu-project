# Fluxo de conversa, Plano B (a tela do painel): pendências e heranças

Fechamento da execução do plano `docs/superpowers/plans/2026-10-27-fluxo-painel.md`.
Sete tarefas, 18 commits, suíte de 73 para **72 arquivos** (entram 3, sai o
`test-textos.mjs`). Revisão por tarefa, revisão da branch inteira, e uma onda final de
correção com re-revisão escopada. **Nenhum achado Critical sobreviveu.**

Este documento existe porque o ledger de execução é rascunho e morre com o workspace. O que
está aqui é o que **não** cabe no histórico do git: o que ficou diferido e por quê, o que o
Plano C herda, as dívidas pré-existentes que apareceram no caminho, e as regras de método
que esta branch descobriu na marra.

---

## 1. O que a branch entrega

A aba **"Fluxo do robô"** (no lugar de "Textos do robô"), onde a cliente lê o fluxo de
conversa desenhado, reescreve os textos que o robô manda, confere, **publica** (congela uma
versão) e **liga** (põe o robô para atender cliente real).

- `fluxo.php` — endpoint autenticado: `ler`, `conferir`, `publicar`, `ligar`, `desligar`.
- `painel/fluxo.js` + `painel/fluxo.css` — lista (celular) e canvas (desktop, a partir de
  980px), gaveta de edição, barra de Publicar/Ligar e a lista de problemas.
- `painel/index.html`, `painel/app.js`, `painel/assets-lock.json` — a aba e o roteamento.
- **Removidos:** `painel/textos.js` e `tests/test-textos.mjs`.

**Medido no dia 1** (migrations rodadas em papel, retratos reais da semente passados pelas
funções de produção e renderizados em Chrome headless): os dois fluxos aparecem com versão
1 e desligados; a triagem acusa **um** bloqueio — a regra 11, porque ela salta para a Lista
de espera, que está desligada — e a frase que manda ligar a espera primeiro aparece junto.
Banco vazio mostra "Nenhum fluxo encontrado", sem exceção.

---

## 2. Residuais aceitos

Nenhum é defeito de produção alcançável hoje. Agrupados por assunto.

### Estado da tela

- **`PO_FLUXO_CONF` não é invalidado quando OUTRO fluxo muda.** Conferir a triagem com a
  espera ligada (limpo, Publicar aceso) → desligar a espera → voltar para a triagem: o
  Publicar continua aceso e o servidor recusa pela regra 11. **Nunca publica errado** — o
  endpoint é a autoridade e revalida — e a frase da ordem de ligar reaparece na mesma barra,
  então há sinal na tela. Recupera-se apertando Conferir.
- **A rolagem horizontal do canvas reseta a cada salvamento**, porque o render reconstrói o
  `innerHTML`. No desktop a cliente perde o lugar. Cosmético, mas incômodo num fluxo de
  3060px de largura.
- **`versao_atual` apontando para versão inexistente** produz "Tem edição não publicada" com
  o Ligar habilitado. Inalcançável: nada apaga versão.

### Edição

- **Os campos de rótulo de opção não têm contador nem `maxlength`**, enquanto a regra 5
  limita botão a 20 e linha de lista a 24 caracteres e proíbe rótulos repetidos. O texto da
  mensagem tem contador ao vivo; o rótulo não tem nada, e ela só descobre no Conferir.
- **Sete dos 22 cards não respondem ao toque** (os 6 `marca` e o `ir_para_fluxo`): não têm
  texto, logo não têm gaveta. Não é defeito — eles não ganham "Editar texto" nem
  `cursor:pointer`, e o rótulo do tipo explica o que são —, mas são 7 cliques que não fazem
  nada.
- **Corrida do salvar:** fechar a gaveta e abrir outro card durante o salvamento (1 a 3s em
  rede móvel) faz o render reabrir o card antigo por cima, perdendo o que foi digitado no
  segundo. Existe também uma janela estreita em que o Publicar dispara durante o salvamento;
  o servidor revalida, então o pior caso é publicar uma edição válida sem conferência.

### Tela x servidor

- **A tela nunca mostra a porta de entrada de cada fluxo** (`gatilho` vem no `ler` e não é
  usado). O erro de porta ocupada diz *"Desligue esse fluxo antes ou mude a porta deste"* — e
  metade do conselho nomeia algo que ela não consegue fazer por aqui.
- **A trava de "a tela não julga variável" é heurística.** Quatro redações plausíveis de uma
  lista branca passariam (`/^(nome|roteiro|data)$/`, `split(" ")`, template literal,
  concatenação). Teste estático não fecha isso; o julgamento de verdade mora no endpoint, e
  apertar mais produziria falso positivo em quem escrever "nome" por outro motivo.
- **Dois espelhos do PHP vivem em JS** (`poFluxoLimite` ↔ `WA_FLUXO_LIMITE` e
  `poFluxoPiorCaso` ↔ `wa_fluxo_tam_pior_caso`). A herança 2 do motor está escrita de forma
  absoluta ("a tela não reimplementa nenhuma das 16 regras"), então **alguém vai reler e
  estranhar**: os dois estão presos por teste contra o PHP e erram para o lado **estrito** (a
  tela recusa, não aprova), que é a direção segura.

### Travas corretas sem teste

- **A guarda "só escreve no resultado se o fluxo ainda for o atual"** (na conferência
  pós-salvar) está **correta** e não tem asserção. Medido o que acontece sem ela: a
  conferência do fluxo A escreve no painel de resultado do fluxo B, com os rótulos dos cards
  errados. Exige trocar de fluxo no seletor durante o `await` — janela de ~1s, efeito
  cosmético, sem corrupção de dado. A barra não sofre, porque ela já lê `poFluxoAtual()`.
  Fecha com uma asserção que troque `PO_FLUXO_SEL` durante o `await`.
- **A varredura que exige a classe base `btn`** só casa a classe no **início** do atributo:
  `class="foo btn-primary"` passaria. Hoje o fonte não emite nada assim — furo teórico.

### Resto

- **`painel.css` ainda tem `.txt-card`**, CSS morto do `textos.js` removido. Não foi apagado
  para não precisar subir o `?v=` e o sha do `painel.css`. `.txt-h2` e `.txt-erro` seguem em
  uso por outras abas e ficam.
- **O parágrafo explicativo da aba foi escrito pelo implementador.** A cliente pode querer
  outro texto.

---

## 3. Pré-condições do Plano C (três becos sem saída que nascem com ele)

Os três são **inalcançáveis com o fluxo semeado** e foram medidos como tal. Cada um vira
beco sem saída no instante em que o Plano C deixar criar card ou renomear fluxo. **Ler isto
antes de começar o Plano C.**

1. **Lembrete em card que não espera.** A gaveta só desenha a caixa do lembrete quando o
   card espera (`botoes`/`lista`), mas a **regra 6 confere `lembrete_ativo` em qualquer
   tipo** e a coluna é `not null default **true**`. Um card `texto` criado com o default e
   sem `lembrete_texto` **bloqueia a publicação, e a gaveta não oferece caminho para
   desligá-lo**. Medido na semente: zero casos hoje.
2. **Sem paginação, duas partes da tela se contradizem.** O `ler` busca todos os cards de
   todos os fluxos numa consulta só, sem paginar, contra o teto de 1000 linhas do PostgREST.
   Se truncar: a lista e o canvas acusam "o card X não existe" nas setas órfãs **e**
   `publicado_igual` vira `false` para sempre — enquanto o `conferir` e o `publicar`, que
   leem por fluxo no servidor, dizem que está tudo certo. Sem erro em lugar nenhum. 22 cards
   hoje.
3. **Card de botões com mais de 3 opções vira gaveta que não salva.** O bloqueio existe e
   está certo, mas a tela não tem como apagar opção (é Plano C). Medido na semente: máximo
   3 botões.

Mais duas heranças diretas:

- **A ordem de ligar é presa ao nome literal "Lista de espera".** Quando o Plano C deixar
  renomear fluxo, a frase some em silêncio e a herança 7 do motor volta a morder. O dado
  para derivar isso sem o nome já existe: o card `ir_para_fluxo` do fluxo 1 aponta
  `config.fluxo` para o id do fluxo 2.
- **Ids de opção e de card continuam proibidos à tela.** Opção com id `sair`, `parar` ou
  `descadastrar` faria o toque no botão **descadastrar o cliente do marketing**
  (`wa_e_saida` é aplicada ao id), e os ids de card, de comprimento fixo, são o que torna
  estável a eleição do `humano` do grafo. No Plano C os ids nascem **gerados**, nunca
  digitados.

---

## 4. Dívidas pré-existentes que este plano tornou mais importantes

1. **`ligar` escreve com `service_role` atrás de um portão que aceita qualquer usuário do
   projeto Supabase.** `po_auth_ok` só confere que o token resolve em `/auth/v1/user`, e o
   projeto é **compartilhado com NOX e hd360**, logo a tabela de usuários é a mesma. Vale
   igual para `campanha.php`, `templates.php` e `upload-video.php` — não é regressão desta
   branch. **O que mudou:** em 26/10 o `WA_PHONE_ID` saiu de vazio e o número da agência está
   conectado em convivência, então **a única barreira restante entre "um usuário de outro
   cliente aperta Ligar" e "o robô fala com cliente real" é o `subscribed_apps` vazio.**
   `ligar` é a ação de maior consequência já publicada atrás desse portão. Anotar para quando
   houver papel/claim no painel.
2. **As políticas RLS das três tabelas novas são `for all to authenticated using (true)`**,
   o mesmo alcance, direto do navegador. O `fluxo.php` não é o elo mais fraco para o *dado* —
   só para a *ação*.

---

## 5. Avisos de deploy que não cabem na receita

A receita está no fim do plano e foi conferida contra `git diff --name-only` dos dois planos:
nomeia exatamente os arquivos certos, na ordem certa. Três pontos que valem repetir:

- **Merge não é deploy.** Nada vai ao ar sem o dono rodar as duas migrations do Plano A e
  subir por FTP. Elas **nunca rodaram em Postgres nenhum**.
- **Ligar o fluxo não liga o robô.** No dia do deploy, `subscribed_apps` está vazio e
  `health_status.can_send_message` está `BLOCKED` (erro 141006, forma de pagamento). A
  cliente vai apertar Ligar, ler "Fluxo ligado", e **nada vai acontecer** — sem jeito, pela
  tela, de saber. Está no passo 8 da receita; avise antes.
- **A suíte tem um vermelho esperado** (`test-wa-schema.php`) **até as migrations rodarem.**
  Depois disso ela fica inteira verde. Qualquer outro vermelho é problema.

---

## 6. Regras de método que esta branch descobriu

Valem para o repositório inteiro, não só para este plano.

- **Não rode o `test-cache-buster.mjs` junto de mutantes semânticos.** Ele hasheia o
  **conteúdo** de `painel/*.js|css`, então mata qualquer edição de byte, com ou sem
  significado. Medido: **54 de 54 "mortos"** com ele ligado, **21 sobreviventes** com ele
  fora. Rode-o só nos mutantes de `?v=`/lock.
- **Comentário não é asserção, e aqui ele ocupou o lugar de uma três vezes.** Uma trava de
  URL satisfeita pelo texto de um comentário; uma regra de CSS virada em comentário
  sobrevivendo; e um comentário afirmando "nenhum nó encosta em outro" que a medição provou
  **falso**. Ao revisar, procure comentário que descreve garantia sem asserção ao lado.
- **Número de mortalidade do implementador não se aceita sem rodada independente.** Cinco
  vezes nesta branch a mortalidade declarada foi desmentida por medição independente — numa
  delas o sobrevivente mais caro era o próprio desenho que o relatório apresentava como
  mitigação; noutra, a medição achou o único Critical da branch. **Peça o conjunto, não o
  total.**
- **"Só se vê no navegador" quase nunca é verdade.** Três coisas foram adiadas com esse
  argumento e as três eram mensuráveis em Node com dados que já estavam no repo: um clamp
  que escondia 3 cards, uma sobreposição de 8 pares, e a rolagem do canvas. Exija a medição
  antes de aceitar o adiamento.
- **Trava de geometria tem que contar o que as tarefas seguintes penduram no nó.** Um botão
  acrescentado por outra tarefa comeu a folga calibrada na anterior, e o teste ficou verde
  porque media a **estimativa**, não o renderizado.
- **O heredoc deste ambiente come barras invertidas**, e **editar arquivo com Python troca o
  fim de linha** (`core.autocrlf=true`). Para script com regex, escreva o arquivo com a
  ferramenta de escrita; para editar, use a ferramenta de edição. Isso custou tempo a cinco
  agentes.
- **Arquivo novo em `painel/` que ninguém carrega deixa a suíte vermelha na hora.** Criar e
  fiar (`<script>`/`<link>` + entrada no `assets-lock.json`) são a **mesma** tarefa.
- **Trava de fonte é contornável movendo a palavra de lugar.** A trava das chaves do salvar
  continuava verde quando um auxiliar gravava chave proibida. A trava certa **executa** e
  compara o conjunto de chaves que sai.
- **A suíte verde na branch não prova a árvore mesclada, e o fim de linha é o motivo.**
  Arquivo que uma ferramenta escreve nasce LF; arquivo que o git materializa nasce CRLF.
  Um arquivo **novo** é LF nos commits todos e vira CRLF no merge, que é o primeiro
  checkout que o apaga e o recria. O `test-fluxo-painel.mjs` passou nos 19 commits e
  ficou vermelho na mescla com o `painel/fluxo.js` idêntico: a allowlist de statement de
  topo ancora em `$` e `function poFluxoTipos() {` passou a terminar em CR. Corrigido
  roteando as 9 leituras de fonte por uma função `le()` que normaliza — **na leitura, não
  em cada regex** (mutante do `.replace` morto; verde medido nos dois fins de linha).
  Rodar a suíte **depois** do merge e **antes** de apagar a branch é o que pegou isto.
