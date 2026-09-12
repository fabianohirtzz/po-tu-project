<?php
// tests/test-wa-import-audita.php
require __DIR__ . '/../lib/wa-import.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

/* Warning e notice contam como estouro. O dado aqui e de terceiro (agenda
   exportada, ficha do CRM antigo, lead velho do site) e chega torto: um
   aviso engolido pelo log do cPanel vira fusao errada que ninguem ve. */
error_reporting(E_ALL);
set_error_handler(function ($n, $s, $f, $l) { fwrite(STDERR, "PHP ERROR: $s em $f:$l\n"); exit(1); });

function cand($over = []) {
    return array_merge([
        'wa_id'=>null,'celulares'=>[],'fixos'=>[],'nome'=>'X','email'=>null,'cpf'=>null,
        'data_nascimento'=>null,'origem_import'=>'agenda-esposa','campos'=>[],'payload_import'=>[],
    ], $over);
}

// base: uma ficha do CRM com historico (ja e cliente), sem telefone
$base = [
  ['id'=>'L1','telefone'=>null,'cpf'=>'11144477735','nome'=>'Antonio','data_nascimento'=>'1981-09-11',
   'historico'=>true,'email'=>'a@x.com','cidade'=>'Floripa'],
  ['id'=>'L2','telefone'=>'+5511988887777','cpf'=>null,'nome'=>'Bruna','data_nascimento'=>null,
   'historico'=>false,'email'=>null,'cidade'=>null],
];

// --- casa por CPF, e a agenda traz o telefone que faltava: PREENCHE, nao sobrescreve
$plano = wa_import_audita([
  cand(['cpf'=>'11144477735','wa_id'=>'+5548999990001','celulares'=>['+5548999990001'],
        'nome'=>'Antonio Filho','email'=>'outro@x.com','origem_import'=>'agenda-esposa']),
], $base);
ok($plano[0]['acao'] === 'funde', 'cpf casa -> funde');
ok($plano[0]['match_por'] === 'cpf', 'casou por cpf');
ok($plano[0]['match_id'] === 'L1', 'casou na ficha certa');
ok($plano[0]['preenche']['telefone'] === '+5548999990001', 'preenche o telefone que faltava');
ok(!array_key_exists('email', $plano[0]['preenche']), 'email ja existe: NAO sobrescreve (regra 2)');
ok(!array_key_exists('nome', $plano[0]['preenche']), 'nome ja existe: NAO sobrescreve');
ok(!array_key_exists('status', $plano[0]['preenche']), 'status nunca e tocado (regra 1)');
ok($plano[0]['candidato']['origem_import'] === 'agenda-esposa', 'candidato volta inteiro no plano');

// --- casa por celular
$p2 = wa_import_audita([
  cand(['wa_id'=>'+5511988887777','celulares'=>['+5511988887777'],'nome'=>'Bruna Lima','email'=>'b@x.com']),
], $base);
ok($p2[0]['acao'] === 'funde' && $p2[0]['match_por'] === 'celular', 'celular casa -> funde');
ok($p2[0]['preenche']['email'] === 'b@x.com', 'preenche email que faltava');
ok(!array_key_exists('nome', $p2[0]['preenche']), 'nome ja preenchido na base nao e sobrescrito');

// --- so nome+nascimento: SUGERE, nao funde
$p3 = wa_import_audita([
  cand(['nome'=>'Antonio','data_nascimento'=>'1981-09-11','celulares'=>['+5548911112222'],'wa_id'=>'+5548911112222']),
], [['id'=>'L1','telefone'=>null,'cpf'=>'99999999999','nome'=>'Antonio','data_nascimento'=>'1981-09-11','historico'=>true]]);
ok($p3[0]['acao'] === 'revisar', 'so nome+nascimento nao funde sozinho');
ok($p3[0]['match_por'] === 'nome_nasc' && $p3[0]['match_id'] === 'L1', 'mas aponta o provavel');
ok($p3[0]['preenche'] === [], 'sugestao nao carrega escrita nenhuma');

