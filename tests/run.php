<?php
/* Runner mínimo: roda todo tests/test-*.php num processo separado.
   Sem PHPUnit de propósito — a hospedagem não tem Composer e o projeto
   não tem build. */
$dir  = __DIR__;
$fail = 0;
foreach (glob($dir . '/test-*.php') as $f) {
    $out  = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($f) . ' 2>&1', $out, $code);
    $name = basename($f);
    if ($code === 0) {
        echo "PASS  $name\n";
    } else {
        $fail++;
        echo "FAIL  $name\n      " . implode("\n      ", $out) . "\n";
    }
}
echo $fail ? "\n$fail arquivo(s) de teste falharam\n" : "\nTodos os testes passaram\n";
exit($fail ? 1 : 0);
