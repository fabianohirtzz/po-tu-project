<?php
require __DIR__ . '/../lib/wa-fluxo.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

/* Quase todo desvio deste arquivo loga de proposito (error_log). Com dezenas de
   linhas na saida, um warning de PHP de verdade passaria sem ninguem ver: aqui
   o log vai para um arquivo e a saida do teste fica so com o que e do teste. */
ini_set('error_log', sys_get_temp_dir() . '/po-test-wa-fluxo-executa.log');

function card($id, $tipo, $extra = []) {
    return array_merge(['id' => $id, 'tipo' => $tipo, 'titulo' => $id, 'texto' => 'oi',
        'opcoes' => [], 'config' => [], 'proximo' => null, 'fallback' => null,
        'sem_resposta' => null, 'lembrete_ativo' => false, 'lembrete_texto' => null], $extra);
}

$g = ['id' => 'f1', 'nome' => 'T', 'inicial' => 'txt', 'humano' => 'hum', 'porta' => 'mensagem_nova', 'porta_valor' => null, 'cards' => [
  'txt'      => card('txt', 'texto', ['texto' => 'Ola, {nome}!', 'proximo' => 'hum']),
  'dt'       => card('dt', 'botoes', ['texto' => 'A {roteiro} sai em {data}. Funciona?', 'opcoes' => [
                  ['id' => 's', 'rotulo' => 'Sim, funciona', 'destino' => 'hum'],
                  ['id' => 'n', 'rotulo' => 'Nao nessa data', 'destino' => 'hum']]]),
  'lst'      => card('lst', 'lista', ['texto' => 'Qual viagem?', 'config' => ['fonte' => 'roteiros'], 'proximo' => 'pdf']),
  'prog2027' => card('prog2027', 'lista', ['texto' => 'Qual viagem?', 'config' => ['fonte' => 'roteiros', 'ano' => '2027'], 'proximo' => 'pdf']),
  'est'      => card('est', 'lista', ['texto' => 'Escolha', 'config' => ['fonte' => 'estatica', 'botao' => 'Escolher'], 'opcoes' => [
                  ['id' => 'a', 'rotulo' => 'Uma linha com titulo muito comprido mesmo', 'descricao' => 'd', 'destino' => 'hum']]]),
  'pdf'      => card('pdf', 'pdf', ['texto' => 'Segue o {roteiro}', 'proximo' => 'hum']),
  'mrc'      => card('mrc', 'marca', ['config' => ['interesses' => '+roteiro', 'status' => 'atendimento'], 'proximo' => 'hum']),
  'mrcv'     => card('mrcv', 'marca', ['config' => ['status' => 'venda'], 'proximo' => 'hum']),
  'vai'      => card('vai', 'ir_para_fluxo', ['config' => ['fluxo' => 'outro']]),
  'hum'      => card('hum', 'humano', ['texto' => 'Ja chamo a Lais.']),
  'fim'      => card('fim', 'fim', ['texto' => '']),
]];

$ENVIADAS = []; $ESCRITAS = []; $LOG = []; $LEAD = [['interesses' => ['chile'], 'espera' => []]];
$ROTEIROS = [['slug' => 'turquia', 'titulo' => 'Turquia com Antalia', 'data_label' => '', 'periodo' => '27/10/27 a 12/11/27', 'pdf_url' => 'https://exemplo/t.pdf']];
$salva = $ROTEIROS;
$OK = true;
wa_fluxo_set_deps([
  'roteiros'       => function () use (&$ROTEIROS) { return $ROTEIROS; },
  'send_text'      => function ($p, $t) use (&$ENVIADAS, &$OK) { $ENVIADAS[] = ['text', $t]; return $OK ? ['ok' => true, 'wamid' => 'w1'] : ['ok' => false, 'wamid' => null, 'erro' => 'rede']; },
  'send_buttons'   => function ($p, $c, $b) use (&$ENVIADAS, &$OK) { $ENVIADAS[] = ['buttons', $c, $b]; return $OK ? ['ok' => true, 'wamid' => 'w2'] : ['ok' => false, 'wamid' => null, 'erro' => 'rede']; },
  'send_list'      => function ($p, $c, $bt, $i) use (&$ENVIADAS, &$OK) { $ENVIADAS[] = ['list', $c, $i, $bt]; return $OK ? ['ok' => true, 'wamid' => 'w3'] : ['ok' => false, 'wamid' => null, 'erro' => 'rede']; },
  'send_doc'       => function ($p, $u, $a, $l) use (&$ENVIADAS, &$OK) { $ENVIADAS[] = ['doc', $u, $l, $a]; return $OK ? ['ok' => true, 'wamid' => 'w4'] : ['ok' => false, 'wamid' => null, 'erro' => 'rede']; },
  'insert'         => function ($t, $c) use (&$LOG) { $LOG[] = [$t, $c]; return true; },
  'update'         => function ($t, $q, $c) use (&$ESCRITAS) { $ESCRITAS[] = [$t, $c]; return true; },
  'select'         => function ($t, $q) use (&$LEAD) { return $t === 'po_leads' ? $LEAD : []; },
  'select_estrito' => function ($t, $q) use (&$LEAD) { return $t === 'po_leads' ? $LEAD : []; },
]);
$fone = '+5548900000001';
$c0 = ['roteiro_slug' => null, 'tentativas' => 0];
$ct = ['roteiro_slug' => 'turquia', 'tentativas' => 0];
function zera() { global $ENVIADAS, $ESCRITAS, $LOG; $ENVIADAS = []; $ESCRITAS = []; $LOG = []; }

