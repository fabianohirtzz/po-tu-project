<?php
// tests/test-wa-import-normaliza.php
require __DIR__ . '/../lib/wa-import.php';
require __DIR__ . '/../lib/wa-vcard.php';   // para a cadeia vCard -> candidato -> auditoria
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

// --- marcador
ok(wa_import_tem_marcador('Lourdete - PO') === true,  'traco PO');
ok(wa_import_tem_marcador('Maria Cliente PO') === true, 'cliente PO');
ok(wa_import_tem_marcador('Maria - po') === true,      'minusculo');
ok(wa_import_tem_marcador('Dentista Joao') === false,  'sem marcador fica de fora');
ok(wa_import_tem_marcador('Poliana Souza') === false,  '"po" dentro de palavra nao conta');
ok(wa_import_tem_marcador('Porto Alegre') === false,   '"po" dentro de "Porto" nao conta');

// --- limpeza de nome: tira marcador e poluicao de busca
ok(wa_import_limpa_nome('Maria Grecia 2 - PO') === 'Maria', 'exemplo da spec 9.4: sobra so o nome');
ok(wa_import_limpa_nome('Lourdete Ramos - Cliente PO') === 'Lourdete Ramos', 'tira "Cliente PO"');
ok(wa_import_limpa_nome('  Joao   Silva ') === 'Joao Silva', 'colapsa espacos');
ok(wa_import_limpa_nome('Lourdete Ramos - PO') === 'Lourdete Ramos', 'sobrenome nao e poluicao');

/* O marcador em QUALQUER posicao (spec 9.1: "a grafia varia entre os dois
   aparelhos"). A versao anterior so o removia no FIM do nome, entao
   "PO - Joao Silva" e "Maria PO Turquia" entravam com o marcador dentro do
   nome - que e o nome que vai para po_leads.nome e para a saudacao do robo
   no WhatsApp. */
ok(wa_import_limpa_nome('PO - Joao Silva') === 'Joao Silva', 'marcador no comeco: o resto e o nome');
ok(wa_import_limpa_nome('PO Joao Silva') === 'Joao Silva', 'marcador no comeco sem separador');
ok(wa_import_limpa_nome('Maria PO Turquia') === 'Maria', 'marcador no meio: o que vem depois e anotacao');
ok(wa_import_limpa_nome('Cliente PO Ana Souza') === 'Ana Souza', '"Cliente PO" no comeco tambem abre o nome');
ok(wa_import_limpa_nome('Ana Souza - po') === 'Ana Souza', 'minusculo no fim');

// nome que sobra vazio nao pode ir em branco para o banco
ok(wa_import_limpa_nome('po') === WA_IMPORT_NOME_VAZIO, 'contato chamado so "po" ganha rotulo, nao string vazia');
ok(wa_import_limpa_nome('- PO') === WA_IMPORT_NOME_VAZIO, 'so separador e marcador tambem');
ok(wa_import_limpa_nome('   ') === WA_IMPORT_NOME_VAZIO, 'nome em branco ganha rotulo');
ok(WA_IMPORT_NOME_VAZIO !== '', 'o rotulo de nome vazio nunca e string vazia');

// nome legitimo nao pode perder letra nenhuma (o marcador so casa \bPO\b)
foreach (['Poliana Souza', 'Porto Alegre', 'Campos', 'Apolo', 'Napoleao', 'Napoleão', 'Pompeu'] as $legit) {
    ok(wa_import_limpa_nome($legit) === $legit, "nome legitimo intacto: $legit");
}

/* ACENTO. Sem o /u nos padroes, o PCRE trabalha em bytes e o byte de
   continuacao UTF-8 vale como fronteira de palavra: '\bPO\b' casava o "Po"
   de 'Poá', 'Poços', 'Poção'. Com o marcador valendo em qualquer posicao,
   isso apagava o nome dali em diante - e, como wa_import_tem_marcador usa a
   MESMA lista, ainda admitia na importacao um contato que a cliente nunca
   marcou. E o nome com que o robo cumprimenta a pessoa no WhatsApp. */
