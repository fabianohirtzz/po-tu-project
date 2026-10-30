-- ============================================================
-- Pereira Oliveira: a VERSAO 2 de "Boas-vindas e triagem" e de "Lista de
-- espera", publicadas e DESLIGADAS, com os cards do rascunho redesenhados.
-- Rodar no Supabase -> SQL Editor, DEPOIS de 2026-10-27-fluxo-semeado.sql e de
-- 2026-10-30-roteiros-enviados.sql.
--
-- POR QUE UMA MIGRATION NOVA, e nao uma edicao da semente: o motor le so o
-- retrato imutavel de po_wa_fluxo_versoes.grafo, e po_wa_conversas.fluxo_versao_id
-- prende cada conversa a versao em que ela entrou. Reescrever a versao 1 mudaria
-- o roteiro debaixo de quem esta no meio da conversa. A versao 1 fica onde esta.
--
-- O QUE MUDA (reuniao de 29/10/2026 com a cliente):
--   1. a boas-vindas perde a porta "Ver a programacao" e fica com duas:
--      "Roteiros" e "Falar com a Lais";
--   2. a escolha da viagem deixa de ser lista interativa e vira o card
--      `roteiros`: texto numerado montado do banco, que aceita resposta
--      digitada (numero, nome inteiro ou parte do nome);
--   3. a pergunta "ja viajou em grupo" sai inteira, com as duas marcas dela;
--   4. depois do PDF fica so "a data serve?". Sim entrega a conversa a Lais;
--      Nao oferece a lista de espera, e quem recusa recebe a despedida;
--   5. `{nome}` sai de TODO texto dos dois fluxos, lembrete incluido: ela nao
--      e mais variavel valida e a regra 14 do validador bloquearia o Publicar.
--      E por isso a "Lista de espera" tambem ganha versao 2: sem ela publicada
--      a regra 11 impediria a cliente de ligar a triagem.
--
-- DESLIGADOS: nenhum comando aqui escreve `ativo`. Publicar e ligar sao atos
-- separados, e ligar e clique da cliente depois de revisar os textos no painel.
-- Ao ligar, "Lista de espera" ANTES de "Boas-vindas e triagem" (o salto exige
-- destino ativo e publicado).
--
-- RODAR INTEIRO, DE UMA VEZ, no SQL Editor. NAO aplicar em pedacos: a regra da casa
-- "em pedacos" existe porque o portao recusa drop/update no mesmo bloco do DDL, e
-- este arquivo NAO TEM DDL (sao seis comandos: insert, insert, delete, insert,
-- update, update). Sem DDL ele roda numa transacao de verdade, tudo ou nada.
-- Partir e a UNICA forma de chegar ao estado pela metade: parar depois do `delete`
-- (irreversivel) e antes do redesenho deixa o rascunho sem 5 cards enquanto o
-- ponteiro ainda aponta a versao 1, e o painel desenha uma conversa que o motor
-- nao executa.
--
-- IDEMPOTENCIA, e o que ela NAO cobre:
--   - os dois inserts de versao sao `on conflict do nothing`, e o redesenho do
--     rascunho e `on conflict (id) do update`: rodar de novo nao duplica nada e
--     recria card seed que tenha sido apagado;
--   - os dois `update` de `versao_atual` sao de RODADA UNICA. Eles existem para
--     apontar os fluxos para a versao 2; rodar esta migration depois de a cliente
--     publicar uma versao 3 pelo painel jogaria os fluxos de volta para a 2;
--   - TUDO OU NADA, POR FLUXO: cada fluxo aplica inteiro ou nao aplica nada. Os
--     passos 2 e 4 carregam a guarda `exists` da versao do SEU fluxo, entao se a
--     versao 2 da triagem nao gravar, a exclusao dos 5 cards nao roda, o ponteiro
--     da triagem nao anda e o passo 3 nao deriva card nenhum dela. A guarda do
--     passo 2 e a que importa, porque e o unico comando irreversivel do arquivo;
--     a do passo 4 so evita um ponteiro pendurado;
--   - e a garantia e POR FLUXO DE PROPOSITO, nao por acoplamento esquecido: uma
--     colisao na triagem nao pode impedir a "Lista de espera" de publicar. Sem a
--     versao 2 dela o `{nome}` segue nos textos, a regra 14 a torna impublicavel,
--     e a regra 11 exige a espera publicada e LIGADA antes de a triagem poder ser
--     ligada (a triagem salta para dentro dela). Acoplar os dois faria uma colisao
--     num lado deixar a cliente sem conseguir ligar o robo, que e o objetivo de
--     tudo isto. No caso em que so um lado aplica, o rascunho de cada fluxo segue
--     casado com a versao publicada DELE, entao nada diverge;
--   - o redesenho SOBRESCREVE os 17 cards semeados, que e o proposito: retrato e
--     rascunho tem de casar, senao o painel desenha uma conversa e o motor executa
--     outra. Edicao que a cliente tenha feito nesses 17 cards se perde. ISSO E
--     MEDIVEL ANTES: rode a consulta de conferencia logo abaixo do cabecalho;
--   - a exclusao e por ID, um a um, e por isso NUNCA alcanca card que a cliente
--     tenha criado no painel. Os 5 ids sao os cards da programacao e da pergunta
--     do grupo, conferidos contra 2026-10-27-fluxo-semeado.sql.
--
-- A fonte da verdade e o retrato jsonb abaixo. Os cards do rascunho (po_wa_cards)
-- sao DERIVADOS dele, linha a linha, pelo mesmo jsonb_each da semente, para os
-- dois nunca divergirem. tests/test-wa-fluxo-semeado.php le este arquivo como
-- texto e passa os dois retratos pelo validador e pelo roteador de verdade.
-- Plano: docs/superpowers/plans/2026-10-30-fluxo-ajustes-reuniao.md
-- ============================================================


