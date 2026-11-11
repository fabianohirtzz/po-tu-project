<?php
/* Modelo com o PDF do roteiro no topo e o botao "Ver roteiro no site"
   (transmissao por interesse, 11/11/2026).

   Criar: a Meta exige um PDF DE EXEMPLO, subido antes pela API de upload
   retomavel (uma sessao no app, depois os bytes), e o botao de URL com a parte
   variavel no FIM e um exemplo de endereco completo.

   Enviar: cada mensagem leva o documento no cabecalho, o nome do roteiro no
   {{1}} do texto e o final do endereco no {{1}} do botao. O formato e lido do
   proprio modelo na Meta, nunca de uma escolha na tela. */
require __DIR__ . '/../lib/wa-template.php';
require_once __DIR__ . '/../lib/wa-camp-fila.php';
function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

$GLOBALS['WA_TOKEN']     = 'token-de-teste';
$GLOBALS['WA_WABA_ID']   = '555000111';
$GLOBALS['WA_ES_APP_ID'] = '777000222';

/* ---------- componentes da criacao ---------- */
$c = wa_tpl_componentes('Olá, tudo bem? Viagem {{1}}. Responda SAIR.', ['Peru Fascinante'],
    ['header_handle' => 'HANDLE1', 'botao' => WA_TPL_BOTAO_ROTEIRO,
     'botao_exemplo' => 'peru-fascinante?utm_source=whatsapp&utm_medium=transmissao']);
ok(array_column($c, 'type') === ['HEADER', 'BODY', 'BUTTONS'], 'ordem: cabecalho, texto, botoes');
ok($c[0]['format'] === 'DOCUMENT' && $c[0]['example']['header_handle'] === ['HANDLE1'], 'cabecalho de documento com o exemplo subido');
ok($c[1]['example']['body_text'] === [['Peru Fascinante']], 'o exemplo do texto segue igual');
$b = $c[2]['buttons'][0];
ok($b['type'] === 'URL' && $b['text'] === 'Ver roteiro no site', 'botao de link com o rotulo');
ok($b['url'] === 'https://pereiraoliveiraturismo.com.br/roteiros/{{1}}', 'a parte variavel e o FIM do endereco');
ok($b['example'] === ['https://pereiraoliveiraturismo.com.br/roteiros/peru-fascinante?utm_source=whatsapp&utm_medium=transmissao'],
   'exemplo do botao e o endereco completo');
ok(array_column(wa_tpl_componentes('Oi {{1}}.', ['Ana']), 'type') === ['BODY'], 'sem extra: o modelo de sempre');

/* ---------- formato lido do modelo na Meta ---------- */
$pdf = ['name' => 'po_roteiro_interesse', 'components' => [
    ['type' => 'HEADER', 'format' => 'DOCUMENT'],
    ['type' => 'BODY', 'text' => 'Viagem {{1}}.'],
    ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => 'Ver', 'url' => 'https://x/{{1}}']]]]];
$nome = ['name' => 'po_roteiro_novo', 'components' => [['type' => 'BODY', 'text' => 'Oi {{1}}.']]];
ok(wa_tpl_formato($pdf) === 'roteiro_pdf', 'documento no topo + botao de link = roteiro_pdf');
ok(wa_tpl_formato($nome) === 'nome', 'so texto = o formato de sempre (nome)');
ok(wa_tpl_formato(['components' => [['type' => 'HEADER', 'format' => 'DOCUMENT'], ['type' => 'BODY', 'text' => 'x {{1}}']]]) === null,
   'documento sem botao e formato que o envio nao sabe montar: null');
ok(wa_tpl_vars_corpo($pdf) === 1 && wa_tpl_vars_corpo($nome) === 1, 'conta as variaveis do texto do modelo');

/* ---------- criacao com o PDF, transporte falso ---------- */
$CHAMADAS = [];
$FALHA = '';
wa_tpl_set_transport(function ($url, $payload, $headers) use (&$CHAMADAS, &$FALHA) {
    $CHAMADAS[] = ['url' => $url, 'payload' => $payload, 'headers' => $headers];
    if (strpos($url, '/docs/') !== false) return ['status' => 200, 'body' => '%PDF-1.4 exemplo'];
    if (strpos($url, '/uploads?') !== false) {
        if ($FALHA === 'sessao') return ['status' => 400, 'body' => '{"error":{"message":"sem permissao"}}'];
        return ['status' => 200, 'body' => '{"id":"upload:SESSAO1"}'];
    }
    if (strpos($url, 'upload:SESSAO1') !== false) return ['status' => 200, 'body' => '{"h":"HANDLE-REAL"}'];
    if (strpos($url, '/message_templates') !== false) return ['status' => 200, 'body' => '{"id":"T1","status":"PENDING"}'];
    return null;
});
$corpo = 'Olá, tudo bem? Você demonstrou interesse na viagem {{1}}. Responda SAIR.';
$r = wa_tpl_cria('po_roteiro_interesse', 'MARKETING', $corpo, ['Peru Fascinante'], 'pt_BR',
    ['formato' => 'roteiro_pdf', 'pdf_exemplo' => 'https://pereiraoliveiraturismo.com.br/docs/peru-fascinante-40ebac8a.pdf',
     'slug_exemplo' => 'peru-fascinante']);
