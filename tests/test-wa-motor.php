<?php
require __DIR__ . '/../lib/wa-motor.php';
require __DIR__ . '/fixtures/fluxo-motor.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

/* Simulador: o banco vira um array em memoria e o envio vira uma lista
   (tests/fixtures/fluxo-motor.php, que tambem traz o fluxo de triagem
   publicado e ligado). Nenhum teste toca a rede.

   Desde o interpretador (Plano A) o motor nao tem mais estados proprios de
   roteiro: quem decide o proximo passo e o grafo. Estes cenarios percorrem a
   triagem de ponta a ponta; as leis do motor tem arquivo proprio em
   tests/test-wa-fluxo-leis.php. */
$DB = fx_banco_inicial();
$ENVIADAS = [];
$QUERIES = [];

/* wa_camp_recibo (chamado por wa_processar no ramo de status, Task 7) usa
   wa_db_select_estrito/wa_db_update de wa-db.php diretamente, por fora da
   injecao de dependencia (wa_motor_set_deps) - de proposito, e a mesma
   funcao que tests/test-wa-camp-recibo.php testa isolada. Sem este mock, o
   teste de status faria uma chamada de rede DE VERDADE para o Supabase de
   producao (SUPABASE_URL vem hardcoded em po-data.php mesmo sem
   config.local.php), violando a regra do projeto de que nenhum teste toca a
   rede.

   Alem de responder, o transporte REGISTRA toda escrita (POST/PATCH) que
   passar por ele fora da injecao de dependencia. E o que permite ao teste de
   status flagrar uma escrita em po_wa_mensagens pelo caminho de baixo: hoje
   nada impede alguem de acrescentar `require_once .../wa-webhook.php` em
   wa-motor.php e fazer o ramo de status chamar wa_registra_evento - a
   guarda de whatsapp.php (travada por assercao de fonte em
   tests/test-wa-webhook.php) fica intacta e o defeito reaparece por
   dentro do motor, em silencio. */
$ESCRITAS_MENSAGENS = [];
wa_db_set_transport(function ($metodo, $url, $corpo) use (&$ESCRITAS_MENSAGENS) {
    if (in_array($metodo, ['POST', 'PATCH'], true) && strpos($url, 'po_wa_mensagens') !== false) {
        // Registra em vez de falhar direto: quem acusa isto e a assercao
        // dedicada do teste de status (`ok(!$ESCRITAS_MENSAGENS, ...)`).
        $ESCRITAS_MENSAGENS[] = [$metodo, $url, $corpo];
        return ['status' => 200, 'body' => '[]'];
    }
    /* So po_wa_envios e esperado aqui, pelo recibo de entrega (Task 7): no
       fluxo correto e a UNICA tabela que chega pela camada crua neste
       arquivo. Um simulador que responde "200, vazio" para QUALQUER tabela
       transforma toda chamada direta futura a wa_db_* em sucesso silencioso
       - foi assim que o vazamento de rede real para o Supabase de producao
       passou despercebido da primeira vez (ver task-7-report.md). */
    if (strpos($url, 'po_wa_envios') === false) {
        fwrite(STDERR, "ASSERT: chamada de banco nao simulada no teste do motor: $metodo $url\n");
        exit(1);
    }
    return ['status' => 200, 'body' => '[]'];
});

fx_instala();

$WA = '+5548900000001';
function ev($tipo, $texto, $extra = []) {
    global $WA;
    return array_merge(['tipo'=>$tipo,'wa_id'=>$WA,'wamid'=>'wamid.'.md5($texto.mt_rand()),
        'tipo_msg'=>'text','texto'=>$texto,'nome'=>'Maria','ad_id'=>null,'ctwa_clid'=>null,'ts'=>time()], $extra);
}
function clica($id, $tipo_msg = 'button_reply') { return ev('mensagem', $id, ['tipo_msg' => $tipo_msg]); }
function zera() {
    $GLOBALS['DB'] = fx_banco_inicial(); $GLOBALS['ENVIADAS'] = []; $GLOBALS['QUERIES'] = [];
    fx_instala();
    wa_motor_set_anuncios([]);
}

