<?php
/* Pereira Oliveira: perguntas frequentes (/perguntas-frequentes).

   Feita para as buscas com IA (ChatGPT, Perplexity, Gemini, Resumos com IA
   do Google), que citam texto no formato pergunta-resposta. A home e uma tela
   de video quase sem texto; esta pagina e onde o site explica, em texto, como
   a viagem em grupo funciona.

   REGRA: so afirmar o que o site e o banco ja publicam. Condicoes variam por
   roteiro (aereo, seguro, acompanhante com numero minimo de participantes),
   entao a resposta manda para a pagina do roteiro em vez de prometer. O
   FAQPage do JSON-LD sai desta MESMA lista: marcacao diferente do texto
   visivel viola as diretrizes do Google. Desde 2023 o Google so mostra o
   resultado rico de FAQ para sites de governo e saude; aqui a marcacao serve
   para as IAs e para o Google entender a pagina, nao para ganhar estrela. */

require_once __DIR__ . '/lib/po-data.php';
require_once __DIR__ . '/lib/po-view.php';

function po_faq_itens() {
    return [
        ['p' => 'O que é a Pereira Oliveira Turismo?',
         'r' => 'É uma agência de Florianópolis especializada em viagens internacionais em grupo, com datas fixas. A história começou em 1967, quando Antônio Pereira Oliveira fundou a Ilhatur, uma das pioneiras do turismo em grupo na cidade. Em 2003 a empresa passou a se chamar Pereira Oliveira Turismo, e hoje a segunda geração da família está na operação.'],
        ['p' => 'A Pereira Oliveira é uma agência regularizada?',
         'r' => 'Sim. A Pereira Oliveira é cadastrada no Cadastur, o cadastro oficial do Ministério do Turismo, na atividade de agência de turismo, com o CNPJ 05.622.878/0001-82. Qualquer pessoa pode conferir o cadastro no site do Cadastur, cadastur.turismo.gov.br.'],
        ['p' => 'Como funciona uma viagem em grupo?',
         'r' => 'Cada roteiro tem data de saída e de volta definidas e um programa dia a dia, com hotéis, traslados e passeios já contratados. O grupo segue o mesmo programa, e você não precisa montar roteiro, dirigir nem resolver reservas no destino.'],
        ['p' => 'Um brasileiro acompanha o grupo durante a viagem?',
         'r' => 'Sim. Um coordenador da Pereira Oliveira acompanha o grupo desde a saída do Brasil e dá suporte durante todo o roteiro. Em alguns roteiros o acompanhamento depende de um número mínimo de participantes, e isso aparece na própria página da viagem, na lista do que está incluído.'],
        ['p' => 'De onde saem as viagens?',
         'r' => 'A agência fica em Florianópolis e forma grupos com saída do Brasil. O ponto de embarque e os voos de cada viagem estão no roteiro dia a dia, na página de cada roteiro.'],
        ['p' => 'O que está incluído no preço da viagem?',
         'r' => 'Depende do roteiro. Cada página de roteiro traz a lista do que está incluído e do que não está. Em geral entram hospedagem, traslados e os passeios do programa, enquanto aéreo internacional, seguro viagem e gorjetas variam de uma viagem para outra. Passaporte, vistos, bebidas e despesas pessoais normalmente ficam por conta do viajante.'],
        ['p' => 'Os valores publicados são por pessoa?',
         'r' => 'Sim. Os valores aparecem como "a partir de", por pessoa, em quarto duplo, e em alguns roteiros o aéreo tem valor separado. Roteiros cotados em euro mostram também o valor em reais. Para o valor atualizado, fale com a equipe.'],
        ['p' => 'Quais são as formas de pagamento?',
         'r' => 'As condições mudam de roteiro para roteiro e aparecem na seção de investimento de cada página. Há roteiros com entrada e saldo parcelado em até 10 vezes. A equipe confirma as condições atualizadas no momento da reserva.'],
        ['p' => 'Como faço para reservar uma viagem?',
         'r' => 'Na página do roteiro, clique em "Quero este roteiro" e preencha seus dados, ou fale direto pelo WhatsApp (48) 99604-8882. A equipe retorna com valores atualizados, condições e disponibilidade, sem compromisso.'],
        ['p' => 'Posso ser atendido pessoalmente?',
         'r' => 'Sim, com hora marcada, na Rua Almirante Lamego 1090, sala 801, Centro, Florianópolis/SC. Agende pelo WhatsApp (48) 99604-8882.'],
        ['p' => 'O que significam as etiquetas nas fotos dos roteiros?',
         'r' => 'Elas mostram a situação de cada viagem. "Em breve" marca as próximas saídas, "Últimas vagas" quer dizer que restam poucos lugares, "Vagas esgotadas" que o grupo está completo e "Em viagem" que o grupo já está no destino. Se a viagem que você queria esgotou, fale com a equipe para saber das próximas saídas.'],
        ['p' => 'Preciso de passaporte ou visto?',
         'r' => 'Para viagens internacionais é preciso passaporte válido, e alguns destinos exigem visto ou outros documentos. Os documentos pessoais ficam por conta do viajante, e a equipe orienta sobre o que cada destino pede antes da reserva.'],
    ];
}

