<?php
/* tests/test-campanha-endpoint.php — o campanha.php inteiro, sem rede.

   Este e o endpoint que gasta dinheiro da cliente: cerca de R$ 0,31 por
   destinatario. O que precisa estar travado aqui nao e o feliz caminho, e o
   que impede uma cobranca que ninguem pediu - login, texto sem a saida,
   campanha em duplicata e publico vazio.

   Mesmo porte do tests/test-contatos-importar.php: o endpoint termina em
   exit(), entao cada caso roda num PROCESSO proprio. O transporte falso
   registra metodo, url e corpo - NUNCA os cabecalhos, que carregam a
   service_role. */

$RAIZ = dirname(__DIR__);
require_once $RAIZ . '/lib/wa-db.php';      // carrega wa-config -> po-data -> config.local
require_once $RAIZ . '/lib/po-auth.php';
require_once $RAIZ . '/lib/wa-camp-fila.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

const CE_CORPO = 'Roteiro novo para Portugal. Responda SAIR para não receber mais.';

/* Tres leads: um entra (cliente revisado com celular), um saiu (opt_out) e um
   e so fixo. Assim o resumo tem mais de um balde e a previa nao passa por
   coincidencia de lista de um elemento so. */
function ce_base() {
    return [
        ['id'=>'L1', 'nome'=>'Um',   'telefone'=>'+5548999990001', 'wa_id'=>null,
         'cliente'=>true, 'revisado'=>true, 'opt_out_at'=>null, 'payload_import'=>null],
        ['id'=>'L2', 'nome'=>'Dois', 'telefone'=>'+5548999990002', 'wa_id'=>null,
         'cliente'=>true, 'revisado'=>true, 'opt_out_at'=>'2026-09-01T00:00:00Z', 'payload_import'=>null],
        ['id'=>'L3', 'nome'=>'Tres', 'telefone'=>'+554832221111',  'wa_id'=>null,
         'cliente'=>true, 'revisado'=>true, 'opt_out_at'=>null, 'payload_import'=>null],
    ];
}

