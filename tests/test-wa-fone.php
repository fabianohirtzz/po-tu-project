<?php
require __DIR__ . '/../lib/wa-fone.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

/* ============================================================
   Os casos de entrada/saida vivem em tests/fixtures/fone.json e sao os
   MESMOS que tests/test-fone.mjs le para o poE164 do painel. Antes cada
   teste tinha a sua lista escrita a mao, e elas ja tinham divergido (o
   lado JS nao testava DDI + celular de 8 digitos nem texto puro): uma
   correcao so no PHP nao deixava o teste do JS vermelho, e os dois
   normalizadores podiam separar em silencio - o que faz a mesma pessoa
   virar duas fichas conforme quem gravou.
============================================================ */
$fx = json_decode(file_get_contents(__DIR__ . '/fixtures/fone.json'), true);
ok(is_array($fx) && !empty($fx['casos']), 'fixture de telefone carregada');

foreach ($fx['casos'] as $i => $caso) {
    list($entrada, $esperado) = $caso;
    $got = wa_e164($entrada);
    ok($got === $esperado,
       "caso $i da fixture: " . var_export($entrada, true) .
       ' -> esperado ' . var_export($esperado, true) . ', veio ' . var_export($got, true));
}

// --- asserções que nao cabem em par entrada/saida
// idempotencia: normalizar o que ja esta normalizado nao estraga
ok(wa_e164(wa_e164('(48) 99604-8882')) === '+5548996048882', 'normalizar de novo nao estraga');

// --- celular x fixo (o fixo nunca entra na transmissao, secao 8 da spec)
ok(wa_e_celular('+5548996048882') === true,  'celular de 9 digitos');
ok(wa_e_celular('+554832220000')  === false, 'fixo nao e celular');
ok(wa_e_celular(null)             === false, 'null nao e celular');

echo "test-wa-fone OK\n";
