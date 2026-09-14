<?php
/* ============================================================
   Envio pela Cloud API (Graph v21).

   O transporte e injetavel de proposito: e o que permite construir e
   testar o motor inteiro contra um simulador, antes de a conta da Meta
   estar verificada. Quando a verificacao sair, so o transporte muda.

   Os segredos (token, phone id) vem de wa_config() (lib/wa-config.php),
   nao de po_config(): aquele arquivo e carregado no render publico das
   paginas de roteiro e o cabecalho dele proibe segredo de aparecer la.
============================================================ */

require_once __DIR__ . '/wa-config.php';
require_once __DIR__ . '/wa-fone.php';

const WA_GRAPH = 'https://graph.facebook.com/v21.0/';

function wa_set_transport($f) { $GLOBALS['WA_TRANSPORT'] = $f; }

function wa_http($url, $payload, $headers) {
    if (isset($GLOBALS['WA_TRANSPORT']) && $GLOBALS['WA_TRANSPORT']) {
        return call_user_func($GLOBALS['WA_TRANSPORT'], $url, $payload, $headers);
    }
    // Sem curl no servidor isto seria fatal, e quem chama pode ser o
    // webhook: um fatal derruba a resposta pra Meta, ela reenvia em loop e
    // acaba desinscrevendo o webhook. Mesmo contorno da rede fora do ar: null.
    if (!function_exists('curl_init')) {
        error_log('wa_http sem curl disponivel | ' . $url);
        return null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erro   = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        error_log('wa_http falhou: ' . $erro);
        return null;
    }
    return ['status' => $status, 'body' => $body];
}

function wa_envia($mensagem) {
    $cfg = wa_config();
    $url = WA_GRAPH . ($cfg['WA_PHONE_ID'] ?? '') . '/messages';
    $h   = [
        'Authorization: Bearer ' . ($cfg['WA_TOKEN'] ?? ''),
        'Content-Type: application/json',
    ];
    $r = wa_http($url, json_encode($mensagem, JSON_UNESCAPED_UNICODE), $h);
    if (!$r) return ['ok' => false, 'wamid' => null, 'erro' => 'sem resposta da Graph API'];

    $j = json_decode($r['body'], true);

    // Corpo que nao decodifica para array: nao da pra confiar que a
    // mensagem saiu so porque o status HTTP veio < 300. Sem esta checagem
    // um 200 com corpo quebrado virava sucesso silencioso.
    if (!is_array($j)) {
        error_log('wa_envia resposta malformada (status ' . $r['status'] . '): ' . $r['body']);
        return ['ok' => false, 'wamid' => null, 'erro' => 'resposta malformada da Graph API'];
    }

    // Mensagem de erro da Meta, nao dado de cliente: pode logar o corpo.
    // A Meta pode devolver erro no corpo com status 200 (ex: token
    // expirado); por isso isto e checado independente do status HTTP.
    if (isset($j['error']['message'])) {
        error_log('wa_envia erro: ' . $r['body']);
        return ['ok' => false, 'wamid' => null, 'erro' => $j['error']['message']];
    }

    if ($r['status'] >= 300) {
        error_log('wa_envia erro: ' . $r['body']);
        return ['ok' => false, 'wamid' => null, 'erro' => 'HTTP ' . $r['status']];
    }

    // Sucesso exige wamid: sem identificador de mensagem o envio nao pode
    // ser dado como feito, senao o motor avanca o estado da conversa como
    // se o cliente tivesse recebido algo que nao saiu.
    $wamid = $j['messages'][0]['id'] ?? null;
    if (!$wamid) {
        error_log('wa_envia resposta sem wamid: ' . $r['body']);
        return ['ok' => false, 'wamid' => null, 'erro' => 'resposta sem wamid'];
    }

    return ['ok' => true, 'wamid' => $wamid, 'erro' => null];
}

/* A Graph API quer o numero so em digitos, sem o "+". Passar com o mais
   devolve 400 e a mensagem nao sai. */
