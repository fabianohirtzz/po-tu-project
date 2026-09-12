<?php
/* ============================================================
   Motor de importacao e fusao de contatos (spec 9). Toda a logica
   e pura e testavel sem rede. A escrita (Task 7) usa a service_role
   por transporte injetavel; a auditoria (Task 6) recebe a base pronta.
============================================================ */

require_once __DIR__ . '/wa-fone.php';

/* Marcador "PO" das agendas. "\bPO\b" pega "- PO" e "Cliente PO" sem casar
   "Poliana". Configuravel: a grafia varia entre os dois aparelhos. */
/* O /u nao e decoracao: sem ele o PCRE trabalha em BYTES, e byte de
   continuacao UTF-8 conta como fronteira de palavra - entao '\bPO\b' casava
   o "Po" de 'Poa', 'Pocos', 'Pocao' (com acento). Enquanto a limpeza estava
   ancorada no fim da string isso passava despercebido; com o marcador
   valendo em QUALQUER posicao, o token virava "marcador" e levava o nome
   embora ('Ana Poá' -> 'Ana'), e pior: wa_import_tem_marcador usa a MESMA
   lista, entao um contato sem marcador nenhum era ADMITIDO na importacao
   como se a cliente o tivesse marcado. O /u liga o PCRE2_UCP, e a fronteira
   volta a ser por caractere. */
const WA_IMPORT_MARCADORES = ['/\bPO\b/iu', '/\bcliente\s+po\b/iu'];

/* Nome que sobra vazio depois da limpeza (o contato chamado so "PO", que
   existe na agenda) NAO pode ir em branco para o banco: o painel mostraria
   uma linha sem identificacao nenhuma e o robo abriria a conversa com a
   saudacao truncada. Vai um rotulo explicito - o nome original continua
   inteiro em payload_import. */
const WA_IMPORT_NOME_VAZIO = 'Contato sem nome';

function wa_import_tem_marcador($nome, $padroes = null) {
    $padroes = $padroes ?: WA_IMPORT_MARCADORES;
    foreach ($padroes as $re) if (preg_match($re, (string) $nome)) return true;
    return false;
}

/* Limpa o nome vindo da agenda (spec 9.4): tira o marcador "PO" EM QUALQUER
   POSICAO e a poluicao de busca em volta dele. A spec 9.1 avisa que "a
   grafia varia entre os dois aparelhos", entao marcador no comeco e no meio
   sao caso esperado, nao exotico - e a versao anterior so o removia quando
   ele estava no fim ('/\bpo\b\s*$/'), deixando "PO - Joao Silva" e
   "Maria PO Turquia" entrarem com o marcador dentro do nome que o robo usa
   para cumprimentar a pessoa no WhatsApp.

   Duas regras, nesta ordem:

   1. MARCADOR. Se ele vem depois de algum nome, tudo dali para a frente e
      anotacao de busca da agenda ("Maria PO Turquia" -> "Maria"). Se ele
      abre a string, o que vem depois E o nome ("PO - Joao Silva" ->
      "Joao Silva").
   2. ANOTACAO NUMERADA. A agenda anota a viagem como "<destino> <edicao>"
      ("Grecia 2", "Turquia 3"), entao um token puramente numerico leva
      embora a palavra que ele numera e tudo que vem depois - e assim que
      "Maria Grecia 2 - PO" vira "Maria", o exemplo que a spec 9.4 escreve
      com todas as letras. A primeira palavra nunca e removida por esta
      regra: um nome inteiro nao pode evaporar por causa de um numero.

   O custo conhecido: "Maria Silva 2" (duas Marias na agenda) perde o
   "Silva". Preferi isso a manter a poluicao, porque o nome vai para a
   saudacao do robo e o original continua guardado em payload_import.

   Nome sem marcador nenhum ("Poliana", "Porto Alegre", "Campos", "Apolo",
   "Napoleao") atravessa intacto: o marcador so casa com \b...\b. */
