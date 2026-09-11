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
| Base de contatos | **Importada da agenda do celular** | É onde as listas dela vivem hoje. Ver seção 9. |

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

## 8. Transmissão

Substitui a lista de transmissão que a coexistência desativa. Não é uma funcionalidade extra:
é reposição de algo que a cliente usa a cada roteiro novo, mais ou menos a cada dois meses.

**O que ela ganha em relação à lista do app:** hoje a lista só é entregue a quem salvou o
número dela na agenda, e o app não avisa quando não entrega. Com 800 na lista e 300 que
salvaram, 500 pessoas nunca souberam do roteiro. Pelo painel, chega em todos, sem o teto de
256, com segmentação e com relatório.

**O que passa a custar:** ~R$ 0,31 por destinatário. 800 contatos = ~R$ 250 por disparo.

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

## 9. Importador de contatos

Entrada: `.vcf` (vCard) ou `.csv` exportado da agenda do celular ou do Google Contatos.

Rotina de limpeza, nesta ordem:

1. Normaliza para E.164: acrescenta `+55`, valida DDD, resolve o nono dígito de celular.
2. Descarta o que não é celular válido, fixos e números internacionais.
3. Deduplica por `wa_id`, mantendo o nome mais completo.
4. Limpa o nome ("Maria Grécia 2" vira "Maria"), preservando o original em `payload`.
5. Entra como `revisado = false`. **Nada é enviado antes da revisão.**

A tela de revisão lista em lotes, com ação em massa para marcar cliente, e cruza com
`po_leads` para pré-marcar quem já é lead conhecido.

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
6. **Importador e revisão de contatos** — seção 9.
7. **Tela de transmissão** — seção 8, com custo estimado e as defesas.
8. **`wa-cron.php`** — lembrete de 24h e encerramento de 48h.
9. **Conexão real** — troca do simulador pela Graph API, templates submetidos, homologação
   com número real.
10. **Treinamento e publicação.**
