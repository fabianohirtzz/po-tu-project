<?php
/* Ponte do lado PHP para tests/test-datas-semanal.mjs.
   NAO e um teste: o nome nao casa com `test-*.php`, entao tests/run.php o
   ignora. Ele existe para o teste cruzado poder EXECUTAR o PHP, em vez de
   conferir o fonte por regex - conferir o fonte testaria o texto, nao o
   comportamento, que e justamente o que o teste cruzado precisa comparar.

   Uso: php tests/bridge-po-datas.php <arquivo.json> [modo]

   modo ausente ou "casos":
     Entrada: [{"roteiro": {...}, "hoje": "2039-11-01"}, ...]
     Saida:   [{"grupos": [...], "prox": ..., "quando": ...,
                "saida": ..., "fim": ..., "vencido": bool}, ...]

   modo "ordem" (a ordem cronologica, que precisa da LISTA inteira):
     Entrada: {"lista": [...], "hoje": "2026-11-05"}
     Saida:   {"ordem": ["slug", ...], "vivos": ["slug", ...]} */
require __DIR__ . '/../lib/po-datas.php';

$entrada = json_decode(file_get_contents($argv[1]), true);
$modo = $argv[2] ?? 'casos';

if ($modo === 'ordem') {
    $h = $entrada['hoje'];
    echo json_encode([
        'ordem' => array_column(po_por_saida($entrada['lista'], $h), 'slug'),
        'vivos' => array_column(po_sem_vencidos($entrada['lista'], $h), 'slug'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(0);
}

$casos = $entrada;
$out = [];
foreach ($casos as $c) {
    $r = $c['roteiro'];
    $h = $c['hoje'];
    $out[] = [
        'grupos' => po_datas_grupos($r),
        'prox'   => po_proxima_saida($r, $h),
        'quando' => po_quando($r, $h),
        'saida'   => po_data_saida($r, $h),
        'fim'     => po_data_fim($r),
        'vencido' => po_roteiro_vencido($r, $h),
    ];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
