<?php
require __DIR__ . '/../lib/wa-timeout.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

// O codigo loga as recusas de proposito (error_log); aqui isso vai para um arquivo, nao para a saida do teste.
ini_set('error_log', sys_get_temp_dir() . '/po-test-wa-timeout.log');

$agora = strtotime('2026-09-11 12:00:00 UTC');
function h($agora, $horas) { return gmdate('c', (int) ($agora - $horas * 3600)); }

ok(WA_HORAS_LEMBRETE === 20, 'o lembrete sai em 20h, com 4h de folga antes da borda da janela de 24h');
ok(WA_HORAS_JANELA === 24, 'a janela de atendimento e de 24h');
ok(WA_HORAS_ENCERRA === 24, 'o encerramento vem 24h depois do lembrete');

/* ---------- o fluxo de mentira ---------- */
$GRAFO = ['id' => 'f1', 'nome' => 'Teste', 'inicial' => 'c_bot', 'humano' => 'c_hum', 'cards' => [
    // espera, lembra, sem destino de "sem resposta"
    'c_bot'  => ['id'=>'c_bot','tipo'=>'botoes','texto'=>'Quer ver?','opcoes'=>[],'config'=>[],'proximo'=>null,'fallback'=>null,
                 'sem_resposta'=>null,'lembrete_ativo'=>true,'lembrete_texto'=>'Oi {nome}, viu o roteiro da {roteiro}?'],
    // espera, lembrete desligado, sem_resposta para um card `fim`
    'c_off'  => ['id'=>'c_off','tipo'=>'botoes','texto'=>'Quer ver?','opcoes'=>[],'config'=>[],'proximo'=>null,'fallback'=>null,
                 'sem_resposta'=>'c_txt','lembrete_ativo'=>false,'lembrete_texto'=>null],
    // espera, lembrete desligado, sem_resposta vazio
    'c_seco' => ['id'=>'c_seco','tipo'=>'botoes','texto'=>'Quer ver?','opcoes'=>[],'config'=>[],'proximo'=>null,'fallback'=>null,
                 'sem_resposta'=>null,'lembrete_ativo'=>false,'lembrete_texto'=>null],
    // espera, lembrete desligado, sem_resposta APONTA PARA CARD QUE ESPERA (publicacao corrompida)
    'c_ruim' => ['id'=>'c_ruim','tipo'=>'botoes','texto'=>'Quer ver?','opcoes'=>[],'config'=>[],'proximo'=>null,'fallback'=>null,
                 'sem_resposta'=>'c_bot','lembrete_ativo'=>false,'lembrete_texto'=>null],
    // espera, lembrete desligado, sem_resposta para a humana
    'c_p_hum'=> ['id'=>'c_p_hum','tipo'=>'botoes','texto'=>'Quer ver?','opcoes'=>[],'config'=>[],'proximo'=>null,'fallback'=>null,
                 'sem_resposta'=>'c_hum','lembrete_ativo'=>false,'lembrete_texto'=>null],
    // espera, lembrete desligado, sem_resposta para um card de TEXTO (que nao e terminal)
    'c_off_t'=> ['id'=>'c_off_t','tipo'=>'botoes','texto'=>'Quer ver?','opcoes'=>[],'config'=>[],'proximo'=>null,'fallback'=>null,
                 'sem_resposta'=>'c_aviso','lembrete_ativo'=>false,'lembrete_texto'=>null],
    'c_aviso'=> ['id'=>'c_aviso','tipo'=>'texto','texto'=>'Qualquer coisa, estou por aqui.','opcoes'=>[],'config'=>[],'proximo'=>null,'fallback'=>null,
                 'sem_resposta'=>null,'lembrete_ativo'=>false,'lembrete_texto'=>null],
    'c_txt'  => ['id'=>'c_txt','tipo'=>'fim','texto'=>'Vou encerrar por aqui, {nome}.','opcoes'=>[],'config'=>[],'proximo'=>null,'fallback'=>null,
                 'sem_resposta'=>null,'lembrete_ativo'=>false,'lembrete_texto'=>null],
    'c_hum'  => ['id'=>'c_hum','tipo'=>'humano','texto'=>'Vou passar para a equipe.','opcoes'=>[],'config'=>[],'proximo'=>null,'fallback'=>null,
                 'sem_resposta'=>null,'lembrete_ativo'=>false,'lembrete_texto'=>null],
]];
$ROTEIROS = [['slug' => 'turquia', 'titulo' => 'Turquia com Antalia', 'periodo' => '10/2027', 'pdf_url' => null]];

