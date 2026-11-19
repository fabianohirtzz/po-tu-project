/* Origem dos leads no painel: classificacao, gaveta, investimento por
 * plataforma e exportacao para o Google Ads (fase 1 do rastreio, 13/11/2026).
 *
 * O defeito que abriu esta frente: o lead do anuncio que abre o WhatsApp
 * chega sem UTM e com o ad_id da Meta, e o classificador so olhava gclid e
 * UTM. Ele caia em "Direto" e ficava fora do CPL e do ROAS. O mesmo valia
 * para quem chega da busca do Google (sem UTM, com referrer) e para o lead
 * do robo sem anuncio (origem 'whatsapp' gravada e ignorada).
 *
 * Tudo aqui roda o codigo DE VERDADE, extraido do app.js. */
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';

const le = rel => readFileSync(new URL('../' + rel, import.meta.url), 'utf8').split('\r').join('');
const src  = le('painel/app.js');
const html = le('painel/index.html');
const css  = le('painel/painel.css');

/* Extrai casando chaves. O regex "ate a proxima \n}" dos outros testes, numa
   funcao de UMA linha, engole tudo o que vem depois ate a proxima funcao de
   varias linhas: passa por acaso, e quebra no dia em que a vizinha mudar. */
function casa(i, abre, fecha) {
  let d = 0;
  for (let k = i; k < src.length; k++) {
    if (src[k] === abre) d++;
    else if (src[k] === fecha && --d === 0) return k + 1;
  }
  throw new Error('bloco sem fechamento a partir de ' + i);
}
function fn(nome) {
  const ini = 'function ' + nome + '(';
  const i = src.indexOf(ini);
  assert.ok(i >= 0, nome + ' existe no app.js');
  assert.equal(src.indexOf(ini, i + 1), -1, nome + ' definida uma vez so');
  const fimParams = casa(i + ini.length - 1, '(', ')');
  return src.slice(i, casa(src.indexOf('{', fimParams), '{', '}')) + '\n';
}
function konst(nome) {
  const ini = 'const ' + nome + '=';
  const i = src.indexOf(ini);
  assert.ok(i >= 0, nome + ' existe no app.js');
  const abre = src[i + ini.length];
  if (abre !== '{' && abre !== '[') return src.slice(i, src.indexOf(';', i) + 1) + '\n';
  return src.slice(i, casa(i + ini.length, abre, abre === '{' ? '}' : ']')) + ';\n';
}
const carrega = (nomes, extra = '') => new Function(
  extra + nomes.map(n => /^[A-Z_]+$/.test(n) ? konst(n) : fn(n)).join('') +
  'return {' + nomes.join(',') + '};')();

const P = carrega(['ORIG', 'PO_PLATAFORMAS', 'isPago', 'poRefHost', 'classifyChannel', 'poIdClique',
  'mapRow', 'poPatchGaveta', 'poGastoDaLinha', 'poGastoParaLinha', 'poGastoTotal', 'poGastoDoMes',
  'poMidiaPorPlataforma', 'PO_GADS_PRAZO_DIAS', 'poHoraSP', 'poCsvGoogleAds', 'esc', 'poOrigensOrdenadas']);

