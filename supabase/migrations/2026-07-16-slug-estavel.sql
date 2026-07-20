-- SEO fase 1: slug como identidade estavel da viagem.
--
-- Regra: o slug e o destino, sem data, tema ou parada da edicao. Uma edicao
-- ativa por slug de cada vez (confirmado com a cliente). Assim a edicao nova
-- HERDA a URL e a autoridade da anterior, em vez de a agencia recomecar do
-- zero no Google a cada ano.
--
-- Pre-condicoes conferidas contra o banco em 2026-07-16 (anon REST):
--   6 roteiros, todos ativos, slugs unicos; os 4 destinos abaixo estavam
--   livres. O indice unico parcial cria sem colisao.
--
-- Palavra-chave em URL nao e fator de ranking relevante (a doc do Google so
-- pede URL legivel), entao encurtar nao custa SEO.

begin;

-- 1) Renomeacoes. A pagina dinamica antiga era noindex, entao nada disso esta
--    indexado: renomear agora e de graca; depois custaria um 301.

-- Antalia e parada desta edicao, nao a identidade da viagem.
update po_roteiros set slug = 'turquia'
  where slug = 'turquia-com-antalia';

-- Santiago e escala; o par Chile + Atacama e a identidade.
update po_roteiros set slug = 'chile-e-deserto-do-atacama'
  where slug = 'chile-santiago-e-deserto-do-atacama';

-- O '2' era contorno do mapa STATIC_ROTEIRO (que mandava um roteiro do banco
-- com slug 'tesouros-asiaticos' para a pagina estatica de mesmo nome). Esse
-- mapa morreu na Task 7, entao o nome esta livre.
update po_roteiros set slug = 'tesouros-asiaticos'
  where slug = 'tesouros-asiaticos2';

-- Truncado no meio da palavra pelo .slice(0,60) do painel (corrigido na Task 8).
update po_roteiros set slug = 'escandinavia'
  where slug = 'um-roteiro-exclusivo-pelo-melhor-da-escandinavia-com-acompan';

-- 2) Um ativo por slug. Parcial de proposito: edicoes arquivadas mantem o slug
--    na linha e nao conflitam, e roteiro.php serve so ativo=true.
create unique index if not exists po_roteiros_slug_ativo_uniq
  on po_roteiros (slug) where ativo;

commit;
