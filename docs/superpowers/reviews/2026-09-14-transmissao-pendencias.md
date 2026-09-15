# Plano 4 (transmissão) — pendências e decisões

Registro do que ficou aberto e do que foi decidido durante a execução do plano 4, na
branch `plano4-transmissao`. Mesmo formato dos documentos dos planos 1 e 2.

**Estado:** 8 tarefas completas, 20 commits, suíte de 42 arquivos verde, revisão final da
branch aprovada para merge.

---

## 1. Pendências antes do MERGE

Uma só, e é de uma linha.

**A asserção de fonte do endpoint não cobre `payload_import`.**
`tests/test-campanha-endpoint.php`, no array da asserção que lista as colunas exigidas no
`select=` de `campanha.php`.

Removendo `payload_import` do `select`, a suíte inteira fica verde e o alerta de
"estrangeiro salvo com 00" morre em silêncio: `wa_camp_ddi_suspeito()` passa a receber
`null` sempre. É justamente a conferência que o `CLAUDE.md` manda fazer antes do primeiro
disparo pago, e é a mesma classe de defeito que a asserção acabou de fechar para
`opt_out_at`, `revisado` e `cliente`.

Conserto: acrescentar `'payload_import'` ao array.

---

## 2. Pendências antes do PRIMEIRO DISPARO PAGO

O sistema vai ao ar **inerte**. Ele só passa a gastar quando as chaves da Meta forem
configuradas e o cron for cadastrado no cPanel. Estes itens são gate desse marco, não do
merge.

**Ensaio com um destinatário.** Deixou de ser recomendação e virou pré-requisito, por
causa do item seguinte: o caminho real de `wa_send_template` não tem cobertura automatizada
(o enviador injetado nos testes descarta o template). Um template de teste com uma
variável, uma campanha de um destinatário, conferindo o recibo chegar em `po_wa_envios`.

**O modelo aprovado na Meta tem que ter exatamente uma variável.** `wa_camp_envia()` chama
sempre com um parâmetro. Modelo com zero ou duas faz a Graph devolver erro para **todo**
destinatário, a campanha inteira vira falha terminal, e o índice único impede re-reservar.
Não custa dinheiro, mas queima a campanha. A tela já avisa; o ensaio confirma.

**Conferir o tier real do número no painel da Meta.** `WA_CAMP_TETO_DIARIO` está em 250,
que é o piso para número novo. Subir exige conferir o tier de mensageria, não editar a
constante por conta. Com teto 250 e degrau inicial 50, uma base de 800 pessoas leva cerca
de quatro dias — que é o que a defesa de lotes crescentes pede.

**Conferir a lista importada contra estrangeiro discado com `00`.** Regra permanente do
projeto. `wa_camp_ddi_suspeito()` existe para isso e a prévia já mostra a contagem. Só vale
depois que as duas agendas entrarem.

**`modo=reservar` não revalida o número confirmado.** O `criar` prende o servidor ao total
que a cliente confirmou; o `reservar`, chamado em laço pelo painel, recalcula do zero. Hoje
é inerte (23 pessoas cabem numa rodada de 200), mas dispara com público acima de 200.

**O dreno não tem orçamento de tempo, ao contrário da reserva.** `wa_camp_reserva_lote()`
confere o relógio antes de cada escrita; `wa_camp_drena()` não confere nada e manda até o
lote inteiro em chamadas sequenciais de até 15 segundos. Pelo botão do painel, um lote
grande pode ser morto no meio pelo limite do cPanel — caindo na janela em que o envio saiu
e a gravação não aconteceu.

**Recibo perdido quando o telefone não normaliza.** `lib/wa-webhook.php` descarta o evento
de status se o destinatário não normaliza, embora o recibo busque por identificador de
mensagem e não precise do telefone. Custo: relatório de campanha paga furado.

---

## 3. Pendências sem prazo

**O dia do teto diário é em UTC**, então o contador zera às 21h de Florianópolis e uma
campanha da noite ganha teto novo.

**`wa_lead()` acha lead só por `wa_id`.** O SAIR de quem só tem `telefone` ainda cria uma
ficha nova em `po_leads`. A correção do C1 neutralizou o efeito na campanha — que era o
dinheiro e a defesa 3 — mas a ficha duplicada segue nascendo e aparece nas abas Clientes e
Funil. A correção durável é `wa_lead()` cair para telefone antes de inserir.

**`resumo['saiu']` conta fichas, não pessoas.** Uma pessoa com duas fichas aparece como
"2 pediram SAIR". Coerente com os outros baldes, mas superestima.

**`wa_camp_recibo` devolve o status novo mesmo quando a gravação falha.** Ninguém lê esse
retorno hoje; vira mentira quando o relatório de entrega for para a tela.

