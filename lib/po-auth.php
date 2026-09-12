<?php
/* ============================================================
   Login do painel (Supabase Auth) para os endpoints PHP.

   Extraido de importar.php, que era o original: a mesma dupla de funcoes
   ja estava copiada em upload-video.php e upload-pdf.php, e o importador
   de contatos seria a quarta copia. Copia de rotina de autorizacao e o
   tipo de duplicacao que envelhece torto - o dia em que uma delas ganhar
   um remendo de seguranca, as outras ficam para tras em silencio.

   A URL e a ANON KEY do Supabase sao PUBLICAS (o painel as usa no
   navegador); quem autoriza e o access_token do usuario logado, que o
   Supabase valida em /auth/v1/user. Nada aqui toca a service_role.

   O verificador e injetavel (po_auth_set_verificador) para o teste rodar
   sem rede - mesmo padrao do wa_db_set_transport.
============================================================ */

/* Substitui a checagem de token por uma funcao propria (so testes). */
function po_auth_set_verificador($f) { $GLOBALS['PO_AUTH_VERIFICADOR'] = $f; }

/* Le o access_token do POST (o painel manda em sb_token) ou do cabecalho
   Authorization. O REDIRECT_ prefixado existe porque em cPanel com
   mod_rewrite o Apache reescreve o cabecalho com esse prefixo. */
function po_auth_token() {
    if (isset($_POST['sb_token']) && is_string($_POST['sb_token']) && $_POST['sb_token'] !== '') {
        return trim($_POST['sb_token']);
    }
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (!is_string($h)) $h = '';
    if ($h === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strtolower($k) === 'authorization') { $h = (string) $v; break; }
        }
    }
    return preg_match('/Bearer\s+(.+)/i', $h, $m) ? trim($m[1]) : '';
}

/* Token vazio (ou nao-string, ou so espaco) NUNCA autoriza, e a checagem
   vem ANTES do verificador: e a mesma regra do wa-config, onde segredo
   vazio comparado com segredo vazio dava 'true' e abria o webhook. */
function po_auth_usuario_valido($url, $anon, $token) {
    if (!is_string($token) || trim($token) === '') return false;

    if (!empty($GLOBALS['PO_AUTH_VERIFICADOR'])) {
        return (bool) call_user_func($GLOBALS['PO_AUTH_VERIFICADOR'], $url, $anon, $token);
    }
    // Sem curl nao ha como validar o login. Negar e a unica saida segura
    // (o original estourava fatal aqui; 401 e melhor que pagina em branco).
    if (!function_exists('curl_init')) {
        error_log('po_auth_usuario_valido sem curl disponivel');
        return false;
    }
    $ch = curl_init(rtrim((string) $url, '/') . '/auth/v1/user');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'apikey: ' . $anon,
            'Authorization: Bearer ' . $token,
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $code < 200 || $code >= 300) return false;
    $u = json_decode($resp, true);
    return is_array($u) && !empty($u['id']);
}

/* Atalho do endpoint: le o token da requisicao e valida. */
function po_auth_ok($url, $anon, $token = null) {
    return po_auth_usuario_valido($url, $anon, $token === null ? po_auth_token() : $token);
}
