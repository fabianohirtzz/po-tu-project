<?php
require __DIR__ . '/../lib/wa-timeout.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

$agora = strtotime('2026-09-11 12:00:00 UTC');
function h($agora, $horas) { return gmdate('c', $agora - $horas * 3600); }

$CONVERSAS = [
    // esperando ha 2h: cedo demais para incomodar
    ['wa_id'=>'+5548900000001','estado'=>'enviado_roteiro','aguardando_desde'=>h($agora,2),  'lembrete_at'=>null],
    // 25h em silencio: merece o lembrete
    ['wa_id'=>'+5548900000002','estado'=>'enviado_roteiro','aguardando_desde'=>h($agora,25), 'lembrete_at'=>null],
    // ja levou lembrete ha 25h (50h de silencio): encerra
    ['wa_id'=>'+5548900000003','estado'=>'enviado_roteiro','aguardando_desde'=>h($agora,50), 'lembrete_at'=>h($agora,25)],
    // ja levou lembrete ha 2h: espera mais
    ['wa_id'=>'+5548900000004','estado'=>'enviado_roteiro','aguardando_desde'=>h($agora,27), 'lembrete_at'=>h($agora,2)],
    // com a humana: o robo nao toca, por mais antigo que seja
    ['wa_id'=>'+5548900000005','estado'=>'humano','aguardando_desde'=>h($agora,300),'lembrete_at'=>null],
    // ja qualificado: idem
    ['wa_id'=>'+5548900000006','estado'=>'qualificado','aguardando_desde'=>h($agora,300),'lembrete_at'=>null],
];
// Lead de quem recebe o lembrete (5548900000002), pra provar que o nome
// vem de po_leads de verdade, nao de uma string fixa (revisao: Important 2).
$LEADS = [
    ['wa_id'=>'+5548900000002','nome'=>'Sandra'],
];
$ENVIADAS = []; $ENVIADAS_TXT = []; $UPDATES = [];

// wa_texto vive em lib/wa-motor.php e resolve dependencia por WA_DEPS (via
// wa_motor_set_deps), NAO por WA_TO_DEPS. wa_varre_timeouts chama wa_texto
// para montar o lembrete, entao sem isto o select de textos cai no
// wa_db_select de verdade e o teste sai para a rede.
$LOG = [];
wa_motor_set_deps([
    'select' => function ($t, $q) {
        if ($t !== 'po_wa_textos') return [];
        return [
            ['chave'=>'lembrete',     'texto'=>'Oi {nome}, tudo bem? So passando para saber se voce chegou a ver o roteiro que enviei.'],
            ['chave'=>'lembrete_menu','texto'=>'Oi {nome}, tudo bem? Mandei a lista das nossas proximas viagens. Quer que eu envie o roteiro completo de alguma delas?'],
        ];
    },
    // O lembrete e gravado no log de saida por wa_registra_saida, que
    // tambem resolve pelo WA_DEPS. Sem este insert o teste sairia para a rede.
    'insert' => function ($t, $linha) use (&$LOG) { $LOG[] = [$t, $linha]; return $linha; },
]);

// Estas sao as dependencias que wa_varre_timeouts usa diretamente. O
// select de po_wa_conversas ignora a query de proposito (devolve as 6
// conversas, inclusive humano/qualificado) para provar que quem barra
// essas duas e a guarda no laco, nao so o filtro que iria na query real.
wa_timeout_set_deps([
    'select' => function ($t, $q) use (&$CONVERSAS, &$LEADS) {
        if ($t === 'po_wa_conversas') return $CONVERSAS;
        if ($t === 'po_leads') {
            preg_match('/wa_id=eq\.([^&]+)/', $q, $m);
            $wa = isset($m[1]) ? urldecode($m[1]) : null;
            return array_values(array_filter($LEADS, fn($l) => $l['wa_id'] === $wa));
        }
        return [];
    },
    'update'    => function ($t, $q, $c) use (&$UPDATES) { $UPDATES[] = [$t, $q, $c]; return true; },
    'send_text' => function ($para, $txt) use (&$ENVIADAS, &$ENVIADAS_TXT) {
        $ENVIADAS[] = $para;
        $ENVIADAS_TXT[$para] = $txt;
        return ['ok'=>true,'wamid'=>'w','erro'=>null];
    },
]);

