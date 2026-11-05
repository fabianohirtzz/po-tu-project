-- ============================================================
-- Pereira Oliveira: a VERSAO 3 de "Boas-vindas e triagem" - os dois cards
-- novos, as duas religacoes e as listas de atalhos. Publicada e DESLIGADA.
-- Rodar no Supabase -> SQL Editor, DEPOIS de 2026-10-30-fluxo-triagem-v2.sql,
-- de 2026-11-05-destinos-espera.sql e de 2026-11-05-destino-na-conversa.sql.
--
-- O QUE MUDA (spec A.5, plano 2026-11-05-robo-entende-a-frase, tarefa 8):
--   1. card novo "Despedida de quem recusou a lista" (fim), com o link de
--      /roteiros. O "Nao, obrigado" dos DOIS cards de lista de espera passa a
--      apontar para ca, em vez de cair na despedida de quem nao responde;
--   2. card novo "Sem data para este destino" (botoes), que usa {destino} e e
--      alcancado SO pelo atalho de destino. O "sim" dele salta para o fluxo
--      "Lista de espera", que ja existe;
--   3. o card de boas-vindas ganha os tres atalhos, nesta ordem: palavras
--      (atendimento humano), roteiro (o PDF) e destino (o card novo);
--   4. o card "Qual viagem?" ganha atalho de palavras e de destino. E ali que
--      a pessoa digita "Italia" depois de tocar em "Conhecer os roteiros";
--      sem isto, o unico lugar que entenderia um destino sem data seria a
--      PRIMEIRA mensagem da conversa.
--
-- >>> ESTA MIGRATION E O INVERSO DA v2, E A DIFERENCA E O PONTO TODO. <<<
--
-- Na v2 o retrato era a fonte e o rascunho era DERIVADO dele, com
-- `on conflict (id) do update` sobrescrevendo tipo, titulo, texto, opcoes e
-- config dos cards semeados. O proprio cabecalho da v2 avisava: "edicao que a
-- cliente tenha feito nesses 17 cards se perde".
--
-- Medido aqui em 05/11/2026, antes de escrever uma linha: a cliente reescreveu
-- `texto` em 11 dos 15 cards da triagem, `opcoes` em 6 (os rotulos dos botoes:
-- "Conhecer os roteiros", "Falar com a equipe", "Sim, tenho interesse") e
-- `lembrete_texto` em 3. Nada disso foi publicado. Repetir a derivacao da v2
-- jogaria esse trabalho fora, calado.
--
-- Entao aqui o RASCUNHO e a fonte:
--   - NENHUM comando escreve `texto`, `titulo`, `tipo`, `lembrete_texto`,
--     `proximo`, `fallback` ou `sem_resposta` de card que ja existe. Os unicos
--     campos tocados em card existente sao `opcoes` (so o `destino` de UMA
--     opcao, por jsonb_set, preservando o rotulo dela) e `config` (so
--     acrescentando a chave `atalhos`, por `||`, e so quando ela nao existe);
--   - o retrato abaixo e uma COPIA do rascunho, tirada em 05/11/2026 pelo
--     proprio wa_fluxo_grafo_de (o montador que o botao Publicar usa), com as
--     reescritas da cliente dentro. Nao e o que esta migration quer que o
--     rascunho seja: e o que o rascunho era.
--
-- Consequencia pratica: a partir daqui, quem publica e a CLIENTE, pelo botao
-- Publicar da aba Fluxo. Esta migration existe porque card novo, religacao de
-- botao e lista de atalho sao ESTRUTURA, e estrutura nao se pede para a
-- cliente montar na mao em cinco lugares sem errar um uuid.
--
-- DESLIGADO: nenhum comando aqui escreve `ativo`. Publicar e ligar sao atos
-- separados. Ao ligar, "Lista de espera" ANTES de "Boas-vindas e triagem": a
-- triagem salta para dentro dela e a regra 11 do validador bloqueia saltar
-- para fluxo desligado. Conferido em 05/11: com a espera ligada, a versao 3
-- valida com ZERO bloqueios; com ela desligada, bloqueia so nesse salto.
--
-- RODAR INTEIRO, DE UMA VEZ. Sao seis comandos (insert, update, update,
-- update, insert, update) e NAO HA DDL, entao o arquivo roda numa transacao de
-- verdade: tudo ou nada. Partir e a unica forma de chegar ao estado pela
-- metade - os atalhos gravados apontando para um card 022 que nao existe
-- deixariam o rascunho impublicavel (regra 17) ate alguem rodar o resto.
--
-- IDEMPOTENCIA:
--   - o insert dos cards novos e `on conflict (id) do nothing`: rodar de novo
--     nao pisa num card que a cliente ja tenha editado;
--   - a religacao e `jsonb_set` no mesmo caminho, entao reaplicar nao muda nada;
--   - os dois updates de atalho exigem `not (config ? 'atalhos')`, entao
--     reaplicar nunca pisa num atalho montado na gaveta do painel;
--   - o insert do retrato e `on conflict do nothing`;
--   - o `update` do ponteiro e de RODADA UNICA. Ele existe para apontar a
--     triagem para a versao 3; rodar esta migration depois de a cliente
--     publicar uma versao 4 pelo painel jogaria o fluxo de volta para a 3.
--
-- A "Lista de espera" NAO e tocada: os cards dela nao mudam e a versao 2 dela
-- segue publicada. O card 15 da triagem continua saltando para ela.
--
-- tests/test-fluxo-v3.php le este arquivo como texto, confere a trava acima
-- comando por comando e passa o retrato pelo validador e pelo roteador de
-- verdade.
-- ============================================================


