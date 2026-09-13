<?php
require __DIR__ . '/../lib/wa-fone.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

// A funcao que o enviar.php vai usar. Isolada para ser testavel sem subir o
// formulario inteiro (o enviar.php manda e-mail e grava no Supabase).
require __DIR__ . '/../lib/po-fone-lead.php';

// Numero valido vira E.164.
ok(po_fone_lead('(48) 99999-0001') === '+5548999990001', 'formato do formulario vira E.164');
ok(po_fone_lead('48999990001')     === '+5548999990001', 'so digitos vira E.164');

// O que NAO da para normalizar e PRESERVADO como o visitante digitou. Perder o
// telefone de um lead e pior que guardar num formato torto: sem ele a cliente
// nao consegue ligar de volta.
ok(po_fone_lead('+1 415 555 2671') === '+1 415 555 2671', 'estrangeiro fica como veio');
ok(po_fone_lead('nao tenho')       === 'nao tenho',       'texto livre fica como veio');
ok(po_fone_lead('')                === '',                'vazio continua vazio');
ok(po_fone_lead('   ')             === '',                'so espaco vira vazio');

echo "test-enviar-fone OK\n";
