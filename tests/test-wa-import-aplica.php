<?php
// tests/test-wa-import-aplica.php — a camada que grava, com os escritores
// injetados. Nenhuma linha daqui toca banco nem rede.
require __DIR__ . '/../lib/wa-import.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

/* Warning conta como estouro: o dado vem de agenda exportada e de ficha de
   CRM antigo, e um aviso engolido pelo log do cPanel vira escrita errada. */
error_reporting(E_ALL);
set_error_handler(function ($n, $s, $f, $l) { fwrite(STDERR, "PHP ERROR: $s em $f:$l\n"); exit(1); });

$inseridos = []; $atualizados = [];
$inserir = function ($linha) use (&$inseridos) { $inseridos[] = $linha; return ['id' => 'novo-' . (count($inseridos))]; };
$atualizar = function ($id, $campos) use (&$atualizados) { $atualizados[] = [$id, $campos]; return true; };

$plano = [
  ['nome'=>'Novo','acao'=>'novo','match_id'=>null,'match_por'=>null,'preenche'=>[],
   'candidato'=>['wa_id'=>'+5548900000000','nome'=>'Novo','email'=>null,'cpf'=>null,
                 'origem_import'=>'agenda-esposa','campos'=>['cidade'=>'Floripa'],'payload_import'=>['x'=>1]]],
  ['nome'=>'Antonio','acao'=>'funde','match_id'=>'L1','match_por'=>'cpf',
   'preenche'=>['telefone'=>'+5548999990001'],'candidato'=>[]],
  ['nome'=>'Talvez','acao'=>'revisar','match_id'=>'L9','match_por'=>'nome_nasc','preenche'=>[],'candidato'=>[]],
  ['nome'=>'JaCompleto','acao'=>'funde','match_id'=>'L2','match_por'=>'celular','preenche'=>[],'candidato'=>[]],
];

$r = wa_import_aplica($plano, $inserir, $atualizar);
ok($r['novos'] === 1, 'um insert');
ok($r['preenchidos'] === 1, 'um update com preenche nao vazio');
ok($r['revisar'] === 1, 'um em revisao, nao escrito');
ok($r['ignorados'] === 1, 'funde sem preenche nao escreve nada');
ok(count($inseridos) === 1 && count($atualizados) === 1, 'so as escritas certas aconteceram');

// o insert leva o payload e a origem, e o telefone vira a coluna telefone
ok($inseridos[0]['telefone'] === '+5548900000000', 'novo leva o wa_id como telefone');
ok($inseridos[0]['origem_import'] === 'agenda-esposa', 'origem gravada no novo');
ok($inseridos[0]['cidade'] === 'Floripa', 'campos do candidato viram colunas');
ok(isset($inseridos[0]['payload_import']), 'payload guardado');
ok($inseridos[0]['revisado'] === false, 'novo de agenda entra como nao revisado');
ok($inseridos[0]['cliente'] === false, 'novo de agenda nao nasce marcado como cliente');

// o update so mandou o preenche, nada mais (nao toca historico)
ok($atualizados[0] === ['L1', ['telefone'=>'+5548999990001']], 'update manda so o preenche');

/* ============================================================
   A whitelist da Task 6 so vale se a escrita mandar EXATAMENTE as chaves de
   'preenche'. Montar o update a partir do candidato cru transformaria a
   protecao em decoracao, e uma importacao de agenda apagaria o funil e o
   faturamento da cliente em silencio.
============================================================ */
$inseridos = []; $atualizados = [];
$r = wa_import_aplica([
  ['nome'=>'Maria','acao'=>'funde','match_id'=>'L7','match_por'=>'celular',
   'preenche'=>['cidade'=>'Tubarao','email'=>'m@x.com'],
   /* o candidato carrega coisas que NUNCA podem ser escritas numa fusao */
   'candidato'=>['wa_id'=>'+5548911112222','nome'=>'Maria','status'=>'novo','venda'=>0,
                 'notas'=>[],'campos'=>['observacoes'=>'da agenda']]],
], $inserir, $atualizar);
ok($r['preenchidos'] === 1, 'fundiu');
ok(count($inseridos) === 0, 'fusao nao insere nada');
$campos = $atualizados[0][1];
ok(array_keys($campos) === ['cidade', 'email'], 'update escreve exatamente as chaves de preenche');
foreach (['status','venda','venda_at','notas','observacoes','telefone','nome','id'] as $proibido) {
    ok(!array_key_exists($proibido, $campos), "update nunca manda $proibido (fora do preenche)");
}

