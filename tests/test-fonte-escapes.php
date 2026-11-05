<?php
/* TRAVA DE FONTE, para todo o PHP do projeto: nenhuma string pode ter como
   CONTEUDO apenas uma quebra de linha CRUA.

   POR QUE ELA EXISTE, e e um defeito medido duas vezes:
   escape de string em PHP nao se escreve apertando Enter. Quem quer um
   separador de linha digita a sequencia de duas letras; quem aperta Enter
   grava no fonte o byte que o editor usar naquele momento. Com
   core.autocrlf=true neste repo, esse byte e LF enquanto uma ferramenta
   escreve o arquivo e vira CRLF no primeiro checkout que o materializa - ou
   seja, NO MERGE. O mesmo codigo passa a se comportar de outra forma sem que
   uma linha mude, e a suite verde na branch nao prova a arvore mesclada.

   1) 02/11/2026: lib/wa-fluxo.php tinha str_replace com os dois primeiros
      argumentos IGUAIS, newline literal em vez das escapes. Nunca normalizou
      nada, e passava porque o arquivo lido era LF na branch. (A trava DAQUELA
      regra mora em tests/test-wa-fluxo-versao.php, no ponto onde o defeito
      aconteceu; duas travas para a mesma regra divergem, e uma delas passa a
      ser a que ninguem atualiza.)
   2) 05/11/2026: lib/po-datas.php juntava as linhas do {datas} com o literal
      apertado no Enter em QUATRO pontos, e tests/test-po-datas.php dividia
      com outro. No merge o teste virou CRLF, o explode parou de dividir, e a
      linha inteira passou a ser medida como se fosse uma data. A funcao de
      producao ficou LF no MESMO checkout: o defeito dela seguiu LATENTE, a um
      checkout de mandar CR para o WhatsApp e de gastar um caractere a mais
      por linha contra o teto de 300 do {datas} e o de 1024 da legenda de PDF
      da Cloud API - teto que a Meta aplica recusando a mensagem INTEIRA, em
      silencio.

   TRES decisoes de desenho, cada uma medida por mutacao:

   a) TOKENIZADOR, nao regex: comentario e bloco de HTML multi-linha tambem
      tem quebra de linha dentro, e os dois sao legitimos. O token_get_all
      separa T_COMMENT de string, e a regra olha o CONTEUDO - uma string cujo
      conteudo e SO quebra de linha nunca e intencional, enquanto um
      "<header ...>" seguido de mais HTML tem texto em volta e nao casa.

   b) ASPAS SIMPLES CONTAM. Elas nao interpretam escape, mas o defeito nao e
      sobre interpretacao: e sobre o byte que o Enter grava. Um literal de
      aspas simples com uma quebra de linha dentro muda de LF para CRLF no
      mesmo checkout, igual. Pular aspas simples era um buraco que a mutacao
      achou, nao uma guarda.

   c) HEREDOC FICA FORA. Dentro de um heredoc, apertar Enter e a UNICA forma
      de escrever uma quebra de linha, e e o uso correto. Um heredoc com
      linha em branco entre duas variaveis produz exatamente um pedaco "so
      quebra de linha", que seria falso positivo. O tokenizador delimita o
      heredoc com T_START_HEREDOC / T_END_HEREDOC, entao o estado e sabido.

   A leitura e de BYTES CRUS de proposito. Toda outra trava de fonte deste
   projeto normaliza o fim de linha na leitura, porque mede o codigo; esta
   mede justamente o byte, entao normalizar a desligaria. */
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

define('TE_CR', chr(13));
define('TE_LF', chr(10));

/* Devolve as linhas de $src com string cujo conteudo e so quebra de linha
   crua. Funcao pura, para o detector ser exercitado contra fonte conhecida
   em vez de so contra os arquivos do projeto - sem isso, o ramo das strings
   interpoladas e o filtro de heredoc nao sao exercitados por nada, e a
   mutacao mostrou os dois sobrevivendo. */
