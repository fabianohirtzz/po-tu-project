-- Status da viagem, mostrado como tag colorida na capa do roteiro (home,
-- /roteiros, hero da pagina e painel). Null = sem tag.
--
-- Separado de `ativo` de proposito: `ativo` decide se o roteiro existe no
-- site; o status so informa a situacao de uma viagem que esta no site.
-- Lista fechada no banco porque o site escolhe a cor pelo valor: um valor
-- fora da lista viraria tag sem cor.

alter table po_roteiros
  add column if not exists status_viagem text
  check (status_viagem in ('em_breve', 'em_viagem', 'esgotado'));
