-- A ordem da lista de roteiros que o robo ENVIOU, para o numero que a pessoa
-- digita valer pelo que ela leu, ainda que a cliente edite uma viagem no meio.
alter table public.po_wa_conversas
  add column if not exists roteiros_enviados jsonb not null default '[]'::jsonb;