// nome normalizado: caixa e espaco sobrando nao impedem a sugestao
$p3b = wa_import_audita([
  cand(['nome'=>'  ANTONIO   ','data_nascimento'=>'1981-09-11']),
], [['id'=>'L1','telefone'=>null,'cpf'=>null,'nome'=>'antonio','data_nascimento'=>'1981-09-11','historico'=>true]]);
ok($p3b[0]['acao'] === 'revisar' && $p3b[0]['match_por'] === 'nome_nasc', 'nome normalizado ainda sugere');

// mesmo nome, nascimento diferente: nao e a mesma pessoa
$p3c = wa_import_audita([
  cand(['nome'=>'Antonio','data_nascimento'=>'1975-01-02']),
], [['id'=>'L1','telefone'=>null,'cpf'=>null,'nome'=>'Antonio','data_nascimento'=>'1981-09-11','historico'=>true]]);
ok($p3c[0]['acao'] === 'novo', 'nascimento diferente nao casa');

// --- nada casa: novo
$p4 = wa_import_audita([cand(['wa_id'=>'+5548900000000','celulares'=>['+5548900000000'],'nome'=>'Novo'])], $base);
ok($p4[0]['acao'] === 'novo', 'sem match -> novo');

// --- fixo NUNCA casa identidade
$baseFixo = [['id'=>'LF','telefone'=>'+554832220000','cpf'=>null,'nome'=>'Empresa','data_nascimento'=>null,'historico'=>false]];
$p5 = wa_import_audita([cand(['fixos'=>['+554832220000'],'wa_id'=>null,'nome'=>'Outro - PO'])], $baseFixo);
ok($p5[0]['acao'] === 'novo', 'fixo igual nao funde pessoas diferentes');

// o mesmo fixo de empresa em tres contatos vira tres pessoas, nunca uma so
$p5b = wa_import_audita([
  cand(['fixos'=>['+554832220000'],'nome'=>'Um']),
  cand(['fixos'=>['+554832220000'],'nome'=>'Dois']),
  cand(['fixos'=>['+554832220000'],'nome'=>'Tres']),
], $baseFixo);
ok(count($p5b) === 3, 'um item de plano por candidato');
foreach ($p5b as $i => $it) ok($it['acao'] === 'novo' && $it['match_id'] === null, "fixo repetido nao funde ($i)");

/* Blindagem dos DOIS lados. O candidato separa celular de fixo na origem,
   entao o fixo da base so faria estrago se um numero fixo caisse por engano
   na lista de celulares de um candidato montado a mao. O indice de telefone
   da base tambem recusa fixo, e esta linha e o que prende essa recusa. */
$p5c = wa_import_audita([cand(['celulares'=>['+554832220000'],'wa_id'=>'+554832220000','nome'=>'Quatro'])], $baseFixo);
ok($p5c[0]['acao'] === 'novo' && $p5c[0]['match_id'] === null,
   'fixo nao casa nem vindo pela lista de celulares do candidato');

// --- a base guarda o historico como dono: mesmo casando, preenche NUNCA muda venda
$p6 = wa_import_audita([
  cand(['cpf'=>'11144477735','wa_id'=>'+5548999990001','celulares'=>['+5548999990001'],'campos'=>['profissao'=>'Medico']]),
], $base);
ok($p6[0]['preenche']['profissao'] === 'Medico', 'preenche campo de ficha que faltava');
ok(!array_key_exists('venda', $p6[0]['preenche']) && !array_key_exists('notas', $p6[0]['preenche']),
   'venda e notas nunca entram no preenche');

/* ============================================================
   Modos de falha que apagariam dado da cliente. Travados por teste.
============================================================ */

