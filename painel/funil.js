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