/* =================== 1. classificacao =================== */
const cl = r => P.classifyChannel(r);
const CASOS = [
  // id de clique: quem carimbou foi a plataforma
  [{ gclid: 'Cj0KCQ' }, 'google', 'gclid (auto-tagging do Google Ads)'],
  [{ gbraid: 'x' }, 'google', 'gbraid (iOS)'],
  [{ wbraid: 'x' }, 'google', 'wbraid (iOS)'],
  [{ origem: 'pago', ad_id: '120210', ctwa_clid: 'ARA' }, 'meta', 'anuncio que abre o WhatsApp, como o robo grava'],
  [{ ctwa_clid: 'ARA' }, 'meta', 'ctwa_clid sozinho'],
  [{ gclid: 'Cj0', utm_source: 'facebook', utm_medium: 'paid' }, 'google', 'id de clique vence UTM'],
  // UTM de anuncio: a fonte diz a plataforma
  [{ utm_source: 'google', utm_medium: 'cpc' }, 'google', 'google / cpc'],
  [{ utm_source: 'youtube', utm_medium: 'paid' }, 'google', 'youtube pago e Google Ads'],
  [{ utm_source: 'fb', utm_medium: 'paid' }, 'meta', '{{site_source_name}} = fb'],
  [{ utm_source: 'ig', utm_medium: 'paid' }, 'meta', '{{site_source_name}} = ig'],
  [{ utm_source: 'msg', utm_medium: 'paid' }, 'meta', '{{site_source_name}} = msg'],
  [{ utm_source: 'an', utm_medium: 'paid' }, 'meta', '{{site_source_name}} = an'],
  [{ utm_source: 'Facebook', utm_medium: 'Paid_Social' }, 'meta', 'caixa e sufixo nao importam'],
  [{ utm_source: 'instagram', utm_medium: 'cpc' }, 'meta', 'instagram pago e Meta Ads, nao Instagram organico'],
  [{ utm_source: 'bing', utm_medium: 'cpc' }, 'pago', 'plataforma desconhecida fica em pago'],
  [{ utm_medium: 'cpc' }, 'pago', 'cpc sem fonte'],
  [{ utm_source: 'tiktok', utm_medium: 'cpc' }, 'pago', 'outra plataforma paga'],
  [{ utm_source: 'yandex', utm_medium: 'cpc' }, 'pago', '"an" so casa inteiro, nunca dentro de outra fonte'],
  [{ utm_source: 'digitalbox', utm_medium: 'cpc' }, 'pago', '"ig" so casa inteiro, nunca dentro de outra fonte'],
  // UTM sem ser anuncio
  [{ utm_source: 'instagram', utm_medium: 'bio', utm_campaign: 'link' }, 'instagram', 'bio do Instagram'],
  [{ utm_source: 'instagram', utm_medium: 'social' }, 'instagram', 'post organico do Instagram'],
  [{ utm_source: 'facebook', utm_medium: 'social' }, 'social', 'post organico do Facebook'],
  [{ utm_source: 'newsletter', utm_medium: 'email' }, 'organico', 'outra campanha de link'],
  /* ChatGPT e outras IAs (15/11/2026). O ChatGPT poe utm_source=chatgpt.com
     sozinho nos links que mostra; as outras chegam pelo referrer. */
  [{ utm_source: 'chatgpt.com' }, 'ia', 'o ChatGPT poe utm_source sozinho'],
  [{ utm_source: 'ChatGPT.com' }, 'ia', 'caixa nao importa'],
  [{ utm_source: 'perplexity' }, 'ia', 'Perplexity com UTM'],
  [{ utm_source: 'copilot.com' }, 'ia', 'Copilot com UTM'],
  [{ utm_source: 'meta' }, 'organico', '"meta" sozinho e a Meta, nunca a Meta AI'],
  [{ utm_source: 'meta.ai' }, 'ia', 'a Meta AI pela UTM'],
  [{ utm_source: 'claudete' }, 'organico', 'IA so como palavra inteira: Claudete nao e o Claude'],
  [{ utm_source: 'parceiro', utm_medium: 'email', utm_campaign: 'roteiros-com-chatgpt' }, 'organico', 'IA so pela fonte: campanha com o nome dela nao e a IA'],
  [{ utm_source: 'chatgpt.com', utm_medium: 'cpc' }, 'pago', 'anuncio dentro de IA e midia paga'],
  [{ gclid: 'Cj0', utm_source: 'chatgpt.com' }, 'google', 'id de clique vence a IA'],
  /* Google Meu Negocio (15/11/2026). Sem UTM o perfil chega como busca do
     Google: o que o separa da busca e a UTM posta nos links dele. */
  [{ utm_source: 'google', utm_medium: 'organic', utm_campaign: 'google-meu-negocio', utm_content: 'site' }, 'meunegocio', 'o padrao do projeto (botao Site do perfil)'],
  [{ utm_source: 'google', utm_medium: 'organic', utm_campaign: 'Google Meu Negócio' }, 'meunegocio', 'caixa, espaco e acento nao importam'],
  [{ utm_source: 'google', utm_medium: 'organic', utm_campaign: 'google_meu_negocio' }, 'meunegocio', 'com sublinhado'],
  [{ utm_source: 'google', utm_medium: 'organic', utm_campaign: 'googlemeunegocio' }, 'meunegocio', 'tudo junto'],
  [{ utm_source: 'gmb', utm_medium: 'organic' }, 'meunegocio', 'gmb, o nome antigo que os guias usam'],
  [{ utm_source: 'google', utm_medium: 'organic', utm_campaign: 'gbp-listing' }, 'meunegocio', 'gbp (Google Business Profile)'],
  [{ utm_source: 'google', utm_medium: 'local', utm_campaign: 'google-business-profile' }, 'meunegocio', 'o nome em ingles'],
  [{ utm_source: 'google', utm_medium: 'organic', utm_campaign: 'googlemybusiness' }, 'meunegocio', 'Google My Business'],
  [{ utm_source: 'google', utm_medium: 'organic', utm_campaign: 'perfil-da-empresa' }, 'meunegocio', 'o nome oficial em portugues'],
  [{ utm_source: 'google', utm_medium: 'organic' }, 'organico', 'google / organic sem a campanha do perfil e busca'],
  [{ utm_source: 'parceiro', utm_medium: 'email', utm_campaign: 'classe-economy-business' }, 'organico', '"economy business" nao e "my business"'],
  [{ utm_source: 'parceiro', utm_medium: 'email', utm_campaign: 'viagem-de-negocios' }, 'organico', 'negocios sem o "meu" nao e o perfil'],
  [{ utm_source: 'agencia-gmbh', utm_medium: 'referral' }, 'organico', '"gmb" so casa inteiro, nunca dentro de gmbh'],
  [{ utm_source: 'google', utm_medium: 'cpc', utm_campaign: 'google-meu-negocio' }, 'google', 'midia paga decide antes do nome da campanha'],
  [{ gclid: 'Cj0', utm_campaign: 'google-meu-negocio' }, 'google', 'id de clique vence o perfil'],
  // sem UTM: quem mandou a visita
  [{ referrer: 'https://www.google.com/' }, 'organico', 'busca do Google'],
  [{ referrer: 'https://www.google.com.br/' }, 'organico', 'busca do Google .br'],
  [{ referrer: 'https://www.bing.com/' }, 'organico', 'busca do Bing'],
  [{ referrer: 'android-app://com.google.android.googlequicksearchbox/' }, 'organico', 'app do Google no Android'],
  [{ referrer: 'android-app://com.google.android.apps.maps/' }, 'meunegocio', 'app do Google Maps: link de la so sai da ficha da empresa'],
  [{ referrer: 'https://maps.google.com/' }, 'meunegocio', 'maps.google.com'],
  [{ referrer: 'https://www.google.com/maps/place/x' }, 'organico', 'o Maps na web chega como google.com: igual a busca (o limite sem UTM)'],
  [{ utm_source: 'instagram', utm_medium: 'bio', referrer: 'android-app://com.google.android.apps.maps/' }, 'instagram', 'a UTM decide antes do referrer do Maps'],
  [{ referrer: 'https://l.instagram.com/' }, 'instagram', 'link do Instagram'],
  [{ referrer: 'https://m.facebook.com/' }, 'social', 'link do Facebook'],
  [{ referrer: 'https://chatgpt.com/' }, 'ia', 'ChatGPT pelo referrer'],
  [{ referrer: 'https://chat.openai.com/' }, 'ia', 'o endereco antigo do ChatGPT'],
  [{ referrer: 'https://www.perplexity.ai/' }, 'ia', 'Perplexity'],
  [{ referrer: 'https://gemini.google.com/' }, 'ia', 'Gemini'],
  [{ referrer: 'https://copilot.microsoft.com/' }, 'ia', 'Copilot'],
  [{ referrer: 'https://claude.ai/' }, 'ia', 'Claude'],
  [{ referrer: 'https://chat.deepseek.com/' }, 'ia', 'DeepSeek'],
  [{ referrer: 'https://www.meta.ai/' }, 'ia', 'Meta AI'],
  [{ referrer: 'https://grok.com/' }, 'ia', 'Grok'],
  [{ referrer: 'https://chat.mistral.ai/' }, 'ia', 'Mistral'],
  [{ referrer: 'https://www.google.com/' }, 'organico', 'o modo IA do Google chega como google.com: igual a busca'],
  [{ referrer: 'https://notchatgpt.com/' }, 'organico', 'dominio parecido nao e a IA'],
  [{ utm_source: 'instagram', utm_medium: 'bio', referrer: 'https://chatgpt.com/' }, 'instagram', 'a UTM decide antes do referrer da IA'],
  [{ referrer: 'https://www.tripadvisor.com.br/' }, 'organico', 'outro site'],
  [{ referrer: 'https://pereiraoliveiraturismo.com.br/roteiros/turquia' }, 'direto', 'navegacao interna nao e origem'],
  [{ referrer: 'https://www.pereiraoliveiraturismo.com.br/' }, 'direto', 'navegacao interna com www'],
  [{ referrer: 'https://fabianohirtzz.github.io/po-tu-project/' }, 'direto', 'preview do GitHub Pages'],
  [{ referrer: 'nao e url' }, 'direto', 'referrer quebrado nao derruba nada'],
  [{ referrer: '' }, 'direto', 'referrer vazio'],
  // fbclid (fase 2): a Meta poe em link pago E nao pago; sem UTM nao e anuncio
  [{ fbclid: 'IwAR1' }, 'social', 'fbclid sozinho: veio do Facebook, sem prova de anuncio'],
  [{ fbclid: 'IwAR1', referrer: 'https://l.instagram.com/' }, 'instagram', 'fbclid vindo do Instagram'],
  [{ fbclid: 'IwAR1', utm_source: 'fb', utm_medium: 'paid' }, 'meta', 'com a UTM do anuncio, e Meta Ads'],
  [{ fbclid: 'IwAR1', utm_source: 'instagram', utm_medium: 'bio' }, 'instagram', 'a UTM decide antes do fbclid'],
  // sem nada do site
  [{ origem: 'whatsapp' }, 'whatsapp', 'robo, sem anuncio'],
  [{ origem: 'site-contato' }, 'direto', 'formulario sem UTM nem referrer externo'],
  [{ origem: 'importado' }, 'direto', 'contato importado'],
  [{ origem: 'Cadastro manual' }, 'direto', 'cadastro manual (a origem vem do origem_manual)'],
  [{}, 'direto', 'nada'],
];
for (const [r, esperado, porque] of CASOS) assert.equal(cl(r), esperado, porque + ' -> ' + JSON.stringify(r));

