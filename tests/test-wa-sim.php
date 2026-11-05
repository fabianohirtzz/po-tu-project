<?php
require __DIR__ . '/../lib/wa-sim.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }
ini_set('error_log', sys_get_temp_dir() . '/po-test-wa-sim.log');

/* ============================================================
   O simulador roda o MOTOR DE VERDADE: wa_fluxo_executa + wa_fluxo_proximo,
   e as funcoes wa_send_* reais atras de um transporte falso. Por isso ele
   enxerga os limites da Cloud API e as recusas que o cliente enxergaria, sem
   rede, sem gravar nada e sem tocar o funil.
============================================================ */

function card($id, $tipo, $extra = []) {
    return array_merge(['id' => $id, 'tipo' => $tipo, 'titulo' => $id, 'texto' => 'Texto de ' . $id,
        'opcoes' => [], 'config' => [], 'proximo' => null, 'fallback' => null,
        'sem_resposta' => null, 'lembrete_ativo' => false, 'lembrete_texto' => null], $extra);
}
function grafo($id, $cards, $extra = []) {
    $mapa = [];
    $humano = null;
    foreach ($cards as $c) {
        $mapa[$c['id']] = $c;
        if ($humano === null && $c['tipo'] === 'humano') $humano = $c['id'];
    }
    return array_merge(['id' => $id, 'nome' => 'Fluxo ' . $id, 'inicial' => $cards[0]['id'],
        'humano' => $humano, 'porta' => 'mensagem_nova', 'porta_valor' => null,
        'sim_ativo' => true, 'cards' => $mapa], $extra);
}

/* Grafo de teste: texto -> botoes -> (roteiros | humano), com fallback. */
$GA = grafo('fa', [
    card('oi',   'texto',  ['texto' => 'Bom dia, aqui e a Pereira Oliveira.', 'proximo' => 'menu']),
    card('menu', 'botoes', ['texto' => 'Como posso ajudar?', 'fallback' => 'nao_entendi',
        'opcoes' => [['id' => 'ver', 'rotulo' => 'Ver roteiros', 'destino' => 'rot'],
                     ['id' => 'falar', 'rotulo' => 'Falar com voces', 'destino' => 'hum']]]),
    card('rot',  'roteiros', ['texto' => 'Qual viagem interessa?', 'proximo' => 'hum', 'fallback' => 'nao_entendi']),
    card('nao_entendi', 'texto', ['texto' => 'Nao entendi, vou repetir.', 'proximo' => 'menu']),
    card('hum',  'humano', ['texto' => 'Vou chamar a Lais.']),
]);
$grafos = ['fa' => $GA];

/* ---------- wa_sim_deps: nada escreve, nada vai para a rede ---------- */

ok(WA_SIM_FONE === '5548900000001', 'o fone falso e um numero de teste do repo publico');
ok(wa_e164(WA_SIM_FONE) !== null, 'o fone falso passa por wa_e164, senao as funcoes de envio reais recusariam tudo');
ok(WA_SIM_MAX_PASSOS === WA_FLUXO_MAX_PASSOS, 'o teto de passos e o MESMO do motor, lido dele');

$d0 = wa_sim_deps();
ok($d0->envios === [] && $d0->escritas === [], 'o coletor nasce vazio');
ok($GLOBALS['WA_SIM_COL'] === $d0, 'e fica alcancavel pelo nome, como o WA_DEPS e o WA_TRANSPORT');
/* wa_sim_passo instala o SEU proprio coletor a cada passo (as bolhas de um
   passo sao so as dele, e assim nao existe caminho em que o simulador rode com
   o banco e o transporte de verdade), entao o que se inspeciona depois da
   chamada e sempre $GLOBALS['WA_SIM_COL']. */

/* O catalogo: `roteiros` NAO e trocado por wa_sim_deps (ela deixa o
   po_fetch_roteiros real, que e o catalogo que a cliente precisa ver). Aqui o
   teste injeta o seu, depois, para nao tocar a rede. */
wa_fluxo_set_deps(['roteiros' => function () {
    return [
        /* Datas bem no futuro: a ordenacao usa o dia de hoje. */
        ['slug'=>'vietna','titulo'=>'Vietnã Completo','periodo'=>'De 22/02/40 a 15/03/40','ativo'=>true,'status_viagem'=>null],
        ['slug'=>'chile','titulo'=>'Chile e Deserto do Atacama','periodo'=>'25/11/39 a 02/12/39','ativo'=>true,'status_viagem'=>null,'pdf_url'=>'https://exemplo.invalido/r.pdf'],
    ];
}]);

/* ---------- um passo do comeco: anda ate a primeira espera ---------- */

