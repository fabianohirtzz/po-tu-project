-- Robo so atende numero NOVO (decisao de 25/11/2026, depois do primeiro teste
-- real: o robo entrou na conversa de uma cliente que falava com a Lais e nao
-- estava na base).
--
-- A API do WhatsApp nao mostra a agenda nem o historico do celular dela (a
-- sincronizacao de contatos da convivencia e uma vez so, nas 24h depois da
-- conexao, e ja passou). Esta tabela e o substituto: a agenda exportada em
-- .vcf, um numero por linha, em E.164 (o mesmo formato do wa_id).
--
--   nao_atender  conhecido da agenda: a conversa nasce com a equipe
--   teste        numero da equipe: o robo atende sempre, passando por cima
--                da agenda e da base (para testar)
--
-- Os numeros NAO ficam no repositorio (que e publico): sao carregados direto
-- no banco. lib/wa-motor.php le esta tabela no primeiro contato.
create table if not exists po_wa_agenda (
  wa_id      text primary key,
  acao       text not null check (acao in ('nao_atender', 'teste')),
  origem     text not null default '',
  created_at timestamptz not null default now()
);

alter table po_wa_agenda enable row level security;
create policy po_wa_agenda_auth on po_wa_agenda
  for all to authenticated using (true) with check (true);
