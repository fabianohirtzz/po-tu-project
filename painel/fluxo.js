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
  const editavel = !!def && poFluxoEditavel(c);
  const classe = 'fl-card ' + (inicial ? 'fl-card--inicial ' : '') + (def ? 'fl-card--' + def.tipo : 'fl-card--desconhecido') +
    (editavel ? ' fl-card--editavel' : '');
  return '<article class="' + classe + '" data-card="' + esc(c.id) + '">' +
    '<div class="fl-card__tipo">' + (def ? esc(def.rotulo) : 'Desconhecido') + '</div>' +
    '<h3 class="fl-card__titulo">' + esc(c.titulo || '(sem título)') + '</h3>' +
    (previa ? '<p class="fl-card__previa">' + esc(previa) + '</p>' : '') +
    aviso +
    (saidas.length ? '<ul class="fl-saidas">' + saidas.join('') + '</ul>' : '') +
    (editavel ? '<button type="button" class="fl-card__editar">Editar texto</button>' : '') +
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
  const L = 260, FOLGA = 60, POR_LINHA = 3, MARGEM = 120, DX_MAX = 100, LINHA_GRADE = 320, FOLGA_V = 40;
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
  const lugar = cards.map(function (c, i) {
    let x, y;
    if (cru[i].x === null || cru[i].y === null) {
      x = MARGEM + (i % POR_LINHA) * (L + FOLGA);
      y = MARGEM + Math.floor(i / POR_LINHA) * LINHA_GRADE;
    } else {
      x = cru[i].x - minX + MARGEM;
      y = cru[i].y - minY + MARGEM;
    }
    return { x: x, y: y, h: alturaDe(c), i: i };
  });
  /* A semente espaca 200px e o card mede de 150 a ~470: sem isto um cobriria o
     de baixo. Por coluna (mesmo left), de cima para baixo, cada card desce o
     quanto precisar para ficar FOLGA_V abaixo do anterior. Empurrar (e nao
     reespacar a semente) vale tambem quando a cliente edita um texto e o card
     cresce. Largura, altura do svg e ancoras das setas saem DAQUI. */
  const colunas = Object.create(null);
  lugar.forEach(function (p) { (colunas[p.x] = colunas[p.x] || []).push(p); });
  Object.keys(colunas).forEach(function (k) {
    const col = colunas[k].sort(function (m, n) { return m.y - n.y || m.i - n.i; });
    for (let j = 1; j < col.length; j++) col[j].y = Math.max(col[j].y, col[j - 1].y + col[j - 1].h + FOLGA_V);
  });
  const pos = Object.create(null);
  let largura = 0, altura = 0;
  lugar.forEach(function (p) {
    const c = cards[p.i];
    if (c.id != null) pos[c.id] = p;
    largura = Math.max(largura, p.x + L + MARGEM);
    altura = Math.max(altura, p.y + p.h + MARGEM);
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

/* ============================================================
   A GAVETA DO CARD. Edita EXATAMENTE QUATRO coisas: o texto, o lembrete
   ligado/desligado, o texto do lembrete e o ROTULO de cada opcao. Nada mais.
   Nao escreve identificacao de opcao, tipo, setas, posicao nem estrutura;
   criar e reestruturar fluxo nao e coisa desta tela.
   Dois motivos concretos, nao de estilo:
   - opcao com identificacao "sair", "parar" ou "descadastrar" faria o toque
     no botao DESCADASTRAR o cliente do marketing (o motor trata o id do
     botao); se a tela pudesse escrever essa identificacao, criaria o botao;
   - o card "humano" do grafo e eleito pelo primeiro card daquele tipo na
     ordem de iteracao, e o jsonb nao preserva ordem de chave.
   Por isso poFluxoPatch e poFluxoSalva NAO citam essas palavras (o teste le o
   fonte). A identificacao do card a gravar mora em poFluxoQuem, a parte.
============================================================ */

// Limite de caracteres do texto do card (espelho de WA_FLUXO_LIMITE). null = o tipo nao tem texto.
function poFluxoLimite(tipo) {
  const m = { texto: 4096, botoes: 1024, lista: 1024, pdf: 1024, humano: 4096, fim: 4096 };
  return Object.prototype.hasOwnProperty.call(m, tipo) ? m[tipo] : null;
}

function poFluxoTemTexto(tipo) {
  return poFluxoLimite(tipo) !== null;
}

// O WhatsApp aceita no maximo 3 botoes. Lista nao tem teto aqui. null = sem teto.
function poFluxoMaxOpcoes(tipo) {
  return tipo === 'botoes' ? 3 : null;
}

/* Tamanho do texto DEPOIS da expansao de pior caso: {roteiro} vale 60, {nome} 40,
   {data} 40 (espelho de WA_FLUXO_VAR_MAX). Conferir o texto cru deixaria passar
   uma frase que estoura o limite so quando o titulo do roteiro e comprido, e ai a
   Meta devolve 400 e o cliente nao recebe nada, em silencio. Variavel que o robo
   nao conhece e removida no envio, entao vale 0. Conta caracteres, nao bytes. */
function poFluxoPiorCaso(texto) {
  const max = { nome: 40, roteiro: 60, data: 40 };
  const pedacos = String(texto == null ? '' : texto).split(/(\{[a-z_]+\})/);
  let total = 0;
  pedacos.forEach(function (p) {
    const m = /^\{([a-z_]+)\}$/.exec(p);
    if (m) total += Object.prototype.hasOwnProperty.call(max, m[1]) ? max[m[1]] : 0;
    else total += Array.from(p).length;
  });
  return total;
}

// O contador: quanto o texto PODE chegar a ter, contra o limite.
function poFluxoContador(texto, limite) {
  const n = poFluxoPiorCaso(texto);
  const estoura = typeof limite === 'number' && n > limite;
  return {
    n: n, limite: limite, estoura: estoura,
    texto: n + ' de ' + limite + ' caracteres, contando o maior nome e roteiro possíveis' +
      (estoura ? '. Encurte: o WhatsApp recusa a mensagem inteira e o cliente não recebe nada.' : '.'),
  };
}

/* As opcoes com o rotulo novo, e SO o rotulo: o resto de cada opcao (a
   identificacao, o destino, a descricao) passa intacto. Mesmo tamanho e mesma
   ordem sempre: rotulo a mais e ignorado, rotulo a menos deixa a opcao como estava. */
function poFluxoOpcoesComRotulos(opcoes, rotulos) {
  let l = opcoes;
  if (typeof l === 'string') { try { l = JSON.parse(l); } catch (e) { l = []; } }
  if (!Array.isArray(l)) return [];
  const novos = Array.isArray(rotulos) ? rotulos : [];
  return l.map(function (o, i) {
    if (!o || typeof o !== 'object' || novos[i] == null) return o;
    return Object.assign({}, o, { rotulo: String(novos[i]).trim() });
  });
}

/* O que vai ao banco: SO estas quatro chaves, e so as que a gaveta ofereceu (a
   chave presente em `v`). Qualquer outra coisa que venha em `v` e ignorada. */
function poFluxoPatch(card, v) {
  const c = card && typeof card === 'object' ? card : {};
  const q = v && typeof v === 'object' ? v : {};
  const limpo = function (s) { const t = s == null ? '' : String(s).trim(); return t === '' ? null : t; };
  const patch = {};
  if ('texto' in q) patch.texto = limpo(q.texto);
  if ('lembrete_ativo' in q) patch.lembrete_ativo = q.lembrete_ativo === true;
  if ('lembrete_texto' in q) patch.lembrete_texto = limpo(q.lembrete_texto);
  if (Array.isArray(q.rotulos)) patch.opcoes = poFluxoOpcoesComRotulos(c.opcoes, q.rotulos);
  return patch;
}

// Qual linha gravar. Sem identificacao NAO existe filtro, e atualizar sem filtro reescreveria todos os cards.
function poFluxoQuem(card) {
  const k = card && card.id != null ? String(card.id).trim() : '';
  if (k === '') throw new Error('Este card não tem identificação, então não dá para salvar.');
  return { id: k };
}

// Motivo de a gaveta recusar salvar, ou '' se pode. Card de botoes com mais de 3 nao passa.
function poFluxoBloqueio(card) {
  const c = card && typeof card === 'object' ? card : {};
  const max = poFluxoMaxOpcoes(c.tipo);
  const n = poFluxoOpcoes(c.opcoes).length;
  if (max !== null && n > max) return 'Este card tem ' + n + ' botões e o WhatsApp aceita no máximo ' + max + '. Não dá para salvar assim.';
  return '';
}

/* O PATCH direto no Supabase, pela sessao da cliente (a RLS da migration
   permite), como o resto do painel. O .select() devolve a linha gravada: a RLS
   que nega nao da erro, so afeta zero linhas, e sem conferir isso a tela diria
   "salvo" sem ter salvo nada. */
async function poFluxoSalva(card, v) {
  const bloqueio = poFluxoBloqueio(card);
  if (bloqueio) throw new Error(bloqueio);
  const patch = poFluxoPatch(card, v);
  if (!Object.keys(patch).length) throw new Error('Não há nada para salvar neste card.');
  const r = await sb.from('po_wa_cards').update(patch).match(poFluxoQuem(card)).select();
  if (r.error) throw new Error('Erro ao salvar: ' + r.error.message);
  if (!r.data || !r.data.length) throw new Error('O servidor não gravou nada. Entre no painel de novo e tente outra vez.');
  return patch;
}

// Os problemas que o ENDPOINT achou (modo=conferir), so os deste card. Nada e julgado aqui.
function poFluxoProblemas(problemas, id) {
  const l = Array.isArray(problemas) ? problemas.filter(function (p) { return p && typeof p === 'object'; }) : [];
  const meus = l.filter(function (p) { return id != null && String(p.card) === String(id); });
  const outros = l.length - meus.length;
  let h = '';
  if (meus.length) {
    h += '<ul class="fl-problemas">' + meus.map(function (p) {
      return '<li class="fl-problema' + (p.bloqueia ? ' fl-problema--bloqueia' : '') + '">' + esc(p.texto) + '</li>';
    }).join('') + '</ul>';
  }
  if (outros > 0) h += '<p class="fl-aviso">E mais ' + outros + (outros === 1 ? ' aviso em outro card' : ' avisos em outros cards') + ' deste fluxo.</p>';
  if (!l.length) h = '<p class="fl-ok">O fluxo foi conferido e está sem problemas.</p>';
  return h;
}

// Pode editar na gaveta? So card com texto.
function poFluxoEditavel(card) {
  return !!card && typeof card === 'object' && poFluxoTemTexto(card.tipo);
}

function poFluxoGaveta(card) {
  const c = card && typeof card === 'object' ? card : {};
  const def = poFluxoTipo(c.tipo);
  if (!def || !poFluxoEditavel(c)) {
    return '<p class="fl-aviso">Este card não tem texto para editar aqui.</p>';
  }
  const limite = poFluxoLimite(def.tipo);
  const opcoes = poFluxoOpcoes(c.opcoes);
  const max = poFluxoMaxOpcoes(def.tipo);
  const campoTexto = function (campo, rotulo, valor, lim, linhas) {
    const ct = poFluxoContador(valor, lim);
    return '<label class="fl-rot" for="fl-g-' + campo + '">' + rotulo + '</label>' +
      '<textarea id="fl-g-' + campo + '" class="fl-campo" data-campo="' + campo + '" data-limite="' + lim + '" rows="' + linhas + '">' +
      esc(valor == null ? '' : valor) + '</textarea>' +
      '<div class="fl-cont' + (ct.estoura ? ' fl-cont--estouro' : '') + '" data-cont="' + campo + '">' + esc(ct.texto) + '</div>';
  };
  let h = '<div class="fl-gaveta__cab"><div><div class="fl-card__tipo">' + esc(def.rotulo) + '</div>' +
    '<h3 class="fl-gaveta__titulo">' + esc(c.titulo || '(sem título)') + '</h3></div>' +
    '<button type="button" class="fl-gaveta__x" data-acao="fechar" aria-label="Fechar">×</button></div>' +
    '<div class="fl-gaveta__corpo">';
  h += campoTexto('texto', def.tipo === 'pdf' ? 'Legenda do PDF' : 'Mensagem', c.texto, limite, 7);
  h += '<p class="fl-dica">Use {nome}, {roteiro} e {data} onde quiser que apareçam o nome da pessoa, o roteiro e a data.</p>';
  if (opcoes.length) {
    h += '<fieldset class="fl-opcoes"><legend>' + (def.tipo === 'botoes' ? 'Botões (no máximo ' + max + ')' : 'Linhas da lista') + '</legend>';
    if (max !== null) h += '<p class="fl-dica">' + opcoes.length + ' de ' + max + ' botões.</p>';
    if (max !== null && opcoes.length > max) {
      h += '<p class="fl-aviso">Este card tem ' + opcoes.length + ' botões e o WhatsApp aceita no máximo ' + max + '. Não dá para salvar assim.</p>';
    }
    opcoes.forEach(function (o, i) {
      h += '<input type="text" class="fl-campo" data-campo="rotulo" data-i="' + i + '" value="' + esc(o.rotulo) + '" aria-label="Texto da opção ' + (i + 1) + '">';
    });
    h += '</fieldset>';
  }
  if (def.espera) {
    h += '<label class="fl-check"><input type="checkbox" data-campo="lembrete_ativo"' + (c.lembrete_ativo ? ' checked' : '') +
      '> Lembrar a pessoa se ela não responder</label>';
    h += campoTexto('lembrete_texto', 'Mensagem do lembrete', c.lembrete_texto, 4096, 4);
  }
  h += '</div><div class="fl-gaveta__rodape"><div class="fl-gaveta__status" data-status role="status"></div>' +
    '<button type="button" class="btn-primary" data-acao="salvar">Salvar</button>' +
    '<button type="button" class="btn-ghost" data-acao="fechar">Cancelar</button></div>';
  return h;
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
let PO_FLUXO_CARD = null;

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
    '<div class="fl-vista fl-vista--canvas">' + poFluxoCanvas(f) + '</div>' +
    '<aside id="fl-gaveta" class="fl-gaveta" hidden></aside>';
  PO_FLUXO_CARD = null;
  const s = box.querySelector('#fl-seletor');
  if (s) s.onchange = function () { PO_FLUXO_SEL = s.value; poFluxoRender(); };
  box.onclick = poFluxoClique;
}

/* O data-card sai DUAS vezes no DOM (na lista e no canvas, que chamam a mesma
   funcao). Por isso o clique descobre o card pelo no que RECEBEU o clique
   (closest), nunca por querySelector('[data-card=...]'), que devolveria o no da
   vista escondida e abriria "o card errado" de vez em quando. Para marcar o card
   aberto, as DUAS ocorrencias, por querySelectorAll. */
function poFluxoClique(ev) {
  const alvo = ev && ev.target && ev.target.closest ? ev.target.closest('[data-card]') : null;
  if (!alvo) return;
  poFluxoAbre(alvo.getAttribute('data-card'));
}

function poFluxoCardAtual(id) {
  const f = PO_FLUXOS.find(function (x) { return String(x.id) === String(PO_FLUXO_SEL); });
  const cards = f && Array.isArray(f.cards) ? f.cards : [];
  return cards.find(function (c) { return c && String(c.id) === String(id); }) || null;
}

function poFluxoMarca(id) {
  document.querySelectorAll('[data-card]').forEach(function (el) {
    el.classList.toggle('fl-card--aberto', id !== null && el.getAttribute('data-card') === String(id));
  });
}

function poFluxoFecha() {
  const g = document.querySelector('#fl-gaveta');
  if (g) { g.hidden = true; g.innerHTML = ''; }
  PO_FLUXO_CARD = null;
  poFluxoMarca(null);
}

function poFluxoAbre(id) {
  const card = poFluxoCardAtual(id);
  const g = document.querySelector('#fl-gaveta');
  if (!card || !g || !poFluxoEditavel(card)) return;
  PO_FLUXO_CARD = String(id);
  g.innerHTML = poFluxoGaveta(card);
  g.hidden = false;
  poFluxoMarca(id);
  // contador ao vivo: o pior caso, a cada tecla
  g.querySelectorAll('textarea[data-limite]').forEach(function (ta) {
    ta.addEventListener('input', function () {
      const ct = poFluxoContador(ta.value, Number(ta.getAttribute('data-limite')));
      const el = g.querySelector('[data-cont="' + ta.getAttribute('data-campo') + '"]');
      if (!el) return;
      el.textContent = ct.texto;
      el.classList.toggle('fl-cont--estouro', ct.estoura);
    });
  });
  g.onclick = function (ev) {
    const b = ev.target && ev.target.closest ? ev.target.closest('[data-acao]') : null;
    if (!b) return;
    if (b.getAttribute('data-acao') === 'fechar') poFluxoFecha();
    else if (b.getAttribute('data-acao') === 'salvar') poFluxoGavetaSalva();
  };
}

function poFluxoStatus(html) {
  const el = document.querySelector('#fl-gaveta [data-status]');
  if (el) el.innerHTML = html;
}

// Le SO o que a gaveta desenhou: campo que nao foi oferecido nao entra no que vai ao banco.
function poFluxoLeGaveta(g) {
  const v = {};
  const t = g.querySelector('[data-campo="texto"]');
  if (t) v.texto = t.value;
  const la = g.querySelector('[data-campo="lembrete_ativo"]');
  if (la) v.lembrete_ativo = la.checked;
  const lt = g.querySelector('[data-campo="lembrete_texto"]');
  if (lt) v.lembrete_texto = lt.value;
  const rs = g.querySelectorAll('[data-campo="rotulo"]');
  if (rs.length) v.rotulos = Array.from(rs).map(function (i) { return i.value; });
  return v;
}

async function poFluxoGavetaSalva() {
  const id = PO_FLUXO_CARD;
  const g = document.querySelector('#fl-gaveta');
  const card = id === null ? null : poFluxoCardAtual(id);
  const f = PO_FLUXOS.find(function (x) { return String(x.id) === String(PO_FLUXO_SEL); });
  if (!card || !g || !f) return;
  const botao = g.querySelector('[data-acao="salvar"]');
  if (botao) botao.disabled = true;
  poFluxoStatus('Salvando…');
  let patch;
  try {
    patch = await poFluxoSalva(card, poFluxoLeGaveta(g));
  } catch (e) {
    if (botao) botao.disabled = false;
    poFluxoStatus('<span class="fl-aviso">' + esc(e.message) + '</span>');
    return;
  }
  Object.assign(card, patch);
  f.publicado_igual = false;
  poFluxoRender();
  poFluxoAbre(id);
  poFluxoStatus('Salvo. Conferindo o fluxo…');
  try {
    // quem julga (variavel desconhecida, limite, botoes) e o endpoint, nunca esta tela
    const j = await poFluxoPost('conferir', { fluxo_id: f.id });
    if (PO_FLUXO_CARD !== String(id)) return;
    poFluxoStatus('Salvo. ' + poFluxoProblemas(j.problemas, id));
  } catch (e) {
    if (PO_FLUXO_CARD !== String(id)) return;
    poFluxoStatus('Salvo, mas não consegui conferir o fluxo: ' + esc(e.message));
  }
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