$r = wa_varre_timeouts($agora);

ok($r['lembretes'] === 1, 'exatamente um lembrete (deu: ' . $r['lembretes'] . ')');
ok($ENVIADAS === ['+5548900000002'], 'lembrete so para quem esta em silencio ha mais de 24h');

// Conteudo da mensagem, nao so o destinatario (revisao: Important 2): o
// nome vem do lead de verdade e a pontuacao sai correta, sem o "Oi ,"
// (espaco solto antes da virgula) que o {nome} vazio produzia antes.
ok($ENVIADAS_TXT['+5548900000002'] ===
    'Oi Sandra, tudo bem? So passando para saber se voce chegou a ver o roteiro que enviei.',
    'lembrete usa o nome real do lead, com pontuacao correta (veio: "' . ($ENVIADAS_TXT['+5548900000002'] ?? '') . '")');

// O lembrete tambem e mensagem do robo e precisa ficar no log: e o que
// impede o eco dele de ser lido como handoff (revisao: Critical 3).
ok(count($LOG) === 1, 'o lembrete entrou no log de saida (deu: ' . count($LOG) . ')');
ok($LOG[0][0] === 'po_wa_mensagens' && $LOG[0][1]['autor'] === 'robo' && $LOG[0][1]['direcao'] === 'out',
    'registrado como saida do robo em po_wa_mensagens');

ok($r['encerrados'] === 1, 'exatamente um encerrado (deu: ' . $r['encerrados'] . ')');
$perdidos = array_values(array_filter($UPDATES, fn($u) => ($u[2]['status'] ?? '') === 'perdido'));
ok(count($perdidos) === 1, 'um lead marcado perdido');
ok(strpos($perdidos[0][1], '5548900000003') !== false, 'o perdido e o de 50h');

// A conversa com a humana nao pode ser tocada nunca, e este e o teste que
// impede o cron de encerrar atendimento em andamento.
foreach ($UPDATES as $u) {
    ok(strpos($u[1], '5548900000005') === false, 'cron nao toca conversa com humano');
    ok(strpos($u[1], '5548900000006') === false, 'cron nao toca conversa qualificada');
}
ok(!in_array('+5548900000005', $ENVIADAS, true), 'nao manda lembrete em conversa humana');

// --- cenario isolado: lead sem nome cadastrado (revisao: Important 2) ---
// Prova que a limpeza de pontuacao entra em acao quando o nome vem vazio:
// sem ela o texto sairia "Oi , tudo bem?" pro cliente.
$agora2 = $agora;
$CONVERSAS2 = [
    ['wa_id'=>'+5548900000009','estado'=>'enviado_roteiro','aguardando_desde'=>h($agora2,25),'lembrete_at'=>null],
];
$ENVIADAS_TXT2 = [];
wa_timeout_set_deps([
    'select' => function ($t, $q) use (&$CONVERSAS2) {
        if ($t === 'po_wa_conversas') return $CONVERSAS2;
        return []; // sem lead cadastrado: nome fica vazio
    },
    'update'    => function ($t, $q, $c) { return true; },
    'send_text' => function ($para, $txt) use (&$ENVIADAS_TXT2) { $ENVIADAS_TXT2[$para] = $txt; return ['ok'=>true,'wamid'=>'w','erro'=>null]; },
]);
$r2 = wa_varre_timeouts($agora2);
ok($r2['lembretes'] === 1, 'segundo cenario: lembrete sai mesmo sem lead cadastrado');
ok($ENVIADAS_TXT2['+5548900000009'] ===
    'Oi, tudo bem? So passando para saber se voce chegou a ver o roteiro que enviei.',
    'sem nome, a limpeza de pontuacao evita "Oi , tudo bem?" (veio: "' . ($ENVIADAS_TXT2['+5548900000009'] ?? '') . '")');

