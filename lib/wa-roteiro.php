<?php
/* ============================================================
   Descobre de qual roteiro o cliente esta falando.

   Ordem de confianca (quem chama decide): anuncio > marcador do link do
   site > casamento por palavra > menu. Aqui moram os dois ultimos.

   NAO usa IA de proposito: o catalogo tem 6 roteiros com nomes de
   destino bem distintos, o casamento por palavra resolve, e assim nao ha
   custo por conversa nem uma peca a mais para falhar.
============================================================ */

/* Apelidos que o cliente usa e que nao aparecem no titulo. Chave = palavra
   que ele digita, valor = pedaco que precisa estar no slug ou no titulo. */
const WA_APELIDOS = [
    'cerejeira'  => 'cerejeir',
    'cerejeiras' => 'cerejeir',
    'japao'      => 'cerejeir',
    'natal'      => 'natal',
    'mercados'   => 'natal',
    'grecia'     => 'grecia',
    'atacama'    => 'atacama',
    'deserto'    => 'atacama',
    'india'      => 'india',
    'turquia'    => 'turquia',
    'escandinavia' => 'escandinav',
    'noruega'    => 'escandinav',
];

/* Minusculas, sem acento. O cliente digita "ESCANDINÁVIA" e "escandinavia"
   e as duas formas precisam casar com o mesmo roteiro.

   Interface publica: a Task 7 tambem chama esta funcao para interpretar
   respostas de sim/nao do cliente, entao a assinatura e o comportamento
   (minusculas, sem acento, so [a-z0-9] separado por espaco) sao contrato,
   nao detalhe interno deste arquivo. */
function wa_normaliza($s) {
    $s = mb_strtolower((string) $s, 'UTF-8');
    $s = strtr($s, [
        'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a',
        'é'=>'e','ê'=>'e','è'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        'ó'=>'o','ô'=>'o','õ'=>'o','ò'=>'o','ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
        'ç'=>'c','ñ'=>'n',
    ]);
    return preg_replace('/[^a-z0-9]+/', ' ', $s);
}

/* Palavras do titulo que nao identificam destino nenhum. Sem esta lista,
   "melhor" casaria com "O melhor da Escandinavia" e qualquer elogio do
   cliente viraria escolha de roteiro. */
const WA_VAZIAS = ['de','da','do','das','dos','e','com','o','a','os','as','em','melhor','roteiro','viagem','pelo','pela'];

function wa_match_roteiros($texto, $roteiros) {
    $t = wa_normaliza($texto);
    if (trim($t) === '') return [];
    $palavras = array_filter(explode(' ', $t), function ($p) {
        // Fragmento curto casaria por acidente ("ar" dentro de "Antalia").
        return strlen($p) >= 4 && !in_array($p, WA_VAZIAS, true);
    });
    if (!$palavras) return [];

    $achados = [];
    foreach ($roteiros as $r) {
        $alvo = wa_normaliza(($r['slug'] ?? '') . ' ' . ($r['titulo'] ?? ''));
        foreach ($palavras as $p) {
            $casou = strpos($alvo, $p) !== false;
            if (!$casou && isset(WA_APELIDOS[$p])) {
                $casou = strpos($alvo, WA_APELIDOS[$p]) !== false;
            }
            if ($casou) { $achados[] = $r['slug']; break; }
        }
    }
    return array_values(array_unique($achados));
}

/* Marcador embutido no texto pre-preenchido do link wa.me do site. E a
   forma mais confiavel depois do anuncio, porque o link e nosso. */
function wa_slug_do_marcador($texto) {
    if (preg_match('/\[r:([a-z0-9-]+)\]/', (string) $texto, $m)) {
        return $m[1];
    }
    return null;
}
