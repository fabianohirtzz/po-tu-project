<?php
require __DIR__ . '/../lib/wa-fluxo.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

function card($id, $tipo, $extra = []) {
    return array_merge(['id' => $id, 'tipo' => $tipo, 'titulo' => $id, 'texto' => 't',
        'opcoes' => [], 'config' => [], 'proximo' => null, 'fallback' => null,
        'sem_resposta' => null, 'lembrete_ativo' => true, 'lembrete_texto' => 'l'], $extra);
}

$grafo = ['id' => 'f1', 'nome' => 'Boas-vindas', 'inicial' => 'bv', 'humano' => 'hum',
          'porta' => 'mensagem_nova', 'porta_valor' => null, 'cards' => [
  'bv'   => card('bv', 'botoes', ['opcoes' => [
              ['id' => 'b1', 'rotulo' => 'Um roteiro', 'destino' => 'lst'],
              ['id' => 'b2', 'rotulo' => 'Programacao', 'destino' => 'prog'],
              ['id' => 'b3', 'rotulo' => 'Falar com a Lais', 'destino' => 'hum']],
            'fallback' => 'ops']),
  'lst'  => card('lst', 'lista', ['config' => ['fonte' => 'roteiros'], 'proximo' => 'pdf', 'fallback' => 'ops']),
  'est'  => card('est', 'lista', ['config' => ['fonte' => 'estatica'], 'opcoes' => [
              ['id' => 'a', 'rotulo' => '2027', 'destino' => 'lst'],
              ['id' => 'b', 'rotulo' => '2028', 'destino' => 'lst']], 'fallback' => 'ops']),
  'pdf'  => card('pdf', 'pdf', ['proximo' => 'mrc']),
  'mrc'  => card('mrc', 'marca', ['proximo' => 'dt']),
  'dt'   => card('dt', 'botoes', ['opcoes' => [['id' => 's', 'rotulo' => 'Sim', 'destino' => 'grp']],
              'fallback' => 'ops', 'sem_resposta' => 'fim']),
  'prog' => card('prog', 'lista', ['config' => ['fonte' => 'roteiros', 'ano' => '2027'], 'proximo' => 'lst']),
  'grp'  => card('grp', 'botoes', ['opcoes' => [['id' => 'j', 'rotulo' => 'Ja viajei', 'destino' => 'hum']], 'fallback' => 'ops']),
  'ops'  => card('ops', 'botoes', ['opcoes' => [['id' => 'v', 'rotulo' => 'Ver roteiros', 'destino' => 'lst']], 'fallback' => 'hum']),
  'hum'  => card('hum', 'humano'),
  'fim'  => card('fim', 'fim'),
  'vai'  => card('vai', 'ir_para_fluxo', ['config' => ['fluxo' => 'espera-id']]),
]];
$ctx0 = ['roteiro_slug' => null, 'tentativas' => 0];

// 1. botao casa a opcao pelo id
$r = wa_fluxo_proximo($grafo, 'bv', 'b2', $ctx0);
ok($r['card'] === 'prog' && $r['motivo'] === 'opcao', 'botao vai para o destino da opcao');
ok($r['ctx']['tentativas'] === 0, 'acerto zera tentativas');

// 2. lista dinamica: a resposta E o slug, vai para o unico destino e grava o contexto
$r = wa_fluxo_proximo($grafo, 'lst', 'turquia', $ctx0);
ok($r['card'] === 'pdf' && $r['motivo'] === 'escolheu', 'lista dinamica vai para proximo');
ok($r['ctx']['roteiro_slug'] === 'turquia', 'lista dinamica grava o slug no contexto');

// 3. lista estatica casa por id de linha, e NAO grava slug
$r = wa_fluxo_proximo($grafo, 'est', 'b', $ctx0);
ok($r['card'] === 'lst' && $r['motivo'] === 'opcao', 'lista estatica casa a linha');
ok($r['ctx']['roteiro_slug'] === null, 'lista estatica nao grava slug');

