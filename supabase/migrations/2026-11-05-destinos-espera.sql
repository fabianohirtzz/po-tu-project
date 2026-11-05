-- Parte A do desenho de 04/11/2026: o robo entende a frase.
-- Spec: docs/superpowers/specs/2026-11-04-robo-entende-frase-e-roteiro-semanal-design.md
-- Plano: docs/superpowers/plans/2026-11-05-robo-entende-a-frase.md (Tarefa 1)
--
-- Tres coisas, TODAS ADITIVAS (nenhum drop, nenhum update de dado existente):
--
-- 1. po_destinos: a lista de destinos que a casa ja vendeu, para o robo
--    reconhecer "Italia" numa frase e responder "ainda nao temos data para a
--    Italia" em vez de nao entender. Em TABELA e nao em constante porque a
--    lista cresce todo ano e, em codigo, cada destino novo viraria deploy.
--
-- 2. po_roteiros.palavras_chave: sinonimos do roteiro, editaveis no painel.
--
--    MEDIDO EM 05/11/2026, E ISTO CORRIGE A SPEC: o desenho justificava esta
--    coluna dizendo que "Tesouros do Oriente" nao nomeia pais nenhum e que
--    por isso ninguem que escrevesse "coreia", "japao" ou "dubai" alcancaria
--    o roteiro. Esta errado: wa_tokens_do_roteiro tokeniza SLUG + TITULO, e o
--    slug e `coreia-do-sul-japao-dubai`, entao os tres JA casam hoje.
--    O que de fato nao casa e o nome de CIDADE: `seul`, `toquio`, `machu
--    picchu`, `taj mahal`. E para isso que a coluna serve, e e so isso que
--    ela semeia.
--
-- 3. po_wa_espera: quem pediu para ser avisado de um destino. Tabela propria,
--    nao array em po_leads.espera, porque filtrar por destino, saber QUANDO a
--    pessoa pediu e remover UMA entrada sao as tres coisas que jsonb faz mal.
--    Medido em 04/11: `espera` esta vazia nos 797 leads, entao trocar a forma
--    nao custa migracao de dado nenhuma.
--
-- SEM `drop policy if exists`: as tabelas sao NOVAS, entao a policy nao pode
-- pre-existir, e o portao do MCP recusa qualquer `drop` - medido em 05/11,
-- a migration foi recusada com ele. O que roda e identico ao que esta aqui.
--
-- RLS: as duas tabelas novas sao AUTENTICADAS, sem leitura anonima. O robo le
-- com service_role (lib/wa-db.php), que IGNORA a RLS, entao a RLS aqui governa
-- so o painel. E po_wa_espera guarda dado de lead: anonimo nao pode ler.

begin;

-- ---------------------------------------------------------------- 1. destinos
create table if not exists po_destinos (
  id        uuid primary key default gen_random_uuid(),
  nome      text not null,                 -- o que o robo escreve de volta: "Italia"
  palavras  text not null default '',      -- o que o cliente digita, separado por virgula
  ativo     boolean not null default true,
  ordem     int not null default 0,
  criado_at timestamptz not null default now()
);

create unique index if not exists po_destinos_nome_uniq on po_destinos (nome);

alter table po_destinos enable row level security;
create policy po_destinos_auth on po_destinos
  for all to authenticated using (true) with check (true);

