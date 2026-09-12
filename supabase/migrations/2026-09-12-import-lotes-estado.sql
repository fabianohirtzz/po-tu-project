-- Estado do lote de importacao: sem isto a guarda de idempotencia so pega o
-- retry DEPOIS que a primeira importacao terminou, que e o caso facil. O caso
-- que o timeout produz e o outro: o fetch do navegador estoura DURANTE a
-- escrita (o proxy do host corta a conexao; o PHP nao percebe, porque so
-- responde no fim), o botao volta a ficar clicavel e a segunda requisicao
-- consulta a po_import_lotes ANTES de a primeira registrar o lote. As duas
-- passam pela guarda e a base duplica.
--
-- Com o estado, o registro vira duas fases: a linha e RESERVADA antes da
-- escrita (o unique em 'hash' e o lock: so uma requisicao consegue inserir) e
-- so ganha concluido_at no fim. Linha sem concluido_at e importacao em
-- andamento; linha sem concluido_at e VELHA e orfa de uma tentativa que morreu
-- no meio, e nesse caso a cliente pode tentar de novo sem precisar do forcar.
alter table public.po_import_lotes
  add column if not exists concluido_at timestamptz;

comment on column public.po_import_lotes.concluido_at is
  'Nulo = importacao reservada e ainda em andamento (ou interrompida). Preenchido = lote aplicado por inteiro.';

-- A guarda consulta por hash e le o estado; o indice unico de hash ja cobre a
-- busca. Este parcial serve a varredura de lotes interrompidos no painel.
create index if not exists po_import_lotes_incompletos
  on public.po_import_lotes (created_at)
  where concluido_at is null;
