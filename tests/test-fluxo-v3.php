<?php
/* A VERSAO 3 de "Boas-vindas e triagem": os dois cards novos, as duas
   religacoes e as listas de atalhos. Le a migration como TEXTO (o que o
   Supabase vai gravar, nao uma copia) e passa o retrato pelo validador e pelo
   roteador de verdade. Nenhuma rede.

   ESTA MIGRATION E O INVERSO DA v2, e a diferenca e a razao de este arquivo
   existir. Na v2 o retrato era a fonte e o rascunho era DERIVADO dele, com
   `on conflict (id) do update` sobrescrevendo tipo, titulo, texto, opcoes e
   config dos cards semeados. Medido em 05/11/2026, antes de escrever uma
   linha: a cliente reescreveu `texto` em 11 dos 15 cards da triagem e `opcoes`
   em 6, e nao publicou nada disso. Repetir a derivacao da v2 aqui jogaria esse
   trabalho fora, calado.

   Entao na v3 o RASCUNHO e a fonte: a migration acrescenta os cards novos, muda
   cirurgicamente o `destino` de UMA opcao em dois cards, acrescenta `atalhos`
   ao `config` de dois cards - e NADA MAIS do rascunho. O retrato e uma copia do
   rascunho tirada no dia em que a migration rodou, com as reescritas dela
   dentro. A trava central deste arquivo e exatamente isso: nenhum comando pode
   escrever `texto`, `titulo` ou `lembrete_texto` de card que ja existe. */
require __DIR__ . '/../lib/wa-fluxo.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

/* Le normalizando o fim de linha: com core.autocrlf=true neste repo o mesmo
   arquivo chega CRLF ou LF conforme quem o materializou, e um regex ancorado
   em \n passaria num estado e falharia no outro. */
$le = function ($rel) {
    $t = @file_get_contents(__DIR__ . '/' . $rel);
    return $t === false ? false : str_replace("\r\n", "\n", $t);
};
$sql2 = $le('../supabase/migrations/2026-10-30-fluxo-triagem-v2.sql');
$sql3 = $le('../supabase/migrations/2026-11-05-fluxo-v3.sql');
ok($sql2 !== false, 'a migration da versao 2 existe (e a base de comparacao)');
ok($sql3 !== false, 'a migration da versao 3 existe');

// Os comandos, sem os comentarios: o cabecalho cita consultas de conferencia
// que nao podem passar por nenhuma trava abaixo.
$cmds3 = preg_replace('/^\s*--.*$/m', '', $sql3);

$cid = function ($nn) { return 'c1000000-0000-4000-8000-0000000000' . $nn; };
$BV  = 'a1000000-0000-4000-8000-000000000001';   // fluxo "Boas-vindas e triagem"
$ESP = 'a1000000-0000-4000-8000-000000000002';   // fluxo "Lista de espera"
$V2  = 'b1000000-0000-4000-8000-000000000003';   // versao 2 da triagem
$V2E = 'b1000000-0000-4000-8000-000000000004';   // versao 2 da lista de espera
$V3  = 'b1000000-0000-4000-8000-000000000005';   // versao 3 da triagem
$RE_UUID = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/';

$DESPEDIDA = $cid('21');   // "Despedida de quem recusou a lista" (fim)
$SEM_DATA  = $cid('22');   // "Sem data para este destino" (botoes)
$HUMANO    = $cid('04');
$PDF       = $cid('05');

// Os retratos da v2, para comparar o que a v3 preserva e o que ela muda.
preg_match_all('/\$grafo\$(.*?)\$grafo\$/s', $sql2, $m2);
ok(count($m2[1]) === 2, 'a v2 publica os dois fluxos');
$g2 = json_decode($m2[1][0], true);
$e2 = json_decode($m2[1][1], true);
ok(json_last_error() === JSON_ERROR_NONE && $g2['id'] === $BV, 'o retrato da triagem v2 e JSON valido');

/* ================================================================
   1. A TRAVA CENTRAL: nenhum comando escreve texto de card existente.
   ================================================================ */

/* Quantos comandos o arquivo roda, e com que verbos. Nenhuma trava abaixo olha
   o total, entao um comando a mais nao previsto - um `update public.po_wa_cards
   set texto = ...`, digamos - passaria por todas elas. O retrato sai da conta
   antes do corte porque um `;` no texto de um card mudaria a contagem sem mudar
   comando nenhum. */
$sem_retrato = preg_replace('/\$grafo\$.*?\$grafo\$/s', "'<retrato>'", $cmds3);
$comandos = array_values(array_filter(array_map('trim', explode(';', $sem_retrato)), 'strlen'));
$verbos = array_map(function ($s) { return strtolower(preg_split('/\s+/', $s)[0]); }, $comandos);
ok($verbos === ['insert', 'update', 'update', 'update', 'insert', 'update'],
   'a v3 roda 6 comandos, nesta ordem (os cards novos, a religacao, os dois atalhos, o retrato e o ponteiro): ' . implode(',', $verbos));

// So estas tres tabelas. Nada de po_leads, po_wa_conversas, po_destinos.
preg_match_all('/\b(?:insert\s+into|update|delete\s+from)\s+public\.(\w+)/i', $cmds3, $tbs);
ok(array_values(array_unique($tbs[1])) === ['po_wa_cards', 'po_wa_fluxo_versoes', 'po_wa_fluxos'],
   'a v3 escreve so nas tres tabelas do fluxo: ' . implode(', ', array_unique($tbs[1])));

// Nenhum comando destrutivo. A v2 tinha um `delete` (os 5 cards que sairam) e
// era o unico comando dela que nao se desfazia; aqui nao sai card nenhum.
ok(preg_match('/\bdelete\b/i', $cmds3) === 0, 'a v3 nao apaga nada');
ok(preg_match('/\bdrop\b|\balter\s+table\b|\btruncate\b/i', $cmds3) === 0, 'e nao tem DDL (por isso roda numa transacao de verdade, tudo ou nada)');

