# Automação de WhatsApp + funil automático

> Spec de design. Pereira Oliveira Turismo · 11/09/2026
> Status: aprovada nas decisões, pendente de revisão do Fabiano antes do plano de implementação.

---

## 1. O problema

Todo lead que chega no WhatsApp recebe hoje o mesmo trabalho manual: a cliente digita as
informações do roteiro e faz duas perguntas de qualificação (data da viagem e experiência
prévia em grupo). Quem responde sim para as duas vira atendimento pessoal.

Dois custos disso:

1. **O lead de madrugada espera.** O anúncio do Instagram roda 24h, o atendimento não.
2. **O funil não existe.** O painel de leads foi construído e não é usado, porque preencher
   CRM no meio do atendimento exige parar a conversa para registrar a conversa. Isso não vai
   mudar pedindo disciplina: precisa ser automático.

**Regra que orienta o desenho inteiro:** nada que dependa de um passo manual extra no meio do
dia vai acontecer. O funil tem que se alimentar da conversa que já existe.

---

## 2. Decisões tomadas (e por quê)

| Decisão | Escolha | Motivo |
|---|---|---|
| Canal | **Cloud API oficial da Meta**, sem BSP | Sem risco de banimento do número, que é ativo comercial desde 1967. Sem mensalidade de intermediário. Dá atribuição de anúncio. |
| Modo | **Coexistência** (app + API no mesmo número) | A cliente continua atendendo pelo celular como sempre. Sem inbox nova para aprender. |
| Motor | **PHP próprio no cPanel** da ereHost | Mesma stack do site (`enviar.php`, `roteiro.php`). n8n exigiria VPS ou assinatura; ManyChat/BotConversa exigiriam a mesma ponte com o Supabase e ainda cobrariam mensalidade. |
| IA | **Nenhuma** | O roteiro é identificado por anúncio, por link ou por casamento de palavra no catálogo. Isso zera custo variável de IA e remove uma peça que pode falhar. |
| Etapas manuais | **Atalho no WhatsApp + kanban arrastável** | Proposta e fechamento são atos dela. Adivinhar por texto livre erra, e funil errado é pior que funil vazio. |
| Transmissão | **Pelo painel, via template** | A coexistência desativa a lista de transmissão do app. Ver seção 8. |
| Base de contatos | **Três fontes: CRM antigo + duas agendas de celular** | O CRM tem 778 fichas com documento; as agendas (dela e do marido) têm os telefones dos clientes, marcados no nome. Ver seção 9. |

### 2.1 O que a coexistência custa

Com o modo ativo, no aplicativo: **listas de transmissão saem** (tratado na seção 8),
mensagens temporárias e localização em tempo real param, e grupos continuam mas não
sincronizam. O app precisa ser aberto ao menos uma vez a cada ~13 dias ou a conexão da API
cai. Throughput fixo de 5 mensagens por segundo, irrelevante neste volume.

### 2.2 Custos de mensagem (Meta, Brasil)

- Mensagem recebida: **grátis, sempre**.
- Mensagem enviada **pelo app** (coexistência): **grátis, sempre** — não passa pela API.
- Resposta do robô dentro da janela de 24h: grátis até 30/09/2026; a partir de 01/10/2026
  cobrada com franquia de **1.000/mês por número**. No volume atual o robô não chega perto.
- Lead vindo de **anúncio Click-to-WhatsApp**: janela gratuita de **72h**, qualquer categoria.
  Confirmado que os anúncios dela são CTWA, então o fluxo principal é gratuito.
- **Template de marketing (transmissão): ~R$ 0,31 por destinatário.** É o único custo relevante.

---

## 3. Arquitetura

```
Anúncio CTWA / bio / site
          │
          ▼
   WhatsApp da cliente ◄──────── ela responde normal, no celular
          │  (Cloud API, coexistência)
          ▼
   whatsapp.php ── webhook: mensagens do cliente + eco do que ela digita
          │
          ├── wa-motor.php    máquina de estados da conversa
          ├── wa-send.php     fala com a Graph API
          │
          ▼
      Supabase ── po_leads (funil) + po_wa_* (conversa, contatos, campanhas)
          │
          ▼
      /painel ── Kanban · Conversa no lead · Transmissão · Textos
```

Arquivos novos na raiz (padrão dos que já existem): `whatsapp.php`, `wa-send.php`,
`wa-motor.php`, `wa-cron.php`, `wa-campanha.php`, `wa-contatos.php`.
Segredos (token da Graph API, verify token, app secret) em `config.local.php`, nunca no Git.

