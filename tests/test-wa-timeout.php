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
$ENVIADAS = []; $UPDATES = [];

// wa_texto vive em lib/wa-motor.php e resolve dependencia por WA_DEPS (via
// wa_motor_set_deps), NAO por WA_TO_DEPS. wa_varre_timeouts chama wa_texto
// para montar o lembrete, entao sem isto o select de textos cai no
// wa_db_select de verdade e o teste sai para a rede.
wa_motor_set_deps([
    'select' => function ($t, $q) {
        return $t === 'po_wa_textos' ? [['chave'=>'lembrete','texto'=>'Oi {nome}, viu o roteiro?']] : [];
    },
]);

// Estas sao as dependencias que wa_varre_timeouts usa diretamente. O
// select ignora a query de proposito (devolve as 6 conversas, inclusive
// humano/qualificado) para provar que quem barra essas duas e a guarda no
// laco, nao so o filtro que iria na query real.
wa_timeout_set_deps([
    'select'    => function ($t, $q) use (&$CONVERSAS) { return $t === 'po_wa_conversas' ? $CONVERSAS : []; },
    'update'    => function ($t, $q, $c) use (&$UPDATES) { $UPDATES[] = [$t, $q, $c]; return true; },
    'send_text' => function ($para, $txt) use (&$ENVIADAS) { $ENVIADAS[] = $para; return ['ok'=>true,'wamid'=>'w','erro'=>null]; },
]);

$r = wa_varre_timeouts($agora);

ok($r['lembretes'] === 1, 'exatamente um lembrete (deu: ' . $r['lembretes'] . ')');
ok($ENVIADAS === ['+5548900000002'], 'lembrete so para quem esta em silencio ha mais de 24h');

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

echo "test-wa-timeout OK\n";
