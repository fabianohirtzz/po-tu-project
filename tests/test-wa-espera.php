<?php
/* A LISTA DE ESPERA: quem pediu para ser avisado de um destino que a agencia
   nao tem data para vender.

   POR QUE TABELA PROPRIA, e nao `po_leads.espera` (um jsonb): filtrar por
   destino, saber QUANDO a pessoa pediu e remover UMA entrada sao as tres
   coisas que jsonb faz mal - e as tres sao o pedido do cliente de 04/11/2026
   ("na transmissao um seletor pra escolher qual pais de lista de espera
   existe, e assim puxa os numeros de todos aqueles leads").

   DUAS REGRAS QUE ESTE ARQUIVO GUARDA:

   1. A REMOCAO E SOFT. `removido_at` em vez de delete. Quem pediu para ser
      avisado da Italia e depois saiu da lista continua tendo pedido - e esse
      fato e o que sustenta a base legal da transmissao (legitimo interesse
      pela relacao existente, nao consentimento). Apagar a linha apagaria a
      prova de que o interesse existiu.

   2. PEDIR DUAS VEZES NAO CRIA DUAS LINHAS, e quem garante isso e o INDICE
      UNICO PARCIAL do banco (`(lead_id, destino) where removido_at is null`),
      nunca uma consulta antes de escrever. Consultar-e-depois-escrever tem
      corrida: duas mensagens da mesma pessoa chegando juntas passariam as
      duas pela consulta e criariam as duas linhas. O 409 e o cadeado
      funcionando, nao erro - e por isso ele NAO vira log de erro.

   Nenhum teste toca a rede: o transporte do wa-db e substituido por um
   emulador de PostgREST que honra o indice parcial. */
require __DIR__ . '/../lib/wa-espera.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }
ini_set('error_log', sys_get_temp_dir() . '/po-test-wa-espera.log');

const UID  = '11111111-1111-4111-8111-111111111111';
const UID2 = '22222222-2222-4222-8222-222222222222';

$ESPERA = [];     // as linhas
$CHAMADAS = [];   // [metodo, url] de cada chamada, para conferir a consulta
$FALHA = null;    // status para forcar erro de banco

/* Emulador minimo do PostgREST para po_wa_espera: POST honrando o indice
   unico parcial (409), PATCH casando lead_id/destino/removido_at. */
function esp_filtra($linhas, $query) {
    $out = [];
    foreach ($linhas as $i => $l) {
        $casa = true;
        foreach (explode('&', (string) $query) as $par) {
            if (strpos($par, '=') === false) continue;
            [$k, $v] = explode('=', $par, 2);
            if (strpos($v, 'eq.') === 0) {
                if ((string) ($l[$k] ?? '') !== urldecode(substr($v, 3))) { $casa = false; break; }
            } elseif ($v === 'is.null') {
                if (($l[$k] ?? null) !== null) { $casa = false; break; }
            } elseif ($v === 'not.is.null') {
                if (($l[$k] ?? null) === null) { $casa = false; break; }
            }
        }
        if ($casa) $out[$i] = $l;
    }
    return $out;
}

wa_db_set_transport(function ($metodo, $url, $corpo) {
    $GLOBALS['CHAMADAS'][] = [$metodo, $url];
    if ($GLOBALS['FALHA'] !== null) return ['status' => $GLOBALS['FALHA'], 'body' => '{}'];
    if (strpos($url, 'po_wa_espera') === false) {
        fwrite(STDERR, "ASSERT: tabela inesperada: $metodo $url\n");
        exit(1);
    }
    $query = (string) parse_url($url, PHP_URL_QUERY);

    if ($metodo === 'POST') {
        $l = json_decode((string) $corpo, true);
        $l = is_array($l) ? $l : [];
        $l += ['removido_at' => null, 'roteiro_slug' => null, 'criado_at' => '2026-11-05T00:00:00Z'];
        // o indice unico PARCIAL: so colide com linha ainda nao removida
        foreach ($GLOBALS['ESPERA'] as $x) {
            if (($x['lead_id'] ?? null) === ($l['lead_id'] ?? null)
                && ($x['destino'] ?? null) === ($l['destino'] ?? null)
                && ($x['removido_at'] ?? null) === null) {
                return ['status' => 409, 'body' => '{"code":"23505"}'];
            }
        }
        $l['id'] = 'esp-' . count($GLOBALS['ESPERA']);
        $GLOBALS['ESPERA'][] = $l;
        return ['status' => 201, 'body' => json_encode([$l])];
    }
    if ($metodo === 'PATCH') {
        $campos = json_decode((string) $corpo, true);
        $achadas = esp_filtra($GLOBALS['ESPERA'], $query);
        foreach (array_keys($achadas) as $i) {
            $GLOBALS['ESPERA'][$i] = array_merge($GLOBALS['ESPERA'][$i], is_array($campos) ? $campos : []);
        }
        return ['status' => 200, 'body' => json_encode(array_values(esp_filtra($GLOBALS['ESPERA'], $query)))];
    }
    if ($metodo === 'GET') {
        return ['status' => 200, 'body' => json_encode(array_values(esp_filtra($GLOBALS['ESPERA'], $query)))];
    }
    return ['status' => 500, 'body' => '{}'];
});

