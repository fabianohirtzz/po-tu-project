<?php
/* ============================================================
   Lembrete (20h) e encerramento da conversa que espera o cliente.

   So mexe em conversa que esta ESPERANDO resposta (estado 'aguardando').
   Conversa entregue a humana, encerrada, ou parada por falha de envio
   ('novo' com card_atual: ninguem esta sendo aguardado) nunca e tocada:
   encerrar atendimento em andamento por relogio seria pior do que nao ter
   cron nenhum.

   Roda de hora em hora, sem ninguem olhando. Um defeito aqui nao aparece
   numa tela: aparece como "a conversa parou" dias depois.
============================================================ */

require_once __DIR__ . '/wa-db.php';
require_once __DIR__ . '/wa-send.php';
require_once __DIR__ . '/wa-motor.php';

/* O lembrete sai com 20h de espera, e NAO com 24h, por causa da janela de
   atendimento da Cloud API: fora das 24h depois da ultima mensagem do
   cliente a Meta recusa tudo que nao seja template aprovado, e este plano
   nao manda template nenhum. O cron roda de hora em hora; com o lembrete em
   24h a varredura que o dispararia cairia na borda da janela ou depois dela,
   o envio seria recusado, lembrete_at nunca seria carimbado (so se carimba
   quando saiu), e a conversa seria encerrada em silencio sem nunca ter sido
   lembrada. Com 20h sobram 4 horas para o cron acertar a janela. */
const WA_HORAS_LEMBRETE = 20;
const WA_HORAS_JANELA   = 24;
const WA_HORAS_ENCERRA  = 24; // contadas DEPOIS do lembrete

// Minimo pra uma WA_CRON_KEY ser levada a serio. Abaixo disso conta como
// "nao configurada": chave ausente ou fraca nunca autoriza, nem contra um
// pedido tambem vazio - hash_equals('', '') === true, e e exatamente o
// defeito que deixava wa-cron.php aberto pela web (revisao: Critical).
const WA_CRON_KEY_MIN = 16;

function wa_timeout_set_deps($d) { $GLOBALS['WA_TO_DEPS'] = array_merge($GLOBALS['WA_TO_DEPS'] ?? [], $d); }
function wa_to_call($nome, ...$args) {
    $padrao = ['select' => 'wa_db_select', 'update' => 'wa_db_update', 'send_text' => 'wa_send_text',
               'grafo' => 'wa_fluxo_grafo', 'executa' => 'wa_fluxo_executa'];
    $f = $GLOBALS['WA_TO_DEPS'][$nome] ?? $padrao[$nome];
    return call_user_func_array($f, $args);
}

/* Segredo nao configurado (ou curto demais pra contar como segredo) e
   ausencia de permissao, nunca permissao - por isso a checagem de
   tamanho vem ANTES do hash_equals, nao depois. Isolada em funcao pura
   (sem $_GET, sem rede) pra dar pra testar sem tocar em nada. Usada por
   wa-cron.php, que fica no docroot e por isso precisa de segredo na
   query quando chamado pela web. */
function wa_cron_autorizado($chave_configurada, $chave_recebida) {
    $chave_configurada = (string) $chave_configurada;
    if (strlen($chave_configurada) < WA_CRON_KEY_MIN) {
        error_log('wa-cron: chamado pela web sem WA_CRON_KEY configurada (ou chave curta demais)');
        return false;
    }
    return hash_equals($chave_configurada, (string) $chave_recebida);
}

/* Encerra a conversa e carimba o lead como perdido. E o UNICO ponto de
   encerramento da varredura, e o carimbo mora aqui de proposito: o publico
   "perdeu so pela data" da transmissao (wa_camp_perdeu_data) seleciona
   status='perdido', entao um caminho de encerramento que esquecesse o carimbo
   faria aquele publico murchar em silencio. */
function wa_to_encerra($wa, $campos = []) {
    wa_to_call('update', 'po_wa_conversas', 'wa_id=eq.' . rawurlencode($wa),
        $campos + ['estado' => 'encerrado']);
    wa_to_call('update', 'po_leads', 'wa_id=eq.' . rawurlencode($wa), ['status' => 'perdido']);
}

