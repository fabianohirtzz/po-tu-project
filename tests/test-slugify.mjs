import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

// extrai a funcao do app.js sem carregar o painel inteiro
const src = readFileSync(new URL('../painel/app.js', import.meta.url), 'utf8');
const m = src.match(/function slugify\([\s\S]*?\n\}/);
assert.ok(m, 'slugify encontrada no app.js');
const slugify = new Function(m[0] + '; return slugify;')();

// o bug real, reproduzido: 60 chars cortando no meio de "acompanhante"
const titulo = 'Um roteiro exclusivo pelo melhor da Escandinávia com acompanhante';
const s = slugify(titulo);
assert.ok(!s.endsWith('-'), 'nao termina com hifen');
assert.ok(s.length <= 60, 'respeita o limite de 60');
assert.ok(!s.includes('acompan') || s.includes('acompanhante'),
  'nao corta no meio da palavra: era isso que gerava ...-com-acompan');

assert.equal(slugify('Tesouros Asiáticos'), 'tesouros-asiaticos', 'acentos e espacos');
assert.equal(slugify('Chile: Santiago & Atacama'), 'chile-santiago-atacama', 'pontuacao');
assert.equal(slugify(''), '', 'vazio');
assert.equal(slugify(null), '', 'null');

// caso extremo: uma unica palavra gigante, sem hifen nenhum para cortar.
// aqui cortar em 60 e aceitavel (nao ha limite de palavra pra respeitar),
// mas nao pode estourar o limite nem quebrar.
const palavraGigante = 'a'.repeat(80);
const sGigante = slugify(palavraGigante);
assert.ok(sGigante.length <= 60, 'palavra gigante sem hifen nao estoura o limite');
assert.ok(!sGigante.endsWith('-'), 'palavra gigante sem hifen nao termina com hifen');
assert.equal(sGigante, 'a'.repeat(60), 'palavra gigante e cortada em 60 quando nao ha hifen');

console.log('slugify ok');
