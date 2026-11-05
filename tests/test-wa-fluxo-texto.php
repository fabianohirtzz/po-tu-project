<?php
require __DIR__ . '/../lib/wa-fluxo.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

// 1. as listas fechadas existem e tem exatamente o conteudo esperado, na ordem
ok(WA_CARD_TIPOS === ['texto','botoes','lista','roteiros','pdf','marca','ir_para_fluxo','humano','fim'], 'WA_CARD_TIPOS');
ok(WA_CARD_ESPERA === ['botoes','lista','roteiros'], 'WA_CARD_ESPERA');
ok(WA_CARD_TERMINAL === ['humano','fim'], 'WA_CARD_TERMINAL');
ok(WA_SEM_RESPOSTA_TIPOS === ['texto','marca','humano','fim'], 'sem_resposta so aceita card que nao espera');
ok(WA_FLUXO_VARS === ['roteiro','data','datas','destino'], 'WA_FLUXO_VARS');
/* As TRES primeiras dependem de haver roteiro no contexto. Sem isto o
   validador deixa publicar um card que usa {datas} antes de o cliente
   escolher a viagem, e a variavel chega vazia a ele. */
ok(WA_FLUXO_VARS_ROTEIRO === ['roteiro','data','datas'], 'WA_FLUXO_VARS_ROTEIRO');
/* {destino} depende de OUTRA coisa: de a conversa ter passado por um atalho
   do tipo `destino`. Por isso ela tem lista propria - nao e uma variavel do
   roteiro, e tratar as duas pela mesma lista bloquearia {destino} em todo
   card sem roteiro, que e justamente onde ela serve. */
ok(WA_FLUXO_VARS_DESTINO === ['destino'], 'WA_FLUXO_VARS_DESTINO');
ok(!in_array('destino', WA_FLUXO_VARS_ROTEIRO, true), 'destino NAO depende de roteiro');
ok(WA_FLUXO_VAR_MAX['destino'] === 40, 'o teto de {destino} e 40 (nome de pais, nao frase)');
ok(!in_array('nome', WA_FLUXO_VARS, true), 'nome saiu de WA_FLUXO_VARS');
ok(!array_key_exists('nome', WA_FLUXO_VAR_MAX), 'nome saiu de WA_FLUXO_VAR_MAX');
/* Toda variavel de WA_FLUXO_VARS tem teto, e todo teto tem variavel: as duas
   listas sao lidas em pontos diferentes (a substituicao e o pior caso), e uma
   sem par faz a validacao aprovar texto que estoura o limite da Cloud API. */
ok(array_keys(WA_FLUXO_VAR_MAX) === WA_FLUXO_VARS, 'WA_FLUXO_VAR_MAX cobre exatamente WA_FLUXO_VARS, na mesma ordem');

// 2. a whitelist NAO contem o que apagaria faturamento nem o que autoriza disparo pago
foreach (['venda','venda_at','notas','cliente','revisado','telefone','opt_out_at'] as $proibido) {
    ok(!in_array($proibido, WA_FLUXO_CAMPOS_MARCAVEIS, true), "$proibido fora da whitelist");
}
ok(!in_array('venda', WA_FLUXO_STATUS_PERMITIDOS, true), 'fluxo nao carimba status venda');
ok(!in_array('negociacao', WA_FLUXO_STATUS_PERMITIDOS, true), 'fluxo nao carimba status negociacao');

// 3. VARIAVEIS. O {data} de hoje sai vazio porque data_label e string vazia e ?? nao pega.
$r_periodo = ['titulo' => 'Turquia com Antalia', 'data_label' => '', 'periodo' => '27/10/27 a 12/11/27'];
$v = wa_fluxo_vars_do_roteiro($r_periodo, 'Marta');
ok($v['data'] === '27/10/27 a 12/11/27', 'data_label vazio cai em periodo (?: e nao ??)');
ok($v['roteiro'] === 'Turquia com Antalia', 'roteiro vem do titulo');
ok(!array_key_exists('nome', $v), 'wa_fluxo_vars_do_roteiro nao devolve nome, mesmo recebendo um');
ok(array_keys($v) === ['roteiro','data','datas','destino'], 'as quatro, na ordem de WA_FLUXO_VARS');
ok($v['destino'] === '', 'sem atalho de destino no contexto, {destino} sai vazia');

