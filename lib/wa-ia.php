<?php
/* ============================================================
   IA que so CLASSIFICA o primeiro contato (27/11/2026).

   Pergunta uma coisa ao Gemini: a primeira mensagem deste numero desconhecido
   e de alguem pedindo informacao sobre viagem? A IA NUNCA responde ao cliente,
   nunca escreve no banco e nunca decide sozinha: devolve um veredito e o motor
   (wa_parece_lead) decide. Qualquer falha devolve null, e o motor segue a
   regra de antes, como se a IA nao existisse.

   So entra em jogo onde a regra de palavras nao basta: audio, video e imagem
   (que a regra nao le) e texto que so tem palavra de viagem (que pode ser de
   conhecido: "Lais, e aquela viagem do meu pai?"). Medido em 27/11 com o
   flash-lite: 6/6 frases e 2/2 audios certos, ~1 s por texto, ~2 s por audio,
   ~400 tokens. Com uns 500 primeiros contatos por mes, fica em centavos.

   A chave vai no CABECALHO, nunca na URL: URL cai em log de erro.
   Midia vem da Meta (Graph) com o WA_TOKEN e vai inline em base64.
============================================================ */

require_once __DIR__ . '/wa-config.php';
require_once __DIR__ . '/wa-send.php';   // WA_GRAPH

/* Alias do flash-lite mais novo, como o importador faz com o flash: versao
   numerada aposentada viraria erro e a IA sumiria em silencio (null). */
const WA_IA_MODELO    = 'gemini-flash-lite-latest';
/* Abaixo disto, "lead" e duvida, e na duvida a conversa fica com a Lais: o
   pior erro do sistema e o robo entrar em conversa pessoal. */
const WA_IA_CONFIANCA = 0.8;
/* Midia acima disto nem e baixada: o pedido inline do Gemini aceita ~20 MB
   e um audio de 2 minutos tem ~200 KB. Video grande fica com a Lais. */
const WA_IA_MIDIA_MAX = 8 * 1024 * 1024;
const WA_IA_MIDIAS    = ['audio', 'video', 'image'];

const WA_IA_INSTRUCAO = 'Você classifica a PRIMEIRA mensagem que um número desconhecido mandou para o WhatsApp da Pereira Oliveira Turismo, uma agência de viagens em grupo de Florianópolis. A mensagem pode ser texto, áudio, imagem ou vídeo.

Responda lead=true SOMENTE se a pessoa está pedindo informação sobre viagens, roteiros, excursões, pacotes, preços, datas ou vagas, como um cliente novo ou antigo que quer viajar de novo.

Responda lead=false se for: conversa pessoal ou familiar com a dona (Laís), amigo ou conhecido, relato de viagem que já aconteceu, fornecedor, prestador de serviço, vendedor oferecendo algo, cobrança, assunto de trabalho interno, saudação solta sem pedido ("oi", "bom dia"), ou qualquer coisa que você não consiga entender.

Na dúvida, lead=false. confianca vai de 0 a 1. motivo em até 10 palavras, em português.

transcricao: o que a pessoa disse ou escreveu, em português, fiel e em até 300 caracteres. Em imagem, o texto visível nela. Sem conteúdo, vazio.';

function wa_ia_set_transport($f) { $GLOBALS['WA_IA_TRANSPORT'] = $f; }

/* ['status' => int, 'body' => string] ou null (rede fora). */
function wa_ia_http($metodo, $url, $headers, $corpo = null, $timeout = 10) {
    if (!empty($GLOBALS['WA_IA_TRANSPORT'])) {
        return call_user_func($GLOBALS['WA_IA_TRANSPORT'], $metodo, $url, $headers, $corpo, $timeout);
    }
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    if ($corpo !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $corpo);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false) return null;
    return ['status' => $status, 'body' => (string) $body];
}

/* ['mime' => 'audio/ogg', 'dados' => bytes] ou null. Duas idas a Meta: o
   metadado (url, tipo, tamanho) e os bytes, as duas com o token. */
function wa_ia_baixa_midia($media_id, $token) {
    if ($media_id === '' || $token === '') return null;
    $auth = ['Authorization: Bearer ' . $token];
    $r = wa_ia_http('GET', WA_GRAPH . rawurlencode($media_id), $auth, null, 8);
    if (!$r || $r['status'] !== 200) return null;
    $meta = json_decode($r['body'], true);
    $url = (string) ($meta['url'] ?? '');
    $mime = trim(explode(';', (string) ($meta['mime_type'] ?? ''))[0]);
    if ($url === '' || $mime === '') return null;
    if ((int) ($meta['file_size'] ?? 0) > WA_IA_MIDIA_MAX) return null;
    $b = wa_ia_http('GET', $url, $auth, null, 15);
    if (!$b || $b['status'] !== 200 || $b['body'] === '' || strlen($b['body']) > WA_IA_MIDIA_MAX) return null;
    return ['mime' => $mime, 'dados' => $b['body']];
}

