/* Eventos de anuncio do site (fase 4 do rastreio, 13/11/2026).
 *
 * O que ele trava:
 *   - com os IDS vazios (o arquivo como esta no Git), nada da Meta carrega e
 *     nada vai para o Google Ads: so o GA4 que ja estava na pagina;
 *   - com os IDS, cada destino recebe o evento certo, so no dominio de
 *     producao, e cada um liga sozinho;
 *   - conversao so de lead que GRAVOU: o lead-form.js roda de verdade aqui,
 *     com honeypot, pressa, campo faltando e servidor fora;
 *   - nenhum dado pessoal sai para a Meta ou para o Google;
 *   - o arquivo publicado: ID fora do formato fica vermelho, e o Pixel e o
 *     Google Ads so ligam com a politica de privacidade declarando os dois.
 *
 * Os scripts rodam DE VERDADE num navegador de mentira (node:vm), como em
 * tests/test-origem-site.mjs: "abrir uma pagina" e executar as tags <script>
 * dela, em ordem, no mesmo window. */
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import vm from 'node:vm';

const le = rel => readFileSync(new URL('../' + rel, import.meta.url), 'utf8').split('\r').join('');
const RASTREIO = le('assets/js/rastreio.js');
const ORIGEM = le('assets/js/origem.js');
const FORM = le('assets/js/lead-form.js');
const SITE = 'https://pereiraoliveiraturismo.com.br';
const WA = 'https://wa.me/5548996048882';
const FBEVENTS = 'https://connect.facebook.net/en_US/fbevents.js';
const limpo = x => JSON.parse(JSON.stringify(x));   // objeto do vm e de outro "realm"

const FALSOS = { META_PIXEL: '123456789012345', GOOGLE_ADS: 'AW-987654321', GOOGLE_ADS_LEAD: 'AbC-D_efG-h12', GOOGLE_ADS_WHATSAPP: 'Zx9-WaWa_77' };
const AW = FALSOS.GOOGLE_ADS;
const SEND_LEAD = AW + '/' + FALSOS.GOOGLE_ADS_LEAD;
const SEND_WA = AW + '/' + FALSOS.GOOGLE_ADS_WHATSAPP;
const INICIO = [['init', FALSOS.META_PIXEL], ['track', 'PageView']];

/* O arquivo com IDs preenchidos, para exercitar o caminho ligado. Cada troca
   TEM que acontecer: um replace que nao casasse testaria o arquivo desligado
   e passaria verde. */
function comIds(ids) {
  let src = RASTREIO;
  for (const [k, v] of Object.entries(ids)) {
    const antes = src;
    src = src.replace(new RegExp('(\\b' + k + ": )''"), (_, a) => a + "'" + v + "'");
    assert.notEqual(src, antes, "o bloco IDS do rastreio.js tem " + k + ": ''");
  }
  return src;
}

/* Uma pagina aberta. injetados = os <script src> que o codigo pendurou;
   dataLayer = tudo o que foi para o gtag; disparados = os eventos do document. */
function pagina(caminho, { scripts = [RASTREIO], gtag = true, h1 = 'Turquia com Antália', elementos = {}, fetch = null, extra = {} } = {}) {
  const url = new URL(caminho.startsWith('http') ? caminho : SITE + caminho);
  const ouvintes = {}, injetados = [], dataLayer = [], disparados = [];
  const pendura = (el) => { injetados.push(el.src); return el; };
  const document = {
    referrer: '',
    head: { appendChild: pendura },
    documentElement: { appendChild: pendura },
    createElement: (tag) => ({ tagName: String(tag).toUpperCase() }),
    querySelector: (sel) => (sel === 'h1' && h1 != null ? { textContent: h1 } : null),
    querySelectorAll: () => [],
    getElementById: (id) => elementos[id] || null,
    addEventListener: (tipo, fn) => { (ouvintes[tipo] = ouvintes[tipo] || []).push(fn); },
    dispatchEvent: (ev) => {
      disparados.push({ type: ev.type, detail: ev.detail === undefined ? null : limpo(ev.detail) });
      (ouvintes[ev.type] || []).slice().forEach((fn) => fn(ev));
      return true;
    },
  };
  const ctx = {
    document, location: { href: url.href, hostname: url.hostname, pathname: url.pathname, search: url.search },
    navigator: {}, URL, URLSearchParams, JSON, CustomEvent, FormData, setTimeout, console, ...extra,
  };
  if (fetch) ctx.fetch = fetch;
  if (gtag) { ctx.dataLayer = dataLayer; ctx.gtag = function () { dataLayer.push(Array.from(arguments)); }; }
  ctx.window = ctx;
  vm.createContext(ctx);
  for (const s of scripts) vm.runInContext(s, ctx);
  return {
    ctx, injetados, ouvintes, disparados,
    fila: () => (typeof ctx.fbq === 'function' && ctx.fbq.queue ? limpo(ctx.fbq.queue.map((a) => Array.from(a))) : null),
    google: () => limpo(dataLayer),
    clica(href) { const a = { href, closest: () => a }; (ouvintes.click || []).forEach((f) => f({ target: a })); },
    lead(detail) { document.dispatchEvent(new CustomEvent('po:lead', { detail })); },
  };
}

