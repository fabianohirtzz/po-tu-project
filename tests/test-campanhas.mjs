/* A tela da transmissao e o ultimo ponto antes de gastar dinheiro da cliente.
   Spec 8.2: "a tela mostra quantas pessoas e quanto vai custar antes do
   envio. Nada dispara sem esse aviso." */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const raiz = join(dirname(fileURLToPath(import.meta.url)), '..');
const src  = readFileSync(join(raiz, 'painel/campanhas.js'), 'utf8');
const pega = n => {
  const m = src.match(new RegExp(String.raw`function ${n}\([\s\S]*?\n\}`));
  if (!m) throw new Error('nao achei ' + n);
  return m[0];
};
const F = new Function(
  pega('poCampCustoBRL') + pega('poCampResumoTexto') + pega('poCampPodeEnviar') +
  pega('poCampValidaCorpo') + pega('poCampAviso') +
  ';return {custo:poCampCustoBRL, resumo:poCampResumoTexto, pode:poCampPodeEnviar,' +
  ' corpo:poCampValidaCorpo, aviso:poCampAviso};')();

// Centavos inteiros viram reais na tela, sem float.
assert.equal(F.custo(0),    'R$ 0,00');
assert.equal(F.custo(31),   'R$ 0,31');
assert.equal(F.custo(3100), 'R$ 31,00');
assert.equal(F.custo(24025),'R$ 240,25');

// O resumo nomeia CADA balde: e como a cliente confere as defesas.
const r = F.resumo({total:23, duplicados:2, saiu:1, nao_cliente:5,
                    nao_revisado:3, sem_telefone:615, sem_celular:138});
assert.ok(/23/.test(r),  'mostra quantos entram');
assert.ok(/615/.test(r), 'mostra quantos ficaram sem telefone');
assert.ok(/saiu|SAIR/i.test(r), 'nomeia o balde de quem pediu para sair');
assert.ok(/revis/i.test(r), 'nomeia o balde de quem falta revisar');

/* Nada dispara com publico vazio, nem sem o custo calculado. E a trava que
   impede o clique que gasta sem avisar. */
assert.equal(F.pode({total:0,  custo_centavos:0}),    false, 'publico vazio nao dispara');
assert.equal(F.pode({total:23, custo_centavos:713}),  true,  'publico com custo dispara');
assert.equal(F.pode({total:23, custo_centavos:null}), false, 'sem custo calculado nao dispara');
assert.equal(F.pode({total:23}),                      false, 'sem o campo de custo nao dispara');

/* O AVISO e a spec 8.2 escrita: a frase que a cliente le e confirma tem que
   trazer o numero de pessoas E o dinheiro. Um "Confirmar?" pelado seria a
   tela disparando sem avisar, que e exatamente o que a spec proibe. */
const aviso = F.aviso({total:23, custo_centavos:713});
assert.ok(/\b23\b/.test(aviso), 'o aviso diz quantas pessoas recebem');
assert.ok(/R\$ 7,13/.test(aviso), 'o aviso diz quanto custa, em reais');

/* O corpo da campanha TEM que trazer a saida: e a defesa 3 da spec 8.1, e a
   promessa que o motor cumpre na Task 6. Sem a frase, a pessoa nao sabe que
   pode sair e o descadastro vira denuncia na Meta. */
assert.equal(F.corpo('Novo roteiro para Portugal em maio.').includes('SAIR'), true,
  'texto sem a palavra SAIR e recusado, e o erro diz qual frase incluir');
assert.equal(
  F.corpo('Novo roteiro para Portugal em maio. Responda SAIR para não receber mais.'),
  null, 'texto com a saida e aceito');
assert.ok(F.corpo('').includes('Escreva'), 'texto vazio e recusado');
assert.ok(/1024/.test(F.corpo('x'.repeat(1025) + ' SAIR')),
  'acima de 1024 e recusado, porque a Meta recusa a mensagem inteira');
assert.ok(F.corpo('Vamos sair juntos nessa viagem!').includes('SAIR'),
  'a palavra minuscula no meio da frase NAO conta como aviso de saida');

/* O caminho do clique. Estas tres travas sao de FONTE porque o resto da tela
   toca o DOM e a rede, e o que se perde numa refatoracao e justamente a
   ordem: validar depois de postar, ou postar sem confirmar. */