$r = wa_sim_passo($grafos, 'fa', null, '', []);
ok($r['estado'] === 'aguardando', 'do comeco, para na primeira espera: ' . $r['estado'] . ' ' . (string) $r['erro']);
ok($r['ok'] === true, 'e deu certo');
ok($r['card_id'] === 'menu', 'o card em que parou e o que espera');
ok($r['fluxo_id'] === 'fa', 'devolve em qual fluxo esta');
ok(count($r['mensagens']) === 2, 'duas bolhas: o texto e a pergunta com botoes, achei ' . count($r['mensagens']));
ok($r['mensagens'][0]['tipo'] === 'text' && $r['mensagens'][0]['texto'] === 'Bom dia, aqui e a Pereira Oliveira.', 'a primeira bolha e o texto, na ordem');
ok($r['mensagens'][1]['tipo'] === 'buttons' && $r['mensagens'][1]['texto'] === 'Como posso ajudar?', 'a segunda e a dos botoes');
ok(count($r['mensagens'][1]['botoes']) === 2, 'com os dois botoes');
ok($r['mensagens'][1]['botoes'][0] === ['id' => 'ver', 'rotulo' => 'Ver roteiros'], 'botao com id e rotulo');
ok($r['erro'] === null, 'sem erro');

/* A bolha sai do PAYLOAD capturado, nao do texto do card: e o que a Meta
   receberia. Prova: o payload tem o destinatario e o envelope do WhatsApp. */
$col = $GLOBALS['WA_SIM_COL'];
ok(count($col->envios) === 2, 'o transporte falso capturou os 2 envios');
ok($col->envios[0]['messaging_product'] === 'whatsapp', 'o payload capturado e o da Cloud API');
ok($col->envios[0]['to'] === WA_SIM_FONE, 'o destino e o fone falso, sem o mais');
ok($col->envios[1]['interactive']['action']['buttons'][0]['reply']['title'] === 'Ver roteiros', 'o rotulo vem do payload');

/* NADA escreveu. O card `marca` nem entrou nesta cadeia, mas o registro de
   saida (po_wa_mensagens) entra em TODO envio: ele tem que ter sido coletado
   como intencao, nunca executado. */
ok($col->escritas !== [], 'a intencao de escrita foi registrada (o log de saida de cada envio)');
foreach ($col->escritas as $e) {
    ok($e['feito'] === false, 'nenhuma escrita foi executada de verdade: ' . $e['tabela']);
}

/* ---------- clicar num botao anda para o destino daquela opcao ---------- */

$r2 = wa_sim_passo($grafos, 'fa', 'menu', 'falar', [], true);
ok($r2['estado'] === 'humano', 'clicar em "Falar com voces" entrega para a humana: ' . $r2['estado']);
ok($r2['mensagens'][0]['texto'] === 'Vou chamar a Lais.', 'e a bolha e o aviso do card humano');

$r3 = wa_sim_passo($grafos, 'fa', 'menu', 'ver', [], true);
ok($r3['estado'] === 'aguardando' && $r3['card_id'] === 'rot', 'clicar em "Ver roteiros" vai para o card de roteiros');

/* ---------- DIGITAR nao e clicar, e isso tem que divergir ---------- */

/* Em card `botoes` so o clique carrega id de opcao (wa-motor.php:566): quem
   digita o rotulo cai no fallback. Se o simulador casasse o texto, ele
   mostraria um caminho que a producao nao faz. */
$r4 = wa_sim_passo($grafos, 'fa', 'menu', 'ver', [], false);
ok($r4['estado'] === 'aguardando' && $r4['card_id'] === 'menu', 'digitar o id de um botao NAO escolhe: cai no fallback e volta para o menu');
ok($r4['ctx']['tentativas'] === 1, 'e conta a tentativa');
$textos = array_column($r4['mensagens'], 'texto');
ok(in_array('Nao entendi, vou repetir.', $textos, true), 'passando pelo card de fallback');

/* ---------- terceira tentativa errada vai para a humana ---------- */

$ctx = [];
$cid = 'menu';
$ida = [];
for ($i = 1; $i <= 3; $i++) {
    $p = wa_sim_passo($grafos, 'fa', $cid, 'bom dia', $ctx, false);
    $ida[] = $p['estado'];
    $ctx = $p['ctx'];
    $cid = $p['card_id'];
}
ok($ida === ['aguardando', 'aguardando', 'humano'], 'duas tentativas e a terceira entrega para a humana: ' . implode(',', $ida));

/* ---------- card `roteiros`: a lista sai em ordem de data e o digitado escolhe ---------- */

$r5 = wa_sim_passo($grafos, 'fa', 'menu', 'ver', [], true);
$lista = $r5['mensagens'][0]['texto'];
ok(strpos($lista, '1. Chile e Deserto do Atacama') !== false, 'a lista sai em ordem de data (Chile parte antes do Vietna): ' . $lista);
ok($r5['ctx']['roteiros_enviados'] === ['chile', 'vietna'], 'e o roteiros_enviados acompanha a ordem: ' . implode(',', (array) $r5['ctx']['roteiros_enviados']));
/* No card `roteiros` o texto digitado E a escolha (o publico 60+ digita). */
$r6 = wa_sim_passo($grafos, 'fa', 'rot', '2', $r5['ctx'], false);
ok($r6['estado'] === 'humano', 'digitar o numero escolhe no card roteiros');
ok($r6['ctx']['roteiro_slug'] === 'vietna', 'e o 2 e o mesmo slug que a lista numerou: ' . (string) $r6['ctx']['roteiro_slug']);

/* ---------- rascunho que nao publica: a recusa REAL, com o motivo ---------- */

