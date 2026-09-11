<?php
/* ============================================================
   Descobre de qual roteiro o cliente esta falando.

   Ordem de confianca (quem chama decide): anuncio > marcador do link do
   site > casamento por palavra > menu. Aqui moram os dois ultimos.

   NAO usa IA de proposito: o catalogo tem 6 roteiros com nomes de
   destino bem distintos, o casamento por palavra resolve, e assim nao ha
   custo por conversa nem uma peca a mais para falhar.

   O casamento e por TOKEN INTEIRO, nunca por pedaco de palavra (strpos).
   Substring parecia inofensivo mas casa palavra comum do portugues com
   nome de destino: "cama" (de "cama de casal") e substring de "atacama",
   "caminho" e substring de "caminhos" (de Caminhos da India), "asia" e
   substring de "asiaticos". A tolerancia e so o plural em "s", o minimo
   para "caminho"/"caminhos" e "cerejeira"/"cerejeiras" casarem sem abrir
   a porta de volta para casamento por pedaco.
============================================================ */

/* Apelidos que o cliente usa e que nao aparecem, com a mesma palavra, no
   slug ou titulo de nenhum roteiro vivo no catalogo. Chave = palavra que
   ele digita, valor = palavra inteira que precisa aparecer como token no
   slug ou titulo (nao um pedaco: o casamento agora e por token, entao um
   fragmento aqui nunca acha nada).

   A lista fica curta de proposito. Um apelido identico a palavra que ja
   esta no titulo (ex.: 'turquia'=>'turquia') e redundante, o casamento
   direto ja cobre. E um apelido que aponta para um roteiro aposentado do
   catalogo (ex.: 'mercados-de-natal', 'floracao-das-cerejeiras',
   'grecia-terra-mar', nenhum dos 6 ativos hoje) e armadilha latente: some
   sozinho e nao atrapalha, mas se um roteiro futuro tiver aquela palavra
   no titulo por coincidencia, o apelido vaza para ele sem ninguem notar.
   Por isso 'japao', 'natal', 'mercados', 'grecia', 'cerejeira' saem daqui
   (revisao encontrou 'japao' apontando pra 'floracao-das-cerejeiras', que
   nem existe mais; hoje 'japao' ja casa direto com o slug
   'coreia-do-sul-japao-dubai', que e o roteiro de verdade). */
const WA_APELIDOS = [
    'noruega' => 'escandinavia',
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
   cliente viraria escolha de roteiro. "Caminhos", "tesouros", "deserto",
   "encantos", "floracao" e "terra" entram pelo mesmo motivo: sao palavras
   genericas que aparecem em titulo de roteiro mas nao identificam destino
   nenhum sozinhas ("qual o caminho para chegar ai" nao pode escolher
   Caminhos da India so por causa da palavra "caminho"). */
const WA_VAZIAS = [
    'de','da','do','das','dos','e','com','o','a','os','as','em',
    'melhor','roteiro','viagem','pelo','pela',
    'caminhos','tesouros','deserto','encantos','floracao','terra',
];

/* Compara duas palavras ja normalizadas tolerando so o plural em "s". E o
   suficiente para "caminho"/"caminhos" e "cerejeira"/"cerejeiras" casarem
   sem reabrir a porta de casamento por pedaco de palavra. */
function wa_mesma_palavra($a, $b) {
    return $a === $b || rtrim($a, 's') === rtrim($b, 's');
}

function wa_eh_vazia($p) {
    foreach (WA_VAZIAS as $v) {
        if (wa_mesma_palavra($p, $v)) return true;
    }
    return false;
}

/* Separa slug + titulo em tokens, no mesmo criterio de normalizacao do
   texto do cliente - e o que permite comparar token contra token em vez
   de procurar um pedaco dentro do outro. */
function wa_tokens_do_roteiro($r) {
    $alvo = wa_normaliza(($r['slug'] ?? '') . ' ' . ($r['titulo'] ?? ''));
    return array_values(array_filter(explode(' ', $alvo), function ($x) { return $x !== ''; }));
}

function wa_match_roteiros($texto, $roteiros) {
    $t = wa_normaliza($texto);
    if (trim($t) === '') return [];
    $palavras = array_filter(explode(' ', $t), function ($p) {
        // Fragmento curto casaria por acidente ("ar" dentro de "Antalia").
        return strlen($p) >= 4 && !wa_eh_vazia($p);
    });
    if (!$palavras) return [];

    $achados = [];
    foreach ($roteiros as $r) {
        $tokens = wa_tokens_do_roteiro($r);
        foreach ($palavras as $p) {
            $casou = false;
            foreach ($tokens as $tok) {
                if (wa_mesma_palavra($p, $tok)) { $casou = true; break; }
            }
            if (!$casou && isset(WA_APELIDOS[$p])) {
                foreach ($tokens as $tok) {
                    if (wa_mesma_palavra(WA_APELIDOS[$p], $tok)) { $casou = true; break; }
                }
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