function wa_import_limpa_nome($nome, $padroes = null) {
    $padroes = $padroes ?: WA_IMPORT_MARCADORES;
    $s = trim(preg_replace('/\s+/', ' ', (string) $nome));
    if ($s === '') return WA_IMPORT_NOME_VAZIO;

    $toks = explode(' ', $s);

    // 1) posicao do marcador
    $ini = -1; $fim = -1;
    foreach ($toks as $i => $t) {
        if (!wa_import_casa_marcador($t, $padroes)) continue;
        $ini = $i; $fim = $i;
        /* Marcador de duas palavras ("Cliente PO"): so estende para tras
           quando existe um padrao que casa o PAR e NAO casa o token sozinho.
           Sem essa condicao, "/\bPO\b/i" casaria o par "Maria PO" e comeria
           o nome da pessoa junto com o marcador. */
        if ($i > 0) {
            $par = $toks[$i - 1] . ' ' . $t;
            foreach ($padroes as $re) {
                if (preg_match($re, $par) && !preg_match($re, $t)) { $ini = $i - 1; break; }
            }
        }
        break;
    }

    if ($ini === 0)      $toks = array_slice($toks, $fim + 1);  // marcador abre: o resto e o nome
    elseif ($ini > 0)    $toks = array_slice($toks, 0, $ini);   // marcador depois do nome: corta ali

    // 2) anotacao numerada, e os separadores orfaos que sobram nas pontas
    $toks = wa_import_corta_anotacao($toks);
    $nomeLimpo = trim(preg_replace('/\s+/', ' ', implode(' ', $toks)));
    return $nomeLimpo !== '' ? $nomeLimpo : WA_IMPORT_NOME_VAZIO;
}

/* Um token e marcador quando um dos padroes casa nele sozinho. */
function wa_import_casa_marcador($token, $padroes) {
    foreach ($padroes as $re) if (preg_match($re, $token)) return true;
    return false;
}

/* Tira o bloco "<destino> <numero>" (e o que vier depois) e os separadores
   soltos das pontas ("Lourdete Ramos -" -> "Lourdete Ramos"). */
function wa_import_corta_anotacao($toks) {
    $toks = array_values($toks);
    foreach ($toks as $i => $t) {
        if (preg_match('/^\d+$/', $t)) {
            $toks = array_slice($toks, 0, max(1, $i - 1));
            break;
        }
    }
    $sep = fn($t) => $t === '' || preg_match('/^[\s\-–—·|\/,]+$/u', $t) === 1;
    while ($toks && $sep(end($toks)))      array_pop($toks);
    while ($toks && $sep($toks[0]))        array_shift($toks);
    return array_values($toks);
}

function wa_import_cpf($bruto) {
    $d = preg_replace('/\D+/', '', (string) $bruto);
    return strlen($d) === 11 ? $d : '';
}

/* As UNICAS origens aceitas. Era um prefixo ('crm' no comeco da string), e
   prefixo e porta destrancada: 'crmx', ou 'crm-' seguido de qualquer coisa,
   marcava o contato como cliente JA REVISADO - inclusive vindo de um CSV do
   Google Contacts, que nao traz ficha nenhuma. A secao 8.1 da spec chama
   "contato nao revisado nunca entra em campanha" de defesa obrigatoria, e
   revisado=true indevido poe a pessoa num disparo de marketing sem nunca ter
   passado pela revisao da cliente. Lista fechada, entao, nao prefixo. */
const WA_IMPORT_ORIGENS     = ['crm-toninho', 'agenda-esposa', 'agenda-marido', 'formulario', 'whatsapp'];
const WA_IMPORT_ORIGENS_CRM = ['crm-toninho'];

function wa_import_origem_valida($origem) {
    return in_array((string) $origem, WA_IMPORT_ORIGENS, true);
}

/* Origem de ficha de CRM: dispensa o marcador "PO" e entra revisada e como
   cliente. Origem desconhecida NUNCA e CRM - o lado seguro do erro. */
function wa_import_eh_crm($origem) {
    return in_array((string) $origem, WA_IMPORT_ORIGENS_CRM, true);
}

function wa_import_candidato($contato, $origem, $padroes = null) {
    $ehCrm = wa_import_eh_crm($origem);
    $nomeBruto = (string) ($contato['nome'] ?? '');

    // Agenda: so entra quem tem o marcador. CRM entra sempre.
    if (!$ehCrm && !wa_import_tem_marcador($nomeBruto, $padroes)) return null;

    $celulares = []; $fixos = [];
    foreach (($contato['telefones'] ?? []) as $t) {
        $e = wa_e164($t);
        if ($e === null) continue;
        if (wa_e_celular($e)) { if (!in_array($e, $celulares, true)) $celulares[] = $e; }
        else                  { if (!in_array($e, $fixos, true))     $fixos[] = $e; }
    }

    $emails = $contato['emails'] ?? [];

    /* Nascimento ja normalizado aqui, e nao so na hora de gravar: e chave de
       identidade (a camada 3 da auditoria compara nome+nascimento contra a
       base, que guarda 'YYYY-MM-DD'). O BDAY do vCard chega como
       '1981-09-11', '19810911' ou '1981-09-11T00:00:00Z' - tres grafias da
       mesma data que nao casariam entre si sem isto. O '--0911' do iPhone
       (sem ano) devolve '' e vira null de proposito: data sem ano nao
       identifica ninguem, e inventar o ano criaria casamento falso. */
    $nasc = wa_import_data_iso($contato['data_nascimento'] ?? '');

    return [
        'wa_id'           => $celulares[0] ?? null,
        'celulares'       => $celulares,
        'fixos'           => $fixos,
        'nome'            => $ehCrm ? trim($nomeBruto) : wa_import_limpa_nome($nomeBruto, $padroes),
        'email'           => $emails[0] ?? null,
        'cpf'             => wa_import_cpf($contato['cpf'] ?? '') ?: null,
        'data_nascimento' => $nasc !== '' ? $nasc : null,
        'origem_import'   => (string) $origem,
        'campos'          => is_array($contato['campos'] ?? null) ? $contato['campos'] : [],
        'payload_import'  => ['raw' => $contato['raw'] ?? '', 'origem' => (string) $origem],
    ];
}

