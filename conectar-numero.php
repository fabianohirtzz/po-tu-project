<?php
/* ============================================================
   Pagina de uso UNICO: liga o numero da agencia a Cloud API em modo de
   CONVIVENCIA, pelo Embedded Signup da Meta.

     conectar-numero.php?k=<WA_ES_KEY>

   Por que isto existe: a convivencia nao tem tela no WhatsApp Manager. O
   unico caminho documentado pela Meta e o popup do Embedded Signup, e ele
   so abre a partir de uma pagina com o SDK do Facebook em dominio nosso,
   cadastrado no app. Daqui saem os dois numeros que o sistema precisa:
   o phone_number_id (vira WA_PHONE_ID) e o waba_id.

   APAGAR DO SERVIDOR depois de conectar. Ela nao faz mal nenhum parada,
   mas tambem nao serve mais para nada: a convivencia e uma vez so.
============================================================ */

require_once __DIR__ . '/lib/wa-es.php';

$cfg = wa_config();

/* Chave antes de qualquer coisa, inclusive antes de dizer o que falta
   configurar: sem isto a pagina contaria a estranhos em que pe esta a
   instalacao. Chave vazia ou curta demais nunca autoriza. */
if (!wa_es_autorizado($cfg['WA_ES_KEY'] ?? '', $_GET['k'] ?? '')) {
    http_response_code(403);
    exit;
}

$falta  = wa_es_faltando($cfg);
$pronto = empty($falta);