/* As duas pontas que alimentam o classificador, lidas do fonte DELAS: se uma
   mudar, isto fica vermelho em vez de a origem errar em silencio. */
const utmBio = /var UTM = '([^']+)'/.exec(le('assets/js/link.js'));
assert.ok(utmBio, 'o /link declara o UTM da bio');
const bio = Object.fromEntries(new URLSearchParams(utmBio[1]));
assert.equal(cl(bio), 'instagram', 'o UTM que o /link realmente escreve vira Instagram');
assert.ok(/'origem'\s*=>\s*\$ev\['ad_id'\]\s*\?\s*'pago'\s*:\s*'whatsapp'/.test(le('lib/wa-motor.php')),
  'o robo segue gravando origem whatsapp (e o literal que o classificador le)');

/* =================== 2. o lead e a gaveta =================== */
const lead = P.mapRow({ id: 'a', origem: 'pago', ad_id: '120210', ctwa_clid: 'ARAkLm3', utm_source: null });
assert.equal(lead.origemAuto, 'meta');
assert.equal(lead.origem, 'meta', 'sem escolha manual, vale a automatica');
assert.equal(lead.adId, '120210');
assert.equal(lead.ctwaClid, 'ARAkLm3');
assert.equal(P.poIdClique(lead), 'ctwa_clid ARAkLm3', 'a gaveta mostra o id do clique');
assert.equal(P.poIdClique(P.mapRow({ gclid: 'Cj0KCQiAabcdefghijklmnop' })), 'gclid Cj0KCQiAabcdef…', 'id longo e encurtado');
assert.equal(P.poIdClique(P.mapRow({ ad_id: '99' })), 'anúncio 99');
assert.equal(P.poIdClique(P.mapRow({ gbraid: '0AAAA' })), 'gbraid 0AAAA', 'Google Ads no iPhone');
assert.equal(P.poIdClique(P.mapRow({ wbraid: 'CkMB' })), 'wbraid CkMB');
assert.equal(P.poIdClique(P.mapRow({ fbclid: 'IwAR1' })), 'fbclid IwAR1');
assert.equal(P.mapRow({ utm_term: 'publico-60', utm_content: '1202100' }).anuncio, 'publico-60 / 1202100',
  'conjunto e anuncio da Meta ({{adset.name}} / {{ad.id}})');
