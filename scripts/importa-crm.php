<?php
/* ============================================================
   Pereira Oliveira Turismo - carga semente do CRM antigo (Task 9, spec 9.3).

   USO UNICO. Roda uma vez, na maquina do controlador, com o export do CRM
   ja baixado. NAO vai para o servidor por FTP - nao faz parte do deploy do
   site, e o arquivo de entrada e PII (CPF, RG, passaporte de gente real) que
   nunca pode ir para o Git nem para o cPanel.

   Uso:
     php scripts/importa-crm.php <arquivo.json>              DRY-RUN (padrao, nao escreve nada)
     php scripts/importa-crm.php <arquivo.json> --aplicar    escreve de verdade na po_leads

   Precisa de SUPABASE_URL e SUPABASE_SERVICE_KEY em config.local.php (fora
   do Git), carregados via lib/wa-config.php.

   O arquivo de entrada e o export bruto do CRM. O formato real e
   {"clients": [...fichas...], "trips": [...], "participations": [...], ...}
   - a raiz NAO e a lista de fichas, entao o script procura por ela dentro do
   JSON (wa_import_acha_fichas, em lib/wa-import-carga.php), em vez de supor
   que o proprio arquivo ja e o array.

   SEGURANCA:
   - Nunca imprime CPF, telefone, e-mail ou nome completo. So contagens
     agregadas (o resumo da auditoria) vao para a tela - o terminal vira
     log, e log com CPF e vazamento.
   - A leitura da base usa wa_db_select_estrito, que distingue "base vazia"
     de "erro de leitura" (o wa_db_select comum devolve [] nos dois casos).
     Se a leitura falhar - Supabase fora do ar, credencial invalida, ou uma
     resposta 200 que nao e JSON de verdade (proxy/portal cativo) - o script
     ABORTA antes de montar qualquer plano. Sem isso, uma base "vazia" por
     falha faria as 778 fichas entrarem como leads NOVOS e duplicar a base
     inteira em silencio (regra 3 da fusao: nunca duplicar).
   - O select da base vem de wa_import_select_base(), derivado da whitelist
     de colunas preenchiveis (WA_IMPORT_CAMPOS_PREENCHIVEIS). Um select mais
     curto faria wa_import_preenche() tratar toda coluna fora da lista como
     vazia e a carga do CRM SOBRESCREVERIA ficha ja preenchida no painel
     (regra 2 da fusao: a fusao so soma, nunca zera).
============================================================ */

require_once __DIR__ . '/../lib/wa-import.php';
require_once __DIR__ . '/../lib/wa-import-carga.php';
require_once __DIR__ . '/../lib/wa-db.php';   // ja carrega wa-config -> po-data -> config.local.php

const IMPORTA_CRM_ORIGEM = 'crm-toninho';
const IMPORTA_CRM_PAGINA = 1000;   // mesma paginacao de contatos-importar.php

function importa_crm_abortar($msg) {
    fwrite(STDERR, "ERRO: $msg\n");
    exit(1);
}

/* Leitura PAGINADA e ESTRITA da po_leads. Cada pagina passa por
   wa_db_select_estrito: se qualquer uma falhar (inclusive a primeira),
   devolve null na hora - nao ha "base parcial" aqui, so "base completa" ou
   "erro". Teto de 100 paginas (100 mil leads; a base de hoje tem ~800). */
function importa_crm_le_base() {
    $base = []; $off = 0;
    $sel  = wa_import_select_base() . '&order=id.asc&limit=' . IMPORTA_CRM_PAGINA;
    for ($i = 0; $i < 100; $i++) {
        $pg = wa_db_select_estrito('po_leads', $sel . '&offset=' . $off);
        if ($pg === null) return null;
        if (!$pg) break;             // pagina vazia: chegou ao fim de verdade
        foreach ($pg as $r) $base[] = $r;
        $off += count($pg);
    }
    return $base;
}

/* -------- CLI -------- */

if (php_sapi_name() !== 'cli') {
    importa_crm_abortar('rode via CLI: php scripts/importa-crm.php <arquivo.json> [--aplicar]');
}

if (!wa_import_origem_valida(IMPORTA_CRM_ORIGEM)) {
    // Nunca deveria acontecer (a constante e fixa), mas se a lista fechada de
    // origens mudar um dia e alguem esquecer de atualizar aqui, e melhor
    // abortar do que gravar leads com origem invalida.
    importa_crm_abortar('origem "' . IMPORTA_CRM_ORIGEM . '" nao esta na lista fechada de origens validas (WA_IMPORT_ORIGENS)');
}

$arq     = $argv[1] ?? '';
$aplicar = in_array('--aplicar', $argv, true);

if ($arq === '' || !is_file($arq)) {
    importa_crm_abortar('informe o caminho do arquivo JSON exportado do CRM. Uso: php scripts/importa-crm.php <arquivo.json> [--aplicar]');
}

$conteudo = file_get_contents($arq);
if ($conteudo === false) importa_crm_abortar("nao consegui ler o arquivo: $arq");

$dados = json_decode($conteudo, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    importa_crm_abortar('JSON invalido: ' . json_last_error_msg());
}

$achado = wa_import_acha_fichas($dados);
if (!is_array($achado['fichas']) || $achado['fichas'] === []) {
    importa_crm_abortar($achado['erro'] ?? 'nenhuma lista de fichas encontrada dentro do JSON');
}
$fichas = $achado['fichas'];

// Qual chave foi escolhida vai para a tela: e o unico jeito de quem roda
// perceber se a busca caiu na chave errada (ex.: o fallback por tamanho
// pegando 'participations' num arquivo sem nenhuma chave conhecida).
fwrite(STDERR, 'Chave usada no arquivo: ' . ($achado['chave'] ?? '(raiz do JSON, sem chave)') . "\n");
fwrite(STDERR, 'Fichas encontradas no arquivo: ' . count($fichas) . "\n");

$res = wa_import_executa(
    $fichas,
    IMPORTA_CRM_ORIGEM,
    'importa_crm_le_base',
    fn($linha) => wa_db_insert('po_leads', $linha),
    fn($id, $campos) => wa_db_update('po_leads', 'id=eq.' . rawurlencode((string) $id), $campos),
    $aplicar
);

if ($res['erro']) {
    importa_crm_abortar('leitura da base po_leads falhou (Supabase fora do ar, credencial invalida, ou resposta que nao e JSON de verdade) - abortando para nao duplicar fichas. Confira SUPABASE_URL/SUPABASE_SERVICE_KEY em config.local.php e tente de novo.');
}

echo "Fichas no arquivo: " . count($fichas) . "\n";
echo "Candidatos apos dedup (mesma pessoa em duas fichas do lote): " . $res['candidatos'] . "\n\n";
echo json_encode($res['resumo'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";

if (!$aplicar) {
    echo "\nDRY-RUN: nada foi gravado no banco. Rode com --aplicar para escrever de verdade.\n";
    exit(0);
}

echo "\nAplicado:\n" . json_encode($res['escrita'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

// Escrita com falhas nao pode sair com codigo 0: uma carga em que centenas
// de inserts falharam encerraria como sucesso, e ninguem checaria de novo.
exit(($res['escrita']['falhas'] ?? 0) > 0 ? 1 : 0);