/* ============================================================
   FILHO: monta a requisicao e roda o endpoint.
============================================================ */
function ce_filho($caso) {
    global $RAIZ;
    $chamadas = [];

    // O error_log do wa_db_* iria para a stderr e se misturaria ao JSON.
    ini_set('error_log', sys_get_temp_dir() . '/po-test-campanha.log');

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['sb_token' => 'tok.valido', 'modo' => 'previa'];

    // Segredos de teste: nem a chave real nem a URL real desta maquina entram.
    $GLOBALS['SUPABASE_URL']         = 'https://teste.local';
    $GLOBALS['SUPABASE_ANON_KEY']    = 'anon-de-teste';
    $GLOBALS['SUPABASE_SERVICE_KEY'] = 'service-de-teste';

    po_auth_set_verificador(fn($u, $a, $t) => $caso !== 'sem-auth' && $t === 'tok.valido');

    /* total_confirmado = 1 porque so um lead da ce_base entra (os outros sao
       opt-out e fixo). E o numero que a tela mostrou e a cliente confirmou. */
    $criar = ['modo'=>'criar', 'nome'=>'Portugal 2027', 'total_confirmado'=>'1',
              'template'=>'po_roteiro_novo', 'corpo'=>CE_CORPO];
    switch ($caso) {
        case 'sem-auth':        $_POST = array_merge($_POST, $criar); break;
        case 'metodo-get':      $_SERVER['REQUEST_METHOD'] = 'GET'; break;
        case 'modo-invalido':   $_POST['modo'] = 'apagar-tudo'; break;
        case 'previa':          break;
        case 'criar':           $_POST = array_merge($_POST, $criar); break;
        case 'criar-sem-sair':  $_POST = array_merge($_POST, $criar, ['corpo'=>'Roteiro novo sem saida.']); break;
        case 'criar-longo':     $_POST = array_merge($_POST, $criar, ['corpo'=>str_repeat('x', 1025) . ' SAIR']); break;
        case 'criar-sem-nome':  $_POST = array_merge($_POST, $criar, ['nome'=>'']); break;
        case 'criar-duplicada': $_POST = array_merge($_POST, $criar); break;
        case 'criar-vazio':     $_POST = array_merge($_POST, $criar); break;
        case 'base-fora':       $_POST = array_merge($_POST, $criar); break;
        case 'criar-confirmado-velho':
            $_POST = array_merge($_POST, $criar, ['total_confirmado'=>'9']); break;
        case 'criar-sem-confirmado':
            $_POST = array_merge($_POST, $criar); unset($_POST['total_confirmado']); break;
        case 'criar-campanhas-ilegivel': $_POST = array_merge($_POST, $criar); break;
        case 'criar-gemea-no-banco':     $_POST = array_merge($_POST, $criar); break;
        case 'criar-fecha-falha':        $_POST = array_merge($_POST, $criar); break;
        case 'drenar-rascunho': $_POST = ['sb_token'=>'tok.valido', 'modo'=>'drenar',
                                          'campanha_id'=>'CJA']; break;
        case 'drenar-recente':  $_POST = ['sb_token'=>'tok.valido', 'modo'=>'drenar',
                                          'campanha_id'=>'CJA']; break;
        case 'drenar-espacado': $_POST = ['sb_token'=>'tok.valido', 'modo'=>'drenar',
                                          'campanha_id'=>'CJA']; break;
        case 'drenar-lote-falhou': $_POST = ['sb_token'=>'tok.valido', 'modo'=>'drenar',
                                          'campanha_id'=>'CJA']; break;
        case 'cancelar':        $_POST = ['sb_token'=>'tok.valido', 'modo'=>'cancelar',
                                          'campanha_id'=>'CJA']; break;
    }

    wa_db_set_transport(function ($metodo, $url, $corpo, $headers) use ($caso, &$chamadas) {
        $chamadas[] = ['metodo'=>$metodo, 'url'=>$url, 'corpo'=>$corpo];   // headers NUNCA

        if (strpos($url, 'po_wa_campanhas') !== false) {
            if ($metodo === 'GET') {
                /* Leitura da lista de campanhas ilegivel. Se esta linha virar
                   "lista vazia", nasce a campanha gemea pela outra porta, com
                   a base cobrada em dobro - a regra permanente do projeto e
                   que leitura de banco distingue erro de vazio. */
                if ($caso === 'criar-campanhas-ilegivel') {
                    return ['status'=>503, 'body'=>'{"message":"fora"}'];
                }
                // A conferencia de "uma campanha por vez".
                if ($caso === 'criar-duplicada') {
                    return ['status'=>200, 'body'=>json_encode([[
                        'id'=>'CJA', 'nome'=>'Ja rodando', 'status'=>'enviando', 'total'=>10]])];
                }
                // Campanha ainda montando a lista: nao pode ser drenada, e
                // PODE ser cancelada (e a saida de emergencia).
                if (in_array($caso, ['drenar-recente', 'drenar-espacado', 'drenar-lote-falhou'], true)) {
                    return ['status'=>200, 'body'=>json_encode([[
                        'id'=>'CJA', 'nome'=>'Rodando', 'status'=>'enviando',
                        'template'=>'po_roteiro_novo', 'total'=>10]])];
                }
                if ($caso === 'drenar-rascunho' || $caso === 'cancelar') {
                    return ['status'=>200, 'body'=>json_encode([[
                        'id'=>'CJA', 'nome'=>'Montando', 'status'=>'rascunho', 'total'=>0]])];
                }
                return ['status'=>200, 'body'=>'[]', 'headers'=>['content-range'=>'*/0']];
            }
            if ($metodo === 'POST') {
                // O indice unico parcial po_wa_campanhas_uma_ativa batendo: a
                // outra aba ganhou a corrida entre ler e escrever.
                if ($caso === 'criar-gemea-no-banco') {
                    return ['status'=>409, 'body'=>'{"code":"23505"}'];
                }
                return ['status'=>201, 'body'=>'[{"id":"CNOVA"}]'];
            }
            // PATCH: fecha a reserva (rascunho -> enviando)
            if ($caso === 'criar-fecha-falha') return ['status'=>500, 'body'=>'{}'];
            return ['status'=>204, 'body'=>''];
        }

        if (strpos($url, 'po_wa_envios') !== false) {
            if ($metodo === 'GET') {
                /* A ultima TENTATIVA desta campanha, que e o que espaca o
                   dreno manual. A consulta por enviado_at responde SEMPRE
                   vazio de proposito: e o cenario do lote inteiro falhado, e
                   e o que separa "ancorado na tentativa" de "ancorado no
                   sucesso". Quem ler o sucesso acha vazio e libera o clique. */
                if (strpos($url, 'order=enviado_at.desc') !== false) {
                    return ['status'=>200, 'body'=>'[]'];
                }
                if (strpos($url, 'order=enviando_at.desc') !== false) {
                    if ($caso === 'drenar-recente' || $caso === 'drenar-lote-falhou') {
                        return ['status'=>200, 'body'=>json_encode(
                            [['enviando_at'=>gmdate('c', time() - 60)]])];
                    }
                    if ($caso === 'drenar-espacado') {
                        return ['status'=>200, 'body'=>json_encode(
                            [['enviando_at'=>gmdate('c', time() - 3600)]])];
                    }
                }
                return ['status'=>200, 'body'=>'[]', 'headers'=>['content-range'=>'*/0']];
            }
            return ['status'=>201, 'body'=>'[{"id":"E1"}]'];
        }

        // po_leads: a base.
        if ($caso === 'base-fora') return ['status'=>503, 'body'=>'{"message":"fora"}'];
        preg_match('/offset=(\d+)/', $url, $m);
        $off = (int) ($m[1] ?? 0);
        if ($off > 0) return ['status'=>200, 'body'=>'[]'];
        // 'criar-vazio': base sem ninguem elegivel.
        $linhas = $caso === 'criar-vazio' ? [] : ce_base();
        return ['status'=>200, 'body'=>json_encode($linhas)];
    });

    register_shutdown_function(function () use (&$chamadas) {
        echo "\n@@CODIGO@@" . (int) http_response_code()
           . "\n@@CHAMADAS@@" . json_encode($chamadas) . "\n";
    });

    require $RAIZ . '/campanha.php';
}

