-- Derruba o UNIQUE (slug) global que a tabela trazia desde a criacao.
--
-- A migration 2026-07-16-slug-estavel.sql criou o indice parcial
-- po_roteiros_slug_ativo_uniq (um ATIVO por slug) para que edicoes arquivadas
-- mantivessem o slug e a edicao nova herdasse a URL. Mas esqueceu de derrubar
-- a constraint antiga, entao a regra nunca valeu: em 22/10/2026 a cliente
-- reimportou um roteiro (ja salvo como oculto) e o painel recusou com
-- "duplicate key value violates unique constraint po_roteiros_slug_key".
--
-- Nada no codigo faz upsert por slug, e toda leitura publica por slug filtra
-- ativo=true (lib/po-data.php) ou depende da RLS que so mostra ativo ao anon.

alter table po_roteiros drop constraint if exists po_roteiros_slug_key;
