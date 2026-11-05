import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

/* O importador mora dentro da gaveta do roteiro (22/10/2026). A cliente pode
   comecar a escrever e so depois anexar o arquivo: o que ela ja escreveu e
   dela, a IA so completa os campos vazios. Antes o importador abria uma
   gaveta nova e sobrescrevia tudo. */
const src = readFileSync(new URL('../painel/app.js', import.meta.url), 'utf8');
function pega(nome) {
  const m = src.match(new RegExp('function ' + nome + '\\([\\s\\S]*?\\n\\}'));
  assert.ok(m, nome + ' encontrada no app.js');
  return m[0];
}
const rotPreenche = new Function(pega('slugify') + pega('rotPreenche') + '; return rotPreenche;')();

const VAZIO = { 'r-titulo': '', 'r-slug': '', 'r-subtitulo': '', 'r-periodo': '', 'r-dias': '', 'r-noites': '',
  'r-badge': '', 'r-desc': '', 'r-inclui': '', 'r-naoinclui': '', 'r-hoteis': '',
  temDias: false, temValores: false, temGaleria: false };
const IA = { titulo: 'Vietnã Completo', subtitulo: 'Com Paris', periodo: '10 a 30/03/27', dias: 21, noites: 19,
  descricao_curta: 'Do norte ao sul.', inclui: ['Aéreo', 'Hotéis'], nao_inclui: ['Vistos'],
  hoteis: [{ cidade: 'Hanói', hotel: 'Hotel H' }], roteiro_dias: [{ n: 2, cidades: 'B' }, { n: 1, cidades: 'A' }],
  valores: [{ tag: 'Duplo', valor: 'R$ 50.000' }], galeria: ['https://x/1.jpg'] };

// gaveta vazia: tudo vem da IA, como era antes
const tudo = rotPreenche(VAZIO, IA);
assert.equal(tudo.campos['r-titulo'], 'Vietnã Completo');
assert.equal(tudo.campos['r-slug'], 'vietna-completo');
assert.equal(tudo.campos['r-badge'], '21 dias');
assert.equal(tudo.campos['r-inclui'], 'Aéreo\nHotéis');
assert.equal(tudo.campos['r-hoteis'], 'Hanói: Hotel H');
assert.deepEqual(tudo.dias.map(d => d.n), [1, 2], 'dias ordenados');
assert.equal(tudo.valores.length, 1);
assert.deepEqual(tudo.galeria, ['https://x/1.jpg']);

// O CASO DO PEDIDO: o que ela escreveu fica, a IA completa o resto
const meu = rotPreenche({ ...VAZIO, 'r-titulo': 'Vietnã e Tailândia', 'r-badge': '22 dias', 'r-inclui': 'Só o aéreo',
  temDias: true, temValores: true, temGaleria: true }, IA);
assert.ok(!('r-titulo' in meu.campos), 'titulo escrito nao e substituido');
assert.ok(!('r-badge' in meu.campos), 'badge escrito nao e substituido');
assert.ok(!('r-inclui' in meu.campos), 'lista escrita nao e substituida');
assert.equal(meu.campos['r-slug'], 'vietna-e-tailandia', 'slug vazio sai do titulo DELA, nao do da IA');
assert.equal(meu.campos['r-periodo'], '10 a 30/03/27', 'campo vazio e preenchido');
assert.equal(meu.dias, null, 'dia a dia ja montado nao e trocado');
assert.equal(meu.valores, null, 'valores ja montados nao sao trocados');
assert.equal(meu.galeria, null, 'galeria com fotos nao e trocada');

// espaco em branco conta como vazio
assert.equal(rotPreenche({ ...VAZIO, 'r-titulo': '   ' }, IA).campos['r-titulo'], 'Vietnã Completo');
// a IA sem um campo nao apaga nada nem escreve "undefined"
const pobre = rotPreenche(VAZIO, { titulo: 'X' });
assert.deepEqual(Object.keys(pobre.campos).sort(), ['r-slug', 'r-titulo']);
assert.equal(pobre.dias, null);
assert.equal(pobre.galeria, null);

// a gaveta usa a funcao (trava no ponto de chamada) e nao abre gaveta nova
const corpo = src.match(/function prefill\([\s\S]*?\n\}/)[0];
assert.ok(/rotPreenche\(/.test(corpo), 'prefill aplica rotPreenche');
const imp = src.match(/\$\('#imp-file'\)\.onchange=[\s\S]*?\n\};/)[0];
assert.ok(!/openRot\(/.test(imp), 'importar nao abre gaveta nova por cima do que ela escreveu');

console.log('rotPreenche ok');
