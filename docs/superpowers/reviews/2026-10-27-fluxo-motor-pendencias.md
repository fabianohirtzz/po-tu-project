# Fluxo de conversa, Plano A (o motor): pendências e heranças

Fechamento da execução do plano `docs/superpowers/plans/2026-10-27-fluxo-motor.md`.
Dez tarefas, 24 commits, suíte de 47 para **56 arquivos**. Revisão por tarefa, revisão da
branch inteira, uma onda de correção e re-revisão escopada. Nenhum achado Critical em
nenhuma rodada.

Este documento existe porque o ledger de execução é rascunho e morre com o workspace. O que
está aqui é o que **não** cabe no histórico do git: o que ficou diferido e por quê, o que o
Plano B herda, e as dívidas pré-existentes que apareceram no caminho.

---

## 1. Residuais aceitos (nenhum tem janela que feche)

Os três vieram da última re-revisão, todos classificados Minor, nenhum bloqueando merge.

1. **`pdf` com catálogo permanentemente vazio não envia, não grava e não deixa nota.**
   `lib/wa-fluxo.php`, case `pdf`. É o custo da semântica que o próprio achado pediu: catálogo
   vazio passou a ser **transitório** (o cliente tenta de novo na mensagem seguinte) em vez de
   permanente, porque `po_fetch_roteiros()` devolve `[]` em erro e tratar isso como "o roteiro
   saiu do ar" entregava a conversa à Laís **em definitivo** numa queda do Supabase. Se todos os
   roteiros estiverem ocultos de propósito, o card fica mudo a cada mensagem, onde antes havia
   handoff. Nesse estado o site inteiro já está degradado (a home e `/roteiros` não têm o que
   mostrar), então o modo de falha é consistente com o resto.

2. **Uma consulta extra a `po_leads` em eco de documento.** `lib/wa-motor.php`: o lead passou a
   ser lido antes do `if`, então um eco de documento cuja legenda já traz atalho (`#proposta`)
   faz um `select` que antes era evitado. Um `select`, só em eco de documento.

3. **O docblock de `wa_fluxo_executa` não menciona a ressalva do catálogo vazio.** Uma frase. O
   comportamento está correto e comentado no ponto da decisão; o cabeçalho da função ficou vinte
   linhas desatualizado. **Quem mexer na função antes do Plano B deve atualizar essa frase** —
   é o contrato que o implementador do painel vai ler.

---

## 2. Diferidos, com a razão

Nada aqui muda comportamento observável. Agrupado por assunto.

### Testes que poderiam ser mais rigorosos

- **As regexes de `tests/test-fluxo-migration.php` têm folgas:** `strpos('public.<tabela>')` não
  prova `create table` (casa a policy e o `references`); a regex do índice parcial usa `/s`
  preguiçoso e não limita ao mesmo statement; a conversão de estado só confere o prefixo do
  `update`, não a lista do `in (...)`, e aceita as strings em comentário. **Por que fica:** as
  duas migrations são imutáveis depois de rodadas, então o risco de regressão que essas folgas
  deixariam passar morre no deploy. A cobertura real da semente vive em
  `tests/test-wa-fluxo-semeado.php`, que roda os grafos pelas funções de produção.
- **Duas asserções quase vacuas** no mesmo arquivo (a de versão 1 casa o comentário do cabeçalho;
  a de `ativo = true` não pegaria `values (..., true, ...)`). Eram prescritas pelo plano, e a
  cobertura real está no teste da semente.
- **`WA_FLUXO_LIMITE` e `WA_FLUXO_VARS_ROTEIRO` não têm asserção própria**, e são valores que
  cinco pontos do código consomem letra por letra.
- **O laço `wa_dep($k) === wa_fl_dep($k)` é tautológico hoje**, porque `wa_dep` delega direto, e
  usa lista fixa de 8 chaves em vez de lê-las da tabela. Serve para travar regressão futura; o
  ponto crítico (`send_buttons`) tem asserção própria.
- **Falta caso de teste** para "`ad_id` preenchido, nenhum fluxo de anúncio casa, mas existe
  `mensagem_nova`" (hoje cai em `palavra` e depois em `mensagem_nova`, que parece certo mas não
  está travado); e para "conversa de 27h com lembrete de 2h", que a guarda da janela encerra.
- **`tests/test-wa-schema.php` não é exercitável sem `config.local.php`** (sai SKIP, que é a
  exceção documentada da suíte). As cinco colunas novas entraram na sonda, mas a conferência de
  verdade é a do deploy, à mão — está na receita do plano.

### Diagnóstico e observabilidade

- **`motivo === 'invalido'` carrega três causas** (card ou tipo desconhecido, `proximo` ausente ou
  pendurado, socorro sem `humano` válido) e o log não diz qual. Cegueira de diagnóstico, não
  defeito de comportamento: as três levam ao mesmo tratamento.