assert.equal(P.mapRow({}).anuncio, '—');
assert.equal(P.poIdClique(P.mapRow({})), '—');
assert.equal(P.mapRow({ utm_source: 'google', utm_medium: 'cpc' }).fonte, 'google / cpc');
assert.equal(P.mapRow({}).fonte, '—');

const manual = P.mapRow({ gclid: 'Cj0', origem_manual: 'organico' });
assert.equal(manual.origem, 'organico', 'a escolha manual vence');
assert.equal(manual.origemAuto, 'google', 'e a automatica continua visivel no "Canal"');

/* O defeito que a regra da gaveta corrige: o select abre na origem efetiva, e
   gravar o que ele mostra a cada Salvar congelava a classificacao do dia. */
const AGORA = '2026-11-13T12:00:00Z';
const base = { ven: '0', orc: '0', status: 'atendimento' };
const g = (origem, lead) => P.poPatchGaveta({ ...base, origem }, { ven: 0, vendaAt: null, ...lead }, AGORA).origem_manual;
assert.equal(g('google', { origemAuto: 'google' }), null, 'salvar sem mexer na origem nao a congela');
assert.equal(g('organico', { origemAuto: 'google' }), 'organico', 'escolha diferente da automatica e gravada');
assert.equal(g('', { origemAuto: 'direto' }), null, 'select vazio nunca grava string vazia');
assert.equal(g('direto', { origemAuto: 'whatsapp' }), 'direto', 'escolher "direto" num lead do robo e escolha, e fica');
assert.ok(/origem:\$\('#e-orig'\)\.value/.test(src), 'o Salvar le o select (trava no ponto de chamada)');
assert.ok(/l\.origem=patch\.origem_manual\|\|l\.origemAuto;/.test(src),
  'depois de salvar, o lead na tela volta para a automatica quando a manual e null');