// --- 1. primeira mensagem: o fluxo responde com as boas-vindas e espera
$acao = wa_processar(ev('mensagem', 'oi queria saber da Turquia'));
ok($acao === 'fluxo', "primeira mensagem entra no fluxo (deu: $acao)");
ok($ENVIADAS[0][0] === 'buttons', 'as boas-vindas saem em botoes');
ok(strpos($ENVIADAS[0][2], 'Maria') !== false, 'com o nome da pessoa');
ok($DB['po_wa_conversas'][0]['estado'] === 'aguardando', 'a conversa espera');
ok($DB['po_wa_conversas'][0]['card_atual'] === 'boas', 'no card de boas-vindas');
ok($DB['po_wa_conversas'][0]['fluxo_versao_id'] === 'v-triagem', 'na versao publicada do fluxo');
ok($DB['po_leads'][0]['status'] === 'novo', 'lead entra como novo');

// --- 2. escolhe o roteiro: lista, PDF e a pergunta da data
$ENVIADAS = [];
ok(wa_processar(clica('rot')) === 'fluxo', 'escolher "um roteiro" abre a lista');
ok($ENVIADAS[0][0] === 'list' && $ENVIADAS[0][2] === 2, 'a lista traz os dois roteiros ativos');
$ENVIADAS = [];
ok(wa_processar(clica('turquia', 'list_reply')) === 'fluxo', 'escolher o roteiro manda o PDF');
ok($ENVIADAS[0][0] === 'doc' && strpos($ENVIADAS[0][2], 't.pdf') !== false, 'o PDF e o do roteiro certo');
ok($ENVIADAS[1][0] === 'buttons' && strpos($ENVIADAS[1][2], '10/05/27') !== false, 'e a pergunta da data sai logo depois, com a data do roteiro');
ok($DB['po_wa_conversas'][0]['card_atual'] === 'data' && $DB['po_wa_conversas'][0]['estado'] === 'aguardando', 'a conversa espera a resposta da data');
ok($DB['po_wa_conversas'][0]['roteiro_slug'] === 'turquia', 'o roteiro escolhido fica no contexto');
ok($DB['po_leads'][0]['interesses'] === ['turquia'], 'e vira interesse do lead');

// --- 3. a data serve: qualifica e a humana assume
$ENVIADAS = [];
wa_processar(clica('sim'));
ok($DB['po_leads'][0]['status'] === 'atendimento', 'lead vai para atendimento');
ok($DB['po_leads'][0]['qualif_data'] === true, 'carimba disponibilidade');
ok($DB['po_wa_conversas'][0]['card_atual'] === 'grupo', 'e pergunta do grupo');
wa_processar(clica('ja'));
ok($DB['po_wa_conversas'][0]['estado'] === 'humano', 'ao fim o fluxo entrega a conversa a humana');
ok(!empty($DB['po_wa_conversas'][0]['silenciado_at']), 'e o robo fica silenciado');

// --- 4. o eco cala o robo naquele contato, para sempre
$ENVIADAS = [];
$acao = wa_processar(ev('eco', 'Bom dia Maria, aqui e a Simone'));
ok($acao === 'ja_humano', "eco numa conversa ja entregue (deu: $acao)");
ok($ENVIADAS === [], 'o robo nao respondeu nada');
$acao = wa_processar(ev('mensagem', 'e quanto custa?'));
ok($acao === 'silenciado', "com humano no comando o robo continua calado (deu: $acao)");
ok($ENVIADAS === [], 'nada enviado mesmo com pergunta nova');

// --- 5. atalho de proposta, vindo do eco
$acao = wa_processar(ev('eco', '#proposta'));
ok($acao === 'proposta', "atalho #proposta move o funil (deu: $acao)");
ok($DB['po_leads'][0]['status'] === 'negociacao', 'lead em negociacao');
ok(!empty($DB['po_leads'][0]['proposta_at']), 'carimba proposta_at');

// --- 6. atalho de fechamento com valor
$acao = wa_processar(ev('eco', '#fechou 22900'));
ok($acao === 'venda', "atalho #fechou registra venda (deu: $acao)");
ok($DB['po_leads'][0]['status'] === 'venda', 'lead em venda');
ok((float) $DB['po_leads'][0]['venda'] === 22900.0, 'valor da venda gravado');
ok(!empty($DB['po_leads'][0]['venda_at']), 'carimba venda_at, que alimenta o ciclo');

// --- 7. PDF enviado por ela depois da entrega tambem marca proposta
zera();
wa_processar(ev('mensagem', 'me fala da escandinavia'));
wa_processar(clica('rot')); wa_processar(clica('escandinavia', 'list_reply'));
wa_processar(clica('sim')); wa_processar(clica('prim'));
$acao = wa_processar(ev('eco', 'proposta.pdf', ['tipo_msg' => 'document']));
ok($acao === 'proposta', "PDF enviado por ela marca proposta (deu: $acao)");

