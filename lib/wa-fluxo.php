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
require_once __DIR__ . '/wa-roteiro.php';   // wa_normaliza / wa_mesma_palavra, para a porta `palavra`

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
        'select_estrito' => 'wa_db_select_estrito',   // null em erro, [] so quando o banco respondeu vazio
        'insert'      => 'wa_db_insert',
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
    // Mesma guarda do roteamento: destino que nao existe no grafo mandaria a
    // conversa para um card fantasma, e ela pararia sem ninguem ver.
    return is_string($d) && $d !== '' && isset($grafo['cards'][$d]) ? $d : null;
}

/* ---------- validacao pura ----------
   A rede que protege o cliente final: a cliente monta o fluxo numa tela, sem
   programar, e e esta funcao que impede um beco sem saida de ir ao ar. Nenhuma
   rede, nenhum banco, nenhum relogio. Quem chama: o endpoint do painel e o
   publicar (que valida de novo por dentro, para nao existir caminho sem ela).
   Nao acrescente regra aqui sem decisao: a semente do fluxo e validada contra
   exatamente estas 14 que bloqueiam (1 a 14) e 2 que avisam (15 e 16). */

/* Monta o retrato do fluxo no formato que o roteamento consome, a partir das
   linhas de edicao (fluxo + cards). Pura. */
function wa_fluxo_grafo_de($fluxo, $cards) {
    $lista = function ($v) {
        if (is_string($v)) $v = json_decode($v, true);
        return is_array($v) ? $v : [];
    };
    $mapa = [];
    $humano = null;
    foreach ($cards as $c) {
        $id = (string) ($c['id'] ?? '');
        if ($id === '') continue;
        $opcoes = [];
        foreach ($lista($c['opcoes'] ?? []) as $o) {
            $opcoes[] = [
                'id'      => (string) ($o['id'] ?? ''),
                'rotulo'  => (string) ($o['rotulo'] ?? ''),
                'destino' => ($o['destino'] ?? '') !== '' ? (string) $o['destino'] : null,
            ] + (isset($o['descricao']) ? ['descricao' => (string) $o['descricao']] : []);
        }
        $mapa[$id] = [
            'id'             => $id,
            'tipo'           => (string) ($c['tipo'] ?? ''),
            'titulo'         => (string) ($c['titulo'] ?? ''),
            'texto'          => isset($c['texto']) ? (string) $c['texto'] : null,
            'opcoes'         => $opcoes,
            'config'         => $lista($c['config'] ?? []),
            'proximo'        => ($c['proximo'] ?? '') !== '' ? (string) $c['proximo'] : null,
            'fallback'       => ($c['fallback'] ?? '') !== '' ? (string) $c['fallback'] : null,
            'sem_resposta'   => ($c['sem_resposta'] ?? '') !== '' ? (string) $c['sem_resposta'] : null,
            'lembrete_ativo' => !empty($c['lembrete_ativo']),
            'lembrete_texto' => isset($c['lembrete_texto']) ? (string) $c['lembrete_texto'] : null,
        ];
        if ($humano === null && $mapa[$id]['tipo'] === 'humano') $humano = $id;
    }
    return [
        'id'          => (string) ($fluxo['id'] ?? ''),
        'nome'        => (string) ($fluxo['nome'] ?? ''),
        'inicial'     => ($fluxo['card_inicial'] ?? '') !== '' ? (string) $fluxo['card_inicial'] : '',
        'humano'      => $humano,
        'porta'       => (string) ($fluxo['gatilho'] ?? ''),
        'porta_valor' => ($fluxo['gatilho_valor'] ?? '') !== '' ? (string) $fluxo['gatilho_valor'] : null,
        'cards'       => $mapa,
    ];
}

/* Devolve a lista de ['regra','card','texto','bloqueia']. As mensagens sao
   lidas pela cliente na tela do painel: dizem o que esta errado e onde. */