-- >>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>
-- >>>  CONSULTA DE CONFERENCIA. NAO E PARTE DA MIGRATION.                <<<
-- >>>  Toda linha deste bloco esta comentada e NADA aqui e executado ao  <<<
-- >>>  rodar o arquivo. Para usar, copie as linhas da consulta sem os    <<<
-- >>>  `--` e rode ANTES da migration. A migration comeca depois do      <<<
-- >>>  marcador de fim deste bloco.                                      <<<
-- >>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>
--
-- O QUE ELA RESPONDE: "a cliente editou a mao algum card que esta migration vai
-- sobrescrever ou apagar?" O retrato da VERSAO 1 ainda guarda, campo por campo,
-- o card que a semente escreveu (`versao = 1` de cada fluxo, que sao as versoes
-- b1000000-...-000000000001 e b1000000-...-000000000002), entao basta comparar o
-- rascunho de hoje com ele.
--
-- ELA COMPARA TUDO QUE O PASSO 3 SOBRESCREVE, nao so o texto: `tipo`, `titulo`,
-- `texto`, `opcoes`, `config`, `proximo`, `fallback`, `sem_resposta`,
-- `lembrete_ativo` e `lembrete_texto`, nos DOIS fluxos. Conferir so os dois
-- campos de texto daria all-clear a quem renomeou um card, mexeu no rotulo de um
-- botao ou trocou um destino, e essas edicoes se perderiam caladas.
--
-- DE FORA, de proposito: `ordem`, `x` e `y`. O passo 3 TAMBEM reseta a posicao
-- dos cards no canvas, e a cliente arrasta card, entao isso vai mudar mesmo. Mas
-- e cosmetico, e 22 linhas de "posicao mudou" enterrariam o que importa. Fica
-- dito aqui para ninguem se surpreender: o desenho do canvas volta ao padrao.
--
-- COMO LER O RESULTADO:
--   - NENHUMA LINHA: ninguem mexeu nos cards semeados. Pode rodar a migration.
--   - UMA OU MAIS LINHAS: aqueles cards foram editados DEPOIS da semente, e rodar
--     a migration vai sobrescrever (ou, nos 5 que saem, apagar) o que esta la. A
--     coluna `campos_editados` diz exatamente o que se perde em cada um. Decida
--     com a cliente antes de rodar.
--
-- Card que a CLIENTE criou no painel nao aparece aqui, e esta correto: ele nao
-- existe no retrato da versao 1, e a migration tambem nao o toca.
--
-- LIMITE: esta consulta ve card ALTERADO, nao card APAGADO. O join exige a linha do
-- rascunho, entao um card semeado que a cliente excluiu de proposito nao aparece, e
-- o passo 3 o recria. Para ver os apagados, rode a segunda consulta abaixo (so as
-- linhas da consulta, sem os `--`):
--
-- select f.nome as fluxo, v.grafo->'cards'->(k.id)->>'titulo' as titulo_semeado, k.id
--   from public.po_wa_fluxo_versoes v
--   join public.po_wa_fluxos f on f.id = v.fluxo_id
--  cross join lateral jsonb_object_keys(v.grafo->'cards') as k(id)
--  where v.versao = 1
--    and v.fluxo_id in ('a1000000-0000-4000-8000-000000000001',
--                       'a1000000-0000-4000-8000-000000000002')
--    and not exists (select 1 from public.po_wa_cards c where c.id = k.id::uuid);
--
--
-- with semeado as (
--   select c.*, v.grafo->'cards'->(c.id::text) as s
--     from public.po_wa_cards c
--     join public.po_wa_fluxo_versoes v on v.fluxo_id = c.fluxo_id and v.versao = 1
--    where c.fluxo_id in ('a1000000-0000-4000-8000-000000000001',
--                         'a1000000-0000-4000-8000-000000000002')
-- ), diferenca as (
--   select f.nome as fluxo, k.ordem, k.titulo, k.id,
--          array_remove(array[
--            case when k.tipo             is distinct from k.s->>'tipo'                      then 'tipo'           end,
--            case when k.titulo           is distinct from k.s->>'titulo'                    then 'titulo'         end,
--            case when k.texto            is distinct from k.s->>'texto'                     then 'texto'          end,
--            case when k.opcoes           is distinct from k.s->'opcoes'                     then 'opcoes'         end,
--            case when k.config           is distinct from k.s->'config'                     then 'config'         end,
--            case when k.proximo::text    is distinct from k.s->>'proximo'                   then 'proximo'        end,
--            case when k.fallback::text   is distinct from k.s->>'fallback'                  then 'fallback'       end,
--            case when k.sem_resposta::text is distinct from k.s->>'sem_resposta'            then 'sem_resposta'   end,
--            case when k.lembrete_ativo   is distinct from (k.s->>'lembrete_ativo')::boolean then 'lembrete_ativo' end,
--            case when k.lembrete_texto   is distinct from k.s->>'lembrete_texto'            then 'lembrete_texto' end
--          ], null) as campos_editados
--     from semeado k
--     join public.po_wa_fluxos f on f.id = k.fluxo_id
--    where k.s is not null
-- )
-- select * from diferenca where cardinality(campos_editados) > 0 order by fluxo, ordem;
--
-- `is distinct from` e nao `<>` de proposito, e vale para todos os dez campos: a
-- maioria dos cards tem `texto`, `proximo` ou `fallback` nulo (marca,
-- ir_para_fluxo, humano) e `<>` com nulo devolve nulo, nunca verdadeiro, entao
-- esses campos jamais acusariam edicao. `opcoes` e `config` sao jsonb e comparam
-- direto, ignorando a ordem das chaves, que e o que se quer.
--
-- E PARA NAO CONFIAR NUM "NENHUMA LINHA" QUE NAO COMPAROU NADA: troque, na ultima
-- linha, o `> 0` por `>= 0` e rode de novo. Devem sair 22 linhas, que sao todos os
-- cards semeados: os 20 da triagem e os 2 da lista de espera. Se sairem menos, a
-- semente nao rodou como este arquivo supoe, e o "nenhuma linha" da consulta de
-- cima nao queria dizer "nada mudou".
-- >>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>
-- >>>  fim da consulta de conferencia. A MIGRATION COMECA AQUI.          <<<
-- >>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>

