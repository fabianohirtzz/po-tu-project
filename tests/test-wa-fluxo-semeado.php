<?php
/* A prova de que as pecas do Plano A fecham: os dois fluxos que a migration
   semeia sao lidos como TEXTO (o que o Supabase vai gravar, nao uma copia) e
   passam pelo validador e pelo roteador de verdade. Nenhuma rede. */
require __DIR__ . '/../lib/wa-fluxo.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

$sql = @file_get_contents(__DIR__ . '/../supabase/migrations/2026-10-27-fluxo-semeado.sql');
ok($sql !== false, 'a migration do fluxo semeado existe');

// O retrato de cada versao vai entre dollar-quotes ($grafo$ ... $grafo$).
preg_match_all('/\$grafo\$(.*?)\$grafo\$/s', $sql, $m);
$grafos = [];
foreach ($m[1] as $j) {
    $g = json_decode($j, true);
    ok(json_last_error() === JSON_ERROR_NONE, 'o retrato semeado e JSON valido: ' . json_last_error_msg());
    $grafos[] = $g;
}
ok(count($grafos) === 2, 'a migration semeia dois fluxos');

// O que a migration grava em po_wa_fluxos e po_wa_fluxo_versoes tem que casar com o retrato.
preg_match_all("/\('([0-9a-f-]{36})',\s*'([^']+)',\s*'(\w+)',\s*(null|'[^']*'),\s*(false|true),\s*'([0-9a-f-]{36})'\)/", $sql, $fx, PREG_SET_ORDER);
ok(count($fx) === 2, 'duas linhas em po_wa_fluxos');
preg_match_all("/po_wa_fluxo_versoes \(id, fluxo_id, versao, grafo\) values \('([0-9a-f-]{36})', '([0-9a-f-]{36})', 1, \\\$grafo\\\$/", $sql, $vs, PREG_SET_ORDER);
ok(count($vs) === 2, 'duas versoes, ambas a versao 1');
preg_match_all("/update public\.po_wa_fluxos set versao_atual = '([0-9a-f-]{36})' where id = '([0-9a-f-]{36})' and versao_atual is null/", $sql, $up, PREG_SET_ORDER);
ok(count($up) === 2, 'versao_atual aponta para a versao 1 de cada fluxo, sem pisar numa publicacao posterior');
foreach ($grafos as $i => $g) {
    ok($fx[$i][1] === $g['id'], 'a linha do fluxo tem o id do retrato');
    ok($fx[$i][2] === $g['nome'], 'a linha do fluxo tem o nome do retrato');
    ok($fx[$i][3] === $g['porta'], 'a linha do fluxo tem a porta do retrato');
    ok($fx[$i][5] === 'false', 'o fluxo "' . $g['nome'] . '" nasce desligado');
    ok($fx[$i][6] === $g['inicial'], 'a linha do fluxo aponta para o card inicial do retrato');
    ok($vs[$i][2] === $g['id'], 'a versao pertence ao fluxo do retrato');
    ok($up[$i][2] === $g['id'] && $up[$i][1] === $vs[$i][1], 'versao_atual e a versao 1 deste fluxo');
}
ok(preg_match('/ativo\s*=\s*true/i', $sql) === 0, 'nenhum comando liga fluxo');

// Todo card do retrato tem lugar no desenho (ordem, x, y), senao a tela os empilha em 0,0.
preg_match_all("/\('([0-9a-f-]{36})',\s*\d+,\s*-?\d+,\s*-?\d+\)/", $sql, $pos);
foreach ($grafos as $g) {
    foreach ($g['cards'] as $id => $c) {
        ok(in_array($id, $pos[1], true), 'card "' . $c['titulo'] . '" tem posicao no desenho');
        ok($c['id'] === $id, 'a chave do card e o id dele');
    }
}

// Texto: sem travessao (nem meia-risca), sem emoji, sem telefone, em todo campo que o cliente final ou a cliente leem.
$emoji = '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}]/u';
foreach ($grafos as $g) {
    $textos = [$g['nome']];
    foreach ($g['cards'] as $c) {
        foreach (['titulo', 'texto', 'lembrete_texto'] as $k) if (isset($c[$k])) $textos[] = (string) $c[$k];
        foreach ($c['opcoes'] as $o) { $textos[] = $o['rotulo']; if (isset($o['descricao'])) $textos[] = $o['descricao']; }
        if (isset($c['config']['botao'])) $textos[] = $c['config']['botao'];
        // O nome da atendente e rotulo de botao (pedido do cliente); no corpo da mensagem ela e "nossa equipe".
        foreach (['texto', 'lembrete_texto'] as $k) {
            $t = (string) ($c[$k] ?? '');
            ok(stripos($t, 'Laís') === false && stripos($t, 'Lais') === false, 'nenhum nome de pessoa no corpo do card ' . $c['titulo']);
        }
    }
    foreach ($textos as $t) {
        ok(strpos($t, '—') === false && strpos($t, '–') === false, 'nenhum travessao: ' . $t);
        ok(!preg_match($emoji, $t), 'nenhum emoji: ' . $t);
        ok(!preg_match('/\d{4,}[-\s]?\d{4}/', $t), 'nenhum telefone no texto: ' . $t);
    }
}

