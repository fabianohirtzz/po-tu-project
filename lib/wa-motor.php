<?php
/* ============================================================
   A maquina de estados da conversa.

   Estados: novo -> aguardando_roteiro -> enviado_roteiro -> qualificado
            | desqualificado | encerrado | humano

   REGRA MAIS IMPORTANTE DO ARQUIVO: o eco (o que a cliente digita no
   celular) silencia o robo naquele contato para sempre. Sem isso, robo e
   humana falam por cima uma da outra com o cliente na linha.

   Toda dependencia externa (banco, envio, catalogo) entra por
   wa_motor_set_deps, para o teste rodar sem tocar a rede.
============================================================ */

require_once __DIR__ . '/po-data.php';
require_once __DIR__ . '/wa-db.php';
require_once __DIR__ . '/wa-send.php';
require_once __DIR__ . '/wa-roteiro.php';
require_once __DIR__ . '/wa-fone.php';

const WA_SITE = 'https://pereiraoliveiraturismo.com.br';

function wa_motor_set_deps($d) {
    $GLOBALS['WA_DEPS'] = array_merge($GLOBALS['WA_DEPS'] ?? [], $d);
}
function wa_motor_set_anuncios($mapa) { $GLOBALS['WA_ANUNCIOS'] = $mapa; }

function wa_dep($nome) {
    if (isset($GLOBALS['WA_DEPS'][$nome])) return $GLOBALS['WA_DEPS'][$nome];
    $padrao = [
        'roteiros'  => 'po_fetch_roteiros',
        'select'    => 'wa_db_select',
        'insert'    => 'wa_db_insert',
        'update'    => 'wa_db_update',
        'send_text' => 'wa_send_text',
        'send_doc'  => 'wa_send_document',
        'send_list' => 'wa_send_list',
    ];
    return $padrao[$nome];
}
function wa_call($nome, ...$args) { return call_user_func_array(wa_dep($nome), $args); }

/* ---------- textos ---------- */
function wa_texto($chave, $vars = []) {
    $linhas = wa_call('select', 'po_wa_textos', 'chave=eq.' . rawurlencode($chave));
    // Filtra pela chave em vez de confiar em $linhas[0]: o select do
    // Postgrest de verdade ja devolve so a linha pedida, mas um simulador
    // pode devolver a tabela inteira, e pegar a primeira linha da lista
    // trocaria o texto de um evento pelo de outro (ex.: perguntas virando
    // envio_pdf) sem erro nenhum, so a mensagem errada saindo pro cliente.
    $t = '';
    foreach ($linhas as $l) {
        if (($l['chave'] ?? null) === $chave) { $t = $l['texto'] ?? ''; break; }
    }
    foreach ($vars as $k => $v) $t = str_replace('{' . $k . '}', (string) $v, $t);
    // Placeholder sem valor nao pode vazar para o cliente.
    return trim(preg_replace('/\{[a-z_]+\}/', '', $t));
}

/* ---------- interpretacao de sim/nao ----------
   Devolve true, false, ou null quando a mensagem nao e nenhum dos dois
   (uma pergunta, por exemplo). Null nunca avanca o funil: preferimos
   deixar o lead parado a classifica-lo errado. */
function wa_resposta_sim($texto) {
    $t = wa_normaliza($texto);
    if (preg_match('/\b(nao|nunca|infelizmente|impossivel|nenhuma)\b/', $t)) return false;
    if (preg_match('/\b(sim|claro|tenho|posso|consigo|pode ser|isso|certo|perfeito|ja fui|ja viajei|ok)\b/', $t)) return true;
    return null;
}

