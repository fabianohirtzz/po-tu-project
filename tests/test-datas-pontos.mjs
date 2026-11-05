import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

/* ============================================================
   Todo lugar que mostra a data de um roteiro chama o helper, e nenhum
   escreve a conta na mao.

   TRAVA NO PONTO DE CHAMADA, nao so na funcao pura. E a regra que este
   projeto aprendeu duas vezes por defeito real: a funcao pura travada e o
   chamador livre deixou o filtro wa_id da conversa sumir num commit que
   prometia outra coisa, e deixou uma Task passar verde reintroduzindo o
   proprio bug que ela corrigia.

   A LISTA COMPLETA, nao a lista do que eu lembro de ter mexido: em
   13/09/2026 um deploy conferiu so os arquivos que lembrava de ter enviado
   e a aba nova foi ao ar desenhando a tela errada.

   Normalizacao do fim de linha na LEITURA (core.autocrlf=true neste repo).
============================================================ */

const raiz = join(import.meta.dirname, '..');
const le = rel => readFileSync(join(raiz, rel), 'utf8').split('\r\n').join('\n');

/* Os 9 pontos que mostram a string de data que a pessoa le. Para cada um: o
   arquivo, o trecho em que ele vive (funcao, ou o arquivo inteiro), e qual
   helper ele tem de chamar. */
const PONTOS = [
  ['index.php',                    'po_home_viagens_html', 'po_quando('],
  ['llms.php',                     'po_llms_txt',         'po_quando('],
  ['roteiro.php',                  'po_hero_chips',       'po_quando('],
  ['roteiro.php',                  'po_outros_roteiros',  'po_quando('],
  ['roteiros.php',                 'po_roteiros_html',    'po_quando('],
  ['assets/js/historia.js',        null,                  'poQuando('],
  ['assets/js/main.js',            null,                  'poQuando('],
  ['assets/js/roteiros-shared.js', 'poCard',              'poQuando('],
  ['painel/app.js',                null,                  'poQuando('],
];

/* O corpo de uma funcao PHP/JS nomeada, do cabecalho ate a chave que fecha
   na coluna zero (PHP) ou com dois espacos (o IIFE do roteiros-shared). */
const corpo = (fonte, nome) => {
  if (nome === null) return fonte;
  const re = new RegExp('(?:function |window\\.)' + nome + '\\s*=?\\s*(?:function\\s*)?\\([\\s\\S]*?\\n(?:\\}|  \\};)');
  const m = re.exec(fonte);
  assert.ok(m, 'achou o corpo de ' + nome);
  return m[0];
};

for (const [arq, fn, helper] of PONTOS) {
  const c = corpo(le(arq), fn);
  assert.ok(c.includes(helper), `${arq}${fn ? ' / ' + fn : ''} chama ${helper}`);
}

/* O NEGATIVO: nenhum desses arquivos escreve mais a conta na mao. Sem isto,
   alguem acrescenta a chamada ao helper e deixa o inline logo abaixo, e
   metade dos cards segue com o comportamento velho.

   Fora da lista de proposito:
     lib/po-datas.php   e o helper, e o unico lugar onde a conta mora
     lib/po-data.php    po_ano precisa do TEXTO da data, nao da string de
                        exibicao, e tem o proprio teste de comportamento
     index.php          po_data_saida, pelo mesmo motivo (ver abaixo)
     lib/wa-fluxo.php, lib/wa-motor.php   o robo, que e a Tarefa 6 */
const INLINE = /data_label[\s\S]{0,80}?periodo|periodo[\s\S]{0,80}?data_label/;
for (const arq of ['roteiros.php', 'assets/js/historia.js', 'assets/js/main.js']) {
  assert.ok(!INLINE.test(le(arq)), arq + ' nao escreve mais a conta na mao');
}
/* Nos tres arquivos que TAMBEM tem derivacao de data legitima, a conferencia
   e por funcao, nao por arquivo. */
for (const [arq, fn] of [['index.php', 'po_home_viagens_html'], ['roteiro.php', 'po_hero_chips'],
                         ['roteiro.php', 'po_outros_roteiros'], ['assets/js/roteiros-shared.js', 'poCard'],
                         ['llms.php', 'po_llms_txt']]) {
  assert.ok(!INLINE.test(corpo(le(arq), fn)), arq + ' / ' + fn + ' nao escreve mais a conta na mao');
}
/* O painel: a linha do card. */
assert.ok(!/r\.data_label\s*\|\|\s*r\.periodo/.test(le('painel/app.js')), 'painel/app.js nao escreve mais a conta na mao');

/* ---------- Review Focus 3: semanal com datas_texto vazio ----------
   A tag aparece e nao ha data nenhuma. Nenhum renderizador pode quebrar, e
   nenhum pode imprimir o rotulo "Proxima saida" orfao: po_quando cai em
   periodo, que para um semanal novo e vazio, entao a data sai vazia - como
   ja sai hoje para qualquer roteiro sem data. Provado nas tres
   implementacoes pelo tests/test-datas-semanal.mjs; aqui fica a trava de que
   o rotulo nunca e escrito FORA do helper. */
const HELPERS = ['lib/po-datas.php', 'painel/datas.js', 'assets/js/roteiros-shared.js'];
for (const arq of HELPERS) {
  assert.ok(le(arq).includes('Próxima saída'), arq + ' e um dos helpers e escreve o rotulo');
}
for (const arq of ['index.php', 'llms.php', 'roteiro.php', 'roteiros.php', 'painel/app.js',
                   'assets/js/main.js', 'assets/js/historia.js', 'lib/po-data.php',
                   'lib/wa-fluxo.php', 'lib/wa-motor.php']) {
  assert.ok(!le(arq).includes('Próxima saída'), arq + ' nao escreve o rotulo por conta propria');
}

console.log('datas-pontos ok (' + PONTOS.length + ' pontos de exibicao)');
