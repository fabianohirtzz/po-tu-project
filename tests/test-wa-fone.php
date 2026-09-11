<?php
require __DIR__ . '/../lib/wa-fone.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

// --- formatos que a agenda do celular realmente produz
ok(wa_e164('(48) 99604-8882')    === '+5548996048882', 'formato com parenteses e traco');
ok(wa_e164('48 99604 8882')      === '+5548996048882', 'formato com espacos');
ok(wa_e164('+55 48 99604-8882')  === '+5548996048882', 'ja com DDI');
ok(wa_e164('5548996048882')      === '+5548996048882', 'so digitos com DDI');
ok(wa_e164('48996048882')        === '+5548996048882', 'so digitos sem DDI');

// --- o nono digito: celular antigo de 8 digitos ganha o 9 na frente
ok(wa_e164('4896048882')         === '+5548996048882', 'celular antigo de 8 digitos ganha o nono');
ok(wa_e164('554896048882')       === '+5548996048882', 'celular antigo com DDI ganha o nono');

// --- fixo NAO ganha nono digito (comeca com 2..5)
ok(wa_e164('4832220000')         === '+554832220000',  'fixo de 8 digitos fica como esta');

// --- lixo da agenda
ok(wa_e164('')                   === null, 'vazio');
ok(wa_e164('123')                === null, 'curto demais');
ok(wa_e164('0800 123 4567')      === null, '0800 nao e telefone de pessoa');
ok(wa_e164('(01) 99999-9999')    === null, 'DDD 01 nao existe');
ok(wa_e164('+1 415 555 2671')    === null, 'numero estrangeiro fica de fora');
ok(wa_e164('nao tem telefone')   === null, 'texto puro');

// --- idempotencia: normalizar duas vezes da o mesmo
ok(wa_e164(wa_e164('(48) 99604-8882')) === '+5548996048882', 'normalizar de novo nao estraga');

// --- celular x fixo
ok(wa_e_celular('+5548996048882') === true,  'celular de 9 digitos');
ok(wa_e_celular('+554832220000')  === false, 'fixo nao e celular');

echo "test-wa-fone OK\n";