/* Elementos novos da gaveta com guarda: o FTP e arquivo a arquivo, e o app.js
   novo com o index.html antigo derrubaria a gaveta inteira com TypeError. */
for (const id of ['dr-fonte', 'dr-clique', 'dr-anuncio']) {
  assert.ok(new RegExp('id="' + id + '"').test(html), id + ' existe na gaveta');
  const usos = src.split("$('#" + id + "')").length - 1;
  const guardados = src.split("if($('#" + id + "'))$('#" + id + "')").length - 1;
  assert.ok(usos > 0 && usos === guardados * 2, id + ': todo acesso e guardado');
}

/* =================== 3. investimento por plataforma =================== */
// Sem a migration as colunas meta/google nao vem: o gasto antigo aparece em
// "Outros", e nada some do total.
assert.deepEqual(P.poGastoDaLinha({ month: '2026-09', amount: '1500' }), { meta: 0, google: 0, outros: 1500 });
assert.deepEqual(P.poGastoDaLinha({ month: '2026-11', amount: 0, meta: '700', google: 300 }), { meta: 700, google: 300, outros: 0 });
assert.deepEqual(P.poGastoParaLinha('2026-11', { meta: 700, google: '300', outros: undefined }),
  { month: '2026-11', meta: 700, google: 300, amount: 0 }, 'a linha gravada leva as tres plataformas');
const gastos = { '2026-09': { meta: 0, google: 0, outros: 1500 }, '2026-10': { meta: 300, google: 200, outros: 0 } };
assert.deepEqual(P.poGastoDoMes(gastos, 'all'), { meta: 300, google: 200, outros: 1500 }, 'todos os meses soma tudo');
assert.deepEqual(P.poGastoDoMes(gastos, '2026-10'), { meta: 300, google: 200, outros: 0 });
assert.deepEqual(P.poGastoDoMes(gastos, '2026-12'), { meta: 0, google: 0, outros: 0 }, 'mes sem gasto e zero');
const copia = P.poGastoDoMes(gastos, '2026-10'); copia.meta = 999;
assert.equal(gastos['2026-10'].meta, 300, 'editar o gasto da tela nao muda o guardado antes de salvar');
assert.equal(P.poGastoTotal({ meta: 300, google: 200, outros: 1500 }), 2000);

/* Cada coluna que o painel grava existe no banco: a do schema mais as das
   migrations. Coluna que nao existe e PGRST204 e o investimento para de salvar. */
const tabela = /create table if not exists public\.po_ad_spend \(([\s\S]*?)\n\);/.exec(le('supabase/schema.sql'));
assert.ok(tabela, 'po_ad_spend no schema.sql');
const colunas = new Set(tabela[1].split('\n').map(l => (/^\s*([a-z_]+)\s/.exec(l) || [])[1]).filter(Boolean));
for (const m of readdirSync(new URL('../supabase/migrations/', import.meta.url))) {
  for (const c of le('supabase/migrations/' + m).matchAll(/alter table public\.po_ad_spend add column if not exists (\w+)/g)) colunas.add(c[1]);
}
for (const k of Object.keys(P.poGastoParaLinha('2026-11', {}))) assert.ok(colunas.has(k), 'po_ad_spend.' + k + ' existe no banco');
const migSpend = le('supabase/migrations/2026-11-13-ad-spend-plataforma.sql');
assert.ok(!/\b(drop|delete|update|truncate)\b/i.test(migSpend.replace(/^--.*$/gm, '')), 'a migration do gasto e so aditiva');

