-- ============================================================
-- Pereira Oliveira — texto do lembrete para quem recebeu o MENU.
-- Rodar no Supabase → SQL Editor. Idempotente.
--
-- A varredura de 24h pega dois estados: quem recebeu o roteiro
-- (enviado_roteiro) e quem recebeu a lista de viagens e nao escolheu
-- nenhuma (aguardando_roteiro). Os dois levavam o mesmo texto, que diz
-- "o roteiro que enviei". Para quem so viu a lista, o robo estava
-- afirmando ter mandado algo que nunca mandou.
--
-- Migration nova, nao edicao da anterior: 2026-09-11-whatsapp-motor.sql
-- ja rodou em producao e nao pode ser reescrita.
-- ============================================================

insert into public.po_wa_textos (chave, texto) values
  ('lembrete_menu', 'Oi {nome}, tudo bem? Mandei a lista das nossas proximas viagens. Quer que eu envie o roteiro completo de alguma delas?')
on conflict (chave) do nothing;
