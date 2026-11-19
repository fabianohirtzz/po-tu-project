-- ============================================================
-- Meta Ads: nome dos anuncios e vendas de volta para a Meta (13/11/2026)
--
-- po_anuncios_meta: o nome de cada anuncio que trouxe lead pelo WhatsApp
--   (ad_id -> anuncio, conjunto, campanha), buscado na API de Marketing pelo
--   anuncios-meta.php, ou dado a mao no painel (nome_manual, que vence).
-- po_meta_conversoes: cada venda com ctwa_clid enviada a API de Conversoes.
--   A Meta NAO deduplica esses eventos: o lead_id como chave primaria e a
--   reserva antes do envio sao o que impede a mesma venda de sair duas vezes.
--   So o servidor escreve (service_role); o painel so le, para mostrar.
--
-- Rodar ANTES do painel/meta.js e dos dois endpoints. Sem as tabelas, o
-- painel so deixa de mostrar o nome do anuncio e o status do envio.
-- So DDL aditivo e idempotente.
-- ============================================================
create table if not exists public.po_anuncios_meta (
  ad_id       text primary key,
  anuncio     text,
  conjunto    text,
  campanha    text,
  nome_manual text,
  buscado_at  timestamptz,
  erro        text,
  updated_at  timestamptz not null default now()
);
alter table public.po_anuncios_meta enable row level security;
drop policy if exists po_anuncios_meta_auth on public.po_anuncios_meta;
create policy po_anuncios_meta_auth on public.po_anuncios_meta
  for all to authenticated using (true) with check (true);

create table if not exists public.po_meta_conversoes (
  lead_id    uuid primary key references public.po_leads(id) on delete cascade,
  evento     text not null default 'Purchase',
  status     text not null default 'enviando' check (status in ('enviando', 'enviado', 'falha')),
  tentativas int  not null default 1,
  valor      numeric,
  enviado_at timestamptz,
  erro       text,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);
alter table public.po_meta_conversoes enable row level security;
drop policy if exists po_meta_conversoes_ler on public.po_meta_conversoes;
create policy po_meta_conversoes_ler on public.po_meta_conversoes
  for select to authenticated using (true);
