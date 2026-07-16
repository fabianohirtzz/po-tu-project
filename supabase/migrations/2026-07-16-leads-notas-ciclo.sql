-- ============================================================
-- Pereira Oliveira — observações em linha temporal + ciclo de venda.
-- Rodar no Supabase → SQL Editor. Idempotente.
--
-- Entra: notas     — [{ts,txt}] com data/hora carimbada a cada anotação,
--                    no lugar do textarea solto de observações.
--        venda_at  — quando o lead virou venda. Ciclo = venda_at - created_at.
-- Sai:   observacoes (texto único, sem histórico) — migrado para notas.
-- ============================================================

alter table public.po_leads add column if not exists notas    jsonb not null default '[]'::jsonb;
alter table public.po_leads add column if not exists venda_at timestamptz;

-- Migra o texto de observacoes para a primeira entrada da timeline, datada na
-- criação do lead (é a única data que temos), e derruba a coluna.
-- Dentro do DO porque, depois do drop, a referência a observacoes daria erro
-- de parse ao rodar de novo.
do $$
begin
  if exists (
    select 1 from information_schema.columns
     where table_schema = 'public' and table_name = 'po_leads'
       and column_name = 'observacoes'
  ) then
    update public.po_leads
       set notas = jsonb_build_array(jsonb_build_object(
             'ts',  to_char(created_at at time zone 'utc', 'YYYY-MM-DD"T"HH24:MI:SS"Z"'),
             'txt', observacoes))
     where observacoes is not null
       and btrim(observacoes) <> ''
       and notas = '[]'::jsonb;

    alter table public.po_leads drop column observacoes;
  end if;
end $$;

-- NÃO carimbamos venda_at nas vendas antigas: usar created_at reportaria ciclo
-- de 0 dias, o que seria mentira — não há registro de quando fecharam. Elas
-- ficam com ciclo "—" e fora da média até serem salvas de novo no painel.