// --- 8. texto livre num card que espera: fallback, e a lista nao se repete
zera();
wa_processar(ev('mensagem', 'oi boa tarde'));
wa_processar(clica('rot'));
$ENVIADAS = [];
foreach (['quanto custa?'] as $frase) {
    wa_processar(ev('mensagem', $frase));
}
ok(!in_array('list', array_column($ENVIADAS, 0), true), 'a lista de roteiros sai uma vez so');
ok($DB['po_wa_conversas'][0]['card_atual'] === 'outra', 'a mensagem que o robo nao entende cai no fallback');
wa_processar(ev('mensagem', 'e voces tem parcelamento?'));
ok($DB['po_wa_conversas'][0]['estado'] === 'humano', 'e na segunda vez a conversa vai para a humana');

// --- 9. nao tem data: perde o lead sem gastar o tempo dela
zera();
wa_processar(ev('mensagem', 'quero saber da turquia'));
wa_processar(clica('rot')); wa_processar(clica('turquia', 'list_reply'));
$ENVIADAS = [];
wa_processar(clica('nao'));
ok($DB['po_leads'][0]['status'] === 'perdido', 'lead vai para perdido');
ok($DB['po_leads'][0]['qualif_data'] === false, 'com a data marcada como nao serve (e o que alimenta "perdeu por data")');
ok($DB['po_wa_conversas'][0]['estado'] === 'encerrado', 'e a conversa encerra');
ok(strpos($ENVIADAS[0][2], 'Maria') !== false, 'depois de se despedir pelo nome');

// --- 10. anuncio mapeado define o roteiro do contexto
zera();
wa_motor_set_anuncios(['120210000' => 'escandinavia']);
$acao = wa_processar(ev('mensagem', 'oi', ['ad_id' => '120210000', 'ctwa_clid' => 'ARxyz']));
ok($acao === 'fluxo', "anuncio entra pela porta (deu: $acao)");
ok($DB['po_wa_conversas'][0]['roteiro_slug'] === 'escandinavia', 'o roteiro do anuncio entra no contexto');
ok($DB['po_leads'][0]['ctwa_clid'] === 'ARxyz', 'atribuicao do anuncio gravada no lead');
ok($DB['po_leads'][0]['origem'] === 'pago', 'e a origem vira pago');

// --- 11. roteiro sem PDF nao pode travar: manda o link da pagina
zera();
wa_processar(ev('mensagem', 'oi'));
wa_processar(clica('rot'));
wa_motor_set_deps(['roteiros' => function () { return [['slug'=>'turquia','titulo'=>'Turquia','pdf_url'=>null,'data_label'=>'10/05/27']]; }]);
$ENVIADAS = [];
wa_processar(clica('turquia', 'list_reply'));
ok($ENVIADAS[0][0] === 'text', 'sem PDF manda texto com o link');
ok(strpos($ENVIADAS[0][2], '/roteiros/turquia') !== false, 'o link e o da pagina do roteiro');

// --- 12. envio que falha nao avanca o estado nem marca o lead
zera();
wa_motor_set_deps(['send_buttons' => function ($para, $corpo, $botoes) {
    return ['ok' => false, 'wamid' => null, 'erro' => 'timeout'];
}]);
$acao = wa_processar(ev('mensagem', 'quero saber da turquia'));
ok($acao === 'falha_envio', "envio que falha nao finge que a resposta saiu (deu: $acao)");
ok(empty($DB['po_wa_conversas'][0]['card_atual']) && $DB['po_wa_conversas'][0]['estado'] === 'novo', 'a conversa NAO avancou');
ok(!isset($DB['po_leads'][0]['interesses']), 'lead nao fica marcado com um roteiro que o cliente nunca recebeu');
ok($ENVIADAS === [], 'nada sai depois de um envio que falhou');

/* --- 13. cliente que volta a falar zera o relogio do timeout. O cron
   decide pelo lembrete_at e ignora o aguardando_desde depois que ele
   existe: um lembrete velho encerrava como perdida uma conversa em que o
   cliente tinha escrito minutos antes. */