/* Funde candidatos do mesmo lote (mesma pessoa nos dois aparelhos). Chave:
   cpf, senao qualquer celular em comum. Soma sem sobrescrever. */
function wa_import_dedup($candidatos) {
    $out = [];
    foreach ($candidatos as $c) {
        $achou = null;
        foreach ($out as $i => $j) {
            $mesmoCpf = $c['cpf'] && $j['cpf'] && $c['cpf'] === $j['cpf'];
            $mesmoCel = array_intersect($c['celulares'], $j['celulares']) !== [];
            if ($mesmoCpf || $mesmoCel) { $achou = $i; break; }
        }
        if ($achou === null) { $out[] = $c; continue; }
        $out[$achou] = wa_import_soma_candidato($out[$achou], $c);
    }
    return array_values($out);
}

function wa_import_soma_candidato($a, $b) {
    $maisLongo = fn($x, $y) => strlen((string) $y) > strlen((string) $x) ? $y : $x;
    $a['nome']  = $maisLongo($a['nome'], $b['nome']);
    $a['email'] = $a['email'] ?: $b['email'];
    $a['cpf']   = $a['cpf'] ?: $b['cpf'];
    $a['data_nascimento'] = $a['data_nascimento'] ?: $b['data_nascimento'];
    $a['celulares'] = array_values(array_unique(array_merge($a['celulares'], $b['celulares'])));
    $a['fixos']     = array_values(array_unique(array_merge($a['fixos'], $b['fixos'])));
    $a['wa_id']     = $a['wa_id'] ?: $b['wa_id'];
    $a['campos']    = $a['campos'] + $b['campos']; // '+' preserva as chaves ja existentes
    $a['payload_import']['tambem'][] = $b['payload_import'];
    return $a;
}

/* Cast defensivo para dado de terceiro (ficha de CRM): valor nao-escalar
   (array/objeto) vira '' em vez de estourar warning "Array to string
   conversion" ou virar a string literal "Array" gravavel no banco; valor
   escalar vira string aparada. */
function wa_import_str($v) {
    if (!is_scalar($v)) return '';
    return trim((string) $v);
}

/* json_encode da ficha cru para o payload_import. NUNCA devolve false: se o
   encode falhar (ex.: byte invalido como UTF-8 solto), tenta de novo com
   substituicao do trecho invalido; em ultimo caso devolve '{}'. raw precisa
   ser sempre string - false quebraria o "?? ''" de quem consome. */
