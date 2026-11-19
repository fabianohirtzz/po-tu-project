/* Memoria de origem do site (fase 2 do rastreio, 13/11/2026).
 *
 * O defeito que ela fecha: o formulario lia a UTM e o gclid so da URL da
 * PROPRIA pagina. Quem chegava pelo anuncio em /roteiros/turquia e preenchia
 * em /contato, ou voltava dias depois, virava lead sem origem: o anuncio
 * pagou o clique e a venda nunca aparecia no ROAS nem voltava ao Google Ads.
 *
 * Aqui o assets/js/origem.js roda DE VERDADE, num navegador de mentira em que
 * "abrir uma pagina" e executar o arquivo de novo com o mesmo localStorage. */
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import vm from 'node:vm';

const le = rel => readFileSync(new URL('../' + rel, import.meta.url), 'utf8').split('\r').join('');
const SRC = le('assets/js/origem.js');
const SITE = 'https://pereiraoliveiraturismo.com.br';
const T0 = Date.parse('2026-11-13T12:00:00Z'), DIA = 864e5;

function navegador({ bloqueado = false } = {}) {
  const dados = new Map();
  const ls = {
    getItem: k => { if (bloqueado) throw new Error('SecurityError'); return dados.has(k) ? dados.get(k) : null; },
    setItem: (k, v) => { if (bloqueado) throw new Error('SecurityError'); dados.set(k, String(v)); },
  };
  return {
    dados,
    /* Uma pagina aberta: o origem.js roda, e o que ele pendurar no clique e
       o que ele mandar por sendBeacon ficam capturados. clica(href) simula o
       toque num link e devolve o href com que o navegador seguiria. */
    pagina(caminho, referrer = '', agora = T0) {
      const ouvintes = [], beacons = [];
      const window = {
        location: { href: caminho.startsWith('http') ? caminho : SITE + caminho }, localStorage: ls,
        navigator: { sendBeacon: (url, fd) => { beacons.push([url, Object.fromEntries(fd.entries())]); return true; } },
      };
      const document = { referrer, addEventListener: (tipo, fn) => { if (tipo === 'click') ouvintes.push(fn); } };
      vm.runInNewContext(SRC, { window, document, Date: { now: () => agora }, URL, URLSearchParams, JSON, FormData });
      const clica = (href) => { const a = { href, closest: () => a }; ouvintes.forEach((f) => f({ target: a })); return a.href; };
      return { po: window.poOrigem, clica, beacons, ouvintes };
    },
    abre(caminho, referrer = '', agora = T0) { return this.pagina(caminho, referrer, agora).po; },
  };
}
const ANUNCIO = '/roteiros/turquia?gclid=CjAbc&utm_source=google&utm_medium=cpc&utm_campaign=turquia-2027';

/* ---------- 1. o caso que motivou: anuncio num roteiro, formulario em outra pagina ---------- */
{
  const b = navegador();
  b.abre(ANUNCIO, 'https://www.google.com/');
  const c = b.abre('/contato', SITE + '/roteiros/turquia', T0 + 3600e3).campos();
  assert.equal(c.gclid, 'CjAbc', 'o gclid da chegada vai com o lead preenchido em outra pagina');
  assert.equal(c.utm_campaign, 'turquia-2027');
  assert.equal(c.landing_page, SITE + ANUNCIO, 'a pagina de entrada e a da chegada, nao a do formulario');
  assert.equal(c.referrer, 'https://www.google.com/', 'o referrer e o de quem mandou, nao a navegacao interna');

  // dias depois, direto: a memoria segue
  assert.equal(b.abre('/', '', T0 + 10 * DIA).campos().gclid, 'CjAbc', 'volta direta dias depois ainda traz o gclid');
  // volta pela BUSCA: site de fora nao apaga campanha
  assert.equal(b.abre('/', 'https://www.google.com/', T0 + 20 * DIA).campos().gclid, 'CjAbc',
    'quem clicou no anuncio e voltou pela busca veio do anuncio');

  // campanha nova entra INTEIRA: nada da campanha anterior sobra misturado
  const meta = b.abre('/?utm_source=fb&utm_medium=paid&utm_campaign=chile&fbclid=IwAR1', 'https://m.facebook.com/', T0 + 30 * DIA).campos();
  assert.equal(meta.utm_source, 'fb');
  assert.equal(meta.fbclid, 'IwAR1');
  assert.equal(meta.gclid, '', 'o gclid da campanha velha NAO fica junto da UTM nova (o painel classificaria errado)');
  assert.equal(meta.utm_campaign, 'chile');
}