$g4 = ['fb' => grafo('fb', [
    card('q4', 'botoes', ['texto' => 'Escolha:', 'opcoes' => [
        ['id' => 'a', 'rotulo' => 'Um', 'destino' => 'hum'], ['id' => 'b', 'rotulo' => 'Dois', 'destino' => 'hum'],
        ['id' => 'c', 'rotulo' => 'Tres', 'destino' => 'hum'], ['id' => 'd', 'rotulo' => 'Quatro', 'destino' => 'hum']]]),
    card('hum', 'humano', ['texto' => 'Vou chamar a Lais.']),
])];
$r7 = wa_sim_passo($g4, 'fb', null, '', []);
$col2 = $GLOBALS['WA_SIM_COL'];
ok($r7['ok'] === false, 'card com 4 botoes nao anda');
ok($r7['erro'] === 'mais de 3 botoes', 'e diz o motivo real da Cloud API: ' . (string) $r7['erro']);
ok($r7['mensagens'] === [], 'nenhuma bolha: nada chegou ao transporte');
ok($col2->envios === [], 'e nada foi enviado');

$g21 = ['fc' => grafo('fc', [
    card('q', 'botoes', ['texto' => 'Escolha:', 'opcoes' => [
        ['id' => 'a', 'rotulo' => 'Rotulo com vinte e um', 'destino' => 'hum']]]),
    card('hum', 'humano', ['texto' => 'Vou chamar a Lais.']),
])];
$r8 = wa_sim_passo($g21, 'fc', null, '', []);
ok($r8['ok'] === false && strpos((string) $r8['erro'], 'rotulo acima de 20 caracteres') === 0,
   'rotulo de 21 caracteres e RECUSADO, nao truncado: ' . (string) $r8['erro']);

/* Texto acima do limite da Cloud API. */
$glongo = ['fd' => grafo('fd', [
    card('q', 'botoes', ['texto' => str_repeat('a', 1100), 'opcoes' => [['id' => 'a', 'rotulo' => 'Ok', 'destino' => 'hum']]]),
    card('hum', 'humano', ['texto' => 'Vou chamar a Lais.']),
])];
$r9 = wa_sim_passo($glongo, 'fd', null, '', []);
ok($r9['ok'] === false && $r9['erro'] === 'corpo acima de 1024 caracteres', 'corpo interativo acima de 1024 e recusado: ' . (string) $r9['erro']);

/* Texto acima de 4096: a Meta devolve 400 e o cliente nao recebe NADA, calado.
   `wa_send_text` nao trunca nem recusa (decisao da casa, registrada no
   CLAUDE.md), entao quem tem de recusar aqui e o transporte falso - ele faz o
   papel da Meta. Sem isso o simulador diria SIM a um card que a Meta recusa,
   que e a unica assimetria que ele nao pode ter. */
$glongo_txt = ['fe' => grafo('fe', [
    card('t', 'texto', ['texto' => str_repeat('a', 5000), 'proximo' => 'fim']),
    card('fim', 'fim', ['texto' => 'Ate logo.']),
])];
$r9b = wa_sim_passo($glongo_txt, 'fe', null, '', []);
ok($r9b['ok'] === false, 'texto de 5000 caracteres NAO passa');
ok(strpos((string) $r9b['erro'], '4096') !== false, 'e o erro cita o limite: ' . (string) $r9b['erro']);
ok($r9b['mensagens'] === [], 'e nenhuma bolha aparece: nada chegaria ao cliente');

/* No limite ainda passa. */
$gno_limite = ['fe' => grafo('fe', [
    card('t', 'texto', ['texto' => str_repeat('a', 4096), 'proximo' => 'fim']),
    card('fim', 'fim', ['texto' => 'Ate logo.']),
])];
$r9c = wa_sim_passo($gno_limite, 'fe', null, '', []);
ok($r9c['ok'] === true, 'exatamente 4096 passa: ' . (string) $r9c['erro']);

/* Legenda de documento tem outro teto (1024), e tambem e do transporte. */
$gdoc = ['fe' => grafo('fe', [
    card('p', 'pdf', ['texto' => str_repeat('b', 1100), 'proximo' => 'fim', 'config' => []]),
    card('fim', 'fim', ['texto' => 'Ate logo.']),
])];
$r9d = wa_sim_passo($gdoc, 'fe', null, '', ['roteiro_slug' => 'chile']);
ok($r9d['ok'] === false && strpos((string) $r9d['erro'], '1024') !== false,
   'legenda de documento acima de 1024 e recusada: ' . (string) $r9d['erro']);

/* ---------- ir_para_fluxo ---------- */

$espera = grafo('fe', [
    card('aviso', 'texto', ['texto' => 'Vou te avisar quando abrir.', 'proximo' => 'fim']),
    card('fim', 'fim', ['texto' => 'Ate logo.']),
], ['sim_ativo' => false]);
$salto = grafo('ff', [
    card('vai', 'ir_para_fluxo', ['texto' => null, 'config' => ['fluxo' => 'fe']]),
]);
$gs = ['ff' => $salto, 'fe' => $espera];
$r10 = wa_sim_passo($gs, 'ff', null, '', []);
ok($r10['fluxo_id'] === 'fe', 'o salto troca de fluxo: ' . $r10['fluxo_id']);
ok($r10['estado'] === 'encerrado', 'e segue ate o fim do fluxo de destino: ' . $r10['estado']);
ok($r10['mensagens'][0]['texto'] === 'Vou te avisar quando abrir.', 'mostrando as bolhas do destino');
/* A DIFERENCA que tem que aparecer na tela: o simulador ignora `ativo` no
   salto (senao, com os dois fluxos desligados, ela nunca alcancaria a Lista de
   espera), e em producao a regra 11 bloquearia. */
