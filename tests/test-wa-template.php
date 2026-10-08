<?php
/* tests/test-wa-template.php — criacao de modelo de mensagem na Meta.

   O que precisa estar travado aqui nao e o caminho feliz. E a contagem de
   variaveis: template aprovado com numero errado de parametros faz a Graph
   recusar TODO destinatario da campanha, e `falha` e terminal. Uma campanha
   assim queima a base inteira sem possibilidade de reenvio.

   Nenhum teste toca a rede: o transporte e injetado e registra as chamadas,
   para os casos de recusa poderem provar que NADA foi chamado. */

$RAIZ = dirname(__DIR__);
require_once $RAIZ . '/lib/wa-template.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

ini_set('error_log', sys_get_temp_dir() . '/po-test-wa-template.log');

/* Segredos de teste: nem a chave real nem a URL real desta maquina entram. */
$GLOBALS['WA_TOKEN']   = 'token-de-teste';
$GLOBALS['WA_WABA_ID'] = '555000111';

$CHAMADAS = [];
function tpl_dubla($resposta) {
    global $CHAMADAS;
    $CHAMADAS = [];
    wa_tpl_set_transport(function ($url, $payload, $headers) use ($resposta) {
        global $CHAMADAS;
        // Os headers carregam o Bearer: ficam FORA do registro.
        $CHAMADAS[] = ['url' => $url, 'payload' => $payload];
        return is_callable($resposta) ? $resposta($url, $payload) : $resposta;
    });
}
function tpl_ok($corpo) {
    return ['status' => 200, 'body' => json_encode($corpo)];
}

/* ============================================================
   NOME
============================================================ */
ok(wa_tpl_nome('Floração das Cerejeiras 2027') === 'floracao_das_cerejeiras_2027',
   'o nome perde acento, cai para minuscula e vira underline (deu: ' . wa_tpl_nome('Floração das Cerejeiras 2027') . ')');
ok(wa_tpl_nome('  Grécia: Terra & Mar!  ') === 'grecia_terra_mar',
   'pontuacao vira separador e nao sobra underline nas pontas (deu: ' . wa_tpl_nome('  Grécia: Terra & Mar!  ') . ')');
ok(wa_tpl_nome('') === '', 'titulo vazio devolve nome vazio');
ok(wa_tpl_nome('...') === '', 'titulo so com pontuacao devolve nome vazio');
ok(preg_match('/^[a-z0-9_]*$/', wa_tpl_nome('Ônibus ÀS 10h, Nº 3')) === 1,
   'o nome so tem caracteres que a Meta aceita (deu: ' . wa_tpl_nome('Ônibus ÀS 10h, Nº 3') . ')');
$longo = wa_tpl_nome(str_repeat('viagem ', 200));
ok(strlen($longo) <= WA_TPL_NOME_MAX, 'o nome respeita o limite (deu: ' . strlen($longo) . ')');
ok(substr($longo, -1) !== '_', 'e nao termina em underline depois de cortado');

/* ============================================================
   VARIAVEIS
============================================================ */
ok(wa_tpl_vars('Oi {{1}}, tudo bem?') === [1], 'acha a variavel');
ok(wa_tpl_vars('Oi {{1}}, o {{2}} saiu') === [1, 2], 'acha duas, na ordem');
ok(wa_tpl_vars('Oi {{ 1 }}') === [], 'chave com espaco nao conta como variavel');
ok(wa_tpl_vars('Oi {{nome}}') === [], 'chave com palavra nao conta como variavel');
ok(wa_tpl_vars('') === [], 'texto vazio nao tem variavel');

/* ============================================================
   PROBLEMAS
   Cada item e uma recusa da Meta evitada. A recusa dela chega generica,
   horas depois, por e-mail, sem dizer qual regra caiu.
============================================================ */
ok(wa_tpl_problemas('Oi {{1}}, saiu roteiro novo para Portugal.', 1) === [],
   'texto bom nao tem problema nenhum');

ok(wa_tpl_problemas('', 1) !== [], 'texto vazio e problema');
ok(count(wa_tpl_problemas('', 1)) === 1, 'texto vazio nao dispara uma enxurrada de avisos');

/* A regra que derruba a campanha inteira. */
$p = wa_tpl_problemas('Saiu roteiro novo para Portugal.', 1);
ok($p !== [], 'texto sem variavel nenhuma e recusado quando se espera uma');
$p = wa_tpl_problemas('Oi {{1}}, o {{2}} saiu.', 1);
ok($p !== [], 'texto com duas variaveis e recusado quando se espera uma');
ok(wa_tpl_problemas('Oi {{1}}, o {{2}} saiu.', 2) === [],
   'as mesmas duas variaveis passam quando se esperam duas');

