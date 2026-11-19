<?php
/* tests/test-meta.php - Meta Ads (fase 3B, 13/11/2026): nome dos anuncios e
   a venda de volta para a Meta. Sem rede: toda chamada passa por
   po_meta_req(), trocada aqui por um duble que registra cada pedido e
   responde como a Graph e o Supabase. Dados inventados.

   As regras que este teste existe para segurar:
   - a Meta NAO deduplica: uma venda sai UMA vez (reserva antes do envio);
   - erro lendo o que ja foi enviado nunca vira "nada foi enviado";
   - "enviando" que ficou para tras nao e reenviado;
   - o token nunca vai na URL. */
require __DIR__ . '/../lib/po-meta.php';
require_once __DIR__ . '/../lib/wa-send.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }
$RAIZ = dirname(__DIR__);
$le = function ($rel) use ($RAIZ) { return str_replace("\r\n", "\n", file_get_contents($RAIZ . '/' . $rel)); };

/* ============ funcoes puras ============ */
ok(po_meta_ids_validos(['120210000000001', ' 120210000000001', 'abc', '12;drop', '', '120210000000002']) === ['120210000000001', '120210000000002'],
    'so id numerico, sem repetido');
ok(count(po_meta_ids_validos(array_map(function ($i) { return (string) (1000000 + $i); }, range(1, 40)))) === 25, 'no maximo 25 por chamada');
ok(po_meta_nomes_da_resposta(['id' => '1', 'name' => ' Video 1 ', 'adset' => ['name' => 'Publico 60+'], 'campaign' => ['name' => 'Turquia 2027']])
    === ['anuncio' => 'Video 1', 'conjunto' => 'Publico 60+', 'campanha' => 'Turquia 2027'], 'nomes da resposta da Graph');
ok(po_meta_nomes_da_resposta(['error' => ['message' => 'x']]) === null, 'erro da Graph nao vira nome');
ok(po_meta_nomes_da_resposta(['id' => '1']) === ['anuncio' => null, 'conjunto' => null, 'campanha' => null], 'campo que falta vira null');

$AGORA = 1794600000;
$lead = ['id' => 'l1', 'status' => 'venda', 'ctwa_clid' => 'ARAkLm3', 'venda' => '15000.456', 'venda_at' => gmdate('c', $AGORA - 3600)];
ok(po_meta_evento_compra($lead, '374665579072339') === [
    'event_name' => 'Purchase', 'event_time' => $AGORA - 3600, 'action_source' => 'business_messaging', 'messaging_channel' => 'whatsapp',
    'user_data' => ['whatsapp_business_account_id' => '374665579072339', 'ctwa_clid' => 'ARAkLm3'],
    'custom_data' => ['currency' => 'BRL', 'value' => 15000.46],
], 'o evento do guia oficial da Meta para anuncio que abre o WhatsApp');

ok(po_meta_venda_elegivel($lead, $AGORA), 'venda de 1h atras vai');
ok(!po_meta_venda_elegivel(['status' => 'negociacao'] + $lead, $AGORA), 'so status venda');
ok(!po_meta_venda_elegivel(['ctwa_clid' => ''] + $lead, $AGORA), 'sem ctwa_clid nao e anuncio do WhatsApp');
ok(!po_meta_venda_elegivel(['venda' => 0] + $lead, $AGORA), 'venda sem valor nao vai (a Meta exige value)');
ok(!po_meta_venda_elegivel(['venda_at' => gmdate('c', $AGORA - 8 * 86400)] + $lead, $AGORA), 'fora da janela de 7 dias');
ok(po_meta_venda_elegivel(['venda_at' => gmdate('c', $AGORA - 7 * 86400)] + $lead, $AGORA), 'no 7o dia ainda vai');
ok(!po_meta_venda_elegivel(['venda_at' => gmdate('c', $AGORA + 86400)] + $lead, $AGORA), 'data do futuro nao vai');
ok(!po_meta_venda_elegivel(['venda_at' => 'ontem'] + $lead, $AGORA), 'data ilegivel nao vai');

