<?php
/* A forma do atalho e as duas regras de travessia do validador.
   Tarefa 3 do plano 2026-11-05-robo-entende-a-frase.

   POR QUE ISTO VEM ANTES DA AVALIACAO DO ATALHO: e o que decide se a cliente
   consegue publicar. O validador percorre o grafo por
   opcoes/proximo/fallback/sem_resposta. Sem contar a aresta do atalho:

     - o card "Sem data para este destino" e alcancavel SO por atalho, entao a
       regra 3 (inalcancavel) o reprova e o fluxo desta spec NAO PUBLICA;
     - a regra 13 ("existe roteiro escolhido antes do card de PDF") reprova o
       atalho de roteiro apontando para o PDF, porque ela so reconhece como
       "escolheu" o `proximo` de um card `roteiros`.

   As regras novas de FORMA sao cinco, todas bloqueio de publicacao, sob o
   numero 17. O CLAUDE.md diz que nao se acrescenta regra ao validador sem
   decisao: estas estao decididas na spec A.1.

   POR QUE 17 E NAO 15: os numeros 15 e 16 JA ESTAVAM OCUPADOS por avisos
   ("nenhum outro card leva a este" e "o fluxo nao tem card de fim"). Numero
   repetido com significado diferente faz a tela agrupar um bloqueio junto de
   um aviso. A primeira versao desta tarefa usou 15 e TODOS OS TESTES
   PASSARAM, porque eles olham so a lista de bloqueios e o 15 existente e
   aviso - a colisao era invisivel para eles. Por isso o teste abaixo tambem
   confere que o 15 segue sendo aviso e nunca bloqueio. */
require __DIR__ . '/../lib/wa-fluxo.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

function bloqueios($fluxo, $cards, $outros = []) {
    $out = [];
    foreach (wa_fluxo_valida($fluxo, $cards, $outros) as $p) if ($p['bloqueia']) $out[] = $p['regra'];
    sort($out); return array_values(array_unique($out));
}
function temRegra($fluxo, $cards, $n, $outros = []) {
    return in_array($n, bloqueios($fluxo, $cards, $outros), true);
}
function card($id, $tipo, $extra = []) {
    return array_merge([
        'id' => $id, 'fluxo_id' => 'f1', 'tipo' => $tipo, 'titulo' => $id, 'texto' => 'Texto do ' . $id,
        'opcoes' => [], 'config' => [], 'proximo' => null, 'fallback' => null, 'sem_resposta' => null,
        'lembrete_ativo' => false, 'lembrete_texto' => null,
    ], $extra);
}

$fx = ['id' => 'f1', 'nome' => 'Triagem', 'gatilho' => 'mensagem_nova', 'gatilho_valor' => null,
       'ativo' => false, 'card_inicial' => 'bv'];

/* Base valida minima: botoes -> humano, com fallback e fim. */
$base = [
    card('bv', 'botoes', [
        'opcoes' => [['id' => 'b1', 'rotulo' => 'Falar com a Lais', 'destino' => 'hum']],
        'fallback' => 'ops', 'sem_resposta' => 'fim', 'lembrete_ativo' => true, 'lembrete_texto' => 'Oi?']),
    card('ops', 'botoes', [
        'opcoes' => [['id' => 'v', 'rotulo' => 'Falar com a Lais', 'destino' => 'hum']],
        'fallback' => 'hum', 'lembrete_ativo' => true, 'lembrete_texto' => 'Oi?']),
    card('hum', 'humano'),
    card('fim', 'fim'),
];
ok(bloqueios($fx, $base) === [], 'a base e valida: nenhum bloqueio, senao o resto do arquivo nao prova nada');