/* ---------- o mundo de mentira ----------
   Roda uma varredura sobre as conversas dadas e devolve tudo que aconteceu.
   O select de po_wa_conversas IGNORA a query de proposito (devolve todas as
   conversas, inclusive humano/novo/encerrado) para provar que quem barra
   essas e a guarda no laco, nao so o filtro que iria na query real. */
function varre($conversas, $agora, $opt = []) {
    global $GRAFO, $ROTEIROS;
    $M = ['envios' => [], 'conv' => [], 'lead' => [], 'log' => [], 'query' => null, 'grafo_chamado' => 0, 'executa' => []];
    $leads = [['wa_id' => '+5548900000002', 'nome' => 'Marta']];
    $grafo_dep = array_key_exists('grafo', $opt) ? $opt['grafo'] : $GRAFO;
    $envio_ok = $opt['envio_ok'] ?? true;

    wa_motor_set_deps([
        'select' => function ($t, $q) { return []; },
        'insert' => function ($t, $linha) use (&$M) { $M['log'][] = [$t, $linha]; return $linha; },
        'update' => function ($t, $q, $c) { return true; },
    ]);
    wa_fluxo_set_deps([
        'roteiros'  => function () use ($ROTEIROS) { return $ROTEIROS; },
        'send_text' => function ($para, $txt) use (&$M, $envio_ok) {
            $M['envios'][] = [$para, 'executa', $txt];
            return $envio_ok ? ['ok' => true, 'wamid' => 'w' . count($M['envios']), 'erro' => null]
                             : ['ok' => false, 'wamid' => null, 'erro' => 'falhou de proposito'];
        },
    ]);
    $executa_real = 'wa_fluxo_executa';
    wa_timeout_set_deps([
        'select' => function ($t, $q) use (&$M, $conversas, $leads) {
            if ($t === 'po_wa_conversas') { $M['query'] = $q; return $conversas; }
            if ($t === 'po_leads') {
                preg_match('/wa_id=eq\.([^&]+)/', $q, $m);
                $wa = isset($m[1]) ? urldecode($m[1]) : null;
                return array_values(array_filter($leads, fn($l) => $l['wa_id'] === $wa));
            }
            return [];
        },
        'update' => function ($t, $q, $c) use (&$M) {
            preg_match('/wa_id=eq\.([^&]+)/', $q, $m);
            $wa = isset($m[1]) ? urldecode($m[1]) : '?';
            if ($t === 'po_wa_conversas') $M['conv'][$wa][] = $c;
            if ($t === 'po_leads') $M['lead'][$wa][] = $c;
            return true;
        },
        'send_text' => function ($para, $txt) use (&$M, $envio_ok) {
            $M['envios'][] = [$para, 'lembrete', $txt];
            return $envio_ok ? ['ok' => true, 'wamid' => 'w' . count($M['envios']), 'erro' => null]
                             : ['ok' => false, 'wamid' => null, 'erro' => 'falhou de proposito'];
        },
        'grafo' => function ($v) use (&$M, $grafo_dep) { $M['grafo_chamado']++; return $grafo_dep; },
        'executa' => function ($g, $card, $wa, $ctx, $nome) use (&$M, $executa_real, $opt) {
            $M['executa'][] = $card;
            if (isset($opt['executa'])) return call_user_func($opt['executa'], $g, $card, $wa, $ctx, $nome);
            return $executa_real($g, $card, $wa, $ctx, $nome);
        },
    ]);
    $M['r'] = wa_varre_timeouts($agora);
    return $M;
}
function conv($wa, $estado, $card, $desde_h, $lembrete_h = null, $extra = []) {
    global $agora;
    return array_merge(['wa_id' => $wa, 'estado' => $estado, 'card_atual' => $card, 'fluxo_versao_id' => 'v1',
        'aguardando_desde' => h($agora, $desde_h), 'lembrete_at' => $lembrete_h === null ? null : h($agora, $lembrete_h),
        'roteiro_slug' => 'turquia', 'tentativas' => 0], $extra);
}
$W = '+5548900000002';

/* 1. A query seleciona por aguardando, nao pelos estados antigos */
$M = varre([], $agora);
ok(strpos($M['query'], 'estado=eq.aguardando') !== false, 'varre por aguardando');
ok(strpos($M['query'], 'enviado_roteiro') === false, 'nao varre mais os estados antigos');
ok(strpos($M['query'], 'order=aguardando_desde.asc&limit=500') !== false, 'ordem e limite preservados');
ok($M['r'] === ['lembretes' => 0, 'encerrados' => 0, 'fora_da_janela' => 0], 'retorno ampliado, zerado');

