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

/* ---------- o SAIR vale pelo NUMERO, nao pela ficha ----------
   Defesa 3 da spec 8.1, e o ciclo completo que quebrava entre as pecas:

     1) a campanha manda para a ficha antiga, que tem telefone e wa_id NULO
        (nenhuma fonte de importacao carrega wa_id);
     2) a pessoa responde SAIR;
     3) wa_lead() procura so por wa_id, nao acha ninguem, e o motor INSERE uma
        ficha nova com o opt_out_at - que ja viola a regra 3 do Armando;
     4) a ficha antiga continua com opt_out_at nulo;
     5) na campanha seguinte ela vem primeiro (created_at.asc), vence a
        deduplicacao por numero e RECEBE - com a tela dizendo, sobre aquele
        mesmo numero, "1 pediu SAIR e fica de fora".

   Aqui as duas fichas do mesmo numero entram na base: o publico tem que sair
   VAZIO e o balde 'saiu' tem que contar. */
$saiu_ciclo = [
    // A ficha velha, do CRM: telefone preenchido, wa_id nulo, sem opt_out.
    ['id'=>'VELHA', 'nome'=>'Maria', 'telefone'=>'+5548999990077', 'wa_id'=>null,
     'cliente'=>true, 'revisado'=>true, 'opt_out_at'=>null],
    // A ficha que o motor criou quando ela respondeu SAIR: so wa_id.
    ['id'=>'NOVA',  'nome'=>'Maria', 'telefone'=>null, 'wa_id'=>'+5548999990077',
     'cliente'=>true, 'revisado'=>true, 'opt_out_at'=>'2026-09-14T10:00:00Z'],
];
$rs = wa_camp_publico($saiu_ciclo);
ok(count($rs['publico']) === 0,
   'quem pediu SAIR nao recebe, mesmo com a ficha antiga limpa (entraram: '
   . count($rs['publico']) . ')');
ok($rs['resumo']['saiu'] === 2,
   'as duas fichas do numero caem no balde SAIU, que e o que a cliente le para conferir '
   . 'a defesa (deu: ' . $rs['resumo']['saiu'] . ')');
ok($rs['resumo']['duplicados'] === 0,
   'e nenhuma delas vira "duplicado", que esconderia justamente o que ela foi conferir');

// O mesmo numero, sem nenhum opt_out em lugar nenhum, continua recebendo: a
// exclusao e do NUMERO QUE SAIU, nao de todo numero repetido.
$sem_saida = $saiu_ciclo;
$sem_saida[1]['opt_out_at'] = null;
$rn = wa_camp_publico($sem_saida);
ok(count($rn['publico']) === 1 && $rn['resumo']['duplicados'] === 1,
   'sem opt_out nenhum, as duas fichas do mesmo numero viram UM destinatario');

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

/* ---------- escada de lotes ----------
   Defesa 2 da spec 8.1: "o primeiro envio vai para um grupo pequeno; o
   tamanho sobe conforme o numero mantem boa qualidade". Numero novo que
   dispara 800 templates de uma vez e numero que a Meta rebaixa ou bloqueia,
   e a cliente perde o WhatsApp que usa para trabalhar todo dia. */
ok(wa_camp_degrau(0, 0, 0)   === 50,   'a primeira campanha da casa vai para 50');
ok(wa_camp_degrau(1, 0, 50)  === 150,  'sem falha, a segunda sobe para 150');
ok(wa_camp_degrau(2, 0, 150) === 400,  'a terceira sobe para 400');
ok(wa_camp_degrau(9, 0, 999) === 2000, 'a escada para no topo e nao passa dele');

/* Qualidade ruim nao sobe degrau: DESCE um. 5% de falha em template e sinal
   de lista velha, e insistir e o caminho para o numero ser rebaixado. */
ok(wa_camp_degrau(2, 20, 150) === 150,
   'com mais de 5% de falha a campanha seguinte desce um degrau');
ok(wa_camp_degrau(1, 20, 50)  === 50,
   'do primeiro degrau nao se desce mais');
ok(wa_camp_degrau(2, 7, 150)  === 400,
   'exatamente 4,6% de falha ainda sobe: o corte e ACIMA de 5%');
ok(wa_camp_degrau(2, 5, 100) === 400,
   'exatamente 5,00% de falha ainda sobe de degrau: o corte e ACIMA de 5%');
ok(wa_camp_degrau(0, 100, 100) === 50,
   'degrau 0 com falha ruim continua em 50, nunca abaixo do primeiro');

/* Divisao por zero: campanha anterior sem nenhum envio. */
ok(wa_camp_degrau(3, 0, 0) === 1000, 'campanha anterior vazia nao derruba o degrau');

/* ---------- teto diario ----------
   O teto da Meta e por dia e por numero (250 antes da verificacao da empresa,
   2.000 depois - a empresa foi verificada em 11/09/2026). Estourar devolve
   erro por destinatario, e cada erro ja e uma mensagem perdida. */
ok(wa_camp_lote_permitido(400, 0, 1000)   === 400, 'com o dia livre, o lote e o degrau inteiro');
ok(wa_camp_lote_permitido(400, 800, 1000) === 200, 'perto do teto, o lote encolhe');
ok(wa_camp_lote_permitido(400, 1000, 1000) === 0,  'no teto, nao sai nada');
ok(wa_camp_lote_permitido(400, 1200, 1000) === 0,  'acima do teto nunca devolve negativo');
/* 250, nao 1000: e o piso do tier de mensageria da Meta para numero novo. A
   verificacao da empresa libera o teto POTENCIAL, nao o tier do numero. E como
   a escada limita o tamanho do LOTE e nao o total do dia, um teto de 1.000 com
   o cron de hora em hora deixaria o primeiro dia de um numero frio chegar a mil
   mensagens - o contrario da defesa 2 da spec 8.1. */
ok(WA_CAMP_TETO_DIARIO === 250, 'o teto proprio padrao e 250 por dia, o piso da Meta para numero novo');

echo "test-wa-camp OK\n";