---

## 4. Modelo de dados

### 4.1 Alterações em `po_leads`

Colunas novas. Nada é removido, nada é renomeado: os relatórios existentes continuam
funcionando sem alteração.

| Coluna | Tipo | Para quê |
|---|---|---|
| `wa_id` | text, único | Telefone em E.164 do contato no WhatsApp. Chave de ligação com a conversa. |
| `ctwa_clid` | text | Identificador do clique no anúncio. Atribuição real de campanha. |
| `ad_id` | text | ID do anúncio de origem. |
| `qualif_data` | boolean | Respondeu que tem disponibilidade na data. |
| `qualif_grupo` | boolean | Já viajou em grupo. |
| `qualif_at` | timestamptz | Quando completou a qualificação. |
| `proposta_at` | timestamptz | Quando a proposta foi enviada (atalho ou PDF). |

**Bloco de ficha do cliente** (adotado do CRM antigo em 12/09/2026 — ver seção 9). Todas
opcionais, todas `text` salvo indicação. O importador preenche; o painel exibe e edita na
ficha. Nenhuma quebra os relatórios existentes.

| Coluna | Tipo | Para quê |
|---|---|---|
| `cpf` | text | Documento. **Chave de identidade mais confiável** na deduplicação (ver 9.2). |
| `rg` | text | Documento. |
| `data_nascimento` | date | Ficha + fallback de identidade (nome + nascimento). |
| `nacionalidade` | text | Ficha. |
| `estado_civil` | text | Ficha. |
| `profissao` | text | Ficha. |
| `cep` · `endereco` · `numero` · `complemento` · `bairro` · `estado` | text | Endereço completo (`cidade` já existe). |
| `passaporte_numero` · `passaporte_orgao_emissor` · `passaporte_emissao` · `passaporte_validade` | text/date | Documento de viagem. `validade` alimenta alerta futuro de passaporte vencendo. |
| `contato_emergencia_nome` · `contato_emergencia_telefone` · `contato_emergencia_parentesco` | text | Exigido pela operadora no embarque. |
| `telefone_secundario` | text | Segundo número da ficha. |
| `observacoes` | text | Nota permanente do cliente (distinta de `notas`, que é a linha do tempo do funil). |
| `origem_import` | text | De qual fonte veio: `crm-toninho`, `agenda-esposa`, `agenda-marido`, `formulario`, `whatsapp`. |
| `payload_import` | jsonb | O registro original da fonte, íntegro. **Rede de segurança: nada da origem se perde**, mesmo o que não vira coluna. |

### 4.2 Tabelas novas

**`po_wa_contatos`** — a base. Um registro por pessoa.
`id` · `wa_id` (E.164, único) · `nome` · `origem` (`importado`\|`lead`\|`manual`) ·
`cliente` (boolean, marcado na revisão) · `revisado` (boolean) · `opt_out_at` ·
`ultimo_contato_at` · `roteiros` (jsonb, quais já fez ou pediu) · `created_at`

**`po_wa_conversas`** — estado por contato. Um registro ativo por `wa_id`.
`id` · `wa_id` · `estado` (ver seção 5) · `roteiro_slug` · `lead_id` · `janela_expira_at` ·
`aguardando_desde` · `lembrete_enviado` (boolean) · `silenciado_at` · `updated_at`

**`po_wa_mensagens`** — log completo, alimenta a aba de conversa no lead.
`id` · `wa_id` · `wamid` (**único**, é a idempotência) · `direcao` (`in`\|`out`) ·
`autor` (`cliente`\|`robo`\|`humano`) · `tipo` · `texto` · `payload` (jsonb) · `ts`

**`po_wa_campanhas`** — uma transmissão.
`id` · `nome` · `roteiro_slug` · `template` · `segmento` (jsonb) · `total` · `enviados` ·
`entregues` · `lidos` · `cliques` · `falhas` · `custo_estimado` · `custo_real` ·
`status` (`rascunho`\|`enviando`\|`concluida`\|`cancelada`) · `created_at`

**`po_wa_envios`** — um destinatário dentro de uma campanha.
`campanha_id` · `contato_id` · `wamid` · `status` · `erro` · `enviado_at`

**`po_wa_anuncios`** — mapa anúncio para roteiro.
`ad_id` (único) · `roteiro_slug` · `rotulo`