/* ---------- estado ---------- */
function wa_conversa($wa_id) {
    $l = wa_call('select', 'po_wa_conversas', 'wa_id=eq.' . rawurlencode($wa_id));
    return $l[0] ?? null;
}
function wa_conversa_set($wa_id, $campos) {
    $campos['updated_at'] = gmdate('c');
    if (wa_conversa($wa_id)) {
        wa_call('update', 'po_wa_conversas', 'wa_id=eq.' . rawurlencode($wa_id), $campos);
    } else {
        wa_call('insert', 'po_wa_conversas', array_merge(['wa_id' => $wa_id], $campos));
    }
}
function wa_lead($wa_id) {
    $l = wa_call('select', 'po_leads', 'wa_id=eq.' . rawurlencode($wa_id));
    return $l[0] ?? null;
}
function wa_lead_set($wa_id, $campos) {
    if (wa_lead($wa_id)) {
        wa_call('update', 'po_leads', 'wa_id=eq.' . rawurlencode($wa_id), $campos);
    } else {
        wa_call('insert', 'po_leads', array_merge(['wa_id' => $wa_id], $campos));
    }
}

function wa_acha_roteiro($slug, $roteiros) {
    foreach ($roteiros as $r) if (($r['slug'] ?? '') === $slug) return $r;
    return null;
}

/* ---------- envio do roteiro ---------- */
function wa_envia_roteiro($wa_id, $r, $nome) {
    $legenda = wa_texto('envio_pdf', ['roteiro' => $r['titulo'] ?? '', 'nome' => $nome]);

    if (!empty($r['pdf_url'])) {
        $arquivo = ($r['slug'] ?? 'roteiro') . '.pdf';
        wa_call('send_doc', $wa_id, $r['pdf_url'], $arquivo, $legenda);
    } else {
        // Sem PDF a conversa nao pode morrer: manda o link da pagina, que
        // sempre existe porque o roteiro esta ativo no banco.
        wa_call('send_text', $wa_id, $legenda . "\n\n" . WA_SITE . '/roteiros/' . ($r['slug'] ?? ''));
    }

    // As duas perguntas vao na sequencia, sem pedir licenca: e exatamente
    // como a cliente faz na mao.
    wa_call('send_text', $wa_id, wa_texto('perguntas', [
        'nome'    => $nome,
        'roteiro' => $r['titulo'] ?? '',
        'data'    => $r['data_label'] ?? 'na data prevista',
    ]));

    wa_conversa_set($wa_id, [
        'estado'           => 'enviado_roteiro',
        'roteiro_slug'     => $r['slug'] ?? null,
        'aguardando_desde' => gmdate('c'),
        'lembrete_at'      => null,
    ]);
    wa_lead_set($wa_id, ['roteiro' => $r['titulo'] ?? '']);
    return 'enviou_roteiro';
}

/* ---------- atalhos digitados por ela ---------- */
function wa_atalho($wa_id, $texto) {
    $t = trim(mb_strtolower($texto, 'UTF-8'));

    if (strpos($t, '#proposta') === 0) {
        wa_lead_set($wa_id, ['status' => 'negociacao', 'proposta_at' => gmdate('c')]);
        return 'proposta';
    }
    if (strpos($t, '#fechou') === 0) {
        $campos = ['status' => 'venda', 'venda_at' => gmdate('c')];
        // "#fechou 22900" ou "#fechou 22.900,50"
        if (preg_match('/#fechou\s+([\d.,]+)/', $t, $m)) {
            $n = str_replace(['.', ','], ['', '.'], $m[1]);
            if (is_numeric($n)) $campos['venda'] = (float) $n;
        }
        wa_lead_set($wa_id, $campos);
        return 'venda';
    }
    if (strpos($t, '#perdeu') === 0) {
        wa_lead_set($wa_id, ['status' => 'perdido']);
        return 'perdido';
    }
    return null;
}

