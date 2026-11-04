# Ordem por data e simulador do chat — pendências e heranças

Branch `fluxo-ordem-simulador`, 5 tarefas, 03/11/2026.
Plano: `docs/superpowers/plans/2026-11-03-ordem-por-data-e-simulador.md`.
Spec: `docs/superpowers/specs/2026-10-27-fluxo-conversa-painel-design.md` (§6.9).

**Nada foi ligado e nada foi publicado.** O webhook segue não inscrito, os dois
fluxos seguem `ativo = false`, nenhum cron foi cadastrado, e o deploy por FTP
não foi feito. O simulador não depende de nada disso para funcionar: ele roda
com as chaves da Meta ausentes, que é o estado de hoje.

## O que entrou

| | |
|---|---|
| Suíte | **76 arquivos, 100% verde** |
| No Git | 62 |
| Só no disco | 14 |

Os 14 são dívida conhecida e anterior a este plano (`test-cadastur`, `test-faq`,
`test-home-index`, `test-home-slide`, `test-home-sobre`, `test-htaccess-redirects`,
`test-limpa-cache`, `test-rot-preenche`, `test-rot-save-erro`, `test-roteiro-preview`,
`test-social`, `test-status-tag`, `test-urls-limpas`, `test-vitrine`). Quem clonar
o repositório roda 62 e **não tem como saber que faltam 14** — eles testam código
de produção que também nunca foi commitado (`faq.php`, `index.php`,
`lib/po-social.php`, `limpa-cache.php`, `llms.php`, `nossa-historia.php` e outros
~50 arquivos). Resolver isso é commitar o trabalho não commitado, não apagar teste.

## O que ficou de fora, e por quê

### 1. A ordem por data não vale para o SITE

A ordenação entra em dois pontos de `lib/wa-fluxo.php` e vale para **a lista que o
robô manda no chat**. A home, `/roteiros` e `/link` continuam em `ordem.asc`, que é
a ordenação manual que a cliente controla pelo painel. Ninguém pediu para mudar, e
mudar tiraria dela o controle da vitrine. Se um dia for pedido, a função
`wa_roteiro_data_ini()` já serve: ela é pura e não depende de nada do WhatsApp.

### 2. O item 2 da reunião é impossível por desenho da Meta

O pedido foi: "deixar só mensagens de leads que qualificaram, e os que foram
descartados no funil ela nem precisa receber as mensagens".

**Não há solução técnica do nosso lado.** Na convivência (coexistence), mensagem
enviada pela Cloud API aparece no WhatsApp Business do celular dela
automaticamente, e mensagem que o cliente manda também. Não existe parâmetro,
cabeçalho ou configuração que filtre o espelhamento por lead, por etapa do funil
ou por qualquer outro critério: o espelhamento é do NÚMERO, não da conversa.

O que já é verdade e atende a intenção dela: **o robô já não a envolve nos
descartados**. Ele se despede sozinho de quem não qualificou e só chama o
atendimento humano no caminho de quem qualificou — é o card `humano` do grafo. O
que sobra no celular dela é o histórico, que ela pode arquivar ou silenciar no
próprio app.

**E o aviso que precisa ser repetido para ela:** se ela responder por dentro de
uma conversa que o robô está atendendo, o eco (`smb_message_echoes`) silencia o
robô naquele contato **para sempre**. É proposital — robô e humana falando por
cima um do outro é o pior modo de falha do sistema — mas é definitivo.

### 3. As três pré-condições do Plano C continuam abertas

Este plano **não** as resolve (ver
`docs/superpowers/reviews/2026-10-27-fluxo-painel-pendencias.md` §3):

- lembrete em card que não espera;
- falta de paginação no modo `ler`;
- apagar opção de card de botões.

O simulador as torna **visíveis** (ela percorre o fluxo e vê o que acontece), mas
não as corrige.

## Heranças para quem mexer nisso depois

- **O simulador lê o RASCUNHO.** É a única exceção à regra de o motor só ler
  `po_wa_fluxo_versoes.grafo`, e existe porque o pedido é testar antes de
  publicar. A montagem é a mesma função do Publicar (`wa_fluxo_grafo_de`); não
  existe segunda montagem.
- **Ele ignora `ativo` no salto `ir_para_fluxo`**, e isso aparece na tela como
  aviso. Sem essa diferença, com os dois fluxos desligados, ela nunca alcançaria
  a Lista de espera dentro do simulador.
- **Ele usa as funções de envio reais** (`wa_send_*`) atrás de um transporte
  falso, então os limites da Cloud API valem nele: 4 botões viram "mais de 3
  botoes", rótulo de 21 caracteres é recusado e não truncado, corpo acima de 1024
  não sai. Trocar isso por um envio de mentira faria a tela aprovar rascunho que
  a Meta recusa.
- **`wa_sim_passo` instala as dependências do simulador a cada passo**, em vez de
  confiar que quem chama as instalou: assim não existe caminho em que ele rode
  com o banco e o transporte de verdade.
- **A tela não roteia.** `painel/simulador.js` não decide para qual card ir, e há
  trava de fonte por busca de substring — os nomes das coisas proibidas não
  aparecem nem em comentário, que é o que torna a trava impossível de burlar. O
  mesmo vale para `lib/wa-sim.php` e as funções de gravação.
- **`WA_FLUXO_MAX_PASSOS` mudou de casa**, de `lib/wa-motor.php` para
  `lib/wa-fluxo.php`. O simulador precisa do mesmo teto e não pode requerer o
  motor (a seta é db/send/po-data ← fluxo ← sim/motor ← timeout).
- **O clique manda o id e mostra o rótulo.** Inverter qualquer um dos dois quebra
  alguma coisa: o rótulo no envio quebra o roteamento, o id na tela põe nome
  técnico numa conversa que o cliente veria.

## O que falta fazer

1. **Deploy por FTP**, com a conferência por hash da lista completa de `lib/` e
   `painel/` (nunca da lista do que se lembra de ter subido). Arquivos novos:
   `lib/wa-sim.php`, `painel/simulador.js`. Alterados: `lib/wa-fluxo.php`,
   `lib/wa-motor.php`, `lib/wa-roteiro.php`, `fluxo.php`, `painel/fluxo.js`,
   `painel/fluxo.css`, `painel/index.html`. O `painel/assets-lock.json` **não**
   sobe: é trava de teste.
2. **Ordem do upload:** os JS/CSS novos primeiro, o `painel/index.html` por
   último. Ao contrário existe uma janela em que a página pede arquivos que ainda
   dão 404.
3. **Ver a aba Fluxo logada**, com o botão Simular na barra. A tela foi conferida
   a 1440 e a 390 com envelopes reais, mas fora do painel logado: ninguém ainda
   abriu o simulador com a sessão da cliente. Lembrar da regra da casa — nenhuma
   tela do painel se julga por screenshot imediato; esperar de 5 a 10 segundos.