**O corpo aprovado não é mostrado de volta** na campanha em andamento.

**O botão de mandar lote agora não mostra custo nem pede confirmação**, ao contrário do
cancelar. É o único clique que gasta sem número na frente.

**`camp_leads()` traz `payload_import` da base inteira** em cada prévia, criação e reserva,
embora só a prévia use.

**Lacunas de cobertura pequenas:** ordem `cliente`/`revisado` sem caso cruzado; `wa_id` cru
sem caso; piso do degrau negativo; rigidez da regex do `check`; `$ev['tipo'] === 'mensagem'`
como código morto; modificador `/u` do regex do SAIR; janela entre a resposta da Meta e a
gravação do identificador.

---

## 4. Decisões tomadas durante a execução

Vinte e quatro no total. As que mudam o produto ou contrariam o plano estão aqui; o resto
era roteamento de trabalho.

**O público sai de `po_leads`, não de `po_wa_contatos`.** A spec 4.2 desenhou a segunda
tabela, mas ela está vazia e não é lida por nenhuma linha de código: tudo foi construído
sobre `po_leads`. Criar agora uma segunda tabela de pessoas forkaria a identidade e
quebraria a regra 3 do Armando. `opt_out_at` foi para `po_leads`.

**Os contadores de entrega não viram coluna.** `enviados`, `entregues`, `lidos` e `falhas`
são contados de `po_wa_envios` na hora de mostrar. Contador copiado desanda no primeiro
webhook fora de ordem, e relatório de entrega errado é pior que relatório nenhum.

**A tomada de posse da fila é por escrita condicional.** O índice único impede a mesma
ficha entrar duas vezes na campanha, mas não impede dois processos enviarem a mesma linha.
Quem escreve primeiro recebe a linha de volta; quem chega depois recebe vazio e pula.

**Risco aceito:** a posse cria uma classe nova de linha presa, quando o processo morre
entre o envio sair e a gravação acontecer. Recuperação automática em 30 minutos, com log
alto nomeando o identificador da mensagem. A alternativa, deixar preso para sempre, é pior:
o destinatário nunca recebe e ninguém fica sabendo.

**A migration foi emendada três vezes durante a execução**, porque ela ainda não foi
aplicada no banco. Ganhou o estado `enviando` e a coluna `enviando_at`, o índice único por
número, e a política de leitura restrita. Cada emenda evitou uma migration extra depois.

**Uma decisão minha foi revogada por uma revisão.** Eu tinha aceitado que `campanha.php`
ficasse sem teste, porque nenhum endpoint deste projeto tem. Envelheceu mal: os outros
endpoints não gastam dinheiro, e neste bastam duas mutações de uma palavra no `select` para
desligar duas das três defesas obrigatórias com a suíte verde. Entrou asserção de fonte.

**Duas defesas são cobradas nos dois lados.** A frase do SAIR no corpo da campanha e o
número confirmado pela cliente são validados na tela e de novo no servidor. A tela pode ser
contornada; o endpoint não.

---

## 5. Regras permanentes que saíram deste plano

- **O cadeado da campanha é por ficha E por número.** A Meta cobra por número, e a base tem
  ficha repetida da mesma pessoa (CRM antigo mais agenda). Só o índice por ficha deixava o
  mesmo telefone ser cobrado duas vezes.
- **Quem pede SAIR é excluído por NÚMERO, não por ficha.** A busca de lead acha só por
  identificador de WhatsApp, e as fichas importadas não têm esse campo, então o SAIR criava
  ficha nova enquanto a antiga continuava recebendo.
- **Reserve antes de enviar, e tome posse antes de chamar a Meta.** São duas travas
  diferentes: a primeira impede duplicar a linha, a segunda impede duplicar o envio.
- **Linha em estado intermediário conta como pendente.** Contar só o que está reservado faz
  a campanha ser concluída com gente sem receber, e a recuperação só roda enquanto alguém
  drena.
- **`falha` é terminal e fica no topo da escada de status.** Sem posto, ela caía no degrau
  zero e um recibo de entrega reentregue depois de uma falha ressuscitava o envio.
- **Trava no ponto de chamada, não só na função pura.** Vale para o `select` do endpoint, a
  fiação do cron e a idempotência do webhook. Três defeitos deste plano foram exatamente
  isso.
- **Teste que fabrica resposta que o servidor não manda não é teste.** Um dublê inventava
  um campo, e metade de uma guarda estava morta com a suíte verde.
- **Neste repositório, `git checkout --` altera fim de linha** e quebra o teste de
  cache-buster. Restaure arquivo por cópia de bytes.
