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

/* Uma ficha na base, ja com historico (venda fechada) e SEM email: e o que
   a fusao tem para preencher. O nome ja existe e nao pode ser sobrescrito. */
function ci_lead($i = 'L1') {
    return ['id'=>$i, 'telefone'=>'+5548999990001', 'nome'=>'Antonio Pereira', 'email'=>null,
            'cpf'=>null, 'cidade'=>null, 'data_nascimento'=>null,
            'status'=>'venda', 'venda'=>5000, 'notas'=>[]];
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
        case 'tipo-invalido': $_POST['tipo'] = 'xml'; break;
        case 'origem-crmx':   $_POST['origem'] = 'crmx'; break;
        case 'vazio':         $_POST['texto'] = "   \n "; break;
        case 'leitura-falha':
        case 'leitura-html':
        case 'leitura-falha-pagina2': $_POST['modo'] = 'aplicar'; break;
    }

    wa_db_set_transport(function ($metodo, $url, $corpo, $headers) use ($caso, &$chamadas) {
        $chamadas[] = ['metodo'=>$metodo, 'url'=>$url, 'corpo'=>$corpo];  // headers NUNCA

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
            return ['status'=>200, 'body'=>json_encode($off > 0 ? [] : [ci_lead()])];
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
ok($r['json']['itens'][0]['preenche'] === ['email'], 'o preview mostra a CHAVE que seria preenchida');
ok(!isset($r['json']['itens'][0]['candidato']), 'o preview nao devolve o dado pessoal inteiro');

/* --- aplicar escreve, e escreve so o que pode --- */
$r = ci_roda('aplicar');
ok($r['json']['ok'] === true, 'aplicar deu certo');
ok($r['json']['aplicado'] === ['novos'=>1,'preenchidos'=>1,'revisar'=>0,'ignorados'=>0,'falhas'=>0], 'contagem do que foi feito');

$escritas = array_values(array_filter($r['chamadas'], fn($c) => $c['metodo'] !== 'GET'));
ok(count($escritas) === 2, 'duas escritas: um insert e um update');

$patch = null; $post = null;
foreach ($escritas as $e) { if ($e['metodo'] === 'PATCH') $patch = $e; else $post = $e; }
ok($patch !== null && strpos($patch['url'], 'id=eq.L1') !== false, 'o update mira a ficha que casou');
$campos = json_decode($patch['corpo'], true);
ok(array_keys($campos) === ['email'], 'o PATCH leva exatamente as chaves de preenche');
foreach (['status','venda','venda_at','notas','nome','telefone'] as $proibido) {
    ok(!array_key_exists($proibido, $campos), "o PATCH nunca manda $proibido");
}
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
ok(ci_metodos($r) === ['GET'], 'aborta na primeira falha, sem sonda e sem escrita');
ok(!in_array('POST', ci_metodos($r), true), 'NADA e inserido quando a base nao pode ser lida');

/* 200 com corpo que nao e JSON. Numa hospedagem compartilhada isso chega de
   verdade: proxy, portal cativo ou pagina de erro do host respondendo 200
   com HTML. Nao e erro de status - e o caminho do Critical que nao passa
   pelo status >= 300 - e mesmo assim NAO e a base. */
$r = ci_roda('leitura-html');
ok($r['codigo'] === 503, 'resposta 200 que nao e JSON tambem responde 503');
ok($r['json']['ok'] === false, 'e nao finge que a base esta vazia');
ok(ci_metodos($r) === ['GET'], 'aborta na primeira resposta ilegivel');
ok(!in_array('POST', ci_metodos($r), true), 'NADA e inserido quando a resposta nao e a base');

$r = ci_roda('leitura-falha-pagina2');
ok($r['codigo'] === 503, 'falha na segunda pagina tambem aborta');
ok($r['json']['ok'] === false, 'nao diz que importou');
$m = ci_metodos($r);
ok($m === ['GET', 'GET'], 'leu a primeira pagina, tentou a segunda e parou');
ok(!in_array('POST', $m, true) && !in_array('PATCH', $m, true), 'base lida pela metade nao escreve nada');

echo "test-contatos-importar OK\n";