/* 2. O texto do lembrete vem do CARD, com as variaveis substituidas */
$M = varre([conv($W, 'aguardando', 'c_bot', 21)], $agora);
ok($M['r']['lembretes'] === 1, 'lembrete as 21h (deu: ' . $M['r']['lembretes'] . ')');
ok($M['envios'] === [[$W, 'lembrete', 'Oi Marta, viu o roteiro da Turquia com Antalia?']],
    'lembrete_texto do card, com nome e roteiro (veio: ' . json_encode($M['envios']) . ')');
ok(count($M['conv'][$W]) === 1 && !empty($M['conv'][$W][0]['lembrete_at']), 'grava lembrete_at quando o envio saiu');
ok(count($M['log']) === 1 && $M['log'][0][0] === 'po_wa_mensagens' && $M['log'][0][1]['autor'] === 'robo' && $M['log'][0][1]['direcao'] === 'out',
    'o lembrete entra no log de saida do robo (senao o eco virava handoff)');
ok($M['lead'] === [], 'lembrete nao mexe no lead');

/* 3. Cedo demais: nada, nem na borda de baixo */
$M = varre([conv($W, 'aguardando', 'c_bot', 2), conv('+5548900000003', 'aguardando', 'c_bot', 19.9)], $agora);
ok($M['envios'] === [] && $M['conv'] === [], 'antes das 20h nao incomoda');
$M = varre([conv($W, 'aguardando', 'c_bot', 20)], $agora);
ok($M['r']['lembretes'] === 1, 'em 20h exatas o lembrete sai');

// Ja levou lembrete ha pouco: espera mais, esteja a janela aberta ou nao
$M = varre([conv($W, 'aguardando', 'c_bot', 22, 2), conv('+5548900000003', 'aguardando', 'c_bot', 27, 2)], $agora);
ok($M['envios'] === [] && $M['conv'] === [] && $M['lead'] === [], 'lembrete enviado ha 2h: a conversa espera mais, sem novo lembrete nem encerramento');

/* 4. Card com lembrete desligado nao manda lembrete */
$M = varre([conv($W, 'aguardando', 'c_seco', 21)], $agora);
ok($M['r']['lembretes'] === 0 && $M['envios'] === [], 'card com lembrete desligado nao manda lembrete');

/* 5. So grava lembrete_at se o envio saiu */
$M = varre([conv($W, 'aguardando', 'c_bot', 21)], $agora, ['envio_ok' => false]);
ok($M['r']['lembretes'] === 0, 'envio falhado nao conta como lembrete');
ok($M['conv'] === [] && $M['lead'] === [], 'envio falhado nao carimba lembrete_at nem encerra');
ok($M['log'] === [], 'envio falhado nao entra no log de saida');

/* 6. CADEIA PARADA (novo + card_atual) nunca recebe lembrete nem encerra */
foreach ([2, 21, 30, 50, 300] as $horas) {
    $M = varre([conv($W, 'novo', 'c_bot', $horas), conv('+5548900000003', 'novo', 'c_seco', $horas, $horas - 1)], $agora);
    ok($M['envios'] === [] && $M['conv'] === [] && $M['lead'] === [] && $M['grafo_chamado'] === 0,
        "cadeia parada ha {$horas}h nao e tocada");
    ok($M['r'] === ['lembretes' => 0, 'encerrados' => 0, 'fora_da_janela' => 0], "cadeia parada ha {$horas}h nao conta");
}

/* 7. Humano, encerrado, qualificado: nunca tocados */
foreach (['humano', 'encerrado', 'qualificado'] as $estado) {
    $M = varre([conv($W, $estado, 'c_bot', 300), conv('+5548900000003', $estado, 'c_bot', 50, 30)], $agora);
    ok($M['envios'] === [] && $M['conv'] === [] && $M['lead'] === [], "conversa em $estado nao e tocada");
    ok($M['r']['encerrados'] === 0, "conversa em $estado nao e encerrada por relogio");
}
// aguardando sem card_atual (nao e espera de card): tambem nao
$M = varre([conv($W, 'aguardando', null, 30)], $agora);
ok($M['envios'] === [] && $M['conv'] === [], 'aguardando sem card_atual nao e tocada');