zera();
wa_processar(ev('mensagem', 'quero saber da turquia'));
$DB['po_wa_conversas'][0]['lembrete_at'] = gmdate('c', time() - 86400);
wa_processar(ev('mensagem', 'oi, ainda estou pensando'));
ok($DB['po_wa_conversas'][0]['lembrete_at'] === null, 'mensagem do cliente zera o lembrete_at');
// E o aguardando_desde recomeca junto: zerar so o lembrete deixaria as 24h
// ja vencidas e o cron mandaria outro lembrete na varredura seguinte.
ok(strtotime($DB['po_wa_conversas'][0]['aguardando_desde']) >= time() - 5, 'e o relogio da espera recomeca junto');

/* --- 14. catalogo vazio manda texto, nunca uma lista impossivel. A Graph
   API recusa lista com zero linhas: a mensagem inteira nao sai e o cliente
   escreve sem receber resposta. */
zera();
wa_processar(ev('mensagem', 'oi, boa tarde'));
wa_motor_set_deps(['roteiros' => function () { return []; }]);
$ENVIADAS = [];
wa_processar(clica('rot'));
ok(count($ENVIADAS) === 1 && $ENVIADAS[0][0] === 'text', 'mandou texto, nao lista');
ok($DB['po_wa_conversas'][0]['estado'] === 'aguardando', 'e fica esperando o destino');

/* --- 15. audio nunca pode virar silencio e depois "perdido". wa_texto_msg
   devolve '' para audio, imagem e figurinha; o publico 60+ responde por
   audio. Agora o fallback repete as opcoes uma vez e na segunda chama a
   humana: o cliente nunca fica sem resposta. */
zera();
wa_processar(ev('mensagem', 'quero saber da turquia'));
$acao = wa_processar(ev('mensagem', '', ['tipo_msg' => 'audio']));
ok($acao === 'fluxo' && $DB['po_wa_conversas'][0]['card_atual'] === 'outra', 'audio recebe as opcoes de novo');
$acao = wa_processar(ev('mensagem', '', ['tipo_msg' => 'audio']));
ok($DB['po_wa_conversas'][0]['estado'] === 'humano', 'na segunda a conversa vai para atendimento humano');
$notas = $DB['po_leads'][0]['notas'] ?? [];
ok(count($notas) === 1, 'a dona ve na ficha do lead por que a conversa parou com o robo');

/* --- 16. lead criado pelo eco nasce com telefone. Quando ela inicia a
   conversa pelo celular, o robo nunca viu aquele contato: o lead entrava so
   com status e carimbo, e aparecia no painel como "(sem nome)" com telefone
   "-". */
zera();
$acao = wa_processar(ev('eco', '#proposta'));
ok($acao === 'proposta', "atalho no primeiro contato pelo celular move o funil (deu: $acao)");
ok($DB['po_leads'][0]['telefone'] === $WA, 'o lead nasce com o telefone em E.164');
ok($DB['po_leads'][0]['wa_id'] === $WA, 'e com o wa_id, que liga a conversa ao lead');

/* --- 17. o eco do proprio envio do robo nao pode silenciar o robo. Nada no
   payload da Meta separa "a dona digitou" de "nos enviamos pela API". */
zera();
wa_processar(ev('mensagem', 'quero saber da turquia'));
$saidas = array_values(array_filter($DB['po_wa_mensagens'], fn($l) => $l['autor'] === 'robo'));
ok(count($saidas) === 1, 'as boas-vindas entram no log como saida do robo (deu: ' . count($saidas) . ')');
ok($saidas[0]['direcao'] === 'out' && $saidas[0]['tipo'] === 'buttons', 'registradas como botoes de saida');
$meu_wamid = $saidas[0]['wamid'];
$acao = wa_processar(ev('eco', 'Oi, Maria', ['wamid' => $meu_wamid]));
ok($acao === 'eco_do_robo', "eco com wamid nosso e ignorado (deu: $acao)");
ok($DB['po_wa_conversas'][0]['estado'] === 'aguardando', 'o robo NAO foi silenciado pelo proprio envio');
$acao = wa_processar(ev('eco', 'Oi, aqui e a Simone'));
ok($acao === 'silenciou', "eco de wamid desconhecido continua sendo handoff (deu: $acao)");
ok($DB['po_wa_conversas'][0]['estado'] === 'humano', 'a fala da dona silencia o robo');

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

