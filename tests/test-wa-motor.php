<?php
require __DIR__ . '/../lib/wa-motor.php';
require __DIR__ . '/fixtures/fluxo-motor.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ASSERT: $msg\n"); exit(1); } }

/* Simulador: o banco vira um array em memoria e o envio vira uma lista
   (tests/fixtures/fluxo-motor.php, que tambem traz o fluxo de triagem
   publicado e ligado). Nenhum teste toca a rede.

   Desde o interpretador (Plano A) o motor nao tem mais estados proprios de
   roteiro: quem decide o proximo passo e o grafo. Estes cenarios percorrem a
   triagem de ponta a ponta; as leis do motor tem arquivo proprio em
   tests/test-wa-fluxo-leis.php. */
$DB = fx_banco_inicial();
$ENVIADAS = [];
$QUERIES = [];

/* wa_camp_recibo (chamado por wa_processar no ramo de status, Task 7) usa
   wa_db_select_estrito/wa_db_update de wa-db.php diretamente, por fora da
   injecao de dependencia (wa_motor_set_deps) - de proposito, e a mesma
   funcao que tests/test-wa-camp-recibo.php testa isolada. Sem este mock, o
   teste de status faria uma chamada de rede DE VERDADE para o Supabase de
   producao (SUPABASE_URL vem hardcoded em po-data.php mesmo sem
   config.local.php), violando a regra do projeto de que nenhum teste toca a
   rede.

   Alem de responder, o transporte REGISTRA toda escrita (POST/PATCH) que
   passar por ele fora da injecao de dependencia. E o que permite ao teste de
   status flagrar uma escrita em po_wa_mensagens pelo caminho de baixo: hoje
   nada impede alguem de acrescentar `require_once .../wa-webhook.php` em
   wa-motor.php e fazer o ramo de status chamar wa_registra_evento - a
   guarda de whatsapp.php (travada por assercao de fonte em
   tests/test-wa-webhook.php) fica intacta e o defeito reaparece por
   dentro do motor, em silencio. */
$ESCRITAS_MENSAGENS = [];
wa_db_set_transport(function ($metodo, $url, $corpo) use (&$ESCRITAS_MENSAGENS) {
    if (in_array($metodo, ['POST', 'PATCH'], true) && strpos($url, 'po_wa_mensagens') !== false) {
        // Registra em vez de falhar direto: quem acusa isto e a assercao
        // dedicada do teste de status (`ok(!$ESCRITAS_MENSAGENS, ...)`).
        $ESCRITAS_MENSAGENS[] = [$metodo, $url, $corpo];
        return ['status' => 200, 'body' => '[]'];
    }
    /* So po_wa_envios e esperado aqui, pelo recibo de entrega (Task 7): no
       fluxo correto e a UNICA tabela que chega pela camada crua neste
       arquivo. Um simulador que responde "200, vazio" para QUALQUER tabela
       transforma toda chamada direta futura a wa_db_* em sucesso silencioso
       - foi assim que o vazamento de rede real para o Supabase de producao
       passou despercebido da primeira vez (ver task-7-report.md). */
    if (strpos($url, 'po_wa_envios') === false) {
        fwrite(STDERR, "ASSERT: chamada de banco nao simulada no teste do motor: $metodo $url\n");
        exit(1);
    }
    return ['status' => 200, 'body' => '[]'];
});

fx_instala();

$WA = '+5548900000001';
function ev($tipo, $texto, $extra = []) {
    global $WA;
    return array_merge(['tipo'=>$tipo,'wa_id'=>$WA,'wamid'=>'wamid.'.md5($texto.mt_rand()),
        'tipo_msg'=>'text','texto'=>$texto,'nome'=>'Maria','ad_id'=>null,'ctwa_clid'=>null,'ts'=>time()], $extra);
}
function clica($id, $tipo_msg = 'button_reply') { return ev('mensagem', $id, ['tipo_msg' => $tipo_msg]); }
function zera() {
    $GLOBALS['DB'] = fx_banco_inicial(); $GLOBALS['ENVIADAS'] = []; $GLOBALS['QUERIES'] = [];
    fx_instala();
    wa_motor_set_anuncios([]);
}

// --- 1. primeira mensagem: o fluxo responde com as boas-vindas e espera
$acao = wa_processar(ev('mensagem', 'oi queria saber da Turquia'));
ok($acao === 'fluxo', "primeira mensagem entra no fluxo (deu: $acao)");
ok($ENVIADAS[0][0] === 'buttons', 'as boas-vindas saem em botoes');
// o card de boas-vindas AINDA tem {nome} no texto (de proposito, o fixture simula um texto antigo):
// a chave tem que sair sem virar o nome do perfil ('Maria' no evento)
ok($ENVIADAS[0][2] === 'Oi! Sobre o que quer falar?', 'nome do perfil nao entra: {nome} sai e a pontuacao fecha (veio: ' . $ENVIADAS[0][2] . ')');
ok($DB['po_wa_conversas'][0]['estado'] === 'aguardando', 'a conversa espera');
ok($DB['po_wa_conversas'][0]['card_atual'] === 'boas', 'no card de boas-vindas');
ok($DB['po_wa_conversas'][0]['fluxo_versao_id'] === 'v-triagem', 'na versao publicada do fluxo');
ok($DB['po_leads'][0]['status'] === 'novo', 'lead entra como novo');

