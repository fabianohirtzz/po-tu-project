<?php
/* Ponte do lado PHP para tests/test-datas-semanal.mjs.
   NAO e um teste: o nome nao casa com `test-*.php`, entao tests/run.php o
   ignora. Ele existe para o teste cruzado poder EXECUTAR o PHP, em vez de
   conferir o fonte por regex - conferir o fonte testaria o texto, nao o
   comportamento, que e justamente o que o teste cruzado precisa comparar.

   Uso: php tests/bridge-po-datas.php <arquivo.json>
   Entrada:  [{"roteiro": {...}, "hoje": "2039-11-01"}, ...]
   Saida:    [{"grupos": [...], "prox": "..."|null, "quando": "..."}, ...] */
require __DIR__ . '/../lib/po-datas.php';

$casos = json_decode(file_get_contents($argv[1]), true);
$out = [];
foreach ($casos as $c) {
    $r = $c['roteiro'];
    $h = $c['hoje'];
    $out[] = [
        'grupos' => po_datas_grupos($r),
        'prox'   => po_proxima_saida($r, $h),
        'quando' => po_quando($r, $h),
    ];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
