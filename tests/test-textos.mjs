import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

/* Extrai as funcoes puras do textos.js sem carregar o painel inteiro, no
   mesmo padrao de tests/test-revisao.mjs. Por isso NENHUMA constante de topo
   de arquivo pode ser dependencia das funcoes testadas: o catalogo mora
   DENTRO do poTextosCatalogo(). */
const src = readFileSync(new URL('../painel/textos.js', import.meta.url), 'utf8');
function pega(nome) {
  const m = src.match(new RegExp('function ' + nome + '\\([\\s\\S]*?\\n\\}'));
  assert.ok(m, nome + ' encontrada no textos.js');
  return m[0];
}
function pegaAsync(nome) {
  const m = src.match(new RegExp('async function ' + nome + '\\([\\s\\S]*?\\n\\}'));
  assert.ok(m, nome + ' (async) encontrada no textos.js');
  return m[0];
}
const ESC = 'function esc(s){return (s==null?"":String(s)).replace(/[&<>"]/g,' +
            'c=>({"&":"&amp;","<":"&lt;",">":"&gt;","\\"":"&quot;"}[c]));}';

const PURAS = ESC + pega('poTextosCatalogo') + pega('poTextoLimite') +
              pega('poLinhaTexto') + pega('poTextoValida');

const { poTextosCatalogo, poTextoLimite, poLinhaTexto, poTextoValida } = new Function(
  PURAS + '; return {poTextosCatalogo, poTextoLimite, poLinhaTexto, poTextoValida};'
)();

/* ============================================================
   O CATALOGO. Sao as chaves que o motor realmente le:
   envio_pdf, menu, perguntas, qualificado, sem_data (lib/wa-motor.php) e
   lembrete, lembrete_menu (lib/wa-timeout.php).
============================================================ */
const cat = poTextosCatalogo();
const chaves = cat.map(c => c.chave).sort();
assert.deepEqual(chaves,
  ['envio_pdf','lembrete','lembrete_menu','menu','perguntas','qualificado','sem_data'],
  'as 7 chaves que o motor le, sem a saudacao que ele ignora');
cat.forEach(c => {
  assert.ok(c.rotulo && c.rotulo.length > 3, c.chave + ' tem rotulo legivel');
  assert.ok(Array.isArray(c.vars), c.chave + ' declara as variaveis que aceita');
  assert.ok(Array.isArray(c.obrigatorias), c.chave + ' declara as obrigatorias');
  // Obrigatoria que nao esta na lista de aceitas seria impossivel de
  // satisfazer: a tela recusaria o texto pela falta e de novo pelo uso.
  c.obrigatorias.forEach(v => assert.ok(c.vars.indexOf(v) !== -1,
    c.chave + ': a obrigatoria {' + v + '} tambem esta entre as aceitas'));
});

// A saudacao e residuo do seed: existe no banco e o motor NUNCA a le.
// Se ela entrasse no catalogo, a tela pediria para a cliente escrever um
// texto que nunca sai no WhatsApp.
assert.equal(cat.find(c => c.chave === 'saudacao'), undefined,
  'saudacao fica fora do catalogo');

/* ============================================================
   A VALIDACAO. As palavras entre chaves sao trocadas na hora do envio
   (wa_texto, em lib/wa-motor.php). Apagar uma obrigatoria manda a mensagem
   sem o dado; usar uma que nao existe faz o motor apagar a palavra do texto
   (ele limpa todo /\{[a-z_]+\}/ que sobrou). Nos dois casos a cliente so
   descobriria pelo WhatsApp do cliente - por isso a recusa e ANTES de salvar.
============================================================ */
assert.equal(poTextoValida('envio_pdf', 'Segue o roteiro completo do {roteiro}.'), null,
  'texto com a variavel obrigatoria passa');
assert.ok(poTextoValida('envio_pdf', 'Segue o roteiro completo.'),
  'texto sem {roteiro} e recusado com mensagem');
assert.ok(poTextoValida('envio_pdf', 'Segue o {destino} completo.'),
  'variavel que nao existe e recusada');