$bv  = $grafos[0];
$esp = $grafos[1];

foreach ($grafos as $i => $g) {
    ok(isset($g['cards'], $g['inicial']) && isset($g['cards'][$g['inicial']]), 'o grafo semeado esta no formato do interpretador');
    ok(($g['cards'][$g['humano']]['tipo'] ?? null) === 'humano' || $g['humano'] === null, 'o card de socorro do retrato e um card humano');

    // 1. valida limpo: zero bloqueios. As regras 8 (beco sem saida) e 11 (salto) merecem nome:
    $fluxo = ['id' => $g['id'], 'nome' => $g['nome'], 'gatilho' => $g['porta'],
              'gatilho_valor' => $g['porta_valor'], 'ativo' => false, 'card_inicial' => $g['inicial']];
    $outro = $i === 0 ? $esp : $bv;
    $outros = [['id' => $outro['id'], 'gatilho' => $outro['porta'], 'gatilho_valor' => $outro['porta_valor'],
                'ativo' => true, 'versao_atual' => 'v1']];
    foreach (wa_fluxo_valida($fluxo, array_values($g['cards']), $outros) as $p) {
        ok(!$p['bloqueia'], 'fluxo "' . $g['nome'] . '" sem bloqueio (regra ' . $p['regra'] . '): ' . $p['texto']);
    }
    // O retrato semeado e o que o montador oficial produziria a partir dos mesmos cards.
    $refeito = wa_fluxo_grafo_de($fluxo, array_values($g['cards']));
    ok($refeito['cards'] == $g['cards'], 'o retrato semeado e o mesmo que wa_fluxo_grafo_de produz');
    ok($refeito['porta'] === $g['porta'] && $refeito['porta_valor'] === $g['porta_valor'], 'porta do retrato');

    foreach ($g['cards'] as $c) {
        $n = count($c['opcoes']);
        // 2. rotulos: 20 caracteres no botao, 24 na linha, no maximo 3 botoes; ids de opcao distintos
        foreach ($c['opcoes'] as $o) {
            $lim = $c['tipo'] === 'lista' ? 24 : 20;
            ok(mb_strlen($o['rotulo'], 'UTF-8') <= $lim, 'rotulo "' . $o['rotulo'] . '" cabe em ' . $lim);
            ok($o['id'] !== '', 'toda opcao tem id (e ele que volta no toque)');
        }
        ok(count(array_unique(array_column($c['opcoes'], 'id'))) === $n, 'ids de opcao distintos no card ' . $c['titulo']);
        if ($c['tipo'] === 'botoes') ok($n >= 1 && $n <= 3, 'botoes: de 1 a 3');
        if (($c['config']['botao'] ?? null) !== null) ok(mb_strlen($c['config']['botao'], 'UTF-8') <= 20, 'rotulo do botao da lista cabe em 20');
        // 3. limites da Cloud API no texto cru tambem (o validador conta o pior caso; aqui o piso)
        if (in_array($c['tipo'], ['botoes', 'lista', 'pdf'], true)) ok(mb_strlen((string) $c['texto'], 'UTF-8') <= 1024, 'corpo de ate 1024: ' . $c['titulo']);
        // 4. nenhum beco sem saida para quem cala: card que espera declara a despedida e o lembrete
        if (in_array($c['tipo'], WA_CARD_ESPERA, true)) {
            ok(!empty($c['sem_resposta']), 'card que espera declara sem_resposta: ' . $c['titulo']);
            ok(isset($g['cards'][$c['sem_resposta']]) && in_array($g['cards'][$c['sem_resposta']]['tipo'], WA_SEM_RESPOSTA_TIPOS, true),
               'sem_resposta leva a texto, marca, humano ou fim: ' . $c['titulo']);
            // O card "respondeu outra coisa" e o fim da linha: sem fallback, a segunda resposta solta cai no socorro (a equipe).
            $e_o_fallback = false;
            foreach ($g['cards'] as $outro) if (($outro['fallback'] ?? null) === $c['id']) $e_o_fallback = true;
            ok(!empty($c['fallback']) || ($e_o_fallback && !empty($g['humano'])), 'card que espera tem fallback (ou e o ultimo degrau, com a equipe como socorro): ' . $c['titulo']);
            ok($c['lembrete_ativo'] === true && trim((string) $c['lembrete_texto']) !== '', 'lembrete ligado e escrito: ' . $c['titulo']);
        }
    }
}

