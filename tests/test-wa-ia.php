<?php
/* lib/wa-ia.php: a IA so CLASSIFICA a primeira mensagem de numero desconhecido
   (e lead pedindo informacao, ou nao?). Nunca responde ao cliente.
   Nenhum teste toca a rede: tudo passa pelo transporte injetado. */
require_once __DIR__ . '/../lib/wa-ia.php';

function ok($c, $m) { if (!$c) { fwrite(STDERR, "ASSERT: $m\n"); exit(1); } }

$CHAMADAS = [];
$RESPOSTAS = [];   // fila: cada chamada consome a primeira
wa_ia_set_transport(function ($metodo, $url, $headers, $corpo, $timeout) {
    $GLOBALS['CHAMADAS'][] = compact('metodo', 'url', 'headers', 'corpo', 'timeout');
    return array_shift($GLOBALS['RESPOSTAS']);
});
function gemini($obj) {
    return ['status' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => json_encode($obj)]]]]]])];
}
$CFG = ['GEMINI_API_KEY' => 'chave-de-teste-1234567890', 'WA_TOKEN' => 'token-wa-teste'];
function ev($tipo, $texto = '', $extra = []) {
    return array_merge(['tipo' => 'mensagem', 'wa_id' => '+5548900000001', 'wamid' => 'w1', 'tipo_msg' => $tipo,
        'texto' => $texto, 'nome' => 'X', 'ad_id' => null, 'ctwa_clid' => null, 'ts' => 0], $extra);
}
function zera() { $GLOBALS['CHAMADAS'] = []; $GLOBALS['RESPOSTAS'] = []; }

// --- sem chave: nao chama ninguem
zera();
ok(wa_ia_lead(ev('text', 'quero viajar'), ['GEMINI_API_KEY' => '']) === null, 'sem chave devolve null');
ok(!$CHAMADAS, 'sem chave nao toca a rede');

// --- texto: uma chamada so, ao Gemini, com a instrucao e o texto
zera();
$RESPOSTAS[] = gemini(['lead' => true, 'confianca' => 0.97, 'motivo' => 'pede valores']);
$r = wa_ia_lead(ev('text', 'Quanto custa a Turquia?'), $CFG);
ok($r === ['lead' => true, 'confianca' => 0.97, 'motivo' => 'pede valores', 'transcricao' => ''], 'le o veredito (sem transcricao vem vazia)');
ok(count($CHAMADAS) === 1 && strpos($CHAMADAS[0]['url'], 'generativelanguage.googleapis.com') !== false, 'uma chamada ao Gemini');
ok(strpos($CHAMADAS[0]['url'], WA_IA_MODELO) !== false, 'no modelo fixado');
$corpo = json_decode($CHAMADAS[0]['corpo'], true);
ok(strpos(json_encode($corpo, JSON_UNESCAPED_UNICODE), 'Quanto custa a Turquia?') !== false, 'o texto vai no pedido');
ok(!empty($corpo['systemInstruction']['parts'][0]['text']), 'com a instrucao');
ok(($corpo['generationConfig']['responseSchema']['required'] ?? []) === ['lead', 'confianca', 'motivo', 'transcricao'], 'com o formato de resposta');
// a chave vai no cabecalho, nunca na URL (URL cai em log)
ok(strpos($CHAMADAS[0]['url'], 'chave-de-teste') === false, 'a chave nao vai na URL');
ok(in_array('x-goog-api-key: chave-de-teste-1234567890', $CHAMADAS[0]['headers'], true), 'a chave vai no cabecalho');

