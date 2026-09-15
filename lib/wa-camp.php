<?php
/* ============================================================
   Transmissao: as regras puras de quem entra, quanto custa e o que
   merece conferencia antes do primeiro disparo pago.

   Nada aqui toca banco nem rede, de proposito: e o que permite testar a
   regra de negocio inteira sem simulador. Quem toca banco e
   lib/wa-camp-fila.php.
============================================================ */

require_once __DIR__ . '/wa-fone.php';

/* ~R$ 0,31 por destinatario (template de marketing, Brasil). E o UNICO custo
   relevante do sistema: mensagem recebida e mensagem que a cliente digita no
   app sao sempre gratis. O preco e congelado na campanha na hora da criacao,
   porque o da Meta muda e o relatorio antigo tem que continuar batendo. */
const WA_CAMP_PRECO_CENTAVOS = 31;

/* O numero com que a pessoa e alcancada. wa_id vence telefone: e o numero
   com que o WhatsApp JA falou, entao e o que sabidamente existe la. */
function wa_camp_telefone($lead) {
    $wa = wa_e164($lead['wa_id'] ?? '');
    if ($wa !== null) return $wa;
    return wa_e164($lead['telefone'] ?? '');
}

/* Devolve null quando a pessoa entra, ou o MOTIVO de ficar de fora.
   A ordem das checagens e o que define em qual balde a pessoa aparece no
   resumo, e o balde e o que a cliente le para conferir as defesas:

   1) opt_out primeiro, sempre. Defesa 3 da spec 8.1 diz "sem excecao", e
      quem saiu tem que aparecer como 'saiu' mesmo que tambem nao seja
      cliente - senao a conferencia da defesa nao tem o que olhar.
   2) revisado/cliente antes de telefone, porque e a defesa 1 e e o numero
      que decide se a fila de revisao ja foi trabalhada.
   3) telefone por ultimo. */
function wa_camp_motivo_fora($lead) {
    if (!empty($lead['opt_out_at']))            return 'saiu';
    if (($lead['cliente']  ?? null) !== true)   return 'nao_cliente';
    if (($lead['revisado'] ?? null) !== true)   return 'nao_revisado';
    $f = wa_camp_telefone($lead);
    if ($f === null)                            return 'sem_telefone';
    // Fixo entra na ficha mas NAO no disparo (spec 8): template para fixo e
    // cobrado e nao entrega.
    if (!wa_e_celular($f))                      return 'sem_celular';
    return null;
}

/* Os NUMEROS que pediram para sair, vindos de QUALQUER ficha da base.

   O SAIR nao pode valer pela ficha, tem que valer pelo numero, e o motivo e
   uma costura entre pecas que so aparece com o sistema inteiro na mesa:

     - wa_lead() acha o lead so por wa_id;
     - as fichas do CRM e das agendas tem telefone preenchido e wa_id NULO,
       porque nenhuma fonte de importacao carrega wa_id;
     - mas o publico da campanha entra por telefone (wa_camp_telefone aceita
       os dois).

   Entao quem recebe pelo telefone e responde SAIR nao e encontrado, ganha uma
   ficha NOVA com o opt_out_at, e a ficha velha continua limpa - mais antiga,
   vence a deduplicacao e volta a receber na campanha seguinte, com a tela
   dizendo "1 pediu SAIR e fica de fora" sobre exatamente aquele numero.

   Casar por numero fecha o ciclo e ainda resolve a ficha duplicada, sem tirar
   a pureza da funcao. */
function wa_camp_saiu_fones($leads) {
    $fora = [];
    foreach ($leads as $l) {
        if (empty($l['opt_out_at'])) continue;
        // Mesma normalizacao do publico: comparar E.164 com E.164.
        $f = wa_camp_telefone($l);
        if ($f !== null) $fora[$f] = true;
    }
    return $fora;
}

/* Monta o publico e o resumo por motivo. A deduplicacao e por E.164, e a
   PRIMEIRA ficha vence: regra 3 do Armando, nunca duplicar. Duas fichas da
   mesma pessoa (uma do CRM, uma da agenda) com o mesmo celular sao um
   destinatario so, cobrado uma vez so. */
function wa_camp_publico($leads) {
    $publico = [];
    $vistos  = [];
    $resumo  = ['total' => 0, 'duplicados' => 0, 'saiu' => 0, 'nao_cliente' => 0,
                'nao_revisado' => 0, 'sem_telefone' => 0, 'sem_celular' => 0];

    // Primeira passada: os numeros que sairam, em qualquer ficha da base.
    $saiu = wa_camp_saiu_fones($leads);

    foreach ($leads as $l) {
        $motivo = wa_camp_motivo_fora($l);
        if ($motivo !== null) { $resumo[$motivo]++; continue; }
        $fone = wa_camp_telefone($l);
        /* Este numero saiu por OUTRA ficha. Conta como 'saiu', nunca como
           duplicado: o balde e o que a cliente le para conferir a defesa 3, e
           um "duplicado" ali esconderia justamente o que ela foi conferir. */
        if (isset($saiu[$fone])) { $resumo['saiu']++; continue; }
        if (isset($vistos[$fone])) { $resumo['duplicados']++; continue; }
        $vistos[$fone] = true;
        $publico[] = [
            'lead_id' => $l['id']   ?? null,
            'wa_id'   => $fone,
            'nome'    => $l['nome'] ?? '',
        ];
    }
    $resumo['total'] = count($publico);
    return ['publico' => $publico, 'resumo' => $resumo];
}

