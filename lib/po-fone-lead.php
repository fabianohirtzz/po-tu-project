<?php
/* Normaliza o telefone do lead do formulario para E.164 quando der, e
   PRESERVA o que o visitante digitou quando nao der.

   Perder o telefone de um lead e pior que guardar num formato torto: sem
   ele a cliente nao consegue ligar de volta. Por isso nunca devolve vazio
   para uma entrada nao vazia. */
require_once __DIR__ . '/wa-fone.php';

function po_fone_lead($bruto) {
    $s = trim((string) $bruto);
    if ($s === '') return '';
    $e = wa_e164($s);
    return $e !== null ? $e : $s;
}