- **Config não-booleana no card `marca` é descartada sem `error_log`**, e `wa_fl_dep` com nome
  desconhecido dá warning em vez de erro claro (herdado de `wa_dep`).
- **Os separadores de palavra-gatilho são só `,` e `;`.** "cruzeiro / navio", ou dois gatilhos em
  linhas separadas (coisa que um `<textarea>` produz), viram uma expressão de duas palavras que
  não casa com nada, em silêncio.
- **O roteador faz `trim()` no `gatilho_valor` e o validador não.** Um valor só de espaços é
  "anúncio específico" para a validação e "qualquer anúncio" para o roteador. É a classe de
  defeito que este projeto já nomeou: lados que leem valores diferentes aprovam o que o outro
  recusa.

### Copy e conteúdo do fluxo semeado

- **Dois rótulos poderiam ser mais simples:** "Só esse destino" nomeia escopo em vez de ação, e
  "Roteiro específico" é vocabulário nosso, não dela. Os dois estão pinados por nome no teste,
  então mexer toca dois arquivos.
- **O teste que proíbe nome de pessoa nos corpos só olha `texto`.** O `lembrete_texto` de dois
  cards diz "para a Laís", e ali está certo (o lembrete vem logo antes da entrega), mas nada
  trava um nome novo aparecendo num lembrete.
- **A lista de preposições proibidas antes de `{data}` é fechada** (em, de, na, no, a, para), sem
  "até", "entre" ou "durante". Suficiente para os textos atuais.
- **A triagem tem dois botões com um resultado só:** "Roteiro específico" e "Ver a programação"
  apontam para o mesmo catálogo, sem `config.ano`, e desembocam no mesmo card `pdf`. A reunião
  pediu triagem entre as duas coisas. **Não é defeito** — `config.ano` é lido na execução, então
  a cliente resolve isso pela tela no Plano B — mas é o tipo de coisa que ela vai notar e
  perguntar. **Vale avisá-la.**

### Forma

- O `ctx` de saída do roteamento é normalizado no caminho de acerto e não nos outros (conferido
  que o slug nunca se perde em nenhum caminho; é forma, não dado) · espaço em branco conta como
  escolha na lista dinâmica, mas a guarda `$clique` do motor tornou isso inalcançável em produção
  · as assinaturas de `lib/wa-fluxo.php` não têm declaração de tipo, coerente com o resto do
  arquivo · `wa_fluxo_grafo_de` é calculado duas vezes no publicar (função pura, só trabalho
  repetido) · um `select_estrito` por coluna onde `select=interesses,espera` resolveria em uma ida
  · `wa_fl_call('roteiros')` no timeout usa o despachante do fluxo e não o `wa_to_call` do próprio
  arquivo · `po_wa_cards` não tem índice em `fluxo_id`, que é o filtro do publicar · o teste da
  versão declara `card()` e `cards_ok()` em escopo global, nomes genéricos num arquivo que carrega
  as libs de produção · `wa_send_list` fixa o título da seção em "Roteiros", e o Plano A passou a
  permitir lista estática (anos, por exemplo) · `WA_HORAS_ENCERRA` virou constante morta, porque
  o `min()` sempre escolhe o outro termo; vale um comentário dizendo que é teto histórico.

---

## 3. Herança obrigatória para o Plano B (a tela do painel)

Escrito aqui porque é o que o implementador do Plano B precisa ler **antes** de desenhar a tela.

1. **A tela TEM que escapar HTML** ao renderizar as mensagens de `wa_fluxo_valida`. Elas
   interpolam texto escrito pela cliente sem escapar — o que está **correto** no PHP, porque ali
   é string, e seria **fatal** na tela, porque lá é DOM. Um rótulo com `<` vira injeção no painel.
2. **A tela NÃO reimplementa nenhuma das 16 regras em JavaScript**, e o teste de fonte que proíbe
   isso é do Plano B. Precedente da casa: `wa_tpl_problemas` + o modo `conferir` do
   `templates.php`. Tela que valida diferente do que o envio valida aprova o que a Meta recusa.
3. **O simulador precisa da mesma guarda `$clique` que o `wa_processar` tem.** Só `list_reply`,
   `button_reply` e `button` carregam escolha; texto digitado vira string vazia. Sem isso o
   simulador aprova um desenho que o motor roteia diferente.
4. **Os ids de card devem ser gerados zero-padded ou de comprimento fixo.** `wa_fluxo_grafo_de`
   elege como `humano` do grafo o **primeiro** card daquele tipo na ordem de iteração, e o `jsonb`
   do Postgres não preserva ordem de chave. Com ids de comprimento igual a ordem de bytes é
   estável; sem isso, uma republicação a partir do rascunho pode eleger outro card `humano`.
5. **O campo de porta exige `gatilho_valor` quando o gatilho é `palavra`.** Não existe regra de
   validação para isso (decisão registrada: criá-la mudaria o contrato contra o qual a semente é
   validada, e a falha é autoevidente no primeiro teste da cliente). O lugar certo é o campo
   obrigatório no formulário.
