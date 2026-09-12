<?php
/* tests/test-contatos-importar.php — o endpoint inteiro, sem rede.

   O endpoint termina em exit(), entao cada caso roda num PROCESSO proprio:
   o arquivo chama a si mesmo com o nome do caso, monta $_POST, injeta o
   verificador de login (po_auth_set_verificador) e o transporte do banco
   (wa_db_set_transport), inclui o contatos-importar.php e imprime o JSON da
   resposta mais TUDO o que teria ido para o Supabase.

   O transporte falso registra metodo, url e corpo - nunca os cabecalhos,
   que carregam a service_role. */

$RAIZ = dirname(__DIR__);
require_once $RAIZ . '/lib/wa-import.php';
require_once $RAIZ . '/lib/wa-db.php';      // carrega wa-config -> po-data -> config.local
require_once $RAIZ . '/lib/po-auth.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

const CI_VCF = "BEGIN:VCARD\nVERSION:3.0\nFN:Antonio Pereira - PO\nEMAIL:antonio@x.com\n"
             . "TEL;TYPE=CELL:+5548999990001\nEND:VCARD\n"
             . "BEGIN:VCARD\nVERSION:3.0\nFN:Maria Nova PO\nTEL;TYPE=CELL:48988887777\nEND:VCARD\n";

/* Uma ficha na base, ja com historico (venda fechada), SEM email e com
   wa_id NULO: e o lead do formulario do site, que o enviar.php nunca
   normalizou para E.164 e por isso o backfill anterior nao pegou. E o que a
   fusao tem para preencher. O nome ja existe e nao pode ser sobrescrito.
   $waId permite o caso inverso: ficha que JA tem wa_id nao pode ser
   reescrita (trocar o wa_id jogaria a conversa dela para outro numero). */
function ci_lead($i = 'L1', $waId = null) {
    return ['id'=>$i, 'telefone'=>'+5548999990001', 'wa_id'=>$waId,
            'nome'=>'Antonio Pereira', 'email'=>null,
            'cpf'=>null, 'cidade'=>null, 'data_nascimento'=>null,
            'status'=>'venda', 'venda'=>5000, 'notas'=>[]];
}

/* Uma linha de po_import_lotes. $concluido nulo = reserva ainda aberta (a
   importacao esta rodando, ou morreu no meio); $criado diz ha quanto tempo a
   reserva foi feita, que e o que separa "em andamento" de "orfa". */
function ci_lote_row($concluido, $criado) {
    return ['id'=>'LOTE-1',
            'created_at'   => gmdate('Y-m-d\TH:i:s\Z', strtotime($criado)),
            'concluido_at' => $concluido ? gmdate('Y-m-d\TH:i:s\Z', strtotime($concluido)) : null];
}

