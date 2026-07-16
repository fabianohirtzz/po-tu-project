<?php
/* ============================================================
   Pereira Oliveira Turismo — Importador de roteiros com IA.

   Recebe do painel (/painel) um arquivo de roteiro e devolve os
   dados JÁ ESTRUTURADOS (título, dias, inclui, hotéis, valores...)
   usando o Google Gemini. O painel preenche o formulário e um
   humano revisa antes de salvar.

   Hospedagem: cPanel   ·   Domínio: pereiraoliveiraturismo.com.br

   POR QUE UM PROXY PHP (e não chamar o Gemini do navegador):
   a chave da API é SEGREDO. Fica só no servidor, em
   config.local.php, igual à senha do SMTP no enviar.php.

   ------------------------------------------------------------
   ENTRADA (POST, multipart/form-data):
     - pdf   : arquivo PDF (o Gemini lê multimodal, com layout)
       OU
     - text  : texto já extraído (docx/pptx) para estruturar
     - sb_token : access_token do Supabase (valida o login)
   SAÍDA (JSON): { ok:true, data:{...roteiro...} }
============================================================ */

// ---------------------- CONFIG ----------------------
$GEMINI_API_KEY = '';                    // defina no config.local.php
$GEMINI_MODEL   = 'gemini-flash-latest'; // alias sempre no flash estável mais novo (tier
                                         // gratuito do AI Studio, multimodal/PDF). Para fixar
                                         // uma versão, defina $GEMINI_MODEL no config.local.php.

// Supabase — URL e anon key são PÚBLICAS (validam o login do painel).
$SUPABASE_URL      = 'https://euzmbswywwhmicjlszqw.supabase.co';
$SUPABASE_ANON_KEY = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImV1em1ic3d5d3dobWljamxzenF3Iiwicm9sZSI6ImFub24iLCJpYXQiOjE3ODA0NDEyODYsImV4cCI6MjA5NjAxNzI4Nn0.oSIv6fSKVxO9Umuii6xt98cT0yoSqepTIzVCdcocfuU';

$MAX_PDF_BYTES = 12 * 1024 * 1024;    // guarda p/ caber no limite inline do Gemini
// ----------------------------------------------------

if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

header('Content-Type: application/json; charset=utf-8');

function fail($code, $msg) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fail(405, 'Método não permitido.');
}
if (!function_exists('curl_init')) {
    fail(500, 'cURL indisponível no servidor.');
}
if ($GEMINI_API_KEY === '' || strpos($GEMINI_API_KEY, 'COLOQUE') !== false) {
    fail(500, 'GEMINI_API_KEY não configurada no config.local.php.');
}

/* -------- valida o login (Supabase Auth) -------- */
function bearerToken() {
    if (!empty($_POST['sb_token'])) return trim((string) $_POST['sb_token']);
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($h === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strtolower($k) === 'authorization') { $h = $v; break; }
        }
    }
    return preg_match('/Bearer\s+(.+)/i', $h, $m) ? trim($m[1]) : '';
}

function usuarioValido($url, $anon, $token) {
    if ($token === '') return false;
    $ch = curl_init(rtrim($url, '/') . '/auth/v1/user');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'apikey: ' . $anon,
            'Authorization: Bearer ' . $token,
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $code < 200 || $code >= 300) return false;
    $u = json_decode($resp, true);
    return is_array($u) && !empty($u['id']);
}

if (!usuarioValido($SUPABASE_URL, $SUPABASE_ANON_KEY, bearerToken())) {
    fail(401, 'Sessão inválida. Faça login no painel novamente.');
}

/* -------- monta o conteúdo para o Gemini -------- */
$parts = [];
$temPdf = isset($_FILES['pdf']) && $_FILES['pdf']['error'] === UPLOAD_ERR_OK;
$texto  = isset($_POST['text']) ? trim((string) $_POST['text']) : '';

