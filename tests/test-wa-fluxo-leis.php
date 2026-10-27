<?php
/* As leis do motor sobrevivem ao interpretador.

   Este arquivo existe para provar que trocar a maquina de estados escrita a
   mao pelo interpretador de fluxo NAO enfraqueceu nenhuma das nove leis que
   ficam acima do fluxo (spec, "Leis do motor"). Mesmo dubles de
   tests/test-wa-motor.php (banco em memoria, envio em lista, transporte cru
   barrando escrita em po_wa_mensagens) mais fluxos publicados e ligados.
   Nenhum teste toca a rede. */
require __DIR__ . '/../lib/wa-motor.php';
require __DIR__ . '/../lib/wa-webhook.php';
require __DIR__ . '/fixtures/fluxo-motor.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

/* Quase todo desvio deste arquivo loga de proposito (error_log). Com dezenas de
   linhas na saida, um warning de PHP de verdade passaria sem ninguem ver: aqui
   o log vai para um arquivo e a saida do teste fica so com o que e do teste. */
ini_set('error_log', sys_get_temp_dir() . '/po-test-wa-fluxo-leis.log');

$DB = fx_banco_inicial(); $ENVIADAS = []; $QUERIES = []; $ESCRITAS_MENSAGENS = [];
$TRANSP_STATUS = 201;   // o que o "Supabase" responde ao POST em po_wa_mensagens
wa_db_set_transport(function ($metodo, $url, $corpo) use (&$ESCRITAS_MENSAGENS, &$TRANSP_STATUS) {
    if (in_array($metodo, ['POST', 'PATCH'], true) && strpos($url, 'po_wa_mensagens') !== false) {
        $ESCRITAS_MENSAGENS[] = [$metodo, $url, $corpo];
        return ['status' => $TRANSP_STATUS, 'body' => '[]'];
    }
    if (strpos($url, 'po_wa_envios') === false) {
        fwrite(STDERR, "ASSERT: chamada de banco nao simulada: $metodo $url\n");
        exit(1);
    }
    return ['status' => 200, 'body' => '[]'];
});

$WA = '+5548900000001';
$WA2 = '+5548900000002';
function ev($tipo, $texto, $extra = []) {
    global $WA;
    return array_merge(['tipo' => $tipo, 'wa_id' => $WA, 'wamid' => 'wamid.' . md5($texto . mt_rand()),
        'tipo_msg' => 'text', 'texto' => $texto, 'nome' => 'Maria', 'ad_id' => null, 'ctwa_clid' => null, 'ts' => time()], $extra);
}
function clica($id, $tipo_msg = 'button_reply', $extra = []) { return ev('mensagem', $id, ['tipo_msg' => $tipo_msg] + $extra); }
function conv($fone = null) { global $WA; return wa_conversa($fone ?? $WA); }
function zera() {
    $GLOBALS['DB'] = fx_banco_inicial(); $GLOBALS['ENVIADAS'] = []; $GLOBALS['QUERIES'] = [];
    $GLOBALS['ESCRITAS_MENSAGENS'] = []; $GLOBALS['TRANSP_STATUS'] = 201;
    fx_instala();
    wa_motor_set_anuncios([]);
}
function tipos() { return array_map(function ($e) { return $e[0]; }, $GLOBALS['ENVIADAS']); }
function mini($inicial, $cards, $humano = null) {
    return ['id' => '', 'nome' => 'mini', 'inicial' => $inicial, 'humano' => $humano, 'porta' => '', 'porta_valor' => null, 'cards' => $cards];
}
function add_fluxo($id, $gatilho, $valor, $grafo, $extra = []) {
    $grafo['id'] = $id;
    $GLOBALS['DB']['po_wa_fluxos'][] = array_merge(['id' => $id, 'nome' => $id, 'gatilho' => $gatilho, 'gatilho_valor' => $valor,
        'ativo' => true, 'versao_atual' => 'v-' . $id, 'created_at' => '2026-10-10T00:00:00Z'], $extra);
    $GLOBALS['DB']['po_wa_fluxo_versoes'][] = ['id' => 'v-' . $id, 'fluxo_id' => $id, 'versao' => 1, 'grafo' => $grafo];
}
/* Faz o envio de um tipo falhar, sem wamid. zera() restaura. */
function quebra($chave) {
    wa_motor_set_deps([$chave => function () { return ['ok' => false, 'wamid' => null, 'erro' => 'timeout']; }]);
}

