<?php
/* ============================================================
   Carga semente do CRM antigo (Task 9, spec 9.3). Duas funcoes puras,
   testaveis sem rede e sem CLI:

   wa_import_acha_fichas  - localiza a lista de fichas dentro do JSON
                             exportado, que NAO tem a lista na raiz. Devolve
                             ['fichas'=>..,'chave'=>..,'erro'=>..] - aborta
                             (fichas=null) se uma chave conhecida existir mas
                             nao validar, em vez de cair num fallback errado.
   wa_import_executa      - orquestra candidato -> dedup -> leitura da
                             base -> auditoria -> (opcional) escrita, com
                             tudo injetado (leitor da base e escritores).

   scripts/importa-crm.php e so uma casca de CLI em cima destas duas.
============================================================ */

require_once __DIR__ . '/wa-import.php';

/* -------- localizar a lista de fichas dentro do arquivo exportado --------

   O export real do CRM tem o formato {"clients": [...778 fichas...],
   "trips": [...], "participations": [...], ...} - a raiz NAO e a lista, e
   o script nao pode assumir isso. Algumas chaves tambem podem vir como
   STRING JSON (serializacao dupla) em vez de array de verdade; por isso
   todo candidato passa por um segundo json_decode antes de ser avaliado.
*/

/* 'Lista de fichas' = array SEQUENCIAL (chaves 0..n-1, nao um mapa) e nao
   vazio, cujos itens sao TODOS arrays (registros). As duas condicoes
   importam: sem exigir sequencial, o proprio JSON de entrada - um mapa tipo
   {"clients": [...], "trips": [...], "participations": [...]}, cujos
   valores de primeiro nivel sao todos arrays - passaria como "lista de
   fichas" na propria raiz, antes de a busca sequer olhar para a chave
   'clients'. Sem PHP 8.1, nao usa array_is_list(); confere na mao. */
function wa_import_eh_lista_de_fichas($v) {
    if (!is_array($v) || $v === []) return false;
    if (array_keys($v) !== range(0, count($v) - 1)) return false;
    foreach ($v as $item) { if (!is_array($item)) return false; }
    return true;
}

/* Le o valor de uma chave de primeiro nivel, decodificando de novo se ele
   chegou como string JSON em vez de array. */
function wa_import_valor_lista($dados, $chave) {
    if (!is_array($dados) || !array_key_exists($chave, $dados)) return null;
    $v = $dados[$chave];
    if (is_string($v)) {
        $dec = json_decode($v, true);
        if (json_last_error() === JSON_ERROR_NONE) $v = $dec;
    }
    return $v;
}

/* Nomes de chave conhecidos, em ordem de preferencia. O export do CRM do
   Toninho usa 'clients'; os sinonimos cobrem outro formato de export sem
   precisar mudar o script. Vem primeiro que o fallback por tamanho porque
   o mesmo arquivo tambem tem 'trips'/'participations'/'orcamentos', que sao
   listas de registros validas mas NAO sao fichas de cliente. */
const WA_IMPORT_CHAVES_FICHAS = ['clients', 'clientes', 'contacts', 'contatos', 'fichas'];

/* Devolve SEMPRE ['fichas'=>array|null, 'chave'=>string|null, 'erro'=>string|null]:
   - sucesso: 'fichas' preenchido, 'erro' null, 'chave' diz de onde veio
     (null quando a propria raiz ja era a lista - formato sem chave nenhuma).
   - falha: 'fichas' null, 'erro' explica o motivo.

   REGRA CRITICA: se uma chave CONHECIDA (clients/clientes/...) existe no
   arquivo mas o valor dela NAO e uma lista valida de fichas (vazia, mapa por
   id em vez de lista, ou com item que nao e registro), a busca ABORTA ali -
   nao tenta a proxima chave conhecida, e principalmente NAO cai no fallback
   por tamanho. Sem esta trava, 'clients' corrompido (vazio, virado mapa, ou
   com um item quebrado) faria o fallback escolher em silencio a MAIOR outra
   lista do arquivo - no export real, 'participations' (17 registros) - e
   quem aplicasse o plano inseriria 17 leads-lixo (sem nome, sem telefone,
   sem CPF) marcados cliente/revisado, achando que carregou os clientes. O
   fallback por tamanho so serve para um formato de export SEM nenhuma das
   chaves conhecidas - nunca para "salvar" uma chave conhecida quebrada. */