header('Content-Type: text/html; charset=utf-8');
/* Pagina interna de uso unico: fora de buscador e fora de cache. */
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Conectar numero em convivencia</title>
<style>
  :root { --ink:#2C2B2B; --cinza:#7A7A7A; --azul:#1FA8DD; --verde:#84C440; --papel:#F9F9F9; }
  * { box-sizing:border-box; }
  body { margin:0; padding:32px 20px 64px; background:var(--papel); color:var(--ink);
         font:16px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif; }
  main { max-width:680px; margin:0 auto; }
  h1 { font-size:24px; line-height:1.25; margin:0 0 4px; }
  .sub { color:var(--cinza); margin:0 0 28px; }
  .cartao { background:#fff; border:1px solid #e6e6e6; border-radius:12px; padding:20px 22px; margin:0 0 18px; }
  .cartao h2 { font-size:15px; text-transform:uppercase; letter-spacing:.06em; margin:0 0 12px; color:var(--cinza); }
  ol, ul { margin:0; padding-left:22px; }
  li { margin:0 0 8px; }
  b.alerta { color:#B3261E; }
  button { font:600 16px/1 inherit; color:#fff; background:var(--azul); border:0;
           border-radius:999px; padding:16px 28px; cursor:pointer; }
  button[disabled] { background:#c7c7c7; cursor:not-allowed; }
  code { background:#f1f1f1; border-radius:4px; padding:1px 5px; font-size:14px; }
  textarea { width:100%; min-height:150px; font:13px/1.5 ui-monospace, Consolas, monospace;
             border:1px solid #d9d9d9; border-radius:8px; padding:12px; resize:vertical; }
  #saida { display:none; }
  #saida.tem { display:block; border-color:var(--verde); }
</style>
</head>
<body>
<main>

<h1>Conectar o numero em convivencia</h1>
<p class="sub">Pereira Oliveira Turismo. O numero continua funcionando no celular.</p>

<?php if (!$pronto) { ?>
<div class="cartao">
  <h2>Falta configurar no servidor</h2>
  <p>Acrescente no <code>config.local.php</code> e recarregue esta pagina:</p>
  <ul>
    <?php foreach ($falta as $chave => $porque) { ?>
      <li><code>$<?= htmlspecialchars($chave, ENT_QUOTES, 'UTF-8') ?></code>:
          <?= htmlspecialchars($porque, ENT_QUOTES, 'UTF-8') ?></li>
    <?php } ?>
  </ul>
</div>
<?php } ?>

<div class="cartao">
  <h2>Antes de clicar</h2>
  <ol>
    <li>A atendente precisa estar com o celular na mao, agora.</li>
    <li>No celular: <b>WhatsApp Business</b>, versao <b>2.24.17</b> ou maior, com backup feito.</li>
    <li>Depois de comecar, ha <b>24 horas</b> para concluir a sincronizacao.</li>
    <li>Na primeira tela da Meta, escolha a opcao que diz que o numero
        <b>ja esta em uso no app do WhatsApp Business</b>.</li>
    <li><b class="alerta">Pare</b> se alguma tela avisar que o numero sera
        removido, transferido ou desconectado do aplicativo. Isso e migracao,
        nao convivencia, e nao se desfaz.</li>
  </ol>
</div>

<p><button id="btn" <?= $pronto ? '' : 'disabled' ?>>Abrir o fluxo da Meta</button></p>

<div class="cartao" id="saida">
  <h2>Copie isto e mande para o Claude</h2>
  <textarea id="dados" readonly></textarea>
</div>

<script>
(function () {
  var APP_ID    = <?= wa_es_js((string) ($cfg['WA_ES_APP_ID'] ?? '')) ?>;
  var CONFIG_ID = <?= wa_es_js((string) ($cfg['WA_ES_CONFIG_ID'] ?? '')) ?>;
  /* O extras vem do PHP (wa_es_extras), nao escrito a mao aqui: e o
     featureType dentro dele que separa convivencia de migracao, e um teste
     de fonte o trava. Ver lib/wa-es.php. */
  var EXTRAS    = <?= wa_es_js(wa_es_extras()) ?>;

  var colhido = {};
  var saida = document.getElementById('saida');
  var area  = document.getElementById('dados');

  function mostra(rotulo, valor) {
    colhido[rotulo] = valor;
    area.value = Object.keys(colhido).map(function (k) {
      return k + ': ' + colhido[k];
    }).join('\n');
    saida.className = 'cartao tem';
  }

  /* Origem conferida com PONTO antes de facebook.com de proposito. O
     exemplo da propria Meta compara so o fim do texto, e um dominio como
     naofacebook.com satisfaz aquela comparacao. */
  function daMeta(origem) {
    return origem === 'https://facebook.com' ||
           /^https:\/\/([a-z0-9-]+\.)*facebook\.com$/.test(origem);
  }

  /* O phone_number_id chega por AQUI, nao pelo retorno do FB.login. Sem
     este ouvinte o fluxo conecta e o numero que o sistema precisa se perde
     junto com a janela. */
  window.addEventListener('message', function (ev) {
    if (!daMeta(ev.origin)) return;
    var d;
    try { d = JSON.parse(ev.data); } catch (e) { return; }
    if (!d || d.type !== 'WA_EMBEDDED_SIGNUP') return;
    if (d.data && d.data.phone_number_id) mostra('phone_number_id', d.data.phone_number_id);
    if (d.data && d.data.waba_id)         mostra('waba_id', d.data.waba_id);
    if (d.data && d.data.business_id)     mostra('business_id', d.data.business_id);
    mostra('evento', JSON.stringify(d));
  });

  window.fbAsyncInit = function () {
    FB.init({ appId: APP_ID, autoLogAppEvents: true, xfbml: false, version: 'v25.0' });
  };

  document.getElementById('btn').addEventListener('click', function () {
    if (typeof FB === 'undefined') {
      alert('O SDK do Facebook nao carregou. Recarregue a pagina.');
      return;
    }
    FB.login(function (resp) {
      if (resp && resp.authResponse && resp.authResponse.code) {
        mostra('code', resp.authResponse.code);
      } else {
        mostra('resultado', 'fluxo fechado sem concluir');
      }
    }, {
      config_id: CONFIG_ID,
      response_type: 'code',
      override_default_response_type: true,
      extras: EXTRAS
    });
  });
})();
</script>
<script async defer crossorigin="anonymous" src="https://connect.facebook.net/pt_BR/sdk.js"></script>

</main>
</body>
</html>
