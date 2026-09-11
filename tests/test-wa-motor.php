<?php
require __DIR__ . '/../lib/wa-motor.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

/* Simulador: o banco vira um array em memoria e o envio vira uma lista.
   Nenhum teste toca a rede. */
$DB = ['po_wa_conversas' => [], 'po_wa_contatos' => [], 'po_leads' => [], 'po_wa_mensagens' => [],
       'po_wa_textos' => [
           ['chave'=>'envio_pdf','texto'=>'Segue o roteiro completo do {roteiro}.'],
           ['chave'=>'perguntas','texto'=>'1. Tem disponibilidade? 2. Ja viajou em grupo?'],
           ['chave'=>'qualificado','texto'=>'Perfeito, {nome}.'],
           ['chave'=>'menu','texto'=>'Sobre qual viagem?'],
           ['chave'=>'sem_data','texto'=>'Entendo, {nome}.'],
       ]];
$ENVIADAS = [];

wa_motor_set_deps([
    'roteiros' => function () {
        return [['slug'=>'turquia','titulo'=>'Turquia com Antalia','pdf_url'=>'https://x/t.pdf','data_label'=>'10/05/27'],
                ['slug'=>'escandinavia','titulo'=>'O melhor da Escandinavia','pdf_url'=>'https://x/e.pdf','data_label'=>'02/06/27']];
    },
    'select' => function ($tabela, $query) use (&$DB) {
        if ($tabela === 'po_wa_textos') return $DB['po_wa_textos'];
        // Antes do wa_id: 'wamid=eq.' e a busca do log de saida.
        if (preg_match('/wamid=eq\.([^&]+)/', $query, $m)) {
            $w = urldecode($m[1]);
            return array_values(array_filter($DB[$tabela] ?? [], fn($l) => ($l['wamid'] ?? '') === $w));
        }
        if (preg_match('/wa_id=eq\.([^&]+)/', $query, $m)) {
            $wa = urldecode($m[1]);
            return array_values(array_filter($DB[$tabela], fn($l) => ($l['wa_id'] ?? '') === $wa));
        }
        return $DB[$tabela] ?? [];
    },
    'insert' => function ($tabela, $linha) use (&$DB) {
        $linha['id'] = $tabela . '-' . count($DB[$tabela] ?? []);
        $DB[$tabela][] = $linha;
        return $linha;
    },
    'update' => function ($tabela, $query, $campos) use (&$DB) {
        preg_match('/wa_id=eq\.([^&]+)/', $query, $m);
        $wa = isset($m[1]) ? urldecode($m[1]) : null;
        foreach ($DB[$tabela] as $i => $l) {
            if ($wa === null || ($l['wa_id'] ?? '') === $wa) $DB[$tabela][$i] = array_merge($l, $campos);
        }
        return true;
    },
    'send_text' => function ($para, $texto) use (&$ENVIADAS) { $ENVIADAS[] = ['text', $para, $texto]; return ['ok'=>true,'wamid'=>'w'.count($ENVIADAS),'erro'=>null]; },
    'send_doc'  => function ($para, $url, $arq, $leg) use (&$ENVIADAS) { $ENVIADAS[] = ['doc', $para, $url, $leg]; return ['ok'=>true,'wamid'=>'w'.count($ENVIADAS),'erro'=>null]; },
    'send_list' => function ($para, $corpo, $botao, $itens) use (&$ENVIADAS) { $ENVIADAS[] = ['list', $para, count($itens)]; return ['ok'=>true,'wamid'=>'w'.count($ENVIADAS),'erro'=>null]; },
]);

$WA = '+5548996048882';
function ev($tipo, $texto, $extra = []) {
    global $WA;
    return array_merge(['tipo'=>$tipo,'wa_id'=>$WA,'wamid'=>'wamid.'.md5($texto.mt_rand()),
        'tipo_msg'=>'text','texto'=>$texto,'nome'=>'Maria','ad_id'=>null,'ctwa_clid'=>null,'ts'=>time()], $extra);
}