function wa_import_acha_fichas($dados) {
    // a raiz ja pode ser a lista (formato de export sem chave nenhuma)
    if (wa_import_eh_lista_de_fichas($dados)) {
        return ['fichas' => array_values($dados), 'chave' => null, 'erro' => null];
    }
    if (!is_array($dados)) {
        return ['fichas' => null, 'chave' => null, 'erro' => 'a raiz do JSON nao e um objeto/array'];
    }

    foreach (WA_IMPORT_CHAVES_FICHAS as $chave) {
        if (!array_key_exists($chave, $dados)) continue;

        $v = wa_import_valor_lista($dados, $chave);
        if (wa_import_eh_lista_de_fichas($v)) {
            return ['fichas' => array_values($v), 'chave' => $chave, 'erro' => null];
        }
        // a chave existe mas nao valida: aborta aqui, sem tentar outra
        // chave conhecida e sem cair no fallback por tamanho.
        return ['fichas' => null, 'chave' => $chave, 'erro' =>
            "a chave '$chave' existe no arquivo, mas nao e uma lista valida de fichas "
            . "(precisa ser uma lista sequencial e nao vazia, com todo item sendo um registro)"];
    }

    // nenhuma chave conhecida esta presente no arquivo: usa a MAIOR lista de
    // registros entre as chaves de primeiro nivel (formato de export sem
    // nome de chave reconhecido).
    $melhorChave = null; $melhor = null; $melhorN = 0;
    foreach (array_keys($dados) as $chave) {
        $v = wa_import_valor_lista($dados, $chave);
        if (wa_import_eh_lista_de_fichas($v) && count($v) > $melhorN) {
            $melhor      = array_values($v);
            $melhorChave = $chave;
            $melhorN     = count($v);
        }
    }
    if ($melhor === null) {
        return ['fichas' => null, 'chave' => null, 'erro' =>
            'nenhuma lista de registros encontrada no arquivo (nem pelas chaves conhecidas, nem por tamanho)'];
    }
    return ['fichas' => $melhor, 'chave' => $melhorChave, 'erro' => null];
}

/* -------- orquestracao da carga (tudo injetado, nada toca rede aqui) --------

   $ler_base: callable sem argumentos, mesmo contrato de wa_db_select_estrito
   (array das linhas OU null em erro). Erro aborta ANTES de montar qualquer
   plano: base lida como vazia por falha faria toda ficha entrar como 'novo'
   e duplicar a base inteira em silencio (regra 3 da fusao).

   $inserir/$atualizar: mesmos escritores injetados de wa_import_aplica.
   Em dry-run ($aplicar=false) eles NUNCA sao chamados - a chamada a
   wa_import_aplica so acontece dentro do "if ($aplicar)". */
function wa_import_executa($fichas, $origem, $ler_base, $inserir, $atualizar, $aplicar) {
    $cands = [];
    foreach ((array) $fichas as $f) {
        if (!is_array($f)) continue;
        $c = wa_import_candidato(wa_import_mapa_crm($f), $origem);
        if ($c) $cands[] = $c;
    }
    $cands = wa_import_dedup($cands);

    $rows = $ler_base();
    if ($rows === null) {
        return ['erro' => true, 'candidatos' => count($cands), 'resumo' => null,
                'aplicado' => false, 'escrita' => null];
    }

    $base   = array_map('wa_import_base_row', $rows);
    $plano  = wa_import_audita($cands, $base);
    $resumo = wa_import_resumo($plano);

    $out = ['erro' => false, 'candidatos' => count($cands), 'resumo' => $resumo,
            'aplicado' => false, 'escrita' => null];

    if ($aplicar) {
        $out['escrita']  = wa_import_aplica($plano, $inserir, $atualizar);
        $out['aplicado'] = true;
    }
    return $out;
}