/* O `on conflict` de cada insert, e e aqui que a inversao da v2 aparece:
   `do nothing` nos dois. Um `do update` em po_wa_cards seria a derivacao da v2
   de volta, e com ela a perda das reescritas da cliente. */
ok(preg_match('/insert\s+into\s+public\.po_wa_cards\b/i', $cmds3) === 1, 'ha um unico insert de cards');
ok(preg_match('/insert\s+into\s+public\.po_wa_cards\b.*?on\s+conflict\s*\(\s*id\s*\)\s*do\s+nothing/is', $cmds3) === 1,
   'o insert de cards e `on conflict (id) do nothing`: rodar de novo nao pisa em card que a cliente editou');
ok(preg_match('/on\s+conflict[^;]*do\s+update/i', $cmds3) === 0,
   'NENHUM `on conflict ... do update` no arquivo: era por ele que a v2 sobrescrevia os cards do rascunho');

/* Cada `update public.po_wa_cards`: as colunas atribuidas tem de ser um
   subconjunto de {opcoes, config}. E a trava que o brief exige, e ela nao
   olha palavra solta - olha o ALVO da atribuicao, porque `texto` aparece
   legitimamente dentro do retrato e dentro de comentario. */
preg_match_all('/\bupdate\s+public\.po_wa_cards\b(.*?);/is', $cmds3, $upc, PREG_SET_ORDER);
ok(count($upc) === 3, 'tres comandos mexem em card existente (a religacao e os dois atalhos): ' . count($upc));
$proibidas = ['texto', 'titulo', 'lembrete_texto', 'tipo', 'proximo', 'fallback', 'sem_resposta', 'lembrete_ativo', 'fluxo_id', 'ordem', 'x', 'y'];
foreach ($upc as $u) {
    // O `set` vai de "set" ate o "where" de nivel zero.
    ok(preg_match('/\bset\b(.*?)\bwhere\b/is', $u[1], $sp) === 1, 'o update de card tem set e where (sem where, iria o rascunho inteiro)');
    /* Separa as atribuicoes nas virgulas de nivel zero, FORA de string. A lista
       de atalhos e um literal JSON cheio de virgulas, e um separador que as
       conte partiria `set config = ...` em dezenas de pedacos. */
    $alvos = [];
    $prof = 0; $str = false; $atual = '';
    foreach (str_split($sp[1]) as $ch) {
        if ($ch === "'") { $str = !$str; $atual .= $ch; continue; }
        if (!$str) {
            if ($ch === '(') $prof++;
            if ($ch === ')') $prof--;
            if ($ch === ',' && $prof === 0) { $alvos[] = $atual; $atual = ''; continue; }
        }
        $atual .= $ch;
    }
    $alvos[] = $atual;
    foreach ($alvos as $a) {
        ok(preg_match('/^\s*(\w+)\s*=/', $a, $mm) === 1, 'cada atribuicao do set e "coluna = ...": ' . trim($a));
        ok(in_array($mm[1], ['opcoes', 'config'], true),
           'update de card escreve so opcoes ou config, nunca "' . $mm[1] . '": a cliente reescreveu 11 textos e 6 listas de opcoes no rascunho');
    }
    // E as colunas proibidas nao aparecem como alvo em lugar nenhum do set.
    foreach ($proibidas as $p) {
        ok(preg_match('/\b' . $p . '\s*=/i', $sp[1]) === 0, 'o set nunca atribui ' . $p);
    }
}

/* A religacao e CIRURGICA: ela reconstroi a lista de opcoes trocando o
   `destino` da opcao `e_nao` e preservando o `rotulo` que a cliente
   reescreveu ("Sim, tenho interesse", "Nao, obrigado"). Trocar a lista inteira
   por um literal era o caminho obvio e perderia esse trabalho nos dois cards -
   os dois estao entre os 6 em que ela mexeu em `opcoes`. */
$relig = null;
foreach ($upc as $u) if (strpos($u[1], 'opcoes') !== false) $relig = $u[1];
ok($relig !== null, 'existe o comando que religa o "Nao, obrigado"');
ok(strpos($relig, 'jsonb_set') !== false && strpos($relig, "'{destino}'") !== false,
   'ela usa jsonb_set no caminho {destino} da opcao, em vez de escrever a lista toda');
ok(strpos($relig, "'rotulo'") === false, 'e nunca escreve rotulo: o da cliente fica');
ok(strpos($relig, 'with ordinality') !== false, 'a reconstrucao preserva a ORDEM das opcoes (with ordinality): invertida, "Nao, obrigado" viria antes de "Sim"');
preg_match_all($RE_UUID, $relig, $ru);
ok(array_values(array_unique($ru[0])) === [$DESPEDIDA, $cid('14'), $cid('20')],
   'ela cita so a despedida nova e os dois cards de lista de espera: ' . implode(', ', array_unique($ru[0])));

/* Os dois updates de atalho escrevem config MESCLANDO (`config ||`), nao
   substituindo: um card de marca tem config com `status`/`qualif_*` dentro, e
   substituir apagaria a marca. E os dois so agem com `not (config ? 'atalhos')`,
   para nunca pisarem num atalho que a cliente tenha montado na gaveta. */
$ats = [];
foreach ($upc as $u) if (strpos($u[1], 'atalhos') !== false) $ats[] = $u[1];
ok(count($ats) === 2, 'dois comandos acrescentam atalhos (um por card que espera): ' . count($ats));
foreach ($ats as $a) {
    ok(preg_match('/set\s+config\s*=\s*config\s*\|\|/i', $a) === 1, 'o atalho e MESCLADO no config, nao substitui (card de marca perderia o status)');
    ok(preg_match('/not\s*\(\s*config\s*\?\s*\'atalhos\'\s*\)/i', $a) === 1, 'e so age se o card ainda nao tem atalhos: nunca pisa no que a cliente montou na gaveta');
}