-- Os 29 destinos. Vieram da pasta de reels do Drive (o material de divulgacao
-- de tudo que a agencia ja vendeu, 53 arquivos deduplicados) mais o que o
-- historico do site registra nos 301 do WordPress antigo.
--
-- As duas ambiguidades do Drive, resolvidas pelo cliente em 04/11/2026:
--   AFRICA 4K    -> Africa do Sul
--   INDIROMA003  -> India com uma parada especial em Roma, entao o nome entra
--                   em India E em Italia/Roma, porque a pessoa pode escrever
--                   qualquer um dos dois.
--
-- `palavras` com ESPACO e tratada como FRASE por quem casa, nao como duas
-- palavras: "africa do sul" quebrada em tokens daria `sul`, que casaria
-- "coreia do sul". Ver wa_match_destinos.
insert into po_destinos (nome, palavras, ordem) values
  ('Grécia',                 'grecia, atenas, santorini, mykonos',                 10),
  ('Peru',                   'peru, machu picchu, machupicchu, cusco, lima',       20),
  ('Vietnã',                 'vietna, vietnam, hanoi, halong',                     30),
  ('Itália',                 'italia, roma, veneza, florenca, toscana',            40),
  ('Turquia',                'turquia, istambul, capadocia, antalia',              50),
  ('Índia',                  'india, taj mahal, tajmahal, delhi, agra',            60),
  ('Chile',                  'chile, santiago, atacama',                           70),
  ('Japão',                  'japao, toquio, tokio, kyoto, quioto',                80),
  ('Coreia do Sul',          'coreia, seul',                                       90),
  ('Dubai',                  'dubai, emirados',                                   100),
  ('Doha',                   'doha, catar, qatar',                                110),
  ('China',                  'china, pequim, xangai, muralha',                    120),
  ('Egito',                  'egito, cairo, piramides, nilo',                     130),
  ('Marrocos',               'marrocos, marraquexe, casablanca',                  140),
  ('África do Sul',          'africa do sul, africa, cidade do cabo, joanesburgo', 150),
  ('Austrália',              'australia, sydney, melbourne',                      160),
  ('Malta',                  'malta, valletta',                                   170),
  ('Lapônia',                'laponia, aurora boreal, aurora, finlandia',         180),
  ('Capitais Imperiais',     'capitais imperiais, viena, praga, budapeste',       190),
  ('Tailândia',              'tailandia, bangkok, phuket',                        200),
  ('Escandinávia',           'escandinavia, noruega, suecia, dinamarca, fiordes', 210),
  ('Mercados de Natal',      'mercados de natal, mercado de natal, natal na europa', 220),
  ('Floração das Cerejeiras','cerejeiras, floracao, sakura',                      230),
  ('Mediterrâneo',           'mediterraneo, cruzeiro',                            240),
  ('Nepal',                  'nepal, katmandu',                                   250),
  ('Paris',                  'paris, franca',                                     260),
  ('Portugal',               'portugal, lisboa, porto',                           270),
  ('Espanha',                'espanha, madri, barcelona',                         280),
  ('Tesouros Asiáticos',     'tesouros asiaticos',                                290)
on conflict (nome) do nothing;

-- ------------------------------------------------- 2. sinonimos do roteiro
alter table po_roteiros add column if not exists palavras_chave text not null default '';

-- So nome de CIDADE e sinonimo que slug+titulo NAO cobrem (ver o comentario
-- do topo). Nada de `asia`: ha teste exigindo que `asia` nao case dentro de
-- `asiaticos`, e semear a palavra contradiria aquele teste.
update po_roteiros set palavras_chave = 'seul, toquio, tokio, quioto, kyoto, emirados'
  where slug = 'coreia-do-sul-japao-dubai' and palavras_chave = '';
update po_roteiros set palavras_chave = 'atenas, santorini, mykonos'
  where slug = 'tesouros-da-grecia' and palavras_chave = '';
update po_roteiros set palavras_chave = 'istambul, capadocia'
  where slug = 'turquia' and palavras_chave = '';
update po_roteiros set palavras_chave = 'santiago'
  where slug = 'chile-e-deserto-do-atacama' and palavras_chave = '';
update po_roteiros set palavras_chave = 'hanoi, halong'
  where slug = 'vietna-tailandia-paris' and palavras_chave = '';
-- Ocultos hoje, semeados para quando a edicao nova herdar a URL. O
-- `noruega` preserva a unica entrada que WA_APELIDOS tinha em codigo.
update po_roteiros set palavras_chave = 'noruega, suecia, dinamarca, fiordes, visby'
  where slug = 'escandinavia' and palavras_chave = '';
update po_roteiros set palavras_chave = 'taj mahal, tajmahal, delhi, agra'
  where slug = 'caminhos-da-india' and palavras_chave = '';

