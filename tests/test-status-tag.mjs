import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

/* Tag de status da viagem na capa do card da home (canto superior direito).
   A lista e a mesma de PO_STATUS_VIAGEM em lib/po-view.php: o card da home e
   o hero da pagina tem que dizer a mesma coisa sobre a mesma viagem. */
const shared = readFileSync(new URL('../assets/js/roteiros-shared.js', import.meta.url), 'utf8');
const window = {};
new Function('window', shared)(window);
const tag = window.poStatusTag;
assert.equal(typeof tag, 'function', 'poStatusTag exposta');

assert.equal(tag({}), '', 'sem status, sem tag');
assert.equal(tag({ status_viagem: null }), '', 'null, sem tag');
assert.equal(tag({ status_viagem: 'em_breve' }), '<span class="st-tag st-tag--em_breve">Em breve</span>');
assert.equal(tag({ status_viagem: 'em_viagem' }), '<span class="st-tag st-tag--em_viagem">Em viagem</span>');
assert.equal(tag({ status_viagem: 'esgotado' }), '<span class="st-tag st-tag--esgotado">Vagas esgotadas</span>');
assert.equal(tag({ status_viagem: '"><img src=x onerror=1>' }), '', 'valor fora da lista nao vira html');
assert.equal(tag({ status_viagem: 'toString' }), '', 'nem chave herdada do Object');

assert.equal(tag({ status_viagem: 'ultimas_vagas' }), '<span class="st-tag st-tag--ultimas_vagas">Últimas vagas</span>');

/* "Saidas semanais" (pedido do cliente, 04/11/2026): o roteiro do Peru sai
   toda semana, entao nao tem periodo unico. O status e condicao permanente
   do roteiro, nao etapa do ciclo da viagem, por isso abre a lista. */
assert.equal(tag({ status_viagem: 'semanal' }), '<span class="st-tag st-tag--semanal">Saídas semanais</span>');

/* MESMA lista, na mesma ordem, nos tres lugares (e no check do banco). Um
   status que existe no painel e falta no site vira tag que nao aparece; um
   que existe no site e falta no CSS vira tag sem cor. */
const LISTA = [['semanal', 'Saídas semanais'], ['em_breve', 'Em breve'], ['ultimas_vagas', 'Últimas vagas'], ['esgotado', 'Vagas esgotadas'], ['em_viagem', 'Em viagem']];
const view = readFileSync(new URL('../lib/po-view.php', import.meta.url), 'utf8');
const app = readFileSync(new URL('../painel/app.js', import.meta.url), 'utf8');
const chavesPhp = [...view.match(/const PO_STATUS_VIAGEM = \[([\s\S]*?)\];/)[1].matchAll(/'(\w+)'\s*=>\s*'([^']+)'/g)].map(m => [m[1], m[2]]);
const chavesJs = [...shared.match(/var STATUS_VIAGEM = \{([^}]*)\}/)[1].matchAll(/(\w+): '([^']+)'/g)].map(m => [m[1], m[2]]);
const chavesPainel = [...app.match(/const STATUS_VIAGEM=Object\.assign\(Object\.create\(null\),\{([^}]*)\}/)[1].matchAll(/(\w+):'([^']+)'/g)].map(m => [m[1], m[2]]);
assert.deepEqual(chavesPhp, LISTA, 'po-view.php');
assert.deepEqual(chavesJs, LISTA, 'roteiros-shared.js');
assert.deepEqual(chavesPainel, LISTA, 'painel/app.js');
const css = readFileSync(new URL('../assets/css/status-tag.css', import.meta.url), 'utf8');
const painelCss = readFileSync(new URL('../painel/painel.css', import.meta.url), 'utf8');
for (const [k] of LISTA) {
  assert.ok(new RegExp('\\.st-tag--' + k + '\\{background:').test(css), 'status-tag.css tem a cor de ' + k);
  assert.ok(new RegExp('\\.st-tag--' + k + '\\{--st-ico:').test(css), 'status-tag.css tem o icone de ' + k);
  assert.ok(new RegExp('\\.st-tag--' + k + '\\{background:').test(painelCss), 'painel.css tem a cor de ' + k);
}

// a home de fato usa a tag no slide (trava no ponto de chamada)
const main = readFileSync(new URL('../assets/js/main.js', import.meta.url), 'utf8');
assert.ok(/poStatusTag\(r\)/.test(main), 'mapDb leva o status para o slide');
assert.ok(/r\.tag \+ '<img/.test(main), 'o slide desenha a tag junto da capa');

/* "Em todo local que mostre o roteiro" (pedido de 22/10/2026). Cada lugar que
   desenha card de roteiro tem que chamar a funcao da tag, e cada pagina que
   tem esses cards tem que carregar o CSS dela. Em 22/10 a tag existia na home
   e faltava no "Outros roteiros", na Nossa Historia e no /link. */
const ler = p => readFileSync(new URL('../' + p, import.meta.url), 'utf8');
for (const js of ['assets/js/main.js', 'assets/js/historia.js', 'assets/js/link.js']) {
  assert.ok(ler(js).includes('poStatusTag('), js + ' desenha a tag');
}
for (const [php, fn] of [['roteiros.php', 'po_roteiros_html'], ['roteiro.php', 'po_outros_roteiros'], ['roteiro.php', 'po_roteiro_html']]) {
  const corpo = ler(php).match(new RegExp('function ' + fn + '\\([\\s\\S]*?\\n\\}'))[0];
  assert.ok(corpo.includes('po_status_tag('), php + ' ' + fn + ' desenha a tag');
}
// e o JS que desenha precisa da versao do roteiros-shared que tem poStatusTag
for (const pg of ['index.html', 'nossa-historia.html', 'link.html']) {
  const v = +(ler(pg).match(/roteiros-shared\.js\?v=(\d+)/) || [0, 0])[1];
  assert.ok(v >= 5, pg + ' pede roteiros-shared.js >= v5 (a que tem poStatusTag), pede v' + v);
}
// CSS da tag: UM arquivo, carregado por toda pagina que mostra roteiro
for (const pg of ['index.html', 'nossa-historia.html', 'link.html', 'roteiro.php', 'roteiros.php']) {
  assert.ok(/status-tag\.css\?v=\d+/.test(ler(pg)), pg + ' carrega status-tag.css');
}
for (const css of ['assets/css/style.css', 'assets/css/roteiro.css', 'assets/css/historia.css', 'assets/css/link.css']) {
  assert.ok(!/\.st-tag--em_breve\{/.test(ler(css)), css + ' nao tem copia propria das cores da tag');
}
const st = ler('assets/css/status-tag.css');
for (const sel of ['.slide .st-tag', '.rts__img .st-tag', '.rot-card .st-tag', '.lk-card__img .st-tag', '.hero__status']) {
  assert.ok(st.includes(sel), 'status-tag.css posiciona ' + sel);
}

console.log('status-tag ok');
