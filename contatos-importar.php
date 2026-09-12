<?php
/* ============================================================
   Pereira Oliveira Turismo — Importador de contatos.

   Recebe do painel um .vcf/.csv (agenda dos dois aparelhos) ou a ficha do
   CRM antigo ja em CSV, calcula a AUDITORIA DE FUSAO (lib/wa-import.php) e:

     modo=preview  -> devolve o plano resumido e NAO escreve nada.
     modo=aplicar  -> executa o plano (insert/update na po_leads).

   Por que um PHP no servidor e nao o navegador: a escrita usa a
   service_role do Supabase, que so existe em config.local.php. O login do
   painel e validado antes de qualquer leitura ou escrita (lib/po-auth.php).

   ------------------------------------------------------------
   ENTRADA (POST, multipart/form-data):
     arquivo  : o .vcf/.csv   (ou)  texto: o conteudo colado
     tipo     : vcf | csv           (padrao vcf)
     origem   : crm-toninho | agenda-esposa | agenda-marido | ...
     modo     : preview | aplicar   (padrao preview)
     sb_token : access_token do Supabase (valida o login)
   SAIDA (JSON): { ok:true, resumo:{...}, itens:[...] }            (preview)
                 { ok:true, resumo:{...}, aplicado:{...} }         (aplicar)
============================================================ */

require_once __DIR__ . '/lib/po-auth.php';
require_once __DIR__ . '/lib/wa-import.php';
require_once __DIR__ . '/lib/wa-vcard.php';
require_once __DIR__ . '/lib/wa-db.php';     // ja carrega o lib/wa-config.php

const CI_MAX_BYTES = 8 * 1024 * 1024;   // agenda inteira em vCard da ~1 MB
const CI_PAGINA    = 1000;              // paginacao da leitura da base

/* Prazo da reserva do lote. Enquanto uma linha reservada for mais nova que
   isto, outra requisicao do MESMO arquivo e tratada como "pode estar
   rodando" e recusada. Passado o prazo, a linha e orfa e libera o retry. */
const CI_LOTE_LEASE = 600;              // 10 min

header('Content-Type: application/json; charset=utf-8');

/* A mensagem de erro NUNCA carrega trecho do arquivo: sao nome, telefone,
   CPF e passaporte de cliente, e mensagem de erro vaza para log, print e
   suporte. */
function cfail($code, $msg) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

/* Campo de POST como string. Sem isto, um "origem[]=x" chega array e o
   preg_match estoura TypeError no PHP 8. */
function cpost($k) {
    $v = $_POST[$k] ?? '';
    return is_string($v) ? trim($v) : '';
}

/* Estado de um lote ja registrado, para a guarda de idempotencia:

     ausente    nunca foi importado
     concluido  importacao terminou por inteiro
     andamento  linha RESERVADA ha pouco: outra requisicao pode estar
                escrevendo os leads agora mesmo
     orfao      linha reservada ha muito tempo e nunca concluida: a
                importacao morreu no meio (fatal, max_execution_time) e a
                cliente pode tentar de novo sem precisar do forcar

   O relogio e o unico jeito de separar 'andamento' de 'orfao' sem um
   heartbeat, que este projeto nao tem. CI_LOTE_LEASE e folgado de proposito:
   o PHP da hospedagem morre muito antes disso, entao uma linha mais velha que
   o prazo esta orfa de verdade. */
function ci_lote_estado($hash) {
    $r = wa_db_select_estrito('po_import_lotes',
        'select=id,created_at,concluido_at&hash=eq.' . rawurlencode($hash) . '&limit=1');
    /* Leitura ESTRITA, pelo mesmo motivo da base: o wa_db_select comum
       devolve [] tanto para "nao achei" quanto para "o Supabase recusou".
       Tratar erro como "nao achei" aqui e concluir que o lote e novo com o
       banco fora do ar - exatamente a duplicacao que esta guarda impede. */
    if ($r === null) return null;
    if (!$r)         return ['estado' => 'ausente'];

    $l = is_array($r[0] ?? null) ? $r[0] : [];
    if (!empty($l['concluido_at'])) return ['estado' => 'concluido', 'linha' => $l];

    $t = strtotime((string) ($l['created_at'] ?? ''));
    // created_at ilegivel conta como 'andamento': na duvida, nao deixar
    // escrever por cima de uma importacao que pode estar correndo.
    $vivo = ($t === false) || (time() - $t < CI_LOTE_LEASE);
    return ['estado' => $vivo ? 'andamento' : 'orfao', 'linha' => $l];
}

