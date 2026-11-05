<?php
/* As datas de um roteiro de SAIDAS SEMANAIS, e a string de data que toda
   tela mostra.

   Spec: docs/superpowers/specs/2026-11-04-robo-entende-frase-e-roteiro-semanal-design.md

   ARQUIVO FOLHA, PURO, SEM NENHUM `require`. E de proposito: tanto a camada
   de view (lib/po-view.php) quanto o motor do robo (lib/wa-roteiro.php)
   precisam destas contas, e so sem dependencia nenhuma os dois podem
   requerer este arquivo sem criar ciclo nem furar a seta do subsistema do
   robo (db/send/po-data <- fluxo <- sim/motor <- timeout).

   POR QUE EXISTE: o roteiro do Peru sai toda semana. Ele nao tem um periodo
   unico, tem uma lista de datas por mes que a cliente mantem no painel. Sem
   tratamento proprio, ele cai no mesmo lugar de quem nao tem data nenhuma -
   e "sem data" vai para o FIM da lista do robo, que e onde ficam as viagens
   que JA PARTIRAM. Um roteiro que sai na semana que vem anunciado no fim da
   fila e o defeito que este arquivo existe para impedir.

   Os gemeos em JavaScript vivem em assets/js/roteiros-shared.js (paginas
   publicas) e em painel/app.js (o painel nao carrega o roteiros-shared), e
   tests/test-datas-semanal.mjs roda a MESMA tabela de casos nos tres e
   exige que concordem caso a caso. */

/* A forma de po_roteiros.datas_texto, limpa:
     [['titulo' => 'Datas de Dezembro', 'datas' => ['02/12/26', '10/12/26']]]

   O que nao tem essa forma e DESCARTADO em silencio, nunca aceito pela
   metade: o jsonb pode ter sido editado a mao no SQL Editor, e um grupo
   meio formado produziria data vinda de sabe-se la onde. Pura. */
function po_datas_grupos($roteiro) {
    if (!is_array($roteiro)) return [];
    $v = $roteiro['datas_texto'] ?? null;
    if (is_string($v)) $v = json_decode($v, true);   // PostgREST pode entregar jsonb como texto
    if (!is_array($v) || $v === []) return [];
    if (array_keys($v) !== range(0, count($v) - 1)) return [];   // tem de ser LISTA, nao objeto

    $out = [];
    foreach ($v as $g) {
        if (!is_array($g) || !isset($g['titulo']) || !is_string($g['titulo'])) continue;
        if (!isset($g['datas']) || !is_array($g['datas'])) continue;
        $datas = [];
        foreach ($g['datas'] as $d) {
            if (!is_string($d)) continue;
            $d = trim($d);
            if ($d !== '') $datas[] = $d;
        }
        $out[] = ['titulo' => $g['titulo'], 'datas' => $datas];
    }
    return $out;
}

/* 'YYYY-MM-DD' da primeira saida de HOJE EM DIANTE, ou null.

   A MENOR das futuras, nao a primeira que aparece na lista: a cliente digita
   na ordem que quiser, e quem le espera a proxima de verdade.

   `>=` e nao `>`: a saida de hoje ainda e uma saida.

   Data impossivel (32/12, 31/02) e texto no lugar da data sao descartados e
   a busca CONTINUA. Sem isso, um erro de digitacao no painel mandaria a
   viagem para o fim da fila em vez de para a data certa. Pura. */
function po_proxima_saida($roteiro, $hoje = null) {
    $hoje = ($hoje === null) ? date('Y-m-d') : (string) $hoje;
    $melhor = null;
    foreach (po_datas_grupos($roteiro) as $g) {
        foreach ($g['datas'] as $txt) {
            $iso = po_datas_iso($txt);
            if ($iso === null || $iso < $hoje) continue;
            if ($melhor === null || $iso < $melhor) $melhor = $iso;
        }
    }
    return $melhor;
}

/* 'd/m/a' -> 'YYYY-MM-DD', ou null. Mesmo criterio de
   wa_roteiro_data_ini: ano de 2 digitos vira 20xx, e checkdate recusa data
   impossivel. Pura. */
function po_datas_iso($txt) {
    if (!is_string($txt)) return null;
    if (!preg_match('#^\s*(\d{1,2})/(\d{1,2})/(\d{4}|\d{2})\s*$#', $txt, $m)) return null;
    $d = (int) $m[1];
    $mes = (int) $m[2];
    $ano = (int) $m[3];
    if (strlen($m[3]) === 2) $ano += 2000;
    if (!checkdate($mes, $d, $ano)) return null;
    return sprintf('%04d-%02d-%02d', $ano, $mes, $d);
}