/* ============================================================
   FILHO: monta a requisicao e roda o endpoint.
============================================================ */
function ci_filho($caso) {
    global $RAIZ;
    $chamadas = [];

    /* O error_log do wa_db_select_estrito iria para a stderr e se misturaria
       ao JSON que o pai le. Vai para um arquivo temporario. */
    ini_set('error_log', sys_get_temp_dir() . '/po-test-contatos.log');

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['sb_token'=>'tok.valido', 'tipo'=>'vcf', 'origem'=>'agenda-esposa',
              'modo'=>'preview', 'texto'=>CI_VCF];

    // Segredos de teste: garantem que nem a chave real nem a URL real do
    // config.local.php desta maquina entram no caminho.
    $GLOBALS['SUPABASE_URL']         = 'https://teste.local';
    $GLOBALS['SUPABASE_ANON_KEY']    = 'anon-de-teste';
    $GLOBALS['SUPABASE_SERVICE_KEY'] = 'service-de-teste';

    po_auth_set_verificador(fn($u, $a, $t) => $caso !== 'sem-auth' && $t === 'tok.valido');

    switch ($caso) {
        case 'sem-auth':      $_POST['modo'] = 'aplicar'; break;
        case 'aplicar':       $_POST['modo'] = 'aplicar'; break;
        case 'aplicar-waid-ja': $_POST['modo'] = 'aplicar'; break;
        case 'tipo-invalido': $_POST['tipo'] = 'xml'; break;
        case 'origem-crmx':   $_POST['origem'] = 'crmx'; break;
        case 'vazio':         $_POST['texto'] = "   \n "; break;
        case 'leitura-falha':
        case 'leitura-html':
        case 'leitura-falha-pagina2': $_POST['modo'] = 'aplicar'; break;

        /* Idempotencia: o mesmo arquivo, na mesma origem, chegando de novo.
           'lote-repetido-preview' e o MESMO lote em modo preview - ver de
           novo o que o arquivo faria nao escreve nada, entao a guarda nao
           pode barrar. */
        case 'lote-repetido':
        case 'lote-consulta-falha':
        case 'lote-em-andamento':
        case 'lote-orfao':
        case 'lote-corrida':
        case 'lote-reserva-falha':
        case 'lote-fecha-falha':      $_POST['modo'] = 'aplicar'; break;
        case 'lote-repetido-forcado': $_POST['modo'] = 'aplicar'; $_POST['forcar'] = '1'; break;
        case 'lote-repetido-preview': $_POST['modo'] = 'preview'; break;
    }

    $lotesGet = 0;   // a corrida precisa responder diferente na releitura

    wa_db_set_transport(function ($metodo, $url, $corpo, $headers) use ($caso, &$chamadas, &$lotesGet) {
        $chamadas[] = ['metodo'=>$metodo, 'url'=>$url, 'corpo'=>$corpo];  // headers NUNCA

        /* A po_import_lotes e uma tabela diferente da base: responder as duas
           pelo mesmo ramo faria o teste passar por acidente. */
        if (strpos($url, 'po_import_lotes') !== false) {
            if ($metodo === 'GET') {
                $lotesGet++;
                if ($caso === 'lote-consulta-falha') {
                    return ['status'=>503, 'body'=>'{"message":"service unavailable"}'];
                }
                // A CORRIDA: na primeira consulta nao ha lote nenhum; quando a
                // reserva bate no unique e o endpoint rele, a linha da outra
                // requisicao ja esta la, recem-criada.
                if ($caso === 'lote-corrida') {
                    return ['status'=>200, 'body'=>json_encode(
                        $lotesGet === 1 ? [] : [ci_lote_row(null, '-30 seconds')])];
                }
                if ($caso === 'lote-reserva-falha') return ['status'=>200, 'body'=>'[]'];
                if ($caso === 'lote-em-andamento') {
                    // Reservado ha 30s e ainda sem concluido_at: a primeira
                    // requisicao pode estar escrevendo os leads AGORA.
                    return ['status'=>200, 'body'=>json_encode([ci_lote_row(null, '-30 seconds')])];
                }
                if ($caso === 'lote-orfao') {
                    // Reservado ha 3h e nunca concluido: morreu no meio.
                    return ['status'=>200, 'body'=>json_encode([ci_lote_row(null, '-3 hours')])];
                }
                if (in_array($caso, ['lote-repetido', 'lote-repetido-preview',
                                     'lote-repetido-forcado'], true)) {
                    return ['status'=>200, 'body'=>json_encode([ci_lote_row('-2 days', '-2 days')])];
                }
                return ['status'=>200, 'body'=>'[]'];      // nunca importado
            }
            if ($metodo === 'POST') {
                // 409 = unique violation: alguem ja tem a reserva deste hash.
                if ($caso === 'lote-corrida' || $caso === 'lote-repetido-forcado') {
                    return ['status'=>409, 'body'=>'{"code":"23505"}'];
                }
                if ($caso === 'lote-reserva-falha') return ['status'=>503, 'body'=>'{"message":"boom"}'];
                return ['status'=>201, 'body'=>'[{"id":"LOTE-1"}]'];
            }
            // PATCH: reivindicacao da orfa e fechamento do lote
            if ($caso === 'lote-fecha-falha') return ['status'=>503, 'body'=>'{"message":"boom"}'];
            return ['status'=>204, 'body'=>''];
        }

        if ($metodo === 'GET') {
            preg_match('/offset=(\d+)/', $url, $m);
            $off = (int) ($m[1] ?? 0);

            if ($caso === 'leitura-falha') {
                return ['status'=>503, 'body'=>'{"message":"service unavailable"}'];
            }
            if ($caso === 'leitura-html') {
                // 200 com corpo que nao e JSON: proxy, portal cativo ou
                // pagina de erro do host. Nao e erro de status, e tambem
                // nao e dado - e o caminho mais traicoeiro do Critical.
                return ['status'=>200, 'body'=>'<html><body>502 Bad Gateway</body></html>'];
            }
            if ($caso === 'leitura-falha-pagina2') {
                // Primeira pagina cheia, a segunda cai: o import tem que
                // abortar em vez de achar que a base acabou.
                if ($off > 0) return ['status'=>500, 'body'=>'{"message":"boom"}'];
                $linhas = [];
                for ($i = 0; $i < 1000; $i++) $linhas[] = ci_lead('L' . $i);
                return ['status'=>200, 'body'=>json_encode($linhas)];
            }
            $ja = $caso === 'aplicar-waid-ja' ? '+5548911112222' : null;
            return ['status'=>200, 'body'=>json_encode($off > 0 ? [] : [ci_lead('L1', $ja)])];
        }
        if ($metodo === 'POST')  return ['status'=>201, 'body'=>'[{"id":"novo-1"}]'];
        return ['status'=>204, 'body'=>''];   // PATCH
    });

    register_shutdown_function(function () use (&$chamadas) {
        echo "\n@@CODIGO@@" . (int) http_response_code()
           . "\n@@CHAMADAS@@" . json_encode($chamadas) . "\n";
    });

    require $RAIZ . '/contatos-importar.php';
}