/* ---------- 1. o arquivo como esta no Git: Meta e Google Ads desligados ---------- */
{
  const p = pagina('/roteiros/turquia');
  assert.deepEqual(p.injetados, [], 'IDs vazios: nenhum script de fora (sem fbevents.js, sem cookie da Meta)');
  assert.equal(p.ctx.fbq, undefined, 'IDs vazios: o fbq nem existe');
  assert.deepEqual(p.google(), [], 'IDs vazios: nada vai para o Google ao abrir a pagina');
  p.lead({ roteiro: 'Turquia com Antália', origem: 'site-roteiro-turquia' });
  p.clica(WA);
  assert.deepEqual(p.google(), [['event', 'generate_lead', { roteiro: 'Turquia com Antália' }], ['event', 'clique_whatsapp', {}]],
    'IDs vazios: so o GA4 que ja estava na pagina recebe o lead e o WhatsApp, e nenhuma conversao do Google Ads');
  assert.equal(p.ctx.fbq, undefined, 'IDs vazios: nem o lead nem o WhatsApp acordam a Meta');
  assert.deepEqual(limpo(p.ctx.poRastreio.ligado),
    { producao: true, meta: false, googleAds: false, conversaoFormulario: false, conversaoWhatsapp: false });
}

/* ---------- 2. tudo ligado: cada destino recebe o evento certo ---------- */
{
  const p = pagina('/roteiros/turquia', { scripts: [comIds(FALSOS)] });
  assert.deepEqual(p.injetados, [FBEVENTS], 'o Pixel carrega o fbevents.js uma vez');
  assert.deepEqual(p.fila(), [...INICIO,
    ['track', 'ViewContent', { content_name: 'Turquia com Antália', content_ids: ['turquia'], content_category: 'roteiro' }]],
    'pagina de roteiro: PageView e ViewContent com o nome do <h1> e o slug');
  assert.deepEqual(p.google(), [['config', AW]], 'a conta do Google Ads entra na pagina (a GT-TNH4L3BV nao a traz)');

  p.lead({ roteiro: 'Turquia com Antália', origem: 'site-roteiro-turquia' });
  assert.deepEqual(p.fila().slice(3), [['track', 'Lead', { content_name: 'Turquia com Antália' }]], 'formulario: Lead na Meta');
  assert.deepEqual(p.google().slice(1), [['event', 'conversion', { send_to: SEND_LEAD }], ['event', 'generate_lead', { roteiro: 'Turquia com Antália' }]],
    'formulario: a conversao do formulario no Google Ads e o generate_lead no GA4');

  p.clica(WA + '?text=' + encodeURIComponent('Olá! Vim pelo site e quero mais informações. (ref G-KTRBX)'));
  assert.deepEqual(p.fila().slice(4), [['track', 'Contact', {}]], 'WhatsApp: Contact na Meta (com ou sem o codigo do origem.js)');
  assert.deepEqual(p.google().slice(3), [['event', 'conversion', { send_to: SEND_WA }], ['event', 'clique_whatsapp', {}]],
    'WhatsApp: a conversao do WhatsApp no Google Ads e o clique_whatsapp no GA4');
  assert.deepEqual(limpo(p.ctx.poRastreio.ligado),
    { producao: true, meta: true, googleAds: true, conversaoFormulario: true, conversaoWhatsapp: true });
}
{
  // ViewContent so na pagina de UM roteiro
  for (const c of ['/', '/roteiros', '/roteiros/', '/contato', '/link', '/perguntas-frequentes', '/nossa-historia']) {
    assert.deepEqual(pagina(c, { scripts: [comIds(FALSOS)] }).fila(), INICIO, c + ': so PageView');
  }
  const barra = pagina('/roteiros/turquia/', { scripts: [comIds(FALSOS)] }).fila();
  assert.equal(barra[2][1], 'ViewContent', 'barra no fim ainda e a pagina do roteiro');
  const semH1 = pagina('/roteiros/chile-e-deserto-do-atacama', { scripts: [comIds(FALSOS)], h1: null }).fila();
  assert.equal(semH1[2][2].content_name, 'chile-e-deserto-do-atacama', 'sem <h1>, o nome e o slug');
  const quebrado = pagina('/roteiros/vietna', { scripts: [comIds(FALSOS)], h1: '\n   Vietnã completo\n   e Tailândia  ' }).fila();
  assert.equal(quebrado[2][2].content_name, 'Vietnã completo e Tailândia', 'quebra de linha do HTML nao vai para o nome');
  const espacos = pagina('/', { scripts: [comIds({ META_PIXEL: ' ' + FALSOS.META_PIXEL + ' ' })] }).fila();
  assert.deepEqual(espacos[0], ['init', FALSOS.META_PIXEL], 'espaco em volta do ID (colado do painel) nao derruba o Pixel');
}