assert.ok(poTextoValida('menu', ''), 'texto vazio e recusado');
assert.ok(poTextoValida('menu', '   '), 'so espaco e recusado');

/* GRAFIA DA VARIAVEL. A validacao casava /\{[a-z_]+\}/ e o wa_texto limpa com
   o MESMO regex, entao toda grafia fora de [a-z_] escapava das duas pontas e
   saia literal no WhatsApp do cliente. "{Nome}" com maiuscula e o que qualquer
   pessoa escreve depois de ponto final, ou o que o corretor do celular faz
   sozinho. Tem que ser recusada, e com o nome certo no erro. */
const maiuscula = poTextoValida('menu', 'Oi {Nome}, sobre qual viagem?');
assert.ok(maiuscula, '{Nome} com maiuscula e recusada, nao sai literal no WhatsApp');
assert.ok(maiuscula.includes('{Nome}'), 'e o erro mostra a grafia que a cliente escreveu');
const comEspaco = poTextoValida('menu', 'Oi { nome }, sobre qual viagem?');
assert.ok(comEspaco, '{ nome } com espaco e recusada');
assert.ok(comEspaco.includes('{ nome }'), 'e o erro mostra a grafia com o espaco');
const comDigito = poTextoValida('menu', 'Oi {nome2}, sobre qual viagem?');
assert.ok(comDigito, '{nome2} e recusada');
assert.ok(comDigito.includes('{nome2}'), 'e o erro nomeia a variavel inventada');

// {nome} e opcional em todas: a cliente pode preferir nao usar o nome do perfil
assert.equal(poTextoValida('menu', 'Sobre qual viagem voce quer saber?'), null,
  'nao usar {nome} e permitido');

/* Quem le a mensagem de erro e a cliente, nao um programador: ela precisa
   dizer QUAL variavel esta faltando, senao a tela recusa o texto sem dar o
   caminho do conserto. Este e o caso do texto que usa SO variaveis validas
   e mesmo assim esquece a obrigatoria - nao ha nada em vermelho para achar
   a olho. */
const faltou = poTextoValida('perguntas', 'Oi {nome}, voce ja viajou em grupo?');
assert.ok(faltou, 'obrigatoria ausente e recusada mesmo sem variavel invalida');
assert.ok(faltou.includes('{data}'), 'e a mensagem NOMEIA a variavel que falta');
const faltouPdf = poTextoValida('envio_pdf', 'Oi {nome}, segue o material.');
assert.ok(faltouPdf.includes('{roteiro}'), 'o mesmo vale no envio do PDF');

/* O catalogo tem que declarar o que o MOTOR realmente passa, senao a tela
   recusa uma variavel que funcionaria e a cliente le "nao existe" sobre algo
   que existe. Conferido ponto a ponto em lib/wa-motor.php e lib/wa-timeout.php:
   perguntas recebe nome, roteiro E data (wa-motor.php:390-393). */
assert.equal(poTextoValida('perguntas',
  'Oi {nome}, sobre o {roteiro}: 1. da para viajar em {data}? 2. voce ja viajou em grupo?'), null,
  '{roteiro} e aceito em perguntas, porque o motor o passa');
assert.equal(poTextoValida('perguntas',
  '1. Da para viajar em {data}? 2. Voce ja viajou em grupo?'), null,
  'e continua opcional: a mensagem vale sem ele (o PDF ja saiu com o nome do roteiro)');

/* A ORDEM DAS PERGUNTAS E CONTRATO IMPLICITO DO TEXTO. wa_respostas_numeradas
   (lib/wa-motor.php) casa \b1\b (.*?) \b2\b (.*) e atribui SEMPRE
   1 -> data, 2 -> grupo. Com as perguntas trocadas, quem responde
   "1. nao (nunca viajei em grupo) 2. sim" cai em data negativa: o motor grava
   status='perdido' e manda o sem_data, quebrando a regra permanente "so a
   pergunta da data desqualifica; quem nunca viajou em grupo e o cliente-alvo,
   nao um descarte" - e quebrando por um texto que a propria tela aprovou. */
