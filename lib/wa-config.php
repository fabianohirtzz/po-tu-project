<?php
/* ============================================================
   Segredos do WhatsApp e a service_role do Supabase.

   Separado do po_config() de proposito: aquele arquivo e carregado no
   render publico das paginas de roteiro, e o proprio cabecalho dele diz
   que a service_role nao pode aparecer la.
============================================================ */

require_once __DIR__ . '/po-data.php';   // ja carrega o config.local.php

function wa_config() {
    $defaults = [
        'SUPABASE_SERVICE_KEY' => '',
        'WA_TOKEN'             => '',
        'WA_PHONE_ID'          => '',
        'WA_APP_SECRET'        => '',
        'WA_VERIFY_TOKEN'      => '',
        'WA_CRON_KEY'          => '',
        // Embedded Signup (conectar-numero.php). O APP_ID e o CONFIG_ID nao sao
        // segredo - vao no HTML, a Meta os le do navegador -, mas moram aqui
        // junto do resto para o deploy nao precisar de uma segunda rodada de FTP
        // so para preencher dois numeros. A WA_ES_KEY e segredo.
        'WA_ES_APP_ID'         => '',
        'WA_ES_CONFIG_ID'      => '',
        'WA_ES_KEY'            => '',
        // Conta do WhatsApp Business. So existe depois do numero conectado;
        // sem ela wa_tpl_cria recusa em vez de chamar a Meta sem destino.
        'WA_WABA_ID'           => '',
        /* Numero de TESTE da Meta, usado SO pelo ensaio. Fica separado de
           WA_PHONE_ID de proposito: aquela chave vazia e o que mantem o
           campanha.php inerte, e preenche-la com o numero de teste faria uma
           campanha falhar para a base inteira de uma vez, em definitivo. */
        'WA_TEST_PHONE_ID'     => '',
    ];
    $out = po_config();
    foreach ($defaults as $k => $v) {
        $out[$k] = isset($GLOBALS[$k]) ? $GLOBALS[$k] : $v;
    }
    return $out;
}