/* ---------- 3. cada um liga sozinho ---------- */
{
  const meta = pagina('/contato', { scripts: [comIds({ META_PIXEL: FALSOS.META_PIXEL })] });
  meta.lead({ roteiro: '' });
  meta.clica(WA);
  assert.deepEqual(meta.fila(), [...INICIO, ['track', 'Lead', {}], ['track', 'Contact', {}]], 'so o Pixel: lead sem roteiro vai sem content_name');
  assert.deepEqual(meta.google(), [['event', 'generate_lead', {}], ['event', 'clique_whatsapp', {}]], 'so o Pixel: nada do Google Ads');

  const g = pagina('/contato', { scripts: [comIds({ GOOGLE_ADS: AW, GOOGLE_ADS_LEAD: FALSOS.GOOGLE_ADS_LEAD })] });
  g.lead({ roteiro: 'Chile' });
  g.clica(WA);
  assert.deepEqual(g.injetados, [], 'so o Google Ads: a Meta nao carrega');
  assert.equal(g.ctx.fbq, undefined);
  assert.deepEqual(g.google(), [['config', AW], ['event', 'conversion', { send_to: SEND_LEAD }], ['event', 'generate_lead', { roteiro: 'Chile' }],
    ['event', 'clique_whatsapp', {}]], 'sem o rotulo do WhatsApp, o clique nao vira conversao (e o formulario segue)');

  const semConta = pagina('/contato', { scripts: [comIds({ GOOGLE_ADS_LEAD: FALSOS.GOOGLE_ADS_LEAD, GOOGLE_ADS_WHATSAPP: FALSOS.GOOGLE_ADS_WHATSAPP })] });
  semConta.lead({});
  semConta.clica(WA);
  assert.ok(!semConta.google().some(c => c[0] === 'config' || c[1] === 'conversion'), 'rotulo sem a conta (AW-) nao mede nada');
  assert.deepEqual(limpo(semConta.ctx.poRastreio.ligado),
    { producao: true, meta: false, googleAds: false, conversaoFormulario: false, conversaoWhatsapp: false },
    'e o console nao diz que esta medindo o que nao mede');
}