if (isset($argv[1]) && $argv[1] !== '') { ci_filho($argv[1]); exit; }

/* ============================================================
   PAI: roda cada caso num processo e confere o que saiu.
============================================================ */
function ci_roda($caso) {
    $out = []; $cod = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($caso) . ' 2>&1', $out, $cod);
    $txt = implode("\n", $out);
    ok(strpos($txt, '@@CODIGO@@') !== false, "caso $caso respondeu (saida: " . substr($txt, 0, 200) . ')');

    list($corpo, $resto)   = explode('@@CODIGO@@', $txt, 2);
    list($codigo, $chams)  = explode('@@CHAMADAS@@', $resto, 2);
    return [
        'json'     => json_decode(trim($corpo), true),
        'codigo'   => (int) trim($codigo),
        'chamadas' => json_decode(trim($chams), true) ?: [],
        'bruto'    => trim($corpo),
    ];
}
function ci_metodos($r) { return array_map(fn($c) => $c['metodo'], $r['chamadas']); }

/* As chamadas de UMA tabela. A guarda de idempotencia consulta a
   po_import_lotes antes da base, entao "so leu a base e parou" passou a
   precisar ser dito por tabela - a contagem total agora mistura as duas. */
function ci_de($r, $tabela) {
    return array_values(array_filter($r['chamadas'],
        fn($c) => strpos($c['url'], '/rest/v1/' . $tabela) !== false));
}
function ci_metodos_de($r, $tabela) { return array_map(fn($c) => $c['metodo'], ci_de($r, $tabela)); }

/* A conversa INTEIRA com o banco, em ordem e com a tabela de cada chamada.
   Fixar so o recorte de uma tabela deixa passar escrita em tabela nao
   relacionada; e a ORDEM e o que prova que a reserva do lote vem antes da
   escrita dos leads e o fechamento depois. */
function ci_seq($r) {
    return array_map(fn($c) => $c['metodo'] . ' '
        . (strpos($c['url'], 'po_import_lotes') !== false ? 'lotes' : 'leads'), $r['chamadas']);
}