/* ============ LEI: o eco silencia para sempre, e o eco do PROPRIO robo nao ============ */
zera();
ok(wa_processar(ev('mensagem', 'oi')) === 'fluxo', 'primeira mensagem entra no fluxo');
ok(conv()['estado'] === 'aguardando' && conv()['card_atual'] === 'boas', 'e espera no card de boas-vindas');
ok(conv()['fluxo_versao_id'] === 'v-triagem', 'carimbou a versao na entrada');
$ENVIADAS = [];
ok(wa_processar(ev('eco', 'Bom dia, aqui e a Simone')) === 'silenciou', 'eco da humana silencia');
ok(conv()['estado'] === 'humano', 'e o estado vira humano');
ok(wa_processar(ev('mensagem', 'e quanto custa?')) === 'silenciado', 'depois do eco o robo nao responde mais');
ok($ENVIADAS === [], 'e nada mais e enviado nesse contato');

zera();
wa_processar(ev('mensagem', 'oi'));
$meu_wamid = $DB['po_wa_mensagens'][0]['wamid'];
ok($DB['po_wa_mensagens'][0]['autor'] === 'robo', 'o envio do robo entra no log como robo');
ok(wa_processar(ev('eco', 'Oi, tudo bem?', ['wamid' => $meu_wamid])) === 'eco_do_robo', 'o eco do proprio envio nao silencia');
ok(conv()['estado'] === 'aguardando', 'o robo continua dono da conversa');

// Atalho e PDF digitados por ela, a partir de um estado que o robo controla,
// tambem silenciam (o motivo de a lei existir).
zera();
wa_processar(ev('mensagem', 'oi'));
ok(wa_processar(ev('eco', '#proposta')) === 'proposta', 'atalho move o funil');
ok(conv()['estado'] === 'humano', 'e silencia o robo mesmo vindo de aguardando');
/* O PDF dela vira proposta numa conversa QUALIFICADA, nao em qualquer uma.
   No motor antigo a rede era o estado 'qualificado', que significava "respondeu
   as perguntas". Com o fluxo, 'aguardando' vale desde a boas-vindas, entao a
   rede larga transformaria quase todo contato novo em "Proposta" no Funil, com
   proposta_at distorcido nos Relatorios, em silencio. O equivalente novo de
   'qualificado' e a ficha ter qualif_at (que o card `qual` do fluxo grava). */
zera();
wa_processar(ev('mensagem', 'oi'));                      // boas-vindas: aguardando, sem qualif_at
ok(empty($DB['po_leads'][0]['qualif_at']), 'preparo: a conversa recem-aberta nao passou pela qualificacao');
ok(wa_processar(ev('eco', 'proposta.pdf', ['tipo_msg' => 'document'])) === 'silenciou', 'PDF dela numa conversa recem-aberta NAO e proposta');
ok(($DB['po_leads'][0]['status'] ?? null) !== 'negociacao', 'e o funil nao anda: nada de inflar a etapa Proposta');
ok(empty($DB['po_leads'][0]['proposta_at']), 'nem o proposta_at dos Relatorios e carimbado');
ok(conv()['estado'] === 'humano', 'mas o eco silencia o robo do mesmo jeito');
// A mesma conversa depois da qualificacao: ai o PDF dela e proposta.
zera();
wa_processar(ev('mensagem', 'oi'));
wa_processar(clica('rot'));
wa_processar(clica('turquia', 'list_reply'));
wa_processar(clica('sim'));                              // passa pelo card `qual`, que grava qualif_at
ok(!empty($DB['po_leads'][0]['qualif_at']) && conv()['estado'] === 'aguardando', 'preparo: a ficha foi qualificada e a conversa segue aguardando');
ok(wa_processar(ev('eco', 'proposta.pdf', ['tipo_msg' => 'document'])) === 'proposta', 'PDF dela numa conversa qualificada e proposta');
ok($DB['po_leads'][0]['status'] === 'negociacao', 'e o funil anda para negociacao sem ela lembrar do #proposta');
ok(!empty($DB['po_leads'][0]['proposta_at']), 'com a data da proposta para os Relatorios');
ok(conv()['estado'] === 'humano', 'PDF dela deixa o estado em humano');
// E numa conversa ja entregue a humana, o PDF dela e proposta mesmo sem qualif_at:
// ali ela esta conduzindo o atendimento, e o documento e a proposta dela.
zera();
wa_processar(ev('mensagem', 'oi'));
wa_processar(clica('hum'));
ok(conv()['estado'] === 'humano' && empty($DB['po_leads'][0]['qualif_at']), 'preparo: conversa em humano, sem qualificacao');
ok(wa_processar(ev('eco', 'proposta.pdf', ['tipo_msg' => 'document'])) === 'proposta', 'PDF dela numa conversa ja em atendimento humano e proposta');

