<?php
/* Valor em reais + valor em dolar ou euro no mesmo card (pedido de 24/11/2026).

   A cliente digita o sinal da moeda a mao (R$, EUR, USD), por isso os dois
   campos sao texto livre. Sem coluna nova: os dois vivem no jsonb `valores`
   (`valor` e `valor2`), entao roteiro antigo, so com `valor`, sai igual. */
define('PO_TEST', 1);
require __DIR__ . '/../lib/po-data.php';
require __DIR__ . '/../lib/po-view.php';
require __DIR__ . '/../roteiro.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

$base = ['slug' => 'teste', 'titulo' => 'Roteiro Teste', 'dias' => 9, 'periodo' => '', 'ativo' => true];
$card = function ($h) { return explode('</section>', explode('id="investimento"', $h)[1])[0]; };

/* os dois valores no card */
$r = $base + ['valores' => [['tag' => 'Quarto duplo', 'valor' => 'R$ 30.921', 'valor2' => 'USD 5.890']]];
$c = $card(po_roteiro_html($r, []));
ok(strpos($c, 'R$ 30.921') !== false && strpos($c, 'USD 5.890') !== false, 'o card mostra o valor em reais e o em moeda');
ok(strpos($c, 'R$ 30.921') < strpos($c, 'USD 5.890'), 'reais primeiro');

/* o texto novo, e o antigo saiu */
ok(strpos($c, 'Valores sujeitos a alteração conforme cotação do câmbio na data do orçamento/pagamento.') !== false, 'texto novo do investimento');
ok(strpos($c, 'Valores sujeitos a alteração, apenas cotação.') === false, 'texto antigo saiu');

/* so um dos dois: nenhum bloco vazio */
$r = $base + ['valores' => [['tag' => 'Por pessoa', 'valor' => '', 'valor2' => 'EUR 7.695']]];
$c = $card(po_roteiro_html($r, []));
ok(strpos($c, 'EUR 7.695') !== false, 'so o valor em moeda tambem aparece');
ok(strpos($c, '<div class="pcard__val"></div>') === false, 'sem valor em reais, nao fica caixa vazia');

$r = $base + ['valores' => [['tag' => 'Por pessoa', 'valor' => 'R$ 10.361,28']]];
$c = $card(po_roteiro_html($r, []));
ok(strpos($c, 'R$ 10.361,28') !== false && strpos($c, 'pcard__val--moeda') === false, 'roteiro antigo, so com `valor`, sai como antes');

/* escapado */
$r = $base + ['valores' => [['valor' => 'R$ 1', 'valor2' => '<script>x</script>']]];
ok(strpos(po_roteiro_html($r, []), '<script>x') === false, 'valor em moeda escapado');

/* JSON-LD: reais primeiro; sem reais, a moeda que ela digitou */
$o = function ($v) use ($base) { $j = po_roteiro_jsonld($base + ['valores' => [$v]]); return $j['offers'] ?? null; };
$x = $o(['valor' => 'R$ 30.921', 'valor2' => 'USD 5.890']);
ok($x['priceCurrency'] === 'BRL' && $x['price'] === '30921', 'offer em reais quando ha valor em reais');
$x = $o(['valor' => '', 'valor2' => 'EUR 7.695']);
ok($x['priceCurrency'] === 'EUR' && $x['price'] === '7695', 'sem reais, o offer usa o valor em euro');
$x = $o(['valor' => 'R$', 'valor2' => 'USD 2.693,09']);
ok($x['priceCurrency'] === 'USD' && $x['price'] === '2693.09', '"R$" sem numero nao conta como preco');
$x = $o(['valor' => '7.540 EUR']);
ok($x['priceCurrency'] === 'EUR', 'EUR escrito por extenso no campo de reais (dado antigo) vira EUR, nao BRL');
ok($o(['valor' => '', 'valor2' => '']) === null, 'sem valor nenhum, sem offer');

/* painel: os dois campos existem e o salvamento leva os dois */
$app = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../painel/app.js'));
ok(strpos($app, 'Valor em reais') !== false && strpos($app, 'Valor em dólar ou euro') !== false, 'rotulos dos dois campos no painel');
ok(strpos($app, "valor2:it.querySelector('.rv-valor2').value.trim()") !== false, 'o salvamento le o campo novo');
ok(strpos($app, '.filter(v=>v.valor||v.valor2)') !== false, 'card so com valor em moeda nao e descartado ao salvar');

echo "test-roteiro-valor-moeda OK\n";