if (isset($argv[1]) && $argv[1] !== '') { ce_filho($argv[1]); exit; }

/* ============================================================
   PAI: roda cada caso num processo e confere o que saiu.
============================================================ */
function ce_roda($caso) {
    $out = []; $cod = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($caso) . ' 2>&1', $out, $cod);
    $txt = implode("\n", $out);
    ok(strpos($txt, '@@CODIGO@@') !== false, "caso $caso respondeu (saida: " . substr($txt, 0, 300) . ')');
    list($corpo, $resto)  = explode('@@CODIGO@@', $txt, 2);
    list($codigo, $chams) = explode('@@CHAMADAS@@', $resto, 2);
    /* Na linha de comando, http_response_code() devolve false enquanto
       ninguem a chamou - e quem so responde ok nunca chama. Zero aqui e
       "seguiu o padrao", ou seja, 200. */
    $cod = (int) trim($codigo);
    return [
        'json'     => json_decode(trim($corpo), true),
        'codigo'   => $cod === 0 ? 200 : $cod,
        'chamadas' => json_decode(trim($chams), true) ?: [],
    ];
}
/* Escrita = POST ou PATCH. E o que nenhum caso de recusa pode ter deixado
   acontecer: recusar DEPOIS de ja ter criado a campanha seria pior que nao
   recusar, porque a campanha meia-criada bloqueia a proxima. */
function ce_escreveu($r) {
    foreach ($r['chamadas'] as $c) {
        if ($c['metodo'] === 'POST' || $c['metodo'] === 'PATCH') return true;
    }
    return false;
}

/* ---------- login ---------- */
$r = ce_roda('sem-auth');
ok($r['codigo'] === 401, 'sem login o endpoint recusa com 401 (deu: ' . $r['codigo'] . ')');
ok($r['chamadas'] === [], 'e nao toca o banco antes de conferir o login');

$r = ce_roda('metodo-get');
ok($r['codigo'] === 405, 'GET nao e aceito (deu: ' . $r['codigo'] . ')');

$r = ce_roda('modo-invalido');
ok($r['codigo'] === 400 && $r['json']['ok'] === false, 'modo desconhecido e recusado');
ok(!ce_escreveu($r), 'modo desconhecido nao escreve nada');