foreach (['Poá', 'Poá Silva', 'Ana Poá', 'Joana Poços', 'Poção', 'Ana Poção Neves'] as $acentuado) {
    ok(wa_import_tem_marcador($acentuado) === false, "acento nao vira marcador: $acentuado");
    ok(wa_import_limpa_nome($acentuado) === $acentuado, "nome acentuado intacto: $acentuado");
}
// e o marcador de verdade continua saindo, mesmo colado num nome acentuado
ok(wa_import_tem_marcador('Ana Poá - PO') === true, 'marcador de verdade ao lado de acento e reconhecido');
ok(wa_import_limpa_nome('Ana Poá - PO') === 'Ana Poá', 'sai o marcador e fica o nome acentuado inteiro');
ok(wa_import_limpa_nome('PO - Ana Poá') === 'Ana Poá', 'marcador no comeco, nome acentuado preservado');

// --- cpf
ok(wa_import_cpf('111.444.777-35') === '11144477735', 'cpf so em digitos');
ok(wa_import_cpf('3423415967') === '',  'cpf com 10 digitos e invalido');
ok(wa_import_cpf('') === '', 'cpf vazio');

// --- candidato de agenda SEM marcador e descartado
$semMarca = ['nome'=>'Dentista', 'telefones'=>['48999990000'], 'emails'=>[], 'org'=>'', 'raw'=>'x'];
ok(wa_import_candidato($semMarca, 'agenda-esposa') === null, 'agenda sem marcador: descartado');

// --- candidato de agenda COM marcador: nome limpo, celular em E.164, wa_id setado
$comMarca = ['nome'=>'Lourdete Ramos - PO', 'telefones'=>['(48) 99999-1111','48 3222-0000'],
             'emails'=>['l@x.com'], 'org'=>'', 'raw'=>'BEGIN...'];
$c = wa_import_candidato($comMarca, 'agenda-esposa');
ok($c['nome'] === 'Lourdete Ramos', 'nome limpo');
ok($c['celulares'] === ['+5548999991111'], 'celular normalizado');
ok($c['fixos'] === ['+554832220000'], 'fixo separado do celular');
ok($c['wa_id'] === '+5548999991111', 'wa_id e o primeiro celular');
ok($c['origem_import'] === 'agenda-esposa', 'origem gravada');
ok($c['payload_import']['raw'] === 'BEGIN...', 'payload guarda o cru');

/* ============================================================
   BDAY da agenda chega ate data_nascimento do candidato, ja em ISO. E a
   camada 3 do casamento (nome+nascimento): sem ela, o cliente antigo
   marcado "PO" na agenda nao casa por CPF (a agenda nao tem CPF) nem por
   celular (a ficha antiga dele quase nunca tem celular) e entra como
   contato NOVO - a mesma pessoa duas vezes, que e a regra 3 do dono.
============================================================ */
$comBday = function ($bday) {
    return wa_import_candidato(
        ['nome' => 'Ana - PO', 'telefones' => ['48999991111'], 'emails' => [],
         'data_nascimento' => $bday, 'raw' => 'x'],
        'agenda-esposa'
    )['data_nascimento'];
};
ok($comBday('1981-09-11') === '1981-09-11', 'BDAY ISO vira data_nascimento');
ok($comBday('19810911') === '1981-09-11', 'BDAY basico do vCard vira ISO');
ok($comBday('1981-09-11T00:00:00Z') === '1981-09-11', 'BDAY com hora vira so a data');
ok($comBday('11/09/1981') === '1981-09-11', 'data BR da agenda vira ISO (11 de setembro)');
// sem ano nao identifica ninguem, e inventar ano criaria casamento falso
ok($comBday('--0911') === null, 'BDAY sem ano (--MMDD do iPhone) e descartado');
ok($comBday('') === null, 'sem BDAY, data_nascimento fica null');
ok($comBday('aniversario') === null, 'texto solto no BDAY e descartado');

// --- agenda com marcador mas so com fixo: entra, mas sem wa_id (vai pra revisao)
$soFixo = ['nome'=>'Tia - PO', 'telefones'=>['48 3222-0000'], 'emails'=>[], 'org'=>'', 'raw'=>'y'];
$f = wa_import_candidato($soFixo, 'agenda-marido');
ok($f !== null, 'so com fixo e marcador ainda entra');
ok($f['wa_id'] === null, 'sem celular, sem wa_id');