function wa_import_raw_json($f) {
    $j = json_encode($f, JSON_UNESCAPED_UNICODE);
    if ($j === false) {
        $j = json_encode($f, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    return $j === false ? '{}' : $j;
}

/* Mapeia a ficha do CRM antigo (camelCase) para o formato do parser, com o
   bloco de ficha em snake_case (colunas da po_leads, seção 4.1). Campo vazio
   NAO entra: a fusao so soma, entao ausencia aqui nunca vira sobrescrita de
   branco depois. */
function wa_import_mapa_crm($f) {
    $de_para = [
        'rg' => 'rg', 'dataNascimento' => 'data_nascimento', 'nacionalidade' => 'nacionalidade',
        'estadoCivil' => 'estado_civil', 'profissao' => 'profissao',
        'cep' => 'cep', 'endereco' => 'endereco', 'numero' => 'numero',
        'complemento' => 'complemento', 'bairro' => 'bairro', 'cidade' => 'cidade', 'estado' => 'estado',
        'passaporteNumero' => 'passaporte_numero', 'passaporteOrgaoEmissor' => 'passaporte_orgao_emissor',
        'passaporteEmissao' => 'passaporte_emissao', 'passaporteValidade' => 'passaporte_validade',
        'contatoEmergenciaNome' => 'contato_emergencia_nome',
        'contatoEmergenciaTelefone' => 'contato_emergencia_telefone',
        'contatoEmergenciaParentesco' => 'contato_emergencia_parentesco',
        'telefoneSecundario' => 'telefone_secundario', 'observacoes' => 'observacoes',
    ];
    $campos = [];
    foreach ($de_para as $src => $dst) {
        $v = wa_import_str($f[$src] ?? '');
        if ($v !== '') $campos[$dst] = $v;
    }

    /* 'comoConheceu' NAO vai para origem_manual. Naquela coluna o painel
       (app.js, mapRow) guarda a SOBRESCRITA MANUAL DO CANAL DE MARKETING -
       um valor de lista fechada (pago|organico|social|direto|instagram|
       whatsapp) que manda no agrupamento dos relatorios, no CPL e no ROAS.
       O texto livre da ficha antiga ("Indicacao da Maria", "Instagram" com I
       maiusculo) entrava ali como se fosse canal e abria um balde novo no
       relatorio; ja aconteceu em producao, com 1 linha gravada 'Instagram',
       separada de 'instagram'.
       O valor nao se perde: vai para observacoes, rotulado, e a ficha crua
       inteira continua em payload_import. */
    $como = wa_import_str($f['comoConheceu'] ?? '');
    if ($como !== '') {
        $obs = $campos['observacoes'] ?? '';
        $campos['observacoes'] = trim(($obs !== '' ? $obs . "\n" : '') . 'Como conheceu: ' . $como);
    }
    $tel = wa_import_str($f['telefone'] ?? '');
    return [
        'nome'      => wa_import_str($f['nome'] ?? ''),
        'telefones' => $tel !== '' ? [$tel] : [],
        'emails'    => ($e = wa_import_str($f['email'] ?? '')) !== '' ? [$e] : [],
        'org'       => '',
        'cpf'       => wa_import_str($f['cpf'] ?? ''),
        'data_nascimento' => wa_import_str($f['dataNascimento'] ?? ''),
        'campos'    => $campos,
        'raw'       => wa_import_raw_json($f),
    ];
}

/* ============================================================
   AUDITORIA DE FUSAO (Task 6). Funcao pura: recebe os candidatos e a
   base ja lida, nao toca banco nem rede. Devolve um PLANO - o que seria
   feito - para a Task 7 escrever e a Task 9 mostrar.

   As tres regras do dono da agencia, que este motor existe para garantir:
     1. O registro com historico e o dono. Ficha com historico nunca e
        sobrescrita nem substituida por um contato de agenda.
     2. A fusao so soma, nunca zera. Campo vazio na fonte nova jamais
        apaga campo preenchido no registro existente.
     3. Nunca duplicar. Nenhum registro novo nasce sem antes tentar casar.
============================================================ */

/* As UNICAS colunas que uma fusao pode preencher. Fora daqui, nada e tocado:
   status, venda, venda_at, notas e qualif_* sao historico e sao do dono.
   Esta lista e a trava estrutural da regra 1: sem ela, uma importacao de
   agenda apagaria o funil e o faturamento da cliente em silencio.

   'wa_id' esta aqui porque a FUSAO tambem precisa preenche-lo. A base tem
   hoje 11 leads com telefone e wa_id nulo (os do formulario do site, que o
   enviar.php nao normaliza para E.164, entao o backfill anterior nao os
   pegou). Quando a agenda trouxer o mesmo celular, o caminho 'funde' casa por
   celular e completa o que esta vazio - e sem wa_id na lista o campo ficava
   nulo. Na primeira mensagem da pessoa, wa_lead() (lib/wa-motor.php) consulta
   por wa_id, nao acha, e INSERE um lead novo: exatamente a duplicacao que o
   insert ja fecha do outro lado. Continua valendo a regra "so preenche o que
   esta vazio" (wa_id ja preenchido nunca e sobrescrito) e "so celular vira
   wa_id" (fixo nao tem WhatsApp), garantida a montante pelo pipeline. */
const WA_IMPORT_CAMPOS_PREENCHIVEIS = [
    'telefone','wa_id','nome','email','cpf','rg','data_nascimento','nacionalidade','estado_civil',
    'profissao','cep','endereco','numero','complemento','bairro','cidade','estado',
    'passaporte_numero','passaporte_orgao_emissor','passaporte_emissao','passaporte_validade',
    'contato_emergencia_nome','contato_emergencia_telefone','contato_emergencia_parentesco',
    'telefone_secundario','observacoes','origem_manual',
];

function wa_import_audita($candidatos, $base) {
    /* Indices: cpf e celular casam identidade; nome+nascimento so sugere.
       FIXO NUNCA ENTRA: na base real ha um telefone fixo de empresa repetido
       em tres fichas de pessoas diferentes - se fixo casasse, tres clientes
       virariam um so. */
    $porCpf = []; $porCel = []; $porNomeNasc = [];
    foreach ((array) $base as $b) {
        if (!is_array($b)) continue;

        /* Normaliza os dois lados do indice. A base tem lead antigo do site e
           ficha digitada a mao no painel, com "(48) 99999-0001" e
           "111.444.777-35"; comparar cru nao casaria e o importador criaria a
           segunda ficha da mesma pessoa (violando a regra 3). */
        $cpf = wa_import_cpf($b['cpf'] ?? '');
        if ($cpf !== '') wa_import_indexa($porCpf, $cpf, $b);

        $tel = wa_e164($b['telefone'] ?? null);
        if ($tel !== null && wa_e_celular($tel)) wa_import_indexa($porCel, $tel, $b);

        $nome = wa_import_chave_nome($b['nome'] ?? '');
        $nasc = wa_import_str($b['data_nascimento'] ?? '');
        if ($nome !== '' && $nasc !== '') wa_import_indexa($porNomeNasc, $nome . '|' . $nasc, $b);
    }

    $plano  = [];
    $usados = []; // ficha ja reservada por um candidato deste mesmo lote

    foreach ((array) $candidatos as $c) {
        $c = is_array($c) ? $c : [];
        $nomeCand = wa_import_str($c['nome'] ?? '');
        $match = null; $por = null;

        $cpfCand = wa_import_cpf($c['cpf'] ?? '');
        if ($cpfCand !== '' && isset($porCpf[$cpfCand])) { $match = $porCpf[$cpfCand]; $por = 'cpf'; }

        if (!$match) {
            foreach ((array) ($c['celulares'] ?? []) as $cel) {
                $e = wa_e164($cel);
                if ($e !== null && isset($porCel[$e])) { $match = $porCel[$e]; $por = 'celular'; break; }
            }
        }

        if ($match) {
            /* Id inutilizavel (ausente, nao-escalar, em branco) e tratado como
               null: e o que $usados indexa e o que a Task 7 usa para gravar. */
            $id = $match['id'] ?? null;
            if (!is_scalar($id) || wa_import_str($id) === '') $id = null;

            /* Duas saidas para 'revisar' em vez de 'funde':

               (a) Ficha sem id. O casamento existe, mas a escrita seria
                   inexequivel - a Task 7 grava por id. E pior: sem id, $usados
                   nao enxerga nada, entao dois candidatos na mesma ficha sairiam
                   os dois como funde e reabririam o conflito silencioso que a
                   trava abaixo existe para fechar. Isso nao vem do banco
                   (po_leads.id e uuid not null); vem de um select que esqueceu a
                   coluna id - e ai TODAS as linhas ficam assim.

               (b) Ficha ja reservada por um candidato anterior deste lote. O
                   primeiro funde, o seguinte vai para revisao humana. Deixar os
                   dois fundirem produziria duas escritas no mesmo lead, cada uma
                   com um valor diferente para o mesmo campo vazio - conflito
                   silencioso, o pior modo de falha daqui. Virar 'novo' seria pior
                   ainda: duplicaria a pessoa (regra 3). */
            if ($id === null || isset($usados[$id])) {
                $plano[] = ['nome'=>$nomeCand,'acao'=>'revisar','match_id'=>$id,
                            'match_por'=>$por,'preenche'=>[],'candidato'=>$c];
                continue;
            }
            $usados[$id] = true;

            $plano[] = [
                'nome' => $nomeCand, 'acao' => 'funde', 'match_id' => $id,
                'match_por' => $por, 'preenche' => wa_import_preenche($match, $c), 'candidato' => $c,
            ];
            continue;
        }

        /* Sem casamento forte: nome+nascimento apenas SUGERE. Homonimo com a
           mesma data existe, e fundir por palpite apagaria a ficha errada. */
        $nasc = wa_import_str($c['data_nascimento'] ?? '');
        $chaveNome = wa_import_chave_nome($nomeCand);
        if ($nasc !== '' && $chaveNome !== '') {
            $k = $chaveNome . '|' . $nasc;
            if (isset($porNomeNasc[$k])) {
                $plano[] = ['nome'=>$nomeCand,'acao'=>'revisar','match_id'=>$porNomeNasc[$k]['id'] ?? null,
                            'match_por'=>'nome_nasc','preenche'=>[],'candidato'=>$c];
                continue;
            }
        }

        $plano[] = ['nome'=>$nomeCand,'acao'=>'novo','match_id'=>null,'match_por'=>null,
                    'preenche'=>[],'candidato'=>$c];
    }
    return $plano;
}

/* Guarda a ficha no indice preferindo SEMPRE a que tem historico: se duas
   fichas dividem o mesmo celular, a dona e a que ja tem passado com a
   agencia (regra 1). Em empate, a primeira fica - trocar a toa deixaria o
   resultado dependendo da ordem em que o banco devolveu as linhas. */
function wa_import_indexa(&$idx, $chave, $b) {
    if (!isset($idx[$chave])) { $idx[$chave] = $b; return; }
    if (empty($idx[$chave]['historico']) && !empty($b['historico'])) $idx[$chave] = $b;
}

/* Preenche SO o que esta vazio na base, SO colunas da whitelist. Nunca
   sobrescreve, nunca toca historico. */
function wa_import_preenche($base, $c) {
    $novo = [];

    /* Vazio e: chave ausente, null ou string em branco. Valor nao-escalar
       (json da base) NAO e vazio - na duvida, nao se escreve por cima. */
    $vazio = function ($k) use ($base) {
        if (!array_key_exists($k, $base)) return true;
        $v = $base[$k];
        if ($v === null) return true;
        if (!is_scalar($v)) return false;
        return trim((string) $v) === '';
    };

    /* O fixo vai para telefone_secundario, nunca para telefone: la ele
       pareceria celular e nunca mais casaria, porque a auditoria so indexa
       'telefone' quando wa_e_celular. Sem esta linha o numero da loja do
       contato que so tem fixo se perdia dentro do payload_import. */
    $doCandidato = array_merge([
        'telefone'            => $c['wa_id'] ?? null,
        'telefone_secundario' => $c['fixos'][0] ?? null,
        'nome'                => $c['nome'] ?? null,
        'email'               => $c['email'] ?? null,
        'cpf'                 => $c['cpf'] ?? null,
        'data_nascimento'     => $c['data_nascimento'] ?? null,
    ], is_array($c['campos'] ?? null) ? $c['campos'] : []);

    /* Depois do merge, e nao dentro dele: 'campos' e texto de arquivo de
       terceiro (vCard/CSV) e agora que 'wa_id' esta na whitelist, uma chave
       'wa_id' vinda do arquivo sobrescreveria esta. So o celular que o
       pipeline validou pode virar wa_id - fixo nao tem WhatsApp, e um wa_id
       inventado faria o motor responder a pessoa errada. */
    $doCandidato['wa_id'] = $c['wa_id'] ?? null;

    foreach ($doCandidato as $k => $v) {
        if (!in_array($k, WA_IMPORT_CAMPOS_PREENCHIVEIS, true)) continue;
        if ($v === null || !is_scalar($v) || trim((string) $v) === '') continue;
        if ($vazio($k)) $novo[$k] = $v;
    }

    /* O saneamento das colunas de data roda AQUI, e nao so na hora de
       gravar: este retorno e o 'preenche' que o preview mostra na tela. Com
       o saneamento so no wa_import_aplica, a tela prometia preencher uma
       data ilegivel que o aplicar depois descartava - e quando ela era a
       unica chave, o "vai completar uma ficha" virava um 'ignorado' calado.
       O plano mostrado passa a ser exatamente o que sera escrito. */
    return wa_import_saneia($novo);
}

function wa_import_chave_nome($nome) {
    $s = mb_strtolower(wa_import_str($nome), 'UTF-8');
    return preg_replace('/\s+/', ' ', $s);
}

/* ============================================================
   APLICACAO DO PLANO (Task 7). A unica camada daqui que escreve - e mesmo
   assim nao sabe escrever: recebe $inserir e $atualizar injetados. No
   endpoint sao wa_db_insert/wa_db_update (service_role); no teste sao
   fakes que so anotam o que receberam, e por isso a suite inteira roda
   sem tocar a rede.
============================================================ */

/* Colunas 'date' da po_leads. Texto torto numa delas tem dois modos de
   falha, os dois caros: string vazia ou "nao informado" faz o PostgREST
   recusar o INSERT INTEIRO (o contato se perde e ninguem ve), e
   "11/09/1981" entra como 9 de NOVEMBRO, porque o DateStyle padrao do
   Postgres e MDY. Por isso toda escrita passa por wa_import_saneia. */
const WA_IMPORT_COLUNAS_DATA = ['data_nascimento', 'passaporte_emissao', 'passaporte_validade'];

/* Data de origem humana (agenda, ficha de CRM digitada em 20 anos) para
   'YYYY-MM-DD'. O que nao for data de verdade devolve '' - e quem chama
   TIRA a chave, em vez de mandar lixo para o banco. */
function wa_import_data_iso($v) {
    if (!is_scalar($v)) return '';
    $s = trim((string) $v);
    if ($s === '') return '';

    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ].*)?$/', $s, $m)) {
        list(, $a, $me, $d) = $m;
    } elseif (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$#', $s, $m)) {
        // Brasileiro: dia primeiro. A fonte e agenda e CRM de agencia daqui.
        list(, $d, $me, $a) = $m;
    } elseif (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $s, $m)) {
        list(, $a, $me, $d) = $m;   // BDAY basico do vCard
    } else {
        return '';
    }
    if (!checkdate((int) $me, (int) $d, (int) $a)) return '';
    return sprintf('%04d-%02d-%02d', (int) $a, (int) $me, (int) $d);
}