-- 1. Os retratos da versao 2. Imutaveis depois de gravados.
-- Fluxo 1: Boas-vindas e triagem
insert into public.po_wa_fluxo_versoes (id, fluxo_id, versao, grafo) values ('b1000000-0000-4000-8000-000000000003', 'a1000000-0000-4000-8000-000000000001', 2, $grafo$
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
            "texto": "Olá! Que bom ter você por aqui. A Pereira Oliveira Turismo organiza viagens em grupo desde 1967. Como podemos ajudar você hoje?",
            "opcoes": [
                {
                    "id": "b_roteiro",
                    "rotulo": "Roteiros",
                    "destino": "c1000000-0000-4000-8000-000000000002"
                },
                {
                    "id": "b_humano",
                    "rotulo": "Falar com a Laís",
                    "destino": "c1000000-0000-4000-8000-000000000004"
                }
            ],
            "config": {},
            "proximo": null,
            "fallback": "c1000000-0000-4000-8000-000000000016",
            "sem_resposta": "c1000000-0000-4000-8000-000000000017",
            "lembrete_ativo": true,
            "lembrete_texto": "Oi, tudo bem? Passando para saber se podemos ajudar com alguma viagem. Se preferir, é só nos responder por aqui."
        },
        "c1000000-0000-4000-8000-000000000002": {
            "id": "c1000000-0000-4000-8000-000000000002",
            "tipo": "roteiros",
            "titulo": "Qual viagem?",
            "texto": "Sobre qual viagem você quer saber? Responda com o número ou com o nome da viagem e nós enviamos o roteiro completo.",
            "opcoes": [],
            "config": {},
            "proximo": "c1000000-0000-4000-8000-000000000005",
            "fallback": "c1000000-0000-4000-8000-000000000016",
            "sem_resposta": "c1000000-0000-4000-8000-000000000017",
            "lembrete_ativo": true,
            "lembrete_texto": "Oi, tudo bem? Quer que mandemos o roteiro completo de alguma das nossas próximas viagens em grupo?"
        },
        "c1000000-0000-4000-8000-000000000004": {
            "id": "c1000000-0000-4000-8000-000000000004",
            "tipo": "humano",
            "titulo": "Atendimento humano",
            "texto": "Combinado. Já avisamos a Laís, que atende você pessoalmente nesta mesma conversa. Se quiser, escreva aqui o que a Laís precisa saber: ela lê tudo antes de responder.",
            "opcoes": [],
            "config": {},
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
            "texto": "Segue o roteiro completo do {roteiro}, com o dia a dia da viagem, tudo que está incluído e o valor.",
            "opcoes": [],
            "config": {},
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
            "texto": "Data da viagem: {data}. Essa data funciona para você?",
            "opcoes": [
                {
                    "id": "d_sim",
                    "rotulo": "Sim, funciona",
                    "destino": "c1000000-0000-4000-8000-000000000008"
                },
                {
                    "id": "d_nao",
                    "rotulo": "Não nessa data",
                    "destino": "c1000000-0000-4000-8000-000000000013"
                }
            ],
            "config": {},
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
                "qualif_data": true,
                "qualif_at": true
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
            "texto": "Ótimo. Já passamos o seu interesse para a Laís, que cuida pessoalmente das reservas. Ela continua o atendimento por aqui mesmo: valores, condições de pagamento e qualquer dúvida sobre a viagem.",
            "opcoes": [],
            "config": {},
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
                "qualif_data": false,
                "qualif_at": true
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
            "texto": "Entendo. Podemos deixar o seu contato na lista de espera deste destino e avisar você assim que abrirmos novas datas. Quer entrar na lista de espera?",
            "opcoes": [
                {
                    "id": "e_sim",
                    "rotulo": "Sim, quero",
                    "destino": "c1000000-0000-4000-8000-000000000015"
                },
                {
                    "id": "e_nao",
                    "rotulo": "Não, obrigado",
                    "destino": "c1000000-0000-4000-8000-000000000017"
                }
            ],
            "config": {},
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
            "texto": "Desculpe, não conseguimos entender. Para ajudarmos do jeito certo, toque em uma das opções abaixo.",
            "opcoes": [
                {
                    "id": "b_roteiro",
                    "rotulo": "Roteiros",
                    "destino": "c1000000-0000-4000-8000-000000000002"
                },
                {
                    "id": "b_humano",
                    "rotulo": "Falar com a Laís",
                    "destino": "c1000000-0000-4000-8000-000000000004"
                }
            ],
            "config": {},
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
            "texto": "Tudo bem. Vamos encerrar por aqui para não incomodar. Quando quiser falar sobre alguma viagem, é só escrever nesta conversa. Organizamos viagens em grupo desde 1967 e ficamos à disposição.",
            "opcoes": [],
            "config": {},
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
            "texto": "Desculpe, não conseguimos entender. Vamos repetir a pergunta. Data da viagem: {data}. Essa data funciona para você? Toque em um dos botões abaixo.",
            "opcoes": [
                {
                    "id": "d_sim",
                    "rotulo": "Sim, funciona",
                    "destino": "c1000000-0000-4000-8000-000000000008"
                },
                {
                    "id": "d_nao",
                    "rotulo": "Não nessa data",
                    "destino": "c1000000-0000-4000-8000-000000000013"
                }
            ],
            "config": {},
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
            "texto": "Desculpe, não conseguimos entender. Vamos repetir: podemos deixar o seu contato na lista de espera deste destino e avisar você quando abrirmos novas datas. Quer entrar na lista de espera? Toque em um dos botões abaixo.",
            "opcoes": [
                {
                    "id": "e_sim",
                    "rotulo": "Sim, quero",
                    "destino": "c1000000-0000-4000-8000-000000000015"
                },
                {
                    "id": "e_nao",
                    "rotulo": "Não, obrigado",
                    "destino": "c1000000-0000-4000-8000-000000000017"
                }
            ],
            "config": {},
            "proximo": null,
            "fallback": null,
            "sem_resposta": "c1000000-0000-4000-8000-000000000017",
            "lembrete_ativo": true,
            "lembrete_texto": "Oi, tudo bem? Se quiser que avisemos quando abrirmos novas datas deste destino, é só tocar em uma das opções da mensagem anterior."
        }
    }
}
$grafo$::jsonb)
on conflict do nothing;