const trocadas = poTextoValida('perguntas',
  '1. Voce ja viajou em grupo? 2. A viagem sai em {data}. Da para essa data?');
assert.ok(trocadas, 'perguntas trocadas de ordem sao recusadas');
assert.ok(/número 1/.test(trocadas), 'e o erro diz que a da data tem que ser a numero 1');

const semNumero = poTextoValida('perguntas',
  'Voce tem disponibilidade em {data}? E ja viajou em grupo?');
assert.ok(semNumero, 'perguntas sem numeracao sao recusadas');
assert.ok(/[Nn]umere/.test(semNumero), 'e o erro manda numerar as duas');

// So o "2" tambem nao serve: o motor precisa dos dois marcadores.
assert.ok(poTextoValida('perguntas', 'A viagem sai em {data}, da? 2. Ja viajou em grupo?'),
  'texto com o 2 e sem o 1 e recusado');

/* O texto que ESTA no banco (seed de 2026-09-11-whatsapp-motor.sql) tem que
   continuar valendo, senao a regra nova recusaria o que o robo ja manda. */
assert.equal(poTextoValida('perguntas', [
  'Para eu ja adiantar seu atendimento, me responde duas coisinhas:', '',
  '1. A viagem sai em {data}. Voce tem disponibilidade nessa data?', '',
  '2. Voce ja viajou em grupo alguma vez?',
].join(String.fromCharCode(10))), null,
  'o texto que ja esta no banco continua valido');

// A regra vale SO para as perguntas: as outras mensagens nao sao numeradas.
assert.equal(poTextoValida('menu', 'Sobre qual viagem voce quer saber?'), null,
  'a exigencia de numerar nao vaza para as outras chaves');

/* E continua recusado onde o motor NAO passa: qualificado e chamado so com
   nome (wa-motor.php:535), entao ali o {roteiro} sairia apagado do texto. */
const roteiroOndeNaoTem = poTextoValida('qualificado', 'Perfeito, {nome}! Vamos cuidar do {roteiro}.');
assert.ok(roteiroOndeNaoTem, '{roteiro} e recusado em qualificado, que o motor chama so com nome');
assert.ok(roteiroOndeNaoTem.includes('{roteiro}'), 'e a mensagem diz qual variavel nao existe ali');

// A recusa da variavel inexistente tambem tem que ensinar quais existem.
const naoExiste = poTextoValida('envio_pdf', 'Segue o {destino} completo do {roteiro}.');
assert.ok(naoExiste.includes('{destino}'), 'a mensagem diz qual variavel nao existe');
assert.ok(naoExiste.includes('{roteiro}') && naoExiste.includes('{nome}'),
  'e lista as que existem naquela mensagem');

/* TAMANHO. wa_send_list trunca em 1024 (lib/wa-send.php:145), mas
   wa_send_text e wa_send_document NAO truncam nada: legenda de PDF acima de
   1024 devolve 400, wa_envia_roteiro retorna 'falha_envio' e o cliente nunca
   recebe o roteiro, em silencio. O menu e truncado - e cortar o texto da
   cliente pela metade tambem e defeito. Os dois param em 1024; os demais vao
   como texto simples, teto 4096. O 1024 vale em dobro porque estes textos
   viram os templates da Meta, cujo corpo tambem para em 1024. */
assert.equal(poTextoLimite('envio_pdf'), 1024, 'legenda de PDF para em 1024');
assert.equal(poTextoLimite('menu'), 1024, 'corpo do menu de lista para em 1024');
['perguntas', 'qualificado', 'sem_data', 'lembrete', 'lembrete_menu'].forEach(c =>
  assert.equal(poTextoLimite(c), 4096, c + ' vai como texto simples, teto 4096'));

const pdf1024 = '{roteiro}' + 'a'.repeat(1015);
assert.equal(pdf1024.length, 1024, 'o caso de borda tem exatamente 1024');
assert.equal(poTextoValida('envio_pdf', pdf1024), null, 'exatamente 1024 passa');
const pdfEstourou = poTextoValida('envio_pdf', '{roteiro}' + 'a'.repeat(1016));
assert.ok(pdfEstourou, '1025 caracteres na legenda do PDF e recusado');
assert.ok(pdfEstourou.includes('1024'), 'e o erro diz qual e o limite');
assert.ok(pdfEstourou.includes('1025'), 'e diz quanto o texto tem hoje');
assert.ok(poTextoValida('menu', 'a'.repeat(1025)), 'o menu tambem para em 1024');

