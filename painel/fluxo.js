/* ============================================================
   Fluxo do robo: o que o robo faz no WhatsApp, card por card.

   Esta e a LISTA, o caminho primario: a Lais usa o painel no celular, entao
   o fluxo e uma coluna de cards, cada um com o tipo, o titulo, uma previa do
   texto e as saidas NOMEADAS (o titulo do card de destino, nunca o id).
   O desenho em canvas do desktop, a gaveta de edicao e o Publicar/Ligar
   vem em outras tarefas.

   Tudo que vem do banco entra na tela por esc(): titulo, texto, rotulo de
   opcao e nome de fluxo sao escritos pela cliente, e as mensagens do
   wa_fluxo_valida (PHP) interpolam esse texto sem escapar - certo la, onde e
   string; aqui e DOM.

   As funcoes puras sao extraidas do fonte pelos testes (new Function), entao
   NENHUMA constante de topo de arquivo pode ser dependencia delas: a lista de
   tipos mora DENTRO de poFluxoTipos(), como o catalogo em textos.js.
============================================================ */

/* Espelho de WA_CARD_TIPOS (lib/wa-fluxo.php), NA MESMA ORDEM. O
   tests/test-wa-fluxo-tipos.mjs le o PHP e compara. O rotulo e o que a cliente
   le: nada de nome tecnico (ir_para_fluxo) na tela. */
function poFluxoTipos() {
  return [
    {tipo:'texto', rotulo:'Mensagem de texto', manda:'Manda um texto e segue para o próximo card.', espera:false},
    {tipo:'botoes', rotulo:'Botões', manda:'Pergunta com até 3 botões e espera a pessoa escolher.', espera:true},
    {tipo:'lista', rotulo:'Lista de opções', manda:'Mostra uma lista para a pessoa escolher e espera a resposta.', espera:true},
    {tipo:'pdf', rotulo:'Envio do PDF', manda:'Manda o PDF do roteiro e segue para o próximo card.', espera:false},
    {tipo:'marca', rotulo:'Anotar no cadastro', manda:'Não manda nada: anota uma informação na ficha da pessoa.', espera:false},
    {tipo:'ir_para_fluxo', rotulo:'Passar para outro fluxo', manda:'Entrega a conversa para outro fluxo.', espera:false},
    {tipo:'humano', rotulo:'Passar para a equipe', manda:'Chama a equipe e o robô se cala.', espera:false},
    {tipo:'fim', rotulo:'Fim da conversa', manda:'Encerra a conversa.', espera:false},
  ];
}

// Devolve a definicao do tipo, ou null se o PHP/banco trouxer um tipo que a tela nao conhece.
function poFluxoTipo(tipo) {
  const lista = poFluxoTipos();
  for (let i = 0; i < lista.length; i++) if (lista[i].tipo === tipo) return lista[i];
  return null;
}

/* As opcoes de um card, sempre como lista de {id, rotulo, destino}. O banco
   (jsonb) pode devolver lista, texto JSON, null ou lixo: tudo isso vira lista,
   no pior caso vazia. Nunca lanca. */
function poFluxoOpcoes(opcoes) {
  let l = opcoes;
  if (typeof l === 'string') { try { l = JSON.parse(l); } catch (e) { l = []; } }
  if (!Array.isArray(l)) return [];
  const saida = [];
  l.forEach(function (o) {
    if (!o || typeof o !== 'object') return;
    saida.push({
      id: o.id == null ? '' : String(o.id),
      rotulo: o.rotulo == null ? '' : String(o.rotulo),
      destino: o.destino == null || o.destino === '' ? null : String(o.destino),
    });
  });
  return saida;
}

// Primeiras palavras do texto, em uma linha. Devolve texto PURO: quem desenha escapa.
function poFluxoPrevia(texto) {
  if (texto == null) return '';
  const t = String(texto).replace(/\s+/g, ' ').trim();
  return t.length > 140 ? t.slice(0, 140).replace(/\s+\S*$/, '') + '…' : t;
}

/* Um card. `porId` e o mapa id -> card do fluxo, para NOMEAR as saidas.
   Card degenerado (null, sem tipo, tipo que a tela nao conhece) vira card
   marcado, nunca excecao: um card ruim nao pode derrubar a tela inteira. */
