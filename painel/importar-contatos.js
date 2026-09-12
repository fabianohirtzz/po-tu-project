/* ============================================================
   Aba Importar contatos: sobe .vcf/.csv (agenda dos dois aparelhos), manda
   para contatos-importar.php em modo=preview e MOSTRA a auditoria de fusao
   antes de qualquer escrita — so depois de o usuario ver e confirmar, o
   botao chama modo=aplicar.

   A origem 'crm-toninho' NAO e oferecida nesta tela (o endpoint continua
   aceitando: a lista fechada do PHP e trava de servidor, nao menu). Ela
   dispensa o filtro do marcador "PO" e grava revisado=true/cliente=true,
   entao escolhe-la por engano com um .vcf de agenda marcaria medico,
   dentista e fornecedor como clientes revisados, prontos para disparo
   pago. A carga do CRM foi de uso unico, por linha de comando, e ja
   aconteceu (scripts/importa-crm.php).

   Por que essa tela existe (spec 8.1/9.5): a agenda do celular NAO e lista
   de consentimento — tem medico, dentista, fornecedor, familia no meio.
   "Contato nao revisado nunca entra em campanha" e defesa obrigatoria, e
   esta tela e onde a cliente ve o que vai acontecer ANTES de acontecer:
   quantos entram novos, quantos completam ficha existente, e quantos ela
   nao consegue decidir sozinha. Nada e' gravado sem esse aviso na frente.

   Dado de terceiro e' sempre escapado (esc, ja existe em app.js): o nome
   vem da agenda do celular de outra pessoa.

   As duas funcoes puras abaixo (poResumoTexto, poLinhaRevisao) sao
   testadas por extracao de fonte via regex (tests/test-importar-
   contatos.mjs, mesmo padrao de test-clientes.mjs) — por isso nenhuma
   delas depende de constante declarada no topo do arquivo.
============================================================ */

/* Frase legivel do resumo do preview (spec 9.5): diz quantos entram novos,
   quantos completam ficha existente (e por qual identificador) e quantos
   precisam de revisao humana — TUDO antes de qualquer escrita. */
function poResumoTexto(resumo) {
  var r = resumo || {};
  var novos = r.novos || 0;
  var funde = r.funde || 0;
  var revisar = r.revisar || 0;
  var porCpf = r.por_cpf || 0;
  var porCelular = r.por_celular || 0;
  var porNomeNasc = r.por_nome_nasc || 0;
  var fundeTxt = funde === 1 ? ' vai completar uma ficha que ja existe' : ' vao completar fichas que ja existem';
  return novos + (novos === 1 ? ' contato novo' : ' contatos novos') + ', ' +
    funde + fundeTxt + ' (' + porCpf + ' por CPF, ' + porCelular + ' por celular), e ' +
    revisar + ' para voce revisar antes de entrar' +
    (porNomeNasc ? ' (' + porNomeNasc + ' por nome e nascimento)' : '') + '.';
}

/* Uma linha da tabela de revisao. 'revisar' chega em TRES sabores
   distinguiveis por match_por + presenca de match_id, e cada um pede uma
   acao diferente da cliente — um texto so ("precisa revisar") deixaria
   dois deles sem instrucao nenhuma:

   1) match_por='nome_nasc'            -> palpite: so bateu nome+nascimento,
      pode ser homonimo. Decisao humana: e' a mesma pessoa ou nao.
   2) match_por='cpf'|'celular' COM id -> disputa: outro contato deste MESMO
      arquivo ja reservou essa ficha (dois candidatos casaram no mesmo
      lead). Nao e' palpite, e' concorrencia dentro do proprio lote.
   3) match_por='cpf'|'celular' SEM id -> erro de leitura: a linha da base
      veio sem id utilizavel. Nao e' decisao do usuario, e' problema
      tecnico (avisar quem cuida do sistema), nada foi alterado por causa
      disso. */