// 1. botoes: corpo com variaveis, rotulos na ordem, e ESPERA
$r = wa_fluxo_executa($g, 'dt', $fone, $ct, 'Marta');
ok($r['ok'] === true && $r['espera'] === true && $r['wamid'] === 'w2', 'card botoes espera resposta');
ok($ENVIADAS[0][0] === 'buttons', 'card botoes usa send_buttons');
ok(strpos($ENVIADAS[0][1], 'Turquia com Antalia') !== false, 'o {roteiro} foi substituido');
ok(strpos($ENVIADAS[0][1], '27/10/27') !== false, 'o {data} veio do periodo, nao vazio');
ok($ENVIADAS[0][2][0]['rotulo'] === 'Sim, funciona' && $ENVIADAS[0][2][1]['id'] === 'n', 'rotulos e ids na ordem das opcoes');

// 2. texto: manda e NAO espera
zera();
$r = wa_fluxo_executa($g, 'txt', $fone, $c0, 'Marta');
ok($r['ok'] === true && $r['espera'] === false && $r['terminal'] === null, 'card texto nao espera');
ok($ENVIADAS[0] === ['text', 'Ola, Marta!'], 'card texto usa send_text com o nome');

// 3. pdf: manda o documento com legenda, nao espera
zera();
$r = wa_fluxo_executa($g, 'pdf', $fone, $ct, 'Marta');
ok($ENVIADAS[0][0] === 'doc' && $ENVIADAS[0][1] === 'https://exemplo/t.pdf', 'manda o pdf_url do roteiro');
ok($ENVIADAS[0][2] === 'Segue o Turquia com Antalia' && $ENVIADAS[0][3] === 'turquia.pdf', 'legenda com a variavel e arquivo pelo slug');
ok($r['ok'] === true && $r['espera'] === false, 'card pdf nao espera');

/* 4. pdf sem roteiro: o catalogo VAZIO e o catalogo CHEIO sem o slug sao coisas
   diferentes, e o card tem que separa-las. po_fetch_roteiros devolve [] em erro,
   entao "vazio" tambem e o Supabase fora do ar ou o cache frio: tratar isso como
   permanente entregaria a humana (que nunca devolve a conversa ao robo) quem
   acabou de escolher o roteiro. Catalogo cheio sem o slug e permanente mesmo:
   o roteiro saiu do ar (Review Focus 5). */
zera(); $ROTEIROS = [];
$r = wa_fluxo_executa($g, 'pdf', $fone, $ct, 'Marta');
ok($r['ok'] === false && $r['terminal'] === null, 'catalogo vazio (Supabase fora) e transitorio: nao entrega a conversa a humana');
ok($r['erro'] !== null && $ENVIADAS === [], 'nada e enviado, e o erro fica registrado para a proxima tentativa');
ok($r['ctx']['roteiro_slug'] === 'turquia', 'e o slug escolhido NAO e descartado: o catalogo vazio nao prova nada');
// catalogo cheio mas sem o slug: permanente, vai para a humana, e o ctx esquece o slug fantasma
zera();
$ROTEIROS = [['slug' => 'outro', 'titulo' => 'Outro', 'periodo' => '', 'pdf_url' => 'u']];
$r = wa_fluxo_executa($g, 'pdf', $fone, $ct, 'Marta');
ok($r['ok'] === false && $r['terminal'] === 'humano', 'roteiro que saiu do ar (catalogo cheio, slug ausente) vai para o humano');
ok($ENVIADAS === [], 'nenhum documento e enviado');
$r = wa_fluxo_executa($g, 'pdf', $fone, ['roteiro_slug' => 'b2', 'tentativas' => 0], 'Marta');
ok($r['terminal'] === 'humano' && $r['ctx']['roteiro_slug'] === null, 'slug desconhecido (botao antigo) e descartado do contexto');
$ROTEIROS = $salva;

