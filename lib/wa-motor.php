<?php
/* ============================================================
   A maquina de estados da conversa.

   Quem decide o proximo passo da conversa e o interpretador de fluxo
   (lib/wa-fluxo.php); este arquivo guarda as leis que ficam ACIMA dele e
   fala com o grafo em wa_processar / wa_fluxo_percorre.
   Estado (espelho do card atual, spec 6.5): aguardando | humano | encerrado.
   'novo' tem dois sentidos: SEM card_atual, e a conversa recem-criada que
   ainda nao respondeu nada; COM card_atual, e uma cadeia parada por falha de
   envio (o card_atual e o proximo a executar e ninguem esta sendo aguardado).
   A varredura de timeout so toca 'aguardando', nunca 'novo'.

   REGRA MAIS IMPORTANTE DO ARQUIVO: o eco (o que a cliente digita no
   celular) silencia o robo naquele contato para sempre. Sem isso, robo e
   humana falam por cima uma da outra com o cliente na linha.

   Toda dependencia externa (banco, envio, catalogo) entra por
   wa_motor_set_deps, para o teste rodar sem tocar a rede.
============================================================ */

require_once __DIR__ . '/po-data.php';
require_once __DIR__ . '/wa-db.php';
require_once __DIR__ . '/wa-fluxo.php';
require_once __DIR__ . '/wa-send.php';
require_once __DIR__ . '/wa-roteiro.php';
require_once __DIR__ . '/wa-fone.php';
require_once __DIR__ . '/wa-camp-recibo.php';

// WA_SITE mora em wa-fluxo.php (o motor ja o requer): um dono so para o dominio.

function wa_motor_set_deps($d) {
    $GLOBALS['WA_DEPS'] = array_merge($GLOBALS['WA_DEPS'] ?? [], $d);
}
function wa_motor_set_anuncios($mapa) { $GLOBALS['WA_ANUNCIOS'] = $mapa; }

function wa_dep($nome) { return wa_fl_dep($nome); }
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
/* @deprecated Plano A: substituidas pelos cards botoes.
   De WA_MESES ate wa_respostas_numeradas/wa_resposta_data/wa_resposta_grupo,
   mais wa_envia_roteiro adiante: eram o roteamento da maquina de estados
   antiga e nenhuma e chamada por wa_processar. Ficam (com os testes) ate o
   Plano A estar no ar e verificado; so entao saem. wa_envia_roteiro mantem de
   proposito o `??` em data_label (nao pega string vazia); o caminho novo,
   wa_fluxo_vars_do_roteiro, usa `?:`. Nao conserte uma, apague as duas. */
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

/* Defesa 3 da spec 8.1: "Responda SAIR para nao receber mais".

   O casamento e ESTRITO - a palavra sozinha, com pontuacao e espaco ao
   redor. Duas razoes:

   1) "quero sair do grupo" e uma pergunta sobre a viagem, nao um descadastro.
   2) "cancelar" ficou FORA da lista de proposito: numa agencia de viagem
      "quero cancelar" quase sempre e cancelar uma reserva, e trata-lo como
      descadastro tiraria da lista justamente quem esta em negociacao. */
function wa_e_saida($texto) {
    return (bool) preg_match('/^\s*(sair|parar|descadastrar)[\s.!]*$/iu', (string) $texto);
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
        // O telefone entra SO no insert: no update sobrescreveria a edicao
        // feita a mao no painel. O wa_id ja esta em E.164, entao serve como
        // telefone sem conversao. Sem isto, o lead nascido do eco (ela
        // inicia a conversa pelo celular, ou digita #proposta numa conversa
        // que o robo nunca viu) aparecia como "(sem nome)" com telefone
        // "-", e ela nao conseguia nem ligar para a pessoa a partir da
        // ficha. O nome, quando existe, ja vem em $campos.
        wa_call('insert', 'po_leads', array_merge(['wa_id' => $wa_id, 'telefone' => $wa_id], $campos));
    }
}

/* Escreve na linha do tempo de observacoes do lead, que e o que a dona le
   no painel. Sem isto, "entregue para atendimento humano" seria uma
   mudanca de estado invisivel: ela abriria a ficha sem saber por que o
   robo parou nem o que o cliente mandou. */
