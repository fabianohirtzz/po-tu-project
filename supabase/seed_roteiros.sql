-- ============================================================
-- Seed dos 5 roteiros atuais em po_roteiros (idempotente por slug).
-- Campos-núcleo (slider/cards/capa/vídeo/valores). O conteúdo dia-a-dia,
-- inclui/não inclui e galeria completos são preenchidos pelo painel/importador
-- ou na etapa do site dinâmico.
-- ============================================================
insert into public.po_roteiros
  (slug, titulo, subtitulo, descricao_curta, dias, data_label, local_label, badge, capa_url, video_id, video_list, valores, ordem, ativo)
values
  ('mercados-de-natal', 'Mercados de Natal',
   'Com cruzeiro pelo Rio Reno e o Concerto de Natal de André Rieu, por Suíça, França, Alemanha e Holanda.',
   'A exclusiva oportunidade de viver o encanto do Natal em uma jornada única, visitando fascinantes Mercados de Natal que atraem milhares de pessoas do mundo inteiro.',
   11, '10 a 21/12/2025', 'Suíça, França, Alemanha e Holanda', '11 dias',
   'assets/images/roteiro-mercados-natal.jpg', 'K6l4OFPdqkw', 'RDK6l4OFPdqkw',
   '[{"tag":"Quarto duplo + aéreo","de":"a partir de","valor":"€ 6.635","extra":"valor por pessoa em quarto duplo, com aéreo"}]'::jsonb,
   1, true),

  ('tesouros-asiaticos', 'Tesouros Asiáticos',
   'Vietnã, Tailândia e Doha em uma só jornada, entre templos, baías e culturas milenares.',
   'Uma jornada entre culturas milenares, paisagens exóticas e contrastes fascinantes: Doha, Vietnã e Tailândia em um só roteiro inesquecível.',
   21, '03 a 23/03/2026', 'Vietnã, Tailândia e Doha', '21 dias',
   'assets/images/roteiro-tesouros-asiaticos.jpg', 'S4Z-125K3_0', null,
   '[{"tag":"Quarto duplo + aéreo","de":"a partir de","valor":"R$ 42.311,48","extra":"valor por pessoa em quarto duplo"}]'::jsonb,
   2, true),

  ('floracao-das-cerejeiras', 'Floração das Cerejeiras',
   'Japão e Doha na primavera oriental, entre cerejeiras em flor e o charme árabe.',
   'Uma viagem que celebra a floração das cerejeiras no Japão e todo o encanto da primavera oriental, e continua em Doha, onde a estação ganha tons dourados e o charme da modernidade árabe.',
   16, '11 a 27/04/2026', 'Japão e Doha', '16 dias',
   'assets/images/roteiro-cerejeiras.jpg', '6gQV0HJALEE', null,
   '[{"tag":"Quarto duplo + aéreo","de":"a partir de","valor":"R$ 57.891,52","extra":"valor por pessoa em quarto duplo"}]'::jsonb,
   3, true),

  ('grecia-terra-mar', 'Grécia Terra e Mar',
   'Meteora, Tessalônica e as Ilhas Gregas a bordo do Celestyal Journey.',
   'Embarque em uma jornada pelos monumentos de Meteora, a vibrante Tessalônica e as deslumbrantes Ilhas Gregas. A bordo do Celestyal Journey, explore Santorini, Mykonos, Creta e muito mais.',
   16, '02 a 18/05/2026', 'Grécia com Cruzeiro', '16 dias',
   'assets/images/roteiro-grecia.jpg', '8Z2kpec0TSE', null,
   '[{"tag":"Quarto duplo","de":"a partir de","valor":"€ 6.115","extra":"+ € 535 em taxas · entrada 30% + 10x"},{"tag":"Individual","de":"a partir de","valor":"€ 7.635","extra":"suplemento individual"}]'::jsonb,
   4, true),

  ('encantos-do-mediterraneo', 'Encantos do Mediterrâneo',
   'Da mítica Cartago a Valletta (UNESCO): Tunísia e Malta em uma viagem.',
   'Dois mundos, uma viagem: da mítica Cartago às casinhas azuis de Sidi Bou Said, do anfiteatro de El Jem ao oásis de Tozeur, até Valletta (UNESCO), a charmosa Gozo e Marsaxlokk.',
   14, '04 a 17/06/2026', 'Tunísia e Malta', '14 dias',
   'assets/images/roteiro-mediterraneo.jpg', 'b5SY6WY498I', null,
   '[{"tag":"Quarto duplo + aéreo","de":"a partir de","valor":"R$ 34.145,00","extra":"valor por pessoa em quarto duplo"}]'::jsonb,
   5, true)

on conflict (slug) do update set
  titulo = excluded.titulo,
  subtitulo = excluded.subtitulo,
  descricao_curta = excluded.descricao_curta,
  dias = excluded.dias,
  data_label = excluded.data_label,
  local_label = excluded.local_label,
  badge = excluded.badge,
  capa_url = excluded.capa_url,
  video_id = excluded.video_id,
  video_list = excluded.video_list,
  valores = excluded.valores,
  ordem = excluded.ordem;