function wa_fluxo_valida($fluxo, $cards, $outros = []) {
    $g = wa_fluxo_grafo_de($fluxo, $cards);
    $C = $g['cards'];
    $probs = [];
    $acha = function ($regra, $card, $texto, $bloqueia = true) use (&$probs) {
        $probs[] = ['regra' => $regra, 'card' => $card, 'texto' => $texto, 'bloqueia' => $bloqueia];
    };
    $nome = function ($id) use ($C) {
        $t = trim((string) ($C[$id]['titulo'] ?? ''));
        return '"' . ($t !== '' ? $t : $id) . '"';
    };
    $existe = function ($id) use ($C) { return is_string($id) && $id !== '' && isset($C[$id]); };
    $espera = function ($c) { return in_array($c['tipo'], WA_CARD_ESPERA, true); };
    $listaDin = function ($c) { return $c['tipo'] === 'lista' && ($c['config']['fonte'] ?? null) === 'roteiros'; };

    // Saidas de um card. $com_sr inclui o "sem resposta" (so o cron o segue).
    $saidas = function ($c, $com_sr = true) use ($existe, $listaDin) {
        $ids = [];
        if (!$listaDin($c)) foreach ($c['opcoes'] as $o) $ids[] = $o['destino'];
        $ids[] = $c['proximo'];
        $ids[] = $c['fallback'];
        if ($com_sr) $ids[] = $c['sem_resposta'];
        return array_values(array_unique(array_filter($ids, $existe)));
    };

    $inicial_ok = $existe($g['inicial']);

    // 1. Saida para card que nao existe: a conversa pararia sem ninguem ver.
    if (!$inicial_ok) {
        $acha(1, null, 'O card em que o fluxo começa não existe. Escolha por onde o fluxo começa.');
    }
    foreach ($C as $id => $c) {
        if (!$listaDin($c)) foreach ($c['opcoes'] as $o) {
            if (!$existe($o['destino'])) {
                $acha(1, $id, 'No card ' . $nome($id) . ', o botão "' . $o['rotulo'] . '" não leva a nenhum card que exista.');
            }
        }
        foreach (['proximo' => 'o card seguinte', 'fallback' => 'o card de "não entendi"', 'sem_resposta' => 'o card de "sem resposta"'] as $k => $rot) {
            if ($c[$k] !== null && !$existe($c[$k])) {
                $acha(1, $id, 'No card ' . $nome($id) . ', ' . $rot . ' aponta para um card que não existe mais.');
            }
        }
    }

    // 2. Espera sem lembrete e sem sem_resposta: o cliente cala e a conversa fica aberta para sempre.
    foreach ($C as $id => $c) {
        if ($espera($c) && !$c['lembrete_ativo'] && $c['sem_resposta'] === null) {
            $acha(2, $id, 'O card ' . $nome($id) . ' espera uma resposta, mas não tem lembrete nem destino para "sem resposta". Se o cliente calar, a conversa fica parada. Ligue o lembrete ou escolha o que acontece sem resposta.');
        }
    }

    // Alcance a partir do card inicial, seguindo todas as saidas.
    $alcanca = [];
    if ($inicial_ok) {
        $fila = [$g['inicial']];
        $alcanca[$g['inicial']] = true;
        while ($fila) {
            $id = array_shift($fila);
            foreach ($saidas($C[$id]) as $d) {
                if (!isset($alcanca[$d])) { $alcanca[$d] = true; $fila[] = $d; }
            }
        }
    }

    // 3. Card inalcancavel: trabalho da cliente que o cliente final nunca vai ver.
    if ($inicial_ok) {
        foreach ($C as $id => $c) {
            if (!isset($alcanca[$id])) {
                $acha(3, $id, 'O card ' . $nome($id) . ' não é alcançado por nenhum caminho a partir do começo do fluxo. Ligue algum card a ele ou apague-o.');
            }
        }
    }

    // 4. Sem saida para uma pessoa: o cliente fica sem ninguem. Fluxo chamado so precisa terminar.
    if ($inicial_ok) {
        $tem_humano = false;
        $tem_term = false;
        foreach ($alcanca as $id => $_) {
            if ($C[$id]['tipo'] === 'humano') $tem_humano = true;
            if (in_array($C[$id]['tipo'], WA_CARD_TERMINAL, true)) $tem_term = true;
        }
        if ($g['porta'] !== 'chamado' && !$tem_humano) {
            $acha(4, null, 'Nenhum caminho deste fluxo leva a um card de atendimento humano ("Falar com a Laís"). Todo fluxo de entrada precisa dar ao cliente a saída para uma pessoa.');
        } elseif ($g['porta'] === 'chamado' && !$tem_term) {
            $acha(4, null, 'Nenhum caminho deste fluxo termina. Inclua um card de fim ou de atendimento humano.');
        }
    }

    // 5. Rotulo que a Meta recusa (vazio, grande, repetido, botoes demais): a mensagem inteira nao sai.
    foreach ($C as $id => $c) {
        if ($c['tipo'] === 'botoes') { $max = 20; $un = 'botão'; }
        elseif ($c['tipo'] === 'lista' && !$listaDin($c)) { $max = 24; $un = 'linha'; }
        else continue;
        $vistos = [];
        if ($c['tipo'] === 'botoes' && count($c['opcoes']) === 0) {
            $acha(5, $id, 'O card ' . $nome($id) . ' é de botões, mas não tem nenhum botão.');
        }
        if ($c['tipo'] === 'botoes' && count($c['opcoes']) > 3) {
            $acha(5, $id, 'O card ' . $nome($id) . ' tem ' . count($c['opcoes']) . ' botões, e o WhatsApp aceita no máximo 3. Use uma lista se precisar de mais opções.');
        }
        foreach ($c['opcoes'] as $o) {
            $r = trim($o['rotulo']);
            $n = mb_strlen($r, 'UTF-8');
            if ($r === '') {
                $acha(5, $id, 'No card ' . $nome($id) . ', há ' . ($un === 'botão' ? 'um botão' : 'uma linha') . ' sem texto.');
            } elseif ($n > $max) {
                $acha(5, $id, 'No card ' . $nome($id) . ', o texto "' . $r . '" tem ' . $n . ' caracteres, e o limite do ' . $un . ' é ' . $max . '.');
            }
            $chave = mb_strtolower($r, 'UTF-8');
            if ($r !== '' && isset($vistos[$chave])) {
                $acha(5, $id, 'No card ' . $nome($id) . ', o texto "' . $r . '" aparece em mais de um ' . $un . '. O cliente não saberia diferenciar.');
            }
            $vistos[$chave] = true;
            if ($c['tipo'] === 'lista' && isset($o['descricao']) && mb_strlen($o['descricao'], 'UTF-8') > 72) {
                $acha(5, $id, 'No card ' . $nome($id) . ', a descrição da linha "' . $r . '" passa de 72 caracteres.');
            }
        }
    }

    // 6. Texto que a Cloud API recusa: contado no pior caso das variaveis, senao estoura depois de trocar o {roteiro}.
    foreach ($C as $id => $c) {
        if (isset(WA_FLUXO_LIMITE[$c['tipo']])) {
            $lim = WA_FLUXO_LIMITE[$c['tipo']];
            // Vazio so bloqueia onde a Cloud API exige corpo. Fim, humano e pdf
            // (legenda) podem ser silenciosos; o tamanho vale para todos.
            if (in_array($c['tipo'], ['texto', 'botoes', 'lista'], true) && trim((string) $c['texto']) === '') {
                $acha(6, $id, 'O card ' . $nome($id) . ' não tem mensagem escrita. O WhatsApp não envia esse tipo de card sem texto.');
            } elseif (($n = wa_fluxo_tam_pior_caso($c['texto'])) > $lim) {
                $acha(6, $id, 'A mensagem do card ' . $nome($id) . ' pode chegar a ' . $n . ' caracteres (contando o maior nome e roteiro possíveis), e o limite é ' . $lim . '. Encurte o texto.');
            }
        }
        // Lembrete ligado sem texto: o envio falha, o lembrete nunca e carimbado e a conversa encerra calada.
        if ($c['lembrete_ativo']) {
            if (trim((string) $c['lembrete_texto']) === '') {
                $acha(6, $id, 'O lembrete do card ' . $nome($id) . ' está ligado, mas não tem texto escrito. Escreva a mensagem do lembrete ou desligue-o.');
            } elseif (($n = wa_fluxo_tam_pior_caso($c['lembrete_texto'])) > 4096) {
                $acha(6, $id, 'O lembrete do card ' . $nome($id) . ' pode chegar a ' . $n . ' caracteres, e o limite é 4096. Encurte o texto.');
            }
        }
    }

    // 7. Lista sem linhas ou sem fonte: o cliente abre um menu vazio.
    foreach ($C as $id => $c) {
        if ($c['tipo'] !== 'lista') continue;
        $fonte = $c['config']['fonte'] ?? null;
        if ($fonte === 'roteiros') {
            if ($c['proximo'] === null) {
                $acha(7, $id, 'A lista ' . $nome($id) . ' mostra os roteiros, mas não diz para qual card ir depois que o cliente escolher um.');
            }
        } elseif ($fonte === 'estatica' || $fonte === null || $fonte === '') {
            $n = count($c['opcoes']);
            if ($n === 0) {
                $acha(7, $id, 'A lista ' . $nome($id) . ' não tem nenhuma linha e não está ligada à lista de roteiros. O cliente abriria um menu vazio.');
            } elseif ($n > 10) {
                $acha(7, $id, 'A lista ' . $nome($id) . ' tem ' . $n . ' linhas, e o WhatsApp aceita no máximo 10.');
            }
        } else {
            $acha(7, $id, 'A lista ' . $nome($id) . ' usa uma fonte de linhas desconhecida.');
        }
    }

    // 8. Beco sem saida: a conversa entra e nunca mais sai (ciclo, ou card sem continuacao).
    //    O "sem resposta" nao conta como saida: so o cron o segue, e o cliente que responde continua preso.
    if ($inicial_ok) {
        $sai = [];
        foreach ($C as $id => $c) {
            if (in_array($c['tipo'], WA_CARD_TERMINAL, true) || $c['tipo'] === 'ir_para_fluxo') $sai[$id] = true;
        }
        do {
            $mudou = false;
            foreach ($C as $id => $c) {
                if (isset($sai[$id])) continue;
                foreach ($saidas($c, false) as $d) {
                    if (isset($sai[$d])) { $sai[$id] = true; $mudou = true; break; }
                }
            }
        } while ($mudou);
        $presos = [];
        foreach ($alcanca as $id => $_) if (!isset($sai[$id])) $presos[] = $id;
        if ($presos) {
            $nomes = implode(', ', array_map($nome, array_slice($presos, 0, 5)));
            $acha(8, $presos[0], count($presos) === 1
                ? 'O card ' . $nome($presos[0]) . ' é um beco sem saída: a conversa chega nele e não consegue ir para lugar nenhum.'
                : 'Estes cards formam um beco sem saída, a conversa entra e não consegue terminar: ' . $nomes . (count($presos) > 5 ? ' e outros' : '') . '. Ligue algum deles a um card de fim ou de atendimento humano.');
        }
    }

    // 9. Duas portas iguais ativas: o cliente cairia em um fluxo ou no outro, ao acaso.
    foreach ($outros as $o) {
        if (($o['id'] ?? null) === $g['id'] || empty($o['ativo'])) continue;
        $ov = ($o['gatilho_valor'] ?? '') !== '' ? (string) $o['gatilho_valor'] : null;
        if (($o['gatilho'] ?? '') === $g['porta'] && $ov === $g['porta_valor']) {
            $acha(9, null, 'Outro fluxo ativo já usa esta mesma porta de entrada. Desligue o outro ou escolha uma porta diferente para este.');
            break;
        }
    }

    // 10. Marca fora da whitelist: um fluxo nunca pode apagar venda nem se autorizar em campanha paga.
    foreach ($C as $id => $c) {
        if ($c['tipo'] !== 'marca') continue;
        foreach ($c['config'] as $k => $v) {
            if (!in_array($k, WA_FLUXO_CAMPOS_MARCAVEIS, true)) {
                $acha(10, $id, 'O card ' . $nome($id) . ' tenta mudar o campo "' . $k . '", que um fluxo não pode alterar.');
            } elseif ($k === 'status' && !in_array($v, WA_FLUXO_STATUS_PERMITIDOS, true)) {
                $acha(10, $id, 'O card ' . $nome($id) . ' tenta colocar o cliente no status "' . (is_scalar($v) ? $v : '?') . '". Um fluxo só pode usar: ' . implode(', ', WA_FLUXO_STATUS_PERMITIDOS) . '.');
            } elseif (in_array($k, ['qualif_data', 'qualif_grupo'], true) && !is_bool($v)) {
                $acha(10, $id, 'O card ' . $nome($id) . ' usa um valor inválido em "' . $k . '" (só vale sim ou não).');
            } elseif (in_array($k, ['interesses', 'espera'], true) && !in_array($v, ['+roteiro', '+destino'], true)) {
                $acha(10, $id, 'O card ' . $nome($id) . ' usa um valor inválido em "' . $k . '".');
            }
        }
    }

    // 11. Salto para fluxo que nao atende: a conversa cairia no vazio.
    $por_id = [];
    foreach ($outros as $o) if (isset($o['id'])) $por_id[$o['id']] = $o;
    foreach ($C as $id => $c) {
        if ($c['tipo'] !== 'ir_para_fluxo') continue;
        $alvo = $por_id[(string) ($c['config']['fluxo'] ?? '')] ?? null;
        if ($alvo === null) {
            $acha(11, $id, 'O card ' . $nome($id) . ' manda para outro fluxo que não existe. Escolha um fluxo da lista.');
        } elseif (empty($alvo['versao_atual'])) {
            $acha(11, $id, 'O card ' . $nome($id) . ' manda para um fluxo que ainda não foi publicado.');
        } elseif (empty($alvo['ativo'])) {
            $acha(11, $id, 'O card ' . $nome($id) . ' manda para um fluxo que está desligado.');
        }
    }

    // 12. "Sem resposta" para card que espera: o cron agendaria uma espera dentro de outra.
    foreach ($C as $id => $c) {
        $d = $c['sem_resposta'];
        if ($d !== null && $existe($d) && !in_array($C[$d]['tipo'], WA_SEM_RESPOSTA_TIPOS, true)) {
            $acha(12, $id, 'No card ' . $nome($id) . ', "sem resposta" leva ao card ' . $nome($d) . ', que também espera uma resposta. Leve a um card de mensagem, de marca, de atendimento humano ou de fim.');
        }
    }

    // Cards que algum caminho alcanca SEM roteiro escolhido. Quem escolhe o
    // roteiro e a lista de roteiros (pelo proximo) ou a porta anuncio.
    $sem_roteiro = [];
    if ($inicial_ok) {
        $ini_tem = $g['porta'] === 'anuncio';
        $visto = [$g['inicial'] . '|' . (int) $ini_tem => true];
        $fila = [[$g['inicial'], $ini_tem]];
        while ($fila) {
            [$id, $tem] = array_shift($fila);
            if (!$tem) $sem_roteiro[$id] = true;
            $c = $C[$id];
            $viz = [];
            if (!$listaDin($c)) foreach ($c['opcoes'] as $o) $viz[] = [$o['destino'], $tem];
            $viz[] = [$c['proximo'], $listaDin($c) ? true : $tem];
            $viz[] = [$c['fallback'], $tem];
            $viz[] = [$c['sem_resposta'], $tem];
            foreach ($viz as [$d, $t]) {
                if (!$existe($d)) continue;
                $k = $d . '|' . (int) $t;
                if (!isset($visto[$k])) { $visto[$k] = true; $fila[] = [$d, $t]; }
            }
        }
    }

    // 13. PDF sem roteiro escolhido: sem slug no contexto ele nao tem o que mandar.
    foreach ($C as $id => $c) {
        if ($c['tipo'] === 'pdf' && isset($sem_roteiro[$id])) {
            $acha(13, $id, 'O card ' . $nome($id) . ' envia o PDF de um roteiro, mas existe um caminho até ele em que o cliente não escolheu nenhum roteiro. Coloque antes uma lista de roteiros.');
        }
    }

    // 14. Variavel que chegaria crua ao cliente, ou vazia por falta de roteiro.
    $confere_vars = function ($id, $texto, $onde) use ($acha, $nome, $sem_roteiro) {
        if ($texto === null || $texto === '') return;
        $lista_vars = implode(', ', array_map(function ($x) { return '{' . $x . '}'; }, WA_FLUXO_VARS));
        $sem = [];
        preg_match_all('/\{[^{}]*\}/', $texto, $m);
        foreach ($m[0] as $v) {
            $limpa = substr($v, 1, -1);
            if (!in_array($limpa, WA_FLUXO_VARS, true)) {
                $acha(14, $id, 'No ' . $onde . ' do card ' . $nome($id) . ', "' . $v . '" não é uma variável válida. Use apenas ' . $lista_vars . ', em letras minúsculas e sem espaços.');
            } elseif (in_array($limpa, WA_FLUXO_VARS_ROTEIRO, true) && isset($sem_roteiro[$id])) {
                $sem[$v] = true;
            }
        }
        // Chave solta, sem par: chegaria crua ao cliente.
        if (preg_match('/[{}]/', preg_replace('/\{[^{}]*\}/', '', $texto))) {
            $acha(14, $id, 'No ' . $onde . ' do card ' . $nome($id) . ' há uma chave { ou } sem par. Ela chegaria assim ao cliente.');
        }
        foreach (array_keys($sem) as $v) {
            $acha(14, $id, 'No ' . $onde . ' do card ' . $nome($id) . ', ' . $v . ' só funciona depois que o cliente escolhe um roteiro, e existe um caminho até este card sem essa escolha.');
        }
    };
    foreach ($C as $id => $c) {
        $confere_vars($id, $c['texto'], 'texto');
        if ($c['lembrete_ativo']) $confere_vars($id, $c['lembrete_texto'], 'lembrete');
    }

    // 15. AVISO: card que nenhum outro liga, trabalho esquecido no desenho.
    $entradas = [];
    foreach ($C as $id => $c) foreach ($saidas($c) as $d) if ($d !== $id) $entradas[$d] = true;
    foreach ($C as $id => $c) {
        if ($id !== $g['inicial'] && !isset($entradas[$id])) {
            $acha(15, $id, 'Nenhum outro card leva ao card ' . $nome($id) . '.', false);
        }
    }
    // 16. AVISO: sem card de fim, a conversa so termina por atendimento humano.
    $tem_fim = false;
    foreach ($C as $c) if ($c['tipo'] === 'fim') $tem_fim = true;
    if (!$tem_fim) $acha(16, null, 'Este fluxo não tem nenhum card de fim.', false);

    return $probs;
}

