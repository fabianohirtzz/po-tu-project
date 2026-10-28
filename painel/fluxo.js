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

   ATENCAO: o esc() da casa (app.js) NAO escapa a aspa simples. Todo atributo
   HTML emitido aqui usa aspas DUPLAS, sempre. Com aspa simples o texto da
   cliente fecharia o atributo (a mesma classe de bug do po_css_url() no PHP).
   Vale em dobro para style="..." e atributos de svg.
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
function poFluxoCard(card, porId, inicial) {
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
  // Lista dinamica (fonte = roteiros): o proximo so vale DEPOIS de a pessoa escolher.
  let cfg = c.config;
  if (typeof cfg === 'string') { try { cfg = JSON.parse(cfg); } catch (e) { cfg = null; } }
  const dinamica = c.tipo === 'lista' && !!cfg && typeof cfg === 'object' && cfg.fonte === 'roteiros';
  if (c.proximo) saidas.push(saida(dinamica ? 'Depois de escolher' : 'Depois', c.proximo));
  if (c.fallback) saidas.push(saida('Não entendeu', c.fallback));
  if (c.sem_resposta) saidas.push(saida('Sem resposta', c.sem_resposta));

  let aviso = '';
  if (!def) aviso = '<p class="fl-aviso">Tipo de card desconhecido: ' + esc(c.tipo == null ? '(vazio)' : c.tipo) + '. O robô não sabe executá-lo.</p>';
  else if (c.tipo === 'botoes' && !opcoes.length) aviso = '<p class="fl-aviso">Este card de botões está sem opções.</p>';

  const previa = poFluxoPrevia(c.texto);
  const classe = 'fl-card ' + (inicial ? 'fl-card--inicial ' : '') + (def ? 'fl-card--' + def.tipo : 'fl-card--desconhecido');
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
    return poFluxoCard(c, porId, c.id != null && c.id === f.card_inicial);
  }).join('') + '</div>';
}

/* O canvas do desktop: os mesmos cards, cada um no lugar (x, y) que a semente
   gravou, e um <svg> por baixo com UM segmento por seta. Saida nula ou para
   card que nao existe NAO vira segmento: a seta apontaria para o canto da
   tela e a cliente leria uma ligacao que nao existe.

   x/y PODEM SER NEGATIVOS (a semente grava uma faixa superior com y < 0). Por
   isso o desenho inteiro e DESLOCADO (x - minimo + margem), nunca cortado em
   zero: cortar empilharia cards de y diferente na mesma posicao e esconderia
   um atras do outro, sem erro nenhum.
   x/y que nao sao numero finito (ausente, texto, NaN), ou so um dos dois,
   caem numa grade derivada da ordem: um NaN num style="" apaga o card da tela.

   A altura do no e ESTIMADA pelo conteudo (o JS nao mede DOM): o card mostra
   TODAS as saidas, porque a seta nao tem rotulo. Estimativa generosa de
   proposito. As setas saem e chegam no meio da altura. O controle da curva
   fica a no maximo DX_MAX do card e a margem e maior que isso, entao a curva
   toda cabe no svg (seta "para tras" nao e clipada).
   As medidas moram AQUI DENTRO (constante de topo quebraria o teste). Quem
   mostra isto e quem esconde a lista e o CSS (@media 980px), nao o JS. */