// ------------------------------------------------------------------ Boas-vindas e triagem
ok($bv['porta'] === 'mensagem_nova' && $bv['porta_valor'] === null, 'a triagem e a porta de contato novo');
$tres = 0;
foreach ($bv['cards'] as $cd) if ($cd['tipo'] === 'botoes' && count($cd['opcoes']) === 3) $tres++;
ok($tres >= 1, 'existe um card de tres botoes (o teto da Cloud API)');
ok($bv['cards'][$bv['inicial']]['tipo'] === 'botoes' && count($bv['cards'][$bv['inicial']]['opcoes']) === 3,
   'a boas-vindas tem os tres caminhos: roteiro, programacao e a equipe');

// Os rotulos de botao que a cliente aprovou no rascunho, sem perder nenhum
$rotulos = [];
foreach ($bv['cards'] as $cd) foreach ($cd['opcoes'] as $o) $rotulos[$o['rotulo']] = true;
foreach (['Ver a programação', 'Falar com a Laís', 'Sim, funciona', 'Não nessa data', 'Já viajei',
          'Primeira vez', 'Só esse destino', 'Ver outras datas'] as $r) {
    ok(isset($rotulos[$r]), 'rotulo do rascunho presente: ' . $r);
}

// Caminho principal: roteiro -> escolhe -> PDF -> marca -> pergunta da data
$ctx = ['roteiro_slug' => null, 'tentativas' => 0];
$r = wa_fluxo_proximo($bv, $bv['inicial'], 'b_roteiro', $ctx);
ok($r['motivo'] === 'opcao' && $bv['cards'][$r['card']]['tipo'] === 'lista', 'o primeiro botao leva a lista de roteiros');
ok(($bv['cards'][$r['card']]['config']['fonte'] ?? null) === 'roteiros', 'a lista e a dinamica, do banco');
$lista_roteiro = $r['card'];
$r = wa_fluxo_proximo($bv, $r['card'], 'turquia', $r['ctx']);
ok($r['motivo'] === 'escolheu' && $r['ctx']['roteiro_slug'] === 'turquia', 'a escolha grava o slug');
$tipos = [];
$card = $r['card']; $c = $r['ctx'];
for ($i = 0; $i < 10 && $card !== null; $i++) {
    $tipos[] = $bv['cards'][$card]['tipo'];
    if (in_array($bv['cards'][$card]['tipo'], WA_CARD_ESPERA, true)) break;
    $r = wa_fluxo_proximo($bv, $card, '', $c); $card = $r['card']; $c = $r['ctx'];
}
ok($tipos === ['pdf', 'marca', 'botoes'], 'depois da escolha vem o pdf, a marcacao do interesse e so entao a pergunta: ' . implode(',', $tipos));
ok($c['roteiro_slug'] === 'turquia', 'o roteiro escolhido atravessa a cadeia ate a pergunta');
$marca = null;
foreach ($bv['cards'] as $cd) if ($cd['tipo'] === 'marca' && ($cd['config']['interesses'] ?? null) === '+roteiro') $marca = $cd;
ok($marca !== null, 'existe a marca que acrescenta o roteiro aos interesses');
$pergunta_data = $card;
ok(strpos($bv['cards'][$pergunta_data]['texto'], '{data}') !== false, 'a pergunta da data diz a data da viagem');