/* ---------- previa: mostra e NAO escreve ---------- */
$r = ce_roda('previa');
ok($r['codigo'] === 200 && $r['json']['ok'] === true, 'a previa responde ok');
ok($r['json']['resumo']['total'] === 1, 'so o lead com celular entra (deu: ' . $r['json']['resumo']['total'] . ')');
ok($r['json']['resumo']['saiu'] === 1, 'quem respondeu SAIR aparece no proprio balde');
ok($r['json']['resumo']['sem_celular'] === 1, 'o fixo aparece no balde do fixo');
ok($r['json']['custo_centavos'] === WA_CAMP_PRECO_CENTAVOS,
   'o custo e o preco vezes o publico (deu: ' . $r['json']['custo_centavos'] . ')');
ok(!ce_escreveu($r), 'a previa NAO escreve nada: ver o que aconteceria nao pode custar nada');

/* ---------- as recusas do modo criar ---------- */
$r = ce_roda('criar-sem-sair');
ok($r['codigo'] === 400, 'texto sem SAIR e recusado (deu: ' . $r['codigo'] . ')');
ok(strpos($r['json']['error'], 'SAIR') !== false, 'e o erro diz o que falta');
ok(!ce_escreveu($r), 'texto sem SAIR nao cria campanha nenhuma');

$r = ce_roda('criar-longo');
ok($r['codigo'] === 400 && !ce_escreveu($r),
   'texto acima de 1024 e recusado antes de escrever (deu: ' . $r['codigo'] . ')');

$r = ce_roda('criar-sem-nome');
ok($r['codigo'] === 400 && !ce_escreveu($r), 'campanha sem nome e recusada antes de escrever');

$r = ce_roda('criar-duplicada');
ok($r['codigo'] === 409, 'campanha em andamento bloqueia outra (deu: ' . $r['codigo'] . ')');
ok(!ce_escreveu($r),
   'e o segundo clique nao cria campanha gemea, que seria a base inteira cobrada em dobro');

$r = ce_roda('criar-vazio');
ok($r['codigo'] === 400 && !ce_escreveu($r), 'publico vazio nao vira campanha');

$r = ce_roda('base-fora');
ok($r['codigo'] === 502, 'base ilegivel devolve erro (deu: ' . $r['codigo'] . ')');
ok(!ce_escreveu($r),
   'base ilegivel NAO pode virar campanha de zero pessoa nem campanha parcial');

/* ---------- criar de verdade ---------- */
$r = ce_roda('criar');
ok($r['codigo'] === 200 && $r['json']['ok'] === true, 'o caminho feliz responde ok');
ok($r['json']['reservados'] === 1, 'reserva o publico inteiro (deu: ' . $r['json']['reservados'] . ')');
ok($r['json']['completo'] === true, 'e a reserva sai completa');

$posts = array_values(array_filter($r['chamadas'],
    fn($c) => $c['metodo'] === 'POST' && strpos($c['url'], 'po_wa_campanhas') !== false));
ok(count($posts) === 1, 'uma campanha criada, uma so');
$linha = json_decode($posts[0]['corpo'], true);
/* NASCE 'rascunho'. O dreno (cron e painel) so olha 'enviando', entao uma
   campanha cuja lista ainda esta sendo montada nunca comeca a enviar pela
   metade - que e o desfecho de reservar centenas de pessoas dentro de uma
   requisicao HTTP que o cPanel corta no meio. */
ok($linha['status'] === 'rascunho',
   'a campanha nasce rascunho, nao enviando (deu: ' . $linha['status'] . ')');
ok($linha['preco_centavos'] === WA_CAMP_PRECO_CENTAVOS,
   'o preco e congelado na criacao, para o relatorio antigo bater com a fatura daquele mes');
ok($linha['corpo'] === CE_CORPO, 'o corpo aprovado fica guardado junto da campanha');

$patches = array_values(array_filter($r['chamadas'],
    fn($c) => $c['metodo'] === 'PATCH' && strpos($c['url'], 'po_wa_campanhas') !== false));
ok(count($patches) === 1, 'a lista completa fecha a campanha');
ok(json_decode($patches[0]['corpo'], true)['status'] === 'enviando',
   'e so entao ela vira enviando, liberada para o dreno');

/* ---------- cancelar: a saida de emergencia ----------
   Sem ela, uma campanha travada em rascunho bloqueia TODA campanha futura
   (existe uma por vez) e so teria conserto por SQL no banco. */