/* Instante atual em ISO 8601 UTC, do jeito que o PostgREST aceita. */
function ci_agora() { return gmdate('Y-m-d\TH:i:s\Z'); }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') cfail(405, 'Metodo nao permitido.');

$cfg = wa_config();
if (!po_auth_ok($cfg['SUPABASE_URL'] ?? '', $cfg['SUPABASE_ANON_KEY'] ?? '')) {
    cfail(401, 'Sessao invalida. Faca login no painel novamente.');
}
if (($cfg['SUPABASE_SERVICE_KEY'] ?? '') === '') {
    cfail(500, 'SUPABASE_SERVICE_KEY nao configurada no config.local.php.');
}

/* -------- parametros, todos contra formato fechado -------- */
$tipoBruto = cpost('tipo');
if ($tipoBruto === '') $tipoBruto = 'vcf';
if (!in_array($tipoBruto, ['vcf', 'csv'], true)) {
    // Adivinhar aqui seria pior que recusar: o parser errado devolve zero
    // contato e a tela diria "nada para importar" sobre uma agenda cheia.
    cfail(400, 'Tipo desconhecido. Use "vcf" ou "csv".');
}
$tipo = $tipoBruto;

/* Lista fechada, nao formato. 'crm' era prefixo, e qualquer coisa comecando
   com ele (ate 'crmx') marcava o contato como cliente JA REVISADO - o que a
   secao 8.1 da spec proibe, porque revisado=true poe a pessoa em disparo de
   marketing sem passar pela revisao da cliente. */
$origem = cpost('origem');
if (!wa_import_origem_valida($origem)) {
    cfail(400, 'Origem invalida. Use uma destas: ' . implode(', ', WA_IMPORT_ORIGENS) . '.');
}

$modo = cpost('modo');
if ($modo === '') $modo = 'preview';
if (!in_array($modo, ['preview', 'aplicar'], true)) cfail(400, 'Modo invalido. Use "preview" ou "aplicar".');

/* -------- conteudo -------- */
$texto = '';
if (isset($_FILES['arquivo'])) {
    $err = $_FILES['arquivo']['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        cfail(413, 'Arquivo grande demais para o servidor. Exporte a agenda em partes.');
    }
    if ($err !== UPLOAD_ERR_OK && $err !== UPLOAD_ERR_NO_FILE) {
        cfail(400, 'Falha no envio do arquivo (codigo ' . (int) $err . ').');
    }
    if ($err === UPLOAD_ERR_OK) {
        if (($_FILES['arquivo']['size'] ?? 0) > CI_MAX_BYTES) {
            cfail(413, 'Arquivo grande demais (max. 8 MB).');
        }
        $lido = @file_get_contents($_FILES['arquivo']['tmp_name']);
        if ($lido === false) cfail(400, 'Nao foi possivel ler o arquivo enviado.');
        $texto = (string) $lido;
    }
}
if ($texto === '') $texto = cpost('texto');

if (strlen($texto) > CI_MAX_BYTES) cfail(413, 'Conteudo grande demais (max. 8 MB).');
$texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto);          // BOM do Windows
if (trim($texto) === '') {
    cfail(400, 'Arquivo vazio. Envie um .vcf/.csv no campo "arquivo" ou o conteudo em "texto".');
}
/* Agenda de Android exporta vCard 2.1 em Latin-1 com frequencia. Sem esta
   conversao os acentos viram byte invalido, o json_encode da escrita
   devolve false e a linha vai embora sem corpo. */
if (function_exists('mb_check_encoding') && !mb_check_encoding($texto, 'UTF-8')) {
    $texto = mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
}

/* -------- idempotencia: este arquivo ja foi aplicado? -------- */
/* O cenario que quebra a base nao e exotico: a importacao grava centenas de
   contatos em POSTs sequenciais, o fetch do navegador estoura o timeout de
   10s DEPOIS de o servidor ja ter gravado, a tela mostra erro e a reacao
   natural e clicar de novo. Das 775 fichas de hoje, 615 nao tem telefone
   nenhum - essas nao casam com nada na segunda passada e entrariam
   duplicadas, sem nenhum sinal.

   Esta conferencia pega o retry disparado DEPOIS que a primeira importacao
   terminou, e vem ANTES da leitura da base porque quem esta no retry ja
   perdeu uma vez e nao ha por que ler 800 leads para so entao recusar.
   O retry disparado COM A PRIMEIRA AINDA RODANDO nao se resolve aqui - para
   esse existe a reserva do lote, mais abaixo. */
