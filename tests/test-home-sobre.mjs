import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

/* Painel "Sobre" da home (22/10/2026). O cliente pediu texto oculto para SEO;
   texto escondido so para robo e spam no Google e da acao manual. O que existe
   e conteudo recolhido que o VISITANTE abre por um link visivel, o que e
   permitido. Este teste trava as tres coisas que separam um do outro. */
const html = readFileSync(new URL('../index.html', import.meta.url), 'utf8');
const css = readFileSync(new URL('../assets/css/style.css', import.meta.url), 'utf8');

// 1. o link existe, aponta para o painel e NAO esta escondido
assert.ok(/<a class="about-link" href="#sobre">[^<]{6,}<\/a>/.test(html), 'link visivel com texto para #sobre');
const regraLink = css.match(/\.about-link\{[^}]*\}/)[0];
assert.ok(!/display:none|visibility:hidden|opacity:0[;}]|font-size:0|left:-\d{3,}/.test(regraLink), 'o link nao pode ser invisivel');
const cor = regraLink.match(/color:rgba\(255,255,255,\.(\d+)\)/);
assert.ok(cor && +('0.' + cor[1]) >= 0.5, 'o link tem opacidade legivel (>= .5) sobre o video');

// 2. abre sem JavaScript (robos e visitante sem JS chegam ao texto)
assert.ok(/\.about:target\{display:flex\}/.test(css), 'painel abre por :target');
const sobre = html.match(/<section class="about" id="sobre"[\s\S]*?<\/section>/)[0];
assert.ok(/<h2 id="sobre-h">/.test(sobre), 'painel tem titulo h2 (a home ja tem o h1)');
assert.ok(sobre.split(/\s+/).length > 400, 'painel tem texto de verdade');

// 3. so texto institucional: dado de roteiro (preco, data) envelhece e pisca
assert.ok(!/R\$|€|\b\d{1,2}\/\d{1,2}\/\d{2,4}\b/.test(sobre), 'nenhum preco nem data de roteiro no painel');
assert.ok(!/—/.test(sobre), 'sem travessao (regra de copy)');

console.log('home-sobre ok');