// Uma caixa de investimento por plataforma, e cada uma grava a sua chave.
const planos = [...html.matchAll(/class="spend-in" data-plat="(\w+)"/g)].map(m => m[1]).sort();
assert.deepEqual(planos, P.PO_PLATAFORMAS.map(p => p.gasto).sort(), 'as caixas sao as plataformas');

/* =================== 4. a tabela por plataforma =================== */
const L = (origem, status, ven) => ({ origem, status, ven });
const rows = [L('meta', 'venda', 10000), L('meta', 'negociacao', 5000), L('meta', 'novo', 0),
  L('google', 'venda', 8000), L('google', 'perdido', 0), L('pago', 'novo', 0), L('organico', 'venda', 3000)];
const gasto = { meta: 2000, google: 1000, outros: 500 };
const t = Object.fromEntries(P.poMidiaPorPlataforma(rows, gasto).map(x => [x.k, x]));
assert.deepEqual(Object.keys(t), ['meta', 'google', 'pago', 'total'], 'Meta, Google, outros pagos e o total');
assert.equal(t.meta.leads, 3); assert.equal(t.meta.vendas, 1);
assert.equal(t.meta.fat, 10000, 'valor de quem nao esta em venda nao entra (a regra do KPI)');
assert.equal(t.meta.roas, 5); assert.equal(Math.round(t.meta.cpl), 667);
assert.equal(t.google.roas, 8); assert.equal(t.google.cpl, 500);
assert.equal(t.pago.inv, 500, '"Pago (outro)" casa com o investimento "Outros"');
assert.equal(t.pago.roas, 0);
assert.equal(t.total.leads, 6, 'organico fica fora da midia paga');
// A linha total e a MESMA conta dos cards de ROAS e CPL do renderReports.
const sp = P.poGastoTotal(gasto);
const venPago = rows.filter(l => l.status === 'venda' && P.isPago(l.origem)).reduce((s, l) => s + l.ven, 0);
assert.equal(t.total.roas, venPago / sp);
assert.equal(t.total.cpl, sp / rows.filter(l => P.isPago(l.origem)).length);
const semGasto = P.poMidiaPorPlataforma(rows, { meta: 0, google: 0, outros: 0 });
assert.ok(semGasto.every(x => x.roas === null && x.cpl === null), 'sem investimento, ROAS e CPL sao "—", nunca infinito');
assert.deepEqual(P.PO_PLATAFORMAS.map(p => p.k).filter(P.isPago), ['meta', 'google', 'pago'], 'isPago e a lista de plataformas');

// Com o index.html antigo (sem a tabela) os Relatorios nao podem cair.
const render = new Function('$', fn('renderPlataformas') + 'return renderPlataformas;')(() => null);
assert.doesNotThrow(() => render([]), 'renderPlataformas sem a tabela na tela nao derruba os Relatorios');

/* =================== 5. CSV para o Google Ads =================== */
assert.equal(P.PO_GADS_PRAZO_DIAS, 90);
assert.equal(P.poHoraSP('2026-11-10T17:30:00Z'), '2026-11-10 14:30:00', 'hora de Sao Paulo');
assert.equal(P.poHoraSP('2026-11-11T03:00:00Z'), '2026-11-11 00:00:00', 'meia-noite e 00, nunca 24');
assert.equal(P.poHoraSP('lixo'), '');
// O Node daqui sai "00" com hour12:false, mas versoes do Chrome ja sairam
// "24:00:00" com ele: a garantia tem que estar no fonte, nao no runtime do teste.
assert.ok(/hourCycle:'h23'/.test(fn('poHoraSP')) && !/hour12/.test(fn('poHoraSP')),
  'a hora usa hourCycle h23, nunca hour12');