if ($temPdf) {
    if ($_FILES['pdf']['size'] > $MAX_PDF_BYTES) {
        fail(413, 'PDF grande demais (máx. 12 MB). Use docx/pptx ou reduza o arquivo.');
    }
    $bin = file_get_contents($_FILES['pdf']['tmp_name']);
    if ($bin === false || $bin === '') fail(400, 'Falha ao ler o PDF.');
    $parts[] = ['inline_data' => ['mime_type' => 'application/pdf', 'data' => base64_encode($bin)]];
} elseif ($texto !== '') {
    $parts[] = ['text' => "DOCUMENTO DO ROTEIRO:\n\n" . $texto];
} else {
    fail(400, 'Envie um PDF (campo "pdf") ou o texto (campo "text").');
}

$instrucao =
"Você é um assistente que extrai os dados de um roteiro de viagem em grupo (agência " .
"brasileira) a partir do documento anexo e devolve APENAS o JSON no schema pedido, em " .
"português do Brasil. LEIA o documento pelo layout visual (não pela ordem crua do texto): " .
"quando dois blocos estão lado a lado ou fora de ordem, use as posições na página para " .
"entender o que pertence a quê.\n\n" .
"Regras:\n" .
"- Extraia FIELMENTE o que está no documento. Não invente nada. Se um campo não existir, " .
"deixe string vazia ou lista vazia.\n" .
"- titulo: o nome do roteiro/destino como na CAPA, em Caixa de Título correta " .
"(ex.: \"Turquia com Antália\", nunca \"turquia\" em minúsculas). Ignore letras soltas, " .
"chamadas de marketing e arte gráfica embaralhada (ex.: \"VOCÊ PRECISA EXPERIMENTAR\").\n" .
"- subtitulo: a linha de destinos/chamada que acompanha o título na capa " .
"(ex.: \"Istambul, Capadócia, Antália, Pamukkale e Kusadasi (Éfeso)\"). Vazio se não houver.\n" .
"- periodo: quando a viagem acontece (mês/ano), se aparecer (ex.: \"Outubro 2026\").\n" .
"- dias: total de dias do roteiro (conte os dias numerados). noites: total de noites/diárias " .
"em destino (some as noites por cidade se o documento listar, ex.: 5+3+3+1+2).\n" .
"- descricao_curta: 1 a 2 frases de resumo do destino, com base no documento.\n" .
"- roteiro_dias: um item por dia, ORDENADOS pelo número do dia (crescente). " .
"n = número do dia; data = SOMENTE a data (ex.: \"27/10\"), sem cidade nem texto; " .
"dia_semana = só se o documento indicar, senão vazio; cidades = a(s) cidade(s) ou o trecho " .
"do dia, separado da data (ex.: \"Istambul\", \"Istambul > Capadócia\"); descricao = o " .
"parágrafo do dia; refeicoes = SOMENTE as refeições explicitamente incluídas no dia " .
"(ex.: \"Café da manhã e jantar incluídos\" vira \"Café da manhã, Jantar\"). O que estiver " .
"\"livre\" NÃO é refeição incluída; deixe vazio se nada for incluído.\n" .
"- SEPARE hotéis, inclui e não-inclui com atenção ao layout: os hotéis costumam aparecer " .
"LISTADOS DENTRO do bloco \"SEU ROTEIRO INCLUI\" (ex.: \"Istambul: Ramada Taksim – 4 estrelas\"). " .
"Esses vão em 'hoteis' (um por cidade) e NÃO devem se repetir em 'inclui'.\n" .
"- inclui: os demais itens do bloco \"INCLUI\" que NÃO são hotéis (ex.: transporte, guia, " .
"alimentação, passeios, traslados, taxas, gorjetas, acompanhamento). Um item por linha.\n" .
"- nao_inclui: os itens do bloco \"NÃO INCLUI\". Um item por linha.\n" .
"- valores: cada faixa de preço. tag = a que se refere (ex.: \"Quarto duplo (por pessoa)\", " .
"\"Quarto individual\", \"Aéreo (classe econômica)\"); de = prefixo se houver (ex.: " .
"\"a partir de\"), senão vazio; valor = o preço principal como aparece, preferindo o total " .
"em reais quando o documento der EUR e R\$ (ex.: \"R\$ 17.364,60\"); extra = complemento " .
"como valor em outra moeda e forma de pagamento numa frase só (ex.: \"EUR 2.631,00 · entrada " .
"R\$ 3.960,00 + saldo em 10x de R\$ 1.340,46\"). Vazio se não houver.\n" .
"Não use travessões longos na copy; sem emojis.";