-- Fluxo 2: Lista de espera
insert into public.po_wa_fluxo_versoes (id, fluxo_id, versao, grafo) values ('b1000000-0000-4000-8000-000000000004', 'a1000000-0000-4000-8000-000000000002', 2, $grafo$
{
    "id": "a1000000-0000-4000-8000-000000000002",
    "nome": "Lista de espera",
    "inicial": "c2000000-0000-4000-8000-000000000001",
    "humano": null,
    "porta": "chamado",
    "porta_valor": null,
    "cards": {
        "c2000000-0000-4000-8000-000000000001": {
            "id": "c2000000-0000-4000-8000-000000000001",
            "tipo": "marca",
            "titulo": "Anota o destino na espera",
            "texto": null,
            "opcoes": [],
            "config": {
                "espera": "+destino"
            },
            "proximo": "c2000000-0000-4000-8000-000000000002",
            "fallback": null,
            "sem_resposta": null,
            "lembrete_ativo": false,
            "lembrete_texto": null
        },
        "c2000000-0000-4000-8000-000000000002": {
            "id": "c2000000-0000-4000-8000-000000000002",
            "tipo": "fim",
            "titulo": "Avisa e termina",
            "texto": "Combinado. Deixamos o seu contato registrado e avisamos você assim que abrirmos novas datas deste destino.",
            "opcoes": [],
            "config": {},
            "proximo": null,
            "fallback": null,
            "sem_resposta": null,
            "lembrete_ativo": false,
            "lembrete_texto": null
        }
    }
}
$grafo$::jsonb)
on conflict do nothing;