/* ---------- retrato publicado, versao por conversa e porta de entrada ----------
   As tabelas de edicao (po_wa_fluxos, po_wa_cards) sao o RASCUNHO. O motor le
   so o retrato congelado em po_wa_fluxo_versoes.grafo, e cada conversa guarda
   em fluxo_versao_id a versao em que esta. Editar nunca quebra conversa em voo.

   PUBLICAR E LIGAR SAO DOIS ATOS. A porta mensagem_nova pega todo contato novo:
   se publicar ligasse, um clique redirecionaria todo mundo, sem ensaio. A coluna
   que liga nasce desligada de proposito e nenhuma funcao daqui a escreve.

   Toda leitura aqui e ESTRITA: banco fora do ar nao pode parecer "nenhum fluxo". */

/* Leitura que distingue erro (null) de vazio ([]). */
function wa_fl_ler($tabela, $query) {
    return wa_fl_call('select_estrito', $tabela, $query);
}

/* Retrato de uma versao, ou null (id vazio, inexistente, banco fora, grafo corrompido). */
function wa_fluxo_grafo($versao_id) {
    $versao_id = (string) $versao_id;
    if ($versao_id === '') return null;
    $linhas = wa_fl_ler('po_wa_fluxo_versoes', 'id=eq.' . rawurlencode($versao_id) . '&select=grafo&limit=1');
    if (!is_array($linhas) || !isset($linhas[0]['grafo'])) return null;
    $g = $linhas[0]['grafo'];
    if (is_string($g)) $g = json_decode($g, true);
    return is_array($g) ? $g : null;
}

