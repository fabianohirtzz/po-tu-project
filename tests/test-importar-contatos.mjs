import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

/* Extrai as funcoes puras de importar-contatos.js sem carregar o painel
   inteiro, no mesmo padrao de tests/test-clientes.mjs. O extrator e um
   regex ingenuo (para na primeira "\n}" em coluna zero), entao as funcoes
   fonte nao podem depender de constante nenhuma declarada no topo do
   arquivo — teve que ir tudo dentro da funcao. */
const src = readFileSync(new URL('../painel/importar-contatos.js', import.meta.url), 'utf8');
function pega(nome) {
  const m = src.match(new RegExp('function ' + nome + '\\([\\s\\S]*?\\n\\}'));
  assert.ok(m, nome + ' encontrada em importar-contatos.js');
  return m[0];
}

const ctx = new Function(
  'function esc(s){return (s==null?"":String(s)).replace(/[&<>"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;","\\"":"&quot;"}[c]));}' +
  pega('poResumoTexto') + pega('poLinhaRevisao') + pega('poIcCamposEnvio') +
  pega('poLinhaFusao') + pega('poIcForcarEnvio') + pega('poIcAvisoLote') +
  '; return {poResumoTexto, poLinhaRevisao, poIcCamposEnvio, poLinhaFusao, poIcForcarEnvio, poIcAvisoLote};'
)();
const { poResumoTexto, poLinhaRevisao, poIcCamposEnvio, poLinhaFusao, poIcForcarEnvio, poIcAvisoLote } = ctx;

/* ---------- poResumoTexto: a frase que diz o que vai acontecer ANTES de
   aplicar (spec 9.5) ---------- */
const t = poResumoTexto({ novos: 120, funde: 52, revisar: 8, por_cpf: 40, por_celular: 12, por_nome_nasc: 8 });
assert.ok(t.includes('120'), 'diz quantos novos');
assert.ok(t.includes('52'), 'diz quantas fusoes');
assert.ok(t.includes('8'), 'diz quantos para revisar');
assert.ok(/cpf/i.test(t) && /celular/i.test(t), 'explica por que casou (cpf/celular)');

// defensivo: resumo ausente/vazio nao pode quebrar a tela
assert.doesNotThrow(() => poResumoTexto(undefined), 'resumo undefined nao explode');
assert.doesNotThrow(() => poResumoTexto({}), 'resumo vazio nao explode');
assert.ok(poResumoTexto({}).includes('0'), 'resumo vazio ainda produz frase com zeros');

// concordancia (fix round 1, minor): funde=1 e singular, nao "1 vao completar fichas"
const singular = poResumoTexto({ novos: 0, funde: 1, revisar: 0, por_cpf: 1, por_celular: 0, por_nome_nasc: 0 });
assert.ok(/1 vai completar/.test(singular), 'funde=1 usa singular (vai completar)');
assert.ok(!/1 vao completar/.test(singular), 'funde=1 nao usa o plural errado');
const plural = poResumoTexto({ novos: 0, funde: 2, revisar: 0, por_cpf: 2, por_celular: 0, por_nome_nasc: 0 });
assert.ok(/2 vao completar/.test(plural), 'funde=2 continua no plural');

/* ---------- poLinhaRevisao: escapa dado de terceiro (nome vem da agenda
   de outra pessoa) ---------- */
const html = poLinhaRevisao({ nome: '<img onerror=alert(1)>', match_por: 'nome_nasc', match_id: 'L1' });
assert.ok(!html.includes('<img onerror'), 'nome de terceiro escapado');
assert.ok(html.includes('&lt;img'), 'aparece escapado, nao sumido');

/* ---------- os TRES sabores de 'revisar' (task 6, carregado para a task 8)
   precisam de textos DIFERENTES — senao a cliente nao sabe o que fazer em
   dois deles. Distinguem-se por match_por + presenca de match_id. ---------- */

// 1) nome_nasc: palpite, precisa de decisao humana (e' a mesma pessoa?)
const palpite = poLinhaRevisao({ nome: 'Maria Silva', match_por: 'nome_nasc', match_id: 'abc-1' });
assert.ok(/nome/i.test(palpite) && /nascimento/i.test(palpite), 'palpite explica que casou por nome+nascimento');
assert.ok(/palpite|mesma pessoa|homonimo|homônimo/i.test(palpite), 'palpite deixa claro que e so um palpite');

