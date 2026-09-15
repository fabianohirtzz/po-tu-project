/* ============================================================
   Aba Transmissao. O ultimo ponto antes de gastar dinheiro da cliente.

   Spec 8.2: a tela mostra QUANTAS PESSOAS e QUANTO CUSTA antes do envio, e
   nada dispara sem esse aviso. Custo invisivel em fatura de terceiro e como
   um projeto assim perde a confianca do cliente.

   Quem faz conta e quem escreve no banco e o campanha.php: a service_role so
   existe no servidor, e a regra de quem entra na campanha (wa_camp_publico)
   precisa ser a MESMA que reserva os destinatarios. Uma copia dessa regra em
   JavaScript mostraria um publico na tela e mandaria para outro.
============================================================ */

/* Centavos inteiros viram reais. Sem float em dinheiro: o numero que ela le
   tem que bater com a fatura da Meta. */
function poCampCustoBRL(centavos) {
  const n = Math.round(Number(centavos) || 0);
  return 'R$ ' + String(Math.floor(n / 100)) + ',' + String(n % 100).padStart(2, '0');
}

/* Cada balde nomeado. E assim que ela confere as defesas: "615 sem telefone"
   explica por que a campanha e pequena, e "3 faltam revisar" e o empurrao
   para a aba Revisao. */
function poCampResumoTexto(r) {
  const d = r || {};
  const p = [];
  p.push((Number(d.total) || 0) + ' pessoas recebem');
  if (d.duplicados)   p.push(d.duplicados + ' repetidas, contadas uma vez');
  if (d.saiu)         p.push(d.saiu + ' pediram SAIR e ficam de fora');
  if (d.nao_revisado) p.push(d.nao_revisado + ' ainda faltam revisar');
  if (d.nao_cliente)  p.push(d.nao_cliente + ' não são clientes');
  if (d.sem_celular)  p.push(d.sem_celular + ' têm só telefone fixo');
  if (d.sem_telefone) p.push(d.sem_telefone + ' estão sem telefone');
  return p.join(' · ');
}

/* A trava do clique que gasta. Publico vazio nunca dispara, e custo nao
   calculado tambem nao: um envio sem o aviso de custo quebra a spec 8.2. */
function poCampPodeEnviar(previa) {
  if (!previa) return false;
  if (!(Number(previa.total) > 0)) return false;
  if (previa.custo_centavos === null || previa.custo_centavos === undefined) return false;
  return true;
}

/* A frase que a cliente le e confirma. Ela CARREGA o numero de pessoas e o
   dinheiro porque e essa a exigencia da spec 8.2: um "Confirmar?" pelado
   seria a tela disparando sem avisar. */
function poCampAviso(previa) {
  const p = previa || {};
  return 'Confirmar o envio para ' + (Number(p.total) || 0) + ' pessoas?\n\n' +
         'Custo estimado: ' + poCampCustoBRL(p.custo_centavos) +
         ' (cerca de R$ 0,31 por pessoa, cobrados pela Meta).\n\n' +
         'O que já tiver saído não tem como cancelar.';
}

/* Defesa 3 da spec 8.1, cobrada ANTES de gastar. Sem a frase, a pessoa nao
   sabe que pode sair, e o descadastro vira denuncia na Meta - que e o que
   derruba a qualidade do numero que a agencia usa para trabalhar. */
function poCampValidaCorpo(corpo) {
  const t = (corpo == null ? '' : String(corpo)).trim();
  if (t === '') return 'Escreva o texto da mensagem.';
  if (t.length > 1024) {
    return 'O texto tem ' + t.length + ' caracteres e o limite do template é 1024. ' +
           'Acima disso a Meta recusa a mensagem inteira.';
  }
  if (!/\bSAIR\b/.test(t)) {
    return 'Inclua a frase "Responda SAIR para não receber mais" no texto. ' +
           'É o que permite a pessoa sair da lista.';
  }
  return null;
}

/* ---------- conversa com o campanha.php ---------- */

let PO_CAMP_PREVIA = null;     // ultima previa recebida do servidor
let PO_CAMP_OCUPADO = false;   // trava contra o segundo clique

/* O token vem de sb.auth.getSession() na hora, como no importador de
   contatos: guardado numa variavel ele vence sozinho e o endpoint passa a
   devolver 401 sem ninguem entender por que. */
async function poCampToken() {
  const s = await sb.auth.getSession();
  return (s && s.data && s.data.session && s.data.session.access_token) || '';
}

