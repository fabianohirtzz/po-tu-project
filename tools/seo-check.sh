#!/usr/bin/env bash
# Asserções de SEO contra uma base URL.
#   bash tools/seo-check.sh https://pereiraoliveiraturismo.com.br
# Sem argumento usa http://localhost:8080 (php -S).
BASE="${1:-http://localhost:8080}"
PASS=0; FAIL=0

chk() { # chk <descricao> <esperado> <obtido>
  if [ "$2" = "$3" ]; then PASS=$((PASS+1)); printf '  ok   %s\n' "$1"
  else FAIL=$((FAIL+1)); printf '  FAIL %s\n       esperado: %s\n       obtido:   %s\n' "$1" "$2" "$3"; fi
}
code() { curl -s -o /dev/null -w '%{http_code}' --max-redirs 0 "$1"; }
body() { curl -s "$1"; }

echo "== $BASE"
chk "/roteiros responde 200"            200 "$(code "$BASE/roteiros")"
chk "/roteiros/turquia responde 200"    200 "$(code "$BASE/roteiros/turquia")"
chk "slug inexistente da 404"           404 "$(code "$BASE/roteiros/nao-existe-xyz")"
chk "sitemap responde 200"              200 "$(code "$BASE/sitemap.xml")"

# o conteudo tem que existir SEM javascript
b=$(body "$BASE/roteiros/turquia")
chk "roteiro traz o titulo no HTML"     ok "$(echo "$b" | grep -qi 'turquia'            && echo ok || echo no)"
chk "roteiro traz JSON-LD TouristTrip"  ok "$(echo "$b" | grep -q  'TouristTrip'        && echo ok || echo no)"
chk "roteiro traz o dia a dia"          ok "$(echo "$b" | grep -q  'class=\"day__p\"'   && echo ok || echo no)"
chk "roteiro NAO tem noindex"           ok "$(echo "$b" | grep -qi 'noindex'            && echo no || echo ok)"
chk "roteiro tem canonical"             ok "$(echo "$b" | grep -q  'rel=\"canonical\"'  && echo ok || echo no)"

s=$(body "$BASE/sitemap.xml")
chk "sitemap lista a turquia"           ok "$(echo "$s" | grep -q '/roteiros/turquia'   && echo ok || echo no)"
chk "sitemap NAO lista roteiro extinto" ok "$(echo "$s" | grep -q 'mercados-de-natal'   && echo no || echo ok)"

echo; echo "  $PASS ok, $FAIL falhas"; [ "$FAIL" -eq 0 ]
