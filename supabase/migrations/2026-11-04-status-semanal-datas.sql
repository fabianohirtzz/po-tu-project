-- Status "Saidas semanais" + as datas do mes (pedido do cliente, 04/11/2026).
-- Spec: docs/superpowers/specs/2026-11-04-robo-entende-frase-e-roteiro-semanal-design.md
--
-- Nasceu do roteiro do Peru, que sai TODA SEMANA: ele nao tem um periodo
-- unico, tem uma lista de datas por mes que a cliente mantem atualizada.
--
-- Duas coisas, as duas ADITIVAS (nenhum delete, nenhum update de dado):
--
-- 1. A lista fechada de status_viagem ganha 'semanal'. A MESMA lista vive em
--    PO_STATUS_VIAGEM (lib/po-view.php), STATUS_VIAGEM (roteiros-shared.js) e
--    STATUS_VIAGEM (painel/app.js), e tests/test-status-tag.mjs exige que as
--    tres sejam identicas e na mesma ordem. 'semanal' entra PRIMEIRO: as
--    outras quatro estao em ordem de ciclo da viagem (em breve -> ultimas
--    vagas -> esgotado -> em viagem) e semanal nao tem ciclo, e condicao
--    permanente do roteiro.
--
-- 2. datas_texto: as datas agrupadas por mes, na forma
--      [{"titulo":"Datas de Dezembro","datas":["02/12/26","10/12/26"]}]
--    Agrupado, e nao uma lista solta de linhas, porque o robo precisa
--    distinguir cabecalho de data: com linhas soltas "Datas de Dezembro:" e
--    "02/12/26" sao a mesma coisa para o codigo, e dai nao se consegue nem
--    ordenar o roteiro pela proxima saida nem responder "qual a proxima data".
--
--    `not null default '[]'` para nenhum leitor precisar tratar null: roteiro
--    que nunca foi semanal le lista vazia, nao nada.
--
-- ATENCAO: trocar o check exige derrubar o antigo. Isso e DDL, nao comando
-- destrutivo de dado. Se o portao do MCP recusar o bloco, o DONO roda no SQL
-- Editor; nao se procura uma terceira porta para passar o destrutivo.

begin;

alter table po_roteiros drop constraint if exists po_roteiros_status_viagem_check;
alter table po_roteiros add constraint po_roteiros_status_viagem_check
  check (status_viagem in ('semanal', 'em_breve', 'ultimas_vagas', 'esgotado', 'em_viagem'));

alter table po_roteiros add column if not exists datas_texto jsonb not null default '[]'::jsonb;

commit;
