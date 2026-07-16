-- ============================================================
-- Pereira Oliveira — vídeo do Instagram (reels) no roteiro.
-- Rodar no Supabase → SQL Editor. Idempotente.
--
-- Sai: video_list (playlist/mix do YouTube). Nunca foi usada por nenhum
--      roteiro; o loop do hero já cai no fallback correto (playlist=<id>).
-- Entra: video_insta_url — URL do reels hospedado na ereHost. Quando
--      preenchida, substitui a capa na seção "por que viajar".
-- ============================================================

alter table public.po_roteiros drop column if exists video_list;
alter table public.po_roteiros add  column if not exists video_insta_url text;
