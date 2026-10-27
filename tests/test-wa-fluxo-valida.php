<?php
require __DIR__ . '/../lib/wa-fluxo.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

/** @return array lista de numeros de regra que bloqueiam */
function bloqueios($fluxo, $cards, $outros = []) {
    $out = [];
    foreach (wa_fluxo_valida($fluxo, $cards, $outros) as $p) if ($p['bloqueia']) $out[] = $p['regra'];
    sort($out); return array_values(array_unique($out));
}
function avisos($fluxo, $cards, $outros = []) {
    $out = [];
    foreach (wa_fluxo_valida($fluxo, $cards, $outros) as $p) if (!$p['bloqueia']) $out[] = $p['regra'];
    sort($out); return array_values(array_unique($out));
}

$fx = ['id' => 'f1', 'nome' => 'Boas-vindas', 'gatilho' => 'mensagem_nova', 'gatilho_valor' => null,
       'ativo' => false, 'card_inicial' => 'bv'];
// fluxo valido minimo: botoes -> humano, com fallback e fim
$base = [
  ['id'=>'bv','fluxo_id'=>'f1','tipo'=>'botoes','titulo'=>'Boas-vindas','texto'=>'Oi {nome}, como ajudo?',
   'opcoes'=>[['id'=>'b1','rotulo'=>'Falar com a Lais','destino'=>'hum']],'config'=>[],
   'proximo'=>null,'fallback'=>'ops','sem_resposta'=>'fim','lembrete_ativo'=>true,'lembrete_texto'=>'Oi {nome}?'],
  ['id'=>'ops','fluxo_id'=>'f1','tipo'=>'botoes','titulo'=>'Nao entendi','texto'=>'Nao entendi. Quer falar com a Lais?',
   'opcoes'=>[['id'=>'v','rotulo'=>'Falar com a Lais','destino'=>'hum']],'config'=>[],
   'proximo'=>null,'fallback'=>'hum','sem_resposta'=>null,'lembrete_ativo'=>true,'lembrete_texto'=>'Oi?'],
  ['id'=>'hum','fluxo_id'=>'f1','tipo'=>'humano','titulo'=>'Atendimento','texto'=>'Vou chamar a Lais.',
   'opcoes'=>[],'config'=>[],'proximo'=>null,'fallback'=>null,'sem_resposta'=>null,'lembrete_ativo'=>false,'lembrete_texto'=>null],
  ['id'=>'fim','fluxo_id'=>'f1','tipo'=>'fim','titulo'=>'Fim','texto'=>'Ate logo.',
   'opcoes'=>[],'config'=>[],'proximo'=>null,'fallback'=>null,'sem_resposta'=>null,'lembrete_ativo'=>false,'lembrete_texto'=>null],
];
ok(bloqueios($fx, $base) === [], 'o fluxo minimo valido nao bloqueia nada');
ok(avisos($fx, $base) === [], 'e nao avisa nada');

// regra 1: saida apontando para card que nao existe
$c = $base; $c[0]['opcoes'][0]['destino'] = 'fantasma';
ok(in_array(1, bloqueios($fx, $c), true), 'regra 1: destino inexistente');

// regra 2: card que espera sem lembrete e sem sem_resposta
$c = $base; $c[0]['lembrete_ativo'] = false; $c[0]['sem_resposta'] = null;
ok(in_array(2, bloqueios($fx, $c), true), 'regra 2: espera sem lembrete e sem sem_resposta');
$c = $base; $c[0]['lembrete_ativo'] = false;  // mas tem sem_resposta
ok(!in_array(2, bloqueios($fx, $c), true), 'regra 2 aceita sem_resposta no lugar do lembrete');

// regra 3: card inalcancavel a partir do inicial
$c = $base; $c[] = ['id'=>'orfao','fluxo_id'=>'f1','tipo'=>'fim','titulo'=>'Orfao','texto'=>'x',
  'opcoes'=>[],'config'=>[],'proximo'=>null,'fallback'=>null,'sem_resposta'=>null,'lembrete_ativo'=>false,'lembrete_texto'=>null];
