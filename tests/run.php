<?php
/* Runner mínimo: roda todo tests/test-*.php num processo separado, e
   todo tests/test-*.mjs no node quando ele existe.
   Sem PHPUnit de propósito - a hospedagem não tem Composer e o projeto
   não tem build.

   O .mjs entrou porque o painel é JavaScript: sem isso, tests/test-slugify.mjs
   ficou no repositório desde julho sem nunca rodar, o que é pior do que
   não ter teste, porque parece cobertura. */
$dir  = __DIR__;
$fail = 0;

/* node pode não existir na máquina (ou no servidor). Nesse caso os testes
   de JS são PULADOS com aviso visível, nunca silenciosamente contados
   como se tivessem passado. */
$node = null;
foreach (['node', 'node.exe'] as $cand) {
    $out = [];
    $cod = 0;
    exec(escapeshellarg($cand) . ' --version 2>&1', $out, $cod);
    if ($cod === 0) { $node = $cand; break; }
}

$arquivos = array_merge(glob($dir . '/test-*.php'), glob($dir . '/test-*.mjs'));
sort($arquivos);

foreach ($arquivos as $f) {
    $name = basename($f);
    $ejs  = substr($f, -4) === '.mjs';

    if ($ejs && !$node) {
        echo "SKIP  $name (node nao encontrado)\n";
        continue;
    }

    $bin = $ejs ? $node : PHP_BINARY;
    $out = [];
    $code = 0;
    exec(escapeshellarg($bin) . ' ' . escapeshellarg($f) . ' 2>&1', $out, $code);

    if ($code === 0) {
        // A saída do filho é impressa quando contém SKIP: sem isto, um
        // teste que se pula aparece como PASS e ninguém percebe.
        $txt = implode("\n", $out);
        echo "PASS  $name\n";
        if (stripos($txt, 'SKIP') !== false) {
            echo "      " . implode("\n      ", $out) . "\n";
        }
    } else {
        $fail++;
        echo "FAIL  $name\n      " . implode("\n      ", $out) . "\n";
    }
}
echo $fail ? "\n$fail arquivo(s) de teste falharam\n" : "\nTodos os testes passaram\n";
exit($fail ? 1 : 0);