// --- 2. escolhe o roteiro: lista, PDF e a pergunta da data
$ENVIADAS = [];
ok(wa_processar(clica('rot')) === 'fluxo', 'escolher "um roteiro" abre a lista');
ok($ENVIADAS[0][0] === 'list' && $ENVIADAS[0][2] === 2, 'a lista traz os dois roteiros ativos');
$ENVIADAS = [];
ok(wa_processar(clica('turquia', 'list_reply')) === 'fluxo', 'escolher o roteiro manda o PDF');
ok($ENVIADAS[0][0] === 'doc' && strpos($ENVIADAS[0][2], 't.pdf') !== false, 'o PDF e o do roteiro certo');
ok($ENVIADAS[1][0] === 'buttons' && strpos($ENVIADAS[1][2], '10/05/27') !== false, 'e a pergunta da data sai logo depois, com a data do roteiro');
ok($DB['po_wa_conversas'][0]['card_atual'] === 'data' && $DB['po_wa_conversas'][0]['estado'] === 'aguardando', 'a conversa espera a resposta da data');
ok($DB['po_wa_conversas'][0]['roteiro_slug'] === 'turquia', 'o roteiro escolhido fica no contexto');
ok($DB['po_leads'][0]['interesses'] === ['turquia'], 'e vira interesse do lead');

// --- 3. a data serve: qualifica e a humana assume
$ENVIADAS = [];
wa_processar(clica('sim'));
ok($DB['po_leads'][0]['status'] === 'atendimento', 'lead vai para atendimento');
ok($DB['po_leads'][0]['qualif_data'] === true, 'carimba disponibilidade');
ok($DB['po_wa_conversas'][0]['card_atual'] === 'grupo', 'e pergunta do grupo');
wa_processar(clica('ja'));
ok($DB['po_wa_conversas'][0]['estado'] === 'humano', 'ao fim o fluxo entrega a conversa a humana');
ok(!empty($DB['po_wa_conversas'][0]['silenciado_at']), 'e o robo fica silenciado');

// --- 4. o eco cala o robo naquele contato, para sempre
$ENVIADAS = [];
$acao = wa_processar(ev('eco', 'Bom dia Maria, aqui e a Simone'));
ok($acao === 'ja_humano', "eco numa conversa ja entregue (deu: $acao)");
ok($ENVIADAS === [], 'o robo nao respondeu nada');
$acao = wa_processar(ev('mensagem', 'e quanto custa?'));
ok($acao === 'silenciado', "com humano no comando o robo continua calado (deu: $acao)");
ok($ENVIADAS === [], 'nada enviado mesmo com pergunta nova');

// --- 5. atalho de proposta, vindo do eco
$acao = wa_processar(ev('eco', '#proposta'));
ok($acao === 'proposta', "atalho #proposta move o funil (deu: $acao)");
ok($DB['po_leads'][0]['status'] === 'negociacao', 'lead em negociacao');
ok(!empty($DB['po_leads'][0]['proposta_at']), 'carimba proposta_at');

// --- 6. atalho de fechamento com valor
$acao = wa_processar(ev('eco', '#fechou 22900'));
ok($acao === 'venda', "atalho #fechou registra venda (deu: $acao)");
ok($DB['po_leads'][0]['status'] === 'venda', 'lead em venda');
ok((float) $DB['po_leads'][0]['venda'] === 22900.0, 'valor da venda gravado');
ok(!empty($DB['po_leads'][0]['venda_at']), 'carimba venda_at, que alimenta o ciclo');
// #contrato e o mesmo atalho, com o valor opcional e aceitando "R$"
$DB['po_leads'][0]['status'] = 'negociacao'; $DB['po_leads'][0]['venda'] = 0;
ok(wa_processar(ev('eco', '#contrato R$ 31.450,50')) === 'venda', '#contrato registra venda');
ok($DB['po_leads'][0]['status'] === 'venda' && (float) $DB['po_leads'][0]['venda'] === 31450.5, '#contrato com R$ grava o valor');
$DB['po_leads'][0]['status'] = 'negociacao'; $DB['po_leads'][0]['venda'] = 0;
ok(wa_processar(ev('eco', '#Contrato')) === 'venda', '#contrato sem valor tambem vale');
ok($DB['po_leads'][0]['status'] === 'venda' && (float) $DB['po_leads'][0]['venda'] === 0.0, 'sem valor: venda com valor 0, completado na gaveta');

// --- 7. PDF enviado por ela depois da entrega tambem marca proposta
zera();
wa_processar(ev('mensagem', 'me fala da escandinavia'));
wa_processar(clica('rot')); wa_processar(clica('escandinavia', 'list_reply'));
wa_processar(clica('sim')); wa_processar(clica('prim'));
$acao = wa_processar(ev('eco', 'proposta.pdf', ['tipo_msg' => 'document']));
ok($acao === 'proposta', "PDF enviado por ela marca proposta (deu: $acao)");

// --- 8. texto livre num card que espera: fallback, e a lista nao se repete
zera();
wa_processar(ev('mensagem', 'oi boa tarde'));
wa_processar(clica('rot'));
$ENVIADAS = [];
foreach (['quanto custa?'] as $frase) {
    wa_processar(ev('mensagem', $frase));
}
ok(!in_array('list', array_column($ENVIADAS, 0), true), 'a lista de roteiros sai uma vez so');
ok($DB['po_wa_conversas'][0]['card_atual'] === 'outra', 'a mensagem que o robo nao entende cai no fallback');
wa_processar(ev('mensagem', 'e voces tem parcelamento?'));
ok($DB['po_wa_conversas'][0]['estado'] === 'humano', 'e na segunda vez a conversa vai para a humana');

// --- 9. nao tem data: perde o lead sem gastar o tempo dela
zera();
wa_processar(ev('mensagem', 'quero saber da turquia'));
wa_processar(clica('rot')); wa_processar(clica('turquia', 'list_reply'));
$ENVIADAS = [];
wa_processar(clica('nao'));
ok($DB['po_leads'][0]['status'] === 'perdido', 'lead vai para perdido');
ok($DB['po_leads'][0]['qualif_data'] === false, 'com a data marcada como nao serve (e o que alimenta "perdeu por data")');
ok($DB['po_wa_conversas'][0]['estado'] === 'encerrado', 'e a conversa encerra');
ok($ENVIADAS[0][2] === 'Entendo. Quando houver outra data, avisamos.', 'a despedida tem {nome} no texto e sai sem o nome do perfil (veio: ' . $ENVIADAS[0][2] . ')');