/* --- login e checado ANTES de qualquer leitura ou escrita --- */
$r = ci_roda('sem-auth');
ok($r['codigo'] === 401, 'sessao invalida responde 401');
ok($r['json']['ok'] === false, 'e diz que nao deu certo');
ok($r['chamadas'] === [], 'sem login, o banco nem e consultado');

/* --- parametros contra lista fechada, antes de tocar o banco --- */
$r = ci_roda('tipo-invalido');
ok($r['codigo'] === 400 && $r['chamadas'] === [], 'tipo desconhecido e recusado sem consultar o banco');
ok(strpos($r['json']['error'], 'vcf') !== false, 'o erro diz quais tipos valem');

$r = ci_roda('origem-crmx');
ok($r['codigo'] === 400 && $r['chamadas'] === [], 'origem "crmx" e recusada (nao basta parecer com crm)');

$r = ci_roda('vazio');
ok($r['codigo'] === 400 && $r['chamadas'] === [], 'arquivo em branco e recusado');
ok(strpos($r['bruto'], 'BEGIN:VCARD') === false, 'nenhuma resposta de erro ecoa conteudo do arquivo');

/* --- preview le, calcula e NAO escreve --- */
$r = ci_roda('preview');
ok($r['codigo'] === 200 || $r['codigo'] === 0, 'preview responde 200');
ok($r['json']['ok'] === true, 'preview deu certo');
ok($r['json']['resumo']['funde'] === 1, 'um casamento com a base');
ok($r['json']['resumo']['novos'] === 1, 'um contato novo');
ok($r['json']['resumo']['por_celular'] === 1, 'casou pelo celular');
ok(ci_metodos($r) === ['GET', 'GET'], 'preview so le (paginou ate a pagina vazia)');
/* ci_lead() E o lead do formulario do site: tem telefone e wa_id NULO (o
   enviar.php nunca normalizou para E.164, entao o backfill nao o pegou). A
   fusao tem que preencher os dois campos vazios - email E wa_id. Sem o wa_id,
   na primeira mensagem que a pessoa mandar o wa_lead() consulta por wa_id,
   nao acha, e insere um lead novo: a duplicacao pelo outro lado. */
ok($r['json']['itens'][0]['preenche'] === ['email', 'wa_id'],
   'o preview mostra as CHAVES que seriam preenchidas, wa_id incluso');
ok(!isset($r['json']['itens'][0]['candidato']), 'o preview nao devolve o dado pessoal inteiro');

/* --- aplicar escreve, e escreve so o que pode --- */
$r = ci_roda('aplicar');
ok($r['json']['ok'] === true, 'aplicar deu certo');
ok($r['json']['aplicado'] === ['novos'=>1,'preenchidos'=>1,'revisar'=>0,'ignorados'=>0,'falhas'=>0], 'contagem do que foi feito');

/* A conversa inteira, em ordem. Fixar so o recorte da po_leads deixaria
   passar escrita em tabela nao relacionada; e a ordem e o que prova que a
   reserva do lote vem ANTES dos leads e o fechamento DEPOIS. */
ok(ci_seq($r) === ['GET lotes', 'GET leads', 'GET leads',
                   'POST lotes', 'PATCH leads', 'POST leads', 'PATCH lotes'],
   'conferiu o lote, leu a base, RESERVOU, escreveu os leads e so entao fechou o lote');

$escritas = array_values(array_filter(ci_de($r, 'po_leads'), fn($c) => $c['metodo'] !== 'GET'));
ok(count($escritas) === 2, 'duas escritas na base: um insert e um update');