ok(in_array(3, bloqueios($fx, $c), true), 'regra 3: card orfao');

// regra 4: porta propria exige humano alcancavel; porta chamado basta terminar
$c = $base; $c[0]['opcoes'][0]['destino'] = 'fim'; $c[1]['fallback'] = 'fim';
array_splice($c, 2, 1); // remove o card humano
ok(in_array(4, bloqueios($fx, $c), true), 'regra 4: fluxo de porta propria sem humano bloqueia');
$fx_chamado = array_merge($fx, ['gatilho' => 'chamado']);
ok(!in_array(4, bloqueios($fx_chamado, $c), true), 'regra 4: fluxo de porta chamado nao exige humano');
$c2 = $c; $c2[0]['opcoes'][0]['destino'] = 'bv'; $c2[0]['sem_resposta'] = null; $c2[0]['fallback'] = 'bv'; $c2[1]['fallback'] = 'bv';
array_splice($c2, 3, 1); // remove tambem o fim: nenhum terminal
ok(in_array(4, bloqueios($fx_chamado, $c2), true), 'regra 4: nem chamado escapa de nao ter terminal nenhum');

// regra 5: rotulo de botao vazio, acima de 20, ou repetido no mesmo card
foreach ([['', 'vazio'], [str_repeat('a', 21), 'acima de 20']] as [$rot, $nome]) {
    $c = $base; $c[0]['opcoes'][0]['rotulo'] = $rot;
    ok(in_array(5, bloqueios($fx, $c), true), "regra 5: rotulo $nome");
}
$c = $base; $c[0]['opcoes'][] = ['id'=>'b2','rotulo'=>'Falar com a Lais','destino'=>'fim'];
ok(in_array(5, bloqueios($fx, $c), true), 'regra 5: rotulos repetidos no mesmo card');
$c = $base; $c[0]['opcoes'] = array_fill(0, 4, ['id'=>'x','rotulo'=>'Op','destino'=>'hum']);
ok(in_array(5, bloqueios($fx, $c), true), 'regra 5: mais de 3 botoes');
$c = $base; $c[0]['opcoes'][0]['rotulo'] = str_repeat('ç', 20);
ok(!in_array(5, bloqueios($fx, $c), true), 'regra 5: 20 caracteres acentuados passam');

// regra 6: texto acima do limite, medido na expansao de pior caso
$c = $base; $c[0]['texto'] = str_repeat('a', 1000) . '{roteiro}';  // 1000 + 60 > 1024
ok(in_array(6, bloqueios($fx, $c), true), 'regra 6: 1000 literais mais {roteiro} estouram 1024 do card botoes');
$c = $base; $c[0]['texto'] = '';
ok(in_array(6, bloqueios($fx, $c), true), 'regra 6: texto vazio em card que manda mensagem');

// regra 7: lista estatica sem linha, ou dinamica sem fonte
$lst = ['id'=>'lst','fluxo_id'=>'f1','tipo'=>'lista','titulo'=>'Qual roteiro','texto'=>'Qual?',
  'opcoes'=>[],'config'=>[],'proximo'=>'hum','fallback'=>'ops','sem_resposta'=>'fim','lembrete_ativo'=>true,'lembrete_texto'=>'Oi?'];
$c = $base; $c[0]['opcoes'][0]['destino'] = 'lst'; $c[] = $lst;
ok(in_array(7, bloqueios($fx, $c), true), 'regra 7: lista sem fonte e sem linha');
$c2 = $c; $c2[4]['config'] = ['fonte' => 'roteiros'];
ok(!in_array(7, bloqueios($fx, $c2), true), 'regra 7: lista com fonte roteiros passa');
$c3 = $c; $c3[4]['opcoes'] = array_fill(0, 11, ['id'=>'x','rotulo'=>'L','destino'=>'hum']); $c3[4]['config'] = ['fonte'=>'estatica'];
ok(in_array(7, bloqueios($fx, $c3), true), 'regra 7: mais de 10 linhas');