// --- 1) a whitelist barra o funil e o faturamento, venham de onde vierem.
//     Sem isso, importar a agenda zeraria o status e o valor de venda.
$pw = wa_import_audita([
  cand(['cpf'=>'11144477735','wa_id'=>'+5548999990001','celulares'=>['+5548999990001'],
        'campos'=>['status'=>'novo','venda'=>'12000','venda_at'=>'2026-01-01','notas'=>'nao cobrar',
                   'qualif_destino'=>'Grecia','qualif_data'=>'2027-03','historico'=>false,'id'=>'HACK',
                   'profissao'=>'Medico']]),
], $base);
ok($pw[0]['acao'] === 'funde', 'ainda funde por cpf');
foreach (['status','venda','venda_at','notas','qualif_destino','qualif_data','historico','id'] as $proibido) {
  ok(!array_key_exists($proibido, $pw[0]['preenche']), "$proibido nunca entra no preenche (whitelist)");
}
ok($pw[0]['preenche']['profissao'] === 'Medico', 'e o campo legitimo do mesmo lote passa');
ok($pw[0]['match_id'] === 'L1', 'e a ficha com historico segue sendo a dona');

// a whitelist e a trava estrutural: o proprio conteudo dela e verificado
foreach (['status','venda','venda_at','notas','qualif_destino','qualif_data','historico','id',
          'created_at','revisado','cliente'] as $proibido) {
  ok(!in_array($proibido, WA_IMPORT_CAMPOS_PREENCHIVEIS, true), "$proibido fora da whitelist");
}
ok(in_array('telefone', WA_IMPORT_CAMPOS_PREENCHIVEIS, true), 'telefone e preenchivel');
ok(in_array('cpf', WA_IMPORT_CAMPOS_PREENCHIVEIS, true), 'cpf e preenchivel');
ok(in_array('passaporte_numero', WA_IMPORT_CAMPOS_PREENCHIVEIS, true), 'bloco de ficha e preenchivel');

/* ------------------------------------------------------------
   wa_id NA FUSAO. O insert ja gravava wa_id no contato novo, mas o caminho
   'funde' monta o update a partir do 'preenche', que passa pela whitelist -
   e wa_id estava fora dela. A base tem 11 leads do formulario do site com
   telefone e wa_id nulo: a agenda traria o mesmo celular, fundiria, e o
   wa_id continuaria nulo. Na primeira mensagem da pessoa, wa_lead() consulta
   por wa_id, nao acha, e INSERE um lead novo - a duplicacao que o importador
   existe para fechar, deixada aberta no caminho central dele.
------------------------------------------------------------ */
ok(in_array('wa_id', WA_IMPORT_CAMPOS_PREENCHIVEIS, true), 'wa_id e preenchivel pela fusao');
ok(preg_match('/(^select=|,)wa_id(,|$)/', wa_import_select_base()) === 1,
   'e o select da base traz wa_id (sem ele a auditoria acha que esta sempre vazio)');

// (a) ficha do site: telefone preenchido, wa_id NULO -> a fusao preenche.
$baseSemWa = [wa_import_base_row(['id'=>'S1', 'telefone'=>'+5548999990001', 'wa_id'=>null,
                                  'nome'=>'Antonio', 'email'=>null, 'status'=>'venda', 'venda'=>9000])];
$pWa = wa_import_audita([cand(['wa_id'=>'+5548999990001','celulares'=>['+5548999990001'],
                               'nome'=>'Antonio PO','email'=>'a@x.com'])], $baseSemWa);
ok($pWa[0]['acao'] === 'funde' && $pWa[0]['match_id'] === 'S1', 'casou por celular com a ficha do site');
ok(($pWa[0]['preenche']['wa_id'] ?? null) === '+5548999990001',
   'a fusao preenche o wa_id que estava vazio');

// (b) ficha que JA tem wa_id: nunca sobrescrito, nem por um numero diferente.
// Trocar o wa_id de uma ficha jogaria a conversa dela para outro telefone.
$baseComWa = [wa_import_base_row(['id'=>'S2', 'telefone'=>'+5548999990001',
                                  'wa_id'=>'+5548911112222', 'nome'=>'Antonio',
                                  'email'=>null, 'status'=>'venda', 'venda'=>9000])];
