-- ============================================================
-- Pereira Oliveira — vídeo capa (hero) no roteiro.
-- Rodar no Supabase → SQL Editor. Idempotente.
--
-- Sai:   video_id (id do YouTube que alimentava o hero). O embed do YouTube
--        NÃO faz autoplay no mobile — é bloqueio de plataforma, não config —
--        então o hero ficava estático no celular. Some junto o ~1 MB de JS do
--        player que todo visitante baixava.
-- Entra: video_capa_url — URL do vídeo hospedado na ereHost (mesmo caminho do
--        reels). Toca no hero em autoplay, mudo, em loop, sem controles.
--
-- Não confundir com video_insta_url: aquele é o reels 9:16 da seção "por que
-- viajar", com áudio e só toca no clique. Este é textura de fundo, sem áudio.
-- ============================================================

alter table public.po_roteiros drop column if exists video_id;
alter table public.po_roteiros add  column if not exists video_capa_url text;

comment on column public.po_roteiros.video_capa_url is
  'Vídeo do hero (upload p/ ereHost, /videos/<slug>-capa-<hash>.mp4). Autoplay, mudo, loop, sem controles. Sem áudio na origem. Fallback quando vazio: capa_url estática.';
