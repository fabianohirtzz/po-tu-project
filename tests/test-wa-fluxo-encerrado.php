<?php
/* O robo para de VENDER o roteiro 15 dias antes da saida, e quem pede um
   roteiro que saiu da venda ouve que ele encerrou e ganha a lista de espera.
   Pedido do cliente em 16/11/2026:

     "no robo, ele nao pode mostrar roteiros 15 dias antes da data de viagem"
     "no roteiro que ja saiu do robo, mas ainda aparece no site, a gente
      oferta uma lista de espera e diz que esse roteiro ja encerrou"

   O SITE continua mostrando o roteiro ate a saida: a regra dos 15 dias e so
   do robo, e por isso mora em wa_fluxo_roteiros_venda e nao na leitura do
   banco (po_fetch_roteiros), que e compartilhada com o site.

   "Exatamente 15 dias" (Fabiano, 16/11): com saida em 25/11, o roteiro some
   da lista do robo no dia 10/11. */
require __DIR__ . '/../lib/wa-fluxo.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

$HOJE = '2026-11-10';
$em = function ($dias) use ($HOJE) { return gmdate('d/m/Y', strtotime($HOJE . ' +' . $dias . ' days')); };
$r = function ($slug, $titulo, $periodo, $extra = []) {
    return array_merge(['slug' => $slug, 'titulo' => $titulo, 'ativo' => true, 'periodo' => $periodo,
                        'status_viagem' => null, 'datas_texto' => [], 'pdf_url' => 'x.pdf'], $extra);
};

/* ---------- 1. a lista de VENDA do robo ---------- */
$chile  = $r('chile-e-deserto-do-atacama', 'Chile e Deserto do Atacama', '25/11/26 a 02/12/26');
$cat = [
    $chile,                                                                       // sai em 15 dias
    $r('quatorze', 'Quatorze Dias', $em(14) . ' a ' . $em(20)),
    $r('dezesseis', 'Dezesseis Dias', $em(16) . ' a ' . $em(25)),
    $r('peru-fascinante', 'Peru Fascinante', '', ['status_viagem' => 'semanal',
        'datas_texto' => [['titulo' => 'Nov', 'datas' => [gmdate('d/m/y', strtotime($HOJE . ' +3 days'))]]]]),
    $r('sem-data', 'Sem Data', 'em breve'),
    $r('turquia', 'Turquia com Antalia', 'De 27/10/26 a 12/11/26', ['status_viagem' => 'em_viagem']),
    $r('tesouros-do-oriente', 'Tesouros do Oriente', 'De 18/03/27 a 09/04/27', ['status_viagem' => 'esgotado']),
];
$venda = array_column(wa_fluxo_roteiros_venda($cat, $HOJE), 'slug');
ok(!in_array('chile-e-deserto-do-atacama', $venda, true), 'faltando EXATAMENTE 15 dias o roteiro ja saiu da venda do robo');
ok(!in_array('quatorze', $venda, true), 'faltando 14 dias tambem');
ok(in_array('dezesseis', $venda, true), 'faltando 16 dias ainda esta a venda');
ok(in_array('peru-fascinante', $venda, true), 'semanal nunca sai pela regra dos 15 dias (sai toda semana)');
ok(in_array('sem-data', $venda, true), 'roteiro sem data continua a venda');
ok(!in_array('turquia', $venda, true) && !in_array('tesouros-do-oriente', $venda, true), 'em viagem e esgotado continuam fora');
ok(in_array('chile-e-deserto-do-atacama', array_column(wa_fluxo_roteiros_venda($cat, '2026-11-09'), 'slug'), true),
   'no dia 09/11 (faltando 16) o Chile ainda estava a venda');
ok(WA_FLUXO_DIAS_ANTES === 15, 'o prazo e de 15 dias');

/* ---------- 2. os ENCERRADOS: no site, fora da venda do robo ---------- */
$enc = array_column(wa_fluxo_roteiros_encerrados($cat, $HOJE), 'slug');
sort($enc);
ok($enc === ['chile-e-deserto-do-atacama', 'quatorze', 'tesouros-do-oriente', 'turquia'],
   'encerrado = esta no site mas saiu da venda (deu: ' . implode(',', $enc) . ')');
ok(!in_array('sem-data', $enc, true) && !in_array('peru-fascinante', $enc, true), 'quem esta a venda nunca e encerrado');
$ocultoCat = [$r('oculto', 'Oculto', '20/11/26 a 30/11/26', ['ativo' => false])];
ok(wa_fluxo_roteiros_encerrados($ocultoCat, $HOJE) === [], 'roteiro oculto no painel nao e oferecido como encerrado');

/* ---------- 3. o atalho `encerrado` ---------- */
ok(WA_ATALHO_TIPOS === ['palavras', 'roteiro', 'destino', 'encerrado'], 'o tipo novo entra no fim da lista fechada');

$DESTINOS = [['nome' => 'Chile', 'palavras' => 'chile, santiago, atacama'],
             ['nome' => 'Turquia', 'palavras' => 'turquia, istambul']];