/* 8. GUARDA DA JANELA: esperando ha mais de 24h nao recebe lembrete, encerra */
$M = varre([conv($W, 'aguardando', 'c_bot', 25)], $agora);
ok($M['r']['lembretes'] === 0 && $M['r']['fora_da_janela'] === 1 && $M['r']['encerrados'] === 1, 'fora da janela nao tenta enviar e encerra');
ok($M['envios'] === [], 'nenhum envio fora da janela');
ok(($M['conv'][$W][0]['estado'] ?? '') === 'encerrado', 'conversa encerrada');
ok(($M['lead'][$W][0]['status'] ?? '') === 'perdido', 'lead carimbado perdido');
ok($M['grafo_chamado'] === 0, 'fora da janela nem consulta o fluxo');
// a borda: 24h exatas ja e fora
$M = varre([conv($W, 'aguardando', 'c_bot', 24)], $agora);
ok($M['envios'] === [] && $M['r']['fora_da_janela'] === 1, 'em 24h exatas a janela ja fechou');
// 23h59 ainda e dentro
$M = varre([conv($W, 'aguardando', 'c_bot', 23.9)], $agora);
ok($M['r']['lembretes'] === 1 && $M['r']['fora_da_janela'] === 0, 'em 23h54 ainda esta dentro da janela');

/* 9. CARIMBO perdido nos TRES caminhos de encerramento */
$M = varre([conv($W, 'aguardando', 'c_bot', 46, 26)], $agora);                  // normal: lembrete + 24h
ok($M['r']['encerrados'] === 1 && $M['r']['fora_da_janela'] === 0 && $M['envios'] === [], 'encerramento normal: encerra sem enviar nada');
ok(($M['conv'][$W][0]['estado'] ?? '') === 'encerrado', 'normal: conversa encerrada');
ok(($M['lead'][$W][0]['status'] ?? '') === 'perdido', 'normal: carimba perdido');

$M = varre([conv($W, 'aguardando', 'c_seco', 21)], $agora);                     // sem_resposta vazio (encerra seco)
ok(($M['conv'][$W][0]['estado'] ?? '') === 'encerrado' && ($M['lead'][$W][0]['status'] ?? '') === 'perdido', 'sem destino: encerra e carimba perdido');
ok($M['envios'] === [], 'sem destino: nada enviado');

$M = varre([conv($W, 'aguardando', 'c_off', 21)], $agora);                      // sem_resposta executado
ok(($M['lead'][$W][0]['status'] ?? '') === 'perdido', 'sem_resposta: carimba perdido');

$M = varre([conv($W, 'aguardando', 'c_bot', 30)], $agora);                      // fora da janela
ok(($M['lead'][$W][0]['status'] ?? '') === 'perdido', 'fora da janela: carimba perdido');

$M = varre([conv($W, 'aguardando', 'c_ruim', 21)], $agora);                     // tipo proibido
ok(($M['lead'][$W][0]['status'] ?? '') === 'perdido', 'tipo proibido: carimba perdido');

/* 10. sem_resposta: executa o card em vez de encerrar seco */
$M = varre([conv($W, 'aguardando', 'c_off', 21)], $agora);
ok($M['executa'] === ['c_txt'], 'o card de sem_resposta e executado');
ok($M['envios'] === [[$W, 'executa', 'Vou encerrar por aqui, Marta.']], 'e a mensagem sai com o nome (veio: ' . json_encode($M['envios']) . ')');
ok(($M['conv'][$W][0]['card_atual'] ?? '') === 'c_txt' && ($M['conv'][$W][0]['estado'] ?? '') === 'encerrado', 'a conversa segue para ele e encerra');
ok($M['r']['encerrados'] === 1 && $M['r']['lembretes'] === 0, 'conta como encerrada');

// card de texto que saiu: encerra, e a conversa fica no card
$M = varre([conv($W, 'aguardando', 'c_off_t', 21)], $agora);
ok(($M['conv'][$W][0]['card_atual'] ?? '') === 'c_aviso' && ($M['conv'][$W][0]['estado'] ?? '') === 'encerrado', 'card de texto enviado: encerra');
// card `fim` cujo aviso falhou: o terminal independe do envio, encerra mesmo assim
$M = varre([conv($W, 'aguardando', 'c_off', 21)], $agora, ['envio_ok' => false]);
ok(($M['conv'][$W][0]['estado'] ?? '') === 'encerrado' && ($M['lead'][$W][0]['status'] ?? '') === 'perdido', 'fim com aviso falhado: encerra e carimba');

