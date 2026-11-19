/* Painel da Meta Ads (painel/meta.js, fase 3B, 13/11/2026): nome do anuncio
 * na ficha e o status da venda devolvida a Meta. Codigo de verdade, extraido
 * do arquivo; dados inventados. */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const le = rel => readFileSync(new URL('../' + rel, import.meta.url), 'utf8').split('\r').join('');
const SRC = le('painel/meta.js'), APP = le('painel/app.js'), HTML = le('painel/index.html');
function casa(src, i, abre, fecha) { let d = 0; for (let k = i; k < src.length; k++) { if (src[k] === abre) d++; else if (src[k] === fecha && --d === 0) return k + 1; } return -1; }
function fn(src, nome) { const i = src.indexOf('function ' + nome + '('); assert.ok(i >= 0, nome + ' existe'); return src.slice(i, casa(src, src.indexOf('{', i), '{', '}')) + '\n'; }
const M = new Function('let PO_ANUNCIOS={};' + ['poNomeAnuncio', 'poCampanhaDoLead', 'poAnunciosSemNome', 'poMetaStatusTexto'].map(n => fn(SRC, n)).join('') +
  'return {poNomeAnuncio,poCampanhaDoLead,poAnunciosSemNome,poMetaStatusTexto};')();

/* ---------- o nome do anuncio ---------- */
assert.equal(M.poNomeAnuncio({ campanha: 'Turquia 2027', conjunto: 'Publico 60+', anuncio: 'Video 1' }), 'Turquia 2027 · Publico 60+ · Video 1');
assert.equal(M.poNomeAnuncio({ campanha: 'Turquia 2027', conjunto: null, anuncio: 'Video 1' }), 'Turquia 2027 · Video 1', 'sem o que falta');
assert.equal(M.poNomeAnuncio({ campanha: 'Turquia 2027', nome_manual: 'Turquia - video da Lais' }), 'Turquia - video da Lais', 'o nome dado a mao vence');
assert.equal(M.poNomeAnuncio({ campanha: 'X', nome_manual: '   ' }), 'X', 'nome a mao em branco nao apaga o da Meta');
assert.equal(M.poNomeAnuncio(null), '');
const ANS = { '1202': { campanha: 'Turquia 2027', anuncio: 'Video 1' } };
assert.equal(M.poCampanhaDoLead({ adId: '1202', camp: 'Anúncio 1202' }, ANS), 'Turquia 2027 · Video 1', 'a ficha mostra o nome, nao o numero');
assert.equal(M.poCampanhaDoLead({ adId: '9999', camp: 'Anúncio 9999' }, ANS), 'Anúncio 9999', 'sem nome: o numero, como antes');
assert.equal(M.poCampanhaDoLead({ adId: '', camp: 'turquia-2027' }, ANS), 'turquia-2027', 'lead sem anuncio: a UTM de sempre');

/* ---------- quem ainda precisa de nome ---------- */
const AGORA = Date.parse('2026-11-13T15:00:00Z');
const ha = h => new Date(AGORA - h * 3600e3).toISOString();
assert.deepEqual(M.poAnunciosSemNome(['1', '2', '3', '4', '5'], {
  '2': { campanha: 'X' },                          // ja tem nome
  '3': { erro: 'HTTP 400', buscado_at: ha(2) },    // tentou ha pouco: nao insiste
  '4': { erro: 'HTTP 400', buscado_at: ha(30) },   // tentou ontem: tenta de novo
  '5': { nome_manual: 'Dado a mao' },              // nome a mao basta
}, AGORA), ['1', '4']);

/* ---------- o status nos Relatorios ---------- */
assert.equal(M.poMetaStatusTexto(null), '', 'endpoint ainda nao publicado: nada na tela');
assert.ok(/falta configurar META_DATASET_ID, WA_WABA_ID/.test(M.poMetaStatusTexto({ ok: true, configurado: false, falta: ['META_DATASET_ID', 'WA_WABA_ID'] })),
  'sem configuracao, diz o que falta');
assert.equal(M.poMetaStatusTexto({ ok: true, configurado: true, enviados: 2, ja_enviados: 1, falhas: 1 }),
  'Vendas para a Meta (últimos 7 dias): 2 vendas enviadas agora, 1 já enviada, 1 falhou (tenta de novo).');