// O ponteiro: um comando, guardado pelo `exists` da versao 3. Sem a guarda,
// apontar para um id que nao gravou deixa o motor sem retrato para ler.
preg_match_all('/\bupdate\s+public\.po_wa_fluxos\b(.*?);/is', $cmds3, $upf, PREG_SET_ORDER);
ok(count($upf) === 1, 'um unico comando move ponteiro: so a triagem ganha versao nova');
ok(preg_match('/\bexists\s*\(/i', $upf[0][1]) === 1, 'e ele e guardado pelo exists da versao 3');
preg_match_all($RE_UUID, $upf[0][1], $uf);
ok(array_values(array_unique($uf[0])) === [$V3, $BV], 'ele cita so a versao 3 e o fluxo da triagem: ' . implode(', ', array_unique($uf[0])));
ok(strpos($cmds3, $V2E) === false, 'a v3 nao toca a "Lista de espera": os cards dela nao mudam, e a v2 dela segue publicada');
ok(preg_match('/\bativo\s*=\s*true/i', $cmds3) === 0, 'a v3 nao liga fluxo nenhum (publicar e ligar sao atos separados)');
ok(preg_match('/\+55(?!48900000)\d{10,11}/', $cmds3) === 0, 'nenhum telefone real na migration (repositorio publico)');

/* ================================================================
   2. O retrato da versao 3
   ================================================================ */
preg_match_all('/\$grafo\$(.*?)\$grafo\$/s', $sql3, $m3);
ok(count($m3[1]) === 1, 'a v3 publica UM fluxo (a triagem): a lista de espera nao mudou');
$g3 = json_decode($m3[1][0], true);
ok(json_last_error() === JSON_ERROR_NONE, 'o retrato da v3 e JSON valido: ' . json_last_error_msg());
ok(preg_match("/po_wa_fluxo_versoes \(id, fluxo_id, versao, grafo\) values \('$V3', '$BV', 3, \\\$grafo\\\$/", $cmds3) === 1,
   'a triagem entra como versao 3, com o id ' . $V3);
ok(preg_match('/insert\s+into\s+public\.po_wa_fluxo_versoes\b.*?on\s+conflict\s+do\s+nothing/is', $cmds3) === 1,
   'o insert do retrato e idempotente');

/* CARD DE CONFIG VAZIA GUARDA `[]`, NAO `{}`, e isto se trava no TEXTO CRU
   porque json_decode apaga a diferenca - em PHP os dois viram array(), entao
   nenhuma assercao sobre $g3 conseguiria ve-la.
   Nao e descuido nem enfeite: `wa_fluxo_grafo_de` monta o retrato em PHP, e
   `json_decode($s, true)` nao distingue objeto vazio de lista vazia. Todo
   retrato que o botao Publicar gravar vai sair assim. Escrever `{}` aqui
   deixaria este arquivo diferente do que a propria casa publica, e a
   conferencia por hash da migration acusaria 11 diferencas inexistentes.
   A v2, que foi escrita a mao, usa `{}` - e por isso ela NAO era igual ao que
   o Publicar produziria. */
ok(substr_count($m3[1][0], '"config": []') === 11, 'os 11 cards de config vazia guardam [], que e o que wa_fluxo_grafo_de produz: ' . substr_count($m3[1][0], '"config": []'));
ok(strpos($m3[1][0], '"config": {}') === false, 'e nenhum guarda {}: a diferenca e invisivel em PHP e visivel na conferencia por hash em SQL');
ok(substr_count($m2[1][0], '"config": {}') > 0, 'a v2, escrita a mao, usava {} (senao a assercao acima nao prova nada)');

ok($g3['id'] === $BV && $g3['nome'] === $g2['nome'], 'a v3 e do MESMO fluxo, nao de fluxo novo');
ok($g3['inicial'] === $g2['inicial'] && $g3['humano'] === $g2['humano'], 'mesmo card inicial e mesmo card de socorro');
ok($g3['porta'] === 'mensagem_nova' && $g3['porta_valor'] === null, 'e a mesma porta de entrada');
ok(count($g2['cards']) === 15, 'a v2 tinha 15 cards');
ok(count($g3['cards']) === 17, 'a v3 tem 17: os 15 mais a despedida e o "sem data": ' . count($g3['cards']));
foreach ($g2['cards'] as $id => $_) ok(isset($g3['cards'][$id]), 'nenhum card da v2 desapareceu na v3: ' . $id);
foreach ($g3['cards'] as $id => $c) ok($c['id'] === $id, 'a chave do card e o id dele: ' . $id);

/* AS REESCRITAS DA CLIENTE ESTAO DENTRO, e esta e a assercao que justifica a
   inversao. Medido em 05/11/2026 contra o retrato da v2: 11 textos, 6 listas de
   opcoes e 3 lembretes diferentes. O retrato e imutavel, entao estes numeros
   nao envelhecem: eles descrevem um arquivo congelado. */
$mudou = ['texto' => [], 'opcoes' => [], 'lembrete_texto' => []];
foreach ($g2['cards'] as $id => $v2c) {
    foreach (array_keys($mudou) as $campo) {
        if (($g3['cards'][$id][$campo] ?? null) != $v2c[$campo]) $mudou[$campo][] = $id;
    }
}
ok(count($mudou['texto']) === 11, 'a v3 carrega os 11 textos que a cliente reescreveu no rascunho: ' . count($mudou['texto']));
ok(count($mudou['lembrete_texto']) === 3, 'e os 3 lembretes: ' . count($mudou['lembrete_texto']));
/* `opcoes`: os 6 em que ela mexeu no rotulo, e nos cards 14 e 20 a religacao
   entra no MESMO campo. Os dois estao na lista dela, e e justamente por isso a
   religacao tem de ser cirurgica. */