ok($r['ok'] === true && $r['id'] === 'T1', 'cria o modelo com PDF (deu: ' . json_encode($r) . ')');
$sess = $CHAMADAS[1];
ok(strpos($sess['url'], '777000222/uploads?') !== false && strpos($sess['url'], 'file_type=application%2Fpdf') !== false
   && strpos($sess['url'], 'file_length=16') !== false, 'a sessao de upload e aberta no app, com tipo e tamanho');
$up = $CHAMADAS[2];
ok(in_array('Authorization: OAuth token-de-teste', $up['headers'], true) && in_array('file_offset: 0', $up['headers'], true),
   'os bytes sobem com OAuth e file_offset 0');
ok($up['payload'] === '%PDF-1.4 exemplo', 'sobem os bytes do PDF de exemplo');
$tpl = json_decode($CHAMADAS[3]['payload'], true);
ok($tpl['components'][0]['example']['header_handle'] === ['HANDLE-REAL'], 'o modelo usa o handle que a Meta devolveu');
ok($tpl['components'][2]['buttons'][0]['example'][0] === 'https://pereiraoliveiraturismo.com.br/roteiros/peru-fascinante?utm_source=whatsapp&utm_medium=transmissao',
   'exemplo do botao com a marcacao de origem');

// upload que falha: NAO submete modelo nenhum (modelo nao se apaga na Meta)
$CHAMADAS = []; $FALHA = 'sessao';
$r = wa_tpl_cria('po_roteiro_interesse', 'MARKETING', $corpo, ['Peru Fascinante'], 'pt_BR',
    ['formato' => 'roteiro_pdf', 'pdf_exemplo' => 'https://pereiraoliveiraturismo.com.br/docs/x.pdf', 'slug_exemplo' => 'x']);
ok($r['ok'] === false && strpos($r['erro'], 'PDF') !== false, 'upload recusado vira erro claro');
foreach ($CHAMADAS as $ch) ok(strpos($ch['url'], 'message_templates') === false, 'e nenhum modelo e submetido');

// sem app id configurado: recusa antes de qualquer chamada
$CHAMADAS = []; $FALHA = ''; $GLOBALS['WA_ES_APP_ID'] = '';
$r = wa_tpl_cria('po_roteiro_interesse', 'MARKETING', $corpo, ['Peru Fascinante'], 'pt_BR',
    ['formato' => 'roteiro_pdf', 'pdf_exemplo' => 'https://pereiraoliveiraturismo.com.br/docs/x.pdf', 'slug_exemplo' => 'x']);
ok($r['ok'] === false && $CHAMADAS === [], 'sem o id do app nao tenta subir nada');
$GLOBALS['WA_ES_APP_ID'] = '777000222';

/* ---------- componentes do ENVIO ---------- */
$env = wa_camp_envio_roteiro(['slug' => 'peru-fascinante', 'titulo' => 'Peru Fascinante',
    'pdf_url' => 'https://pereiraoliveiraturismo.com.br/docs/peru-fascinante-40ebac8a.pdf']);
ok($env['formato'] === 'roteiro_pdf' && $env['roteiro'] === 'Peru Fascinante', 'o envio grava o nome do roteiro');
ok($env['sufixo'] === 'peru-fascinante?utm_source=whatsapp&utm_medium=transmissao&utm_campaign=peru-fascinante', 'final do endereco com a origem');
ok(wa_camp_envio_roteiro(['slug' => 'x', 'titulo' => 'X', 'pdf_url' => '']) === null, 'roteiro sem PDF nao serve para este modelo');

$comp = wa_camp_componentes($env, 'Maria');
ok($comp[0] === ['type' => 'header', 'parameters' => [['type' => 'document', 'document' =>
    ['link' => 'https://pereiraoliveiraturismo.com.br/docs/peru-fascinante-40ebac8a.pdf', 'filename' => 'Roteiro Peru Fascinante.pdf']]]],
   'cabecalho com o PDF e um nome de arquivo legivel');
ok($comp[1] === ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Peru Fascinante']]], '{{1}} do texto = o roteiro, NAO o nome da pessoa');
ok($comp[2] === ['type' => 'button', 'sub_type' => 'url', 'index' => '0',
    'parameters' => [['type' => 'text', 'text' => $env['sufixo']]]], 'o botao recebe o final do endereco');
ok(wa_camp_componentes(['formato' => 'nome'], 'Maria') === [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Maria']]]],
   'o formato de sempre segue mandando o nome');
ok(wa_camp_componentes(null, 'Maria') === wa_camp_componentes(['formato' => 'nome'], 'Maria'), 'campanha antiga, sem envio gravado = nome');

// wa_send_template manda os componentes prontos
$ENV = null;
wa_set_transport(function ($url, $payload, $h) use (&$ENV) { $ENV = json_decode($payload, true); return ['status' => 200, 'body' => '{"messages":[{"id":"wamid.X"}]}']; });
$GLOBALS['WA_PHONE_ID'] = '111222333444555';
$s = wa_send_template('5548999990000', 'po_roteiro_interesse', [], 'pt_BR', null, $comp);
ok($s['ok'] && $ENV['template']['components'] === $comp, 'os componentes vao como vieram');

echo "test-wa-template-pdf OK\n";
