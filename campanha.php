<?php
/* ============================================================
   Pereira Oliveira Turismo — Transmissao.

     modo=previa    -> monta o publico, devolve resumo + custo. NAO escreve.
     modo=criar     -> cria a campanha e comeca a RESERVAR. NAO envia.
     modo=reservar  -> reserva o proximo pedaco do publico. NAO envia.
     modo=drenar    -> manda o proximo lote (o wa-cron chama o mesmo caminho).
     modo=cancelar  -> encerra a campanha atual. A saida de emergencia.
     modo=estado    -> o andamento da campanha atual, so para a tela mostrar.

   Criar, reservar e drenar sao separados de proposito:

     - a reserva e um POST por pessoa. No topo da escada (2.000) seriam 2.000
       POSTs sequenciais numa requisicao HTTP, que o PHP do cPanel nao aguenta:
       morre no meio e a campanha fica com publico parcial, sem nada dizendo
       quem ficou de fora. Por isso ela vem em pedacos, e a campanha so sai de
       'rascunho' quando o publico INTEIRO esta reservado - o dreno so olha
       'enviando', entao nunca existe campanha enviando pela metade.
     - o envio custa dinheiro e demora. Com as linhas ja reservadas, o dreno
       seguinte continua de onde parou em vez de recomecar.

   A escrita usa a service_role, que so existe em config.local.php. O login do
   painel e validado antes de qualquer leitura ou escrita.
============================================================ */

require_once __DIR__ . '/lib/po-auth.php';
require_once __DIR__ . '/lib/wa-camp.php';
require_once __DIR__ . '/lib/wa-camp-fila.php';
require_once __DIR__ . '/lib/wa-db.php';     // ja carrega o lib/wa-config.php

const CAMP_PAGINA = 1000;   // paginacao da leitura da base

/* Espacamento minimo entre dois lotes disparados PELO BOTAO. O degrau e o
   teto do dia limitam TAMANHO; nada limitava FREQUENCIA, e a defesa 2 da spec
   8.1 e sobre cadencia: numero novo que dispara centenas de templates de uma
   vez e numero que a Meta rebaixa. Quem materializa a cadencia e o cron
   horario; o botao, que e util para nao esperar uma hora pelo primeiro lote,
   contornava essa cadencia - vinte cliques mandavam vinte lotes num minuto. */
const CAMP_INTERVALO_MANUAL = 600;   // 10 min

header('Content-Type: application/json; charset=utf-8');

/* A mensagem de erro NUNCA carrega nome, telefone nem trecho de ficha: ela
   vaza para log, print e suporte. */
function cfail($code, $msg) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function cok($dados) {
    echo json_encode(['ok' => true] + $dados, JSON_UNESCAPED_UNICODE);
    exit;
}

/* Campo de POST como string. Sem isto, um "modo[]=x" chega array e o
   in_array/preg_match estoura TypeError no PHP 8. */