-- >>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>
-- >>>  CONSULTAS DE CONFERENCIA. NAO SAO PARTE DA MIGRATION.             <<<
-- >>>  Toda linha deste bloco esta comentada e NADA aqui e executado ao  <<<
-- >>>  rodar o arquivo. Para usar, copie as linhas da consulta sem os    <<<
-- >>>  `--`. A migration comeca depois do marcador de fim deste bloco.   <<<
-- >>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>
--
-- (A) ANTES DE RODAR: "o que a cliente reescreveu no rascunho?" Compara o
-- rascunho de hoje com a versao que esta publicada. Foi esta consulta que
-- mediu os 11 textos, as 6 listas de opcoes e os 3 lembretes, e e ela que
-- justifica a inversao descrita no cabecalho. Rodar de novo antes de aplicar:
-- se o numero mudou, a cliente seguiu editando, e isto esta CERTO - o retrato
-- abaixo e que fica velho, e nesse caso a publicacao certa e ela clicar em
-- Publicar no painel, nao rodar este arquivo.
--
-- with pub as (
--   select c.*, v.grafo->'cards'->(c.id::text) as s
--     from public.po_wa_cards c
--     join public.po_wa_fluxos f on f.id = c.fluxo_id
--     join public.po_wa_fluxo_versoes v on v.id = f.versao_atual
--    where c.fluxo_id = 'a1000000-0000-4000-8000-000000000001'
-- )
-- select k.ordem, k.titulo, k.id,
--        array_remove(array[
--          case when k.tipo             is distinct from k.s->>'tipo'                      then 'tipo'           end,
--          case when k.titulo           is distinct from k.s->>'titulo'                    then 'titulo'         end,
--          case when k.texto            is distinct from k.s->>'texto'                     then 'texto'          end,
--          case when k.opcoes           is distinct from k.s->'opcoes'                     then 'opcoes'         end,
--          case when k.config           is distinct from k.s->'config'                     then 'config'         end,
--          case when k.proximo::text    is distinct from k.s->>'proximo'                   then 'proximo'        end,
--          case when k.fallback::text   is distinct from k.s->>'fallback'                  then 'fallback'       end,
--          case when k.sem_resposta::text is distinct from k.s->>'sem_resposta'            then 'sem_resposta'   end,
--          case when k.lembrete_ativo   is distinct from (k.s->>'lembrete_ativo')::boolean then 'lembrete_ativo' end,
--          case when k.lembrete_texto   is distinct from k.s->>'lembrete_texto'            then 'lembrete_texto' end
--        ], null) as campos_editados
--   from pub k order by k.ordem;
--
-- `is distinct from` e nao `<>` em todos os dez campos: a maioria dos cards tem
-- `texto`, `proximo` ou `fallback` nulo (marca, ir_para_fluxo, humano) e `<>`
-- com nulo devolve nulo, nunca verdadeiro, entao esses campos jamais acusariam
-- edicao. Para nao confiar num resultado que nao comparou nada, a consulta
-- devolve UMA LINHA POR CARD (hoje 15): coluna vazia e "nao mexeu".
--
--
-- (B) DEPOIS DE RODAR: o rascunho e o retrato da versao 3 batem, campo por
-- campo? E a conferencia por hash canonico da casa, e ela prova que os cinco
-- primeiros comandos produziram exatamente o rascunho de que o retrato abaixo
-- e copia. CALIBRAR NA VERSAO 2 PRIMEIRO (troque o 3 por 2 na ultima linha):
-- com a versao 2 ela TEM de acusar diferenca nos cards que a cliente reescreveu,
-- e um hash que nunca acusou nada nao prova nada.
--
-- Campos escalares so (`->>`, booleano como true/false, ausente como ''),
-- porque a conta nao pode depender de como cada lado serializa jsonb.
--
-- select v.versao,
--        count(*) as cards,
--        count(*) filter (where h.do_rascunho is distinct from h.do_retrato) as diferentes,
--        md5(string_agg(h.do_rascunho, '|' order by h.id)) as hash_rascunho,
--        md5(string_agg(h.do_retrato,  '|' order by h.id)) as hash_retrato
--   from public.po_wa_fluxo_versoes v
--  cross join lateral (
--    select c.id::text as id,
--           md5(concat_ws(chr(31), c.tipo, c.titulo, coalesce(c.texto,''), c.opcoes::text, c.config::text,
--                         coalesce(c.proximo::text,''), coalesce(c.fallback::text,''),
--                         coalesce(c.sem_resposta::text,''), c.lembrete_ativo::text,
--                         coalesce(c.lembrete_texto,''))) as do_rascunho,
--           md5(concat_ws(chr(31), s->>'tipo', s->>'titulo', coalesce(s->>'texto',''),
--                         (s->'opcoes')::text, coalesce(nullif((s->'config')::text,'[]'),'{}'),
--                         coalesce(s->>'proximo',''), coalesce(s->>'fallback',''),
--                         coalesce(s->>'sem_resposta',''), (s->>'lembrete_ativo'),
--                         coalesce(s->>'lembrete_texto',''))) as do_retrato
--      from public.po_wa_cards c
--      left join lateral (select v.grafo->'cards'->(c.id::text) as s) j on true
--     where c.fluxo_id = v.fluxo_id
--  ) h
--  where v.fluxo_id = 'a1000000-0000-4000-8000-000000000001' and v.versao = 3
--  group by v.versao;
--
-- `diferentes = 0` e o resultado esperado na versao 3. `opcoes::text` e
-- `config::text` comparam a forma canonica do jsonb dos dois lados (chaves
-- ordenadas pelo Postgres), que e justamente o que se quer: igualdade de
-- CONTEUDO, nao de como alguem digitou.
--
-- O `nullif(...,'[]')` do `config` NAO e um relaxamento para fazer a conta
-- fechar, e nao esconde nada: ele nomeia um artefato REAL e inevitavel desta
-- casa. `config` e `jsonb not null default '{}'` na tabela, mas o retrato
-- nasce de wa_fluxo_grafo_de, em PHP, e `json_decode($s, true)` nao distingue
-- objeto vazio de lista vazia - os dois viram array() e voltam como `[]` no
-- json_encode. Entao TODO retrato publicado por esta casa, inclusive os que a
-- cliente publicar pelo botao Publicar, traz `"config": []` em card de config
-- vazia. Sem esta normalizacao a conferencia acusaria 11 diferencas que nao
-- existem, e quem a rodasse da proxima vez aprenderia a ignora-la - que e como
-- um instrumento morre. Em execucao os dois sao iguais: o PHP le `[]` e `{}`
-- da mesma forma, e `$config['atalhos'] ?? null` funciona nos dois.
-- Medido em 05/11/2026 nos 11 cards de config vazia da triagem.
-- >>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>
-- >>>  fim das consultas de conferencia. A MIGRATION COMECA AQUI.        <<<
-- >>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>