/* Executa o card de "sem resposta" e fecha a conversa. So chamada DENTRO da
   janela de 24h. Devolve true quando a conversa foi resolvida (encerrada ou
   entregue a humana) e false quando deve ficar como esta para a proxima
   varredura (envio que falhou sem destino terminal: a janela ainda esta
   aberta, vale tentar de novo). Executa UM card, sem seguir o `proximo`:
   seguir poderia chegar a um card que espera. */
function wa_to_sem_resposta($c, $grafo, $nome) {
    $wa    = $c['wa_id'];
    $atual = (string) $c['card_atual'];
    $dest  = wa_fluxo_sem_resposta($grafo, $atual);
    if ($dest === null) { wa_to_encerra($wa); return true; }

    // O cron executa sem ninguem olhando e NAO confia na validacao de quem
    // publicou: card que espera (botoes, lista) mandaria uma pergunta de
    // madrugada e agendaria uma espera dentro de outra.
    if (!in_array($grafo['cards'][$dest]['tipo'] ?? '', WA_SEM_RESPOSTA_TIPOS, true)) {
        error_log('wa_varre_timeouts: sem_resposta de ' . $atual . ' aponta para card de tipo nao permitido (' . $dest . '), encerrando sem executar | ' . $wa);
        wa_to_encerra($wa);
        return true;
    }

    $ctx = ['roteiro_slug' => $c['roteiro_slug'] ?? null, 'tentativas' => (int) ($c['tentativas'] ?? 0)];
    $r = wa_to_call('executa', $grafo, $dest, $wa, $ctx, $nome);

    if (($r['terminal'] ?? null) === 'humano') {
        // ok diz se o aviso saiu, terminal diz para onde a conversa vai: a
        // humana recebe de qualquer jeito. Nao e encerramento, entao o lead
        // nao e carimbado perdido: quem decide e a pessoa que assume.
        wa_to_call('update', 'po_wa_conversas', 'wa_id=eq.' . rawurlencode($wa), [
            'estado' => 'humano', 'card_atual' => $dest, 'lembrete_at' => null, 'silenciado_at' => gmdate('c'),
        ]);
        wa_nota_lead($wa, 'O cliente não respondeu a tempo e o fluxo entregou a conversa para atendimento humano.');
        return true;
    }
    if (($r['terminal'] ?? null) === 'fim' || !empty($r['ok'])) {
        wa_to_encerra($wa, ['card_atual' => $dest, 'lembrete_at' => null]);
        return true;
    }
    error_log('wa_varre_timeouts: sem_resposta nao saiu (' . ($r['erro'] ?? '?') . '), tenta de novo na proxima varredura | ' . $wa);
    return false;
}