// --- 10. anuncio mapeado define o roteiro do contexto
zera();
wa_motor_set_anuncios(['120210000' => 'escandinavia']);
$acao = wa_processar(ev('mensagem', 'oi', ['ad_id' => '120210000', 'ctwa_clid' => 'ARxyz']));
ok($acao === 'fluxo', "anuncio entra pela porta (deu: $acao)");
ok($DB['po_wa_conversas'][0]['roteiro_slug'] === 'escandinavia', 'o roteiro do anuncio entra no contexto');
ok($DB['po_leads'][0]['ctwa_clid'] === 'ARxyz', 'atribuicao do anuncio gravada no lead');
ok($DB['po_leads'][0]['origem'] === 'pago', 'e a origem vira pago');

// --- 10b. quem JA esta na base (CRM, agenda, site) e escreve pela primeira vez:
// a ficha e a dona (regra 1 do Armando). Antes, o primeiro contato fazia
// update com nome do perfil, status 'novo' e origem 'whatsapp': a venda
// voltava para "Contato feito" e saia do faturamento, em silencio.
zera();
$DB['po_leads'][] = ['id' => 'L1', 'wa_id' => $WA, 'telefone' => '(48) 90000-0001', 'nome' => 'Maria da Agenda',
    'status' => 'venda', 'venda' => 22900, 'origem' => 'importado', 'ad_id' => null, 'ctwa_clid' => null];
$acao = wa_processar(ev('mensagem', 'oi', ['ad_id' => '999', 'ctwa_clid' => 'ARabc']));
ok($acao === 'fluxo', "cliente da base tambem e atendido pelo fluxo (deu: $acao)");
ok(count($DB['po_leads']) === 1, 'nao cria ficha nova');
$L = $DB['po_leads'][0];
ok($L['nome'] === 'Maria da Agenda', 'o nome da ficha nao vira o nome do perfil');
ok($L['status'] === 'venda' && $L['venda'] === 22900, 'a venda continua venda');
ok($L['origem'] === 'importado', 'a origem nao muda');
ok($L['telefone'] === '(48) 90000-0001', 'o telefone editado a mao nao e sobrescrito');
ok($L['ad_id'] === '999' && $L['ctwa_clid'] === 'ARabc', 'a atribuicao do anuncio entra no que estava vazio');
zera();
$DB['po_leads'][] = ['id' => 'L2', 'wa_id' => $WA, 'telefone' => $WA, 'nome' => '', 'status' => 'semresposta', 'origem' => 'importado'];
wa_processar(ev('mensagem', 'oi'));
ok($DB['po_leads'][0]['nome'] === 'Maria', 'ficha sem nome ganha o nome do perfil');

// --- 10c. CLIENTE da base (aprovado na Revisao): o robo nao atende, a conversa
// nasce com a equipe (decisao de 25/11/2026: robo so para numero novo).
zera();
$DB['po_leads'][] = ['id' => 'L3', 'wa_id' => $WA, 'telefone' => $WA, 'nome' => 'Dona Cliente', 'status' => 'semresposta',
    'origem' => 'importado', 'cliente' => true];
$ENVIADAS = [];
$acao = wa_processar(ev('mensagem', 'oi Lais, tudo bem?'));
ok($acao === 'cliente_base', "cliente da base nao entra no fluxo (deu: $acao)");
ok(!$ENVIADAS, 'o robo nao manda nada para cliente da base');
ok($DB['po_wa_conversas'][0]['estado'] === 'humano', 'a conversa nasce com a equipe');
ok(!empty($DB['po_leads'][0]['notas']), 'e a ficha explica por que o robo nao atendeu');
$ENVIADAS = [];
ok(wa_processar(ev('mensagem', 'quero ver as viagens')) === 'silenciado', 'e segue calado nas mensagens seguintes');
ok(!$ENVIADAS, 'nada sai');
// quem ainda nao e cliente (pendente ou "nao e cliente") continua com o robo
zera();
$DB['po_leads'][] = ['id' => 'L4', 'wa_id' => $WA, 'telefone' => $WA, 'nome' => 'Lead', 'status' => 'semresposta', 'cliente' => false];
ok(wa_processar(ev('mensagem', 'oi')) === 'fluxo', 'ficha que nao e cliente segue no fluxo');

// --- 10d. SO NUMERO NOVO (25/11/2026, depois do primeiro teste real). O robo
// entrou na conversa de uma cliente que falava com a Lais e nao estava na base.
// Fica com a equipe: quem esta na agenda do celular dela (po_wa_agenda, carregada
// do .vcf) e quem entrou por importacao (origem_import), aprovado ou nao.
zera();
$DB['po_wa_agenda'][] = ['wa_id' => $WA, 'acao' => 'nao_atender', 'origem' => 'agenda-esposa'];
$ENVIADAS = [];
$acao = wa_processar(ev('mensagem', 'Ok, agradeço.'));
ok($acao === 'agenda', "contato da agenda dela nao entra no fluxo (deu: $acao)");
ok(!$ENVIADAS, 'o robo nao manda nada para contato da agenda');
ok($DB['po_wa_conversas'][0]['estado'] === 'humano', 'a conversa nasce com a equipe');
ok(!$DB['po_leads'], 'contato da agenda nao vira lead no funil');
ok(wa_processar(ev('mensagem', 'conhecer as viagens')) === 'silenciado' && !$ENVIADAS, 'e segue calado');

zera();
$DB['po_leads'][] = ['id' => 'L5', 'wa_id' => $WA, 'telefone' => $WA, 'nome' => 'Do Toninho', 'status' => 'semresposta',
    'origem_import' => 'agenda-marido', 'revisado' => false, 'cliente' => false];
$ENVIADAS = [];
ok(wa_processar(ev('mensagem', 'oi')) === 'cliente_base' && !$ENVIADAS, 'contato importado, mesmo pendente na Revisao, fica com a equipe');