/* Valida o rascunho e, so se nao houver problema que bloqueie, congela o
   retrato como a proxima versao e aponta versao_atual para ele. A validacao
   roda AQUI, e nao so no endpoint: nao pode existir caminho que publique sem ela.
   Devolve ['ok'=>bool, 'versao'=>?int, 'problemas'=>array]. */
function wa_fluxo_publica($fluxo_id) {
    $falha = function ($texto, $problemas = null) {
        return ['ok' => false, 'versao' => null,
                'problemas' => $problemas ?? [['regra' => 0, 'card' => null, 'texto' => $texto, 'bloqueia' => true]]];
    };
    $id = rawurlencode((string) $fluxo_id);
    if ((string) $fluxo_id === '') return $falha('Não foi dito qual fluxo publicar.');

    $linhas = wa_fl_ler('po_wa_fluxos', 'id=eq.' . $id . '&limit=1');
    if ($linhas === null) return $falha('Não consegui ler o fluxo agora. Nada foi publicado. Tente de novo em instantes.');
    if (!$linhas) return $falha('Este fluxo não existe.');
    $fluxo = $linhas[0];

    $cards = wa_fl_ler('po_wa_cards', 'fluxo_id=eq.' . $id . '&order=ordem.asc');
    $outros = wa_fl_ler('po_wa_fluxos', 'select=id,gatilho,gatilho_valor,ativo,versao_atual');
    if ($cards === null || $outros === null) {
        return $falha('Não consegui ler os cards agora. Nada foi publicado. Tente de novo em instantes.');
    }

    $problemas = wa_fluxo_valida($fluxo, $cards, $outros);
    foreach ($problemas as $p) {
        if (!empty($p['bloqueia'])) return $falha('', $problemas);
    }

    $ultima = wa_fl_ler('po_wa_fluxo_versoes', 'fluxo_id=eq.' . $id . '&select=versao&order=versao.desc&limit=1');
    if ($ultima === null) return $falha('Não consegui ler as versões agora. Nada foi publicado. Tente de novo em instantes.');
    $numero = $ultima ? (int) $ultima[0]['versao'] + 1 : 1;

    // O unique (fluxo_id, versao) segura duas publicacoes simultaneas: a segunda leva null.
    $versao = wa_fl_call('insert', 'po_wa_fluxo_versoes', [
        'fluxo_id' => (string) $fluxo_id,
        'versao'   => $numero,
        'grafo'    => wa_fluxo_grafo_de($fluxo, $cards),
    ]);
    if (!is_array($versao) || empty($versao['id'])) {
        return $falha('Não consegui gravar a versão. Se alguém publicou ao mesmo tempo, tente de novo.');
    }
    // So versao_atual. Quem liga o fluxo e outro ato, de outra tela.
    if (!wa_fl_call('update', 'po_wa_fluxos', 'id=eq.' . $id, ['versao_atual' => $versao['id']])) {
        return $falha('A versão foi gravada, mas não consegui colocá-la no ar. Tente publicar de novo.');
    }
    return ['ok' => true, 'versao' => $numero, 'problemas' => $problemas];
}

