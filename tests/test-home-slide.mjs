import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

/* Card do carrossel da home (22/10/2026). O cursor de mao prometia clique e
   o card ativo nao abria nada; o cliente pediu imagem e titulo clicaveis.

   Armadilha: o carrossel tambem se arrasta, e o navegador dispara "click" no
   fim do arraste do mouse. Sem a guarda, arrastar o card abriria o roteiro.
   (Antes desta mudanca esse mesmo click fazia o carrossel VOLTAR ao card de
   onde o arraste saiu.) */
const src = readFileSync(new URL('../assets/js/main.js', import.meta.url), 'utf8');
const m = src.match(/function slideClique\([\s\S]*?\n {2}\}/);
assert.ok(m, 'slideClique encontrada no main.js');
const slideClique = new Function(m[0] + '; return slideClique;')();

assert.equal(slideClique({ i: 2, active: 0, arrastouHa: Infinity }), 'focar', 'card lateral: so traz para o centro');
assert.equal(slideClique({ i: 0, active: 0, arrastouHa: Infinity }), 'abrir', 'card do centro: abre o roteiro');
assert.equal(slideClique({ i: 0, active: 0, arrastouHa: 40 }), 'nada', 'click que fecha um arraste nao abre nada');
assert.equal(slideClique({ i: 2, active: 0, arrastouHa: 40 }), 'nada', 'nem faz o carrossel voltar');
assert.equal(slideClique({ i: 0, active: 0, arrastouHa: 2000 }), 'abrir', 'arraste antigo nao bloqueia clique novo');

// ponto de chamada: o handler usa a funcao, o titulo e link de verdade
assert.ok(/slideClique\(\{ i, active, arrastouHa: Date\.now\(\) - arrastouEm \}\)/.test(src), 'o click do slide decide por slideClique');
assert.ok(/<a class="t" href="' \+ r\.href \+ '"/.test(src), 'titulo do card e um <a href> (abre com teclado e em nova aba)');
assert.ok(/arrastouEm = Date\.now\(\)/.test(src), 'o arraste registra a hora');
assert.ok(/location\.href = r\.href/.test(src), 'abrir navega para o roteiro');

console.log('home-slide ok');