ok(count($r10['avisos']) === 1, 'o salto para fluxo desligado empilha UM aviso, achei ' . count($r10['avisos']));
ok(strpos($r10['avisos'][0], 'Fluxo fe') !== false, 'o aviso nomeia o fluxo de destino: ' . $r10['avisos'][0]);
ok(strpos($r10['avisos'][0], 'desligado') !== false, 'e diz que ele esta desligado: ' . $r10['avisos'][0]);

/* Salto para fluxo ligado: anda sem aviso nenhum. */
$gs2 = ['ff' => $salto, 'fe' => grafo('fe', [card('fim', 'fim', ['texto' => 'Ate logo.'])])];
$r11 = wa_sim_passo($gs2, 'ff', null, '', []);
ok($r11['estado'] === 'encerrado' && $r11['avisos'] === [], 'salto para fluxo ligado nao avisa nada');

/* Destino que nao existe em $grafos: recusa clara, sem laco e sem 500. */
$gs3 = ['ff' => grafo('ff', [card('vai', 'ir_para_fluxo', ['texto' => null, 'config' => ['fluxo' => 'nao-existe']])])];
$r12 = wa_sim_passo($gs3, 'ff', null, '', []);
ok($r12['estado'] === 'recusado', 'salto para fluxo inexistente e recusado: ' . $r12['estado']);
ok($r12['ok'] === false && strpos((string) $r12['erro'], 'nao-existe') !== false, 'e o erro nomeia o destino: ' . (string) $r12['erro']);

/* Destino sem card inicial: em producao vai para a humana. */
$gs4 = ['ff' => $salto, 'fe' => ['id' => 'fe', 'nome' => 'Fluxo fe', 'inicial' => '', 'humano' => null,
        'porta' => 'mensagem_nova', 'porta_valor' => null, 'sim_ativo' => true, 'cards' => []]];
$r13 = wa_sim_passo($gs4, 'ff', null, '', []);
ok($r13['estado'] === 'humano', 'destino sem card inicial entrega para a humana: ' . $r13['estado']);

/* Fluxo pedido que nao existe. */
$r14 = wa_sim_passo($grafos, 'nao-existe', null, '', []);
ok($r14['estado'] === 'recusado' && $r14['mensagens'] === [], 'fluxo inexistente e recusado sem bolha');

/* ---------- teto de passos: cadeia que nunca espera ---------- */

$cadeia = [];
for ($i = 1; $i <= 30; $i++) $cadeia[] = card('t' . $i, 'texto', ['texto' => 'passo ' . $i, 'proximo' => 't' . ($i + 1)]);
$cadeia[] = card('t31', 'texto', ['texto' => 'fim da cadeia', 'proximo' => null]);
$r15 = wa_sim_passo(['fg' => grafo('fg', $cadeia)], 'fg', null, '', []);
ok($r15['estado'] === 'teto_passos', 'cadeia que nunca espera para no teto: ' . $r15['estado']);
ok(count($r15['mensagens']) === WA_SIM_MAX_PASSOS, 'com exatamente o teto de bolhas, achei ' . count($r15['mensagens']));
ok($r15['avisos'] !== [], 'e avisa que em producao a conversa iria para a humana');

/* ---------- card `marca`: registra a intencao, nunca escreve ---------- */

$gm = ['fh' => grafo('fh', [
    card('m', 'marca', ['texto' => null, 'config' => ['status' => 'atendimento'], 'proximo' => 'fim']),
    card('fim', 'fim', ['texto' => 'Ate logo.']),
])];
$r16 = wa_sim_passo($gm, 'fh', null, '', []);
$col3 = $GLOBALS['WA_SIM_COL'];
ok($r16['estado'] === 'encerrado' && $r16['ok'] === true, 'o card marca anda: ' . $r16['estado'] . ' ' . (string) $r16['erro']);
$tabelas = array_column($col3->escritas, 'tabela');
ok(in_array('po_leads', $tabelas, true), 'a intencao de marcar a ficha foi registrada');
foreach ($col3->escritas as $e) ok($e['feito'] === false, 'e nenhuma escrita saiu de verdade');

/* ---------- card_id que nao existe no grafo: igual a producao ---------- */

$r17 = wa_sim_passo($grafos, 'fa', 'card-que-nao-existe', 'oi', [], true);
ok($r17['estado'] === 'humano', 'card_id fora do grafo entrega para a humana, como em producao: ' . $r17['estado']);
ok($r17['avisos'] !== [], 'e diz por que');

/* ---------- wa_sim_grafos: monta do rascunho pela MESMA funcao do Publicar ---------- */

