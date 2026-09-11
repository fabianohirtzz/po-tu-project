<?php
require __DIR__ . '/../lib/po-data.php';
require __DIR__ . '/../lib/wa-config.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

$cfg = po_config();
$base = rtrim($cfg['SUPABASE_URL'], '/') . '/rest/v1/';

/* Pergunta ao PostgREST se a tabela responde. 200 = existe e tem RLS
   coerente; 404 = a migration nao rodou. Usa limit=0 para nao trafegar
   dado nenhum. A anon key basta aqui: um 404 de tabela inexistente e
   um 200 vazio (por RLS) sao distinguiveis sem precisar ver linhas. */
function tabela_existe($base, $tabela) {
    $body = po_http_get($base . $tabela . '?select=*&limit=0', 0);
    return $body !== null;
}

foreach (['po_wa_contatos','po_wa_conversas','po_wa_mensagens','po_wa_anuncios','po_wa_textos'] as $t) {
    ok(tabela_existe($base, $t), "tabela $t existe (rodou a migration?)");
}

// As colunas novas de po_leads: pedir a coluna por nome devolve 400 se nao existir.
foreach (['wa_id','ctwa_clid','ad_id','qualif_data','qualif_grupo','qualif_at','proposta_at'] as $c) {
    ok(po_http_get($base . 'po_leads?select=' . $c . '&limit=0', 0) !== null, "po_leads.$c existe");
}

/* Os textos iniciais precisam estar la, senao o robo manda mensagem vazia.
   NAO da pra conferir isso com a anon key: a RLS da migration e "to
   authenticated", entao um GET anonimo nao da erro, devolve [] - o teste
   passaria a falsa impressao de que os textos nao foram inseridos mesmo
   com a migration correta. Le com a service_role (que ignora RLS), via
   curl direto, so para este trecho. */
function wa_http_get_service($url) {
    $wcfg = wa_config();
    if (!function_exists('curl_init') || strlen($wcfg['SUPABASE_SERVICE_KEY']) < 20) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => [
            'apikey: ' . $wcfg['SUPABASE_SERVICE_KEY'],
            'Authorization: Bearer ' . $wcfg['SUPABASE_SERVICE_KEY'],
            'Accept: application/json',
        ],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300) return null;
    return $body;
}

$txt = json_decode(wa_http_get_service($base . 'po_wa_textos?select=chave') ?: '[]', true);
$chaves = array_column($txt ?: [], 'chave');
foreach (['saudacao','envio_pdf','perguntas','qualificado','menu','lembrete','lembrete_menu','sem_data'] as $k) {
    ok(in_array($k, $chaves, true), "texto inicial '$k' foi inserido");
}

echo "test-wa-schema OK\n";