function po_faq_jsonld($itens) {
    $q = [];
    foreach ($itens as $it) {
        $q[] = ['@type' => 'Question', 'name' => $it['p'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $it['r']]];
    }
    return ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $q];
}

function po_faq_html($itens) {
    $h  = '<section class="rts-hero faq-hero"><div class="wrap">';
    $h .= '<p class="tag sec__eyebrow"><span></span>Perguntas frequentes</p>';
    // quebra fixa so no desktop (faq.css); no celular o <br> some e o titulo flui
    $h .= '<h1 class="rts-hero__h">Tudo sobre viajar em grupo<br class="faq-br"> com a Pereira Oliveira</h1>';
    $h .= '<p class="rts-hero__lead">Como funcionam as viagens, o que está incluído, formas de pagamento e como reservar. '
        . 'Não achou sua dúvida? Fale com a equipe pelo WhatsApp.</p>';
    $h .= '</div></section>';
    $h .= '<section class="faq"><div class="wrap faq__list">';
    foreach ($itens as $it) {
        $h .= '<details class="faq__item"><summary class="faq__q">' . po_e($it['p']) . '</summary>'
            . '<div class="faq__a"><p>' . po_e($it['r']) . '</p></div></details>';
    }
    $h .= po_cadastur_selo('faq__cad');
    $h .= '<div class="faq__cta"><p>Ainda tem dúvida?</p>'
        . '<div class="faq__actions"><a class="btn" href="/roteiros">Ver os roteiros</a>'
        . '<a class="btn btn--wa" href="https://wa.me/5548996048882" target="_blank" rel="noopener">Falar no WhatsApp</a></div></div>';
    $h .= '</div></section>';
    return $h;
}

if (!defined('PO_TEST')) {
    $itens = po_faq_itens();
    header('Content-Type: text/html; charset=utf-8');
    echo po_head([
        'title'       => 'Perguntas frequentes sobre viagens em grupo · Pereira Oliveira Turismo',
        'description' => 'Como funcionam as excursões em grupo da Pereira Oliveira: acompanhante brasileiro, o que está incluído, pagamento, reserva e atendimento em Florianópolis.',
        'canonical'   => po_url('/perguntas-frequentes'),
        'og_image'    => po_url('/assets/images/roteiro-grecia.jpg'),
        'css'         => ['/assets/css/roteiro.css?v=13', '/assets/css/roteiros.css?v=3', '/assets/css/faq.css?v=3', '/assets/css/cadastur.css?v=2'],
        'jsonld'      => po_faq_jsonld($itens),
    ]);
    echo po_header();
    echo '<main>' . po_faq_html($itens) . '</main>';
    echo po_footer();
    echo po_wa_float();
    // menu mobile e reveal-on-scroll; o resto do roteiro.js e no-op aqui
    echo '<script src="/assets/js/roteiro.js?v=5"></script>' . "\n";
    echo '</body></html>';
}