-- 1. Os dois cards novos. `do nothing` no conflito: rodar de novo nao pisa em
-- card que a cliente tenha editado. Os textos sao FICTICIOS, como o cliente
-- pediu em 04/11: nada aqui e definitivo, a Lais reescreve na aba Fluxo.
-- (ordem, x, y) e so o desenho na tela, e card sem posicao a tela empilha em
-- 0,0 por cima de outro; as duas posicoes escolhidas estao livres na v2.
insert into public.po_wa_cards
  (id, fluxo_id, tipo, titulo, texto, opcoes, config, proximo, fallback, sem_resposta,
   lembrete_ativo, lembrete_texto, ordem, x, y)
values
  ('c1000000-0000-4000-8000-000000000021', 'a1000000-0000-4000-8000-000000000001', 'fim', 'Despedida de quem recusou a lista', 'Tudo bem, obrigado por falar com a gente. Quando quiser saber de alguma viagem, é só escrever nesta conversa. As viagens que já estão com data aberta ficam aqui: https://pereiraoliveiraturismo.com.br/roteiros', '[]'::jsonb, '{}'::jsonb, null, null, null, false, null, 16, 2560, 360),
  ('c1000000-0000-4000-8000-000000000022', 'a1000000-0000-4000-8000-000000000001', 'botoes', 'Sem data para este destino', 'Ainda não temos data aberta para {destino}. Podemos deixar o seu contato na lista de espera deste destino e avisar você assim que abrirmos uma data. Quer entrar na lista de espera?', '[{"id":"sd_sim","rotulo":"Sim, quero","destino":"c1000000-0000-4000-8000-000000000015"},{"id":"sd_nao","rotulo":"Não, obrigado","destino":"c1000000-0000-4000-8000-000000000021"}]'::jsonb, '{}'::jsonb, null, 'c1000000-0000-4000-8000-000000000016', 'c1000000-0000-4000-8000-000000000017', true, 'Oi, tudo bem? Se quiser que avisemos quando abrirmos uma data deste destino, é só tocar em uma das opções da mensagem anterior.', 17, 1920, 560)