const enviar = src.match(/async function poCampEnviar\(\)[\s\S]*?\n\}/);
assert.ok(enviar, 'existe a funcao do clique de enviar');
const passo = enviar[0];
const iValida  = passo.indexOf('poCampValidaCorpo');
const iAviso   = passo.indexOf('poCampAviso');
const iPost    = passo.search(/poCampPost\(\s*['"]criar['"]/);
assert.ok(iValida >= 0 && iAviso >= 0 && iPost >= 0,
  'o clique valida o corpo, monta o aviso e so entao posta modo=criar');
assert.ok(iValida < iPost, 'a validacao do corpo vem ANTES de criar a campanha');
assert.ok(iAviso  < iPost, 'a confirmacao com pessoas e custo vem ANTES de criar a campanha');
assert.ok(/confirm\(/.test(passo), 'o envio passa por uma confirmacao explicita');

/* O numero confirmado tem que VIAJAR para o servidor. Sem ele o aviso de
   custo existe mas nao prende ninguem: a tela confirma sobre uma previa que
   so e recalculada ao entrar na aba, e o servidor refaz a conta do zero. Com
   duas abas abertas, ela confirma um numero e a campanha sai com outro. */
/* O `:` faz parte da assercao. Sem ele o teste casava o COMENTARIO que
   explica o campo, e apagar o campo de verdade deixava a suite verde. */
assert.ok(/total_confirmado\s*:/.test(passo),
  'o clique manda o total que a cliente confirmou, para o servidor recusar se a lista mudou');

/* A reserva e retomavel: o endpoint devolve o publico em pedacos e a tela
   continua ate 'completo'. Sem o laco, uma base de 800 pessoas viraria
   campanha com publico parcial e ninguem saberia quem ficou de fora. */
assert.ok(/poCampPost\(\s*['"]reservar['"]/.test(src),
  'a tela sabe continuar a reserva em lotes');

/* ---------- o laco de reserva nao pode insistir sem progresso ----------
   Com a escrita falhando, cada rodada devolve reservados:0, ja_existiam:0 e
   completo:false. Insistir as 200 voltas sao 200 leituras da base inteira e
   40 mil POSTs contra o mesmo Supabase que ja esta mal. */
const pegaAsync = n => {
  const m = src.match(new RegExp(String.raw`async function ${n}\([\s\S]*?\n\}`));
  if (!m) throw new Error('nao achei ' + n);
  return m[0];
};
const fabricaLaco = (post) => new Function('poCampPost', 'poCampMsg', 'toast',
  pegaAsync('poCampReservaAteOFim') + ';return poCampReservaAteOFim;')(
    post, () => {}, () => {});

/* O duble devolve EXATAMENTE os campos do cok() de reservar. Fabricar um
   campo aqui e o jeito mais facil de escrever um teste que afirma
   comportamento que o codigo real nao produz - foi assim que a metade
   `ja_existiam` desta guarda nasceu morta e passou verde. */
const respostaReservar = (extra) => Object.assign(
  { campanha_id: 'C1', total: 5, reservados: 0, ja_existiam: 0,
    reservados_total: 0, faltam: 5, erros: 0, completo: false }, extra);

let chamadas = 0;
const paradoLaco = fabricaLaco(async () => {
  chamadas++;
  return respostaReservar({});
});
await paradoLaco('C1', respostaReservar({}));
assert.equal(chamadas, 1,
  'rodada que nao reservou ninguem para o laco na hora, em vez de insistir 200 vezes');

/* OUTRA ABA AVANCOU. A aba B ve 500 faltando, pede 200, e a aba A ja tinha
   pegado esses 200: volta reservados=0 com ja_existiam=200. A lista esta indo
   bem, entao o laco TEM que continuar - parar aqui escreveria "a lista ficou
   incompleta e nada foi enviado" no meio de uma reserva saudavel. E o cenario
   de duas abas, que e o mesmo desta rodada inteira de correcao. */
let voltas = 0;
const outraAbaLaco = fabricaLaco(async () => {
  voltas++;
  return voltas < 3
    ? respostaReservar({ reservados: 0, ja_existiam: 200, faltam: 300, reservados_total: 200 * voltas })
    : respostaReservar({ reservados: 0, ja_existiam: 300, faltam: 0, reservados_total: 500, completo: true });
});
await outraAbaLaco('C1', respostaReservar({ reservados: 200, ja_existiam: 0, faltam: 300 }));
assert.equal(voltas, 3,
  'rodada em que OUTRA ABA reservou (ja_existiam > 0) e progresso: o laco continua');

// E o caminho que AVANCA continua indo ate o fim.
let passos = 0;
const andandoLaco = fabricaLaco(async () => {
  passos++;
  return passos < 3
    ? { completo: false, reservados: 2, ja_existiam: 0, faltam: 2, total: 6, reservados_total: 2 * passos }
    : { completo: true,  reservados: 2, ja_existiam: 0, faltam: 0, total: 6, reservados_total: 6 };
});
await andandoLaco('C1', { completo: false, reservados: 2, ja_existiam: 0,
                          faltam: 4, total: 6, reservados_total: 2 });
assert.equal(passos, 3, 'o laco que avanca segue ate a lista fechar');

/* ---------- o duble nao pode inventar campo que o endpoint nao manda ----------
   Este e o defeito que o plano inteiro vem cacando: teste que afirma
   comportamento que o codigo real nao produz. A guarda acima le atual.X; se o
   cok() de `reservar` nao mandar X, Number(undefined) > 0 e sempre falso e
   metade da guarda nasce morta - com o teste verde, porque o duble fabricou o
   campo. Aqui o contrato e conferido contra o FONTE do endpoint. */
const php = readFileSync(join(raiz, 'campanha.php'), 'utf8');
const laco = pegaAsync('poCampReservaAteOFim');
const lidos = [...new Set([...laco.matchAll(/\batual\.([a-z_]+)/g)].map(m => m[1]))];
assert.ok(lidos.length >= 4, 'o laco le varios campos da resposta (achou: ' + lidos + ')');


// Os cok() da reserva sao os que carregam reservados_total.
const blocos = [...php.matchAll(/cok\(\[[\s\S]*?\n\s*\]\);/g)]
  .map(m => m[0]).filter(b => b.includes("'reservados_total'"));
assert.equal(blocos.length, 2, 'criar e reservar respondem o andamento da reserva');
for (const bloco of blocos) {
  const mandados = [...bloco.matchAll(/'([a-z_]+)'\s*=>/g)].map(m => m[1]);
  for (const campo of lidos) {
    assert.ok(mandados.includes(campo),
      'o laco le "' + campo + '" e o endpoint NAO manda esse campo: a guarda fica morta ' +
      'em producao e verde no teste. Campos mandados: ' + mandados.join(', '));
  }
}

/* A ligacao dos botoes precisa da guarda de readyState, no padrao do
   painel/importar-contatos.js. Hoje o script e classico e esta no fim do
   corpo, entao o DOMContentLoaded ainda vem; no dia em que alguem puser
   `defer` ou mover a tag, o evento ja terá passado e NENHUM botao da aba
   responderia - em silencio, sem erro no console. */
assert.ok(/document\.readyState\s*===\s*['"]loading['"]/.test(src),
  'a ligacao dos botoes tem a guarda de readyState');
assert.ok(/addEventListener\(\s*['"]DOMContentLoaded['"]\s*,\s*poCampLiga\s*\)/.test(src),
  'e ainda liga no DOMContentLoaded quando o documento esta carregando');
assert.ok(/\}\s*else\s*\{[\s\S]{0,40}?poCampLiga\(\);/.test(src),
  'e liga na hora quando o documento ja carregou');

/* wa_camp_envia() manda SEMPRE um parametro. Modelo aprovado na Meta com zero
   ou com dois faz a Graph recusar TODO destinatario, e falha e terminal: o
   indice unico impede re-reservar, entao a campanha inteira queima e so resta
   recriar. Nao custa dinheiro, mas custa a campanha - e a unica defesa
   possivel e a tela dizer isso antes. */
const painelHtml = readFileSync(join(raiz, 'painel/index.html'), 'utf8');
assert.ok(/exatamente uma vari/i.test(painelHtml),
  'a aba avisa que o modelo da Meta precisa ter exatamente uma variavel');

/* Falha e TERMINAL. Sem esta frase a cliente olharia "3 com falha" e esperaria
   por um conserto automatico que nao existe: nenhuma varredura reenvia, porque
   o indice unico (campanha, pessoa) impede reservar a mesma pessoa de novo. */
assert.ok(/não é reenviado|nao e reenviado/i.test(src),
  'a tela avisa que quem ficou com falha nao e reenviado por esta campanha');

// O painel e servido com cache de 1 mes: arquivo novo precisa de ?v=.
const painel = readFileSync(join(raiz, 'painel/index.html'), 'utf8');
assert.ok(/src="campanhas\.js\?v=\d+"/.test(painel), 'campanhas.js entra com cache-buster');
assert.ok(/data-view="campanhas"/.test(painel), 'a aba Transmissao existe no menu');

console.log('test-campanhas OK');