/* Centavos inteiros, nunca float: o numero que a cliente le antes de
   confirmar tem que bater com a fatura da Meta (spec 8.2). */
function wa_camp_custo($n, $preco_centavos = WA_CAMP_PRECO_CENTAVOS) {
    return (int) $n * (int) $preco_centavos;
}

/* Estrangeiro discado com 00, o limite conhecido e permanente de wa_e164.
   "0045 3314-1414" e Copenhague E e fixo de Cascavel na mesma string, digito
   por digito - nao ha informacao no numero que separe os dois. So o valor
   CRU, guardado em payload_import na importacao, ainda tem o "00" na frente.

   Devolve o DDI de dois digitos suspeito, ou null. Varre o payload inteiro
   em vez de olhar uma chave fixa porque o formato varia por origem (vCard da
   agenda, CSV do CRM) e uma chave errada aqui devolveria "nada suspeito"
   silenciosamente, que e o pior resultado possivel para uma conferencia. */
function wa_camp_ddi_suspeito($payload_import) {
    if (is_string($payload_import)) $payload_import = json_decode($payload_import, true);
    if (!is_array($payload_import)) return null;

    $textos = [];
    $achata = function ($v) use (&$achata, &$textos) {
        if (is_array($v))       { foreach ($v as $x) $achata($x); return; }
        if (is_string($v))      { $textos[] = $v; }
        elseif (is_numeric($v)) { $textos[] = (string) $v; }
    };
    $achata($payload_import);

    foreach ($textos as $t) {
        $d = preg_replace('/\D+/', '', $t);
        if (strlen($d) < 12)               continue;   // o corte do 00 exige 12+
        if (substr($d, 0, 2) !== '00')     continue;
        if (substr($d, 2, 2) === '55')     continue;   // 00 + Brasil nao e suspeito
        // So e suspeito se wa_e164 REALMENTE o aceita: um 00+DDI que morre na
        // normalizacao nunca chegou a virar destinatario, entao nao ha o que
        // conferir. Marcar esses so encheria a tela de ruido.
        if (wa_e164($t) === null)          continue;
        return substr($d, 2, 2);
    }
    return null;
}

/* Defesa 2 da spec 8.1: lotes crescentes. Numero novo que dispara centenas de
   templates de uma vez e numero que a Meta rebaixa ou bloqueia - e o numero
   dela e o que a agencia usa para trabalhar todo dia, entao o custo de errar
   aqui nao e a campanha, e o telefone da empresa. */
const WA_CAMP_ESCADA = [50, 150, 400, 1000, 2000];

/* Teto NOSSO, por dia. 250 e o PISO da Meta para numero novo: a verificacao
   da empresa (feita em 11/09/2026) libera o teto POTENCIAL, mas o tier de
   mensageria de um numero recem-registrado comeca em 250/dia e so sobe por
   qualidade. O tier real nao e legivel pela API de forma confiavel, entao o
   cinto comeca no piso - subir daqui exige conferir o tier no painel da Meta.

   Este numero e o unico que limita o TOTAL DO DIA. A escada limita o tamanho
   do LOTE, e o cron roda de hora em hora: com o degrau 50 e o teto em 1.000,
   o primeiro dia de um numero frio chegaria a mil mensagens - o oposto de "o
   primeiro envio vai para um grupo pequeno", que e a defesa 2 da spec 8.1. */
const WA_CAMP_TETO_DIARIO = 250;

/* O degrau da PROXIMA campanha. Sobe com o numero de campanhas concluidas e
   desce um quando a ultima passou de 5% de falha. */
function wa_camp_degrau($concluidas, $falhas_ultima, $total_ultima) {
    $i = (int) $concluidas;
    if ($i < 0) $i = 0;
    if ($i > count(WA_CAMP_ESCADA) - 1) $i = count(WA_CAMP_ESCADA) - 1;

    // Guarda de divisao por zero: campanha anterior sem nenhum envio nao diz
    // nada sobre qualidade, entao nao derruba o degrau.
    if ($i > 0 && (int) $total_ultima > 0
        && ((int) $falhas_ultima / (int) $total_ultima) > 0.05) {
        $i = $i - 1;
    }
    return WA_CAMP_ESCADA[$i];
}

/* Quantos podem sair AGORA: o degrau, limitado pelo que sobra do dia.
   Nunca negativo - um lote negativo viraria array_slice ao contrario e
   mandaria para o fim da fila. */
function wa_camp_lote_permitido($degrau, $enviados_hoje, $teto = WA_CAMP_TETO_DIARIO) {
    $resta = (int) $teto - (int) $enviados_hoje;
    if ($resta < 0) $resta = 0;
    return min((int) $degrau, $resta);
}