$patch = null; $post = null;
foreach ($escritas as $e) { if ($e['metodo'] === 'PATCH') $patch = $e; else $post = $e; }
ok($patch !== null && strpos($patch['url'], 'id=eq.L1') !== false, 'o update mira a ficha que casou');
$campos = json_decode($patch['corpo'], true);
ok(array_keys($campos) === ['email', 'wa_id'], 'o PATCH leva exatamente as chaves de preenche');
ok($campos['wa_id'] === '+5548999990001', 'a fusao grava o wa_id que estava nulo na ficha');
foreach (['status','venda','venda_at','notas','nome','telefone'] as $proibido) {
    ok(!array_key_exists($proibido, $campos), "o PATCH nunca manda $proibido");
}
/* E o inverso, no mesmo caminho de ponta a ponta: ficha que JA tem wa_id nao
   e reescrita. Regra "so preenche o que esta vazio", que vale para o wa_id
   como vale para o resto - trocar o wa_id de uma ficha jogaria a conversa
   dela para outro numero. */
$rJa = ci_roda('aplicar-waid-ja');
$patchJa = null;
foreach (array_filter(ci_de($rJa, 'po_leads'), fn($c) => $c['metodo'] === 'PATCH') as $e) $patchJa = $e;
ok($patchJa !== null, 'a fusao aconteceu tambem com wa_id ja preenchido');
$camposJa = json_decode($patchJa['corpo'], true);
ok(!array_key_exists('wa_id', $camposJa), 'wa_id ja preenchido NUNCA e sobrescrito');
ok(array_keys($camposJa) === ['email'], 'so o que estava vazio e preenchido');
$linha = json_decode($post['corpo'], true);
ok($linha['telefone'] === '+5548988887777', 'o insert leva o celular normalizado');
ok($linha['nome'] === 'Maria Nova', 'o marcador PO sai do nome');
ok($linha['revisado'] === false && $linha['cliente'] === false, 'contato de agenda entra para revisao');

/* ============================================================
   O CRITICO: leitura que falha NAO pode virar importacao bem-sucedida.
   wa_db_select devolve [] tanto para "acabou" quanto para "deu erro"; com a
   base de hoje (abaixo de 1.000 leads) um unico 5xx na primeira pagina daria
   base vazia, e TODO contato do arquivo entraria como lead novo, duplicando
   a base em silencio e com a tela dizendo sucesso.
============================================================ */
$r = ci_roda('leitura-falha');
ok($r['codigo'] === 503, 'base ilegivel responde 503');
ok($r['json']['ok'] === false, 'e nao finge sucesso');
ok(ci_metodos_de($r, 'po_leads') === ['GET'], 'aborta na primeira falha, sem sonda e sem escrita');
ok(!in_array('POST', ci_metodos($r), true), 'NADA e inserido quando a base nao pode ser lida');

/* 200 com corpo que nao e JSON. Numa hospedagem compartilhada isso chega de
   verdade: proxy, portal cativo ou pagina de erro do host respondendo 200
   com HTML. Nao e erro de status - e o caminho do Critical que nao passa
   pelo status >= 300 - e mesmo assim NAO e a base. */
$r = ci_roda('leitura-html');
ok($r['codigo'] === 503, 'resposta 200 que nao e JSON tambem responde 503');
ok($r['json']['ok'] === false, 'e nao finge que a base esta vazia');
ok(ci_metodos_de($r, 'po_leads') === ['GET'], 'aborta na primeira resposta ilegivel');
ok(!in_array('POST', ci_metodos($r), true), 'NADA e inserido quando a resposta nao e a base');

$r = ci_roda('leitura-falha-pagina2');
ok($r['codigo'] === 503, 'falha na segunda pagina tambem aborta');
ok($r['json']['ok'] === false, 'nao diz que importou');
$m = ci_metodos($r);
ok(ci_metodos_de($r, 'po_leads') === ['GET', 'GET'], 'leu a primeira pagina, tentou a segunda e parou');
ok(!in_array('POST', $m, true) && !in_array('PATCH', $m, true), 'base lida pela metade nao escreve nada');
ok(ci_metodos_de($r, 'po_import_lotes') === ['GET'],
   'import que abortou ANTES de escrever nao reserva nem registra lote nenhum');