**`po_wa_textos`** — as mensagens do robô, editáveis pelo painel sem deploy.
`chave` (única) · `texto` · `updated_at`

RLS ligada em todas, no padrão das tabelas existentes: leitura e escrita só para usuário
autenticado; o PHP escreve com `service_role`, que ignora RLS e vive só no servidor.

---

## 5. A máquina de estados da conversa

```
                 mensagem nova de um wa_id desconhecido
                                │
                                ▼
                    ┌──────────────────────┐
                    │ identifica o roteiro │
                    └──────────┬───────────┘
              achou ───────────┴─────────── não achou
                │                                │
                ▼                                ▼
      ┌──────────────────┐            ┌──────────────────────┐
      │  ENVIADO_ROTEIRO │            │  AGUARDANDO_ROTEIRO  │
      │ PDF + 2 perguntas│            │  menu de roteiros    │
      └────────┬─────────┘            └──────────┬───────────┘
               │                                 │ escolheu
               │  ◄──────────────────────────────┘
               │
       responde ├── sim + sim ──────► QUALIFICADO ──► avisa que a equipe assume
                ├── não na data ────► DESQUALIFICADO
                └── 24h em silêncio ► lembrete ──► +24h ──► ENCERRADO

     em QUALQUER estado, um eco de mensagem dela (smb_message_echoes) ──► HUMANO
     HUMANO = robô silenciado nesse contato, permanentemente
```

**Identificação do roteiro, nesta ordem:**

1. `referral.source_id` do webhook (veio de anúncio) consultando `po_wa_anuncios`.
2. Marcador no texto pré-preenchido do link `wa.me` do site (`[r:<slug>]`, invisível na prática).
3. Casamento por palavra contra `po_roteiros` ativos: título, destino e apelidos
   (`grécia`, `cerejeira`, `natal`, `turquia`...). Exige correspondência única; duas
   correspondências caem no menu.
4. Menu de lista interativa com os roteiros ativos.

**Regra de silêncio:** o eco é o sinal de handoff. No instante em que ela digita qualquer coisa
naquela conversa pelo celular, o robô se cala ali para sempre. Isso resolve o pior modo de
falha possível: robô e humano falando por cima um do outro com o cliente.

**Atalhos** (capturados do eco, aceitos em qualquer ponto):

- `#proposta` → etapa "proposta enviada", carimba `proposta_at`
- `#fechou 22900` → etapa "contrato assinado", grava venda e `venda_at`
- `#perdeu` → etapa "perdido"

Sinal complementar: ela enviar um **documento PDF** numa conversa já qualificada também marca
proposta enviada. É alta confiança no fluxo dela, e cobre o esquecimento do atalho.

---

## 6. O webhook: contrato e garantias

`whatsapp.php` é o único ponto de entrada.

- **GET** com `hub.verify_token` responde o desafio de verificação da Meta.
- **POST** valida a assinatura `X-Hub-Signature-256` com o app secret. Assinatura inválida
  devolve 403 e não processa nada.
- **Idempotência por `wamid`**, com índice único em `po_wa_mensagens.wamid`. A Meta reenvia o
  webhook quando não recebe 200 rápido; sem isso o cliente receberia o roteiro duas vezes.
  Grava primeiro, processa depois: se o `wamid` já existe, responde 200 e encerra.
- **Responde 200 sempre**, mesmo em erro interno, logando a falha. Devolver erro faz a Meta
  reenviar em loop e, na repetição, derrubar a inscrição do webhook.
- Eventos tratados: `messages` (cliente), `smb_message_echoes` (ela, pelo app),
  `statuses` (entregue/lido, alimenta o relatório de campanha).

---

## 7. Etapas do funil

Mapeamento com os status que já existem, escolhido para **não quebrar nenhum relatório**
(`venda` e `venda_at` alimentam ROAS, ciclo e conversão, e ficam intocados):

| Coluna do kanban | `po_leads.status` | Como entra |
|---|---|---|
| Contato feito | `novo` *(único status novo)* | automático, primeira mensagem |
| Qualificado | `atendimento` | automático, sim para as duas perguntas |
| Proposta enviada | `negociacao` | atalho `#proposta` ou PDF enviado |
| Contrato assinado | `venda` | atalho `#fechou` |
| Perdido | `perdido` | automático, por resposta negativa ou 48h de silêncio |