ok(count($mudou['opcoes']) === 6, 'e as 6 listas de opcoes: ' . implode(', ', $mudou['opcoes']));
foreach ([$cid('14'), $cid('20')] as $k) ok(in_array($k, $mudou['opcoes'], true), 'o card ' . substr($k, -2) . ' esta entre eles');
// Guarda: ausencia que nunca esteve presente nao prova nada, e o inverso tambem.
ok($g3['cards'][$cid('01')]['texto'] !== $g2['cards'][$cid('01')]['texto'], 'o texto da boas-vindas da v3 e o da cliente, nao o da v2');
ok($g3['cards'][$cid('06')]['texto'] === $g2['cards'][$cid('06')]['texto'], 'e card que ela nao tocou segue igual (senao a conta acima nao prova nada)');

/* O RETRATO INTEIRO, POR HASH. Retrato publicado e IMUTAVEL: quem precisa de
   outra conversa publica uma versao nova, nunca reescreve uma que ja esta no
   banco e pode ter conversa presa a ela. Entao este hash nao envelhece, e
   qualquer edicao neste bloco $grafo$ - uma virgula, um uuid, um texto - deixa
   o teste vermelho.
   Ele existe porque as contas acima medem QUANTOS campos diferem da v2, nunca
   QUAIS: trocar o texto que a cliente escreveu por outro texto errado mantem a
   conta em 11 e passaria despercebido. A rodada de mutacao de 05/11 provou
   isso com dois mutantes vivos (um texto reescrito e um rotulo de botao
   revertido ao da v2), e e este hash que os mata.
   E o MESMO valor que o banco devolve: conferido em 05/11 contra o grafo
   gravado em po_wa_fluxo_versoes, lido direto por PostgREST. */
$canon = function ($x) use (&$canon) {
    if (!is_array($x)) return $x;
    if (array_keys($x) !== range(0, count($x) - 1)) ksort($x);
    foreach ($x as $k => $v) $x[$k] = $canon($v);
    return $x;
};
$hash_v3 = md5(json_encode($canon($g3), JSON_UNESCAPED_UNICODE));
ok($hash_v3 === '20ca931138c257fe6333a8678b73e0ae',
   'o retrato da v3 e exatamente o que foi publicado no banco em 05/11/2026 (md5 canonico): ' . $hash_v3);
ok(md5(json_encode($canon($g2), JSON_UNESCAPED_UNICODE)) !== $hash_v3, 'e o da v2 e outro (senao o hash nao discrimina nada)');

/* ---------- os dois cards novos ---------- */
$d = $g3['cards'][$DESPEDIDA] ?? null;
ok(is_array($d), 'o card "Despedida de quem recusou a lista" existe');
ok($d['tipo'] === 'fim', 'e ele e do tipo fim: ' . $d['tipo']);
ok(preg_match('#https?://[^\s]*/roteiros#', (string) $d['texto']) === 1,
   'e leva o link de /roteiros: o link clicavel ja funciona (wa_send_text manda preview_url)');
ok(preg_match('/\{[^}]*\}/', (string) $d['texto']) === 0, 'sem variavel nenhuma: quem recusa a lista pode nunca ter escolhido roteiro');
ok($d['proximo'] === null && $d['fallback'] === null && $d['sem_resposta'] === null, 'card de fim nao tem saida');

$s = $g3['cards'][$SEM_DATA] ?? null;
ok(is_array($s), 'o card "Sem data para este destino" existe');
ok($s['tipo'] === 'botoes', 'e ele e do tipo botoes: ' . $s['tipo']);
ok(strpos((string) $s['texto'], '{destino}') !== false, 'e usa {destino}: reconhecer a Italia e nao dizer "Italia" entrega metade do valor pelo preco inteiro');
$dests = array_column($s['opcoes'], 'destino', 'id');
ok(count($s['opcoes']) === 2, 'duas opcoes');
ok(in_array($cid('15'), $dests, true), 'o "sim" vai para o card que salta para o fluxo da lista de espera (que ja existe)');
ok(in_array($DESPEDIDA, $dests, true), 'e o "nao" vai para a despedida nova');
ok($g3['cards'][$cid('15')]['tipo'] === 'ir_para_fluxo' && $g3['cards'][$cid('15')]['config']['fluxo'] === $ESP,
   'e esse card salta mesmo para a "Lista de espera"');

/* ---------- as duas religacoes ---------- */
$e_nao = function ($card) use ($g3) {
    foreach ($g3['cards'][$card]['opcoes'] as $o) if ($o['id'] === 'e_nao') return $o;
    return null;
};
foreach ([$cid('14'), $cid('20')] as $k) {
    $o = $e_nao($k);
    ok(is_array($o), 'o card ' . substr($k, -2) . ' tem a opcao e_nao');
    ok($o['destino'] === $DESPEDIDA, 'e ela aponta para a despedida de quem RECUSOU, nao para a de quem nao responde: ' . $o['destino']);
    // Guarda: na v2 ela apontava para a despedida de quem nao responde (achado 2 da spec).
    $v2o = null;
    foreach ($g2['cards'][$k]['opcoes'] as $oo) if ($oo['id'] === 'e_nao') $v2o = $oo;
    ok($v2o['destino'] === $cid('17'), 'e na v2 apontava para o card 17 (senao a religacao nao e religacao nenhuma)');
    // O rotulo da cliente sobreviveu a religacao.
    ok($o['rotulo'] === $v2o['rotulo'] || $o['rotulo'] !== '', 'o rotulo continua escrito');
}
ok($g3['cards'][$cid('17')]['tipo'] === 'fim' && $DESPEDIDA !== $cid('17'), 'as duas despedidas sao cards diferentes');

