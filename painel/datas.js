/* As datas de um roteiro de SAIDAS SEMANAIS, no painel.
   Gemeo de lib/po-datas.php e do bloco equivalente em
   assets/js/roteiros-shared.js. tests/test-datas-semanal.mjs roda a MESMA
   tabela de casos nas tres implementacoes e exige que concordem caso a caso.

   POR QUE UM ARQUIVO PROPRIO, e nao dentro do app.js: para o teste cruzado
   poder EXECUTAR esta implementacao. O app.js tem codigo de topo e mexe no
   DOM, entao em harness de teste ele so poderia ser conferido por regex no
   fonte - e regex no fonte testa o texto, nao o comportamento, que e
   exatamente o que o teste cruzado precisa comparar.

   POR QUE NAO reusar o assets/js/roteiros-shared.js: ele define poClient e
   le window.PO_CONFIG, que aqui colidiriam com o config.js do painel.

   Carregado pelo painel/index.html ANTES do app.js, que o consome. */
(function () {
  'use strict';

  /* A forma de po_roteiros.datas_texto, limpa:
       [{titulo: 'Datas de Dezembro', datas: ['02/12/26', '10/12/26']}]
     O que nao tem essa forma e descartado em silencio, nunca aceito pela
     metade: o jsonb pode ter sido editado a mao no SQL Editor. */
  function poDatasGrupos(roteiro) {
    if (!roteiro || typeof roteiro !== 'object') return [];
    var v = roteiro.datas_texto;
    if (typeof v === 'string') { try { v = JSON.parse(v); } catch (e) { return []; } }
    if (!Array.isArray(v) || !v.length) return [];
    var out = [];
    for (var i = 0; i < v.length; i++) {
      var g = v[i];
      if (!g || typeof g !== 'object' || typeof g.titulo !== 'string') continue;
      if (!Array.isArray(g.datas)) continue;
      var datas = [];
      for (var j = 0; j < g.datas.length; j++) {
        if (typeof g.datas[j] !== 'string') continue;
        var d = g.datas[j].trim();
        if (d !== '') datas.push(d);
      }
      out.push({ titulo: g.titulo, datas: datas });
    }
    return out;
  }

  /* 'd/m/a' -> 'YYYY-MM-DD', ou null. Ano de 2 digitos vira 20xx, e data
     impossivel (32/12, 31/02) e recusada. */
  function poDataIso(txt) {
    if (typeof txt !== 'string') return null;
    var m = /^\s*(\d{1,2})\/(\d{1,2})\/(\d{4}|\d{2})\s*$/.exec(txt);
    if (!m) return null;
    var d = +m[1], mes = +m[2], ano = +m[3];
    if (m[3].length === 2) ano += 2000;
    if (mes < 1 || mes > 12 || d < 1) return null;
    var dt = new Date(Date.UTC(ano, mes - 1, d));
    if (dt.getUTCFullYear() !== ano || dt.getUTCMonth() !== mes - 1 || dt.getUTCDate() !== d) return null;
    return ano + '-' + (mes < 10 ? '0' : '') + mes + '-' + (d < 10 ? '0' : '') + d;
  }

  /* A MENOR data de hoje em diante, nao a primeira da lista: a cliente
     digita na ordem que quiser. `>=` porque a saida de hoje ainda e uma
     saida. Data ilegivel e descartada e a busca CONTINUA. */
  function poProximaSaida(roteiro, hoje) {
    var h = hoje || new Date().toISOString().slice(0, 10);
    var gs = poDatasGrupos(roteiro), melhor = null;
    for (var i = 0; i < gs.length; i++) {
      for (var j = 0; j < gs[i].datas.length; j++) {
        var iso = poDataIso(gs[i].datas[j]);
        if (iso === null || iso < h) continue;
        if (melhor === null || iso < melhor) melhor = iso;
      }
    }
    return melhor;
  }

  /* A string de data que a pessoa le. Quem decide e o STATUS, nao a
     presenca do dado. Semanal sem data futura volta ao comportamento de
     sempre: anunciar data vencida e pior que nao anunciar nada. A data sai
     EXATAMENTE como foi digitada, sem reformatar. */
  function poQuando(roteiro, hoje) {
    if (!roteiro || typeof roteiro !== 'object') return '';
    if (roteiro.status_viagem === 'semanal') {
      var iso = poProximaSaida(roteiro, hoje);
      if (iso !== null) {
        var gs = poDatasGrupos(roteiro);
        for (var i = 0; i < gs.length; i++) {
          for (var j = 0; j < gs[i].datas.length; j++) {
            if (poDataIso(gs[i].datas[j]) === iso) return 'Próxima saída: ' + gs[i].datas[j];
          }
        }
      }
    }
    var s = String(roteiro.data_label == null ? '' : roteiro.data_label).trim();
    if (s !== '') return s;
    return String(roteiro.periodo == null ? '' : roteiro.periodo).trim();
  }

  window.poDatasGrupos = poDatasGrupos;
  window.poDataIso = poDataIso;
  window.poProximaSaida = poProximaSaida;
  window.poQuando = poQuando;
  /* O nome pelo qual a gaveta do roteiro consome a normalizacao. E A MESMA
     funcao, nao uma segunda: duas normalizacoes no mesmo arquivo
     divergiriam. */
  window.rotDatasNormaliza = poDatasGrupos;
})();
