/* ============================================================
   Modelos de mensagem (templates) da Meta.

   Mensagem fora da janela de 24h so sai por modelo APROVADO. Ate aqui o
   modelo era criado na mao no WhatsApp Manager e o nome dele digitado na
   tela de Transmissao - nada garantia que o texto aprovado la e o texto
   escrito aqui fossem o mesmo, e e o aprovado que chega no cliente.

   A conferencia do texto NAO e refeita em JavaScript: ela roda no servidor
   (templates.php, modo=conferir) pela MESMA funcao que o criar usa. Uma
   copia das regras aqui envelheceria em silencio, e tela que valida
   diferente do envio aprova o que a Meta recusa.

   As funcoes puras vivem soltas no arquivo porque o teste as extrai do
   fonte por regex (tests/test-modelos.mjs), no mesmo padrao do textos.js.
============================================================ */

/* Status cru da Meta virando algo que a cliente entende. Nao traduz o que
   nao conhece: status novo aparece como veio, em vez de sumir da tela. */
function poModeloStatus(s) {
  const cru = (s == null ? '' : String(s)).toUpperCase();
  const mapa = {
    APPROVED: { rotulo: 'Aprovado',   classe: 'ok' },
    PENDING:  { rotulo: 'Em análise', classe: 'aguardando' },
    REJECTED: { rotulo: 'Recusado',   classe: 'erro' },
    PAUSED:   { rotulo: 'Pausado',    classe: 'erro' },
    DISABLED: { rotulo: 'Desativado', classe: 'erro' },
  };
  return mapa[cru] || { rotulo: cru || 'desconhecido', classe: 'aguardando' };
}

/* Só modelo aprovado pode ser usado numa campanha. Em análise não serve: o
   envio sairia recusado para todo mundo, e falha é terminal. */
function poModeloUsavel(t) {
  return String((t && t.status) || '').toUpperCase() === 'APPROVED';
}

/* O nome e o status vem da Meta, entao aqui dentro sao DADO, nunca
   marcacao: tudo passa pelo esc(). */
function poModeloLinha(t) {
  const it = t || {};
  const st = poModeloStatus(it.status);
  const usavel = poModeloUsavel(it);
  return '<div class="mdl-linha">' +
    '<div class="mdl-nome"><code>' + esc(it.nome) + '</code>' +
      '<span class="mdl-meta">' + esc(it.categoria) + ' · ' + esc(it.idioma) + '</span></div>' +
    '<span class="mdl-st ' + st.classe + '">' + esc(st.rotulo) + '</span>' +
    (usavel
      ? '<button class="btn btn-ghost mdl-usar" type="button" data-nome="' +
        esc(it.nome) + '">Usar na transmissão</button>'
      : '') +
    '</div>';
}

/* Os problemas chegam prontos do servidor, em portugues. Lista vazia vira
   um visto, nunca uma area em branco: "nao apareceu erro" e ambiguo entre
   "esta certo" e "ainda nao conferi". */
function poModeloProblemasHtml(problemas) {
  const lista = problemas || [];
  if (!lista.length) {
    return '<div class="mdl-ok">O texto está dentro das regras da Meta.</div>';
  }
  return '<ul class="mdl-probs">' +
    lista.map(p => '<li>' + esc(p) + '</li>').join('') + '</ul>';
}

/* ---------- conversa com o templates.php ---------- */

let PO_MDL_OK = false;       // o texto conferido passou?
let PO_MDL_OCUPADO = false;  // trava contra o segundo clique

/* O token vem de sb.auth.getSession() na hora, como nos outros endpoints:
   guardado numa variavel ele vence sozinho e o servidor passa a devolver
   401 sem ninguem entender por que. */
async function poModeloPost(modo, extra) {
  const s = await sb.auth.getSession();
  const fd = new FormData();
  fd.append('modo', modo);
  fd.append('sb_token', (s && s.data && s.data.session && s.data.session.access_token) || '');
  Object.keys(extra || {}).forEach(k => fd.append(k, extra[k]));
  const res = await fetch('../templates.php', { method: 'POST', body: fd });
  let j = null;
  try { j = await res.json(); } catch (e) { j = null; }
  if (!j) throw new Error('O servidor não respondeu direito. Tente de novo.');
  // 422 traz a lista de problemas: nao e falha de servidor, e texto a corrigir.
  if (!j.ok && !(j.problemas && j.problemas.length)) {
    throw new Error(j.error || 'Falha ao falar com a Meta.');
  }
  return j;
}