/* ============ LEI: SAIR vem antes de tudo, carimba sempre, responde so se nao silenciado ============ */
zera();
wa_processar(ev('mensagem', 'oi'));
$ENVIADAS = [];
ok(wa_processar(ev('mensagem', 'SAIR')) === 'opt_out', 'SAIR no meio de um card que espera sai da lista');
ok(!empty($DB['po_leads'][0]['opt_out_at']), 'carimba opt_out_at');
ok(count($ENVIADAS) === 1 && $ENVIADAS[0][0] === 'text', 'e confirma uma vez');
zera();
wa_processar(ev('mensagem', 'oi'));
wa_processar(ev('eco', 'Oi, aqui e a Simone'));
$ENVIADAS = [];
ok(wa_processar(ev('mensagem', 'sair')) === 'opt_out', 'SAIR vale com o robo silenciado');
ok(!empty($DB['po_leads'][0]['opt_out_at']), 'carimba mesmo silenciado');
ok($ENVIADAS === [], 'mas nao responde por cima da humana');

/* ============ LEI: humano nunca volta para o robo, nem com fluxo ligado ============ */
zera();
wa_processar(ev('mensagem', 'oi'));
wa_processar(clica('hum'));
ok(conv()['estado'] === 'humano', 'o card humano entrega a conversa');
$ENVIADAS = [];
foreach (['oi', 'quero um roteiro', 'cruzeiro'] as $frase) {
    ok(wa_processar(ev('mensagem', $frase)) === 'silenciado', "humano nao volta para o robo: $frase");
}
ok($ENVIADAS === [] && conv()['estado'] === 'humano', 'nada enviado e o estado nao mudou');

/* ============ LEI: o estado so avanca se o envio saiu ============ */
zera();
wa_processar(ev('mensagem', 'oi'));
$antes = conv();
$ENVIADAS = [];
quebra('send_list');
ok(wa_processar(clica('rot')) === 'falha_envio', 'envio falhado devolve falha_envio');
ok(conv()['card_atual'] === $antes['card_atual'] && conv()['estado'] === 'aguardando', 'card_atual e estado nao avancaram');
ok((int) conv()['tentativas'] === (int) $antes['tentativas'], 'nem as tentativas');
// ok=true sem wamid tambem nao e envio
zera();
wa_processar(ev('mensagem', 'oi'));
wa_motor_set_deps(['send_list' => function () { return ['ok' => true, 'wamid' => null, 'erro' => null]; }]);
ok(wa_processar(clica('rot')) === 'falha_envio', 'resposta sem wamid nao conta como enviado');
ok(conv()['card_atual'] === 'boas', 'e o card nao avanca');
// primeiro contato com envio falhado: a conversa nasce, mas sem card nem versao
zera();
quebra('send_buttons');
ok(wa_processar(ev('mensagem', 'oi')) === 'falha_envio', 'primeira mensagem com envio falhado');
ok(empty(conv()['card_atual']) && empty(conv()['fluxo_versao_id']), 'nao carimba card nem versao de algo que nao saiu');
ok($DB['po_wa_mensagens'] === [], 'e nada entra no log de saida');
ok(($DB['po_leads'][0]['notas'] ?? []) === [], 'sem progresso nao ha o que contar na ficha: nenhuma nota');

// Cadeia interrompida: o que SAIU fica registrado (lei 9 = nao registrar o que
// nao chegou, nao = esquecer o que chegou). Cadeia lista -> pdf -> marca -> data,
// com o botoes da data falhando: o PDF ja foi entregue.
zera();
wa_processar(ev('mensagem', 'oi'));
wa_processar(clica('rot'));
$ENVIADAS = [];
quebra('send_buttons');
ok(wa_processar(clica('turquia', 'list_reply')) === 'falha_envio', 'a pergunta da data falha depois do PDF');
ok(tipos() === ['doc'], 'o PDF saiu');
ok(conv()['card_atual'] === 'data', 'o progresso ate o ultimo envio que saiu fica gravado');
ok(conv()['estado'] !== 'aguardando', 'mas a cadeia parada NAO e marcada como esperando o cliente (o cron mandaria lembrete)');
ok(conv()['roteiro_slug'] === 'turquia', 'e o roteiro escolhido segue no contexto');
/* A cadeia parada nao tem relogio nenhum: a varredura ignora 'novo' (e esta
   certa, ninguem esta sendo aguardado), entao sem nota na ficha o cliente que
   recebeu o PDF e nunca recebeu a pergunta fica invisivel. A ficha e o que a
   Lais le, e aqui e o unico canal que sobra. */
$notas = $DB['po_leads'][0]['notas'] ?? [];
ok(count($notas) === 1, 'a cadeia parada deixa exatamente uma nota na ficha');
ok(stripos($notas[0]['txt'], 'parte das mensagens') !== false && stripos($notas[0]['txt'], 'parada') !== false,
   'e a nota diz que parte saiu e que a conversa esta parada: ' . $notas[0]['txt']);