$pWa2 = wa_import_audita([cand(['wa_id'=>'+5548999990001','celulares'=>['+5548999990001'],
                                'nome'=>'Antonio PO','email'=>'a@x.com'])], $baseComWa);
ok($pWa2[0]['acao'] === 'funde', 'funde do mesmo jeito');
ok(!array_key_exists('wa_id', $pWa2[0]['preenche']), 'wa_id ja preenchido NUNCA e sobrescrito');
ok(($pWa2[0]['preenche']['email'] ?? null) === 'a@x.com', 'e o campo realmente vazio segue sendo preenchido');

// (c) so celular vira wa_id: 'campos' e texto de arquivo de terceiro e agora
// passaria pela whitelist. Um wa_id vindo do arquivo (um fixo, ou qualquer
// coisa) faria o motor responder a pessoa errada.
$pWa3 = wa_import_audita([cand(['wa_id'=>'+5548999990001','celulares'=>['+5548999990001'],
                                'nome'=>'Antonio PO',
                                'campos'=>['wa_id'=>'+554833334444']])], $baseSemWa);
ok(($pWa3[0]['preenche']['wa_id'] ?? null) === '+5548999990001',
   'wa_id vindo do arquivo nao vence o celular validado pelo pipeline');
$nova = wa_import_linha_nova(['nome'=>'Novo','wa_id'=>'+5548999998888','origem_import'=>'agenda-esposa',
                              'campos'=>['wa_id'=>'+554833334444']]);
ok($nova['wa_id'] === '+5548999998888', 'e nem no insert do contato novo');

// --- 2) dois candidatos distintos na MESMA ficha: nada de escrita conflitante
//     em silencio. O primeiro funde; o segundo vai para revisao humana.
$baseUm = [['id'=>'L9','telefone'=>'+5548999990001','cpf'=>'11144477735','nome'=>'Antonio',
            'data_nascimento'=>null,'historico'=>true,'email'=>null]];
$pd = wa_import_audita([
  cand(['cpf'=>'11144477735','wa_id'=>'+5548911110000','celulares'=>['+5548911110000'],
        'nome'=>'Antonio Um','email'=>'um@x.com']),
  cand(['wa_id'=>'+5548999990001','celulares'=>['+5548999990001'],
        'nome'=>'Antonio Dois','email'=>'dois@x.com']),
], $baseUm);
ok($pd[0]['acao'] === 'funde' && $pd[0]['match_id'] === 'L9', 'o primeiro a casar funde');
ok($pd[0]['preenche']['email'] === 'um@x.com', 'e escreve o que faltava');
ok($pd[1]['match_id'] === 'L9', 'o segundo aponta a mesma ficha');
ok($pd[1]['acao'] !== 'funde', 'segundo candidato na mesma ficha nao escreve por cima do primeiro');
ok($pd[1]['acao'] !== 'novo', 'e tambem nao duplica a pessoa (regra 3)');
ok($pd[1]['acao'] === 'revisar', 'vai para revisao humana');
ok($pd[1]['preenche'] === [], 'revisar nunca carrega escrita');

// --- 2b) ficha da base SEM id: o casamento existe, mas a escrita seria
//     inexequivel (a Task 7 grava por id) e $usados nao enxergaria nada, entao
//     dois candidatos sairiam os dois como funde. Vira revisar.
$baseSemId = [['telefone'=>'+5548999990001','cpf'=>'11144477735','nome'=>'Antonio',
               'data_nascimento'=>null,'historico'=>true,'email'=>null]];
$ps = wa_import_audita([
  cand(['cpf'=>'11144477735','nome'=>'Um','email'=>'um@x.com']),
  cand(['wa_id'=>'+5548999990001','celulares'=>['+5548999990001'],'nome'=>'Dois','email'=>'dois@x.com']),
], $baseSemId);
ok($ps[0]['acao'] === 'revisar', 'ficha sem id nao vira funde (escrita inexequivel)');
ok($ps[0]['match_id'] === null && $ps[0]['match_por'] === 'cpf', 'mas registra por onde casou');
ok($ps[1]['acao'] === 'revisar', 'o segundo candidato na mesma ficha sem id tambem');
ok($ps[0]['preenche'] === [] && $ps[1]['preenche'] === [], 'e nenhum dos dois carrega escrita');
ok(array_filter($ps, function ($x) { return $x['acao'] === 'funde'; }) === [],
   'base sem id nao produz fusao nenhuma (nem uma escrita cega)');