/* --- 19. evento de status (entregue/lido) nao pode virar conversa com o
   robo. wa_processar recebe TODO evento que whatsapp.php desempacota,
   status inclusive, e um robo respondendo a um recibo de entrega seria o
   pior desfecho possivel: ele trata isto na primeira linha
   (`if ($ev['tipo'] === 'status') return 'status';`), e este teste trava
   que ninguem remova essa guarda por engano. E defesa em profundidade, e
   nao substitui a asserção de fonte em tests/test-wa-webhook.php, que
   cuida do ponto de chamada. */
$DB['po_wa_conversas'] = []; $DB['po_leads'] = []; $DB['po_wa_mensagens'] = []; $ENVIADAS = [];
$ESCRITAS_MENSAGENS = [];
$acao = wa_processar([
    'tipo' => 'status', 'wa_id' => $WA, 'wamid' => 'wamid.STATUS1',
    'tipo_msg' => 'status', 'texto' => 'delivered', 'nome' => null,
    'ad_id' => null, 'ctwa_clid' => null, 'ts' => time(),
]);
ok($acao === 'status', "evento de status devolve 'status' (deu: $acao)");
ok(count($ENVIADAS) === 0, 'nenhum envio acontece para um evento de status');
ok($DB['po_wa_conversas'] === [], 'evento de status nao cria nem altera conversa');
ok($DB['po_leads'] === [], 'evento de status nao cria nem altera lead');

/* Evento de status nao pode ESCREVER em po_wa_mensagens, nunca. O wamid de um
   status e o da mensagem original e a coluna e unica: gravar ali faz o recibo e o
   eco disputarem a MESMA linha, o eco vira 'duplicado', o handoff morre e o robo
   passa a falar por cima da atendente. Hoje isso e impedido em whatsapp.php (e
   travado la por assercao de fonte), mas nada impediria o motor de reintroduzir o
   defeito por dentro. Esta assercao impede. */
ok(!$ESCRITAS_MENSAGENS,
   'evento de status nao escreve em po_wa_mensagens, nem pelo motor');
/* A assercao acima olha o transporte cru. Mas o idioma do motor para escrever nesta
   tabela e wa_call (injecao de dependencia), que no teste grava direto em $DB e nunca
   passa pelo transporte. Sem esta segunda linha, injetar o defeito real no ramo de
   status deixa a suite inteira VERDE - foi medido. */
ok($DB['po_wa_mensagens'] === [], 'nem pela injecao de dependencia');

/* ---------- pontuacao quando o nome vem vazio ----------
   O {nome} sai do perfil do WhatsApp de quem escreve (contacts[0].profile.name
   em lib/wa-webhook.php) e vem NULO quando a pessoa nao tem nome no perfil ou
   quando o payload chega sem o bloco contacts. Nos lembretes o nome vem do
   lead, que tambem pode estar vazio.

   A limpeza so juntava pontuacao IGUAL, entao "Perfeito, {nome}." virava
   "Perfeito,." e saia assim no WhatsApp do cliente. A unica forma que escapava
   era "Oi {nome},", o que na pratica obrigava todo texto da tela a comecar por
   saudacao. Os textos do seed tinham exatamente este defeito. */
$casos = [
    ['Perfeito, {nome}.',                     'Perfeito.'],
    ['Entendo, {nome}. Vou registrar.',       'Entendo. Vou registrar.'],
    ['Que boa noticia, {nome}!',              'Que boa noticia!'],
    ['Fechado, {nome}?',                      'Fechado?'],
    ['Aqui esta o roteiro do Japao, {nome}.', 'Aqui esta o roteiro do Japao.'],
    ['Oi {nome}, tudo bem?',                  'Oi, tudo bem?'],
];
foreach ($casos as $c) {
    $saiu = wa_limpa_pontuacao(preg_replace('/\{[a-z_]+\}/', '', $c[0]));
    ok($saiu === $c[1], "nome vazio em [{$c[0]}] deve virar [{$c[1]}], saiu [$saiu]");
}
// Com o nome presente nada pode ser comido.
ok(wa_limpa_pontuacao('Perfeito, Marlene.') === 'Perfeito, Marlene.',
   'com nome presente a virgula do vocativo fica de pe');
ok(wa_limpa_pontuacao('Leve documento, passagem e seguro.') === 'Leve documento, passagem e seguro.',
   'virgula comum no meio da frase nao e tocada');