async function poCampPost(modo, extra) {
  const fd = new FormData();
  fd.append('modo', modo);
  fd.append('sb_token', await poCampToken());
  Object.keys(extra || {}).forEach(k => fd.append(k, extra[k]));
  const res = await fetch('../campanha.php', { method: 'POST', body: fd });
  let j = null;
  try { j = await res.json(); } catch (e) { j = null; }
  if (!j) throw new Error('O servidor não respondeu direito. Tente de novo.');
  if (!j.ok) throw new Error(j.error || 'Falha na transmissão.');
  return j;
}

function poCampMsg(sel, texto) {
  const el = document.querySelector(sel);
  if (el) el.textContent = texto || '';
}

/* ---------- render ---------- */

/* O botao NASCE desabilitado e so o retorno da previa o habilita: assim nao
   existe janela em que um clique dispare sem o aviso de custo na tela. */
async function poRenderCampanhas() {
  const btn = document.querySelector('#camp-enviar');
  if (btn) btn.disabled = true;
  PO_CAMP_PREVIA = null;
  poCampMsg('#camp-erro', '');
  poCampMsg('#camp-alerta', '');
  poCampMsg('#camp-custo', '—');
  poCampMsg('#camp-resumo', 'Calculando o público...');

  await poCampCarregaAndamento();

  let previa;
  try {
    previa = await poCampPost('previa', {});
  } catch (e) {
    poCampMsg('#camp-resumo', 'Não consegui calcular o público. Tente de novo.');
    poCampMsg('#camp-erro', e.message || '');
    return;
  }

  PO_CAMP_PREVIA = previa;
  poCampMsg('#camp-custo', poCampCustoBRL(previa.custo_centavos));
  poCampMsg('#camp-resumo', poCampResumoTexto(previa.resumo));

  if (previa.suspeitos > 0) {
    poCampMsg('#camp-alerta',
      previa.suspeitos + ' número(s) podem ser estrangeiros salvos com 00. ' +
      'Confira antes de enviar: cada um custa igual e não entrega.');
  }
  if (btn) btn.disabled = !poCampPodeEnviar(previa);
}

/* A campanha em andamento fica visivel porque e ela que bloqueia uma nova:
   uma campanha por vez e o que impede duas dividirem o teto diario sem saber
   uma da outra. Enquanto existir uma aqui, o botao de enviar fica travado. */
async function poCampCarregaAndamento() {
  const box = document.querySelector('#camp-andamento');
  if (!box) return;
  let e;
  try {
    e = await poCampPost('estado', {});
  } catch (err) {
    box.hidden = true;
    return;
  }
  const c = e.campanha;
  if (!c) { box.hidden = true; return; }

  box.hidden = false;
  const emReserva = c.status === 'rascunho';
  poCampMsg('#camp-and-nome', c.nome || 'Campanha sem nome');
  poCampMsg('#camp-and-dados',
    emReserva
      ? ('Montando a lista: ' + e.reservados + ' de ' + c.total + ' pessoas separadas. ' +
         'Continue para terminar - nada é enviado antes disso.')
      : (e.enviados + ' enviadas · ' + e.pendentes + ' na fila · ' + e.falhas + ' com falha'));

  const bReserva = document.querySelector('#camp-continuar');
  const bDrena   = document.querySelector('#camp-drenar');
  if (bReserva) bReserva.hidden = !emReserva;
  if (bDrena)   bDrena.hidden   = emReserva;
  document.querySelector('#camp-andamento').dataset.id = c.id;
}

/* ---------- acoes ---------- */

/* O clique que gasta. A ordem e o contrato: valida o texto, mostra pessoas e
   custo, pede confirmacao e SO ENTAO cria a campanha. */