assert.equal(poTextoValida('qualificado', 'a'.repeat(4096)), null, 'texto simples vai ate 4096');
const simplesEstourou = poTextoValida('qualificado', 'a'.repeat(4097));
assert.ok(simplesEstourou, 'acima de 4096 e recusado');
assert.ok(simplesEstourou.includes('4096'), 'e o erro diz qual e o limite');

// O teto tambem e o maxlength do textarea: a cliente esbarra enquanto
// escreve, em vez de descobrir so ao salvar.
assert.ok(poLinhaTexto({chave:'envio_pdf', rotulo:'x', texto:'', vars:[]}).includes('maxlength="1024"'),
  'a caixa do envio_pdf limita em 1024');
assert.ok(poLinhaTexto({chave:'menu', rotulo:'x', texto:'', vars:[]}).includes('maxlength="1024"'),
  'a caixa do menu limita em 1024');
assert.ok(poLinhaTexto({chave:'lembrete', rotulo:'x', texto:'', vars:[]}).includes('maxlength="4096"'),
  'a caixa das demais limita em 4096');

// Chave fora do catalogo nunca e aceita - inclusive a saudacao, que o motor
// nao le: salvar um texto nela seria trabalho da cliente jogado fora.
assert.ok(poTextoValida('saudacao', 'Oi!'), 'saudacao nao e um texto editavel');
assert.ok(poTextoValida('inventada', 'Oi!'), 'chave desconhecida e recusada');

// Entradas degeneradas nao estouram a validacao.
assert.ok(poTextoValida('menu', null), 'texto nulo e recusado, nao estoura');
assert.ok(poTextoValida('menu', undefined), 'texto ausente e recusado, nao estoura');

/* ============================================================
   A LINHA. O texto e escrito pela cliente e sai no WhatsApp: no painel ele
   e DADO, nunca marcacao.
============================================================ */
const html = poLinhaTexto({chave:'menu', rotulo:'Menu de roteiros',
                           texto:'<img src=x onerror=alert(1)>', vars:['nome']});
assert.ok(!html.includes('<img src=x'), 'texto escapado');
assert.ok(html.includes('&lt;img'), 'aparece escapado, nao sumido');
assert.ok(html.includes('data-chave="menu"'), 'a linha carrega a chave');

// Item degenerado nao pode derrubar a tela inteira.
assert.ok(poLinhaTexto(null).includes('txt-card'), 'item nulo nao estoura a montagem');
assert.ok(poLinhaTexto({chave:'menu'}).includes('data-chave="menu"'),
  'item sem texto ainda monta a linha');

/* ============================================================
   A TELA: render e salvamento, com um DOM de mentira. Nada aqui toca a rede.
============================================================ */
function fakeArea(chave, valor) {
  const cx = { textContent: '', hidden: true };
  return {
    dataset: { chave }, value: valor, erro: cx,
    classList: { on: false, add() { this.on = true; }, remove() { this.on = false; } },
    parentElement: { querySelector: () => cx },
  };
}

function montaTela(opts) {
  const o = opts || {};
  const box = { innerHTML: '' };
  const areas = o.areas || [];
  const upserts = [];
  const avisos = [];
  const TEXTOS = o.textos || {};
  const rodar = new Function(
    'box', 'areas', 'upserts', 'avisos', 'TEXTOS', 'erro',
    'const document = { querySelector: () => box, querySelectorAll: () => areas };' +
    'const sb = { from(tabela){ return { upsert(linhas, opcoes){' +
    '  upserts.push({tabela, linhas, opcoes});' +
    '  return Promise.resolve({error: erro}); } }; } };' +
    'function toast(msg, err){ avisos.push({msg, err: !!err}); }' +
    PURAS + pega('poRenderTextos') + pegaAsync('poSalvaTextos') +
    '; return {poRenderTextos, poSalvaTextos};'
  )(box, areas, upserts, avisos, TEXTOS, o.erro || null);
  return { box, areas, upserts, avisos, TEXTOS, ...rodar };
}