-- 2. Os cards que saem do rascunho, nomeados um a um. Por ID, entao nao alcanca
-- card que a cliente tenha criado. Sao a lista da programacao (003) e a pergunta
-- "ja viajou em grupo" com as duas marcas dela e o repete (009, 010, 011, 019).
--
-- O `exists` NAO E ENFEITE, e e o mesmo do passo 4. Este e o unico comando do
-- arquivo que nao se desfaz, e por isso e o unico que nao pode rodar sozinho: se
-- o passo 1 nao gravou (a cliente publicou uma versao 2 pelo painel e o
-- `unique (fluxo_id, versao)` recusou a nossa), o passo 3 nao casa nada e o passo
-- 4 se barra, mas sem esta guarda os 5 cards JA FORAM, e sobra um rascunho sem
-- cards que o grafo publicado ainda aponta. Com ela a migration e tudo ou nada.
delete from public.po_wa_cards
 where id in (
   'c1000000-0000-4000-8000-000000000003',
   'c1000000-0000-4000-8000-000000000009',
   'c1000000-0000-4000-8000-000000000010',
   'c1000000-0000-4000-8000-000000000011',
   'c1000000-0000-4000-8000-000000000019'
 )
   and exists (select 1 from public.po_wa_fluxo_versoes where id = 'b1000000-0000-4000-8000-000000000003');