$fluxos = [['id' => 'f1', 'nome' => 'Boas-vindas', 'gatilho' => 'mensagem_nova', 'gatilho_valor' => null,
            'ativo' => false, 'card_inicial' => 'bv', 'versao_atual' => null],
           ['id' => 'f2', 'nome' => 'Lista de espera', 'gatilho' => 'palavra', 'gatilho_valor' => 'espera',
            'ativo' => true, 'card_inicial' => 'av', 'versao_atual' => null]];
$cards = [['id' => 'bv', 'fluxo_id' => 'f1', 'tipo' => 'texto', 'titulo' => 'Oi', 'texto' => 'Bom dia', 'proximo' => 'hu'],
          ['id' => 'hu', 'fluxo_id' => 'f1', 'tipo' => 'humano', 'titulo' => 'Humana', 'texto' => 'Chamo a Lais'],
          ['id' => 'av', 'fluxo_id' => 'f2', 'tipo' => 'fim', 'titulo' => 'Aviso', 'texto' => 'Ate logo']];
$gg = wa_sim_grafos($fluxos, $cards);
ok(array_keys($gg) === ['f1', 'f2'], 'um grafo por fluxo, pela chave do id');
ok($gg['f1']['inicial'] === 'bv' && $gg['f1']['humano'] === 'hu', 'o grafo e o do wa_fluxo_grafo_de');
ok(array_keys($gg['f1']['cards']) === ['bv', 'hu'], 'cada fluxo leva SO os cards dele: ' . implode(',', array_keys($gg['f1']['cards'])));
ok($gg['f2']['cards'] === ['av' => $gg['f2']['cards']['av']], 'e o outro fluxo leva so o seu');
ok($gg['f1']['sim_ativo'] === false && $gg['f2']['sim_ativo'] === true, 'carrega o ativo do rascunho, que o retrato publicado nao guarda');
/* Prova de que a montagem e a mesma do Publicar, nao uma segunda: o grafo sem
   a anotacao do simulador e identico ao de wa_fluxo_grafo_de. */
$sem = $gg['f1']; unset($sem['sim_ativo']);
ok($sem === wa_fluxo_grafo_de($fluxos[0], [$cards[0], $cards[1]]), 'tirando sim_ativo, o grafo e byte a byte o do wa_fluxo_grafo_de');

/* ---------- ATALHO NO PONTO 1: a primeira frase da conversa simulada ----------
   O simulador abre a conversa sem mensagem nenhuma (resposta vazia), e nesse
   caso ele comeca no card inicial, como sempre. Mas a cliente tem que poder
   escrever a PRIMEIRA frase e ver o desvio - senao a tela aprovaria um
   rascunho cujo atalho do card inicial nunca foi exercitado, que e o caminho
   mais usado dos tres (o cliente chega escrevendo).

   A saudacao aparece como BOLHA, e antes do destino: e a mesma ordem da
   producao, e o que ela existe para provar na tela. */
$GB = grafo('fb', [
    card('bv',  'botoes', ['texto' => 'Oi! Sobre o que quer falar?', 'fallback' => 'ops',
        'config' => ['atalhos' => [
            ['tipo' => 'palavras', 'palavras' => ['atendente'], 'destino' => 'hum', 'saudacao' => ''],
            ['tipo' => 'roteiro',  'palavras' => [], 'destino' => 'pdf', 'saudacao' => 'Oi! Já te mando o roteiro.'],
        ]],
        'opcoes' => [['id' => 'ver', 'rotulo' => 'Ver roteiros', 'destino' => 'rot']]]),
    card('pdf', 'pdf',    ['texto' => 'Segue o roteiro do {roteiro}.', 'proximo' => 'fimz']),
    card('rot', 'roteiros', ['texto' => 'Qual viagem?', 'proximo' => 'fimz', 'fallback' => 'ops']),
    card('ops', 'texto',  ['texto' => 'Nao entendi.', 'proximo' => 'bv']),
    card('fimz','fim',    ['texto' => 'Qualquer coisa, chama aqui.']),
    card('hum', 'humano', ['texto' => 'Vou chamar a Lais.']),
]);
$gb = ['fb' => $GB];

$r = wa_sim_passo($gb, 'fb', null, '', []);
ok($r['card_id'] === 'bv' && $r['estado'] === 'aguardando', 'abrir sem mensagem comeca no card inicial, como hoje');
ok(count($r['mensagens']) === 1, 'e sai so a bolha do card inicial');

$r = wa_sim_passo($gb, 'fb', '', 'quero saber do chile', []);
ok($r['mensagens'][0]['tipo'] === 'text' && $r['mensagens'][0]['texto'] === 'Oi! Já te mando o roteiro.',
   'a primeira frase dispara o atalho e a SAUDACAO e a primeira bolha');
ok($r['mensagens'][1]['tipo'] === 'document', 'e so depois dela vem o PDF do destino do atalho');
ok($r['ctx']['roteiro_slug'] === 'chile', 'o roteiro reconhecido na frase entra no contexto');
ok($r['estado'] === 'encerrado', 'a cadeia segue do destino do atalho ate o fim dela: ' . $r['estado']);

$r = wa_sim_passo($gb, 'fb', '', 'quero um atendente', []);
ok($r['card_id'] === 'hum' && $r['estado'] === 'humano', 'atalho de palavras na primeira frase vai direto para a humana');
ok($r['mensagens'][0]['tipo'] === 'text' && $r['mensagens'][0]['texto'] === 'Vou chamar a Lais.',
   'com saudacao vazia, a primeira bolha e a do destino: salta calado');

