-- O DESTINO QUE A PESSOA DISSE, GUARDADO NA CONVERSA.
-- Tarefa 6 do plano 2026-11-05-robo-entende-a-frase.
--
-- POR QUE ESTA COLUNA EXISTE, e e um defeito que o plano nao tinha previsto:
-- `destino_nome` nasce no atalho do tipo `destino` e vive no CONTEXTO, e o
-- contexto que a conversa persiste eram tres campos (`roteiro_slug`,
-- `tentativas`, `roteiros_enviados`). Sem a coluna, o destino morria no fim da
-- mensagem - e os dois lugares que precisam dele chegam depois:
--
--   1. O card `marca` com `+destino`. A pessoa responde "Quero" numa MENSAGEM
--      NOVA, e e so ai que a espera e anotada. Sem a coluna, `destino_nome`
--      vinha vazio e a espera nao era gravada: o robo respondia bonito e a
--      agencia nao ficava com nada - o achado 6 por outro caminho.
--   2. O lembrete do card. O cron manda horas depois, por fora da mensagem, e
--      um lembrete com {destino} sairia "ainda quer saber da ?".
--
-- Aditiva, idempotente, so em tabela po_*. Nada de NOX/hd360.
alter table public.po_wa_conversas
  add column if not exists destino_nome text;
