<?php
/* tests/test-origem-whatsapp.php - o WhatsApp do site (fase 3A, 13/11/2026).

   Duas coisas:
   1. A linha que o clique.php grava (lib/po-clique.php): codigo em formato
      fechado, e SO campos de origem. O endpoint e publico: nada vindo de fora
      pode escrever lead_id ou casado_por.
   2. O TEXTO que o visitante envia nao pode mudar o que o robo faz. O texto
      real (lido do assets/js/origem.js), com milhares de codigos sorteados,
      passa pelas funcoes DE VERDADE do robo: atalho por palavra, roteiro,
      destino, numero da lista de roteiros e resposta numerada. Nenhuma pode
      casar. E por isso que o codigo e so consoante: sem vogal nao forma
      palavra, sem digito nao vira "escolhi o roteiro 7". */
require __DIR__ . '/../lib/po-clique.php';
require __DIR__ . '/../lib/wa-motor.php';   // traz wa-fluxo, wa-roteiro e wa-destinos

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }
$RAIZ = dirname(__DIR__);
$le = function ($rel) use ($RAIZ) { return str_replace("\r\n", "\n", file_get_contents($RAIZ . '/' . $rel)); };

/* ============ 1. a linha do clique ============ */
$T = 1794600000;
$l = po_clique_linha(['codigo' => ' G-KTRBX ', 'gclid' => 'CjAbc', 'utm_source' => 'google', 'utm_medium' => 'cpc',
    'fbclid' => '', 'pagina' => 'https://pereiraoliveiraturismo.com.br/contato',
    'lead_id' => '00000000-0000-4000-8000-000000000000', 'casado_por' => 'codigo', 'origem_casada' => 'codigo'], $T);
ok(is_array($l), 'codigo valido (com espaco em volta) vira linha');
ok($l['codigo'] === 'G-KTRBX', 'o codigo sai aparado');
ok($l['ultimo_clique_at'] === gmdate('c', $T), 'a hora do clique e a do servidor');
ok($l['gclid'] === 'CjAbc' && $l['utm_medium'] === 'cpc', 'a origem vai inteira');
ok($l['fbclid'] === null, 'campo vazio vira null');
$chaves = array_keys($l);
sort($chaves);
$esperado = array_merge(['codigo', 'ultimo_clique_at'], PO_CLIQUE_CAMPOS);
sort($esperado);
ok($chaves === $esperado, 'SO campos de origem: lead_id, casado_por e o resto vindo de fora nunca entram (' . implode(',', $chaves) . ')');
$longo = po_clique_linha(['codigo' => 'M-BCDFG', 'landing_page' => str_repeat('é', 900)], $T);
ok(mb_strlen($longo['landing_page'], 'UTF-8') === 500, 'teto de 500 caracteres (nao bytes)');
ok(po_clique_linha(['codigo' => 'S-ZZZZZ', 'utm_source' => ['x']], $T)['utm_source'] === null, 'campo que nao e texto vira null');
foreach (['', 'G-KAEIO', 'X-KTRBX', 'G-KTRB', 'G-KTRBXX', 'g-ktrbx', 'G KTRBX', 'G-KTRB1'] as $ruim) {
    ok(po_clique_linha(['codigo' => $ruim], $T) === null, 'codigo recusado: "' . $ruim . '"');
}
ok(po_clique_linha(['codigo' => ['G-KTRBX']], $T) === null, 'codigo que nao e texto e recusado');

/* O formato e o MESMO nos tres lados: o site gera, o servidor aceita, o
   painel procura na mensagem. */
$js = $le('assets/js/origem.js');
ok(preg_match('/var CODIGO = (\/\^\[GMS\]-\[([A-Z]+)\]\{5\}\$\/);/', $js, $m) === 1, 'origem.js declara o formato do codigo');
ok($m[1] === PO_CLIQUE_CODIGO, 'site e servidor aceitam o mesmo formato');
$consoantes = $m[2];
ok(preg_match("/var CONSOANTES = '([A-Z]+)';/", $js, $c) === 1 && $c[1] === $consoantes, 'o site sorteia do mesmo alfabeto que aceita');
ok(preg_match('/[AEIOUY0-9]/', $consoantes) === 0, 'o alfabeto nao tem vogal nem digito');
$painel = $le('painel/cliques.js');
ok(strpos($painel, '([' . strtolower($consoantes) . ']{5})') !== false, 'o painel procura o mesmo alfabeto na mensagem');

/* ============ 2. o texto nao mexe no robo ============ */
ok(preg_match("/var TEXTO = '([^']+)';/", $js, $t) === 1, 'origem.js declara o texto');
$TEXTO = $t[1];