/* O render sai do CATALOGO, nao do que o banco devolveu. E o que garante que
   a saudacao (que existe no banco e o motor nunca le) nunca ganhe caixa de
   edicao - e, por consequencia, nunca seja salva de volta. */
const tela = montaTela({ textos: {
  menu: 'Sobre qual viagem voce quer saber?',
  saudacao: 'Ola! Sou a Pereira Oliveira Turismo.',
} });
tela.poRenderTextos();
const desenhadas = [...tela.box.innerHTML.matchAll(/data-chave="([a-z_]+)"/g)].map(m => m[1]);
assert.deepEqual([...new Set(desenhadas)].sort(),
  ['envio_pdf','lembrete','lembrete_menu','menu','perguntas','qualificado','sem_data'],
  'a tela desenha as 7 chaves do catalogo');
assert.ok(!tela.box.innerHTML.includes('saudacao'),
  'a saudacao vinda do banco nao ganha caixa de edicao');
assert.ok(tela.box.innerHTML.includes('Sobre qual viagem'),
  'o texto que veio do banco aparece na caixa');

/* ---------- salvar ---------- */
const bons = [
  fakeArea('menu', ' Sobre qual viagem voce quer saber? '),
  fakeArea('envio_pdf', 'Oi {nome}, segue o roteiro do {roteiro}.'),
];
const salvou = montaTela({ areas: bons, textos: { saudacao: 'residuo do seed' } });
await salvou.poSalvaTextos();

assert.equal(salvou.upserts.length, 1, 'um unico upsert');
const up = salvou.upserts[0];
assert.equal(up.tabela, 'po_wa_textos', 'escreve na tabela dos textos');
assert.deepEqual(up.opcoes, { onConflict: 'chave' }, 'casa pela chave, que e unica');
assert.deepEqual(up.linhas.map(l => l.chave), ['menu', 'envio_pdf'], 'sobe as caixas da tela');

/* O upsert manda EXATAMENTE chave e texto. updated_at tem default no banco:
   calcular a hora no navegador da cliente gravaria o relogio da maquina
   dela - e qualquer campo inventado aqui volta como erro do PostgREST, com
   a mensagem crua no toast. */
up.linhas.forEach(l => assert.deepEqual(Object.keys(l).sort(), ['chave', 'texto'],
  l.chave + ': a linha sobe so chave e texto'));
assert.equal(up.linhas[0].texto, 'Sobre qual viagem voce quer saber?',
  'o texto sobe sem espaco sobrando nas pontas');

// A saudacao estava no TEXTOS (veio do banco) e NAO pode subir no upsert:
// ela nao esta no catalogo e o motor nao a le.
assert.ok(!up.linhas.some(l => l.chave === 'saudacao'),
  'a saudacao nunca entra no que e salvo');

assert.equal(salvou.avisos.length, 1, 'avisa que salvou');
assert.equal(salvou.avisos[0].err, false, 'e o aviso nao e de erro');
assert.equal(salvou.TEXTOS.menu, 'Sobre qual viagem voce quer saber?',
  'a memoria da tela acompanha o que foi gravado');

/* Texto invalido: NADA sobe para o banco, e o erro aparece na caixa certa.
   Salvar as caixas boas e recusar a ruim em silencio deixaria o robo
   mandando uma mensagem quebrada sem ninguem saber. */
const ruins = [
  fakeArea('menu', 'Sobre qual viagem voce quer saber?'),
  fakeArea('envio_pdf', 'Oi {nome}, segue o material.'),
];
const recusou = montaTela({ areas: ruins });
await recusou.poSalvaTextos();
assert.equal(recusou.upserts.length, 0, 'texto invalido nao chega ao banco');
assert.equal(ruins[1].erro.hidden, false, 'a caixa errada mostra o erro');
assert.ok(ruins[1].erro.textContent.includes('{roteiro}'), 'e diz qual variavel falta');
assert.equal(ruins[0].erro.hidden, true, 'a caixa certa nao ganha erro');
assert.equal(recusou.avisos.length, 1, 'avisa uma vez');
assert.equal(recusou.avisos[0].err, true, 'e o aviso e de erro');