function poLinhaRevisao(item) {
  var it = item || {};
  var por = it.match_por || '';
  var temId = it.match_id !== null && it.match_id !== undefined && it.match_id !== '';
  var classe, rotulo, motivo;
  if (por === 'nome_nasc') {
    classe = 'rv-palpite';
    rotulo = 'Palpite por nome e nascimento';
    motivo = 'Bateu so o nome e a data de nascimento com uma ficha ja existente — pode ser homonimo. ' +
      'Confirme por fora do importador se e a mesma pessoa antes de juntar as duas fichas.';
  } else if ((por === 'cpf' || por === 'celular') && temId) {
    classe = 'rv-disputa';
    rotulo = 'Duas entradas do arquivo, uma ficha so';
    motivo = 'Outro contato deste mesmo arquivo ja preencheu essa ficha por ' + esc(por) + '. ' +
      'Para nao gravar duas informacoes diferentes na mesma ficha, so o primeiro foi aplicado; ' +
      'confira manualmente qual dado esta correto.';
  } else if (por === 'cpf' || por === 'celular') {
    classe = 'rv-erro';
    rotulo = 'Erro de leitura da base';
    motivo = 'A ficha encontrada na base veio sem identificador. Isto e um problema tecnico, ' +
      'nao uma decisao sua — avise o suporte tecnico. Nada foi alterado por causa disso.';
  } else {
    classe = 'rv-outro';
    rotulo = 'Precisa de revisao';
    motivo = 'Nao foi possivel casar este contato automaticamente.';
  }
  return '<tr class="' + classe + '">' +
    '<td><div class="c-name">' + esc(it.nome) + '</div></td>' +
    '<td class="mono">' + esc(por || '—') + '</td>' +
    '<td><div class="rv-rotulo">' + esc(rotulo) + '</div><div class="rv-motivo">' + esc(motivo) + '</div></td>' +
    '</tr>';
}

/* A spec 9.5 exige que as fusoes sejam VISTAS, nao adivinhadas: a cliente
   autoriza a escrita, entao ela precisa ver o que cada fusao vai preencher.
   A resposta do preview ja traz 'preenche' (lista de nomes de coluna,
   array_keys no PHP) para cada item com acao='funde' — ate esta task a
   tela filtrava so 'revisar' e descartava todo 'funde' em silencio.
   O rotulo legivel fica DENTRO da funcao de proposito — o teste extrai a
   funcao do fonte por regex e uma constante de topo de arquivo daria
   ReferenceError (ja aconteceu tres vezes neste projeto). */
function poLinhaFusao(item) {
  var it = item || {};
  var rotulos = {
    telefone: 'telefone', telefone_secundario: 'telefone fixo', email: 'e-mail',
    nome: 'nome', cpf: 'CPF', rg: 'RG', data_nascimento: 'nascimento',
    nacionalidade: 'nacionalidade', estado_civil: 'estado civil', profissao: 'profissao',
    cep: 'CEP', endereco: 'endereco', numero: 'numero', complemento: 'complemento',
    bairro: 'bairro', cidade: 'cidade', estado: 'estado',
    passaporte_numero: 'passaporte', passaporte_orgao_emissor: 'orgao do passaporte',
    passaporte_emissao: 'emissao do passaporte', passaporte_validade: 'validade do passaporte',
    contato_emergencia_nome: 'contato de emergencia',
    contato_emergencia_telefone: 'telefone de emergencia',
    contato_emergencia_parentesco: 'parentesco de emergencia',
    observacoes: 'observacoes', origem_manual: 'origem',
  };
  var campos = Array.isArray(it.preenche) ? it.preenche : [];
  var por = it.match_por === 'cpf' ? 'CPF' : (it.match_por === 'celular' ? 'celular' : (it.match_por || ''));
  var lista = campos.length
    ? campos.map(function (c) { return '<span class="ic-campo">' + esc(rotulos[c] || c) + '</span>'; }).join(' ')
    : '<span class="ic-campo ic-campo--vazio">nenhum campo a preencher</span>';
  return '<tr>' +
    '<td><div class="c-name">' + esc(it.nome) + '</div></td>' +
    '<td class="mono">' + esc(por) + '</td>' +
    '<td>' + lista + '</td>' +
    '</tr>';
}

/* Decide se o POST de aplicar leva forcar=1 (ruling A da Task 2, sobre o
   plano 3.1 desta branch). A guarda de 409 do contatos-importar.php tem
   lease de 10 minutos e, sem esta caixa, uma importacao que morre no meio
   bloqueava a cliente ate o lease expirar, SEM escapatoria pela interface.
   A caixa "importar novamente" e escape hatch, nao fluxo normal: comeca
   desmarcada, e so o aplicar pode mandar forcar — o preview NUNCA manda
   (a guarda de servidor ja nao bloqueia preview de proposito, entao
   mandar seria so ruido), mesmo que a caixa esteja marcada por acidente. */
function poIcForcarEnvio(modo, marcado) {
  return modo === 'aplicar' && marcado === true;
}

