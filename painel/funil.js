/* ============================================================
   Aba Funil: o quadro que se move pela conversa do WhatsApp.

   As tres primeiras colunas sao preenchidas pelo robo sozinho (entrada,
   qualificacao e perda); as duas do meio dependem de um gesto da dona,
   que e um atalho digitado no proprio WhatsApp (#proposta, #fechou).
   Arrastar aqui e a rede de seguranca para corrigir, nao o caminho
   principal: o pedido do projeto foi justamente nao ter que preencher
   CRM na mao.
============================================================ */

const PO_COLUNAS = [
  {status:'novo',        titulo:'Contato feito',    modo:'auto'},
  {status:'atendimento', titulo:'Qualificado',      modo:'auto'},
  {status:'negociacao',  titulo:'Proposta enviada', modo:'atalho'},
  {status:'venda',       titulo:'Contrato assinado',modo:'atalho'},
  {status:'perdido',     titulo:'Perdido',          modo:'auto'},
];

/* 'semresposta' nao e um status legado: e o DEFAULT da coluna po_leads.status
   no banco, entao todo lead que chega pelo formulario do site (enviar.php,
   que nao seta status nenhum no insert) nasce assim. Ele nao tem coluna
   propria porque nao precisa: cai em 'novo' porque "contato feito" e
   exatamente o que ele e, tenha vindo pelo WhatsApp (status 'novo') ou pelo
   formulario do site (status 'semresposta'). */
function poAgrupaFunil(leads) {
  const cols = {};
  PO_COLUNAS.forEach(c => { cols[c.status] = []; });
  (leads || []).forEach(l => {
    const alvo = cols[l.status] ? l.status : 'novo';
    cols[alvo].push(l);
  });
  return cols;
}

function poCardFunil(l) {
  const valor = l.ven || l.orc;
  return '<article class="fn-card" draggable="true" data-id="' + esc(l.id) + '">' +
    '<div class="fn-card-n">' + esc(l.nome) + '</div>' +
    '<div class="fn-card-r">' + esc(l.roteiro) + '</div>' +
    '<div class="fn-card-f">' +
      '<span class="mono">' + esc(fmtData(l.data)) + '</span>' +
      (valor ? '<span class="money">' + brl(valor) + '</span>' : '') +
    '</div></article>';
}

function poRenderFunil() {
  const alvo = document.querySelector('#fn-board');
  if (!alvo) return;
  const cols = poAgrupaFunil(typeof filtered === 'function' ? filtered() : LEADS);

  alvo.innerHTML = PO_COLUNAS.map(c => {
    const lista = cols[c.status] || [];
    const etiqueta = c.modo === 'auto'
      ? '<span class="fn-modo fn-modo--auto">automatico</span>'
      : '<span class="fn-modo fn-modo--atalho">atalho</span>';
    return '<section class="fn-col" data-status="' + c.status + '">' +
      '<header class="fn-col-h"><span class="fn-col-t">' + c.titulo + '</span>' +
        '<span class="fn-col-n">' + lista.length + '</span></header>' +
      etiqueta +
      '<div class="fn-col-b">' + (lista.length ? lista.map(poCardFunil).join('') :
        '<div class="fn-vazio">nenhum</div>') + '</div>' +
      '</section>';
  }).join('');

  if (typeof poLigaMover === 'function') poLigaMover();
}

/* ---------- mover o card ---------- */

/* O que vai para o banco quando o status muda. Separado da UI porque a
   regra do venda_at e de negocio, nao de interface: ele alimenta o ciclo
   de venda dos relatorios, e um carimbo errado faz o relatorio mentir
   sem que ninguem perceba. */
function poPatchStatus(status, lead) {
  const patch = {status};
  if (status === 'venda') {
    // Venda que ja tinha carimbo mantem a data original: mover o card de
    // novo nao pode reescrever quando a venda aconteceu.
    if (!lead.vendaAt) patch.venda_at = new Date().toISOString();
    else patch.venda_at = lead.vendaAt;
  } else if (lead.vendaAt) {
    patch.venda_at = null;
  }
  return patch;
}

