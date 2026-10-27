-- ============================================================
-- Pereira Oliveira: os dois fluxos de conversa de verdade, publicados na
-- versao 1 e DESLIGADOS.
-- Rodar no Supabase → SQL Editor, DEPOIS de 2026-10-27-fluxo-conversa.sql.
-- Idempotente: rodar de novo nao duplica nada, nao sobrescreve card, fluxo nem
-- versao que ja existam e nao pisa numa publicacao posterior (cada insert e
-- on conflict do nothing, e versao_atual so e preenchida quando ainda esta
-- vazia). Limite conhecido: um card seed que a cliente APAGAR volta numa
-- segunda rodada, porque a unica guarda e o id. Rodar uma vez so.
--
-- Dois fluxos, ids fixos (uuid literal, nao gen_random_uuid()) para o seed ser
-- idempotente e para o card "Lista de espera" poder apontar para o segundo:
--   1. "Boas-vindas e triagem"  porta mensagem_nova
--   2. "Lista de espera"        porta chamado (so um card ir_para_fluxo o chama)
--
-- PUBLICADOS: para o motor e o simulador terem um grafo que conseguem ler.
-- DESLIGADOS (ativo = false): ligar troca o atendimento de todo contato novo,
-- e isso e clique da cliente depois de revisar os textos pelo painel.
-- Antes de ligar a triagem, ligar a Lista de espera (o salto dela exige
-- destino ativo e publicado).
--
-- A fonte da verdade e o retrato jsonb da versao 1 (os dois blocos jsonb abaixo). Os
-- cards do rascunho (po_wa_cards) sao DERIVADOS dele, linha a linha, para os
-- dois nunca divergirem. O teste tests/test-wa-fluxo-semeado.php le este
-- arquivo como texto e passa o mesmo JSON pelo validador e pelo roteador.
--
-- Os textos vem dos sete de hoje em po_wa_textos (menu, envio_pdf, perguntas,
-- qualificado, sem_data, lembrete, lembrete_menu), para nada que a cliente
-- escreveu se perder; po_wa_textos NAO e apagada. Os demais sao novos.
-- Spec: docs/superpowers/specs/2026-10-27-fluxo-conversa-painel-design.md, 6.10.
-- ============================================================

-- 1. Os fluxos. ativo nasce false; versao_atual e preenchida no passo 4.
insert into public.po_wa_fluxos (id, nome, gatilho, gatilho_valor, ativo, card_inicial) values
  ('a1000000-0000-4000-8000-000000000001', 'Boas-vindas e triagem', 'mensagem_nova', null, false, 'c1000000-0000-4000-8000-000000000001'),
  ('a1000000-0000-4000-8000-000000000002', 'Lista de espera', 'chamado', null, false, 'c2000000-0000-4000-8000-000000000001')
on conflict (id) do nothing;