/* Quais palavras uma porta `palavra` aceita: separadas por virgula ou ponto e
   virgula, cada uma pode ter mais de uma palavra ("tesouros asiaticos"). */
function wa_fluxo_porta_palavras($valor) {
    $termos = [];
    foreach (preg_split('/[,;]/', (string) $valor) as $t) {
        $tokens = array_values(array_filter(explode(' ', wa_normaliza($t)), 'strlen'));
        if ($tokens) $termos[] = $tokens;
    }
    return $termos;
}

/* A mensagem tem esse termo como palavra(s) INTEIRA(S)? Sem limite de palavra,
   "natal" casaria dentro de "natalidade" (a mesma classe de defeito do PO em Poa). */
function wa_fluxo_porta_casa($texto, $valor) {
    $msg = array_values(array_filter(explode(' ', wa_normaliza($texto)), 'strlen'));
    foreach (wa_fluxo_porta_palavras($valor) as $termo) {
        for ($i = 0; $i + count($termo) <= count($msg); $i++) {
            $bate = true;
            foreach ($termo as $j => $p) {
                if (!wa_mesma_palavra($msg[$i + $j], $p)) { $bate = false; break; }
            }
            if ($bate) return true;
        }
    }
    return false;
}

/* Qual fluxo atende esta entrada, ou null. Pura. Ordem FIXA: anuncio > palavra
   > mensagem_nova. `chamado` nunca dispara sozinha (so um card ir_para_fluxo a
   chama). Fluxo desligado ou nunca publicado nao atende. Anuncio sem valor
   atende qualquer anuncio, mas o que nomeia o proprio anuncio ganha dele. */
