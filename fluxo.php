<?php
/* ============================================================
   Pereira Oliveira Turismo — fluxo de conversa do robo, lado do painel.

     modo=ler      -> os fluxos com os cards do rascunho de cada um, a versao
                      publicada e se o rascunho ainda bate com ela.
     modo=conferir -> o julgamento do fluxo (fluxo_id), SEM gravar nada.
     modo=publicar -> congela o rascunho como a proxima versao (wa_fluxo_publica
                      valida por dentro e NAO toca em `ativo`) e limpa o cache.
     modo=ligar    -> poe o fluxo no ar (ativo=true). E ato a parte do publicar.
     modo=desligar -> tira do ar. Nao olha o grafo: um fluxo quebrado tem que
                      poder ser desligado, senao fica atendendo cliente real.

   O texto de cada problema vem de wa_fluxo_valida e e repassado sem
   alteracao: e a mesma funcao que o publicar roda por dentro, entao a tela
   nunca aprova o que a publicacao recusa. Pelo mesmo motivo o conferir passa
   a lista dos DEMAIS fluxos ($outros): e com ela que as regras de porta
   repetida e de salto para fluxo desligado enxergam alguma coisa.

   Leitura de banco que falha e 502, nunca lista vazia: a tela diria que o
   fluxo nao tem card nenhum com o Supabase fora do ar.
============================================================ */

require_once __DIR__ . '/lib/po-auth.php';
require_once __DIR__ . '/lib/wa-fluxo.php';   // ja carrega wa-db, wa-send -> wa-config

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/* A mensagem de erro NUNCA carrega nome, telefone nem trecho de ficha: ela
   vaza para log, print e suporte. */