wa_fluxo_set_deps([
    'roteiros' => function () use (&$cat) { return $cat; },
    'destinos' => function () use ($DESTINOS) { return $DESTINOS; },
    'hoje'     => function () use (&$HOJE) { return $HOJE; },
]);
function card($id, $tipo, $extra = []) {
    return array_merge([
        'id' => $id, 'fluxo_id' => 'f1', 'tipo' => $tipo, 'titulo' => $id, 'texto' => 'T ' . $id,
        'opcoes' => [], 'config' => [], 'proximo' => null, 'fallback' => null, 'sem_resposta' => null,
        'lembrete_ativo' => false, 'lembrete_texto' => null,
    ], $extra);
}
$fx = ['id' => 'f1', 'nome' => 'T', 'gatilho' => 'mensagem_nova', 'gatilho_valor' => null,
       'ativo' => false, 'card_inicial' => 'bv'];
$cards = [
    card('bv', 'botoes', [
        'opcoes'  => [['id' => 'b1', 'rotulo' => 'Ver roteiros', 'destino' => 'fim']],
        'config'  => ['atalhos' => [
            ['tipo' => 'roteiro',   'destino' => 'pdf'],
            ['tipo' => 'encerrado', 'destino' => 'enc'],
            ['tipo' => 'destino',   'destino' => 'semdata'],
        ]],
        'fallback' => 'fim', 'sem_resposta' => 'fim', 'lembrete_ativo' => true, 'lembrete_texto' => 'Oi?']),
    card('pdf', 'pdf', ['proximo' => 'fim']),
    card('enc', 'botoes', [
        'texto'  => 'As vendas do {roteiro} encerraram. Quer entrar na lista de espera para {destino}?',
        'opcoes' => [['id' => 's', 'rotulo' => 'Sim', 'destino' => 'fim']],
        'fallback' => 'fim', 'sem_resposta' => 'fim', 'lembrete_ativo' => true, 'lembrete_texto' => 'Oi?']),
    card('semdata', 'botoes', [
        'texto'  => 'Ainda nao temos data para {destino}.',
        'opcoes' => [['id' => 's', 'rotulo' => 'Sim', 'destino' => 'fim']],
        'fallback' => 'fim', 'sem_resposta' => 'fim', 'lembrete_ativo' => true, 'lembrete_texto' => 'Oi?']),
    card('fim', 'fim'),
];
$g = wa_fluxo_grafo_de($fx, $cards);
$ctx0 = ['roteiro_slug' => null, 'tentativas' => 0];

$x = wa_fluxo_proximo($g, 'bv', '', $ctx0, 'quero o chile');
ok($x['card'] === 'enc' && $x['atalho_tipo'] === 'encerrado', 'quem pede o Chile ja fechado vai para o card de encerrado');
ok($x['ctx']['roteiro_slug'] === 'chile-e-deserto-do-atacama', 'o roteiro fica no contexto ({roteiro} e o PDF funcionam)');
ok($x['ctx']['destino_nome'] === 'Chile', 'e o DESTINO tambem, para a lista de espera agrupar com quem pediu o Chile');

$x = wa_fluxo_proximo($g, 'bv', '', $ctx0, 'turquia');
ok($x['card'] === 'enc' && $x['ctx']['destino_nome'] === 'Turquia', 'roteiro em viagem tambem e encerrado');

$x = wa_fluxo_proximo($g, 'bv', '', $ctx0, 'tesouros do oriente');
ok($x['card'] === 'enc' && $x['ctx']['destino_nome'] === 'Tesouros do Oriente',
   'sem destino da casa no titulo, a espera leva o nome do roteiro');

$cat[] = $r('coreia-do-sul-japao-dubai', 'Tesouros do Oriente', 'De 18/03/27 a 09/04/27', ['status_viagem' => 'esgotado']);
$DESTINOS2 = array_merge($DESTINOS, [['nome' => 'Japão', 'palavras' => 'japao, toquio']]);
wa_fluxo_set_deps(['destinos' => function () use ($DESTINOS2) { return $DESTINOS2; }]);
$x = wa_fluxo_proximo($g, 'bv', '', $ctx0, 'quero ir pro japao');
ok($x['card'] === 'enc' && $x['ctx']['destino_nome'] === 'Japão',
   'o destino da espera e o que a PESSOA escreveu, mesmo com o roteiro chamado Tesouros do Oriente');
array_pop($cat);

$x = wa_fluxo_proximo($g, 'bv', '', $ctx0, 'dezesseis dias');
ok($x['card'] === 'pdf', 'roteiro ainda a venda segue pelo atalho de roteiro, nunca pelo de encerrado');

$HOJE = '2026-11-09';   // um dia antes: o Chile ainda esta a venda
$x = wa_fluxo_proximo($g, 'bv', '', $ctx0, 'quero o chile');
ok($x['card'] === 'pdf', 'no dia 09/11 o Chile ainda ia para o PDF');
$HOJE = '2026-11-10';

/* ---------- 4. o validador aceita e sabe o que ele semeia ---------- */
$probs = wa_fluxo_valida($fx, $cards);
$txt = implode(' | ', array_map(function ($p) { return $p['texto']; }, $probs));
ok(strpos($txt, 'tipo que nao existe') === false, 'o validador aceita o tipo novo (deu: ' . $txt . ')');
ok(strpos($txt, 'Chegue a ele por um atalho de destino') === false && strpos($txt, 'escolhe um roteiro') === false,
   'o card de encerrado pode usar {roteiro} e {destino}: o atalho semeia os dois (deu: ' . $txt . ')');

echo "OK test-wa-fluxo-encerrado\n";