// "Sim, funciona" -> pergunta do grupo -> qualquer das duas respostas chega na equipe
$id_sim = null; $id_nao = null;
foreach ($bv['cards'][$pergunta_data]['opcoes'] as $o) {
    if ($o['rotulo'] === 'Sim, funciona') $id_sim = $o['id'];
    if ($o['rotulo'] === 'Não nessa data') $id_nao = $o['id'];
}
ok($id_sim !== null && $id_nao !== null, 'a pergunta da data tem os dois botoes');
$percorre = function ($grafo, $card, $resposta, $ctx) {
    // Segue o roteador, encadeando os cards que nao esperam; devolve [ultimo card, tipos, motivo final]
    $r = wa_fluxo_proximo($grafo, $card, $resposta, $ctx);
    $vistos = [];
    for ($i = 0; $i < 12 && $r['card'] !== null; $i++) {
        $vistos[] = $r['card'];
        $t = $grafo['cards'][$r['card']]['tipo'];
        if (in_array($t, WA_CARD_ESPERA, true) || in_array($t, WA_CARD_TERMINAL, true) || $t === 'ir_para_fluxo') break;
        $r = wa_fluxo_proximo($grafo, $r['card'], '', $r['ctx']);
    }
    return [end($vistos) ?: null, $vistos, $r];
};
[$fim_sim, $v_sim] = $percorre($bv, $pergunta_data, $id_sim, $c);
$grupo = $fim_sim;
ok($bv['cards'][$grupo]['tipo'] === 'botoes' && count($bv['cards'][$grupo]['opcoes']) === 2, 'depois do "sim" vem a pergunta do grupo');
foreach ($bv['cards'][$grupo]['opcoes'] as $o) {
    [$fim] = $percorre($bv, $grupo, $o['id'], $c);
    ok($bv['cards'][$fim]['tipo'] === 'humano', '"' . $o['rotulo'] . '" termina na equipe');
}
// "Nao nessa data" -> a data nao serve -> "Só esse destino" salta para a lista de espera
[$fim_nao] = $percorre($bv, $pergunta_data, $id_nao, $c);
$nao_serve = $fim_nao;
ok($bv['cards'][$nao_serve]['tipo'] === 'botoes', 'depois do "nao" vem a pergunta de saida');
$salto = null; $outras = null;
foreach ($bv['cards'][$nao_serve]['opcoes'] as $o) {
    if ($o['rotulo'] === 'Só esse destino') $salto = $o;
    if ($o['rotulo'] === 'Ver outras datas') $outras = $o;
}
ok($salto !== null && $outras !== null, 'a data nao serve oferece lista de espera e outras datas');
$rs = wa_fluxo_proximo($bv, $nao_serve, $salto['id'], $c);
ok($bv['cards'][$rs['card']]['tipo'] === 'ir_para_fluxo' && $bv['cards'][$rs['card']]['config']['fluxo'] === $esp['id'],
   '"Só esse destino" salta para o fluxo Lista de espera, pelo id fixo');
ok(wa_fluxo_proximo($bv, $rs['card'], '', $c)['motivo'] === 'ir_para_fluxo', 'o salto se anuncia ao executor');
// ... e "Ver outras datas" volta para a programacao, que desemboca de novo no PDF
$rp = wa_fluxo_proximo($bv, $nao_serve, $outras['id'], $c);
ok($bv['cards'][$rp['card']]['tipo'] === 'lista', '"Ver outras datas" abre a programacao');
$re = wa_fluxo_proximo($bv, $rp['card'], 'chile-e-deserto-do-atacama', $rp['ctx']);
ok($bv['cards'][$re['card']]['tipo'] === 'pdf' && $re['ctx']['roteiro_slug'] === 'chile-e-deserto-do-atacama',
   'escolher na programacao leva ao PDF daquele roteiro, sem perguntar o roteiro de novo');

// Ordem: o pdf vem depois do card que escolhe e o marca depois do pdf (por construcao do grafo, nao por sorte do percurso)
$pdf_ids = array_keys(array_filter($bv['cards'], function ($x) { return $x['tipo'] === 'pdf'; }));
ok(count($pdf_ids) === 1, 'um unico card de pdf');
foreach ($bv['cards'] as $cd) {
    if ($cd['tipo'] === 'lista' && ($cd['config']['fonte'] ?? null) === 'roteiros') ok($cd['proximo'] === $pdf_ids[0], 'toda lista de roteiros desemboca direto no pdf');
}
ok($bv['cards'][$pdf_ids[0]]['proximo'] !== null && $bv['cards'][$bv['cards'][$pdf_ids[0]]['proximo']]['tipo'] === 'marca', 'o marca vem logo depois do pdf');
ok($bv['cards'][$bv['cards'][$pdf_ids[0]]['proximo']]['proximo'] === $pergunta_data, 'e a pergunta da data vem depois do marca');