/* ============ o duble da rede ============ */
$CH = [];          // cada pedido: [metodo, url, corpo, cabecalhos]
$RESP = [];        // padrao da URL => funcao(metodo, url, corpo) -> [codigo, corpo]
$GLOBALS['PO_META_HTTP'] = function ($metodo, $url, $corpo, $cab) use (&$CH, &$RESP) {
    $CH[] = [$metodo, $url, $corpo, $cab];
    foreach ($RESP as $padrao => $f) if (preg_match($padrao, $url)) return $f($metodo, $url, $corpo);
    return [404, null];
};
$CFG = ['SUPABASE_URL' => 'https://sb.exemplo', 'SUPABASE_ANON_KEY' => 'anon', 'SUPABASE_SERVICE_KEY' => 'service-secreta',
        'META_ADS_TOKEN' => 'TOKEN-ADS-SECRETO', 'META_CAPI_TOKEN' => 'TOKEN-CAPI-SECRETO', 'META_DATASET_ID' => '998877', 'WA_WABA_ID' => '374665579072339'];
$tokenSoNoCabecalho = function () use (&$CH) {
    foreach ($CH as [$m, $u, $c, $h]) {
        if (strpos($u, 'SECRETO') !== false || strpos(json_encode($c), 'SECRETO') !== false) return false;
    }
    return true;
};

/* ============ 1. nomes dos anuncios ============ */
[$c, $r] = po_meta_nomes_run(['120210000000001'], ['META_ADS_TOKEN' => ''] + $CFG, $AGORA);
ok($c === 200 && $r['configurado'] === false && !$CH, 'sem token: diz que nao esta configurado e nao chama ninguem');

$GRAVADO = [];
$RESP = [
    '~graph\.facebook\.com/v26\.0/120210000000001\?~' => function () { return [200, ['id' => '120210000000001', 'name' => 'Video 1', 'adset' => ['name' => 'Publico 60+'], 'campaign' => ['name' => 'Turquia 2027']]]; },
    '~graph\.facebook\.com/v26\.0/120210000000002\?~' => function () { return [400, ['error' => ['message' => 'Unsupported get request']]]; },
    '~/rest/v1/po_anuncios_meta\?on_conflict=ad_id~' => function ($m, $u, $corpo) use (&$GRAVADO) { $GRAVADO[] = $corpo[0]; return [201, null]; },
];
[$c, $r] = po_meta_nomes_run(['120210000000001', '120210000000002', 'lixo'], $CFG, $AGORA);
ok($c === 200 && $r['nomes'] === ['120210000000001' => ['anuncio' => 'Video 1', 'conjunto' => 'Publico 60+', 'campanha' => 'Turquia 2027']], 'devolve so os nomes achados');
ok(count($GRAVADO) === 2, 'grava os dois: o achado e o erro (para nao pedir de novo a cada carga)');
ok($GRAVADO[0]['campanha'] === 'Turquia 2027' && $GRAVADO[0]['erro'] === null, 'nome achado limpa o erro');
ok(isset($GRAVADO[1]['erro']) && strpos($GRAVADO[1]['erro'], 'Unsupported') !== false && !array_key_exists('anuncio', $GRAVADO[1]),
    'erro grava a mensagem e NAO apaga nome conhecido (nao manda as colunas de nome)');
ok(!array_key_exists('nome_manual', $GRAVADO[0]), 'o nome dado a mao nunca e sobrescrito');
ok(preg_match('~fields=id%2Cname%2Cadset%7Bname%7D%2Ccampaign%7Bname%7D~', $CH[0][1]) === 1, 'pede anuncio, conjunto e campanha');
ok(in_array('Authorization: Bearer TOKEN-ADS-SECRETO', $CH[0][3], true), 'o token vai no cabecalho');
ok($tokenSoNoCabecalho(), 'o token nunca vai na URL nem no corpo');

/* ============ 2. venda de volta para a Meta ============ */
$CH = [];
[$c, $r] = po_meta_conversao_run(['META_DATASET_ID' => '', 'META_CAPI_TOKEN' => ''] + $CFG, $AGORA);
ok($c === 200 && $r['configurado'] === false && $r['falta'] === ['META_DATASET_ID', 'META_CAPI_TOKEN'] && !$CH, 'sem configuracao: diz o que falta, sem chamar ninguem');

