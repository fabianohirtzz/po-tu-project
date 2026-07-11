-- ============================================================
-- Pereira Oliveira Turismo — Schema Supabase (projeto COMPARTILHADO hd360/NOX)
-- Prefixo po_ para não colidir com as tabelas de outros clientes.
-- Rodar no Supabase → SQL Editor. Idempotente (create if not exists).
-- ============================================================

-- ---------- LEADS ----------
create table if not exists public.po_leads (
  id            uuid primary key default gen_random_uuid(),
  created_at    timestamptz not null default now(),
  nome          text,
  telefone      text,
  email         text,
  cidade        text,
  viajantes     text,
  roteiro       text,               -- roteiro de interesse
  mensagem      text,
  -- rastreio de origem
  origem        text,
  utm_source    text,
  utm_medium    text,
  utm_campaign  text,
  utm_term      text,
  utm_content   text,
  gclid         text,
  referrer      text,
  landing_page  text,
  -- gestão no painel
  status        text not null default 'semresposta',  -- atendimento|negociacao|venda|semresposta|perdido
  origem_manual text,
  orcamento     numeric not null default 0,
  venda         numeric not null default 0,
  observacoes   text
);
create index if not exists po_leads_created_idx on public.po_leads (created_at desc);

-- ---------- ROTEIROS ----------
create table if not exists public.po_roteiros (
  id             uuid primary key default gen_random_uuid(),
  created_at     timestamptz not null default now(),
  updated_at     timestamptz not null default now(),
  slug           text unique not null,
  titulo         text not null,
  subtitulo      text,
  descricao_curta text,
  periodo        text,              -- "25 de novembro a 02 de dezembro de 2026"
  dias           int,
  noites         int,
  data_label     text,              -- card/slider: "10 a 21/12/2025"
  local_label    text,              -- card/slider: "Suíça, França, Alemanha e Holanda"
  badge          text,              -- card: "11 dias"
  capa_url       text,              -- capa (retrato) usada na home e no "por que viajar"
  video_id       text,              -- id do YouTube (hero)
  video_list     text,              -- opcional: playlist/mix (ex.: RDxxxx)
  roteiro_dias   jsonb default '[]'::jsonb,  -- [{n,data,dia_semana,cidades,descricao,refeicoes}]
  hoteis         jsonb default '[]'::jsonb,  -- [{cidade,hotel}] ou [texto]
  inclui         jsonb default '[]'::jsonb,  -- [texto]
  nao_inclui     jsonb default '[]'::jsonb,  -- [texto]
  valores        jsonb default '[]'::jsonb,  -- [{tag,de,valor,extra}] (1 ou 2 cards)
  galeria        jsonb default '[]'::jsonb,  -- [url]
  ativo          boolean not null default true,
  ordem          int not null default 0
);
create index if not exists po_roteiros_ordem_idx on public.po_roteiros (ordem, created_at);

-- mantém updated_at
create or replace function public.po_touch_updated_at()
returns trigger language plpgsql as $$
begin new.updated_at = now(); return new; end $$;
drop trigger if exists po_roteiros_touch on public.po_roteiros;
create trigger po_roteiros_touch before update on public.po_roteiros
  for each row execute function public.po_touch_updated_at();

-- ---------- AD SPEND (relatórios: ROAS/ROI/CPL) ----------
create table if not exists public.po_ad_spend (
  month  text primary key,   -- 'YYYY-MM'
  amount numeric not null default 0
);

-- ============================================================
-- RLS
-- ============================================================
alter table public.po_leads    enable row level security;
alter table public.po_roteiros enable row level security;
alter table public.po_ad_spend enable row level security;

-- LEADS: anon só INSERT (formulário público). Ler/editar/excluir só logado.
drop policy if exists po_leads_insert_anon on public.po_leads;
create policy po_leads_insert_anon on public.po_leads
  for insert to anon, authenticated with check (true);

drop policy if exists po_leads_rw_auth on public.po_leads;
create policy po_leads_rw_auth on public.po_leads
  for all to authenticated using (true) with check (true);

-- ROTEIROS: anon lê só os ativos (site público). Logado faz tudo (painel).
drop policy if exists po_roteiros_read_public on public.po_roteiros;
create policy po_roteiros_read_public on public.po_roteiros
  for select to anon, authenticated using (ativo or auth.role() = 'authenticated');

drop policy if exists po_roteiros_rw_auth on public.po_roteiros;
create policy po_roteiros_rw_auth on public.po_roteiros
  for all to authenticated using (true) with check (true);

-- AD SPEND: só logado.
drop policy if exists po_ad_spend_auth on public.po_ad_spend;
create policy po_ad_spend_auth on public.po_ad_spend
  for all to authenticated using (true) with check (true);

-- ============================================================
-- STORAGE — bucket público de imagens dos roteiros
-- ============================================================
insert into storage.buckets (id, name, public)
values ('po-imagens', 'po-imagens', true)
on conflict (id) do nothing;

-- leitura pública
drop policy if exists po_img_read on storage.objects;
create policy po_img_read on storage.objects
  for select to anon, authenticated using (bucket_id = 'po-imagens');

-- upload/edição/remoção só logado
drop policy if exists po_img_write on storage.objects;
create policy po_img_write on storage.objects
  for all to authenticated using (bucket_id = 'po-imagens') with check (bucket_id = 'po-imagens');