// regra 8: ciclo sem terminal alcancavel de dentro dele
$c = $base; $c[0]['opcoes'][0]['destino'] = 'ops'; $c[1]['opcoes'][0]['destino'] = 'bv'; $c[1]['fallback'] = 'bv'; $c[0]['fallback'] = 'ops';
array_splice($c, 2, 1);
ok(in_array(8, bloqueios($fx, $c), true), 'regra 8: ciclo fechado sem terminal');

// regra 9: porta de entrada ja ocupada por outro fluxo ativo
$outros = [['id'=>'f2','gatilho'=>'mensagem_nova','gatilho_valor'=>null,'ativo'=>true,'versao_atual'=>'v2']];
ok(in_array(9, bloqueios($fx, $base, $outros), true), 'regra 9: porta ocupada');
$outros_desligado = [['id'=>'f2','gatilho'=>'mensagem_nova','gatilho_valor'=>null,'ativo'=>false,'versao_atual'=>'v2']];
ok(!in_array(9, bloqueios($fx, $base, $outros_desligado), true), 'regra 9: fluxo desligado nao ocupa a porta');
ok(!in_array(9, bloqueios($fx, $base, [['id'=>'f1','gatilho'=>'mensagem_nova','gatilho_valor'=>null,'ativo'=>true,'versao_atual'=>'v']]), true),
   'regra 9: o proprio fluxo nao disputa a porta com ele mesmo');

// regra 10: marca tentando campo fora da whitelist
$mrc = ['id'=>'mrc','fluxo_id'=>'f1','tipo'=>'marca','titulo'=>'Marca','texto'=>null,
  'opcoes'=>[],'config'=>['venda'=>1000],'proximo'=>'hum','fallback'=>null,'sem_resposta'=>null,'lembrete_ativo'=>false,'lembrete_texto'=>null];
$c = $base; $c[0]['opcoes'][0]['destino'] = 'mrc'; $c[] = $mrc;
ok(in_array(10, bloqueios($fx, $c), true), 'regra 10: marca com venda na config');
$c2 = $c; $c2[4]['config'] = ['status' => 'venda'];
ok(in_array(10, bloqueios($fx, $c2), true), 'regra 10: marca com status venda');
$c3 = $c; $c3[4]['config'] = ['status' => 'perdido', 'qualif_data' => false];
ok(!in_array(10, bloqueios($fx, $c3), true), 'regra 10: marca dentro da whitelist passa');

// regra 11: ir_para_fluxo para fluxo inexistente, nao publicado ou desligado
$vai = ['id'=>'vai','fluxo_id'=>'f1','tipo'=>'ir_para_fluxo','titulo'=>'Vai','texto'=>null,
  'opcoes'=>[],'config'=>['fluxo'=>'f9'],'proximo'=>null,'fallback'=>null,'sem_resposta'=>null,'lembrete_ativo'=>false,'lembrete_texto'=>null];
$c = $base; $c[0]['opcoes'][0]['destino'] = 'vai'; $c[] = $vai;
ok(in_array(11, bloqueios($fx, $c, []), true), 'regra 11: fluxo destino inexistente');
$so_publicado = [['id'=>'f9','gatilho'=>'chamado','gatilho_valor'=>null,'ativo'=>false,'versao_atual'=>'v9']];
ok(in_array(11, bloqueios($fx, $c, $so_publicado), true), 'regra 11: fluxo destino desligado');
$ok9 = [['id'=>'f9','gatilho'=>'chamado','gatilho_valor'=>null,'ativo'=>true,'versao_atual'=>'v9']];
ok(!in_array(11, bloqueios($fx, $c, $ok9), true), 'regra 11: fluxo destino publicado e ligado passa');