/* ============================================================
   'revisar' NAO escreve, nos tres sabores em que a Task 6 o emite.
============================================================ */
$inseridos = []; $atualizados = [];
$r = wa_import_aplica([
  // (a) palpite por nome+nascimento
  ['nome'=>'A','acao'=>'revisar','match_id'=>'L1','match_por'=>'nome_nasc','preenche'=>[],'candidato'=>['nome'=>'A']],
  // (b) ficha ja reservada por outro candidato do mesmo lote
  ['nome'=>'B','acao'=>'revisar','match_id'=>'L2','match_por'=>'cpf','preenche'=>['cidade'=>'X'],'candidato'=>['nome'=>'B']],
  // (c) base sem coluna id: casou, mas a escrita seria inexequivel
  ['nome'=>'C','acao'=>'revisar','match_id'=>null,'match_por'=>'celular','preenche'=>['cidade'=>'Y'],'candidato'=>['nome'=>'C']],
], $inserir, $atualizar);
ok($r['revisar'] === 3, 'os tres contam como revisar');
ok($inseridos === [] && $atualizados === [], 'revisar nao escreve nada, nem com preenche cheio');

/* ============================================================
   Escrita que falha nao pode virar numero verde no relatorio: wa_db_insert
   devolve null e wa_db_update devolve false quando o PostgREST recusa.
============================================================ */
$r = wa_import_aplica([
  ['nome'=>'X','acao'=>'novo','match_id'=>null,'match_por'=>null,'preenche'=>[],'candidato'=>['nome'=>'X','origem_import'=>'agenda-esposa']],
  ['nome'=>'Y','acao'=>'funde','match_id'=>'L1','match_por'=>'cpf','preenche'=>['cidade'=>'Z'],'candidato'=>[]],
], fn($l) => null, fn($id, $c) => false);
ok($r['novos'] === 0 && $r['preenchidos'] === 0, 'escrita recusada nao conta como feita');
ok($r['falhas'] === 2, 'as duas falhas sao relatadas');

/* ============================================================
   Linha nova: CRM entra revisado e ja como cliente (a ficha e de quem ja
   viajou); agenda entra para revisao humana (spec 9.4).
============================================================ */
$lc = wa_import_linha_nova(['nome'=>'Toninho','wa_id'=>'+5548999990001','cpf'=>'11144477735',
    'origem_import'=>'crm-toninho','campos'=>['rg'=>'123','cidade'=>'Floripa'],'payload_import'=>['raw'=>'{}']]);
ok($lc['revisado'] === true && $lc['cliente'] === true, 'ficha de CRM entra revisada e como cliente');
ok($lc['origem'] === 'importado', 'origem marcada como importado');
ok($lc['rg'] === '123', 'campos da ficha viram colunas');

/* Chave fora da whitelist nunca vai para o insert: uma coluna inexistente
   derruba o INSERT inteiro no PostgREST e o contato se perde. */
$lx = wa_import_linha_nova(['nome'=>'Z','origem_import'=>'agenda-esposa',
    'campos'=>['cidade'=>'Floripa','status'=>'venda','venda'=>9999,'coluna_que_nao_existe'=>'x']]);
ok($lx['cidade'] === 'Floripa', 'campo da whitelist entra');
foreach (['status','venda','coluna_que_nao_existe'] as $proibido) {
    ok(!array_key_exists($proibido, $lx), "insert nunca leva $proibido");
}

/* ============================================================
   Colunas 'date' da po_leads: data_nascimento, passaporte_emissao e
   passaporte_validade. Texto torto ali ou derruba o insert inteiro no
   PostgREST, ou (pior) entra silenciosamente errado, porque o Postgres le
   "11/09/1981" como 9 de novembro no DateStyle padrao (MDY).
============================================================ */
ok(wa_import_data_iso('1981-09-11') === '1981-09-11', 'ISO passa');
ok(wa_import_data_iso('1981-09-11T00:00:00Z') === '1981-09-11', 'ISO com hora vira so a data');
ok(wa_import_data_iso('11/09/1981') === '1981-09-11', 'data BR vira ISO (11 de setembro, nao 9 de novembro)');
ok(wa_import_data_iso('11-09-1981') === '1981-09-11', 'data BR com traco vira ISO');
ok(wa_import_data_iso('19810911') === '1981-09-11', 'BDAY basico do vCard vira ISO');
ok(wa_import_data_iso('31/02/1981') === '', 'data que nao existe no calendario sai');
ok(wa_import_data_iso('nao informado') === '', 'texto solto sai');
ok(wa_import_data_iso('') === '' && wa_import_data_iso(null) === '' && wa_import_data_iso(['a']) === '', 'vazio e nao-escalar saem');