function ffail($code, $msg) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function fok($dados) {
    echo json_encode(['ok' => true] + $dados, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/* Campo de POST como string. Sem isto, "modo[]=x" chega array e o in_array
   estoura TypeError no PHP 8. */
function fpost($k) {
    $v = $_POST[$k] ?? '';
    return is_string($v) ? trim($v) : '';
}

/* Chaves em ordem, para comparar o retrato do banco (jsonb nao guarda a ordem
   das chaves) com o recem montado. Listas mantem a ordem. */
function fcanon($v) {
    if (!is_array($v)) return $v;
    $v = array_map('fcanon', $v);
    if (array_keys($v) !== range(0, count($v) - 1)) ksort($v);
    return $v;
}

function fgrafo_igual($a, $b) {
    if (is_string($b)) $b = json_decode($b, true);
    if (!is_array($a) || !is_array($b)) return false;
    $ja = json_encode(fcanon($a));
    // Nao conseguir codificar nao e "igual".
    return $ja !== false && $ja === json_encode(fcanon($b));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') ffail(405, 'Método não permitido.');

$cfg = wa_config();
if (!po_auth_ok($cfg['SUPABASE_URL'] ?? '', $cfg['SUPABASE_ANON_KEY'] ?? '')) {
    ffail(401, 'Sessão expirada. Entre de novo no painel.');
}

$modo = fpost('modo');
if (!in_array($modo, ['ler', 'conferir', 'publicar', 'ligar', 'desligar'], true)) {
    ffail(400, 'Modo inválido.');
}

$FALHA_LEITURA = 'Não consegui ler o banco agora. Tente de novo em instantes.';

/* ------------------------------------------------------------
   ler
------------------------------------------------------------ */
if ($modo === 'ler') {
    $fluxos = wa_fl_ler('po_wa_fluxos', 'select=*&order=created_at.asc');
    $cards  = wa_fl_ler('po_wa_cards', 'select=*&order=ordem.asc');
    if ($fluxos === null || $cards === null) ffail(502, $FALHA_LEITURA);

    $ids_versao = [];
    foreach ($fluxos as $f) {
        if (($f['versao_atual'] ?? '') !== '') $ids_versao[] = rawurlencode((string) $f['versao_atual']);
    }
    $versoes = [];
    if ($ids_versao) {
        $linhas = wa_fl_ler('po_wa_fluxo_versoes', 'id=in.(' . implode(',', $ids_versao) . ')&select=id,versao,grafo');
        if ($linhas === null) ffail(502, $FALHA_LEITURA);
        foreach ($linhas as $l) $versoes[(string) $l['id']] = $l;
    }

    $por_fluxo = [];
    foreach ($cards as $c) $por_fluxo[(string) ($c['fluxo_id'] ?? '')][] = $c;

    $saida = [];
    foreach ($fluxos as $f) {
        $id    = (string) $f['id'];
        $meus  = $por_fluxo[$id] ?? [];
        $va    = ($f['versao_atual'] ?? '') !== '' ? (string) $f['versao_atual'] : null;
        $v     = $va !== null ? ($versoes[$va] ?? null) : null;
        $saida[] = [
            'id'              => $id,
            'nome'            => $f['nome'] ?? '',
            'gatilho'         => $f['gatilho'] ?? '',
            'gatilho_valor'   => $f['gatilho_valor'] ?? null,
            'ativo'           => !empty($f['ativo']),
            'card_inicial'    => $f['card_inicial'] ?? null,
            'versao_atual'    => $va,
            'versao_numero'   => $v !== null ? (int) $v['versao'] : null,
            // Sem updated_at nos cards: compara-se o retrato, a mesma funcao do publicar.
            'publicado_igual' => $v !== null && fgrafo_igual(wa_fluxo_grafo_de($f, $meus), $v['grafo'] ?? null),
            'cards'           => $meus,
        ];
    }
    fok(['fluxos' => $saida]);
}

/* ------------------------------------------------------------
   Os demais modos pedem um fluxo
------------------------------------------------------------ */
$SEM_ID = [
    'conferir' => 'Não foi dito qual fluxo conferir.',
    'publicar' => 'Não foi dito qual fluxo publicar.',
    'ligar'    => 'Não foi dito qual fluxo ligar.',
    'desligar' => 'Não foi dito qual fluxo desligar.',
];
$fluxo_id = fpost('fluxo_id');
if ($fluxo_id === '') ffail(400, $SEM_ID[$modo]);
$id = rawurlencode($fluxo_id);

/* ------------------------------------------------------------
   conferir
------------------------------------------------------------ */
if ($modo === 'conferir') {
    $linhas = wa_fl_ler('po_wa_fluxos', 'id=eq.' . $id . '&limit=1');
    if ($linhas === null) ffail(502, $FALHA_LEITURA);
    if (!$linhas) ffail(404, 'Este fluxo não existe.');

    $cards  = wa_fl_ler('po_wa_cards', 'fluxo_id=eq.' . $id . '&order=ordem.asc');
    $outros = wa_fl_ler('po_wa_fluxos', 'select=id,gatilho,gatilho_valor,ativo,versao_atual');
    if ($cards === null || $outros === null) ffail(502, $FALHA_LEITURA);

    fok(['problemas' => wa_fluxo_valida($linhas[0], $cards, $outros)]);
}

/* ------------------------------------------------------------
   publicar
   Repassa o que wa_fluxo_publica devolve, sem revalidar e sem filtrar: os
   AVISOS (regras 15 e 16) chegam a tela junto com a publicacao permitida.
   Publicar nao liga: `ativo` nao e escrito aqui nem la.
------------------------------------------------------------ */
if ($modo === 'publicar') {
    $r = wa_fluxo_publica($fluxo_id);
    if (empty($r['ok'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'problemas' => $r['problemas'] ?? []],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
    /* Limpa o cache do SITE PUBLICO (roteiros, avaliacoes, instagram). O fluxo
       em si nao e cacheado: o motor le por wa_db_select_estrito, sem cache em
       disco. A chamada existe por decisao do plano, nao por necessidade do fluxo. */
    po_cache_limpa();
    fok(['versao' => (int) $r['versao'], 'problemas' => $r['problemas'] ?? []]);
}

/* ------------------------------------------------------------
   ligar / desligar
------------------------------------------------------------ */
$linhas = wa_fl_ler('po_wa_fluxos', 'id=eq.' . $id . '&limit=1');
if ($linhas === null) ffail(502, $FALHA_LEITURA);
if (!$linhas) ffail(404, 'Este fluxo não existe.');
$fluxo = $linhas[0];

if ($modo === 'desligar') {
    if (!wa_fl_call('update', 'po_wa_fluxos', 'id=eq.' . $id, ['ativo' => false])) {
        ffail(502, 'Não consegui desligar o fluxo agora. Tente de novo em instantes.');
    }
    fok(['ativo' => false]);
}

if ($modo === 'ligar') {
    // Fluxo nunca publicado nao atende ninguem, mas ocuparia a porta.
    if (($fluxo['versao_atual'] ?? '') === '') ffail(400, 'Publique o fluxo antes de ligar.');

    if (!wa_fl_call('update', 'po_wa_fluxos', 'id=eq.' . $id, ['ativo' => true])) {
        /* O indice po_wa_fluxos_porta_ativa_uniq recusa dois fluxos ligados na
           mesma (gatilho, coalesce(gatilho_valor,'')). wa_db_update nao devolve
           o status, entao pergunta-se quem ocupa a porta: sem o nome a cliente
           leria um erro de banco e nao teria o que fazer (mesma ideia do
           rotSaveErro do painel). O nome e texto escrito por ela no painel, nao
           dado de cliente. */
        $ativos = wa_fl_ler('po_wa_fluxos', 'ativo=eq.true&select=id,nome,gatilho,gatilho_valor');
        if ($ativos === null) ffail(502, $FALHA_LEITURA);
        foreach ($ativos as $a) {
            if ((string) $a['id'] === (string) $fluxo['id']) continue;
            if (($a['gatilho'] ?? '') === ($fluxo['gatilho'] ?? '')
                && (string) ($a['gatilho_valor'] ?? '') === (string) ($fluxo['gatilho_valor'] ?? '')) {
                ffail(409, 'Já existe outro fluxo ligado nesta porta de entrada: "' . (string) ($a['nome'] ?? '')
                    . '". Desligue esse fluxo antes ou mude a porta deste.');
            }
        }
        ffail(502, 'Não consegui ligar o fluxo agora. Tente de novo em instantes.');
    }
    fok(['ativo' => true]);
}

ffail(400, 'Modo inválido.');
