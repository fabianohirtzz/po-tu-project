<?php
/* ============================================================
   O simulador do fluxo: percorre o grafo do RASCUNHO e devolve, passo a
   passo, o que o cliente receberia - sem mandar mensagem para ninguem, sem
   gravar linha nenhuma e sem tocar o funil.

   Ele roda o MOTOR DE VERDADE (wa_fluxo_executa + wa_fluxo_proximo) e as
   funcoes wa_send_* reais atras de um transporte falso. E por isso que ele
   enxerga os limites da Cloud API e as recusas exatas que a Meta devolveria:
   rotulo de botao acima de 20 caracteres, mais de 3 botoes, corpo acima de
   1024. Um simulador que reimplementasse o envio aprovaria rascunho que a
   Meta recusa, que e justamente o que ele existe para evitar.

   Tres diferencas deliberadas em relacao a producao, e as tres aparecem na
   tela como aviso:

   1) Le o RASCUNHO, nao o retrato publicado. E a unica excecao a regra de o
      motor so ler po_wa_fluxo_versoes.grafo, e existe porque o pedido e
      justamente testar antes de publicar. A montagem e a MESMA funcao do
      Publicar (wa_fluxo_grafo_de): nao ha segunda montagem.
   2) No salto `ir_para_fluxo`, ignora `ativo`. Em producao a regra 11 bloqueia
      saltar para fluxo desligado, e hoje os dois estao desligados: sem essa
      diferenca a cliente nunca alcancaria a Lista de espera no simulador.
   3) Nao persiste nada. A conversa simulada vive no navegador e volta inteira
      em cada passo.

   Este arquivo NUNCA requer o wa-motor.php: a seta e db/send/po-data <-
   fluxo <- sim/motor <- timeout. E nao usa o lacador que mora no motor, que e
   casado com a gravacao da conversa e a nota na ficha - ou seja, com a
   persistencia. O laco aqui e o mesmo DESENHO, reescrito sem ela.

   Os nomes das funcoes de gravacao nao aparecem neste arquivo nem em
   comentario, de proposito: a trava de fonte do teste e busca de substring,
   que e o que a torna impossivel de burlar.
============================================================ */

require_once __DIR__ . '/wa-fluxo.php';
require_once __DIR__ . '/wa-fone.php';

/* Destino falso. Precisa ser um numero que passe por wa_e164, senao as
   funcoes de envio reais recusam tudo antes de montar o payload e o
   simulador nao mostraria nada. Faixa de teste do repositorio publico. */
const WA_SIM_FONE = '5548900000001';

/* O mesmo teto do motor, lido dele. Um numero proprio aqui seria um segundo
   teto, e eles desandariam separados. */
const WA_SIM_MAX_PASSOS = WA_FLUXO_MAX_PASSOS;

/* Tetos da Cloud API que NENHUMA funcao de envio confere do nosso lado: corpo
   de texto e legenda de documento. Acima deles a Meta devolve 400 e o cliente
   nao recebe nada, em silencio - e e esse silencio que o simulador precisa
   quebrar. Contados em CARACTERES (mb_strlen), nunca em bytes: 900 letras
   acentuadas seriam recusadas a toa.

   Pura. Devolve a frase do motivo, ou null quando o payload passa. */
const WA_SIM_MAX_TEXTO  = 4096;
const WA_SIM_MAX_LEGENDA = 1024;

function wa_sim_recusa_meta($p) {
    if (!is_array($p)) return null;
    $tipo = (string) ($p['type'] ?? '');
    if ($tipo === 'text') {
        $n = mb_strlen((string) ($p['text']['body'] ?? ''), 'UTF-8');
        if ($n > WA_SIM_MAX_TEXTO) {
            return 'texto de ' . $n . ' caracteres: a Meta recusa acima de ' . WA_SIM_MAX_TEXTO . ' e nada chega ao cliente';
        }
    }
    if ($tipo === 'document') {
        $n = mb_strlen((string) ($p['document']['caption'] ?? ''), 'UTF-8');
        if ($n > WA_SIM_MAX_LEGENDA) {
            return 'legenda de ' . $n . ' caracteres: a Meta recusa acima de ' . WA_SIM_MAX_LEGENDA . ' e nada chega ao cliente';
        }
    }
    return null;
}