/* ---------- 2. validade de 90 dias (a janela do gclid no Google Ads) ---------- */
{
  const b = navegador();
  b.abre(ANUNCIO);
  assert.equal(b.abre('/', '', T0 + 90 * DIA).campos().gclid, 'CjAbc', 'no 90o dia ainda vale');
  const vencido = b.abre('/contato', '', T0 + 91 * DIA).campos();
  assert.equal(vencido.gclid, '', 'depois de 90 dias a memoria nao vale');
  assert.equal(vencido.landing_page, SITE + '/contato', 'e o lead volta a ser o da propria visita');

  const futuro = navegador();
  futuro.dados.set('po_origem', JSON.stringify({ ts: T0 + 5 * DIA, nivel: 2, gclid: 'Relogio' }));
  assert.equal(futuro.abre('/').campos().gclid, '', 'carimbo do futuro (relogio errado) nao vale');
}

/* ---------- 3. niveis: campanha > site de fora > nada ---------- */
{
  const b = navegador();
  b.abre('/', 'https://chatgpt.com/');
  assert.equal(b.abre('/contato', SITE + '/').campos().referrer, 'https://chatgpt.com/', 'site de fora fica guardado');
  b.abre('/', 'https://www.bing.com/', T0 + DIA);
  assert.equal(b.abre('/contato', '', T0 + DIA).campos().referrer, 'https://www.bing.com/', 'site de fora mais novo troca o mais velho');
  b.abre('/?utm_source=instagram&utm_medium=bio&utm_campaign=link', '', T0 + 2 * DIA);
  assert.equal(b.abre('/contato', 'https://www.google.com/', T0 + 3 * DIA).campos().utm_medium, 'bio',
    'campanha (a bio) troca o site de fora, e o site de fora depois nao a apaga');
}

/* ---------- 4. o que NAO e origem nao grava nada ---------- */
{
  const b = navegador();
  b.abre('/', SITE + '/roteiros');
  b.abre('/', 'https://www.pereiraoliveiraturismo.com.br/');
  b.abre('/', 'https://fabianohirtzz.github.io/po-tu-project/');
  b.abre('/', '');
  assert.equal(b.dados.size, 0, 'navegacao interna, preview e visita direta nao gravam');
  // sem memoria, o formulario manda o que a pagina tem: o comportamento de antes
  const c = b.abre('/contato', SITE + '/').campos();
  assert.equal(c.referrer, SITE + '/', 'sem memoria, o referrer e o da pagina, como sempre foi');
  assert.equal(c.landing_page, SITE + '/contato');
}

/* ---------- 5. nada aqui pode quebrar a pagina ---------- */
{
  const b = navegador({ bloqueado: true });
  let p;
  assert.doesNotThrow(() => { p = b.abre(ANUNCIO); }, 'armazenamento bloqueado (aba anonima) nao derruba a pagina');
  assert.equal(p.campos().gclid, 'CjAbc', 'e o lead preenchido na pagina de chegada ainda leva o gclid');

  const lixo = navegador();
  lixo.dados.set('po_origem', '{nao e json');
  assert.doesNotThrow(() => lixo.abre('/').campos(), 'memoria corrompida e ignorada');
  lixo.dados.set('po_origem', JSON.stringify({ ts: 'ontem', nivel: 2, gclid: 'x' }));
  assert.equal(lixo.abre('/').campos().gclid, '', 'memoria de formato estranho e ignorada');

  const longo = navegador().abre('/?utm_campaign=' + 'a'.repeat(900)).campos();
  assert.equal(longo.utm_campaign.length, 500, 'campo vindo de fora tem teto de 500');
  assert.ok(longo.landing_page.length <= 500, 'a URL de entrada tambem');
}