/* ============================================================
   IDEMPOTENCIA: reaplicar o mesmo arquivo nao pode duplicar a base.
   O gatilho e real: o fetch do navegador estoura o timeout DEPOIS de o
   servidor ja ter gravado, a tela mostra erro e a reacao natural e clicar
   de novo. Das 775 fichas de hoje, 615 nao tem telefone nenhum - essas nao
   tem por onde ser reconciliadas numa segunda passada e entrariam
   duplicadas, sem nenhum sinal.
============================================================ */

/* --- lote repetido: o segundo aplicar e recusado, sem escrever nada --- */
$r = ci_roda('lote-repetido');
ok($r['codigo'] === 409, 'lote ja aplicado responde 409');
ok($r['json']['ok'] === false, 'e nao diz ok');
ok(!in_array('POST', ci_metodos($r), true), 'nenhuma escrita de lead');
ok(!in_array('PATCH', ci_metodos($r), true), 'e nenhum update tambem');
ok(strpos(strtolower($r['json']['error'] ?? ''), 'ja foi importado') !== false,
   'a mensagem explica que o arquivo ja foi importado');
ok(ci_de($r, 'po_leads') === [],
   'recusa antes de ler a base inteira: a cliente ja esta no retry, o 409 tem que ser rapido');
ok(strpos($r['bruto'], 'BEGIN:VCARD') === false, 'o 409 nao ecoa conteudo do arquivo');

/* --- com forcar=1, aplica mesmo assim --- */
$r = ci_roda('lote-repetido-forcado');
ok($r['codigo'] === 200 || $r['codigo'] === 0, 'forcar=1 aplica mesmo com lote repetido');
ok($r['json']['ok'] === true, 'e diz que deu certo');
/* Forcar nao consulta ANTES (nao ha o que conferir: a cliente ja decidiu).
   A reserva bate no unique da linha da importacao anterior - por isso o POST
   e seguido de um GET, que e como o endpoint distingue "alguem ja tem a
   reserva" de "o banco falhou" - e o PATCH final fecha o registro com a
   contagem nova. O que nao pode acontecer e forcar NAO registrar nada. */
ok(ci_metodos_de($r, 'po_import_lotes') === ['POST', 'GET', 'PATCH'],
   'forcar nao consulta antes, mas reserva e REGISTRA');
ok(in_array('PATCH lotes', ci_seq($r), true), 'forcar fecha o lote no fim');
ok($r['json']['lote_registrado'] === true, 'e avisa que o registro fechou');
$escritas = array_values(array_filter(ci_de($r, 'po_leads'), fn($c) => $c['metodo'] !== 'GET'));
ok(count($escritas) === 2, 'forcar escreve de verdade: um insert e um update');

/* --- o preview NUNCA e bloqueado pelo lote: ver de novo nao escreve nada --- */
$r = ci_roda('lote-repetido-preview');
ok($r['codigo'] === 200 || $r['codigo'] === 0, 'preview de lote repetido continua funcionando');
ok($r['json']['ok'] === true, 'e devolve o plano');
ok(ci_de($r, 'po_import_lotes') === [], 'o preview nem pergunta pelo lote');
ok(!in_array('POST', ci_metodos($r), true) && !in_array('PATCH', ci_metodos($r), true),
   'preview segue sem escrever nada');

/* --- consulta do lote que FALHA nao pode virar "lote novo" --- */
/* E o mesmo buraco do Critical, um andar acima: tratar erro como "nao achei"
   aqui significa concluir que o lote e novo com o banco fora do ar, que e
   exatamente a duplicacao que esta guarda existe para impedir. */
$r = ci_roda('lote-consulta-falha');
ok($r['codigo'] === 503, 'consulta do lote ilegivel responde 503');
ok($r['json']['ok'] === false, 'e nao finge que o lote e novo');
ok(ci_de($r, 'po_leads') === [], 'nem chega a ler a base');
ok(!in_array('POST', ci_metodos($r), true) && !in_array('PATCH', ci_metodos($r), true),
   'NADA e escrito quando nao da para conferir o lote');