-- -------------------------------------------------------- 3. lista de espera
create table if not exists po_wa_espera (
  id           uuid primary key default gen_random_uuid(),
  lead_id      uuid not null references po_leads(id) on delete cascade,
  destino      text not null,
  roteiro_slug text,                  -- quando a pessoa esperava data nova de um roteiro
  criado_at    timestamptz not null default now(),
  removido_at  timestamptz            -- SOFT DELETE: ver abaixo
);

-- Remocao e SOFT pelas tres regras do dono: o registro com historico nunca e
-- sobrescrito, a fusao so soma e nunca zera, e nunca duplicar. Tirar alguem da
-- espera nao pode apagar que ela um dia pediu.
--
-- E o indice e PARCIAL: pedir a Italia duas vezes nao cria duas linhas nem
-- erra a contagem do publico da transmissao, mas pedir de novo DEPOIS de ter
-- sido removido pode.
create unique index if not exists po_wa_espera_ativa_uniq
  on po_wa_espera (lead_id, destino) where removido_at is null;

create index if not exists po_wa_espera_destino_idx
  on po_wa_espera (destino) where removido_at is null;

alter table po_wa_espera enable row level security;
create policy po_wa_espera_auth on po_wa_espera
  for all to authenticated using (true) with check (true);

-- ------------------------------------- 4. as 12 fichas de exemplo, SEM telefone
-- Decisao do dono em 05/11/2026. Elas existem para a Parte C ter o que filtrar
-- na aba e no seletor da transmissao.
--
-- SEM TELEFONE E SEM wa_id, e isto nao e detalhe: desde 26/10 o WA_PHONE_ID
-- esta preenchido, entao um celular ficticio plausivel pode alcancar um
-- estranho de verdade quando a conta puder disparar. Sem telefone, a
-- transmissao as conta no resumo como "sem telefone" e nao envia a ninguem -
-- que e o mesmo que ela ja faz com 753 das 797 fichas reais.
--
-- `revisado = true` de proposito: o default e false, e com ele as 12 cairiam
-- na fila da aba Revisao da cliente, que e para contato de agenda importada.
-- Nome com sobrenome abreviado, no mesmo porte LGPD das avaliacoes.
insert into po_leads (nome, status, revisado, cliente)
select v.nome, 'semresposta', true, false
from (values
  ('EXEMPLO Ana P.'), ('EXEMPLO Bruno M.'), ('EXEMPLO Carla S.'),
  ('EXEMPLO Daniel R.'), ('EXEMPLO Elisa T.'), ('EXEMPLO Fábio L.'),
  ('EXEMPLO Gisele A.'), ('EXEMPLO Heitor C.'), ('EXEMPLO Irene B.'),
  ('EXEMPLO João V.'), ('EXEMPLO Karina D.'), ('EXEMPLO Lucas F.')
) as v(nome)
where not exists (select 1 from po_leads l where l.nome = v.nome);

-- Espalhadas em 5 destinos: Italia 4, Egito 3, Japao 2, Portugal 2, Laponia 1.
insert into po_wa_espera (lead_id, destino)
select l.id, d.destino
from (values
  ('EXEMPLO Ana P.',     'Itália'),
  ('EXEMPLO Bruno M.',   'Itália'),
  ('EXEMPLO Carla S.',   'Itália'),
  ('EXEMPLO Daniel R.',  'Itália'),
  ('EXEMPLO Elisa T.',   'Egito'),
  ('EXEMPLO Fábio L.',   'Egito'),
  ('EXEMPLO Gisele A.',  'Egito'),
  ('EXEMPLO Heitor C.',  'Japão'),
  ('EXEMPLO Irene B.',   'Japão'),
  ('EXEMPLO João V.',    'Portugal'),
  ('EXEMPLO Karina D.',  'Portugal'),
  ('EXEMPLO Lucas F.',   'Lapônia')
) as d(nome, destino)
join po_leads l on l.nome = d.nome
where not exists (
  select 1 from po_wa_espera e
  where e.lead_id = l.id and e.destino = d.destino and e.removido_at is null
);

commit;