/* ---------- 6. a MESMA lista de campos nos quatro lugares ----------
   origem.js -> lead-form.js (gravacao de reserva) -> enviar.php -> po_leads.
   Campo que falta em um deles some em silencio no meio do caminho. */
const CAMPOS = [...navegador().abre('/').CAMPOS];   // copia: o array do vm e de outro "realm"
assert.deepEqual(CAMPOS, ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
  'gclid', 'gbraid', 'wbraid', 'fbclid', 'referrer', 'landing_page']);
const leadForm = le('assets/js/lead-form.js'), enviar = le('enviar.php');
const tabela = /create table if not exists public\.po_leads \(([\s\S]*?)\n\);/.exec(le('supabase/schema.sql'));
const colunas = new Set(tabela[1].split('\n').map(l => (/^\s*([a-z_]+)\s/.exec(l) || [])[1]).filter(Boolean));
for (const m of readdirSync(new URL('../supabase/migrations/', import.meta.url))) {
  for (const c of le('supabase/migrations/' + m).matchAll(/alter table public\.po_leads add column if not exists (\w+)/g)) colunas.add(c[1]);
}
for (const k of CAMPOS) {
  assert.ok(new RegExp('\\b' + k + ": data\\.get\\('" + k + "'\\)").test(leadForm), 'lead-form.js grava ' + k + ' na reserva');
  assert.ok(new RegExp("'" + k + "'\\s*=>\\s*campoOrigem\\('" + k + "'\\)").test(enviar), 'enviar.php grava ' + k + ' com teto');
  assert.ok(colunas.has(k), 'po_leads.' + k + ' existe no banco');
}
// o teto e o mesmo nos dois lados
assert.ok(/var MAX = 500;/.test(SRC) && /mb_substr\(\$v, 0, 500, 'UTF-8'\) : substr\(\$v, 0, 500\)/.test(enviar), 'teto de 500 no navegador e no servidor');

/* As colunas que nasceram em 13/11 sao as que a gravacao tenta de novo SEM,
   se o banco ainda nao as tem: a mesma lista na migration, no lead-form e no
   enviar.php. Faltar uma na lista de retentativa = PGRST204 = lead perdido. */
const novas = [...le('supabase/migrations/2026-11-13-leads-ids-clique.sql').matchAll(/add column if not exists (\w+)/g)].map(m => m[1]).sort();
const listaDe = (txt, re) => (re.exec(txt) || [, ''])[1].match(/[a-z_]+/g).sort();
assert.deepEqual(listaDe(leadForm, /\[('utm_term'[^\]]*)\]\.forEach/), novas, 'lead-form tenta de novo sem exatamente as colunas novas');
assert.deepEqual(listaDe(enviar, /array_flip\(\[([^\]]*)\]\)/), novas, 'enviar.php tenta de novo sem exatamente as colunas novas');
assert.ok(/\$code === 400 && is_string\(\$resp\) && strpos\(\$resp, 'PGRST204'\) !== false/.test(enviar), 'enviar.php so tenta de novo no erro de coluna');
assert.ok(/if \(error && error\.code === 'PGRST204'\)/.test(leadForm), 'lead-form so tenta de novo no erro de coluna');
assert.ok(!/\bdrop|\bdelete|\bupdate/i.test(le('supabase/migrations/2026-11-13-leads-ids-clique.sql').replace(/^--.*$/gm, '')), 'migration so aditiva');