$schema = [
    'type' => 'OBJECT',
    'properties' => [
        'titulo'          => ['type' => 'STRING'],
        'subtitulo'       => ['type' => 'STRING'],
        'periodo'         => ['type' => 'STRING'],
        'dias'            => ['type' => 'INTEGER'],
        'noites'          => ['type' => 'INTEGER'],
        'descricao_curta' => ['type' => 'STRING'],
        'roteiro_dias'    => [
            'type'  => 'ARRAY',
            'items' => [
                'type' => 'OBJECT',
                'properties' => [
                    'n'          => ['type' => 'INTEGER'],
                    'data'       => ['type' => 'STRING'],
                    'dia_semana' => ['type' => 'STRING'],
                    'cidades'    => ['type' => 'STRING'],
                    'descricao'  => ['type' => 'STRING'],
                    'refeicoes'  => ['type' => 'STRING'],
                ],
                'propertyOrdering' => ['n', 'data', 'dia_semana', 'cidades', 'descricao', 'refeicoes'],
            ],
        ],
        'inclui'     => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
        'nao_inclui' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
        'hoteis'     => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
        'valores'    => [
            'type'  => 'ARRAY',
            'items' => [
                'type' => 'OBJECT',
                'properties' => [
                    'tag'   => ['type' => 'STRING'],
                    'de'    => ['type' => 'STRING'],
                    'valor' => ['type' => 'STRING'],
                    'extra' => ['type' => 'STRING'],
                ],
                'propertyOrdering' => ['tag', 'de', 'valor', 'extra'],
            ],
        ],
    ],
    'propertyOrdering' => ['titulo', 'subtitulo', 'periodo', 'dias', 'noites', 'descricao_curta',
        'roteiro_dias', 'inclui', 'nao_inclui', 'hoteis', 'valores'],
];

$body = [
    'contents'         => [['parts' => array_merge([['text' => $instrucao]], $parts)]],
    'generationConfig' => [
        'responseMimeType' => 'application/json',
        'responseSchema'   => $schema,
        'temperature'      => 0.1,
    ],
];

$endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/'
    . rawurlencode($GEMINI_MODEL) . ':generateContent?key=' . urlencode($GEMINI_API_KEY);

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 90,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$cerr = curl_error($ch);
curl_close($ch);

if ($resp === false) {
    error_log('PO importar cURL: ' . $cerr);
    fail(502, 'Não foi possível falar com o Gemini.');
}
if ($code < 200 || $code >= 300) {
    error_log('PO importar Gemini ' . $code . ': ' . substr($resp, 0, 500));
    $j = json_decode($resp, true);
    $m = $j['error']['message'] ?? ('Erro do Gemini (' . $code . ').');
    fail(502, $m);
}

$data = json_decode($resp, true);
$jsonText = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
$roteiro  = json_decode($jsonText, true);
if (!is_array($roteiro)) {
    error_log('PO importar: JSON do Gemini inválido: ' . substr($jsonText, 0, 500));
    fail(502, 'A IA não devolveu um roteiro válido. Tente de novo.');
}

echo json_encode(['ok' => true, 'data' => $roteiro], JSON_UNESCAPED_UNICODE);
