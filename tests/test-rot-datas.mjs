import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

/* ============================================================
   O editor das datas do mes, na gaveta do roteiro.

   As funcoes PURAS moram em painel/datas.js, para este teste poder
   EXECUTA-LAS: o painel/app.js tem codigo de topo e mexe no DOM, entao em
   harness de teste ele so pode ser conferido por regex no fonte - e regex no
   fonte testa o texto, nao o comportamento. A fiacao (HTML da gaveta,
   handlers, payload do salvar) e que fica no app.js, com trava de fonte.

   Normalizacao do fim de linha na LEITURA (core.autocrlf=true neste repo).
============================================================ */

const raiz = join(import.meta.dirname, '..');
const le = rel => readFileSync(join(raiz, rel), 'utf8').split('\r\n').join('\n');

const w = {};
new Function('window', le('painel/datas.js'))(w);
const { rotDatasNormaliza, rotDatasAddMes, rotDatasDelMes, rotDatasAddData, rotDatasDelData, rotDatasDoForm, rotDatasDaTela, rotDatasHtml, rotEsc } = w;

for (const [nome, fn] of Object.entries({ rotDatasNormaliza, rotDatasAddMes, rotDatasDelMes, rotDatasAddData, rotDatasDelData, rotDatasDoForm, rotDatasDaTela, rotDatasHtml, rotEsc })) {
  assert.equal(typeof fn, 'function', 'painel/datas.js expoe ' + nome);
}

const BASE = [
  { titulo: 'Datas de Dezembro', datas: ['02/12/39', '10/12/39'] },
  { titulo: 'Datas de Janeiro', datas: ['07/01/40'] },
];
const congelado = JSON.stringify(BASE);

/* ---------- nada MUTA a lista de entrada ----------
   O editor redesenha a partir do retorno. Se um Add/Del mexesse na lista
   recebida, o estado anterior sumiria e um "desfazer" futuro - ou dois
   handlers lendo o mesmo array - veriam dados ja alterados. */
for (const [nome, chamada] of [
  ['rotDatasAddMes', () => rotDatasAddMes(BASE)],
  ['rotDatasDelMes', () => rotDatasDelMes(BASE, 0)],
  ['rotDatasAddData', () => rotDatasAddData(BASE, 0)],
  ['rotDatasDelData', () => rotDatasDelData(BASE, 0, 0)],
]) {
  const saida = chamada();
  assert.notEqual(saida, BASE, nome + ' devolve lista NOVA, nao a mesma referencia');
  assert.equal(JSON.stringify(BASE), congelado, nome + ' nao mexeu na lista de entrada');
  assert.notEqual(saida[0], BASE[0], nome + ' nao reaproveita o objeto do grupo');
}

/* ---------- o que cada um faz ---------- */
assert.equal(rotDatasAddMes(BASE).length, 3, 'addMes acrescenta um grupo');
assert.deepEqual(rotDatasAddMes(BASE)[2], { titulo: '', datas: [''] }, 'o grupo novo nasce com um campo de data vazio, pronto para digitar');
assert.deepEqual(rotDatasDelMes(BASE, 0), [BASE[1]], 'delMes tira o grupo da posicao');
assert.deepEqual(rotDatasAddData(BASE, 1).at(-1).datas, ['07/01/40', ''], 'addData acrescenta campo vazio no grupo certo');
assert.deepEqual(rotDatasDelData(BASE, 0, 1).at(0).datas, ['02/12/39'], 'delData tira a data da posicao');
assert.equal(rotDatasAddData(BASE, 0).at(1).datas.length, 1, 'addData nao toca nos outros grupos');

/* ---------- indice fora da faixa nao quebra ----------
   Clique repetido enquanto a tela redesenha chega com indice que ja nao
   existe. Nenhum desses pode estourar nem apagar o que nao foi pedido. */
for (const [nome, chamada] of [
  ['delMes(-1)', () => rotDatasDelMes(BASE, -1)],
  ['delMes(99)', () => rotDatasDelMes(BASE, 99)],
  ['delData(99,0)', () => rotDatasDelData(BASE, 99, 0)],
  ['delData(0,99)', () => rotDatasDelData(BASE, 0, 99)],
  ['addData(99)', () => rotDatasAddData(BASE, 99)],
]) {
  assert.deepEqual(chamada(), BASE, nome + ' devolve a lista inalterada');
}

/* ---------- rotDatasDoForm: o que vai para o banco ----------
   Duble de DOM: o que a funcao precisa e so querySelectorAll devolvendo
   elementos com .value e .querySelectorAll proprio. */