/* ============================================================
   O RETRY DURANTE, que e o que o timeout produz de verdade.

   Se o fetch estoura NO MEIO da escrita (o proxy do host corta a conexao; o
   PHP nem percebe, porque so responde no fim), o botao volta a ficar
   clicavel e a segunda requisicao consulta a po_import_lotes ANTES de a
   primeira registrar o lote. Com o registro so no fim, as duas passavam pela
   guarda e a base duplicava - o unique em 'hash' dedupe a linha de log, nao
   os leads. Por isso a reserva vem antes da escrita.
============================================================ */

/* --- corrida: a consulta passa, mas a RESERVA bate no unique --- */
$r = ci_roda('lote-corrida');
ok($r['codigo'] === 409, 'quem perde a corrida da reserva responde 409');
ok($r['json']['ok'] === false, 'e nao finge que importou');
ok(!in_array('POST leads', ci_seq($r), true) && !in_array('PATCH leads', ci_seq($r), true),
   'NENHUM lead e escrito: a reserva vem antes da escrita, nao depois');
ok(ci_metodos_de($r, 'po_import_lotes') === ['GET', 'POST', 'GET'],
   'conferiu, tentou reservar, e releu para saber se o null foi conflito ou falha');

/* --- reserva que falha DE VERDADE (nao e conflito) tambem nao escreve --- */
/* Com $ignora_conflito o null do wa_db_insert seria ambiguo. A releitura e
   quem separa: aqui ela diz "nao existe lote nenhum", logo nao foi o unique,
   foi o banco - e a resposta e 503, nao 409. */
$r = ci_roda('lote-reserva-falha');
ok($r['codigo'] === 503, 'falha real na reserva responde 503, nao 409');
ok(strpos(strtolower($r['json']['error'] ?? ''), 'reservar') !== false,
   'a mensagem diz que nao deu para reservar');
ok(!in_array('POST leads', ci_seq($r), true) && !in_array('PATCH leads', ci_seq($r), true),
   'e nada e escrito na base');

/* --- lote reservado ha pouco e ainda aberto: a primeira pode estar rodando --- */
$r = ci_roda('lote-em-andamento');
ok($r['codigo'] === 409, 'lote reservado e ainda em andamento responde 409');
ok(ci_de($r, 'po_leads') === [], 'nem le a base');
ok(strpos(strtolower($r['json']['error'] ?? ''), 'rodando') !== false,
   'a mensagem explica que pode haver uma importacao em andamento');

/* --- lote reservado ha horas e nunca concluido: a tentativa morreu no meio --- */
/* Aqui a cliente TEM que conseguir tentar de novo sem o forcar - e a intencao
   do desenho original ("gravar depois"), preservada sem o buraco da corrida. */
$r = ci_roda('lote-orfao');
ok($r['codigo'] === 200 || $r['codigo'] === 0, 'lote orfao NAO bloqueia o retry');
ok($r['json']['ok'] === true, 'e a importacao roda');
ok(ci_seq($r) === ['GET lotes', 'GET leads', 'GET leads',
                   'PATCH lotes', 'PATCH leads', 'POST leads', 'PATCH lotes'],
   'reivindica a linha orfa (PATCH, nao POST: o unique impediria o insert) antes de escrever');
$claim = json_decode(ci_de($r, 'po_import_lotes')[1]['corpo'], true);
ok(array_key_exists('concluido_at', $claim) && $claim['concluido_at'] === null,
   'a reivindicacao reabre a linha: sem concluido_at ela segue como em andamento');
ok(!empty($claim['created_at']),
   'e renova o relogio, para uma terceira requisicao ver "em andamento" enquanto esta escreve');

/* --- o lote e RESERVADO antes e FECHADO depois, com a contagem real --- */
$r = ci_roda('aplicar');
$lotes = ci_de($r, 'po_import_lotes');
ok(ci_metodos_de($r, 'po_import_lotes') === ['GET', 'POST', 'PATCH'],
   'conferiu, reservou antes de escrever e fechou depois');