$r = wa_sim_passo($gb, 'fb', '', 'bom dia', []);
ok($r['card_id'] === 'bv' && count($r['mensagens']) === 1,
   'frase que nao casa atalho nenhum comeca no card inicial, sem desvio');

/* ---------- ATALHO DE DESTINO: o caminho que a Parte C existe para encher ----
   Este e o unico dos tres tipos que a cliente NAO consegue conferir de outro
   jeito. Roteiro ela ve pelo PDF que sai; palavras, pela conversa indo para a
   humana. Destino termina numa linha de lista de espera que o simulador nao
   grava - entao, sem aviso na tela, ela nao tem como saber se funcionou. */
$DESTINOS = [
    ['nome' => 'Itália', 'palavras' => 'italia, roma, veneza', 'ativo' => true],
    ['nome' => 'Chile',  'palavras' => 'chile, atacama',       'ativo' => true],
];
wa_fluxo_set_deps(['destinos' => function () use ($DESTINOS) { return $DESTINOS; }]);

$GC = grafo('fc', [
    card('bv', 'botoes', ['texto' => 'Oi! Sobre o que quer falar?', 'fallback' => 'ops',
        'config' => ['atalhos' => [
            ['tipo' => 'palavras', 'palavras' => ['atendente'], 'destino' => 'hum',   'saudacao' => 'Ja chamo alguem.'],
            ['tipo' => 'roteiro',  'palavras' => [], 'destino' => 'pdf',   'saudacao' => 'Ja mando o roteiro.'],
            ['tipo' => 'destino',  'palavras' => [], 'destino' => 'sdata', 'saudacao' => 'Deixa eu ver as datas.'],
        ]],
        'opcoes' => [['id' => 'ver', 'rotulo' => 'Ver roteiros', 'destino' => 'rot']]]),
    card('sdata', 'botoes', ['texto' => 'Ainda nao temos data para {destino}. Quer entrar na lista?',
        'fallback' => 'ops',
        'opcoes' => [['id' => 'sim', 'rotulo' => 'Sim, quero', 'destino' => 'anota'],
                     ['id' => 'nao', 'rotulo' => 'Nao, obrigado', 'destino' => 'fimz']]]),
    card('anota', 'marca', ['texto' => null, 'config' => ['espera' => '+destino'], 'proximo' => 'fimz']),
    card('pdf',  'pdf',      ['texto' => 'Segue o roteiro do {roteiro}.', 'proximo' => 'fimz']),
    /* O card que espera tambem le a frase, e e ali que a pessoa digita o nome
       do destino depois de tocar em "Ver roteiros". Saudacao vazia: no ponto 2
       ela e ignorada. */
    card('rot',  'roteiros', ['texto' => 'Qual viagem?', 'proximo' => 'fimz', 'fallback' => 'ops',
        'config' => ['atalhos' => [
            ['tipo' => 'palavras', 'palavras' => ['atendente'], 'destino' => 'hum',   'saudacao' => ''],
            ['tipo' => 'destino',  'palavras' => [], 'destino' => 'sdata', 'saudacao' => 'Deixa eu ver as datas.'],
        ]]]),
    card('ops',  'texto',    ['texto' => 'Nao entendi.', 'proximo' => 'bv']),
    card('fimz', 'fim',      ['texto' => 'Qualquer coisa, chama aqui.']),
    card('hum',  'humano',   ['texto' => 'Vou chamar a Lais.']),
]);
$gc = ['fc' => $GC];

/* Ponto 1: a frase traz o nome de um destino sem data. */
$r = wa_sim_passo($gc, 'fc', '', 'tem viagem para a Italia?', []);
ok($r['card_id'] === 'sdata' && $r['estado'] === 'aguardando',
   'a primeira frase com o nome de um destino leva ao card "sem data": ' . $r['card_id'] . ' ' . $r['estado']);
ok($r['ctx']['destino_nome'] === 'Itália', 'e o nome do destino entra no contexto');
ok($r['mensagens'][0]['texto'] === 'Deixa eu ver as datas.', 'a saudacao do atalho e a primeira bolha');
ok(strpos($r['mensagens'][1]['texto'], 'Itália') !== false,
   'e a bolha do card diz o NOME do destino, nao {destino}: ' . $r['mensagens'][1]['texto']);

/* O AVISO DO ATALHO. Sem ele a conversa salta e nada na tela diz por que: a
   cliente nao consegue distinguir um atalho de um "nao entendi" que a levou
   para outro lugar - e e justamente o atalho que ela acabou de montar na
   gaveta que ela esta testando. */
$diz = function ($avisos, $pedaco) {
    foreach ((array) $avisos as $a) if (strpos($a, $pedaco) !== false) return true;
    return false;
};
ok($diz($r['avisos'], 'destino'), 'a tela diz que foi um atalho de DESTINO que desviou a conversa: ' . json_encode($r['avisos'], JSON_UNESCAPED_UNICODE));
ok($diz($r['avisos'], 'Ainda nao temos data') || $diz($r['avisos'], 'sdata'), 'e para onde ela foi');

