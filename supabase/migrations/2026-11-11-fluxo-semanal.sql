-- ============================================================
-- Pereira Oliveira: o caminho do roteiro de SAIDAS SEMANAIS no rascunho de
-- "Boas-vindas e triagem" (reuniao de 09/11/2026 com a Lais).
--
-- O Peru sai toda semana e nao tem uma data para confirmar: pelo card "A data
-- serve?" o robo diria "nossa viagem esta programada para na data prevista".
-- Agora, depois do PDF, o card "Marca interesse" desvia (config.se_semanal)
-- quando o roteiro e semanal, para um card que pergunta se a pessoa leu o
-- roteiro e se ele atende, com dois botoes:
--   "Falar com a equipe"  -> "Marca: a data serve" (qualifica) -> "A Lais assume"
--   "Ver outros roteiros" -> "Qual viagem?" (o menu de destinos)
--
-- Os cards ficam DENTRO da triagem, e nao num fluxo separado: "Ver outros
-- roteiros" volta para a triagem, e dois fluxos que saltam um para o outro
-- nunca poderiam ser ligados (a regra 11 exige o destino do salto ligado).
--
-- RASCUNHO E A FONTE (ver 2026-11-05-fluxo-v3.sql): nenhum comando escreve
-- texto, opcoes ou ligacao de card que ja existe. Card existente ganha so a
-- chave `se_semanal` no config, por `||`, e so se ainda nao a tiver.
-- Nao publica e nao liga: quem publica e a cliente, pelo painel.
-- ============================================================

insert into public.po_wa_cards
  (id, fluxo_id, tipo, titulo, texto, opcoes, config, proximo, fallback, sem_resposta, lembrete_ativo, lembrete_texto, ordem, x, y)
values
  ('c1000000-0000-4000-8000-000000000023', 'a1000000-0000-4000-8000-000000000001', 'botoes',
   'Saídas semanais: o roteiro atende?',
   'Esta viagem tem saídas toda semana, então você pode escolher a data que for melhor para você. Você já teve a oportunidade de ler o roteiro? Ele atende ao que você procura?',
   '[{"id":"s_equipe","rotulo":"Falar com a equipe","destino":"c1000000-0000-4000-8000-000000000008"},{"id":"s_outros","rotulo":"Ver outros roteiros","destino":"c1000000-0000-4000-8000-000000000002"}]'::jsonb,
   '{"atalhos":[{"tipo":"palavras","palavras":["atendente","atendimento","humano","pessoa","falar com alguem"],"destino":"c1000000-0000-4000-8000-000000000004","saudacao":""}]}'::jsonb,
   null, 'c1000000-0000-4000-8000-000000000024', 'c1000000-0000-4000-8000-000000000017',
   true, 'Oi, tudo bem? Só passando para saber se você chegou a ver o roteiro que enviamos.', 18, 1280, 800),
  ('c1000000-0000-4000-8000-000000000024', 'a1000000-0000-4000-8000-000000000001', 'botoes',
   'Repete: o roteiro atende?',
   'Só para confirmar: o roteiro atende ao que você procura? Escolha uma das opções abaixo para continuarmos.',
   '[{"id":"s_equipe","rotulo":"Falar com a equipe","destino":"c1000000-0000-4000-8000-000000000008"},{"id":"s_outros","rotulo":"Ver outros roteiros","destino":"c1000000-0000-4000-8000-000000000002"}]'::jsonb,
   '{}'::jsonb,
   null, null, 'c1000000-0000-4000-8000-000000000017',
   true, 'Oi, tudo bem? Só passando para saber se você chegou a ver o roteiro que enviamos.', 19, 1600, 800)
on conflict (id) do nothing;

update public.po_wa_cards
   set config = config || '{"se_semanal":"c1000000-0000-4000-8000-000000000023"}'::jsonb
 where id = 'c1000000-0000-4000-8000-000000000006'
   and not (config ? 'se_semanal');

-- ------------------------------------------------------------
-- A coluna "Lista de espera" do funil (pedido de 11/11/2026): o card que anota
-- o destino na espera passa a gravar tambem o status `espera`. Quem toca em
-- "Sim, tenho interesse" cai sozinho na coluna. O robo nunca troca o status de
-- quem esta em proposta ou venda (WA_FLUXO_STATUS_INTOCAVEIS, no codigo).
update public.po_wa_cards
   set config = config || '{"status":"espera"}'::jsonb
 where id = 'c2000000-0000-4000-8000-000000000001'
   and not (config ? 'status');