on conflict (id) do nothing;

-- 2. A religacao do "Nao, obrigado", nos dois cards de lista de espera.
--
-- CIRURGICA, e e por isso que ela nao e um literal. O caminho obvio - escrever
-- a lista de opcoes inteira - perderia o rotulo que a cliente reescreveu, e os
-- dois cards estao justamente entre os 6 em que ela mexeu em `opcoes` ("Sim,
-- tenho interesse" no lugar de "Sim, quero"). Entao a lista e reconstruida
-- opcao por opcao e so o `destino` da opcao `e_nao` troca.
--
-- `with ordinality` + `order by t.i`: jsonb_agg sem ordem explicita nao
-- promete a ordem das linhas, e opcao e POSICAO na tela do cliente - invertida,
-- "Nao, obrigado" viria antes de "Sim".
--
-- As duas guardas: a primeira recusa religar para um card que nao existe (sem
-- ela, um arquivo partido deixaria a opcao apontando para o vazio, que a regra
-- 1 do validador reprova e a conversa viveria como "respondeu e nada
-- aconteceu"); a segunda evita gravar uma lista reconstruida em card que nao
-- tem a opcao `e_nao`, caso a cliente a tenha renomeado ou apagado.
update public.po_wa_cards c
   set opcoes = (
         select jsonb_agg(
                  case when t.o->>'id' = 'e_nao'
                       then jsonb_set(t.o, '{destino}', to_jsonb('c1000000-0000-4000-8000-000000000021'::text))
                       else t.o end
                  order by t.i)
           from jsonb_array_elements(c.opcoes) with ordinality as t(o, i)
       )
 where c.id in ('c1000000-0000-4000-8000-000000000014',
                'c1000000-0000-4000-8000-000000000020')
   and exists (select 1 from public.po_wa_cards n where n.id = 'c1000000-0000-4000-8000-000000000021')
   and exists (select 1 from jsonb_array_elements(c.opcoes) e where e->>'id' = 'e_nao');

-- 3. Os atalhos do card de boas-vindas. A ORDEM E DADO: palavras, depois
-- roteiro, depois destino. Pedido explicito por uma pessoa ganha de palpite, e
-- roteiro a venda e resposta melhor que "nao temos data" - o Chile esta nas
-- duas listas, e e esta ordem que faz quem escreve "quero saber do Chile"
-- receber o roteiro em vez da lista de espera. A cliente muda na gaveta.
--
-- `config || ...` MESCLA, nao substitui: card de marca guarda status e
-- qualif_* no mesmo campo, e substituir apagaria a marca. O
-- `not (config ? 'atalhos')` e o que impede esta migration de pisar num atalho
-- que a cliente tenha montado na gaveta do painel.
--
-- A saudacao existe porque no card inicial um atalho faz a pessoa pular as
-- boas-vindas: sem ela, quem escreve "quero saber do Chile" recebe um PDF como
-- primeira mensagem da vida.
update public.po_wa_cards
   set config = config || '{"atalhos":[{"tipo":"palavras","palavras":["atendente","atendimento","humano","pessoa","falar com alguem"],"destino":"c1000000-0000-4000-8000-000000000004","saudacao":"Olá! Já estou chamando a nossa equipe para falar com você."},{"tipo":"roteiro","palavras":[],"destino":"c1000000-0000-4000-8000-000000000005","saudacao":"Olá! Já vou te mandar o roteiro completo dessa viagem."},{"tipo":"destino","palavras":[],"destino":"c1000000-0000-4000-8000-000000000022","saudacao":"Olá! Deixe eu ver como estão as datas desse destino."}]}'::jsonb
 where id = 'c1000000-0000-4000-8000-000000000001'
   and not (config ? 'atalhos')
   and exists (select 1 from public.po_wa_cards n where n.id = 'c1000000-0000-4000-8000-000000000022');

-- 4. Os atalhos do card "Qual viagem?". Dois, nao tres: o proprio card ja casa
-- roteiro por numero e por nome, e um atalho de roteiro ali seria uma segunda
-- regra respondendo a mesma pergunta. O de destino e o que importa aqui - e
-- neste card que a pessoa digita "Italia" depois de tocar em "Conhecer os
-- roteiros", e hoje isso cai no "nao entendi".
--
-- Saudacao vazia nos dois: no ponto 2 (card que espera) ela e ignorada, porque
-- a conversa ja comecou, e escrever uma ali prometeria uma mensagem que nao sai.
update public.po_wa_cards
   set config = config || '{"atalhos":[{"tipo":"palavras","palavras":["atendente","atendimento","humano","pessoa","falar com alguem"],"destino":"c1000000-0000-4000-8000-000000000004","saudacao":""},{"tipo":"destino","palavras":[],"destino":"c1000000-0000-4000-8000-000000000022","saudacao":""}]}'::jsonb
 where id = 'c1000000-0000-4000-8000-000000000002'
   and not (config ? 'atalhos')
   and exists (select 1 from public.po_wa_cards n where n.id = 'c1000000-0000-4000-8000-000000000022');

-- 5. O retrato da versao 3. Imutavel depois de gravado.
--
-- COPIA DO RASCUNHO, nao fonte dele. Foi montado em 05/11/2026 pelo proprio
-- wa_fluxo_grafo_de - o montador que o botao Publicar usa, sem segunda
-- implementacao - a partir do rascunho ja com os quatro comandos acima
-- aplicados. Por isso ele carrega os 11 textos, as 6 listas de opcoes e os 3
-- lembretes que a cliente reescreveu. Nenhum comando desta migration escreve
-- esses campos de volta no rascunho.
insert into public.po_wa_fluxo_versoes (id, fluxo_id, versao, grafo) values ('b1000000-0000-4000-8000-000000000005', 'a1000000-0000-4000-8000-000000000001', 3, $grafo$
{
    "id": "a1000000-0000-4000-8000-000000000001",
    "nome": "Boas-vindas e triagem",
    "inicial": "c1000000-0000-4000-8000-000000000001",
    "humano": "c1000000-0000-4000-8000-000000000004",
    "porta": "mensagem_nova",
    "porta_valor": null,
    "cards": {
        "c1000000-0000-4000-8000-000000000001": {
            "id": "c1000000-0000-4000-8000-000000000001",
            "tipo": "botoes",
            "titulo": "Boas-vindas",
            "texto": "Olá! Que bom ter você por aqui! Seja muito bem-vindo(a) à Pereira Oliveira Turismo! Desde 1967, organizamos viagens em grupo, com todo o cuidado e atenção que cada experiência merece. Será um prazer compartilhar mais informações com você!",
            "opcoes": [
                {
                    "id": "b_roteiro",
                    "rotulo": "Conhecer os roteiros",
                    "destino": "c1000000-0000-4000-8000-000000000002"
                },
                {
                    "id": "b_humano",
                    "rotulo": "Falar com a equipe",
                    "destino": "c1000000-0000-4000-8000-000000000004"
                }
            ],
            "config": {
                "atalhos": [
                    {
                        "tipo": "palavras",
                        "palavras": [
                            "atendente",
                            "atendimento",
                            "humano",
                            "pessoa",
                            "falar com alguem"
                        ],
                        "destino": "c1000000-0000-4000-8000-000000000004",
                        "saudacao": "Olá! Já estou chamando a nossa equipe para falar com você."
                    },
                    {
                        "tipo": "roteiro",
                        "palavras": [],
                        "destino": "c1000000-0000-4000-8000-000000000005",
                        "saudacao": "Olá! Já vou te mandar o roteiro completo dessa viagem."
                    },
                    {
                        "tipo": "destino",
                        "palavras": [],
                        "destino": "c1000000-0000-4000-8000-000000000022",
                        "saudacao": "Olá! Deixe eu ver como estão as datas desse destino."
                    }
                ]
            },
            "proximo": null,
            "fallback": "c1000000-0000-4000-8000-000000000016",
            "sem_resposta": "c1000000-0000-4000-8000-000000000017",
            "lembrete_ativo": true,
            "lembrete_texto": "Olá! Passando para saber se você conseguiu ver nossa mensagem anterior. Se quiser conhecer melhor nossas viagens, será um prazer compartilhar mais informações com você!\n\nEstamos à disposição."
        },
        "c1000000-0000-4000-8000-000000000002": {
            "id": "c1000000-0000-4000-8000-000000000002",
            "tipo": "roteiros",
            "titulo": "Qual viagem?",
            "texto": "Qual dos nossos destinos chamou sua atenção? Basta nos informar o nome ou o número da viagem e enviaremos o roteiro completo para você.",
            "opcoes": [],
            "config": {
                "atalhos": [
                    {
                        "tipo": "palavras",
                        "palavras": [
                            "atendente",
                            "atendimento",
                            "humano",
                            "pessoa",
                            "falar com alguem"
                        ],
                        "destino": "c1000000-0000-4000-8000-000000000004",
                        "saudacao": ""
                    },
                    {
                        "tipo": "destino",
                        "palavras": [],
                        "destino": "c1000000-0000-4000-8000-000000000022",
                        "saudacao": ""
                    }
                ]
            },
            "proximo": "c1000000-0000-4000-8000-000000000005",
            "fallback": "c1000000-0000-4000-8000-000000000016",
            "sem_resposta": "c1000000-0000-4000-8000-000000000017",
            "lembrete_ativo": true,
            "lembrete_texto": "Olá, tudo bem? Passando para saber se alguma das nossas próximas viagens despertou seu interesse. Se desejar, teremos prazer em enviar o roteiro completo para você."
        },
        "c1000000-0000-4000-8000-000000000004": {
            "id": "c1000000-0000-4000-8000-000000000004",
            "tipo": "humano",
            "titulo": "Atendimento humano",
            "texto": "Perfeito! Vou encaminhar seu atendimento para a Laís, que dará continuidade à nossa conversa. Se desejar, pode deixar sua mensagem por aqui que ela responderá assim que possível.",
            "opcoes": [],
            "config": [],
            "proximo": null,
            "fallback": null,
            "sem_resposta": null,
            "lembrete_ativo": false,
            "lembrete_texto": null
        },
        "c1000000-0000-4000-8000-000000000005": {
            "id": "c1000000-0000-4000-8000-000000000005",
            "tipo": "pdf",
            "titulo": "Manda o PDF",
            "texto": "Segue o roteiro completo da nossa viagem para {roteiro}, com a programação dia a dia, os serviços incluídos e todas as informações sobre o investimento.",
            "opcoes": [],
            "config": [],
            "proximo": "c1000000-0000-4000-8000-000000000006",
            "fallback": null,
            "sem_resposta": null,
            "lembrete_ativo": false,
            "lembrete_texto": null
        },
        "c1000000-0000-4000-8000-000000000006": {
            "id": "c1000000-0000-4000-8000-000000000006",
            "tipo": "marca",
            "titulo": "Marca interesse",
            "texto": null,
            "opcoes": [],
            "config": {
                "interesses": "+roteiro"
            },
            "proximo": "c1000000-0000-4000-8000-000000000007",
            "fallback": null,
            "sem_resposta": null,
            "lembrete_ativo": false,
            "lembrete_texto": null
        },
        "c1000000-0000-4000-8000-000000000007": {
            "id": "c1000000-0000-4000-8000-000000000007",
            "tipo": "botoes",
            "titulo": "A data serve?",
            "texto": "Só para confirmar, nossa viagem está programada para {data}. Esse período é adequado para você?",
            "opcoes": [
                {
                    "id": "d_sim",
                    "rotulo": "Sim, posso viajar",
                    "destino": "c1000000-0000-4000-8000-000000000008"
                },
                {
                    "id": "d_nao",
                    "rotulo": "Não posso viajar",
                    "destino": "c1000000-0000-4000-8000-000000000013"
                }
            ],
            "config": [],
            "proximo": null,
            "fallback": "c1000000-0000-4000-8000-000000000018",
            "sem_resposta": "c1000000-0000-4000-8000-000000000017",
            "lembrete_ativo": true,
            "lembrete_texto": "Oi, tudo bem? Só passando para saber se você chegou a ver o roteiro que enviamos."
        },
        "c1000000-0000-4000-8000-000000000008": {
            "id": "c1000000-0000-4000-8000-000000000008",
            "tipo": "marca",
            "titulo": "Marca: a data serve",
            "texto": null,
            "opcoes": [],
            "config": {
                "status": "atendimento",
                "qualif_at": true,
                "qualif_data": true
            },
            "proximo": "c1000000-0000-4000-8000-000000000012",
            "fallback": null,
            "sem_resposta": null,
            "lembrete_ativo": false,
            "lembrete_texto": null
        },
        "c1000000-0000-4000-8000-000000000012": {
            "id": "c1000000-0000-4000-8000-000000000012",
            "tipo": "humano",
            "titulo": "A Laís assume",
            "texto": "Ótimo! Seu interesse já foi encaminhado à Laís, que dará continuidade ao atendimento por aqui. Ela estará à disposição para esclarecer suas dúvidas e orientar você sobre os próximos passos da viagem.",
            "opcoes": [],
            "config": [],
            "proximo": null,
            "fallback": null,
            "sem_resposta": null,
            "lembrete_ativo": false,
            "lembrete_texto": null
        },
        "c1000000-0000-4000-8000-000000000013": {
            "id": "c1000000-0000-4000-8000-000000000013",
            "tipo": "marca",
            "titulo": "Marca: a data não serve",
            "texto": null,
            "opcoes": [],
            "config": {
                "status": "perdido",
                "qualif_at": true,
                "qualif_data": false
            },
            "proximo": "c1000000-0000-4000-8000-000000000014",
            "fallback": null,
            "sem_resposta": null,
            "lembrete_ativo": false,
            "lembrete_texto": null
        },
        "c1000000-0000-4000-8000-000000000014": {
            "id": "c1000000-0000-4000-8000-000000000014",
            "tipo": "botoes",
            "titulo": "Lista de espera?",
            "texto": "Entendemos! Se desejar, podemos registrar seu interesse para que você seja informado caso tenhamos uma nova saída para esse destino. Gostaria que incluíssemos seu nome em nossa lista de interessados?",
            "opcoes": [
                {
                    "id": "e_sim",
                    "rotulo": "Sim, tenho interesse",
                    "destino": "c1000000-0000-4000-8000-000000000015"
                },
                {
                    "id": "e_nao",
                    "rotulo": "Não, obrigado",
                    "destino": "c1000000-0000-4000-8000-000000000021"
                }
            ],
            "config": [],
            "proximo": null,
            "fallback": "c1000000-0000-4000-8000-000000000020",
            "sem_resposta": "c1000000-0000-4000-8000-000000000017",
            "lembrete_ativo": true,
            "lembrete_texto": "Oi, tudo bem? Se quiser que avisemos quando abrirmos novas datas deste destino, é só tocar em uma das opções da mensagem anterior."
        },
        "c1000000-0000-4000-8000-000000000015": {
            "id": "c1000000-0000-4000-8000-000000000015",
            "tipo": "ir_para_fluxo",
            "titulo": "Lista de espera",
            "texto": null,
            "opcoes": [],
            "config": {
                "fluxo": "a1000000-0000-4000-8000-000000000002"
            },
            "proximo": null,
            "fallback": null,
            "sem_resposta": null,
            "lembrete_ativo": false,
            "lembrete_texto": null
        },
        "c1000000-0000-4000-8000-000000000016": {
            "id": "c1000000-0000-4000-8000-000000000016",
            "tipo": "botoes",
            "titulo": "Respondeu outra coisa",
            "texto": "Para que possamos ajudar você da melhor forma, selecione uma das opções abaixo. Se preferir, também podemos encaminhar sua mensagem para um atendimento personalizado.",
            "opcoes": [
                {
                    "id": "b_roteiro",
                    "rotulo": "Conhecer os roteiros",
                    "destino": "c1000000-0000-4000-8000-000000000002"
                },
                {
                    "id": "b_humano",
                    "rotulo": "Falar com a Laís",
                    "destino": "c1000000-0000-4000-8000-000000000004"
                }
            ],
            "config": [],
            "proximo": null,
            "fallback": null,
            "sem_resposta": "c1000000-0000-4000-8000-000000000017",
            "lembrete_ativo": true,
            "lembrete_texto": "Oi, tudo bem? Passando para saber se podemos ajudar com alguma viagem. Se preferir, é só nos responder por aqui."
        },
        "c1000000-0000-4000-8000-000000000017": {
            "id": "c1000000-0000-4000-8000-000000000017",
            "tipo": "fim",
            "titulo": "Despedida de quem não responde",
            "texto": "Olá! Como não tivemos seu retorno, vamos deixar nossa conversa por aqui. Mas saiba que estaremos sempre à disposição caso queira conhecer melhor alguma de nossas viagens. Será um prazer atender você!",
            "opcoes": [],
            "config": [],
            "proximo": null,
            "fallback": null,
            "sem_resposta": null,
            "lembrete_ativo": false,
            "lembrete_texto": null
        },
        "c1000000-0000-4000-8000-000000000018": {
            "id": "c1000000-0000-4000-8000-000000000018",
            "tipo": "botoes",
            "titulo": "Repete: a data serve?",
            "texto": "Só para confirmar, nossa viagem está programada para {data}. Esse período é adequado para você?",
            "opcoes": [
                {
                    "id": "d_sim",
                    "rotulo": "Sim, posso viajar",
                    "destino": "c1000000-0000-4000-8000-000000000008"
                },
                {
                    "id": "d_nao",
                    "rotulo": "Não posso viajar",
                    "destino": "c1000000-0000-4000-8000-000000000013"
                }
            ],
            "config": [],
            "proximo": null,
            "fallback": null,
            "sem_resposta": "c1000000-0000-4000-8000-000000000017",
            "lembrete_ativo": true,
            "lembrete_texto": "Oi, tudo bem? Só passando para saber se você chegou a ver o roteiro que enviamos."
        },
        "c1000000-0000-4000-8000-000000000020": {
            "id": "c1000000-0000-4000-8000-000000000020",
            "tipo": "botoes",
            "titulo": "Repete: lista de espera?",
            "texto": "Só para confirmar, gostaria que registrássemos seu interesse para uma possível nova saída desse destino? Assim, poderemos entrar em contato caso tenhamos novidades.",
            "opcoes": [
                {
                    "id": "e_sim",
                    "rotulo": "Sim, tenho interesse",
                    "destino": "c1000000-0000-4000-8000-000000000015"
                },
                {
                    "id": "e_nao",
                    "rotulo": "Não, obrigado",
                    "destino": "c1000000-0000-4000-8000-000000000021"
                }
            ],
            "config": [],
            "proximo": null,
            "fallback": null,
            "sem_resposta": "c1000000-0000-4000-8000-000000000017",
            "lembrete_ativo": true,
            "lembrete_texto": "Olá! Passando apenas para confirmar se gostaria que registrássemos seu interesse em uma futura viagem para esse destino. Caso tenha interesse, basta selecionar uma das opções da mensagem anterior."
        },
        "c1000000-0000-4000-8000-000000000021": {
            "id": "c1000000-0000-4000-8000-000000000021",
            "tipo": "fim",
            "titulo": "Despedida de quem recusou a lista",
            "texto": "Tudo bem, obrigado por falar com a gente. Quando quiser saber de alguma viagem, é só escrever nesta conversa. As viagens que já estão com data aberta ficam aqui: https://pereiraoliveiraturismo.com.br/roteiros",
            "opcoes": [],
            "config": [],
            "proximo": null,
            "fallback": null,
            "sem_resposta": null,
            "lembrete_ativo": false,
            "lembrete_texto": null
        },
        "c1000000-0000-4000-8000-000000000022": {
            "id": "c1000000-0000-4000-8000-000000000022",
            "tipo": "botoes",
            "titulo": "Sem data para este destino",
            "texto": "Ainda não temos data aberta para {destino}. Podemos deixar o seu contato na lista de espera deste destino e avisar você assim que abrirmos uma data. Quer entrar na lista de espera?",
            "opcoes": [
                {
                    "id": "sd_sim",
                    "rotulo": "Sim, quero",
                    "destino": "c1000000-0000-4000-8000-000000000015"
                },
                {
                    "id": "sd_nao",
                    "rotulo": "Não, obrigado",
                    "destino": "c1000000-0000-4000-8000-000000000021"
                }
            ],
            "config": [],
            "proximo": null,
            "fallback": "c1000000-0000-4000-8000-000000000016",
            "sem_resposta": "c1000000-0000-4000-8000-000000000017",
            "lembrete_ativo": true,
            "lembrete_texto": "Oi, tudo bem? Se quiser que avisemos quando abrirmos uma data deste destino, é só tocar em uma das opções da mensagem anterior."
        }
    }
}
$grafo$::jsonb)
on conflict do nothing;

-- 6. A triagem passa a servir a versao 3. RODADA UNICA (ver o cabecalho).
-- O `exists` e a guarda que importa: se o insert do passo 5 nao gravou (a
-- cliente publicou uma versao 3 pelo painel e o `unique (fluxo_id, versao)`
-- recusou a nossa), apontar para um id que nao existe deixaria o motor sem
-- retrato para ler, e o fluxo pararia calado.
update public.po_wa_fluxos set versao_atual = 'b1000000-0000-4000-8000-000000000005'
 where id = 'a1000000-0000-4000-8000-000000000001'
   and exists (select 1 from public.po_wa_fluxo_versoes v where v.id = 'b1000000-0000-4000-8000-000000000005');