/* ---------- 7. o formulario usa a memoria, e sobrevive sem ela ---------- */
function casa(src, i, abre, fecha) { let d = 0; for (let k = i; k < src.length; k++) { if (src[k] === abre) d++; else if (src[k] === fecha && --d === 0) return k + 1; } return -1; }
function fn(src, nome) { const i = src.indexOf('function ' + nome + '('); assert.ok(i >= 0, nome + ' existe'); return src.slice(i, casa(src, src.indexOf('{', i), '{', '}')); }
const aplica = new vm.Script(fn(leadForm, 'poAplicaOrigem') + fn(leadForm, 'poSemOrigemNova') + ';({poAplicaOrigem,poSemOrigemNova})');
const rodaCom = window => aplica.runInNewContext({ window });
{
  const fd = () => { const d = new FormData(); d.set('nome', 'Ensaio'); d.set('gclid', ''); d.set('referrer', SITE + '/'); return d; };
  const { poAplicaOrigem } = rodaCom({ poOrigem: { campos: () => ({ gclid: 'CjAbc', referrer: 'https://www.google.com/', fbclid: '' }) } });
  const d = poAplicaOrigem(fd());
  assert.equal(d.get('gclid'), 'CjAbc', 'a memoria vence o campo oculto da pagina');
  assert.equal(d.get('referrer'), 'https://www.google.com/');
  assert.equal(d.get('nome'), 'Ensaio', 'o resto do formulario fica intacto');

  const quebrado = rodaCom({ poOrigem: { campos: () => { throw new Error('x'); } } }).poAplicaOrigem;
  assert.equal(quebrado(fd()).get('referrer'), SITE + '/', 'origem.js com erro: fica o que a pagina tinha, sem impedir o envio');
  assert.equal(rodaCom({}).poAplicaOrigem(fd()).get('referrer'), SITE + '/', 'pagina sem origem.js: igual a antes');

  const { poSemOrigemNova } = rodaCom({});
  const sem = poSemOrigemNova({ nome: 'x', gclid: 'g', fbclid: 'f', gbraid: 'b', wbraid: 'w', utm_term: 't', utm_content: 'c', utm_source: 's' });
  assert.deepEqual(Object.keys(sem).sort(), ['gclid', 'nome', 'utm_source'], 'a reserva tira so as colunas novas');
}
assert.ok(/const data = poAplicaOrigem\(new FormData\(form\)\);/.test(leadForm), 'o envio passa pela memoria (trava no ponto de chamada)');
assert.ok(leadForm.indexOf('poAplicaOrigem(new FormData') < leadForm.indexOf("fetch('/enviar.php'"), 'antes de chamar o enviar.php');

/* ---------- 8. toda pagina publica carrega a memoria ----------
   Anuncio pode cair em qualquer uma. Pagina nova sem o origem.js deixaria
   de guardar a chegada sem ninguem notar. */