/* ---------- wa_atalhos_do_card: a leitura ---------- */
$g = wa_fluxo_grafo_de($fx, $base);
ok(wa_atalhos_do_card($g['cards']['bv']) === [], 'card sem atalho devolve lista vazia');
ok(wa_atalhos_do_card(['config' => ['atalhos' => 'nao e lista']]) === [], 'atalhos que nao e lista devolve vazio');
ok(wa_atalhos_do_card([]) === [], 'card sem config nao estoura');
$um = wa_atalhos_do_card(['config' => ['atalhos' => [
    ['tipo' => 'palavras', 'palavras' => ['atendente'], 'destino' => 'hum', 'saudacao' => 'Oi!'],
]]]);
ok(count($um) === 1 && $um[0]['tipo'] === 'palavras' && $um[0]['destino'] === 'hum', 'le o atalho bem formado');
ok($um[0]['saudacao'] === 'Oi!', 'le a saudacao');
ok(WA_ATALHO_TIPOS === ['palavras', 'roteiro', 'destino'], 'os tres tipos, nesta ordem, e a lista e fechada');

/* ---------- as cinco regras de FORMA (regra 15) ----------
   A Meta nao avisa nada disto: quem avisa e esta tela, antes de publicar. */
function comAtalho($base, $atalhos, $cardId = 'bv') {
    $out = [];
    foreach ($base as $c) {
        if ($c['id'] === $cardId) $c['config'] = ['atalhos' => $atalhos];
        $out[] = $c;
    }
    return $out;
}

ok(temRegra($fx, comAtalho($base, [['tipo' => 'inventado', 'destino' => 'hum']]), 17),
    'FORMA 1: tipo fora da lista fechada bloqueia');
ok(temRegra($fx, comAtalho($base, [['tipo' => 'palavras', 'palavras' => ['atendente'], 'destino' => 'naoexiste']]), 17),
    'FORMA 2: destino inexistente bloqueia (Review Focus 2)');
ok(temRegra($fx, comAtalho($base, [['tipo' => 'palavras', 'palavras' => [], 'destino' => 'hum']]), 17),
    'FORMA 3: tipo palavras com lista vazia bloqueia');
ok(temRegra($fx, comAtalho($base, [['tipo' => 'palavras', 'palavras' => ['ate'], 'destino' => 'hum']]), 17),
    'FORMA 4: palavra de menos de 4 caracteres normalizados bloqueia');
ok(temRegra($fx, comAtalho($base, [['tipo' => 'palavras', 'palavras' => ['ATÉ!'], 'destino' => 'hum']]), 17),
    'FORMA 4: e conta DEPOIS de normalizar, senao acento e pontuacao enganariam a contagem');
ok(temRegra($fx, comAtalho($base, [
        ['tipo' => 'roteiro', 'destino' => 'hum'], ['tipo' => 'roteiro', 'destino' => 'fim']]), 17),
    'FORMA 5: dois atalhos de roteiro no mesmo card bloqueia');
ok(temRegra($fx, comAtalho($base, [
        ['tipo' => 'destino', 'destino' => 'hum'], ['tipo' => 'destino', 'destino' => 'fim']]), 17),
    'FORMA 5: dois atalhos de destino tambem');
ok(!temRegra($fx, comAtalho($base, [
        ['tipo' => 'palavras', 'palavras' => ['atendente'], 'destino' => 'hum'],
        ['tipo' => 'palavras', 'palavras' => ['humano'], 'destino' => 'hum']]), 17),
    'FORMA 5 nao vale para `palavras`: varios grupos de palavra no mesmo card e uso legitimo');
ok(!temRegra($fx, comAtalho($base, [
        ['tipo' => 'palavras', 'palavras' => ['atendente'], 'destino' => 'hum'],
        ['tipo' => 'roteiro', 'destino' => 'hum'],
        ['tipo' => 'destino', 'destino' => 'hum']]), 17),
    'um de cada tipo no mesmo card e a configuracao recomendada, e nao bloqueia');

/* ---------- TRAVESSIA 1: a aresta do atalho conta no alcance ----------
   Sem isto o fluxo da spec nao publica. */
$comOrfao = array_merge($base, [card('semdata', 'botoes', [
    'opcoes' => [['id' => 's', 'rotulo' => 'Sim', 'destino' => 'fim']],
    'fallback' => 'hum', 'lembrete_ativo' => true, 'lembrete_texto' => 'Oi?'])]);
ok(temRegra($fx, $comOrfao, 3),
    'card que NINGUEM alcanca segue sendo reprovado: a regra 3 nao foi relaxada');
