-- ============================================================
-- Pereira Oliveira: fluxo de conversa do robo (grafo de cards).
-- Rodar no Supabase → SQL Editor. Idempotente.
--
-- O roteamento da conversa deixa de ser uma maquina de estados escrita a
-- mao e passa a ser um grafo guardado no banco: fluxo -> cards -> versoes
-- publicadas. A conversa em andamento aponta para uma VERSAO (retrato
-- imutavel), entao editar o rascunho nunca muda uma conversa que ja comecou.
--
-- ATENCAO: o DDL daqui e idempotente, mas a CONVERSAO DE ESTADO do fim do
-- arquivo e de RODADA UNICA. Rodar esta migration de novo depois do go-live
-- (depois de o robo estar atendendo) colapsaria em 'encerrado' duas coisas que
-- 'novo' significa de verdade: a cadeia parada por falha de envio ('novo' COM
-- card_atual, que a proxima mensagem retoma) e a conversa recem-criada. As duas
-- voltariam a resolver a porta, e o cliente receberia as boas-vindas e o PDF
-- outra vez. Entao: rodar UMA vez, antes de ligar o robo. Depois disso, se
-- precisar reaplicar o DDL, rodar o arquivo SEM os dois `update` finais.
--
-- Spec: docs/superpowers/specs/2026-10-27-fluxo-conversa-painel-design.md, secao 7.
-- ============================================================

-- Fluxo: uma porta de entrada (gatilho) e o ponteiro para a versao publicada.
-- ativo nasce false: publicar nao liga o robo para ninguem.
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

-- Cards do rascunho. As setas (proximo, fallback, sem_resposta e o destino
-- de cada opcao) guardam o id de outro card do mesmo fluxo.
create table if not exists public.po_wa_cards (
  id                  uuid primary key default gen_random_uuid(),
  fluxo_id            uuid not null references public.po_wa_fluxos(id) on delete cascade,
  tipo                text not null,       -- WA_CARD_TIPOS
  titulo              text not null,
  texto               text,
  -- O `id` de cada opcao e OBRIGATORIO: e ele que o WhatsApp devolve quando o
  -- cliente toca. wa_send_buttons RECUSA id vazio ou repetido no mesmo card, e
  -- a mensagem inteira nao sai; no card inicial isso vira silencio para todo
  -- cliente que entra por aquela porta. `descricao` so vale na lista.
  opcoes              jsonb not null default '[]'::jsonb,  -- [{id, rotulo, destino, descricao?}]
  config              jsonb not null default '{}'::jsonb,  -- por tipo
  proximo             uuid,
  fallback            uuid,                -- saida "respondeu outra coisa"
  sem_resposta        uuid,
  lembrete_ativo      boolean not null default true,
  lembrete_texto      text,
  ordem               int not null default 0,
  x                   int not null default 0,
  y                   int not null default 0
);

-- Versoes publicadas: retrato imutavel do grafo no momento da publicacao.
create table if not exists public.po_wa_fluxo_versoes (
  id           uuid primary key default gen_random_uuid(),
  fluxo_id     uuid not null references public.po_wa_fluxos(id) on delete cascade,
  versao       int  not null,
  grafo        jsonb not null,             -- retrato imutavel
  publicado_at timestamptz not null default now(),
  unique (fluxo_id, versao)
);

-- Uma porta de entrada por vez, entre os fluxos ATIVOS. Indice parcial: duas
-- portas iguais podem coexistir se uma estiver desligada. O coalesce faz o
-- gatilho_valor nulo valer como string vazia; sem ele, dois fluxos "qualquer
-- anuncio" passariam, porque null nunca e igual a null num indice unico.
create unique index if not exists po_wa_fluxos_porta_ativa_uniq
  on public.po_wa_fluxos (gatilho, coalesce(gatilho_valor, ''))
  where ativo;

-- A conversa passa a saber onde esta.
alter table public.po_wa_conversas add column if not exists fluxo_versao_id uuid;
alter table public.po_wa_conversas add column if not exists card_atual      uuid;
alter table public.po_wa_conversas add column if not exists tentativas      int not null default 0;

-- Marcadores de interesse e de espera, no funil que ja existe.
alter table public.po_leads add column if not exists interesses jsonb not null default '[]'::jsonb;
alter table public.po_leads add column if not exists espera     jsonb not null default '[]'::jsonb;

-- RLS no padrao das demais: o painel le e escreve autenticado, o PHP escreve
-- com service_role (que ignora RLS e so existe no servidor).
alter table public.po_wa_fluxos        enable row level security;
alter table public.po_wa_cards         enable row level security;
alter table public.po_wa_fluxo_versoes enable row level security;

-- drop+create em vez de "create policy if not exists": nem toda versao do
-- Postgres aceita IF NOT EXISTS em CREATE POLICY, e drop+create e idempotente
-- em qualquer versao. Escrito por extenso (sem loop) para ficar legivel.
drop policy if exists po_wa_fluxos_auth on public.po_wa_fluxos;
create policy po_wa_fluxos_auth on public.po_wa_fluxos
  for all to authenticated using (true) with check (true);

drop policy if exists po_wa_cards_auth on public.po_wa_cards;
create policy po_wa_cards_auth on public.po_wa_cards
  for all to authenticated using (true) with check (true);

drop policy if exists po_wa_fluxo_versoes_auth on public.po_wa_fluxo_versoes;
create policy po_wa_fluxo_versoes_auth on public.po_wa_fluxo_versoes
  for all to authenticated using (true) with check (true);

-- Conversao dos estados antigos, na mesma migration. Hoje as tabelas de
-- conversa estao vazias, mas o motor novo pode subir antes desta migration
-- rodar, e uma linha com estado antigo sairia do radar da varredura.
-- RODADA UNICA (ver o cabecalho): depois do go-live, estes dois `update` nao
-- se repetem. 'novo' COM card_atual e cadeia parada, e nao conversa antiga.
update public.po_wa_conversas set estado = 'aguardando'
  where estado in ('aguardando_roteiro','enviado_roteiro');
update public.po_wa_conversas set estado = 'encerrado'
  where estado in ('qualificado','desqualificado','novo');
