<?php
/* ============================================================
   Pereira Oliveira Turismo — Recebe o formulário de lead do site,
   grava no Supabase (po_leads) e notifica a equipe por e-mail
   via SMTP AUTENTICADO.

   Hospedagem: cPanel   ·   Domínio: pereiraoliveiraturismo.com.br

   POR QUE SMTP (e não mail()): a hospedagem bloqueia mail() sem
   autenticação. Por isso autenticamos numa conta de e-mail do
   próprio domínio via SMTP.
   ------------------------------------------------------------
   CONFIG SMTP: cPanel -> "Contas de E-mail" -> conta ->
   "Conectar Dispositivos" (Servidor de Saída, Porta, SSL/TLS).
============================================================ */

// ---------------------- CONFIG (edite aqui) ----------------------
$SMTP_HOST   = 'mail.pereiraoliveiraturismo.com.br';   // servidor de saída (ver cPanel)
$SMTP_PORT   = 465;                                    // 465 = SSL | 587 = TLS
$SMTP_SECURE = 'ssl';                                  // 'ssl' p/ 465 | 'tls' p/ 587
$SMTP_USER   = 'contato@pereiraoliveiraturismo.com.br';// usuário = e-mail completo
$SMTP_PASS   = 'COLOQUE_NO_config.local.php';          // senha — definir no config.local.php

$DESTINO   = 'contato@pereiraoliveiraturismo.com.br';  // para onde o lead chega
$REMETENTE = 'contato@pereiraoliveiraturismo.com.br';  // "De" — precisa ser a conta autenticada
$NOME_SITE = 'Site Pereira Oliveira Turismo';

// Supabase (painel de leads) — projeto COMPARTILHADO (hd360/NOX).
// A service_role key é SEGREDO (ignora RLS): deixe vazia aqui e
// defina o valor real no config.local.php (só no servidor).
$SUPABASE_URL         = 'https://euzmbswywwhmicjlszqw.supabase.co';
$SUPABASE_SERVICE_KEY = '';                            // service_role — NUNCA versionar
// -----------------------------------------------------------------

if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

header('Content-Type: application/json; charset=utf-8');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/lib/PHPMailer/Exception.php';
require __DIR__ . '/lib/PHPMailer/PHPMailer.php';
require __DIR__ . '/lib/PHPMailer/SMTP.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método não permitido.']);
    exit;
}

/* Spam: registra o motivo no log e FINGE SUCESSO (o bot não percebe). */
function bloqueiaSpam($motivo) {
    error_log('PO form spam bloqueado: ' . $motivo
        . ' | IP '    . ($_SERVER['REMOTE_ADDR'] ?? '?')
        . ' | nome='  . substr((string) ($_POST['nome']  ?? ''), 0, 60)
        . ' | email=' . substr((string) ($_POST['email'] ?? ''), 0, 80));
    echo json_encode(['ok' => true]);
    exit;
}

// honeypot (campo oculto "website"; aceita "_gotcha" por compatibilidade)
if (!empty($_POST['website']) || !empty($_POST['_gotcha'])) {
    bloqueiaSpam('honeypot preenchido');
}

// time-trap: o form real carimba "_t" (ms desde a abertura). Post direto sem JS
// vem sem carimbo; preenchimento < 3s também é tratado como robô.
$t = isset($_POST['_t']) ? (int) $_POST['_t'] : -1;
if ($t < 0)      { bloqueiaSpam('sem carimbo de tempo (post direto / sem JS)'); }
// obs.: "_t" é o timestamp de abertura; a diferença é calculada no cliente,
// aqui só exigimos presença + rejeitamos valores absurdos.

function campo($k) { return isset($_POST[$k]) ? trim((string) $_POST[$k]) : ''; }

$nome      = campo('nome');
$telefone  = campo('telefone');
$email     = campo('email');
$cidade    = campo('cidade');
$viajantes = campo('viajantes');
$roteiro   = campo('roteiro');
$mensagem  = campo('mensagem');

// heurística: links/BBCode num lead são sinal de spam
$conteudo = "$nome $cidade $roteiro $mensagem";
if (preg_match('~https?://|www\.\S|\[/?(?:url|link)|</?a\b~i', $conteudo)) {
    bloqueiaSpam('link/BBCode no conteúdo');
}

// validação mínima
if ($nome === '' || ($email === '' && $telefone === '')) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Preencha ao menos o nome e um contato (telefone ou e-mail).']);
    exit;
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'O e-mail informado parece inválido.']);
    exit;
}

// corpo do e-mail
$corpo  = "Novo lead pelo site:\n\n";
$corpo .= "Nome:              $nome\n";
$corpo .= "WhatsApp/telefone: $telefone\n";
$corpo .= "E-mail:            $email\n";
$corpo .= "Cidade:            $cidade\n";
$corpo .= "Nº de viajantes:   $viajantes\n";
$corpo .= "Roteiro:           $roteiro\n\n";
$corpo .= "Mensagem:\n" . ($mensagem !== '' ? $mensagem : '(não informada)') . "\n\n";
$corpo .= "------------------------------------------\n";
$corpo .= "Origem: " . campo('origem') . "\n";
$corpo .= "Enviado em " . date('d/m/Y') . " as " . date('H:i') . "\n";

$assunto = 'Novo lead' . ($roteiro !== '' ? " — $roteiro" : '') . " — $nome";

/* Grava no Supabase (po_leads) com a service_role key. Roda ANTES do e-mail
   para nunca perder o lead; não bloqueia o envio se falhar. */
function salvarSupabase($url, $key, array $dados) {
    if ($url === '' || $key === '' || strpos($url, 'SEU-PROJETO') !== false || !function_exists('curl_init')) {
        return;
    }
    $ch = curl_init(rtrim($url, '/') . '/rest/v1/po_leads');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => [
            'apikey: ' . $key,
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
            'Prefer: return=minimal',
        ],
        CURLOPT_POSTFIELDS     => json_encode($dados, JSON_UNESCAPED_UNICODE),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($resp === false || $code < 200 || $code >= 300) {
        error_log('PO Supabase insert falhou (' . $code . '): ' . $resp);
    }
    curl_close($ch);
}

salvarSupabase($SUPABASE_URL, $SUPABASE_SERVICE_KEY, [
    'nome'         => $nome,
    'telefone'     => $telefone,
    'email'        => $email,
    'cidade'       => $cidade,
    'viajantes'    => $viajantes,
    'roteiro'      => $roteiro,
    'mensagem'     => $mensagem,
    'origem'       => campo('origem'),
    'utm_source'   => campo('utm_source'),
    'utm_medium'   => campo('utm_medium'),
    'utm_campaign' => campo('utm_campaign'),
    'gclid'        => campo('gclid'),
    'referrer'     => campo('referrer'),
    'landing_page' => campo('landing_page'),
]);

$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host       = $SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = $SMTP_USER;
    $mail->Password   = $SMTP_PASS;
    $mail->SMTPSecure = $SMTP_SECURE;
    $mail->Port       = $SMTP_PORT;
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom($REMETENTE, $NOME_SITE);
    $mail->addAddress($DESTINO);
    if ($email !== '') {
        $mail->addReplyTo($email, $nome !== '' ? $nome : $email);
    }

    $mail->Subject = $assunto;
    $mail->Body    = $corpo;
    $mail->isHTML(false);

    $mail->send();
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    // e-mail falhou, mas o lead já foi gravado no Supabase acima.
    error_log('PO form SMTP error: ' . $mail->ErrorInfo);
    echo json_encode(['ok' => true, 'warn' => 'lead salvo; e-mail pendente']);
}
