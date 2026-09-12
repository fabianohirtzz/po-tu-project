<?php
// tests/test-wa-vcard.php
require __DIR__ . '/../lib/wa-vcard.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

// --- vCard 3.0 com dois telefones e um email (o que o iPhone exporta)
$vcf = "BEGIN:VCARD\r\nVERSION:3.0\r\nN:Ramos;Lourdete;;;\r\nFN:Lourdete Ramos - PO\r\n" .
       "TEL;TYPE=CELL:(48) 99999-1111\r\nTEL;TYPE=HOME:48 3222-0000\r\n" .
       "EMAIL:lourdete@x.com\r\nEND:VCARD\r\n";
$r = wa_vcard_parse($vcf);
ok(count($r) === 1, 'um contato');
ok($r[0]['nome'] === 'Lourdete Ramos - PO', 'FN vira o nome, com marcador intacto');
ok(count($r[0]['telefones']) === 2, 'dois telefones capturados');
ok(in_array('(48) 99999-1111', $r[0]['telefones'], true), 'celular cru preservado');
ok($r[0]['emails'] === ['lourdete@x.com'], 'email capturado');

// --- dois contatos no mesmo arquivo
$dois = "BEGIN:VCARD\nFN:A\nTEL:1\nEND:VCARD\nBEGIN:VCARD\nFN:B\nTEL:2\nEND:VCARD\n";
ok(count(wa_vcard_parse($dois)) === 2, 'dois cards separados');

// --- sem FN, cai no N formatado
$semfn = "BEGIN:VCARD\nN:Silva;Joao;;;\nTEL:9\nEND:VCARD\n";
ok(wa_vcard_parse($semfn)[0]['nome'] === 'Joao Silva', 'sem FN monta do N (nome sobrenome)');

// --- quoted-printable no nome (acontece em export antigo do Android)
$qp = "BEGIN:VCARD\nFN;ENCODING=QUOTED-PRINTABLE:Mar=C3=ADlia\nTEL:9\nEND:VCARD\n";
ok(wa_vcard_parse($qp)[0]['nome'] === 'Marília', 'quoted-printable decodificado');

// --- linha dobrada (folding): continuacao comeca com espaco
$fold = "BEGIN:VCARD\nFN:Nome Muito\n  Longo\nTEL:9\nEND:VCARD\n";
ok(wa_vcard_parse($fold)[0]['nome'] === 'Nome MuitoLongo', 'folding remontado');

// --- CSV do Google Contacts (cabecalho com colunas conhecidas)
$csv = "Name,Phone 1 - Value,E-mail 1 - Value\r\n" .
       "\"Maria Grecia 2 - PO\",+55 48 98888-2222,maria@x.com\r\n" .
       "Sem Telefone,,so@email.com\r\n";
$c = wa_csv_parse($csv);
ok(count($c) === 2, 'duas linhas de dados');
ok($c[0]['nome'] === 'Maria Grecia 2 - PO', 'nome do CSV');
ok($c[0]['telefones'] === ['+55 48 98888-2222'], 'telefone do CSV');
ok($c[1]['telefones'] === [], 'linha sem telefone nao inventa telefone');

// --- raw preservado para o payload_import
ok(strpos($r[0]['raw'], 'BEGIN:VCARD') !== false, 'raw guarda o card original');

/* ============================================================
   BDAY: a TERCEIRA camada de identidade da fusao (nome+nascimento). Sem
   ela, o cliente antigo marcado "PO" na agenda nao casa por CPF (a agenda
   nao tem CPF) nem por celular (a ficha antiga dele quase nunca tem
   celular) e entra como contato NOVO - a mesma pessoa duas vezes.
   O parser so EXTRAI (cru); quem normaliza e wa_import_candidato.
============================================================ */
$bday = function ($valor) {
    $c = wa_vcard_parse("BEGIN:VCARD\nFN:Teste\nBDAY:$valor\nTEL:48999990001\nEND:VCARD\n");
    return $c[0]['data_nascimento'];
};
ok($bday('1981-09-11') === '1981-09-11', 'BDAY ISO capturado');
ok($bday('19810911') === '19810911', 'BDAY basico (sem tracos) capturado cru');
ok($bday('1981-09-11T00:00:00Z') === '1981-09-11T00:00:00Z', 'BDAY com hora capturado cru');
ok($bday('--0911') === '--0911', 'BDAY sem ano capturado cru (quem descarta e o candidato)');

$comParam = wa_vcard_parse("BEGIN:VCARD\nFN:Teste\nBDAY;VALUE=DATE:19810911\nEND:VCARD\n");
ok($comParam[0]['data_nascimento'] === '19810911', 'BDAY com parametro (;VALUE=DATE) tambem e lido');

ok($r[0]['data_nascimento'] === '', 'card sem BDAY nao inventa nascimento');

// CSV do Google tambem traz aniversario quando a coluna existe
$csvNasc = wa_csv_parse("Name,Phone 1 - Value,Birthday\r\n\"Ana - PO\",48999990001,1981-09-11\r\n");
ok($csvNasc[0]['data_nascimento'] === '1981-09-11', 'coluna Birthday do CSV lida');
ok($c[0]['data_nascimento'] === '', 'CSV sem coluna de aniversario nao inventa (nem cai na coluna 0)');

/* ============================================================
   REGRESSAO: vCard TRUNCADO nao pode fundir dois contatos.
   O separador era '(.*?)' com /s, e o '.' casava \n: um BEGIN sem END
   (o que acontece quando a agenda e exportada em partes - e o proprio
   endpoint pede isso quando o arquivo passa do limite) grudava no card
   seguinte. Saia UM contato so, com o nome do segundo e os telefones dos
   dois, e o wa_id (primeiro celular) era o do contato que sumiu: um
   disparo chamaria uma pessoa pelo nome da outra.
============================================================ */
$truncado = "BEGIN:VCARD\nVERSION:3.0\nFN:Ana Alves - PO\nTEL;TYPE=CELL:48999991111\n" .
            "BEGIN:VCARD\nVERSION:3.0\nFN:Bruno Dias - PO\nTEL;TYPE=CELL:48999992222\nEND:VCARD\n";
$t = wa_vcard_parse($truncado);
ok(count($t) === 1, 'o card truncado (sem END) nao vira contato: so o card inteiro entra');
ok($t[0]['nome'] === 'Bruno Dias - PO', 'o card inteiro mantem o proprio nome');
ok($t[0]['telefones'] === ['48999992222'], 'e NAO herda o telefone do card truncado');

echo "test-wa-vcard OK\n";
