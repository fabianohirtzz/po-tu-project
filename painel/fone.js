/* ============================================================
   Normalizacao de telefone brasileiro para E.164 (+55DDNNNNNNNNN).
   Porte 1:1 de lib/wa-fone.php: as duas linguagens PRECISAM concordar,
   senao a mesma pessoa vira ficha diferente conforme quem gravou.
============================================================ */

function poE164(bruto) {
  // DDDs que existem de fato no Brasil (faixa 11..99 tem buracos). A
  // constante vive dentro da funcao (em vez de no topo do arquivo) porque
  // os testes JS deste projeto extraem funcoes isoladas por regex, sem
  // carregar o arquivo inteiro como o PHP faz com require().
  const PO_DDDS = new Set([
    11,12,13,14,15,16,17,18,19, 21,22,24,27,28,
    31,32,33,34,35,37,38, 41,42,43,44,45,46,47,48,49,
    51,53,54,55, 61,62,63,64,65,66,67,68,69,
    71,73,74,75,77,79, 81,82,83,84,85,86,87,88,89,
    91,92,93,94,95,96,97,98,99,
  ]);
  // Numero local brasileiro COMPLETO: celular de 9 digitos comecando em 9 ou
  // fixo de 8 comecando entre 2 e 5. Mesma constante do WA_RE_LOCAL do PHP.
  const PO_RE_LOCAL = /^(9\d{8}|[2-5]\d{7})$/;
  let d = String(bruto == null ? '' : bruto).replace(/\D+/g, '');
  if (d === '') return null;
  // Prefixo internacional discado ("0048 ..."), que a agenda do celular
  // exporta. So tira quando sobram 10 digitos ou mais.
  if (d.length >= 12 && d.slice(0, 2) === '00') d = d.slice(2);
  if (d.length >= 12 && d.slice(0, 2) === '55') d = d.slice(2);
  // Zero de operadora antes do DDD ("048 99999-0001"). So tira quando o que
  // sobra JA e um numero brasileiro completo - sem esse rigor "0800 123 4567"
  // viraria DDD 80 e "(01) 99999-9999" ganharia o nono digito e entraria como
  // Campinas. Ver o comentario longo em lib/wa-fone.php.
  if (d.length >= 11 && d[0] === '0') {
    const sem = d.slice(1);
    if (PO_DDDS.has(parseInt(sem.slice(0, 2), 10)) && PO_RE_LOCAL.test(sem.slice(2))) d = sem;
  }
  if (d.length > 11 || d.length < 10) return null;
  const ddd = parseInt(d.slice(0, 2), 10);
  let resto = d.slice(2);
  if (!PO_DDDS.has(ddd)) return null;
  if (resto.length === 8 && resto[0] >= '6') resto = '9' + resto;
  if (!PO_RE_LOCAL.test(resto)) return null;
  return '+55' + ddd + resto;
}

function poEhCelular(e164) {
  return /^\+55\d{2}9\d{8}$/.test(String(e164 == null ? '' : e164));
}