async function poMoveLead(id, status) {
  const l = LEADS.find(x => String(x.id) === String(id));
  if (!l || l.status === status) return;

  const antes = {status: l.status, vendaAt: l.vendaAt};
  const patch = poPatchStatus(status, l);

  // Move na tela primeiro: o quadro responde na hora e desfaz se o banco
  // recusar. Esperar a rede para so entao mover deixa a sensacao de travado.
  l.status = status;
  if ('venda_at' in patch) l.vendaAt = patch.venda_at;
  poRenderFunil();

  const {error} = await sb.from('po_leads').update(patch).eq('id', l.id);
  if (error) {
    l.status = antes.status;
    l.vendaAt = antes.vendaAt;
    poRenderFunil();
    toast('Nao consegui mover: ' + error.message, true);
    return;
  }
  const col = PO_COLUNAS.find(c => c.status === status);
  toast('Movido para ' + (col ? col.titulo : status) + '.');
}

/* Arrastar so existe no desktop: o HTML puro nao tem arrastar por toque, e
   o projeto nao carrega biblioteca de front. No celular o toque abre o
   menu de etapas, que e ate mais rapido do que arrastar com o dedo. */
function poLigaMover() {
  const board = document.querySelector('#fn-board');
  if (!board) return;
  const toque = window.matchMedia('(hover: none)').matches;

  board.querySelectorAll('.fn-card').forEach(card => {
    if (toque) {
      card.draggable = false;
      card.onclick = () => poAbreMenuEtapa(card.dataset.id);
      return;
    }
    card.ondblclick = () => openDrawer(card.dataset.id);
    card.ondragstart = e => {
      e.dataTransfer.setData('text/plain', card.dataset.id);
      e.dataTransfer.effectAllowed = 'move';
      card.classList.add('fn-card--arrastando');
    };
    card.ondragend = () => card.classList.remove('fn-card--arrastando');
  });

  board.querySelectorAll('.fn-col').forEach(col => {
    col.ondragover = e => { e.preventDefault(); col.classList.add('fn-col--alvo'); };
    col.ondragleave = () => col.classList.remove('fn-col--alvo');
    col.ondrop = e => {
      e.preventDefault();
      col.classList.remove('fn-col--alvo');
      const id = e.dataTransfer.getData('text/plain');
      if (id) poMoveLead(id, col.dataset.status);
    };
  });
}

function poFechaMenuEtapa() {
  const menu = document.querySelector('#fn-menu');
  if (menu) menu.classList.remove('on');
}

function poAbreMenuEtapa(id) {
  const l = LEADS.find(x => String(x.id) === String(id));
  if (!l) return;
  const menu = document.querySelector('#fn-menu');
  menu.querySelector('#fn-menu-n').textContent = l.nome;
  menu.querySelector('#fn-menu-o').innerHTML = PO_COLUNAS.map(c =>
    '<button class="fn-menu-b' + (c.status === l.status ? ' on' : '') +
      '" data-status="' + c.status + '">' + c.titulo + '</button>').join('') +
    '<button class="fn-menu-b fn-menu-b--ver" data-ver="1">Ver o lead completo</button>';
  menu.querySelectorAll('.fn-menu-b').forEach(b => {
    b.onclick = () => {
      poFechaMenuEtapa();
      document.querySelector('#scrim').classList.remove('on');
      if (b.dataset.ver) openDrawer(id);
      else if (b.dataset.status !== l.status) poMoveLead(id, b.dataset.status);
    };
  });
  document.querySelector('#scrim').classList.add('on');
  menu.classList.add('on');
}

/* O menu de etapas usa o mesmo #scrim do drawer e da ficha, que sobrevive
   ao innerHTML do #fn-board. Por isso o listener de fechar ao tocar fora
   e anexado uma vez so, aqui fora do poRenderFunil/poLigaMover, e via
   addEventListener (nao onclick) para nao substituir o handler que o
   app.js ja tem no scrim para o drawer/ficha/rdrawer/ndrawer. Sem isso o
   menu nao tinha nenhum jeito de fechar sem escolher uma etapa. */
(function poLigaScrimMenu() {
  const scrim = document.querySelector('#scrim');
  if (scrim) scrim.addEventListener('click', poFechaMenuEtapa);
})();