// regra 12: sem_resposta apontando para card que ESPERA
$c = $base; $c[0]['sem_resposta'] = 'ops';  // ops e botoes
ok(in_array(12, bloqueios($fx, $c), true), 'regra 12: sem_resposta para card que espera');
ok(!in_array('ir_para_fluxo', WA_SEM_RESPOSTA_TIPOS, true), 'o cron nao pula de fluxo por falta de resposta');
foreach (WA_SEM_RESPOSTA_TIPOS as $t) {
    $c = $base; $c[] = ['id'=>'sr','fluxo_id'=>'f1','tipo'=>$t,'titulo'=>'SR','texto'=>'x',
      'opcoes'=>[],'config'=>[],'proximo'=>'hum','fallback'=>null,
      'sem_resposta'=>null,'lembrete_ativo'=>false,'lembrete_texto'=>null];
    $c[0]['sem_resposta'] = 'sr';
    ok(!in_array(12, bloqueios($fx, $c), true), "regra 12 aceita sem_resposta para card $t");
}

// regra 13: pdf alcancavel sem escolha de roteiro antes
$pdf = ['id'=>'pdf','fluxo_id'=>'f1','tipo'=>'pdf','titulo'=>'PDF','texto'=>'Segue a {roteiro}.',
  'opcoes'=>[],'config'=>[],'proximo'=>'hum','fallback'=>null,'sem_resposta'=>null,'lembrete_ativo'=>false,'lembrete_texto'=>null];
$c = $base; $c[0]['opcoes'][0]['destino'] = 'pdf'; $c[] = $pdf;
ok(in_array(13, bloqueios($fx, $c), true), 'regra 13: pdf sem lista dinamica no caminho');
$c2 = $c; $c2[] = $lst; $c2[4]['config'] = ['fonte'=>'roteiros']; $c2[0]['opcoes'][0]['destino'] = 'lst'; $c2[4]['proximo'] = 'pdf';
ok(!in_array(13, bloqueios($fx, $c2), true), 'regra 13: pdf depois de lista dinamica passa');
$fx_anuncio = array_merge($fx, ['gatilho' => 'anuncio']);
ok(!in_array(13, bloqueios($fx_anuncio, $c), true), 'regra 13: porta anuncio ja traz o roteiro');

// regra 14: variavel fora da lista, mal escrita, ou indisponivel naquele card
foreach (['{Nome}', '{ nome }', '{nome2}', '{inventada}'] as $v) {
    $c = $base; $c[0]['texto'] = 'Oi ' . $v;
    ok(in_array(14, bloqueios($fx, $c), true), "regra 14: $v recusada");
}
$c = $base; $c[0]['texto'] = 'A {roteiro} e boa.';  // bv e alcancavel sem roteiro no contexto
ok(in_array(14, bloqueios($fx, $c), true), 'regra 14: {roteiro} em card alcancavel sem roteiro');
$c = $base; $c[0]['lembrete_texto'] = 'Oi {Nome}';
ok(in_array(14, bloqueios($fx, $c), true), 'regra 14 vale tambem para o texto do lembrete');

// AVISOS, que nao bloqueiam
$c = $base; $c[] = ['id'=>'so','fluxo_id'=>'f1','tipo'=>'fim','titulo'=>'So','texto'=>'x',
  'opcoes'=>[],'config'=>[],'proximo'=>null,'fallback'=>null,'sem_resposta'=>null,'lembrete_ativo'=>false,'lembrete_texto'=>null];
$c[1]['sem_resposta'] = 'so';  // alcancavel so por sem_resposta, entao nao e regra 3 (bv segue levando a fim)
ok(bloqueios($fx, $c) === [], 'o card alcancavel por sem_resposta nao bloqueia');

// mensagens: toda em portugues, sem travessao, e nomeando o card
foreach (wa_fluxo_valida($fx, (function($b){ $b[0]['opcoes'][0]['destino']='fantasma'; return $b; })($base)) as $p) {
    ok(strpos($p['texto'], '—') === false, 'nenhuma mensagem usa travessao');
    ok(trim($p['texto']) !== '', 'toda mensagem tem texto');
}