/* Instala o transporte falso e as dependencias que nao escrevem, e devolve o
   coletor.

   `insert` e `update` registram a INTENCAO e nao executam: o `marca` precisa
   ver `update` dar certo para seguir (ele trata falha de gravacao como "andou,
   mas registre"), e o registro de saida chama `insert` em todo envio. Devolver
   erro ali mostraria na tela um defeito que nao existe.

   `select`/`select_estrito` devolvem [] - lista vazia, nunca null: null quer
   dizer "o banco nao respondeu", e o `marca` entao deixa de gravar interesses
   para nao apagar o que estava la, o que apareceria como aviso de erro.

   `roteiros` NAO e trocada de proposito: o catalogo que a cliente precisa ver
   e o real. */
function wa_sim_deps() {
    $col = new stdClass();
    $col->envios   = [];   // payloads decodificados, na ordem em que a Meta os receberia
    $col->escritas = [];   // intencoes de escrita, todas com feito=false
    $n = 0;
    /* O ultimo coletor instalado fica alcancavel pelo nome, no mesmo porte de
       $GLOBALS['WA_DEPS'] e $GLOBALS['WA_TRANSPORT']: wa_sim_passo instala o
       seu proprio a cada passo (as bolhas de um passo sao so as dele, e assim
       nao existe caminho em que o simulador rode com o banco e o transporte de
       verdade), entao quem quiser inspecionar o que foi capturado le daqui. */
    $GLOBALS['WA_SIM_COL'] = $col;

    wa_set_transport(function ($url, $payload, $headers) use ($col, &$n) {
        $p = json_decode($payload, true);
        /* O transporte falso faz o papel da META, e a Meta recusa o que passa
           dos tetos dela. Isto NAO e redundante com as funcoes de envio: elas
           recusam botao demais e rotulo comprido localmente, mas `wa_send_text`
           e `wa_send_document` nao conferem tamanho nenhum (decisao da casa:
           acima do teto a Meta devolve 400 e o cliente nao recebe nada, calado).
           Sem esta recusa aqui o simulador diria SIM a um card que a Meta
           recusa - a unica assimetria que ele nao pode ter. */
        $erro = wa_sim_recusa_meta($p);
        if ($erro !== null) {
            return ['status' => 400, 'body' => json_encode(['error' => ['message' => $erro, 'code' => 131009]])];
        }
        $n++;
        $col->envios[] = $p;
        return ['status' => 200, 'body' => '{"messages":[{"id":"sim-' . $n . '"}]}'];
    });

    wa_fluxo_set_deps([
        'insert' => function ($tabela, $dados) use ($col) {
            $col->escritas[] = ['tipo' => 'insert', 'tabela' => $tabela, 'feito' => false];
            return ['id' => 'sim-insert'];
        },
        'update' => function ($tabela, $query, $dados) use ($col) {
            $col->escritas[] = ['tipo' => 'update', 'tabela' => $tabela, 'feito' => false];
            return true;
        },
        'select'         => function ($tabela, $query) { return []; },
        'select_estrito' => function ($tabela, $query) { return []; },
    ]);

    return $col;
}

/* Teto de roteiros numa lista enviada. O numero que a pessoa le vale por
   POSICAO nesta lista, e o proprio card `roteiros` corta em torno de 50 itens
   pelo teto de 4096 caracteres da Cloud API: uma lista maior vinda do navegador
   nao tem de onde ter nascido. */
const WA_SIM_CTX_MAX_ROTEIROS = 50;

/* O contexto da conversa simulada vem do NAVEGADOR e e alimentado no motor,
   entao passa por aqui antes. Pura: devolve o contexto limpo, ou null quando o
   que chegou nao e contexto (e ai quem chama recusa, sem simular nada).

   Lista FECHADA: so as 3 chaves que o motor le (`wa_fluxo_executa` e
   `wa_fluxo_proximo`). Chave a mais e descartada em silencio - ela nao tem como
   ser legitima, e o contexto nao e lugar de carregar estado de conversa
   (`estado`, `silenciado_at` e o que mais houver sao do banco, que o simulador
   nao toca). */