`semresposta` continua existindo para os leads antigos e para quem chega pelo formulário do
site sem passar pelo WhatsApp. Nenhum registro histórico é reescrito.

---

## 7.1 O painel: abas e a ficha do cliente

> Decidido em 11/09/2026, depois do plano 1. Amplia a seção 7.

O painel passa a ter quatro abas: **Clientes** (a aba Leads de hoje, evoluída),
**Funil** (o kanban, nova), Relatórios e Roteiros.

**A pessoa e o interesse são coisas diferentes.** Hoje `po_leads` tem uma linha por
contato: a mesma pessoa pedindo a Grécia em 2026 e a Turquia em 2027 vira duas linhas sem
ligação nenhuma, e a ficha dela não existe. Para uma operadora de viagem em grupo isso não
é detalhe, porque **quem já viajou é o melhor lead do próximo roteiro**, e é exatamente esse
recorte que a transmissão (seção 8) vai querer.

**A ficha é uma agregação, não uma tabela nova.** A aba Clientes lista pessoas únicas
agrupadas por telefone em E.164, e a ficha junta o que já existe: os dados de
`po_wa_contatos`, todos os leads daquela pessoa em `po_leads`, a conversa em
`po_wa_mensagens` e as anotações de `notas`. Sem migration, sem backfill, sem regra de
deduplicação para manter.

Por que não uma tabela `po_clientes`: ela é o modelo mais correto e aguenta crescer sem
limite, mas custa migration, backfill e uma regra de quando dois telefones são a mesma
pessoa. No volume desta agência (seis roteiros por ano, leads na casa das centenas) a
agregação sustenta por anos, e a tabela pode nascer depois sem jogar fora o que for feito
agora — o agrupamento por E.164 já é a chave que ela usaria.

**Consequência para o telefone:** o agrupamento só funciona se o telefone estiver sempre no
mesmo formato. `wa_e164()` garante isso do lado do WhatsApp, mas o `enviar.php` ainda grava
o que o visitante digitou no formulário do site (pendência registrada). Enquanto isso não
for corrigido, a mesma pessoa vinda dos dois caminhos aparece como duas fichas.

**Mover card no celular:** arrastar no desktop com os eventos nativos de arrastar do HTML,
e no celular um menu de etapas ao tocar no card. Sem biblioteca de terceiro: o projeto não
tem build nem dependência de front além do cliente do Supabase, e arrastar por toque não
existe em HTML puro.

**A conversa** fica numa aba dentro da gaveta de edição que já existe (Dados · Conversa),
para tudo sobre o lead viver num lugar só.

---

## 8. Transmissão

Substitui a lista de transmissão que a coexistência desativa. Não é uma funcionalidade extra:
é reposição de algo que a cliente usa a cada roteiro novo, mais ou menos a cada dois meses.

**O que ela ganha em relação à lista do app:** hoje a lista só é entregue a quem salvou o
número dela na agenda, e o app não avisa quando não entrega. Com 800 na lista e 300 que
salvaram, 500 pessoas nunca souberam do roteiro. Pelo painel, chega em todos, sem o teto de
256, com segmentação e com relatório.

**O que passa a custar:** ~R$ 0,31 por destinatário. O número real de destinatários só se
conhece depois de importar as agendas — o CRM sozinho tem só 172 telefones, e a transmissão
manda **só para celular** (fixo e internacional entram na ficha mas não no disparo). A tela
mostra a contagem e o custo antes de confirmar (8.2).

### 8.1 Defesas obrigatórias

Agenda de celular não é lista de consentimento: tem fornecedor, contador e desconhecido no
meio. Alcançar todo mundo remove a proteção acidental que a regra do "salvou o número" dava.
Três defesas, desenhadas como parte do produto e não como opção:

1. **Revisão antes do primeiro disparo.** Contato não revisado nunca entra em campanha.
   A tela exige marcar quem é cliente.
2. **Lotes crescentes.** O primeiro envio vai para um grupo pequeno; o tamanho sobe conforme
   o número mantém boa qualidade. Casa com o teto da Meta (250/dia antes da verificação,
   2.000/dia depois).
3. **Saída em toda mensagem.** "Responda SAIR para não receber mais", com baixa automática
   em `opt_out_at`. Quem saiu é excluído de toda campanha futura, sem exceção.

### 8.2 Custo na tela antes de confirmar