-- 3. Os cards do rascunho, derivados do retrato da versao 2. (ordem, x, y) e so
-- o desenho na tela; o retrato nao o guarda. O `do update` e o que mantem o
-- rascunho casado com o publicado, e recria card seed apagado.
insert into public.po_wa_cards
  (id, fluxo_id, tipo, titulo, texto, opcoes, config, proximo, fallback, sem_resposta,
   lembrete_ativo, lembrete_texto, ordem, x, y)
select (k.card->>'id')::uuid,
       v.fluxo_id,
       k.card->>'tipo',
       k.card->>'titulo',
       k.card->>'texto',
       coalesce(k.card->'opcoes', '[]'::jsonb),
       coalesce(k.card->'config', '{}'::jsonb),
       nullif(k.card->>'proximo', '')::uuid,
       nullif(k.card->>'fallback', '')::uuid,
       nullif(k.card->>'sem_resposta', '')::uuid,
       coalesce((k.card->>'lembrete_ativo')::boolean, false),
       k.card->>'lembrete_texto',
       p.ordem, p.x, p.y
from public.po_wa_fluxo_versoes v
cross join lateral jsonb_each(v.grafo->'cards') as k(chave, card)
join (values
    ('c1000000-0000-4000-8000-000000000001', 1, 0, 200),
    ('c1000000-0000-4000-8000-000000000002', 2, 320, 0),
    ('c1000000-0000-4000-8000-000000000004', 3, 320, 400),
    ('c1000000-0000-4000-8000-000000000005', 4, 640, 0),
    ('c1000000-0000-4000-8000-000000000006', 5, 960, 0),
    ('c1000000-0000-4000-8000-000000000007', 6, 1280, 0),
    ('c1000000-0000-4000-8000-000000000008', 7, 1600, -120),
    ('c1000000-0000-4000-8000-000000000012', 8, 1920, -120),
    ('c1000000-0000-4000-8000-000000000013', 9, 1600, 160),
    ('c1000000-0000-4000-8000-000000000014', 10, 1920, 160),
    ('c1000000-0000-4000-8000-000000000015', 11, 2240, 160),
    ('c1000000-0000-4000-8000-000000000016', 12, 640, 400),
    ('c1000000-0000-4000-8000-000000000017', 13, 640, 600),
    ('c1000000-0000-4000-8000-000000000018', 14, 1280, -240),
    ('c1000000-0000-4000-8000-000000000020', 15, 1920, 360),
    ('c2000000-0000-4000-8000-000000000001', 1, 0, 0),
    ('c2000000-0000-4000-8000-000000000002', 2, 320, 0)
) as p(id, ordem, x, y) on p.id::uuid = (k.card->>'id')::uuid
where v.id in ('b1000000-0000-4000-8000-000000000003', 'b1000000-0000-4000-8000-000000000004')
on conflict (id) do update set
  tipo           = excluded.tipo,
  titulo         = excluded.titulo,
  texto          = excluded.texto,
  opcoes         = excluded.opcoes,
  config         = excluded.config,
  proximo        = excluded.proximo,
  fallback       = excluded.fallback,
  sem_resposta   = excluded.sem_resposta,
  lembrete_ativo = excluded.lembrete_ativo,
  lembrete_texto = excluded.lembrete_texto,
  ordem          = excluded.ordem,
  x              = excluded.x,
  y              = excluded.y;

-- 4. Cada fluxo passa a servir a sua versao 2. RODADA UNICA (ver o cabecalho).
-- O `exists` e a guarda que importa: se o insert do passo 1 nao gravou (a cliente
-- ja tinha publicado uma versao 2 pelo painel, e o unique (fluxo_id, versao)
-- recusou), apontar para um id que nao existe deixaria o motor sem retrato para
-- ler, e o fluxo pararia calado.
update public.po_wa_fluxos set versao_atual = 'b1000000-0000-4000-8000-000000000003'
 where id = 'a1000000-0000-4000-8000-000000000001'
   and exists (select 1 from public.po_wa_fluxo_versoes v where v.id = 'b1000000-0000-4000-8000-000000000003');
update public.po_wa_fluxos set versao_atual = 'b1000000-0000-4000-8000-000000000004'
 where id = 'a1000000-0000-4000-8000-000000000002'
   and exists (select 1 from public.po_wa_fluxo_versoes v where v.id = 'b1000000-0000-4000-8000-000000000004');
