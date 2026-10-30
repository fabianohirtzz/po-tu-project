<?php
/* Base compartilhada de tests/test-wa-motor.php e tests/test-wa-fluxo-leis.php:
   o banco em memoria, a lista de envios e um fluxo de triagem publicado.
   Nenhum teste toca a rede. Telefones de teste: +5548900000001 em diante.

   Quem inclui precisa ter, no escopo global, $DB (as tabelas) e $ENVIADAS
   (a lista do que foi enviado). Os dubles leem os dois por $GLOBALS. */

function fx_card($id, $tipo, $extra = []) {
    return array_merge(['id' => $id, 'tipo' => $tipo, 'titulo' => $id, 'texto' => 'oi',
        'opcoes' => [], 'config' => [], 'proximo' => null, 'fallback' => null,
        'sem_resposta' => null, 'lembrete_ativo' => false, 'lembrete_texto' => null], $extra);
}

/* O fluxo de triagem: botoes -> lista de roteiros -> PDF -> marca -> data ->
   grupo -> humana; "nao tenho a data" perde o lead e encerra. Fallback de
   todo card que espera: "outra", que na segunda vez chama a humana. */
function fx_grafo_triagem() {
    return ['id' => 'f-triagem', 'nome' => 'Triagem', 'inicial' => 'boas', 'humano' => 'hum',
        'porta' => 'mensagem_nova', 'porta_valor' => null, 'cards' => [
        'boas'  => fx_card('boas', 'botoes', ['texto' => 'Oi, {nome}! Sobre o que quer falar?', 'fallback' => 'outra',
                     'opcoes' => [['id' => 'rot', 'rotulo' => 'Um roteiro', 'destino' => 'lista'],
                                  ['id' => 'hum', 'rotulo' => 'Falar com a equipe', 'destino' => 'hum']]]),
        'lista' => fx_card('lista', 'lista', ['texto' => 'Sobre qual viagem?', 'config' => ['fonte' => 'roteiros'],
                     'proximo' => 'pdf', 'fallback' => 'outra']),
        'pdf'   => fx_card('pdf', 'pdf', ['texto' => 'Segue o roteiro completo do {roteiro}.', 'proximo' => 'mrc']),
        'mrc'   => fx_card('mrc', 'marca', ['config' => ['interesses' => '+roteiro'], 'proximo' => 'data']),
        'data'  => fx_card('data', 'botoes', ['texto' => 'A data {data} funciona para voce?', 'fallback' => 'outra',
                     'opcoes' => [['id' => 'sim', 'rotulo' => 'Sim, funciona', 'destino' => 'qual'],
                                  ['id' => 'nao', 'rotulo' => 'Nao nessa data', 'destino' => 'semdata']]]),
        'qual'  => fx_card('qual', 'marca', ['config' => ['status' => 'atendimento', 'qualif_data' => true, 'qualif_at' => true], 'proximo' => 'grupo']),
        'grupo' => fx_card('grupo', 'botoes', ['texto' => 'Ja viajou em grupo?', 'fallback' => 'outra',
                     'opcoes' => [['id' => 'ja', 'rotulo' => 'Ja viajei', 'destino' => 'hum'],
                                  ['id' => 'prim', 'rotulo' => 'Primeira vez', 'destino' => 'hum']]]),
        'semdata' => fx_card('semdata', 'marca', ['config' => ['status' => 'perdido', 'qualif_data' => false], 'proximo' => 'adeus']),
        'adeus' => fx_card('adeus', 'fim', ['texto' => 'Entendo, {nome}. Quando houver outra data, avisamos.']),
        'outra' => fx_card('outra', 'botoes', ['texto' => 'Nao entendi. Escolha uma opcao.',
                     'opcoes' => [['id' => 'rot', 'rotulo' => 'Um roteiro', 'destino' => 'lista'],
                                  ['id' => 'hum', 'rotulo' => 'Falar com a equipe', 'destino' => 'hum']]]),
        'hum'   => fx_card('hum', 'humano', ['texto' => 'Ja chamo a equipe.']),
    ]];
}

function fx_roteiros() {
    return [['slug' => 'turquia', 'titulo' => 'Turquia com Antalia', 'pdf_url' => 'https://x/t.pdf', 'data_label' => '10/05/27'],
            ['slug' => 'escandinavia', 'titulo' => 'O melhor da Escandinavia', 'pdf_url' => 'https://x/e.pdf', 'data_label' => '02/06/27']];
}