const dias = n => new Date(Date.parse(AGORA) - n * 86400000).toISOString();
const V = (id, over) => ({ id, gclid: 'Cj0' + id, status: 'venda', ven: 15000, vendaAt: '2026-11-10T17:30:00Z', data: dias(10), ...over });
const csv = P.poCsvGoogleAds([
  V('a'),
  V('b', { gclid: '' }),                                 // sem gclid: nao e do Google
  V('c', { status: 'negociacao' }),                      // nao vendeu
  V('d', { data: dias(91) }),                            // clique velho: o Google recusa
  V('e', { data: dias(89) }),                            // ainda no prazo
  V('f', { vendaAt: null }),                             // venda sem data de fechamento
  V('g', { ven: 0 }),                                    // sem valor: usa o padrao da acao
  V('h', { ven: 12345.678 }),
], 'Venda, site', AGORA);
assert.equal(csv.n, 4); assert.equal(csv.fora, 1); assert.equal(csv.semData, 1);
assert.equal(csv.csv, [
  'Parameters:TimeZone=America/Sao_Paulo',
  'Google Click ID,Conversion Name,Conversion Time,Conversion Value,Conversion Currency,Order ID',
  'Cj0a,"Venda, site",2026-11-10 14:30:00,15000,BRL,a',
  'Cj0e,"Venda, site",2026-11-10 14:30:00,15000,BRL,e',
  'Cj0g,"Venda, site",2026-11-10 14:30:00,,,g',
  'Cj0h,"Venda, site",2026-11-10 14:30:00,12345.68,BRL,h',
].join('\n') + '\n', 'o arquivo sai exatamente no modelo do Google');
assert.ok(/poCsvGoogleAds\(LEADS,nome,/.test(src),
  'a exportacao leva TODOS os leads, nao o recorte do mes (o Order ID evita duplicar)');

/* =================== 6. a tela conhece todas as origens =================== */
const chaves = Object.keys(P.ORIG);
assert.deepEqual(chaves.slice(0, 3), ['meta', 'google', 'pago'], 'as pagas abrem a legenda');
const chips = [...html.matchAll(/data-orig="(\w+)"/g)].map(m => m[1]).filter(k => k !== 'todos');
assert.deepEqual(chips.sort(), [...chaves].sort(), 'um chip de filtro por origem');
for (const sel of ['e-orig', 'n-orig']) {
  const bloco = new RegExp('<select id="' + sel + '">([\\s\\S]*?)</select>').exec(html);
  assert.ok(bloco, sel + ' existe');
  const ops = [...bloco[1].matchAll(/value="(\w+)"/g)].map(m => m[1]).sort();
  assert.deepEqual(ops, [...chaves].sort(), sel + ' oferece todas as origens');
}
/* O corpo de uma regra que COMECA a linha. Procurar o seletor em qualquer
   lugar deixava o selo sumir sem ninguem ver: ".dr-head .orig.meta{" tambem
   contem ".orig.meta{" (achado na rodada de mutacao de 15/11/2026). */
const regra = sel => (new RegExp('(?:^|\\n)' + sel.replace(/\./g, '\\.') + '\\{([^}]*)\\}').exec(css) || [])[1] || '';
for (const [k, o] of Object.entries(P.ORIG)) {
  assert.ok(regra('.orig.' + o.cls), 'selo .orig.' + o.cls + ' tem cor (' + k + ')');
  assert.ok(regra('.orig.' + o.cls + '::before'), 'selo .orig.' + o.cls + ' tem bolinha (' + k + ')');
}
for (const c of ['c-meta', 'c-google', 'c-gmn', 'c-ia']) assert.ok(css.includes('.chip.active.' + c + '{'), c + ' tem cor de chip ativo');
assert.ok(/class="chip c-gmn" data-orig="meunegocio"/.test(html), 'o chip do Google Meu Negocio leva a cor dele');
assert.ok(/class="chip c-ia" data-orig="ia"/.test(html), 'o chip das IAs leva a cor dele');
// No cabecalho escuro da gaveta o tom escuro some: os selos novos tem o claro, como Meta e Google.
for (const cls of ['meunegocio', 'ia']) assert.ok(css.includes('.dr-head .orig.' + cls + '{'), cls + ' tem tom claro no cabecalho escuro da gaveta');
/* Achado ABRINDO a tela (15/11/2026, painel real sem login, Chrome headless):
   a 500px a barra de origens cortava Instagram, WhatsApp e Direto (overflow
   hidden e sem rolagem: impossiveis de tocar) e a 1024px os nomes quebravam
   em duas linhas. O 10o filtro piorava as duas coisas. A barra rola de lado,
   e o chip nao encolhe nem quebra. */
assert.ok(/overflow-x:auto/.test(regra('.chip-group')) && !/overflow:hidden/.test(regra('.chip-group')),
  'a barra de origens rola de lado em vez de cortar');
assert.ok(/flex:0 0 auto/.test(regra('.chip')) && /white-space:nowrap/.test(regra('.chip')),
  'o filtro nao encolhe nem quebra em duas linhas');
for (const id of ['plat-rows', 'gads-nome', 'gads-csv', 'gads-info']) assert.ok(html.includes('id="' + id + '"'), id + ' existe');
/* Achado so ABRINDO a tela a 500px: item de grid nao encolhe abaixo do
   conteudo, e a tabela de 600px alargava a coluna da .rep-grid inteira -
   todos os cards dos Relatorios iam a 710px numa tela de 500. */
const cardPlat = (/<div class="([^"]*)">\s*<div class="card-title">Mídia paga por plataforma/.exec(html) || [])[1] || '';
assert.ok(cardPlat.split(' ').includes('plat-card'), 'o card da tabela tem a classe que o deixa encolher');
assert.ok(/\.plat-card\{min-width:0\}/.test(css), '.plat-card encolhe (min-width:0), e a tabela rola dentro dele');
assert.ok(/\.plat-wrap\{overflow-x:auto\}/.test(css), 'a tabela rola de lado dentro do card');
assert.ok(/if\(\$\('#gads-csv'\)\)\$\('#gads-csv'\)\.onclick=/.test(src), 'o botao de exportar e ligado com guarda');