const raiz = readdirSync(new URL('../', import.meta.url));
const publicas = raiz.filter(f => (/\.html$/.test(f) && le(f).includes('GT-TNH4L3BV')) || (/\.php$/.test(f) && le(f).includes('po_head(')));
assert.ok(publicas.length >= 8, 'achou as paginas publicas: ' + publicas.join(', '));
for (const p of publicas) assert.ok(/["']\/?assets\/js\/origem\.js\?v=\d+["']/.test(le(p)), p + ' carrega o origem.js');

/* ---------- 9. WhatsApp do site (fase 3A) ----------
   O botao abre o app sem nada da URL. Visita com campanha leva no texto um
   codigo, e o clique vai para o /clique.php com a origem inteira. */
const WA = 'https://wa.me/5548996048882';
const textoDe = (href) => new URL(href).searchParams.get('text');
const RE_COD = /\(ref ([GMS]-[BCDFGHJKLMNPQRSTVWXZ]{5})\)$/;
{
  const b = navegador();
  b.abre(ANUNCIO, 'https://www.google.com/');
  const p = b.pagina('/roteiros/turquia', SITE + '/');
  const href = p.clica(WA);
  assert.ok(href.startsWith(WA + '?text='), 'o link segue para o numero da agencia: ' + href);
  const cod = RE_COD.exec(textoDe(href));
  assert.ok(cod, 'o texto termina com o codigo: ' + textoDe(href));
  assert.equal(textoDe(href), p.po.TEXTO + ' (ref ' + cod[1] + ')', 'texto fixo + codigo, nada mais');
  assert.equal(cod[1][0], 'G', 'quem veio do Google Ads leva G');
  assert.equal(p.beacons.length, 1, 'o clique e registrado uma vez');
  const [url, enviado] = p.beacons[0];
  assert.equal(url, '/clique.php', 'caminho absoluto: vale tambem em /roteiros/<slug>');
  assert.equal(enviado.codigo, cod[1]);
  assert.equal(enviado.gclid, 'CjAbc', 'o servidor recebe o gclid da chegada');
  assert.equal(enviado.utm_campaign, 'turquia-2027');
  assert.equal(enviado.landing_page, SITE + ANUNCIO);
  assert.equal(enviado.pagina, SITE + '/roteiros/turquia', 'e a pagina onde o botao foi tocado');

  const p2 = b.pagina('/contato', SITE + '/', T0 + DIA);
  assert.equal(RE_COD.exec(textoDe(p2.clica(WA)))[1], cod[1], 'o mesmo visitante leva o MESMO codigo depois');
  const p3 = b.pagina(ANUNCIO, '', T0 + 2 * DIA);
  assert.equal(RE_COD.exec(textoDe(p3.clica(WA)))[1], cod[1], 'recarregar o anuncio (mesma campanha) nao troca o codigo');
  const p4 = b.pagina('/?utm_source=fb&utm_medium=paid&utm_campaign=chile', '', T0 + 3 * DIA);
  const novo = RE_COD.exec(textoDe(p4.clica(WA)))[1];
  assert.ok(novo[0] === 'M' && novo !== cod[1], 'campanha nova da Meta: codigo novo, com M (' + novo + ')');
}
{
  const b = navegador();
  b.abre('/', 'https://www.google.com/');
  const p = b.pagina('/contato', SITE + '/');
  assert.equal(p.clica(WA), WA, 'visita sem campanha (busca organica): o WhatsApp abre como sempre');
  assert.equal(p.beacons.length, 0, 'e nada e registrado');

  const b2 = navegador(); b2.abre(ANUNCIO);
  const q = b2.pagina('/');
  assert.equal(q.clica('https://wa.me/5511999999999'), 'https://wa.me/5511999999999', 'outro numero: intacto');
  assert.equal(q.clica(SITE + '/contato'), SITE + '/contato', 'link do proprio site: intacto');
  assert.equal(q.beacons.length, 0, 'link que nao e o WhatsApp da agencia nao registra nada');
  assert.ok(/^Quero o Chile \(ref G-[BCDFGHJKLMNPQRSTVWXZ]{5}\)$/.test(textoDe(q.clica(WA + '?text=' + encodeURIComponent('Quero o Chile')))),
    'texto que o link ja tinha fica, com o codigo no fim');
}
{
  const p = navegador({ bloqueado: true }).pagina(ANUNCIO);
  assert.ok(RE_COD.test(textoDe(p.clica(WA))), 'aba anonima na pagina do anuncio ainda leva o codigo');
  assert.equal(p.beacons.length, 1);
  const q = navegador().pagina(ANUNCIO);
  assert.doesNotThrow(() => q.ouvintes.forEach((f) => f({ target: { closest: () => { throw new Error('x'); } } })),
    'erro no clique nunca impede o WhatsApp de abrir');
}

/* A letra do codigo (G/M/S) e uma pista para quem le a conversa; quem
   classifica e o painel. Os dois tem que concordar caso a caso. */
{
  const app = le('painel/app.js');
  const nucleo = navegador().abre('/')._nucleo;
  const classifica = new vm.Script(fn(app, 'poRefHost') + fn(app, 'classifyChannel') + ';classifyChannel').runInNewContext({ URL });
  const TABELA = [{ gclid: 'x' }, { gbraid: 'x' }, { wbraid: 'x' }, { utm_source: 'google', utm_medium: 'cpc' },
    { utm_source: 'youtube', utm_medium: 'paid' }, { utm_source: 'fb', utm_medium: 'paid' }, { utm_source: 'ig', utm_medium: 'paid' },
    { utm_source: 'Facebook', utm_medium: 'Paid_Social' }, { utm_source: 'instagram', utm_medium: 'cpc' },
    { utm_source: 'bing', utm_medium: 'cpc' }, { utm_source: 'instagram', utm_medium: 'bio' }, { fbclid: 'x' },
    { utm_source: 'chatgpt.com' }, { utm_source: 'fb', utm_medium: 'paid', gclid: 'x' }, { utm_source: 'yandex', utm_medium: 'cpc' },
    // Google Meu Negocio e campanha sem ser anuncio: letra S; com midia paga, e Google Ads.
    { utm_source: 'google', utm_medium: 'organic', utm_campaign: 'google-meu-negocio' }, { utm_source: 'gmb', utm_medium: 'organic' },
    { utm_source: 'google', utm_medium: 'cpc', utm_campaign: 'google-meu-negocio' },
    // IA sem ser anuncio: letra S; anuncio dentro de IA e pago de outra plataforma, tambem S.
    { utm_source: 'perplexity' }, { utm_source: 'chatgpt.com', utm_medium: 'cpc' }];
  for (const r of TABELA) {
    const canal = classifica(r);
    assert.equal(nucleo.plataforma(r), canal === 'google' ? 'G' : canal === 'meta' ? 'M' : 'S',
      'letra do codigo e canal do painel concordam: ' + JSON.stringify(r) + ' -> ' + canal);
  }
  // codigo: so consoante, sempre no formato que o servidor e o painel aceitam
  for (let i = 0; i < 300; i++) {
    const c = nucleo.novoCodigo({ gclid: 'x' }, () => Math.floor(Math.random() * 4294967296));
    assert.ok(/^G-[BCDFGHJKLMNPQRSTVWXZ]{5}$/.test(c), 'codigo fora do formato: ' + c);
  }
}

/* ---------- 10. a chegada na /link e a bio do Instagram ----------
   O link da bio e LIMPO (com UTM ele apareceria inteiro no perfil) e o
   navegador do Instagram quase nunca manda referrer. Sem o script de cabeca
   da link.html a chegada nao tinha origem nenhuma: o WhatsApp DA PROPRIA /link
   abria sem codigo, o formulario so herdava a bio se a pessoa tocasse num
   card, e o GA4 contava visita direta. O script roda aqui de verdade,
   extraido do HTML, e o origem.js roda depois com a URL que ele deixou. */
{
  const html = le('link.html');
  const m = /<script id="po-link-bio">([\s\S]*?)<\/script>/.exec(html);
  assert.ok(m, 'link.html tem o script da bio');
  const ini = m.index;
  assert.ok(ini < html.indexOf('<script async src="https://www.googletagmanager.com/gtag/js'),
    'roda ANTES da tag do Google: depois dela o GA4 ja contou a visita sem a UTM');
  assert.ok(ini < html.indexOf('<script src="assets/js/origem.js'), 'e antes da memoria de origem');
  const bio = new vm.Script(m[1]);

  /* Chega na /link e devolve a URL que a tag do Google e o origem.js leem. */
  function chega(caminho, referrer = '', extra = {}) {
    const window = { location: { href: caminho.startsWith('http') ? caminho : SITE + caminho } };
    window.history = extra.history || { state: null, replaceState(s, t, url) { window.location.href = new URL(url, window.location.href).href; } };
    bio.runInNewContext({ window, document: { referrer }, URL: 'URL' in extra ? extra.URL : URL });
    return window.location.href;
  }
  const BIO = 'utm_source=instagram&utm_medium=bio&utm_campaign=link';
  const comBio = SITE + '/link?' + BIO;

  assert.equal(chega('/link'), comBio, 'sem referrer (o normal no Instagram): ganha a UTM da bio');
  assert.equal(chega('/link', 'https://l.instagram.com/'), comBio, 'referrer do Instagram: idem');
  assert.equal(chega('/link', 'android-app://com.instagram.android/'), comBio, 'app do Instagram no Android: idem');
  assert.equal(chega('/link#roteiros'), comBio + '#roteiros', 'a ancora fica');
  assert.equal(chega('https://fabianohirtzz.github.io/po-tu-project/link.html'),
    'https://fabianohirtzz.github.io/po-tu-project/link.html?' + BIO, 'o caminho fica (preview)');
  const fb = chega('/link?fbclid=IwAR9');
  assert.equal(fb, SITE + '/link?fbclid=IwAR9&' + BIO, 'o fbclid que o Instagram poe sozinho nao e UTM: ganha a bio e ele fica');

  // O que a visita ja trouxe vence: cada parametro, um por um.
  for (const k of ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'gbraid', 'wbraid']) {
    assert.equal(chega('/link?' + k + '=x'), SITE + '/link?' + k + '=x', k + ' da propria visita: a URL fica como veio');
  }
  for (const [c, r, por] of [
    ['/link?utm_source=ig&utm_medium=social&utm_content=link_in_bio', '', 'a UTM que o proprio Instagram as vezes poe'],
    ['/link?utm_source=instagram&utm_medium=story&utm_campaign=promo', '', 'link de story com UTM propria'],
    ['/link', 'https://m.facebook.com/', 'referrer de outro site (o origem.js guarda o referrer)'],
    ['/link', SITE + '/roteiros', 'navegacao interna'],
  ]) assert.equal(chega(c, r), SITE + c, 'intacta: ' + por);

  // Nada aqui pode quebrar a pagina.
  assert.doesNotThrow(() => chega('/link', '', { history: { state: null, replaceState() { throw new Error('SecurityError'); } } }),
    'replaceState recusado: a pagina segue');
  assert.doesNotThrow(() => chega('/link', '', { URL: undefined }), 'navegador sem URL: a pagina segue');

  // A cadeia inteira: script -> origem.js -> WhatsApp -> painel.
  const classifica = new vm.Script(fn(le('painel/app.js'), 'poRefHost') + fn(le('painel/app.js'), 'classifyChannel') + ';classifyChannel').runInNewContext({ URL });
  assert.equal(navegador().pagina('/link').clica(WA), WA, 'sem o script, a chegada da bio abria o WhatsApp SEM codigo (o buraco)');

  const b = navegador();
  const p = b.pagina(chega('/link'), '');
  const href = p.clica(WA);
  const cod = RE_COD.exec(textoDe(href));
  assert.ok(cod && cod[1][0] === 'S', 'com o script, o WhatsApp DA /link leva o codigo: ' + href);
  assert.equal(p.beacons.length, 1);
  assert.equal(p.beacons[0][1].utm_medium, 'bio', 'o clique e registrado como bio');
  assert.equal(p.beacons[0][1].pagina, comBio, 'na pagina /link');
  const contato = b.abre('/contato', SITE + '/link', T0 + DIA).campos();
  assert.equal(contato.utm_campaign, 'link', 'formulario em outra pagina herda a bio, sem a pessoa ter tocado num card');
  assert.equal(classifica(contato), 'instagram', 'e o painel classifica como Instagram');
  assert.equal(classifica(navegador().abre(fb).campos()), 'instagram', 'com o fbclid tambem (sozinho ele dava Social)');
  assert.equal(classifica(navegador().abre('/link?fbclid=IwAR9').campos()), 'social', 'o que era antes do script');
}

console.log('test-origem-site OK (' + publicas.length + ' paginas)');