// --- CRM nao exige marcador (origem crm-*)
$crm = ['nome'=>'Antonio Filho', 'telefones'=>['48999990001'], 'emails'=>[], 'org'=>'',
        'raw'=>'{}', 'cpf'=>'111.444.777-35', 'campos'=>['cidade'=>'Florianopolis']];
$cc = wa_import_candidato($crm, 'crm-toninho');
ok($cc !== null, 'CRM entra sem marcador');
ok($cc['cpf'] === '11144477735', 'cpf normalizado no candidato');
ok($cc['campos']['cidade'] === 'Florianopolis', 'campos extras do CRM preservados');

// --- dedup intra-lote: mesma pessoa nos dois aparelhos, casada por celular
$dup = wa_import_dedup([
  ['wa_id'=>'+5548999991111','celulares'=>['+5548999991111'],'fixos'=>[],'nome'=>'Lourdete',
   'email'=>null,'cpf'=>null,'data_nascimento'=>null,'origem_import'=>'agenda-esposa','campos'=>[],'payload_import'=>['a'=>1]],
  ['wa_id'=>'+5548999991111','celulares'=>['+5548999991111'],'fixos'=>[],'nome'=>'Lourdete Ramos',
   'email'=>'l@x.com','cpf'=>null,'data_nascimento'=>null,'origem_import'=>'agenda-marido','campos'=>[],'payload_import'=>['b'=>2]],
]);
ok(count($dup) === 1, 'mesma pessoa nos dois aparelhos vira um candidato');
ok($dup[0]['nome'] === 'Lourdete Ramos', 'fica o nome mais completo');
ok($dup[0]['email'] === 'l@x.com', 'email preenchido vence o vazio');

// --- dedup nao funde pessoas diferentes
$dois = wa_import_dedup([
  ['wa_id'=>'+5548999991111','celulares'=>['+5548999991111'],'fixos'=>[],'nome'=>'A','email'=>null,'cpf'=>null,'data_nascimento'=>null,'origem_import'=>'agenda-esposa','campos'=>[],'payload_import'=>[]],
  ['wa_id'=>'+5511988887777','celulares'=>['+5511988887777'],'fixos'=>[],'nome'=>'B','email'=>null,'cpf'=>null,'data_nascimento'=>null,'origem_import'=>'agenda-esposa','campos'=>[],'payload_import'=>[]],
]);
ok(count($dois) === 2, 'celulares diferentes nao se fundem');

/* ============================================================
   A CADEIA INTEIRA, que era onde o defeito aparecia: .vcf da agenda ->
   parser -> candidato -> auditoria. A ficha antiga na base nao tem CPF nem
   celular (das 775 fichas do CRM, so 22 tem celular), entao o unico
   caminho que resta e nome+nascimento. Com o BDAY jogado fora, este caso
   virava 'novo' e a pessoa passava a existir duas vezes.
============================================================ */
$vcfBday = "BEGIN:VCARD\nVERSION:3.0\nFN:Antonio Ferreira - PO\n" .
           "TEL;TYPE=CELL:48999993333\nBDAY:19550214\nEND:VCARD\n";
$candsBday = [];
foreach (wa_vcard_parse($vcfBday) as $ct) $candsBday[] = wa_import_candidato($ct, 'agenda-esposa');
$fichaAntiga = wa_import_base_row(['id' => 'L-CRM', 'telefone' => null, 'cpf' => null,
    'nome' => 'Antonio Ferreira', 'data_nascimento' => '1955-02-14', 'status' => 'venda', 'venda' => 9000]);
$planoBday = wa_import_audita($candsBday, [$fichaAntiga]);
ok($planoBday[0]['acao'] === 'revisar', 'ficha antiga sem cpf e sem celular ainda e encontrada (nao vira novo)');
ok($planoBday[0]['match_por'] === 'nome_nasc', 'o casamento veio da camada 3 (nome + nascimento do BDAY)');
ok($planoBday[0]['match_id'] === 'L-CRM', 'aponta a ficha antiga para a revisao humana');

echo "test-wa-import-normaliza OK\n";
