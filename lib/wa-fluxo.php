<?php
/* ============================================================
   O fluxo de conversa do robo: um grafo de cards guardado no banco,
   percorrido por um interpretador. Este arquivo cresce por secoes:
   listas fechadas e variaveis (aqui), depois roteamento, validacao,
   versionamento e execucao.

   Direcao das dependencias, e e uma so:
     wa-db / wa-send / po-data  <-  wa-fluxo  <-  wa-motor  <-  wa-timeout
   Este arquivo NUNCA faz require de wa-motor.php: o caminho inverso
   fecharia um ciclo. E por isso que as quatro funcoes puras que o motor
   e o interpretador dividem (wa_limpa_pontuacao, wa_registra_saida,
   wa_msg_do_robo, wa_acha_roteiro) moram aqui e nao la.

   POR QUE `cliente` E `revisado` NAO ESTAO NA WHITELIST DO CARD `marca`:
   sao as duas colunas que decidem quem recebe disparo pago. Um fluxo
   desenhado no painel nunca pode se autorizar a entrar numa campanha.
   Pelo mesmo motivo `venda`, `venda_at` e `notas` ficam de fora: escreve-las
   apagaria faturamento e ROAS em silencio. Nao acrescente campo aqui
   "so para ficar completo".
============================================================ */

require_once __DIR__ . '/po-data.php';
require_once __DIR__ . '/wa-db.php';
require_once __DIR__ . '/wa-send.php';

/* ---------- dependencias injetaveis ----------
   Usa o MESMO repositorio de dependencias do motor ($GLOBALS['WA_DEPS']).
   Assim um teste que injeta o banco por wa_motor_set_deps tambem vale para
   as funcoes que moram aqui, sem dois mundos que divergem. */
function wa_fluxo_set_deps($d) {
    $GLOBALS['WA_DEPS'] = array_merge($GLOBALS['WA_DEPS'] ?? [], $d);
}
function wa_fl_dep($nome) {
    if (isset($GLOBALS['WA_DEPS'][$nome])) return $GLOBALS['WA_DEPS'][$nome];
    $padrao = [
        'select'       => 'wa_db_select',
        'insert'       => 'wa_db_insert',
        'update'       => 'wa_db_update',
        'roteiros'     => 'po_fetch_roteiros',
        'send_text'    => 'wa_send_text',
        'send_buttons' => 'wa_send_buttons',
        'send_list'    => 'wa_send_list',
        'send_doc'     => 'wa_send_document',
    ];
    return $padrao[$nome];
}
function wa_fl_call($nome, ...$args) { return call_user_func_array(wa_fl_dep($nome), $args); }

/* ---------- listas fechadas ---------- */
const WA_CARD_TIPOS    = ['texto','botoes','lista','pdf','marca','ir_para_fluxo','humano','fim'];
const WA_CARD_ESPERA   = ['botoes','lista'];          // cards que param e esperam o cliente
const WA_CARD_TERMINAL = ['humano','fim'];            // cards sem saida
// Destino de "sem resposta": so card que nao espera, senao o cron de timeout
// agendaria uma espera dentro de outra.
const WA_SEM_RESPOSTA_TIPOS = ['texto','marca','humano','fim'];

/* ---------- variaveis de texto ---------- */
const WA_FLUXO_VARS         = ['nome','roteiro','data'];
const WA_FLUXO_VARS_ROTEIRO = ['roteiro','data'];     // as que dependem de haver roteiro no contexto
// Maximo de caracteres de cada variavel. Vale nos dois lados: o valor real e
// cortado nele, e a validacao conta o texto pelo pior caso com ele.
const WA_FLUXO_VAR_MAX      = ['nome' => 40, 'roteiro' => 60, 'data' => 40];

/* Limite de caracteres por tipo de card, contado na expansao de pior caso.
   Corpo interativo e legenda de documento: 1024. Texto simples: 4096. */
const WA_FLUXO_LIMITE = [
    'texto' => 4096, 'botoes' => 1024, 'lista' => 1024,
    'pdf' => 1024, 'humano' => 4096, 'fim' => 4096,
];

/* ---------- card `marca`: a whitelist ---------- */
const WA_FLUXO_CAMPOS_MARCAVEIS = ['status','qualif_data','qualif_grupo','qualif_at','interesses','espera'];
// `venda` e `negociacao` ficam de fora: venda e decisao humana, e negociacao
// e o que o atalho #proposta da dona carimba.
const WA_FLUXO_STATUS_PERMITIDOS = ['novo','atendimento','perdido'];

/* O {data} do roteiro. `data_label ?? 'na data prevista'` (o que o motor
   fazia) so pega null, nao string vazia, e nenhum roteiro do banco tem
   data_label preenchido (todos usam `periodo`): a variavel chegava vazia ao
   cliente. `?:` pega vazio, e o periodo entra como segunda opcao. */
function wa_fluxo_vars_do_roteiro($r, $nome) {
    $data = '';
    if (is_array($r)) {
        $data = (string) ($r['data_label'] ?? '') ?: (string) ($r['periodo'] ?? '') ?: 'na data prevista';
    }
    $vars = [
        'nome'    => (string) $nome,
        'roteiro' => is_array($r) ? (string) ($r['titulo'] ?? '') : '',
        'data'    => $data,
    ];
    foreach (WA_FLUXO_VAR_MAX as $k => $max) $vars[$k] = mb_substr($vars[$k], 0, $max, 'UTF-8');
    return $vars;
}

/* Substitui {nome}, {roteiro} e {data}. Qualquer outro {buraco} e REMOVIDO:
   texto montado no painel nao pode vazar chave crua para o cliente. */
