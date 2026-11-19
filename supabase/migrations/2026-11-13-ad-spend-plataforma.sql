-- ============================================================
-- Investimento em anuncios POR PLATAFORMA (13/11/2026)
--
-- Os Relatorios do painel passam a separar Meta Ads e Google Ads, com CPL e
-- ROAS de cada um. A coluna antiga `amount` continua existindo e com o mesmo
-- dado: ela vira "Outros" (gasto sem plataforma), e o total do mes passa a ser
-- amount + meta + google. Nenhum valor gravado antes some do ROAS dos meses
-- passados.
--
-- RODAR ANTES DE PUBLICAR O painel/app.js NOVO. O painel grava as tres
-- colunas a cada edicao do investimento; sem elas o PostgREST recusa com
-- PGRST204 e o investimento para de salvar (o resto do painel segue de pe).
--
-- So DDL aditivo e idempotente: cria duas colunas, nao toca em linha nenhuma.
-- A RLS do po_ad_spend (so logado) ja cobre colunas novas.
-- ============================================================
alter table public.po_ad_spend add column if not exists meta   numeric not null default 0;
alter table public.po_ad_spend add column if not exists google numeric not null default 0;
