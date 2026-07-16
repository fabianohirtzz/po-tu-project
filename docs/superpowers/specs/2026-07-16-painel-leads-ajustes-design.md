# Painel de leads — e-mail, lead manual, timeline de observações e ciclo de venda

Data: 2026-07-16 · Status: aprovado, em implementação

## Contexto

O painel (`painel/`) já tem Leads, Relatórios e Roteiros sobre `po_leads` /
`po_ad_spend` / `po_roteiros` no Supabase compartilhado. O formulário do site posta
em `enviar.php`, que grava no Supabase e notifica por SMTP autenticado.

Quatro ajustes pedidos pela cliente, mais uma limpeza no cadastro de roteiro.

## 1. E-mail do lead

- `$DESTINO` passa de `site@pereiraoliveiraturismo.com.br` para
  `poturismo@poturismo.com.br`. **Substitui**, não acumula.
- Assunto fixo + nome: `NOVO LEAD SITE PO TURISMO — <nome>`. O nome entra para que
  o Gmail não empilhe todos os leads numa thread única (assunto idêntico agrupa).
- O SMTP continua autenticando como `site@pereiraoliveiraturismo.com.br` — é a conta
  que existe no cPanel. Ela vira só remetente.
- `$DESTINO` passa a ser sobrescrevível no `config.local.php` (documentado no
  `.example`), para trocar o e-mail no servidor sem mexer em código.
- **Risco a validar no deploy:** o destino é outro domínio. Relay autenticado para
  fora deve funcionar, mas exige um lead de teste real para confirmar.

## 2. Adicionar lead manual

Botão "+ Adicionar lead" na barra da view Leads → gaveta `#ndrawer` (reaproveita o
CSS de `.drawer` / `.field`).

Campos: nome, telefone, e-mail, cidade, nº de viajantes, roteiro (datalist com os
roteiros cadastrados, aceita texto livre), mensagem, origem e **data de entrada**
(pré-preenchida com agora, editável).

A data é editável porque o ciclo de venda conta a partir dela: cadastrar hoje um lead
que chegou há 3 dias encurtaria o ciclo artificialmente.

Grava em `po_leads` com `origem_manual` = a origem escolhida e `origem` = 'Cadastro
manual' (o campo de rastreio bruto).

### Origens novas

`ORIG` ganha `instagram` (rosa) e `whatsapp` (verde #25D366). Os chips de filtro
passam a ser um por origem, com filtro exato: Todas / Pago / Orgânico / Social /
Instagram / WhatsApp / Direto.

KPIs e ROAS continuam separando só pago vs não-pago (`isPago`), então nenhuma métrica
de mídia muda de significado. O donut de "Leads por origem" ganha as fatias sozinho.

## 3. Observações em linha temporal

Coluna nova `notas jsonb not null default '[]'` em `po_leads`, guardando
`[{ts, txt}]`.

**Por que jsonb e não tabela separada:** a nota sempre é lida junto com o lead. Sem
tabela nova, sem policy nova, sem query extra. O risco de dois atendentes salvarem ao
mesmo tempo é coberto relendo as notas antes de anexar.

Na gaveta, o textarea solto de "Observações" dá lugar a: campo + botão "Adicionar", e
abaixo a timeline (mais recente em cima), cada entrada com data/hora automáticas e um
✕ para apagar. A nota grava na hora, não espera o "Salvar" — assim não se perde ao
fechar a gaveta.

A migration move o `observacoes` atual para a primeira entrada da timeline (datada na
criação do lead) e derruba a coluna, para não deixar campo morto no schema.

## 4. Ciclo de venda

Coluna nova `venda_at timestamptz`.

**Regra (ao salvar o lead):**
- Se `venda > 0` **ou** `status === 'venda'` e `venda_at` está vazio → carimba agora.
- Só na primeira vez: reeditar o valor não reinicia a contagem.
- Se o gatilho foi o valor (`venda > 0`), o status vira 'venda' automaticamente.
- Se `venda == 0` **e** `status !== 'venda'` → o carimbo é apagado.

Ciclo = dias entre `created_at` e `venda_at` (0 exibido como "menos de 1 dia").

**Onde aparece:**
- Gaveta do lead: linha "Ciclo de venda".
- Tabela de leads: coluna "Ciclo" depois de Venda.
- Relatórios: KPI "Ciclo de venda médio" + card "Ciclo de venda por lead" listando
  cada venda com nome e dias, do mais rápido ao mais lento, com mínimo/máximo.

A média considera leads que **entraram** no mês selecionado e já fecharam — responde
"quanto demorou para fechar os leads deste mês", não "quanto fechou neste mês". É
consistente com o resto dos relatórios, que já filtram por `created_at`.

Vendas anteriores à migration ficam com `venda_at` nulo (ciclo "—", fora da média).
Carimbar retroativamente com `created_at` reportaria ciclo 0, que seria mentira — não
havia registro do fechamento.

## 5. Campos do cadastro de roteiro

Saem **Data (card)** (`data_label`) e **Local (card)** (`local_label`) do formulário.
O campo **Ordem** fica.

Os três não estavam inertes — alimentam o site com fallback, por isso pareciam mortos:
- `local_label` → subtítulo do card no carrossel (`main.js`), fallback `subtitulo`
- `data_label` → data do card e período do hero (`main.js`, `roteiro-dynamic.js`),
  fallback `periodo`
- `ordem` → ordena o carrossel (`roteiros-shared.js`), por isso permanece

Os valores já gravados continuam no banco e no site; roteiros novos passam a usar
`periodo` / `subtitulo` pelos fallbacks. `collectRoteiro()` para de enviar os dois
campos, então um update não apaga o que já existe.

## Entregáveis

- `supabase/migrations/2026-07-16-leads-notas-ciclo.sql` (idempotente)
- `supabase/schema.sql` (refletir o estado)
- `enviar.php`, `config.local.example.php`
- `painel/index.html`, `painel/app.js`, `painel/painel.css`

Deploy: FTP (docroot direto) + rodar a migration no SQL Editor do Supabase.