function wa_fluxo_texto($texto, $vars) {
    foreach (WA_FLUXO_VARS as $k) $texto = str_replace('{' . $k . '}', (string) ($vars[$k] ?? ''), $texto);
    $texto = preg_replace('/\{[a-z_]+\}/', '', $texto);
    return wa_limpa_pontuacao($texto);
}

/* Tamanho do texto com cada variavel valendo o seu maximo. Conferir o texto
   cru deixaria passar uma mensagem que estoura o limite da Cloud API quando
   o nome e o roteiro sao longos. Conta caracteres, nunca bytes. */
function wa_fluxo_tam_pior_caso($texto) {
    $total = 0;
    $pedacos = preg_split('/(\{[a-z_]+\})/', (string) $texto, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
    foreach ($pedacos as $p) {
        if (preg_match('/^\{([a-z_]+)\}$/', $p, $m)) {
            $total += WA_FLUXO_VAR_MAX[$m[1]] ?? 0;   // desconhecida e removida no envio
        } else {
            $total += mb_strlen($p, 'UTF-8');
        }
    }
    return $total;
}

/* O que o card `marca` pode gravar. Leva EXATAMENTE as chaves da whitelist
   que vieram na config, nada mais. `interesses` e `espera` sao ACRESCIMO:
   '+roteiro' (ou '+destino') significa "acrescenta o slug do contexto", e a
   funcao devolve so a lista do que acrescentar. Quem junta com o que ja esta
   na coluna e o executor. */
function wa_fluxo_campos_marca($config, $ctx) {
    $campos = [];
    if (isset($config['status']) && in_array($config['status'], WA_FLUXO_STATUS_PERMITIDOS, true)) {
        $campos['status'] = $config['status'];
    }
    foreach (['qualif_data', 'qualif_grupo'] as $c) {
        if (isset($config[$c]) && is_bool($config[$c])) $campos[$c] = $config[$c];
    }
    if (!empty($config['qualif_at'])) $campos['qualif_at'] = gmdate('c');

    $slug = (string) ($ctx['roteiro_slug'] ?? '');
    foreach (['interesses', 'espera'] as $c) {
        if (in_array($config[$c] ?? null, ['+roteiro', '+destino'], true) && $slug !== '') {
            $campos[$c] = [$slug];
        }
    }
    return $campos;
}

/* ---------- funcoes puras compartilhadas com o motor ---------- */

/* Rede de seguranca contra placeholder vazio (ex.: {nome} quando quem
   chama nao tem o nome do lead a mao): sem isto sobra pontuacao orfa no
   texto que vai pro cliente, tipo "Oi , tudo bem?" em vez de "Oi, tudo
   bem?". Nao existe pra "consertar" a falta do dado, so pra nenhum texto
   sair visivelmente quebrado quando isso acontece (revisao: Important 2). */
function wa_limpa_pontuacao($t) {
    $t = preg_replace('/ {2,}/', ' ', $t);           // espaco duplo
    $t = preg_replace('/\s+([,.!?;:])/', '$1', $t);  // espaco antes de pontuacao
    $t = preg_replace('/([,.!?;:])\1+/', '$1', $t);  // pontuacao duplicada
    /* Virgula orfa ANTES do placeholder. A regra acima so junta pontuacao
       IGUAL, entao "Perfeito, {nome}." com nome vazio virava "Perfeito,." e
       saia assim no WhatsApp do cliente. So a forma "Oi {nome}," escapava, o
       que obrigava todo texto da tela a comecar por saudacao. Vale tambem
       para os textos que estao no banco desde o seed. */
    $t = preg_replace('/[,;:]\s*([.!?])/', '$1', $t);
    return trim($t);
}

/* ---------- log do que o robo manda ----------
   Duas coisas de uma vez:

   1) po_wa_mensagens vira o log completo que a spec promete (secao 4) e
      que a aba de conversa do lead vai ler. Antes so entrava mensagem do
      cliente e eco, entao a aba nasceria mostrando as respostas sem as
      perguntas.

   2) E o unico jeito de reconhecer o proprio envio quando ele volta como
      eco. Nada no payload da Meta distingue "a dona digitou no celular" de
      "nos enviamos pela Cloud API": se a configuracao de coexistencia
      ecoar tambem o que sai pela API, o primeiro PDF que o robo mandar
      volta como eco, vira handoff e silencia o robo naquele contato para
      sempre - em toda conversa, e o sintoma pareceria "o robo so responde
      uma vez".

   Envio que falhou nao entra: sem wamid nao ha o que reconhecer depois. */
function wa_registra_saida($wa_id, $envio, $tipo, $texto) {
    if (empty($envio['ok']) || empty($envio['wamid'])) return;
    wa_fl_call('insert', 'po_wa_mensagens', [
        'wa_id'   => $wa_id,
        'wamid'   => $envio['wamid'],
        'direcao' => 'out',
        'autor'   => 'robo',
        'tipo'    => $tipo,
        'texto'   => $texto,
        'ts'      => gmdate('c'),
    ]);
}

/* O eco carrega o wamid da mensagem ecoada. Se ele ja esta no log como
   nosso, o evento e o proprio envio voltando, nao a dona falando. */
function wa_msg_do_robo($wamid) {
    if ($wamid === '' || $wamid === null) return false;
    $linhas = wa_fl_call('select', 'po_wa_mensagens', 'wamid=eq.' . rawurlencode($wamid));
    foreach ($linhas as $l) {
        if (($l['wamid'] ?? null) === $wamid) return ($l['autor'] ?? '') === 'robo';
    }
    return false;
}

function wa_acha_roteiro($slug, $roteiros) {
    foreach ($roteiros as $r) if (($r['slug'] ?? '') === $slug) return $r;
    return null;
}
