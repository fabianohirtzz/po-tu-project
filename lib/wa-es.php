<?php
/* ============================================================
   Embedded Signup da Meta: o UNICO caminho documentado para ligar
   o numero em modo de CONVIVENCIA (o app no celular da atendente e a
   Cloud API no mesmo numero, ao mesmo tempo).

   Nao existe botao de convivencia no WhatsApp Manager. O que existe la
   e o fluxo normal, que TIRA o numero do celular. A diferenca entre os
   dois e um unico parametro: extras.featureType.

   Por isso o featureType mora aqui, numa funcao, e nao escrito a mao no
   JavaScript da pagina: assim o teste pode trava-lo. Perder essa palavra
   nao da erro nenhum na tela - abre o fluxo errado, e o numero da cliente
   sai do aplicativo dela de forma que nao se desfaz.
============================================================ */

require_once __DIR__ . '/wa-config.php';

/* Mesmo piso do WA_CRON_KEY: segredo vazio ou curto demais nunca
   autoriza. hash_equals('','') devolve true, e esta pagina fica no
   docroot de um site publico. */
const WA_ES_KEY_MIN = 16;

function wa_es_autorizado($chave_configurada, $chave_recebida) {
    $chave_configurada = (string) $chave_configurada;
    if (strlen($chave_configurada) < WA_ES_KEY_MIN) {
        error_log('wa-es: chamado sem WA_ES_KEY configurada (ou chave curta demais)');
        return false;
    }
    return hash_equals($chave_configurada, (string) $chave_recebida);
}

/* O que ainda falta no config.local.php do servidor. Devolve lista vazia
   quando esta tudo pronto. A pagina usa isto para dizer o que fazer em vez
   de abrir um fluxo que vai falhar no popup da Meta, onde a mensagem de erro
   e generica e nao diz qual campo faltou. */
function wa_es_faltando($cfg) {
    $falta = [];
    if (trim((string) ($cfg['WA_ES_APP_ID'] ?? '')) === '') {
        $falta['WA_ES_APP_ID'] = 'o ID do app da Meta (Configuracoes > Basico)';
    }
    if (trim((string) ($cfg['WA_ES_CONFIG_ID'] ?? '')) === '') {
        $falta['WA_ES_CONFIG_ID'] = 'o ID da configuracao v4 do Login do Facebook para Empresas';
    }
    if (strlen((string) ($cfg['WA_ES_KEY'] ?? '')) < WA_ES_KEY_MIN) {
        $falta['WA_ES_KEY'] = 'uma chave de pelo menos ' . WA_ES_KEY_MIN . ' caracteres para proteger esta pagina';
    }
    return $falta;
}

/* O extras do FB.login.

   featureType = whatsapp_business_app_onboarding e a convivencia. O valor
   antigo 'coexistence' foi invalidado pela Meta em 29/05/2025.

   NAO leva sessionInfoVersion: ele pertence as versoes 2 e 3 do Embedded
   Signup, que a Meta desliga em 15/10/2026. Na v4 quem decide o fluxo e a
   configuracao do Login, nao um numero de versao mandado pelo navegador. */
function wa_es_extras() {
    return [
        'setup'       => (object) [],
        'featureType' => 'whatsapp_business_app_onboarding',
    ];
}

/* Valor de PHP virando literal de JavaScript. As flags HEX fecham o
   </script> e as aspas: capa_url e nome de roteiro vindos do painel ja
   escaparam de um atributo neste projeto uma vez. */
function wa_es_js($v) {
    return json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
}
