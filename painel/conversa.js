/* ============================================================
   A conversa do WhatsApp dentro da gaveta do lead.

   Le po_wa_mensagens, que o motor alimenta com as tres pontas do dialogo:
   o cliente, o robo e a propria dona (pelo eco do celular). Saber QUEM
   falou e o que ela mais precisa ao abrir: se o robo ja mandou o roteiro,
   ela nao repete.
============================================================ */

const PO_AUTOR = {
  cliente: {cls:'cv--in',  nome:'Cliente'},
  robo:    {cls:'cv--bot', nome:'Automatico'},
  humano:  {cls:'cv--out', nome:'Voce'},
};

function poBolha(m) {
  const a = PO_AUTOR[m.autor] || PO_AUTOR.cliente;
  const hora = (function () {
    const d = new Date(m.ts);
    return isNaN(d) ? '' : d.toLocaleDateString('pt-BR', {day:'2-digit', month:'2-digit'}) +
      ' ' + d.toLocaleTimeString('pt-BR', {hour:'2-digit', minute:'2-digit'});
  })();

  // Mensagem sem texto e audio, imagem ou figurinha. Sair em branco faria
  // parecer que a conversa tem buracos.
  let corpo = String(m.texto == null ? '' : m.texto);
  if (!corpo.trim()) {
    const t = m.tipo || '';
    corpo = t === 'audio' ? '(audio)'
          : t === 'image' ? '(imagem)'
          : t === 'document' ? '(documento)'
          : '(anexo)';
    corpo = esc(corpo);
  } else {
    corpo = esc(corpo).replace(/\n/g, '<br>');
  }

  return '<div class="cv ' + a.cls + '">' +
    '<span class="cv-w">' + a.nome + ' · ' + esc(hora) + '</span>' +
    '<div class="cv-t">' + corpo + '</div></div>';
}

/* A consulta da conversa descrita como dado, e nao escrita direto na
   chamada. Existe para ser testavel sem rede e sem navegador: o filtro
   por wa_id ja desapareceu uma vez numa mexida na ordenacao, e sem ele a
   gaveta de um lead mostra a conversa de todos os contatos misturada. */
function poFiltroConversa(waId) {
  return {
    tabela: 'po_wa_mensagens',
    colunas: 'autor,tipo,texto,ts',
    // O filtro por contato E a regra desta tela: sem ele a consulta
    // continua funcionando (a RLS libera a tabela toda para quem esta
    // logado) e devolve as mensagens de outras pessoas como se fossem
    // deste lead.
    filtro: {coluna: 'wa_id', valor: waId},
    // Descendente, nao ascendente: com limite de 300, ascendente cortaria
    // do lado errado, trazendo as 300 mensagens MAIS ANTIGAS e escondendo
    // o fim da conversa. Numa venda de viagem, consultiva e longa, isso
    // mostra a apresentacao do roteiro e esconde a negociacao. Pedimos as
    // mais recentes e revertemos no render para ficar em ordem
    // cronologica.
    ordem: {coluna: 'ts', ascendente: false},
    limite: 300,
  };
}

/* Aplica o descritor acima num cliente Supabase. O cliente entra por
   parametro para o teste poder passar um duble que so anota o que foi
   chamado, sem tocar a rede. */
function poMontaConsulta(cliente, f) {
  return cliente.from(f.tabela)
    .select(f.colunas)
    .eq(f.filtro.coluna, f.filtro.valor)
    .order(f.ordem.coluna, {ascending: f.ordem.ascendente})
    .limit(f.limite);
}

async function poRenderConversa(waId) {
  const alvo = document.querySelector('#dr-conversa');
  if (!alvo) return;

  // Guarda contra a corrida de trocar de lead rapido: se a resposta desta
  // consulta chegar depois de o usuario ja ter aberto outro lead, o
  // openId global (mantido pelo app.js) ja mudou, e descartamos a
  // resposta velha em vez de sobrescrever a conversa que esta na tela.
  const pedidoPara = openId;

  if (!waId || waId === '—') {
    alvo.innerHTML = '<div class="empty">Este lead nao veio pelo WhatsApp.</div>';
    return;
  }
  alvo.innerHTML = '<div class="empty">Carregando...</div>';

  const {data, error} = await poMontaConsulta(sb, poFiltroConversa(waId));

  if (pedidoPara !== openId) return; // usuario ja abriu outro lead

  if (error) { alvo.innerHTML = '<div class="empty">Nao consegui carregar a conversa.</div>'; return; }
  if (!data || !data.length) { alvo.innerHTML = '<div class="empty">Nenhuma mensagem ainda.</div>'; return; }

  alvo.innerHTML = data.reverse().map(poBolha).join('');
  poRolaConversaFim();
}

/* Rolar para a ultima mensagem so funciona com a aba visivel: elemento em
   display:none nao tem caixa, entao scrollHeight vale 0 e a atribuicao nao
   faz nada. Como o openDrawer abre sempre na aba Dados, a chamada de
   dentro do render e no-op na pratica; quem rola de verdade e o handler
   que troca de aba, no app.js. Fica nos dois lugares porque a funcao ja se
   protege sozinha e assim um render futuro com a aba aberta tambem acerta. */
function poRolaConversaFim() {
  const alvo = document.querySelector('#dr-conversa');
  if (alvo) alvo.scrollTop = alvo.scrollHeight;
}
