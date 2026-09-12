<?php
/* ============================================================
   Leitura de vCard (.vcf) e CSV exportados da agenda do celular ou
   do Google Contacts. Só EXTRAI: nome, telefones, emails, org e o
   card cru. A normalizacao (E.164, marcador, limpeza) e da Task 4.
============================================================ */

function wa_vcard_parse($texto) {
    $texto = str_replace(["\r\n", "\r"], "\n", (string) $texto);
    $cards = [];
    /* Separa cada BEGIN:VCARD ... END:VCARD. O miolo NAO pode atravessar um
       BEGIN:VCARD: com '(.*?)' e o modificador /s, um card truncado (sem
       END, que e o que acontece quando a agenda e exportada em partes - e o
       proprio endpoint pede isso quando o arquivo passa do limite) se
       grudava no card seguinte. O resultado era UM contato so, com o nome
       do segundo e os telefones dos dois, e o primeiro sumia da importacao
       inteira - com o agravante de o wa_id (primeiro celular) ser o do
       contato que desapareceu, entao um disparo chamaria uma pessoa pelo
       nome da outra. */
    if (preg_match_all('/BEGIN:VCARD\n((?:(?!BEGIN:VCARD).)*?)\nEND:VCARD/is', $texto, $m)) {
        foreach ($m[0] as $i => $bruto) {
            $linhas = wa_vcard_desdobra($m[1][$i]);
            $card = ['nome' => '', 'telefones' => [], 'emails' => [], 'org' => '',
                     'data_nascimento' => '', 'raw' => $bruto];
            $fn = ''; $n = '';
            foreach ($linhas as $ln) {
                if (strpos($ln, ':') === false) continue;
                list($chaveBruta, $valor) = explode(':', $ln, 2);
                $partes = explode(';', $chaveBruta);
                $prop   = strtoupper($partes[0]);
                $qp     = false;
                foreach ($partes as $p) {
                    if (stripos($p, 'QUOTED-PRINTABLE') !== false) $qp = true;
                }
                if ($qp) $valor = quoted_printable_decode($valor);
                switch ($prop) {
                    case 'FN':    $fn = trim($valor); break;
                    case 'N':     $n  = wa_vcard_nome_de_n($valor); break;
                    case 'TEL':   if (trim($valor) !== '') $card['telefones'][] = trim($valor); break;
                    case 'EMAIL': if (trim($valor) !== '') $card['emails'][] = trim($valor); break;
                    case 'ORG':   $card['org'] = trim($valor); break;
                    /* BDAY e a TERCEIRA camada de identidade (nome+nascimento)
                       da auditoria de fusao. Sem ele, cliente antigo marcado
                       "PO" na agenda nao casa por CPF (a agenda nao tem CPF)
                       nem por celular (das 775 fichas do CRM na base, so 22
                       tem celular) e entra como contato NOVO - a mesma pessoa
                       duas vezes, que e exatamente a regra 3 do dono.
                       Fica CRU: quem normaliza e wa_import_candidato, com
                       wa_import_data_iso (este arquivo so extrai). */
                    case 'BDAY':  $card['data_nascimento'] = trim($valor); break;
                }
            }
            $card['nome'] = $fn !== '' ? $fn : $n;
            $cards[] = $card;
        }
    }
    return $cards;
}

/* Folding do vCard: uma linha continua na proxima quando esta comeca com
   espaco ou tab. Remonta antes de parsear. */
function wa_vcard_desdobra($bloco) {
    $out = [];
    foreach (explode("\n", $bloco) as $ln) {
        if ($ln !== '' && ($ln[0] === ' ' || $ln[0] === "\t") && $out) {
            $out[count($out) - 1] .= ltrim($ln);
        } else {
            $out[] = $ln;
        }
    }
    return $out;
}

/* N e "Sobrenome;Nome;;;". Vira "Nome Sobrenome". */
function wa_vcard_nome_de_n($valor) {
    $p = explode(';', $valor);
    $sobrenome = trim($p[0] ?? '');
    $nome      = trim($p[1] ?? '');
    return trim($nome . ' ' . $sobrenome);
}

/* CSV do Google Contacts. Casa colunas por nome de cabecalho, que muda de
   idioma ("Name" / "Nome"), entao procura por substring conhecida. */
function wa_csv_parse($texto) {
    $texto = str_replace(["\r\n", "\r"], "\n", (string) $texto);
    $linhas = array_values(array_filter(explode("\n", $texto), fn($l) => $l !== ''));
    if (!$linhas) return [];
    $cab = str_getcsv($linhas[0]);
    $colNome = wa_csv_acha_col($cab, ['name', 'nome']);
    $colsTel = wa_csv_acha_cols($cab, ['phone', 'telefone', 'celular']);
    $colsMail = wa_csv_acha_cols($cab, ['e-mail', 'email']);
    /* Mesma razao do BDAY do vCard: e a terceira camada de identidade. Aqui
       a coluna pode nao existir (-1), e ai o campo fica vazio. */
    $colNasc = wa_csv_acha_col($cab, ['birthday', 'nascimento', 'aniversario'], -1);
    $out = [];
    for ($i = 1; $i < count($linhas); $i++) {
        $c = str_getcsv($linhas[$i]);
        $tel = []; foreach ($colsTel as $k) if (trim($c[$k] ?? '') !== '') $tel[] = trim($c[$k]);
        $mail = []; foreach ($colsMail as $k) if (trim($c[$k] ?? '') !== '') $mail[] = trim($c[$k]);
        $out[] = [
            'nome' => trim($c[$colNome] ?? ''),
            'telefones' => $tel, 'emails' => $mail, 'org' => '',
            'data_nascimento' => $colNasc >= 0 ? trim($c[$colNasc] ?? '') : '',
            'raw' => $linhas[$i],
        ];
    }
    return $out;
}

/* $padrao e o que volta quando nenhuma coluna casa: 0 para o nome (a
   primeira coluna e o palpite util) e -1 para coluna opcional, que quando
   falta nao pode virar "a coluna 0". */
function wa_csv_acha_col($cab, $chaves, $padrao = 0) {
    foreach ($cab as $i => $nome) foreach ($chaves as $ch)
        if (stripos($nome, $ch) !== false) return $i;
    return $padrao;
}
function wa_csv_acha_cols($cab, $chaves) {
    $out = [];
    foreach ($cab as $i => $nome) foreach ($chaves as $ch)
        if (stripos($nome, $ch) !== false) { $out[] = $i; break; }
    return $out;
}