/* =================== 7. leads por origem: barras ordenadas =================== */
/* Era uma rosca com uma cor por origem. Com 10 origens ela deixa de ser lida, e
   "mais cores" e exatamente o que a skill de graficos proibe acima de ~7 classes
   (15/11/2026). Barras da maior para a menor, numa cor so, com o nome escrito:
   origem nova nao pede cor nova no relatorio. */
const O = (...origens) => origens.map(origem => ({ origem }));
assert.deepEqual(P.poOrigensOrdenadas(O('direto', 'ia', 'ia', 'organico', 'ia', 'organico', 'meta')),
  [['ia', 3], ['organico', 2], ['meta', 1], ['direto', 1]], 'da maior para a menor; empate na ordem dos filtros');
assert.deepEqual(P.poOrigensOrdenadas(O('Instagram', 'direto')), [['direto', 2]],
  'origem que o painel nao conhece conta como Direto, como no selo (e nao vira uma segunda barra "Direto")');
assert.deepEqual(P.poOrigensOrdenadas([]), [], 'periodo vazio');
assert.ok(Object.values(P.ORIG).every(o => !('cor' in o)), 'o ORIG nao guarda mais cor de grafico: a cor do selo mora no CSS');

const R = new Function('$', 'esc', 'ORIG', 'poOrigensOrdenadas', fn('renderOrigens') + 'return renderOrigens;');
const desenha = ($) => R($, P.esc, P.ORIG, P.poOrigensOrdenadas);
assert.doesNotThrow(() => desenha(() => null)(O('ia')), 'sem a lista na tela (index.html antigo, no FTP) os Relatorios seguem');
const tela = { innerHTML: '' };
desenha(() => tela)(O('organico', 'ia', 'ia', 'ia'));
assert.deepEqual([...tela.innerHTML.matchAll(/class="bar-lbl">([^<]*)</g)].map(m => m[1]), ['ChatGPT e IAs', 'Orgânico'],
  'barras da maior para a menor, com o nome escrito');
assert.deepEqual([...tela.innerHTML.matchAll(/width:([\d.]+)%/g)].map(m => Math.round(Number(m[1]))), [100, 33],
  'a maior enche a barra; as outras na proporcao dela');
assert.ok(/>3<span class="bar-pct">75%</.test(tela.innerHTML), 'quantidade e parte do total');
assert.ok(!/background/.test(tela.innerHTML), 'uma cor so: a barra nao recebe cor por origem');
desenha(() => tela)([]);
assert.ok(/Sem dados no período/.test(tela.innerHTML), 'periodo vazio avisa');
assert.ok(/renderOrigens\(rows\);/.test(fn('renderReports')), 'os Relatorios desenham as barras (trava no ponto de chamada)');
assert.ok(html.includes('id="orig-bars"'), 'o card tem a lista de barras');
assert.ok(!/donut/.test(html) && !/donut/.test(src) && !/donut|legend-/.test(css), 'a rosca saiu do html, do app.js e do css');
assert.ok(regra('.bar-row--orig .bar-fill'), 'a cor unica das barras de origem mora no CSS');
/* Achado ABRINDO a tela, com tudo acima verde: .bar-row--orig declarada ANTES
   de .bar-row perdia para ela (mesma especificidade, vence a ultima), e a
   quantidade e a parte quebravam em duas linhas na coluna de 40px da base. */
const pos = sel => { const m = new RegExp('(?:^|\\n)' + sel.replace(/\./g, '\\.') + '\\{').exec(css); return m ? m.index : -1; };
assert.ok(pos('.bar-row') >= 0 && pos('.bar-row--orig') > pos('.bar-row'), '.bar-row--orig vem DEPOIS de .bar-row na cascata');

console.log('test-origem-painel OK');