// numero de TESTE: atendido sempre, mesmo estando na agenda e na base
zera();
$DB['po_wa_agenda'][] = ['wa_id' => $WA, 'acao' => 'teste', 'origem' => 'equipe'];
$DB['po_leads'][] = ['id' => 'L6', 'wa_id' => $WA, 'telefone' => $WA, 'nome' => 'Testador', 'status' => 'semresposta',
    'origem_import' => 'agenda-esposa', 'cliente' => true];
ok(wa_processar(ev('mensagem', 'oi')) === 'fluxo', 'numero de teste passa por cima da agenda e da base');

// banco fora do ar na consulta da agenda: nao arrisca o robo num conhecido, e
// nao prende o numero com a equipe para sempre (a proxima mensagem tenta de novo)
zera();
wa_motor_set_deps(['select_estrito' => function ($t, $q) { return $t === 'po_wa_agenda' ? null : fx_consulta($t, $q); }]);
$ENVIADAS = [];
ok(wa_processar(ev('mensagem', 'oi')) === 'agenda_indisponivel', 'erro de leitura da agenda nao atende');
ok(!$ENVIADAS && !$DB['po_wa_conversas'] && !$DB['po_leads'], 'nada sai e nada fica gravado');
fx_instala();
ok(wa_processar(ev('mensagem', 'oi')) === 'fluxo', 'com o banco de volta, o numero novo e atendido');

// --- 10f. CHAMOU A LAIS PELO NOME (26/11/2026). Conhecidos fora da agenda e da
// base ("Oi Lais, bom dia", "Falaaa Laiiiss") recebiam as boas-vindas.
ok(wa_chamou_lais('Oi Lais, bom dia, como vai?'), 'Lais');
ok(wa_chamou_lais('Bom dia Laís, tudo bem contigo?'), 'Laís com acento');
ok(wa_chamou_lais('Falaaa Laiiiss, como você está??'), 'Laiiiss');
ok(wa_chamou_lais('LAIS!'), 'maiusculas');
ok(!wa_chamou_lais('Olá! Posso ter mais informações sobre isso?'), 'mensagem padrao do anuncio nao conta');
ok(!wa_chamou_lais('Falei com a Laisa ontem'), 'outro nome que comeca igual nao conta');
ok(!wa_chamou_lais('Olá! Vim pelo site e quero mais informações. (ref S-QGGGC)'), 'mensagem do site nao conta');

zera();
$ENVIADAS = [];
$acao = wa_processar(ev('mensagem', 'Oi Lais, bom dia, como vai?'));
ok($acao === 'chamou_lais', "numero novo que chama a Lais pelo nome nao entra no fluxo (deu: $acao)");
ok(!$ENVIADAS, 'o robo nao manda nada');
ok($DB['po_wa_conversas'][0]['estado'] === 'humano', 'a conversa nasce com a equipe');
ok(!$DB['po_leads'], 'e nao vira lead no funil');
ok(wa_processar(ev('mensagem', 'conhecer as viagens')) === 'silenciado' && !$ENVIADAS, 'e segue calado');

// no meio do fluxo: o robo sai e a ficha explica por que
zera();
ok(wa_processar(ev('mensagem', 'oi')) === 'fluxo', 'numero novo entra no fluxo');
$ENVIADAS = [];
ok(wa_processar(ev('mensagem', 'na verdade queria falar com a Laís')) === 'chamou_lais' && !$ENVIADAS, 'chamou a Lais no meio do fluxo: o robo sai');
ok($DB['po_wa_conversas'][0]['estado'] === 'humano', 'a conversa passa para a equipe');
ok(!$DB['po_leads'] || !empty($DB['po_leads'][0]['notas']), 'a ficha, se existir, explica por que');

// --- 10g. PRIMEIRA MENSAGEM QUE O ROBO NAO LE (26/11/2026): "unsupported" e
// reacao nao sao lead chegando. Fica com a equipe e nao nasce ficha.
foreach (['unsupported', 'reaction'] as $tp) {
    zera();
    $ENVIADAS = [];
    $acao = wa_processar(ev('mensagem', '', ['tipo_msg' => $tp]));
    ok($acao === 'nao_lido', "primeira mensagem $tp nao entra no fluxo (deu: $acao)");
    ok(!$ENVIADAS && !$DB['po_leads'], "$tp: nada sai e nao vira lead");
    ok($DB['po_wa_conversas'][0]['estado'] === 'humano', "$tp: a conversa nasce com a equipe");
}
// audio e imagem de numero novo seguem com o robo (lead pode mandar audio)
zera();
ok(wa_processar(ev('mensagem', '', ['tipo_msg' => 'audio'])) === 'fluxo', 'audio de numero novo segue no fluxo');
// no meio do fluxo a regra nao vale (so a primeira mensagem decide)
zera();
wa_processar(ev('mensagem', 'oi'));
ok(wa_processar(ev('mensagem', '', ['tipo_msg' => 'reaction'])) !== 'nao_lido', 'reacao no meio do fluxo nao tira o robo');

// numero de teste segue atendido mesmo chamando a Lais
zera();
$DB['po_wa_agenda'][] = ['wa_id' => $WA, 'acao' => 'teste', 'origem' => 'equipe'];
ok(wa_processar(ev('mensagem', 'Laís, a principio o robo ta tudo bem')) === 'fluxo', 'numero de teste ignora a regra do nome');