// --- 1. primeira mensagem com destino reconhecivel: manda PDF + perguntas
$acao = wa_processar(ev('mensagem', 'oi queria saber da Turquia'));
ok($acao === 'enviou_roteiro', "primeira mensagem com destino manda roteiro (deu: $acao)");
ok($ENVIADAS[0][0] === 'doc', 'mandou o PDF primeiro');
ok(strpos($ENVIADAS[0][2], 't.pdf') !== false, 'o PDF e o do roteiro certo');
ok($ENVIADAS[1][0] === 'text', 'depois o texto');
ok(strpos($ENVIADAS[1][2], 'disponibilidade') !== false, 'as duas perguntas vao junto, sem pedir licenca');
ok($DB['po_wa_conversas'][0]['estado'] === 'enviado_roteiro', 'estado avancou');
ok($DB['po_leads'][0]['status'] === 'novo', 'lead entra como novo');
ok($DB['po_leads'][0]['roteiro'] === 'Turquia com Antalia', 'lead guarda o roteiro');

// --- 2. sim para as duas perguntas: qualifica
$ENVIADAS = [];
$acao = wa_processar(ev('mensagem', 'tenho sim, e ja viajei em grupo pra Portugal'));
ok($acao === 'qualificou', "sim e sim qualifica (deu: $acao)");
ok($DB['po_wa_conversas'][0]['estado'] === 'qualificado', 'estado qualificado');
ok($DB['po_leads'][0]['status'] === 'atendimento', 'lead vai para atendimento');
ok($DB['po_leads'][0]['qualif_data'] === true, 'carimba disponibilidade');

// --- 3. o eco cala o robo naquele contato, para sempre
$ENVIADAS = [];
$acao = wa_processar(ev('eco', 'Bom dia Maria, aqui e a Simone'));
ok($acao === 'silenciou', "eco silencia (deu: $acao)");
ok($DB['po_wa_conversas'][0]['estado'] === 'humano', 'estado humano');
ok($ENVIADAS === [], 'o robo nao respondeu nada');

$acao = wa_processar(ev('mensagem', 'e quanto custa?'));
ok($acao === 'silenciado', "com humano no comando o robo continua calado (deu: $acao)");
ok($ENVIADAS === [], 'nada enviado mesmo com pergunta nova');

// --- 4. atalho de proposta, vindo do eco
$acao = wa_processar(ev('eco', '#proposta'));
ok($acao === 'proposta', "atalho #proposta move o funil (deu: $acao)");
ok($DB['po_leads'][0]['status'] === 'negociacao', 'lead em negociacao');
ok(!empty($DB['po_leads'][0]['proposta_at']), 'carimba proposta_at');

// --- 5. atalho de fechamento com valor
$acao = wa_processar(ev('eco', '#fechou 22900'));
ok($acao === 'venda', "atalho #fechou registra venda (deu: $acao)");
ok($DB['po_leads'][0]['status'] === 'venda', 'lead em venda');
ok((float) $DB['po_leads'][0]['venda'] === 22900.0, 'valor da venda gravado');
ok(!empty($DB['po_leads'][0]['venda_at']), 'carimba venda_at, que alimenta o ciclo');

// --- 6. PDF enviado por ela tambem marca proposta
$DB['po_wa_conversas'] = []; $DB['po_leads'] = []; $ENVIADAS = [];
wa_processar(ev('mensagem', 'me fala da escandinavia'));
wa_processar(ev('mensagem', 'sim, e ja viajei em grupo'));
$acao = wa_processar(ev('eco', 'proposta.pdf', ['tipo_msg' => 'document']));
ok($acao === 'proposta', "PDF enviado por ela marca proposta (deu: $acao)");

// --- 7. texto sem destino reconhecivel cai no menu
$DB['po_wa_conversas'] = []; $DB['po_leads'] = []; $ENVIADAS = [];
$acao = wa_processar(ev('mensagem', 'oi boa tarde'));
ok($acao === 'menu', "sem destino manda o menu (deu: $acao)");
ok($ENVIADAS[0][0] === 'list', 'mandou lista');
ok($ENVIADAS[0][2] === 2, 'com os dois roteiros ativos');

// --- 7b. o menu nao se repete a cada mensagem nao reconhecida (revisao: Important 2)
$ENVIADAS = [];
foreach (['quanto custa?', 'e voces tem parcelamento?', 'obrigada'] as $frase) {
    $acao = wa_processar(ev('mensagem', $frase));
    ok($acao === 'aguardando_menu', "menu ja mandado nao vira segundo menu em \"$frase\" (deu: $acao)");
}
ok($ENVIADAS === [], 'nenhuma lista repetida: o menu sai uma vez so');