// 2) cpf/celular COM match_id: disputa — outro contato do mesmo lote ja
//    reservou essa ficha. Nao e' o mesmo texto do palpite.
const disputaCpf = poLinhaRevisao({ nome: 'Joao Souza', match_por: 'cpf', match_id: 'ficha-99' });
assert.ok(/outro contato|mesmo arquivo|mesma ficha|ja foi/i.test(disputaCpf), 'disputa explica a reserva pelo mesmo lote');
assert.notEqual(disputaCpf, palpite, 'disputa por cpf tem texto diferente do palpite');

const disputaCelular = poLinhaRevisao({ nome: 'Ana Costa', match_por: 'celular', match_id: 'ficha-100' });
assert.ok(/outro contato|mesmo arquivo|mesma ficha|ja foi/i.test(disputaCelular), 'disputa por celular tambem explicada');

// 3) cpf/celular SEM match_id: erro de leitura da base, nao e' decisao do
//    usuario — texto tem que dizer isso, nao empurrar uma pergunta pra ele.
const erroLeitura = poLinhaRevisao({ nome: 'Pedro Lima', match_por: 'cpf', match_id: null });
assert.ok(/erro|suporte|tecnic/i.test(erroLeitura), 'erro de leitura aponta problema tecnico, nao decisao do usuario');
assert.notEqual(erroLeitura, disputaCpf, 'erro de leitura tem texto diferente da disputa (mesmo match_por)');
assert.notEqual(erroLeitura, palpite, 'erro de leitura tem texto diferente do palpite');

// nome de terceiro escapado em todos os tres sabores, nao so no primeiro
const inj = '<script>alert(2)</script>';
assert.ok(!poLinhaRevisao({ nome: inj, match_por: 'cpf', match_id: 'x' }).includes('<script>'), 'disputa escapa nome');
assert.ok(!poLinhaRevisao({ nome: inj, match_por: 'cpf', match_id: null }).includes('<script>'), 'erro de leitura escapa nome');

/* ---------- poIcCamposEnvio: trava o achado Critical do fix round 1 ----------
   origem/tipo do POST de aplicar tem que ser exatamente o que foi
   AUDITADO no preview, nunca o que esta ao vivo no formulario — senao
   trocar o seletor depois de ver a auditoria (sem trocar de arquivo)
   grava uma origem diferente da que a cliente viu, e origem='crm-toninho'
   liga revisado=true sem revisao humana nenhuma (spec 8.1). */

// preview sempre usa o que esta no formulario agora (nao ha nada auditado ainda)
const p1 = poIcCamposEnvio('preview', null, { origem: 'agenda-esposa', tipo: 'vcf' });
assert.deepEqual(p1, { origem: 'agenda-esposa', tipo: 'vcf' }, 'preview usa o formulario atual');

// REPRODUCAO DO CRITICAL: auditoria rodou com 'agenda-esposa', o usuario
// trocou o select para 'crm-toninho' SEM trocar o arquivo (sem nova
// auditoria) e clicou em aplicar. O POST tem que sair com o valor
// AUDITADO ('agenda-esposa'), nunca com o 'crm-toninho' que esta no
// select agora.
const auditado = { origem: 'agenda-esposa', tipo: 'vcf' };
const trocadoAoVivo = { origem: 'crm-toninho', tipo: 'csv' };
const aplicarDepoisDaTroca = poIcCamposEnvio('aplicar', auditado, trocadoAoVivo);
assert.deepEqual(aplicarDepoisDaTroca, auditado, 'aplicar reenvia o AUDITADO, nao o que foi trocado ao vivo depois do preview');
assert.notDeepEqual(aplicarDepoisDaTroca, trocadoAoVivo, 'aplicar nunca vaza o valor trocado ao vivo (era o defeito do round 1)');