// --- 10e. MENSAGENS FORA DE ORDEM (segundo teste real, 25/11/2026). O Carlos
// mandou 3 mensagens em 6 segundos e tocou no mesmo botao de duas boas-vindas.
// (1) clique num botao de mensagem ANTERIOR e ignorado em silencio, em vez de
// cair no "nao entendi"; (2) mensagem enviada ANTES de a pergunta atual sair
// nao gera resposta; (3) a mesma pessoa nunca e processada em paralelo.
zera();
wa_processar(ev('mensagem', 'oi'));
wa_processar(clica('rot'));                       // boas -> lista de roteiros
ok($DB['po_wa_conversas'][0]['card_atual'] === 'lista', 'o primeiro toque leva a lista');
$ENVIADAS = [];
ok(wa_processar(clica('rot')) === 'clique_antigo', 'segundo toque no mesmo botao antigo e ignorado');
ok(!$ENVIADAS, 'nada sai: nem "nao entendi", nem a lista de novo');
ok($DB['po_wa_conversas'][0]['card_atual'] === 'lista', 'e a conversa continua onde estava');
ok(wa_processar(clica('turquia', 'list_reply')) === 'fluxo' && $ENVIADAS, 'a escolha da lista atual segue valendo');
$ENVIADAS = [];
ok(wa_processar(clica('rot')) === 'clique_antigo' && !$ENVIADAS, 'botao das boas-vindas no card da data tambem e ignorado');
ok(wa_processar(clica('sim')) === 'fluxo', 'o botao da pergunta atual funciona');

// (2) rajada: mensagem com hora ANTERIOR a entrega da pergunta atual
zera();
wa_processar(ev('mensagem', 'Olá! Vim pelo site e quero mais informações.'));
$DB['po_wa_conversas'][0]['aguardando_desde'] = gmdate('c', time() + 5);   // a pergunta saiu depois
$ENVIADAS = [];
ok(wa_processar(ev('mensagem', 'Teste', ['ts' => time()])) === 'antes_da_pergunta', 'mensagem escrita antes da pergunta nao e respondida');
ok(!$ENVIADAS && $DB['po_wa_conversas'][0]['card_atual'] === 'boas', 'nada sai e nada muda');
ok(wa_processar(ev('mensagem', 'Teste', ['ts' => time() + 10])) === 'fluxo', 'depois da pergunta, a resposta e lida');

// (3) trava por contato: enquanto um processo segura, outro nao entra
$t1 = wa_trava('+5548900000077', false);
ok($t1 !== null, 'a trava e obtida');
ok(wa_trava('+5548900000077', false) === null, 'a mesma pessoa nao e processada duas vezes ao mesmo tempo');
ok(wa_trava('+5548900000078', false) !== null, 'outra pessoa nao espera');
wa_destrava($t1);
ok(($t2 = wa_trava('+5548900000077', false)) !== null, 'solta, a proxima entra');
wa_destrava($t2);
$fonte = str_replace(chr(13), '', file_get_contents(__DIR__ . '/../lib/wa-motor.php'));
ok(preg_match('/function wa_processar\(\$ev\) \{\s*\$trava = wa_trava\(/', $fonte) === 1, 'wa_processar entra pela trava');

// --- 11. roteiro sem PDF nao pode travar: manda o link da pagina
zera();
wa_processar(ev('mensagem', 'oi'));
wa_processar(clica('rot'));
wa_motor_set_deps(['roteiros' => function () { return [['slug'=>'turquia','titulo'=>'Turquia','pdf_url'=>null,'data_label'=>'10/05/27']]; }]);
$ENVIADAS = [];
wa_processar(clica('turquia', 'list_reply'));
ok($ENVIADAS[0][0] === 'text', 'sem PDF manda texto com o link');
ok(strpos($ENVIADAS[0][2], '/roteiros/turquia') !== false, 'o link e o da pagina do roteiro');

// --- 12. envio que falha nao avanca o estado nem marca o lead
zera();
wa_motor_set_deps(['send_buttons' => function ($para, $corpo, $botoes) {
    return ['ok' => false, 'wamid' => null, 'erro' => 'timeout'];
}]);
$acao = wa_processar(ev('mensagem', 'quero saber da turquia'));
ok($acao === 'falha_envio', "envio que falha nao finge que a resposta saiu (deu: $acao)");
ok(empty($DB['po_wa_conversas'][0]['card_atual']) && $DB['po_wa_conversas'][0]['estado'] === 'novo', 'a conversa NAO avancou');
ok(!isset($DB['po_leads'][0]['interesses']), 'lead nao fica marcado com um roteiro que o cliente nunca recebeu');
ok($ENVIADAS === [], 'nada sai depois de um envio que falhou');

/* --- 13. cliente que volta a falar zera o relogio do timeout. O cron
   decide pelo lembrete_at e ignora o aguardando_desde depois que ele
   existe: um lembrete velho encerrava como perdida uma conversa em que o
   cliente tinha escrito minutos antes. */
zera();
wa_processar(ev('mensagem', 'quero saber da turquia'));
$DB['po_wa_conversas'][0]['lembrete_at'] = gmdate('c', time() - 86400);
wa_processar(ev('mensagem', 'oi, ainda estou pensando'));
ok($DB['po_wa_conversas'][0]['lembrete_at'] === null, 'mensagem do cliente zera o lembrete_at');
// E o aguardando_desde recomeca junto: zerar so o lembrete deixaria as 24h
// ja vencidas e o cron mandaria outro lembrete na varredura seguinte.
ok(strtotime($DB['po_wa_conversas'][0]['aguardando_desde']) >= time() - 5, 'e o relogio da espera recomeca junto');

/* --- 14. catalogo vazio manda texto, nunca uma lista impossivel. A Graph
   API recusa lista com zero linhas: a mensagem inteira nao sai e o cliente
   escreve sem receber resposta. */
zera();
wa_processar(ev('mensagem', 'oi, boa tarde'));
wa_motor_set_deps(['roteiros' => function () { return []; }]);
$ENVIADAS = [];
wa_processar(clica('rot'));
ok(count($ENVIADAS) === 1 && $ENVIADAS[0][0] === 'text', 'mandou texto, nao lista');
ok($DB['po_wa_conversas'][0]['estado'] === 'aguardando', 'e fica esperando o destino');

/* --- 15. audio nunca pode virar silencio e depois "perdido". wa_texto_msg
   devolve '' para audio, imagem e figurinha; o publico 60+ responde por
   audio. Agora o fallback repete as opcoes uma vez e na segunda chama a
   humana: o cliente nunca fica sem resposta. */