// 4. REVIEW FOCUS 2: botao de mensagem antiga. O id existe no grafo, mas nao neste card.
$r = wa_fluxo_proximo($grafo, 'dt', 'b2', $ctx0);
ok($r['card'] === 'ops' && $r['motivo'] === 'fallback',
   'id de botao de outro card cai no fallback, nunca no destino daquele outro card');

// 5. texto livre em card que espera cai no fallback, e conta a tentativa
$r = wa_fluxo_proximo($grafo, 'dt', 'quanto custa?', $ctx0);
ok($r['card'] === 'ops' && $r['motivo'] === 'fallback', 'texto livre cai no fallback');
ok($r['ctx']['tentativas'] === 1, 'fallback conta tentativa');

// 6. rede de seguranca: na tentativa WA_FLUXO_MAX_TENTATIVAS vai para o humano do fluxo
ok(WA_FLUXO_MAX_TENTATIVAS === 2, 'o teto de tentativas e 2');
$r = wa_fluxo_proximo($grafo, 'dt', 'bla', ['roteiro_slug' => null, 'tentativas' => 2]);
ok($r['card'] === 'hum' && $r['motivo'] === 'socorro', 'no teto, socorro vai para o humano do fluxo');
ok($r['ctx']['tentativas'] === 0, 'socorro zera o contador');

// 7. card que NAO espera nunca consulta a resposta: segue por proximo
foreach ([['pdf', 'mrc'], ['mrc', 'dt']] as [$de, $para]) {
    $r = wa_fluxo_proximo($grafo, $de, 'qualquer coisa', $ctx0);
    ok($r['card'] === $para && $r['motivo'] === 'proximo', "card $de segue por proximo, ignorando a resposta");
}
// 7b. `prog` e uma LISTA (espera): NAO ignora a resposta. Texto digitado vira
// roteiro_slug. Contrato visivel: a execucao (Task 7) tem de conferir o slug
// contra o catalogo, porque esta funcao pura nao conhece os roteiros.
$r = wa_fluxo_proximo($grafo, 'prog', 'qualquer coisa', $ctx0);
ok($r['card'] === 'lst' && $r['motivo'] === 'escolheu', 'lista dinamica escolhe, nao so segue');
ok($r['ctx']['roteiro_slug'] === 'qualquer coisa', 'a lista dinamica grava a resposta crua como slug');

// 8. terminal nao tem proximo
foreach (['hum', 'fim'] as $t) {
    $r = wa_fluxo_proximo($grafo, $t, 'x', $ctx0);
    ok($r['card'] === null && $r['motivo'] === 'terminal', "card $t e terminal");
}

// 9. ir_para_fluxo se anuncia pelo motivo, e o destino sai da config
$r = wa_fluxo_proximo($grafo, 'vai', '', $ctx0);
ok($r['card'] === null && $r['motivo'] === 'ir_para_fluxo', 'ir_para_fluxo tem card null e motivo proprio');

// 9b. mesmo com `proximo` preenchido, ir_para_fluxo nao segue por ele
$g7 = $grafo; $g7['cards']['vai']['proximo'] = 'lst';
ok(wa_fluxo_proximo($g7, 'vai', '', $ctx0)['card'] === null, 'ir_para_fluxo nunca devolve card, nem com proximo');

// 10. card inexistente e tipo fora da lista nao estouram: devolvem invalido
ok(wa_fluxo_proximo($grafo, 'nao-existe', 'x', $ctx0)['motivo'] === 'invalido', 'card inexistente e invalido');
$g2 = $grafo; $g2['cards']['zz'] = card('zz', 'inventado');
ok(wa_fluxo_proximo($g2, 'zz', 'x', $ctx0)['motivo'] === 'invalido', 'tipo fora de WA_CARD_TIPOS e invalido');

// 11. fallback ausente num card que espera: cai no humano, nunca em null
$g3 = $grafo; $g3['cards']['dt']['fallback'] = null;
$r = wa_fluxo_proximo($g3, 'dt', 'bla', $ctx0);
ok($r['card'] === 'hum' && $r['motivo'] === 'socorro', 'sem fallback, socorro imediato');