/* Um banco de mentira com a regra do po_meta_conversoes: lead_id e chave
   primaria, e o PATCH so muda a linha que casa com o filtro. */
/* $leads null = o banco nao respondeu a leitura das vendas; $regs 'erro' =
   nao respondeu a leitura dos envios anteriores. */
function cenario($leads, $regs, $graph) {
    global $RESP, $CH, $ENVIADOS, $BANCO;
    $CH = []; $ENVIADOS = []; $BANCO = is_array($regs) ? $regs : [];
    $RESP = [
        '~/rest/v1/po_leads\?~' => function () use ($leads) { return is_array($leads) ? [200, $leads] : [500, null]; },
        '~/rest/v1/po_meta_conversoes\?select=~' => function () use ($regs) {
            return $regs === 'erro' ? [503, null] : [200, array_values($regs)];
        },
        '~/rest/v1/po_meta_conversoes$~' => function ($m, $u, $corpo) {
            global $BANCO; $id = $corpo[0]['lead_id'];
            if (isset($BANCO[$id])) return [201, []];          // ignore-duplicates: outro levou
            $BANCO[$id] = $corpo[0]; return [201, [$corpo[0]]];
        },
        '~/rest/v1/po_meta_conversoes\?lead_id=eq\.([^&]+)(&status=eq\.(\w+)&tentativas=eq\.(\d+))?$~' => function ($m, $u, $corpo) {
            global $BANCO;
            preg_match('~lead_id=eq\.([^&]+)(&status=eq\.(\w+)&tentativas=eq\.(\d+))?$~', $u, $x);
            $id = rawurldecode($x[1]);
            if (!empty($x[2])) {   // a posse: so casa se o estado for exatamente o lido
                if (!isset($BANCO[$id]) || $BANCO[$id]['status'] !== $x[3] || (int) $BANCO[$id]['tentativas'] !== (int) $x[4]) return [200, []];
            }
            $BANCO[$id] = $corpo + ($BANCO[$id] ?? []);
            return [200, [$BANCO[$id]]];
        },
        '~graph\.facebook\.com/v26\.0/998877/events$~' => function ($m, $u, $corpo) use ($graph) {
            global $ENVIADOS; $ENVIADOS[] = $corpo['data'][0]; return $graph($corpo['data'][0]);
        },
    ];
}
$V = function ($id, $over = []) use ($AGORA) {
    return $over + ['id' => $id, 'status' => 'venda', 'ctwa_clid' => 'clid-' . $id, 'venda' => 10000, 'venda_at' => gmdate('c', $AGORA - 3600)];
};
$aceita = function () { return [200, ['events_received' => 1, 'fbtrace_id' => 'x']]; };

// caminho feliz: nova vai, falha antiga tenta de novo, enviada nao repete,
// "enviando" nunca e reenviada, fora da janela nem entra
cenario([$V('a'), $V('b'), $V('c'), $V('d'), $V('e', ['venda_at' => gmdate('c', $AGORA - 9 * 86400)])], [
    'b' => ['lead_id' => 'b', 'status' => 'falha', 'tentativas' => 1],
    'c' => ['lead_id' => 'c', 'status' => 'enviado', 'tentativas' => 1],
    'd' => ['lead_id' => 'd', 'status' => 'enviando', 'tentativas' => 1],
], $aceita);
[$c, $r] = po_meta_conversao_run($CFG, $AGORA);
ok($c === 200 && $r['enviados'] === 2 && $r['ja_enviados'] === 1 && $r['falhas'] === 0 && $r['elegiveis'] === 4,
    'nova + retentativa saem, enviada e contada, fora da janela fica fora: ' . json_encode($r));
ok(array_column($ENVIADOS, 'user_data') === [['whatsapp_business_account_id' => '374665579072339', 'ctwa_clid' => 'clid-a'],
    ['whatsapp_business_account_id' => '374665579072339', 'ctwa_clid' => 'clid-b']], 'saiu exatamente a e b, uma vez cada');