$inseridos = []; $atualizados = [];
wa_import_aplica([
  ['nome'=>'D','acao'=>'novo','match_id'=>null,'match_por'=>null,'preenche'=>[],
   'candidato'=>['nome'=>'D','origem_import'=>'crm-toninho','data_nascimento'=>'11/09/1981',
                 'campos'=>['passaporte_validade'=>'30/06/2031','passaporte_emissao'=>'data ilegivel']]],
  ['nome'=>'E','acao'=>'funde','match_id'=>'L5','match_por'=>'cpf',
   'preenche'=>['data_nascimento'=>'11/09/1981','cidade'=>'Floripa'],'candidato'=>[]],
], $inserir, $atualizar);
ok($inseridos[0]['data_nascimento'] === '1981-09-11', 'insert normaliza a data de nascimento');
ok($inseridos[0]['passaporte_validade'] === '2031-06-30', 'insert normaliza a validade do passaporte');
ok(!array_key_exists('passaporte_emissao', $inseridos[0]), 'data ilegivel sai do insert em vez de derrubar a linha');
ok($atualizados[0][1] === ['data_nascimento'=>'1981-09-11','cidade'=>'Floripa'], 'update normaliza a data e nao inventa chave');

/* ============================================================
   O PLANO MOSTRADO NA TELA E O QUE SERA ESCRITO. O saneamento das datas
   roda dentro do wa_import_preenche, nao so na hora de gravar: com ele so
   no wa_import_aplica, o preview listava 'data_nascimento' em 'preenche',
   o aplicar descartava a data ilegivel, e quando ela era a UNICA chave a
   promessa "vai completar uma ficha" virava um 'ignorado' calado.
============================================================ */
$baseVazia = wa_import_base_row(['id'=>'L8','telefone'=>'+5548999990001','nome'=>'Antonio']);
$pr = wa_import_preenche($baseVazia, [
    'wa_id' => null, 'nome' => null, 'email' => null, 'cpf' => null,
    'data_nascimento' => '11/09/1981',
    'campos' => ['passaporte_validade' => '30/06/2031', 'passaporte_emissao' => 'data ilegivel'],
]);
ok($pr['data_nascimento'] === '1981-09-11', 'o preview ja mostra a data normalizada');
ok($pr['passaporte_validade'] === '2031-06-30', 'e a validade tambem');
ok(!array_key_exists('passaporte_emissao', $pr), 'data ilegivel nao e prometida no preview');

/* E quando a data ilegivel era a UNICA chave, o preview nao pode dizer
   'funde com 1 campo' para o aplicar depois nao escrever nada. */
$so = wa_import_preenche(wa_import_base_row(['id'=>'L8','nome'=>'Antonio','telefone'=>'+5548999990001']),
    ['wa_id'=>null,'nome'=>null,'email'=>null,'cpf'=>null,'data_nascimento'=>'ontem','campos'=>[]]);
ok($so === [], 'preenche so com data ilegivel sai vazio ja no preview');

/* O que a tela lista e exatamente o que o aplicar manda: mesmas chaves. */
$planoP = wa_import_audita([
  ['wa_id'=>'+5548999990001','celulares'=>['+5548999990001'],'fixos'=>[],'nome'=>'Antonio',
   'email'=>null,'cpf'=>null,'data_nascimento'=>'11/09/1981','origem_import'=>'agenda-esposa',
   'campos'=>['passaporte_emissao'=>'data ilegivel'],'payload_import'=>[]],
], [wa_import_base_row(['id'=>'L8','telefone'=>'+5548999990001','nome'=>'Antonio','status'=>'venda','venda'=>10])]);
$inseridos = []; $atualizados = [];
wa_import_aplica($planoP, $inserir, $atualizar);
ok(array_keys($planoP[0]['preenche']) === array_keys($atualizados[0][1]),
   'as chaves do preview sao as mesmas que o aplicar escreve');