/* As proximas datas de um roteiro de saidas semanais, agrupadas pelo titulo
   que a cliente escreveu, uma linha por mes:

     Datas de Dezembro: 02/12/26, 10/12/26
     Datas de Janeiro: 07/01/27

   SO AS FUTURAS: data vencida no texto do WhatsApp e ruido e, pior, parece
   oferta. Mes que ficou sem data futura sai inteiro.

   O CORTE CAI SEMPRE NUMA DATA COMPLETA. A legenda do card de PDF da Cloud
   API para em 1024 caracteres, e acima disso a Meta recusa a mensagem
   INTEIRA, em silencio - entao o teto nao e opcional. Mas cortar no meio de
   uma data afirmaria ao cliente um dia que nao existe, que e pior que
   mandar menos datas. Pura. */
function po_datas_proximas($roteiro, $hoje = null, $max = 300) {
    if (!is_array($roteiro) || ($roteiro['status_viagem'] ?? null) !== 'semanal') return '';
    $hoje = ($hoje === null) ? date('Y-m-d') : (string) $hoje;

    $linhas = [];
    foreach (po_datas_grupos($roteiro) as $g) {
        $ds = [];
        foreach ($g['datas'] as $txt) {
            $iso = po_datas_iso($txt);
            if ($iso !== null && $iso >= $hoje) $ds[] = $txt;
        }
        if (!$ds) continue;
        $t = trim($g['titulo']);
        $linhas[] = ($t !== '' ? $t . ': ' : '') . implode(', ', $ds);
    }
    if (!$linhas) return '';

    /* Monta ate onde cabe, data a data: a unidade do corte e a data, nunca o
       caractere. Linha que nao cabe inteira entra com as datas que couberem;
       nao cabendo nenhuma, a linha nao entra. */
    $out = '';
    foreach ($linhas as $linha) {
        if (mb_strlen($out === '' ? $linha : $out . "
" . $linha, 'UTF-8') <= $max) {
            $out = ($out === '' ? $linha : $out . "
" . $linha);
            continue;
        }
        $pos = mb_strpos($linha, ': ', 0, 'UTF-8');
        $pre = ($pos === false) ? '' : mb_substr($linha, 0, $pos + 2, 'UTF-8');
        $ds  = explode(', ', ($pos === false) ? $linha : mb_substr($linha, $pos + 2, null, 'UTF-8'));
        $parcial = '';
        foreach ($ds as $d) {
            $tenta = $pre . ($parcial === '' ? $d : $parcial . ', ' . $d);
            if (mb_strlen($out === '' ? $tenta : $out . "
" . $tenta, 'UTF-8') > $max) break;
            $parcial = ($parcial === '' ? $d : $parcial . ', ' . $d);
        }
        if ($parcial !== '') $out = ($out === '' ? $pre . $parcial : $out . "
" . $pre . $parcial);
        break;   // o que nao coube nesta linha nao cabera nas seguintes
    }
    return $out;
}

/* A string de data que a pessoa le, em TODO lugar que mostra roteiro.

   Quem decide e o STATUS, nao a presenca do dado: um roteiro comum com
   datas_texto preenchido por engano segue mostrando o periodo dele.

   Semanal sem nenhuma data futura volta ao comportamento de sempre:
   anunciar "Proxima saida" de uma data que ja passou e pior que nao anunciar
   nada, e e o que aconteceria no mes em que ninguem atualizasse o painel.

   A data sai EXATAMENTE como a cliente digitou, sem reformatar: ela escolheu
   o formato e reformatar so cria surpresa. Pura. */
function po_quando($roteiro, $hoje = null) {
    if (!is_array($roteiro)) return '';
    if (($roteiro['status_viagem'] ?? null) === 'semanal') {
        $iso = po_proxima_saida($roteiro, $hoje);
        if ($iso !== null) {
            foreach (po_datas_grupos($roteiro) as $g) {
                foreach ($g['datas'] as $txt) {
                    if (po_datas_iso($txt) === $iso) return 'Próxima saída: ' . $txt;
                }
            }
        }
    }
    $s = trim((string) ($roteiro['data_label'] ?? ''));
    if ($s !== '') return $s;
    return trim((string) ($roteiro['periodo'] ?? ''));
}
