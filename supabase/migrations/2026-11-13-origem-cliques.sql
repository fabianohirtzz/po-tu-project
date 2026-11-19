-- ============================================================
-- Clique no WhatsApp do site, ligado ao lead (13/11/2026)
--
-- O botao de WhatsApp do site abre o app sem nada da URL: o gclid e a UTM
-- de quem chegou pelo anuncio ficavam no navegador e o lead do WhatsApp
-- nascia "sem origem". Agora, quando a visita tem campanha, o texto que a
-- pessoa envia leva um codigo curto, "(ref G-KTRBX)", e o clique fica
-- registrado AQUI com a origem inteira. O painel liga o clique ao lead:
--   'codigo'  -> o codigo apareceu na mensagem dele (certeza)
--   'horario' -> sem codigo (a pessoa apagou), mas houve UM clique e UM
--                lead novo do WhatsApp na mesma janela de minutos (provavel)
-- Nada disto passa pelo robo: ele so recebe um texto a mais na primeira
-- mensagem, que nao casa com nenhum atalho (tests/test-origem-whatsapp.php).
--
-- Quem grava o clique e o clique.php, com a service_role. Anonimo nao le
-- nem escreve aqui: a tabela tem a origem de cada visitante que clicou.
-- So DDL aditivo e idempotente.
-- ============================================================
create table if not exists public.po_origem_cliques (
  id               uuid primary key default gen_random_uuid(),
  created_at       timestamptz not null default now(),
  ultimo_clique_at timestamptz not null default now(),
  codigo           text not null unique,
  utm_source       text,
  utm_medium       text,
  utm_campaign     text,
  utm_term         text,
  utm_content      text,
  gclid            text,
  gbraid           text,
  wbraid           text,
  fbclid           text,
  referrer         text,
  landing_page     text,
  pagina           text,
  lead_id          uuid references public.po_leads(id) on delete set null,
  casado_por       text check (casado_por in ('codigo', 'horario')),
  casado_at        timestamptz
);
create index if not exists po_origem_cliques_livres
  on public.po_origem_cliques (ultimo_clique_at) where lead_id is null;

alter table public.po_origem_cliques enable row level security;
drop policy if exists po_origem_cliques_auth on public.po_origem_cliques;
create policy po_origem_cliques_auth on public.po_origem_cliques
  for all to authenticated using (true) with check (true);

-- Como a origem do lead foi ligada a um clique do site (null = nao foi).
alter table public.po_leads add column if not exists origem_casada text
  check (origem_casada in ('codigo', 'horario'));