/* Tabelas vazias + o fluxo de triagem ligado. */
function fx_banco_inicial() {
    return [
        'po_wa_conversas' => [], 'po_wa_contatos' => [], 'po_leads' => [], 'po_wa_mensagens' => [],
        'po_wa_textos' => [],
        'po_wa_fluxos' => [
            ['id' => 'f-triagem', 'nome' => 'Triagem', 'gatilho' => 'mensagem_nova', 'gatilho_valor' => null,
             'ativo' => true, 'versao_atual' => 'v-triagem', 'created_at' => '2026-10-01T00:00:00Z'],
        ],
        'po_wa_fluxo_versoes' => [
            ['id' => 'v-triagem', 'fluxo_id' => 'f-triagem', 'versao' => 1, 'grafo' => fx_grafo_triagem()],
        ],
    ];
}

/* Filtro minimo de PostgREST: col=eq.v e col=not.is.null casam a linha;
   order=a.asc,b.desc e limit ordenam e cortam; o resto (select=) e ignorado.
   Honrar o order= e o que permite o teste notar quem o tira da consulta. */
function fx_casa($l, $query) {
    foreach (explode('&', (string) $query) as $par) {
        if (strpos($par, '=') === false) continue;
        [$k, $v] = explode('=', $par, 2);
        if (strpos($v, 'eq.') === 0) {
            $alvo = urldecode(substr($v, 3));
            $x = $l[$k] ?? null;
            if ($x === true) $x = 'true';
            if ($x === false) $x = 'false';
            if ($x === null || (string) $x !== $alvo) return false;
        } elseif ($v === 'not.is.null') {
            if (($l[$k] ?? null) === null) return false;
        }
    }
    return true;
}

function fx_consulta($tabela, $query) {
    $linhas = array_values(array_filter($GLOBALS['DB'][$tabela] ?? [], function ($l) use ($query) { return fx_casa($l, $query); }));
    foreach (explode('&', (string) $query) as $par) {
        if (strpos($par, '=') === false) continue;
        [$k, $v] = explode('=', $par, 2);
        if ($k === 'order') {
            $cols = array_map(function ($c) { return explode('.', $c); }, explode(',', $v));
            usort($linhas, function ($a, $b) use ($cols) {
                foreach ($cols as $c) {
                    $cmp = strcmp((string) ($a[$c[0]] ?? ''), (string) ($b[$c[0]] ?? ''));
                    if ($cmp !== 0) return (($c[1] ?? 'asc') === 'desc') ? -$cmp : $cmp;
                }
                return 0;
            });
        }
        if ($k === 'limit') $linhas = array_slice($linhas, 0, (int) $v);
    }
    return $linhas;
}

/* Instala os dubles no motor. Cada envio devolve ok+wamid; para forcar falha
   o teste sobrescreve o send_* com wa_motor_set_deps. */
function fx_instala() {
    $consulta = function ($tabela, $query) { $GLOBALS['QUERIES'][] = [$tabela, $query]; return fx_consulta($tabela, $query); };
    wa_motor_set_deps([
        'roteiros'       => 'fx_roteiros',
        'select'         => $consulta,
        'select_estrito' => $consulta,
        'insert' => function ($tabela, $linha) {
            $linha['id'] = $tabela . '-' . count($GLOBALS['DB'][$tabela] ?? []);
            $GLOBALS['DB'][$tabela][] = $linha;
            return $linha;
        },
        'update' => function ($tabela, $query, $campos) {
            foreach ($GLOBALS['DB'][$tabela] as $i => $l) {
                if (fx_casa($l, $query)) $GLOBALS['DB'][$tabela][$i] = array_merge($l, $campos);
            }
            return true;
        },
        // Valvula de seguranca: um laco sem teto viraria um teste pendurado.
        // Com ela, vira vermelho com mensagem.
        'send_text'    => function ($para, $texto) { $GLOBALS['ENVIADAS'][] = ['text', $para, $texto]; if (count($GLOBALS['ENVIADAS']) > 200) { fwrite(STDERR, "ASSERT: rajada de envios, o laco nao tem teto\n"); exit(1); } return ['ok' => true, 'wamid' => 'w' . count($GLOBALS['ENVIADAS']), 'erro' => null]; },
        'send_doc'     => function ($para, $url, $arq, $leg) { $GLOBALS['ENVIADAS'][] = ['doc', $para, $url, $leg]; return ['ok' => true, 'wamid' => 'w' . count($GLOBALS['ENVIADAS']), 'erro' => null]; },
        'send_list'    => function ($para, $corpo, $botao, $itens) { $GLOBALS['ENVIADAS'][] = ['list', $para, count($itens)]; return ['ok' => true, 'wamid' => 'w' . count($GLOBALS['ENVIADAS']), 'erro' => null]; },
        'send_buttons' => function ($para, $corpo, $botoes) { $GLOBALS['ENVIADAS'][] = ['buttons', $para, $corpo, $botoes]; return ['ok' => true, 'wamid' => 'w' . count($GLOBALS['ENVIADAS']), 'erro' => null]; },
    ]);
}