const grupo = (titulo, datas) => ({
  querySelector: s => (s === '.rd-tit' ? { value: titulo } : null),
  querySelectorAll: () => datas.map(v => ({ value: v })),
});
const root = gs => ({ querySelectorAll: s => (s === '.rd-grupo' ? gs : []) });

assert.deepEqual(
  rotDatasDoForm(root([grupo('Datas de Dezembro', ['02/12/39', '10/12/39'])])),
  [{ titulo: 'Datas de Dezembro', datas: ['02/12/39', '10/12/39'] }],
  'le titulo e datas do formulario');

assert.deepEqual(
  rotDatasDoForm(root([grupo('D', ['02/12/39', '  ', '', '10/12/39'])])),
  [{ titulo: 'D', datas: ['02/12/39', '10/12/39'] }],
  'descarta data em branco no meio, e apara o espaco');

/* Grupo com titulo e ZERO datas nao vai para o banco: ele nao informa nada e
   ocuparia espaco na legenda do PDF, que tem teto de 1024 caracteres. */
assert.deepEqual(rotDatasDoForm(root([grupo('Datas de Marco', ['', '  '])])), [], 'grupo sem nenhuma data nao e gravado');
assert.deepEqual(
  rotDatasDoForm(root([grupo('vazio', []), grupo('D', ['02/12/39'])])),
  [{ titulo: 'D', datas: ['02/12/39'] }],
  'grupo vazio sai e o bom fica');
assert.deepEqual(rotDatasDoForm(root([])), [], 'formulario sem grupo nenhum');
/* Data preenchida e titulo em branco entra: o titulo e decoracao, a data e o
   dado. Perder a data por falta de rotulo seria o pior dos dois erros. */
assert.deepEqual(rotDatasDoForm(root([grupo('  ', ['02/12/39'])])), [{ titulo: '', datas: ['02/12/39'] }], 'titulo em branco nao descarta a data');

/* ---------- a tela e o banco sao DUAS leituras ----------
   DEFEITO REAL, visto abrindo a tela em 05/11/2026: o redesenho lia o
   formulario com rotDatasDoForm, que e a forma do SALVAR e descarta grupo
   sem nenhuma data. O mes recem-adicionado nasce com um campo vazio, entao
   ele sumia no primeiro clique seguinte - e os indices dos botoes ainda
   deslocavam, fazendo "excluir mes" apagar o mes errado.

   rotDatasDaTela mantem o que esta na tela como esta; rotDatasDoForm limpa
   para gravar. */
const soVazio = root([grupo('Datas de Marco', ['', '  ']), grupo('D', ['02/12/39'])]);
assert.equal(rotDatasDaTela(soVazio).length, 2, 'a tela mantem o mes recem-adicionado, ainda sem data');
assert.deepEqual(rotDatasDaTela(soVazio)[0], { titulo: 'Datas de Marco', datas: ['', '  '] }, 'mantem os campos como estao: nem apara o espaco nem descarta o vazio');
assert.equal(rotDatasDoForm(soVazio).length, 1, 'o banco so recebe o que tem data');
/* E por isso os indices batem: a posicao na tela e a posicao na lista. */
assert.deepEqual(rotDatasDelMes(rotDatasDaTela(soVazio), 0).map(g => g.titulo), ['D'],
  'excluir o mes de indice 0 tira o que esta na posicao 0 da TELA');

/* O que sai do formulario tem de ser aceito pela normalizacao: as duas
   pontas desenham a mesma forma, senao o painel grava algo que o site
   descarta - e a cliente ve o dado sumir sem erro nenhum. */
const doForm = rotDatasDoForm(root([grupo('D', ['02/12/39'])]));
assert.deepEqual(rotDatasNormaliza({ datas_texto: doForm }), doForm, 'o que o formulario produz atravessa a normalizacao intacto');

/* ---------- a fiacao no app.js, por trava de fonte ---------- */
const app = le('painel/app.js');