ok($BANCO['a']['status'] === 'enviado' && $BANCO['b']['status'] === 'enviado' && $BANCO['b']['tentativas'] === 2, 'marcadas como enviadas');
ok($BANCO['d']['status'] === 'enviando', '"enviando" que ficou para tras nao e reenviado (a Meta pode ter recebido)');
$tocouD = array_filter($CH, function ($x) { return strpos($x[1], 'po_meta_conversoes?lead_id=eq.d') !== false; });
ok(!$tocouD, 'e nem se tenta tomar posse dele: envio em andamento nao e de mais ninguem');
ok($tokenSoNoCabecalho(), 'o token da CAPI nunca vai na URL nem no corpo');

// rodar de novo nao reenvia nada (a Meta nao deduplica)
$depois = $BANCO;
cenario([$V('a'), $V('b')], $depois, $aceita);
[$c, $r] = po_meta_conversao_run($CFG, $AGORA);
ok($r['enviados'] === 0 && $r['ja_enviados'] === 2 && !$ENVIADOS, 'segunda rodada: nada sai de novo');

// outro processo reservou primeiro: este nao envia
cenario([$V('a')], [], $aceita);
$BANCO['a'] = ['lead_id' => 'a', 'status' => 'enviando', 'tentativas' => 1];   // chegou entre a leitura e a reserva
[$c, $r] = po_meta_conversao_run($CFG, $AGORA);
ok($r['enviados'] === 0 && !$ENVIADOS, 'quem nao ficou com a reserva nao envia');

// a Meta recusou: fica como falha, com a mensagem, e conta
cenario([$V('a')], [], function () { return [400, ['error' => ['message' => 'Invalid parameter']]]; });
[$c, $r] = po_meta_conversao_run($CFG, $AGORA);
ok($r['falhas'] === 1 && $BANCO['a']['status'] === 'falha' && strpos($BANCO['a']['erro'], 'Invalid parameter') !== false, 'falha registrada com a mensagem');
// 200 sem events_received tambem e falha
cenario([$V('a')], [], function () { return [200, ['events_received' => 0]]; });
[$c, $r] = po_meta_conversao_run($CFG, $AGORA);
ok($r['falhas'] === 1, '200 sem evento recebido nao e sucesso');
// falha que ja gastou as 3 tentativas nao sai mais
cenario([$V('a')], ['a' => ['lead_id' => 'a', 'status' => 'falha', 'tentativas' => 3]], $aceita);
[$c, $r] = po_meta_conversao_run($CFG, $AGORA);
ok($r['enviados'] === 0 && !$ENVIADOS, 'tres tentativas e para');

// erro lendo os envios anteriores NUNCA vira "nada foi enviado"
cenario([$V('a'), $V('c')], 'erro', $aceita);
[$c, $r] = po_meta_conversao_run($CFG, $AGORA);
ok($c === 502 && !$ENVIADOS, 'sem saber o que ja foi, nao envia nada (reenviaria tudo)');
cenario(null, [], $aceita);
[$c, $r] = po_meta_conversao_run($CFG, $AGORA);
ok($c === 502 && !$ENVIADOS, 'sem conseguir ler as vendas, nao envia');

/* ============ 3. versao da Graph e endpoints ============ */
ok(PO_META_GRAPH === WA_GRAPH, 'a Graph da Meta Ads e a mesma do WhatsApp: o test-wa-graph vigia a validade das duas');
foreach (['anuncios-meta.php', 'meta-conversao.php'] as $ep) {
    $src = $le($ep);
    ok(strpos($src, "!== 'POST'") !== false, "$ep: so POST");
    $auth = strpos($src, 'po_auth_ok('); $roda = strpos($src, '_run(');
    ok($auth !== false && $roda !== false && $auth < $roda, "$ep: confere o login ANTES de fazer qualquer coisa");
}
$sql = $le('supabase/migrations/2026-11-13-meta-anuncios-conversoes.sql');
ok(preg_match('/lead_id\s+uuid primary key/', $sql) === 1, 'po_meta_conversoes: um registro por lead (e a trava contra envio duplo)');
ok(preg_match('/create policy po_meta_conversoes_ler on public\.po_meta_conversoes\s+for select to authenticated/', $sql) === 1
    && substr_count($sql, 'on public.po_meta_conversoes') === 2, 'o painel so LE os envios; quem escreve e o servidor');

echo "test-meta OK\n";
