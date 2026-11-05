import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

/* Aba "Prova social" do painel (painel/vitrine.js), 22/10/2026.
   Avaliacoes do Google e posts do Instagram que aparecem na Nossa Historia e
   no painel Sobre da home. */
const src = readFileSync(new URL('../painel/vitrine.js', import.meta.url), 'utf8');
const pega = n => { const m = src.match(new RegExp('function ' + n + '\\([\\s\\S]*?\\n\\}')); assert.ok(m, n + ' no vitrine.js'); return m[0]; };
const poNomeCurto = new Function(pega('poNomeCurto') + '; return poNomeCurto;')();

/* Nome publicado = primeiro nome + inicial do ultimo (LGPD, decidido com o
   cliente). A cliente cola o nome como esta no Google; o painel abrevia. */
assert.equal(poNomeCurto('Humberto Vieira'), 'Humberto V.');
assert.equal(poNomeCurto('Beatriz Esther Hagemann Ruschel'), 'Beatriz R.');
assert.equal(poNomeCurto('  sara   rauen '), 'Sara R.');
assert.equal(poNomeCurto('Marlene Michielin Michielin'), 'Marlene M.');
assert.equal(poNomeCurto('Joana H.'), 'Joana H.', 'ja abreviado fica como esta');
assert.equal(poNomeCurto('Madonna'), 'Madonna', 'nome unico fica como esta');
assert.equal(poNomeCurto('Álvaro Éverton'), 'Álvaro É.', 'acento');
assert.equal(poNomeCurto(''), '');

// trava no ponto de chamada
assert.ok(/nome:\s*poNomeCurto\(/.test(src), 'gravar avaliacao passa o nome por poNomeCurto');
/* Instagram com VIDEO (padrao Casa Angelina): upload pelo mesmo POVideo dos
   roteiros, tipo 'feed', com um id por item no nome do arquivo. */
const poIdDoVideo = new Function(pega('poIdDoVideo') + '; return poIdDoVideo;')();
assert.equal(poIdDoVideo('https://pereiraoliveiraturismo.com.br/videos/ig-0a1b2c3d-feed-9f8e7d6c.mp4'), 'ig-0a1b2c3d');
assert.equal(poIdDoVideo('https://pereiraoliveiraturismo.com.br/videos/turquia-insta-9f8e7d6c.mp4'), null,
  'video de roteiro nunca vira alvo da exclusao do feed');
assert.equal(poIdDoVideo('/videos/ig-0a1b2c3d-feed-9f8e7d6c.mp4?x=../'), null);
// .* na mesma linha: os argumentos tem parenteses e ; dentro (a funcao de progresso)
assert.ok(/POVideo\.upload\(.*'feed'\);/.test(src), 'envia como tipo feed');
assert.ok(/POVideo\.encode\(.*'feed'\);/.test(src), 'comprime antes de enviar');
assert.ok(/POVideo\.remove\(slug, [^)]*'feed'\)/.test(src), 'excluir apaga o arquivo do item');
assert.ok(!/insta-capa/.test(src), 'o caminho antigo (capa pelo link) saiu');
const enc = readFileSync(new URL('../painel/video-encode.js', import.meta.url), 'utf8');
assert.ok(/t === 'feed'/.test(enc), 'video-encode.js deixa passar o tipo feed');
const upl = readFileSync(new URL('../upload-video.php', import.meta.url), 'utf8');
assert.ok(/\['insta', 'capa', 'feed'\]/.test(upl), 'upload-video.php aceita o tipo feed (lista fechada)');
assert.ok((src.match(/limpaCacheSite\(\)/g) || []).length >= 2, 'toda gravacao limpa o cache do site');

/* Duas abas separadas (pedido do cliente, 23/10/2026): "Avaliações" e
   "Instagram". Antes era uma aba so, "Prova social". */
const html = readFileSync(new URL('../painel/index.html', import.meta.url), 'utf8');
const app = readFileSync(new URL('../painel/app.js', import.meta.url), 'utf8');
for (const v of ['avaliacoes', 'instagram']) {
  assert.ok(new RegExp('data-view="' + v + '"').test(html) && new RegExp('id="view-' + v + '"').test(html), 'aba e secao ' + v);
  assert.ok(new RegExp("v==='" + v + "'[\\s\\S]{0,300}poCarregaVitrine\\(\\)").test(app), 'app.js abre a aba ' + v + ' e carrega');
}
assert.ok(!/data-view="vitrine"/.test(html) && !/v==='vitrine'/.test(app), 'a aba unica antiga saiu');
assert.ok(/id="vit-av-lista"/.test(html.split('id="view-avaliacoes"')[1].split('</section>')[0]), 'lista de avaliacoes na aba Avaliações');
assert.ok(/id="vit-ig-lista"/.test(html.split('id="view-instagram"')[1].split('</section>')[0]), 'lista de videos na aba Instagram');
assert.ok(/<script src="vitrine\.js\?v=\d+"><\/script>/.test(html), 'script carregado com versao');

/* Instagram: SO envio de video. O link do post saiu (o "Ver no Instagram" do
   site vai ao perfil). Compressao automatica no envio, com aviso quando o
   navegador nao consegue comprimir. */
assert.ok(!/vit-ig-url|vit-ig-link/.test(html + src), 'nada de campo de link no Instagram');
assert.ok(/accept="video\/\*"/.test(html), 'o envio aceita so video');
assert.ok(/if \(POVideo\.supported\(\)\)/.test(src) && /n[aã]o comprime/i.test(src), 'comprime quando da, e avisa quando o navegador nao comprime');

console.log('vitrine ok');