// 5. lista dinamica com catalogo vazio cai em TEXTO
zera(); $ROTEIROS = [];
$r = wa_fluxo_executa($g, 'lst', $fone, $c0, 'Marta');
ok($ENVIADAS[0] === ['text', 'Qual viagem?'], 'catalogo vazio manda o corpo como texto puro');
ok($r['ok'] === true && $r['espera'] === true, 'e segue esperando a resposta');

// 6. lista dinamica: ate 10 linhas, id = slug, titulo ate 24 caracteres, descricao ate 72
zera(); $ROTEIROS = [];
for ($i = 0; $i < 12; $i++) $ROTEIROS[] = ['slug' => "r$i", 'titulo' => "Roteiro de titulo muito comprido numero $i", 'data_label' => '', 'periodo' => '01/01/27 a 10/01/27', 'pdf_url' => 'u'];
$r = wa_fluxo_executa($g, 'lst', $fone, $c0, 'Marta');
ok($ENVIADAS[0][0] === 'list' && count($ENVIADAS[0][2]) === 10, 'no maximo 10 linhas');
ok($ENVIADAS[0][2][0]['id'] === 'r0', 'o id da linha e o slug');
foreach ($ENVIADAS[0][2] as $l) ok(mb_strlen($l['titulo'], 'UTF-8') <= 24, 'titulo de linha ate 24 caracteres');
ok(mb_strlen($ENVIADAS[0][2][0]['titulo'], 'UTF-8') > 0, 'titulo cortado nao some');
$ROTEIROS[0]['periodo'] = str_repeat('x', 100);
zera(); wa_fluxo_executa($g, 'lst', $fone, $c0, 'Marta');
ok(mb_strlen($ENVIADAS[0][2][0]['descricao'], 'UTF-8') <= 72, 'descricao de linha ate 72 caracteres');

// 7. filtro de ano pelo po_ano
zera();
$ROTEIROS = [['slug' => 'a', 'titulo' => 'A', 'data_label' => '', 'periodo' => '01/01/27 a 10/01/27', 'pdf_url' => 'u'],
             ['slug' => 'b', 'titulo' => 'B', 'data_label' => '', 'periodo' => '01/01/28 a 10/01/28', 'pdf_url' => 'u']];
wa_fluxo_executa($g, 'prog2027', $fone, $c0, 'Marta');
ok(count($ENVIADAS[0][2]) === 1 && $ENVIADAS[0][2][0]['id'] === 'a', 'o filtro de ano usa po_ano sobre periodo');
// filtro que zera a lista: texto, nunca lista vazia
zera(); $ROTEIROS = [$ROTEIROS[1]];
wa_fluxo_executa($g, 'prog2027', $fone, $c0, 'Marta');
ok($ENVIADAS[0][0] === 'text', 'filtro de ano sem resultado cai em texto');
$ROTEIROS = $salva;

// 7b. lista estatica: titulo cortado em 24, botao da config
zera();
wa_fluxo_executa($g, 'est', $fone, $c0, 'Marta');
ok($ENVIADAS[0][0] === 'list' && $ENVIADAS[0][3] === 'Escolher', 'lista estatica usa o botao da config');
ok($ENVIADAS[0][2][0]['id'] === 'a' && mb_strlen($ENVIADAS[0][2][0]['titulo'], 'UTF-8') <= 24, 'lista estatica corta o titulo em 24');