6. **O aviso 15 coincide com a regra 3** para um card totalmente órfão: um bloqueia e o outro
   avisa, dando duas linhas para o mesmo problema. A tela deve esconder o aviso 15 de card que já
   aparece bloqueado pela 3.
7. **A ordem de ligar os fluxos importa, e a tela deve dizer isso:** a "Lista de espera" tem que
   ser ligada **antes** da "Boas-vindas e triagem", porque a regra 11 exige que o destino de um
   `ir_para_fluxo` esteja publicado **e ativo**. Com a espera desligada, a triagem não republica,
   e a cliente vai achar que o fluxo dela está quebrado.
8. **`wa_e_saida` é aplicada ao id do botão.** Um card com opção de id `sair`, `parar` ou
   `descadastrar` faria o toque no botão descadastrar o cliente de marketing. Nada no validador
   impede, e no Plano B os ids virão da tela.
9. **`wa_fluxo_valida` não confere o tamanho do `config.botao` da lista**, que a Cloud API limita
   a 20 caracteres. Um botão de 25 publica e é truncado no envio. Cosmético, não falha de envio.

---

## 4. Dívidas pré-existentes que este plano tornou mais importantes

Nenhuma é regressão deste plano. As duas primeiras apareceram em revisão de tarefa e foram
conferidas no código.

1. **`wa_msg_do_robo` devolve `false` com o Supabase fora do ar, e `false` ali significa "não é do
   robô".** Ou seja, **numa queda do banco o eco não silencia o robô** — e robô e humana falando
   por cima uma da outra é, pela regra escrita no CLAUDE.md, o pior modo de falha deste
   subsistema. O Plano A multiplicou as mensagens que o robô envia, então a superfície de eco
   cresceu. Vale uma passada de dívida.
2. **`wa_conversa()` lê com `wa_call('select')` e não com a variante estrita**, então erro de banco
   é indistinguível de "sem conversa". É a única janela em que `estado='novo'` com `card_atual`
   preenchido (o marcador de cadeia parada) poderia significar outra coisa.
3. **`wa_camp_perdeu_data()` não existe neste repositório.** A revisão grepou o código e o
   histórico: as únicas ocorrências são comentários e a seção 6.12 do spec, e o `campanha.php` não
   seleciona `status` nem `qualif_data`. O CLAUDE.md afirma que a função entrou em 23/10/2026.
   **Isso não enfraquece os cinco cards `marca` do fluxo semeado** — ao contrário: significa que a
   coluna precisa ser alimentada para o seletor "perdeu só pela data" ser construível, e agora
   ela será. Mas a frase "perdeu por data entra na transmissão" é, hoje, promessa não
   implementada, e **o lugar de corrigir é o CLAUDE.md**.
4. **`wa_envia_roteiro` segue com `$r['data_label'] ?? 'na data prevista'`**, e `??` não pega
   string vazia. Como nenhum roteiro do banco tem `data_label` preenchido, a variável chega vazia
   ao cliente por aquele caminho. O caminho novo usa `?:` e cai no `periodo`. A convivência é
   deliberada: as funções do motor antigo ficam marcadas `@deprecated` até o Plano A estar no ar e
   verificado, e só então se apagam.
5. **O ramo de eco do motor ainda testa `'qualificado'`** em outro ponto? Não: a lista virou
   `['aguardando','humano']` e, na onda final, `aguardando` passou a exigir `qualif_at`. Fica o
   registro de que o estado `'qualificado'` não é mais escrito por ninguém.

---

## 5. Avisos de deploy que não cabem na receita

A receita está no fim do plano e foi atualizada com a ordem de upload, a conferência das cinco
colunas e a ordem de ligar os fluxos. Dois pontos que valem repetir aqui:

- **As duas migrations nunca foram executadas em Postgres nenhum.** O SQL foi lido linha por linha
  por dois revisores, que não acharam erro de sintaxe nem de semântica, mas leitura não é
  execução. O projeto Supabase é compartilhado com outros clientes, então rodar é decisão do dono.
- **`2026-10-27-fluxo-conversa.sql` é de rodada única.** Reaplicá-la depois do go-live colapsaria o
  marcador de cadeia parada (`estado='novo'` com `card_atual`) **e** as conversas recém-criadas,
  fazendo a porta ser resolvida de novo e o PDF ser reenviado. O cabeçalho da migration diz isso,
  e o item 1 da receita repete.
- **Com o webhook inscrito e nenhum fluxo ligado, o robô não responde nada.** Toda mensagem
  devolve `sem_fluxo`, o lead é criado e aparece na aba Leads, e a equipe atende pelo app. É o
  critério de aceitação do plano, mas a cliente precisa saber disso **antes** de alguém inscrever
  o webhook — o motor antigo mandava o menu.
