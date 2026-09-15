/* Trava de cache-buster do painel.
 *
 * O bug que este teste existe para impedir aconteceu em producao no dia
 * 13/09/2026: a branch da faxina alterou painel/app.js (ganhou o ramo da aba
 * "textos") e painel/painel.css, o ?v= dos dois ficou parado, e os dois nao
 * subiram no FTP. O index.html novo foi ao ar anunciando a aba; o app.js que
 * responde ao clique era o antigo, caia no `else` final e desenhava Roteiros.
 * A aba "Textos do robo" mostrava a tela de Roteiros.
 *
 * O teste que deveria ter pego isso fixava o numero na mao
 * (`[['app.js', 8], ...]`), entao ele afirmava exatamente a condicao que o bug
 * precisava para passar: com o app.js ja alterado e o pino em 8, ficou verde.
 *
 * Numero fixo nao protege nada. O que protege e amarrar a versao ao CONTEUDO:
 * se o arquivo muda e o ?v= nao sobe, isto fica vermelho. */
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const raiz   = join(dirname(fileURLToPath(import.meta.url)), '..');
const painel = readFileSync(join(raiz, 'painel/index.html'), 'utf8');
const lock   = JSON.parse(readFileSync(join(raiz, 'painel/assets-lock.json'), 'utf8'));

/* O hash e calculado sobre o conteudo NORMALIZADO, com os CR removidos.
   Motivo: este repo roda com core.autocrlf=true no Windows, entao o git guarda LF
   e materializa CRLF no disco - e um `git checkout` ou um merge pode trocar o fim
   de linha sem ninguem ter tocado no arquivo. Hashear os bytes crus fazia o lock
   registrar valores que dependiam de COMO o arquivo tinha sido materializado, e o
   teste acusava mudanca onde nao houve nenhuma. Aconteceu de verdade no merge do
   plano 4: o painel.css ficou vermelho com o conteudo identico.
   Fim de linha nao muda o comportamento de CSS nem de JS no navegador, entao
   normalizar nao enfraquece a trava: arquivo com conteudo alterado continua
   mudando de hash. */
const sha = arq => createHash('sha256')
  .update(readFileSync(join(raiz, 'painel', arq), 'utf8').split('\r').join(''))
  .digest('hex').slice(0, 16);

// O que o index.html realmente pede, lido do HTML e nao de uma lista paralela
// que envelhece sozinha.
const pedidos = new Map(
  [...painel.matchAll(/(?:src|href)="([a-z0-9.-]+\.(?:js|css))\?v=(\d+)"/g)]
    .map(m => [m[1], Number(m[2])])
);

// 1) Todo asset versionado no HTML tem entrada no lock. Sem isto, um arquivo
//    novo entra no painel sem guarda nenhuma e repete a historia.
for (const arq of pedidos.keys()) {
  assert.ok(lock[arq], arq + ' tem ?v= no index.html mas nao esta no assets-lock.json');
}

// 2) Todo asset do lock continua sendo pedido, na versao que o lock declara.
for (const [arq, esperado] of Object.entries(lock)) {
  if (arq.startsWith('_')) continue;
  assert.ok(pedidos.has(arq), arq + ' esta no lock mas o index.html nao carrega mais');
  assert.equal(pedidos.get(arq), esperado.v,
    arq + ': index.html pede ?v=' + pedidos.get(arq) + ' e o lock diz ?v=' + esperado.v);
}

// 3) O CONTEUDO do arquivo e o que o lock registrou. Esta e a assercao que
//    teria ficado vermelha na faxina.
for (const [arq, esperado] of Object.entries(lock)) {
  if (arq.startsWith('_')) continue;
  const atual = sha(arq);
  assert.equal(atual, esperado.sha,
    'painel/' + arq + ' MUDOU e o ?v= continua em ' + esperado.v + '.\n' +
    '      Suba o ?v= no painel/index.html, troque o sha no painel/assets-lock.json\n' +
    '      para "' + atual + '" e SUBA O ARQUIVO NO FTP.');
}

// 4) Nenhum .js/.css do painel fica de fora do index.html sem querer. Serve de
//    lembrete quando um arquivo novo e criado e ninguem o carregou ainda.
const soltos = readdirSync(join(raiz, 'painel'))
  .filter(f => /\.(js|css)$/.test(f))
  .filter(f => !pedidos.has(f) && f !== 'config.js' && f !== 'video-encode.js');
assert.deepEqual(soltos, [],
  'arquivos em painel/ que ninguem carrega no index.html: ' + soltos.join(', '));

console.log('test-cache-buster OK');
