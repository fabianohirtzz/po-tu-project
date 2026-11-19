-- ============================================================
-- Origem "manual" que o painel antigo carimbou sozinho (13/11/2026)
--
-- OPCIONAL, e roda DEPOIS de publicar o painel novo.
--
-- O problema: ate 13/11 a gaveta do lead gravava em `origem_manual` o valor
-- que o select mostrava, a cada Salvar. Como o select abre na origem
-- AUTOMATICA, todo lead salvo uma vez (para mudar status, valor, qualquer
-- coisa) ficou com a classificacao daquele dia congelada como se alguem a
-- tivesse escolhido. A correcao da classificacao de 13/11 (gclid vira Google
-- Ads, ad_id vira Meta Ads, visita vinda de busca vira Organico) nao alcanca
-- esses leads, e as vendas deles nao entram no ROAS da plataforma certa.
--
-- O que esta limpeza faz: devolve para null ("siga a automatica") SO o
-- `origem_manual` que e IGUAL ao que o classificador ANTIGO dava para aquela
-- linha, ou seja, o que o painel carimbou sem ninguem escolher. Escolha que
-- DIFERE da automatica antiga fica intacta (a do cadastro manual de lead e a
-- do "como conheceu" do CRM, na maioria). A que coincidia com ela e
-- indistinguivel do carimbo e volta a seguir a automatica; para quem nao tem
-- UTM, referrer, id de clique nem origem do robo, a automatica nova da o
-- mesmo valor da antiga, entao a tela nao muda (caso dos contatos importados
-- com "direto"). O painel novo ja nao carimba: so grava quando difere.
--
-- O classificador antigo, transcrito do painel/app.js como estava ate 13/11:
--   if(r.gclid) return 'pago';
--   const med=(r.utm_medium||'').toLowerCase();
--   if(/cpc|ppc|paid|ads/.test(med)) return 'pago';
--   if(/social|facebook|instagram|meta/.test(med)) return 'social';
--   if(r.utm_source||med) return 'organico';
--   return 'direto';
--
-- Por que DEPOIS do painel novo: com o painel antigo no ar, o proximo Salvar
-- carimbaria de novo. Rodar no SQL Editor, conferindo antes com o passo 1.
-- ============================================================

-- 1) CONFERIR (so leitura): exatamente as linhas que o passo 2 vai mudar.
select id, created_at, nome, origem, origem_manual, utm_source, utm_medium,
       (coalesce(gclid, '') <> '') as tem_gclid, ad_id, referrer
  from public.po_leads
 where origem_manual is not null
   and origem_manual = case
         when coalesce(gclid, '') <> '' then 'pago'
         when lower(coalesce(utm_medium, '')) ~ '(cpc|ppc|paid|ads)' then 'pago'
         when lower(coalesce(utm_medium, '')) ~ '(social|facebook|instagram|meta)' then 'social'
         when coalesce(utm_source, '') <> '' or coalesce(utm_medium, '') <> '' then 'organico'
         else 'direto'
       end
 order by created_at desc;

-- 2) APLICAR: a mesma condicao do passo 1.
update public.po_leads
   set origem_manual = null
 where origem_manual is not null
   and origem_manual = case
         when coalesce(gclid, '') <> '' then 'pago'
         when lower(coalesce(utm_medium, '')) ~ '(cpc|ppc|paid|ads)' then 'pago'
         when lower(coalesce(utm_medium, '')) ~ '(social|facebook|instagram|meta)' then 'social'
         when coalesce(utm_source, '') <> '' or coalesce(utm_medium, '') <> '' then 'organico'
         else 'direto'
       end;
