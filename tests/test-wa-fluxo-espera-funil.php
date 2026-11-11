<?php
/* O robo e o funil (reuniao de 09/11/2026, pedidos de 11/11).

   1. `espera` e status que o card `marca` pode gravar: quem toca em "Sim,
      tenho interesse" cai sozinho na coluna "Lista de espera" do funil.
   2. O `marca` com `+roteiro` preenche tambem `po_leads.roteiro` com o NOME do
      roteiro, que e o "Roteiro de interesse" da gaveta. So o slug em
      `interesses` deixava a gaveta mostrando "-".
   3. O robo nunca rebaixa proposta nem venda: elas sao decisao da Lais. Uma
      cliente que ja comprou e volta perguntando por outro destino nao pode ir
      parar em "Perdido" ou "Lista de espera". */
require __DIR__ . '/../lib/wa-fluxo.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

function card($id, $tipo, $extra = []) {
    return array_merge(['id' => $id, 'tipo' => $tipo, 'titulo' => $id, 'texto' => '',
        'opcoes' => [], 'config' => [], 'proximo' => null, 'fallback' => null,
        'sem_resposta' => null, 'lembrete_ativo' => true, 'lembrete_texto' => 'l'], $extra);
}

ok(in_array('espera', WA_FLUXO_STATUS_PERMITIDOS, true), 'espera e status permitido ao fluxo');
ok(!in_array('venda', WA_FLUXO_STATUS_PERMITIDOS, true) && !in_array('negociacao', WA_FLUXO_STATUS_PERMITIDOS, true),
   'venda e negociacao seguem fora');

// validador aceita o marca com status espera
$fluxo = ['id' => 'f2', 'nome' => 'Espera', 'card_inicial' => 'm', 'gatilho' => 'chamado', 'gatilho_valor' => null];
$probs = wa_fluxo_valida($fluxo, [
    card('m', 'marca', ['config' => ['espera' => '+destino', 'status' => 'espera'], 'proximo' => 'fim']),
    card('fim', 'fim', ['texto' => 'Combinado.']),
]);
foreach ($probs as $p) ok($p['regra'] !== 10, 'status espera nao e bloqueado pela regra 10: ' . $p['texto']);

$ROTEIROS = [['slug' => 'chile', 'titulo' => 'Chile e Deserto do Atacama', 'status_viagem' => 'em_breve', 'periodo' => '25/11/27 a 02/12/27']];
$LEAD = [['id' => 'L1', 'status' => 'novo', 'interesses' => []]];
$ESCRITAS = [];
wa_fluxo_set_deps([
    'roteiros'       => function () use (&$ROTEIROS) { return $ROTEIROS; },
    'update'         => function ($t, $q, $c) use (&$ESCRITAS) { $ESCRITAS[] = $c; return true; },
    'select_estrito' => function ($t, $q) use (&$LEAD) { return $LEAD; },
    'espera_anota'   => function () { return true; },
]);
$g = ['id' => 'f1', 'inicial' => 'mi', 'humano' => null, 'cards' => [
    'mi' => card('mi', 'marca', ['config' => ['interesses' => '+roteiro']]),
    'me' => card('me', 'marca', ['config' => ['espera' => '+destino', 'status' => 'espera']]),
    'mp' => card('mp', 'marca', ['config' => ['status' => 'perdido', 'qualif_data' => false]]),
]];

// 2. o nome do roteiro vai para a gaveta
wa_fluxo_executa($g, 'mi', '5548999990000', ['roteiro_slug' => 'chile', 'tentativas' => 0], 'Ana');
ok(($ESCRITAS[0]['roteiro'] ?? null) === 'Chile e Deserto do Atacama', 'o marca +roteiro grava o nome do roteiro');
ok(($ESCRITAS[0]['interesses'] ?? null) === ['chile'], 'e segue gravando o slug em interesses');

// 1. espera grava o status
$ESCRITAS = [];
wa_fluxo_executa($g, 'me', '5548999990000', ['destino_nome' => 'Itália', 'tentativas' => 0], 'Ana');
ok(($ESCRITAS[0]['status'] ?? null) === 'espera', 'a lista de espera move o lead para a coluna da espera');

// 3. venda e proposta nao sao rebaixadas
foreach (['venda', 'negociacao'] as $st) {
    $LEAD = [['id' => 'L1', 'status' => $st, 'interesses' => []]];
    foreach (['me', 'mp'] as $cid) {
        $ESCRITAS = [];
        wa_fluxo_executa($g, $cid, '5548999990000', ['destino_nome' => 'Itália', 'tentativas' => 0], 'Ana');
        ok(!isset(($ESCRITAS[0] ?? [])['status']), "lead em $st nao tem o status trocado pelo card $cid");
    }
}
$ESCRITAS = [];
wa_fluxo_executa($g, 'mp', '5548999990000', ['tentativas' => 0], 'Ana');
ok(($ESCRITAS[0]['qualif_data'] ?? null) === false, 'o resto da marca (qualif_data) segue sendo gravado');

// leitura do status falhou: vale a regra que ja existia para o marca (test-wa-fluxo-executa):
// a marcacao segue e o status e gravado
$LEAD = null;
$ESCRITAS = [];
wa_fluxo_executa($g, 'mp', '5548999990000', ['tentativas' => 0], 'Ana');
ok((($ESCRITAS[0] ?? [])['status'] ?? null) === 'perdido', 'banco fora na leitura: a marcacao segue, como antes');

echo "test-wa-fluxo-espera-funil OK\n";
