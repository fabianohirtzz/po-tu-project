<?php
/* ============================================================
   Criacao de modelo de mensagem (template) na Meta.

   Existe por dois motivos que se encontram:

   1. Envio fora da janela de 24h exige template APROVADO. Hoje a cliente
      escreve o texto na aba "Textos do robo" e alguem copia e cola no
      WhatsApp Manager na mao. Entre o texto que ela escreveu e o template
      que foi submetido nao ha nenhuma garantia de que sao o mesmo.

   2. A transmissao do plano 4 manda UM parametro ({{1}} = nome). Template
      aprovado com zero ou com dois parametros faz a Graph API recusar
      TODO destinatario, e `falha` e terminal: a campanha inteira queima
      sem possibilidade de reenvio. A conferencia que o CLAUDE.md mandava
      fazer "antes do primeiro disparo pago" passa a ser codigo.

   Transporte proprio e injetavel: nenhum teste toca a rede. Nao reaproveita
   o wa_http() do wa-send.php porque aquele e POST sempre, e a listagem de
   templates e GET.
============================================================ */

require_once __DIR__ . '/wa-send.php';      // WA_GRAPH, wa_config
require_once __DIR__ . '/wa-roteiro.php';   // wa_normaliza

const WA_TPL_CORPO_MAX  = 1024;   // limite de corpo de template da Cloud API
const WA_TPL_NOME_MAX   = 512;
const WA_TPL_CATEGORIAS = ['MARKETING', 'UTILITY', 'AUTHENTICATION'];

function wa_tpl_set_transport($f) { $GLOBALS['WA_TPL_TRANSPORT'] = $f; }

/* $payload null = GET. */
function wa_tpl_http($url, $payload, $headers) {
    if (isset($GLOBALS['WA_TPL_TRANSPORT']) && $GLOBALS['WA_TPL_TRANSPORT']) {
        return call_user_func($GLOBALS['WA_TPL_TRANSPORT'], $url, $payload, $headers);
    }
    if (!function_exists('curl_init')) {
        error_log('wa_tpl_http sem curl disponivel | ' . $url);
        return null;
    }
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 20,
    ];
    if ($payload !== null) {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = $payload;
    }
    curl_setopt_array($ch, $opts);
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erro   = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        error_log('wa_tpl_http falhou: ' . $erro);
        return null;
    }
    return ['status' => $status, 'body' => $body];
}

/* ------------------------------------------------------------
   Nome do template: a Meta aceita so [a-z0-9_].
------------------------------------------------------------ */
function wa_tpl_nome($titulo) {
    // wa_normaliza devolve minusculas, sem acento, separado por espaco.
    $s = trim(preg_replace('/\s+/', '_', trim(wa_normaliza($titulo))), '_');
    if (strlen($s) > WA_TPL_NOME_MAX) {
        $s = trim(substr($s, 0, WA_TPL_NOME_MAX), '_');
    }
    return $s;
}

/* ------------------------------------------------------------
   As variaveis do corpo, na ordem em que aparecem. So conta o formato que a
   Meta aceita de verdade: {{1}}, sem espaco dentro das chaves.
------------------------------------------------------------ */
function wa_tpl_vars($corpo) {
    preg_match_all('/\{\{(\d+)\}\}/', (string) $corpo, $m);
    return array_map('intval', $m[1]);
}

/* ------------------------------------------------------------
   Tudo que faria a Meta recusar o template, em portugues, para a tela
   mostrar ANTES de submeter. Lista vazia = pode mandar.

   Recusa da Meta chega como erro generico horas depois, por e-mail, sem
   dizer qual regra foi quebrada. Cada item aqui e uma rodada dessas
   economizada.
------------------------------------------------------------ */
function wa_tpl_problemas($corpo, $esperadas = 1) {
    $c = (string) $corpo;
    $t = trim($c);
    if ($t === '') return ['O texto está vazio.'];

    $p = [];

    if (mb_strlen($c, 'UTF-8') > WA_TPL_CORPO_MAX) {
        $p[] = 'O texto tem ' . mb_strlen($c, 'UTF-8') . ' caracteres e o limite é '
             . WA_TPL_CORPO_MAX . '.';
    }
    if ($c !== $t) {
        $p[] = 'O texto começa ou termina com espaço ou quebra de linha, e a Meta recusa.';
    }

    /* Chave malformada: {{ 1 }}, {{nome}}, {1}. A Meta trata isso como texto
       literal e o cliente receberia as chaves na mensagem. */
    preg_match_all('/\{\{[^{}]*\}\}/u', $c, $todas);
    foreach ($todas[0] as $oc) {
        if (!preg_match('/^\{\{\d+\}\}$/', $oc)) {
            $p[] = 'A variável ' . $oc . ' está fora do formato. O certo é {{1}}, sem espaços.';
        }
    }

    $vars  = wa_tpl_vars($c);
    $unica = array_values(array_unique($vars));
    sort($unica);

    if (count($unica) !== (int) $esperadas) {
        /* A regra que o CLAUDE.md mandava conferir na mao antes do primeiro
           disparo pago. Numero errado de parametros derruba a campanha
           INTEIRA, e falha e terminal. */
        $p[] = 'O texto tem ' . count($unica) . ' variável(is) e precisa ter exatamente '
             . (int) $esperadas . '.';
    }
    if ($unica && $unica !== range(1, count($unica))) {
        $p[] = 'As variáveis precisam ser numeradas em sequência a partir de {{1}}.';
    }
    if (preg_match('/^\{\{\d+\}\}/', $t)) {
        $p[] = 'O texto não pode começar com uma variável. Escreva algo antes, como "Oi {{1}}".';
    }
    if (preg_match('/\{\{\d+\}\}$/', $t)) {
        $p[] = 'O texto não pode terminar com uma variável.';
    }
    if (preg_match('/\}\}\s*\{\{/', $c)) {
        $p[] = 'Duas variáveis não podem ficar coladas uma na outra.';
    }
    return $p;
}