A tela mostra **quantas pessoas e quanto vai custar** antes do envio. Nada dispara sem esse
aviso. Custo invisível em fatura de terceiro é como projeto assim perde a confiança do cliente.

---

## 9. Importador de contatos e auditoria de base

> Reescrito em 12/09/2026 depois de auditar as três fontes reais. A versão anterior
> assumia uma fonte só (agenda) e identidade só por telefone. As duas premissas caíram.

São **três fontes**, e elas se completam em vez de se repetir:

| Fonte | O que traz | O que falta |
|---|---|---|
| **CRM antigo** (sistema do Netlify) | 778 fichas com CPF (423), passaporte (289), endereço (567), nascimento (445) | **Telefone: só 172 de 778.** 606 sem nenhum. |
| **Agenda da cliente** (`.vcf`) | Telefones de clientes | Sem documento. Misturada com não-clientes. |
| **Agenda do marido** (`.vcf`) | Telefones de clientes | Idem. |

O CRM é rico em documento e pobre em telefone; as agendas são o inverso. Por isso **o
telefone não pode ser a chave de identidade** (78% do CRM não tem), e a importação não é
uma carga: é uma **auditoria de fusão** entre as três.

### 9.1 O marcador "PO" nas agendas

Informação do Armando (dono, 12/09): todo cliente salvo nas agendas deles tem o nome
marcado — `- Cliente PO`, `- PO` ou variantes. A agenda tem sujeira (família, médico,
fornecedor); **o marcador é o filtro que separa cliente de ruído.** O CRM não tem esse
marcador (conferido: 0 fichas), e não precisa — lá tudo já é cliente.

Regra: da agenda, **só entra quem tem o marcador**. O resto é ignorado (não descartado com
alarde: some da importação, silenciosamente). O padrão do marcador é configurável, porque a
grafia varia entre os dois aparelhos.

### 9.2 Identidade e deduplicação — as regras do Armando

As três regras, ditas por ele, são critério de aceitação, não recomendação:

1. **O registro com histórico é o dono.** Uma ficha que já tem histórico (veio do CRM, ou
   já é lead com conversa/venda/nota) **nunca é sobrescrita nem substituída** por um contato
   de agenda.
2. **A fusão só soma, nunca zera.** Campo vazio na fonte nova jamais apaga campo preenchido
   no registro existente. A agenda serve para **preencher o telefone que faltava** numa ficha
   do CRM — não para mexer no resto.
3. **Nunca duplicar.** Nenhum registro novo é criado sem antes tentar casar com um existente.

**Casamento por camadas, nesta ordem** (a primeira que bater vence):

1. **CPF** normalizado — chave limpa e única (0 duplicatas nas 778). Só existe entre fichas
   de CRM, mas é o casamento de maior confiança.
2. **Telefone** em E.164 (via `wa_e164()`, o normalizador único — ver pendência). Casa
   agenda ↔ CRM, mas só alcança as 172 fichas que têm telefone. **Cuidado:** há telefones
   fixos repetidos entre fichas (um fixo de SP em 3 fichas — número de empresa, não de
   pessoa). Fixo nunca funde duas pessoas; só celular casa identidade.
3. **Nome normalizado + data de nascimento** — rede final, e só quando os dois batem.

O que **não casa em nenhuma camada** não é fundido nem inventado: entra como candidato novo
na **tela de revisão**, com `revisado = false`. Fusão automática só acontece com casamento
de CPF ou de celular; nome+nascimento sozinho **sugere**, não funde. Nada de criar ou
sobrescrever no escuro.

### 9.3 Ordem de importação (por que o CRM entra primeiro)

O CRM é a semente: entra primeiro, íntegro, como base-mestra marcada `cliente = true`. As
agendas entram depois, casando contra essa base já assentada. Assim, quando um telefone de
agenda casa por celular com uma ficha de CRM, ele **completa** a ficha (regra 2) em vez de
criar uma segunda. As duas agendas também se deduplicam entre si (o mesmo cliente pode estar
nos dois aparelhos).

### 9.4 Limpeza de cada registro

1. Normaliza telefone para E.164 com `wa_e164()`: `+55`, valida DDD, resolve o nono dígito.
   Fixo e internacional são preservados na ficha, mas **não entram na transmissão** (seção 8).
2. Limpa o nome: tira o marcador e a poluição de busca ("Maria Grécia 2 - PO" vira "Maria"),
   **preservando o original em `payload_import`**.