// 8. texto vazio: recusa, nada sai, nada avanca
foreach (['txt', 'dt', 'lst'] as $id) {
    zera();
    $g2 = $g; $g2['cards'][$id]['texto'] = '   ';
    $r = wa_fluxo_executa($g2, $id, $fone, $c0, 'Marta');
    ok($r['ok'] === false && $r['erro'] !== null, "texto vazio no card $id nao da ok");
    ok($ENVIADAS === [] && $LOG === [], "texto vazio no card $id nao chega ao envio");
    ok($r['terminal'] === 'humano', "recusa permanente no card $id entrega a conversa a humana, nao a deixa muda");
}
// texto que so tinha variavel vazia
zera(); $g2 = $g; $g2['cards']['txt']['texto'] = '{roteiro}';
$r = wa_fluxo_executa($g2, 'txt', $fone, $c0, 'Marta');
ok($r['ok'] === false && $ENVIADAS === [], 'texto que expande para vazio tambem e recusado');
ok($r['terminal'] === 'humano', 'e tambem vai para a humana (passa na validacao, que olha o texto cru)');
// card de tipo desconhecido: recusa permanente tambem
zera(); $g2 = $g; $g2['cards']['txt']['tipo'] = 'inventado';
$r = wa_fluxo_executa($g2, 'txt', $fone, $c0, 'Marta');
ok($r['ok'] === false && $r['terminal'] === 'humano' && $ENVIADAS === [], 'tipo desconhecido vai para a humana');

// 9. LEI: envio que falhou nunca devolve ok, nem registra saida
foreach (['txt' => $c0, 'dt' => $ct, 'lst' => $c0, 'pdf' => $ct, 'hum' => $c0] as $id => $cx) {
    zera(); $OK = false;
    $r = wa_fluxo_executa($g, $id, $fone, $cx, 'Marta');
    ok($r['ok'] === false && $r['wamid'] === null && $r['erro'] !== null, "envio falhado no card $id nao da ok nem wamid");
    // ok diz se saiu; terminal diz para onde vai a conversa: o humano segue terminal mesmo sem o aviso.
    ok($r['terminal'] === ($id === 'hum' ? 'humano' : null), "terminal do card $id independe de o envio ter saido");
    ok($LOG === [], "envio falhado no card $id nao entra no log");
    $OK = true;
}

// 9b. ok sem wamid tambem nao conta como enviado (sem wamid o eco nao se reconhece)
zera();
wa_fluxo_set_deps(['send_text' => function () { return ['ok' => true, 'wamid' => null]; }]);
$r = wa_fluxo_executa($g, 'txt', $fone, $c0, 'Marta');
ok($r['ok'] === false && $r['wamid'] === null, 'ok sem wamid nao avanca');
wa_fluxo_set_deps(['send_text' => function ($p, $t) use (&$ENVIADAS) { $ENVIADAS[] = ['text', $t]; return ['ok' => true, 'wamid' => 'w1']; }]);

// 10. marca: so a whitelist, e interesses ACRESCENTA
zera();
$r = wa_fluxo_executa($g, 'mrc', $fone, $ct, 'Marta');
$campos = null;
foreach ($ESCRITAS as $e) if ($e[0] === 'po_leads') $campos = $e[1];
ok($campos !== null, 'card marca escreve em po_leads');
ok($campos['interesses'] === ['chile', 'turquia'], 'interesses ACRESCENTA, nao substitui');
ok($campos['status'] === 'atendimento', 'status permitido e gravado');
ok(array_diff(array_keys($campos), WA_FLUXO_CAMPOS_MARCAVEIS) === [], 'nenhuma chave fora da whitelist chega ao banco');
ok($r['ok'] === true && $r['espera'] === false && $ENVIADAS === [], 'card marca nao espera e nao envia');
$LEAD = [['interesses' => ['turquia'], 'espera' => []]]; zera();
wa_fluxo_executa($g, 'mrc', $fone, $ct, 'Marta');
ok($ESCRITAS[0][1]['interesses'] === ['turquia'], 'interesse repetido nao duplica');
// status venda nunca passa
zera(); $LEAD = [['interesses' => [], 'espera' => []]];
wa_fluxo_executa($g, 'mrcv', $fone, $ct, 'Marta');
ok($ESCRITAS === [], 'status fora da lista nao escreve nada');
// slug fantasma nao vira interesse
zera(); $ROTEIROS = [['slug' => 'outro', 'titulo' => 'O', 'periodo' => '', 'pdf_url' => 'u']];
wa_fluxo_executa($g, 'mrc', $fone, ['roteiro_slug' => 'b2', 'tentativas' => 0], 'Marta');
ok(!isset($ESCRITAS[0][1]['interesses']), 'slug que nao esta no catalogo nao vira interesse');
$ROTEIROS = $salva;
// leitura que falhou nao pode apagar o que ja estava na ficha
zera();
wa_fluxo_set_deps(['select_estrito' => function () { return null; }]);
wa_fluxo_executa($g, 'mrc', $fone, $ct, 'Marta');
foreach ($ESCRITAS as $e) ok(!isset($e[1]['interesses']), 'leitura com erro nao grava interesses por cima do que existe');
ok(($ESCRITAS[0][1]['status'] ?? null) === 'atendimento', 'o resto da marca ainda e gravado');
wa_fluxo_set_deps(['select_estrito' => function ($t, $q) use (&$LEAD) { return $LEAD; }]);