function wa_varre_timeouts($agora = null) {
    $agora = $agora ?: time();
    $lembretes = 0; $encerrados = 0; $fora_da_janela = 0;
    $resultado = function () use (&$lembretes, &$encerrados, &$fora_da_janela) {
        return ['lembretes' => $lembretes, 'encerrados' => $encerrados, 'fora_da_janela' => $fora_da_janela];
    };

    // Conversa esperando o cliente = estado 'aguardando'. NAO varrer 'novo':
    // 'novo' com card_atual e uma cadeia parada por falha de envio, onde
    // ninguem esta sendo aguardado, e lembrete ali cobraria resposta de uma
    // pergunta que o cliente nunca recebeu. Tambem nao varrer so por
    // aguardando_desde, que numa linha 'novo' pode ficar antigo.
    //
    // order= junto do limit: sem ordem definida, se um dia passar de 500
    // conversas abertas, o Postgrest devolve um recorte qualquer e as mais
    // antigas poderiam nunca ser varridas. As mais antigas primeiro sao
    // justamente as que estao esperando ha mais tempo (conhecido 8).
    $abertas = wa_to_call('select', 'po_wa_conversas',
        'estado=eq.aguardando&order=aguardando_desde.asc&limit=500');
    if (!is_array($abertas)) return $resultado();

    $grafos = [];     // cache por versao dentro da varredura (null tambem: "nao sei")
    $catalogo = null;

    foreach ($abertas as $c) {
        // Guarda dupla: mesmo que a query mude, so 'aguardando' com ponto marcado entra.
        if (($c['estado'] ?? '') !== 'aguardando' || empty($c['card_atual'])) continue;

        $desde = strtotime($c['aguardando_desde'] ?? '') ?: null;
        if (!$desde) continue;
        $wa = $c['wa_id'];
        $lembrete = !empty($c['lembrete_at']) ? strtotime($c['lembrete_at']) : null;
        $idade = $agora - $desde;

        if ($lembrete) {
            if (($agora - $lembrete) < WA_HORAS_ENCERRA * 3600) continue;
        } elseif ($idade < WA_HORAS_LEMBRETE * 3600) {
            continue;
        }

        // GUARDA DA JANELA, antes de qualquer envio: fora das 24h a Meta
        // recusa tudo que nao e template. Nao tenta, encerra. Conta como
        // "fora da janela" quando havia algo a fazer que se perdeu (o
        // lembrete que nunca saiu).
        if ($idade >= WA_HORAS_JANELA * 3600) {
            wa_to_encerra($wa);
            $encerrados++;
            if (!$lembrete) $fora_da_janela++;
            continue;
        }

        // Dentro da janela: precisa do card para saber o que fazer.
        $versao = (string) ($c['fluxo_versao_id'] ?? '');
        if ($versao === '') {
            error_log('wa_varre_timeouts: conversa aguardando sem fluxo_versao_id, nada feito | ' . $wa);
            continue;
        }
        if (!array_key_exists($versao, $grafos)) $grafos[$versao] = wa_to_call('grafo', $versao);
        $grafo = $grafos[$versao];
        // null = "nao sei" (versao inexistente OU banco fora do ar): nao envia,
        // nao avanca estado, deixa para a proxima varredura. Encerrar aqui
        // transformaria uma oscilacao do banco em lead perdido.
        if ($grafo === null) {
            error_log('wa_varre_timeouts: grafo da versao ' . $versao . ' indisponivel, fica para a proxima varredura | ' . $wa);
            continue;
        }
        $card = $grafo['cards'][$c['card_atual']] ?? null;
        if (!is_array($card)) {
            error_log('wa_varre_timeouts: card ' . $c['card_atual'] . ' nao existe na versao ' . $versao . ' | ' . $wa);
            continue;
        }

        // Nome de verdade, vindo do lead - sem isto {nome} some do texto e
        // sobra pontuacao orfa ("Oi , tudo bem?") na mensagem do cliente.
        $lead = wa_to_call('select', 'po_leads', 'wa_id=eq.' . rawurlencode($wa));
        $nome = $lead[0]['nome'] ?? '';

        if (!$lembrete) {
            // Lembrete do CARD: cada card decide se lembra e o que diz.
            $msg = '';
            if (!empty($card['lembrete_ativo'])) {
                if ($catalogo === null) { $cat = wa_fl_call('roteiros'); $catalogo = is_array($cat) ? $cat : []; }
                $slug = (string) ($c['roteiro_slug'] ?? '');
                $roteiro = $slug !== '' ? wa_acha_roteiro($slug, $catalogo) : null;
                $msg = trim(wa_fluxo_texto((string) ($card['lembrete_texto'] ?? ''), wa_fluxo_vars_do_roteiro($roteiro, $nome)));
            }
            if ($msg !== '') {
                $envio = wa_to_call('send_text', $wa, $msg);
                // O lembrete tambem e mensagem do robo: entra no log pelo
                // mesmo caminho do resto, senao some da aba de conversa e o
                // eco dele viraria handoff.
                wa_registra_saida($wa, $envio, 'text', $msg);
                // So grava lembrete_at se o envio realmente saiu. Falha aqui so
                // loga e deixa a conversa como esta, pro cron tentar de novo.
                if (!empty($envio['ok'])) {
                    wa_to_call('update', 'po_wa_conversas', 'wa_id=eq.' . rawurlencode($wa),
                        ['lembrete_at' => gmdate('c', $agora)]);
                    $lembretes++;
                } else {
                    error_log('wa_varre_timeouts: falha ao enviar lembrete, tenta de novo na proxima varredura | wa_id ' . $wa);
                }
                continue;
            }
            // Card sem lembrete: nao ha o que lembrar, e o "sem resposta" vale
            // no momento em que o lembrete sairia (ainda dentro da janela).
        }

        if (wa_to_sem_resposta($c, $grafo, $nome)) $encerrados++;
    }

    return $resultado();
}