function wa_fluxo_porta($ev, $fluxos) {
    $vivos = [];
    foreach ($fluxos as $f) {
        if (!empty($f['ativo']) && !empty($f['versao_atual'])) $vivos[] = $f;
    }
    $da_porta = function ($gatilho) use ($vivos) {
        return array_values(array_filter($vivos, function ($f) use ($gatilho) { return ($f['gatilho'] ?? '') === $gatilho; }));
    };
    $valor = function ($f) { return trim((string) ($f['gatilho_valor'] ?? '')); };

    $ad = trim((string) ($ev['ad_id'] ?? ''));
    if ($ad !== '') {
        $generico = null;
        foreach ($da_porta('anuncio') as $f) {
            if ($valor($f) === $ad) return $f;
            if ($valor($f) === '' && $generico === null) $generico = $f;
        }
        if ($generico !== null) return $generico;
    }
    $texto = (string) ($ev['texto'] ?? '');
    foreach ($da_porta('palavra') as $f) {
        if ($valor($f) !== '' && wa_fluxo_porta_casa($texto, $f['gatilho_valor'])) return $f;
    }
    $nova = $da_porta('mensagem_nova');
    return $nova ? $nova[0] : null;
}

/* ---------- execucao de um card: o lado que fala com o cliente ----------
   Unica parte do interpretador que manda mensagem de verdade. A LEI daqui:
   o estado so avanca se o envio saiu. `ok` so e true quando a Cloud API
   devolveu ok E um wamid; quem chama (wa_processar) so grava o card novo na
   conversa com ok === true. Sem isso o lead fica "aguardando resposta" de uma
   mensagem que nunca chegou, e o cron o encerra sozinho.

   Retorna ['ok','wamid','espera','terminal','ctx','erro']:
   - terminal: null | 'humano' | 'fim'. Vem junto de ok=false so quando o
     proprio card nao tem o que fazer (pdf sem roteiro): quem chama entrega a
     conversa ao humano em vez de repetir o card.
   - ctx: o contexto, com o slug descartado se ele nao existe mais no catalogo.
   `ir_para_fluxo` nao envia nada: quem carrega o outro grafo e o chamador. */
