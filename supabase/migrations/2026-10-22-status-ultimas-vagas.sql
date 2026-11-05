-- Status "Ultimas vagas" (pedido do cliente, 22/10/2026). Amplia a lista
-- fechada de po_roteiros.status_viagem criada em 2026-10-22-status-viagem.sql.
-- A lista e a mesma de PO_STATUS_VIAGEM (lib/po-view.php), poStatusTag
-- (roteiros-shared.js) e STATUS_VIAGEM (painel/app.js).

begin;
alter table po_roteiros drop constraint if exists po_roteiros_status_viagem_check;
alter table po_roteiros add constraint po_roteiros_status_viagem_check
  check (status_viagem in ('em_breve', 'ultimas_vagas', 'esgotado', 'em_viagem'));
commit;