/* ---------- ponto de entrada ---------- */
function wa_processar($ev) {
    $wa_id = $ev['wa_id'];
    $texto = (string) ($ev['texto'] ?? '');
    $nome  = $ev['nome'] ?? '';

    if ($ev['tipo'] === 'status') return 'status';

    /* ----- eco: ela falou pelo celular ----- */
    if ($ev['tipo'] === 'eco') {
        $acao = wa_atalho($wa_id, $texto);
        if ($acao) return $acao;

        // Documento numa conversa ja qualificada e proposta, com alta
        // confianca no fluxo dela. Cobre o esquecimento do atalho.
        $conv = wa_conversa($wa_id);
        if (($ev['tipo_msg'] ?? '') === 'document'
            && in_array($conv['estado'] ?? '', ['qualificado', 'humano'], true)) {
            wa_lead_set($wa_id, ['status' => 'negociacao', 'proposta_at' => gmdate('c')]);
            return 'proposta';
        }

        // Qualquer outra fala dela e handoff: o robo se cala aqui.
        if (($conv['estado'] ?? '') !== 'humano') {
            wa_conversa_set($wa_id, ['estado' => 'humano', 'silenciado_at' => gmdate('c')]);
            return 'silenciou';
        }
        return 'ja_humano';
    }

    /* ----- mensagem do cliente ----- */
    $conv = wa_conversa($wa_id);

    // Conversa entregue a humana nunca volta para o robo.
    if (($conv['estado'] ?? '') === 'humano') return 'silenciado';

    $roteiros = wa_call('roteiros');

    // Primeiro contato: cria lead e contato.
    if (!$conv) {
        wa_lead_set($wa_id, [
            'nome'      => $nome,
            'telefone'  => $wa_id,
            'status'    => 'novo',
            'origem'    => $ev['ad_id'] ? 'pago' : 'whatsapp',
            'ad_id'     => $ev['ad_id'],
            'ctwa_clid' => $ev['ctwa_clid'],
        ]);
        wa_conversa_set($wa_id, ['estado' => 'novo']);
        $conv = wa_conversa($wa_id);
    }

    // Já mandou o roteiro: o que chega agora e resposta das perguntas.
    if (($conv['estado'] ?? '') === 'enviado_roteiro') {
        $r = wa_resposta_sim($texto);
        if ($r === false) {
            wa_conversa_set($wa_id, ['estado' => 'desqualificado']);
            wa_lead_set($wa_id, ['status' => 'perdido', 'qualif_data' => false]);
            wa_call('send_text', $wa_id, wa_texto('sem_data', ['nome' => $nome]));
            return 'desqualificou';
        }
        if ($r === true) {
            wa_conversa_set($wa_id, ['estado' => 'qualificado']);
            wa_lead_set($wa_id, [
                'status'       => 'atendimento',
                'qualif_data'  => true,
                'qualif_grupo' => true,
                'qualif_at'    => gmdate('c'),
            ]);
            wa_call('send_text', $wa_id, wa_texto('qualificado', ['nome' => $nome]));
            return 'qualificou';
        }
        // Nem sim nem nao (uma pergunta, por exemplo): nao classifica
        // errado e nao responde bobagem. Deixa para a humana.
        return 'aguardando';
    }

    // Identificacao do roteiro, da fonte mais confiavel para a menos.
    $slug = null;

    if (!empty($ev['ad_id'])) {
        $mapa = $GLOBALS['WA_ANUNCIOS'] ?? null;
        if ($mapa === null) {
            $linhas = wa_call('select', 'po_wa_anuncios', 'ad_id=eq.' . rawurlencode($ev['ad_id']));
            $slug = $linhas[0]['roteiro_slug'] ?? null;
        } else {
            $slug = $mapa[$ev['ad_id']] ?? null;
        }
    }
    if (!$slug) $slug = wa_slug_do_marcador($texto);
    if (!$slug && ($ev['tipo_msg'] ?? '') === 'list_reply') $slug = trim($texto);
    if (!$slug) {
        $achados = wa_match_roteiros($texto, $roteiros);
        if (count($achados) === 1) $slug = $achados[0];
    }

    if ($slug) {
        $r = wa_acha_roteiro($slug, $roteiros);
        if ($r) return wa_envia_roteiro($wa_id, $r, $nome);
    }

    // Nada identificado (ou ambiguo): o menu resolve sem chutar.
    $itens = [];
    foreach ($roteiros as $r) {
        $itens[] = ['id' => $r['slug'], 'titulo' => $r['titulo'], 'descricao' => $r['data_label'] ?? ''];
    }
    wa_call('send_list', $wa_id, wa_texto('menu', ['nome' => $nome]), 'Ver roteiros', $itens);
    wa_conversa_set($wa_id, ['estado' => 'aguardando_roteiro', 'aguardando_desde' => gmdate('c')]);
    return 'menu';
}