function wa_nota_lead($wa_id, $txt) {
    $lead  = wa_lead($wa_id);
    $notas = (is_array($lead) && isset($lead['notas']) && is_array($lead['notas'])) ? $lead['notas'] : [];
    $notas[] = ['ts' => gmdate('c'), 'txt' => $txt];
    wa_lead_set($wa_id, ['notas' => $notas]);
}

/* @deprecated Plano A: sem chamador desde que o fluxo decide quando chamar a humana
   (o fallback dos cards). Fica ate o Plano A estar no ar e verificado.

   Quantas mensagens o cliente mandou depois de o roteiro sair. O webhook
   grava a mensagem em po_wa_mensagens ANTES de chamar o motor, entao a que
   esta sendo processada agora ja esta contada: duas ou mais significa que
   ele voltou a escrever sem ter respondido as perguntas.

   A comparacao e estritamente maior que aguardando_desde de proposito: a
   mensagem que pediu o roteiro pode cair no mesmo segundo do envio, e
   conta-la faria o primeiro "quanto custa?" ja virar handoff. Errar para o
   lado de esperar mais uma mensagem e melhor do que tirar o robo da
   conversa cedo demais. */
function wa_msgs_do_cliente_depois($wa_id, $desde) {
    $corte = strtotime((string) $desde);
    if (!$corte) return 0;
    $linhas = wa_call('select', 'po_wa_mensagens',
        'wa_id=eq.' . rawurlencode($wa_id) . '&direcao=eq.in&order=ts.desc&limit=20');
    $n = 0;
    foreach ($linhas as $l) {
        if (($l['direcao'] ?? '') !== 'in') continue;
        $ts = strtotime((string) ($l['ts'] ?? ''));
        if ($ts && $ts > $corte) $n++;
    }
    return $n;
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

    if ($ev['tipo'] === 'status') {
        /* O recibo atualiza po_wa_envios, e SO ele. Nao passa por
           wa_registra_evento de proposito: o wamid de um status E o da
           mensagem original e po_wa_mensagens.wamid e unique, entao recibo e
           eco competiriam pela mesma linha e o eco viraria 'duplicado' -
           fazendo o robo nao se calar. */
        wa_camp_recibo($ev['wamid'] ?? '', $ev['texto'] ?? '');
        return 'status';
    }

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

        /* Documento numa conversa ja QUALIFICADA (ou ja em humano) e proposta,
           com alta confianca no fluxo dela. Cobre o esquecimento do atalho.

           A qualificacao e exigida de proposito, e e o ponto delicado daqui:
           no motor antigo a rede era o estado 'qualificado', que significava
           "respondeu as duas perguntas". Com o interpretador, 'aguardando' vale
           desde a PRIMEIRA mensagem (a boas-vindas ja deixa a conversa assim),
           entao aceitar 'aguardando' sozinho faria o PDF dela virar
           `negociacao` + `proposta_at` para quase todo contato novo - inflando
           a etapa Proposta do Funil e distorcendo os Relatorios em silencio. O
           equivalente novo de 'qualificado' e a ficha ter `qualif_at`, que o
           card `marca` da qualificacao grava. Em 'humano' nao se exige nada:
           ali ela ja esta conduzindo o atendimento.
           A ficha so e lida quando o evento e documento: nenhum eco comum
           ganha uma consulta a mais por causa disto. */
        $lead_eco = ($ev['tipo_msg'] ?? '') === 'document' ? wa_lead($wa_id) : null;
        if (!$acao && ($ev['tipo_msg'] ?? '') === 'document'
            && (($conv['estado'] ?? '') === 'humano'
                || (($conv['estado'] ?? '') === 'aguardando' && !empty($lead_eco['qualif_at'])))) {
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

    /* SAIR vem antes de tudo, inclusive do silencio. O silencio existe para
       o robo nao falar por cima da humana; nao existe para a pessoa perder o
       direito de sair da lista. Por isso o CARIMBO acontece sempre e so a
       RESPOSTA respeita o silencio. */
    if ($ev['tipo'] === 'mensagem' && wa_e_saida($ev['texto'] ?? '')) {
        wa_lead_set($wa_id, ['opt_out_at' => gmdate('c')]);
        $conv = wa_conversa($wa_id);
        if (empty($conv['silenciado_at'])) {
            /* Nao prometer a volta: NADA limpa opt_out_at, nem o painel nem o
               motor, entao "e so escrever aqui" e promessa que o sistema nao
               cumpre - a pessoa escreve, nada acontece, e a agencia fica com a
               conta. Enquanto nao existir o caminho de volta, a frase diz o que
               e verdade: o canal continua aberto para falar com a equipe. */
            wa_envia_texto($wa_id,
                'Tudo bem, você não vai mais receber nossas mensagens sobre viagens. ' .
                'Se precisar de alguma coisa, é só chamar a nossa equipe neste mesmo número.');
            wa_conversa_set($wa_id, ['estado' => 'encerrado']);
        }
        return 'opt_out';
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
        // O aguardando_desde reinicia junto, e nao por simetria: zerar so o
        // lembrete_at deixaria a conversa com "esperando ha mais de 24h"
        // ainda verdadeiro, e a varredura seguinte mandaria OUTRO lembrete
        // na hora, e mais um a cada mensagem do cliente. O relogio
        // recomecar e as duas coisas.
        $agora = gmdate('c');
        wa_conversa_set($wa_id, ['lembrete_at' => null, 'aguardando_desde' => $agora]);
        $conv['lembrete_at']      = null;
        $conv['aguardando_desde'] = $agora;
    }

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

    /* Conversa com ponto marcado: 'aguardando' (o card ja foi entregue e
       espera a resposta) ou 'novo' com card_atual (cadeia interrompida por
       falha de envio: o card_atual e o PROXIMO A EXECUTAR e ninguem esta
       sendo aguardado, por isso o estado nao e 'aguardando': a varredura de
       timeout trataria como "o cliente calou"). */
    $estado  = $conv['estado'] ?? '';
    $retoma = in_array($estado, ['aguardando', 'novo'], true) && !empty($conv['card_atual']);

    /* ----- conversa com ponto marcado -----
       Duas situacoes: aguardando a resposta de um card que espera, ou cadeia
       parada por falha de envio (estado 'novo', ninguem sendo aguardado).
       A porta NAO e resolvida aqui (spec 6.11): mensagem que chega com a
       conversa esperando botao vai para o fallback daquele card, nunca para
       outro fluxo. O grafo e o da versao que a conversa carimbou ao entrar. */
    if ($retoma) {
        $versao_id = (string) ($conv['fluxo_versao_id'] ?? '');
        if ($versao_id === '') {
            error_log('wa_processar: conversa com ponto marcado (aguardando ou cadeia parada) sem fluxo_versao_id | ' . $wa_id);
            wa_entrega_humana($wa_id, 'A conversa estava parada num card, mas sem versão de fluxo registrada. Entregue para atendimento humano.');
            return 'card_perdido';
        }
        $grafo = wa_fluxo_grafo($versao_id);
        // null COM versao_id preenchido e "nao sei" (versao apagada ou banco
        // fora), e nunca "sem fluxo": resolver a porta de novo faria quem esta
        // no meio da conversa receber as boas-vindas outra vez.
        if ($grafo === null) {
            error_log('wa_processar: grafo da versao ' . $versao_id . ' indisponivel, nada feito | ' . $wa_id);
            return 'grafo_indisponivel';
        }
        $atual = (string) $conv['card_atual'];
        if (!isset($grafo['cards'][$atual])) {
            error_log('wa_processar: card_atual ' . $atual . ' nao existe na versao ' . $versao_id . ' | ' . $wa_id);
            wa_entrega_humana($wa_id, 'O robô perdeu o ponto da conversa (o card em que ela estava não existe mais no fluxo). Entregue para atendimento humano.');
            return 'card_perdido';
        }
        $ctx = ['roteiro_slug' => $conv['roteiro_slug'] ?? null, 'tentativas' => (int) ($conv['tentativas'] ?? 0)];
        /* Retomada: o card_atual ainda nao foi entregue (cadeia interrompida)
           ou nao e de um tipo que espera. Roteia-lo pularia o card que falhou,
           e o cliente nunca receberia a pergunta. A mensagem dele nao decide
           nada aqui: so reabre a cadeia de onde parou, sem repetir o que saiu. */
        if ($estado === 'novo' || !in_array($grafo['cards'][$atual]['tipo'] ?? '', WA_CARD_ESPERA, true)) {
            return wa_fluxo_percorre($wa_id, $nome, $grafo, $versao_id, $atual, $ctx, false);
        }
        // So o clique (botao ou linha de lista) carrega o id de uma opcao.
        // Texto digitado nao e escolha: se "turquia" valesse como slug, quem
        // escreve em vez de tocar na lista escolheria um roteiro sem querer.
        $clique   = in_array($ev['tipo_msg'] ?? '', ['list_reply', 'button_reply', 'button'], true);
        $resposta = $clique ? $texto : '';
        $p = wa_fluxo_proximo($grafo, $atual, $resposta, $ctx);
        if ($p['card'] === null) {
            error_log('wa_processar: card ' . $atual . ' sem saida (' . $p['motivo'] . ') | ' . $wa_id);
            wa_entrega_humana($wa_id, 'O card em que a conversa estava não tem saída para essa resposta. Entregue para atendimento humano.');
            return 'card_perdido';
        }
        return wa_fluxo_percorre($wa_id, $nome, $grafo, $versao_id, $p['card'], $p['ctx'], false);
    }

    /* ----- conversa fora de card que espera: a porta decide ----- */
    $fluxos = wa_fluxos_ativos();
    if ($fluxos === null) {
        error_log('wa_processar: lista de fluxos indisponivel, nada feito | ' . $wa_id);
        return 'grafo_indisponivel';
    }
    $porta = wa_fluxo_porta($ev, $fluxos);
    if ($porta === null) {
        // Sem fluxo ligado o robo nao inventa resposta. A mensagem fica no
        // log e a equipe atende pelo app.
        error_log('wa_processar: nenhum fluxo ligado atende a mensagem | ' . $wa_id);
        return 'sem_fluxo';
    }
    $versao_id = (string) $porta['versao_atual'];
    $grafo = wa_fluxo_grafo($versao_id);
    if ($grafo === null) {
        error_log('wa_processar: grafo da versao ' . $versao_id . ' indisponivel, nada feito | ' . $wa_id);
        return 'grafo_indisponivel';
    }
    $inicial = (string) ($grafo['inicial'] ?? '');
    if ($inicial === '' || !isset($grafo['cards'][$inicial])) {
        error_log('wa_processar: card inicial da versao ' . $versao_id . ' nao existe | ' . $wa_id);
        wa_entrega_humana($wa_id, 'O fluxo que atenderia essa mensagem está sem card inicial. Entregue para atendimento humano.');
        return 'card_perdido';
    }

    // Conversa nova (ou recomecada): o contexto nasce limpo. Carregar o
    // roteiro_slug de uma conversa encerrada faria quem volta um mes depois
    // receber o PDF da viagem de antes. So o anuncio mapeado semeia o roteiro.
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
    return wa_fluxo_percorre($wa_id, $nome, $grafo, $versao_id, $inicial,
        ['roteiro_slug' => $slug, 'tentativas' => 0], true);
}

/* ---------- o interpretador, do lado do motor ---------- */

/* Teto de cards executados por mensagem. Cards que nao esperam encadeiam
   (texto, marca, pdf, salto de fluxo), e um grafo mal desenhado pode girar
   para sempre: sem teto, uma unica mensagem do cliente viraria rajada de
   mensagens ate o PHP estourar o tempo. Contam tambem os saltos de fluxo. */
const WA_FLUXO_MAX_PASSOS = 10;

/* Fluxos que podem atender: ligados e publicados. null = o banco nao
   respondeu (NAO e "nenhum fluxo"). O order= e FIXO de proposito: com dois
   fluxos `palavra` de valores diferentes e uma mensagem que casa os dois, sem
   ele vence a linha que o banco devolveu primeiro, ao acaso. Com ele, vence o
   fluxo mais antigo, sempre o mesmo. */
function wa_fluxos_ativos() {
    return wa_call('select_estrito', 'po_wa_fluxos',
        'ativo=eq.true&versao_atual=not.is.null&select=id,nome,gatilho,gatilho_valor,ativo,versao_atual'
        . '&order=created_at.asc,id.asc');
}

/* Entrega a conversa a humana e deixa o motivo na ficha, para ela abrir o
   lead sem saber por que o robo parou e encontrar a explicacao. Nunca deixa a
   conversa muda: e o destino de tudo que o fluxo nao sabe resolver. */
function wa_entrega_humana($wa_id, $nota) {
    wa_conversa_set($wa_id, ['estado' => 'humano', 'silenciado_at' => gmdate('c'), 'lembrete_at' => null]);
    wa_nota_lead($wa_id, $nota);
}

/* Executa a partir de $card ate a conversa esperar, terminar ou travar.
   $estampar = esta e a ENTRADA num fluxo (porta ou salto): so ai se grava
   fluxo_versao_id. Conversa em andamento nunca o reescreve: editar e
   republicar o fluxo nao pode jogar quem esta a meio caminho num grafo onde
   o card_atual dela talvez nem exista.

   Lei do envio: card_atual/estado so avancam depois de a mensagem sair.
   A unica excecao e o `terminal`: ok diz se saiu, terminal diz para onde a
   conversa vai, e a humana recebe a conversa mesmo com o aviso falhado. */
function wa_fluxo_percorre($wa_id, $nome, $grafo, $versao_id, $card, $ctx, $estampar) {
    $fluxos = null;   // carregado so se houver salto
    $passos = 0;
    $progresso = false;   // algum card desta cadeia ja SAIU
    while (true) {
        if ($passos >= WA_FLUXO_MAX_PASSOS) {
            error_log('wa_fluxo_percorre: teto de ' . WA_FLUXO_MAX_PASSOS . ' passos | ' . $wa_id);
            wa_entrega_humana($wa_id, 'O fluxo encadeou cards sem nunca esperar o cliente e foi interrompido. Entregue para atendimento humano.');
            return 'teto_passos';
        }
        $passos++;

        $r   = wa_fluxo_executa($grafo, $card, $wa_id, $ctx, $nome);
        $ctx = $r['ctx'];
        $grava = [
            'card_atual'   => $card,
            'tentativas'   => (int) ($ctx['tentativas'] ?? 0),
            'roteiro_slug' => $ctx['roteiro_slug'] ?? null,
            'lembrete_at'  => null,
        ];
        if ($estampar) $grava['fluxo_versao_id'] = $versao_id;

        if ($r['terminal'] !== null) {
            if (!$r['ok']) error_log('wa_fluxo_percorre: card ' . $card . ' terminal ' . $r['terminal'] . ' sem o aviso enviado (' . $r['erro'] . '), conversa entregue assim mesmo | ' . $wa_id);
            if ($r['terminal'] === 'humano') {
                wa_conversa_set($wa_id, $grava + ['estado' => 'humano', 'silenciado_at' => gmdate('c')]);
                wa_nota_lead($wa_id, $r['ok']
                    ? 'O fluxo entregou a conversa para atendimento humano.'
                    : 'O fluxo entregou a conversa para atendimento humano, mas o card não pôde ser enviado (' . $r['erro'] . ').');
            } else {
                wa_conversa_set($wa_id, $grava + ['estado' => 'encerrado']);
            }
            return 'fluxo';
        }

        if (!$r['ok']) {
            error_log('wa_fluxo_percorre: envio falhou no card ' . $card . ' (' . $r['erro'] . ') | ' . $wa_id);
            // Lei do envio: nao registrar o que NAO chegou, mas tambem nao
            // esquecer o que chegou. Se cards anteriores desta cadeia ja
            // saidas (um PDF, por exemplo), grava ate onde chegou: o card
            // que falhou vira o proximo a executar, e a mensagem seguinte
            // retoma dele sem repetir o PDF. Sem progresso, nada a gravar.
            // O estado NAO vira 'aguardando': ninguem esta sendo aguardado.
            //
            // E por isso mesmo a cadeia parada precisa de NOTA NA FICHA: a
            // varredura ignora 'novo' (corretamente), entao nenhum relogio
            // alcanca esta linha. Sem a nota, o cliente recebeu o PDF, a
            // pergunta nunca chegou, e se ele nao escrever de novo a conversa
            // morre invisivel. O auto-conserto existe (qualquer mensagem
            // seguinte retoma a cadeia) mas depende de ele insistir, e o
            // publico 60+ da agencia e justamente quem nao insiste. A ficha e
            // o que a Lais le, e o unico canal que sobra neste caminho.
            if ($progresso) {
                wa_conversa_set($wa_id, $grava + ['estado' => 'novo']);
                wa_nota_lead($wa_id, 'O robô enviou parte das mensagens e a próxima falhou ('
                    . $r['erro'] . '). A conversa está parada neste ponto.');
            }
            return 'falha_envio';
        }
        $progresso = true;

        if ($r['espera']) {
            wa_conversa_set($wa_id, $grava + ['estado' => 'aguardando', 'aguardando_desde' => gmdate('c')]);
            return 'fluxo';
        }

        // Card que nao espera: segue para o proximo sem olhar resposta.
        $p = wa_fluxo_proximo($grafo, $card, '', $ctx);

        if ($p['motivo'] === 'proximo') {
            $card = $p['card'];
            $ctx  = $p['ctx'];
            continue;
        }

        if ($p['motivo'] === 'ir_para_fluxo') {
            $destino = (string) ($grafo['cards'][$card]['config']['fluxo'] ?? '');
            if ($fluxos === null) $fluxos = wa_fluxos_ativos();
            if ($fluxos === null) {
                error_log('wa_fluxo_percorre: lista de fluxos indisponivel no salto | ' . $wa_id);
                return 'grafo_indisponivel';
            }
            $alvo = null;
            foreach ($fluxos as $f) {
                if ((string) ($f['id'] ?? '') === $destino) { $alvo = $f; break; }
            }
            if ($alvo === null) {
                error_log('wa_fluxo_percorre: salto para fluxo ausente, desligado ou nao publicado: ' . $destino . ' | ' . $wa_id);
                wa_entrega_humana($wa_id, 'O fluxo mandou a conversa para outro fluxo que não está no ar. Entregue para atendimento humano.');
                return 'card_perdido';
            }
            $novo = wa_fluxo_grafo((string) $alvo['versao_atual']);
            if ($novo === null) {
                error_log('wa_fluxo_percorre: grafo do fluxo de destino indisponivel | ' . $wa_id);
                return 'grafo_indisponivel';
            }
            $inicial = (string) ($novo['inicial'] ?? '');
            if ($inicial === '' || !isset($novo['cards'][$inicial])) {
                error_log('wa_fluxo_percorre: fluxo de destino sem card inicial | ' . $wa_id);
                wa_entrega_humana($wa_id, 'O fluxo de destino está sem card inicial. Entregue para atendimento humano.');
                return 'card_perdido';
            }
            // Entrar em outro fluxo e uma entrada: carimba a versao DELE.
            $grafo     = $novo;
            $versao_id = (string) $alvo['versao_atual'];
            $estampar  = true;
            $card      = $inicial;
            $ctx['tentativas'] = 0;
            continue;
        }

        // Sem proximo valido e sem terminal: o grafo acabou no meio (card que
        // nao espera, sem proximo que exista). A conversa encerra, igual a um
        // card `fim` - mas sem nenhuma despedida ter sido desenhada, e nenhum
        // relogio alcanca conversa encerrada. A NOTA e o unico aviso: a ficha
        // e o que a Lais le, e o card fim de proposito nao deixa nota nenhuma,
        // entao a nota aqui significa sempre "o desenho do fluxo tem furo".
        error_log('wa_fluxo_percorre: card ' . $card . ' sem proximo (' . $p['motivo'] . '), encerrando | ' . $wa_id);
        wa_conversa_set($wa_id, $grava + ['estado' => 'encerrado']);
        wa_nota_lead($wa_id, 'O fluxo acabou no card "'
            . (string) ($grafo['cards'][$card]['titulo'] ?? $card)
            . '", que não leva a nenhum outro card. A conversa foi encerrada sem despedida.');
        return 'fluxo';
    }
}