ok(array_filter($ps, function ($x) { return $x['acao'] === 'novo'; }) === [],
   'e tambem nao duplica a pessoa');

// id nao-escalar na base: isset($usados[$id]) daria TypeError fatal
$pt = wa_import_audita([
  cand(['cpf'=>'11144477735','nome'=>'Um']),
  cand(['cpf'=>'11144477735','nome'=>'Dois']),
], [['id'=>['x'],'cpf'=>'11144477735','nome'=>'Antonio','data_nascimento'=>null,'historico'=>true]]);
ok($pt[0]['acao'] === 'revisar' && $pt[0]['match_id'] === null, 'id nao-escalar vira revisar, sem estourar');
ok($pt[1]['acao'] === 'revisar', 'e o segundo tambem');

// id em branco na base conta como ausente
$pu = wa_import_audita([cand(['cpf'=>'11144477735','nome'=>'Um'])],
  [['id'=>'   ','cpf'=>'11144477735','nome'=>'Antonio','data_nascimento'=>null,'historico'=>true]]);
ok($pu[0]['acao'] === 'revisar' && $pu[0]['match_id'] === null, 'id em branco conta como ausente');

// --- 3) sem cpf, sem celular e sem nascimento -> novo, jamais funde
//     (o nome aqui e identico ao da base de proposito: nome sozinho nao e identidade)
$pn = wa_import_audita([cand(['nome'=>'Antonio','wa_id'=>null,'celulares'=>[],'fixos'=>[]])], $base);
ok($pn[0]['acao'] === 'novo', 'sem cpf, sem celular e sem nascimento -> novo');
ok($pn[0]['match_id'] === null && $pn[0]['match_por'] === null, 'e sem apontar ficha nenhuma');
ok($pn[0]['preenche'] === [], 'novo nao preenche ficha alheia');

// --- regra 1 na indexacao: entre duas fichas do mesmo numero, a que tem
//     historico e a dona. A sem historico nunca rouba o casamento.
$baseDupla = [
  ['id'=>'LS','telefone'=>'+5548999990001','cpf'=>null,'nome'=>'Antonio','data_nascimento'=>null,'historico'=>false],
  ['id'=>'LH','telefone'=>'+5548999990001','cpf'=>null,'nome'=>'Antonio','data_nascimento'=>null,'historico'=>true],
];
$ph = wa_import_audita([cand(['wa_id'=>'+5548999990001','celulares'=>['+5548999990001']])], $baseDupla);
ok($ph[0]['match_id'] === 'LH', 'a ficha com historico e a dona do casamento (regra 1)');

// e a ordem em que o banco devolveu as linhas nao pode mudar o resultado
$ph2 = wa_import_audita([cand(['wa_id'=>'+5548999990001','celulares'=>['+5548999990001']])],
                        array_reverse($baseDupla));
ok($ph2[0]['match_id'] === 'LH', 'ordem invertida na base nao tira a ficha com historico');

// --- base em formato livre (lead velho do site, digitado a mao no painel)
//     ainda casa: senao o importador criaria a segunda ficha da mesma pessoa.
$baseCru = [['id'=>'LC','telefone'=>'(48) 99999-0001','cpf'=>'111.444.777-35','nome'=>'Antonio',
             'data_nascimento'=>null,'historico'=>true]];
ok(wa_import_audita([cand(['cpf'=>'11144477735'])], $baseCru)[0]['match_por'] === 'cpf',
   'cpf formatado na base ainda casa');
ok(wa_import_audita([cand(['wa_id'=>'+5548999990001','celulares'=>['+5548999990001']])], $baseCru)[0]['match_por'] === 'celular',
   'telefone em formato livre na base ainda casa');

