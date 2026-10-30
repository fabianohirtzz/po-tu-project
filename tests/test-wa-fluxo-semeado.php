<?php
/* A prova de que as pecas do Plano A fecham: os dois fluxos que a migration
   semeia sao lidos como TEXTO (o que o Supabase vai gravar, nao uma copia) e
   passam pelo validador e pelo roteador de verdade. Nenhuma rede. */
require __DIR__ . '/../lib/wa-fluxo.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

/* Le normalizando o fim de linha: com core.autocrlf=true neste repo o mesmo
   arquivo chega CRLF ou LF conforme quem o materializou, e um regex ancorado
   em \n passaria num estado e falharia no outro. */
$le = function ($rel) {
    $t = @file_get_contents(__DIR__ . '/' . $rel);
    return $t === false ? false : str_replace("\r\n", "\n", $t);
};
$sql  = $le('../supabase/migrations/2026-10-27-fluxo-semeado.sql');   // a semente: a versao 1, ja no banco
$sql2 = $le('../supabase/migrations/2026-10-30-fluxo-triagem-v2.sql'); // a versao 2: a que vai ao ar
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

// O insert de po_wa_cards deriva cada coluna do retrato: a lista de colunas e as expressoes do select tem que
// concordar posicao a posicao, senao o rascunho diverge do publicado (fallback x sem_resposta trocados) sem teste vermelho.
// Vale para a semente E para a versao 2: as duas derivam o rascunho do retrato pelo mesmo jsonb_each, e painel
// mostrando desenho diferente do que o motor executa e o pior modo de falha desta tela.
$confere_derivacao = function ($sql, $qual) {
    preg_match('/insert into public\.po_wa_cards\s*\((.*?)\)\s*select(.*?)from public\.po_wa_fluxo_versoes v/s', $sql, $ins);
    ok(count($ins) === 3, "o insert de po_wa_cards existe no formato esperado ($qual)");
    $colunas = array_map('trim', explode(',', $ins[1]));
    // Separa as expressoes do select nas virgulas de nivel zero (as de dentro de coalesce(...) ficam).
    $exprs = [];
    $prof = 0; $atual = '';
    foreach (str_split(trim($ins[2])) as $ch) {
        if ($ch === '(') $prof++;
        if ($ch === ')') $prof--;
        if ($ch === ',' && $prof === 0) { $exprs[] = trim($atual); $atual = ''; continue; }
        $atual .= $ch;
    }
    $exprs[] = trim($atual);
    ok(count($colunas) === count($exprs), "colunas e expressoes do insert de cards tem o mesmo tamanho ($qual): " . count($colunas) . ' x ' . count($exprs));
    foreach ($colunas as $k => $col) {
        if (in_array($col, ['ordem', 'x', 'y'], true)) { ok($exprs[$k] === 'p.' . $col, "coluna $col vem de p.$col ($qual)"); continue; }
        if ($col === 'fluxo_id') { ok($exprs[$k] === 'v.fluxo_id', "fluxo_id vem da versao ($qual)"); continue; }
        ok(strpos($exprs[$k], "card->>'$col'") !== false || strpos($exprs[$k], "card->'$col'") !== false, "a coluna $col le a chave '$col' do retrato, nao outra ($qual): " . $exprs[$k]);
        foreach (['proximo', 'fallback', 'sem_resposta', 'tipo', 'titulo', 'texto', 'opcoes', 'config', 'lembrete_ativo', 'lembrete_texto'] as $outra) {
            if ($outra !== $col && $col !== 'id') ok(strpos($exprs[$k], "'$outra'") === false, "a coluna $col nao le a chave '$outra' ($qual)");
        }
    }
};
$confere_derivacao($sql, 'semente');

// Texto: sem travessao (nem meia-risca), sem emoji, sem telefone, em todo campo que o cliente final ou a cliente leem.
$emoji = '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}]/u';
$confere_copy = function ($g) use ($emoji) {
    $textos = [$g['nome']];
    foreach ($g['cards'] as $c) {
        foreach (['titulo', 'texto', 'lembrete_texto'] as $k) if (isset($c[$k])) $textos[] = (string) $c[$k];
        foreach ($c['opcoes'] as $o) { $textos[] = $o['rotulo']; if (isset($o['descricao'])) $textos[] = $o['descricao']; }
        if (isset($c['config']['botao'])) $textos[] = $c['config']['botao'];
        // A atendente e nomeada onde o cliente espera ouvi-la: no botao e na resposta de quem entrega a conversa a ela.
        // Nos demais corpos o robo fala como a agencia. Nenhum outro nome de pessoa existe no seed.
        if ($c['tipo'] !== 'humano') {
            ok(stripos((string) $c['texto'], 'Laís') === false, 'a atendente so e nomeada nos cards de entrega: ' . $c['titulo']);
        }
    }
    foreach ($textos as $t) {
        // {data} chega como "De 27/10/26 a 12/11/26", "25/11/26 a 02/12/26" ou "na data prevista": preposicao colada antes quebra o portugues.
        ok(!preg_match('/\b(em|de|na|no|a|para)\s+\{data\}/iu', $t), 'nenhuma preposicao colada antes de {data}: ' . $t);
        ok(strpos($t, '—') === false && strpos($t, '–') === false, 'nenhum travessao: ' . $t);
        ok(!preg_match($emoji, $t), 'nenhum emoji: ' . $t);
        ok(!preg_match('/\d{4,}[-\s]?\d{4}/', $t), 'nenhum telefone no texto: ' . $t);
    }
};
foreach ($grafos as $g) $confere_copy($g);

$bv  = $grafos[0];
$esp = $grafos[1];

/* A VERSAO 1 CARREGA `{nome}` PARA SEMPRE, E ISSO NAO E PARA SER CONSERTADO.
   `{nome}` saiu de WA_FLUXO_VARS, entao a regra 14 do validador reclama de
   cada texto da semente que a usa. A semente e retrato IMUTAVEL e ja esta no
   banco: editar a migration de 2026-10-27 seria reescrever um retrato que uma
   conversa em voo pode estar executando, e o proprio arquivo diz que a fonte
   da verdade e o retrato.
   E e inofensivo: wa_fluxo_valida e portao do PUBLICAR (wa_fluxo_publica, em
   lib/wa-fluxo.php:790, e o modo `conferir` de fluxo.php:151), nunca da
   execucao. Conversa presa a versao 1 segue rodando: wa_fluxo_texto apaga a
   chave desconhecida e wa_limpa_pontuacao tira a virgula orfa que sobra.
   O conserto e a VERSAO 2, no fim deste arquivo, que e a que vai ao ar.
   Por isso as assercoes abaixo separam os bloqueios de `{nome}` dos demais, e
   exigem ZERO dos demais. */
