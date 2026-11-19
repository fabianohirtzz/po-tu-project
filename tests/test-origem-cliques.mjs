/* O casamento do clique no WhatsApp do site com o lead do WhatsApp
 * (painel/cliques.js, fase 3A, 13/11/2026). Roda o codigo de verdade,
 * extraido do arquivo. Dados inventados.
 *
 * As duas regras que este teste existe para segurar:
 * - por HORARIO so liga quando nao ha duvida nenhuma: um clique, um lead;
 *   provavel nao pode virar chute;
 * - lead que ja tem origem (formulario, anuncio da Meta) nunca e sobrescrito. */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const le = rel => readFileSync(new URL('../' + rel, import.meta.url), 'utf8').split('\r').join('');
const SRC = le('painel/cliques.js');
function casa(src, i, abre, fecha) { let d = 0; for (let k = i; k < src.length; k++) { if (src[k] === abre) d++; else if (src[k] === fecha && --d === 0) return k + 1; } return -1; }
function fn(src, nome) {
  let i = src.indexOf('function ' + nome + '(');
  assert.ok(i >= 0, nome + ' existe');
  if (src.slice(i - 6, i) === 'async ') i -= 6;   // sem o async, o await dentro vira erro de sintaxe
  return src.slice(i, casa(src, src.indexOf('{', i), '{', '}')) + '\n';
}
function konst(src, nome) { const i = src.indexOf('const ' + nome + ' ='); assert.ok(i >= 0, nome + ' existe'); return src.slice(i, src.indexOf(';', i) + 1) + '\n'; }

const C = new Function(konst(SRC, 'PO_CLIQUE_JANELA_MIN') + konst(SRC, 'PO_CLIQUE_ORIGEM') +
  ['poCodigosDoTexto', 'poLeadSemOrigem', 'poCasaPorCodigo', 'poCasaPorHorario', 'poPatchDoClique'].map(n => fn(SRC, n)).join('') +
  'return {poCodigosDoTexto,poLeadSemOrigem,poCasaPorCodigo,poCasaPorHorario,poPatchDoClique,PO_CLIQUE_ORIGEM};')();

/* ---------- o codigo dentro da mensagem ---------- */
assert.deepEqual(C.poCodigosDoTexto('Olá! Vim pelo site e quero mais informações. (ref G-KTRBX)'), ['G-KTRBX']);
assert.deepEqual(C.poCodigosDoTexto('ref g-ktrbx'), ['G-KTRBX'], 'minusculas');
assert.deepEqual(C.poCodigosDoTexto('oi REF MBCDFG obrigada'), ['M-BCDFG'], 'sem hifen e sem parenteses');
assert.deepEqual(C.poCodigosDoTexto('ref: S-ZZZZZ'), ['S-ZZZZZ']);
assert.deepEqual(C.poCodigosDoTexto('ref G-KAEIO'), [], 'vogal nao e codigo (o site so gera consoante)');
assert.deepEqual(C.poCodigosDoTexto('ref X-KTRBX'), [], 'so G, M ou S');
assert.deepEqual(C.poCodigosDoTexto('a referencia do pacote'), [], '"referencia" nao e "ref"');
assert.deepEqual(C.poCodigosDoTexto(null), []);

/* ---------- dados ---------- */
const T = Date.parse('2026-11-13T15:00:00Z'), MIN = 60000;
const iso = t => new Date(t).toISOString();
const clique = (id, codigo, minAntes, extra = {}) => ({ id, codigo, ultimo_clique_at: iso(T - minAntes * MIN),
  created_at: iso(T - minAntes * MIN), gclid: 'Cj' + id, utm_source: 'google', utm_medium: 'cpc', utm_campaign: 'turquia',
  landing_page: 'https://pereiraoliveiraturismo.com.br/roteiros/turquia?gclid=Cj' + id, ...extra });
const lead = (id, waId, minDepois = 0, raw = {}, extra = {}) => ({ id, waId, data: iso(T + minDepois * MIN), origemCasada: '',
  raw: { origem: 'whatsapp', ...raw }, ...extra });

/* ---------- por codigo ---------- */
{
  const cs = [clique('c1', 'G-KTRBX', 3), clique('c2', 'M-BCDFG', 300)];
  const ls = [lead('l1', '+5548999990001'), lead('l2', '+5548999990002', 0, { ad_id: '1202', origem: 'pago' }), lead('l3', '+5548999990003')];
  const msgs = [
    { wa_id: '+5548999990001', texto: 'Olá! (ref G-KTRBX)', ts: iso(T) },
    { wa_id: '+5548999990001', texto: 'de novo ref g-ktrbx', ts: iso(T + MIN) },
    { wa_id: '+5548999990002', texto: 'oi (ref M-BCDFG)', ts: iso(T) },
    { wa_id: '+5548999990003', texto: '(ref G-ZZZZZ)', ts: iso(T) },          // codigo sem clique: nada
    { wa_id: '+5500000000000', texto: '(ref G-KTRBX)', ts: iso(T) },          // sem lead: nada
  ];
  const p = C.poCasaPorCodigo(cs, msgs, ls);
  assert.equal(p.length, 2, 'um par por clique: ' + JSON.stringify(p.map(x => x.clique.id)));
  assert.equal(p[0].lead.id, 'l1'); assert.equal(p[0].por, 'codigo'); assert.equal(p[0].aplica, true);
  assert.equal(p[1].lead.id, 'l2');
  assert.equal(p[1].aplica, false, 'lead do anuncio da Meta (ad_id) ja tem origem: o clique e ligado, o lead NAO e sobrescrito');
}
assert.equal(C.poLeadSemOrigem({ origem: 'whatsapp' }), true);
for (const k of ['utm_source', 'utm_medium', 'gclid', 'gbraid', 'wbraid', 'fbclid', 'ad_id', 'ctwa_clid']) {
  assert.equal(C.poLeadSemOrigem({ [k]: 'x' }), false, k + ' conta como origem');
}

