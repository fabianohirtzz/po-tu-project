-- ============================================================
-- Ids de clique e UTM completa no lead (13/11/2026)
--
-- A memoria de origem do site (assets/js/origem.js) passa a mandar com o
-- lead os ids de clique de quem chegou por anuncio:
--   gbraid / wbraid  -> Google Ads no iPhone (no lugar do gclid, quando a
--                       Apple bloqueia o rastreio do aparelho)
--   fbclid           -> link vindo do Facebook/Instagram (anuncio ou nao)
-- utm_term e utm_content estao no schema base desde julho; o "if not
-- exists" so garante que existem (o enviar.php passou a grava-los).
--
-- RODAR ANTES DE PUBLICAR O enviar.php E O lead-form.js NOVOS. Se rodar
-- depois, os dois gravam o lead mesmo assim (tentam de novo sem estes
-- campos ao receber PGRST204), mas os ids de clique desse intervalo se
-- perdem.
--
-- So DDL aditivo e idempotente. A RLS do po_leads ja cobre colunas novas.
-- ============================================================
alter table public.po_leads add column if not exists gbraid      text;
alter table public.po_leads add column if not exists wbraid      text;
alter table public.po_leads add column if not exists fbclid      text;
alter table public.po_leads add column if not exists utm_term    text;
alter table public.po_leads add column if not exists utm_content text;