/* ---------- 4. formato errado: desligado (e o item 12 deixa a suite vermelha) ---------- */
{
  const tortos = [['META_PIXEL', 'abc123def4567'], ['META_PIXEL', '123'], ['META_PIXEL', '1234 5678 9012 345'],
    ['GOOGLE_ADS', '987654321'], ['GOOGLE_ADS', 'aw-987654321'], ['GOOGLE_ADS', SEND_LEAD]];
  for (const [k, v] of tortos) {
    // com o rotulo junto, uma conta aceita por engano faria a conversao sair
    const ids = k === 'GOOGLE_ADS' ? { [k]: v, GOOGLE_ADS_LEAD: FALSOS.GOOGLE_ADS_LEAD } : { [k]: v };
    const p = pagina('/roteiros/turquia', { scripts: [comIds(ids)] });
    p.lead({ roteiro: 'x' });
    assert.deepEqual(p.injetados, [], k + " = '" + v + "': nada carrega");
    assert.equal(p.ctx.fbq, undefined, k + " = '" + v + "': sem fbq");
    assert.ok(!p.google().some(c => c[0] === 'config' || c[1] === 'conversion'), k + " = '" + v + "': nada do Google Ads");
  }
  const rotulo = pagina('/contato', { scripts: [comIds({ GOOGLE_ADS: AW, GOOGLE_ADS_LEAD: 'tem espaco aqui' })] });
  rotulo.lead({});
  assert.ok(!rotulo.google().some(c => c[1] === 'conversion'), 'rotulo fora do formato nao vira conversao');
}

/* ---------- 5. fora do dominio de producao, nada ----------
   Teste do formulario na maquina de quem desenvolve viraria conversao, e o
   lance automatico do Google Ads aprende com conversao falsa. */
{
  for (const url of ['http://localhost:3000/roteiros/turquia', 'http://127.0.0.1:8080/contato',
    'https://fabianohirtzz.github.io/po-tu-project/contato.html', 'https://pereiraoliveiraturismo.com.br.golpe.example/contato',
    'https://golpe.example/?h=pereiraoliveiraturismo.com.br', 'https://xpereiraoliveiraturismo.com.br/']) {
    const p = pagina(url, { scripts: [comIds(FALSOS)] });
    p.lead({ roteiro: 'Turquia' });
    p.clica(WA);
    assert.deepEqual(p.injetados, [], url + ': nada carrega');
    assert.equal(p.ctx.fbq, undefined, url + ': sem fbq');
    assert.deepEqual(p.google(), [], url + ': nem o GA4 recebe o lead');
    assert.equal(p.ctx.poRastreio.ligado.producao, false, url + ': o console diz que nao e producao');
  }
  assert.deepEqual(pagina('https://www.pereiraoliveiraturismo.com.br/', { scripts: [comIds(FALSOS)] }).injetados, [FBEVENTS],
    'www tambem e producao (o .htaccess redireciona, mas o arquivo nao depende disso)');
}

/* ---------- 6. so o WhatsApp DA AGENCIA conta ---------- */
{
  const p = pagina('/contato', { scripts: [comIds(FALSOS)] });
  for (const href of ['https://wa.me/5511999999999', 'https://wa.me/554899604888', 'https://wa.me/55489960488820', SITE + '/contato',
    'tel:+5548996048882', 'https://instagram.com/pereiraoliveiraturismo', 'http://wa.me/5548996048882',
    'https://wa.me.golpe.example/5548996048882']) p.clica(href);
  assert.deepEqual(p.fila(), INICIO, 'link que nao e o WhatsApp da agencia nao vira Contact');
  assert.deepEqual(p.google(), [['config', AW]], 'nem conversao');
  const a = { href: WA };
  p.ouvintes.click.forEach(f => f({ target: { closest: () => a } }));
  assert.deepEqual(p.fila().slice(2), [['track', 'Contact', {}]], 'o toque no icone (svg dentro do <a>) conta pelo link');
  p.ouvintes.click.forEach(f => f({ target: { closest: () => null } }));
  p.ouvintes.click.forEach(f => f({ target: null }));
  assert.equal(p.fila().length, 3, 'clique fora de link nao conta');
}

/* ---------- 7. nenhum dado pessoal sai daqui ---------- */
{
  const p = pagina('/roteiros/turquia', { scripts: [comIds(FALSOS)] });
  p.lead({ roteiro: 'Turquia', origem: 'site-roteiro-turquia', nome: 'Ana Pessoal', telefone: '48999990000', email: 'ana@pessoal.example', cidade: 'Palhoça' });
  const tudo = JSON.stringify([p.fila(), p.google()]);
  for (const dado of ['Ana Pessoal', '48999990000', 'ana@pessoal.example', 'Palhoça']) {
    assert.ok(!tudo.includes(dado), 'dado pessoal no aviso nao segue para Meta nem Google: ' + dado);
  }
  p.lead({ roteiro: 'x'.repeat(900) });
  assert.equal(p.fila().at(-1)[2].content_name.length, 150, 'nome de roteiro tem teto');
}

