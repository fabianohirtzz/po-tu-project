/* ============================================================
   Simulador do fluxo: a conversa de teste, dentro do painel.

   A Lais percorre o atendimento inteiro aqui, sem mandar mensagem para
   ninguem. Quem roda o grafo e o SERVIDOR (fluxo.php modo=simular ->
   lib/wa-sim.php), com o motor de verdade e as funcoes de envio reais atras
   de um transporte falso. Esta tela nao decide nada do caminho: ela manda o
   que a pessoa fez e desenha o que voltou.

   E deliberado. Se a tela decidisse para onde ir, divergiria do motor no
   primeiro caso em que o cliente responde algo que o card nao espera, e o
   simulador deixaria de servir justamente para o que ele existe: mostrar o
   que o cliente vai receber.

   Por isso o arquivo nao conhece saida de card, nem contador de erro, nem
   lista de tipos, e os nomes dessas coisas nao aparecem nem em comentario: a
   trava do tests/test-simulador.mjs e busca de substring, que e o que a torna
   impossivel de burlar.

   O estado da conversa vive aqui no navegador e vai inteiro em cada passo: o
   servidor nao guarda linha nenhuma. O `ctx` trafega OPACO - a tela nunca le
   dentro dele.

   ATENCAO: o esc() da casa (app.js) NAO escapa a aspa simples. Todo atributo
   emitido aqui usa aspas DUPLAS, sempre.
============================================================ */

let PO_SIM = null;
let PO_SIM_OCUPADO = false;

/* ---------- as funcoes puras (o teste as extrai do fonte) ---------- */

/* Uma mensagem do envelope -> a bolha do robo. O envelope vem do payload que a
   Meta receberia, entao e isto que o cliente leria, ja truncado pelas funcoes
   de envio reais. */
function poSimBolha(m) {
  const x = m && typeof m === 'object' ? m : {};
  const tipo = String(x.tipo || 'text');
  const bot = Array.isArray(x.botoes) ? x.botoes : [];
  const itens = Array.isArray(x.itens) ? x.itens : [];
  let h = '<div class="sim-bolha sim-bolha--robo">';
  /* Documento: o NOME do arquivo, nunca o PDF embutido. A conversa de teste
     mostra que o roteiro seria enviado; abrir o arquivo e outra tela. */
  if (tipo === 'document') {
    h += '<p class="sim-doc">Enviaria o arquivo <strong>' + esc(x.arquivo || '') + '</strong></p>';
  }
  if (x.texto) h += '<p class="sim-txt">' + esc(x.texto) + '</p>';
  if (tipo === 'buttons' && bot.length) {
    h += '<div class="sim-ops">';
    bot.forEach(function (b) {
      const o = b && typeof b === 'object' ? b : {};
      h += '<button type="button" class="sim-op" data-sim="responde" data-id="' +
        esc(o.id || '') + '">' + esc(o.rotulo || '') + '</button>';
    });
    h += '</div>';
  }
  if (tipo === 'list' && itens.length) {
    h += '<ul class="sim-itens">';
    itens.forEach(function (i) {
      const t = i && typeof i === 'object' ? i : {};
      h += '<li><button type="button" class="sim-item" data-sim="responde" data-id="' +
        esc(t.id || '') + '"><span class="sim-item__t">' + esc(t.titulo || '') + '</span>' +
        (t.descricao ? '<span class="sim-item__d">' + esc(t.descricao) + '</span>' : '') +
        '</button></li>';
    });
    h += '</ul>';
  }
  return h + '</div>';
}

/* A fala do cliente. Fica do outro lado, com outra cor: a conversa tem que
   parecer o WhatsApp para ela reconhecer o que esta vendo. */
function poSimEu(texto) {
  return '<div class="sim-bolha sim-bolha--eu"><p class="sim-txt">' + esc(texto == null ? '' : texto) + '</p></div>';
}

/* O que a bolha do cliente MOSTRA. Para o servidor vai o id da opcao; na tela
   tem que aparecer o rotulo que ela clicou, senao a conversa de teste mostra
   nome tecnico que o cliente de verdade nunca veria. */
function poSimFala(resposta, rotulo) {
  const r = String(rotulo == null ? '' : rotulo).trim();
  return r !== '' ? r : String(resposta == null ? '' : resposta);
}

/* O estado -> o que a cliente le. Nenhum nome tecnico na tela. */
function poSimSelo(estado) {
  const e = String(estado || '');
  if (e === 'aguardando') return 'Esperando o cliente responder';
  if (e === 'humano') return 'Entregaria a conversa para você';
  if (e === 'encerrado') return 'Conversa encerrada';
  if (e === 'recusado') return 'Não sairia do jeito que está';
  if (e === 'teto_passos') return 'Interrompida: cards demais sem esperar o cliente';
  return 'Estado que eu não reconheço';
}

/* A faixa das diferencas entre o simulador e o ar. Sem aviso, sem faixa: ela
   so aparece quando ha algo a dizer. */