ok(stripos($notas[0]['txt'], 'timeout') !== false, 'e carrega o erro do envio, para a Lais saber se foi rede');
fx_instala();
$ENVIADAS = [];
ok(wa_processar(ev('mensagem', 'oi?')) === 'fluxo', 'a mensagem seguinte retoma a cadeia');
ok(!in_array('doc', tipos(), true), 'sem reenviar o documento');
ok(tipos() === ['buttons'], 'so reenvia o card que tinha falhado');
ok(conv()['estado'] === 'aguardando' && conv()['card_atual'] === 'data', 'e agora sim espera a resposta');
// o mesmo na ENTRADA: texto -> pdf -> botoes, com o botoes falhando
zera();
wa_motor_set_anuncios(['AD2' => 'turquia']);
add_fluxo('f-ent', 'anuncio', 'AD2', mini('s1', [
    's1' => fx_card('s1', 'texto', ['texto' => 'Ola!', 'proximo' => 's2']),
    's2' => fx_card('s2', 'pdf', ['texto' => 'Segue.', 'proximo' => 's3']),
    's3' => fx_card('s3', 'botoes', ['texto' => 'Serve?', 'fallback' => 's3',
        'opcoes' => [['id' => 'a', 'rotulo' => 'Sim', 'destino' => 's3']]]),
]));
quebra('send_buttons');
ok(wa_processar(ev('mensagem', 'oi', ['ad_id' => 'AD2'])) === 'falha_envio', 'entrada interrompida no ultimo card');
ok(conv()['card_atual'] === 's3' && conv()['fluxo_versao_id'] === 'v-f-ent', 'grava o card e a versao ate onde chegou');
fx_instala(); wa_motor_set_anuncios(['AD2' => 'turquia']);
$ENVIADAS = [];
wa_processar(ev('mensagem', 'oi de novo'));
ok(tipos() === ['buttons'], 'a proxima mensagem NAO repete saudacao nem PDF');

// Recusa permanente (texto vazio depois da interpolacao: o nome do perfil
// do WhatsApp chega vazio) nao pode deixar a conversa muda para sempre.
zera();
add_fluxo('f-vazio', 'palavra', 'vazio', mini('v1', ['v1' => fx_card('v1', 'texto', ['texto' => '{nome}'])]));
$ENVIADAS = [];
ok(wa_processar(ev('mensagem', 'vazio', ['nome' => ''])) === 'fluxo', 'card que expande para vazio e tratado');
ok(conv()['estado'] === 'humano' && $ENVIADAS === [], 'vai para a humana, nada enviado');
ok(count($DB['po_leads'][0]['notas'] ?? []) === 1, 'e a ficha diz por que');

// A EXCECAO documentada: o terminal. ok=false com terminal='humano' entrega
// a conversa assim mesmo, senao o robo segue dono de uma conversa liberada.
zera();
wa_processar(ev('mensagem', 'oi'));
quebra('send_text');
ok(wa_processar(clica('hum')) === 'fluxo', 'card humano com aviso falhado ainda e tratado');
ok(conv()['estado'] === 'humano', 'a conversa vai para a humana mesmo com o aviso falhado');
ok(!empty(conv()['silenciado_at']), 'e o robo fica silenciado');
// pdf sem roteiro no catalogo: terminal humano, nada enviado
zera();
wa_processar(ev('mensagem', 'oi'));
wa_processar(clica('rot'));
wa_motor_set_deps(['roteiros' => function () { return [['slug' => 'outro', 'titulo' => 'Outro', 'pdf_url' => 'u', 'data_label' => 'x']]; }]);
$ENVIADAS = [];
ok(wa_processar(clica('turquia', 'list_reply')) === 'fluxo', 'escolha de roteiro que saiu do ar e tratada');
ok(conv()['estado'] === 'humano' && $ENVIADAS === [], 'vai para a humana e nao envia documento nenhum');

// ok=true com erro e "andou, mas registre": a gravacao da marca falhou e a
// conversa continua ate o proximo card que espera.
zera();
wa_processar(ev('mensagem', 'oi'));
wa_processar(clica('rot'));
wa_motor_set_deps(['update' => function ($tabela, $query, $campos) {
    if ($tabela === 'po_leads') return false;
    foreach ($GLOBALS['DB'][$tabela] as $i => $l) if (fx_casa($l, $query)) $GLOBALS['DB'][$tabela][$i] = array_merge($l, $campos);
    return true;
}]);
ok(wa_processar(clica('turquia', 'list_reply')) === 'fluxo', 'marca que nao gravou nao derruba a conversa');
ok(conv()['card_atual'] === 'data' && conv()['estado'] === 'aguardando', 'a conversa segue ate a pergunta da data');

/* ============ O grafo que acaba no meio tambem deixa rastro na ficha ============
   Card que nao espera e sem proximo: a conversa encerra, igual a um card `fim`,
   mas sem nenhuma despedida ter sido desenhada. A cliente desenhou um fluxo que
   para, e so a ficha pode contar isso para a Lais (nao ha lembrete nem varredura
   que alcance conversa encerrada). */