/* ------------------------------------------------------------
   Os componentes do template. O exemplo e OBRIGATORIO quando ha variavel: a
   Meta usa ele para revisar e recusa sem ele.
------------------------------------------------------------ */
function wa_tpl_componentes($corpo, $exemplos) {
    $comp = ['type' => 'BODY', 'text' => (string) $corpo];
    $ex = array_values(array_map('strval', (array) $exemplos));
    if ($ex) {
        // body_text e uma lista DE LISTAS: uma lista de exemplos por linha.
        $comp['example'] = ['body_text' => [$ex]];
    }
    return [$comp];
}

function wa_tpl_waba($cfg = null) {
    $cfg = $cfg ?: wa_config();
    return trim((string) ($cfg['WA_WABA_ID'] ?? ''));
}

function wa_tpl_headers($cfg) {
    return [
        'Authorization: Bearer ' . ($cfg['WA_TOKEN'] ?? ''),
        'Content-Type: application/json',
    ];
}

/* ------------------------------------------------------------
   Submete o template para aprovacao.

   A validacao roda AQUI, e nao so em quem chama: assim nao existe caminho
   que submeta sem passar por ela. E a regra permanente do projeto, a trava
   no ponto de chamada, aplicada ao contrario - este e o ponto por onde todo
   mundo passa.
------------------------------------------------------------ */
function wa_tpl_cria($titulo, $categoria, $corpo, $exemplos = [], $idioma = 'pt_BR') {
    $cfg  = wa_config();
    $waba = wa_tpl_waba($cfg);
    if ($waba === '') {
        return ['ok' => false, 'erro' => 'A conta do WhatsApp ainda não está conectada.'];
    }
    if (trim((string) ($cfg['WA_TOKEN'] ?? '')) === '') {
        return ['ok' => false, 'erro' => 'WA_TOKEN não configurado no servidor.'];
    }

    $categoria = strtoupper(trim((string) $categoria));
    if (!in_array($categoria, WA_TPL_CATEGORIAS, true)) {
        return ['ok' => false, 'erro' => 'Categoria inválida: ' . $categoria . '.'];
    }

    $nome = wa_tpl_nome($titulo);
    if ($nome === '') {
        return ['ok' => false, 'erro' => 'O nome do modelo ficou vazio depois de normalizado.'];
    }

    $exemplos  = array_values((array) $exemplos);
    $problemas = wa_tpl_problemas($corpo, count($exemplos));
    if ($problemas) {
        return ['ok' => false, 'erro' => implode(' ', $problemas), 'problemas' => $problemas];
    }

    $payload = [
        'name'       => $nome,
        'language'   => (string) $idioma,
        'category'   => $categoria,
        'components' => wa_tpl_componentes($corpo, $exemplos),
    ];
    $r = wa_tpl_http(WA_GRAPH . $waba . '/message_templates',
                     json_encode($payload, JSON_UNESCAPED_UNICODE),
                     wa_tpl_headers($cfg));
    if (!$r) return ['ok' => false, 'erro' => 'Sem resposta da Meta. Nada foi criado.'];

    $j = json_decode((string) ($r['body'] ?? ''), true);
    if (($r['status'] ?? 0) >= 200 && ($r['status'] ?? 0) < 300 && isset($j['id'])) {
        return [
            'ok'     => true,
            'id'     => (string) $j['id'],
            'nome'   => $nome,
            // Template nasce PENDING; a Meta responde em minutos ou horas.
            'status' => (string) ($j['status'] ?? 'PENDING'),
        ];
    }
    $msg = $j['error']['error_user_msg']
        ?? $j['error']['message']
        ?? ('A Meta recusou (HTTP ' . ($r['status'] ?? 0) . ').');
    return ['ok' => false, 'erro' => (string) $msg];
}

/* ------------------------------------------------------------
   Os templates que existem na conta, com o status de cada um. E como a tela
   mostra "aprovado" sem ninguem abrir o WhatsApp Manager.
------------------------------------------------------------ */
function wa_tpl_lista($limite = 50) {
    $cfg  = wa_config();
    $waba = wa_tpl_waba($cfg);
    if ($waba === '') return null;

    $url = WA_GRAPH . $waba . '/message_templates'
         . '?fields=name,status,category,language,components&limit=' . (int) $limite;
    $r = wa_tpl_http($url, null, wa_tpl_headers($cfg));
    if (!$r || ($r['status'] ?? 0) < 200 || ($r['status'] ?? 0) >= 300) return null;

    $j = json_decode((string) ($r['body'] ?? ''), true);
    // Lista vazia e resposta legitima; erro de leitura e null. Confundir os
    // dois faria a tela dizer "nenhum template" quando a Meta esta fora.
    if (!is_array($j) || !isset($j['data']) || !is_array($j['data'])) return null;
    return $j['data'];
}