// sem auditoria nenhuma (defensivo; a UI mantem o botao de aplicar
// escondido/desabilitado neste caso, mas a funcao pura nao pode confiar
// nisso) cai no formulario atual, mesmo comportamento do preview.
const semAuditoria = poIcCamposEnvio('aplicar', null, { origem: 'agenda-marido', tipo: 'vcf' });
assert.deepEqual(semAuditoria, { origem: 'agenda-marido', tipo: 'vcf' }, 'aplicar sem auditoria previa cai no formulario atual');

/* ---------- a TELA nao pode oferecer 'crm-toninho' como origem ----------
   Escolher "CRM do Toninho" com um .vcf de agenda faz wa_import_eh_crm()
   devolver true, e isso DISPENSA o filtro do marcador "PO" e grava
   revisado=true, cliente=true: um clique errado marcaria medico, dentista
   e fornecedor como clientes revisados, prontos para disparo pago (a spec
   8.1 trata "contato nao revisado nunca entra em campanha" como defesa
   obrigatoria). A carga do CRM foi de uso unico, por linha de comando, e ja
   aconteceu. O endpoint continua aceitando a origem (a lista fechada do
   PHP e uma trava de servidor, nao um menu) — so a interface deixa de
   oferece-la. */
const painel = readFileSync(new URL('../painel/index.html', import.meta.url), 'utf8');
assert.ok(/<select[^>]*id="ic-origem"/.test(painel), 'o seletor de origem existe');
const selOrigem = painel.slice(painel.indexOf('id="ic-origem"'), painel.indexOf('id="ic-tipo"'));
assert.ok(!/crm-toninho/.test(selOrigem), 'a tela NAO oferece crm-toninho como origem');
assert.ok(/agenda-esposa/.test(selOrigem) && /agenda-marido/.test(selOrigem), 'as duas agendas continuam la');

/* ---------- cache-buster: JS alterado precisa de ?v novo ----------
   O .htaccess cacheia JS por 1 mes. poChavePessoa mudou nesta branch
   (passou a usar poE164) e o app.js foi para v=3; com clientes.js preso em
   v=1, a cliente receberia o app.js novo com o clientes.js velho. */
const ver = (arq) => {
  const m = painel.match(new RegExp('src="' + arq + '\\?v=(\\d+)"'));
  assert.ok(m, arq + ' referenciado com ?v= no painel');
  return Number(m[1]);
};
assert.ok(ver('clientes.js') >= 2, 'clientes.js subiu de versao junto com a mudanca de poChavePessoa');
assert.ok(ver('importar-contatos.js') >= 3, 'importar-contatos.js subiu de versao (poLinhaFusao/forcar/aviso de lote)');

/* ---------- poLinhaFusao: quais fusoes vao preencher quais campos (task 3,
   spec 9.5) — o dado ja vinha na resposta do preview e a tela descartava
   todo 'funde'. A cliente autoriza uma escrita sem ver o que ela preenche. ---------- */

// a fusao diz QUAIS campos vai preencher, com nome legivel
const h = poLinhaFusao({nome:'Maria', match_por:'cpf', match_id:'L1',
                        preenche:['telefone','cidade','passaporte_numero']});
assert.ok(h.includes('Maria'), 'mostra o nome');
assert.ok(/cpf/i.test(h), 'diz por que casou');
assert.ok(/telefone/i.test(h) && /cidade/i.test(h), 'lista os campos que vai preencher');
assert.ok(/passaporte/i.test(h), 'inclusive os do bloco de ficha');

// fusao sem campo nenhum precisa dizer isso, senao a cliente acha que vai mudar algo
const vazio = poLinhaFusao({nome:'Joao', match_por:'celular', match_id:'L2', preenche:[]});
assert.ok(/nada a preencher|nenhum campo/i.test(vazio),
  'fusao sem campo diz que nao preenche nada');

// dado de terceiro escapado: o nome vem da agenda de outra pessoa
const mal = poLinhaFusao({nome:'<img src=x onerror=alert(1)>', match_por:'cpf',
                          match_id:'L3', preenche:['cidade']});
assert.ok(!mal.includes('<img src=x'), 'nome de terceiro escapado');
assert.ok(mal.includes('&lt;img'), 'aparece escapado, nao sumido');

// nome de coluna tambem e escapado: vem do servidor, mas nao custa
const colMal = poLinhaFusao({nome:'Ana', match_por:'cpf', match_id:'L4',
                             preenche:['<script>']});