zera();
add_fluxo('f-corta', 'palavra', 'corta', mini('u1', ['u1' => fx_card('u1', 'texto', ['texto' => 'Ja volto.'])]));
ok(wa_processar(ev('mensagem', 'corta')) === 'fluxo', 'o card sem proximo e tratado');
ok(conv()['estado'] === 'encerrado', 'a conversa encerra, como num card de fim');
$notas = $DB['po_leads'][0]['notas'] ?? [];
ok(count($notas) === 1, 'e deixa uma nota na ficha');
ok(stripos($notas[0]['txt'], 'Ja volto') !== false || stripos($notas[0]['txt'], 'u1') !== false,
   'a nota nomeia o card em que o fluxo acabou: ' . $notas[0]['txt']);
ok(stripos($notas[0]['txt'], 'encerrada') !== false, 'e diz que a conversa foi encerrada: ' . $notas[0]['txt']);
// O card `fim` desenhado de proposito NAO deixa nota: ele e o caminho certo.
zera();
add_fluxo('f-adeus', 'palavra', 'adeus', mini('z1', ['z1' => fx_card('z1', 'fim', ['texto' => 'Ate logo.'])]));
ok(wa_processar(ev('mensagem', 'adeus')) === 'fluxo' && conv()['estado'] === 'encerrado', 'o card fim encerra');
ok(($DB['po_leads'][0]['notas'] ?? []) === [], 'e o fim desenhado de proposito nao vira aviso na ficha');

/* ============ LEI: mensagem do cliente reseta lembrete_at E aguardando_desde juntos ============ */
zera();
wa_processar(ev('mensagem', 'oi'));
$velho = gmdate('c', time() - 90000);
wa_conversa_set($WA, ['lembrete_at' => gmdate('c', time() - 3600), 'aguardando_desde' => $velho]);
quebra('send_list');   // o fluxo nao grava nada: so o reset do motor pode explicar o resultado
ok(wa_processar(clica('rot')) === 'falha_envio', 'preparo: o fluxo nao escreve nada');
ok(conv()['lembrete_at'] === null, 'a mensagem zera o lembrete_at');
ok(strtotime(conv()['aguardando_desde']) >= time() - 5, 'e recomeca o aguardando_desde junto');

/* ============ LEI: menu uma vez so ============ */
zera();
wa_processar(ev('mensagem', 'oi'));
wa_processar(clica('rot'));
ok(count(array_keys(tipos(), 'list')) === 1, 'preparo: a lista saiu uma vez');
ok(wa_processar(ev('mensagem', 'quanto custa?')) === 'fluxo', 'mensagem livre no card de lista e tratada');
ok(count(array_keys(tipos(), 'list')) === 1, 'a lista NAO se repete a cada mensagem que o robo nao entende');
ok(conv()['card_atual'] === 'outra', 'a mensagem livre foi para o fallback');

/* ============ LEI: catalogo vazio cai em texto puro ============ */
zera();
wa_processar(ev('mensagem', 'oi'));
wa_motor_set_deps(['roteiros' => function () { return []; }]);
$ENVIADAS = [];
ok(wa_processar(clica('rot')) === 'fluxo', 'sem roteiro ativo o fluxo segue');
ok(count($ENVIADAS) === 1 && $ENVIADAS[0][0] === 'text', 'manda texto, nunca uma lista impossivel');
ok(conv()['estado'] === 'aguardando' && conv()['card_atual'] === 'lista', 'e continua esperando o destino');

/* ============ LEI: idempotencia por wamid; status nao passa por ela ============ */
zera();
$e = ev('mensagem', 'oi');
$TRANSP_STATUS = 409;
ok(wa_registra_evento($e) === 'duplicado', '409 e duplicado');
$TRANSP_STATUS = 500;
ok(wa_registra_evento($e) === 'falha', 'erro do banco e falha (e falha processa assim mesmo)');
$TRANSP_STATUS = 201;
ok(wa_registra_evento($e) === 'novo', '201 e novo');
$fonte = file_get_contents(__DIR__ . '/../whatsapp.php');
ok(preg_match("/\\\$ev\\['tipo'\\]\\s*!==\\s*'status'/", $fonte) === 1, 'whatsapp.php exclui status da idempotencia');
ok(preg_match("/=== 'duplicado'\\)\\s*continue;/", $fonte) === 1, 'e duplicado pula o processamento');
zera();
$ESCRITAS_MENSAGENS = [];
ok(wa_processar(['tipo' => 'status', 'wa_id' => $WA, 'wamid' => 'wamid.S1', 'tipo_msg' => 'status', 'texto' => 'delivered',
    'nome' => null, 'ad_id' => null, 'ctwa_clid' => null, 'ts' => time()]) === 'status', 'status devolve status');