$r1 = wa_sim_passo($gc, 'fc', '', 'quero saber do chile', []);
ok($r1['card_id'] === 'pdf' || $r1['estado'] === 'encerrado', 'o Chile esta nas duas listas e o atalho de roteiro vem antes');
ok($diz($r1['avisos'], 'roteiro'), 'e a tela diz que foi o atalho de ROTEIRO: ' . json_encode($r1['avisos'], JSON_UNESCAPED_UNICODE));

$r1 = wa_sim_passo($gc, 'fc', '', 'quero um atendente', []);
ok($diz($r1['avisos'], 'palavras'), 'e para o terceiro tipo diz PALAVRAS: ' . json_encode($r1['avisos'], JSON_UNESCAPED_UNICODE));

$r1 = wa_sim_passo($gc, 'fc', null, '', []);
ok(!$diz($r1['avisos'], 'atalho'), 'abrir a conversa sem frase nao inventa aviso de atalho');

/* PONTO 2: a frase digitada num card que espera. E o caminho mais provavel no
   uso real - a pessoa toca "Ver roteiros" e so depois escreve "Italia". */
$r2 = wa_sim_passo($gc, 'fc', 'rot', 'quero ir para Roma', [], false);
ok($r2['card_id'] === 'sdata', 'digitar o nome de um destino num card que espera tambem dispara o atalho: ' . $r2['card_id']);
ok($r2['ctx']['destino_nome'] === 'Itália', 'e o destino entra no contexto tambem no ponto 2');
ok($diz($r2['avisos'], 'destino'), 'com o mesmo aviso');
ok(!$diz($r2['avisos'], 'Deixa eu ver as datas'), 'e SEM a saudacao: no ponto 2 ela e ignorada, e mostra-la prometeria uma mensagem que nao sai');
foreach ($r2['mensagens'] as $m) {
    ok($m['texto'] !== 'Deixa eu ver as datas.', 'nenhuma bolha de saudacao no ponto 2');
}

/* O CONTEXTO ATRAVESSA O NAVEGADOR. A tela devolve o ctx em cada passo, e ele
   passa por wa_sim_ctx antes de voltar ao motor: se a chave do destino nao
   estiver na lista fechada, ela morre no caminho e a espera e anotada vazia -
   calada, com a tela dizendo que deu certo. E o mesmo defeito que a producao
   teve antes de po_wa_conversas ganhar a coluna. */
$volta = wa_sim_ctx(json_encode($r['ctx']));
ok(is_array($volta), 'o ctx do passo anterior e aceito de volta');
ok(($volta['destino_nome'] ?? null) === 'Itália', 'e o nome do destino sobrevive a ida e volta pelo navegador');
ok(wa_sim_ctx('{"destino_nome":123}') === null, 'destino que nao e texto recusa o contexto inteiro');
ok(wa_sim_ctx('{"destino_nome":null}') === [], 'destino nulo e simplesmente ausencia');
ok(!array_key_exists('estado', (array) wa_sim_ctx('{"destino_nome":"Itália","estado":"x"}')),
   'e a lista segue FECHADA: chave que o motor nao le e descartada');

/* A ESPERA: o simulador mostra que ela seria anotada, e NAO anota.
   Hoje o caminho parava antes por acidente (a ficha nao era encontrada), nao
   por decisao - e acidente nao e trava. */
$r3 = wa_sim_passo($gc, 'fc', 'sdata', 'sim', $volta, true);
ok($r3['estado'] === 'encerrado', 'o "sim" do card segue ate o fim: ' . $r3['estado'] . ' ' . (string) $r3['erro']);
ok($diz($r3['avisos'], 'Itália'), 'e a tela diz que a Italia entraria na lista de espera: ' . json_encode($r3['avisos'], JSON_UNESCAPED_UNICODE));
ok($diz($r3['avisos'], 'espera'), 'nomeando a lista de espera');
$esp = array_values(array_filter($GLOBALS['WA_SIM_COL']->escritas, function ($e) { return $e['tipo'] === 'espera'; }));
ok(count($esp) === 1, 'a intencao foi COLETADA uma vez: ' . count($esp));
ok($esp[0]['feito'] === false, 'e marcada como nao feita, igual as outras escritas do simulador');
ok($esp[0]['destino'] === 'Itália', 'com o destino que a pessoa disse');

/* E sem destino no contexto, nada de aviso: dizer "entraria na lista" quando
   nao ha nome e exatamente o defeito que o achado 6 era. */
$r4 = wa_sim_passo($gc, 'fc', 'sdata', 'sim', [], true);
ok(!$diz($r4['avisos'], 'lista de espera'), 'sem destino no contexto a tela nao promete uma anotacao que nao aconteceria');

/* O AVISO DIZ QUAL DOS TRES CASOU, e nao so que "houve um atalho". A rodada de
   mutacao achou isto: as frases dos tres tipos ja contem a palavra do tipo
   ("...nome de um destino...", "...as palavras de um atalho..."), entao
   procurar a palavra solta no aviso passava verde mesmo sem o rotulo. O rotulo
   importa porque e ele que liga o aviso a LINHA da gaveta que ela configurou:
   sao tres linhas no mesmo card, e saber qual disparou e o que ela veio ver. */