/* ---------- SAIR ----------
   Defesa 3 da spec 8.1. O texto da transmissao promete "Responda SAIR para
   nao receber mais", e a promessa tem que valer.

   O casamento e ESTRITO: so a palavra sozinha. "cancelar" ficou de fora de
   proposito - numa agencia de viagem "quero cancelar" quase sempre e
   cancelar uma RESERVA, e tratar isso como descadastro tiraria da lista
   justamente quem esta em negociacao. */
ok(wa_e_saida('SAIR') === true,        'SAIR maiusculo sai');
ok(wa_e_saida('sair') === true,        'sair minusculo sai');
ok(wa_e_saida('  Sair. ') === true,    'com espaco e ponto ainda sai');
ok(wa_e_saida('parar') === true,       'parar tambem sai');
ok(wa_e_saida('descadastrar') === true,'descadastrar tambem sai');
ok(wa_e_saida('quero sair do grupo') === false,
   'a palavra no meio da frase NAO desinscreve: "sair do grupo" e outra coisa');
ok(wa_e_saida('quero cancelar minha reserva') === false,
   'cancelar reserva nunca e descadastro');
/* "cancelar" fica FORA da lista de proposito, e isto e decisao de produto e nao de
   implementacao: numa agencia de viagem "quero cancelar" quase sempre e cancelar uma
   RESERVA. Tratar como descadastro tiraria da lista justamente quem esta negociando.
   A frase inteira ja era barrada pela ancora; esta assercao e o que impede alguem
   acrescentar a palavra a lista sem perceber o que esta desfazendo. */
ok(wa_e_saida('cancelar') === false,
   'a palavra cancelar sozinha NAO descadastra: em viagem, cancelar e sobre reserva');
ok(wa_e_saida('') === false,           'texto vazio nao desinscreve');

$DB['po_wa_conversas'] = []; $DB['po_leads'] = []; $DB['po_wa_mensagens'] = []; $ENVIADAS = [];
$acao = wa_processar([
    'tipo' => 'mensagem', 'wa_id' => $WA, 'wamid' => 'wamid.SAIR1',
    'tipo_msg' => 'text', 'texto' => 'SAIR', 'nome' => 'Marlene',
    'ad_id' => null, 'ctwa_clid' => null, 'ts' => time(),
]);
ok($acao === 'opt_out', "SAIR devolve 'opt_out' (deu: $acao)");
ok(!empty($DB['po_leads'][0]['opt_out_at']), 'opt_out_at e carimbado no lead');
ok(count($ENVIADAS) === 1, 'uma confirmacao e enviada');
ok(stripos($ENVIADAS[0][2], 'não') !== false
   || stripos($ENVIADAS[0][2], 'nao') !== false,
   'a confirmacao diz que a pessoa nao recebera mais');
/* E NAO promete a volta. Nada em lugar nenhum limpa opt_out_at - nem o motor,
   nem o painel, nem o endpoint da transmissao -, entao "se um dia quiser
   voltar, e so escrever aqui" era promessa a um cliente que o sistema nao
   cumpre: ela escreve, nada acontece, e a agencia fica com a conta. Se algum
   dia existir o caminho de volta, esta linha cai junto com ele. */
ok(!preg_match('/quiser voltar|voltar a receber/i', $ENVIADAS[0][2]),
   'a confirmacao NAO promete um retorno que nenhum codigo do projeto executa');

/* SAIR tem que valer mesmo com o robo JA silenciado naquele contato: o
   silencio existe para o robo nao falar por cima da humana, nao para a
   pessoa perder o direito de sair da lista. O carimbo acontece sempre; a
   RESPOSTA e que nao sai, para nao atropelar a conversa humana. */
$DB['po_wa_conversas'] = []; $DB['po_leads'] = []; $ENVIADAS = [];
wa_conversa_set($WA, ['estado' => 'humano', 'silenciado_at' => gmdate('c')]);
$acao = wa_processar([
    'tipo' => 'mensagem', 'wa_id' => $WA, 'wamid' => 'wamid.SAIR2',
    'tipo_msg' => 'text', 'texto' => 'sair', 'nome' => 'Marlene',
    'ad_id' => null, 'ctwa_clid' => null, 'ts' => time(),
]);
ok($acao === 'opt_out', 'SAIR vale mesmo com o robo silenciado');
ok(!empty($DB['po_leads'][0]['opt_out_at']), 'o carimbo acontece mesmo silenciado');
ok(count($ENVIADAS) === 0, 'mas o robo NAO responde por cima da humana');

echo "test-wa-motor OK\n";