/* ---------- 8. nada aqui quebra a pagina, o formulario ou o WhatsApp ---------- */
{
  const p = pagina('/contato', { scripts: [comIds(FALSOS)], extra: { fbq: () => { throw new Error('fbq'); } } });
  assert.doesNotThrow(() => { p.lead({ roteiro: 'x' }); p.clica(WA); }, 'Pixel quebrado nao derruba nada');
  assert.ok(p.google().some(c => c[1] === 'conversion'), 'e o Google Ads segue medindo');

  // o Pixel abriu bem e quebra so no evento: o Google Ads, logo depois, nao pode perder a conversao
  const fila = [];
  const trava = function () { if (arguments[0] === 'track' && arguments[1] !== 'PageView') throw new Error('fbq'); fila.push(arguments[1]); };
  const t = pagina('/contato', { scripts: [comIds(FALSOS)], extra: { fbq: trava } });
  assert.doesNotThrow(() => { t.lead({ roteiro: 'x' }); t.clica(WA); }, 'Pixel que quebra no evento nao derruba nada');
  assert.deepEqual(t.google().filter(c => c[1] === 'conversion'), [['event', 'conversion', { send_to: SEND_LEAD }], ['event', 'conversion', { send_to: SEND_WA }]],
    'Pixel que quebra no evento nao leva junto as conversoes do Google Ads');
  assert.deepEqual(fila, [FALSOS.META_PIXEL, 'PageView'], 'o Pixel tinha aberto (o caso e o evento quebrar, nao o init)');

  const q = pagina('/contato', { scripts: [comIds(FALSOS)], gtag: false, extra: { gtag: () => { throw new Error('gtag'); } } });
  assert.doesNotThrow(() => { q.lead({}); q.clica(WA); }, 'gtag quebrado nao derruba nada');
  assert.deepEqual(q.fila().slice(2), [['track', 'Lead', {}], ['track', 'Contact', {}]], 'e o Pixel segue medindo');

  // o Google Ads recusa a conversao: o GA4, logo depois, ainda recebe o evento
  const ga4 = [];
  const g = pagina('/contato', { scripts: [comIds(FALSOS)], gtag: false,
    extra: { gtag: function () { if (arguments[1] === 'conversion') throw new Error('ads'); ga4.push(arguments[1]); } } });
  assert.doesNotThrow(() => { g.lead({ roteiro: 'x' }); g.clica(WA); });
  assert.deepEqual(ga4, [AW, 'generate_lead', 'clique_whatsapp'], 'conversao quebrada nao leva junto o GA4');

  const r = pagina('/contato', { scripts: [comIds(FALSOS)], gtag: false });
  assert.doesNotThrow(() => { r.lead({}); r.clica(WA); }, 'pagina sem a tag do Google');
  assert.deepEqual(r.fila().slice(2), [['track', 'Lead', {}], ['track', 'Contact', {}]]);

  const s = pagina('/contato', { scripts: [comIds(FALSOS)] });
  assert.doesNotThrow(() => s.ctx.document.dispatchEvent(new CustomEvent('po:lead')), 'aviso sem detalhe');
  assert.deepEqual(s.fila().at(-1), ['track', 'Lead', {}]);
  assert.doesNotThrow(() => s.ouvintes.click.forEach(f => f({ target: { closest: () => { throw new Error('x'); } } })),
    'erro no clique nunca impede o WhatsApp de abrir');

  // a pagina que carrega o arquivo duas vezes mede uma vez so
  const src = comIds(FALSOS);
  const d = pagina('/roteiros/turquia', { scripts: [src, src] });
  assert.deepEqual(d.injetados, [FBEVENTS], 'carregado duas vezes: um fbevents.js');
  assert.equal(d.fila().filter(c => c[1] === 'PageView').length, 1, 'um PageView');
  assert.equal(d.google().filter(c => c[0] === 'config').length, 1, 'uma conta do Google Ads');
  d.lead({ roteiro: 'x' });
  assert.equal(d.fila().filter(c => c[1] === 'Lead').length, 1, 'um Lead por lead');
  assert.equal(d.google().filter(c => c[1] === 'conversion').length, 1, 'uma conversao por lead');
}