$r = ce_roda('cancelar');
ok($r['codigo'] === 200 && $r['json']['cancelada'] === true, 'a campanha travada pode ser cancelada');
$pt = array_values(array_filter($r['chamadas'], fn($c) => $c['metodo'] === 'PATCH'));
ok(count($pt) === 1 && json_decode($pt[0]['corpo'], true)['status'] === 'cancelada',
   'e o status vai para cancelada, que o dreno do cron nao pega');

/* ---------- I2: o numero confirmado prende o servidor ----------
   A spec 8.2 pede que nada dispare sem o aviso de pessoas e custo. O aviso so
   vale se for VINCULANTE: sem isto ela confirma "1 pessoa, R$ 0,31" numa aba
   e a campanha sai com o publico que o servidor recalculou agora. */
$r = ce_roda('criar-confirmado-velho');
ok($r['codigo'] === 409, 'total confirmado divergente e recusado (deu: ' . $r['codigo'] . ')');
ok(!ce_escreveu($r), 'e nada e criado com um numero que ela nao viu');

$r = ce_roda('criar-sem-confirmado');
ok($r['codigo'] === 400 && !ce_escreveu($r),
   'criar sem o numero confirmado e recusado: seria gastar sem o aviso');

/* ---------- I6: leitura ilegivel nao pode virar campanha gemea ----------
   Regra permanente do projeto: leitura de banco distingue erro de vazio. Se
   esta conferencia regredir para "lista vazia", um Supabase instavel faz
   nascer a segunda campanha e a base sai cobrada em dobro. */
$r = ce_roda('criar-campanhas-ilegivel');
ok($r['codigo'] === 502, 'lista de campanhas ilegivel devolve erro (deu: ' . $r['codigo'] . ')');
ok(!ce_escreveu($r), 'e nao cria campanha nenhuma em cima de leitura que falhou');

/* ---------- I8: o 409 do banco tem a mesma mensagem da conferencia ----------
   O indice unico parcial po_wa_campanhas_uma_ativa e quem de fato tranca: a
   conferencia le e so depois escreve, e nessa janela cabe a outra aba. */
$r = ce_roda('criar-gemea-no-banco');
ok($r['codigo'] === 409, 'o 409 do indice unico vira 409 do endpoint (deu: ' . $r['codigo'] . ')');
ok(strpos($r['json']['error'], 'campanha em andamento') !== false,
   'com a mesma mensagem da conferencia, e nao um erro cru de banco');

/* ---------- I1: nao anunciar prontidao quando a escrita falhou ----------
   O PATCH que leva rascunho -> enviando falhando e a campanha fica em
   rascunho: nem o cron nem o botao drenam, e ela ainda bloqueia toda campanha
   futura. Dizer completo:true ai faz a tela escrever "campanha pronta". */
$r = ce_roda('criar-fecha-falha');
ok($r['codigo'] === 200 && $r['json']['ok'] === true, 'a campanha foi criada e reservada');
ok($r['json']['reservados'] === 1, 'a reserva em si funcionou');
ok($r['json']['completo'] === false,
   'mas completo e FALSO quando o fechamento da lista falhou (deu: '
   . var_export($r['json']['completo'], true) . ')');

/* ---------- drenar so aceita campanha com a lista fechada ---------- */
$r = ce_roda('drenar-rascunho');
ok($r['codigo'] === 409, 'campanha em rascunho nao pode ser drenada (deu: ' . $r['codigo'] . ')');
ok(!ce_escreveu($r),
   'e nada e enviado: drenar uma lista pela metade concluiria a campanha com o resto da base de fora');

/* ---------- I3: o dreno manual tem espacamento ----------
   O degrau e o teto do dia limitam TAMANHO; a defesa 2 da spec 8.1 e sobre
   CADENCIA. Quem materializa a cadencia e o cron horario - o botao do painel
   a contornava: vinte cliques mandavam vinte lotes num minuto. */
$r = ce_roda('drenar-recente');
ok($r['codigo'] === 429, 'lote disparado ha 1 minuto bloqueia o proximo (deu: ' . $r['codigo'] . ')');
ok(!ce_escreveu($r), 'e nada sai: nenhuma mensagem paga no clique repetido');

$r = ce_roda('drenar-espacado');
ok($r['codigo'] === 200, 'com uma hora desde o ultimo envio o dreno manual passa (deu: ' . $r['codigo'] . ')');

