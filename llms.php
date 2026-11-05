<?php
/* /llms.txt (via .htaccess): resumo do site em markdown para assistentes de
   IA, no formato proposto em llmstxt.org.

   Expectativa honesta: em 2026 nenhum buscador grande confirmou que usa este
   arquivo. Custa pouco e nao atrapalha; o que pesa de verdade para IA e o
   texto das paginas (roteiros, FAQ) e os dados estruturados.

   Os roteiros saem do banco, como no sitemap.php: roteiro novo entra sozinho
   e roteiro oculto nunca aparece. Arquivo escrito a mao envelheceria. */

require_once __DIR__ . '/lib/po-data.php';
require_once __DIR__ . '/lib/po-view.php';

function po_llms_linha($s) { return trim(preg_replace('/\s+/u', ' ', (string) $s)); }

function po_llms_txt($lista) {
    $t  = "# Pereira Oliveira Turismo\n\n";
    $t .= "> Agência de viagens em grupo de Florianópolis/SC, desde 1967. Roteiros internacionais com datas fixas, "
        . "hotéis, traslados e passeios contratados, e um coordenador da agência acompanhando o grupo desde a saída do Brasil.\n\n";
    $t .= "- Razão social: Pereira Oliveira Consultoria em Turismo e Viagens LTDA (CNPJ 05.622.878/0001-82)\n";
    $t .= "- Fundada em 1967 por Antônio Pereira Oliveira, como Ilhatur; nome atual desde 2003\n";
    $t .= "- Atendimento presencial com hora marcada: Rua Almirante Lamego 1090, sala 801, Centro, Florianópolis/SC\n";
    $t .= "- WhatsApp: +55 48 99604-8882 (https://wa.me/5548996048882)\n";
    $t .= "- Instagram: https://instagram.com/pereiraoliveiraturismo\n\n";

    $t .= "## Próximas viagens\n\n";
    if (!count($lista)) {
        $t .= "- [Todos os roteiros](" . PO_BASE . "/roteiros): lista atualizada das próximas viagens\n";
    }
    foreach ($lista as $r) {
        $partes = [];
        if (!empty($r['dias'])) $partes[] = (int) $r['dias'] . ' dias';
        $data = po_quando($r);
        if ($data !== '') $partes[] = po_llms_linha($data);
        $st = $r['status_viagem'] ?? null;
        if (is_string($st) && isset(PO_STATUS_VIAGEM[$st])) $partes[] = PO_STATUS_VIAGEM[$st];
        $desc = po_llms_linha($r['descricao_curta'] ?? '');
        $t .= '- [' . po_llms_linha($r['titulo'] ?? '') . '](' . po_url(po_roteiro_href($r)) . ')'
            . (count($partes) ? ': ' . implode(', ', $partes) . '.' : '')
            . ($desc !== '' ? ' ' . $desc : '') . "\n";
    }

    $t .= "\n## Páginas\n\n";
    $t .= "- [Todos os roteiros](" . PO_BASE . "/roteiros): viagens em grupo abertas, com valores e datas\n";
    $t .= "- [Perguntas frequentes](" . PO_BASE . "/perguntas-frequentes): como funciona a viagem em grupo, o que está incluído, pagamento e reserva\n";
    $t .= "- [Nossa história](" . PO_BASE . "/nossa-historia): a trajetória desde 1967\n";
    $t .= "- [Contato](" . PO_BASE . "/contato): formulário para pedir valores e disponibilidade\n";
    $t .= "\n## Opcional\n\n";
    $t .= "- [Política de privacidade](" . PO_BASE . "/politica-de-privacidade)\n";
    return $t;
}

if (!defined('PO_TEST')) {
    header('Content-Type: text/plain; charset=utf-8');
    echo po_llms_txt(po_fetch_roteiros());
}
