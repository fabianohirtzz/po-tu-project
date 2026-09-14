#!/usr/bin/env bash
# Confere o painel publicado contra o repo, arquivo por arquivo.
#
# Existe por causa do dia 13/09/2026: o deploy subiu painel/index.html (que
# passou a pedir app.js?v=8) mas NAO subiu painel/app.js nem painel/painel.css.
# A query string nao muda o arquivo no servidor, entao o ?v= novo continuou
# servindo o conteudo velho. A aba "Textos do robo" foi ao ar desenhando a tela
# de Roteiros. A verificacao daquele deploy conferiu so os arquivos que EU tinha
# subido - por isso ela nao viu nada.
#
# A regra que este script encarna: conferir a LISTA COMPLETA, nunca a lista do
# que voce lembra de ter mandado.
#
# Uso: bash scripts/confere-deploy.sh
set -u
BASE="https://pereiraoliveiraturismo.com.br/painel"
RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

falhas=0
# A lista sai do proprio index.html local, mais o index. Assim um arquivo novo
# entra na conferencia sozinho, sem ninguem lembrar de cadastra-lo aqui.
arquivos="index.html $(grep -o '\(src\|href\)="[a-z0-9.-]*\.\(js\|css\)?v=' "$RAIZ/painel/index.html" \
          | sed 's/.*"//; s/?v=//')"

for f in $arquivos; do
  [ -f "$RAIZ/painel/$f" ] || { echo "  ?  $f nao existe no repo"; continue; }
  # cache-buster aleatorio: sem ele um proxy pode devolver a copia velha e a
  # conferencia mente a favor do deploy.
  curl -sS -f "$BASE/$f?confere=$RANDOM$RANDOM" -o "$TMP/$f" 2>/dev/null || {
    echo "  X  $f NAO RESPONDE"; falhas=$((falhas+1)); continue; }
  a=$(sha256sum "$TMP/$f"          | cut -d' ' -f1)
  b=$(sha256sum "$RAIZ/painel/$f"  | cut -d' ' -f1)
  if [ "$a" = "$b" ]; then
    printf '  ok  %s\n' "$f"
  else
    printf '  X   %s DIFERE (servidor %s bytes, repo %s bytes) - FALTA SUBIR\n' \
      "$f" "$(wc -c < "$TMP/$f")" "$(wc -c < "$RAIZ/painel/$f")"
    falhas=$((falhas+1))
  fi
done

echo
if [ "$falhas" -eq 0 ]; then echo "painel publicado bate com o repo."; else
  echo "$falhas arquivo(s) fora de sincronia."; exit 1; fi