// wa_fluxo_grafo_de monta o retrato no formato que a Task 4 consome
$g = wa_fluxo_grafo_de($fx, $base);
ok($g['id'] === 'f1' && $g['nome'] === 'Boas-vindas', 'o retrato guarda id e nome do fluxo');
ok($g['inicial'] === 'bv', 'o retrato guarda o card inicial');
ok($g['humano'] === 'hum', 'o retrato guarda o card humano do fluxo');
ok($g['porta'] === 'mensagem_nova', 'o retrato guarda a porta');
ok(array_keys($g['cards']) === ['bv','ops','hum','fim'], 'os cards viram mapa por id');

// ---- casos que o brief nao cobre: o par "dispara / nao dispara" de cada regra e os avisos ----
function card($id, $tipo, $extra = []) {
    return array_merge(['id'=>$id,'fluxo_id'=>'f1','tipo'=>$tipo,'titulo'=>ucfirst($id),'texto'=>'x',
        'opcoes'=>[],'config'=>[],'proximo'=>null,'fallback'=>null,'sem_resposta'=>null,
        'lembrete_ativo'=>false,'lembrete_texto'=>null], $extra);
}
function avisos_ids($fluxo, $cards, $outros = []) {
    $out = [];
    foreach (wa_fluxo_valida($fluxo, $cards, $outros) as $p) if (!$p['bloqueia']) $out[$p['regra']] = $p['card'];
    return $out;
}

// aviso 15: card que ninguem liga (avisa, e ainda bloqueia pela 3, que e outra coisa)
$c = $base; $c[] = card('solto', 'fim');
$av = avisos_ids($fx, $c);
ok(isset($av[15]) && $av[15] === 'solto', 'aviso 15: card sem entrada');
foreach (wa_fluxo_valida($fx, $c) as $p) if ($p['regra'] === 15) ok($p['bloqueia'] === false, 'aviso 15 nunca bloqueia');
ok(!array_key_exists(15, avisos_ids($fx, $base)), 'aviso 15 nao dispara no fluxo ligado, nem no card inicial');

// aviso 16: sem card fim
$c = $base; array_splice($c, 3, 1); $c[0]['sem_resposta'] = null;
ok(array_key_exists(16, avisos_ids($fx, $c)), 'aviso 16: fluxo sem card fim');
foreach (wa_fluxo_valida($fx, $c) as $p) if ($p['regra'] === 16) ok($p['bloqueia'] === false, 'aviso 16 nunca bloqueia');
ok(bloqueios($fx, $c) === [], 'aviso 16 nao bloqueia (humano alcancavel basta)');
ok(!array_key_exists(16, avisos_ids($fx, $base)), 'aviso 16 nao dispara com card fim');

// regra 13: caminho misto (um com roteiro, outro sem) ainda bloqueia
$lst2 = card('lst', 'lista', ['config'=>['fonte'=>'roteiros'],'proximo'=>'pdf','fallback'=>'ops','sem_resposta'=>'fim','lembrete_ativo'=>true,'lembrete_texto'=>'Oi?']);
$pdf2 = card('pdf', 'pdf', ['texto'=>'Segue a {roteiro}.','proximo'=>'hum']);
$c = $base; $c[0]['opcoes'][0]['destino'] = 'lst'; $c[] = $lst2; $c[] = $pdf2;
ok(!in_array(13, bloqueios($fx, $c), true), 'regra 13: pdf logo depois de lista de roteiros passa');
ok(!in_array(14, bloqueios($fx, $c), true), 'regra 14: {roteiro} depois da escolha passa');
$c[1]['opcoes'][0]['destino'] = 'pdf';  // ops (fallback da lista) agora pula a escolha
$c[1]['opcoes'][0]['destino'] = 'pdf';
ok(in_array(13, bloqueios($fx, $c), true), 'regra 13: um caminho que pula a lista basta para bloquear');