// --- 8. escolha no menu retoma o fluxo
$ENVIADAS = [];
$acao = wa_processar(ev('mensagem', 'turquia', ['tipo_msg' => 'list_reply']));
ok($acao === 'enviou_roteiro', "escolha no menu manda o roteiro (deu: $acao)");

// --- 9. nao tem data: desqualifica sem gastar o tempo dela
$DB['po_wa_conversas'] = []; $DB['po_leads'] = []; $ENVIADAS = [];
wa_processar(ev('mensagem', 'quero saber da turquia'));
$acao = wa_processar(ev('mensagem', 'nessa data nao consigo, infelizmente'));
ok($acao === 'desqualificou', "sem data desqualifica (deu: $acao)");
ok($DB['po_leads'][0]['status'] === 'perdido', 'lead vai para perdido');

// --- 10. anuncio define o roteiro sem perguntar nada
$DB['po_wa_conversas'] = []; $DB['po_leads'] = []; $ENVIADAS = [];
wa_motor_set_anuncios(['120210000' => 'escandinavia']);
$acao = wa_processar(ev('mensagem', 'oi', ['ad_id' => '120210000', 'ctwa_clid' => 'ARxyz']));
ok($acao === 'enviou_roteiro', "anuncio identifica o roteiro sem menu (deu: $acao)");
ok(strpos($ENVIADAS[0][2], 'e.pdf') !== false, 'mandou o PDF do roteiro do anuncio');
ok($DB['po_leads'][0]['ctwa_clid'] === 'ARxyz', 'atribuicao do anuncio gravada no lead');

// --- 11. marcador do link do site tem prioridade sobre o texto
$DB['po_wa_conversas'] = []; $DB['po_leads'] = []; $ENVIADAS = [];
$acao = wa_processar(ev('mensagem', 'Quero saber sobre a escandinavia [r:turquia]'));
ok(strpos($ENVIADAS[0][2], 't.pdf') !== false, 'marcador ganha do texto');

// --- 12. roteiro sem PDF nao pode travar: manda o link da pagina
$DB['po_wa_conversas'] = []; $DB['po_leads'] = []; $ENVIADAS = [];
wa_motor_set_deps(['roteiros' => function () { return [['slug'=>'turquia','titulo'=>'Turquia','pdf_url'=>null,'data_label'=>'10/05/27']]; }]);
$acao = wa_processar(ev('mensagem', 'quero saber da turquia'));
ok($acao === 'enviou_roteiro', 'roteiro sem PDF ainda responde');
ok($ENVIADAS[0][0] === 'text', 'sem PDF manda texto com o link');
ok(strpos($ENVIADAS[0][2], '/roteiros/turquia') !== false, 'o link e o da pagina do roteiro');

// --- 13. eco de atalho a partir de enviado_roteiro tambem silencia (revisao: Critical 1)
$DB['po_wa_conversas'] = []; $DB['po_leads'] = []; $ENVIADAS = [];
wa_motor_set_deps([
    'roteiros' => function () {
        return [['slug'=>'turquia','titulo'=>'Turquia com Antalia','pdf_url'=>'https://x/t.pdf','data_label'=>'10/05/27'],
                ['slug'=>'escandinavia','titulo'=>'O melhor da Escandinavia','pdf_url'=>'https://x/e.pdf','data_label'=>'02/06/27']];
    },
]);
wa_processar(ev('mensagem', 'quero saber da turquia'));
ok($DB['po_wa_conversas'][0]['estado'] === 'enviado_roteiro', 'preparo: estado enviado_roteiro antes do eco');
$ENVIADAS = [];
$acao = wa_processar(ev('eco', '#proposta'));
ok($acao === 'proposta', "atalho por eco continua movendo o funil (deu: $acao)");
ok($DB['po_wa_conversas'][0]['estado'] === 'humano', 'o eco tambem silencia o robo, mesmo vindo de enviado_roteiro');
$ENVIADAS = [];
$acao = wa_processar(ev('mensagem', 'tenho sim, e ja viajei em grupo'));
ok($acao === 'silenciado', "com humano no comando o robo nao qualifica por cima da dona (deu: $acao)");
ok($ENVIADAS === [], 'nada enviado: o robo nao responde a uma pergunta que a humana ja esta tratando');
ok($DB['po_wa_conversas'][0]['estado'] === 'humano', 'estado continua humano');