$r_label = ['titulo' => 'Chile', 'data_label' => '10/03/27 a 20/03/27', 'periodo' => 'ignorar'];
ok(wa_fluxo_vars_do_roteiro($r_label, 'X')['data'] === '10/03/27 a 20/03/27', 'data_label ganha quando existe');

$r_nada = ['titulo' => 'Sem data', 'data_label' => '', 'periodo' => ''];
ok(wa_fluxo_vars_do_roteiro($r_nada, 'X')['data'] === 'na data prevista', 'sem nenhuma das duas, texto de recurso');
ok(wa_fluxo_vars_do_roteiro(null, 'X')['roteiro'] === '', 'sem roteiro no contexto, roteiro vazio');

// valor maior que o maximo e cortado, senao o pior caso conferido na validacao mentiria
$longo = wa_fluxo_vars_do_roteiro(['titulo' => str_repeat('á', 90), 'periodo' => str_repeat('ã', 90)], 'ignorado');
ok(mb_strlen($longo['roteiro']) === 60 && mb_strlen($longo['data']) === 40 && mb_substr($longo['data'], -1) === '…', 'valores respeitam WA_FLUXO_VAR_MAX');

// 4. substituicao, e nenhum {buraco} sobra na mensagem do cliente
ok(wa_fluxo_texto('A {roteiro} sai em {data}.', $v) === 'A Turquia com Antalia sai em 27/10/27 a 12/11/27.', 'substitui as duas');
ok(strpos(wa_fluxo_texto('Oi {inexistente} fim', $v), '{') === false, 'buraco desconhecido e removido, nao vai para o cliente');
ok(wa_fluxo_texto('Perfeito, {roteiro}.', ['roteiro' => '', 'data' => '']) === 'Perfeito.', 'variavel vazia nao deixa pontuacao orfa');
// {nome} nao e mais variavel: vira buraco desconhecido, e a chave nunca chega ao cliente
ok(wa_fluxo_texto('Oi {nome}, segue o {roteiro}', ['nome' => 'Fulano', 'roteiro' => 'Chile', 'data' => '']) === 'Oi, segue o Chile', '{nome} sai sem a chave e sem o nome, com a pontuacao limpa');

// 5. tamanho conferido na expansao de PIOR CASO, nao no texto cru
ok(wa_fluxo_tam_pior_caso('abc') === 3, 'texto sem variavel conta o proprio tamanho');
ok(wa_fluxo_tam_pior_caso('{roteiro}') === 60, 'roteiro conta 60');
ok(wa_fluxo_tam_pior_caso('{roteiro} e {data}') === 60 + 3 + 40, 'soma as variaveis pelo maximo e o literal');
ok(wa_fluxo_tam_pior_caso('{nome}') === 0, '{nome} nao conta: e removida no envio');
ok(wa_fluxo_tam_pior_caso('ação') === 4, 'conta caracteres, nao bytes');

// 6. wa_fluxo_campos_marca leva EXATAMENTE a whitelist, nada mais
$ctx = ['roteiro_slug' => 'turquia'];
$cfg = ['status' => 'atendimento', 'qualif_data' => true, 'venda' => 99999,
        'venda_at' => '2026-01-01', 'notas' => 'apagar tudo', 'cliente' => true,
        'revisado' => true, 'interesses' => '+roteiro'];
$campos = wa_fluxo_campos_marca($cfg, $ctx);
foreach (['venda','venda_at','notas','cliente','revisado'] as $p) {
    ok(!array_key_exists($p, $campos), "$p nao passa");
}
ok($campos['status'] === 'atendimento', 'status permitido passa');
ok($campos['qualif_data'] === true, 'qualif_data passa');
ok($campos['interesses'] === ['turquia'], 'interesses vira a lista com o slug do contexto');
ok(array_diff(array_keys($campos), WA_FLUXO_CAMPOS_MARCAVEIS) === [], 'nenhuma chave fora da whitelist');

// status fora da lista e DESCARTADO, nao gravado
ok(!array_key_exists('status', wa_fluxo_campos_marca(['status' => 'venda'], $ctx)), 'status venda e descartado');
ok(!array_key_exists('status', wa_fluxo_campos_marca(['status' => 'negociacao'], $ctx)), 'status negociacao e descartado');

// interesses sem slug no contexto nao grava lista com vazio dentro
ok(!array_key_exists('interesses', wa_fluxo_campos_marca(['interesses' => '+roteiro'], [])), 'sem slug, interesses nao e gravado');