ok(wa_tpl_problemas('Oi {{2}}, tudo bem?', 1) !== [],
   'variavel que nao comeca em 1 e recusada');
ok(wa_tpl_problemas('{{1}}, saiu roteiro novo.', 1) !== [],
   'texto nao pode COMECAR com variavel');
ok(wa_tpl_problemas('Saiu roteiro novo, {{1}}', 1) !== [],
   'texto nao pode TERMINAR com variavel');
ok(wa_tpl_problemas('Oi {{1}} {{2}}, tudo bem?', 2) !== [],
   'duas variaveis coladas sao recusadas');
ok(wa_tpl_problemas(' Oi {{1}}, tudo bem? ', 1) !== [],
   'espaco nas pontas e recusado');
ok(wa_tpl_problemas("Oi {{1}}, tudo bem?\n", 1) !== [],
   'quebra de linha no fim e recusada');
ok(wa_tpl_problemas('Oi {{ 1 }}, tudo bem? Abraco.', 0) !== [],
   'chave malformada e apontada');
ok(wa_tpl_problemas('Oi {{1}}, ' . str_repeat('a', WA_TPL_CORPO_MAX) . ' fim.', 1) !== [],
   'texto acima do limite e recusado');
/* O limite conta CARACTERES, nao bytes: com strlen um texto de 900 letras
   acentuadas passaria dos 1024 bytes e seria recusado a toa. */
ok(wa_tpl_problemas('Oi {{1}}, ' . str_repeat('ç', 900) . ' fim.', 1) === [],
   'o limite conta caracteres, nao bytes');

/* ============================================================
   COMPONENTES
============================================================ */
$c = wa_tpl_componentes('Oi {{1}}, tudo bem?', ['Maria']);
ok($c[0]['type'] === 'BODY', 'o componente e o corpo');
ok($c[0]['text'] === 'Oi {{1}}, tudo bem?', 'o texto vai inteiro');
/* body_text e lista DE LISTAS. Mandar lista simples faz a Meta recusar com
   erro de formato, que e exatamente o tipo de recusa cara de diagnosticar. */
ok($c[0]['example']['body_text'] === [['Maria']],
   'o exemplo vai como lista de listas (veio: ' . json_encode($c[0]['example'] ?? null) . ')');
$c = wa_tpl_componentes('Sem variavel aqui.', []);
ok(!isset($c[0]['example']), 'sem variavel nao vai exemplo nenhum');

/* ============================================================
   CRIACAO: AS RECUSAS, E A PROVA DE QUE A REDE NAO FOI CHAMADA
============================================================ */
tpl_dubla(tpl_ok(['id' => '1', 'status' => 'PENDING']));
$GLOBALS['WA_WABA_ID'] = '';
$r = wa_tpl_cria('Portugal 2027', 'MARKETING', 'Oi {{1}}, saiu roteiro novo.', ['Maria']);
ok($r['ok'] === false, 'sem conta do WhatsApp conectada, recusa');
ok($CHAMADAS === [], 'e nao chama a Meta');
$GLOBALS['WA_WABA_ID'] = '555000111';

tpl_dubla(tpl_ok(['id' => '1']));
$GLOBALS['WA_TOKEN'] = '';
$r = wa_tpl_cria('Portugal 2027', 'MARKETING', 'Oi {{1}}, saiu roteiro novo.', ['Maria']);
ok($r['ok'] === false, 'sem token, recusa');
ok($CHAMADAS === [], 'e nao chama a Meta');
$GLOBALS['WA_TOKEN'] = 'token-de-teste';

tpl_dubla(tpl_ok(['id' => '1']));
$r = wa_tpl_cria('Portugal 2027', 'PROMOCAO', 'Oi {{1}}, saiu roteiro novo.', ['Maria']);
ok($r['ok'] === false, 'categoria fora da lista da Meta, recusa');
ok($CHAMADAS === [], 'e nao chama a Meta');

tpl_dubla(tpl_ok(['id' => '1']));
$r = wa_tpl_cria('...', 'MARKETING', 'Oi {{1}}, saiu roteiro novo.', ['Maria']);
ok($r['ok'] === false, 'titulo que normaliza para vazio, recusa');
ok($CHAMADAS === [], 'e nao chama a Meta');

