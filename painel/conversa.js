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

async function poRenderConversa(waId) {
  const alvo = document.querySelector('#dr-conversa');
  if (!alvo) return;

  if (!waId || waId === '—') {
    alvo.innerHTML = '<div class="empty">Este lead nao veio pelo WhatsApp.</div>';
    return;
  }
  alvo.innerHTML = '<div class="empty">Carregando...</div>';

  const {data, error} = await sb.from('po_wa_mensagens')
    .select('autor,direcao,tipo,texto,ts')
    .eq('wa_id', waId)
    .order('ts', {ascending: true})
    .limit(300);

  if (error) { alvo.innerHTML = '<div class="empty">Nao consegui carregar a conversa.</div>'; return; }
  if (!data || !data.length) { alvo.innerHTML = '<div class="empty">Nenhuma mensagem ainda.</div>'; return; }

  alvo.innerHTML = data.map(poBolha).join('');
  alvo.scrollTop = alvo.scrollHeight;
}
