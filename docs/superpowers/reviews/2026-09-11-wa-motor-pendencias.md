# Motor de conversa do WhatsApp — pendências conhecidas

> Registrado em 11/09/2026, ao fim da implementação do plano
> `docs/superpowers/plans/2026-09-11-wa-motor-conversa.md`.
> Nada aqui bloqueia o merge. Os dois primeiros bloqueiam o go-live.

## Antes de apontar a Meta para produção

1. **Preencher os segredos em `config.local.php`.** Com `WA_APP_SECRET` e
   `WA_VERIFY_TOKEN` vazios, o webhook recusa tudo, **inclusive a verificação do
   cadastro na Meta**. Isso é proposital (segredo vazio nunca autoriza), mas
   significa que subir `whatsapp.php` sem preencher deixa a integração muda.
   O mesmo vale para `WA_CRON_KEY` e o `wa-cron.php`.

2. **`falha_perguntas` não deveria interpretar a mensagem seguinte como resposta.**
   Quando o PDF sai e as duas perguntas falham, a próxima mensagem do cliente é
   lida como se respondesse perguntas que ele nunca viu. Medido: "Nao recebi nada"
   e "Nao entendi" devolvem `data=false` e gravam `status=perdido`. Só acontece se
   a Graph API falhar no meio de um envio. Correção: um estado
   `perguntas_pendentes` que reenvie só as perguntas.

3. **`tests/test-wa-schema.php` não consegue mais ficar vermelho** nesta máquina.
   A sonda usa `po_http_get`, que serve o cache em disco quando a requisição
   falha, e o cache já está quente com respostas `[]`. Derrubar uma tabela do
   WhatsApp não faz o teste falhar. Trocar por curl direto (o
   `wa_http_get_service` do próprio arquivo já é isso). Junto: `tests/run.php` só
   imprime a saída do filho em caso de falha, então um SKIP aparece como PASS.

## Dívida aceita, para o próximo plano

4. **Quatro mecanismos de injeção de dependência** (`wa_set_transport`,
   `wa_db_set_transport`, `wa_motor_set_deps`, `wa_timeout_set_deps`), e
   `wa_varre_timeouts` atravessa dois deles. A transmissão em massa vai querer um
   quinto. Consolidar antes disso.

5. **`enviar.php` não normaliza telefone para E.164**, apesar de o invariante
   valer "em todo o banco". A mesma pessoa que preenche o formulário do site e
   depois chama no WhatsApp vira dois leads. O arquivo está em produção hoje, por
   isso ficou fora desta branch.

6. **Eventos de tipo `status` não passam pela idempotência.** Hoje é inofensivo
   (nada é feito com eles), mas o plano da transmissão usa status de entrega para
   o relatório de campanha.

7. **`wa_respostas_numeradas` pode fabricar `qualif_grupo`**: "somos 1 casal e 2
   pessoas, e sim tenho a data" devolve `grupo=true`. O campo `grupo` nunca
   desqualifica ninguém, então o estrago é um dado errado no painel.

8. **Cortesia qualifica.** `"ok"`, `"Bom dia, tudo bem sim"` e `"Sim, recebi o
   roteiro"` devolvem `data=true`. Interpretar português livre é impreciso por
   natureza; o erro aqui é na direção de qualificar demais, e quem qualifica
   demais gasta tempo da equipe, não perde venda.

9. **Menores, sem urgência:** prefixos `00` e `0+DDD` caem em `null` no
   `wa_e164` (vira bloqueador no importador de contatos, que é justamente o
   formato que sai da agenda); `limit=500` na varredura de timeouts;
   `wa_nota_lead` é leitura-modificação-escrita sem transação; `select`-depois-
   `insert` sem transação (os índices únicos em `wa_id` protegem no banco);
   legenda vazia possível no `send_doc` se a chave `envio_pdf` sumir do banco.