const WA_FLUXO_SITE = 'https://pereiraoliveiraturismo.com.br';

function wa_fluxo_executa($grafo, $card_id, $wa_id, $ctx, $nome) {
    $res = function ($ok, $extra = []) use (&$ctx) {
        return array_merge(['ok' => $ok, 'wamid' => null, 'espera' => false, 'terminal' => null, 'ctx' => $ctx, 'erro' => null], $extra);
    };
    $recusa = function ($motivo) use ($res, $card_id) {
        error_log('wa_fluxo_executa: card ' . $card_id . ': ' . $motivo);
        return $res(false, ['erro' => $motivo]);
    };
    $card = $grafo['cards'][$card_id] ?? null;
    if (!is_array($card) || !in_array($card['tipo'] ?? null, WA_CARD_TIPOS, true)) {
        return $recusa('card inexistente ou de tipo desconhecido');
    }
    $tipo = $card['tipo'];
    if ($tipo === 'ir_para_fluxo') return $res(true);

    // O catalogo e lido uma vez por execucao.
    $catalogo = null;
    $roteiros = function () use (&$catalogo) {
        if ($catalogo === null) {
            $c = wa_fl_call('roteiros');
            $catalogo = is_array($c) ? $c : [];
        }
        return $catalogo;
    };

    // O slug do contexto pode ser lixo: o roteamento grava qualquer resposta
    // de uma lista dinamica, e o roteiro pode ter saido do ar. Aqui, que tem o
    // catalogo, e que se confere. Catalogo VAZIO nao prova nada (Supabase
    // fora): nesse caso o roteiro vale null so nesta execucao e o slug segue
    // no contexto.
    $slug = (string) ($ctx['roteiro_slug'] ?? '');
    $roteiro = null;
    if ($slug !== '') {
        $roteiro = wa_acha_roteiro($slug, $roteiros());
        if ($roteiro === null && $roteiros()) {
            error_log('wa_fluxo_executa: slug fora do catalogo descartado: ' . $slug);
            $ctx['roteiro_slug'] = null;
        }
    }

    $corpo = wa_fluxo_texto((string) ($card['texto'] ?? ''), wa_fluxo_vars_do_roteiro($roteiro, $nome));
    $vazio = trim($corpo) === '';

    // Registra a saida (o log que separa o eco do robo do eco da dona) e
    // decide o ok: so com wamid.
    $saiu = function ($envio, $tipo_log, $texto, $espera) use ($wa_id, $res, $card_id) {
        wa_registra_saida($wa_id, $envio, $tipo_log, $texto);
        if (empty($envio['ok']) || empty($envio['wamid'])) {
            $erro = (string) ($envio['erro'] ?? 'envio sem confirmacao');
            error_log('wa_fluxo_executa: envio falhou no card ' . $card_id . ': ' . $erro);
            return $res(false, ['erro' => $erro]);
        }
        return $res(true, ['wamid' => $envio['wamid'], 'espera' => $espera]);
    };

    switch ($tipo) {
    case 'texto':
        if ($vazio) return $recusa('texto do card vazio');
        return $saiu(wa_fl_call('send_text', $wa_id, $corpo), 'text', $corpo, false);

    case 'botoes':
        if ($vazio) return $recusa('texto do card vazio');
        $botoes = [];
        foreach ($card['opcoes'] ?? [] as $o) $botoes[] = ['id' => (string) ($o['id'] ?? ''), 'rotulo' => (string) ($o['rotulo'] ?? '')];
        return $saiu(wa_fl_call('send_buttons', $wa_id, $corpo, $botoes), 'buttons', $corpo, true);

    case 'lista':
        if ($vazio) return $recusa('texto do card vazio');
        $itens = [];
        if (($card['config']['fonte'] ?? null) === 'roteiros') {
            $ano = trim((string) ($card['config']['ano'] ?? ''));
            foreach ($roteiros() as $r) {
                if ($ano !== '' && po_ano($r) !== $ano) continue;
                $id = (string) ($r['slug'] ?? '');
                $titulo = trim((string) ($r['titulo'] ?? ''));
                if ($id === '' || $titulo === '') continue;
                $quando = (string) ($r['data_label'] ?? '') ?: (string) ($r['periodo'] ?? '');
                // Titulo de linha 24 e descricao 72: a variavel {roteiro} tem teto 60, entao so aqui se corta.
                $itens[] = ['id' => $id, 'titulo' => wa_fluxo_corta($titulo, 24), 'descricao' => wa_fluxo_corta($quando, 72)];
            }
        } else {
            foreach ($card['opcoes'] ?? [] as $o) {
                $titulo = trim((string) ($o['rotulo'] ?? ''));
                if ($titulo === '' || (string) ($o['id'] ?? '') === '') continue;
                $itens[] = ['id' => (string) $o['id'], 'titulo' => wa_fluxo_corta($titulo, 24),
                            'descricao' => wa_fluxo_corta((string) ($o['descricao'] ?? ''), 72)];
            }
        }
        $itens = array_slice($itens, 0, 10);
        // A Graph recusa lista de zero linhas e a mensagem inteira nao sai.
        // O corpo ja pergunta qual viagem interessa: como texto, sai sempre.
        if (!$itens) {
            return $saiu(wa_fl_call('send_text', $wa_id, $corpo), 'text', $corpo, true);
        }
        $botao = wa_fluxo_corta(trim((string) ($card['config']['botao'] ?? '')) ?: 'Ver roteiros', 20);
        return $saiu(wa_fl_call('send_list', $wa_id, $corpo, $botao, $itens), 'list', $corpo, true);

    case 'pdf':
        if ($roteiro === null) {
            error_log('wa_fluxo_executa: pdf sem roteiro no catalogo (slug "' . $slug . '"), indo para o humano');
            return $res(false, ['terminal' => 'humano', 'erro' => 'roteiro fora do catalogo']);
        }
        if (empty($roteiro['pdf_url'])) {
            // Sem PDF a conversa nao morre: o link da pagina sempre existe.
            $texto = trim($corpo . "\n\n" . WA_FLUXO_SITE . '/roteiros/' . $slug);
            return $saiu(wa_fl_call('send_text', $wa_id, $texto), 'text', $texto, false);
        }
        return $saiu(wa_fl_call('send_doc', $wa_id, $roteiro['pdf_url'], $slug . '.pdf', $corpo), 'document', $corpo, false);

    case 'marca':
        $campos = wa_fluxo_campos_marca($card['config'] ?? [], ['roteiro_slug' => $roteiro !== null ? $slug : null]);
        // interesses e espera ACRESCENTAM ao que a ficha ja tem (quem compara
        // dois roteiros fica com os dois). Ler com a variante estrita: um erro
        // lido como "vazio" apagaria o que estava la ao gravar por cima.
        foreach (['interesses', 'espera'] as $c) {
            if (!isset($campos[$c])) continue;
            $linhas = wa_fl_call('select_estrito', 'po_leads', 'wa_id=eq.' . rawurlencode($wa_id) . '&select=' . $c . '&limit=1');
            if ($linhas === null) {
                error_log('wa_fluxo_executa: leitura de ' . $c . ' falhou, nao grava para nao apagar');
                unset($campos[$c]);
                continue;
            }
            $atual = $linhas[0][$c] ?? [];
            if (is_string($atual)) $atual = json_decode($atual, true);
            $campos[$c] = array_values(array_unique(array_merge(is_array($atual) ? $atual : [], $campos[$c])));
        }
        // Cinto e suspensorio: so a whitelist chega ao banco.
        $campos = array_intersect_key($campos, array_flip(WA_FLUXO_CAMPOS_MARCAVEIS));
        if ($campos && !wa_fl_call('update', 'po_leads', 'wa_id=eq.' . rawurlencode($wa_id), $campos)) {
            return $recusa('gravacao da marca falhou');
        }
        return $res(true);

    case 'humano':
    case 'fim':
        $terminal = $tipo === 'humano' ? 'humano' : 'fim';
        if ($vazio) return $res(true, ['terminal' => $terminal]);
        $r = $saiu(wa_fl_call('send_text', $wa_id, $corpo), 'text', $corpo, false);
        if ($r['ok']) $r['terminal'] = $terminal;
        return $r;
    }
    return $recusa('tipo sem execucao');
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
