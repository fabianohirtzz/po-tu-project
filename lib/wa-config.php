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
    ];
    $out = po_config();
    foreach ($defaults as $k => $v) {
        $out[$k] = isset($GLOBALS[$k]) ? $GLOBALS[$k] : $v;
    }
    return $out;
}