/* Normaliza as colunas de data de um conjunto de campos. NUNCA acrescenta
   chave: so corrige o valor ou remove a chave ilegivel. */
function wa_import_saneia($campos) {
    foreach (WA_IMPORT_COLUNAS_DATA as $k) {
        if (!array_key_exists($k, $campos)) continue;
        $iso = wa_import_data_iso($campos[$k]);
        if ($iso === '') unset($campos[$k]); else $campos[$k] = $iso;
    }
    return $campos;
}

/* Executa o plano com escritores injetados. 'novo' insere; 'funde' com
   preenche atualiza; 'revisar' e 'funde' sem preenche nao escrevem.

   O $atualizar recebe EXATAMENTE as chaves de $item['preenche'] (saneadas,
   nunca acrescidas). Montar o update a partir do candidato cru faria da
   whitelist da Task 6 pura decoracao, e uma importacao de agenda apagaria
   o funil e o faturamento da cliente em silencio. */
function wa_import_aplica($plano, $inserir, $atualizar) {
    $r = ['novos'=>0, 'preenchidos'=>0, 'revisar'=>0, 'ignorados'=>0, 'falhas'=>0];
    foreach ((array) $plano as $item) {
        if (!is_array($item)) continue;
        $acao = $item['acao'] ?? 'revisar';

        if ($acao === 'novo') {
            // Escrita recusada (PostgREST devolve null) NAO pode virar
            // numero verde na tela: a cliente confiaria numa importacao
            // que nao aconteceu.
            $res = $inserir(wa_import_linha_nova($item['candidato'] ?? []));
            if ($res) $r['novos']++; else $r['falhas']++;

        } elseif ($acao === 'funde') {
            $campos = wa_import_saneia(is_array($item['preenche'] ?? null) ? $item['preenche'] : []);
            if (!$campos) { $r['ignorados']++; continue; }
            $res = $atualizar($item['match_id'], $campos);
            if ($res) $r['preenchidos']++; else $r['falhas']++;

        } else { // revisar: decisao humana, nenhuma escrita
            $r['revisar']++;
        }
    }
    return $r;
}

