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

/* A reserva e retomavel: o endpoint devolve o publico em pedacos e a tela
   continua ate 'completo'. Sem o laco, uma base de 800 pessoas viraria
   campanha com publico parcial e ninguem saberia quem ficou de fora. */
assert.ok(/poCampPost\(\s*['"]reservar['"]/.test(src),
  'a tela sabe continuar a reserva em lotes');

// O painel e servido com cache de 1 mes: arquivo novo precisa de ?v=.
const painel = readFileSync(join(raiz, 'painel/index.html'), 'utf8');
assert.ok(/src="campanhas\.js\?v=\d+"/.test(painel), 'campanhas.js entra com cache-buster');
assert.ok(/data-view="campanhas"/.test(painel), 'a aba Transmissao existe no menu');

console.log('test-campanhas OK');