function wa_destino($para) {
    $e = wa_e164($para);
    return $e ? ltrim($e, '+') : null;
}

function wa_send_text($para, $texto) {
    $to = wa_destino($para);
    if (!$to) return ['ok' => false, 'wamid' => null, 'erro' => 'telefone invalido: ' . $para];
    return wa_envia([
        'messaging_product' => 'whatsapp',
        'to'                => $to,
        'type'              => 'text',
        'text'              => ['body' => $texto, 'preview_url' => true],
    ]);
}

function wa_send_document($para, $url, $arquivo, $legenda = '') {
    $to = wa_destino($para);
    if (!$to) return ['ok' => false, 'wamid' => null, 'erro' => 'telefone invalido: ' . $para];
    return wa_envia([
        'messaging_product' => 'whatsapp',
        'to'                => $to,
        'type'              => 'document',
        'document'          => ['link' => $url, 'filename' => $arquivo, 'caption' => $legenda],
    ]);
}

/* Menu de lista. O WhatsApp impoe: no maximo 10 linhas, titulo de linha
   com ate 24 caracteres e descricao com ate 72. Estourar qualquer um
   devolve 400 e a mensagem inteira nao sai, entao cortamos aqui. */
function wa_send_list($para, $corpo, $botao, $itens) {
    $to = wa_destino($para);
    if (!$to) return ['ok' => false, 'wamid' => null, 'erro' => 'telefone invalido: ' . $para];

    $rows = [];
    foreach (array_slice($itens, 0, 10) as $i) {
        $row = [
            'id'    => substr($i['id'], 0, 200),
            'title' => mb_substr($i['titulo'], 0, 24, 'UTF-8'),
        ];
        if (!empty($i['descricao'])) {
            $row['description'] = mb_substr($i['descricao'], 0, 72, 'UTF-8');
        }
        $rows[] = $row;
    }

    return wa_envia([
        'messaging_product' => 'whatsapp',
        'to'                => $to,
        'type'              => 'interactive',
        'interactive'       => [
            'type'   => 'list',
            'body'   => ['text' => mb_substr($corpo, 0, 1024, 'UTF-8')],
            'action' => [
                'button'   => mb_substr($botao, 0, 20, 'UTF-8'),
                'sections' => [['title' => 'Roteiros', 'rows' => $rows]],
            ],
        ],
    ]);
}

/* Envio por TEMPLATE. Fora da janela de 24h a Cloud API nao aceita outra
   coisa, e a transmissao por definicao alcanca quem nao escreveu hoje.

   O corpo do texto NAO vem daqui: ele vive aprovado na Meta. O que mandamos
   sao os parametros posicionais, na ordem em que {{1}}, {{2}} aparecem no
   template aprovado. Ordem trocada aqui manda o nome do roteiro no lugar do
   nome da pessoa, e o cliente recebe "Ola Mercados de Natal".

   Sem parametros, o bloco `components` e OMITIDO: mandar components vazio
   faz a Graph devolver 132000 ("number of parameters does not match") e a
   mensagem inteira nao sai. */
function wa_send_template($para, $template, $params = [], $idioma = 'pt_BR') {
    $template = trim((string) $template);
    if ($template === '') {
        // Nome vazio viraria 400 na Meta e um destinatario sem nada, em
        // silencio. Recusa aqui, antes de gastar a chamada.
        error_log('wa_send_template: nome de template vazio, nada enviado');
        return ['ok' => false, 'wamid' => null, 'erro' => 'template sem nome'];
    }
    $msg = [
        'messaging_product' => 'whatsapp',
        'to'                => wa_destino($para),
        'type'              => 'template',
        'template'          => [
            'name'     => $template,
            'language' => ['code' => $idioma],
        ],
    ];
    if ($params) {
        $msg['template']['components'] = [[
            'type'       => 'body',
            'parameters' => array_map(
                function ($v) { return ['type' => 'text', 'text' => (string) $v]; },
                array_values($params)          // array_values: chave nomeada viraria objeto no JSON
            ),
        ]];
    }
    return wa_envia($msg);
}