/* 11. sem_resposta para card que ESPERA nunca e executado pelo cron */
$M = varre([conv($W, 'aguardando', 'c_ruim', 21)], $agora);
ok($M['executa'] === [] && $M['envios'] === [], 'o cron recusa executar card que espera');
ok(($M['conv'][$W][0]['estado'] ?? '') === 'encerrado', 'e encerra em vez de mandar botoes de madrugada');

/* 12. sem_resposta para a humana: entrega, nao carimba perdido */
$M = varre([conv($W, 'aguardando', 'c_p_hum', 21)], $agora);
ok(($M['conv'][$W][0]['estado'] ?? '') === 'humano' && !empty($M['conv'][$W][0]['silenciado_at']), 'entregue a humana e silenciada');
ok(!isset($M['lead'][$W][0]['status']), 'entrega a humana nao e encerramento: nao carimba perdido');
// mesmo com o aviso falhando, a humana recebe
$M = varre([conv($W, 'aguardando', 'c_p_hum', 21)], $agora, ['envio_ok' => false]);
ok(($M['conv'][$W][0]['estado'] ?? '') === 'humano', 'aviso falhado: a conversa vai para a humana assim mesmo');

/* 13. sem_resposta que nao saiu e sem destino terminal: fica para a proxima */
$M = varre([conv($W, 'aguardando', 'c_off_t', 21)], $agora, ['envio_ok' => false]);
ok($M['executa'] === ['c_aviso'], 'tentou executar o card de texto');
ok($M['conv'] === [] && $M['lead'] === [] && $M['r']['encerrados'] === 0, 'envio do sem_resposta falhou: nada avanca, tenta na proxima');

/* 14. grafo null = "nao sei": nao envia, nao avanca, nao encerra */
$M = varre([conv($W, 'aguardando', 'c_bot', 21), conv('+5548900000003', 'aguardando', 'c_off', 21)], $agora, ['grafo' => null]);
ok($M['envios'] === [] && $M['conv'] === [] && $M['lead'] === [], 'grafo indisponivel: nada e feito');
ok($M['r'] === ['lembretes' => 0, 'encerrados' => 0, 'fora_da_janela' => 0], 'e nada e contado');
// versao vazia e card inexistente tambem so ficam para depois
$M = varre([conv($W, 'aguardando', 'c_bot', 21, null, ['fluxo_versao_id' => null])], $agora);
ok($M['envios'] === [] && $M['conv'] === [], 'sem versao registrada: nada e feito');
$M = varre([conv($W, 'aguardando', 'c_fantasma', 21)], $agora);
ok($M['envios'] === [] && $M['conv'] === [], 'card que nao existe mais na versao: nada e feito');

/* 15. Lembrete com texto que fica vazio nao vira mensagem em branco */
$G3 = $GRAFO; $G3['cards']['c_bot']['lembrete_texto'] = '{x}';
$M = varre([conv($W, 'aguardando', 'c_bot', 21)], $agora, ['grafo' => $G3]);
ok($M['envios'] === [] && $M['r']['lembretes'] === 0, 'lembrete que renderiza vazio nao manda mensagem em branco');

/* 16. O lembrete e registrado como saida do robo */
$fonte = file_get_contents(__DIR__ . '/../lib/wa-timeout.php');
ok(strpos($fonte, 'wa_registra_saida') !== false, 'o lembrete e registrado como saida do robo');
ok(strpos($fonte, "'estado=eq.aguardando&") !== false, 'a query do fonte varre por aguardando');

// --- wa_cron_autorizado: segredo ausente ou fraco nunca autoriza (revisao: Critical) ---
ok(wa_cron_autorizado('', '') === false, 'chave vazia nunca autoriza, nem contra pedido tambem vazio');
ok(wa_cron_autorizado('', 'qualquer-coisa-que-mandarem') === false, 'chave vazia nunca autoriza');
ok(wa_cron_autorizado('curta-demais', 'curta-demais') === false, 'chave com menos de 16 caracteres nao e levada a serio, mesmo batendo');
ok(wa_cron_autorizado('12345678901234567890', 'outra-chave-longa-mas-errada') === false, 'chave configurada e longa, mas recebida errada, nao autoriza');
ok(wa_cron_autorizado('12345678901234567890', '12345678901234567890') === true, 'chave configurada e longa, e igual a recebida, autoriza');

echo "test-wa-timeout OK\n";