assert.ok(/#r-datas/.test(app), 'a gaveta tem o bloco das datas');
/* Sem isto a cliente edita, salva, e o dado nao vai - com todos os testes
   das puras verdes. */
assert.ok(/datas_texto:\s*rotDatasDoForm\(/.test(app), 'o payload do salvar leva datas_texto de rotDatasDoForm');
/* O REDESENHO le a TELA, nao o que iria para o banco. Esta trava existe
   porque a versao frouxa dela ("o app menciona 'semanal' em algum lugar")
   deixou passar o defeito real de 05/11/2026: com rotDatasDoForm aqui, o mes
   recem-adicionado sumia no primeiro clique seguinte e os indices dos botoes
   deslocavam, fazendo "excluir mes" apagar o mes errado. */
assert.ok(/function rotDatasAtuais\(\)\{return rotDatasDaTela\(/.test(app),
  'rotDatasAtuais le a TELA (rotDatasDaTela)');
assert.ok(!/rotDatasAtuais\(\)\{return rotDatasDoForm/.test(app),
  'e nunca a leitura do banco, que descarta o mes recem-adicionado');

/* O bloco so aparece quando o status e semanal, na ABERTURA da gaveta e na
   TROCA do select - os dois, senao ele nasce certo e fica errado, ou o
   contrario. */
assert.ok(/id="r-datas-wrap" \$\{r\.status_viagem==='semanal'\?'':'hidden'\}/.test(app),
  'na abertura da gaveta o bloco ja nasce escondido fora do semanal');
assert.ok(/\$\('#r-datas-wrap'\)\.hidden=\$\('#r-status'\)\.value!=='semanal'/.test(app),
  'e a troca do select reavalia');

/* O select de status oferece o semanal. Ele vem de STATUS_VIAGEM, que o
   test-status-tag.mjs ja pina nas tres listas; aqui trava que o select de
   fato e montado daquela lista, e nao de uma lista paralela. */
assert.ok(/Object\.keys\(STATUS_VIAGEM\)/.test(app), 'o select de status e montado de STATUS_VIAGEM');

/* ---------- a marcacao, EXECUTADA ----------
   rotDatasHtml mora no painel/datas.js justamente para isto: no app.js so
   daria para conferir o texto do fonte. */
const html = rotDatasHtml([{ titulo: 'Datas de Dezembro', datas: ['02/12/39', '10/12/39'] }]);
assert.ok(html.includes('class="rd-grupo"'), 'desenha o grupo');
assert.equal((html.match(/class="rd-data"/g) || []).length, 2, 'um campo por data');
assert.equal((html.match(/class="rd-del-data"/g) || []).length, 2, 'um botao de excluir por data');
assert.ok(/class="rd-del-mes"/.test(html), 'botao de excluir o mes');
/* SEM inputmode="numeric": no iPhone esse e o teclado de digitos PUROS,
   sem barra e sem tecla para voltar ao ABC - e o formato exigido aqui e
   02/12/26. A cliente usa o painel no celular. */
assert.ok(!/inputmode/.test(html), 'o campo de data nao prende o teclado em digitos');
/* Classe da CASA (rep-add), nao uma inventada: na primeira versao eu usei
   um `btn-sec` que nao existe no painel.css, e os dois botoes sairam sem
   estilo nenhum - so apareceu abrindo a tela. */
assert.ok(/class="rep-add rd-add-data"/.test(html), 'o botao de adicionar data usa a classe da casa');
/* Nenhum atributo com aspa simples: o esc() do app.js nao escapa aspa
   simples, e por isso esta marcacao usa o rotEsc proprio, que escapa. */
assert.ok(!/<(input|button|div)[^>]*=\s*'/.test(html), 'nenhum atributo usa aspa simples');

/* Grupo nenhum ainda assim desenha um campo: senao a cliente abre o bloco e
   nao tem onde digitar. */
assert.ok(rotDatasHtml([]).includes('class="rd-data"'), 'lista vazia desenha um campo para digitar');

/* O escape de verdade, com o caractere que o esc() do app deixaria passar. */
/* O contrato que de fato protege: o payload atravessa o escapador, e so
   aparece na forma escapada. Procurar a palavra "onfocus" no html nao prova
   nada - dentro de um atributo bem fechado ela e texto inofensivo, e foi
   assim que a primeira versao desta assercao ficou vermelha com o codigo
   certo. */
for (const veneno of ['" onfocus=alert(1) x="', "' onfocus=alert(1) x='", '"><script>alert(1)</script>']) {
  const h = rotDatasHtml([{ titulo: veneno, datas: [veneno] }]);
  assert.ok(h.includes(rotEsc(veneno)), 'o payload aparece escapado: ' + veneno);
  assert.ok(!h.includes(veneno), 'e nunca cru: ' + veneno);
  assert.ok(!h.includes('<script'), 'nenhuma tag nova: ' + veneno);
}
assert.equal(rotEsc('<a href="x">&'), '&lt;a href=&quot;x&quot;&gt;&amp;', 'rotEsc escapa os cinco');
assert.equal(rotEsc("'"), '&#39;', 'rotEsc escapa a aspa simples, que e o que o esc() do app deixa passar');

console.log('rot-datas ok');