assert.equal(M.poMetaStatusTexto({ ok: true, configurado: true, enviados: 0, ja_enviados: 0, falhas: 0 }),
  'Vendas para a Meta (últimos 7 dias): nenhuma venda de anúncio do WhatsApp.');
assert.ok(/Não consegui/.test(M.poMetaStatusTexto({ ok: false })));

/* ---------- os dois modulos rodam nas DUAS ordens de carga ----------
   O defeito achado abrindo o painel: com o banco respondendo rapido, o
   loadData terminava antes de cliques.js e meta.js existirem, e a chamada
   direta (com guarda de typeof) nao acontecia. Aqui o arquivo inteiro roda
   num contexto de mentira; cada execucao do modulo conta uma chamada ao sb. */
import vm from 'node:vm';
function carrega(arquivo, leadsJaProntos) {
  const ouvintes = [];
  let execucoes = 0;
  const cadeia = { select() { return cadeia; }, is() { return cadeia; }, gte() { return cadeia; }, limit() { return cadeia; },
    in() { return cadeia; }, eq() { return cadeia; }, ilike() { return cadeia; }, order() { return cadeia; },
    then(ok) { return Promise.resolve({ data: [], error: null }).then(ok); } };
  const ctx = {
    window: { PO_LEADS_PRONTOS: leadsJaProntos },
    document: { addEventListener: (t, f) => { if (t === 'po:leads') ouvintes.push(f); } },
    sb: { from() { execucoes++; return cadeia; }, auth: { getSession: async () => { execucoes++; return { data: { session: null } }; } } },
    LEADS: [], console, Promise, Date, Object, Map, Set, FormData, fetch: async () => ({ json: async () => null }),
    $: () => null,   // no painel vem do app.js
    renderLeads() {}, renderReports() {},
  };
  vm.runInNewContext(le(arquivo), ctx);
  return { ctx, ouvintes, conta: () => execucoes };
}
const umCiclo = () => new Promise((r) => setTimeout(r, 20));   // o modulo e async: conta depois dos awaits
for (const arq of ['painel/cliques.js', 'painel/meta.js']) {
  const antes = carrega(arq, true);
  await umCiclo();
  assert.ok(antes.conta() > 0, arq + ': leads ja carregados antes do arquivo -> roda na hora');
  const depois = carrega(arq, undefined);
  await umCiclo();
  assert.equal(depois.conta(), 0, arq + ': leads ainda nao carregados -> espera');
  assert.equal(depois.ouvintes.length, 1, arq + ': escuta o aviso do app.js');
  depois.ouvintes[0]();
  await umCiclo();
  assert.ok(depois.conta() > 0, arq + ': quando o aviso chega, roda');
}
assert.ok(!/poMetaCarrega\(\)|poCasaCliques\(\)/.test((APP.match(/async function loadData\(\)\{[\s\S]*?\n\}/) || [''])[0]),
  'o loadData nao chama os modulos direto (a chamada direta e o defeito)');

/* ---------- travas no ponto de chamada ---------- */
assert.ok(/\$\('#dr-camp'\)\.textContent=typeof poCampanhaDoLead==='function'\?poCampanhaDoLead\(l\):l\.camp;/.test(APP),
  'a ficha usa o nome do anuncio, e sem o meta.js cai no de antes');
assert.ok(/if\(\$\('#dr-nomear'\)\)\{/.test(APP), 'o botao "Dar nome" e ligado com guarda');
assert.ok(/\$\('#dr-nomear'\)\.hidden=!\(l\.adId&&typeof poMetaNomear==='function'\);/.test(APP), 'so aparece em lead de anuncio');
assert.ok(!/from\('po_meta_conversoes'\)/.test(SRC + APP), 'o painel nunca escreve os envios para a Meta (so o servidor)');
assert.ok(/\.upsert\(\{ ad_id: adId, nome_manual: limpo/.test(SRC), 'o nome a mao grava so nome_manual');
for (const id of ['dr-camp', 'dr-nomear', 'meta-capi-info']) assert.ok(HTML.includes('id="' + id + '"'), id + ' existe no painel');
const ordem = ['app.js', 'cliques.js', 'meta.js'].map(f => HTML.indexOf('src="' + f + '?v='));
assert.ok(ordem.every(i => i > 0) && ordem[0] < ordem[1] && ordem[1] < ordem[2], 'cliques.js e meta.js carregam depois do app.js');

console.log('test-meta-painel OK');