function poSimAvisos(lista) {
  const l = Array.isArray(lista) ? lista.filter(function (x) { return x; }) : [];
  if (!l.length) return '';
  return '<div class="sim-avisos">' + l.map(function (a) {
    return '<p>' + esc(a) + '</p>';
  }).join('') + '</div>';
}

/* Comeco de conversa: sem card (o servidor parte do inicial do fluxo) e sem
   contexto. Cada chamada devolve um objeto novo, para "Comecar de novo" nao
   herdar nada do anterior. */
function poSimEstadoInicial(fluxoId) {
  return { fluxo_id: String(fluxoId == null ? '' : fluxoId), fluxo_nome: '',
    card_id: null, ctx: {}, estado: '', erro: null, avisos: [], linhas: [] };
}

/* O painel inteiro, a partir do estado. O campo de texto existe sempre que a
   conversa espera, porque o publico 60+ digita - e todo card que espera tem
   caminho para quem digita. Quando a conversa nao anda (recusa, encerrada,
   entregue), o campo sai: oferecer um campo que nao leva a nada mente. */
function poSimPainel(st) {
  const s = st && typeof st === 'object' ? st : poSimEstadoInicial('');
  const linhas = Array.isArray(s.linhas) ? s.linhas : [];
  const espera = String(s.estado || '') === 'aguardando';
  /* Sem nenhuma bolha (a recusa acontece ANTES do primeiro envio) a caixa
     viraria um retangulo cinza do tamanho da tela, que nao explica nada. */
  const corpo = linhas.length
    ? linhas.map(function (l) {
      const x = l && typeof l === 'object' ? l : {};
      return x.lado === 'eu' ? poSimEu(x.texto) : poSimBolha(x.m);
    }).join('')
    : '<p class="sim-vazio">Nenhuma mensagem sairia neste ponto.</p>';
  const selo = s.estado ? '<span class="sim-selo sim-selo--' + esc(s.estado) + '">' + esc(poSimSelo(s.estado)) + '</span>' : '';
  const erro = s.erro ? '<p class="sim-erro"><strong>Motivo:</strong> ' + esc(s.erro) + '</p>' : '';
  return '<header class="sim__cab">' +
    '<div><p class="sim__tit">Simulação</p>' +
    '<p class="sim__sub">' + esc(s.fluxo_nome || '') + '</p></div>' +
    '<button type="button" class="sim__x" data-sim="fecha" aria-label="Fechar a simulação">&times;</button>' +
    '</header>' +
    '<p class="sim__nota">Esta conversa é de teste. Nada é enviado para ninguém e nada é gravado.</p>' +
    poSimAvisos(s.avisos) +
    '<div class="sim-conversa" id="sim-conversa">' + corpo + '</div>' +
    selo + erro +
    (espera
      ? '<form class="sim-form" data-sim="digita"><input type="text" class="sim-campo" name="t" ' +
        'placeholder="Escreva como o cliente" autocomplete="off"><button type="submit" class="btn btn-primary">Enviar</button></form>'
      : '') +
    /* O rodape e um FORMULARIO, e o campo da primeira frase e OPCIONAL. O
       atalho do card de entrada so dispara na PRIMEIRA mensagem, entao sem
       este campo o caminho mais usado dos tres - o cliente que chega
       escrevendo "quero saber do Chile" - seria o unico que a cliente nao
       conseguiria testar aqui.
       Um controle so, e nao dois: vazio ele recomeca como sempre recomecou,
       escrito ele recomeca com a frase. Dois botoes obrigariam a cliente a
       escolher antes de saber a diferenca.
       Ele aparece mesmo com a conversa parada, ao contrario do campo de
       resposta: recomecar com outra frase e justamente o que se faz depois de
       a conversa ter terminado. */
    '<form class="sim__rodape" data-sim="recomeca">' +
    '<input type="text" class="sim-primeira" name="p" ' +
    'placeholder="Primeira frase do cliente (opcional)" autocomplete="off" ' +
    'aria-label="Primeira frase do cliente, opcional">' +
    '<button type="submit" class="btn btn-ghost">Começar de novo</button>' +
    '</form>';
}

/* ---------- a tela ---------- */

function poSimCaixa() {
  return document.querySelector('#sim-painel');
}

function poSimDesenha() {
  const box = poSimCaixa();
  if (!box) return;
  box.innerHTML = poSimPainel(PO_SIM);
  box.hidden = !PO_SIM;
  if (!PO_SIM) return;
  // a conversa cresce para baixo: a ultima bolha e a que interessa
  const c = box.querySelector('#sim-conversa');
  if (c) c.scrollTop = c.scrollHeight;
  const campo = box.querySelector('.sim-campo');
  if (campo) campo.focus();
}

/* Um passo. Manda o estado inteiro e desenha o que voltou. `clicou` separa o
   clique do texto digitado, e essa diferenca e do motor: em card de botoes so
   o clique vale como escolha. */
