import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { join } from 'node:path';

/* ============================================================
   Cache-buster do SITE PUBLICO. Irmao de tests/test-cache-buster.mjs, que
   so cobre o painel.

   POR QUE ELE EXISTE, e e um defeito medido em producao em 05/11/2026:
   o `assets/css/status-tag.css` ganhou a tag "Saidas semanais" e o `?v=` das
   paginas ficou parado. O arquivo em producao estava em `?v=3` desde 22/10,
   com `Cache-Control: max-age=2592000` (30 dias), e subir a versao nova na
   MESMA URL nao faz navegador nenhum pedir de novo. Sem a regra
   `.st-tag--semanal`, `--st-ico` fica indefinida, a mascara do `::before`
   vira declaracao invalida e a tag sai como um QUADRADO SOLIDO ao lado do
   texto, numa pilula transparente - na home, em /roteiros, no hero, na
   Nossa Historia e no /link. E o bug de 13/09/2026 outra vez, do lado que
   nao tinha trava.

   No mesmo deploy, `main.js` e `historia.js` passaram a chamar poQuando e
   tambem ficaram sem bump: com cache quente eles seguiriam imprimindo a
   conta antiga e o roteiro semanal apareceria SEM DATA, sem erro no
   console.

   Duas regras:
     1. versao unica por arquivo em todas as paginas (o roteiros-shared.js
        esteve em ?v=4 em duas paginas e ?v=6 em tres, o que significa que
        metade dos visitantes rodava codigo de outra epoca);
     2. arquivo alterado sem bump fica vermelho, pelo SHA.

   Hash do conteudo NORMALIZADO: com core.autocrlf=true o mesmo arquivo
   nasce LF e vira CRLF no checkout, e a trava nao pode depender disso.
============================================================ */

const raiz = join(import.meta.dirname, '..');
const le = rel => readFileSync(join(raiz, rel), 'utf8');
const sha = rel => createHash('sha256').update(le(rel).split('\r').join('')).digest('hex').slice(0, 16);

const PAGINAS = readdirSync(raiz).filter(f => /\.(html|php)$/.test(f));
assert.ok(PAGINAS.length >= 8, 'achou as paginas do site: ' + PAGINAS.length);

/* O que cada pagina pede, lido do HTML e nao de uma lista paralela que
   envelhece sozinha. Caminho relativo e absoluto contam como o mesmo
   arquivo. */
const pedidos = new Map();   // 'assets/css/x.css' -> Map(versao -> [paginas])
for (const pag of PAGINAS) {
  for (const m of le(pag).matchAll(/["'(]\/?(assets\/(?:css|js)\/[\w.-]+\.(?:css|js))\?v=(\d+)/g)) {
    if (!pedidos.has(m[1])) pedidos.set(m[1], new Map());
    const v = pedidos.get(m[1]);
    if (!v.has(m[2])) v.set(m[2], []);
    v.get(m[2]).push(pag);
  }
}
assert.ok(pedidos.size >= 4, 'achou os assets versionados: ' + pedidos.size);

// 1. uma versao so por arquivo
for (const [arq, versoes] of pedidos) {
  assert.equal(versoes.size, 1,
    arq + ' pedido em versoes diferentes: ' +
    [...versoes].map(([v, ps]) => '?v=' + v + ' em ' + ps.join(', ')).join(' | '));
}

const lock = JSON.parse(le('assets/assets-lock.json'));

for (const [arq, versoes] of pedidos) {
  const v = Number([...versoes.keys()][0]);
  const nome = arq.replace(/^assets\/(css|js)\//, '');
  assert.ok(lock[nome], arq + ' tem ?v= nas paginas mas nao esta no assets/assets-lock.json');
  assert.equal(lock[nome].v, v, `${arq}: as paginas pedem ?v=${v} e o lock diz ?v=${lock[nome].v}`);
  assert.equal(lock[nome].sha, sha(arq),
    `${arq} MUDOU e o ?v= continua em ${v}.\n` +
    `      Suba o ?v= em TODAS as paginas que o pedem, troque o sha no\n` +
    `      assets/assets-lock.json para "${sha(arq)}" e SUBA O ARQUIVO NO FTP.`);
}

// lock sem consumidor e lock que envelhece em silencio
for (const nome of Object.keys(lock)) {
  if (nome.startsWith('_')) continue;
  assert.ok([...pedidos.keys()].some(a => a.endsWith('/' + nome)),
    nome + ' esta no assets-lock.json e nenhuma pagina o pede');
}

console.log('cache-buster-site ok (' + pedidos.size + ' assets em ' + PAGINAS.length + ' paginas)');