ok(!$ESCRITAS_MENSAGENS && $DB['po_wa_mensagens'] === [], 'status nao escreve em po_wa_mensagens, por nenhum caminho');
ok($DB['po_wa_conversas'] === [] && $DB['po_leads'] === [] && $ENVIADAS === [], 'nem cria conversa, nem lead, nem envia');

/* ============ PORTA so fora de card que espera (spec 6.11) ============ */
zera();
add_fluxo('f-cruz', 'palavra', 'cruzeiro', mini('c1', ['c1' => fx_card('c1', 'fim', ['texto' => 'Cruzeiro!'])]));
wa_processar(ev('mensagem', 'oi'));
$versao_original = conv()['fluxo_versao_id'];
ok(wa_processar(ev('mensagem', 'e o cruzeiro?')) === 'fluxo', 'a mensagem e processada');
ok(conv()['fluxo_versao_id'] === $versao_original, 'nao trocou de fluxo no meio');
ok(conv()['card_atual'] === 'outra', 'caiu no fallback do card que esperava');
// a mesma mensagem com a conversa encerrada ABRE o fluxo da palavra
wa_conversa_set($WA2, ['estado' => 'encerrado', 'card_atual' => 'adeus', 'fluxo_versao_id' => 'v-triagem', 'roteiro_slug' => 'turquia']);
wa_lead_set($WA2, ['nome' => 'Joana', 'status' => 'novo']);
ok(wa_processar(ev('mensagem', 'e o cruzeiro?', ['wa_id' => $WA2])) === 'fluxo', 'conversa encerrada recomeca pela porta');
ok(conv($WA2)['fluxo_versao_id'] === 'v-f-cruz', 'encerrado conta como fora de conversa');
ok(conv($WA2)['roteiro_slug'] === null, 'e o roteiro da conversa antiga nao vaza para a nova');

// Texto digitado nao e escolha: "turquia" escrito num card de lista nao vale como slug.
zera();
wa_processar(ev('mensagem', 'oi'));
wa_processar(clica('rot'));
wa_processar(ev('mensagem', 'turquia'));
ok(conv()['card_atual'] === 'outra' && empty(conv()['roteiro_slug']), 'texto digitado vai para o fallback, nao escolhe roteiro');

/* ============ A versao e carimbada UMA vez ============ */
zera();
wa_processar(ev('mensagem', 'oi'));
// o fluxo e republicado: versao nova, com card de outro id, apontada por versao_atual
$novo = mini('x1', ['x1' => fx_card('x1', 'fim', ['texto' => 'Versao nova'])]);
$novo['id'] = 'f-triagem';
$DB['po_wa_fluxo_versoes'][] = ['id' => 'v-triagem-2', 'fluxo_id' => 'f-triagem', 'versao' => 2, 'grafo' => $novo];
$DB['po_wa_fluxos'][0]['versao_atual'] = 'v-triagem-2';
ok(wa_processar(clica('rot')) === 'fluxo', 'a conversa em voo segue');
ok(conv()['fluxo_versao_id'] === 'v-triagem', 'e continua na versao em que entrou, nao na atual');
ok(conv()['card_atual'] === 'lista', 'andou pelo grafo antigo');
// conversa nova ja entra na versao nova
ok(wa_processar(ev('mensagem', 'oi', ['wa_id' => $WA2])) === 'fluxo', 'contato novo entra');
ok(conv($WA2)['fluxo_versao_id'] === 'v-triagem-2', 'pela versao atual');

/* ============ grafo null: "nao sei" ou "sem fluxo", nunca o contrario ============ */
// COM fluxo_versao_id: nao resolve porta, nao envia, nao muda estado.
zera();
wa_processar(ev('mensagem', 'oi'));
$antes = conv();
$DB['po_wa_fluxo_versoes'] = [];          // versao inexistente
$ENVIADAS = [];
ok(wa_processar(ev('mensagem', 'oi de novo')) === 'grafo_indisponivel', 'versao sumida e "nao sei"');
ok($ENVIADAS === [], 'nao reenvia as boas-vindas');
ok(conv()['card_atual'] === $antes['card_atual'] && conv()['estado'] === $antes['estado'], 'e nao mexe no estado');
zera();
wa_processar(ev('mensagem', 'oi'));
wa_motor_set_deps(['select_estrito' => function ($tabela, $query) { return $tabela === 'po_wa_fluxo_versoes' ? null : fx_consulta($tabela, $query); }]);
$ENVIADAS = [];
ok(wa_processar(ev('mensagem', 'oi de novo')) === 'grafo_indisponivel', 'banco fora do ar e "nao sei"');
ok($ENVIADAS === [], 'banco fora nao reenvia boas-vindas a quem esta no meio da conversa');
// lista de fluxos indisponivel (banco fora) tambem nao e "nenhum fluxo"
zera();
wa_motor_set_deps(['select_estrito' => function () { return null; }]);
ok(wa_processar(ev('mensagem', 'oi')) === 'grafo_indisponivel' && $ENVIADAS === [], 'lista de fluxos fora do ar: nada feito');