function poFluxoCard(card, porId) {
  const c = card && typeof card === 'object' ? card : {};
  const mapa = porId && typeof porId === 'object' ? porId : {};
  const def = poFluxoTipo(c.tipo);
  const nomeDe = function (id) {
    if (id == null || id === '') return '';
    const d = Object.prototype.hasOwnProperty.call(mapa, id) ? mapa[id] : null;
    if (!d) return '<span class="fl-aviso">o card "' + esc(id) + '" não existe</span>';
    return esc(d.titulo || '(sem título)');
  };
  const saida = function (rotulo, destino) {
    const alvo = destino == null || destino === '' ? '<span class="fl-aviso">sem destino</span>' : nomeDe(destino);
    return '<li class="fl-saida"><span class="fl-saida__rot">' + rotulo + '</span> <span class="fl-saida__seta">→</span> ' + alvo + '</li>';
  };

  const opcoes = poFluxoOpcoes(c.opcoes);
  const saidas = [];
  opcoes.forEach(function (o) { saidas.push(saida(esc(o.rotulo || '(sem nome)'), o.destino)); });
  if (c.proximo) saidas.push(saida('Depois', c.proximo));
  if (c.fallback) saidas.push(saida('Não entendeu', c.fallback));
  if (c.sem_resposta) saidas.push(saida('Sem resposta', c.sem_resposta));

  let aviso = '';
  if (!def) aviso = '<p class="fl-aviso">Tipo de card desconhecido: ' + esc(c.tipo == null ? '(vazio)' : c.tipo) + '. O robô não sabe executá-lo.</p>';
  else if (c.tipo === 'botoes' && !opcoes.length) aviso = '<p class="fl-aviso">Este card de botões está sem opções.</p>';

  const previa = poFluxoPrevia(c.texto);
  const classe = 'fl-card ' + (def ? 'fl-card--' + def.tipo : 'fl-card--desconhecido');
  return '<article class="' + classe + '" data-card="' + esc(c.id) + '">' +
    '<div class="fl-card__tipo">' + (def ? esc(def.rotulo) : 'Desconhecido') + '</div>' +
    '<h3 class="fl-card__titulo">' + esc(c.titulo || '(sem título)') + '</h3>' +
    (previa ? '<p class="fl-card__previa">' + esc(previa) + '</p>' : '') +
    aviso +
    (saidas.length ? '<ul class="fl-saidas">' + saidas.join('') + '</ul>' : '') +
    '</article>';
}

// A coluna de cards de um fluxo. O card inicial vem marcado.
function poFluxoLista(fluxo) {
  const f = fluxo && typeof fluxo === 'object' ? fluxo : {};
  const cards = Array.isArray(f.cards) ? f.cards.filter(function (c) { return c && typeof c === 'object'; }) : [];
  if (!cards.length) return '<p class="fl-vazio">Este fluxo ainda não tem cards.</p>';
  const porId = Object.create(null);
  cards.forEach(function (c) { if (c.id != null) porId[c.id] = c; });
  return '<div class="fl-lista">' + cards.map(function (c) {
    const h = poFluxoCard(c, porId);
    return c.id != null && c.id === f.card_inicial
      ? h.replace('class="fl-card ', 'class="fl-card fl-card--inicial ')
      : h;
  }).join('') + '</div>';
}

// O seletor de fluxo. Sao poucos fluxos e a cliente precisa alcancar todos.
function poFluxoSeletor(fluxos, selecionado) {
  const l = Array.isArray(fluxos) ? fluxos.filter(function (f) { return f && f.id != null; }) : [];
  if (!l.length) return '';
  return '<select id="fl-seletor" class="fl-seletor" aria-label="Fluxo">' + l.map(function (f) {
    return '<option value="' + esc(f.id) + '"' + (String(f.id) === String(selecionado) ? ' selected' : '') + '>' +
      esc(f.nome || '(sem nome)') + '</option>';
  }).join('') + '</select>';
}

/* ============================================================
   Tela: so aqui ha DOM e rede (nao testado por execucao).
============================================================ */
let PO_FLUXOS = [];
let PO_FLUXO_SEL = null;

/* O token vem de sb.auth.getSession() na hora, como nos outros endpoints. */
async function poFluxoPost(modo, extra) {
  const s = await sb.auth.getSession();
  const fd = new FormData();
  fd.append('modo', modo);
  fd.append('sb_token', (s && s.data && s.data.session && s.data.session.access_token) || '');
  Object.keys(extra || {}).forEach(function (k) { fd.append(k, extra[k]); });
  const res = await fetch('../fluxo.php', { method: 'POST', body: fd });
  let j = null;
  try { j = await res.json(); } catch (e) { j = null; }
  if (!j) throw new Error('O servidor não respondeu direito. Tente de novo.');
  if (!j.ok) throw new Error(j.error || 'Falha ao ler o fluxo.');
  return j;
}

function poFluxoRender() {
  const box = document.querySelector('#fluxo-lista');
  if (!box) return;
  const f = PO_FLUXOS.find(function (x) { return String(x.id) === String(PO_FLUXO_SEL); }) || PO_FLUXOS[0] || null;
  if (!f) { box.innerHTML = '<p class="fl-vazio">Nenhum fluxo encontrado.</p>'; return; }
  PO_FLUXO_SEL = f.id;
  box.innerHTML = poFluxoSeletor(PO_FLUXOS, f.id) + poFluxoLista(f);
  const s = box.querySelector('#fl-seletor');
  if (s) s.onchange = function () { PO_FLUXO_SEL = s.value; poFluxoRender(); };
}

// Entrada que o app.js chama ao abrir a aba.
async function poCarregaFluxo() {
  const box = document.querySelector('#fluxo-lista');
  if (box) box.innerHTML = '<p class="fl-vazio">Carregando o fluxo…</p>';
  try {
    const j = await poFluxoPost('ler');
    PO_FLUXOS = Array.isArray(j.fluxos) ? j.fluxos : [];
    poFluxoRender();
  } catch (e) {
    if (box) box.innerHTML = '<p class="fl-aviso">' + esc(e.message) + '</p>';
  }
}
