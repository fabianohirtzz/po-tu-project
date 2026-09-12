<?php
// tests/test-wa-import-carga.php — Task 9 (carga semente do CRM). Cobre as
// duas funcoes puras que scripts/importa-crm.php usa: achar a lista de
// fichas dentro do JSON exportado, e orquestrar candidato -> dedup ->
// leitura da base -> auditoria -> escrita, tudo injetado. Nada aqui toca
// rede nem arquivo.
require __DIR__ . '/../lib/wa-import-carga.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

/* ============================================================
   wa_import_acha_fichas: o export real e {"clients": [...778...], "trips":
   [...], "participations": [...], ...} - a raiz NAO e a lista. Devolve
   sempre ['fichas'=>.., 'chave'=>.., 'erro'=>..].
============================================================ */

// --- raiz ja e a lista (outro formato de export, sem chave nenhuma)
$raiz = [['nome' => 'A'], ['nome' => 'B']];
$rRaiz = wa_import_acha_fichas($raiz);
ok($rRaiz['fichas'] === $raiz && $rRaiz['chave'] === null && $rRaiz['erro'] === null,
   'raiz ja como lista de fichas e devolvida direto, sem chave');

// --- formato real: chave 'clients' entre varias outras listas de registros
$export = [
    'clients'        => [['nome' => 'A'], ['nome' => 'B'], ['nome' => 'C']],
    'trips'          => [['destino' => 'Grecia']],
    'participations' => [['id' => 1], ['id' => 2]],
];
$f = wa_import_acha_fichas($export);
ok($f['fichas'] === $export['clients'] && $f['chave'] === 'clients' && $f['erro'] === null,
   "'clients' e escolhida (e informada) mesmo nao sendo a unica lista de registros");

// --- sinonimo em pt-BR, sem 'clients'
$f2 = wa_import_acha_fichas(['clientes' => [['nome' => 'X']], 'outraCoisa' => [1, 2, 3]]);
ok($f2['fichas'] === [['nome' => 'X']] && $f2['chave'] === 'clientes', "'clientes' (sinonimo) e reconhecida");

// --- chave vem como STRING JSON em vez de array (serializacao dupla)
$f4 = wa_import_acha_fichas(['clients' => json_encode([['nome' => 'Z']])]);
ok($f4['fichas'] === [['nome' => 'Z']] && $f4['chave'] === 'clients', 'chave como string JSON e decodificada');

// --- nenhuma chave conhecida: fallback pega a MAIOR lista de registros
$f5 = wa_import_acha_fichas(['fornecedores' => [['nome' => 'F1']], 'contatosDoCrm' => [['nome' => 'G1'], ['nome' => 'G2']]]);
ok($f5['fichas'] === [['nome' => 'G1'], ['nome' => 'G2']] && $f5['chave'] === 'contatosDoCrm',
   'sem chave conhecida, escolhe a maior lista de registros (e informa qual foi)');

// --- nada aproveitavel: sem lista de registros em lugar nenhum
ok(wa_import_acha_fichas(['a' => 1, 'b' => 'texto'])['fichas'] === null, 'sem nenhuma lista de registros, fichas fica null');
ok(wa_import_acha_fichas(null)['fichas'] === null && wa_import_acha_fichas(null)['erro'] !== null, 'raiz null aborta com erro');
ok(wa_import_acha_fichas('nao e array')['fichas'] === null, 'raiz escalar aborta');
ok(wa_import_acha_fichas([])['fichas'] === null, 'objeto vazio aborta');

/* ============================================================
   O ACHADO DO REVISOR: uma chave CONHECIDA (clients) que existe mas nao
   valida tem que ABORTAR, nunca cair no fallback por tamanho. Sem esta
   trava, as tres degeneracoes abaixo faziam a busca devolver os 17
   registros de 'participations' em vez das fichas de cliente - com
   --aplicar, isso insere 17 leads-lixo (sem nome/telefone/cpf) marcados
   cliente/revisado.
============================================================ */
$participations = [['id' => 1], ['id' => 2]]; // 17 no arquivo real; 2 bastam pro teste

// (a) clients com um item que NAO e registro (array)
$degA = wa_import_acha_fichas(['clients' => [['nome' => 'A'], 'nao e registro'], 'participations' => $participations]);
ok($degA['fichas'] === null, 'clients com item invalido: aborta, nao cai em participations');
ok($degA['chave'] === 'clients', 'o erro aponta para a chave clients, nao para participations');
ok($degA['erro'] !== null && stripos($degA['erro'], 'clients') !== false, 'a mensagem de erro cita a chave clients');

// (b) clients vazia
$degB = wa_import_acha_fichas(['clients' => [], 'participations' => $participations]);
ok($degB['fichas'] === null, 'clients vazia: aborta, nao cai em participations');
ok($degB['chave'] === 'clients', 'o erro aponta para clients mesmo vazia');

// (c) clients como MAPA por id (nao e lista sequencial)
$degC = wa_import_acha_fichas(['clients' => ['id1' => ['nome' => 'A'], 'id2' => ['nome' => 'B']], 'participations' => $participations]);
ok($degC['fichas'] === null, 'clients como mapa por id: aborta, nao cai em participations');
ok($degC['chave'] === 'clients', 'o erro aponta para clients mesmo como mapa');