// --- 14. eco de documento a partir de qualificado tambem silencia (revisao: Critical 1)
$DB['po_wa_conversas'] = []; $DB['po_leads'] = []; $ENVIADAS = [];
wa_processar(ev('mensagem', 'quero saber da turquia'));
wa_processar(ev('mensagem', 'sim, e ja viajei em grupo'));
ok($DB['po_wa_conversas'][0]['estado'] === 'qualificado', 'preparo: estado qualificado antes do eco');
$ENVIADAS = [];
$acao = wa_processar(ev('eco', 'proposta.pdf', ['tipo_msg' => 'document']));
ok($acao === 'proposta', "documento por eco continua marcando proposta (deu: $acao)");
ok($DB['po_wa_conversas'][0]['estado'] === 'humano', 'o eco tambem silencia o robo, mesmo vindo de qualificado');
$ENVIADAS = [];
$acao = wa_processar(ev('mensagem', 'oi, tudo bem?'));
ok($acao === 'silenciado', "com humano no comando o robo nao reenvia o menu por cima da negociacao (deu: $acao)");
ok($ENVIADAS === [], 'nada enviado: nem menu, nem qualquer outra coisa');
ok($DB['po_wa_conversas'][0]['estado'] === 'humano', 'estado continua humano, nao volta para aguardando_roteiro');

// --- 15. envio que falha nao avanca o estado nem marca o lead (revisao: Critical 2)
$DB['po_wa_conversas'] = []; $DB['po_leads'] = []; $ENVIADAS = [];
wa_motor_set_deps(['send_doc' => function ($para, $url, $arq, $leg) {
    return ['ok' => false, 'wamid' => null, 'erro' => 'timeout'];
}]);
$acao = wa_processar(ev('mensagem', 'quero saber da turquia'));
ok($acao === 'falha_envio', "envio que falha nao finge que o roteiro saiu (deu: $acao)");
ok($DB['po_wa_conversas'][0]['estado'] === 'novo', 'estado NAO avancou para enviado_roteiro');
ok(!isset($DB['po_leads'][0]['roteiro']), 'lead nao fica marcado com um roteiro que o cliente nunca recebeu');
ok($ENVIADAS === [], 'as perguntas nao saem depois de um roteiro que falhou');
// Restaura o envio de documento (nao ha teste depois que dependa disso, mas
// evita que uma falha injetada aqui vaze pra frente se a ordem mudar).
wa_motor_set_deps(['send_doc' => function ($para, $url, $arq, $leg) use (&$ENVIADAS) {
    $ENVIADAS[] = ['doc', $para, $url, $leg];
    return ['ok' => true, 'wamid' => 'w' . count($ENVIADAS), 'erro' => null];
}]);

/* --- 19. cliente que volta a falar zera o relogio do timeout
   (revisao: Important 1). O cron decide pelo lembrete_at e ignora o
   aguardando_desde depois que ele existe: um lembrete velho encerrava
   como perdida uma conversa em que o cliente tinha escrito minutos antes. */
$DB['po_wa_conversas'] = []; $DB['po_leads'] = []; $DB['po_wa_mensagens'] = []; $ENVIADAS = [];
wa_processar(ev('mensagem', 'quero saber da turquia'));
$DB['po_wa_conversas'][0]['lembrete_at'] = gmdate('c', time() - 86400);
wa_processar(ev('mensagem', 'oi, ainda estou pensando'));
ok($DB['po_wa_conversas'][0]['lembrete_at'] === null, 'mensagem do cliente zera o lembrete_at');

/* --- 20. catalogo vazio manda texto, nunca uma lista impossivel
   (revisao: Important 10). A Graph API recusa lista com zero linhas: a
   mensagem inteira nao sai e o cliente escreve sem receber resposta. */
