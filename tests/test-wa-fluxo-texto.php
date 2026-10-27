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

echo "test-wa-fluxo-texto: ok\n";