$comeca = function ($avisos, $prefixo) {
    foreach ((array) $avisos as $a) if (strpos($a, $prefixo) === 0) return true;
    return false;
};
$r5 = wa_sim_passo($gc, 'fc', '', 'tem viagem para a Italia?', []);
ok($comeca($r5['avisos'], 'Atalho de destino:'), 'o aviso nomeia o tipo: ' . json_encode($r5['avisos'], JSON_UNESCAPED_UNICODE));
$r5 = wa_sim_passo($gc, 'fc', '', 'quero saber do chile', []);
ok($comeca($r5['avisos'], 'Atalho de roteiro:'), 'idem para roteiro: ' . json_encode($r5['avisos'], JSON_UNESCAPED_UNICODE));
$r5 = wa_sim_passo($gc, 'fc', '', 'quero um atendente', []);
ok($comeca($r5['avisos'], 'Atalho de palavras:'), 'idem para palavras: ' . json_encode($r5['avisos'], JSON_UNESCAPED_UNICODE));

/* Tipo que o motor casou e a tela nao sabe nomear ainda: aviso MESMO ASSIM,
   com o nome cru. Um desvio real esta acontecendo, e a alternativa (frase
   vazia) seria a tela calada no caso em que ela mais precisa falar. Pura,
   entao se exercita direto. */
$a = wa_sim_aviso_atalho('tipo_novo', 'Algum card');
ok(strpos($a, 'tipo_novo') !== false, 'tipo desconhecido aparece no aviso: ' . $a);
ok(strpos($a, 'Algum card') !== false, 'com o card de destino: ' . $a);
ok(trim($a) !== '' && strpos($a, '::') === false, 'e a frase nao fica truncada nem vazia: ' . $a);
/* O motivo nao pode sair VAZIO: "Atalho de tipo_novo: , e a conversa foi..."
   e uma frase com buraco, e a cliente le isso na tela. A mutacao que trocou o
   motivo desconhecido por string vazia sobreviveu a tres assercoes antes
   desta. */
ok(preg_match('/:\s*,/', $a) === 0, 'e nao fica um buraco entre o rotulo e a virgula: ' . $a);
foreach (['palavras', 'roteiro', 'destino'] as $t) {
    $f = wa_sim_aviso_atalho($t, 'X');
    ok(strpos($f, 'Atalho de ' . $t . ':') === 0, 'o aviso de ' . $t . ' comeca pelo rotulo do tipo: ' . $f);
    ok(strpos($f, 'card "X"') !== false, 'e termina no card de destino: ' . $f);
}

/* E O AVISO NAO PODE MENTIR. Rota que NAO foi atalho - a resposta digitada num
   card de botoes, que cai no fallback - nao ganha aviso de atalho nenhum.
   Sem esta assercao, um aviso disparando em toda rota passava verde: a cliente
   leria "Atalho de ..." num caminho que o atalho nao decidiu, e iria mexer na
   gaveta para consertar o que nao esta la. */
$r6 = wa_sim_passo($gc, 'fc', 'bv', 'xpto zzz nada a ver', [], false);
ok($r6['card_id'] === 'bv' || $r6['card_id'] === 'ops',
   'frase que nao casa nada cai no fallback: ' . $r6['card_id']);
ok(!$comeca($r6['avisos'], 'Atalho de'), 'e NAO ganha aviso de atalho: ' . json_encode($r6['avisos'], JSON_UNESCAPED_UNICODE));
$r6 = wa_sim_passo($gc, 'fc', 'bv', 'ver', [], true);
ok($r6['card_id'] === 'rot', 'o clique num botao segue pela opcao');
ok(!$comeca($r6['avisos'], 'Atalho de'), 'e tampouco ganha aviso de atalho: ' . json_encode($r6['avisos'], JSON_UNESCAPED_UNICODE));

/* ---------- travas de fonte ---------- */

$src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../lib/wa-sim.php'));
foreach (['wa_db_insert', 'wa_db_update', 'wa_conversa_set', 'wa_nota_lead', 'wa_entrega_humana', 'wa_espera_anota'] as $proibido) {
    ok(strpos($src, $proibido) === false, 'lib/wa-sim.php nao menciona ' . $proibido . ': o simulador nao grava e nao toca o funil');
}
ok(preg_match('/require(_once)?\s+__DIR__\s*\.\s*\x27\/wa-motor\.php\x27/', $src) === 0,
   'lib/wa-sim.php nao requer o wa-motor.php (a seta e db/send/po-data <- fluxo <- sim/motor <- timeout)');
ok(strpos($src, 'wa_fluxo_percorre') === false,
   'e nao chama wa_fluxo_percorre, que e casado com wa_conversa_set/wa_nota_lead');
ok(preg_match('/const WA_SIM_MAX_PASSOS\s*=\s*WA_FLUXO_MAX_PASSOS\s*;/', $src) === 1,
   'o teto e o do motor, lido dele, nunca um numero novo');
ok(preg_match('/\x27roteiros\x27\s*=>/', $src) === 0,
   'wa_sim_deps nao troca a dep `roteiros`: o catalogo que a cliente ve e o real');

echo "ok\n";