/* ---------- 9. o formulario so avisa lead que GRAVOU ----------
   O lead-form.js roda de verdade, com o rastreio ligado na mesma pagina. */
class Dados extends FormData {   // o FormData do Node nao aceita <form>: este le os campos do formulario falso
  constructor(f) { super(); if (f && f.campos) for (const [k, v] of Object.entries(f.campos)) this.set(k, v); }
}
const RESPOSTA = {
  ok: async () => ({ ok: true, json: async () => ({ ok: true }) }),
  recusa: async () => ({ ok: false, json: async () => ({ ok: false }) }),
  okFalso: async () => ({ ok: true, json: async () => ({ ok: false }) }),
  semPhp: async () => { throw new TypeError('Failed to fetch'); },
};
const supabase = (erro) => ({ createClient: () => ({ from: () => ({ insert: async () => ({ error: erro }) }) }) });

async function envia({ campos = {}, resposta = 'ok', demora = 5000, extra = {} } = {}) {
  const relogio = { t: Date.parse('2026-11-13T12:00:00Z') };
  const Relogio = class extends Date {};
  Relogio.now = () => relogio.t;
  let aoEnviar = null;
  const valores = { nome: 'Ana Teste', telefone: '(48) 99999-0000', email: 'ana@exemplo.com', cidade: 'Florianópolis', viajantes: '2',
    roteiro: 'Turquia com Antália', mensagem: 'Quero o valor', origem: 'site-roteiro-turquia', website: '', ...campos };
  const msg = { attrs: {}, textContent: '', setAttribute(k, v) { this.attrs[k] = v; } };
  const form = {
    dataset: {}, campos: valores, reset() {}, querySelector: (sel) => (sel === '.form__submit' ? { disabled: false, style: {} } : null),
    addEventListener: (tipo, fn) => { if (tipo === 'submit') aoEnviar = fn; },
  };
  const chamadas = [];
  const p = pagina('/roteiros/turquia', {
    scripts: [comIds(FALSOS), FORM],
    elementos: { 'lead-form': form, 'form-msg': msg, 'f-roteiro': { tagName: 'INPUT', value: valores.roteiro } },
    fetch: async (url) => { chamadas.push(url); return RESPOSTA[resposta](); },
    extra: { Date: Relogio, FormData: Dados, ...extra },
  });
  assert.ok(aoEnviar, 'o lead-form.js ligou o envio do formulario');
  relogio.t += demora;
  await aoEnviar({ preventDefault() {} });
  const leads = p.disparados.filter(e => e.type === 'po:lead');
  return { p, msg: msg.attrs['data-show'], chamadas, leads,
    conversoes: p.google().filter(c => c[1] === 'conversion').length, metaLeads: p.fila().filter(c => c[1] === 'Lead').length };
}
{
  const ok = await envia();
  assert.equal(ok.msg, 'ok');
  assert.deepEqual(ok.chamadas, ['/enviar.php']);
  assert.equal(ok.leads.length, 1, 'lead gravado: um aviso');
  assert.deepEqual(ok.leads[0].detail, { roteiro: 'Turquia com Antália', origem: 'site-roteiro-turquia' },
    'o aviso leva so o roteiro e a origem do formulario, nenhum dado pessoal');
  assert.equal(ok.metaLeads, 1, 'um Lead na Meta');
  assert.equal(ok.conversoes, 1, 'uma conversao no Google Ads');
  assert.deepEqual(ok.p.google().filter(c => c[1] === 'generate_lead'), [['event', 'generate_lead', { roteiro: 'Turquia com Antália' }]]);
  const tudo = JSON.stringify([ok.p.fila(), ok.p.google()]);
  for (const dado of ['Ana Teste', '99999-0000', 'ana@exemplo.com', 'Quero o valor']) assert.ok(!tudo.includes(dado), 'nao sai: ' + dado);

  const reserva = await envia({ resposta: 'semPhp', extra: { supabase: supabase(null), PO_CONFIG: { SUPABASE_URL: 'u', SUPABASE_ANON_KEY: 'k' } } });
  assert.equal(reserva.leads.length, 1, 'sem PHP, gravado pela reserva do Supabase: tambem e lead');
  assert.equal(reserva.conversoes, 1);

  const naoGravou = [
    ['honeypot preenchido (robo)', { campos: { website: 'http://spam.example' } }, 'ok'],
    ['enviado depressa demais (robo)', { demora: 1000 }, 'err'],
    ['sem e-mail', { campos: { email: '' } }, 'err'],
    ['e-mail invalido', { campos: { email: 'ana@' } }, 'err'],
    ['enviar.php recusou, sem reserva', { resposta: 'recusa' }, 'err'],
    ['enviar.php respondeu ok:false', { resposta: 'okFalso' }, 'err'],
    ['sem PHP e a reserva falhou', { resposta: 'semPhp', extra: { supabase: supabase({ code: '42501' }), PO_CONFIG: { SUPABASE_URL: 'u', SUPABASE_ANON_KEY: 'k' } } }, 'err'],
  ];
  for (const [caso, op, msgEsperada] of naoGravou) {
    const r = await envia(op);
    assert.equal(r.msg, msgEsperada, caso + ': a tela segue como antes');
    assert.equal(r.leads.length, 0, caso + ': nenhum aviso');
    assert.equal(r.metaLeads, 0, caso + ': nenhum Lead na Meta');
    assert.equal(r.conversoes, 0, caso + ': nenhuma conversao no Google Ads');
  }
}

