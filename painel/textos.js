/* ============================================================
   Textos do robo: o que ele manda no WhatsApp, editavel sem deploy.

   Ate agora so dava para mudar rodando SQL, e o que esta no banco e rascunho,
   sem acento. Sao estes textos que viram os TEMPLATES submetidos a Meta,
   entao escrever aqui adianta duas etapas.

   As palavras entre chaves sao trocadas na hora do envio, em wa_texto()
   (lib/wa-motor.php). Apagar uma obrigatoria manda a mensagem sem o dado;
   escrever uma que nao existe faz o motor APAGAR a palavra do texto (ele
   limpa todo /\{[a-z_]+\}/ que sobrou depois da troca). Nos dois casos a
   cliente so descobriria pelo WhatsApp do cliente - por isso a recusa
   acontece aqui, antes de salvar, com o nome da variavel na mensagem.

   O catalogo vive DENTRO da funcao de proposito: o teste extrai a funcao do
   fonte por regex e uma constante de topo de arquivo daria ReferenceError.
============================================================ */

/* As 7 chaves que o motor realmente le: envio_pdf, menu, perguntas,
   qualificado e sem_data em lib/wa-motor.php; lembrete e lembrete_menu em
   lib/wa-timeout.php. A oitava linha do banco (saudacao) e residuo do seed e
   nunca e lida - fica fora daqui para a cliente nao escrever um texto que
   nunca sai. */
function poTextosCatalogo() {
  return [
    {chave:'menu', rotulo:'Menu de roteiros',
     ajuda:'Mandado quando o robô não descobre de qual viagem a pessoa fala.',
     vars:['nome'], obrigatorias:[]},
    {chave:'envio_pdf', rotulo:'Envio do roteiro em PDF',
     ajuda:'Legenda do PDF do roteiro.',
     vars:['nome','roteiro'], obrigatorias:['roteiro']},
    /* O motor passa nome, roteiro E data aqui (wa-motor.php:390-393). O
       {roteiro} fica opcional: a mensagem faz sentido sem ele, porque o PDF
       com o nome do roteiro acabou de sair - e torna-lo obrigatorio
       recusaria o texto que ja esta no banco. */
    {chave:'perguntas', rotulo:'As duas perguntas de qualificação',
     ajuda:'Vai logo depois do PDF. {data} é a data de saída da viagem. ' +
           'As duas perguntas precisam ser numeradas, e a da DATA tem que ser a número 1: ' +
           'o robô sempre lê a resposta "1" como a da data e a "2" como a de já ter viajado ' +
           'em grupo. Se você trocar a ordem, quem nunca viajou em grupo — que é o cliente ' +
           'que a gente quer — vira lead perdido por engano.',
     vars:['nome','roteiro','data'], obrigatorias:['data']},
    {chave:'qualificado', rotulo:'Quando a pessoa se qualifica',
     ajuda:'Avisa que a equipe assume daqui.',
     vars:['nome'], obrigatorias:[]},
    {chave:'sem_data', rotulo:'Quando a pessoa não tem a data',
     ajuda:'Resposta de quem não pode viajar nessa data.',
     vars:['nome'], obrigatorias:[]},
    {chave:'lembrete', rotulo:'Lembrete após 24h sem resposta',
     ajuda:'Para quem recebeu o roteiro e não respondeu.',
     vars:['nome'], obrigatorias:[]},
    {chave:'lembrete_menu', rotulo:'Lembrete de quem não escolheu roteiro',
     ajuda:'Para quem recebeu o menu e não escolheu.',
     vars:['nome'], obrigatorias:[]},
  ];
}

/* Devolve null quando o texto pode ser salvo, ou a mensagem do problema.
   Quem le a mensagem e a cliente, nao um programador: ela diz QUAL variavel
   esta errada e quais existem naquela mensagem. */