zera();
wa_processar(ev('mensagem', 'quero saber da turquia'));
$acao = wa_processar(ev('mensagem', '', ['tipo_msg' => 'audio']));
ok($acao === 'fluxo' && $DB['po_wa_conversas'][0]['card_atual'] === 'outra', 'audio recebe as opcoes de novo');
$acao = wa_processar(ev('mensagem', '', ['tipo_msg' => 'audio']));
ok($DB['po_wa_conversas'][0]['estado'] === 'humano', 'na segunda a conversa vai para atendimento humano');
$notas = $DB['po_leads'][0]['notas'] ?? [];
ok(count($notas) === 1, 'a dona ve na ficha do lead por que a conversa parou com o robo');

/* --- 16. lead criado pelo eco nasce com telefone. Quando ela inicia a
   conversa pelo celular, o robo nunca viu aquele contato: o lead entrava so
   com status e carimbo, e aparecia no painel como "(sem nome)" com telefone
   "-". */
zera();
$acao = wa_processar(ev('eco', '#proposta'));
ok($acao === 'proposta', "atalho no primeiro contato pelo celular move o funil (deu: $acao)");
ok($DB['po_leads'][0]['telefone'] === $WA, 'o lead nasce com o telefone em E.164');
ok($DB['po_leads'][0]['wa_id'] === $WA, 'e com o wa_id, que liga a conversa ao lead');

/* --- 17. o eco do proprio envio do robo nao pode silenciar o robo. Nada no
   payload da Meta separa "a dona digitou" de "nos enviamos pela API". */
zera();
wa_processar(ev('mensagem', 'quero saber da turquia'));
$saidas = array_values(array_filter($DB['po_wa_mensagens'], fn($l) => $l['autor'] === 'robo'));
ok(count($saidas) === 1, 'as boas-vindas entram no log como saida do robo (deu: ' . count($saidas) . ')');
ok($saidas[0]['direcao'] === 'out' && $saidas[0]['tipo'] === 'buttons', 'registradas como botoes de saida');
$meu_wamid = $saidas[0]['wamid'];
$acao = wa_processar(ev('eco', 'Oi, Maria', ['wamid' => $meu_wamid]));
ok($acao === 'eco_do_robo', "eco com wamid nosso e ignorado (deu: $acao)");
ok($DB['po_wa_conversas'][0]['estado'] === 'aguardando', 'o robo NAO foi silenciado pelo proprio envio');
$acao = wa_processar(ev('eco', 'Oi, aqui e a Simone'));
ok($acao === 'silenciou', "eco de wamid desconhecido continua sendo handoff (deu: $acao)");
ok($DB['po_wa_conversas'][0]['estado'] === 'humano', 'a fala da dona silencia o robo');

// --- interpretacao da resposta sobre a data (a unica que desqualifica)
ok(wa_resposta_data('sim')                    === true,  'sim');
ok(wa_resposta_data('Tenho sim!')             === true,  'tenho sim');
ok(wa_resposta_data('claro, pode ser')        === true,  'claro');
ok(wa_resposta_data('nao consigo nessa data') === false, 'nao');
ok(wa_resposta_data('infelizmente nao')       === false, 'infelizmente nao');
ok(wa_resposta_data('qual o valor?')          === null,  'pergunta nao e sim nem nao');

// --- incerteza nao pode virar decisao (revisao: Important 2, medido com o publico real)
ok(wa_resposta_data('acho que sim, mas preciso ver com meu marido') === null, 'duvida com o marido nao qualifica sozinha');
ok(wa_resposta_data('nao sei ainda')                                === null, 'nao sei ainda nao e um nao');
ok(wa_resposta_data('sim, mas so em outubro')                       === null, 'confirmacao com ressalva de data fica em duvida');
ok(wa_resposta_data('pode ser que sim')                             === null, '"pode ser que" e duvida, diferente de "pode ser" sozinho');

/* --- "tenho a data, mas nunca viajei em grupo" e lead BOM (revisao: Critical 2)
   Sao as quatro frases medidas na revisao, que antes viravam todas
   desqualificado + perdido. Numa operadora que vende viagem em grupo,
   quem nunca viajou em grupo e exatamente o cliente-alvo. */
$mistas = [
    'Tenho disponibilidade sim, mas nunca viajei em grupo',
    'Tenho a data sim. Nunca viajei em grupo, seria a primeira vez',
    '1 sim 2 nao',
    'Posso sim, e nao, nunca viajei em grupo',
];
foreach ($mistas as $frase) {
    ok(wa_resposta_data($frase)  === true,  "a data esta confirmada em: $frase");
    ok(wa_resposta_grupo($frase) === false, "e o 'nunca em grupo' fica so como informacao em: $frase");
}

// Um "sim" solto responde a pergunta 1. A 2 continua sem resposta: carimbar
// qualif_grupo aqui seria inventar dado que a dona le no painel como do cliente.
ok(wa_resposta_data('sim')  === true, 'sim solto confirma a data');
ok(wa_resposta_grupo('sim') === null, 'sim solto NAO diz nada sobre viagem em grupo');
ok(wa_resposta_grupo('sim, ja viajei em grupo') === true, 'evidencia explicita de grupo e lida');

/* --- 19. evento de status (entregue/lido) nao pode virar conversa com o
   robo. wa_processar recebe TODO evento que whatsapp.php desempacota,
   status inclusive, e um robo respondendo a um recibo de entrega seria o
   pior desfecho possivel: ele trata isto na primeira linha
   (`if ($ev['tipo'] === 'status') return 'status';`), e este teste trava
   que ninguem remova essa guarda por engano. E defesa em profundidade, e
   nao substitui a asserção de fonte em tests/test-wa-webhook.php, que
   cuida do ponto de chamada. */