/* ---------- 10. o origem.js e o rastreio.js na mesma pagina ----------
   Os dois ouvem o mesmo toque no WhatsApp: um poe o codigo no texto, o outro
   conta o Contact. Nenhum atrapalha o outro, e e um toque, uma conversao. */
{
  const memoria = new Map();
  const beacons = [];
  const p = pagina('/roteiros/turquia?gclid=CjAbc&utm_source=google&utm_medium=cpc&utm_campaign=turquia', {
    scripts: [ORIGEM, comIds(FALSOS)],
    extra: { localStorage: { getItem: k => (memoria.has(k) ? memoria.get(k) : null), setItem: (k, v) => memoria.set(k, String(v)) },
             navigator: { sendBeacon: (u) => { beacons.push(u); return true; } } },
  });
  const a = { href: WA, closest: () => a };
  p.ouvintes.click.forEach(f => f({ target: a }));
  assert.ok(/\(ref G-[BCDFGHJKLMNPQRSTVWXZ]{5}\)$/.test(new URL(a.href).searchParams.get('text')), 'o origem.js segue pondo o codigo');
  assert.deepEqual(beacons, ['/clique.php'], 'e registrando o clique');
  assert.equal(p.fila().filter(c => c[1] === 'Contact').length, 1, 'e o rastreio conta o mesmo toque uma vez');
  assert.equal(p.google().filter(c => c[1] === 'conversion').length, 1);

  const reDe = (src, nome) => { const m = /var WHATSAPP = (\/.+\/);/.exec(src); assert.ok(m, nome + ' declara var WHATSAPP'); return m[1]; };
  assert.equal(reDe(RASTREIO, 'rastreio.js'), reDe(ORIGEM, 'origem.js'),
    'o rastreio conta como WhatsApp da agencia exatamente o link que o origem.js marca com o codigo');
}