// regra 14: variaveis validas passam; lembrete desligado nao e conferido
$c = $base; $c[0]['texto'] = 'Oi {nome}, {nome} de novo.';
ok(!in_array(14, bloqueios($fx, $c), true), 'regra 14: {nome} repetido passa');
$c = $base; $c[0]['texto'] = 'Oi {nome';
ok(in_array(14, bloqueios($fx, $c), true), 'regra 14: chave sem par');
$c = $base; $c[0]['lembrete_ativo'] = false; $c[0]['lembrete_texto'] = 'Oi {Nome}'; $c[0]['sem_resposta'] = 'fim';
ok(!in_array(14, bloqueios($fx, $c), true), 'regra 14: lembrete desligado nao e conferido');
$fx_anuncio = array_merge($fx, ['gatilho' => 'anuncio']);
$c = $base; $c[0]['texto'] = 'Sobre {roteiro} em {data}.';
ok(!in_array(14, bloqueios($fx_anuncio, $c), true), 'regra 14: porta anuncio ja traz o roteiro');

// regra 8: card sem continuacao e beco, ainda que nao seja ciclo
$c = $base; $c[0]['opcoes'][0]['destino'] = 'mudo'; $c[] = card('mudo', 'texto');
ok(in_array(8, bloqueios($fx, $c), true), 'regra 8: texto sem proximo e beco sem saida');
$c[4]['proximo'] = 'hum';
ok(!in_array(8, bloqueios($fx, $c), true), 'regra 8: texto que continua ate o humano passa');
$ok9 = [['id'=>'f9','gatilho'=>'chamado','gatilho_valor'=>null,'ativo'=>true,'versao_atual'=>'v9']];
$c = $base; $c[] = card('salto', 'ir_para_fluxo', ['config'=>['fluxo'=>'f9']]); $c[0]['opcoes'][0]['destino'] = 'salto';
ok(!in_array(8, bloqueios($fx, $c, $ok9), true), 'regra 8: ir_para_fluxo conta como saida');

// regra 6: exatamente no limite passa
$c = $base; $c[0]['texto'] = str_repeat('é', 1024);
ok(!in_array(6, bloqueios($fx, $c), true), 'regra 6: 1024 caracteres acentuados passam');
$c[0]['texto'] = str_repeat('a', 1025);
ok(in_array(6, bloqueios($fx, $c), true), 'regra 6: 1025 estouram');
$c = $base; $c[2]['texto'] = str_repeat('a', 4096);
ok(!in_array(6, bloqueios($fx, $c), true), 'regra 6: card humano aceita 4096');

// regra 1: proximo e sem_resposta fantasma; card inicial inexistente
$c = $base; $c[2]['proximo'] = 'fantasma';
ok(in_array(1, bloqueios($fx, $c), true), 'regra 1: proximo inexistente');
$c = $base; $c[0]['sem_resposta'] = 'fantasma';
ok(in_array(1, bloqueios($fx, $c), true), 'regra 1: sem_resposta inexistente');
ok(in_array(1, bloqueios(array_merge($fx, ['card_inicial'=>'nada']), $base), true), 'regra 1: card inicial inexistente');

// regra 3: o card alcancavel so por fallback nao bloqueia (base: ops so e levado pelo fallback do bv)
ok(!in_array(3, bloqueios($fx, $base), true), 'regra 3: alcance por fallback conta');

// regra 4: humano alcancavel so por sem_resposta conta
$c = $base; $c[0]['opcoes'][0]['destino'] = 'fim'; $c[1]['fallback'] = 'fim'; $c[1]['opcoes'][0]['destino'] = 'fim'; $c[0]['sem_resposta'] = 'hum';
ok(!in_array(4, bloqueios($fx, $c), true), 'regra 4: humano alcancavel por sem_resposta conta');