// O funil: nenhum card marca escreve fora da whitelist, e nada liga venda nem campanha
foreach ($bv['cards'] as $cd) {
    if ($cd['tipo'] !== 'marca') continue;
    foreach (array_keys($cd['config']) as $k) ok(in_array($k, WA_FLUXO_CAMPOS_MARCAVEIS, true), 'marca so grava campo da whitelist: ' . $k);
    foreach (['venda', 'venda_at', 'notas', 'cliente', 'revisado'] as $proibido) ok(!array_key_exists($proibido, $cd['config']), "marca nunca grava $proibido");
}
// Data que nao serve carimba perdido + qualif_data=false: e o publico "perdeu por data" da transmissao
$perdeu = false;
foreach ($bv['cards'] as $cd) if ($cd['tipo'] === 'marca' && ($cd['config']['status'] ?? null) === 'perdido' && ($cd['config']['qualif_data'] ?? null) === false) $perdeu = true;
ok($perdeu, 'existe a marca que grava perdido + qualif_data=false para quem a data nao serve');
$caminho_nao = $bv['cards'][$pergunta_data]['opcoes'];
foreach ($caminho_nao as $o) if ($o['id'] === $id_nao) {
    $m1 = $bv['cards'][$o['destino']];
    ok($m1['tipo'] === 'marca' && ($m1['config']['qualif_data'] ?? null) === false, '"Não nessa data" passa pela marca antes de perguntar o que fazer');
}

// Resposta fora do esperado: 1a vez vai para "respondeu outra coisa", 2a vai para a equipe
$r1 = wa_fluxo_proximo($bv, $pergunta_data, 'bom dia, e o cruzeiro?', $c);
ok($r1['motivo'] === 'fallback' && $bv['cards'][$r1['card']]['tipo'] === 'botoes', 'texto solto cai no "respondeu outra coisa"');
$r2 = wa_fluxo_proximo($bv, $r1['card'], 'ainda nao entendi', $r1['ctx']);
ok($r2['motivo'] === 'socorro' && $bv['cards'][$r2['card']]['tipo'] === 'humano', 'a segunda resposta solta chama a equipe');
ok($r2['card'] === $bv['humano'], 'o socorro vai ao card de atendimento humano declarado no retrato');
// ... e as opcoes dele repetem os tres caminhos da boas-vindas
$ini_dest = [];
foreach ($bv['cards'][$bv['inicial']]['opcoes'] as $o) $ini_dest[$o['id']] = $o['destino'];
foreach ($bv['cards'][$r1['card']]['opcoes'] as $o) ok(($ini_dest[$o['id']] ?? null) === $o['destino'], 'o "respondeu outra coisa" repete a opcao da boas-vindas: ' . $o['rotulo']);
ok(count($bv['cards'][$r1['card']]['opcoes']) === 3, 'e repete as tres');
// Quem toca a opcao do fallback volta ao fluxo com o contador zerado
$r3 = wa_fluxo_proximo($bv, $r1['card'], 'b_roteiro', $r1['ctx']);
ok($r3['motivo'] === 'opcao' && $r3['ctx']['tentativas'] === 0, 'responder certo zera as tentativas');

// A despedida de quem cala existe e e dita com palavras da cliente
foreach ($bv['cards'] as $cd) {
    if (!in_array($cd['tipo'], WA_CARD_ESPERA, true)) continue;
    $d = $bv['cards'][$cd['sem_resposta']];
    ok(trim((string) $d['texto']) !== '', 'a despedida de ' . $cd['titulo'] . ' tem texto (senao a conversa encerra calada)');
}

// ------------------------------------------------------------------ Lista de espera
ok($esp['porta'] === 'chamado' && $esp['porta_valor'] === null, 'o segundo fluxo e chamado por outro, nao por contato novo');
$term = 0; $marca_espera = false;
foreach ($esp['cards'] as $cd) {
    if (in_array($cd['tipo'], WA_CARD_TERMINAL, true)) $term++;
    if ($cd['tipo'] === 'marca' && ($cd['config']['espera'] ?? null) === '+destino') $marca_espera = true;
    ok($cd['tipo'] !== 'humano', 'a lista de espera nao entrega a conversa a ninguem: so avisa e termina');
}
ok($term >= 1, 'o fluxo de espera termina');
ok($marca_espera, 'o fluxo de espera grava o destino em espera');
$ctx_e = ['roteiro_slug' => 'turquia', 'tentativas' => 0];
$r = wa_fluxo_proximo($esp, $esp['inicial'], '', $ctx_e);
ok($esp['cards'][$esp['inicial']]['tipo'] === 'marca' && $esp['cards'][$r['card']]['tipo'] === 'fim', 'espera: marca o destino e se despede');
ok(strpos($esp['cards'][$r['card']]['texto'], 'novas datas') !== false || strpos($esp['cards'][$r['card']]['texto'], 'nova data') !== false, 'e promete avisar quando houver data nova');

echo "test-wa-fluxo-semeado: ok\n";
