-- ============================================================
-- Pereira Oliveira — motor de conversa do WhatsApp.
-- Rodar no Supabase → SQL Editor. Idempotente.
--
-- Telefone SEMPRE em E.164 (+55DDNNNNNNNNN). O formato livre que vem da
-- agenda e do formulario e normalizado antes de gravar: sem isso o mesmo
-- cliente vira dois contatos e a conversa se perde.
-- ============================================================

-- Contatos: a base. Uma linha por pessoa.
create table if not exists public.po_wa_contatos (
  id                uuid primary key default gen_random_uuid(),
  wa_id             text not null unique,
  nome              text,
  origem            text not null default 'lead',
  cliente           boolean not null default false,
  revisado          boolean not null default false,
  opt_out_at        timestamptz,
  ultimo_contato_at timestamptz,
  roteiros          jsonb not null default '[]'::jsonb,
  payload           jsonb not null default '{}'::jsonb,
  created_at        timestamptz not null default now()
);

-- Conversa: o estado do fluxo. Uma linha por contato.
create table if not exists public.po_wa_conversas (
  id               uuid primary key default gen_random_uuid(),
  wa_id            text not null unique,
  estado           text not null default 'novo',
  roteiro_slug     text,
  lead_id          uuid references public.po_leads(id) on delete set null,
  aguardando_desde timestamptz,
  lembrete_at      timestamptz,
  silenciado_at    timestamptz,
  updated_at       timestamptz not null default now()
);

-- Log de mensagens. O wamid unico E a idempotencia do webhook: a Meta
-- reenvia o evento quando nao recebe 200 rapido, e sem esta restricao o
-- cliente receberia o roteiro duas vezes.
create table if not exists public.po_wa_mensagens (
  id        uuid primary key default gen_random_uuid(),
  wa_id     text not null,
  wamid     text not null unique,
  direcao   text not null,
  autor     text not null,
  tipo      text not null default 'text',
  texto     text,
  payload   jsonb not null default '{}'::jsonb,
  ts        timestamptz not null default now()
);

create index if not exists po_wa_mensagens_wa_id_ts on public.po_wa_mensagens (wa_id, ts desc);

-- Mapa anuncio -> roteiro. Preenchido pelo painel.
create table if not exists public.po_wa_anuncios (
  ad_id        text primary key,
  roteiro_slug text not null,
  rotulo       text,
  created_at   timestamptz not null default now()
);

-- Textos do robo, editaveis pelo painel sem deploy.
create table if not exists public.po_wa_textos (
  chave      text primary key,
  texto      text not null,
  updated_at timestamptz not null default now()
);

-- Colunas no funil que ja existe. Nada e removido nem renomeado: venda e
-- venda_at ficam intocados, entao ROAS, ciclo e conversao seguem valendo.
alter table public.po_leads add column if not exists wa_id        text;
alter table public.po_leads add column if not exists ctwa_clid    text;
alter table public.po_leads add column if not exists ad_id        text;
alter table public.po_leads add column if not exists qualif_data  boolean;
alter table public.po_leads add column if not exists qualif_grupo boolean;
alter table public.po_leads add column if not exists qualif_at    timestamptz;
alter table public.po_leads add column if not exists proposta_at  timestamptz;

create unique index if not exists po_leads_wa_id_uniq on public.po_leads (wa_id) where wa_id is not null;

-- RLS no padrao das demais: o painel le autenticado, o PHP escreve com
-- service_role (que ignora RLS e so existe no servidor).
alter table public.po_wa_contatos  enable row level security;
alter table public.po_wa_conversas enable row level security;
alter table public.po_wa_mensagens enable row level security;
alter table public.po_wa_anuncios  enable row level security;
alter table public.po_wa_textos    enable row level security;

-- drop+create em vez de "create policy if not exists": nem toda versao do
-- Postgres aceita IF NOT EXISTS em CREATE POLICY, e drop+create e idempotente
-- em qualquer versao (a migration roda mais de uma vez sem erro).
do $$
declare t text;
begin
  foreach t in array array['po_wa_contatos','po_wa_conversas','po_wa_mensagens','po_wa_anuncios','po_wa_textos']
  loop
    execute format('drop policy if exists %I on public.%I', t || '_auth', t);
    execute format(
      'create policy %I on public.%I for all to authenticated using (true) with check (true)',
      t || '_auth', t);
  end loop;
end $$;

-- Textos iniciais. A cliente edita pelo painel depois.
insert into public.po_wa_textos (chave, texto) values
  ('saudacao',    'Boa tarde, {nome}. Que bom falar com voce.'),
  ('envio_pdf',   'Segue o roteiro completo do {roteiro}, com o dia a dia da viagem, tudo que esta incluido e o valor.'),
  ('perguntas',   'Para eu ja adiantar seu atendimento, me responde duas coisinhas:' || chr(10) || chr(10) || '1. A viagem sai em {data}. Voce tem disponibilidade nessa data?' || chr(10) || chr(10) || '2. Voce ja viajou em grupo alguma vez?'),
  ('qualificado', 'Perfeito, {nome}. Vou passar seu contato para a nossa equipe e em breve alguem fala com voce pessoalmente.'),
  ('menu',        'Sobre qual viagem voce quer saber?'),
  ('lembrete',    'Oi {nome}, tudo bem? So passando para saber se voce chegou a ver o roteiro que enviei.'),
  ('sem_data',    'Entendo, {nome}. Vou deixar seu contato registrado e aviso quando abrirmos novas datas.')
on conflict (chave) do nothing;