// regra 5: repetido sem distinguir caixa; 3 botoes passam; lista estatica 24 passa e 25 nao
$c = $base; $c[0]['opcoes'][] = ['id'=>'b2','rotulo'=>'FALAR COM A LAIS','destino'=>'fim'];
ok(in_array(5, bloqueios($fx, $c), true), 'regra 5: repetido sem distinguir caixa');
$c = $base; $c[0]['opcoes'] = [['id'=>'a','rotulo'=>'Um','destino'=>'hum'],['id'=>'b','rotulo'=>'Dois','destino'=>'hum'],['id'=>'c','rotulo'=>'Tres','destino'=>'hum']];
ok(!in_array(5, bloqueios($fx, $c), true), 'regra 5: exatamente 3 botoes passam');
$st = card('st', 'lista', ['config'=>['fonte'=>'estatica'],'opcoes'=>[['id'=>'l1','rotulo'=>str_repeat('a', 24),'destino'=>'hum']],'fallback'=>'ops','sem_resposta'=>'fim','lembrete_ativo'=>true,'lembrete_texto'=>'Oi?']);
$c = $base; $c[0]['opcoes'][0]['destino'] = 'st'; $c[] = $st;
ok(!in_array(5, bloqueios($fx, $c), true) && !in_array(7, bloqueios($fx, $c), true), 'regras 5 e 7: lista estatica de 1 linha de 24 caracteres passa');
$c[4]['opcoes'][0]['rotulo'] = str_repeat('a', 25);
ok(in_array(5, bloqueios($fx, $c), true), 'regra 5: titulo de linha com 25 caracteres');

// regra 10: valores invalidos dentro de chave permitida, e campos proibidos
$mrc2 = card('mrc', 'marca', ['texto'=>null,'proximo'=>'hum','config'=>['interesses'=>'+roteiro','qualif_grupo'=>true,'qualif_at'=>true,'espera'=>'+destino']]);
$c = $base; $c[0]['opcoes'][0]['destino'] = 'mrc'; $c[] = $mrc2;
ok(!in_array(10, bloqueios($fx, $c), true), 'regra 10: todas as chaves da whitelist com valores validos passam');
foreach ([['cliente', true], ['revisado', true], ['notas', 'x'], ['venda_at', 'x'], ['qualif_data', 'sim'], ['interesses', 'tudo']] as [$k, $v]) {
    $c[4]['config'] = [$k => $v];
    ok(in_array(10, bloqueios($fx, $c), true), "regra 10: $k=" . json_encode($v));
}

// regra 11: fluxo destino sem versao publicada
$c = $base; $c[0]['opcoes'][0]['destino'] = 'vai'; $c[] = card('vai', 'ir_para_fluxo', ['config'=>['fluxo'=>'f9']]);
ok(in_array(11, bloqueios($fx, $c, [['id'=>'f9','gatilho'=>'chamado','gatilho_valor'=>null,'ativo'=>true,'versao_atual'=>null]]), true), 'regra 11: sem versao publicada');

// regra 9: valor de gatilho diferente nao disputa a porta
$fx_kw = array_merge($fx, ['gatilho' => 'palavra', 'gatilho_valor' => 'natal']);
$o_kw = function ($v) { return [['id'=>'f2','gatilho'=>'palavra','gatilho_valor'=>$v,'ativo'=>true,'versao_atual'=>'v']]; };
ok(in_array(9, bloqueios($fx_kw, $base, $o_kw('natal')), true), 'regra 9: mesma palavra');
ok(!in_array(9, bloqueios($fx_kw, $base, $o_kw('pascoa')), true), 'regra 9: palavra diferente');

// regra 2: so card que espera precisa de lembrete
$c = $base; $c[2]['lembrete_ativo'] = false; $c[2]['sem_resposta'] = null;
ok(!in_array(2, bloqueios($fx, $c), true), 'regra 2: card que nao espera nao precisa de lembrete');

// formato de todo problema; sem emoji nem travessao
foreach (wa_fluxo_valida($fx, (function($b){ $b[0]['texto']=''; $b[0]['opcoes'][0]['destino']='x'; return $b; })($base)) as $p) {
    ok(is_int($p['regra']) && is_bool($p['bloqueia']) && array_key_exists('card', $p), 'formato do problema');
    ok(!preg_match('/\p{So}|\x{2014}|\x{2013}/u', $p['texto']), 'sem emoji nem travessao');
}