-- 2. A versao 1 de cada um: o retrato imutavel que o motor le.
-- Fluxo 1: Boas-vindas e triagem
insert into public.po_wa_fluxo_versoes (id, fluxo_id, versao, grafo) values ('b1000000-0000-4000-8000-000000000001', 'a1000000-0000-4000-8000-000000000001', 1, $grafo$
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
                    "rotulo": "Roteiro específico",
                    "destino": "c1000000-0000-4000-8000-000000000002"
                },
                {
                    "id": "b_programacao",
                    "rotulo": "Ver a programação",
                    "destino": "c1000000-0000-4000-8000-000000000003"
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
            "lembrete_texto": "Oi {nome}, tudo bem? Passando para saber se podemos ajudar com alguma viagem. Se preferir, é só nos responder por aqui."
        },
        "c1000000-0000-4000-8000-000000000002": {
            "id": "c1000000-0000-4000-8000-000000000002",
            "tipo": "lista",
            "titulo": "Qual roteiro?",
            "texto": "Sobre qual viagem você quer saber? Escolha uma das opções e nós enviamos o roteiro completo.",
            "opcoes": [],
            "config": {
                "fonte": "roteiros",
                "botao": "Ver roteiros"
            },
            "proximo": "c1000000-0000-4000-8000-000000000005",
            "fallback": "c1000000-0000-4000-8000-000000000016",
            "sem_resposta": "c1000000-0000-4000-8000-000000000017",
            "lembrete_ativo": true,
            "lembrete_texto": "Oi {nome}, tudo bem? Quer que mandemos o roteiro completo de alguma das nossas próximas viagens em grupo?"
        },
        "c1000000-0000-4000-8000-000000000003": {
            "id": "c1000000-0000-4000-8000-000000000003",
            "tipo": "lista",
            "titulo": "A programação",
            "texto": "Temos várias viagens em grupo com datas marcadas. Escolha a que mais chamar a sua atenção e nós enviamos o roteiro completo.",
            "opcoes": [],
            "config": {
                "fonte": "roteiros",
                "botao": "Ver programação"
            },
            "proximo": "c1000000-0000-4000-8000-000000000005",
            "fallback": "c1000000-0000-4000-8000-000000000016",
            "sem_resposta": "c1000000-0000-4000-8000-000000000017",
            "lembrete_ativo": true,
            "lembrete_texto": "Oi {nome}, tudo bem? Quer que mandemos o roteiro completo de alguma das nossas próximas viagens em grupo?"
        },
        "c1000000-0000-4000-8000-000000000004": {
            "id": "c1000000-0000-4000-8000-000000000004",
            "tipo": "humano",
            "titulo": "Atendimento humano",
            "texto": "Combinado, {nome}. Já avisamos a Laís, que fala com você pessoalmente, por aqui mesmo, em breve.",
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
            "lembrete_texto": "Oi {nome}, tudo bem? Só passando para saber se você chegou a ver o roteiro que enviamos."
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
            "proximo": "c1000000-0000-4000-8000-000000000009",
            "fallback": null,
            "sem_resposta": null,
            "lembrete_ativo": false,
            "lembrete_texto": null
        },
        "c1000000-0000-4000-8000-000000000009": {
            "id": "c1000000-0000-4000-8000-000000000009",
            "tipo": "botoes",
            "titulo": "Já viajou em grupo?",
            "texto": "Para adiantarmos o seu atendimento, mais uma pergunta: você já viajou em grupo alguma vez?",
            "opcoes": [
                {
                    "id": "g_ja",
                    "rotulo": "Já viajei",
                    "destino": "c1000000-0000-4000-8000-000000000010"
                },
                {
                    "id": "g_nunca",
                    "rotulo": "Primeira vez",
                    "destino": "c1000000-0000-4000-8000-000000000011"
                }
            ],
            "config": {},
            "proximo": null,
            "fallback": "c1000000-0000-4000-8000-000000000019",
            "sem_resposta": "c1000000-0000-4000-8000-000000000017",
            "lembrete_ativo": true,
            "lembrete_texto": "Oi {nome}, tudo bem? Falta só uma pergunta para passarmos o seu atendimento para a Laís: você já viajou em grupo alguma vez?"
        },
        "c1000000-0000-4000-8000-000000000010": {
            "id": "c1000000-0000-4000-8000-000000000010",
            "tipo": "marca",
            "titulo": "Marca: já viajou em grupo",
            "texto": null,
            "opcoes": [],
            "config": {
                "qualif_grupo": true
            },
            "proximo": "c1000000-0000-4000-8000-000000000012",
            "fallback": null,
            "sem_resposta": null,
            "lembrete_ativo": false,
            "lembrete_texto": null
        },
        "c1000000-0000-4000-8000-000000000011": {
            "id": "c1000000-0000-4000-8000-000000000011",
            "tipo": "marca",
            "titulo": "Marca: primeira viagem em grupo",
            "texto": null,
            "opcoes": [],
            "config": {
                "qualif_grupo": false
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
            "texto": "Perfeito, {nome}. Já passamos o seu contato para a Laís, que fala com você pessoalmente em breve.",
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
            "titulo": "A data não serve",
            "texto": "Entendo, {nome}, essa data não funciona para você. Se o seu interesse é só por este destino, deixamos o seu contato registrado e avisamos quando abrirmos novas datas. Se preferir, podemos mostrar outras viagens.",
            "opcoes": [
                {
                    "id": "n_espera",
                    "rotulo": "Só esse destino",
                    "destino": "c1000000-0000-4000-8000-000000000015"
                },
                {
                    "id": "n_outras",
                    "rotulo": "Ver outras datas",
                    "destino": "c1000000-0000-4000-8000-000000000003"
                }
            ],
            "config": {},
            "proximo": null,
            "fallback": "c1000000-0000-4000-8000-000000000020",
            "sem_resposta": "c1000000-0000-4000-8000-000000000017",
            "lembrete_ativo": true,
            "lembrete_texto": "Oi {nome}, tudo bem? Se quiser que avisemos quando abrirmos novas datas deste destino, é só tocar em uma das opções da mensagem anterior."
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
                    "rotulo": "Roteiro específico",
                    "destino": "c1000000-0000-4000-8000-000000000002"
                },
                {
                    "id": "b_programacao",
                    "rotulo": "Ver a programação",
                    "destino": "c1000000-0000-4000-8000-000000000003"
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
            "lembrete_texto": "Oi {nome}, tudo bem? Passando para saber se podemos ajudar com alguma viagem. Se preferir, é só nos responder por aqui."
        },
        "c1000000-0000-4000-8000-000000000017": {
            "id": "c1000000-0000-4000-8000-000000000017",
            "tipo": "fim",
            "titulo": "Despedida de quem não responde",
            "texto": "Tudo bem, {nome}, não vamos insistir. Quando quiser conversar sobre uma viagem, é só escrever por aqui. Estamos à disposição.",
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
            "lembrete_texto": "Oi {nome}, tudo bem? Só passando para saber se você chegou a ver o roteiro que enviamos."
        },
        "c1000000-0000-4000-8000-000000000019": {
            "id": "c1000000-0000-4000-8000-000000000019",
            "tipo": "botoes",
            "titulo": "Repete: já viajou em grupo?",
            "texto": "Desculpe, não conseguimos entender. Vamos repetir a pergunta: você já viajou em grupo alguma vez? Toque em um dos botões abaixo.",
            "opcoes": [
                {
                    "id": "g_ja",
                    "rotulo": "Já viajei",
                    "destino": "c1000000-0000-4000-8000-000000000010"
                },
                {
                    "id": "g_nunca",
                    "rotulo": "Primeira vez",
                    "destino": "c1000000-0000-4000-8000-000000000011"
                }
            ],
            "config": {},
            "proximo": null,
            "fallback": null,
            "sem_resposta": "c1000000-0000-4000-8000-000000000017",
            "lembrete_ativo": true,
            "lembrete_texto": "Oi {nome}, tudo bem? Falta só uma pergunta para passarmos o seu atendimento para a Laís: você já viajou em grupo alguma vez?"
        },
        "c1000000-0000-4000-8000-000000000020": {
            "id": "c1000000-0000-4000-8000-000000000020",
            "tipo": "botoes",
            "titulo": "Repete: a data não serve",
            "texto": "Desculpe, não conseguimos entender. Vamos repetir: se o seu interesse é só por este destino, deixamos o seu contato registrado e avisamos quando abrirmos novas datas. Ou podemos mostrar outras viagens. Toque em um dos botões abaixo.",
            "opcoes": [
                {
                    "id": "n_espera",
                    "rotulo": "Só esse destino",
                    "destino": "c1000000-0000-4000-8000-000000000015"
                },
                {
                    "id": "n_outras",
                    "rotulo": "Ver outras datas",
                    "destino": "c1000000-0000-4000-8000-000000000003"
                }
            ],
            "config": {},
            "proximo": null,
            "fallback": null,
            "sem_resposta": "c1000000-0000-4000-8000-000000000017",
            "lembrete_ativo": true,
            "lembrete_texto": "Oi {nome}, tudo bem? Se quiser que avisemos quando abrirmos novas datas deste destino, é só tocar em uma das opções da mensagem anterior."
        }
    }
}
$grafo$::jsonb)
on conflict do nothing;

