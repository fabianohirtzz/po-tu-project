<?php
require_once __DIR__ . '/po-datas.php';
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
/* WA_APELIDOS SAIU DAQUI em 05/11/2026. Era uma constante com UMA entrada
   (`noruega` -> `escandinavia`), e cada sinonimo novo exigia deploy por FTP.
   Agora os sinonimos vivem em `po_roteiros.palavras_chave`, editaveis no
   painel, e a entrada antiga foi semeada pela migration
   2026-11-05-destinos-espera.sql. Nenhum teste cobria a constante. */

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

/* Separa slug + titulo + palavras_chave em tokens, no mesmo criterio de
   normalizacao do texto do cliente - e o que permite comparar token contra
   token em vez de procurar um pedaco dentro do outro.

   `palavras_chave` entra CRUA e e tokenizada, nao casada como frase (que e o
   que wa_match_destinos faz com `po_destinos.palavras`). A assimetria e
   deliberada e tem duas razoes: aqui os tokens vem majoritariamente de slug e
   titulo, que ja sao casados token a token pelos 20 testes deste arquivo, e
   trocar isso mudaria comportamento que eles travam; e usar o parser de
   frases exigiria requerer wa-destinos.php, que requer ESTE arquivo - ciclo.
   O risco fica limitado porque a coluna e semeada com palavra distintiva: a
   unica composta hoje e `taj mahal`, e `taj` tem 3 caracteres, entao nunca
   chega como palavra do cliente (o filtro de 4 a descarta antes). */
function wa_tokens_do_roteiro($r) {
    $alvo = wa_normaliza(($r['slug'] ?? '') . ' ' . ($r['titulo'] ?? '') . ' ' . ($r['palavras_chave'] ?? ''));
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
            if ($casou) { $achados[] = $r['slug']; break; }
        }
    }
    return array_values(array_unique($achados));
}

/* O slug quando EXATAMENTE um roteiro casa, null quando zero ou dois ou
   mais.

   A regra e do CHAMADOR, nao do casamento: wa_match_roteiros devolve todos os
   que casaram, de proposito, e ha teste acima provando que uma frase com dois
   destinos devolve dois. Quem vai RESPONDER ao cliente precisa de certeza -
   com dois, o robo nao sabe qual PDF mandar, e perguntar e melhor que errar.
   E o atalho de roteiro usa esta funcao, nao a outra. */
function wa_roteiro_unico($texto, $roteiros) {
    $a = wa_match_roteiros($texto, $roteiros);
    return count($a) === 1 ? $a[0] : null;
}

/* Data de partida do roteiro, em 'YYYY-MM-DD', ou null. Pura.

   O banco NAO tem coluna de data: a data vive no texto que a cliente escreve
   no painel. Medido nos 7 roteiros em 03/11/2026, em 4 formatos ("De
   22/02/2027 a 15/03/2027", "De 27/10/26 à 12/11/26", "25/11/26 a 02/12/26",
   "De 18/03/27 à 09/04/27").

   A ORDEM DOS CAMPOS vem dessa medicao: `periodo` primeiro porque os 7 tem
   data ali; `descricao_curta` por ultimo porque nela a data existe em 1 so
   (nos outros 6 e texto de venda, e ler data dali daria a data errada).

   Vale a PRIMEIRA data valida: o texto e um intervalo, e o que ordena a lista
   e a partida. Data impossivel (31/02) e descartada e a busca continua - sem
   `checkdate`, um erro de digitacao no painel mandaria a viagem para o lugar
   errado da fila em vez de para o fim dela. */
function wa_roteiro_data_ini($roteiro, $hoje = null) {
    return po_data_saida($roteiro, $hoje);
}

/* Marcador embutido no texto pre-preenchido do link wa.me do site. E a
   forma mais confiavel depois do anuncio, porque o link e nosso. */
function wa_slug_do_marcador($texto) {
    if (preg_match('/\[r:([a-z0-9-]+)\]/', (string) $texto, $m)) {
        return $m[1];
    }
    return null;
}