function te_literais_crus($src) {
    $toks = @token_get_all($src);
    if (!is_array($toks)) return [];
    $achados = [];
    $heredoc = false;

    foreach ($toks as $t) {
        if (!is_array($t)) continue;
        $tipo = token_name($t[0]);

        if ($tipo === 'T_START_HEREDOC') { $heredoc = true;  continue; }
        if ($tipo === 'T_END_HEREDOC')   { $heredoc = false; continue; }
        if ($heredoc) continue;

        if ($tipo !== 'T_CONSTANT_ENCAPSED_STRING' && $tipo !== 'T_ENCAPSED_AND_WHITESPACE') continue;

        $txt = $t[1];
        if (strpos($txt, TE_LF) === false && strpos($txt, TE_CR) === false) continue;

        /* string literal inteira vem com as aspas no token; pedaco de string
           interpolada vem sem elas */
        $conteudo = $txt;
        if ($tipo === 'T_CONSTANT_ENCAPSED_STRING') {
            $q = $txt[0];
            if ($q === '"' || $q === "'") $conteudo = substr($txt, 1, -1);
        }

        if (str_replace([TE_CR, TE_LF], '', $conteudo) === '') $achados[] = $t[2];
    }
    return $achados;
}

/* ---------- o detector, contra fonte conhecida ---------- */
$ab = '<?php ';

ok(te_literais_crus($ab . '$x = $a . "' . TE_LF . '" . $b;') !== [],
    'pega aspas duplas com so a quebra de linha dentro');
ok(te_literais_crus($ab . '$x = $a . "' . TE_CR . TE_LF . '" . $b;') !== [],
    'pega tambem quando o fim de linha e CRLF');
ok(te_literais_crus($ab . "\$x = \$a . '" . TE_LF . "' . \$b;") !== [],
    'pega ASPAS SIMPLES: o defeito e o byte que o Enter grava, nao a interpretacao');
ok(te_literais_crus($ab . '$x = "$a' . TE_LF . '$b";') !== [],
    'pega o pedaco de uma string INTERPOLADA (T_ENCAPSED_AND_WHITESPACE)');

ok(te_literais_crus($ab . '$x = $a . "\n" . $b;') === [],
    'a escape de duas letras passa, que e o conserto certo');
ok(te_literais_crus($ab . '$x = "<h1>$t</h1>' . TE_LF . '<p>fim</p>";')  === [],
    'bloco de HTML multi-linha nao e o defeito: tem texto em volta');
ok(te_literais_crus($ab . '/* comentario' . TE_LF . 'em duas linhas */ $x = 1;') === [],
    'comentario multi-linha nao e string');
/* O fixture do heredoc tem as duas variaveis COLADAS na linha em branco de
   proposito: com HTML entre elas o pedaco nunca seria 'so quebra de linha' e
   o filtro de heredoc nao era exercitado por nada - a mutacao mostrou ele
   sobrevivendo com a arvore verde. Assim o pedaco entre $a e $b e LF+LF puro,
   que e exatamente o que o filtro existe para perdoar. */
ok(te_literais_crus($ab . '$x = <<<T' . TE_LF . '$a' . TE_LF . TE_LF . '$b' . TE_LF . 'T;' . TE_LF) === [],
    'HEREDOC com linha em branco entre variaveis: apertar Enter ali e o uso correto');

/* ---------- e agora o projeto ---------- */
$raiz = dirname(__DIR__);
$arquivos = array_merge(
    glob($raiz . '/lib/*.php'),
    glob($raiz . '/*.php'),
    glob($raiz . '/tests/*.php'),
    glob($raiz . '/scripts/*.php')
);
ok(count($arquivos) >= 40, 'achou o PHP do projeto: ' . count($arquivos));

$ruins = [];
foreach ($arquivos as $f) {
    $src = file_get_contents($f);
    ok(is_array(@token_get_all($src)), 'tokenizou ' . basename($f));
    foreach (te_literais_crus($src) as $ln) $ruins[] = basename($f) . ':' . $ln;
}

ok($ruins === [], 'string cujo conteudo e so quebra de linha CRUA (escreva a escape de duas letras): ' . implode(', ', $ruins));

echo "OK test-fonte-escapes (" . count($arquivos) . " arquivos PHP)\n";
