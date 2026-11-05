<?php
/* Reconhecer um DESTINO da casa pela frase do cliente.
   Tarefa 2 do plano 2026-11-05-robo-entende-a-frase.

   Destino nao e roteiro. Roteiro esta a venda e tem PDF, preco e data;
   destino e so um lugar que a agencia ja vendeu alguma vez. Reconhecer o
   destino e o que permite o robo responder "ainda nao temos data para a
   Italia" em vez de nao entender - e dai oferecer a lista de espera, que e o
   pedido do cliente de 04/11/2026.

   QUEM DECIDE A ORDEM NAO E ESTE ARQUIVO. O grafo tenta o atalho de roteiro
   antes do de destino, porque roteiro a venda e resposta melhor que "nao
   temos data". Aqui so mora o reconhecimento.

   DEPENDENCIA: so wa-roteiro.php, de onde vem a normalizacao. A seta do
   subsistema e `db/send/po-data <- fluxo <- sim/motor <- timeout`, e este
   arquivo entra ao lado de wa-roteiro.php, antes de fluxo. Ele NAO requer
   wa-fluxo.php nem wa-motor.php.

   REAPROVEITA a normalizacao em vez de ter a propria: duas normalizacoes
   divergem e os dois testes ficam verdes medindo coisas diferentes. */

require_once __DIR__ . '/wa-roteiro.php';

/* Parser unico de `po_destinos.palavras` e `po_roteiros.palavras_chave`, que
   tem a MESMA forma: texto separado por virgula.

   Descarta entrada de menos de 4 caracteres pelo mesmo motivo que
   wa_match_roteiros descarta palavra curta do cliente: fragmento curto casa
   por acidente. E deduplica, para o painel poder repetir sem efeito.

   Em `text` separado por virgula, e nao array nem jsonb, porque no painel e
   um campo de texto so e quem compara e o normalizador, que ja quebra em
   palavras. */
function wa_palavras_lista($csv) {
    $out = [];
    foreach (explode(',', (string) $csv) as $p) {
        $n = trim(wa_normaliza($p));
        if ($n === '' || mb_strlen($n, 'UTF-8') < 4) continue;
        $out[$n] = true;
    }
    return array_keys($out);
}

/* Os destinos cujas palavras aparecem na frase. Devolve os NOMES, que e o que
   o robo escreve de volta ao cliente.

   PALAVRA COMPOSTA E TRATADA COMO FRASE, e isto nao e refinamento: e o que
   impede um defeito real. "africa do sul" quebrada em tokens daria `africa`,
   `do` e `sul`; ai a frase "quero a coreia do sul" casaria `sul`, devolveria
   DOIS destinos, o unico viraria null, e o robo responderia o menu para quem
   escreveu exatamente o nome de um destino da casa. Com frase, `africa do
   sul` so casa com as tres palavras juntas e na ordem.

   A frase e casada com BORDA DE PALAVRA nas duas pontas (o espaco em volta),
   senao `aurora` casaria dentro de `auroras` ao contrario do pretendido e,
   pior, uma frase casaria no meio de outra palavra.

   Devolve TODOS os que casaram. A regra de "exatamente um" e do chamador, em
   wa_destino_unico: duas respostas possiveis significam que o robo nao sabe,
   e perguntar e melhor que mandar a resposta errada.

   NAO ENTENDE NEGACAO nem tempo passado: "nao quero a Italia" e "fui pra
   Italia ano passado" sao lidos como interesse. Limitacao aceita de proposito
   no desenho - resolver exige analise de linguagem, o custo de errar e baixo
   (a Lais ve a conversa) e o risco de tentar e alto. */
function wa_match_destinos($texto, $destinos) {
    /* Os tokens primeiro, e o texto cercado e DERIVADO deles. Assim o
       espacamento da frase e sempre canonico e nao existe um `trim` solto
       para alguem tirar: a rodada de mutacao mostrou que remover o trim nao
       mudava resultado nenhum, porque a normalizacao ja colapsa espaco e os
       lacos abaixo simplesmente nao acham nada quando nao ha token. Guarda
       que nao muda resultado e guarda que ninguem consegue testar. */
    $tokens = array_values(array_filter(explode(' ', wa_normaliza($texto)), function ($x) { return $x !== ''; }));
    $cercado = ' ' . implode(' ', $tokens) . ' ';

    $achados = [];
    foreach ((array) $destinos as $d) {
        if (!is_array($d)) continue;
        $nome = trim((string) ($d['nome'] ?? ''));
        if ($nome === '') continue;      // o nome e o que o robo escreve de volta

        foreach (wa_palavras_lista($d['palavras'] ?? '') as $p) {
            $casou = false;
            if (strpos($p, ' ') !== false) {
                $casou = strpos($cercado, ' ' . $p . ' ') !== false;
            } else {
                foreach ($tokens as $tok) {
                    if (wa_mesma_palavra($tok, $p)) { $casou = true; break; }
                }
            }
            if ($casou) { $achados[$nome] = true; break; }
        }
    }
    return array_keys($achados);
}

/* O nome do destino quando EXATAMENTE um casa, null quando zero ou dois ou
   mais. E a regra que o atalho usa. */
function wa_destino_unico($texto, $destinos) {
    $a = wa_match_destinos($texto, $destinos);
    return count($a) === 1 ? $a[0] : null;
}
