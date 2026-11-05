-- Prova social no site (22/10/2026): avaliacoes do Google e posts do Instagram,
-- os dois cadastrados pela cliente numa aba do painel.
--
-- Avaliacoes: copiadas do perfil do Google, nome publicado como "Nome I."
-- (LGPD). SEM AggregateRating no JSON-LD: avaliacao que a propria empresa
-- seleciona e hospeda nao pode virar estrela na busca (diretriz do Google).
--
-- Instagram: a cliente cola so o LINK do post; o servidor (insta-capa.php) le
-- a capa pela pre-visualizacao de link do Instagram, baixa e guarda uma copia
-- em /images/insta/ (a URL do CDN deles e assinada e expira). capa_url aponta
-- para essa copia.
--
-- RLS no mesmo padrao do po_roteiros: publico le so o que esta ativo; o painel
-- (authenticated) le e escreve tudo.

begin;

create table if not exists po_avaliacoes (
  id          uuid primary key default gen_random_uuid(),
  nome        text not null,
  texto       text not null,
  nota        smallint not null default 5 check (nota between 1 and 5),
  data_label  text,
  ordem       int not null default 0,
  ativo       boolean not null default true,
  created_at  timestamptz not null default now()
);

create table if not exists po_instagram (
  id          uuid primary key default gen_random_uuid(),
  url         text not null unique,
  capa_url    text,
  ordem       int not null default 0,
  ativo       boolean not null default true,
  created_at  timestamptz not null default now()
);

alter table po_avaliacoes enable row level security;
alter table po_instagram  enable row level security;

create policy po_avaliacoes_read_public on po_avaliacoes for select to anon, authenticated
  using (ativo or auth.role() = 'authenticated');
create policy po_avaliacoes_rw_auth on po_avaliacoes for all to authenticated
  using (true) with check (true);

create policy po_instagram_read_public on po_instagram for select to anon, authenticated
  using (ativo or auth.role() = 'authenticated');
create policy po_instagram_rw_auth on po_instagram for all to authenticated
  using (true) with check (true);

commit;