/* ---------- as listas de atalhos ---------- */
$tipos_de = function ($card) use ($g3) { return array_column(wa_atalhos_do_card($g3['cards'][$card]), 'tipo'); };
ok($tipos_de($cid('01')) === ['palavras', 'roteiro', 'destino'],
   'a ordem dos atalhos na boas-vindas e palavras, roteiro, destino: pedido explicito por uma pessoa ganha de palpite, e roteiro a venda e resposta melhor que "nao temos data"');
$a1 = wa_atalhos_do_card($g3['cards'][$cid('01')]);
ok($a1[0]['destino'] === $HUMANO, 'o atalho de palavras leva ao atendimento humano');
ok($a1[1]['destino'] === $PDF, 'o de roteiro leva ao card que manda o PDF');
ok($a1[2]['destino'] === $SEM_DATA, 'e o de destino leva ao "Sem data para este destino"');
foreach ($a1 as $i => $a) {
    ok(trim($a['saudacao']) !== '', 'atalho ' . ($i + 1) . ' da boas-vindas tem saudacao: no card inicial, sem ela a pessoa receberia um PDF como primeira mensagem da vida');
    ok(strpos($a['saudacao'], '{') === false, 'e a saudacao nao usa variavel: ela sai ANTES do salto, quando nada foi escolhido ainda');
}
ok(count($a1[0]['palavras']) >= 3, 'o atalho de palavras tem palavras de verdade');
foreach ($a1[0]['palavras'] as $p) {
    ok(mb_strlen(trim(wa_normaliza($p)), 'UTF-8') >= 4, 'cada palavra tem 4 caracteres ou mais depois de normalizar: "' . $p . '"');
}

/* O card "Qual viagem?" tambem le a frase, e nao e enfeite: e ali que a pessoa
   digita "Italia" depois de tocar em "Conhecer os roteiros". Sem isto, o unico
   lugar que entende um destino sem data seria a PRIMEIRA mensagem da conversa.
   Ele NAO ganha atalho de roteiro: o proprio card ja casa roteiro por nome e
   por numero, e um atalho de roteiro ali seria uma segunda regra para a mesma
   pergunta. */
ok($tipos_de($cid('02')) === ['palavras', 'destino'], 'o card "Qual viagem?" tem atalho de palavras e de destino, e nenhum de roteiro: ' . implode(',', $tipos_de($cid('02'))));
$a2 = wa_atalhos_do_card($g3['cards'][$cid('02')]);
ok($a2[0]['destino'] === $HUMANO && $a2[1]['destino'] === $SEM_DATA, 'e eles levam ao humano e ao "sem data"');
foreach ($a2 as $a) ok($a['saudacao'] === '', 'atalho de card do meio da conversa nao tem saudacao: no ponto 2 ela e ignorada, e escrever uma ali promete o que nao sai');
// E nenhum outro card ganhou atalho: atalho em card que a cliente nao pediu e
// estrutura que ela tem de entender sem ter escolhido. A gaveta do painel
// (Tarefa 7) existe para ela por atalho onde quiser.
$com_atalho = [];
foreach ($g3['cards'] as $id => $c) if (wa_atalhos_do_card($c)) $com_atalho[] = $id;
ok($com_atalho === [$cid('01'), $cid('02')], 'so estes dois cards tem atalho: ' . implode(', ', $com_atalho));
/* Os atalhos do RETRATO sao byte a byte os que a migration escreve no
   RASCUNHO. Sem isto o painel desenharia um atalho e o motor executaria outro,
   que e o pior modo de falha desta tela, e nenhuma das travas acima o veria:
   as duas metades sao lidas de lugares diferentes do mesmo arquivo. */