$DB['po_wa_conversas'] = []; $DB['po_leads'] = []; $DB['po_wa_mensagens'] = []; $ENVIADAS = [];
wa_motor_set_deps(['roteiros' => function () { return []; }]);
$acao = wa_processar(ev('mensagem', 'oi, boa tarde'));
ok($acao === 'menu_texto', "sem roteiro ativo o robo pergunta o destino por texto (deu: $acao)");
ok(count($ENVIADAS) === 1 && $ENVIADAS[0][0] === 'text', 'mandou texto, nao lista');
ok($DB['po_wa_conversas'][0]['estado'] === 'aguardando_roteiro', 'e fica esperando o destino');
wa_motor_set_deps([
    'roteiros' => function () {
        return [['slug'=>'turquia','titulo'=>'Turquia com Antalia','pdf_url'=>'https://x/t.pdf','data_label'=>'10/05/27'],
                ['slug'=>'escandinavia','titulo'=>'O melhor da Escandinavia','pdf_url'=>'https://x/e.pdf','data_label'=>'02/06/27']];
    },
]);

/* --- 21. PDF que sai com as perguntas falhando nao pode ser reenviado
   (conhecido 7). O estado avanca assim mesmo: o cliente com o roteiro na
   mao e sem as perguntas e melhor do que o mesmo PDF chegando duas vezes. */
$DB['po_wa_conversas'] = []; $DB['po_leads'] = []; $DB['po_wa_mensagens'] = []; $ENVIADAS = [];
$TEXTO_OK = false;
wa_motor_set_deps(['send_text' => function ($para, $texto) use (&$ENVIADAS, &$TEXTO_OK) {
    if (!$TEXTO_OK) return ['ok' => false, 'wamid' => null, 'erro' => 'timeout'];
    $ENVIADAS[] = ['text', $para, $texto];
    return ['ok' => true, 'wamid' => 'w' . count($ENVIADAS), 'erro' => null];
}]);
$acao = wa_processar(ev('mensagem', 'quero saber da turquia'));
ok($acao === 'falha_perguntas', "a falha das perguntas e registrada como tal (deu: $acao)");
ok($DB['po_wa_conversas'][0]['estado'] === 'enviado_roteiro', 'o estado avanca porque o PDF saiu de verdade');
ok($DB['po_wa_conversas'][0]['roteiro_slug'] === 'turquia', 'e o roteiro enviado fica registrado');
$ENVIADAS = []; $TEXTO_OK = true;
$acao = wa_processar(ev('mensagem', 'oi'));
ok($acao !== 'enviou_roteiro', "a proxima mensagem NAO reenvia o PDF (deu: $acao)");
foreach ($ENVIADAS as $e) ok($e[0] !== 'doc', 'nenhum documento sai de novo');
wa_motor_set_deps(['send_text' => function ($para, $texto) use (&$ENVIADAS) {
    $ENVIADAS[] = ['text', $para, $texto];
    return ['ok' => true, 'wamid' => 'w' . count($ENVIADAS), 'erro' => null];
}]);

// --- interpretacao da resposta sobre a data (a unica que desqualifica)
ok(wa_resposta_data('sim')                    === true,  'sim');
ok(wa_resposta_data('Tenho sim!')             === true,  'tenho sim');
ok(wa_resposta_data('claro, pode ser')        === true,  'claro');
ok(wa_resposta_data('nao consigo nessa data') === false, 'nao');
ok(wa_resposta_data('infelizmente nao')       === false, 'infelizmente nao');
ok(wa_resposta_data('qual o valor?')          === null,  'pergunta nao e sim nem nao');

// --- incerteza nao pode virar decisao (revisao: Important 2, medido com o publico real)
ok(wa_resposta_data('acho que sim, mas preciso ver com meu marido') === null, 'duvida com o marido nao qualifica sozinha');
ok(wa_resposta_data('nao sei ainda')                                === null, 'nao sei ainda nao e um nao');
ok(wa_resposta_data('sim, mas so em outubro')                       === null, 'confirmacao com ressalva de data fica em duvida');
ok(wa_resposta_data('pode ser que sim')                             === null, '"pode ser que" e duvida, diferente de "pode ser" sozinho');

/* --- "tenho a data, mas nunca viajei em grupo" e lead BOM (revisao: Critical 2)
   Sao as quatro frases medidas na revisao, que antes viravam todas
   desqualificado + perdido. Numa operadora que vende viagem em grupo,
   quem nunca viajou em grupo e exatamente o cliente-alvo. */
