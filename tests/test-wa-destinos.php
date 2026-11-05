<?php
/* lib/wa-destinos.php: reconhecer um destino da casa pela frase do cliente.
   Tarefa 2 do plano 2026-11-05-robo-entende-a-frase.

   Isto existe para o robo responder "ainda nao temos data para a Italia" em
   vez de nao entender. Destino nao e roteiro: roteiro esta a venda, destino e
   so lugar que a casa ja vendeu alguma vez. Quem decide a ordem (roteiro
   primeiro, destino depois) e o grafo, nao este arquivo. */
require __DIR__ . '/../lib/wa-destinos.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

/* Recorte do catalogo real de po_destinos, no formato que o select devolve.
   As duas entradas com "do Sul" estao aqui de proposito: ver o bloco da
   frase. */
$D = [
    ['nome' => 'Itália',        'palavras' => 'italia, roma, veneza, florenca, toscana'],
    ['nome' => 'Grécia',        'palavras' => 'grecia, atenas, santorini, mykonos'],
    ['nome' => 'Peru',          'palavras' => 'peru, machu picchu, machupicchu, cusco, lima'],
    ['nome' => 'Coreia do Sul', 'palavras' => 'coreia, seul'],
    ['nome' => 'África do Sul', 'palavras' => 'africa do sul, africa, cidade do cabo, joanesburgo'],
    ['nome' => 'Lapônia',       'palavras' => 'laponia, aurora boreal, aurora, finlandia'],
    ['nome' => 'Tesouros Asiáticos', 'palavras' => 'tesouros asiaticos'],
];

/* ---------- wa_palavras_lista: o parser das duas colunas ----------
   `po_destinos.palavras` e `po_roteiros.palavras_chave` tem a MESMA forma e
   uma leitura so. Duas leituras divergem e os dois testes ficam verdes. */
ok(wa_palavras_lista('italia, roma, veneza') === ['italia','roma','veneza'], 'quebra na virgula e apara');
ok(wa_palavras_lista('italia, roma , , ita') === ['italia','roma'], 'descarta vazia e palavra de menos de 4 caracteres');
ok(wa_palavras_lista('') === [], 'coluna vazia e lista vazia');
ok(wa_palavras_lista('ITÁLIA, Roma') === ['italia','roma'], 'normaliza caixa e acento: o cliente digita como quiser');
ok(wa_palavras_lista('roma, roma, ROMA') === ['roma'], 'deduplica');
ok(wa_palavras_lista('machu picchu') === ['machu picchu'], 'palavra composta sobrevive inteira ao parser');
ok(wa_palavras_lista(null) === [], 'null nao estoura');

/* ---------- o caso simples ---------- */
ok(wa_destino_unico('quero saber da italia', $D) === 'Itália', 'uma palavra casa o destino');
ok(wa_destino_unico('queria roma', $D) === 'Itália', 'sinonimo casa e devolve o NOME, nao a palavra digitada');
ok(wa_destino_unico('ITÁLIA!!!', $D) === 'Itália', 'caixa, acento e pontuacao nao atrapalham');
ok(wa_destino_unico('bom dia', $D) === null, 'frase sem destino');
ok(wa_destino_unico('', $D) === null, 'frase vazia');
ok(wa_destino_unico('quero uma viagem', $D) === null, 'palavra generica de viagem nao casa destino nenhum');

/* ---------- Review Focus 1: ambiguidade devolve null ----------
   A funcao devolve TODOS os que casaram; a regra de "exatamente um" e do
   chamador. Duas respostas possiveis significa que o robo nao sabe, e
   perguntar e melhor que mandar o PDF errado. */
ok(count(wa_match_destinos('quero italia e grecia', $D)) === 2, 'duas citacoes devolvem duas');
ok(wa_destino_unico('quero italia e grecia', $D) === null, 'e o unico devolve null');
ok(wa_destino_unico('roma e atenas', $D) === null, 'ambiguo tambem por sinonimo');