async function poCampEnviar() {
  if (PO_CAMP_OCUPADO) return;
  const nome     = (document.querySelector('#camp-nome').value || '').trim();
  const template = (document.querySelector('#camp-template').value || '').trim();
  const corpo    = document.querySelector('#camp-corpo').value || '';

  const erroCorpo = poCampValidaCorpo(corpo);
  if (erroCorpo) { poCampMsg('#camp-erro', erroCorpo); return; }
  if (nome === '')     { poCampMsg('#camp-erro', 'Dê um nome para esta campanha.'); return; }
  if (template === '') { poCampMsg('#camp-erro', 'Informe o nome do modelo aprovado na Meta.'); return; }
  if (!poCampPodeEnviar(PO_CAMP_PREVIA)) {
    poCampMsg('#camp-erro', 'Espere o público e o custo aparecerem antes de enviar.');
    return;
  }
  if (!confirm(poCampAviso(PO_CAMP_PREVIA))) return;

  PO_CAMP_OCUPADO = true;
  const btn = document.querySelector('#camp-enviar');
  if (btn) btn.disabled = true;
  poCampMsg('#camp-erro', '');
  poCampMsg('#camp-progresso', 'Separando os destinatários...');
  try {
    const c = await poCampPost('criar', { nome: nome, template: template, corpo: corpo });
    await poCampReservaAteOFim(c.campanha_id, c);
  } catch (e) {
    poCampMsg('#camp-erro', e.message || 'Falha ao criar a campanha.');
  }
  PO_CAMP_OCUPADO = false;
  await poRenderCampanhas();
}

/* A reserva vem em pedacos porque sao centenas de escritas: o servidor devolve
   quanto falta e a tela pede o proximo pedaco. O laco tem teto de voltas para
   nunca virar laco infinito contra um servidor que pare de progredir. */
async function poCampReservaAteOFim(id, passo) {
  let atual = passo;
  for (let i = 0; i < 200 && atual && !atual.completo; i++) {
    poCampMsg('#camp-progresso',
      'Separando os destinatários: ' + (atual.reservados_total || 0) +
      ' de ' + (atual.total || 0) + '.');
    atual = await poCampPost('reservar', { campanha_id: id });
  }
  if (atual && atual.completo) {
    poCampMsg('#camp-progresso',
      'Campanha pronta com ' + atual.reservados_total + ' destinatários. ' +
      'O envio sai em lotes, a cada hora.');
    toast('Campanha criada. O primeiro lote sai na próxima hora.');
  } else {
    poCampMsg('#camp-progresso',
      'A lista ficou incompleta e nada foi enviado. Abra a aba de novo e clique em Continuar.');
  }
}

/* Continuar a reserva de uma campanha que ficou pela metade (a aba foi
   fechada no meio, ou a internet caiu). Sem este caminho, a campanha ficaria
   em 'rascunho' para sempre e nenhuma outra poderia ser criada. */
async function poCampContinuar() {
  const box = document.querySelector('#camp-andamento');
  const id  = box && box.dataset.id;
  if (!id || PO_CAMP_OCUPADO) return;
  PO_CAMP_OCUPADO = true;
  try {
    const passo = await poCampPost('reservar', { campanha_id: id });
    await poCampReservaAteOFim(id, passo);
  } catch (e) {
    poCampMsg('#camp-erro', e.message || 'Falha ao continuar a lista.');
  }
  PO_CAMP_OCUPADO = false;
  await poRenderCampanhas();
}

/* Manda o proximo lote agora, sem esperar o cron. O tamanho do lote e quem
   decide e o servidor (a escada da spec 8.1 e o teto do dia); a tela so pede. */
async function poCampDrenar() {
  const box = document.querySelector('#camp-andamento');
  const id  = box && box.dataset.id;
  if (!id || PO_CAMP_OCUPADO) return;
  PO_CAMP_OCUPADO = true;
  poCampMsg('#camp-progresso', 'Enviando o próximo lote...');
  try {
    const d = await poCampPost('drenar', { campanha_id: id });
    if (d.lote === 0) {
      poCampMsg('#camp-progresso',
        'O limite de envios de hoje já foi atingido. O resto sai amanhã, sozinho.');
    } else if (d.restam < 0) {
      poCampMsg('#camp-progresso',
        'Não consegui falar com o banco agora. Nada se perdeu: tente de novo em instantes.');
    } else {
      poCampMsg('#camp-progresso',
        d.enviados + ' enviadas agora · ' + d.falhas + ' com falha · ' + d.restam + ' na fila.');
    }
  } catch (e) {
    poCampMsg('#camp-erro', e.message || 'Falha ao enviar o lote.');
  }
  PO_CAMP_OCUPADO = false;
  await poCampCarregaAndamento();
}

document.addEventListener('DOMContentLoaded', function () {
  const b1 = document.querySelector('#camp-enviar');
  const b2 = document.querySelector('#camp-continuar');
  const b3 = document.querySelector('#camp-drenar');
  if (b1) b1.onclick = poCampEnviar;
  if (b2) b2.onclick = poCampContinuar;
  if (b3) b3.onclick = poCampDrenar;
});