// 7. as quatro funcoes movidas vivem aqui
ok(function_exists('wa_limpa_pontuacao') && function_exists('wa_registra_saida')
   && function_exists('wa_msg_do_robo') && function_exists('wa_acha_roteiro'), 'funcoes movidas definidas');
ok(wa_acha_roteiro('b', [['slug' => 'a'], ['slug' => 'b']]) === ['slug' => 'b'], 'wa_acha_roteiro');

// 8. o teto vale no PONTO DE USO: $vars montado a mao tambem e cortado
$mao = ['roteiro' => str_repeat('palavra ', 40),
        'data' => '27/10/2027 a 12/11/2027 e depois mais um bocado de texto',
        'datas' => str_repeat('Datas de Dezembro: 02/12/27, 10/12/27 ', 20),
        'destino' => str_repeat('Emirados Arabes Unidos ', 5)];
foreach (WA_FLUXO_VARS as $k) {
    $saida = wa_fluxo_texto('{' . $k . '}', $mao);
    ok(mb_strlen($saida, 'UTF-8') <= WA_FLUXO_VAR_MAX[$k], "$k a mao nao passa do maximo");
    ok(mb_substr($saida, -1, 1, 'UTF-8') === '…', "$k cortado leva reticencia");
}

foreach (WA_FLUXO_VAR_MAX as $k => $max) {
    $exato = str_repeat('x', $max);
    ok(wa_fluxo_corta($exato, $max) === $exato, "$k: exatamente o maximo sai intacto");
    ok(strpos(wa_fluxo_corta($exato, $max), '…') === false, "$k: sem reticencia quando nao cortou");
    $mais = wa_fluxo_corta(str_repeat('x', $max + 1), $max);
    ok(mb_strlen($mais, 'UTF-8') === $max, "$k: maximo+1 sai com exatamente o maximo, contando a reticencia");
    ok(mb_substr($mais, -1, 1, 'UTF-8') === '…', "$k: reticencia ao cortar");
    ok(wa_fluxo_corta($mais, $max) === $mais, "$k: idempotente");
    ok(wa_fluxo_corta($mao['data'], $max) === wa_fluxo_corta(wa_fluxo_corta($mao['data'], $max), $max), "$k: idempotente em frase");
}
ok(wa_fluxo_corta('abc', 3) === 'abc' && wa_fluxo_corta('abcd', 1) === '…', 'bordas do corte');

// recua a limite de palavra: periodo de data nao sai partido no meio de um numero
$cortada = wa_fluxo_corta('27/10/2027 a 12/11/2027 e depois mais', 40);
ok($cortada === '27/10/2027 a 12/11/2027 e depois mais', 'cabe, sai intacto');
$cortada = wa_fluxo_corta('27/10/2027 a 12/11/2027 e volta em 25/11/2027 sempre', 40);
ok($cortada === '27/10/2027 a 12/11/2027 e volta em…', 'recuou ate o espaco, sem partir 25/11/2027');
ok(wa_fluxo_corta(str_repeat('a', 30) . ' ' . str_repeat('b', 30), 40) === str_repeat('a', 30) . '…', 'recuo ate a palavra');

// o pior caso cobre o render com valores longos
$modelo = 'Oi, sobre {roteiro}: {data}.';
ok(mb_strlen(wa_fluxo_texto($modelo, $mao), 'UTF-8') <= wa_fluxo_tam_pior_caso($modelo), 'pior caso cobre o render');

// 9. wa_dep (motor) e wa_fl_dep (fluxo) sao uma tabela so
require_once __DIR__ . '/../lib/wa-motor.php';
ok(wa_dep('send_buttons') === 'wa_send_buttons', 'wa_dep resolve send_buttons');
foreach (['select','insert','update','roteiros','send_text','send_buttons','send_list','send_doc'] as $k) {
    ok(wa_dep($k) === wa_fl_dep($k), "default de $k igual nos dois");
}

/* ---------- 10. {destino}: a variavel do atalho de destino ----------
   Ela nao vem do roteiro: vem de `destino_nome`, que o atalho do tipo
   `destino` grava no contexto. E a frase que ela existe para montar e a
   resposta do pedido de 04/11/2026: "ainda nao temos data para a Italia". */
