-- Idempotencia da importacao. Sem isto, reaplicar o mesmo .vcf duplica toda
-- ficha que nao tem CPF nem celular para reconciliar (na base de hoje, 615 das
-- 775 nao tem telefone nenhum). O gatilho e realista: o fetch estoura o timeout
-- depois de o servidor ja ter gravado, e a reacao natural e clicar de novo.
create table if not exists public.po_import_lotes (
  id           uuid primary key default gen_random_uuid(),
  hash         text not null unique,
  origem       text not null,
  arquivo      text,
  total        integer not null default 0,
  novos        integer not null default 0,
  preenchidos  integer not null default 0,
  created_at   timestamptz not null default now()
);

alter table public.po_import_lotes enable row level security;

-- Mesmo padrao das demais: quem esta logado no painel le e escreve; o PHP usa a
-- service_role, que ignora RLS e so existe no servidor.
drop policy if exists po_import_lotes_rw_auth on public.po_import_lotes;
create policy po_import_lotes_rw_auth on public.po_import_lotes
  for all to authenticated using (true) with check (true);
