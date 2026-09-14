<?php
require __DIR__ . '/../lib/wa-camp.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

/* ---------- quem entra e quem fica de fora ----------
   Defesa 1 da spec 8.1: "contato nao revisado nunca entra em campanha".
   Defesa 3: quem respondeu SAIR e excluido de toda campanha futura, sem
   excecao - por isso opt_out e a PRIMEIRA checagem, antes ate de cliente:
   alguem que saiu e depois foi marcado como nao-cliente tem que aparecer no
   balde 'saiu', que e o que a cliente olha para conferir a defesa. */
$base = ['cliente'=>true, 'revisado'=>true, 'opt_out_at'=>null,
         'telefone'=>'+5548999990001', 'wa_id'=>null, 'id'=>'L1', 'nome'=>'Teste Um'];

ok(wa_camp_motivo_fora($base) === null, 'cliente revisado com celular entra');

$x = $base; $x['opt_out_at'] = '2026-09-14T10:00:00Z';
ok(wa_camp_motivo_fora($x) === 'saiu', 'quem pediu SAIR nunca entra');

$x = $base; $x['opt_out_at'] = '2026-09-14T10:00:00Z'; $x['cliente'] = false;
ok(wa_camp_motivo_fora($x) === 'saiu',
   'opt_out e checado ANTES de cliente, senao quem saiu some no balde errado');

$x = $base; $x['cliente'] = false;
ok(wa_camp_motivo_fora($x) === 'nao_cliente', 'nao-cliente fica de fora');

$x = $base; $x['revisado'] = false;
ok(wa_camp_motivo_fora($x) === 'nao_revisado', 'nao revisado fica de fora (defesa 1)');

$x = $base; $x['telefone'] = null;
ok(wa_camp_motivo_fora($x) === 'sem_telefone', 'sem telefone fica de fora');

/* Fixo NUNCA entra no disparo: a transmissao manda so para celular (spec 8).
   Um template mandado para fixo e cobrado e nao entrega. */
$x = $base; $x['telefone'] = '+554832220000';
ok(wa_camp_motivo_fora($x) === 'sem_celular', 'fixo fica de fora do disparo');

/* wa_id vence telefone: e o numero com que o WhatsApp JA falou. */
$x = $base; $x['wa_id'] = '+5548988880002'; $x['telefone'] = '+5548999990001';
ok(wa_camp_telefone($x) === '+5548988880002', 'wa_id tem prioridade sobre telefone');

$x = $base; $x['wa_id'] = null; $x['telefone'] = '48 99999-0001';
ok(wa_camp_telefone($x) === '+5548999990001', 'telefone cru e normalizado');

/* ---------- deduplicacao ----------
   Regra 3 do Armando: nunca duplicar. Duas fichas da mesma pessoa (uma do
   CRM, uma da agenda) com o mesmo celular nao podem virar dois envios
   cobrados. A PRIMEIRA vence, que e a ordem em que a consulta entrega. */
$leads = [
  ['id'=>'A', 'cliente'=>true, 'revisado'=>true, 'opt_out_at'=>null, 'telefone'=>'+5548999990001', 'wa_id'=>null, 'nome'=>'Um'],
  ['id'=>'B', 'cliente'=>true, 'revisado'=>true, 'opt_out_at'=>null, 'telefone'=>'48999990001',   'wa_id'=>null, 'nome'=>'Um de novo'],
  ['id'=>'C', 'cliente'=>true, 'revisado'=>true, 'opt_out_at'=>null, 'telefone'=>'+5548999990002', 'wa_id'=>null, 'nome'=>'Dois'],
  ['id'=>'D', 'cliente'=>false,'revisado'=>true, 'opt_out_at'=>null, 'telefone'=>'+5548999990003', 'wa_id'=>null, 'nome'=>'Fora'],
];
$r = wa_camp_publico($leads);
ok(count($r['publico']) === 2, 'duas pessoas distintas no publico (deu: ' . count($r['publico']) . ')');
ok($r['publico'][0]['lead_id'] === 'A', 'a primeira ficha vence a duplicata');
ok($r['publico'][0]['wa_id']   === '+5548999990001', 'o publico carrega o E.164 ja normalizado');
ok($r['resumo']['duplicados']  === 1, 'a duplicata e contada');
ok($r['resumo']['nao_cliente'] === 1, 'quem ficou de fora e contado por motivo');
ok($r['resumo']['total']       === 2, 'o total do resumo e o tamanho do publico');

/* ---------- custo ----------
   Spec 8.2: a tela mostra quantas pessoas e quanto custa ANTES do envio.
   Em centavos inteiros: float de dinheiro acumula erro e o numero que a
   cliente le tem que bater com a fatura da Meta. */
ok(wa_camp_custo(0, 31)   === 0,    'publico vazio custa zero');
ok(wa_camp_custo(100, 31) === 3100, '100 destinatarios a 31 centavos = R$ 31,00');
ok(WA_CAMP_PRECO_CENTAVOS === 31,   'o preco padrao e 31 centavos');

/* ---------- estrangeiro discado com 00 ----------
   Limite conhecido e permanente de wa_e164: "0045 3314-1414" e Copenhague E
   e fixo de Cascavel na mesma string, digito por digito. Espanha (+34),
   Belgica (+32), Dinamarca (+45), Noruega (+47) e Tailandia (+66) tem DDI de
   dois digitos que tambem e DDD valido. Cuba, Espanha, Peru e Coreia estao no
   catalogo de roteiros da casa, entao hotel ou receptivo salvo na agenda a
   partir de uma ligacao e cenario real. Nao da para separar pelo numero
   normalizado: so o CRU, guardado em payload_import, ainda tem o "00". */
ok(wa_camp_ddi_suspeito(['telefone' => '0045 3314 1414']) === '45',
   'numero salvo com 00 + DDI estrangeiro e marcado');
ok(wa_camp_ddi_suspeito(['fone' => '004733112233']) === '47',
   'a varredura acha o telefone em qualquer chave do payload');
ok(wa_camp_ddi_suspeito(['telefone' => '+55 48 99999-0001']) === null,
   'numero brasileiro normal nao e marcado');
ok(wa_camp_ddi_suspeito(['telefone' => '0055 48 99999-0001']) === null,
   '00 seguido do DDI do Brasil nao e suspeito');
ok(wa_camp_ddi_suspeito(['telefone' => '0012125551234']) === null,
   '00 + DDI que NAO vira numero brasileiro valido nao e marcado');
ok(wa_camp_ddi_suspeito(null) === null, 'payload nulo nao quebra');

echo "test-wa-camp OK\n";