/* Interpreta 'lote_registrado' da resposta do aplicar (ruling B da Task 2).
   Quando vem false, os contatos FORAM gravados mas o registro do lote nao
   fechou — e sem esse registro uma segunda importacao do mesmo arquivo
   passa pela guarda de novo e duplica a base. Nao e erro (a importacao deu
   certo): e aviso, para a cliente conferir antes de tentar de novo. */
function poIcAvisoLote(resp) {
  if (!resp || resp.lote_registrado !== false) return '';
  return 'Importado, mas nao consegui registrar este arquivo. Confira antes de importar de novo.';
}

/* Decide quais origem/tipo vao no POST (fix round 1, achado Critical): no
   preview, os que estao no formulario NESTE INSTANTE; no aplicar, os que
   foram AUDITADOS na ultima chamada de preview bem-sucedida, NUNCA os que
   estao no formulario agora. Sem isto, trocar o seletor de origem depois
   de ver a auditoria (sem trocar o arquivo, que e o unico gatilho que
   limpava o estado) fazia o aplicar gravar uma origem diferente da que a
   cliente viu no resumo — e origem='crm-toninho' liga revisado=true sem a
   pessoa nunca ter passado pela revisao humana (regra obrigatoria da spec
   8.1). Fica pura e testavel para travar esse invariante sem precisar de
   DOM: "o que e aplicado e exatamente o que foi auditado". */
function poIcCamposEnvio(modo, auditado, atual) {
  var at = atual || {};
  if (modo === 'aplicar' && auditado) {
    return { origem: auditado.origem, tipo: auditado.tipo };
  }
  return { origem: at.origem || '', tipo: at.tipo || 'vcf' };
}