// --- a fusao so soma: string vazia e espaco na base contam como vazio,
//     e valor vazio no candidato nunca vira escrita de branco.
$baseVazios = [['id'=>'LV','telefone'=>'+5548999990001','cpf'=>null,'nome'=>'   ','data_nascimento'=>null,
                'historico'=>true,'email'=>'','cidade'=>'Floripa']];
$pv = wa_import_audita([
  cand(['wa_id'=>'+5548999990001','celulares'=>['+5548999990001'],'nome'=>'Antonio','email'=>'a@x.com',
        'campos'=>['cidade'=>'','estado'=>'  ','bairro'=>'Centro']]),
], $baseVazios);
ok($pv[0]['preenche']['nome'] === 'Antonio', 'so espaco na base conta como vazio');
ok($pv[0]['preenche']['email'] === 'a@x.com', 'string vazia na base conta como vazio');
ok(!array_key_exists('cidade', $pv[0]['preenche']), 'valor vazio no candidato nao apaga a base');
ok(!array_key_exists('estado', $pv[0]['preenche']), 'so espaco no candidato nao vira escrita');
ok($pv[0]['preenche']['bairro'] === 'Centro', 'campo real do mesmo lote passa');

/* ============================================================
   Dado de terceiro: nada pode estourar.
============================================================ */

ok(wa_import_audita([], $base) === [], 'lote vazio devolve lista vazia');
ok(wa_import_audita([], []) === [], 'lote e base vazios');

$pz = wa_import_audita([cand(['cpf'=>'11144477735','celulares'=>['+5548999990001']])], []);
ok($pz[0]['acao'] === 'novo', 'base vazia: tudo e novo');

$pi = wa_import_audita([['nome'=>'So nome']], $base);
ok($pi[0]['acao'] === 'novo' && $pi[0]['nome'] === 'So nome', 'candidato sem as chaves nao estoura');

$pj = wa_import_audita([cand(['cpf'=>'11144477735','celulares'=>['+5548999990001'],'wa_id'=>'+5548999990001'])],
                       [['id'=>'LX'], ['nome'=>'sem id']]);
ok($pj[0]['acao'] === 'novo', 'ficha da base sem colunas nao estoura');

// valor nao-escalar dos dois lados: nao estoura, nao vira "Array" no banco
$pk = wa_import_audita([
  // 'cidade' PRECISA ser oferecida aqui: sem ela o guard da base nunca e
  // consultado e a assercao de baixo passaria por vacuidade.
  cand(['cpf'=>'11144477735','campos'=>['profissao'=>['a','b'],'cidade'=>'Sao Paulo','bairro'=>'Centro']]),
], [['id'=>'L1','telefone'=>null,'cpf'=>'11144477735','nome'=>'Antonio','data_nascimento'=>null,
     'historico'=>true,'cidade'=>['x'=>'y']]]);
ok(!array_key_exists('profissao', $pk[0]['preenche']), 'valor nao-escalar do candidato nao entra');
ok(!array_key_exists('cidade', $pk[0]['preenche']), 'valor estranho na base nao e tratado como vazio');
ok($pk[0]['preenche']['bairro'] === 'Centro', 'e o resto do lote segue normal');

// telefone/cpf ilegiveis nao viram indice nem casamento
$pl = wa_import_audita([cand(['cpf'=>'123','wa_id'=>'abc','celulares'=>['abc']])],
                       [['id'=>'LY','telefone'=>'abc','cpf'=>'123','nome'=>'Z','data_nascimento'=>null,'historico'=>false]]);
ok($pl[0]['acao'] === 'novo', 'lixo nao casa com lixo');

// forma do retorno: as seis chaves, sempre
foreach (['nome','acao','match_id','match_por','preenche','candidato'] as $k) {
  ok(array_key_exists($k, $plano[0]), "retorno tem a chave $k");
}
ok(in_array($plano[0]['acao'], ['novo','funde','revisar'], true), 'acao e um dos tres valores');

echo "test-wa-import-audita OK\n";