// Corrigir limpa o erro anterior: sem isso a mensagem velha ficaria colada
// na tela depois de o texto ja estar certo.
ruins[1].value = 'Oi {nome}, segue o roteiro do {roteiro}.';
await recusou.poSalvaTextos();
assert.equal(recusou.upserts.length, 1, 'corrigido, agora salva');
assert.equal(ruins[1].erro.hidden, true, 'e o erro antigo some da tela');

/* Banco falhou: avisa o erro e NAO mente dizendo que salvou. A memoria da
   tela nao pode andar sozinha, senao a cliente sai daqui achando que o robo
   ja fala o texto novo. */
const caiu = [fakeArea('menu', 'Texto novo da cliente.')];
const falhou = montaTela({ areas: caiu, textos: { menu: 'texto antigo' },
                           erro: { message: 'permission denied' } });
await falhou.poSalvaTextos();
assert.equal(falhou.upserts.length, 1, 'tentou gravar');
assert.equal(falhou.TEXTOS.menu, 'texto antigo', 'a memoria nao anda quando o banco falha');
assert.equal(falhou.avisos.length, 1, 'avisa uma vez');
assert.equal(falhou.avisos[0].err, true, 'e o aviso e de erro');
assert.ok(falhou.avisos[0].msg.includes('permission denied'), 'repassa a mensagem do banco');
assert.ok(!/salvos/.test(falhou.avisos[0].msg), 'nao diz "salvos" depois de falhar');

/* ============================================================
   A LIGACAO COM O PAINEL.
============================================================ */
const app = readFileSync(new URL('../painel/app.js', import.meta.url), 'utf8');
const painel = readFileSync(new URL('../painel/index.html', import.meta.url), 'utf8');

/* Guarda de nulo, como na aba Revisao: o deploy e FTP manual, arquivo a
   arquivo, e subir o app.js antes do index.html faria $('#txt-salvar')
   devolver null, o .onclick estourar TypeError e o script parar - matando o
   painel INTEIRO, nao so a aba nova. */
assert.ok(/if\(\$\('#txt-salvar'\)\)\$\('#txt-salvar'\)\.on/.test(app),
  '#txt-salvar e ligado com guarda de nulo');

// A aba entra no mecanismo de troca de view e carrega os textos ao abrir.
assert.ok(/v==='textos'/.test(app), 'a view textos entra na troca de abas');
assert.ok(/typeof poCarregaTextos==='function'/.test(app),
  'e chama poCarregaTextos so se o arquivo tiver carregado');

assert.ok(/data-view="textos"/.test(painel), 'a aba existe na navegacao');
assert.ok(/id="view-textos"/.test(painel), 'e a secao correspondente');
assert.ok(/id="txt-lista"/.test(painel), 'com o container das caixas');

/* A edicao do index.html e ADITIVA: os scripts que ja estavam ali tem que
   continuar todos. Um <script> perdido num merge derruba uma aba inteira em
   producao sem erro visivel no HTML. */
for (const s of ['config.js', 'video-encode.js', 'fone.js', 'app.js', 'clientes.js',
                 'funil.js', 'conversa.js', 'importar-contatos.js', 'revisao.js',
                 'textos.js']) {
  assert.ok(new RegExp('src="' + s.replace('.', '\\.') + '(\\?v=\\d+)?"').test(painel),
    s + ' continua carregado no painel');
}

// O cache-buster e travado em tests/test-cache-buster.mjs, amarrado ao SHA do
// arquivo. Aqui ficava um pino no numero (['app.js', 8]) que passou verde
// enquanto o app.js ja estava alterado e o ?v= parado - que e exatamente como
// a aba de textos foi ao ar mostrando a tela de Roteiros.

console.log('test-textos OK');
