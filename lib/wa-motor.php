<?php
/* ============================================================
   A maquina de estados da conversa.

   Estados: novo -> aguardando_roteiro -> enviado_roteiro -> qualificado
            | desqualificado | encerrado | humano

   REGRA MAIS IMPORTANTE DO ARQUIVO: o eco (o que a cliente digita no
   celular) silencia o robo naquele contato para sempre. Sem isso, robo e
   humana falam por cima uma da outra com o cliente na linha.

   Toda dependencia externa (banco, envio, catalogo) entra por
   wa_motor_set_deps, para o teste rodar sem tocar a rede.
============================================================ */

require_once __DIR__ . '/po-data.php';
require_once __DIR__ . '/wa-db.php';
require_once __DIR__ . '/wa-send.php';
require_once __DIR__ . '/wa-roteiro.php';
require_once __DIR__ . '/wa-fone.php';

const WA_SITE = 'https://pereiraoliveiraturismo.com.br';

function wa_motor_set_deps($d) {
    $GLOBALS['WA_DEPS'] = array_merge($GLOBALS['WA_DEPS'] ?? [], $d);
}
function wa_motor_set_anuncios($mapa) { $GLOBALS['WA_ANUNCIOS'] = $mapa; }

function wa_dep($nome) {
    if (isset($GLOBALS['WA_DEPS'][$nome])) return $GLOBALS['WA_DEPS'][$nome];
    $padrao = [
        'roteiros'  => 'po_fetch_roteiros',
        'select'    => 'wa_db_select',
        'insert'    => 'wa_db_insert',
        'update'    => 'wa_db_update',
        'send_text' => 'wa_send_text',
        'send_doc'  => 'wa_send_document',
        'send_list' => 'wa_send_list',
    ];
    return $padrao[$nome];
}
function wa_call($nome, ...$args) { return call_user_func_array(wa_dep($nome), $args); }

/* ---------- textos ---------- */
function wa_texto($chave, $vars = []) {
    $linhas = wa_call('select', 'po_wa_textos', 'chave=eq.' . rawurlencode($chave));
    // Filtra pela chave em vez de confiar em $linhas[0]: o select do
    // Postgrest de verdade ja devolve so a linha pedida, mas um simulador
    // pode devolver a tabela inteira, e pegar a primeira linha da lista
    // trocaria o texto de um evento pelo de outro (ex.: perguntas virando
    // envio_pdf) sem erro nenhum, so a mensagem errada saindo pro cliente.
    $t = null;
    foreach ($linhas as $l) {
        if (($l['chave'] ?? null) === $chave) { $t = $l['texto'] ?? ''; break; }
    }
    if ($t === null) {
        // Chave apagada no painel ou nunca cadastrada: sem isto o robo
        // manda mensagem em branco pro cliente em silencio. Quem decide o
        // que fazer com o corpo vazio e quem manda (wa_envia_texto ou o
        // proprio ponto de chamada), este log so entrega o rastro.
        error_log('wa_texto: chave nao encontrada em po_wa_textos: ' . $chave);
        $t = '';
    }
    foreach ($vars as $k => $v) $t = str_replace('{' . $k . '}', (string) $v, $t);
    // Placeholder sem valor nao pode vazar para o cliente.
    $t = preg_replace('/\{[a-z_]+\}/', '', $t);
    return wa_limpa_pontuacao($t);
}

/* Rede de seguranca contra placeholder vazio (ex.: {nome} quando quem
   chama nao tem o nome do lead a mao): sem isto sobra pontuacao orfa no
   texto que vai pro cliente, tipo "Oi , tudo bem?" em vez de "Oi, tudo
   bem?". Nao existe pra "consertar" a falta do dado, so pra nenhum texto
   sair visivelmente quebrado quando isso acontece (revisao: Important 2). */