/* ['lead' => bool, 'confianca' => float, 'motivo' => string] ou null. */
function wa_ia_lead($ev, $cfg = null) {
    $cfg = $cfg ?? wa_config();
    $chave = (string) ($cfg['GEMINI_API_KEY'] ?? '');
    if ($chave === '') return null;

    $tipo = (string) ($ev['tipo_msg'] ?? 'text');
    $texto = trim((string) ($ev['texto'] ?? '') . ' ' . (string) ($ev['legenda'] ?? ''));
    $parts = [['text' => 'Tipo da mensagem: ' . $tipo . '. Texto: ' . ($texto === '' ? '(nenhum)' : $texto)]];

    if (in_array($tipo, WA_IA_MIDIAS, true)) {
        $midia = wa_ia_baixa_midia((string) ($ev['media_id'] ?? ''), (string) ($cfg['WA_TOKEN'] ?? ''));
        if (!$midia) return null;
        $parts[] = ['inlineData' => ['mimeType' => $midia['mime'], 'data' => base64_encode($midia['dados'])]];
    }

    $corpo = [
        'systemInstruction' => ['parts' => [['text' => WA_IA_INSTRUCAO]]],
        'contents'          => [['parts' => $parts]],
        'generationConfig'  => [
            'responseMimeType' => 'application/json',
            'temperature'      => 0,
            'responseSchema'   => [
                'type'       => 'OBJECT',
                'properties' => ['lead' => ['type' => 'BOOLEAN'], 'confianca' => ['type' => 'NUMBER'], 'motivo' => ['type' => 'STRING'],
                                 'transcricao' => ['type' => 'STRING']],
                'required'   => ['lead', 'confianca', 'motivo', 'transcricao'],
            ],
        ],
    ];
    $r = wa_ia_http('POST',
        'https://generativelanguage.googleapis.com/v1beta/models/' . WA_IA_MODELO . ':generateContent',
        ['Content-Type: application/json', 'x-goog-api-key: ' . $chave],
        json_encode($corpo, JSON_UNESCAPED_UNICODE), 15);
    if (!$r || $r['status'] !== 200) {
        error_log('wa_ia_lead: Gemini ' . ($r ? $r['status'] : 'sem rede'));
        return null;
    }
    $j = json_decode($r['body'], true);
    $v = json_decode((string) ($j['candidates'][0]['content']['parts'][0]['text'] ?? ''), true);
    if (!is_array($v) || !is_bool($v['lead'] ?? null) || !is_numeric($v['confianca'] ?? null)) return null;
    $out = ['lead' => $v['lead'], 'confianca' => (float) $v['confianca'], 'motivo' => (string) ($v['motivo'] ?? ''),
            'transcricao' => mb_substr(trim((string) ($v['transcricao'] ?? '')), 0, 300)];
    // Sem o conteudo da mensagem no log: so o veredito, para auditar a regua.
    error_log('wa_ia_lead: ' . $tipo . ' lead=' . ($out['lead'] ? 'sim' : 'nao') . ' conf=' . $out['confianca'] . ' | ' . $out['motivo']);
    return $out;
}

/* A regua: true so com lead confiante, false para o resto, null sem veredito. */
function wa_ia_aceita($v) {
    if (!is_array($v)) return null;
    return $v['lead'] === true && $v['confianca'] >= WA_IA_CONFIANCA;
}

/* A transcricao da midia que a IA acabou de ouvir, para os atalhos do card
   inicial ("quero o roteiro da Grecia" falado pula para a Grecia, como se
   tivesse sido escrito). Vale so para a MESMA mensagem (wamid), so para midia
   e so quando a IA aceitou como lead. Quem grava o veredito e wa_parece_lead. */
function wa_ia_transcricao($ev) {
    $x = $GLOBALS['WA_IA_VEREDITO'] ?? null;
    $wamid = (string) ($ev['wamid'] ?? '');
    if (!is_array($x) || $wamid === '' || ($x['wamid'] ?? '') !== $wamid) return '';
    if (!in_array($ev['tipo_msg'] ?? '', WA_IA_MIDIAS, true)) return '';
    if (wa_ia_aceita($x['v'] ?? null) !== true) return '';
    return trim((string) ($x['v']['transcricao'] ?? ''));
}
