<?php
require_once __DIR__ . '/lib/po-data.php';
require_once __DIR__ . '/lib/po-view.php';

function po_sitemap_xml($lista) {
    $hoje = gmdate('Y-m-d');
    $u = function ($loc, $mod, $pri) {
        return '  <url><loc>' . po_e($loc) . '</loc><lastmod>' . po_e($mod) . '</lastmod>'
             . '<priority>' . $pri . '</priority></url>' . "\n";
    };
    $x  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $x .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    $x .= $u(PO_BASE . '/', $hoje, '1.0');
    $x .= $u(PO_BASE . '/roteiros', $hoje, '0.9');
    $x .= $u(PO_BASE . '/nossa-historia.html', $hoje, '0.7');
    $x .= $u(PO_BASE . '/contato.html', $hoje, '0.7');
    foreach ($lista as $r) {
        $mod = !empty($r['updated_at']) ? substr((string) $r['updated_at'], 0, 10) : $hoje;
        $x .= $u(PO_BASE . '/roteiros/' . rawurlencode($r['slug']), $mod, '0.8');
    }
    $x .= '</urlset>' . "\n";
    return $x;
}

if (!defined('PO_TEST')) {
    header('Content-Type: application/xml; charset=utf-8');
    echo po_sitemap_xml(po_fetch_roteiros());
}