function esp_ativa($lead, $destino) {
    foreach ($GLOBALS['ESPERA'] as $l) {
        if ($l['lead_id'] === $lead && $l['destino'] === $destino && $l['removido_at'] === null) return $l;
    }
    return null;
}
function esp_qualquer($lead, $destino) {
    foreach ($GLOBALS['ESPERA'] as $l) {
        if ($l['lead_id'] === $lead && $l['destino'] === $destino) return $l;
    }
    return null;
}

/* ---------- anotar ---------- */
ok(wa_espera_anota(UID, 'Itália', null) === true, 'anota o pedido e devolve true');
ok(count($ESPERA) === 1, 'uma linha no banco');
ok($ESPERA[0]['lead_id'] === UID && $ESPERA[0]['destino'] === 'Itália', 'com o lead e o destino');
ok($ESPERA[0]['roteiro_slug'] === null, 'sem roteiro quando nao foi dado');
ok($ESPERA[0]['removido_at'] === null, 'e nasce ativa');

ok(wa_espera_anota(UID, 'Itália', null) === false, 'pedir duas vezes devolve false');
ok(count($ESPERA) === 1, 'e NAO cria uma segunda linha');

/* Quem decide isso e o indice do BANCO, nao uma consulta antes de escrever:
   o segundo pedido tem que ter chegado como POST e levado 409. Se a funcao
   consultasse antes, nao haveria POST nenhum na segunda chamada - e a
   corrida de duas mensagens simultaneas criaria as duas linhas. */
$posts = 0;
foreach ($CHAMADAS as [$m, $u]) if ($m === 'POST') $posts++;
ok($posts === 2, 'os dois pedidos foram POST: o cadeado e o indice unico parcial, nao uma consulta (deu: ' . $posts . ')');

/* Outro destino da mesma pessoa e outra linha: quem compara Italia e Japao
   espera as duas. */
ok(wa_espera_anota(UID, 'Japão', 'turquia') === true, 'o mesmo lead pode esperar outro destino');
ok(esp_ativa(UID, 'Japão')['roteiro_slug'] === 'turquia', 'e o roteiro de onde o pedido veio fica gravado');
ok(count($ESPERA) === 2, 'duas linhas');

/* Outra pessoa, mesmo destino. */
ok(wa_espera_anota(UID2, 'Itália', null) === true, 'outra pessoa pode esperar o mesmo destino');

/* ---------- remover: SOFT, sempre ---------- */
ok(wa_espera_remove(UID, 'Itália') === true, 'remove e devolve true');
ok(esp_ativa(UID, 'Itália') === null, 'a entrada nao esta mais ativa');
ok(esp_qualquer(UID, 'Itália') !== null, 'mas a LINHA continua existindo: a remocao e soft');
ok(esp_qualquer(UID, 'Itália')['removido_at'] !== null, 'com removido_at carimbado');
ok(count($ESPERA) === 3, 'nenhuma linha foi apagada (deu: ' . count($ESPERA) . ')');

/* E a remocao nao alcanca a outra pessoa nem o outro destino. */
ok(esp_ativa(UID2, 'Itália') !== null, 'a espera da outra pessoa pelo mesmo destino segue ativa');
ok(esp_ativa(UID, 'Japão') !== null, 'e o outro destino da mesma pessoa tambem');

/* Depois de removida, pedir de novo volta a valer: o indice e PARCIAL. */
ok(wa_espera_anota(UID, 'Itália', null) === true, 'quem saiu da lista pode voltar a entrar');
ok(count($ESPERA) === 4, 'e isso cria linha nova, sem reviver a antiga');
ok(esp_qualquer(UID, 'Itália')['removido_at'] !== null, 'a linha antiga segue removida');