function cpost($k) {
    $v = $_POST[$k] ?? '';
    return is_string($v) ? trim($v) : '';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') cfail(405, 'Método não permitido.');

$cfg = wa_config();
if (!po_auth_ok($cfg['SUPABASE_URL'] ?? '', $cfg['SUPABASE_ANON_KEY'] ?? '')) {
    cfail(401, 'Sessão expirada. Entre de novo no painel.');
}
if (($cfg['SUPABASE_SERVICE_KEY'] ?? '') === '') {
    cfail(500, 'SUPABASE_SERVICE_KEY não configurada no config.local.php.');
}

/* Le a base inteira, paginada. wa_db_select_estrito e nao wa_db_select: com o
   Supabase fora do ar o select frouxo devolve lista vazia, e a previa diria
   "0 pessoas" em vez de dizer que falhou - a cliente concluiria que a base
   sumiu. Pior ainda no modo reservar, onde base vazia significaria "acabou". */
function camp_leads() {
    $todos = [];
    for ($i = 0; $i < 100; $i++) {      // teto de 100 mil leads; a base tem ~790
        $p = wa_db_select_estrito('po_leads',
            'select=id,nome,telefone,wa_id,cliente,revisado,opt_out_at,payload_import'
            . '&order=created_at.asc&offset=' . ($i * CAMP_PAGINA) . '&limit=' . CAMP_PAGINA);
        if ($p === null) return null;
        foreach ($p as $l) $todos[] = $l;
        if (count($p) < CAMP_PAGINA) break;
    }
    return $todos;
}

/* A campanha que esta no caminho: a que ainda monta a lista ('rascunho') ou a
   que ainda tem gente para enviar ('enviando'). Existe UMA por vez de
   proposito - duas campanhas ao mesmo tempo dividiriam o teto diario sem
   saber uma da outra, e o dreno do cron so pega uma. E tambem e o que impede
   o segundo clique no botao de criar uma campanha gemea, cobrada em dobro. */
function camp_em_andamento() {
    return wa_db_select_estrito('po_wa_campanhas',
        'select=id,nome,template,status,total,custo_estimado_centavos,created_at'
        . '&status=in.(rascunho,enviando)&order=created_at.asc&limit=1');
}

/* Publico + custo, do jeito que a tela mostra e o reservador usa. A MESMA
   funcao nos dois caminhos: previa que calcule diferente da reserva mostra um
   numero e cobra outro. */
function camp_calcula() {
    $leads = camp_leads();
    if ($leads === null) cfail(502, 'Não consegui ler a base agora. Nada foi alterado.');
    $r = wa_camp_publico($leads);
    return [$leads, $r, wa_camp_custo($r['resumo']['total'], WA_CAMP_PRECO_CENTAVOS)];
}

/* Fecha a lista: carimba o total REAL de reservados e libera o dreno.

   O total e reescrito porque a base pode ter mudado entre a confirmacao e o
   fim da reserva (alguem respondeu SAIR, alguem foi revisado). Quem manda e
   quantas linhas existem de verdade na fila; o preco continua congelado, e o
   custo passa a ser o do numero real.

   DEVOLVE o booleano, e quem chama e obrigado a olhar. Jogar fora o retorno
   fazia a resposta dizer completo:true com o PATCH em 500: a tela anunciava
   "campanha pronta" e a campanha ficava em 'rascunho' - que nem o cron nem o
   botao drenam, e que ainda bloqueia toda campanha futura. */
function camp_fecha_reserva($id, $reservados_total, $custo_estimado) {
    $n = (int) $reservados_total;
    return wa_db_update('po_wa_campanhas', 'id=eq.' . rawurlencode((string) $id), [
        'total'                   => $n,
        'custo_estimado_centavos' => $n > 0 ? wa_camp_custo($n, WA_CAMP_PRECO_CENTAVOS)
                                            : (int) $custo_estimado,
        'status'                  => 'enviando',
    ]) === true;
}

$modo = cpost('modo');
if ($modo === '') $modo = 'previa';
if (!in_array($modo, ['previa', 'criar', 'reservar', 'drenar', 'cancelar', 'estado'], true)) {
    cfail(400, 'Modo desconhecido.');
}

/* ---------------------------------------------------------- estado */
if ($modo === 'estado') {
    $atual = camp_em_andamento();
    if ($atual === null) cfail(502, 'Não consegui ler as campanhas agora.');
    if (!$atual) cok(['campanha' => null]);

    $c   = $atual[0];
    $cid = 'campanha_id=eq.' . rawurlencode((string) $c['id']);
    /* Contagens pelo banco (wa_db_conta), nao trazendo as linhas: sao ate
       2.000 destinatarios com nome e telefone, e a tela so precisa do numero. */
    $reservados = wa_db_conta('po_wa_envios', $cid);
    $pendentes  = wa_db_conta('po_wa_envios', $cid . '&status=in.(reservado,enviando)');
    $enviados   = wa_db_conta('po_wa_envios', $cid . '&status=in.(enviado,entregue,lido)');
    $falhas     = wa_db_conta('po_wa_envios', $cid . '&status=eq.falha');
    cok([
        'campanha'   => $c,
        'reservados' => $reservados === null ? 0 : $reservados,
        'pendentes'  => $pendentes  === null ? 0 : $pendentes,
        'enviados'   => $enviados   === null ? 0 : $enviados,
        'falhas'     => $falhas     === null ? 0 : $falhas,
    ]);
}

/* ---------------------------------------------------------- previa */
if ($modo === 'previa') {
    list($leads, $r, $custo) = camp_calcula();

    // Conferencia do estrangeiro discado com 00, so entre QUEM VAI RECEBER.
    $porLead = [];
    foreach ($leads as $l) $porLead[(string) ($l['id'] ?? '')] = $l;
    $suspeitos = 0;
    foreach ($r['publico'] as $p) {
        $l = $porLead[(string) $p['lead_id']] ?? null;
        if ($l && wa_camp_ddi_suspeito($l['payload_import'] ?? null) !== null) $suspeitos++;
    }

    cok([
        'resumo'         => $r['resumo'],
        'total'          => $r['resumo']['total'],
        'custo_centavos' => $custo,
        'suspeitos'      => $suspeitos,
    ]);
}

/* ---------------------------------------------------------- criar */
if ($modo === 'criar') {
    $nome     = cpost('nome');
    $template = cpost('template');
    $corpo    = cpost('corpo');

    if ($nome === '')     cfail(400, 'Dê um nome para esta campanha.');
    if ($template === '') cfail(400, 'Informe o nome do modelo aprovado na Meta.');

    /* Defesa 3 da spec 8.1, cobrada tambem no servidor: a tela pode ser
       contornada, o endpoint nao. */
    if ($corpo === '') cfail(400, 'Escreva o texto da mensagem.');
    if (mb_strlen($corpo) > 1024) {
        cfail(400, 'O texto passa de 1024 caracteres e a Meta recusaria a mensagem inteira.');
    }
    if (!preg_match('/\bSAIR\b/', $corpo)) {
        cfail(400, 'O texto precisa conter a frase com a palavra SAIR, '
                 . 'que é como a pessoa sai da lista.');
    }

    /* O NUMERO QUE ELA CONFIRMOU PRENDE O SERVIDOR (spec 8.2). Sem isto o
       aviso de custo existe mas nao e vinculante: a tela confirma "23 pessoas,
       R$ 7,13" sobre uma previa que so e recalculada ao entrar na aba, e o
       criar refaz a conta do zero. Com duas abas do painel abertas, ela
       confirma um numero e a campanha sai com outro publico e outro custo.

       Vazio tambem e recusado: criar campanha sem numero confirmado seria
       gastar sem o aviso, que e exatamente o que a spec proibe. */
    $confirmado = cpost('total_confirmado');
    if ($confirmado === '' || !ctype_digit($confirmado)) {
        cfail(400, 'Recarregue a aba Transmissão: o público e o custo precisam estar '
                 . 'na tela antes de enviar.');
    }

    /* Uma campanha por vez. Esta conferencia vem ANTES de calcular o publico:
       quem esbarrou nela ja perdeu, e nao ha por que ler 800 leads para so
       entao recusar. */
    $atual = camp_em_andamento();
    if ($atual === null) cfail(502, 'Não consegui conferir as campanhas. Nada foi criado.');
    if ($atual) {
        cfail(409, 'Já existe uma campanha em andamento. Termine ou aguarde o envio dela '
                 . 'antes de começar outra.');
    }

    list($leads, $r, $custo) = camp_calcula();
    /* Publico vazio nunca vira campanha: uma linha 'rascunho' sem
       destinatario ficaria eternamente no caminho da proxima. */
    if ($r['resumo']['total'] === 0) {
        cfail(400, 'Nenhuma pessoa entra nesta campanha. Revise os contatos antes.');
    }
    if ((int) $confirmado !== $r['resumo']['total']) {
        cfail(409, 'A lista mudou desde que você viu o público: agora são '
                 . $r['resumo']['total'] . ' pessoas, e não ' . (int) $confirmado . '. '
                 . 'Confira o público e o custo de novo antes de enviar.');
    }

    /* wa_db_insert_status e nao wa_db_insert: com o indice unico parcial
       po_wa_campanhas_uma_ativa no banco, o 409 aqui significa "outra aba
       ganhou a corrida", nao "o banco caiu". Sao mensagens diferentes, e o
       unique e quem de fato tranca - a conferencia acima le e so depois
       escreve, e a janela entre as duas coisas cabe um segundo clique. */
    $ins = wa_db_insert_status('po_wa_campanhas', [
        'nome'                    => mb_substr($nome, 0, 120),
        'roteiro_slug'            => cpost('roteiro_slug') ?: null,
        'template'                => $template,
        'corpo'                   => $corpo,
        'segmento'                => ['origem' => 'painel'],
        'total'                   => $r['resumo']['total'],
        // Congelados AGORA: o preco da Meta muda, e o relatorio de uma
        // campanha antiga tem que continuar batendo com a fatura daquele mes.
        'preco_centavos'          => WA_CAMP_PRECO_CENTAVOS,
        'custo_estimado_centavos' => $custo,
        // 'rascunho', nao 'enviando': o dreno so pega 'enviando', entao a
        // campanha so fica visivel para ele quando a lista estiver inteira.
        'status'                  => 'rascunho',
    ]);
    if ($ins['status'] === 409) {
        cfail(409, 'Já existe uma campanha em andamento. Termine ou aguarde o envio dela '
                 . 'antes de começar outra.');
    }
    $camp = $ins['linha'];
    if (!$camp) cfail(502, 'Não consegui criar a campanha. Nada foi enviado.');

    $l = wa_camp_reserva_lote($camp['id'], $r['publico']);
    /* completo so vale depois de o PATCH passar: a campanha ainda esta em
       'rascunho' ate ele, e rascunho ninguem drena. */
    $completo = $l['completo']
        && camp_fecha_reserva($camp['id'], $l['reservados_total'], $custo);

    cok([
        'campanha_id'      => $camp['id'],
        'total'            => $r['resumo']['total'],
        'reservados'       => $l['reservados'],
        'reservados_total' => $l['reservados_total'],
        'faltam'           => $l['faltam'],
        'erros'            => $l['erros'],
        'completo'         => $completo,
        'custo_centavos'   => $custo,
    ]);
}

/* ---------------------------------------------------------- reservar */
if ($modo === 'reservar') {
    $id = cpost('campanha_id');
    if ($id === '') cfail(400, 'Campanha não informada.');

    $c = wa_db_select_estrito('po_wa_campanhas',
        'select=id,status,preco_centavos&id=eq.' . rawurlencode($id) . '&limit=1');
    if ($c === null) cfail(502, 'Não consegui ler a campanha agora.');
    if (!$c)         cfail(404, 'Campanha não encontrada.');
    /* So 'rascunho' aceita reserva. Reservar em campanha ja 'enviando'
       acrescentaria gente a uma lista que o dreno ja esta consumindo, e o
       numero que a cliente confirmou deixaria de valer. */
    if (($c[0]['status'] ?? '') !== 'rascunho') {
        cfail(409, 'Esta campanha já está com a lista fechada.');
    }

    list($leads, $r, $custo) = camp_calcula();
    $l = wa_camp_reserva_lote($id, $r['publico']);
    $completo = $l['completo']
        && camp_fecha_reserva($id, $l['reservados_total'], $custo);

    cok([
        'campanha_id'      => $id,
        'total'            => $r['resumo']['total'],
        'reservados'       => $l['reservados'],
        'reservados_total' => $l['reservados_total'],
        'faltam'           => $l['faltam'],
        'erros'            => $l['erros'],
        'completo'         => $completo,
    ]);
}

/* ---------------------------------------------------------- drenar */
if ($modo === 'drenar') {
    $id = cpost('campanha_id');
    if ($id === '') cfail(400, 'Campanha não informada.');

    $c = wa_db_select_estrito('po_wa_campanhas',
        'select=id,status&id=eq.' . rawurlencode($id) . '&limit=1');
    if ($c === null) cfail(502, 'Não consegui ler a campanha agora. Nada foi enviado.');
    if (!$c)         cfail(404, 'Campanha não encontrada.');
    /* So 'enviando' e drenavel, a mesma condicao do cron. Uma campanha em
       'rascunho' ainda esta montando a lista: drenar ali mandaria para o
       pedaco ja reservado e concluiria a campanha com o resto da base de
       fora, sem nada dizendo quem ficou. */
    if (($c[0]['status'] ?? '') !== 'enviando') {
        cfail(409, 'Esta campanha não está pronta para enviar.');
    }

    /* Espacamento do dreno manual. Le o envio mais recente DESTA campanha;
       leitura que falha recusa o disparo em vez de liberar - na duvida, o
       lado barato e nao mandar, porque o cron manda de qualquer jeito. */
    $ult = wa_db_select_estrito('po_wa_envios',
        'select=enviado_at&campanha_id=eq.' . rawurlencode($id)
        . '&status=in.(enviado,entregue,lido)&order=enviado_at.desc&limit=1');
    if ($ult === null) cfail(502, 'Não consegui conferir o último envio. Nada foi enviado.');
    if ($ult && !empty($ult[0]['enviado_at'])) {
        $quando = strtotime((string) $ult[0]['enviado_at']);
        // Data ilegivel conta como recente: na duvida, nao mandar.
        if ($quando === false || (time() - $quando) < CAMP_INTERVALO_MANUAL) {
            cfail(429, 'O lote anterior saiu há menos de '
                     . (int) (CAMP_INTERVALO_MANUAL / 60) . ' minutos. O envio sai em lotes '
                     . 'espaçados de propósito, para o WhatsApp não rebaixar o número da '
                     . 'agência. O próximo lote sai sozinho, de hora em hora.');
        }
    }

    /* wa_camp_drena_campanha, nao wa_camp_drena: e o mesmo caminho do cron,
       com a escada, o teto do dia e o encerramento no mesmo lugar. Duas telas
       decidindo sozinhas quando concluir uma campanha e como essa regra
       apodrece. */
    cok(wa_camp_drena_campanha($id));
}

/* ---------------------------------------------------------- cancelar */
/* A saida de emergencia. Sem ela, uma campanha travada em 'rascunho' (a
   reserva morreu no meio e nao volta) bloqueia TODA campanha futura, porque
   existe uma por vez - e nao haveria caminho no painel para destravar, so
   SQL no banco. Tambem serve para parar um envio no meio: o que ja saiu esta
   pago, mas o resto da fila para de custar. */
if ($modo === 'cancelar') {
    $id = cpost('campanha_id');
    if ($id === '') cfail(400, 'Campanha não informada.');

    $c = wa_db_select_estrito('po_wa_campanhas',
        'select=id,status&id=eq.' . rawurlencode($id) . '&limit=1');
    if ($c === null) cfail(502, 'Não consegui ler a campanha agora.');
    if (!$c)         cfail(404, 'Campanha não encontrada.');
    if (!in_array($c[0]['status'] ?? '', ['rascunho', 'enviando'], true)) {
        cfail(409, 'Esta campanha já foi encerrada.');
    }
    if (!wa_db_update('po_wa_campanhas', 'id=eq.' . rawurlencode($id),
            ['status' => 'cancelada', 'concluida_at' => gmdate('c')])) {
        cfail(502, 'Não consegui cancelar a campanha. Tente de novo.');
    }
    cok(['campanha_id' => $id, 'cancelada' => true]);
}

cfail(400, 'Modo desconhecido.');
