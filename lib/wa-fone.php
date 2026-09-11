<?php
/* ============================================================
   Normalizacao de telefone brasileiro para E.164 (+55DDNNNNNNNNN).

   POR QUE ISTO EXISTE: a base de contatos vem exportada da agenda do
   celular, onde o mesmo numero aparece como "(48) 99604-8882",
   "48996048882" e "+55 48 99604 8882". Gravar formato livre faz o mesmo
   cliente virar tres contatos e a conversa se perder no meio.
============================================================ */

/* DDDs que existem de fato no Brasil. A faixa 11..99 tem buracos (20, 23,
   25, 26, 29, 30...) e aceitar tudo deixaria lixo da agenda entrar. */
const WA_DDDS = [
    11,12,13,14,15,16,17,18,19, 21,22,24,27,28,
    31,32,33,34,35,37,38, 41,42,43,44,45,46,47,48,49,
    51,53,54,55, 61,62,63,64,65,66,67,68,69,
    71,73,74,75,77,79, 81,82,83,84,85,86,87,88,89,
    91,92,93,94,95,96,97,98,99,
];

function wa_e164($bruto) {
    $d = preg_replace('/\D+/', '', (string) $bruto);
    if ($d === '') return null;

    // DDI do Brasil, quando veio. 13 digitos = 55 + DDD + 9 digitos.
    if (strlen($d) >= 12 && substr($d, 0, 2) === '55') {
        $d = substr($d, 2);
    }

    // Sobrou coisa demais: e numero estrangeiro, nao nosso.
    if (strlen($d) > 11 || strlen($d) < 10) return null;

    $ddd   = (int) substr($d, 0, 2);
    $resto = substr($d, 2);
    if (!in_array($ddd, WA_DDDS, true)) return null;

    // Nono digito: celular antigo tem 8 digitos comecando em 6..9. Fixo
    // comeca em 2..5 e NAO leva o nono (se levasse, viraria numero que nao
    // existe e o envio falharia em silencio).
    if (strlen($resto) === 8 && $resto[0] >= '6') {
        $resto = '9' . $resto;
    }

    // O prefixo do numero local e o que separa um telefone brasileiro de um
    // estrangeiro que por acaso caiu num DDD valido: "+1 415 555 2671" vira
    // 14155552671, e 14 e Bauru. Celular tem 9 digitos e SEMPRE comeca com 9;
    // fixo tem 8 e comeca entre 2 e 5. Fora disso, nao e numero daqui.
    if (!preg_match('/^9\d{8}$/', $resto) && !preg_match('/^[2-5]\d{7}$/', $resto)) {
        return null;
    }

    return '+55' . $ddd . $resto;
}

function wa_e_celular($e164) {
    return (bool) preg_match('/^\+55\d{2}9\d{8}$/', (string) $e164);
}