assert.ok(!colMal.includes('<script>'), 'nome de coluna escapado');

/* ---------- poIcForcarEnvio: a caixa "importar novamente" (ruling A da
   Task 2) — a guarda de 409 do servidor tem lease de 10min sem escape
   pela interface. So o aplicar pode mandar forcar=1, e so quando a caixa
   estiver marcada; o preview nunca manda (a guarda de servidor ja nao
   bloqueia preview, e o codigo nao pode confiar so na UI escondendo o
   campo). ---------- */
assert.equal(poIcForcarEnvio('aplicar', true), true, 'aplicar com a caixa marcada manda forcar');
assert.equal(poIcForcarEnvio('aplicar', false), false, 'aplicar com a caixa desmarcada nao manda forcar');
assert.equal(poIcForcarEnvio('aplicar', undefined), false, 'aplicar sem checkbox no DOM nao manda forcar (defensivo)');
assert.equal(poIcForcarEnvio('preview', true), false, 'preview NUNCA manda forcar, mesmo com a caixa marcada');

/* ---------- poIcAvisoLote: 'lote_registrado'===false (ruling B da Task 2)
   — os contatos foram gravados, mas o registro do lote nao fechou; sem
   ele uma reimportacao do mesmo arquivo passaria pela guarda e duplicaria.
   Nao e erro (a importacao deu certo): e aviso. ---------- */
const avisoFalhou = poIcAvisoLote({ ok: true, aplicado: {}, lote_registrado: false });
assert.ok(/nao consegui registrar|confira/i.test(avisoFalhou), 'lote_registrado=false avisa para conferir antes de importar de novo');

assert.equal(poIcAvisoLote({ ok: true, aplicado: {}, lote_registrado: true }), '', 'lote_registrado=true nao gera aviso');
assert.equal(poIcAvisoLote({ ok: true, aplicado: {} }), '', 'resposta sem lote_registrado (ex.: preview) nao gera aviso');
assert.equal(poIcAvisoLote(null), '', 'resposta ausente nao explode e nao gera aviso');

/* ---------- a tela tem a caixa "importar novamente", desmarcada por
   padrao (e escape hatch, nao fluxo normal) ---------- */
assert.ok(/<input[^>]*id="ic-forcar"[^>]*type="checkbox"/.test(painel) || /<input[^>]*type="checkbox"[^>]*id="ic-forcar"/.test(painel),
  'existe checkbox #ic-forcar');
const checkboxTag = (painel.match(/<input[^>]*id="ic-forcar"[^>]*>/) || [''])[0];
assert.ok(!/checked/.test(checkboxTag), 'a caixa "importar novamente" comeca desmarcada');
assert.ok(/importar novamente/i.test(painel), 'a tela tem o texto "importar novamente" (o erro 409 cita esta frase)');

/* ---------- placeholder da tabela "Fichas que vao ser completadas".
   Ela nao tem wrapper escondivel (fica sempre visivel, porque "nenhuma ficha
   sera completada" tambem e informacao), entao sem placeholder a aba abria
   com cabecalho sobre corpo vazio. ---------- */
const tbodyFusoes = (painel.match(/<tbody id="ic-fusoes">[\s\S]*?<\/tbody>/) || [''])[0];
assert.ok(tbodyFusoes, 'existe o tbody #ic-fusoes');
assert.ok(/class="empty"/.test(tbodyFusoes),
  'a tabela de fusoes nasce com o placeholder padrao do projeto, nao vazia');
// E o reset repoe o placeholder em vez de esvaziar: invalidar o preview
// (arquivo novo, origem trocada) devolvia o corpo vazio.
const reset = (src.match(/function poIcReset\(\)[\s\S]*?\n  \}/) || [''])[0];
assert.ok(reset, 'poIcReset encontrada');
assert.ok(/fusoesRows\.innerHTML = poIcFusoesVazio\(\)/.test(reset),
  'o reset volta ao placeholder, nao a corpo vazio');
assert.ok(/class="empty"/.test(pega('poIcFusoesVazio')), 'e o placeholder e o <div class="empty">');

console.log('test-importar-contatos OK');