ok(!array_key_exists('passaporte_emissao', $planoP[0]['preenche']), 'a data ilegivel ja nao aparece no plano');

/* Fusao cuja unica chave era uma data ilegivel nao vira update vazio (o
   PATCH sem corpo e um pedido inutil ao banco) e nao conta como preenchido. */
$inseridos = []; $atualizados = [];
$r = wa_import_aplica([
  ['nome'=>'F','acao'=>'funde','match_id'=>'L6','match_por'=>'cpf','preenche'=>['data_nascimento'=>'ontem'],'candidato'=>[]],
], $inserir, $atualizar);
ok($atualizados === [], 'update nao sai com o corpo vazio');
ok($r['ignorados'] === 1 && $r['preenchidos'] === 0, 'preenche que sobrou vazio conta como ignorado');

/* ============================================================
   wa_import_base_row: o booleano 'historico' e a base inteira da regra
   "o registro com historico e o dono". wa_import_indexa confia nele e NAO
   tem como perceber se ele vier sempre false - a regra degradaria calada.
============================================================ */
ok(wa_import_base_row(['id'=>'1','status'=>'venda','venda'=>1000,'notas'=>[]])['historico'] === true, 'venda = historico');
ok(wa_import_base_row(['id'=>'2','status'=>'novo','venda'=>0,'notas'=>[]])['historico'] === false, 'novo sem nada = sem historico');
ok(wa_import_base_row(['id'=>'3','status'=>'semresposta','venda'=>0,'notas'=>['oi']])['historico'] === true, 'nota = historico');
ok(wa_import_base_row(['id'=>'4','status'=>'atendimento','venda'=>0,'notas'=>[]])['historico'] === true, 'status avancado = historico');
ok(wa_import_base_row(['id'=>'5','status'=>'negociacao','venda'=>0,'notas'=>[]])['historico'] === true, 'negociacao = historico');
ok(wa_import_base_row(['id'=>'6','status'=>'perdido','venda'=>0,'notas'=>[]])['historico'] === true, 'perdido tambem e passado com a agencia');
ok(wa_import_base_row(['id'=>'7','status'=>'semresposta','venda'=>0,'notas'=>[]])['historico'] === false, 'lead do site parado = sem historico');
ok(wa_import_base_row(['id'=>'8'])['historico'] === false, 'linha sem as colunas de historico nao inventa historico');
// venda e notas chegam do PostgREST como texto/json conforme o caso
ok(wa_import_base_row(['id'=>'9','status'=>'novo','venda'=>'2500.00','notas'=>'[]'])['historico'] === true, 'venda em texto conta');
ok(wa_import_base_row(['id'=>'10','status'=>'novo','venda'=>'0','notas'=>'[]'])['historico'] === false, 'notas "[]" em texto nao e nota');

/* A linha da base precisa chegar INTEIRA na auditoria: 'id' (sem ele nada
   funde) e toda coluna da whitelist (sem ela, wa_import_preenche acha que o
   campo esta vazio e SOBRESCREVE o que a cliente digitou a mao). */
$b = wa_import_base_row(['id'=>'L1','telefone'=>'+5548999990001','cpf'=>'034','nome'=>'A',
    'data_nascimento'=>'1981-09-11','email'=>'a@x.com','cidade'=>'Floripa',
    'observacoes'=>'digitado no painel','status'=>'venda','venda'=>10,'notas'=>[]]);
ok($b['id'] === 'L1', 'id preservado');
ok($b['observacoes'] === 'digitado no painel', 'coluna da whitelist preservada (nao vira campo vazio)');
foreach (['telefone','cpf','nome','data_nascimento','email','cidade'] as $k) {
    ok(array_key_exists($k, $b), "coluna $k preservada");
}
ok(array_key_exists('id', wa_import_base_row([])), 'id sempre existe como chave, mesmo ausente na origem');
ok(wa_import_base_row([])['id'] === null, 'id ausente vira null (a auditoria manda para revisao)');

/* O select da base e derivado da whitelist, entao nunca pode ficar para
   tras dela. Sem 'id' toda linha ficaria sem dono; sem status/venda/notas o
   'historico' seria sempre false. */
$sel = wa_import_select_base();
foreach (array_merge(['id','status','venda','notas'], WA_IMPORT_CAMPOS_PREENCHIVEIS) as $col) {
    ok(preg_match('/(^select=|,)' . preg_quote($col, '/') . '(,|$)/', $sel) === 1, "o select da base traz $col");
}

