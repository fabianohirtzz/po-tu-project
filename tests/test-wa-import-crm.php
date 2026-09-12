<?php
// tests/test-wa-import-crm.php
require __DIR__ . '/../lib/wa-import.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

// ficha sintetica no formato do CRM (mesmo shape de campo por campo
// conferido na auditoria de 12/09; CPF, RG, passaporte, telefone, nome,
// e-mail e endereco sao FICTICIOS - fix round 1 trocou os valores reais
// que tinham vazado para ca por estes, apos revisao encontrar o CPF
// (valido nos digitos verificadores) e o registro correspondente no
// export real do CRM)
$ficha = [
  'id'=>'ficha-teste-0001','nome'=>'fulano de tal exemplo','email'=>'fulano.teste@example.com',
  'telefone'=>'48999990001','cpf'=>'11144477735','rg'=>'111111111','dataNascimento'=>'1981-09-11',
  'cep'=>'88000000','endereco'=>'Rua Exemplo','numero'=>'100','complemento'=>'APTO 1',
  'bairro'=>'Centro','cidade'=>'Florianópolis','estado'=>'SC',
  'profissao'=>'Empresário','estadoCivil'=>'Casado(a)','nacionalidade'=>'Brasileira','comoConheceu'=>'Instagram',
  'passaporteNumero'=>'AB000000','passaporteValidade'=>'2028-07-04','passaporteEmissao'=>'',
  'passaporteOrgaoEmissor'=>'','telefoneSecundario'=>'','observacoes'=>'',
  'contatoEmergenciaNome'=>'Lais','contatoEmergenciaTelefone'=>'48996048882','contatoEmergenciaParentesco'=>'Cônjuge',
];

$m = wa_import_mapa_crm($ficha);
ok($m['nome'] === 'fulano de tal exemplo', 'nome copiado');
ok($m['telefones'] === ['48999990001'], 'telefone vira lista para o parser comum');
ok($m['cpf'] === '11144477735', 'cpf passa para o candidato normalizar');
ok($m['campos']['data_nascimento'] === '1981-09-11', 'nascimento mapeado para snake_case');
ok($m['campos']['endereco'] === 'Rua Exemplo', 'endereco');
ok($m['campos']['passaporte_numero'] === 'AB000000', 'passaporte camelCase -> snake_case');
ok($m['campos']['passaporte_validade'] === '2028-07-04', 'validade');
ok($m['campos']['contato_emergencia_parentesco'] === 'Cônjuge', 'emergencia');
ok($m['campos']['estado'] === 'SC', 'estado');
ok(!array_key_exists('passaporte_emissao', $m['campos']), 'campo vazio nao entra (fusao so soma)');
ok(json_decode($m['raw'], true)['id'] === 'ficha-teste-0001', 'raw guarda a ficha inteira');

/* ============================================================
   'comoConheceu' NAO pode virar origem_manual. Naquela coluna o painel
   guarda a SOBRESCRITA MANUAL DO CANAL DE MARKETING (pago|organico|social|
   direto|instagram|whatsapp, lida em app.js e agrupada nos relatorios), e o
   texto livre da ficha antiga entrava la como se fosse canal: a carga real
   gravou 1 linha com 'Instagram' (I maiusculo), que virou um balde de canal
   separado de 'instagram' no relatorio. O valor nao se perde - vai para
   observacoes, rotulado, e a ficha crua continua inteira em payload_import.
============================================================ */
ok(!array_key_exists('origem_manual', $m['campos']), 'comoConheceu NAO vira origem_manual');
ok(strpos($m['campos']['observacoes'], 'Instagram') !== false, 'o valor de comoConheceu sobrevive em observacoes');
ok(strpos($m['campos']['observacoes'], 'Como conheceu') !== false, 'e vai rotulado, nao solto');
ok(strpos($m['raw'], 'comoConheceu') !== false, 'e a ficha crua segue inteira no payload');

// observacoes que ja existe na ficha nao e sobrescrita: soma (regra 2)
$mObs = wa_import_mapa_crm(['nome'=>'X','telefone'=>'48999990001',
    'observacoes'=>'Prefere janela', 'comoConheceu'=>'Indicacao']);
ok(strpos($mObs['campos']['observacoes'], 'Prefere janela') !== false, 'observacao original preservada');
ok(strpos($mObs['campos']['observacoes'], 'Indicacao') !== false, 'e o comoConheceu somado a ela');

// ficha sem comoConheceu nao inventa observacoes (campo vazio nunca entra)
$mSem = wa_import_mapa_crm(['nome'=>'X','telefone'=>'48999990001']);
ok(!array_key_exists('observacoes', $mSem['campos']), 'sem comoConheceu e sem observacoes, a chave nao nasce');
ok(!in_array('origem_manual', array_keys($mSem['campos']), true), 'e origem_manual nunca aparece');

// passa pelo candidato: vira registro pronto
$c = wa_import_candidato($m, 'crm-toninho');
ok($c['wa_id'] === '+5548999990001', 'telefone do CRM normalizado');
ok($c['cpf'] === '11144477735', 'cpf no candidato');
ok($c['campos']['cidade'] === 'Florianópolis', 'cidade preservada');

// --- fix round 1: valor nao-escalar nao estoura e nao vira "Array"
$ficha2 = ['nome' => ['a', 'b'], 'endereco' => ['rua' => 'x'], 'telefone' => '48999990001'];
$m2 = wa_import_mapa_crm($ficha2);
ok($m2['nome'] === '', 'nome nao-escalar vira string vazia, sem estourar');
ok(!array_key_exists('endereco', $m2['campos']), 'campo nao-escalar nao vira "Array" e nao entra em campos');

// --- fix round 1: raw sempre string, mesmo com byte invalido como UTF-8
$ficha3 = ['nome' => "Antonio Jos\xE9 da Silva", 'telefone' => '48999990001'];
$m3 = wa_import_mapa_crm($ficha3);
ok(is_string($m3['raw']), 'raw e sempre string, mesmo quando json_encode falharia');
ok($m3['raw'] !== '', 'raw nao fica vazio no fallback');

// --- regressao: '0' continua entrando, so espaco continua fora (campos com $de_para)
$ficha4 = ['nome' => 'X', 'telefone' => '48999990001', 'rg' => '0', 'nacionalidade' => '   '];
$m4 = wa_import_mapa_crm($ficha4);
ok($m4['campos']['rg'] === '0', "'0' continua entrando em campos");
ok(!array_key_exists('nacionalidade', $m4['campos']), 'campo so com espaco continua fora de campos');

echo "test-wa-import-crm OK\n";