function poModeloCampos() {
  return {
    titulo:  (document.querySelector('#mdl-titulo') || {}).value || '',
    corpo:   (document.querySelector('#mdl-corpo') || {}).value || '',
    exemplo: (document.querySelector('#mdl-exemplo') || {}).value || '',
  };
}

/* O botao de criar NASCE desabilitado e so uma conferencia sem problemas o
   habilita: assim nao existe janela em que um clique crie um modelo torto.
   Modelo criado nao se renomeia nem se apaga de imediato na Meta, entao o
   erro fica na conta para sempre. */
function poModeloHabilita(pode) {
  PO_MDL_OK = !!pode;
  const b = document.querySelector('#mdl-criar');
  if (b) b.disabled = !pode;
}

async function poModeloConfere() {
  const c = poModeloCampos();
  const cx = document.querySelector('#mdl-conferencia');
  poModeloHabilita(false);
  try {
    const j = await poModeloPost('conferir', c);
    if (cx) cx.innerHTML = poModeloProblemasHtml(j.problemas);
    const nome = document.querySelector('#mdl-nome-previsto');
    if (nome) nome.textContent = j.nome || '';
    poModeloHabilita((j.problemas || []).length === 0 && (c.titulo || '').trim() !== '');
  } catch (e) {
    if (cx) cx.innerHTML = poModeloProblemasHtml([e.message]);
  }
}

async function poModeloCria() {
  if (PO_MDL_OCUPADO || !PO_MDL_OK) return;
  PO_MDL_OCUPADO = true;
  const b = document.querySelector('#mdl-criar');
  if (b) b.disabled = true;
  try {
    const j = await poModeloPost('criar', poModeloCampos());
    if (j.problemas && j.problemas.length) {
      const cx = document.querySelector('#mdl-conferencia');
      if (cx) cx.innerHTML = poModeloProblemasHtml(j.problemas);
      return;
    }
    toast('Modelo "' + j.nome + '" enviado para aprovação. A Meta responde em minutos ou horas.');
    await poRenderModelos();
  } catch (e) {
    toast(e.message, true);
  } finally {
    PO_MDL_OCUPADO = false;
    // Continua desabilitado de proposito: o texto tem que ser conferido de
    // novo antes de um segundo envio, senao dois cliques criam dois modelos.
    poModeloHabilita(false);
  }
}

async function poRenderModelos() {
  const box = document.querySelector('#mdl-lista');
  if (!box) return;
  box.innerHTML = '<div class="hint">Lendo os modelos da conta...</div>';
  try {
    const j = await poModeloPost('listar', {});
    const ts = j.templates || [];
    box.innerHTML = ts.length
      ? ts.map(poModeloLinha).join('')
      : '<div class="hint">Nenhum modelo criado ainda.</div>';
    box.querySelectorAll('.mdl-usar').forEach(b => {
      b.onclick = () => {
        const campo = document.querySelector('#camp-template');
        if (campo) campo.value = b.dataset.nome;
        toast('Modelo "' + b.dataset.nome + '" escolhido para a próxima transmissão.');
      };
    });
  } catch (e) {
    // Erro de leitura NUNCA vira "nenhum modelo": com a Meta fora do ar
    // alguem criaria um modelo duplicado que fica para sempre na conta.
    box.innerHTML = '<div class="txt-erro">' + esc(e.message) + '</div>';
  }
}

function poModeloLiga() {
  const b = document.querySelector('#mdl-conferir');
  if (b) b.onclick = poModeloConfere;
  const c = document.querySelector('#mdl-criar');
  if (c) c.onclick = poModeloCria;
  // Mexeu no texto, a conferencia anterior nao vale mais.
  ['#mdl-titulo', '#mdl-corpo', '#mdl-exemplo'].forEach(sel => {
    const el = document.querySelector(sel);
    if (el) el.oninput = () => poModeloHabilita(false);
  });
}

/* O script e classico e esta no fim do corpo, entao o DOM ja existe. */
poModeloLiga();