$DB['po_wa_conversas'] = []; $DB['po_leads'] = []; $DB['po_wa_mensagens'] = []; $ENVIADAS = [];
$ESCRITAS_MENSAGENS = [];
$acao = wa_processar([
    'tipo' => 'status', 'wa_id' => $WA, 'wamid' => 'wamid.STATUS1',
    'tipo_msg' => 'status', 'texto' => 'delivered', 'nome' => null,
    'ad_id' => null, 'ctwa_clid' => null, 'ts' => time(),
]);
ok($acao === 'status', "evento de status devolve 'status' (deu: $acao)");
ok(count($ENVIADAS) === 0, 'nenhum envio acontece para um evento de status');
ok($DB['po_wa_conversas'] === [], 'evento de status nao cria nem altera conversa');
ok($DB['po_leads'] === [], 'evento de status nao cria nem altera lead');

/* Evento de status nao pode ESCREVER em po_wa_mensagens, nunca. O wamid de um
   status e o da mensagem original e a coluna e unica: gravar ali faz o recibo e o
   eco disputarem a MESMA linha, o eco vira 'duplicado', o handoff morre e o robo
   passa a falar por cima da atendente. Hoje isso e impedido em whatsapp.php (e
   travado la por assercao de fonte), mas nada impediria o motor de reintroduzir o
   defeito por dentro. Esta assercao impede. */
ok(!$ESCRITAS_MENSAGENS,
   'evento de status nao escreve em po_wa_mensagens, nem pelo motor');
/* A assercao acima olha o transporte cru. Mas o idioma do motor para escrever nesta
   tabela e wa_call (injecao de dependencia), que no teste grava direto em $DB e nunca
   passa pelo transporte. Sem esta segunda linha, injetar o defeito real no ramo de
   status deixa a suite inteira VERDE - foi medido. */
ok($DB['po_wa_mensagens'] === [], 'nem pela injecao de dependencia');

/* ---------- pontuacao quando o nome vem vazio ----------
   O {nome} sai do perfil do WhatsApp de quem escreve (contacts[0].profile.name
   em lib/wa-webhook.php) e vem NULO quando a pessoa nao tem nome no perfil ou
   quando o payload chega sem o bloco contacts. Nos lembretes o nome vem do
   lead, que tambem pode estar vazio.

   A limpeza so juntava pontuacao IGUAL, entao "Perfeito, {nome}." virava
   "Perfeito,." e saia assim no WhatsApp do cliente. A unica forma que escapava
   era "Oi {nome},", o que na pratica obrigava todo texto da tela a comecar por
   saudacao. Os textos do seed tinham exatamente este defeito. */
$casos = [
    ['Perfeito, {nome}.',                     'Perfeito.'],
    ['Entendo, {nome}. Vou registrar.',       'Entendo. Vou registrar.'],
    ['Que boa noticia, {nome}!',              'Que boa noticia!'],
    ['Fechado, {nome}?',                      'Fechado?'],
    ['Aqui esta o roteiro do Japao, {nome}.', 'Aqui esta o roteiro do Japao.'],
    ['Oi {nome}, tudo bem?',                  'Oi, tudo bem?'],
];
foreach ($casos as $c) {
    $saiu = wa_limpa_pontuacao(preg_replace('/\{[a-z_]+\}/', '', $c[0]));
    ok($saiu === $c[1], "nome vazio em [{$c[0]}] deve virar [{$c[1]}], saiu [$saiu]");
}
// Com o nome presente nada pode ser comido.
ok(wa_limpa_pontuacao('Perfeito, Marlene.') === 'Perfeito, Marlene.',
   'com nome presente a virgula do vocativo fica de pe');
ok(wa_limpa_pontuacao('Leve documento, passagem e seguro.') === 'Leve documento, passagem e seguro.',
   'virgula comum no meio da frase nao e tocada');

/* ---------- SAIR ----------
   Defesa 3 da spec 8.1. O texto da transmissao promete "Responda SAIR para
   nao receber mais", e a promessa tem que valer.

   O casamento e ESTRITO: so a palavra sozinha. "cancelar" ficou de fora de
   proposito - numa agencia de viagem "quero cancelar" quase sempre e
   cancelar uma RESERVA, e tratar isso como descadastro tiraria da lista
   justamente quem esta em negociacao. */
ok(wa_e_saida('SAIR') === true,        'SAIR maiusculo sai');
ok(wa_e_saida('sair') === true,        'sair minusculo sai');
ok(wa_e_saida('  Sair. ') === true,    'com espaco e ponto ainda sai');
ok(wa_e_saida('parar') === true,       'parar tambem sai');
ok(wa_e_saida('descadastrar') === true,'descadastrar tambem sai');
ok(wa_e_saida('quero sair do grupo') === false,
   'a palavra no meio da frase NAO desinscreve: "sair do grupo" e outra coisa');
ok(wa_e_saida('quero cancelar minha reserva') === false,
   'cancelar reserva nunca e descadastro');
/* "cancelar" fica FORA da lista de proposito, e isto e decisao de produto e nao de
   implementacao: numa agencia de viagem "quero cancelar" quase sempre e cancelar uma
   RESERVA. Tratar como descadastro tiraria da lista justamente quem esta negociando.
   A frase inteira ja era barrada pela ancora; esta assercao e o que impede alguem
   acrescentar a palavra a lista sem perceber o que esta desfazendo. */
ok(wa_e_saida('cancelar') === false,
   'a palavra cancelar sozinha NAO descadastra: em viagem, cancelar e sobre reserva');
ok(wa_e_saida('') === false,           'texto vazio nao desinscreve');

$DB['po_wa_conversas'] = []; $DB['po_leads'] = []; $DB['po_wa_mensagens'] = []; $ENVIADAS = [];
$acao = wa_processar([
    'tipo' => 'mensagem', 'wa_id' => $WA, 'wamid' => 'wamid.SAIR1',
    'tipo_msg' => 'text', 'texto' => 'SAIR', 'nome' => 'Marlene',
    'ad_id' => null, 'ctwa_clid' => null, 'ts' => time(),
]);
ok($acao === 'opt_out', "SAIR devolve 'opt_out' (deu: $acao)");
ok(!empty($DB['po_leads'][0]['opt_out_at']), 'opt_out_at e carimbado no lead');
ok(count($ENVIADAS) === 1, 'uma confirmacao e enviada');
ok(stripos($ENVIADAS[0][2], 'não') !== false
   || stripos($ENVIADAS[0][2], 'nao') !== false,
   'a confirmacao diz que a pessoa nao recebera mais');