/* ---------- por horario: so sem duvida ---------- */
{
  const um = C.poCasaPorHorario([clique('c1', 'G-KTRBX', 3)], [lead('l1', '+55a')]);
  assert.equal(um.length, 1, 'um clique 3 min antes de um lead novo: liga');
  assert.equal(um[0].por, 'horario');

  assert.equal(C.poCasaPorHorario([clique('c1', 'G-KTRBX', 3), clique('c2', 'G-BBBBB', 5)], [lead('l1', '+55a')]).length, 0,
    'dois cliques na janela: duvida, nao liga');
  assert.equal(C.poCasaPorHorario([clique('c1', 'G-KTRBX', 3)], [lead('l1', '+55a'), lead('l2', '+55b', 2)]).length, 0,
    'um clique que serve a dois leads: duvida, nao liga nenhum');
  assert.equal(C.poCasaPorHorario([clique('c1', 'G-KTRBX', 16)], [lead('l1', '+55a')]).length, 0, '16 min antes: fora da janela');
  assert.equal(C.poCasaPorHorario([clique('c1', 'G-KTRBX', 15)], [lead('l1', '+55a')]).length, 1, '15 min antes: ainda dentro');
  assert.equal(C.poCasaPorHorario([clique('c1', 'G-KTRBX', -2)], [lead('l1', '+55a')]).length, 0, 'clique DEPOIS da mensagem nao trouxe ninguem');
  assert.equal(C.poCasaPorHorario([clique('c1', 'G-KTRBX', -0.5)], [lead('l1', '+55a')]).length, 1, 'meio minuto de relogio fora: tolera');
  assert.equal(C.poCasaPorHorario([clique('c1', 'G-KTRBX', 3)], [lead('l1', '+55a', 0, { gclid: 'x' })]).length, 0, 'lead com origem nao entra');
  assert.equal(C.poCasaPorHorario([clique('c1', 'G-KTRBX', 3)], [lead('l1', '+55a', 0, { origem: 'site-contato' })]).length, 0,
    'so lead que o ROBO criou (o formulario ja manda a propria origem)');
  assert.equal(C.poCasaPorHorario([clique('c1', 'G-KTRBX', 3)], [lead('l1', '+55a', 0, {}, { origemCasada: 'codigo' })]).length, 0,
    'lead ja ligado nao entra de novo');
  assert.equal(C.poCasaPorHorario([clique('c1', 'G-KTRBX', 3)], [lead('l1', '')]).length, 0, 'sem WhatsApp, sem casamento');
}

/* ---------- o que o lead recebe ---------- */
{
  const p = C.poPatchDoClique(clique('c9', 'G-KTRBX', 3, { fbclid: '' }), 'horario');
  assert.equal(p.origem_casada, 'horario');
  assert.equal(p.gclid, 'Cjc9');
  assert.equal(p.utm_campaign, 'turquia');
  assert.equal(p.fbclid, null, 'vazio vira null, nunca string vazia');
  assert.deepEqual(Object.keys(p).sort(), [...C.PO_CLIQUE_ORIGEM, 'origem_casada'].sort(), 'so campos de origem: nada de status, venda ou notas');
}
// a origem que o lead recebe e a mesma lista que o site guarda (origem.js)
const site = /var CAMPOS = UTM\.concat\(CLIQUES, \['referrer', 'landing_page'\]\);/.test(le('assets/js/origem.js'));
assert.ok(site, 'origem.js monta CAMPOS do jeito esperado');
assert.deepEqual([...C.PO_CLIQUE_ORIGEM], ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
  'gclid', 'gbraid', 'wbraid', 'fbclid', 'referrer', 'landing_page'], 'a mesma lista do site e do po_leads');

/* ---------- o runner de verdade, com um Supabase de mentira ----------
   Uma trava de fonte aqui nao morde: o mesmo .is('lead_id', null) aparece
   na LEITURA dos cliques. O que importa e o comportamento: se outra aba do
   painel ligou o clique entre a leitura e a escrita, esta NAO escreve no lead. */