$hash   = wa_import_hash_lote($texto, $origem);
$forcar = cpost('forcar') === '1';
$lote   = ['estado' => 'ausente'];

if ($modo === 'aplicar' && !$forcar) {
    $lote = ci_lote_estado($hash);
    if ($lote === null) {
        cfail(503, 'Nao consegui conferir se este arquivo ja foi importado. Nada foi gravado. Tente de novo em instantes.');
    }
    if ($lote['estado'] === 'concluido') {
        cfail(409, 'Este arquivo ja foi importado nesta origem. Se quiser importar mesmo assim, marque "importar novamente".');
    }
    if ($lote['estado'] === 'andamento') {
        cfail(409, 'Uma importacao deste mesmo arquivo comecou ha pouco e pode ainda estar rodando. Espere ela terminar e confira a base antes de tentar de novo.');
    }
    // 'orfao' segue adiante: a tentativa anterior morreu no meio e a cliente
    // tem direito de tentar de novo sem precisar do forcar.
}
/* O preview NUNCA passa por aqui de proposito: ver de novo o que o arquivo
   faria nao escreve nada, entao recusar seria so atrapalhar. */

$contatos = $tipo === 'csv' ? wa_csv_parse($texto) : wa_vcard_parse($texto);
if (!$contatos) {
    cfail(422, 'Nenhum contato reconhecido no arquivo. Confira se o tipo (' . $tipo . ') esta certo.');
}

$cands = [];
foreach ($contatos as $ct) {
    $c = wa_import_candidato($ct, $origem);
    if ($c) $cands[] = $c;                 // agenda sem o marcador "PO" fica de fora
}
$cands = wa_import_dedup($cands);
if (!$cands) {
    cfail(422, 'Nenhum contato elegivel: na agenda, so entram os marcados com "PO" no nome.');
}

/* -------- base atual, INTEIRA -------- */
/* Ler a base pela metade e o pior desfecho possivel aqui: quem nao veio no
   select nao casa com nada e vira lead NOVO, duplicando a pessoa e ferindo
   a regra 3.

   Por isso a leitura e wa_db_select_ESTRITO: o wa_db_select comum devolve []
   tanto para "acabou" quanto para "o Supabase recusou", e as duas coisas sao
   indistinguiveis justo quando importam mais. Com a base abaixo de 1.000
   leads (a de hoje), um unico 5xx na PRIMEIRA pagina daria base vazia e todo
   contato do arquivo entraria como novo, em silencio, com a tela dizendo
   sucesso. O free tier e compartilhado e pode ate estar pausado: 5xx no meio
   de um import nao e hipotese remota. Erro aqui aborta antes de escrever. */
$base = []; $off = 0;
$sel  = wa_import_select_base() . '&order=id.asc&limit=' . CI_PAGINA;
for ($i = 0; $i < 100; $i++) {      // teto de 100 mil leads; a base tem ~800
    $pg = wa_db_select_estrito('po_leads', $sel . '&offset=' . $off);
    if ($pg === null) {
        cfail(503, 'Nao foi possivel ler a base de leads. Nada foi importado. Tente de novo em instantes.');
    }
    if (!$pg) break;                // pagina vazia: chegou ao fim de verdade
    foreach ($pg as $r) $base[] = wa_import_base_row($r);
    $off += count($pg);
}

$plano = wa_import_audita($cands, $base);

/* Itens do preview: sem valor de campo, so a chave. A tela precisa mostrar
   O QUE seria preenchido, nao repetir o dado pessoal na resposta. */
$itens = [];
foreach ($plano as $p) {
    $itens[] = [
        'nome'      => $p['nome'],
        'acao'      => $p['acao'],
        'match_id'  => $p['match_id'],
        'match_por' => $p['match_por'],
        'preenche'  => array_keys(is_array($p['preenche'] ?? null) ? $p['preenche'] : []),
    ];
}
$resumo = wa_import_resumo($plano);