3. Guarda a fonte em `origem_import` e o registro cru em `payload_import`.
4. Entra com `revisado = false` quando veio de agenda. **Nada é transmitido antes da revisão.**

### 9.5 Tela de revisão e auditoria

Lista em lotes, com ação em massa para marcar cliente. Antes de qualquer escrita, mostra o
**resultado da auditoria**: quantos casaram por CPF, quantos por celular, quantos são
candidatos novos, e **quais fusões vão preencher campos** — para a decisão ser vista, não
adivinhada. Casos ambíguos (só nome+nascimento) aparecem lado a lado para o humano confirmar.

### 9.6 A fonte CRM e um alerta de segurança

O CRM antigo (Netlify + Supabase próprio) **expõe os 778 cadastros sem autenticação**: a
chave de acesso está no código público e a tela de login é decorativa. CPF, RG e passaporte
de 778 pessoas ficam legíveis para qualquer um com o endereço — exposição de dado sensível,
questão de LGPD. **Não é sistema nosso.** Registrado aqui para: (a) avisar o cliente que o
link não deve circular; (b) quando reaproveitarmos a base, ela vai para o **nosso** Supabase,
com login e RLS de verdade.

---

## 10. Segurança

- `config.local.php` guarda token da Graph API, app secret e verify token. Fora do Git,
  como já é feito com SMTP, `service_role` e `GEMINI_API_KEY`.
- Assinatura do webhook validada sempre (seção 6).
- `service_role` só no servidor. O painel usa `anon key` com RLS e login, sem mudança.
- Conteúdo vindo do WhatsApp é dado de terceiro: **sempre escapar** ao renderizar no painel,
  com a mesma disciplina do `po_e`/`po_css_url` no `roteiro.php`.
- Texto de mensagem recebida nunca é interpretado como instrução, só casado contra o catálogo.

---

## 11. Fora de escopo

- Atendimento com IA conversando livremente no lugar do robô.
- Outros canais além do WhatsApp.
- Fluxos de pós-venda (documentos da viagem, lembretes de embarque).
- Pagamento dentro do WhatsApp.
- App Review público da Meta para onboardar terceiros: o app é de uso próprio da cliente.

---

## 12. Riscos

| Risco | Probabilidade | Mitigação |
|---|---|---|
| Verificação do negócio na Meta demorar ou ser recusada | média | Submetida em 11/09. Desenvolvimento roda em paralelo contra simulador. Documentos conferidos contra o Cartão CNPJ. |
| Embedded Signup exigir revisão de app | **a confirmar** | Item aberto. Se exigir, some uma etapa antes da conexão. Não bloqueia o desenvolvimento. |
| Qualidade do número cair por denúncia na transmissão | média | As três defesas da seção 8.1. |
| App não aberto por 13 dias derruba a conexão | baixa | Monitoramento: se o webhook ficar mudo além do esperado, alerta por e-mail. |
| Meta reenviar webhook e duplicar mensagem | alta se não tratado | Idempotência por `wamid` (seção 6). |
| Robô e humana falando ao mesmo tempo | alta se não tratado | Regra de silêncio pelo eco (seção 5). |

---

## 13. Ordem de implementação

Cada etapa é entregável e testável sozinha. As três primeiras não dependem da Meta.

1. **Migrations** — as tabelas da seção 4. Base de tudo.
2. **`wa-send.php` + simulador** — envio de texto, documento e lista, com um modo local que
   registra em vez de chamar a Graph API. Permite construir o resto sem a conta pronta.
3. **`whatsapp.php` + `wa-motor.php`** — webhook, idempotência, máquina de estados, atalhos,
   regra de silêncio. Testado ponta a ponta contra o simulador.
4. **Kanban no painel** — as cinco colunas, arrastável, sobre o `po_leads`.
5. **Conversa dentro do lead** — a aba que lê `po_wa_mensagens`.
6. **Importador, auditoria de fusão e semente do CRM** — seção 9. Campos de ficha (4.1),
   casamento por camadas, regras do Armando travadas por teste, carga das 778 do CRM como
   base-mestra, depois o merge das agendas com revisão. **Este é o plano 3.**
7. **Tela de transmissão** — seção 8, com custo estimado e as defesas.
8. **`wa-cron.php`** — lembrete de 24h e encerramento de 48h.
9. **Conexão real** — troca do simulador pela Graph API, templates submetidos, homologação
   com número real.
10. **Treinamento e publicação.**