$vd = wa_fluxo_vars_do_roteiro(null, '', ['destino_nome' => 'Itália']);
ok($vd['destino'] === 'Itália', 'o destino do contexto vira a variavel');
ok(wa_fluxo_texto('Ainda não temos data para a {destino}.', $vd) === 'Ainda não temos data para a Itália.', 'a variavel imprime');
ok(wa_fluxo_texto('Ainda não temos data para a {destino}.', wa_fluxo_vars_do_roteiro(null, '', []))
   === 'Ainda não temos data para a.', 'sem destino ela some e a pontuacao fecha, como as outras');

/* O teto vale no PONTO DE USO, nao so em quem monta o array: um chamador que
   monte $vars a mao renderia mais que o orcamento que a validacao aprovou, e
   a Meta recusa a mensagem INTEIRA, em silencio. */
ok(mb_strlen(wa_fluxo_texto('{destino}', ['destino' => str_repeat('z', 90)]), 'UTF-8') === 40, 'o teto de 40 vale no ponto de uso');
ok(wa_fluxo_corta(str_repeat('a', 60), WA_FLUXO_VAR_MAX['destino']) === str_repeat('a', 39) . '…', 'teto de 40 contando a reticencia');
ok(wa_fluxo_tam_pior_caso('{destino}') === 40, '{destino} entra na analise de pior caso');
ok(wa_fluxo_tam_pior_caso('a {destino} b {roteiro}') === 5 + 40 + 60, 'somada com as outras');

/* O destino longo tambem e cortado na montagem, nao so no uso. */
ok(mb_strlen(wa_fluxo_vars_do_roteiro(null, '', ['destino_nome' => str_repeat('é', 90)])['destino'], 'UTF-8') === 40,
   'o valor ja sai cortado da montagem');

/* ---------- 11. o card `marca` e o +destino ----------
   ACHADO 6, que esta tarefa corrige: `+destino` e `+roteiro` passavam pelo
   MESMO caminho, que exigia slug. Um atalho por destino nao tem slug, entao
   gravava NADA, em silencio - justamente o dado que justifica o pedido. */
ok(wa_fluxo_campos_marca(['espera' => '+destino'], ['destino_nome' => 'Itália'])['espera_destino'] === 'Itália',
   '+destino grava o NOME do destino');
ok(!isset(wa_fluxo_campos_marca(['espera' => '+destino'], [])['espera_destino']),
   'sem destino no contexto nao grava nada');
ok(!isset(wa_fluxo_campos_marca(['espera' => '+destino'], ['destino_nome' => '  '])['espera_destino']),
   'destino em branco tambem nao');
/* E `+destino` NUNCA escreve na coluna `espera` de po_leads: ela deixou de
   ser escrita quando a tabela propria entrou. Escrever nas duas faria duas
   fontes da mesma verdade, que desandam. */
ok(!isset(wa_fluxo_campos_marca(['espera' => '+destino'], ['destino_nome' => 'Itália'])['espera']),
   '+destino nao escreve mais a coluna espera de po_leads');
/* O slug NAO vaza para a espera do destino: sao dois atalhos diferentes. */
ok(!isset(wa_fluxo_campos_marca(['espera' => '+destino'], ['roteiro_slug' => 'turquia'])['espera_destino']),
   'roteiro no contexto nao serve de destino');
/* `+roteiro` segue como era, em interesses. */
ok(wa_fluxo_campos_marca(['interesses' => '+roteiro'], ['roteiro_slug' => 'turquia'])['interesses'] === ['turquia'],
   '+roteiro segue gravando o slug em interesses');
ok(!isset(wa_fluxo_campos_marca(['interesses' => '+roteiro'], ['destino_nome' => 'Itália'])['interesses']),
   'e destino no contexto nao serve de roteiro');

/* A whitelist cresceu em UMA chave, e as proibidas seguem proibidas. A
   invariante de verdade nao e o tamanho: e que nada que uma importacao de
   agenda apagaria (faturamento, anotacao) possa entrar aqui. */
ok(in_array('espera_destino', WA_FLUXO_CAMPOS_MARCAVEIS, true), 'espera_destino entrou na whitelist');
ok(count(WA_FLUXO_CAMPOS_MARCAVEIS) === 7, 'a whitelist foi de 6 para 7, e so por espera_destino (deu: ' . count(WA_FLUXO_CAMPOS_MARCAVEIS) . ')');
foreach (['venda','venda_at','notas'] as $c) {
    ok(!in_array($c, WA_FLUXO_CAMPOS_MARCAVEIS, true), $c . ' nunca entra na whitelist');
}

echo "test-wa-fluxo-texto: ok\n";