if ($modo === 'preview') {
    echo json_encode(['ok' => true, 'resumo' => $resumo, 'itens' => $itens], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============================================================
   FASE 1 DO REGISTRO: RESERVA a linha do lote ANTES de escrever lead nenhum.

   Registrar so no fim (o desenho anterior) fecha apenas o retry disparado
   depois que tudo terminou, que e o caso facil. O caso que o timeout produz
   e o outro: o fetch estoura DURANTE a escrita - o proxy do host corta a
   conexao e o PHP nem percebe, porque so responde no fim -, o botao volta a
   ficar clicavel e a segunda requisicao consulta a po_import_lotes ANTES de
   a primeira registrar o lote. As duas passam pela guarda e a base duplica.

   O unique em 'hash' e o lock: das duas requisicoes concorrentes, so uma
   consegue inserir. A outra para aqui, com 409, sem escrever lead nenhum.
   A linha so ganha concluido_at na fase 2, entao uma importacao que morrer
   no meio deixa linha incompleta - que depois do prazo vira orfa e libera o
   retry, preservando a intencao do desenho original. */
$arquivoNome = substr((string) ($_FILES['arquivo']['name'] ?? ''), 0, 120);

if ($lote['estado'] === 'orfao') {
    /* Orfa: o insert bateria no unique da linha que ficou para tras. Aqui a
       reserva e reivindicar a linha existente, renovando o relogio para que
       uma terceira requisicao veja 'andamento' enquanto esta escreve. */
    if (!wa_db_update('po_import_lotes', 'hash=eq.' . rawurlencode($hash), [
            'origem'       => $origem,
            'arquivo'      => $arquivoNome,
            'total'        => $resumo['total'] ?? 0,
            'novos'        => 0,
            'preenchidos'  => 0,
            'concluido_at' => null,
            'created_at'   => ci_agora(),
        ])) {
        cfail(503, 'Nao consegui reservar o registro desta importacao. Nada foi gravado.');
    }
} else {
    /* Sem $ignora_conflito de proposito: aqui o 409 do unique NAO e rotina,
       e o sinal de que outra requisicao ganhou a corrida. */
    $reserva = wa_db_insert('po_import_lotes', [
        'hash'        => $hash,
        'origem'      => $origem,
        'arquivo'     => $arquivoNome,   // so o nome; o conteudo tem CPF e telefone
        'total'       => $resumo['total'] ?? 0,
        'novos'       => 0,              // a contagem real entra na fase 2
        'preenchidos' => 0,
    ]);
    if ($reserva === null) {
        /* null e ambiguo: conflito no unique (alguem chegou primeiro, ou -
           com forcar - a linha da importacao anterior) ou falha de verdade
           do banco. Quem distingue os dois e reler o estado. */
        $dep = ci_lote_estado($hash);
        if ($dep === null || $dep['estado'] === 'ausente') {
            cfail(503, 'Nao consegui reservar o registro desta importacao. Nada foi gravado.');
        }
        if (!$forcar) {
            cfail(409, 'Uma importacao deste mesmo arquivo comecou ha pouco e pode ainda estar rodando. Espere ela terminar e confira a base antes de tentar de novo.');
        }
        // Com forcar, a linha que conflitou e a da importacao anterior: a
        // fase 2 fecha em cima dela, com a contagem nova.
    }
}

$res = wa_import_aplica(
    $plano,
    fn($linha) => wa_db_insert('po_leads', $linha),
    fn($id, $campos) => wa_db_update('po_leads', 'id=eq.' . rawurlencode((string) $id), $campos)
);

/* FASE 2: fecha o registro com a contagem real. Se este update nao passar, os
   leads ja estao gravados e a linha fica incompleta - depois do prazo ela
   vira orfa e libera um retry que duplicaria a base. Por isso o
   'lote_registrado' vai no JSON: a tela precisa poder avisar, em vez de o
   unico rastro ser um error_log que ninguem le. */
$fechou = wa_db_update('po_import_lotes', 'hash=eq.' . rawurlencode($hash), [
    'total'        => $resumo['total'] ?? 0,
    'novos'        => $res['novos'] ?? 0,
    'preenchidos'  => $res['preenchidos'] ?? 0,
    'concluido_at' => ci_agora(),
]);

echo json_encode([
    'ok'              => true,
    'resumo'          => $resumo,
    'aplicado'        => $res,
    'lote_registrado' => $fechou === true,
], JSON_UNESCAPED_UNICODE);