/* A recusa que vale dinheiro: um exemplo, duas variaveis. */
tpl_dubla(tpl_ok(['id' => '1']));
$r = wa_tpl_cria('Portugal 2027', 'MARKETING', 'Oi {{1}}, o {{2}} saiu.', ['Maria']);
ok($r['ok'] === false, 'numero de variaveis diferente do numero de exemplos, recusa');
ok(isset($r['problemas']) && $r['problemas'], 'e devolve a lista de problemas para a tela');
ok($CHAMADAS === [], 'e nao chama a Meta');

/* ============================================================
   CRIACAO: O CAMINHO FELIZ
============================================================ */
tpl_dubla(tpl_ok(['id' => '9988', 'status' => 'PENDING', 'category' => 'MARKETING']));
$r = wa_tpl_cria('Novidade Portugal 2027', 'marketing',
                 'Oi {{1}}, saiu roteiro novo para Portugal. Responda SAIR para não receber mais.',
                 ['Maria']);
ok($r['ok'] === true, 'o caminho feliz cria (erro: ' . ($r['erro'] ?? '') . ')');
ok($r['id'] === '9988', 'devolve o id do template');
ok($r['nome'] === 'novidade_portugal_2027', 'devolve o nome normalizado, que e o que o envio usa');
ok($r['status'] === 'PENDING', 'o template nasce pendente');
ok(count($CHAMADAS) === 1, 'uma chamada so');
ok(strpos($CHAMADAS[0]['url'], '/555000111/message_templates') !== false,
   'bate na conta certa (url: ' . $CHAMADAS[0]['url'] . ')');
$env = json_decode($CHAMADAS[0]['payload'], true);
ok($env['name'] === 'novidade_portugal_2027', 'o nome enviado e o normalizado');
ok($env['category'] === 'MARKETING', 'a categoria sobe em maiuscula');
ok($env['language'] === 'pt_BR', 'o idioma padrao e pt_BR');
ok($env['components'][0]['example']['body_text'] === [['Maria']], 'o exemplo vai junto');
/* UNESCAPED_UNICODE: com o escape padrao o acento viraria ã no corpo do
   template, e a cliente receberia a mensagem com a sequencia literal. */
ok(strpos($CHAMADAS[0]['payload'], 'não') !== false,
   'o acento vai como acento, nao como escape');

/* ============================================================
   CRIACAO: QUANDO A META RECUSA OU SOME
============================================================ */
tpl_dubla(['status' => 400, 'body' => json_encode(
    ['error' => ['message' => 'Generic', 'error_user_msg' => 'Já existe um modelo com esse nome.']])]);
$r = wa_tpl_cria('Portugal 2027', 'MARKETING', 'Oi {{1}}, saiu roteiro novo.', ['Maria']);
ok($r['ok'] === false, 'erro da Meta nao vira sucesso');
ok(strpos($r['erro'], 'Já existe') !== false,
   'a mensagem para o usuario e preferida a generica (veio: ' . $r['erro'] . ')');

tpl_dubla(['status' => 200, 'body' => '{"nao_tem_id":true}']);
$r = wa_tpl_cria('Portugal 2027', 'MARKETING', 'Oi {{1}}, saiu roteiro novo.', ['Maria']);
ok($r['ok'] === false, '200 sem id nao e sucesso');

tpl_dubla(null);
$r = wa_tpl_cria('Portugal 2027', 'MARKETING', 'Oi {{1}}, saiu roteiro novo.', ['Maria']);
ok($r['ok'] === false, 'rede fora nao vira sucesso');

/* ============================================================
   LISTAGEM
============================================================ */
tpl_dubla(tpl_ok(['data' => [['name' => 'novidade', 'status' => 'APPROVED']]]));
$l = wa_tpl_lista();
ok(is_array($l) && count($l) === 1, 'a listagem devolve os templates');
ok($CHAMADAS[0]['payload'] === null, 'listagem e GET, sem corpo');
ok(strpos($CHAMADAS[0]['url'], 'fields=') !== false, 'a listagem pede os campos');
ok(strpos($CHAMADAS[0]['url'], 'status') !== false,
   'e pede o status, que e a unica coisa que a tela precisa saber');

tpl_dubla(tpl_ok(['data' => []]));
ok(wa_tpl_lista() === [], 'conta sem template nenhum devolve lista vazia');

/* Erro tem que ser distinguivel de vazio: com os dois virando [], a tela
   diria "nenhum template aprovado" com a Meta fora do ar, e alguem criaria
   um template duplicado. Mesmo motivo do wa_db_select_estrito. */