function wa_limpa_pontuacao($t) {
    $t = preg_replace('/ {2,}/', ' ', $t);           // espaco duplo
    $t = preg_replace('/\s+([,.!?;:])/', '$1', $t);  // espaco antes de pontuacao
    $t = preg_replace('/([,.!?;:])\1+/', '$1', $t);  // pontuacao duplicada
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
    wa_call('insert', 'po_wa_mensagens', [
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
    $linhas = wa_call('select', 'po_wa_mensagens', 'wamid=eq.' . rawurlencode($wamid));
    foreach ($linhas as $l) {
        if (($l['wamid'] ?? null) === $wamid) return ($l['autor'] ?? '') === 'robo';
    }
    return false;
}

/* Envia texto puro, mas nunca com o corpo vazio: uma chave apagada do
   painel nao pode virar mensagem em branco entregue ao cliente. Tratado
   como falha de envio, no mesmo formato de wa_send_text, para o chamador
   decidir com a mesma checagem de $envio['ok']. */
function wa_envia_texto($wa_id, $texto) {
    if ($texto === '') {
        error_log('wa_envia_texto: texto vazio, nada enviado para ' . $wa_id);
        return ['ok' => false, 'wamid' => null, 'erro' => 'texto vazio'];
    }
    $envio = wa_call('send_text', $wa_id, $texto);
    wa_registra_saida($wa_id, $envio, 'text', $texto);
    return $envio;
}

/* Meses por extenso, sem acento (mesma normalizacao de wa_normaliza). So
   entram na checagem de duvida combinados com "mas" (ver wa_tem_duvida) -
   nao criam duvida sozinhos, senao "a viagem e em outubro" viraria null. */
const WA_MESES = [
    'janeiro','fevereiro','marco','abril','maio','junho',
    'julho','agosto','setembro','outubro','novembro','dezembro',
];

/* Frases que uma cliente de 60+ usa para nao decidir, e que nao sao nem
   sim nem nao. Medido com casos reais: "acho que sim, mas preciso ver com
   meu marido" e "sim, mas so em outubro" tem "sim" no meio e caiam no
   sim; "nao sei ainda" tem "nao" e caia no nao - os dois errados, porque
   nenhuma das duas decidiu nada. Checar duvida ANTES de sim/nao e o que
   impede isso. */
function wa_tem_duvida($t) {
    if (preg_match('/\b(acho que|nao sei|talvez|preciso ver|vou ver|depende|pode ser que|tenho que ver|vou pensar)\b/', $t)) {
        return true;
    }
    // "sim, mas so em outubro": confirmacao com ressalva de data que o
    // robo nao tem como conferir contra o roteiro (isso e trabalho da
    // humana). Nao tenta resolver "mas + qualquer condicao" no geral (um
    // reflexo generico desses ia caçar frase legitima demais), so o par
    // mas + mes, que e o caso medido de verdade com o publico da agencia.
    if (preg_match('/\bmas\b/', $t)) {
        foreach (WA_MESES as $mes) {
            if (preg_match('/\b' . $mes . '\b/', $t)) return true;
        }
    }
    return false;
}

/* ---------- interpretacao de sim/nao ----------
   Devolve true, false, ou null quando o trecho nao e nenhum dos dois (uma
   pergunta, por exemplo). Null nunca avanca o funil: preferimos deixar o
   lead parado a classifica-lo errado. */
function wa_sim_nao($t) {
    if (preg_match('/\b(nao|nunca|infelizmente|impossivel|nenhuma)\b/', $t)) return false;
    if (preg_match('/\b(sim|claro|tenho|posso|consigo|pode ser|isso|certo|perfeito|ja fui|ja viajei|ok)\b/', $t)) return true;
    return null;
}

/* ---------- as duas perguntas, lidas separadamente ----------
   As perguntas ("tem disponibilidade nessa data?" e "ja viajou em grupo?")
   saem numa mensagem so, e a resposta volta numa mensagem so. Antes, um
   unico veredicto plano decidia as duas, e o "nunca" da segunda jogava o
   lead em perdido: "tenho disponibilidade sim, mas nunca viajei em grupo"
   virava desqualificado. Numa operadora que VENDE viagem em grupo, quem
   nunca viajou em grupo e o cliente-alvo, nao o descarte.

   Quem desqualifica e SO a pergunta da data (spec, secao 5). A experiencia
   previa em grupo e informacao complementar: entra no lead quando o
   cliente falou dela, e fica null quando ninguem sabe.

   A leitura e por oracao: a frase e quebrada, cada pedaco e classificado
   pelo assunto (data, grupo ou solta) e so entao vira sim, nao ou null. */
const WA_MARCAS_GRUPO = ['grupo', 'grupos', 'excursao', 'excursoes', 'viajei', 'viajamos', 'viajado', 'primeira vez'];
const WA_MARCAS_DATA  = ['data', 'datas', 'disponibilidade', 'disponivel', 'periodo', 'agenda', 'livre', 'posso', 'consigo', 'nessa epoca'];

function wa_oracoes($texto) {
    // A quebra na pontuacao vem ANTES de wa_normaliza de proposito: a
    // normalizacao troca toda pontuacao por espaco, e sem quebrar antes
    // "tenho a data sim, mas nunca viajei em grupo" vira uma frase so,
    // onde o "nunca" da segunda resposta contamina a primeira.
    $oracoes = [];
    foreach (preg_split('/[.,;:!?\r\n]+/u', (string) $texto) as $parte) {
        $n = trim(wa_normaliza($parte));
        if ($n === '') continue;
        // "mas" e "e" separam as duas respostas tanto quanto a virgula
        // ("posso sim e nunca viajei em grupo").
        foreach (preg_split('/\b(?:mas|porem|e)\b/', $n) as $o) {
            $o = trim($o);
            if ($o !== '') $oracoes[] = $o;
        }
    }
    return $oracoes;
}

function wa_assunto_oracao($o) {
    // Grupo primeiro: a marca de grupo e mais especifica, entao uma oracao
    // que fala das duas coisas conta como resposta da segunda pergunta.
    foreach (WA_MARCAS_GRUPO as $m) if (preg_match('/\b' . $m . '\b/', $o)) return 'grupo';
    foreach (WA_MARCAS_DATA  as $m) if (preg_match('/\b' . $m . '\b/', $o)) return 'data';
    return 'solta';
}

/* Quando a pessoa numera ("1 sim 2 nao"), a numeracao e a evidencia mais
   forte que existe: cada numero aponta uma pergunta. So vale se pelo menos
   um dos dois lados decidir algo, senao "1 pessoa e 2 quartos" viraria
   resposta das perguntas. */
function wa_respostas_numeradas($texto) {
    $n = wa_normaliza($texto);
    if (!preg_match('/\b1\b\s*(.*?)\s*\b2\b\s*(.*)$/', $n, $m)) return null;
    $d = wa_sim_nao(trim($m[1]));
    $g = wa_sim_nao(trim($m[2]));
    if ($d === null && $g === null) return null;
    return ['data' => $d, 'grupo' => $g];
}

/* Pergunta 1: disponibilidade na data. E a unica que desqualifica. */
function wa_resposta_data($texto) {
    // Duvida em qualquer ponto da mensagem derruba a data para null: "sim,
    // mas so em outubro" e "acho que sim, preciso ver com meu marido" nao
    // decidiram nada. Null nao classifica ninguem, fica para a humana.
    if (wa_tem_duvida(wa_normaliza($texto))) return null;

    $num = wa_respostas_numeradas($texto);
    if ($num && $num['data'] !== null) return $num['data'];

    // A oracao que fala de data manda. Sem nenhuma, vale a primeira oracao
    // solta: a resposta da pergunta 1 vem primeiro na frase quase sempre.
    $solta = null;
    foreach (wa_oracoes($texto) as $o) {
        $assunto = wa_assunto_oracao($o);
        if ($assunto === 'data') {
            $v = wa_sim_nao($o);
            if ($v !== null) return $v;
        } elseif ($assunto === 'solta' && $solta === null) {
            $solta = wa_sim_nao($o);
        }
    }
    return $solta;
}

/* Pergunta 2: ja viajou em grupo. Nunca desqualifica, e so aceita
   evidencia explicita: um "sim" solto responde a pergunta 1, e carimbar
   qualif_grupo com ele seria inventar um dado que a dona vai ler no painel
   como se tivesse saido da boca do cliente. */
function wa_resposta_grupo($texto) {
    $num = wa_respostas_numeradas($texto);
    if ($num && $num['grupo'] !== null) return $num['grupo'];

    foreach (wa_oracoes($texto) as $o) {
        if (wa_assunto_oracao($o) === 'grupo') {
            $v = wa_sim_nao($o);
            if ($v !== null) return $v;
        }
    }
    return null;
}

/* ---------- estado ---------- */
function wa_conversa($wa_id) {
    $l = wa_call('select', 'po_wa_conversas', 'wa_id=eq.' . rawurlencode($wa_id));
    return $l[0] ?? null;
}
function wa_conversa_set($wa_id, $campos) {
    $campos['updated_at'] = gmdate('c');
    if (wa_conversa($wa_id)) {
        wa_call('update', 'po_wa_conversas', 'wa_id=eq.' . rawurlencode($wa_id), $campos);
    } else {
        wa_call('insert', 'po_wa_conversas', array_merge(['wa_id' => $wa_id], $campos));
    }
}
function wa_lead($wa_id) {
    $l = wa_call('select', 'po_leads', 'wa_id=eq.' . rawurlencode($wa_id));
    return $l[0] ?? null;
}
function wa_lead_set($wa_id, $campos) {
    if (wa_lead($wa_id)) {
        wa_call('update', 'po_leads', 'wa_id=eq.' . rawurlencode($wa_id), $campos);
    } else {
        wa_call('insert', 'po_leads', array_merge(['wa_id' => $wa_id], $campos));
    }
}

function wa_acha_roteiro($slug, $roteiros) {
    foreach ($roteiros as $r) if (($r['slug'] ?? '') === $slug) return $r;
    return null;
}

/* ---------- envio do roteiro ---------- */
function wa_envia_roteiro($wa_id, $r, $nome) {
    $legenda = wa_texto('envio_pdf', ['roteiro' => $r['titulo'] ?? '', 'nome' => $nome]);

    if (!empty($r['pdf_url'])) {
        $arquivo = ($r['slug'] ?? 'roteiro') . '.pdf';
        $envio = wa_call('send_doc', $wa_id, $r['pdf_url'], $arquivo, $legenda);
        wa_registra_saida($wa_id, $envio, 'document', $legenda);
    } else {
        // Sem PDF a conversa nao pode morrer: manda o link da pagina, que
        // sempre existe porque o roteiro esta ativo no banco.
        $envio = wa_envia_texto($wa_id, trim($legenda . "\n\n" . WA_SITE . '/roteiros/' . ($r['slug'] ?? '')));
    }

    // O estado so avanca se o roteiro de fato saiu. Sem esta checagem, um
    // envio que falhou (Graph API fora do ar, token vencido, telefone
    // invalido) marcava a conversa como "enviado_roteiro" mesmo sem o
    // cliente ter recebido nada - e o cron de timeout (Task 8) encerraria
    // esse lead por silencio 48h depois de uma mensagem que nunca chegou.
    // Melhor a dona ver a conversa parada do que o funil mentir.
    if (empty($envio['ok'])) {
        error_log('wa_envia_roteiro: falha ao enviar o roteiro para ' . $wa_id);
        return 'falha_envio';
    }

    // O documento saiu, entao o estado avanca AGORA, antes das perguntas.
    // Se a gravacao esperasse as perguntas e elas falhassem, a conversa
    // ficaria em 'novo' e a proxima mensagem do cliente reenviaria o PDF
    // inteiro: com a Graph API instavel, isso vira PDF duplicado em serie.
    // Cliente com o roteiro na mao e sem as perguntas e melhor do que
    // cliente recebendo o mesmo roteiro duas vezes.
    wa_conversa_set($wa_id, [
        'estado'           => 'enviado_roteiro',
        'roteiro_slug'     => $r['slug'] ?? null,
        'aguardando_desde' => gmdate('c'),
        'lembrete_at'      => null,
    ]);
    wa_lead_set($wa_id, ['roteiro' => $r['titulo'] ?? '']);

    // As duas perguntas vao na sequencia, sem pedir licenca: e exatamente
    // como a cliente faz na mao.
    $perguntas = wa_envia_texto($wa_id, wa_texto('perguntas', [
        'nome'    => $nome,
        'roteiro' => $r['titulo'] ?? '',
        'data'    => $r['data_label'] ?? 'na data prevista',
    ]));
    if (empty($perguntas['ok'])) {
        error_log('wa_envia_roteiro: roteiro saiu e as perguntas falharam, estado avancado assim mesmo para nao reenviar o PDF | ' . $wa_id);
        return 'falha_perguntas';
    }

    return 'enviou_roteiro';
}

/* ---------- atalhos digitados por ela ---------- */
function wa_atalho($wa_id, $texto) {
    $t = trim(mb_strtolower($texto, 'UTF-8'));

    if (strpos($t, '#proposta') === 0) {
        wa_lead_set($wa_id, ['status' => 'negociacao', 'proposta_at' => gmdate('c')]);
        return 'proposta';
    }
    if (strpos($t, '#fechou') === 0) {
        $campos = ['status' => 'venda', 'venda_at' => gmdate('c')];
        // "#fechou 22900" ou "#fechou 22.900,50"
        if (preg_match('/#fechou\s+([\d.,]+)/', $t, $m)) {
            $n = str_replace(['.', ','], ['', '.'], $m[1]);
            if (is_numeric($n)) $campos['venda'] = (float) $n;
        }
        wa_lead_set($wa_id, $campos);
        return 'venda';
    }
    if (strpos($t, '#perdeu') === 0) {
        wa_lead_set($wa_id, ['status' => 'perdido']);
        return 'perdido';
    }
    return null;
}

/* ---------- ponto de entrada ---------- */
function wa_processar($ev) {
    $wa_id = $ev['wa_id'];
    $texto = (string) ($ev['texto'] ?? '');
    $nome  = $ev['nome'] ?? '';

    if ($ev['tipo'] === 'status') return 'status';

    /* ----- eco: ela falou pelo celular ----- */
    if ($ev['tipo'] === 'eco') {
        // ...ou fomos nos. O eco pode ser o proprio envio do robo voltando
        // pela Cloud API, e trata-lo como handoff silenciaria o robo no
        // primeiro PDF que ele mandasse, em toda conversa. O log de saida
        // (wa_registra_saida) e o que permite separar os dois.
        if (wa_msg_do_robo($ev['wamid'] ?? '')) return 'eco_do_robo';

        $conv      = wa_conversa($wa_id);
        $ja_humano = ($conv['estado'] ?? '') === 'humano';

        $acao = wa_atalho($wa_id, $texto);

        // Documento numa conversa ja qualificada (ou ja em humano) e
        // proposta, com alta confianca no fluxo dela. Cobre o esquecimento
        // do atalho.
        if (!$acao && ($ev['tipo_msg'] ?? '') === 'document'
            && in_array($conv['estado'] ?? '', ['qualificado', 'humano'], true)) {
            wa_lead_set($wa_id, ['status' => 'negociacao', 'proposta_at' => gmdate('c')]);
            $acao = 'proposta';
        }

        // Qualquer fala dela pelo celular e handoff, tenha sido atalho,
        // documento ou frase qualquer: o robo se cala aqui, sempre - mesmo
        // quando a fala tambem disparou uma acao de funil. Sem isto, um
        // atalho ou PDF digitado a partir de um estado que o robo ainda
        // controla (enviado_roteiro, qualificado) deixava o estado como
        // estava, e a proxima mensagem do cliente era respondida pelo robo
        // por cima da humana, que ja estava na conversa.
        if (!$ja_humano) {
            wa_conversa_set($wa_id, ['estado' => 'humano', 'silenciado_at' => gmdate('c')]);
        }

        if ($acao) return $acao;
        return $ja_humano ? 'ja_humano' : 'silenciou';
    }

    /* ----- mensagem do cliente ----- */
    $conv = wa_conversa($wa_id);

    // Conversa entregue a humana nunca volta para o robo.
    if (($conv['estado'] ?? '') === 'humano') return 'silenciado';

    // O cliente voltou a falar: o relogio do timeout recomeca do zero. E o
    // significado real de "ele respondeu", e vale para qualquer mensagem,
    // nao so para a que o robo consegue interpretar. Sem isto, um
    // lembrete_at de ontem sobrevivia a conversa viva e wa_varre_timeouts
    // encerrava como perdida uma conversa em que o cliente escreveu
    // minutos antes (a varredura decide pelo lembrete_at e ignora o
    // aguardando_desde depois que ele existe).
    if (!empty($conv['lembrete_at'])) {
        wa_conversa_set($wa_id, ['lembrete_at' => null]);
        $conv['lembrete_at'] = null;
    }

    $roteiros = wa_call('roteiros');

    // Primeiro contato: cria lead e contato.
    if (!$conv) {
        wa_lead_set($wa_id, [
            'nome'      => $nome,
            'telefone'  => $wa_id,
            'status'    => 'novo',
            'origem'    => $ev['ad_id'] ? 'pago' : 'whatsapp',
            'ad_id'     => $ev['ad_id'],
            'ctwa_clid' => $ev['ctwa_clid'],
        ]);
        wa_conversa_set($wa_id, ['estado' => 'novo']);
        $conv = wa_conversa($wa_id);
    }

    // Já mandou o roteiro: o que chega agora e resposta das perguntas.
    if (($conv['estado'] ?? '') === 'enviado_roteiro') {
        $r     = wa_resposta_data($texto);
        $grupo = wa_resposta_grupo($texto);
        if ($r === false) {
            // Manda primeiro, avanca o estado so se saiu: um envio que
            // falhou nao pode gravar "perdido" no lead como se a cliente
            // tivesse sido avisada - ela ficaria perdida sem nunca ter
            // recebido a mensagem.
            $envio = wa_envia_texto($wa_id, wa_texto('sem_data', ['nome' => $nome]));
            if (empty($envio['ok'])) {
                error_log('wa_processar: falha ao enviar sem_data para ' . $wa_id);
                return 'falha_envio';
            }
            wa_conversa_set($wa_id, ['estado' => 'desqualificado']);
            $campos = ['status' => 'perdido', 'qualif_data' => false];
            if ($grupo !== null) $campos['qualif_grupo'] = $grupo;
            wa_lead_set($wa_id, $campos);
            return 'desqualificou';
        }
        if ($r === true) {
            $envio = wa_envia_texto($wa_id, wa_texto('qualificado', ['nome' => $nome]));
            if (empty($envio['ok'])) {
                error_log('wa_processar: falha ao enviar qualificado para ' . $wa_id);
                return 'falha_envio';
            }
            wa_conversa_set($wa_id, ['estado' => 'qualificado']);
            // Data confirmada qualifica, tenha o cliente viajado em grupo
            // antes ou nao. qualif_grupo so e gravado quando existe
            // evidencia: sem ela a coluna fica como estava, em vez de
            // receber um true que ninguem disse.
            $campos = [
                'status'      => 'atendimento',
                'qualif_data' => true,
                'qualif_at'   => gmdate('c'),
            ];
            if ($grupo !== null) $campos['qualif_grupo'] = $grupo;
            wa_lead_set($wa_id, $campos);
            return 'qualificou';
        }
        // Nem sim nem nao (uma pergunta, por exemplo): nao classifica
        // errado e nao responde bobagem. Deixa para a humana.
        return 'aguardando';
    }

    // Identificacao do roteiro, da fonte mais confiavel para a menos.
    $slug = null;

    if (!empty($ev['ad_id'])) {
        $mapa = $GLOBALS['WA_ANUNCIOS'] ?? null;
        if ($mapa === null) {
            $linhas = wa_call('select', 'po_wa_anuncios', 'ad_id=eq.' . rawurlencode($ev['ad_id']));
            $slug = $linhas[0]['roteiro_slug'] ?? null;
        } else {
            $slug = $mapa[$ev['ad_id']] ?? null;
        }
    }
    if (!$slug) $slug = wa_slug_do_marcador($texto);
    if (!$slug && ($ev['tipo_msg'] ?? '') === 'list_reply') $slug = trim($texto);
    if (!$slug) {
        $achados = wa_match_roteiros($texto, $roteiros);
        if (count($achados) === 1) $slug = $achados[0];
    }

    if ($slug) {
        $r = wa_acha_roteiro($slug, $roteiros);
        if ($r) return wa_envia_roteiro($wa_id, $r, $nome);
    }

    // Nada identificado (ou ambiguo): o menu resolve sem chutar. Mas sai UMA
    // vez: estando em aguardando_roteiro o menu ja foi mandado, e repetir a
    // lista a cada mensagem que o robo nao entende (quatro listas seguidas,
    // medido na revisao) le como robo travado para o publico da agencia. O
    // estado existe justamente para distinguir os dois momentos. Quem
    // continua sem escolher cai no lembrete do cron, que tem texto proprio.
    if (($conv['estado'] ?? '') === 'aguardando_roteiro') return 'aguardando_menu';

    $itens = [];
    foreach ($roteiros as $r) {
        $itens[] = ['id' => $r['slug'], 'titulo' => $r['titulo'], 'descricao' => $r['data_label'] ?? ''];
    }
    $corpo_menu = wa_texto('menu', ['nome' => $nome]);
    if ($corpo_menu === '') {
        error_log('wa_processar: texto do menu vazio, nada enviado para ' . $wa_id);
        return 'falha_envio';
    }

    // Catalogo vazio (Supabase fora, cache frio, nenhum roteiro ativo): a
    // Graph API recusa lista com zero linhas, entao a mensagem inteira nao
    // sai e o cliente fica sem resposta nenhuma. O proprio texto do menu
    // ja pergunta qual viagem interessa, e como texto puro ele sempre sai.
    if (!$itens) {
        $envio = wa_envia_texto($wa_id, $corpo_menu);
        if (empty($envio['ok'])) {
            error_log('wa_processar: catalogo vazio e falha ao pedir o destino para ' . $wa_id);
            return 'falha_envio';
        }
        wa_conversa_set($wa_id, ['estado' => 'aguardando_roteiro', 'aguardando_desde' => gmdate('c')]);
        return 'menu_texto';
    }
    $envio = wa_call('send_list', $wa_id, $corpo_menu, 'Ver roteiros', $itens);
    wa_registra_saida($wa_id, $envio, 'list', $corpo_menu);
    if (empty($envio['ok'])) {
        error_log('wa_processar: falha ao enviar o menu para ' . $wa_id);
        return 'falha_envio';
    }
    wa_conversa_set($wa_id, ['estado' => 'aguardando_roteiro', 'aguardando_desde' => gmdate('c')]);
    return 'menu';
}