// SEM fluxo_versao_id: e o caminho legitimo "sem fluxo".
zera();
$DB['po_wa_fluxos'] = [];
ok(wa_processar(ev('mensagem', 'oi')) === 'sem_fluxo', 'nenhum fluxo ligado: sem_fluxo');
ok($ENVIADAS === [], 'e nada e enviado');
zera();
$DB['po_wa_fluxos'][0]['ativo'] = false;
ok(wa_processar(ev('mensagem', 'oi')) === 'sem_fluxo' && $ENVIADAS === [], 'fluxo desligado nao atende');
zera();
$DB['po_wa_fluxos'][0]['versao_atual'] = null;
ok(wa_processar(ev('mensagem', 'oi')) === 'sem_fluxo' && $ENVIADAS === [], 'fluxo nunca publicado nao atende');

/* ============ REVIEW FOCUS 1: card_atual que nao existe mais no retrato ============ */
zera();
wa_processar(ev('mensagem', 'oi'));
wa_conversa_set($WA, ['card_atual' => 'card-que-foi-apagado']);
$ENVIADAS = [];
ok(wa_processar(ev('mensagem', 'oi')) === 'card_perdido', 'card orfao tem retorno proprio');
ok(in_array(conv()['estado'], ['humano', 'encerrado'], true), 'nao fica mudo: vai para humano ou encerra');
ok(conv()['estado'] === 'humano' && !empty(conv()['silenciado_at']), 'vai para a humana');
$notas = $DB['po_leads'][0]['notas'] ?? [];
ok(count($notas) === 1 && stripos($notas[0]['txt'], 'perdeu') !== false, 'e a ficha diz por que o robo parou');
ok(wa_processar(ev('mensagem', 'alguem ai?')) === 'silenciado', 'e a proxima mensagem nao estoura nem reabre o robo');
// aguardando sem versao registrada: tambem nao estoura
zera();
wa_processar(ev('mensagem', 'oi'));
wa_conversa_set($WA, ['fluxo_versao_id' => null]);
ok(wa_processar(ev('mensagem', 'oi')) === 'card_perdido' && conv()['estado'] === 'humano', 'aguardando sem versao vai para a humana');

/* ============ O teto de passos ============ */
ok(WA_FLUXO_MAX_PASSOS === 10, 'o teto de passos por mensagem e 10');
zera();
add_fluxo('f-loop', 'palavra', 'giro', mini('t1', [
    't1' => fx_card('t1', 'texto', ['texto' => 'um', 'proximo' => 't2']),
    't2' => fx_card('t2', 'texto', ['texto' => 'dois', 'proximo' => 't1']),
]));
$r = wa_processar(ev('mensagem', 'giro'));
$passos_executados = count($ENVIADAS);
ok($passos_executados <= WA_FLUXO_MAX_PASSOS, 'a cadeia para no teto, nao em loop infinito');
ok($passos_executados === WA_FLUXO_MAX_PASSOS && $r === 'teto_passos', 'e para exatamente nele');
ok(conv()['estado'] === 'humano', 'e a conversa vai para a humana em vez de reiniciar o laco na proxima mensagem');
// o salto de fluxo conta contra o mesmo teto
zera();
add_fluxo('f-a', 'palavra', 'ciclo', mini('a1', ['a1' => fx_card('a1', 'ir_para_fluxo', ['config' => ['fluxo' => 'f-b']])]));
add_fluxo('f-b', 'chamado', null, mini('b1', ['b1' => fx_card('b1', 'ir_para_fluxo', ['config' => ['fluxo' => 'f-a']])]));
ok(wa_processar(ev('mensagem', 'ciclo')) === 'teto_passos', 'ciclo de saltos entre fluxos tambem para no teto');