/* Monta a linha do lead a partir de um candidato novo. CRM entra revisado e
   ja marcado como cliente (a ficha e de quem ja viajou); agenda entra com
   revisado = false - nada e transmitido antes da revisao (spec 9.4).

   Os 'campos' passam pela MESMA whitelist da fusao: chave fora dela seria
   ou coluna inexistente (o PostgREST recusa a linha toda) ou uma coluna de
   historico entrando pela porta dos fundos. */
function wa_import_linha_nova($c) {
    $c = is_array($c) ? $c : [];
    $ehCrm = wa_import_eh_crm($c['origem_import'] ?? '');

    $campos = [];
    foreach ((is_array($c['campos'] ?? null) ? $c['campos'] : []) as $k => $v) {
        if (in_array($k, WA_IMPORT_CAMPOS_PREENCHIVEIS, true)) $campos[$k] = $v;
    }
    $linha = array_merge([
        'nome'                => $c['nome'] ?? '',
        'telefone'            => $c['wa_id'] ?? null,
        // O motor do WhatsApp casa lead por wa_id (wa_lead() consulta wa_id=eq.
        // e INSERE quando nao acha). Sem isto, a ficha rica duplicaria na
        // primeira mensagem. So celular vira wa_id: fixo nao tem WhatsApp.
        'wa_id'          => $c['wa_id'] ?? null,
        // Contato so com fixo (a Task 6 deixa passar de proposito): o numero
        // vai para ca, senao o lead nasce sem telefone nenhum e inalcancavel.
        'telefone_secundario' => $c['fixos'][0] ?? null,
        'email'               => $c['email'] ?? null,
        'cpf'                 => $c['cpf'] ?? null,
        'data_nascimento'     => $c['data_nascimento'] ?? null,
        'origem'              => 'importado',
        'origem_import'       => $c['origem_import'] ?? '',
        'payload_import'      => $c['payload_import'] ?? [],
        'revisado'            => $ehCrm ? true : false,
        'cliente'             => $ehCrm ? true : false,
    ], $campos);

    // Mesma razao do wa_import_preenche: 'wa_id' entrou na whitelist, entao
    // $campos (texto de arquivo de terceiro) passaria por ela e venceria o
    // array_merge. So o celular validado pelo pipeline vira wa_id.
    $linha['wa_id'] = $c['wa_id'] ?? null;

    // null numa coluna date passa; texto torto derrubaria o insert inteiro.
    foreach (WA_IMPORT_COLUNAS_DATA as $k) {
        if (array_key_exists($k, $linha) && $linha[$k] === null) unset($linha[$k]);
    }
    return wa_import_saneia($linha);
}