// controle: SEM nenhuma chave conhecida, 'participations' (unica lista
// valida) continua sendo aceita pelo fallback normalmente - o bloqueio e
// so quando uma chave CONHECIDA existe e falha, nao contra 'participations'
// em si.
$semNenhumaConhecida = wa_import_acha_fichas(['participations' => $participations]);
ok($semNenhumaConhecida['fichas'] === $participations && $semNenhumaConhecida['chave'] === 'participations',
   'sem nenhuma chave conhecida presente, o fallback por tamanho segue funcionando normalmente');

/* ============================================================
   wa_import_executa: leitura falha ABORTA antes de montar plano (nunca
   escreve). Sem isto, um Supabase fora do ar leria a base como vazia e as
   778 fichas do CRM entrariam duplicadas.
============================================================ */
$chamouInserir = 0; $chamouAtualizar = 0;
$inserir   = function ($l) use (&$chamouInserir) { $chamouInserir++; return ['id' => 'novo']; };
$atualizar = function ($id, $c) use (&$chamouAtualizar) { $chamouAtualizar++; return true; };

$fichaCrm = ['nome' => 'Antonio', 'telefone' => '48999990001', 'cpf' => '11144477735'];

$r = wa_import_executa([$fichaCrm], 'crm-toninho', fn() => null, $inserir, $atualizar, true);
ok($r['erro'] === true, 'leitura da base falhando marca erro');
ok($r['resumo'] === null, 'sem plano nenhum quando a leitura falha');
ok($chamouInserir === 0 && $chamouAtualizar === 0, 'leitura falhando NUNCA escreve, mesmo com --aplicar');

/* ============================================================
   DRY-RUN (aplicar=false): mesmo com plano que teria 'novo' e 'funde',
   inserir/atualizar nunca sao chamados. E o proprio ponto do dry-run.
============================================================ */
$chamouInserir = 0; $chamouAtualizar = 0;
$fichas = [
    ['nome' => 'Novo Cliente', 'telefone' => '48988887777', 'cpf' => ''],       // vira 'novo'
    ['nome' => 'Antonio', 'telefone' => '48999990001', 'cpf' => '11144477735'], // casa por cpf com a base
];
$baseAtual = [
    ['id' => 'L1', 'telefone' => '+5548999990001', 'cpf' => '111.444.777-35', 'nome' => 'Antonio',
     'email' => null, 'status' => 'venda', 'venda' => 5000, 'notas' => []],
];

$r = wa_import_executa($fichas, 'crm-toninho', fn() => $baseAtual, $inserir, $atualizar, false);
ok($r['erro'] === false, 'leitura ok nao marca erro');
ok($r['candidatos'] === 2, 'as duas fichas viraram candidatos (CRM dispensa marcador PO)');
ok($r['resumo']['novos'] === 1 && $r['resumo']['funde'] === 1, 'plano calculado normalmente em dry-run');
ok($r['aplicado'] === false, 'aplicado fica false em dry-run');
ok($r['escrita'] === null, 'escrita fica null em dry-run');
ok($chamouInserir === 0 && $chamouAtualizar === 0, 'dry-run NUNCA chama inserir/atualizar');

/* ============================================================
   Com --aplicar: agora sim escreve, e o resumo bate com o que foi escrito.
============================================================ */
$chamouInserir = 0; $chamouAtualizar = 0;
$r2 = wa_import_executa($fichas, 'crm-toninho', fn() => $baseAtual, $inserir, $atualizar, true);
ok($r2['aplicado'] === true, 'aplicado vira true com --aplicar');
ok($r2['escrita']['novos'] === 1, 'um insert realizado');
// a ficha do Antonio ja tinha telefone/nome/cpf iguais na base e email nulo
// nos dois lados: nao ha nada NOVO para preencher, entao a fusao conta como
// ignorada (nao como preenchida) - update com corpo vazio nao sai.
ok($r2['escrita']['ignorados'] === 1, 'fusao sem nada de novo para preencher conta como ignorada, nao como preenchida');
ok($r2['escrita']['preenchidos'] === 0, 'nenhum update de verdade saiu (nada estava vazio na base)');
ok($chamouInserir === 1, 'inserir foi chamado exatamente uma vez');
ok($chamouAtualizar === 0, 'atualizar nao foi chamado (fusao sem preenche nao escreve)');

/* ============================================================
   Fichas de CRM SEM telefone/cpf ainda viram candidato (o CRM dispensa o
   marcador "PO" e entra sempre) - e nao quebram o dedup.
============================================================ */
$semContato = [['nome' => 'Sem Contato Nenhum']];
$r3 = wa_import_executa($semContato, 'crm-toninho', fn() => [], null, null, false);
ok($r3['erro'] === false, 'base vazia de verdade (sem erro) processa normalmente');
ok($r3['candidatos'] === 1, 'ficha de CRM sem telefone/cpf ainda vira candidato');
ok($r3['resumo']['novos'] === 1, 'sem nada na base, vira novo');

echo "test-wa-import-carga OK\n";
