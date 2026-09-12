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

   A conferencia vem ANTES da leitura da base: quem esta no retry ja perdeu
   uma vez, e nao ha por que ler 800 leads para depois recusar. */
$hash   = wa_import_hash_lote($texto, $origem);
$forcar = cpost('forcar') === '1';

if ($modo === 'aplicar' && !$forcar) {
    /* Leitura ESTRITA, pelo mesmo motivo da base: o wa_db_select comum
       devolve [] tanto para "nao achei" quanto para "o Supabase recusou".
       Tratar erro como "nao achei" aqui e concluir que o lote e novo com o
       banco fora do ar - exatamente a duplicacao que esta guarda impede. */
    $ja = wa_db_select_estrito('po_import_lotes',
        'select=id,created_at&hash=eq.' . rawurlencode($hash) . '&limit=1');
    if ($ja === null) {
        cfail(503, 'Nao consegui conferir se este arquivo ja foi importado. Nada foi gravado. Tente de novo em instantes.');
    }
    if (!empty($ja)) {
        cfail(409, 'Este arquivo ja foi importado nesta origem. Se quiser importar mesmo assim, marque "importar novamente".');
    }
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

$res = wa_import_aplica(
    $plano,
    fn($linha) => wa_db_insert('po_leads', $linha),
    fn($id, $campos) => wa_db_update('po_leads', 'id=eq.' . rawurlencode((string) $id), $campos)
);

/* Grava o lote DEPOIS da escrita: se a importacao morreu no meio, o lote nao
   fica registrado e a cliente consegue tentar de novo sem precisar do forcar.
   Registrar antes trocaria o problema de lado - a base ficaria pela metade e
   a segunda tentativa levaria 409.

   Sem o conteudo do arquivo, so o hash dele: nome, telefone e CPF de cliente
   nao tem por que morar numa tabela de log. $ignora_conflito=true porque o
   unique em 'hash' E a idempotencia: dois cliques simultaneos fazem o
   segundo insert bater no unique, e isso e o comportamento esperado, nao
   erro que deva derrubar uma importacao que ja escreveu. */
wa_db_insert('po_import_lotes', [
    'hash'        => $hash,
    'origem'      => $origem,
    'arquivo'     => substr((string) ($_FILES['arquivo']['name'] ?? ''), 0, 120),
    'total'       => $resumo['total'] ?? 0,
    'novos'       => $res['novos'] ?? 0,
    'preenchidos' => $res['preenchidos'] ?? 0,
], true);

echo json_encode(['ok' => true, 'resumo' => $resumo, 'aplicado' => $res], JSON_UNESCAPED_UNICODE);