/* Prepara uma linha da po_leads para a auditoria. Faz duas coisas, e as
   duas sao criticas:

   1. Deriva 'historico' - o booleano em que wa_import_indexa se apoia para
      decidir quem e o dono de um telefone repetido. Ele nao tem como
      perceber um 'false' constante, entao a regra degradaria em silencio.
   2. PRESERVA a linha inteira. wa_import_preenche trata coluna AUSENTE
      como coluna vazia: recortar a linha aqui faria a fusao sobrescrever
      justamente o que a cliente digitou a mao (violando a regra 2). */
function wa_import_base_row($r) {
    $r = is_array($r) ? $r : [];

    $status  = wa_import_str($r['status'] ?? '') ?: 'semresposta';
    $notas   = $r['notas'] ?? null;
    $temNota = is_array($notas) ? $notas !== []
             : (is_scalar($notas) && !in_array(trim((string) $notas), ['', '[]', 'null'], true));

    $r['historico'] = !in_array($status, ['semresposta', 'novo'], true)
        || (float) (is_scalar($r['venda'] ?? null) ? $r['venda'] : 0) > 0
        || $temNota;

    // Chaves que a auditoria le sempre existem, mesmo que a linha nao as
    // tenha trazido - inclusive 'id', sem o qual nada funde.
    foreach (['id','telefone','cpf','nome','data_nascimento','email','cidade'] as $k) {
        if (!array_key_exists($k, $r)) $r[$k] = null;
    }
    return $r;
}