async function poSimPasso(resposta, clicou, rotulo) {
  if (!PO_SIM || PO_SIM_OCUPADO) return;
  PO_SIM_OCUPADO = true;
  const envia = String(resposta == null ? '' : resposta);
  if (envia !== '') PO_SIM.linhas.push({ lado: 'eu', texto: poSimFala(envia, rotulo) });
  poSimDesenha();
  const r = await poFluxoChama('simular', {
    fluxo_id: PO_SIM.fluxo_id,
    card_id: PO_SIM.card_id == null ? '' : PO_SIM.card_id,
    resposta: envia,
    clicou: clicou ? '1' : '',
    ctx: JSON.stringify(PO_SIM.ctx || {})
  });
  PO_SIM_OCUPADO = false;
  /* Erro de rede ou do servidor vira AVISO e nao apaga a conversa da tela: ela
     acabou de ler as bolhas, e limpa-las pareceria que o fluxo quebrou. */
  if (!r.j || !r.j.ok) {
    PO_SIM.avisos = [(r.j && r.j.error) || r.erro || 'Não consegui simular agora. Tente de novo.'];
    poSimDesenha();
    return;
  }
  const p = r.j.passo && typeof r.j.passo === 'object' ? r.j.passo : {};
  PO_SIM.fluxo_id = p.fluxo_id || PO_SIM.fluxo_id;
  PO_SIM.fluxo_nome = r.j.fluxo_nome || PO_SIM.fluxo_nome;
  PO_SIM.card_id = p.card_id == null ? null : p.card_id;
  PO_SIM.ctx = p.ctx && typeof p.ctx === 'object' ? p.ctx : {};
  PO_SIM.estado = p.estado || '';
  PO_SIM.erro = p.erro || null;
  PO_SIM.avisos = Array.isArray(p.avisos) ? p.avisos : [];
  (Array.isArray(p.mensagens) ? p.mensagens : []).forEach(function (m) {
    PO_SIM.linhas.push({ lado: 'robo', m: m });
  });
  poSimDesenha();
}

/* `frase` e a primeira mensagem do cliente, e vazia e o caso comum (a cliente
   toca Simular e quer ver as boas-vindas). Ela vai como resposta do primeiro
   passo, que e onde o servidor avalia os atalhos do card de entrada. */
async function poSimAbre(fluxoId, frase) {
  PO_SIM = poSimEstadoInicial(fluxoId);
  const f = (typeof PO_FLUXOS !== 'undefined' && Array.isArray(PO_FLUXOS))
    ? PO_FLUXOS.find(function (x) { return String(x.id) === String(fluxoId); }) : null;
  if (f) PO_SIM.fluxo_nome = f.nome || '';
  poSimDesenha();
  const p = String(frase == null ? '' : frase).trim();
  await poSimPasso(p, false);
}

function poSimFecha() {
  PO_SIM = null;
  const box = poSimCaixa();
  if (box) { box.innerHTML = ''; box.hidden = true; }
}

/* O clique e delegado na caixa, que o poFluxoRender repinta: handler ligado em
   botao morreria no primeiro redesenho. */
function poSimClique(ev) {
  const t = ev && ev.target && ev.target.closest ? ev.target.closest('[data-sim]') : null;
  if (!t) return;
  const acao = t.getAttribute('data-sim');
  if (acao === 'fecha') { poSimFecha(); return; }
  /* O recomecar NAO e tratado aqui: ele virou submit, e um botao de submit
     dispara click e submit. Tratar nos dois recomecaria duas vezes, e a
     primeira seria sem a frase que a cliente acabou de escrever. */
  if (acao === 'responde') { poSimPasso(t.getAttribute('data-id') || '', true, t.textContent || ''); return; }
}

/* Os DOIS formularios passam por aqui, e a diferenca entre eles e o que a
   cliente espera: o de cima responde no ponto em que a conversa parou, o de
   baixo joga a conversa fora e recomeca. */
function poSimEnvia(ev) {
  const f = ev && ev.target && ev.target.closest ? ev.target.closest('[data-sim]') : null;
  if (!f) return;
  const acao = f.getAttribute('data-sim');
  if (acao === 'recomeca') {
    ev.preventDefault();
    const c = f.querySelector('.sim-primeira');
    const frase = c ? String(c.value || '').trim() : '';
    poSimAbre(PO_SIM ? PO_SIM.fluxo_id : '', frase);
    return;
  }
  if (acao !== 'digita') return;
  ev.preventDefault();
  const campo = f.querySelector('.sim-campo');
  const v = campo ? String(campo.value || '').trim() : '';
  if (v === '') return;
  if (campo) campo.value = '';
  poSimPasso(v, false);
}

function poSimLiga() {
  const box = poSimCaixa();
  if (!box) return;
  box.onclick = poSimClique;
  box.onsubmit = poSimEnvia;
  /* O poFluxoRender recria esta caixa VAZIA e escondida, e ele roda depois de
     salvar um card e depois de publicar. Religar o clique sem redesenhar deixa
     o handler vivo num elemento vazio: a conversa continua em PO_SIM e a
     cliente ve o simulador ter sumido sozinho, logo depois de ir corrigir o
     card que ela viu aqui. */
  if (PO_SIM) poSimDesenha();
}