function wa_sim_ctx($json) {
    $json = trim((string) $json);
    if ($json === '') return [];
    $v = json_decode($json, true);
    if (!is_array($v) || (count($v) && array_keys($v) === range(0, count($v) - 1))) return null;

    $out = [];
    if (array_key_exists('roteiro_slug', $v) && $v['roteiro_slug'] !== null) {
        if (!is_string($v['roteiro_slug'])) return null;
        $out['roteiro_slug'] = $v['roteiro_slug'];
    }
    if (array_key_exists('tentativas', $v)) {
        if (!is_int($v['tentativas']) && !(is_string($v['tentativas']) && preg_match('/^[0-9]{1,3}$/', $v['tentativas']))) return null;
        $out['tentativas'] = (int) $v['tentativas'];
    }
    if (array_key_exists('roteiros_enviados', $v)) {
        $l = $v['roteiros_enviados'];
        if (!is_array($l) || array_keys($l) !== range(0, count($l) - 1)) return null;
        if (count($l) > WA_SIM_CTX_MAX_ROTEIROS) return null;
        foreach ($l as $x) if (!is_string($x)) return null;
        $out['roteiros_enviados'] = array_values($l);
    }
    return $out;
}

/* Um grafo por fluxo, montado do RASCUNHO pela mesma funcao do Publicar.

   Alem do que wa_fluxo_grafo_de devolve, cada grafo leva `sim_ativo`: o
   retrato publicado nao guarda `ativo` (nem deve), e o aviso obrigatorio do
   salto para fluxo desligado precisa do dado. */
function wa_sim_grafos($fluxos, $cards) {
    $out = [];
    foreach ((array) $fluxos as $f) {
        $id = (string) ($f['id'] ?? '');
        if ($id === '') continue;
        $meus = [];
        foreach ((array) $cards as $c) {
            if ((string) ($c['fluxo_id'] ?? '') === $id) $meus[] = $c;
        }
        $out[$id] = wa_fluxo_grafo_de($f, $meus) + ['sim_ativo' => !empty($f['ativo'])];
    }
    return $out;
}

/* Uma mensagem do envelope, lida do PAYLOAD capturado (nao do texto do card:
   e o que a Meta receberia, depois de toda truncagem e recusa das funcoes de
   envio reais). */
function wa_sim_bolha($p) {
    $base = ['tipo' => 'text', 'texto' => '', 'botoes' => [], 'itens' => [], 'arquivo' => null];
    if (!is_array($p)) return $base;
    $tipo = (string) ($p['type'] ?? '');

    if ($tipo === 'text') {
        return ['tipo' => 'text', 'texto' => (string) ($p['text']['body'] ?? '')] + $base;
    }
    if ($tipo === 'document') {
        return ['tipo' => 'document', 'texto' => (string) ($p['document']['caption'] ?? ''),
                'arquivo' => (string) ($p['document']['filename'] ?? '')] + $base;
    }
    if ($tipo === 'interactive') {
        $i = is_array($p['interactive'] ?? null) ? $p['interactive'] : [];
        $corpo = (string) ($i['body']['text'] ?? '');
        if ((string) ($i['type'] ?? '') === 'button') {
            $botoes = [];
            foreach ((array) ($i['action']['buttons'] ?? []) as $b) {
                $botoes[] = ['id' => (string) ($b['reply']['id'] ?? ''), 'rotulo' => (string) ($b['reply']['title'] ?? '')];
            }
            return ['tipo' => 'buttons', 'texto' => $corpo, 'botoes' => $botoes] + $base;
        }
        $itens = [];
        foreach ((array) ($i['action']['sections'] ?? []) as $s) {
            foreach ((array) ($s['rows'] ?? []) as $l) {
                $itens[] = ['id' => (string) ($l['id'] ?? ''), 'titulo' => (string) ($l['title'] ?? ''),
                            'descricao' => (string) ($l['description'] ?? '')];
            }
        }
        return ['tipo' => 'list', 'texto' => $corpo, 'itens' => $itens] + $base;
    }
    return $base;
}

