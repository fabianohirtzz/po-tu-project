<?php
require __DIR__ . '/../lib/wa-roteiro.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

// Catalogo falso no formato que po_fetch_roteiros() devolve.
$cat = [
    ['slug' => 'turquia',                    'titulo' => 'Turquia com Antalia'],
    ['slug' => 'chile-e-deserto-do-atacama', 'titulo' => 'Chile, Santiago e Deserto do Atacama'],
    ['slug' => 'escandinavia',               'titulo' => 'O melhor da Escandinavia'],
    ['slug' => 'caminhos-da-india',          'titulo' => 'Caminhos da India'],
];

// --- o nome do destino no meio de uma frase
ok(wa_match_roteiros('oi queria saber da viagem pra Turquia', $cat) === ['turquia'], 'destino no meio da frase');
ok(wa_match_roteiros('bom dia, me fala do atacama',           $cat) === ['chile-e-deserto-do-atacama'], 'palavra do titulo composto');

// --- acento e caixa nao podem atrapalhar: o cliente digita como quiser
ok(wa_match_roteiros('ESCANDINÁVIA',   $cat) === ['escandinavia'], 'caixa alta e acento');
ok(wa_match_roteiros('india',          $cat) === ['caminhos-da-india'], 'sem acento casa com titulo acentuado');

// --- apelidos que o cliente usa e nao estao no titulo
ok(wa_match_roteiros('a viagem das cerejeiras', array_merge($cat, [['slug'=>'floracao-das-cerejeiras','titulo'=>'Floracao das Cerejeiras']])) === ['floracao-das-cerejeiras'], 'apelido cerejeira');

// --- nada reconhecivel devolve vazio, e o motor cai no menu
ok(wa_match_roteiros('oi boa tarde',        $cat) === [], 'saudacao sem destino');
ok(wa_match_roteiros('quanto custa?',       $cat) === [], 'pergunta sem destino');
ok(wa_match_roteiros('',                    $cat) === [], 'vazio');

// --- ambiguo devolve os dois, e o motor tambem cai no menu
$dois = wa_match_roteiros('quero saber da turquia e da india', $cat);
ok(count($dois) === 2, 'dois destinos citados devolvem dois slugs');

// --- palavra curta nao pode casar por pedaco: "ar" nao e "Antalia"
ok(wa_match_roteiros('ar', $cat) === [], 'fragmento curto nao casa');

// --- bug real de revisao: substring casa palavra comum do portugues com
// nome de destino. Palavra de 4+ letras tambem nao pode casar por pedaco.
$catAsia = array_merge($cat, [['slug' => 'tesouros-asiaticos', 'titulo' => 'Tesouros Asiaticos']]);
ok(wa_match_roteiros('preciso de uma cama de casal no hotel', $cat)     === [], 'cama nao e substring valida de atacama');
ok(wa_match_roteiros('qual o caminho para chegar ai',        $cat)     === [], 'caminho nao e substring valida de caminhos');
ok(wa_match_roteiros('tenho um parente que mora na asia',    $catAsia) === [], 'asia nao e substring valida de asiaticos');

// --- a correcao acima nao pode quebrar o casamento legitimo pelo nome
// inteiro do roteiro (o token "caminhos" virou palavra vazia, mas "india"
// continua identificando o destino sozinho)
ok(wa_match_roteiros('quero saber dos caminhos da india', $cat) === ['caminhos-da-india'], 'caminhos da india ainda casa pelo nome inteiro');

// --- marcador do link do site tem prioridade e nunca vaza para o cliente
ok(wa_slug_do_marcador('Quero saber do roteiro [r:turquia]') === 'turquia', 'le o marcador');
ok(wa_slug_do_marcador('Quero saber do roteiro')             === null,      'sem marcador devolve null');
ok(wa_slug_do_marcador('[r:nao_existe_slug_com_underline]')  === null,      'marcador invalido e ignorado');

echo "test-wa-roteiro OK\n";