function poTextoValida(chave, texto) {
  const cat = poTextosCatalogo().find(c => c.chave === chave);
  if (!cat) return 'Texto desconhecido.';
  const t = (texto == null ? '' : String(texto)).trim();
  if (t === '') return 'O texto não pode ficar vazio.';

  /* Casa QUALQUER coisa entre chaves, nao so /\{[a-z_]+\}/. O motor limpa o
     que sobrou com o regex estreito, entao toda grafia fora de [a-z_] escapa
     das DUAS pontas e sai literal no WhatsApp do cliente: "{Nome}" (o que
     qualquer pessoa escreve depois de ponto final, ou o que o corretor do
     celular faz sozinho), "{ nome }" e "{nome2}". Aqui elas viram recusa com
     o nome exato no erro, que e o unico lugar onde a cliente ve o problema
     antes de o cliente ver. */
  const usadas = (t.match(/\{[^}]*\}/g) || []).map(v => v.slice(1, -1));
  const desconhecida = usadas.find(v => cat.vars.indexOf(v) === -1);
  if (desconhecida) {
    return 'A variável {' + desconhecida + '} não existe aqui. Disponíveis: ' +
           cat.vars.map(v => '{' + v + '}').join(', ') + '.';
  }
  const faltando = cat.obrigatorias.find(v => usadas.indexOf(v) === -1);
  if (faltando) {
    return 'Falta a variável {' + faltando + '}, que é obrigatória nesta mensagem.';
  }

  /* A ORDEM DAS PERGUNTAS E CONTRATO. wa_respostas_numeradas
     (lib/wa-motor.php) casa \b1\b (.*?) \b2\b (.*) e atribui SEMPRE
     1 -> data, 2 -> grupo. Isso nunca esteve escrito no texto, entao a tela
     aceitava as perguntas trocadas ou sem numero - e ai quem responde
     "1. nao (nunca viajei em grupo) 2. sim" cai em resposta de data
     negativa, o motor grava status='perdido' e manda o sem_data. Quebraria a
     regra permanente "so a pergunta da data desqualifica; quem nunca viajou
     em grupo e o cliente-alvo, nao um descarte" - e quebraria por um texto
     que a propria tela aprovou. */
  if (chave === 'perguntas') {
    const m1 = t.match(/\b1\b/);
    const m2 = m1 ? t.slice(m1.index + 1).match(/\b2\b/) : null;
    if (!m1 || !m2) {
      return 'Numere as duas perguntas: "1." na pergunta da data e "2." na de já ter ' +
             'viajado em grupo. Sem os números o robô não consegue separar as duas respostas.';
    }
    const i2 = m1.index + 1 + m2.index;
    const iData = t.indexOf('{data}');
    if (iData < m1.index || iData > i2) {
      return 'A pergunta da data tem que ser a número 1: escreva {data} depois do "1" e ' +
             'antes do "2". O robô sempre lê a resposta "1" como a da data e a "2" como a ' +
             'de já ter viajado em grupo, então com as perguntas trocadas quem nunca ' +
             'viajou em grupo vira lead perdido por engano.';
    }
  }

  return null;
}

/* O texto e escrito pela cliente e sai no WhatsApp do cliente: aqui dentro
   do painel ele e DADO, nunca marcacao - por isso tudo passa pelo esc(). */
function poLinhaTexto(item) {
  const it = item || {};
  const vars = (it.vars || []).map(v => '<code>{' + esc(v) + '}</code>').join(' ');
  return '<div class="txt-card" data-chave="' + esc(it.chave) + '">' +
    '<div class="txt-cab"><b>' + esc(it.rotulo) + '</b>' +
      '<span class="txt-vars">' + vars + '</span></div>' +
    '<div class="txt-ajuda">' + esc(it.ajuda) + '</div>' +
    '<textarea class="txt-area" data-chave="' + esc(it.chave) + '" rows="3">' +
      esc(it.texto) + '</textarea>' +
    '<div class="txt-erro" hidden></div>' +
    '</div>';
}

/* ---------- render e salvar ---------- */
let TEXTOS = {};

async function poCarregaTextos() {
  const { data, error } = await sb.from('po_wa_textos').select('chave,texto');
  if (error) { toast('Erro ao ler os textos: ' + error.message, true); return; }
  TEXTOS = {};
  (data || []).forEach(r => { TEXTOS[r.chave] = r.texto; });
  poRenderTextos();
}

/* A tela sai do CATALOGO, nao do que o banco devolveu: e o que garante que a
   saudacao (que esta no banco e o motor nunca le) nunca ganhe caixa de
   edicao e, por consequencia, nunca seja salva de volta. */
function poRenderTextos() {
  const box = document.querySelector('#txt-lista');
  if (!box) return;
  box.innerHTML = poTextosCatalogo()
    .map(c => poLinhaTexto(Object.assign({}, c, { texto: TEXTOS[c.chave] || '' })))
    .join('');
}

/* Valida TODAS as caixas antes de escrever qualquer coisa: salvar as boas e
   recusar a ruim em silencio deixaria o robo mandando uma mensagem quebrada
   sem ninguem saber. */
async function poSalvaTextos() {
  const areas = [...document.querySelectorAll('.txt-area')];
  let erro = false;
  areas.forEach(a => {
    const msg = poTextoValida(a.dataset.chave, a.value);
    const cx = a.parentElement.querySelector('.txt-erro');
    if (msg) { cx.textContent = msg; cx.hidden = false; a.classList.add('erro'); erro = true; }
    else { cx.textContent = ''; cx.hidden = true; a.classList.remove('erro'); }
  });
  if (erro) { toast('Corrija os textos marcados antes de salvar.', true); return; }

  // Sobe exatamente chave e texto: updated_at tem default no banco, e
  // calcular a hora aqui gravaria o relogio da maquina da cliente.
  const linhas = areas.map(a => ({ chave: a.dataset.chave, texto: a.value.trim() }));
  const { error } = await sb.from('po_wa_textos').upsert(linhas, { onConflict: 'chave' });
  if (error) { toast('Erro ao salvar: ' + error.message, true); return; }
  linhas.forEach(l => { TEXTOS[l.chave] = l.texto; });
  toast('Textos salvos. O robô passa a usar na próxima mensagem.');
}
