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
    // Unica tabela de defaults: wa_dep (motor) delega para ca.
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
    foreach (WA_FLUXO_VAR_MAX as $k => $max) $vars[$k] = wa_fluxo_corta($vars[$k], $max);
    return $vars;
}

/* Corta o valor de uma variavel no teto, em limite de palavra, com reticencia
   so quando cortou de fato. Corte seco seria pior que feio: um periodo
   cortado no meio ("27/10/2027 a 12/1") afirma uma data de volta que nao
   existe. Sai com no maximo $max caracteres (o '…' conta um), entao o pior
   caso de wa_fluxo_tam_pior_caso continua valendo. Idempotente. */
function wa_fluxo_corta($v, $max) {
    $v = (string) $v;
    if (mb_strlen($v, 'UTF-8') <= $max) return $v;
    $corte = mb_substr($v, 0, $max - 1, 'UTF-8');
    // Recua ate o ultimo espaco, mas so se ele estiver razoavelmente perto
    // do fim; senao uma palavra gigante comeria quase o valor inteiro.
    $pos = mb_strrpos($corte, ' ', 0, 'UTF-8');
    if ($pos !== false && $pos >= (int) ($max / 2)) $corte = mb_substr($corte, 0, $pos, 'UTF-8');
    return rtrim($corte) . '…';
}

/* Substitui {nome}, {roteiro} e {data}. Qualquer outro {buraco} e REMOVIDO:
   texto montado no painel nao pode vazar chave crua para o cliente. */
function wa_fluxo_texto($texto, $vars) {
    // O teto e aplicado AQUI, no ponto de uso, e nao so em quem monta $vars:
    // um chamador que monte o array a mao renderia mais que o orcamento que
    // a validacao aprovou, e a Meta recusaria a mensagem inteira em silencio.
    foreach (WA_FLUXO_VARS as $k) {
        $texto = str_replace('{' . $k . '}', wa_fluxo_corta($vars[$k] ?? '', WA_FLUXO_VAR_MAX[$k]), $texto);
    }
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

/* ---------- roteamento puro ----------
   Dada a resposta do cliente, qual card vem agora. Nenhuma rede, nenhum
   banco, nenhum relogio: grafo, resposta e contexto entram, o proximo card
   sai. Quem envia e quem grava e a execucao, nao esta secao.

   Teto de respostas fora do esperado (texto, audio, botao antigo) antes de
   o robo desistir e chamar a dona. Com 2, a terceira vai para o humano. */
const WA_FLUXO_MAX_TENTATIVAS = 2;

/* Retorna ['card' => ?string, 'ctx' => array, 'motivo' => string].
   motivo: opcao | escolheu | fallback | socorro | invalido | ir_para_fluxo
   mais dois que nomeiam os "sem proximo" que, sem nome, seriam um null mudo:
   proximo (card que nao espera) e terminal (humano/fim).
   `ir_para_fluxo` so se anuncia (card null): o destino esta em
   config.fluxo e quem carrega o outro grafo e o executor. */
function wa_fluxo_proximo($grafo, $card_id, $resposta, $ctx) {
    $card = $grafo['cards'][$card_id] ?? null;
    if (!is_array($card) || !in_array($card['tipo'] ?? null, WA_CARD_TIPOS, true)) {
        return ['card' => null, 'ctx' => $ctx, 'motivo' => 'invalido'];
    }
    $tipo = $card['tipo'];
    if (in_array($tipo, WA_CARD_TERMINAL, true)) {
        return ['card' => null, 'ctx' => $ctx, 'motivo' => 'terminal'];
    }
    if ($tipo === 'ir_para_fluxo') {
        return ['card' => null, 'ctx' => $ctx, 'motivo' => 'ir_para_fluxo'];
    }

    $existe = function ($id) use ($grafo) {
        return is_string($id) && $id !== '' && isset($grafo['cards'][$id]);
    };

    // Card que nao espera nunca olha a resposta.
    if (!in_array($tipo, WA_CARD_ESPERA, true)) {
        if ($existe($card['proximo'] ?? null)) {
            return ['card' => $card['proximo'], 'ctx' => $ctx, 'motivo' => 'proximo'];
        }
        return ['card' => null, 'ctx' => $ctx, 'motivo' => 'invalido'];
    }

    $resposta = (string) $resposta;
    $certo = ['roteiro_slug' => $ctx['roteiro_slug'] ?? null, 'tentativas' => 0];

    // Lista dinamica: a resposta e o slug e o destino e um so (proximo). Esta
    // funcao e pura e nao sabe quais roteiros existem; conferir o slug contra
    // o catalogo e da execucao. Resposta vazia nao e escolha.
    if ($tipo === 'lista' && ($card['config']['fonte'] ?? null) === 'roteiros') {
        if ($resposta !== '' && $existe($card['proximo'] ?? null)) {
            $certo['roteiro_slug'] = $resposta;
            return ['card' => $card['proximo'], 'ctx' => $certo, 'motivo' => 'escolheu'];
        }
    } else {
        // So as opcoes DESTE card: o id de um botao de mensagem antiga existe
        // no grafo, mas nao aqui, e tem que cair no fallback.
        foreach ($card['opcoes'] ?? [] as $o) {
            if ($resposta !== '' && (string) ($o['id'] ?? '') === $resposta && $existe($o['destino'] ?? null)) {
                return ['card' => $o['destino'], 'ctx' => $certo, 'motivo' => 'opcao'];
            }
        }
    }

    $tentativas = (int) ($ctx['tentativas'] ?? 0);
    if ($existe($card['fallback'] ?? null) && $tentativas < WA_FLUXO_MAX_TENTATIVAS) {
        $ctx['tentativas'] = $tentativas + 1;
        return ['card' => $card['fallback'], 'ctx' => $ctx, 'motivo' => 'fallback'];
    }

    // Socorro: sem fallback ou no teto. Zera o contador.
    $ctx['tentativas'] = 0;
    $humano = $grafo['humano'] ?? null;
    if ($existe($humano)) {
        return ['card' => $humano, 'ctx' => $ctx, 'motivo' => 'socorro'];
    }
    return ['card' => null, 'ctx' => $ctx, 'motivo' => 'invalido'];
}

/* Destino do "sem resposta" do card, ou null. */
function wa_fluxo_sem_resposta($grafo, $card_id) {
    $d = $grafo['cards'][$card_id]['sem_resposta'] ?? null;
    return is_string($d) && $d !== '' ? $d : null;
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