ok(!temRegra($fx, comAtalho($comOrfao, [['tipo' => 'destino', 'destino' => 'semdata']]), 3),
    'mas alcancavel SO por atalho deixa de ser inalcancavel');

/* E o beco sem saida passa a valer para ele, porque agora ele esta no alcance. */
$becoPorAtalho = array_merge($base, [card('preso', 'botoes', [
    'opcoes' => [], 'fallback' => null, 'lembrete_ativo' => true, 'lembrete_texto' => 'Oi?'])]);
ok(!temRegra($fx, $becoPorAtalho, 8),
    'card fora do alcance NAO e conferido por beco sem saida (controle)');
ok(temRegra($fx, comAtalho($becoPorAtalho, [['tipo' => 'destino', 'destino' => 'preso']]), 8),
    'card alcancado por atalho e sem saida e beco sem saida: entrar no alcance traz a regra 8 junto');

/* ---------- TRAVESSIA 2: atalho de roteiro conta como "escolheu" ----------
   Review Focus: o PDF sem roteiro no contexto nao tem o que mandar. */
$comPdf = array_merge($base, [card('pdf', 'pdf', ['proximo' => 'fim'])]);
ok(temRegra($fx, $comPdf, 3), 'o card de PDF solto e inalcancavel (controle)');

$pdfPorRoteiro  = comAtalho($comPdf, [['tipo' => 'roteiro',  'destino' => 'pdf']]);
$pdfPorDestino  = comAtalho($comPdf, [['tipo' => 'destino',  'destino' => 'pdf']]);
$pdfPorPalavras = comAtalho($comPdf, [['tipo' => 'palavras', 'palavras' => ['atendente'], 'destino' => 'pdf']]);

ok(!temRegra($fx, $pdfPorRoteiro, 13),
    'atalho de ROTEIRO conta como roteiro escolhido: o PDF tem o que mandar');
ok(temRegra($fx, $pdfPorDestino, 13),
    'atalho de DESTINO nao conta: destino nao tem slug, e o PDF sairia sem roteiro');
ok(temRegra($fx, $pdfPorPalavras, 13),
    'atalho de PALAVRAS nao conta pelo mesmo motivo');
ok(!temRegra($fx, $pdfPorRoteiro, 3) && !temRegra($fx, $pdfPorDestino, 3),
    'os tres tipos contam no ALCANCE, mesmo os que nao contam como roteiro escolhido');

/* ---------- a trava da colisao de numero ----------
   O 15 e o 16 sao AVISOS que ja existiam. Se alguem reaproveitar um deles
   para um bloqueio novo, a tela do painel passa a mostrar um impedimento de
   publicacao junto de uma observacao de desenho. */
function avisos_de($fluxo, $cards) {
    $out = [];
    foreach (wa_fluxo_valida($fluxo, $cards) as $p) if (!$p['bloqueia']) $out[] = $p['regra'];
    return array_values(array_unique($out));
}
$soltos = array_merge($base, [card('ninguem', 'fim')]);
ok(in_array(15, avisos_de($fx, $soltos), true), '15 segue sendo AVISO: card que ninguem liga');
ok(!temRegra($fx, $soltos, 15), 'e nunca bloqueio');
ok(!in_array(17, avisos_de($fx, comAtalho($base, [['tipo' => 'xx', 'destino' => 'hum']])), true),
    '17 e sempre bloqueio, nunca aviso');

/* E a aresta de atalho tambem conserta o aviso 15, de graca, porque ele e
   calculado pelo MESMO $saidas: card alcancado por atalho deixa de ser
   "trabalho esquecido no desenho". */
$orfaoFim = array_merge($base, [card('semdata2', 'fim')]);
ok(in_array(15, avisos_de($fx, $orfaoFim), true), 'sem atalho, o card avulso recebe o aviso 15');
ok(!in_array(15, avisos_de($fx, comAtalho($orfaoFim, [['tipo' => 'destino', 'destino' => 'semdata2']])), true),
    'com atalho apontando para ele, o aviso 15 cessa');

echo "OK test-wa-atalho-valida\n";