/* ---------- PALAVRA COMPOSTA E FRASE, NAO DUAS PALAVRAS ----------
   Este bloco e o motivo de o casamento de destino nao poder reaproveitar
   tal e qual o tokenizador do roteiro.

   "africa do sul" quebrada em tokens daria `africa`, `do` e `sul`. Ai a
   frase "quero a coreia do sul" casaria `sul` e devolveria DOIS destinos
   (Coreia do Sul e Africa do Sul), o unico viraria null, e o robo
   responderia o menu para quem disse exatamente o nome de um destino da
   casa. Com frase, `africa do sul` so casa se as tres palavras estiverem
   juntas e na ordem. */
ok(wa_destino_unico('quero a coreia do sul', $D) === 'Coreia do Sul',
    'coreia do sul NAO colide com africa do sul: palavra composta e frase');
ok(wa_destino_unico('quero a africa do sul', $D) === 'África do Sul', 'e a frase casa quando ela esta inteira');
ok(wa_destino_unico('fui pro sul do brasil', $D) === null, '`sul` sozinho nao casa nada');
ok(wa_destino_unico('MACHU PICCHU!!', $D) === 'Peru', 'frase com pontuacao no meio da mensagem');
ok(wa_destino_unico('quero ver a aurora boreal', $D) === 'Lapônia', 'frase e palavra do mesmo destino contam como UM');
ok(wa_destino_unico('cidade do cabo', $D) === 'África do Sul', 'frase de tres palavras');
ok(wa_destino_unico('picchu machu', $D) === null, 'frase fora de ordem nao casa: e frase, nao conjunto');
/* A frase precisa de BORDA DE PALAVRA nas duas pontas, e nao de simples
   "esta contido". Sem a borda, `cidade do cabo` casaria dentro de "cidade do
   Cabobranco" (Cabo Branco, em Joao Pessoa) e o robo responderia Africa do
   Sul para quem falou de uma praia no Brasil. A rodada de mutacao achou esta
   guarda sem nenhum teste em cima dela. */
ok(wa_destino_unico('quero ver a cidade do cabobranco', $D) === null,
    'frase nao casa dentro de palavra maior');
ok(wa_destino_unico('aurora boreale na italia', $D) === null,
    'ambiguidade conta igual se vem de frase ou de palavra: aurora (Laponia) e italia');
ok(wa_destino_unico('tesouros', $D) === null, 'pedaco da frase nao casa: senao "Tesouros da Grecia" viraria Tesouros Asiaticos');
ok(wa_destino_unico('tesouros asiaticos', $D) === 'Tesouros Asiáticos', 'a frase inteira casa');

/* ---------- a limitacao aceita, documentada de proposito ----------
   Negacao e tempo passado NAO sao entendidos. Resolver exige analise de
   linguagem que o desenho recusa; o custo e baixo (a Lais ve a conversa) e o
   risco do contrario e alto. O teste existe para a limitacao ser uma decisao
   registrada em vez de uma surpresa. */
ok(wa_destino_unico('nao quero a italia', $D) === 'Itália', 'negacao NAO e entendida: limitacao aceita');
ok(wa_destino_unico('fui pra italia ano passado', $D) === 'Itália', 'passado tambem nao');

/* ---------- destino inativo nao participa ----------
   Quem filtra por `ativo` e a consulta, mas a funcao nao pode casar um
   destino que o chamador nao mandou: isto trava o contrato. */
$comInativo = array_merge($D, [['nome' => 'Marte', 'palavras' => 'marte']]);
ok(wa_destino_unico('quero marte', $D) === null, 'destino que nao veio na lista nao casa');
ok(wa_destino_unico('quero marte', $comInativo) === 'Marte', 'e casa quando vem');

/* ---------- forma de entrada defensiva ---------- */
ok(wa_match_destinos('italia', []) === [], 'catalogo vazio');
ok(wa_match_destinos('italia', [['nome' => 'X', 'palavras' => '']]) === [], 'destino sem palavra nunca casa');
ok(wa_match_destinos('italia', [['palavras' => 'italia']]) === [], 'destino sem nome nao casa: o nome e o que o robo escreve de volta');

echo "OK test-wa-destinos\n";
