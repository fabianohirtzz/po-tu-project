-- Tres correcoes aplicadas pelo controlador DEPOIS da carga semente do CRM
-- (775 fichas, 12/09/2026). Vieram da revisao final da branch plano3-importador.
-- Ja rodadas no projeto euzmbswywwhmicjlszqw; ficam aqui para o historico.

-- ---------------------------------------------------------------------------
-- 1) anon nao pode mais plantar contato ja marcado como cliente revisado.
--
-- A anon key e publica (painel/config.js e servido pela web) e a policy de
-- INSERT aceitava qualquer coisa (with check true). Como o bloco de ficha
-- nasceu nesta branch, qualquer um podia inserir revisado=true/cliente=true —
-- e a spec 8.1 diz que "contato nao revisado nunca entra em campanha" e defesa
-- OBRIGATORIA. Contato assim entraria em disparo pago (~R$ 0,31 por
-- destinatario) na conta da cliente.
--
-- Em producao o formulario grava por enviar.php com a service_role (que ignora
-- RLS), entao esta policy governa so o fallback anon do preview. O
-- assets/js/lead-form.js envia apenas campos basicos + utm; todas as colunas
-- abaixo ficam no DEFAULT e satisfazem o check. authenticated nao e afetado:
-- po_leads_rw_auth (ALL) e permissiva e faz OR com esta.
--
-- ATENCAO: revogar por coluna NAO funciona aqui. O Supabase concede os
-- privilegios no nivel da TABELA, entao `revoke insert (col) ... from anon` e
-- inocuo. Quem restringe e a RLS.
alter policy po_leads_insert_anon on public.po_leads
  with check (
        coalesce(revisado, false) = false
    and coalesce(cliente,  false) = false
    and origem_import  is null
    and payload_import is null
    and coalesce(venda, 0) = 0
    and venda_at is null
    and status = 'semresposta'
  );

-- ---------------------------------------------------------------------------
-- 2) Backfill de wa_id para as fichas do CRM que tem celular.
--
-- O importador grava `telefone`, mas o motor do WhatsApp casa lead por `wa_id`
-- (wa_lead() em lib/wa-motor.php consulta wa_id=eq. e INSERE quando nao acha).
-- Sem isto, as fichas ricas (CPF, passaporte) duplicariam na primeira mensagem
-- que a pessoa mandasse, e a conversa ficaria pendurada num lead vazio.
-- Conferido antes de rodar: 0 celulares duplicados, entao o indice unico
-- parcial po_leads_wa_id_uniq nao e violado.
update public.po_leads
   set wa_id = telefone
 where telefone ~ '^\+55[0-9]{2}9[0-9]{8}$'
   and wa_id is null;

-- ---------------------------------------------------------------------------
-- 3) Limpa o balde de canal dos relatorios.
--
-- O mapa do CRM mandava `comoConheceu` para `origem_manual`, que neste projeto
-- e a SOBRESCRITA MANUAL DE CANAL de marketing (pago|organico|social|direto|
-- instagram|whatsapp), lida em painel/app.js e agrupada nos relatorios. A carga
-- gravou "Instagram" com I maiusculo, que virou um bucket separado de
-- "instagram". O dado nao se perde: vai para observacoes, rotulado.
-- O codigo ja foi corrigido na mesma revisao (o mapa agora escreve em
-- observacoes), entao isto so conserta a linha que ja tinha entrado.
update public.po_leads
   set observacoes = trim(both E'\n' from coalesce(observacoes, '') || E'\nComo conheceu: ' || origem_manual),
       origem_manual = null
 where origem_manual is not null
   and origem_manual not in ('pago', 'organico', 'social', 'direto', 'instagram', 'whatsapp');