$reserva = json_decode($lotes[1]['corpo'], true);
/* trim() porque cpost() apara o campo 'texto' colado; pelo upload o conteudo
   vai byte a byte como veio. Cada caminho e consistente consigo mesmo, que e
   o que a idempotencia precisa. */
ok(is_array($reserva) && ($reserva['hash'] ?? '') === wa_import_hash_lote(trim(CI_VCF), 'agenda-esposa'),
   'a reserva leva o hash do conteudo + origem');
ok(strpos($lotes[0]['url'], 'hash=eq.' . $reserva['hash']) !== false,
   'o hash reservado e o MESMO que foi consultado: senao a guarda nunca acharia o proprio lote');
ok($reserva['origem'] === 'agenda-esposa', 'e a origem');
ok(!array_key_exists('concluido_at', $reserva) || $reserva['concluido_at'] === null,
   'a reserva nasce SEM concluido_at: e isso que a marca como em andamento');
ok(($reserva['novos'] ?? null) === 0 && ($reserva['preenchidos'] ?? null) === 0,
   'e sem contagem, que so existe depois de escrever');
ok(strpos(json_encode($reserva), 'BEGIN:VCARD') === false, 'o registro do lote nao guarda o arquivo');

$fecha = json_decode($lotes[2]['corpo'], true);
ok(strpos($lotes[2]['url'], 'hash=eq.' . $reserva['hash']) !== false, 'o fechamento mira a propria linha');
ok(!empty($fecha['concluido_at']), 'o fechamento e o que carimba concluido_at');
ok($fecha['total'] === 2 && $fecha['novos'] === 1 && $fecha['preenchidos'] === 1,
   'a contagem gravada e a do que realmente foi aplicado');
ok($r['json']['lote_registrado'] === true, 'e a resposta confirma que o registro fechou');

$ordem = ci_seq($r);
ok(array_search('POST lotes', $ordem, true) < array_search('POST leads', $ordem, true),
   'a RESERVA entra antes dos leads: e ela que fecha a corrida do retry');
ok(array_search('PATCH lotes', $ordem, true) > array_search('POST leads', $ordem, true),
   'e o FECHAMENTO depois: import que morre no meio deixa linha orfa, nao lote concluido');

/* --- fechamento que falha: os leads entraram, o registro nao --- */
/* O unico rastro disso seria um error_log que ninguem le, e a linha orfa
   libera um retry que duplicaria. A tela precisa poder avisar. */
$r = ci_roda('lote-fecha-falha');
ok($r['codigo'] === 200 || $r['codigo'] === 0, 'a importacao ja escreveu, entao a resposta nao vira erro');
ok($r['json']['ok'] === true, 'e diz que os leads foram aplicados');
ok($r['json']['lote_registrado'] === false,
   'mas avisa que o registro do lote NAO fechou: a tela tem que poder alertar');

/* --- o hash muda com o conteudo e com a origem --- */
ok(wa_import_hash_lote('AAA', 'agenda-esposa') === wa_import_hash_lote('AAA', 'agenda-esposa'),
   'mesmo conteudo e mesma origem dao o mesmo hash');
ok(wa_import_hash_lote('AAA', 'agenda-esposa') !== wa_import_hash_lote('BBB', 'agenda-esposa'),
   'conteudo diferente muda o hash');
ok(wa_import_hash_lote('AAA', 'agenda-esposa') !== wa_import_hash_lote('AAA', 'agenda-marido'),
   'origem diferente muda o hash: o mesmo arquivo em duas origens e engano do operador');
/* O separador \0 existe para isto: sem ele, 'ag' + 'endaX' e 'age' + 'ndaX'
   dariam a mesma string e o mesmo hash. */
ok(wa_import_hash_lote('B', 'A') !== wa_import_hash_lote('', 'AB'),
   'a fronteira entre origem e conteudo nao pode ser ambigua');

echo "test-contatos-importar OK\n";