function fakeSb(db, antesDoUpdate) {
  const log = [];
  return { log, from(t) {
    const q = { t, filtros: [], op: 'select', dados: null };
    const b = {
      select() { return b; }, order() { return b; }, limit() { return b; },
      eq(c, v) { q.filtros.push(r => r[c] === v); return b; },
      is(c, v) { q.filtros.push(r => (r[c] ?? null) === v); return b; },
      gte(c, v) { q.filtros.push(r => String(r[c]) >= v); return b; },
      ilike(c, p) { const s = p.replace(/%/g, '').toLowerCase(); q.filtros.push(r => String(r[c] || '').toLowerCase().includes(s)); return b; },
      update(d) { q.op = 'update'; q.dados = d; return b; },
      then(ok, err) {
        if (q.op === 'update' && antesDoUpdate) antesDoUpdate(q.t, db);
        const linhas = (db[q.t] || []).filter(r => q.filtros.every(f => f(r)));
        if (q.op === 'update') { linhas.forEach(r => Object.assign(r, q.dados)); log.push([q.t, linhas.map(r => r.id)]); }
        return Promise.resolve({ data: linhas.map(r => ({ ...r })), error: null }).then(ok, err);
      },
    };
    return b;
  } };
}
const rodaRunner = new Function('sb', 'LEADS', 'mapRow',
  'const $=()=>null;function renderLeads(){}function renderReports(){}' + konst(SRC, 'PO_CLIQUE_JANELA_MIN') + konst(SRC, 'PO_CLIQUE_ORIGEM') +
  ['poCodigosDoTexto', 'poLeadSemOrigem', 'poCasaPorCodigo', 'poCasaPorHorario', 'poPatchDoClique', 'poCasaCliques'].map(n => fn(SRC, n)).join('') +
  'return poCasaCliques();');
const mapRowFalso = r => ({ raw: r, origem: r.gclid ? 'google' : 'whatsapp', origemCasada: r.origem_casada || '' });
const banco = () => ({
  po_origem_cliques: [{ ...clique('c1', 'G-KTRBX', 3), lead_id: null, created_at: iso(T - 3 * MIN) }],
  po_wa_mensagens: [{ wa_id: '+5548999990001', texto: 'Olá! (ref G-KTRBX)', ts: iso(T), direcao: 'in' }],
  po_leads: [{ id: 'l1', origem: 'whatsapp' }],
});
{
  const db = banco(), sb = fakeSb(db);
  const ls = [lead('l1', '+5548999990001')];
  await rodaRunner(sb, ls, mapRowFalso);
  assert.deepEqual(sb.log.map(x => x[0]), ['po_origem_cliques', 'po_leads'], 'clique livre: liga o clique e depois escreve o lead');
  assert.equal(db.po_origem_cliques[0].lead_id, 'l1');
  assert.equal(db.po_leads[0].gclid, 'Cjc1', 'o lead recebe o gclid do clique');
  assert.equal(ls[0].origem, 'google', 'e e reclassificado na tela sem recarregar');
}
{
  // outra aba ligou o clique entre a leitura e a escrita
  const db = banco(), sb = fakeSb(db, (t, d) => { if (t === 'po_origem_cliques') d.po_origem_cliques[0].lead_id = 'outra-aba'; });
  await rodaRunner(sb, [lead('l1', '+5548999990001')], mapRowFalso);
  assert.equal(db.po_origem_cliques[0].lead_id, 'outra-aba', 'o clique ja ligado nao e roubado');
  assert.ok(!sb.log.some(x => x[0] === 'po_leads'), 'quem nao ganhou o clique NAO escreve no lead');
}
{
  // a mensagem da EQUIPE com o codigo (ela repetiu o codigo) nao conta
  const db = banco(); db.po_wa_mensagens[0].direcao = 'out';
  const sb = fakeSb(db);
  await rodaRunner(sb, [lead('l1', '+5548999990001', 60)], mapRowFalso);
  assert.ok(!sb.log.length, 'so mensagem recebida do cliente liga o codigo');
}

/* ---------- travas no ponto de chamada ---------- */
const runner = fn(SRC, 'poCasaCliques');
assert.ok(/\.eq\('direcao', 'in'\)/.test(runner), 'so mensagem RECEBIDA conta (a da equipe nao)');
assert.ok(!/from\('po_wa_(conversas|fluxo)/.test(SRC) && !/\.(insert|delete)\(/.test(SRC), 'nada do robo e escrito, nada e apagado');
const app = le('painel/app.js');
const carga = (app.match(/async function loadData\(\)\{[\s\S]*?\n\}/) || [''])[0];
assert.ok(carga.indexOf("document.dispatchEvent(new Event('po:leads'))") > carga.indexOf('LEADS=(leadsData'),
  'o app.js avisa (evento po:leads) DEPOIS de os leads estarem carregados');
assert.ok(/window\.PO_LEADS_PRONTOS=true;/.test(carga), 'e deixa a marca para quem carregar depois do aviso');
assert.ok(/raw:r,origemCasada:r\.origem_casada\|\|''/.test(app), 'o lead carrega a linha crua e como foi ligado');

console.log('test-origem-cliques OK');