/* ============================================================
   Resumo: e o que a tela de revisao mostra antes de qualquer escrita.
============================================================ */
$resumo = wa_import_resumo([
  ['acao'=>'novo','match_por'=>null],['acao'=>'funde','match_por'=>'cpf'],
  ['acao'=>'funde','match_por'=>'celular'],['acao'=>'revisar','match_por'=>'nome_nasc'],
]);
ok($resumo['novos'] === 1 && $resumo['funde'] === 2 && $resumo['revisar'] === 1, 'contagem por acao');
ok($resumo['por_cpf'] === 1 && $resumo['por_celular'] === 1, 'contagem por chave de casamento');
ok($resumo['por_nome_nasc'] === 1, 'contagem do palpite por nome+nascimento');
ok($resumo['total'] === 4, 'total confere com o tamanho do plano');
ok(wa_import_resumo([])['total'] === 0, 'plano vazio nao estoura');

/* REPRODUCAO do resumo que se contradizia: o 'revisar' TAMBEM guarda o
   match_por (casou por CPF, mas outro candidato do lote ja reservou a
   ficha). Contando por chave sobre o plano inteiro, tres candidatos de
   mesmo CPF contra uma ficha so davam "1 vai completar uma ficha que ja
   existe (3 por CPF)" - e a tela e a unica peca que a cliente le antes de
   autorizar a escrita. As contagens por chave sao escopadas por acao. */
$conflito = wa_import_resumo([
  ['acao'=>'funde','match_por'=>'cpf'],
  ['acao'=>'revisar','match_por'=>'cpf'],
  ['acao'=>'revisar','match_por'=>'cpf'],
]);
ok($conflito['funde'] === 1 && $conflito['revisar'] === 2, 'as acoes continuam contadas');
ok($conflito['por_cpf'] === 1, 'por_cpf conta so o que de fato funde (era 3)');
ok($conflito['por_cpf'] + $conflito['por_celular'] <= $conflito['funde'],
   'o detalhamento nunca promete mais casamentos do que fusoes');

// o detalhamento do 'revisar' e o palpite por nome+nascimento, e so ele
$rev = wa_import_resumo([
  ['acao'=>'revisar','match_por'=>'nome_nasc'],
  ['acao'=>'revisar','match_por'=>'celular'],
  ['acao'=>'funde','match_por'=>'celular'],
]);
ok($rev['por_nome_nasc'] === 1, 'por_nome_nasc conta so o palpite');
ok($rev['por_celular'] === 1, 'revisar por celular nao infla o detalhe da fusao');

/* ============================================================
   Contato que so tem TELEFONE FIXO. A Task 6 o deixa passar de proposito
   (fixo nunca casa identidade: um fixo de empresa aparece em tres fichas de
   pessoas diferentes). Mas o numero precisa chegar em alguma coluna: sem
   isto o lead nascia com telefone null e o +55 48 3222-0000 so sobrevivia
   dentro do payload_import.raw, que ninguem le. O lead entrava inalcancavel.
   Destino e telefone_secundario - no 'telefone' ele pareceria celular e
   nunca mais casaria, porque a auditoria so indexa quando wa_e_celular.
============================================================ */
$lf = wa_import_linha_nova(['nome'=>'Loja do Seu Ze','wa_id'=>null,'fixos'=>['+554832220000'],
    'origem_import'=>'agenda-esposa','campos'=>[]]);
ok($lf['telefone_secundario'] === '+554832220000', 'fixo vai para telefone_secundario');
ok($lf['telefone'] === null, 'fixo nao entra como telefone (la nunca casaria)');

$lb = wa_import_linha_nova(['nome'=>'A','wa_id'=>'+5548999990001','fixos'=>['+554832220000'],
    'origem_import'=>'agenda-esposa']);
ok($lb['telefone'] === '+5548999990001' && $lb['telefone_secundario'] === '+554832220000',
   'com celular e fixo, cada um na sua coluna');

$lcs = wa_import_linha_nova(['nome'=>'A','fixos'=>['+554832220000'],'origem_import'=>'crm-toninho',
    'campos'=>['telefone_secundario'=>'+554899990000']]);
ok($lcs['telefone_secundario'] === '+554899990000', 'telefone_secundario da ficha vence o fixo da agenda');