// ---- mutantes que sobreviveram a primeira rodada ----
function textos_da_regra($fluxo, $cards, $regra, $outros = []) {
    $t = [];
    foreach (wa_fluxo_valida($fluxo, $cards, $outros) as $p) if ($p['regra'] === $regra) $t[] = $p['texto'];
    return $t;
}
// 4 botoes DISTINTOS: so o limite de 3 pode barrar
$c = $base; $c[0]['opcoes'] = [];
foreach (['A','B','C','D'] as $i => $r) $c[0]['opcoes'][] = ['id'=>"x$i",'rotulo'=>"Opcao $r",'destino'=>'hum'];
ok(in_array(5, bloqueios($fx, $c), true), 'regra 5: 4 botoes distintos passam do limite de 3');
$c = $base; $c[0]['opcoes'] = [];
ok(in_array(5, bloqueios($fx, $c), true), 'regra 5: card de botoes sem nenhum botao');
// descricao de linha de lista
$c = $base; $c[0]['opcoes'][0]['destino'] = 'st'; $c[] = $st;
$c[4]['opcoes'][0]['descricao'] = str_repeat('d', 72);
ok(!in_array(5, bloqueios($fx, $c), true), 'regra 5: descricao de 72 caracteres passa');
$c[4]['opcoes'][0]['descricao'] = str_repeat('d', 73);
ok(in_array(5, bloqueios($fx, $c), true), 'regra 5: descricao de 73 caracteres');
// lembrete grande demais
$c = $base; $c[0]['lembrete_texto'] = str_repeat('a', 4097);
ok(in_array(6, bloqueios($fx, $c), true), 'regra 6: lembrete acima de 4096');
$c[0]['lembrete_texto'] = str_repeat('a', 4096);
ok(!in_array(6, bloqueios($fx, $c), true), 'regra 6: lembrete de 4096 passa');
// lista de roteiros sem proximo, e fonte desconhecida
$c = $base; $c[0]['opcoes'][0]['destino'] = 'lst'; $c[] = $lst; $c[4]['config'] = ['fonte'=>'roteiros']; $c[4]['proximo'] = null;
ok(in_array(7, bloqueios($fx, $c), true), 'regra 7: lista de roteiros sem card seguinte');
$c[4]['proximo'] = 'hum';
ok(!in_array(7, bloqueios($fx, $c), true), 'regra 7: lista de roteiros com card seguinte passa');
$c[4]['config'] = ['fonte'=>'inventada'];
ok(in_array(7, bloqueios($fx, $c), true), 'regra 7: fonte desconhecida');
// regra 11: a mensagem diz o motivo certo
$c = $base; $c[0]['opcoes'][0]['destino'] = 'vai'; $c[] = card('vai', 'ir_para_fluxo', ['config'=>['fluxo'=>'f9']]);
$t = textos_da_regra($fx, $c, 11);
ok(count($t) === 1 && strpos($t[0], 'não existe') !== false, 'regra 11: fluxo inexistente diz que nao existe');
$t = textos_da_regra($fx, $c, 11, [['id'=>'f9','gatilho'=>'chamado','gatilho_valor'=>null,'ativo'=>true,'versao_atual'=>null]]);
ok(count($t) === 1 && strpos($t[0], 'publicado') !== false, 'regra 11: sem versao diz que nao foi publicado');
$t = textos_da_regra($fx, $c, 11, [['id'=>'f9','gatilho'=>'chamado','gatilho_valor'=>null,'ativo'=>false,'versao_atual'=>'v']]);
ok(count($t) === 1 && strpos($t[0], 'desligado') !== false, 'regra 11: desligado diz que esta desligado');

// a funcao e PURA
$antes = $base; wa_fluxo_valida($fx, $base); ok($base === $antes, 'wa_fluxo_valida nao modifica a entrada');

echo "test-wa-fluxo-valida: ok\n";