// O catalogo de roteiros a venda (titulos e slugs reais de 11/2026).
$ROTEIROS = [];
foreach ([['turquia', 'Turquia com Antália'], ['chile-e-deserto-do-atacama', 'Chile e Deserto do Atacama'],
          ['tesouros-asiaticos', 'Tesouros Asiáticos'], ['escandinavia', 'O melhor da Escandinávia'],
          ['caminhos-da-india', 'Caminhos da Índia'], ['coreia-do-sul-japao-dubai', 'Coreia do Sul, Japão e Dubai'],
          ['peru-fascinante', 'Peru Fascinante'], ['vietna-tailandia-paris', 'Vietnã completo e Tailândia com Paris'],
          ['grecia-terra-mar', 'Grécia Terra e Mar']] as [$slug, $titulo]) {
    $ROTEIROS[] = ['slug' => $slug, 'titulo' => $titulo, 'palavras_chave' => '', 'ativo' => true];
}
// Os destinos semeados no banco, lidos da propria migration.
$DESTINOS = [];
preg_match_all("/\('([^']+)',\s*'([^']+)',\s*\d+\)/u", $le('supabase/migrations/2026-11-05-destinos-espera.sql'), $ds, PREG_SET_ORDER);
foreach ($ds as $d) $DESTINOS[] = ['nome' => $d[1], 'palavras' => $d[2]];
ok(count($DESTINOS) >= 25, 'leu os destinos da migration: ' . count($DESTINOS));
// As palavras dos atalhos do fluxo semeado (hoje: falar com a equipe).
preg_match_all('/"palavras":\[([^\]]*)\]/', $le('supabase/migrations/2026-11-11-fluxo-semanal.sql'), $ps);
$ATALHOS = [];
foreach ($ps[1] as $lista) foreach (json_decode('[' . $lista . ']', true) ?: [] as $p) $ATALHOS[] = $p;
ok(in_array('atendimento', $ATALHOS, true), 'leu as palavras de atalho do fluxo: ' . implode(',', $ATALHOS));

$sorteio = function () use ($consoantes) {
    $s = '';
    for ($i = 0; $i < 5; $i++) $s .= $consoantes[random_int(0, strlen($consoantes) - 1)];
    return $s;
};
$SLUGS = array_column($ROTEIROS, 'slug');
for ($n = 0; $n < 3000; $n++) {
    $cod = ['G', 'M', 'S'][$n % 3] . '-' . $sorteio();
    $msg = $TEXTO . ' (ref ' . $cod . ')';
    ok(!wa_fluxo_porta_casa($msg, implode(',', $ATALHOS)), "atalho de palavra disparou: $msg");
    ok(wa_match_roteiros($msg, $ROTEIROS) === [], "casou roteiro: $msg");
    ok(wa_match_destinos($msg, $DESTINOS) === [], "casou destino: $msg");
    ok(wa_fluxo_casa_roteiro($msg, $SLUGS, $ROTEIROS) === null, "virou escolha da lista de roteiros: $msg");
    ok(wa_respostas_numeradas($msg) === null, "virou resposta numerada: $msg");
}
// Controle: o mesmo casamento AINDA morde, senao o laco acima nao provaria nada.
ok(wa_fluxo_porta_casa('quero atendimento', implode(',', $ATALHOS)), 'controle: atalho de palavra funciona');
ok(wa_match_roteiros('quero o Chile', $ROTEIROS) === ['chile-e-deserto-do-atacama'], 'controle: roteiro funciona');
ok(wa_match_destinos('quero ir ao Peru', $DESTINOS) === ['Peru'], 'controle: destino funciona');
ok(wa_fluxo_casa_roteiro('7. Peru', $SLUGS, $ROTEIROS) === 'peru-fascinante', 'controle: numero da lista funciona');

/* ============ 3. o endpoint ============ */
$ep = $le('clique.php');
ok(strpos($ep, "!== 'POST'") !== false, 'so POST');
ok(strpos($ep, 'po_clique_linha($_POST, time())') !== false, 'a linha sai da funcao testada (trava no ponto de chamada)');
ok(strpos($ep, 'po_origem_cliques?on_conflict=codigo') !== false && strpos($ep, 'resolution=merge-duplicates') !== false,
    'o mesmo codigo clicado de novo so atualiza a linha');
ok(strpos($ep, 'SUPABASE_SERVICE_KEY') !== false, 'grava com a service_role (anonimo nao tem politica na tabela)');
ok(preg_match('/echo|print|var_dump/', $ep) === 0, 'o endpoint publico nunca devolve dado');
$sql = $le('supabase/migrations/2026-11-13-origem-cliques.sql');
ok(preg_match('/create policy po_origem_cliques_auth on public\.po_origem_cliques\s+for all to authenticated/', $sql) === 1,
    'so logado le e escreve a tabela de cliques');
ok(stripos($sql, 'to anon') === false, 'nenhuma politica para anonimo');

echo "test-origem-whatsapp OK\n";