/* O select da base, derivado da whitelist para nunca ficar para tras dela.
   'id' identifica a ficha na escrita; status, venda e notas alimentam o
   'historico'; as colunas da whitelist precisam vir para que a fusao saiba
   o que ja esta preenchido. */
function wa_import_select_base() {
    $cols = array_merge(['id', 'status', 'venda', 'notas'], WA_IMPORT_CAMPOS_PREENCHIVEIS);
    return 'select=' . implode(',', array_values(array_unique($cols)));
}

/* Contagem do plano para a tela de revisao: por acao e por chave de
   casamento, para a decisao ser vista antes de qualquer escrita (spec 9.5).

   As contagens por chave sao ESCOPADAS POR ACAO, e isso e o que impede a
   frase da tela de se contradizer. 'revisar' tambem guarda o match_por
   (casou por CPF, mas outro contato do lote ja reservou a ficha), entao
   contar por chave sobre o plano inteiro produzia "1 vai completar uma
   ficha que ja existe (3 por CPF...)" - tres detalhes para uma fusao so,
   na unica tela que a cliente le antes de autorizar a escrita.
   por_cpf/por_celular detalham o 'funde'; por_nome_nasc e sempre 'revisar'
   (nome+nascimento nunca funde sozinho) e detalha o 'revisar'. */
function wa_import_resumo($plano) {
    $r = ['total'=>0,'novos'=>0,'funde'=>0,'revisar'=>0,'por_cpf'=>0,'por_celular'=>0,'por_nome_nasc'=>0];
    foreach ((array) $plano as $p) {
        if (!is_array($p)) continue;
        $r['total']++;
        $acao = $p['acao'] ?? 'revisar';
        $por  = $p['match_por'] ?? null;

        if ($acao === 'novo') {
            $r['novos']++;
        } elseif ($acao === 'funde') {
            $r['funde']++;
            if ($por === 'cpf')          $r['por_cpf']++;
            elseif ($por === 'celular')  $r['por_celular']++;
        } else {
            $r['revisar']++;
            if ($por === 'nome_nasc')    $r['por_nome_nasc']++;
        }
    }
    return $r;
}

/* ============================================================
   IDENTIDADE DO LOTE (idempotencia da importacao)
============================================================ */

/* Identidade de um lote de importacao. O CONTEUDO, nao o nome do arquivo: a
   cliente vai exportar dois aparelhos e os dois podem se chamar contatos.vcf.
   A origem entra no hash porque o MESMO arquivo aplicado como agenda-esposa e
   depois como agenda-marido e engano do operador, nao segunda importacao.

   O \0 separa os dois campos. Sem ele a concatenacao e ambigua: 'ag'+'endaX'
   e 'age'+'ndaX' dariam o mesmo hash, e dois lotes diferentes se confundiriam. */
function wa_import_hash_lote($texto, $origem) {
    return hash('sha256', (string) $origem . "\0" . (string) $texto);
}
