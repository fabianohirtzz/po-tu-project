<?php
/* ============================================================
   Pereira Oliveira Turismo — modelos de mensagem (templates) da Meta.

     modo=conferir -> aponta o que a Meta recusaria. NAO chama a Meta.
     modo=criar    -> submete o modelo para aprovacao.
     modo=listar   -> os modelos da conta, com o status de cada um.

   Por que existe: ate aqui a cliente escrevia o texto na aba "Textos do
   robo" e alguem copiava para o WhatsApp Manager na mao. Nada garantia que
   o texto escrito e o modelo submetido fossem o mesmo, e e o modelo que
   sai para o cliente.

   O 'conferir' e separado de proposito: a tela consegue apontar o erro
   enquanto a cliente digita, sem gastar chamada nem criar modelo torto que
   depois fica para sempre na conta (a Meta nao deixa renomear).
============================================================ */

require_once __DIR__ . '/lib/po-auth.php';
require_once __DIR__ . '/lib/wa-template.php';   // ja carrega wa-send -> wa-config

header('Content-Type: application/json; charset=utf-8');

/* A mensagem de erro NUNCA carrega nome, telefone nem trecho de ficha: ela
   vaza para log, print e suporte. */
function tfail($code, $msg) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function tok($dados) {
    echo json_encode(['ok' => true] + $dados, JSON_UNESCAPED_UNICODE);
    exit;
}

/* Campo de POST como string. Sem isto, um "modo[]=x" chega array e o
   in_array/preg_match estoura TypeError no PHP 8. */
function tpost($k) {
    $v = $_POST[$k] ?? '';
    return is_string($v) ? trim($v) : '';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') tfail(405, 'Método não permitido.');

$cfg = wa_config();
if (!po_auth_ok($cfg['SUPABASE_URL'] ?? '', $cfg['SUPABASE_ANON_KEY'] ?? '')) {
    tfail(401, 'Sessão expirada. Entre de novo no painel.');
}

$modo = tpost('modo');
if (!in_array($modo, ['conferir', 'criar', 'listar'], true)) {
    tfail(400, 'Modo inválido.');
}

/* Quantas variaveis o texto deve ter. Vem da tela porque a transmissao usa
   uma ({{1}} = nome) e um aviso operacional pode nao usar nenhuma. O padrao
   e 1: e o caso que custa dinheiro se estiver errado. */
$exemplo   = tpost('exemplo');
$exemplos  = $exemplo === '' ? [] : [$exemplo];
/* Sem trim, ao contrario dos outros campos: espaco ou quebra de linha nas
   pontas E um dos defeitos que a Meta recusa, entao aparar aqui esconderia o
   problema da tela e a recusa voltaria da Meta horas depois. */
$corpo_bruto = $_POST['corpo'] ?? '';
$corpo       = is_string($corpo_bruto) ? $corpo_bruto : '';

/* ------------------------------------------------------------
   conferir: o mesmo julgamento que o criar faz, sem gastar chamada.
   A MESMA funcao nos dois caminhos - uma tela que valide diferente do envio
   aprova o que a Meta recusa, e o contrario.
------------------------------------------------------------ */
if ($modo === 'conferir') {
    $problemas = wa_tpl_problemas($corpo, count($exemplos));
    tok([
        'problemas' => $problemas,
        'nome'      => wa_tpl_nome(tpost('titulo')),
        'caracteres'=> mb_strlen($corpo, 'UTF-8'),
        'limite'    => WA_TPL_CORPO_MAX,
    ]);
}

if ($modo === 'listar') {
    $l = wa_tpl_lista();
    // null e erro de leitura, nao conta vazia. Confundir os dois faria a tela
    // dizer "nenhum modelo" com a Meta fora, e alguem criaria um duplicado.
    if ($l === null) tfail(502, 'Não consegui ler os modelos agora.');
    $limpo = [];
    foreach ($l as $t) {
        $limpo[] = [
            'nome'     => (string) ($t['name'] ?? ''),
            'status'   => (string) ($t['status'] ?? ''),
            'categoria'=> (string) ($t['category'] ?? ''),
            'idioma'   => (string) ($t['language'] ?? ''),
        ];
    }
    tok(['templates' => $limpo]);
}

/* ------------------------------------------------------------
   criar
------------------------------------------------------------ */
$titulo    = tpost('titulo');
$categoria = tpost('categoria') ?: 'MARKETING';

if ($titulo === '') tfail(400, 'Dê um nome ao modelo.');

$r = wa_tpl_cria($titulo, $categoria, $corpo, $exemplos);
if (!$r['ok']) {
    // 422: o texto e que esta errado, nao a requisicao. A tela distingue para
    // mostrar a lista de problemas em vez de "erro inesperado".
    $codigo = isset($r['problemas']) ? 422 : 502;
    http_response_code($codigo);
    echo json_encode(['ok' => false, 'error' => $r['erro'],
                      'problemas' => $r['problemas'] ?? []], JSON_UNESCAPED_UNICODE);
    exit;
}
tok(['id' => $r['id'], 'nome' => $r['nome'], 'status' => $r['status']]);