/* ============================================================
   Cascas de DOM (nao testadas: so orquestram fetch + as funcoes puras
   acima). Reusa $, $$, esc, toast e o cliente `sb` que ja existem em
   app.js — nenhum deles e redeclarado aqui.
============================================================ */
(function () {
  var icArquivo = null;    // File selecionado, guardado ate o preview/aplicar
  var icPreview = null;    // resposta de modo=preview, guardada p/ o aplicar reusar a msg de confirmacao
  var icAuditado = null;   // {origem,tipo} usados na ULTIMA auditoria bem-sucedida; e o que o aplicar reenvia

  /* Nao existe um sbToken()/getToken() dedicado no painel: todo lugar que
     precisa do token (importador de roteiro, upload de video/pdf) chama
     sb.auth.getSession() na hora. Segue o mesmo padrao aqui. */
  async function poIcToken() {
    var s = await sb.auth.getSession();
    var session = s && s.data ? s.data.session : null;
    return session && session.access_token ? session.access_token : '';
  }

  async function poIcEnvia(modo) {
    var origemEl = document.querySelector('#ic-origem');
    var tipoEl = document.querySelector('#ic-tipo');
    var forcarEl = document.querySelector('#ic-forcar');
    var atual = { origem: (origemEl && origemEl.value) || '', tipo: (tipoEl && tipoEl.value) || 'vcf' };
    // poIcCamposEnvio e o unico lugar que decide isto: em modo=aplicar ela
    // ignora `atual` e devolve icAuditado, entao um select trocado depois
    // do preview nunca vaza para o POST de aplicar.
    var campos = poIcCamposEnvio(modo, icAuditado, atual);
    var fd = new FormData();
    fd.append('arquivo', icArquivo);
    fd.append('tipo', campos.tipo);
    fd.append('origem', campos.origem);
    fd.append('modo', modo);
    // poIcForcarEnvio e quem decide isto: so o aplicar manda forcar, e so
    // quando a caixa "importar novamente" estiver marcada. O preview nunca
    // manda, mesmo que a caixa esteja marcada por acidente.
    if (poIcForcarEnvio(modo, !!(forcarEl && forcarEl.checked))) fd.append('forcar', '1');
    fd.append('sb_token', await poIcToken());
    var res = await fetch('../contatos-importar.php', { method: 'POST', body: fd });
    var j = null;
    try { j = await res.json(); } catch (e) { /* corpo nao-JSON: j fica null, cai no erro abaixo */ }
    if (!res.ok || !j || !j.ok) {
      throw new Error((j && j.error) ? j.error : ('Falha no servidor (HTTP ' + res.status + ').'));
    }
    return j;
  }

  function poIcAtualizaBotoes() {
    var btn = document.querySelector('#ic-btn-preview');
    var origemEl = document.querySelector('#ic-origem');
    if (!btn || !origemEl) return;
    btn.disabled = !icArquivo || !origemEl.value;
  }

  function poIcRenderResumo(resumo) {
    var box = document.querySelector('#ic-resumo');
    if (!box) return;
    box.hidden = false;
    box.innerHTML = '<div class="lbl">O que vai acontecer se voce confirmar</div>' +
      '<div class="hint">' + esc(poResumoTexto(resumo)) + '</div>';
  }

  function poIcRenderRevisao(itens) {
    var wrap = document.querySelector('#ic-revisar-wrap');
    var rows = document.querySelector('#ic-revisar-rows');
    if (!wrap || !rows) return;
    var revisar = (itens || []).filter(function (it) { return it && it.acao === 'revisar'; });
    if (!revisar.length) {
      wrap.hidden = true;
      rows.innerHTML = '';
      return;
    }
    wrap.hidden = false;
    rows.innerHTML = revisar.map(poLinhaRevisao).join('');
  }

  // Irma da poIcRenderRevisao: enquanto aquela mostra quem precisa de
  // decisao humana, esta mostra quem ja tem decisao tomada (fusao
  // automatica) e O QUE vai ser preenchido — spec 9.5, task 3.
  function poIcRenderFusoes(itens) {
    var rows = document.querySelector('#ic-fusoes');
    if (!rows) return;
    var fusoes = (itens || []).filter(function (it) { return it && it.acao === 'funde'; });
    rows.innerHTML = fusoes.length
      ? fusoes.map(poLinhaFusao).join('')
      : '<tr><td colspan="3"><div class="empty">Nenhuma ficha existente sera completada.</div></td></tr>';
  }

  function poIcRenderResultado(resp) {
    var box = document.querySelector('#ic-resultado');
    if (!box) return;
    var ap = (resp && resp.aplicado) || {};
    // 'lote_registrado'===false (ruling B, Task 2): os contatos foram
    // gravados, mas o registro do lote nao fechou. Nao e erro, e aviso.
    var aviso = poIcAvisoLote(resp);
    box.hidden = false;
    box.innerHTML = '<div class="lbl">Importacao concluida</div>' +
      '<div class="hint">' +
      (ap.novos || 0) + ' contato(s) novo(s) · ' +
      (ap.preenchidos || 0) + ' ficha(s) completada(s) · ' +
      (ap.revisar || 0) + ' ainda para voce revisar' +
      ((ap.falhas) ? ' · ' + ap.falhas + ' falha(s)' : '') +
      '</div>' +
      (aviso ? '<div class="imp-ct-aviso">' + esc(aviso) + '</div>' : '');
  }

  function poIcReset() {
    icPreview = null;
    icAuditado = null;
    var resumo = document.querySelector('#ic-resumo');
    var wrap = document.querySelector('#ic-revisar-wrap');
    var fusoesRows = document.querySelector('#ic-fusoes');
    var resultado = document.querySelector('#ic-resultado');
    var btnAplicar = document.querySelector('#ic-btn-aplicar');
    var origemEl = document.querySelector('#ic-origem');
    var tipoEl = document.querySelector('#ic-tipo');
    var forcarEl = document.querySelector('#ic-forcar');
    if (resumo) resumo.hidden = true;
    if (wrap) wrap.hidden = true;
    // A tabela de fusoes (diferente da de revisao) nao tem wrapper
    // escondivel: fica sempre visivel, mesmo vazia, porque "nenhuma ficha
    // sera completada" tambem e informacao do preview. So o conteudo e
    // limpo, para nao sobrar fusao de uma auditoria anterior na tela.
    if (fusoesRows) fusoesRows.innerHTML = '';
    if (resultado) resultado.hidden = true;
    if (btnAplicar) { btnAplicar.hidden = true; btnAplicar.disabled = true; }
    // Destrava os seletores: sem auditoria valida, origem/tipo voltam a
    // poder ser escolhidos livremente ate a proxima "Ver auditoria".
    if (origemEl) origemEl.disabled = false;
    if (tipoEl) tipoEl.disabled = false;
    // "Importar novamente" e escape hatch, nao fluxo normal: qualquer
    // invalidacao da auditoria (arquivo novo, origem/tipo trocados) volta
    // a caixa para o padrao desmarcado, para nao sobreviver por engano a
    // uma nova auditoria que nem precisa dela.
    if (forcarEl) forcarEl.checked = false;
  }

  function poIcWire() {
    var btnArquivo = document.querySelector('#ic-btn-arquivo');
    var fileInput = document.querySelector('#ic-file');
    var origemEl = document.querySelector('#ic-origem');
    var tipoEl = document.querySelector('#ic-tipo');
    var btnPreview = document.querySelector('#ic-btn-preview');
    var btnAplicar = document.querySelector('#ic-btn-aplicar');
    if (!btnArquivo || !fileInput || !origemEl || !tipoEl || !btnPreview || !btnAplicar) return;

    btnArquivo.onclick = function () { fileInput.click(); };

    fileInput.onchange = function (e) {
      icArquivo = e.target.files[0] || null;
      var nomeEl = document.querySelector('#ic-filename');
      if (nomeEl) nomeEl.textContent = icArquivo ? icArquivo.name : 'Nenhum arquivo selecionado.';
      poIcReset(); // um arquivo novo invalida qualquer preview anterior e destrava os seletores
      // .vcf e o padrao (mesmo default do endpoint); .csv so quando a extensao diz isso.
      // Vem DEPOIS do poIcReset(): o reset destrava tipoEl, senao o valor
      // seria escrito num select ainda travado por uma auditoria antiga.
      if (icArquivo) tipoEl.value = /\.csv$/i.test(icArquivo.name) ? 'csv' : 'vcf';
      poIcAtualizaBotoes();
    };

    // Trocar origem ou tipo DEPOIS de uma auditoria bem-sucedida invalida
    // o preview em vez de deixar o aplicar reenviar um valor nao-auditado
    // (o guarda de verdade e o poIcCamposEnvio; isto e so feedback visual
    // honesto, e defesa a mais se os selects um dia deixarem de travar).
    origemEl.onchange = function () { poIcReset(); poIcAtualizaBotoes(); };
    tipoEl.onchange = function () { poIcReset(); poIcAtualizaBotoes(); };

    btnPreview.onclick = async function () {
      if (!icArquivo || !origemEl.value) return;
      btnPreview.disabled = true;
      var textoOriginal = btnPreview.textContent;
      btnPreview.textContent = 'Analisando…';
      try {
        var j = await poIcEnvia('preview');
        icPreview = j;
        // Trava AQUI o que foi de fato auditado. O aplicar (poIcEnvia via
        // poIcCamposEnvio) usa isto, nunca o valor ao vivo dos selects —
        // e os selects sao travados na UI para o mesmo invariante ficar
        // visivel: o que se ve no resumo e o que sera aplicado.
        icAuditado = { origem: origemEl.value, tipo: tipoEl.value };
        origemEl.disabled = true;
        tipoEl.disabled = true;
        poIcRenderResumo(j.resumo);
        poIcRenderRevisao(j.itens || []);
        poIcRenderFusoes(j.itens || []);
        var resultado = document.querySelector('#ic-resultado');
        if (resultado) resultado.hidden = true;
        btnAplicar.hidden = false;
        btnAplicar.disabled = false;
      } catch (err) {
        toast('Erro ao analisar o arquivo: ' + err.message, true);
      } finally {
        btnPreview.disabled = false;
        btnPreview.textContent = textoOriginal;
      }
    };

    btnAplicar.onclick = async function () {
      if (!icArquivo || !icPreview) return;
      var msg = 'Confirma a importacao?\n\n' + poResumoTexto(icPreview.resumo);
      if (!window.confirm(msg)) return;
      btnAplicar.disabled = true;
      var textoOriginal = btnAplicar.textContent;
      btnAplicar.textContent = 'Importando…';
      try {
        var j = await poIcEnvia('aplicar');
        poIcRenderResultado(j);
        toast('Importacao concluida.');
        btnAplicar.hidden = true;
      } catch (err) {
        toast('Erro ao importar: ' + err.message, true);
      } finally {
        btnAplicar.disabled = false;
        btnAplicar.textContent = textoOriginal;
      }
    };

    poIcAtualizaBotoes();
  }

  // A view usa o mesmo mecanismo das demais (app.js chama poRenderImportar
  // ao trocar de aba). Aqui nao ha dado do Supabase para recarregar — so
  // reafirma o estado dos botoes, que depende de arquivo+origem escolhidos.
  window.poRenderImportar = function () { poIcAtualizaBotoes(); };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', poIcWire);
  } else {
    poIcWire();
  }
})();
