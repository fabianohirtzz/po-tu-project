-- Plano 4: transmissao (spec secao 8).
--
-- DECISAO REGISTRADA: o publico sai de po_leads, NAO de po_wa_contatos.
-- A spec 4.2 desenhou po_wa_contatos como "a base", mas a tabela esta vazia
-- e nao e lida por nenhuma linha de codigo: os planos 1 a 3 construiram tudo
-- sobre po_leads (as 775 fichas do CRM, o painel, o wa_lead() do motor).
-- Uma segunda tabela de pessoas forkaria a identidade e quebraria a regra 3
-- do Armando (nunca duplicar). Por isso opt_out_at entra em po_leads.

create table if not exists public.po_wa_campanhas (
  id                      uuid primary key default gen_random_uuid(),
  nome                    text        not null,
  roteiro_slug            text,
  template                text        not null,   -- nome do template aprovado na Meta
  corpo                   text        not null,   -- nossa copia do corpo, para a previa e para exigir o SAIR
  segmento                jsonb       not null default '{}'::jsonb,
  -- total e custo sao FOTOGRAFIA do momento da criacao, e por isso ficam aqui.
  -- Ja enviados/entregues/lidos/falhas NAO tem coluna de proposito: eles sao
  -- contados de po_wa_envios na hora de mostrar. Contador copiado desanda em
  -- silencio no primeiro retry ou webhook fora de ordem, e um relatorio de
  -- entrega errado e pior que nenhum - foi a mesma licao do somatorio de
  -- venda no painel, que inflava o faturamento para sempre.
  total                   integer     not null default 0,
  preco_centavos          integer     not null,   -- congelado na criacao: o preco da Meta muda
  custo_estimado_centavos integer     not null default 0,
  status                  text        not null default 'rascunho',
  created_at              timestamptz not null default now(),
  concluida_at            timestamptz,
  constraint po_wa_campanhas_status_chk
    check (status in ('rascunho','enviando','concluida','cancelada'))
);

create table if not exists public.po_wa_envios (
  id           uuid primary key default gen_random_uuid(),
  campanha_id  uuid        not null references public.po_wa_campanhas(id) on delete cascade,
  lead_id      uuid        not null references public.po_leads(id)        on delete cascade,
  wa_id        text        not null,
  -- Nome FOTOGRAFADO na reserva, nao lido do lead na hora do envio: e o nome
  -- que a cliente viu e aprovou na previa. Se a ficha for editada entre a
  -- confirmacao e o dreno, o template ainda sai com o nome revisado. E o
  -- parametro {{1}} do template, entao vazio aqui e "Ola ," no cliente.
  nome         text        not null default '',
  wamid        text,
  status       text        not null default 'reservado',
  erro         text,
  reservado_at timestamptz not null default now(),
  -- Marca a TOMADA DE POSSE da linha, antes da chamada a Meta. Sem um estado
  -- intermediario, dois drenos simultaneos (o cron e o botao do painel) leem as
  -- mesmas linhas `reservado` e mandam a mesma mensagem duas vezes, cobrada duas
  -- vezes. O reservado_at nao serve: ele marca quando a pessoa entrou na campanha.
  enviando_at  timestamptz,
  enviado_at   timestamptz,
  entregue_at  timestamptz,
  lido_at      timestamptz,
  constraint po_wa_envios_status_chk
    check (status in ('reservado','enviando','enviado','entregue','lido','falha'))
);

-- O CADEADO da reserva-antes-do-envio. O insert que conflita devolve 409, o
-- wa_db_insert_status devolve o status para o chamador (wa_camp_reserva)
-- separar o cadeado (409) de erro de verdade, e o destinatario e pulado. Sem
-- isto, dois drenos ao mesmo tempo (o cron e o botao do painel) mandam a
-- mesma campanha duas vezes para a mesma pessoa, a R$ 0,31 cada.
create unique index if not exists po_wa_envios_camp_lead_uniq
  on public.po_wa_envios (campanha_id, lead_id);

-- Por onde o recibo de entrega acha o envio. E aqui que a idempotencia de
-- ENTREGA vive, e nao em po_wa_mensagens: o wamid de um evento `status` E o
-- wamid da mensagem original, e po_wa_mensagens.wamid e unique, entao recibo
-- e eco competiriam pela mesma linha e o eco viraria `duplicado` - fazendo o
-- robo NAO se calar. Parcial porque a linha nasce reservada, sem wamid.
create unique index if not exists po_wa_envios_wamid_uniq
  on public.po_wa_envios (wamid) where wamid is not null;

create index if not exists po_wa_envios_campanha_status_idx
  on public.po_wa_envios (campanha_id, status);

-- Defesa 3 da spec 8.1: "Responda SAIR para nao receber mais", com baixa
-- automatica. Quem saiu e excluido de toda campanha futura, sem excecao.
alter table public.po_leads add column if not exists opt_out_at timestamptz;

create index if not exists po_leads_opt_out_idx
  on public.po_leads (opt_out_at) where opt_out_at is not null;

-- RLS no padrao das tabelas existentes (po_wa_mensagens_auth, po_wa_conversas_auth):
-- so usuario autenticado. O PHP escreve com a service_role, que ignora RLS.
alter table public.po_wa_campanhas enable row level security;
alter table public.po_wa_envios    enable row level security;

drop policy if exists po_wa_campanhas_auth on public.po_wa_campanhas;
create policy po_wa_campanhas_auth on public.po_wa_campanhas
  for all to authenticated using (true) with check (true);

-- Envios sao SO LEITURA para o painel: quem escreve e o dreno, com a
-- service_role. Painel que pudesse marcar 'enviado' na mao mentiria no
-- relatorio de entrega e no custo.
drop policy if exists po_wa_envios_auth on public.po_wa_envios;
create policy po_wa_envios_auth on public.po_wa_envios
  for select to authenticated using (true);