/* O LOTE INTEIRO FALHOU: nao existe envio bem sucedido, so tentativas. Se a
   cadencia se ancorasse no sucesso, a leitura voltaria vazia e nao haveria
   espacamento nenhum - justo no cenario em que a Meta esta rejeitando, que e
   a hora de desacelerar, e nao de deixar clicar em sequencia. */
$r = ce_roda('drenar-lote-falhou');
ok($r['codigo'] === 429,
   'com o lote inteiro falhado a cadencia continua de pe (deu: ' . $r['codigo'] . ')');
ok(!ce_escreveu($r), 'e nenhuma tentativa nova sai por cima de um numero que ja esta mal');

/* ---------- ASSERCAO DE FONTE: as colunas que alimentam as defesas ----------
   wa_camp_motivo_fora() esta bem travada como funcao pura, mas ela so ve o
   que o endpoint traz do banco. Tirar UMA palavra do select= aqui desliga uma
   defesa inteira com a suite verde: sem opt_out_at, todo mundo que pediu para
   sair volta a receber, pago; sem revisado, contato nao revisado entra em
   campanha. E a regra permanente do projeto - a trava fica no PONTO DE
   CHAMADA, nao so na funcao pura -, e o projeto ja pagou por ela duas vezes. */
$src_camp = file_get_contents(__DIR__ . '/../campanha.php');
ok(preg_match("/wa_db_select_estrito\(\s*'po_leads',\s*'select=([^'&]*)/", $src_camp, $ms) === 1,
   'campanha.php le a base com um select= explicito');
$cols = array_map('trim', explode(',', $ms[1]));
foreach ([
    'opt_out_at' => 'defesa 3: sem esta coluna, quem pediu SAIR volta a receber, pago',
    'revisado'   => 'defesa 1: sem esta coluna, contato nao revisado entra em campanha',
    'cliente'    => 'defesa 1: sem esta coluna, quem nao e cliente entra em campanha',
    'telefone'   => 'sem esta coluna a base inteira vira "sem telefone"',
    'wa_id'      => 'sem esta coluna quem so tem wa_id fica de fora',
    'id'         => 'sem o id nao ha como reservar ninguem',
    'nome'       => 'o nome e o parametro {{1}} do template',
] as $col => $porque) {
    ok(in_array($col, $cols, true),
       'o select= da base carrega "' . $col . '" (' . $porque . '). Veio: ' . $ms[1]);
}

/* ---------- ASSERCAO DE FONTE: a fiacao do cron ----------
   O gemeo das travas do modo drenar mora no wa-cron.php e nao passa por
   nenhum teste de comportamento (o cron nao e chamavel sem rede). A regra
   permanente do projeto e que a trava fica no PONTO DE CHAMADA, nao so na
   funcao pura - foi assim que o filtro da conversa sumiu uma vez. Mesmo molde
   da assercao que tests/test-wa-webhook.php usa para o whatsapp.php. */
$src_cron = file_get_contents(__DIR__ . '/../wa-cron.php');
ok(preg_match("/wa_db_select_estrito\(\s*'po_wa_campanhas',\s*'([^']*)'/", $src_cron, $mc) === 1,
   'wa-cron.php escolhe a campanha a drenar lendo po_wa_campanhas');
$q_cron = $mc[1];
/* status=eq.enviando: campanha em 'rascunho' ainda esta montando a lista.
   Drenar ali mandaria so para o pedaco ja reservado e concluiria a campanha
   com o resto da base de fora, sem nada dizendo quem ficou. */
ok(strpos($q_cron, 'status=eq.enviando') !== false,
   'o cron so drena campanha com a lista fechada (a consulta foi: ' . $q_cron . ')');
/* limit=1: duas campanhas drenando na mesma varredura dividiriam o teto
   diario sem saber uma da outra e estourariam o limite da Meta. */
ok(strpos($q_cron, 'limit=1') !== false,
   'o cron drena UMA campanha por varredura (a consulta foi: ' . $q_cron . ')');
/* O mesmo caminho do botao do painel: a escada, o teto do dia e o
   encerramento da campanha num lugar so. */
ok(strpos($src_cron, 'wa_camp_drena_campanha(') !== false,
   'o cron drena pelo mesmo caminho do painel, e nao por wa_camp_drena direto');
ok(strpos($src_cron, "require_once __DIR__ . '/lib/wa-camp-fila.php'") !== false,
   'o cron carrega a fila da transmissao');

echo "test-campanha-endpoint OK\n";