/* ---------- 11. toda pagina publica carrega o rastreio, uma vez ---------- */
const raiz = readdirSync(new URL('../', import.meta.url));
const publicas = raiz.filter(f => (/\.html$/.test(f) && le(f).includes('GT-TNH4L3BV')) || (/\.php$/.test(f) && le(f).includes('po_head(')));
assert.ok(publicas.length >= 8, 'achou as paginas publicas: ' + publicas.join(', '));
for (const pag of publicas) {
  const n = (le(pag).match(/["']\/?assets\/js\/rastreio\.js\?v=\d+["']/g) || []).length;
  assert.equal(n, 1, pag + ' carrega o rastreio.js uma vez (achou ' + n + ')');
}
assert.ok(/if \(!\$previa\) echo '<script src="\/assets\/js\/rastreio\.js\?v=\d+"><\/script>'/.test(le('roteiro.php')),
  'a previa do painel nao mede: a cliente conferindo o rascunho (e o formulario dele) viraria visita e conversao de anuncio');

/* ---------- 12. o arquivo que vai para o ar ----------
   ID fora do formato fica desligado no site SEM avisar ninguem: aqui ele fica
   vermelho. E o Pixel e o Google Ads poem cookie de anuncio e mandam a visita
   para a Meta e o Google: so ligam com a politica de privacidade dizendo
   isso (em 13/11/2026 ela so cita o Google Analytics). */
const DICA = {
  META_PIXEL: 'so os numeros do ID do conjunto de dados',
  GOOGLE_ADS: "so a parte ANTES da barra do send_to, com o AW-: 'AW-123456789'",
  GOOGLE_ADS_LEAD: 'so a parte DEPOIS da barra do send_to da conversao do formulario',
  GOOGLE_ADS_WHATSAPP: 'so a parte DEPOIS da barra do send_to da conversao do WhatsApp',
};
function problemas(ids, formato, politica) {
  const p = [];
  for (const k of Object.keys(DICA)) {
    if (ids[k] !== '' && !formato[k].test(ids[k])) p.push(k + " = '" + ids[k] + "' fora do formato: " + DICA[k]);
  }
  if ((ids.GOOGLE_ADS_LEAD || ids.GOOGLE_ADS_WHATSAPP) && !ids.GOOGLE_ADS) p.push('rotulo sem GOOGLE_ADS: o send_to precisa das duas partes');
  if (ids.META_PIXEL && !/\bpixel\b/i.test(politica)) p.push('META_PIXEL preenchido e a politica de privacidade nao declara o Pixel da Meta');
  if (ids.GOOGLE_ADS && !/Google Ads/.test(politica)) p.push('GOOGLE_ADS preenchido e a politica de privacidade nao declara o Google Ads');
  return p;
}
{
  const R = pagina('/').ctx.poRastreio;
  const formato = R.FORMATO;
  assert.deepEqual(Object.keys(limpo(R.IDS)).sort(), Object.keys(DICA).sort(), 'os IDS do arquivo sao os que este teste confere');
  // o detector, calibrado contra casos conhecidos antes de julgar o arquivo
  const VAZIOS = { META_PIXEL: '', GOOGLE_ADS: '', GOOGLE_ADS_LEAD: '', GOOGLE_ADS_WHATSAPP: '' };
  const SEM = '<h2>10. Cookies</h2><p>Usamos os cookies do Google Analytics, que contam visitas.</p>';
  const COM = '<h2>10. Cookies</h2><p>Usamos o Google Analytics, o Pixel da Meta e o Google Ads.</p>';
  assert.deepEqual(problemas(VAZIOS, formato, SEM), [], 'tudo vazio nunca e problema');
  assert.deepEqual(problemas(FALSOS, formato, COM), [], 'IDs no formato com a politica declarando: ok');
  assert.equal(problemas(FALSOS, formato, SEM).length, 2, 'Pixel e Google Ads sem a politica: os dois recusados');
  assert.equal(problemas({ ...VAZIOS, GOOGLE_ADS: '987654321' }, formato, COM).length, 1, 'AW sem o prefixo');
  assert.equal(problemas({ ...VAZIOS, GOOGLE_ADS: SEND_LEAD }, formato, COM).length, 1, 'o send_to inteiro no lugar da conta');
  assert.equal(problemas({ ...VAZIOS, GOOGLE_ADS_LEAD: FALSOS.GOOGLE_ADS_LEAD }, formato, COM).length, 1, 'rotulo sem a conta');
  assert.equal(problemas({ ...VAZIOS, META_PIXEL: '1234 5678 9012 345' }, formato, COM).length, 1, 'Pixel com espaco');
  assert.equal(problemas({ ...VAZIOS, META_PIXEL: ' ' + FALSOS.META_PIXEL }, formato, COM).length, 1, 'espaco em volta tambem (o site tolera, o arquivo nao)');

  const real = problemas(limpo(R.IDS), formato, le('politica-de-privacidade.html'));
  assert.deepEqual(real, [], 'assets/js/rastreio.js:\n      ' + real.join('\n      '));
}

console.log('test-rastreio OK (' + publicas.length + ' paginas)');