// 12. wa_fluxo_sem_resposta
ok(wa_fluxo_sem_resposta($grafo, 'dt') === 'fim', 'sem_resposta do card');
ok(wa_fluxo_sem_resposta($grafo, 'bv') === null, 'card sem sem_resposta devolve null');
ok(wa_fluxo_sem_resposta($grafo, 'nao-existe') === null, 'card inexistente devolve null');
$g6 = $grafo; $g6['cards']['dt']['sem_resposta'] = 'sumiu';
ok(wa_fluxo_sem_resposta($g6, 'dt') === null, 'sem_resposta para card inexistente devolve null');

// 12b. contrato: exatamente tres chaves, em todo caminho
foreach (array_keys($grafo['cards']) as $id) {
    foreach (['x', 'b2', ''] as $resp) {
        $r = wa_fluxo_proximo($grafo, $id, $resp, $ctx0);
        ok(array_keys($r) === ['card', 'ctx', 'motivo'], "retorno de $id/'$resp' tem so card, ctx e motivo");
    }
}
ok(array_keys(wa_fluxo_proximo($grafo, 'nao-existe', 'x', $ctx0)) === ['card', 'ctx', 'motivo'], 'invalido tem so tres chaves');

// 12c. o slug ja escolhido sobrevive ao card seguinte (senao o PDF sai do roteiro errado)
$ctxS = ['roteiro_slug' => 'turquia', 'tentativas' => 1];
$r = wa_fluxo_proximo($grafo, 'bv', 'b1', $ctxS);
ok($r['motivo'] === 'opcao' && $r['ctx']['roteiro_slug'] === 'turquia', 'opcao preserva o slug do contexto');
$r = wa_fluxo_proximo($grafo, 'pdf', 'x', $ctxS);
ok($r['ctx']['roteiro_slug'] === 'turquia', 'card que nao espera preserva o slug');
$r = wa_fluxo_proximo($grafo, 'dt', 'bla', $ctxS);
ok($r['motivo'] === 'fallback' && $r['ctx']['roteiro_slug'] === 'turquia', 'fallback preserva o slug');

// 13. a funcao e PURA: o grafo e o ctx que entraram nao mudam
$antes = $grafo; $ctx = $ctx0;
wa_fluxo_proximo($grafo, 'lst', 'turquia', $ctx);
ok($grafo === $antes, 'o grafo nao e modificado');
ok($ctx === $ctx0, 'o ctx de entrada nao e modificado');

// 14. lista dinamica: resposta vazia nao e escolha
$r = wa_fluxo_proximo($grafo, 'lst', '', $ctx0);
ok($r['motivo'] === 'fallback', 'resposta vazia na lista dinamica cai no fallback');

// 15. toda saida tem motivo; null so com motivo que explique
foreach (array_keys($grafo['cards']) as $id) {
    $r = wa_fluxo_proximo($grafo, $id, 'x', $ctx0);
    ok(is_string($r['motivo']) && $r['motivo'] !== '', "card $id sempre devolve motivo");
}
ok(wa_fluxo_proximo($grafo, 'hum', 'x', $ctx0)['motivo'] === 'terminal', 'terminal explica o null');
// socorro sem humano no grafo: null com motivo invalido, nunca silencioso
$g4 = $grafo; $g4['humano'] = null; $g4['cards']['dt']['fallback'] = null;
$r = wa_fluxo_proximo($g4, 'dt', 'bla', $ctx0);
ok($r['card'] === null && $r['motivo'] === 'invalido', 'sem humano no fluxo, socorro e invalido');

// 16. opcao cujo destino nao existe no grafo nao leva a lugar nenhum: fallback
$g5 = $grafo; $g5['cards']['dt']['opcoes'][0]['destino'] = 'sumiu';
$r = wa_fluxo_proximo($g5, 'dt', 's', $ctx0);
ok($r['card'] === 'ops' && $r['motivo'] === 'fallback', 'destino inexistente cai no fallback');

echo "test-wa-fluxo-rota: ok\n";