-- Fluxo 2: Lista de espera
insert into public.po_wa_fluxo_versoes (id, fluxo_id, versao, grafo) values ('b1000000-0000-4000-8000-000000000002', 'a1000000-0000-4000-8000-000000000002', 1, $grafo$
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
            "texto": "Combinado, {nome}. Deixamos o seu contato registrado e avisamos você assim que abrirmos novas datas deste destino.",
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

-- 3. Os cards do rascunho, derivados do retrato. (ordem, x, y) e so o desenho
-- na tela; o retrato nao o guarda.
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
    ('c1000000-0000-4000-8000-000000000003', 3, 320, 200),
    ('c1000000-0000-4000-8000-000000000004', 4, 320, 400),
    ('c1000000-0000-4000-8000-000000000005', 5, 640, 0),
    ('c1000000-0000-4000-8000-000000000006', 6, 960, 0),
    ('c1000000-0000-4000-8000-000000000007', 7, 1280, 0),
    ('c1000000-0000-4000-8000-000000000008', 8, 1600, -120),
    ('c1000000-0000-4000-8000-000000000009', 9, 1920, -120),
    ('c1000000-0000-4000-8000-000000000010', 10, 2240, -200),
    ('c1000000-0000-4000-8000-000000000011', 11, 2240, -40),
    ('c1000000-0000-4000-8000-000000000012', 12, 2560, -120),
    ('c1000000-0000-4000-8000-000000000013', 13, 1600, 160),
    ('c1000000-0000-4000-8000-000000000014', 14, 1920, 160),
    ('c1000000-0000-4000-8000-000000000015', 15, 2240, 160),
    ('c1000000-0000-4000-8000-000000000016', 16, 640, 400),
    ('c1000000-0000-4000-8000-000000000017', 17, 640, 600),
    ('c1000000-0000-4000-8000-000000000018', 18, 1280, -240),
    ('c1000000-0000-4000-8000-000000000019', 19, 1920, -300),
    ('c1000000-0000-4000-8000-000000000020', 20, 1920, 360),
    ('c2000000-0000-4000-8000-000000000001', 1, 0, 0),
    ('c2000000-0000-4000-8000-000000000002', 2, 320, 0)
) as p(id, ordem, x, y) on p.id::uuid = (k.card->>'id')::uuid
where v.id in ('b1000000-0000-4000-8000-000000000001', 'b1000000-0000-4000-8000-000000000002')
on conflict (id) do nothing;

-- 4. Aponta cada fluxo para a sua versao 1, so se ainda nao aponta para nenhuma
-- (rodar de novo nao pisa numa publicacao posterior da cliente).
update public.po_wa_fluxos set versao_atual = 'b1000000-0000-4000-8000-000000000001' where id = 'a1000000-0000-4000-8000-000000000001' and versao_atual is null;
update public.po_wa_fluxos set versao_atual = 'b1000000-0000-4000-8000-000000000002' where id = 'a1000000-0000-4000-8000-000000000002' and versao_atual is null;