// e na fusao: ficha da base sem secundario recebe o fixo da agenda
$pf = wa_import_audita([
  ['wa_id'=>'+5548999990001','celulares'=>['+5548999990001'],'fixos'=>['+554832220000'],
   'nome'=>'Antonio','email'=>null,'cpf'=>null,'data_nascimento'=>null,
   'origem_import'=>'agenda-esposa','campos'=>[],'payload_import'=>[]],
], [wa_import_base_row(['id'=>'L1','telefone'=>'+5548999990001','nome'=>'Antonio','status'=>'venda','venda'=>10])]);
ok($pf[0]['acao'] === 'funde', 'casou por celular');
ok($pf[0]['preenche']['telefone_secundario'] === '+554832220000', 'a fusao guarda o fixo em telefone_secundario');

/* ============================================================
   ORIGEM: lista fechada, nao prefixo. 'crm' como prefixo deixava 'crmx' e
   'crm-qualquer-coisa' marcarem contato como cliente JA REVISADO - e a
   secao 8.1 da spec trata "contato nao revisado nunca entra em campanha"
   como defesa obrigatoria: revisado=true indevido poe a pessoa num disparo
   de marketing sem nunca ter passado pela revisao da cliente.
============================================================ */
ok(wa_import_origem_valida('crm-toninho') === true, 'crm-toninho e origem valida');
ok(wa_import_origem_valida('agenda-esposa') === true, 'agenda-esposa e origem valida');
ok(wa_import_origem_valida('crmx') === false, 'crmx nao e origem valida');
ok(wa_import_origem_valida('crm-qualquer-coisa') === false, 'crm- seguido de qualquer coisa nao vale');
ok(wa_import_origem_valida('') === false, 'origem vazia nao vale');

ok(wa_import_eh_crm('crm-toninho') === true, 'crm-toninho e ficha de CRM');
ok(wa_import_eh_crm('crmx') === false, 'crmx NAO e ficha de CRM (quase-acerto)');
ok(wa_import_eh_crm('agenda-esposa') === false, 'agenda nao e ficha de CRM');

$lq = wa_import_linha_nova(['nome'=>'Z','origem_import'=>'crmx','campos'=>[]]);
ok($lq['cliente'] === false && $lq['revisado'] === false, 'quase-acerto de origem nao marca cliente revisado');
$lok = wa_import_linha_nova(['nome'=>'Z','origem_import'=>'crm-toninho','campos'=>[]]);
ok($lok['cliente'] === true && $lok['revisado'] === true, 'a ficha de CRM de verdade continua entrando revisada');

// e a origem desconhecida volta a exigir o marcador "PO" no nome
ok(wa_import_candidato(['nome'=>'Maria Silva','telefones'=>['48999990001']], 'crmx') === null,
   'origem desconhecida nao dispensa o marcador PO');
ok(wa_import_candidato(['nome'=>'Maria Silva','telefones'=>['48999990001']], 'crm-toninho') !== null,
   'a ficha de CRM segue dispensando o marcador');

// O motor casa lead por wa_id e INSERE quando nao acha. Sem gravar wa_id aqui,
// a ficha rica duplica na primeira mensagem que a pessoa mandar.
$comCel = wa_import_linha_nova([
  'wa_id' => '+5548999990001', 'celulares' => ['+5548999990001'], 'fixos' => [],
  'nome' => 'Ana', 'email' => null, 'cpf' => null, 'data_nascimento' => null,
  'origem_import' => 'agenda-esposa', 'campos' => [], 'payload_import' => [],
]);
ok($comCel['wa_id'] === '+5548999990001', 'candidato com celular grava wa_id');
ok($comCel['telefone'] === '+5548999990001', 'e o telefone continua preenchido');

$soFixo = wa_import_linha_nova([
  'wa_id' => null, 'celulares' => [], 'fixos' => ['+554832220000'],
  'nome' => 'Loja', 'email' => null, 'cpf' => null, 'data_nascimento' => null,
  'origem_import' => 'agenda-esposa', 'campos' => [], 'payload_import' => [],
]);
ok(!array_key_exists('wa_id', $soFixo) || $soFixo['wa_id'] === null,
   'so com fixo NAO grava wa_id: fixo nao tem WhatsApp e nao casa identidade');

echo "test-wa-import-aplica OK\n";