/* E NAO promete a volta. Nada em lugar nenhum limpa opt_out_at - nem o motor,
   nem o painel, nem o endpoint da transmissao -, entao "se um dia quiser
   voltar, e so escrever aqui" era promessa a um cliente que o sistema nao
   cumpre: ela escreve, nada acontece, e a agencia fica com a conta. Se algum
   dia existir o caminho de volta, esta linha cai junto com ele. */
ok(!preg_match('/quiser voltar|voltar a receber/i', $ENVIADAS[0][2]),
   'a confirmacao NAO promete um retorno que nenhum codigo do projeto executa');

/* SAIR tem que valer mesmo com o robo JA silenciado naquele contato: o
   silencio existe para o robo nao falar por cima da humana, nao para a
   pessoa perder o direito de sair da lista. O carimbo acontece sempre; a
   RESPOSTA e que nao sai, para nao atropelar a conversa humana. */
$DB['po_wa_conversas'] = []; $DB['po_leads'] = []; $ENVIADAS = [];
wa_conversa_set($WA, ['estado' => 'humano', 'silenciado_at' => gmdate('c')]);
$acao = wa_processar([
    'tipo' => 'mensagem', 'wa_id' => $WA, 'wamid' => 'wamid.SAIR2',
    'tipo_msg' => 'text', 'texto' => 'sair', 'nome' => 'Marlene',
    'ad_id' => null, 'ctwa_clid' => null, 'ts' => time(),
]);
ok($acao === 'opt_out', 'SAIR vale mesmo com o robo silenciado');
ok(!empty($DB['po_leads'][0]['opt_out_at']), 'o carimbo acontece mesmo silenciado');
ok(count($ENVIADAS) === 0, 'mas o robo NAO responde por cima da humana');

/* --- 20. digitar vale como escolha SO no card `roteiros`. Nele os roteiros
   saem como texto numerado, nao ha o que tocar, e o publico 60+ digita. Em
   `botoes` e `lista` o texto segue descartado: o cliente que escreve "sim"
   em vez de tocar nao pode escolher o botao Sim sem querer. */
function fx_com_card_roteiros() {
    zera();
    $g =& $GLOBALS['DB']['po_wa_fluxo_versoes'][0]['grafo'];
    $g['cards']['boas'] = fx_card('boas', 'roteiros', ['texto' => 'Qual viagem?', 'proximo' => 'pdf', 'fallback' => 'outra']);
    unset($g);
    // O catalogo do fixture nao traz `ativo`, que o card roteiros exige (so lista o que esta a venda).
    wa_motor_set_deps(['roteiros' => function () {
        return array_map(function ($r) { return $r + ['ativo' => true, 'status_viagem' => null]; }, fx_roteiros());
    }]);
}

// 20a. card roteiros: "2" digitado escolhe o segundo da lista enviada
fx_com_card_roteiros();
wa_processar(ev('mensagem', 'oi'));
ok($DB['po_wa_conversas'][0]['card_atual'] === 'boas', '20a: a conversa espera no card roteiros');
$ENVIADAS = [];
wa_processar(ev('mensagem', '2'));
ok($DB['po_wa_conversas'][0]['roteiro_slug'] === 'escandinavia', '20a: no card roteiros, digitar "2" escolhe o segundo roteiro (veio: ' . var_export($DB['po_wa_conversas'][0]['roteiro_slug'] ?? null, true) . ')');
ok($ENVIADAS && $ENVIADAS[0][0] === 'doc' && strpos($ENVIADAS[0][2], 'e.pdf') !== false, '20a: e o PDF do roteiro escolhido sai');

// 20b. o clique segue escolhendo no card roteiros
fx_com_card_roteiros();
wa_processar(ev('mensagem', 'oi'));
wa_processar(clica('1'));
ok($DB['po_wa_conversas'][0]['roteiro_slug'] === 'turquia', '20b: o clique continua escolhendo no card roteiros');

// 20c. texto que nao casa nada no card roteiros cai no fallback
fx_com_card_roteiros();
wa_processar(ev('mensagem', 'oi'));
wa_processar(ev('mensagem', 'quanto custa?'));
ok($DB['po_wa_conversas'][0]['card_atual'] === 'outra', '20c: texto que nao casa roteiro nenhum cai no fallback');
ok(empty($DB['po_wa_conversas'][0]['roteiro_slug']), '20c: e nenhum roteiro fica escolhido');

// 20d. card botoes: digitar o id da opcao NAO escolhe (esta e a trava do produto)
zera();
wa_processar(ev('mensagem', 'oi'));
wa_processar(clica('rot')); wa_processar(clica('turquia', 'list_reply'));
ok($DB['po_wa_conversas'][0]['card_atual'] === 'data', '20d: a conversa espera no card de botoes da data');
wa_processar(ev('mensagem', 'sim'));
ok($DB['po_wa_conversas'][0]['card_atual'] === 'outra', '20d: no card de botoes, digitar "sim" NAO vale como o botao Sim (cai no fallback)');
ok(($DB['po_leads'][0]['qualif_data'] ?? null) !== true, '20d: e o lead nao e qualificado por texto digitado');

// 20e. card lista: digitar o slug NAO escolhe; o clique na linha escolhe (coberto no cenario 2)
zera();
wa_processar(ev('mensagem', 'oi'));
wa_processar(clica('rot'));
wa_processar(ev('mensagem', 'turquia'));
ok(empty($DB['po_wa_conversas'][0]['roteiro_slug']), '20e: no card lista, digitar o slug NAO escolhe o roteiro');
ok($DB['po_wa_conversas'][0]['card_atual'] === 'outra', '20e: e cai no fallback');

echo "test-wa-motor OK\n";