tpl_dubla(['status' => 500, 'body' => 'erro']);
ok(wa_tpl_lista() === null, 'erro na listagem devolve null, nao lista vazia');
tpl_dubla(null);
ok(wa_tpl_lista() === null, 'rede fora na listagem devolve null');
tpl_dubla(tpl_ok(['sem_data' => 1]));
ok(wa_tpl_lista() === null, 'resposta sem data devolve null');

$GLOBALS['WA_WABA_ID'] = '';
tpl_dubla(tpl_ok(['data' => []]));
ok(wa_tpl_lista() === null, 'sem conta conectada a listagem devolve null');
ok($CHAMADAS === [], 'e nao chama a Meta');
$GLOBALS['WA_WABA_ID'] = '555000111';

/* ============================================================
   IDIOMA E APROVACAO, com a lista dada na mao (sem rede)

   Modelo e identificado por nome + IDIOMA. O nome certo com o idioma errado
   devolve "#132001 ... does not exist in the translation" e nao entrega -
   para um destinatario ou para a base inteira, do mesmo jeito.
============================================================ */
$LISTA = [
    ['name' => 'hello_world',   'status' => 'APPROVED', 'language' => 'en_US'],
    ['name' => 'po_novidade',   'status' => 'APPROVED', 'language' => 'pt_BR'],
    ['name' => 'po_rascunho',   'status' => 'PENDING',  'language' => 'pt_BR'],
    ['name' => 'po_recusado',   'status' => 'REJECTED', 'language' => 'pt_BR'],
];

ok(wa_tpl_idioma('hello_world', $LISTA) === 'en_US', 'acha o idioma do hello_world');
ok(wa_tpl_idioma('po_novidade', $LISTA) === 'pt_BR', 'e o do modelo em portugues');
ok(wa_tpl_idioma('nao_existe', $LISTA) === null, 'modelo ausente devolve null');
ok(wa_tpl_idioma('', $LISTA) === null, 'nome vazio devolve null');
ok(wa_tpl_idioma('hello_world', 'nao e lista') === null, 'lista invalida devolve null');

ok(wa_tpl_aprovado('po_novidade', $LISTA) === true, 'aprovado e aprovado');
ok(wa_tpl_aprovado('po_rascunho', $LISTA) === false, 'em analise NAO e aprovado');
ok(wa_tpl_aprovado('po_recusado', $LISTA) === false, 'recusado NAO e aprovado');
/* A lista VEIO e o modelo nao esta nela: ele nao existe, e isso e false -
   diferente de null, que e "nao deu para saber". Confundir os dois deixaria
   criar campanha com nome de modelo digitado errado, e todo envio falharia
   de forma terminal. */
ok(wa_tpl_aprovado('nome_digitado_errado', $LISTA) === false,
   'modelo que nao esta na lista nao conta como aprovado');
ok(wa_tpl_aprovado('po_novidade', 'nao e lista') === null, 'lista invalida devolve null');

/* Lista nao passada = a funcao vai buscar. Com a Meta fora, as duas devolvem
   null - "nao deu para saber", que NAO e "nao aprovado" nem "sem idioma".
   Quem chama tem que poder distinguir: o campanha.php responde 502 num caso
   e 400 no outro, e so um deles manda a cliente procurar erro de digitacao. */
tpl_dubla(null);
ok(wa_tpl_aprovado('po_novidade') === null, 'Meta fora devolve null, nao false');
ok(wa_tpl_idioma('po_novidade') === null, 'e o idioma tambem vem null');

/* ============================================================
   ASSERCAO DE FONTE
   A trava fica no PONTO DE CHAMADA. Se a validacao sair de dentro de
   wa_tpl_cria e ficar so na tela, existe caminho que submete sem ela.
============================================================ */
$src = file_get_contents(__DIR__ . '/../lib/wa-template.php');
ok(preg_match('/function wa_tpl_cria\(.*?\n\}/s', $src, $m) === 1,
   'achei o corpo de wa_tpl_cria');
$corpo_cria = $m[0];
ok(strpos($corpo_cria, 'wa_tpl_problemas(') !== false,
   'wa_tpl_cria valida o texto antes de submeter');
ok(strpos($corpo_cria, 'wa_tpl_componentes(') !== false,
   'wa_tpl_cria monta os componentes pela funcao travada');
ok(strpos($corpo_cria, 'JSON_UNESCAPED_UNICODE') !== false,
   'o corpo do template sobe com acento de verdade');
/* po_config() e carregada no render publico das paginas de roteiro e o
   cabecalho dela proibe segredo ali. */
ok(strpos($src, 'po_config()') === false,
   'a biblioteca le segredo por wa_config(), nunca po_config()');

echo "test-wa-template OK\n";