function poFluxoCanvas(fluxo) {
  const f = fluxo && typeof fluxo === 'object' ? fluxo : {};
  const cards = Array.isArray(f.cards) ? f.cards.filter(function (c) { return c && typeof c === 'object'; }) : [];
  if (!cards.length) return '<p class="fl-vazio">Este fluxo ainda não tem cards.</p>';
  const L = 260, FOLGA = 60, POR_LINHA = 3, MARGEM = 120, DX_MAX = 100, LINHA_GRADE = 320;
  const num = function (v) {
    if (typeof v === 'string' && v.trim() !== '') v = Number(v);
    return typeof v === 'number' && isFinite(v) ? Math.round(v) : null;
  };
  const porId = Object.create(null);
  cards.forEach(function (c) { if (c.id != null) porId[c.id] = c; });

  // altura estimada: cabecalho + titulo + previa + aviso + UMA linha por saida (ou duas se o texto for longo)
  const alturaDe = function (c) {
    const saidas = poFluxoOpcoes(c.opcoes).map(function (o) {
      return String(o.rotulo || '(sem nome)').length + 12 + (o.destino && porId[o.destino] ? String(porId[o.destino].titulo || '').length : 12);
    });
    [c.proximo, c.fallback, c.sem_resposta].forEach(function (d) {
      if (d != null && d !== '') saidas.push(16 + (porId[d] ? String(porId[d].titulo || '').length : 24));
    });
    let h = 28 + 20 + 28 * Math.ceil(String(c.titulo || '(sem título)').length / 20);
    const previa = poFluxoPrevia(c.texto);
    if (previa) h += 8 + 20 * Math.ceil(previa.length / 30);
    h += 44;
    if (saidas.length) h += 20 + saidas.reduce(function (s, n) { return s + 22 * Math.ceil(n / 30); }, 0);
    return Math.max(150, h);
  };

  // 1) posicao crua; o deslocamento sai do minimo dos cards que TEM posicao valida
  const cru = cards.map(function (c) { return { x: num(c.x), y: num(c.y) }; });
  let minX = null, minY = null;
  cru.forEach(function (p) {
    if (p.x === null || p.y === null) return;
    minX = minX === null ? p.x : Math.min(minX, p.x);
    minY = minY === null ? p.y : Math.min(minY, p.y);
  });
  const pos = Object.create(null);
  let largura = 0, altura = 0;
  const lugar = cards.map(function (c, i) {
    let x, y;
    if (cru[i].x === null || cru[i].y === null) {
      x = MARGEM + (i % POR_LINHA) * (L + FOLGA);
      y = MARGEM + Math.floor(i / POR_LINHA) * LINHA_GRADE;
    } else {
      x = cru[i].x - minX + MARGEM;
      y = cru[i].y - minY + MARGEM;
    }
    const h = alturaDe(c);
    if (c.id != null) pos[c.id] = { x: x, y: y, h: h };
    largura = Math.max(largura, x + L + MARGEM);
    altura = Math.max(altura, y + h + MARGEM);
    return { x: x, y: y, h: h };
  });
  // 2) os nos, ja com o mapa completo
  const nos = cards.map(function (c, i) {
    return '<div class="fl-no" style="left:' + lugar[i].x + 'px;top:' + lugar[i].y + 'px;width:' + L + 'px;height:' + lugar[i].h + 'px">' +
      poFluxoCard(c, porId, c.id != null && c.id === f.card_inicial) + '</div>';
  });

  const setas = [];
  cards.forEach(function (c) {
    if (c.id == null) return;
    const destinos = [];
    poFluxoOpcoes(c.opcoes).forEach(function (o) { destinos.push(o.destino); });
    destinos.push(c.proximo, c.fallback, c.sem_resposta);
    destinos.filter(function (d) {
      return d != null && d !== '' && Object.prototype.hasOwnProperty.call(pos, d);
    }).forEach(function (d) {
      const de = pos[c.id], para = pos[d];
      const x1 = de.x + L, y1 = Math.round(de.y + de.h / 2);
      const x2 = para.x, y2 = Math.round(para.y + para.h / 2);
      const dx = Math.min(DX_MAX, Math.max(40, Math.round(Math.abs(x2 - x1) / 2)));
      setas.push('<path class="fl-seta" data-de="' + esc(c.id) + '" data-para="' + esc(d) + '" d="M' + x1 + ' ' + y1 +
        ' C' + (x1 + dx) + ' ' + y1 + ' ' + (x2 - dx) + ' ' + y2 + ' ' + x2 + ' ' + y2 + '" marker-end="url(#fl-ponta)"/>');
    });
  });

  return '<div class="fl-canvas" style="width:' + largura + 'px;height:' + altura + 'px">' +
    '<svg class="fl-setas" width="' + largura + '" height="' + altura + '" viewBox="0 0 ' + largura + ' ' + altura + '" aria-hidden="true">' +
    '<defs><marker id="fl-ponta" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="8" markerHeight="8" orient="auto">' +
    '<path d="M0 0 L10 5 L0 10 z" class="fl-ponta"/></marker></defs>' + setas.join('') + '</svg>' +
    nos.join('') + '</div>';
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
  /* As duas vistas saem no HTML; o CSS mostra a lista no celular e o canvas a
     partir de 980px (fluxo.css). Nao ha JavaScript lendo largura de tela. */
  box.innerHTML = poFluxoSeletor(PO_FLUXOS, f.id) +
    '<div class="fl-vista fl-vista--lista">' + poFluxoLista(f) + '</div>' +
    '<div class="fl-vista fl-vista--canvas">' + poFluxoCanvas(f) + '</div>';
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
