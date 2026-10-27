<?php
require __DIR__ . '/../lib/wa-fluxo.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

// 1. as listas fechadas existem e tem exatamente o conteudo esperado, na ordem
ok(WA_CARD_TIPOS === ['texto','botoes','lista','pdf','marca','ir_para_fluxo','humano','fim'], 'WA_CARD_TIPOS');
ok(WA_CARD_ESPERA === ['botoes','lista'], 'WA_CARD_ESPERA');
ok(WA_CARD_TERMINAL === ['humano','fim'], 'WA_CARD_TERMINAL');
ok(WA_SEM_RESPOSTA_TIPOS === ['texto','marca','humano','fim'], 'sem_resposta so aceita card que nao espera');
ok(WA_FLUXO_VARS === ['nome','roteiro','data'], 'WA_FLUXO_VARS');

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
ok($v['nome'] === 'Marta', 'nome vem do lead');

$r_label = ['titulo' => 'Chile', 'data_label' => '10/03/27 a 20/03/27', 'periodo' => 'ignorar'];
ok(wa_fluxo_vars_do_roteiro($r_label, 'X')['data'] === '10/03/27 a 20/03/27', 'data_label ganha quando existe');

$r_nada = ['titulo' => 'Sem data', 'data_label' => '', 'periodo' => ''];
ok(wa_fluxo_vars_do_roteiro($r_nada, 'X')['data'] === 'na data prevista', 'sem nenhuma das duas, texto de recurso');
ok(wa_fluxo_vars_do_roteiro(null, 'X')['roteiro'] === '', 'sem roteiro no contexto, roteiro vazio');

// valor maior que o maximo e cortado, senao o pior caso conferido na validacao mentiria
$longo = wa_fluxo_vars_do_roteiro(['titulo' => str_repeat('á', 90)], str_repeat('ã', 70));
ok(mb_strlen($longo['roteiro']) === 60 && mb_strlen($longo['nome']) === 40, 'valores respeitam WA_FLUXO_VAR_MAX');

// 4. substituicao, e nenhum {buraco} sobra na mensagem do cliente
ok(wa_fluxo_texto('Oi {nome}, a {roteiro} sai em {data}.', $v) === 'Oi Marta, a Turquia com Antalia sai em 27/10/27 a 12/11/27.', 'substitui as tres');
ok(strpos(wa_fluxo_texto('Oi {nome}. {inexistente} fim', $v), '{') === false, 'buraco desconhecido e removido, nao vai para o cliente');
ok(wa_fluxo_texto('Perfeito, {nome}.', ['nome' => '', 'roteiro' => '', 'data' => '']) === 'Perfeito.', 'nome vazio nao deixa pontuacao orfa');

// 5. tamanho conferido na expansao de PIOR CASO, nao no texto cru
ok(wa_fluxo_tam_pior_caso('abc') === 3, 'texto sem variavel conta o proprio tamanho');
ok(wa_fluxo_tam_pior_caso('{roteiro}') === 60, 'roteiro conta 60');
ok(wa_fluxo_tam_pior_caso('{nome} e {data}') === 40 + 3 + 40, 'soma as variaveis pelo maximo e o literal');
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
$mao = ['nome' => str_repeat('a', 200),
        'roteiro' => str_repeat('palavra ', 40),
        'data' => '27/10/2027 a 12/11/2027 e depois mais um bocado de texto'];
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
$modelo = 'Oi {nome}, sobre {roteiro}: {data}.';
ok(mb_strlen(wa_fluxo_texto($modelo, $mao), 'UTF-8') <= wa_fluxo_tam_pior_caso($modelo), 'pior caso cobre o render');

// 9. wa_dep (motor) e wa_fl_dep (fluxo) sao uma tabela so
require_once __DIR__ . '/../lib/wa-motor.php';
ok(wa_dep('send_buttons') === 'wa_send_buttons', 'wa_dep resolve send_buttons');
foreach (['select','insert','update','roteiros','send_text','send_buttons','send_list','send_doc'] as $k) {
    ok(wa_dep($k) === wa_fl_dep($k), "default de $k igual nos dois");
}

echo "test-wa-fluxo-texto: ok\n";