/* Um passo da conversa simulada.

   $card_id nulo ou vazio comeca pelo card inicial do fluxo e ignora a
   resposta. $card_id preenchido e o card em que a conversa parou esperando:
   a resposta e roteada por wa_fluxo_proximo e a cadeia segue dali.

   $clicou separa CLIQUE de TEXTO DIGITADO, e essa diferenca e do motor
   (wa-motor.php: "so o clique carrega o id de uma opcao"): em card
   `botoes`/`lista` o texto digitado NAO escolhe e cai no fallback; no card
   `roteiros` e o contrario, o digitado e a unica forma de escolher, porque a
   lista sai como texto numerado. Sem isso o simulador mostraria um caminho que
   a producao nao faz.

   Devolve ['ok','fluxo_id','card_id','ctx','estado','mensagens','avisos','erro'].
   `estado`: aguardando | humano | encerrado | recusado | teto_passos.
   `ok` e `estado` sao independentes, como no motor: o card terminal entrega a
   conversa mesmo com o aviso falhado. */
function wa_sim_passo($grafos, $fluxo_id, $card_id, $resposta, $ctx, $clicou = false) {
    $grafos = (array) $grafos;
    $ctx    = (array) $ctx;
    $avisos = [];
    $col    = wa_sim_deps();

    $fim = function ($estado, $ok, $card, $erro = null) use (&$fluxo_id, &$ctx, &$avisos, $col) {
        $mensagens = [];
        foreach ($col->envios as $p) $mensagens[] = wa_sim_bolha($p);
        return ['ok' => $ok, 'fluxo_id' => (string) $fluxo_id, 'card_id' => $card, 'ctx' => $ctx,
                'estado' => $estado, 'mensagens' => $mensagens, 'avisos' => $avisos, 'erro' => $erro];
    };

    $grafo = $grafos[(string) $fluxo_id] ?? null;
    if (!is_array($grafo)) {
        return $fim('recusado', false, null, 'Este fluxo não está no rascunho: ' . (string) $fluxo_id);
    }

    $card = (string) ($card_id ?? '');
    if ($card === '') {
        $card = (string) ($grafo['inicial'] ?? '');
        if ($card === '' || !isset($grafo['cards'][$card])) {
            return $fim('recusado', false, null, 'Este fluxo está sem card inicial.');
        }
        /* PONTO 1, igual ao da producao: quando a conversa NASCE com uma frase
           (a cliente escreveu a primeira mensagem em vez de so abrir a
           simulacao), os atalhos do card inicial decidem por onde entrar, e a
           saudacao do atalho sai ANTES do salto. Frase vazia - que e como a
           tela abre a conversa - nao dispara nada: wa_fluxo_atalho devolve
           null para texto vazio.

           Mandar a saudacao pela funcao de envio REAL, e nao fabricar a
           bolha, e o que mantem valendo aqui o teto de 4096 caracteres que a
           Meta aplica recusando a mensagem inteira em silencio. */
        $at = wa_fluxo_atalho($grafo, $grafo['cards'][$card], (string) $resposta, $ctx);
        if ($at !== null) {
            $saud = trim((string) $at['saudacao']);
            if ($saud !== '') {
                $envio = wa_fl_call('send_text', WA_SIM_FONE, $saud);
                if (empty($envio['ok'])) {
                    return $fim('recusado', false, $card, (string) ($envio['erro'] ?? 'a saudação deste atalho não sairia'));
                }
            }
            if ($at['roteiro_slug'] !== null) $ctx['roteiro_slug'] = $at['roteiro_slug'];
            if ($at['destino_nome'] !== null) $ctx['destino_nome'] = $at['destino_nome'];
            $card = (string) $at['card'];
        }
    } elseif (!isset($grafo['cards'][$card])) {
        /* Mesmo desfecho da producao (wa_processar: card_atual que nao existe
           mais na versao entrega para a humana), e nao um 500. */
        $avisos[] = 'O card em que a conversa estava não existe neste fluxo. No ar, a conversa iria para você.';
        return $fim('humano', false, null, 'card fora do grafo: ' . $card);
    } elseif (in_array($grafo['cards'][$card]['tipo'] ?? '', WA_CARD_ESPERA, true)) {
        /* Card que espera: a resposta decide para onde ir. Digitado so conta
           no `roteiros`. */
        $vale = ($clicou || ($grafo['cards'][$card]['tipo'] ?? '') === 'roteiros') ? (string) $resposta : '';
        $p = wa_fluxo_proximo($grafo, $card, $vale, $ctx, (string) $resposta);
        $ctx = $p['ctx'];
        if ($p['card'] === null) {
            $avisos[] = 'Este card não tem saída para essa resposta. No ar, a conversa iria para você.';
            return $fim('humano', false, null, 'card sem saida: ' . $p['motivo']);
        }
        $card = (string) $p['card'];
    }
    /* Card que NAO espera e veio preenchido: a producao o reexecuta (cadeia
       interrompida), e a resposta nao decide nada. A cadeia abaixo faz isso. */

    $passos = 0;
    while (true) {
        if ($passos >= WA_SIM_MAX_PASSOS) {
            $avisos[] = 'O fluxo encadeou ' . WA_SIM_MAX_PASSOS . ' cards sem nunca esperar o cliente. No ar, a conversa seria interrompida e entregue para você.';
            return $fim('teto_passos', false, $card, 'teto de ' . WA_SIM_MAX_PASSOS . ' passos');
        }
        $passos++;

        $r   = wa_fluxo_executa($grafo, $card, WA_SIM_FONE, $ctx, '');
        $ctx = $r['ctx'];

        // terminal antes de ok, como no motor: a conversa vai para onde o card
        // manda mesmo que o aviso nao tenha saido.
        if ($r['terminal'] !== null) {
            return $fim($r['terminal'] === 'humano' ? 'humano' : 'encerrado', (bool) $r['ok'], $card, $r['erro']);
        }
        if (!$r['ok']) {
            return $fim('recusado', false, $card, $r['erro']);
        }
        if ($r['espera']) {
            return $fim('aguardando', true, $card, $r['erro']);
        }

        $p = wa_fluxo_proximo($grafo, $card, '', $ctx, '');

        if ($p['motivo'] === 'proximo') {
            $card = (string) $p['card'];
            $ctx  = $p['ctx'];
            continue;
        }

        if ($p['motivo'] === 'ir_para_fluxo') {
            $destino = (string) ($grafo['cards'][$card]['config']['fluxo'] ?? '');
            $novo = $grafos[$destino] ?? null;
            if (!is_array($novo)) {
                return $fim('recusado', false, $card, 'Este card manda a conversa para um fluxo que não está no rascunho: ' . $destino);
            }
            /* A diferenca 2: o simulador salta para fluxo desligado, e diz. */
            if (empty($novo['sim_ativo'])) {
                $avisos[] = 'No ar este salto não aconteceria: o fluxo "' . (string) ($novo['nome'] ?? $destino) . '" está desligado. Aqui o simulador segue em frente para você poder ver o caminho inteiro.';
            }
            $inicial = (string) ($novo['inicial'] ?? '');
            if ($inicial === '' || !isset($novo['cards'][$inicial])) {
                $avisos[] = 'O fluxo de destino está sem card inicial. No ar, a conversa iria para você.';
                return $fim('humano', false, null, 'fluxo de destino sem card inicial: ' . $destino);
            }
            $grafo     = $novo;
            $fluxo_id  = $destino;
            $card      = $inicial;
            $ctx['tentativas'] = 0;
            continue;
        }

        /* Sem proximo e sem terminal: o grafo acabou no meio. A producao
           encerra a conversa e deixa nota na ficha, que e o aviso de que o
           desenho tem furo - aqui o furo aparece na propria tela. */
        $avisos[] = 'O fluxo acabou no card "' . (string) ($grafo['cards'][$card]['titulo'] ?? $card)
                  . '", que não leva a nenhum outro. A conversa encerra sem despedida.';
        return $fim('encerrado', true, $card, null);
    }
}