// --- cenario isolado: envio do lembrete falha (revisao: Important 1) ---
// A conversa NAO pode ser marcada como avisada se a mensagem nao saiu,
// senao o encerramento vem 24h depois sem o cliente ter recebido nada.
$agora3 = $agora;
$CONVERSAS3 = [
    ['wa_id'=>'+5548900000008','estado'=>'enviado_roteiro','aguardando_desde'=>h($agora3,25),'lembrete_at'=>null],
];
$UPDATES3 = [];
wa_timeout_set_deps([
    'select'    => function ($t, $q) use (&$CONVERSAS3) { return $t === 'po_wa_conversas' ? $CONVERSAS3 : []; },
    'update'    => function ($t, $q, $c) use (&$UPDATES3) { $UPDATES3[] = [$t, $q, $c]; return true; },
    'send_text' => function ($para, $txt) { return ['ok'=>false,'wamid'=>null,'erro'=>'falhou de proposito']; },
]);
$r3 = wa_varre_timeouts($agora3);
ok($r3['lembretes'] === 0, 'envio que falha nao conta como lembrete enviado');
ok(count($UPDATES3) === 0, 'envio que falha nao grava lembrete_at, senao o lead encerraria sem ter recebido nada');

/* --- cenario isolado: quem so viu o menu leva outro texto (revisao: Important 3)
   O texto unico afirmava "o roteiro que enviei" para quem recebeu apenas a
   lista de viagens: o robo dizia ter mandado algo que nunca mandou. */
$CONVERSAS4 = [
    ['wa_id'=>'+5548900000007','estado'=>'aguardando_roteiro','aguardando_desde'=>h($agora,25),'lembrete_at'=>null],
];
$ENVIADAS_TXT4 = [];
wa_timeout_set_deps([
    'select'    => function ($t, $q) use (&$CONVERSAS4) { return $t === 'po_wa_conversas' ? $CONVERSAS4 : []; },
    'update'    => function ($t, $q, $c) { return true; },
    'send_text' => function ($para, $txt) use (&$ENVIADAS_TXT4) { $ENVIADAS_TXT4[$para] = $txt; return ['ok'=>true,'wamid'=>'w','erro'=>null]; },
]);
$r4 = wa_varre_timeouts($agora);
ok($r4['lembretes'] === 1, 'quem recebeu o menu tambem leva lembrete');
ok(strpos($ENVIADAS_TXT4['+5548900000007'], 'lista das nossas proximas viagens') !== false,
    'e o texto e o do menu, nao o do roteiro (veio: "' . ($ENVIADAS_TXT4['+5548900000007'] ?? '') . '")');
ok(strpos($ENVIADAS_TXT4['+5548900000007'], 'roteiro que enviei') === false,
    'o robo nao afirma ter enviado um roteiro que nunca enviou');

// --- wa_cron_autorizado: segredo ausente ou fraco nunca autoriza (revisao: Critical) ---
ok(wa_cron_autorizado('', '') === false, 'chave vazia nunca autoriza, nem contra pedido tambem vazio');
ok(wa_cron_autorizado('', 'qualquer-coisa-que-mandarem') === false, 'chave vazia nunca autoriza');
ok(wa_cron_autorizado('curta-demais', 'curta-demais') === false, 'chave com menos de 16 caracteres nao e levada a serio, mesmo batendo');
ok(wa_cron_autorizado('12345678901234567890', 'outra-chave-longa-mas-errada') === false, 'chave configurada e longa, mas recebida errada, nao autoriza');
ok(wa_cron_autorizado('12345678901234567890', '12345678901234567890') === true, 'chave configurada e longa, e igual a recebida, autoriza');

echo "test-wa-timeout OK\n";