// --- audio: baixa da Meta (2 chamadas com o token) e manda inline, mime sem parametro
zera();
$RESPOSTAS[] = ['status' => 200, 'body' => json_encode(['url' => 'https://lookaside.fbsbx.com/x', 'mime_type' => 'audio/ogg; codecs=opus', 'file_size' => 5000])];
$RESPOSTAS[] = ['status' => 200, 'body' => 'BYTESDOAUDIO'];
$RESPOSTAS[] = gemini(['lead' => false, 'confianca' => 0.9, 'motivo' => 'conversa pessoal', 'transcricao' => 'oi tudo bem']);
$r = wa_ia_lead(ev('audio', '', ['media_id' => 'MID1']), $CFG);
ok($r['lead'] === false && $r['transcricao'] === 'oi tudo bem', 'audio classificado e transcrito');
ok(count($CHAMADAS) === 3, 'meta (metadado), meta (bytes), gemini');
ok($CHAMADAS[0]['url'] === WA_GRAPH . 'MID1', 'metadado pelo id da midia');
ok(in_array('Authorization: Bearer token-wa-teste', $CHAMADAS[0]['headers'], true), 'metadado com o token');
ok($CHAMADAS[1]['url'] === 'https://lookaside.fbsbx.com/x' && in_array('Authorization: Bearer token-wa-teste', $CHAMADAS[1]['headers'], true), 'bytes com o token');
$inline = json_decode($CHAMADAS[2]['corpo'], true)['contents'][0]['parts'][1]['inlineData'] ?? [];
ok(($inline['mimeType'] ?? '') === 'audio/ogg', 'mime sem o "; codecs"');
ok(($inline['data'] ?? '') === base64_encode('BYTESDOAUDIO'), 'bytes em base64');

// --- midia grande demais: nem baixa
zera();
$RESPOSTAS[] = ['status' => 200, 'body' => json_encode(['url' => 'https://x/y', 'mime_type' => 'video/mp4', 'file_size' => WA_IA_MIDIA_MAX + 1])];
ok(wa_ia_lead(ev('video', '', ['media_id' => 'MID2']), $CFG) === null, 'video grande demais: null');
ok(count($CHAMADAS) === 1, 'e nao baixa os bytes');

// --- falhas viram null (quem chama decide o que fazer sem a IA)
zera(); $RESPOSTAS[] = null;
ok(wa_ia_lead(ev('text', 'viagem'), $CFG) === null, 'rede fora: null');
zera(); $RESPOSTAS[] = ['status' => 429, 'body' => '{"error":{}}'];
ok(wa_ia_lead(ev('text', 'viagem'), $CFG) === null, 'erro do Gemini: null');
zera(); $RESPOSTAS[] = ['status' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => 'nao e json']]]]]])];
ok(wa_ia_lead(ev('text', 'viagem'), $CFG) === null, 'resposta ilegivel: null');
zera(); $RESPOSTAS[] = gemini(['lead' => 'sim', 'confianca' => 1, 'motivo' => '']);
ok(wa_ia_lead(ev('text', 'viagem'), $CFG) === null, 'lead que nao e booleano: null');
zera(); $RESPOSTAS[] = ['status' => 500, 'body' => ''];
ok(wa_ia_lead(ev('audio', '', ['media_id' => 'MID3']), $CFG) === null, 'falha ao baixar a midia: null');
ok(count($CHAMADAS) === 1, 'e nao chama o Gemini sem a midia');
zera();
ok(wa_ia_lead(ev('audio', ''), $CFG) === null && !$CHAMADAS, 'audio sem media_id: null, sem rede');

// --- a regua: so aceita lead com confianca alta
ok(wa_ia_aceita(['lead' => true, 'confianca' => 0.95, 'motivo' => '']) === true, 'lead confiante');
ok(wa_ia_aceita(['lead' => true, 'confianca' => 0.5, 'motivo' => '']) === false, 'lead em duvida fica com a Lais');
ok(wa_ia_aceita(['lead' => false, 'confianca' => 0.3, 'motivo' => '']) === false, 'nao-lead e nao-lead');
ok(wa_ia_aceita(null) === null, 'sem veredito: null');

// --- a legenda de imagem/video vai junto
zera();
$RESPOSTAS[] = ['status' => 200, 'body' => json_encode(['url' => 'https://x/i', 'mime_type' => 'image/jpeg', 'file_size' => 10])];
$RESPOSTAS[] = ['status' => 200, 'body' => 'IMG'];
$RESPOSTAS[] = gemini(['lead' => true, 'confianca' => 0.9, 'motivo' => 'print do anuncio']);
wa_ia_lead(ev('image', '', ['media_id' => 'MID4', 'legenda' => 'quero esse aqui']), $CFG);
ok(strpos($CHAMADAS[2]['corpo'], 'quero esse aqui') !== false, 'legenda vai no pedido');

echo "test-wa-ia OK\n";