/* A DATA EM QUE A PESSOA SAIU DA LISTA E DADO, e uma remocao nova nao pode
   reescreve-la. A linha antiga recebe uma data reconhecivel; depois a pessoa
   e removida OUTRA vez (a linha nova), e a data antiga tem que sair intacta.
   Sem o filtro `removido_at=is.null` no PATCH, as duas sao recarimbadas e o
   historico de quando cada passagem terminou se perde - de uma vez, para
   todas as linhas daquele par. */
$ESPERA[0]['removido_at'] = '2020-01-01T00:00:00Z';
ok(wa_espera_remove(UID, 'Itália') === true, 'remove a entrada nova');
ok($ESPERA[0]['removido_at'] === '2020-01-01T00:00:00Z',
   'e a data da saida ANTERIOR fica intacta (veio: ' . (string) $ESPERA[0]['removido_at'] . ')');
ok(esp_ativa(UID, 'Itália') === null, 'enquanto a entrada nova sai da lista');
ok(wa_espera_anota(UID, 'Itália', null) === true, 'e ela pode entrar de novo');

/* Remover o que nao existe nao e erro: o botao pode ser clicado duas vezes. */
ok(wa_espera_remove(UID2, 'Egito') === true, 'remover entrada que nao existe nao e erro');

/* O 409 DO INDICE PARCIAL NAO VIRA LOG DE ERRO. Pedir duas vezes e o cadeado
   funcionando, e e o caminho COMUM (a pessoa reescreve, o cron reentrega o
   evento). Se ele logasse, o arquivo encheria de linhas esperadas e o erro de
   verdade - banco fora, coluna faltando - ficaria escondido no meio delas,
   que e o unico lugar onde alguem procuraria. */
$log = sys_get_temp_dir() . '/po-test-wa-espera.log';
@unlink($log);
ok(wa_espera_anota(UID2, 'Itália', null) === false, 'guarda: este pedido ja existe');
ok(!file_exists($log) || trim((string) file_get_contents($log)) === '',
   'o 409 esperado nao escreveu nada no log (veio: ' . (file_exists($log) ? trim((string) file_get_contents($log)) : '') . ')');

/* ---------- guardas de entrada ---------- */
$antes = count($CHAMADAS);
ok(wa_espera_anota('nao-e-uuid', 'Itália', null) === false, 'lead_id que nao e uuid nao vira linha orfa');
ok(wa_espera_anota('', 'Itália', null) === false, 'lead_id vazio tambem nao');
ok(wa_espera_anota(UID, '', null) === false, 'destino vazio nao vira linha');
ok(wa_espera_anota(UID, '   ', null) === false, 'destino com so espacos tambem nao');
ok(wa_espera_remove('nao-e-uuid', 'Itália') === false, 'remocao com lead_id invalido nao chama o banco');
ok(wa_espera_remove(UID, '') === false, 'nem com destino vazio');
ok(count($CHAMADAS) === $antes, 'e NENHUMA das entradas invalidas chegou ao banco (deu: ' . (count($CHAMADAS) - $antes) . ' chamadas)');

/* O destino e aparado antes de gravar: " Itália " e o mesmo destino, senao o
   indice unico nao os reconheceria como iguais e o seletor da transmissao
   mostraria dois paises. */
ok(wa_espera_anota(UID2, '  Japão  ', null) === true, 'destino com espaco nas pontas entra');
ok(esp_ativa(UID2, 'Japão') !== null, 'aparado (veio: ' . json_encode(array_column($ESPERA, 'destino')) . ')');
ok(wa_espera_anota(UID2, 'Japão', null) === false, 'e por isso o mesmo destino aparado nao duplica');

/* ---------- erro de banco e DIFERENTE de "ja existia" ---------- */
$FALHA = 500;
@unlink($log);
ok(wa_espera_anota(UID2, 'Egito', null) === false, 'banco fora devolve false (nao finge sucesso)');
/* O PAR DA REGRA DO 409: o esperado nao loga, o inesperado LOGA. Sem este
   lado, "nao logar nada nunca" passaria verde nos dois - e um Supabase fora
   do ar deixaria de aparecer em qualquer lugar, com a tela dizendo que a
   pessoa entrou na lista. */
$l = file_exists($log) ? (string) file_get_contents($log) : '';
ok(strpos($l, 'wa_espera_anota') !== false && strpos($l, '500') !== false,
   'erro de banco na anotacao LOGA, com o status (veio: ' . trim($l) . ')');
ok(wa_espera_remove(UID2, 'Japão') === false, 'e a remocao tambem');
$FALHA = null;

echo "OK test-wa-espera\n";
