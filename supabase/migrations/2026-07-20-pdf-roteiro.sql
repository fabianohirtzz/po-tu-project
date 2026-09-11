-- ============================================================
-- Pereira Oliveira — PDF do roteiro (download na página).
-- Rodar no Supabase → SQL Editor. Idempotente.
--
-- Entra: pdf_url — URL do PDF hospedado na ereHost
--        (/docs/<slug>-<hash>.pdf, upload pelo painel, mesmo padrão
--        dos vídeos: fica na ereHost e NÃO no Supabase Storage, que é
--        projeto compartilhado com NOX/hd360 e tem egress limitado).
--
-- A cliente monta o roteiro no Word e exporta em PDF; esse documento
-- diagramado é melhor do que qualquer PDF gerado da página web. A
-- página de roteiro mostra a faixa "Baixar roteiro em PDF" SÓ quando
-- este campo tem valor; sem PDF, a seção não aparece.
-- ============================================================

alter table public.po_roteiros add column if not exists pdf_url text;

comment on column public.po_roteiros.pdf_url is
  'PDF do roteiro para download (upload p/ ereHost, /docs/<slug>-<hash>.pdf). Vazio = a seção "Baixar roteiro em PDF" não aparece na página.';