$mistas = [
    'Tenho disponibilidade sim, mas nunca viajei em grupo',
    'Tenho a data sim. Nunca viajei em grupo, seria a primeira vez',
    '1 sim 2 nao',
    'Posso sim, e nao, nunca viajei em grupo',
];
foreach ($mistas as $frase) {
    ok(wa_resposta_data($frase)  === true,  "a data esta confirmada em: $frase");
    ok(wa_resposta_grupo($frase) === false, "e o 'nunca em grupo' fica so como informacao em: $frase");
}

// Um "sim" solto responde a pergunta 1. A 2 continua sem resposta: carimbar
// qualif_grupo aqui seria inventar dado que a dona le no painel como do cliente.
ok(wa_resposta_data('sim')  === true, 'sim solto confirma a data');
ok(wa_resposta_grupo('sim') === null, 'sim solto NAO diz nada sobre viagem em grupo');
ok(wa_resposta_grupo('sim, ja viajei em grupo') === true, 'evidencia explicita de grupo e lida');

// --- 16. resposta mista qualifica pelo lado da data (revisao: Critical 2)
$DB['po_wa_conversas'] = []; $DB['po_leads'] = []; $ENVIADAS = [];
wa_processar(ev('mensagem', 'quero saber da turquia'));
$ENVIADAS = [];
$acao = wa_processar(ev('mensagem', 'Tenho disponibilidade sim, mas nunca viajei em grupo'));
ok($acao === 'qualificou', "tem data e nunca viajou em grupo QUALIFICA (deu: $acao)");
ok($DB['po_leads'][0]['status'] === 'atendimento', 'lead vai para atendimento, nao para perdido');
ok($DB['po_leads'][0]['qualif_data'] === true, 'carimba a data');
ok($DB['po_leads'][0]['qualif_grupo'] === false, 'e registra que nunca viajou em grupo, sem desqualificar');

/* --- 18. o eco do proprio envio do robo nao pode silenciar o robo
   (revisao: Critical 3). Nada no payload da Meta separa "a dona digitou"
   de "nos enviamos pela API". Se a coexistencia ecoar o que sai pela API,
   sem esta guarda o primeiro PDF silenciava o robo em toda conversa. */
$DB['po_wa_conversas'] = []; $DB['po_leads'] = []; $DB['po_wa_mensagens'] = []; $ENVIADAS = [];
wa_processar(ev('mensagem', 'quero saber da turquia'));
$saidas = array_values(array_filter($DB['po_wa_mensagens'], fn($l) => $l['autor'] === 'robo'));
ok(count($saidas) === 2, 'o PDF e as perguntas entram no log como saida do robo (deu: ' . count($saidas) . ')');
ok($saidas[0]['direcao'] === 'out' && $saidas[0]['tipo'] === 'document', 'o PDF fica registrado como documento de saida');
$meu_wamid = $saidas[0]['wamid'];
$ENVIADAS = [];
$acao = wa_processar(ev('eco', 'Segue o roteiro completo', ['wamid' => $meu_wamid]));
ok($acao === 'eco_do_robo', "eco com wamid nosso e ignorado (deu: $acao)");
ok($DB['po_wa_conversas'][0]['estado'] === 'enviado_roteiro', 'o robo NAO foi silenciado pelo proprio envio');
// E o eco de verdade, com wamid que nao e nosso, continua silenciando.
$acao = wa_processar(ev('eco', 'Oi, aqui e a Simone'));
ok($acao === 'silenciou', "eco de wamid desconhecido continua sendo handoff (deu: $acao)");
ok($DB['po_wa_conversas'][0]['estado'] === 'humano', 'a fala da dona silencia o robo');

// --- 17. "sim" solto qualifica sem inventar o dado do grupo
$DB['po_wa_conversas'] = []; $DB['po_leads'] = []; $ENVIADAS = [];
wa_processar(ev('mensagem', 'quero saber da turquia'));
$acao = wa_processar(ev('mensagem', 'sim'));
ok($acao === 'qualificou', "sim solto qualifica (deu: $acao)");
ok(!array_key_exists('qualif_grupo', $DB['po_leads'][0]), 'qualif_grupo nao e gravado sem evidencia');

echo "test-wa-motor OK\n";