$bloq_nome = function ($p) { return $p['regra'] === 14 && strpos($p['texto'], '{nome}') !== false; };
$fora_do_nome = function ($probs) use ($bloq_nome) {
    return array_values(array_filter($probs, function ($p) use ($bloq_nome) { return $p['bloqueia'] && !$bloq_nome($p); }));
};
$so_do_nome = function ($probs) use ($bloq_nome) {
    return array_values(array_filter($probs, function ($p) use ($bloq_nome) { return $p['bloqueia'] && $bloq_nome($p); }));
};

/* Os limites que a Cloud API impoe a cada card, no retrato (o validador conta o pior caso das
   variaveis; aqui o piso). Fechado numa funcao para a versao 2 herdar a MESMA rede: um limite
   conferido so na semente deixaria a versao 2, que e a que vai ao ar, sem rede. */
$confere_cards = function ($g) {
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
};

foreach ($grafos as $i => $g) {
    ok(isset($g['cards'], $g['inicial']) && isset($g['cards'][$g['inicial']]), 'o grafo semeado esta no formato do interpretador');
    ok(($g['cards'][$g['humano']]['tipo'] ?? null) === 'humano' || $g['humano'] === null, 'o card de socorro do retrato e um card humano');

    // 1. valida limpo: zero bloqueios. As regras 8 (beco sem saida) e 11 (salto) merecem nome:
    $fluxo = ['id' => $g['id'], 'nome' => $g['nome'], 'gatilho' => $g['porta'],
              'gatilho_valor' => $g['porta_valor'], 'ativo' => false, 'card_inicial' => $g['inicial']];
    $outro = $i === 0 ? $esp : $bv;
    $outros = [['id' => $outro['id'], 'gatilho' => $outro['porta'], 'gatilho_valor' => $outro['porta_valor'],
                'ativo' => false, 'versao_atual' => 'v1']];   // o estado REAL do banco depois da migration: os dois desligados
    $probs = wa_fluxo_valida($fluxo, array_values($g['cards']), $outros);
    $bloqueios = $fora_do_nome($probs);
    if ($i === 0) {
        // Atrito conhecido e desejado: o salto exige destino ativo, entao a triagem so republica depois de ligar a Lista de espera.
        // Se um SEGUNDO bloqueio aparecer aqui, este teste grita.
        ok(count($bloqueios) === 1 && $bloqueios[0]['regra'] === 11, 'a triagem, com a espera desligada, tem exatamente um bloqueio alem do `{nome}` (regra 11): ' . json_encode($bloqueios, JSON_UNESCAPED_UNICODE));
    } else {
        ok(!$bloqueios, 'a lista de espera nao tem bloqueio alem do `{nome}`: ' . json_encode($bloqueios, JSON_UNESCAPED_UNICODE));
    }
    // A heranca que a versao 2 conserta. Se isto ficar vazio, ou alguem editou a semente (proibido: ela ja rodou)
    // ou `{nome}` voltou para WA_FLUXO_VARS; nos dois casos as assercoes acima deixariam de provar o que dizem.
    ok($so_do_nome($probs), 'a versao 1 de "' . $g['nome'] . '" ainda carrega `{nome}`, que e o que a versao 2 conserta');
    // Com o irmao ligado e publicado (o que a cliente faz antes de ligar a triagem), zero bloqueios alem do `{nome}`.
    $outros[0]['ativo'] = true;
    $restantes = $fora_do_nome(wa_fluxo_valida($fluxo, array_values($g['cards']), $outros));
    ok(!$restantes, 'fluxo "' . $g['nome'] . '" sem bloqueio alem do `{nome}`: ' . json_encode($restantes, JSON_UNESCAPED_UNICODE));
    // O retrato semeado e o que o montador oficial produziria a partir dos mesmos cards.
    $refeito = wa_fluxo_grafo_de($fluxo, array_values($g['cards']));
    ok($refeito['cards'] == $g['cards'], 'o retrato semeado e o mesmo que wa_fluxo_grafo_de produz');
    ok($refeito['porta'] === $g['porta'] && $refeito['porta_valor'] === $g['porta_valor'], 'porta do retrato');

    $confere_cards($g);
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

// Resposta fora do esperado, em cada pergunta: quem DIGITA em vez de tocar volta a ouvir a PROPRIA pergunta,
// com os botoes dela, e nao um menu que ja nao responde nada. A segunda resposta solta chama a Laís.
$perguntas = [$pergunta_data => 'Data da viagem', $grupo => 'viajou em grupo', $nao_serve => 'só por este destino'];
foreach ($perguntas as $pid => $trecho) {
    $orig = $bv['cards'][$pid];
    $r1 = wa_fluxo_proximo($bv, $pid, 'acho que sim', $c);
    ok($r1['motivo'] === 'fallback' && $bv['cards'][$r1['card']]['tipo'] === 'botoes', 'texto solto em "' . $orig['titulo'] . '" cai num fallback de botoes');
    ok($r1['card'] !== $bv['cards'][$bv['inicial']]['fallback'], 'o fallback de "' . $orig['titulo'] . '" NAO e o menu geral');
    ok(stripos($bv['cards'][$r1['card']]['texto'], $trecho) !== false, 'o fallback de "' . $orig['titulo'] . '" repete a propria pergunta');
    ok(count($bv['cards'][$r1['card']]['opcoes']) === count($orig['opcoes']), 'mesmo numero de botoes');
    foreach ($orig['opcoes'] as $k => $o) {
        $f = $bv['cards'][$r1['card']]['opcoes'][$k];
        ok($f['id'] === $o['id'] && $f['rotulo'] === $o['rotulo'] && $f['destino'] === $o['destino'], 'o fallback repete o botao "' . $o['rotulo'] . '" com o mesmo destino');
    }
    $r2 = wa_fluxo_proximo($bv, $r1['card'], 'ainda nao entendi', $r1['ctx']);
    ok($r2['motivo'] === 'socorro' && $r2['card'] === $bv['humano'] && $bv['cards'][$r2['card']]['tipo'] === 'humano', 'a segunda resposta solta em "' . $orig['titulo'] . '" chama a Laís');
    $r3 = wa_fluxo_proximo($bv, $r1['card'], $orig['opcoes'][0]['id'], $r1['ctx']);
    ok($r3['motivo'] === 'opcao' && $r3['card'] === $orig['opcoes'][0]['destino'] && $r3['ctx']['tentativas'] === 0, 'tocar o botao no fallback segue o mesmo caminho e zera as tentativas');
}
// Boas-vindas e listas: o fallback e o menu geral, que repete as tres opcoes da boas-vindas
$ini_dest = [];
foreach ($bv['cards'][$bv['inicial']]['opcoes'] as $o) $ini_dest[$o['id']] = $o['destino'];
$rm = wa_fluxo_proximo($bv, $bv['inicial'], 'bom dia, e o cruzeiro?', $c);
ok($rm['motivo'] === 'fallback', 'texto solto na boas-vindas cai no "respondeu outra coisa"');
foreach ($bv['cards'][$rm['card']]['opcoes'] as $o) ok(($ini_dest[$o['id']] ?? null) === $o['destino'], 'o "respondeu outra coisa" repete a opcao da boas-vindas: ' . $o['rotulo']);
ok(count($bv['cards'][$rm['card']]['opcoes']) === 3, 'e repete as tres');
$rm2 = wa_fluxo_proximo($bv, $rm['card'], 'nao entendi', $rm['ctx']);
ok($rm2['motivo'] === 'socorro' && $rm2['card'] === $bv['humano'], 'e a segunda resposta solta chama a Laís');

/* O corpo dos cards de lista tem que ler certo NOS DOIS CAMINHOS. Quando o
   catalogo volta vazio (Supabase fora, ou nenhum roteiro ativo), a Graph recusa
   lista de zero linhas e wa_fluxo_executa manda esse MESMO corpo como texto
   puro. Um texto que manda tocar num botao que nao esta ali, ou que afirma uma
   lista que nao foi enviada, deixa o cliente de 60+ procurando o que nao
   existe. Quem diz o que fazer no caminho normal e o config.botao. */
$listas = 0;
foreach ($bv['cards'] as $cd) {
    if ($cd['tipo'] !== 'lista') continue;
    $listas++;
    /* Vale para o corpo E para o lembrete: o lembrete sai 20h depois do card que
       o cliente nao respondeu, e se aquele card saiu como texto puro, dizer
       "enviamos a lista" mente do mesmo jeito. */
    foreach (['texto' => 'o corpo', 'lembrete_texto' => 'o lembrete'] as $campo => $onde) {
        $t = (string) $cd[$campo];
        if (trim($t) === '') continue;
        ok(!preg_match('/\bbot(ão|ao|ões|oes)\b/iu', $t), $onde . ' da lista "' . $cd['titulo'] . '" nao manda tocar num botao (que o caminho degradado nao envia): ' . $t);
        ok(!preg_match('/\b(abaixo|a seguir|aqui embaixo|logo abaixo)\b/iu', $t), 'nem aponta para algo que pode nao estar ali: ' . $t);
        ok(!preg_match('/\b(estas são as|esta é a|enviamos a lista|segue a lista|veja a lista|abaixo a lista)\b/iu', $t), 'nem AFIRMA uma lista que pode nao ter sido enviada: ' . $t);
        // E o convite a escolher continua de pe nos dois caminhos.
        ok(preg_match('/\bescolh|\bqual\b|\bquer\b/iu', $t), 'mas segue convidando o cliente a escolher: ' . $t);
        ok(mb_strlen($t, 'UTF-8') <= 1024, 'e cabe no corpo da lista e no texto puro: ' . $cd['titulo']);
    }
}
ok($listas === 2, 'os dois cards de lista do seed foram conferidos, nao zero');

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

/* ==================================================================
   A VERSAO 2 (2026-10-30-fluxo-triagem-v2.sql): a que vai ao ar.
   A reuniao de 29/10 encurtou a conversa: o menu perde uma porta, a escolha da
   viagem vira o card `roteiros` (texto numerado montado do banco, que aceita
   resposta digitada), a pergunta "ja viajou em grupo" sai inteira, e quem diz
   que a data nao serve vai para a lista de espera ou para a despedida.
   A semente NAO e editada: ela ja rodou no banco e o retrato e imutavel, entao
   isto e uma migration nova, que publica a versao 2 e redesenha o rascunho.
   ================================================================== */
ok($sql2 !== false, 'a migration da versao 2 existe');
// Os comandos, sem os comentarios: um `delete` citado no cabecalho nao pode passar pelas conferencias abaixo.
$cmds2 = preg_replace('/^\s*--.*$/m', '', $sql2);

$cid = function ($nn) { return 'c1000000-0000-4000-8000-0000000000' . $nn; };
$V2  = 'b1000000-0000-4000-8000-000000000003';   // versao 2 de "Boas-vindas e triagem"
$V2E = 'b1000000-0000-4000-8000-000000000004';   // versao 2 de "Lista de espera"
$RE_UUID = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/';

// Os dois retratos, na ordem em que a migration os publica.
preg_match_all('/\$grafo\$(.*?)\$grafo\$/s', $sql2, $m2);
ok(count($m2[1]) === 2, 'a versao 2 publica os DOIS fluxos: `{nome}` tornou os dois impublicaveis, e a regra 11 exige a espera publicada para a triagem poder ser ligada');
$g2 = json_decode($m2[1][0], true);
ok(json_last_error() === JSON_ERROR_NONE, 'o retrato da triagem v2 e JSON valido: ' . json_last_error_msg());
$e2 = json_decode($m2[1][1], true);
ok(json_last_error() === JSON_ERROR_NONE, 'o retrato da lista de espera v2 e JSON valido: ' . json_last_error_msg());
ok($g2['id'] === $bv['id'] && $e2['id'] === $esp['id'], 'a versao 2 e dos MESMOS dois fluxos, nao de fluxo novo');
ok($g2['inicial'] === $bv['inicial'] && $g2['humano'] === $bv['humano'], 'mesmo card inicial e mesmo card de socorro');
ok($g2['porta'] === 'mensagem_nova' && $e2['porta'] === 'chamado', 'e as mesmas portas de entrada');

// O que a migration grava: a versao 2 de cada fluxo, e o versao_atual apontando para ela.
foreach ([[$V2, $bv['id'], 'triagem'], [$V2E, $esp['id'], 'lista de espera']] as [$vid, $fid, $qual]) {
    ok(preg_match_all("/po_wa_fluxo_versoes \(id, fluxo_id, versao, grafo\) values \('$vid', '$fid', 2, \\\$grafo\\\$/", $cmds2) === 1,
       "a $qual entra como versao 2, com o id $vid");
    ok(preg_match_all("/set versao_atual = '$vid'[^;]*\bid = '$fid'/s", $cmds2) === 1,
       "versao_atual da $qual passa a apontar para a versao 2");
}
ok(preg_match_all('/on conflict do nothing/i', $cmds2) === 2, 'os dois inserts de versao sao idempotentes (on conflict do nothing)');
ok(preg_match_all('/on conflict \(id\) do update/i', $cmds2) === 1,
   'e o rascunho e redesenhado com on conflict (id) do update, para retrato e rascunho nunca divergirem');
ok(preg_match('/ativo\s*=\s*true/i', $cmds2) === 0, 'a migration da versao 2 nao liga fluxo nenhum (publicar e ligar sao atos separados)');
ok(preg_match('/\+55(?!48900000)\d{10,11}/', $cmds2) === 0, 'nenhum telefone real na migration (repositorio publico)');

/* CADA FLUXO DEPENDE SO DE SI, e isto e decisao, nao descuido. A migration e tudo ou
   nada POR FLUXO: o comando que move o ponteiro de um fluxo e guardado pela versao DELE,
   nunca pela do outro. Acoplar os dois seria pior, nao melhor: se uma colisao na triagem
   bloqueasse a espera, a espera ficaria sem a versao 2, seguiria com o `{nome}` que a
   regra 14 recusa, e a regra 11 exige a espera publicada e ligada ANTES de a triagem
   poder ser ligada. Ou seja, uma colisao de um lado deixaria a cliente sem conseguir
   ligar o robo. No caso em que so um lado aplica, o rascunho de cada fluxo segue casado
   com a versao publicada dele, entao nada diverge. */
preg_match_all('/\bupdate\s+public\.po_wa_fluxos([^;]*);/is', $cmds2, $ups, PREG_SET_ORDER);
ok(count($ups) === 2, 'dois comandos movem ponteiro, um por fluxo: ' . count($ups));
foreach ([[$V2, $bv['id'], $V2E, 'triagem'], [$V2E, $esp['id'], $V2, 'lista de espera']] as [$vid, $fid, $alheio, $qual]) {
    // Acha o comando pelo id do FLUXO, nao pelo da versao: se alguem acoplar os dois, o
    // uuid de versao aparece nos dois comandos e a busca por ele apontaria para o errado,
    // fazendo a assercao acusar com o nome do fluxo trocado.
    $meu = null;
    foreach ($ups as $u) if (strpos($u[1], $fid) !== false) $meu = $u[1];
    ok($meu !== null, 'existe o comando que aponta a ' . $qual . ' para a versao 2 dela');
    preg_match_all($RE_UUID, $meu, $us);
    ok(array_values(array_unique($us[0])) === [$vid, $fid],
       'e ele cita so a versao e o fluxo da ' . $qual . ': ' . implode(', ', array_unique($us[0])));
    ok(strpos($meu, $alheio) === false,
       'nunca a versao do outro fluxo: acoplar os dois faria uma colisao num impedir o outro de publicar (' . $qual . ')');
}
// E o redesenho do rascunho le as DUAS versoes 2, para o fluxo que gravou ter o rascunho
// derivado mesmo quando o outro nao gravou.
ok(preg_match("/where v\.id in \(\s*'$V2',\s*'$V2E'\s*\)/", $cmds2) === 1,
   'o redesenho do rascunho le as duas versoes 2, nao so a da triagem');

/* O comando de exclusao e o unico desta migration que nao se desfaz: um uuid errado
   apagaria um card que a cliente pode ter editado a mao. Ele tem de alcancar EXATAMENTE
   os 5 cards que saem, nomeados um a um, e nada mais.
   E ele e tambem o unico que nao pode rodar SOZINHO. Se o insert da versao 2 nao gravar
   (a cliente publicou uma versao 2 pelo painel e o `unique (fluxo_id, versao)` recusou a
   nossa), o redesenho do rascunho nao casa nada e os `exists` barram os dois ponteiros,
   mas sem guarda aqui os 5 cards JA FORAM: sobra um rascunho sem cards que o grafo
   publicado ainda aponta, que e exatamente o "painel desenha uma conversa e o motor
   executa outra" que o cabecalho da migration promete evitar. Por isso ele carrega o
   mesmo `exists` dos updates, e a migration passa a ser tudo ou nada. */
$mortos = array_map($cid, ['03', '09', '10', '11', '19']);
preg_match_all('/\bdelete\s+from\s+public\.(\w+)([^;]*);/is', $cmds2, $dels, PREG_SET_ORDER);
ok(count($dels) === 1, 'ha um unico comando de exclusao na migration: ' . count($dels));
ok($dels[0][1] === 'po_wa_cards', 'e ele exclui card do rascunho, nunca fluxo nem versao publicada: ' . $dels[0][1]);
ok(preg_match('/\bwhere\b/i', $dels[0][2]) === 1, 'com where (sem ele, iria o fluxo inteiro)');
// A guarda e o resto do comando sao conferidos separados, senao o uuid da versao que
// entra no `exists` contaria como um sexto id apagado.
$pedacos = preg_split('/\band\s+exists\b/i', $dels[0][2], 2);
ok(count($pedacos) === 2, 'e com a guarda `and exists`: sem ela a exclusao roda mesmo quando a versao 2 nao foi gravada');
preg_match_all($RE_UUID, $pedacos[0], $apagados);
ok($apagados[0] === $mortos, 'nomeia os 5 ids que saem, um a um, e so eles: ' . implode(', ', $apagados[0]));
preg_match_all($RE_UUID, $pedacos[1], $guarda);
ok($guarda[0] === [$V2], 'e a guarda olha a versao 2 da triagem, so ela: ' . implode(', ', $guarda[0]));
foreach ($mortos as $morto) {
    ok(isset($bv['cards'][$morto]), 'o card ' . substr($morto, -2) . ' existe na versao 1 (senao o delete apagaria outra coisa)');
    ok(!isset($g2['cards'][$morto]), 'e saiu da versao 2');
}
ok(count($g2['cards']) === 15 && count($bv['cards']) === 20, 'a versao 2 tem 15 cards, a versao 1 tinha 20: ' . count($g2['cards']));

// Todo card do retrato tem lugar no desenho: sem (ordem, x, y) a tela os empilha em 0,0.
preg_match_all("/\('([0-9a-f-]{36})',\s*(\d+),\s*(-?\d+),\s*(-?\d+)\)/", $cmds2, $pos2, PREG_SET_ORDER);
$lugar = [];
foreach ($pos2 as $p) $lugar[$p[1]] = $p[3] . ',' . $p[4];
ok(count($pos2) === count($g2['cards']) + count($e2['cards']),
   'a lista de posicoes tem uma linha por card da versao 2, nem mais nem menos: ' . count($pos2));
foreach ([$g2, $e2] as $g) {
    foreach ($g['cards'] as $kid => $c) {
        ok(isset($lugar[$kid]), 'card "' . $c['titulo'] . '" tem posicao no desenho da versao 2');
        ok($c['id'] === $kid, 'a chave do card e o id dele (versao 2)');
    }
    $usados = array_intersect_key($lugar, $g['cards']);
    ok(count(array_unique($usados)) === count($usados), 'nenhum card do fluxo "' . $g['nome'] . '" ocupa a posicao de outro');
}
// Sobre os COMANDOS, nao sobre o arquivo: o cabecalho da versao 2 leva uma consulta de
// conferencia comentada que tambem fala de po_wa_cards, e ela nao pode entrar nesta regex.
$confere_derivacao($cmds2, 'versao 2');

/* Quantos comandos o arquivo roda. Todas as travas acima olham UM comando por vez e
   nenhuma olha o total: um setimo comando nao previsto (um `update public.po_wa_cards
   set texto = ...`, digamos) passaria por todas elas. Os retratos saem da conta antes do
   corte porque um `;` no texto de um card mudaria a contagem sem mudar comando nenhum. */
$sem_retrato = preg_replace('/\$grafo\$.*?\$grafo\$/s', "'<retrato>'", $cmds2);
$comandos = array_values(array_filter(array_map('trim', explode(';', $sem_retrato)), 'strlen'));
$verbos = array_map(function ($s) { return strtolower(preg_split('/\s+/', $s)[0]); }, $comandos);
ok($verbos === ['insert', 'insert', 'delete', 'insert', 'update', 'update'],
   'a migration roda 6 comandos, nesta ordem (as duas versoes, a exclusao, o redesenho do rascunho e os dois ponteiros): ' . implode(',', $verbos));

/* O validador, no estado em que a cliente publica: o irmao ja publicado e ligado (a ordem
   que o painel manda seguir). Zero bloqueio, porque e esta versao que vai ao ar. */
foreach ([[$g2, $e2, 'triagem'], [$e2, $g2, 'lista de espera']] as [$g, $outro, $qual]) {
    $fluxo = ['id' => $g['id'], 'nome' => $g['nome'], 'gatilho' => $g['porta'],
              'gatilho_valor' => $g['porta_valor'], 'ativo' => false, 'card_inicial' => $g['inicial']];
    $outros = [['id' => $outro['id'], 'gatilho' => $outro['porta'], 'gatilho_valor' => $outro['porta_valor'],
                'ativo' => true, 'versao_atual' => 'v2']];
    $probs = wa_fluxo_valida($fluxo, array_values($g['cards']), $outros);
    $bl = array_values(array_filter($probs, function ($p) { return $p['bloqueia']; }));
    ok(!$bl, 'a versao 2 da ' . $qual . ' nao tem bloqueio nenhum: ' . json_encode($bl, JSON_UNESCAPED_UNICODE));
    /* A regra 14 e a que o `{nome}` acendia na versao 1. Filtrada pela REGRA, nao contada no
       total: mais de uma regra pode acender no mesmo card, e um total "esperado" esconderia a
       troca de um problema por outro. */
    $r14 = array_values(array_filter($probs, function ($p) { return $p['regra'] === 14; }));
    ok(!$r14, 'e nenhum problema de variavel na ' . $qual . ': ' . json_encode($r14, JSON_UNESCAPED_UNICODE));
    $refeito = wa_fluxo_grafo_de($fluxo, array_values($g['cards']));
    ok($refeito['cards'] == $g['cards'], 'o retrato da versao 2 da ' . $qual . ' e o mesmo que wa_fluxo_grafo_de produz');
    ok($refeito['porta'] === $g['porta'] && $refeito['porta_valor'] === $g['porta_valor'], 'porta do retrato da ' . $qual);
    $confere_copy($g);
    $confere_cards($g);
}
// Com a espera DESLIGADA, o atrito de sempre (a regra 11 obriga a ligar a espera primeiro) e nada mais.
$fx2 = ['id' => $g2['id'], 'nome' => $g2['nome'], 'gatilho' => $g2['porta'],
        'gatilho_valor' => $g2['porta_valor'], 'ativo' => false, 'card_inicial' => $g2['inicial']];
$desligado = [['id' => $e2['id'], 'gatilho' => $e2['porta'], 'gatilho_valor' => $e2['porta_valor'],
               'ativo' => false, 'versao_atual' => 'v2']];
$bl11 = array_values(array_filter(wa_fluxo_valida($fx2, array_values($g2['cards']), $desligado), function ($p) { return $p['bloqueia']; }));
ok(count($bl11) === 1 && $bl11[0]['regra'] === 11 && $bl11[0]['card'] === $cid('15'),
   'com a espera desligada, a triagem v2 bloqueia so no salto (regra 11, card 15): ' . json_encode($bl11, JSON_UNESCAPED_UNICODE));

/* `{nome}` nao existe em NENHUM texto dos dois retratos, lembrete incluido: ela saiu do
   catalogo de variaveis e a regra 14 bloquearia o Publicar. Vale para o JSON inteiro. */
$j2  = json_encode($g2, JSON_UNESCAPED_UNICODE);
$j2e = json_encode($e2, JSON_UNESCAPED_UNICODE);
$j1  = json_encode($bv, JSON_UNESCAPED_UNICODE);
ok(strpos($j2, '{nome}') === false, 'nenhum texto da triagem v2 usa {nome}');
ok(strpos($j2e, '{nome}') === false, 'nenhum texto da lista de espera v2 usa {nome}');
ok(strpos($j1, '{nome}') !== false, 'e a versao 1 usava (senao as duas assercoes acima nao provam nada)');
/* A pergunta do grupo saiu inteira. Nao basta os cards: o campo que ela existia para
   preencher nao pode ser gravado por marca nenhuma, e a pergunta nao pode sobrar em texto.
   Nao se confere a ausencia de "em grupo": "viagens em grupo desde 1967" e o posicionamento
   da casa e e justamente o que a boas-vindas diz. */
// Cada assercao de ausencia com a sua guarda na linha de baixo: ausencia que nunca
// esteve presente nao prova nada, e e o jeito que este plano mais pagou para aprender.
ok(strpos($j2, 'qualif_grupo') === false, 'nenhuma marca da versao 2 grava qualif_grupo');
ok(strpos($j1, 'qualif_grupo') !== false, 'e a versao 1 gravava (senao a assercao acima nao prova nada)');
ok(!preg_match('/j[aá]\s+viaj/iu', $j2), 'nenhum texto da versao 2 pergunta se a pessoa ja viajou');
ok(preg_match('/j[aá]\s+viaj/iu', $j1), 'e a versao 1 perguntava (senao a assercao acima nao prova nada)');

// ---- 1. o menu perde a porta "Ver a programacao"
$menu2 = $g2['cards'][$cid('01')];
ok($menu2['tipo'] === 'botoes' && count($menu2['opcoes']) === 2, 'o menu da versao 2 tem 2 opcoes, nao 3: ' . count($menu2['opcoes']));
ok(array_column($menu2['opcoes'], 'destino') === [$cid('02'), $cid('04')], 'as duas portas: os roteiros e a Lais');
ok(count($bv['cards'][$cid('01')]['opcoes']) === 3, 'a versao 1 tinha 3 (senao a assercao acima nao prova nada)');

// ---- 2. a escolha da viagem vira o card `roteiros`
$esc2 = $g2['cards'][$cid('02')];
ok($esc2['tipo'] === 'roteiros', 'a escolha da viagem virou o card de texto numerado: ' . $esc2['tipo']);
ok($esc2['opcoes'] === [], 'sem opcao escrita a mao (a lista vem do banco, e a regra 7 avisa quem as escrever)');
ok($esc2['proximo'] === $cid('05'), 'escolher leva direto ao pdf');
ok($esc2['fallback'] === $cid('16') && $esc2['sem_resposta'] === $cid('17'), 'com o fallback do menu e a despedida de quem cala');
/* O motor casa numero, nome inteiro e parte do nome. O card tem de DIZER isso: uma lista
   numerada sem instrucao deixa o publico de 60+ esperando um botao que este card nao envia. */
ok(preg_match('/n[uú]mero/iu', $esc2['texto']) && preg_match('/nome/iu', $esc2['texto']),
   'e o texto diz que vale responder com o numero ou com o nome: ' . $esc2['texto']);
/* Vale para o corpo E para o lembrete. O card `roteiros` sai SEMPRE como texto puro, com a
   lista numerada colada embaixo, e nunca com botao: mandar tocar num botao deixaria o
   publico de 60+ procurando o que nao esta ali, nos dois momentos. */
foreach (['texto' => 'o corpo', 'lembrete_texto' => 'o lembrete'] as $campo => $onde) {
    ok(!preg_match('/\bbot(ão|ao|ões|oes)\b/iu', (string) $esc2[$campo]),
       $onde . ' do card de roteiros nao manda tocar num botao, que ele nunca envia: ' . $esc2[$campo]);
}

// ---- 3. depois do pdf fica so a pergunta da data, e o "sim" vai direto para a Lais
ok($g2['cards'][$cid('05')]['proximo'] === $cid('06') && $g2['cards'][$cid('06')]['proximo'] === $cid('07'),
   'pdf, marca do interesse e a pergunta da data, nesta ordem');
$m8 = $g2['cards'][$cid('08')];
ok($m8['tipo'] === 'marca' && $m8['config']['status'] === 'atendimento' && $m8['config']['qualif_data'] === true,
   'o "sim" da data carimba atendimento e qualif_data');
ok($m8['proximo'] === $cid('12') && $g2['cards'][$cid('12')]['tipo'] === 'humano',
   'e entrega a conversa, sem a pergunta do grupo no meio');
ok($bv['cards'][$cid('08')]['proximo'] === $cid('09'), 'na versao 1 ele ia para a pergunta do grupo (senao a assercao acima nao prova nada)');

// ---- 4. o "nao" da data: marca perdido e oferece a lista de espera
$m13 = $g2['cards'][$cid('13')];
ok($m13['tipo'] === 'marca' && $m13['config']['status'] === 'perdido' && $m13['config']['qualif_data'] === false,
   'o "nao" da data carimba perdido + qualif_data=false, que e o publico "perdeu por data" da transmissao');
ok($m13['proximo'] === $cid('14'), 'e leva a pergunta da lista de espera');
$pe2 = $g2['cards'][$cid('14')];
ok($pe2['tipo'] === 'botoes' && count($pe2['opcoes']) === 2, 'a lista de espera pergunta Sim/Nao');
ok(array_column($pe2['opcoes'], 'destino') === [$cid('15'), $cid('17')], 'Sim entra na lista de espera, Nao se despede');
ok($g2['cards'][$cid('15')]['tipo'] === 'ir_para_fluxo' && $g2['cards'][$cid('15')]['config']['fluxo'] === $esp['id'],
   'e o salto aponta para o fluxo Lista de espera, pelo id fixo');
ok(stripos($pe2['texto'], 'lista de espera') !== false, 'e o texto diz o que vai acontecer: ' . $pe2['texto']);

/* ---- 5. os tres textos em que o robo para de falar.
   Sao as tres saidas da conversa: a despedida (17, de quem calou e de quem recusou a
   lista de espera) e as duas entregas a Lais (04, pelo menu; 12, de quem qualificou).
   Ninguem responde a estes tres, e por isso sao os unicos em que uma promessa fica sem
   dono: o robo NAO sabe quando a Lais vai estar livre, entao ele nao promete prazo. Diz
   o que ja foi feito e o que acontece em seguida, que e o que sustenta a espera sem
   inventar hora. `{nome}` tambem sai, nos tres: ela nao e mais variavel valida (regra 14)
   e tratar alguem pelo nome do perfil do WhatsApp produzia tratamento errado. */
$PRAZO     = '/\b(volto|retorno|em breve|logo mais|assim que poss[ií]vel)\b/iu';
$TRAVESSAO = '/[\x{2014}\x{2013}]/u';
/* A GUARDA DOS REGEX, antes de usa-los para provar ausencia: regex que nao casa nada
   aprova qualquer texto, e assercao de ausencia sem guarda e o defeito que este plano
   mais pagou para aprender. */
ok(preg_match($PRAZO, 'ela fala com você em breve') === 1
   && preg_match($PRAZO, 'retorno assim que possível') === 1
   && preg_match($PRAZO, 'eu volto logo mais') === 1,
   'o regex de prazo casa as promessas que ele existe para proibir');
ok(preg_match($PRAZO, 'Ela continua o atendimento por aqui mesmo') === 0,
   'e nao casa uma continuidade sem hora marcada, que e justamente o que se quer escrever');
ok(preg_match($TRAVESSAO, 'a Laís assume o atendimento — por aqui') === 1, 'o regex de travessao casa um travessao');
ok(preg_match($emoji, 'tudo certo 🙂') === 1, 'e o regex de emoji casa um emoji');

foreach ([['17', 'a despedida'], ['04', 'a entrega pelo menu'], ['12', 'a entrega de quem qualificou']] as [$nn, $qual]) {
    $t  = (string) $g2['cards'][$cid($nn)]['texto'];
    $t1 = (string) $bv['cards'][$cid($nn)]['texto'];
    /* Vazio nao bloqueia o validador em `fim` nem em `humano` (regra 6 so exige corpo
       onde a Cloud API exige), entao e aqui que se trava: calar nestes tres encerra a
       conversa sem uma palavra. */
    ok(trim($t) !== '', $qual . ' (card ' . $nn . ') tem texto escrito');
    ok(strpos($t, '{nome}') === false, $qual . ' nao chama a pessoa pelo nome do perfil');
    ok(strpos($t1, '{nome}') !== false, 'e a versao 1 do card ' . $nn . ' chamava (senao a assercao acima nao prova nada)');
    ok(!preg_match($PRAZO, $t), $qual . ' nao promete prazo que o atendimento nao controla: ' . $t);
    // Quem fala e a agencia, na primeira pessoa do plural, nunca um "eu" sem dono.
    ok(preg_match('/\b(avisamos|passamos|organizamos|ficamos|estamos|vamos|podemos|temos|deixamos)\b/iu', $t),
       $qual . ' fala em nome da agencia, na primeira pessoa do plural: ' . $t);
}
// As duas entregas da versao 1 terminavam em "em breve": sao elas que dao a guarda da
// assercao de ausencia acima. A despedida (17) nunca prometeu prazo, e nao e dela que
// se prova que o regex acende.
foreach (['04', '12'] as $nn) {
    ok(preg_match($PRAZO, (string) $bv['cards'][$cid($nn)]['texto']) === 1,
       'a versao 1 do card ' . $nn . ' prometia prazo (senao a assercao de ausencia acima nao prova nada)');
}
/* Travessao e emoji no JSON INTEIRO dos dois retratos, config incluido: o confere_copy
   olha os campos que ele enumera, e este pega qualquer canto que ele nao enumere.
   Sobre o JSON com JSON_UNESCAPED_UNICODE, nunca sobre json_encode cru: cru, um
   travessao vira uma sequencia de escape em ASCII e o regex jamais casaria, aprovando tudo. */
foreach (['triagem' => $j2, 'lista de espera' => $j2e] as $qual => $j) {
    ok(!preg_match($TRAVESSAO, $j), 'nenhum travessao em canto nenhum da ' . $qual);
    ok(!preg_match($emoji, $j), 'nenhum emoji em canto nenhum da ' . $qual);
}
/* E a prova de que o PAR (regex + JSON_UNESCAPED_UNICODE) acha o caractere DENTRO do
   retrato codificado: um retrato de mentira, com um travessao e um emoji no texto de dois
   cards. A segunda assercao de cada par e a que trava o motivo da flag: sem ela o mesmo
   caractere vira uma sequencia de escape em ASCII e o regex aprova tudo, calado. Colar o
   caractere DEPOIS do json_encode nao serve de guarda: casaria com flag ou sem ela. */
$falso = $g2;
$falso['cards'][$cid('17')]['texto'] = 'Tudo bem — ficamos por aqui.';
$falso['cards'][$cid('04')]['texto'] = 'Combinado 🙂';
ok(preg_match($TRAVESSAO, json_encode($falso, JSON_UNESCAPED_UNICODE)) === 1,
   'o regex acha um travessao dentro de um retrato codificado com JSON_UNESCAPED_UNICODE');
ok(preg_match($TRAVESSAO, json_encode($falso)) === 0,
   'e NAO o acha sem a flag: por isso as assercoes acima leem $j2, e nunca json_encode cru');
ok(preg_match($emoji, json_encode($falso, JSON_UNESCAPED_UNICODE)) === 1,
   'o mesmo para o emoji, com a flag');
ok(preg_match($emoji, json_encode($falso)) === 0,
   'e o mesmo silencio sem ela');

/* ---- o caminho inteiro, pelo roteador de verdade.
   O card `roteiros` e o UNICO ramo de wa_fluxo_proximo que le o catalogo (casar por nome
   precisa dos titulos, e o contexto so guarda slugs). Sem o duble abaixo, converter o
   card `...002` para `roteiros` faria ESTE arquivo chamar o Supabase de verdade. */
$CAT_LIDO = 0;
$CAT2 = [
  ['slug' => 'turquia', 'titulo' => 'Turquia com Antália', 'periodo' => 'De 27/10/26 a 12/11/26', 'ativo' => true, 'status_viagem' => null],
  ['slug' => 'chile-e-deserto-do-atacama', 'titulo' => 'Chile e Deserto do Atacama', 'periodo' => '25/11/26 a 02/12/26', 'ativo' => true, 'status_viagem' => 'ultimas_vagas'],
];
wa_fluxo_set_deps(['roteiros' => function () use (&$CAT_LIDO, $CAT2) { $CAT_LIDO++; return $CAT2; }]);
ok(wa_fl_dep('roteiros') !== 'po_fetch_roteiros', 'o catalogo esta injetado: o roteamento do card `roteiros` nao vai ao banco');

$ctx2 = ['roteiro_slug' => null, 'tentativas' => 0, 'roteiros_enviados' => array_column($CAT2, 'slug')];
$r = wa_fluxo_proximo($g2, $g2['inicial'], $menu2['opcoes'][0]['id'], $ctx2);
ok($r['motivo'] === 'opcao' && $r['card'] === $cid('02'), 'a porta "Roteiros" leva a escolha da viagem');
$r = wa_fluxo_proximo($g2, $cid('02'), '2', $ctx2);
ok($r['motivo'] === 'escolheu' && $r['ctx']['roteiro_slug'] === 'chile-e-deserto-do-atacama',
   'responder "2" escolhe a segunda viagem da lista ENVIADA');
ok($CAT_LIDO > 0, 'e o casamento passou pelo catalogo injetado (senao a assercao acima nao prova nada)');
$r = wa_fluxo_proximo($g2, $cid('02'), 'atacama', $ctx2);
ok($r['motivo'] === 'escolheu' && $r['ctx']['roteiro_slug'] === 'chile-e-deserto-do-atacama', 'e parte do nome escolhe a mesma');
$r = wa_fluxo_proximo($g2, $cid('02'), 'bom dia', $ctx2);
ok($r['motivo'] === 'fallback' && $r['card'] === $cid('16'),
   'frase que nao casa viagem nenhuma cai no "respondeu outra coisa", sem escolher roteiro no lugar da pessoa');
ok(($r['ctx']['roteiro_slug'] ?? null) === null, 'e sem roteiro no contexto');

$r = wa_fluxo_proximo($g2, $cid('02'), '1', $ctx2);
$tipos2 = [];
$card2 = $r['card']; $cc2 = $r['ctx'];
for ($k = 0; $k < 10 && $card2 !== null; $k++) {
    $tipos2[] = $g2['cards'][$card2]['tipo'];
    if (in_array($g2['cards'][$card2]['tipo'], WA_CARD_ESPERA, true)) break;
    $rr = wa_fluxo_proximo($g2, $card2, '', $cc2); $card2 = $rr['card']; $cc2 = $rr['ctx'];
}
ok($tipos2 === ['pdf', 'marca', 'botoes'], 'depois da escolha vem o pdf, a marcacao do interesse e so entao a pergunta: ' . implode(',', $tipos2));
ok($card2 === $cid('07') && $cc2['roteiro_slug'] === 'turquia', 'a pergunta e a da data, com o roteiro escolhido no contexto');
ok(strpos($g2['cards'][$cid('07')]['texto'], '{data}') !== false, 'que diz a data da viagem');

[, $v_sim2] = $percorre($g2, $cid('07'), $g2['cards'][$cid('07')]['opcoes'][0]['id'], $cc2);
ok($v_sim2 === [$cid('08'), $cid('12')], 'o "sim" da data passa pela marca e termina na Lais: ' . implode(',', $v_sim2));
[, $v_nao2] = $percorre($g2, $cid('07'), $g2['cards'][$cid('07')]['opcoes'][1]['id'], $cc2);
ok($v_nao2 === [$cid('13'), $cid('14')], 'o "nao" da data passa pela marca de perdido e chega na pergunta da espera: ' . implode(',', $v_nao2));
$rsim = wa_fluxo_proximo($g2, $cid('14'), $pe2['opcoes'][0]['id'], $cc2);
ok($rsim['card'] === $cid('15') && wa_fluxo_proximo($g2, $rsim['card'], '', $cc2)['motivo'] === 'ir_para_fluxo',
   'o "sim" da espera salta para o outro fluxo, e o salto se anuncia ao executor');
$rnao = wa_fluxo_proximo($g2, $cid('14'), $pe2['opcoes'][1]['id'], $cc2);
ok($rnao['card'] === $cid('17') && $g2['cards'][$rnao['card']]['tipo'] === 'fim', 'e o "nao" da espera se despede');

/* Quem DIGITA numa pergunta de botoes continua ouvindo a PROPRIA pergunta de novo, com os
   botoes dela, e nunca o menu, que ja nao responde nada. A segunda resposta solta chama a Lais. */
foreach ([$cid('07') => [$cid('18'), 'Data da viagem'], $cid('14') => [$cid('20'), 'lista de espera']] as $pid => [$fb, $trecho]) {
    $orig = $g2['cards'][$pid];
    $r1 = wa_fluxo_proximo($g2, $pid, 'acho que sim', $cc2);
    ok($r1['motivo'] === 'fallback' && $r1['card'] === $fb, 'texto solto em "' . $orig['titulo'] . '" cai no fallback proprio');
    ok($fb !== $menu2['fallback'], 'que NAO e o menu geral: ' . $orig['titulo']);
    ok(stripos($g2['cards'][$fb]['texto'], $trecho) !== false,
       'e que repete a PROPRIA pergunta ("' . $trecho . '"), nao um menu que ja nao responde nada: ' . $g2['cards'][$fb]['texto']);
    ok(count($g2['cards'][$fb]['opcoes']) === count($orig['opcoes']), 'com o mesmo numero de botoes: ' . $orig['titulo']);
    foreach ($orig['opcoes'] as $k => $o) {
        $f = $g2['cards'][$fb]['opcoes'][$k];
        ok($f['id'] === $o['id'] && $f['rotulo'] === $o['rotulo'] && $f['destino'] === $o['destino'],
           'o fallback repete o botao "' . $o['rotulo'] . '" com o mesmo destino');
    }
    $r2 = wa_fluxo_proximo($g2, $fb, 'ainda nao entendi', $r1['ctx']);
    ok($r2['motivo'] === 'socorro' && $r2['card'] === $g2['humano'], 'e a segunda resposta solta chama a Lais: ' . $orig['titulo']);
}
$rm2 = wa_fluxo_proximo($g2, $g2['inicial'], 'bom dia, e o cruzeiro?', $ctx2);
ok($rm2['motivo'] === 'fallback' && $rm2['card'] === $cid('16'), 'texto solto no menu cai no "respondeu outra coisa"');
$dest_menu = [];
foreach ($menu2['opcoes'] as $o) $dest_menu[$o['id']] = $o['destino'];
ok(count($g2['cards'][$cid('16')]['opcoes']) === 2, 'que repete as DUAS portas, nao as tres antigas');
foreach ($g2['cards'][$cid('16')]['opcoes'] as $o) {
    ok(($dest_menu[$o['id']] ?? null) === $o['destino'], 'repetindo a opcao "' . $o['rotulo'] . '" com o mesmo destino');
}

// A lista de espera v2: grava o destino, se despede, e nao entrega a conversa a ninguem.
$ctx_e2 = ['roteiro_slug' => 'turquia', 'tentativas' => 0];
$re2 = wa_fluxo_proximo($e2, $e2['inicial'], '', $ctx_e2);
ok($e2['cards'][$e2['inicial']]['tipo'] === 'marca' && ($e2['cards'][$e2['inicial']]['config']['espera'] ?? null) === '+destino',
   'a espera v2 grava o destino');
ok($e2['cards'][$re2['card']]['tipo'] === 'fim', 'e termina');
ok(strpos($e2['cards'][$re2['card']]['texto'], 'novas datas') !== false, 'prometendo avisar quando houver data nova');
foreach ($e2['cards'] as $c) ok($c['tipo'] !== 'humano', 'a lista de espera nao entrega a conversa a ninguem: so avisa e termina');

echo "test-wa-fluxo-semeado: ok\n";