// 10b. marca: gravacao que falha NAO bloqueia a conversa, mas avisa
zera(); $LEAD = [['interesses' => [], 'espera' => []]];
wa_fluxo_set_deps(['update' => function () { return false; }]);
$r = wa_fluxo_executa($g, 'mrc', $fone, $ct, 'Marta');
ok($r['ok'] === true && $r['erro'] !== null, 'gravacao falha: ok=true com erro preenchido');
wa_fluxo_set_deps(['update' => function ($t, $q, $c) use (&$ESCRITAS) { $ESCRITAS[] = [$t, $c]; return true; }]);
$r = wa_fluxo_executa($g, 'mrc', $fone, $ct, 'Marta');
ok($r['ok'] === true && $r['erro'] === null, 'gravacao ok: ok=true sem erro');

// 11. terminais
zera();
$r = wa_fluxo_executa($g, 'hum', $fone, $c0, 'M');
ok($r['terminal'] === 'humano' && $r['ok'] === true && $ENVIADAS[0] === ['text', 'Ja chamo a Lais.'], 'card humano manda o aviso e e terminal humano');
zera();
$r = wa_fluxo_executa($g, 'fim', $fone, $c0, 'M');
ok($r['terminal'] === 'fim' && $r['ok'] === true && $ENVIADAS === [], 'card fim sem texto e silencioso e terminal fim');

// 12. ir_para_fluxo nao envia nada
zera();
$r = wa_fluxo_executa($g, 'vai', $fone, $ct, 'M');
ok($r['ok'] === true && $r['wamid'] === null && $r['espera'] === false && $r['terminal'] === null, 'ir_para_fluxo devolve o vazio');
ok($ENVIADAS === [] && $ESCRITAS === [] && $LOG === [], 'ir_para_fluxo nao envia nem escreve');

// 13. toda saida do robo entra no log com o wamid, senao o eco viraria handoff
zera();
wa_fluxo_executa($g, 'txt', $fone, $c0, 'Marta');
wa_fluxo_executa($g, 'dt', $fone, $ct, 'Marta');
wa_fluxo_executa($g, 'lst', $fone, $c0, 'Marta');
wa_fluxo_executa($g, 'pdf', $fone, $ct, 'Marta');
ok(array_column(array_column($LOG, 1), 'wamid') === ['w1', 'w2', 'w3', 'w4'], 'text, buttons, list e document registram a saida');
ok(array_column(array_column($LOG, 1), 'tipo') === ['text', 'buttons', 'list', 'document'], 'o tipo do log acompanha o envio');

// 14. um dono so para o dominio do link: o motor nao redefine WA_SITE
ok(!preg_match('/^\s*const\s+WA_SITE\b/m', file_get_contents(__DIR__ . '/../lib/wa-motor.php')), 'wa-motor.php nao define WA_SITE');
ok(WA_SITE === 'https://pereiraoliveiraturismo.com.br', 'WA_SITE vem do wa-fluxo.php');

// 15. roteiro sem PDF: legenda + link da pagina, montado com o WA_SITE
zera(); $ROTEIROS[0]['pdf_url'] = '';
$r = wa_fluxo_executa($g, 'pdf', $fone, $ct, 'Marta');
ok($r['ok'] === true && substr($ENVIADAS[0][1], -strlen(WA_SITE . '/roteiros/turquia')) === WA_SITE . '/roteiros/turquia', 'sem PDF manda o link da pagina com o WA_SITE');
$ROTEIROS = $salva;

// card que nao existe
$r = wa_fluxo_executa($g, 'nao-existe', $fone, $c0, 'M');
ok($r['ok'] === false && $r['erro'] !== null, 'card inexistente nao da ok');

echo "test-wa-fluxo-executa: ok\n";