/* ============ ir_para_fluxo, de ponta a ponta ============ */
zera();
wa_motor_set_anuncios(['AD1' => 'turquia']);
add_fluxo('f-ponte', 'anuncio', 'AD1', mini('p1', [
    'p1' => fx_card('p1', 'texto', ['texto' => 'Vou te encaminhar.', 'proximo' => 'p2']),
    'p2' => fx_card('p2', 'ir_para_fluxo', ['config' => ['fluxo' => 'f-espera']]),
]));
add_fluxo('f-espera', 'chamado', null, mini('e1', [
    'e1' => fx_card('e1', 'marca', ['config' => ['espera' => '+destino'], 'proximo' => 'e2']),
    'e2' => fx_card('e2', 'fim', ['texto' => 'Avisamos quando houver data nova.']),
]));
ok(wa_processar(ev('mensagem', 'oi', ['ad_id' => 'AD1'])) === 'fluxo', 'o salto roda');
ok(count($ENVIADAS) === 2 && $ENVIADAS[1][2] === 'Avisamos quando houver data nova.', 'as mensagens dos dois fluxos saem, na ordem');
ok(conv()['fluxo_versao_id'] === 'v-f-espera', 'entrar em outro fluxo carimba a versao DELE');
ok(conv()['estado'] === 'encerrado', 'e o fim do outro fluxo encerra');
ok($DB['po_leads'][0]['espera'] === ['turquia'], 'o contexto atravessou o salto: o destino foi para a lista de espera');
// Salto a partir de uma conversa que ja estava em andamento (a entrada nao
// carimbou nada nesta mensagem): o salto e quem carimba a versao do destino.
zera();
add_fluxo('f-menu', 'palavra', 'menu', mini('m1', [
    'm1' => fx_card('m1', 'botoes', ['texto' => 'Quer entrar na lista de espera?', 'fallback' => 'm1',
        'opcoes' => [['id' => 'sim', 'rotulo' => 'Sim', 'destino' => 'm2']]]),
    'm2' => fx_card('m2', 'ir_para_fluxo', ['config' => ['fluxo' => 'f-espera']]),
]));
add_fluxo('f-espera', 'chamado', null, mini('e1', ['e1' => fx_card('e1', 'fim', ['texto' => 'Avisamos.'])]));
wa_processar(ev('mensagem', 'menu'));
ok(conv()['fluxo_versao_id'] === 'v-f-menu', 'preparo: a conversa entrou pelo fluxo do menu');
ok(wa_processar(clica('sim')) === 'fluxo', 'o clique atravessa o salto');
ok(conv()['fluxo_versao_id'] === 'v-f-espera' && conv()['estado'] === 'encerrado', 'e a conversa passa a pertencer ao fluxo de destino');
// destino ausente ou desligado cai no mesmo caminho do card perdido
zera();
add_fluxo('f-quebra', 'palavra', 'quebra', mini('q1', ['q1' => fx_card('q1', 'ir_para_fluxo', ['config' => ['fluxo' => 'nao-existe']])]));
ok(wa_processar(ev('mensagem', 'quebra')) === 'card_perdido' && conv()['estado'] === 'humano', 'salto para fluxo ausente vai para a humana');
zera();
add_fluxo('f-quebra', 'palavra', 'quebra', mini('q1', ['q1' => fx_card('q1', 'ir_para_fluxo', ['config' => ['fluxo' => 'f-off']])]));
add_fluxo('f-off', 'chamado', null, mini('o1', ['o1' => fx_card('o1', 'fim', ['texto' => 'x'])]), ['ativo' => false]);
ok(wa_processar(ev('mensagem', 'quebra')) === 'card_perdido' && conv()['estado'] === 'humano', 'salto para fluxo desligado tambem');

/* ============ order= fixo na escolha do fluxo ============ */
zera();
// "cruzeiro" criado depois, mas fisicamente primeiro; "natal" mais antigo.
add_fluxo('f-cr', 'palavra', 'cruzeiro', mini('c1', ['c1' => fx_card('c1', 'fim', ['texto' => 'Cruzeiro'])]), ['created_at' => '2026-10-05T00:00:00Z']);
add_fluxo('f-na', 'palavra', 'natal', mini('n1', ['n1' => fx_card('n1', 'fim', ['texto' => 'Natal'])]), ['created_at' => '2026-10-02T00:00:00Z']);
ok(wa_processar(ev('mensagem', 'cruzeiro de natal')) === 'fluxo', 'mensagem que casa dois fluxos');
ok(conv()['fluxo_versao_id'] === 'v-f-na', 'vence sempre o fluxo mais antigo, nao o que o banco devolveu primeiro');
$consulta_fluxos = '';
foreach ($QUERIES as $q) if ($q[0] === 'po_wa_fluxos') $consulta_fluxos = $q[1];
ok(strpos($consulta_fluxos, 'order=') !== false, 'a consulta dos fluxos pede order=');

/* ============ Respostas fora do esperado nunca viram silencio ============ */
zera();
wa_processar(ev('mensagem', 'oi'));
ok(wa_processar(ev('mensagem', '', ['tipo_msg' => 'audio'])) === 'fluxo', 'audio na primeira vez vai para o fallback');
ok(conv()['card_atual'] === 'outra' && (int) conv()['tentativas'] === 1, 'conta a tentativa');
ok(wa_processar(ev('mensagem', '', ['tipo_msg' => 'audio'])) === 'fluxo', 'audio de novo');
ok(conv()['estado'] === 'humano', 'na segunda o cliente vai para a humana, nao para o silencio');

echo "test-wa-fluxo-leis: ok\n";
