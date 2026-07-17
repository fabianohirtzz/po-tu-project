# -*- coding: utf-8 -*-
"""
Gera os paths do mapa-mundi de fundo da pagina /link (link.html).

Rodar so quando precisar refazer o mapa. O resultado ja esta colado dentro do
<g class="lk-map__land"> do link.html; este script existe para a derivacao ser
reproduzivel, e nao um monte de coordenada orfa que ninguem sabe de onde saiu.

Fonte: Natural Earth 110m, DOMINIO PUBLICO, via world-atlas (TopoJSON).
  curl -sSL -o land110.json https://cdn.jsdelivr.net/npm/world-atlas@2/land-110m.json
  python tools/mapa-mundi.py     # escreve land-paths.txt

Projecao: equiretangular, a MESMA que as rotas do link.html usam, senao o aviao
pousaria fora do continente:
  x = (lon + 180) / 360 * 1000
  y = (90 - lat) / 180 * 500

Saida atual: 70 paths, ~3.100 vertices, ~11,8 KB.
"""
import json

d = json.load(open('land110.json'))
tr = d['transform']; sc = tr['scale']; tl = tr['translate']
raw_arcs = d['arcs']

def decode(arc):
    """Delta-decode um arco para lista de (lon, lat)."""
    x = y = 0; out = []
    for dx, dy in arc:
        x += dx; y += dy
        out.append((x * sc[0] + tl[0], y * sc[1] + tl[1]))
    return out

arcs = [decode(a) for a in raw_arcs]

def resolve(idx):
    """Indice negativo = arco invertido (convencao TopoJSON: ~i = -i-1)."""
    if idx >= 0:
        return arcs[idx]
    return arcs[~idx][::-1]

def ring_coords(ring):
    pts = []
    for i in ring:
        seg = resolve(i)
        pts.extend(seg if not pts else seg[1:])
    return pts

def project(lon, lat):
    return ((lon + 180.0) / 360.0 * 1000.0, (90.0 - lat) / 180.0 * 500.0)

def area(pts):
    """Area do shoelace, ja em unidades do viewBox."""
    a = 0.0
    for i in range(len(pts)):
        x1, y1 = pts[i]; x2, y2 = pts[(i + 1) % len(pts)]
        a += x1 * y2 - x2 * y1
    return abs(a) / 2.0

def simplify(pts, tol):
    """Ramer-Douglas-Peucker."""
    if len(pts) < 3:
        return pts
    def rdp(p):
        if len(p) < 3:
            return p
        x1, y1 = p[0]; x2, y2 = p[-1]
        dx, dy = x2 - x1, y2 - y1
        den = (dx * dx + dy * dy) ** .5
        imax, dmax = 0, 0.0
        for i in range(1, len(p) - 1):
            x0, y0 = p[i]
            dist = abs(dy * x0 - dx * y0 + x2 * y1 - y2 * x1) / den if den else ((x0-x1)**2+(y0-y1)**2)**.5
            if dist > dmax:
                imax, dmax = i, dist
        if dmax > tol:
            return rdp(p[:imax + 1])[:-1] + rdp(p[imax:])
        return [p[0], p[-1]]
    return rdp(pts)

def collect(g):
    """land vem como GeometryCollection > MultiPolygon; achata para lista de poligonos."""
    t = g['type']
    if t == 'GeometryCollection':
        out = []
        for sub in g['geometries']:
            out.extend(collect(sub))
        return out
    if t == 'MultiPolygon':
        return list(g['arcs'])
    if t == 'Polygon':
        return [g['arcs']]
    return []

polys = collect(d['objects']['land'])

MIN_AREA = 14.0   # descarta ilha miuda: a 7% de opacidade vira sujeira, nao mapa
TOL      = 1.15   # simplificacao: mantem a silhueta, corta vertice redundante

out, kept, dropped = [], 0, 0
for poly in polys:
    ring = poly[0]                      # so o anel externo; buraco nao importa aqui
    pts = [project(lon, lat) for lon, lat in ring_coords(ring)]
    if area(pts) < MIN_AREA:
        dropped += 1
        continue
    pts = simplify(pts, TOL)
    if len(pts) < 3:
        dropped += 1
        continue
    xs = [p[0] for p in pts]; ys = [p[1] for p in pts]
    w = max(xs) - min(xs); h = max(ys) - min(ys)
    # Sliver degenerado: fita larga e rasteira. Nao e costa.
    if h <= 4 and w > 200:
        dropped += 1
        continue
    # Antartida: faixa colada na borda de baixo, cortada em -85,6 pela propria
    # fonte, entao a "costa" sul e uma reta atravessando o mapa. Fora: a empresa
    # nao vende Antartida e o traco reto le como erro de desenho, nao como gelo.
    if min(ys) > 400 and w > 500:
        dropped += 1
        continue
    kept += 1
    a = area(pts)
    # A Eurasia cruza o antimeridiano: o anel vai ate x=1000 e reaparece em x=0,
    # e o segmento que liga os dois vira uma reta atravessando o mapa inteiro.
    # Quebra o anel nesses saltos e emite cada pedaco como polilinha ABERTA (sem
    # Z): so o traco da costa importa, o preenchimento nao existe aqui.
    segs, cur = [], [pts[0]]
    for p, q in zip(pts, pts[1:]):
        if abs(q[0] - p[0]) > 500:
            segs.append(cur); cur = [q]
        else:
            cur.append(q)
    segs.append(cur)
    if len(segs) == 1:
        out.append((a, 'M' + ' '.join('%d %d' % (round(x), round(y)) for x, y in pts) + 'Z'))
    else:
        for seg in segs:
            if len(seg) < 2:
                continue
            out.append((a, 'M' + ' '.join('%d %d' % (round(x), round(y)) for x, y in seg)))

out.sort(key=lambda t: -t[0])           # maior primeiro: continente antes de ilha
paths = [dd for _, dd in out]
open('land-paths.txt', 'w').write('\n'.join(paths))
print('poligonos mantidos:', kept, '| descartados (miudos):', dropped)
print('total de vertices :', sum(p.count(' ') for p in paths))
print('peso dos paths    : %.1f KB' % (sum(len(p) for p in paths) / 1024.0))