foreach ([$cid('01'), $cid('02')] as $k) {
    $j = json_encode(['atalhos' => wa_atalhos_do_card($g3['cards'][$k])], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $meus = array_values(array_filter($ats, function ($a) use ($k) { return strpos($a, "id = '" . $k . "'") !== false; }));
    ok(count($meus) === 1, 'ha um comando de atalho para o card ' . substr($k, -2) . ': ' . count($meus));
    ok(strpos($meus[0], $j) !== false, 'e o que ele grava no rascunho e exatamente o que o retrato publica (card ' . substr($k, -2) . ')');
}

/* ================================================================
   3. O validador e o roteador de verdade
   ================================================================ */
$fluxo3 = ['id' => $g3['id'], 'nome' => $g3['nome'], 'gatilho' => $g3['porta'],
           'gatilho_valor' => $g3['porta_valor'], 'ativo' => false, 'card_inicial' => $g3['inicial']];
$cards3 = array_values($g3['cards']);

// O retrato e o que o montador oficial produz a partir dos mesmos cards.
$refeito = wa_fluxo_grafo_de($fluxo3, $cards3);
ok($refeito['cards'] == $g3['cards'], 'o retrato da v3 e o mesmo que wa_fluxo_grafo_de produz a partir dos cards dele');
ok($refeito['porta'] === $g3['porta'] && $refeito['porta_valor'] === $g3['porta_valor'] && $refeito['humano'] === $g3['humano'], 'porta e socorro do retrato');

// Com a espera publicada e LIGADA (a ordem que o painel manda seguir): zero bloqueio.
$espera_ligada = [['id' => $ESP, 'gatilho' => 'chamado', 'gatilho_valor' => null, 'ativo' => true, 'versao_atual' => $V2E]];
$probs = wa_fluxo_valida($fluxo3, $cards3, $espera_ligada);
$bl = array_values(array_filter($probs, function ($p) { return $p['bloqueia']; }));
ok(!$bl, 'a v3 publica sem bloqueio nenhum: ' . json_encode($bl, JSON_UNESCAPED_UNICODE));
// As regras que esta tarefa mais poderia acender, filtradas pela REGRA e nao pelo total.
foreach ([3 => 'card inalcancavel', 11 => 'beco sem saida', 13 => 'PDF sem roteiro', 14 => 'variavel', 17 => 'forma do atalho'] as $r => $qual) {
    $f = array_values(array_filter($probs, function ($p) use ($r) { return $p['regra'] === $r; }));
    ok(!$f, 'nenhum problema de ' . $qual . ' (regra ' . $r . '): ' . json_encode($f, JSON_UNESCAPED_UNICODE));
}

/* O card "Sem data para este destino" e alcancavel SO por atalho, e e por isso
   que a aresta de atalho conta na travessia do validador. Prova pelo avesso:
   tirado o atalho de destino dos dois cards, a regra 3 o reprova. */
$sem_atalho = [];
foreach ($cards3 as $c) {
    if (isset($c['config']['atalhos'])) {
        $c['config']['atalhos'] = array_values(array_filter($c['config']['atalhos'], function ($a) { return $a['tipo'] !== 'destino'; }));
    }
    $sem_atalho[] = $c;
}
$r3 = array_values(array_filter(wa_fluxo_valida($fluxo3, $sem_atalho, $espera_ligada), function ($p) { return $p['regra'] === 3; }));
ok(count($r3) === 1 && $r3[0]['card'] === $SEM_DATA,
   'sem o atalho de destino o card "Sem data" fica inalcancavel (regra 3): e a aresta de atalho que o torna publicavel');
/* E o {destino} do mesmo card e guardado pela regra 14, que e uma trava
   DIFERENTE da de cima e precisa da sua propria prova. Remover o atalho torna o
   card inalcancavel, e card inalcancavel nem entra na travessia da regra 14 -
   quem acusa ali e a regra 3. Entao a prova pelo avesso aqui e outra: o atalho
   continua existindo e levando ao card, mas com tipo `palavras` em vez de
   `destino`. O card segue alcancavel e NINGUEM disse um destino, que e
   exatamente o caminho em que {destino} chegaria vazio ao cliente. */
$tipo_trocado = [];
foreach ($cards3 as $c) {
    if (isset($c['config']['atalhos'])) {
        foreach ($c['config']['atalhos'] as $k => $a) {
            if ($a['tipo'] === 'destino') {
                $c['config']['atalhos'][$k]['tipo'] = 'palavras';
                $c['config']['atalhos'][$k]['palavras'] = ['italia', 'portugal'];
            }
        }
    }
    $tipo_trocado[] = $c;
}
$probs_tt = wa_fluxo_valida($fluxo3, $tipo_trocado, $espera_ligada);
$r14 = array_values(array_filter($probs_tt, function ($p) { return $p['regra'] === 14; }));
ok(count($r14) === 1 && $r14[0]['card'] === $SEM_DATA, 'com o atalho virando palavras, a regra 14 acusa o {destino}: ' . json_encode($r14, JSON_UNESCAPED_UNICODE));
$r3b = array_values(array_filter($probs_tt, function ($p) { return $p['regra'] === 3; }));
ok(!$r3b, 'e nesse caso a regra 3 NAO acusa: o card segue alcancavel, so sem destino no contexto');

// Com a espera DESLIGADA, o atrito de sempre e nada mais: a regra 11 obriga a
// ligar a "Lista de espera" antes da triagem, porque a triagem salta para dentro dela.
$espera_desligada = [['id' => $ESP, 'gatilho' => 'chamado', 'gatilho_valor' => null, 'ativo' => false, 'versao_atual' => $V2E]];
$bl11 = array_values(array_filter(wa_fluxo_valida($fluxo3, $cards3, $espera_desligada), function ($p) { return $p['bloqueia']; }));
ok(count($bl11) === 1 && $bl11[0]['regra'] === 11 && $bl11[0]['card'] === $cid('15'),
   'com a espera desligada, a v3 bloqueia so no salto (regra 11, card 15): ' . json_encode($bl11, JSON_UNESCAPED_UNICODE));

/* ---------- o roteador, com o catalogo e os destinos em duble ---------- */
$ROTEIROS = [
    ['slug' => 'chile-e-deserto-do-atacama', 'titulo' => 'Chile, Santiago e Deserto do Atacama', 'ativo' => true, 'periodo' => 'De 10/03/27 à 20/03/27', 'pdf_url' => 'x.pdf'],
    ['slug' => 'turquia', 'titulo' => 'Turquia com Antália', 'ativo' => true, 'periodo' => 'De 27/10/27 à 12/11/27', 'pdf_url' => 'y.pdf'],
];
$DESTINOS = [
    ['nome' => 'Itália', 'palavras' => 'italia, roma, veneza, toscana', 'ativo' => true],
    ['nome' => 'Chile',  'palavras' => 'chile, atacama, santiago',      'ativo' => true],
];
wa_fluxo_set_deps([
    'roteiros' => function () use ($ROTEIROS) { return $ROTEIROS; },
    'destinos' => function () use ($DESTINOS) { return $DESTINOS; },
]);

// Ponto 1 (primeira mensagem da conversa): os tres atalhos da boas-vindas.
$inicial = $g3['cards'][$g3['inicial']];
$at = wa_fluxo_atalho($g3, $inicial, 'preciso falar com um atendente', []);
ok($at !== null && $at['card'] === $HUMANO && $at['tipo'] === 'palavras', 'quem escreve "falar com um atendente" na primeira mensagem vai direto para a Lais');
ok(trim($at['saudacao']) !== '', 'e recebe a saudacao antes do salto');

$at = wa_fluxo_atalho($g3, $inicial, 'quero saber do Chile', []);
ok($at !== null && $at['card'] === $PDF && $at['roteiro_slug'] === 'chile-e-deserto-do-atacama',
   'quem escreve "quero saber do Chile" recebe o roteiro do Chile: ele esta a venda, e roteiro a venda ganha de "nao temos data"');

$at = wa_fluxo_atalho($g3, $inicial, 'tem viagem para a Italia?', []);
ok($at !== null && $at['card'] === $SEM_DATA && $at['destino_nome'] === 'Itália' && $at['roteiro_slug'] === null,
   'e quem escreve "Italia" - destino que a casa vende e que nao tem data - cai no card "Sem data", com o nome do destino no contexto');

// O Chile e as DUAS coisas (roteiro a venda e destino cadastrado), e a ordem decide.
$at = wa_fluxo_atalho($g3, $inicial, 'Chile', []);
ok($at !== null && $at['card'] === $PDF, 'o Chile esta nas duas listas, e a ordem faz o roteiro vencer o destino');

/* Ponto 2 (card que espera): a frase digitada no "Qual viagem?". O contexto
   leva os roteiros JA ENVIADOS, que e o estado real de quem acabou de receber
   a lista numerada - e casar por nome so vale contra essa lista. */
$enviou = ['roteiros_enviados' => ['chile-e-deserto-do-atacama', 'turquia']];
$p = wa_fluxo_proximo($g3, $cid('02'), 'Itália', $enviou + ['tentativas' => 1], 'quero ir para a Italia');
ok($p['card'] === $SEM_DATA && $p['motivo'] === 'atalho', 'digitar "Italia" no "Qual viagem?" cai no card "Sem data", nao no "nao entendi"');
ok($p['ctx']['destino_nome'] === 'Itália', 'e o nome do destino entra no contexto');
ok((int) $p['ctx']['tentativas'] === 0, 'atalho que dispara nao conta tentativa: foi rota certa');

$p = wa_fluxo_proximo($g3, $cid('02'), 'Turquia com Antalia', $enviou + ['tentativas' => 0], 'Turquia com Antalia');
ok($p['motivo'] === 'escolheu' && $p['ctx']['roteiro_slug'] === 'turquia', 'e o casamento de roteiro do proprio card continua vindo ANTES do atalho');
$p = wa_fluxo_proximo($g3, $cid('02'), '1', $enviou + ['tentativas' => 0], '1');
ok($p['motivo'] === 'escolheu' && $p['ctx']['roteiro_slug'] === 'chile-e-deserto-do-atacama', 'e a escolha por numero tambem: o atalho nao rouba a resposta esperada do card');

// O "sim" e o "nao" do card novo.
$p = wa_fluxo_proximo($g3, $SEM_DATA, $s['opcoes'][0]['id'], ['destino_nome' => 'Itália'], '');
ok($p['card'] === $cid('15') && $p['motivo'] === 'opcao', 'o "sim" do card novo salta para o fluxo da lista de espera');
ok($p['ctx']['destino_nome'] === 'Itália', 'e o destino atravessa o salto: e dele que a espera e anotada');
$p = wa_fluxo_proximo($g3, $SEM_DATA, $s['opcoes'][1]['id'], ['destino_nome' => 'Itália'], '');
ok($p['card'] === $DESPEDIDA && $p['motivo'] === 'opcao', 'e o "nao" vai para a despedida de quem recusou');

// A religacao, no roteador.
foreach ([$cid('14'), $cid('20')] as $k) {
    $p = wa_fluxo_proximo($g3, $k, 'e_nao', [], '');
    ok($p['card'] === $DESPEDIDA, 'o "Nao, obrigado" do card ' . substr($k, -2) . ' leva a despedida de quem recusou');
    $p = wa_fluxo_proximo($g3, $k, 'e_sim', [], '');
    ok($p['card'] === $cid('15'), 'e o "Sim" segue para a lista de espera');
}

/* ================================================================
   4. Copy e limites da Cloud API, nos cards novos
   ================================================================ */
$emoji = '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}]/u';
foreach ([$DESPEDIDA, $SEM_DATA] as $k) {
    $c = $g3['cards'][$k];
    foreach ([$c['titulo'], (string) $c['texto'], (string) $c['lembrete_texto']] as $t) {
        ok(strpos($t, '—') === false && strpos($t, '–') === false, 'sem travessao nem meia-risca: ' . $c['titulo']);
        ok(preg_match($emoji, $t) === 0, 'sem emoji: ' . $c['titulo']);
        ok(preg_match('/\+55\s?\d|\(\d{2}\)\s?\d{4,5}-?\d{4}/', $t) === 0, 'sem telefone no texto: ' . $c['titulo']);
    }
    // 1024 e o teto de corpo de botoes e de legenda de documento na Cloud API, e
    // a Meta recusa a mensagem INTEIRA acima disso, em silencio.
    ok(wa_fluxo_tam_pior_caso((string) $c['texto']) <= WA_FLUXO_LIMITE[$c['tipo']],
       'texto dentro do limite do tipo no PIOR caso da variavel ({destino} valendo ' . WA_FLUXO_VAR_MAX['destino'] . '): ' . $c['titulo']);
    foreach ($c['opcoes'] as $o) {
        ok($o['id'] !== '', 'toda opcao tem id: e ele que o WhatsApp devolve no toque');
        ok(mb_strlen($o['rotulo'], 'UTF-8') <= 20, 'rotulo de ate 20 caracteres (a Cloud API recusa acima, e wa_send_buttons nao trunca): "' . $o['rotulo'] . '"');
    }
    ok(count($c['opcoes']) <= 3, 'ate 3 botoes: ' . $c['titulo']);
    // Card que espera nao pode ser beco para quem cala.
    if (in_array($c['tipo'], WA_CARD_ESPERA, true)) {
        ok(!empty($c['sem_resposta']) && in_array($g3['cards'][$c['sem_resposta']]['tipo'], WA_SEM_RESPOSTA_TIPOS, true), 'sem_resposta leva a texto, marca, humano ou fim: ' . $c['titulo']);
        ok(!empty($c['fallback']) && isset($g3['cards'][$c['fallback']]), 'e tem fallback para quem responde outra coisa: ' . $c['titulo']);
        ok($c['lembrete_ativo'] === true && trim((string) $c['lembrete_texto']) !== '', 'lembrete ligado e escrito: ' . $c['titulo']);
    }
}

/* O CARD QUE ENTRA NO RASCUNHO E O MESMO QUE ENTRA NO RETRATO, campo por campo.
   A v2 tinha esta trava pelo avesso (`$confere_derivacao`: o rascunho era
   derivado do retrato pelo mesmo jsonb_each, entao nao havia como divergirem).
   Aqui a relacao e invertida - o retrato e copia do rascunho - e os dois cards
   novos sao o unico lugar do arquivo em que a mesma informacao e escrita DUAS
   vezes, a mao. Divergir ali faz o painel desenhar uma conversa e o motor
   executar outra, que e o pior modo de falha desta tela. A rodada de mutacao
   de 05/11 achou isto com o card novo nascendo `texto` no rascunho e `botoes`
   no retrato, com o teste verde. */
preg_match('/insert\s+into\s+public\.po_wa_cards\s*\((.*?)\)\s*values\s*(.*?)\s*on\s+conflict/is', $cmds3, $ic);
ok(count($ic) === 3, 'o insert dos cards novos esta no formato esperado');
$colunas = array_map('trim', explode(',', $ic[1]));
ok($colunas === ['id', 'fluxo_id', 'tipo', 'titulo', 'texto', 'opcoes', 'config', 'proximo', 'fallback',
                 'sem_resposta', 'lembrete_ativo', 'lembrete_texto', 'ordem', 'x', 'y'],
   'com as 15 colunas na ordem conhecida: ' . implode(',', $colunas));
/* Separa as linhas e os campos respeitando a string SQL (virgula dentro de
   texto e de JSON nao e separador, e '' e uma aspa escapada). */
$linhas = []; $campos = []; $atual = ''; $prof = 0; $str = false;
$bytes = str_split($ic[2]);
for ($i = 0; $i < count($bytes); $i++) {
    $ch = $bytes[$i];
    if ($str) {
        if ($ch === "'" && ($bytes[$i + 1] ?? '') === "'") { $atual .= "'"; $i++; continue; }
        if ($ch === "'") { $str = false; continue; }
        $atual .= $ch;
        continue;
    }
    if ($ch === "'") { $str = true; continue; }
    if ($ch === '(') { $prof++; if ($prof === 1) { $campos = []; $atual = ''; } continue; }
    if ($ch === ')') { $prof--; if ($prof === 0) { $campos[] = trim($atual); $linhas[] = $campos; $atual = ''; } continue; }
    if ($ch === ',' && $prof === 1) { $campos[] = trim($atual); $atual = ''; continue; }
    if ($prof === 1) $atual .= $ch;
}
ok(count($linhas) === 2, 'duas linhas de card novo: ' . count($linhas));
foreach ($linhas as $l) {
    ok(count($l) === 15, 'a linha tem 15 campos: ' . count($l));
    $v = array_combine($colunas, $l);
    $nn = substr($v['id'], -2);
    $c = $g3['cards'][$v['id']] ?? null;
    ok(is_array($c), 'o card ' . $nn . ' do rascunho existe no retrato');
    ok($v['fluxo_id'] === $BV, 'e pertence a triagem');
    foreach (['tipo', 'titulo'] as $k) ok($v[$k] === $c[$k], "o card $nn tem o mesmo $k nos dois lados: '" . $v[$k] . "' x '" . $c[$k] . "'");
    foreach (['texto', 'lembrete_texto'] as $k) {
        $esperado = $c[$k] === null ? 'null' : $c[$k];
        ok($v[$k] === $esperado, "o card $nn tem o mesmo $k nos dois lados");
    }
    foreach (['proximo', 'fallback', 'sem_resposta'] as $k) {
        ok($v[$k] === ($c[$k] === null ? 'null' : $c[$k]), "o card $nn tem o mesmo $k nos dois lados");
    }
    ok($v['lembrete_ativo'] === ($c['lembrete_ativo'] ? 'true' : 'false'), "o card $nn tem o mesmo lembrete_ativo nos dois lados");
    // opcoes e config vao como literal JSON seguido de ::jsonb: compara decodificado.
    foreach (['opcoes', 'config'] as $k) {
        $j = json_decode(preg_replace('/::jsonb$/', '', $v[$k]), true);
        ok(json_last_error() === JSON_ERROR_NONE, "o $k do card $nn e JSON valido no insert");
        ok($j == $c[$k], "o card $nn tem o mesmo $k nos dois lados");
    }
}

// E o desenho: card sem posicao a tela empilha em 0,0, por cima de outro.
preg_match_all("/\('([0-9a-f-]{36})',\s*'[0-9a-f-]{36}',.*?,\s*(\d+),\s*(-?\d+),\s*(-?\d+)\)/s", $cmds3, $pos, PREG_SET_ORDER);
ok(count($pos) === 2, 'os dois cards novos entram com ordem, x e y: ' . count($pos));
$ocupado = [];
foreach ($g2['cards'] as $id => $_) {
    preg_match_all("/\('$id',\s*(\d+),\s*(-?\d+),\s*(-?\d+)\)/", $sql2, $pv2, PREG_SET_ORDER);
    if ($pv2) $ocupado[$pv2[0][2] . ',' . $pv2[0][3]] = $id;
}
foreach ($pos as $p) {
    ok(in_array($p[1], [$DESPEDIDA, $SEM_DATA], true), 'a posicao e de um dos cards novos: ' . $p[1]);
    ok(!isset($ocupado[$p[3] . ',' . $p[4]]), 'e nao cai em cima de um card da v2: ' . $p[3] . ',' . $p[4]);
    $ocupado[$p[3] . ',' . $p[4]] = $p[1];
}
ok((int) $pos[0][2] > 15 && (int) $pos[1][2] > 15, 'e a ordem dos novos vem depois dos 15 (a ordem decide qual card humano e o socorro)');

echo "ok test-fluxo-v3\n";
