# SEO — indexação dos roteiros e recuperação das URLs antigas

> Fase 1. Data: 2026-07-16.
> Objetivo: fazer os roteiros à venda existirem no Google, e recuperar a autoridade
> das URLs do site antigo antes que ela expire.

---

## 1. Diagnóstico (verificado, não suposto)

O problema não é "SEO mal formatado". É que **os roteiros à venda estão proibidos de
entrar no índice do Google, por escrito**.

| Sintoma | Causa raiz | Evidência |
|---|---|---|
| "Chile e Deserto do Atacama roteiro" não acha o site | `roteiro.html` linha 9 tem `<meta name="robots" content="noindex, nofollow">`, e **os 6 roteiros do banco são servidos por ela** | leitura do arquivo |
| idem | essas URLs não estão no `sitemap.xml` | leitura do sitemap |
| idem | conteúdo só existe após o JS buscar o Supabase (render client-side, URL com `?slug=`) | `roteiro-dynamic.js:158` |
| "passeio turquia florianópolis" acha o site **antigo** | `/turquia` (WordPress) ainda indexada; hoje **404 sem `301`** | `curl` → 404 |
| — | `www` responde **200** em vez de `301` para não-www: site duplicado | `curl` → 200 |
| — | sitemap anuncia 5 roteiros estáticos que **não existem no banco**, incluindo `mercados-de-natal` (já excluído do catálogo) | sitemap × `po_roteiros` |

**Resumo:** o Google conhece exatamente os roteiros que não estão mais à venda, e
desconhece 100% dos que estão.

### Inventário de URLs antigas (Wayback + Search Console)

~20 URLs do WordPress com anos de autoridade, **todas 404 hoje**:
`/nossos-roteiros`, `/nossa-historia/`, `/sobre-nos`, `/contato`, `/blog`,
`/dicas-de-viagem`, `/turquia`, `/india-nepal`, `/japao-dubai-cingapura`, `/marrocos`,
`/capitais-imperiais`, `/cruzeiro-pelo-danubio`, `/viagem-transiberiana`,
`/terra_santa-jordania`, `/rota-romantica-alemanha`, `/expresso-luzes-do-norte`,
`/europa`, `/africa`, `/asia-oriente-medio`, `/america-do-sul`, `/oceania`,
`/america-do-norte-caribe`, `/outros-destinos`, `/en/*`, `/es/*`.

O Wayback **não é completo** (`/nossa-historia/` só apareceu via busca). Na
implementação, puxar a lista definitiva do **Search Console → Páginas**.

**Por que isso é urgente:** URL 404 perde autoridade a cada recrawl, e a perda é
**irreversível** — `301` aplicado depois não ressuscita. Cada semana custa.

---

## 2. Decisões tomadas

| # | Decisão | Quem decidiu |
|---|---|---|
| 1 | As 5 páginas estáticas de roteiro são **passado**, não estão à venda. Aposentar. | cliente |
| 2 | Novos roteiros devem indexar **automaticamente**, sem tocar em código. | cliente |
| 3 | Criar **`/roteiros`** como página mãe. A home continua travada/cinematográfica. | cliente |
| 4 | Renderização **server-side em PHP** a partir do banco (abordagem A). | recomendação aceita |
| 5 | Sem ferramenta paga de volume de busca. Estratégia por Search Console + autocomplete + concorrentes. | cliente |
| 6 | Avaliações do Google no site → **fase 2**. | recomendação aceita |
| 7 | **Uma edição por destino de cada vez** — quando abre a próxima, a anterior já saiu. Logo slug = destino estável, sem ano. | cliente |
| 8 | `tesouros-asiaticos2` é **nova edição da mesma viagem** do `tesouros-asiaticos.html` antigo (mesmo título, outro roteiro). | cliente |

---

## 3. Arquitetura

O **banco (`po_roteiros`) vira fonte única de verdade**. Nada de roteiro escrito à mão
em HTML (mesma regra que já vale para o `index.html`).

```
/roteiros                      → roteiros.php   (página mãe, lista po_roteiros ativos)
/roteiros/<slug>               → roteiro.php    (um roteiro, renderizado no servidor)
/sitemap.xml                   → sitemap.php    (gerado do banco, via .htaccess)
```

Rewrites no `.htaccess`; as URLs públicas não expõem `.php` nem `?slug=`.

### `roteiro.php`

Busca o Supabase **no servidor** (REST + anon key; RLS já garante que anon só lê
`ativo = true`) e devolve HTML completo:

- `<title>` e `description` no padrão de busca do público (ver §5)
- `canonical`, Open Graph, Twitter Card
- JSON-LD `TouristTrip` + `Offer` com preço real vindo de `valores`
- **o `roteiro_dias` inteiro em texto no HTML** (hoje só existe pós-JS)
- slug inexistente ou `ativo = false` → **HTTP 404** + `404.html` (não 200 com "não encontrado")

Cache em disco (~10 min, arquivo no servidor) para não bater no Supabase por visita.
Se o Supabase cair e não houver cache → 503, nunca HTML vazio indexável.

O JS de front (`roteiro-dynamic.js`) deixa de montar a página; segue só o comportamento
interativo (galeria, lightbox, vídeo, formulário).

### Colunas reais de `po_roteiros` (conferidas no banco)

```
id, created_at, updated_at, slug, titulo, subtitulo, descricao_curta, periodo,
dias, noites, data_label, local_label, badge, capa_url, roteiro_dias, hoteis,
inclui, nao_inclui, valores, galeria, ativo, ordem, video_insta_url, video_capa_url
```

### `sitemap.php`

Gera do banco: `/`, `/roteiros`, `/nossa-historia.html`, `/contato.html` + um `<url>`
por roteiro `ativo`. `lastmod` = `updated_at`.
**Consequência:** roteiro novo no painel entra no sitemap sozinho. Requisito nº 2 atendido.

### Limpeza

- 5 páginas estáticas de roteiro: removidas do repo, do sitemap e dos catálogos
  (`historia.js`, `roteiro.js`, `PO_STATIC_ROTEIRO` em `roteiros-shared.js`)
- `poRoteiroHref()` passa a devolver sempre `/roteiros/<slug>`
- `roteiro.html?slug=` → `301` para `/roteiros/<slug>`

---

## 4. Mapa de redirects (`.htaccess`, todos `301`)

| De | Para |
|---|---|
| `www.*` | não-www (mesma URI) |
| `/turquia` | `/roteiros/turquia` |
| `/india-nepal` | `/roteiros/caminhos-da-india` |
| `/japao-dubai-cingapura` | `/roteiros/coreia-do-sul-japao-dubai` |
| `/nossos-roteiros`, `/europa`, `/africa`, `/asia-oriente-medio`, `/america-do-sul`, `/oceania`, `/america-do-norte-caribe`, `/outros-destinos` | `/roteiros` |
| `/marrocos`, `/capitais-imperiais`, `/cruzeiro-pelo-danubio`, `/viagem-transiberiana`, `/terra_santa-jordania`, `/rota-romantica-alemanha`, `/expresso-luzes-do-norte` | `/roteiros` |
| `tesouros-asiaticos.html` (estática aposentada) | `/roteiros/tesouros-asiaticos` — confirmado pelo cliente: mesma viagem, nova edição |
| as demais estáticas (`grecia-terra-mar.html`, `mercados-de-natal.html`, `floracao-das-cerejeiras.html`, `encantos-do-mediterraneo.html`) | `/roteiros` |
| `/nossa-historia/`, `/sobre-nos` | `/nossa-historia.html` |
| `/contato` | `/contato.html` |
| `/blog`, `/dicas-de-viagem`, `/6-dicas-...`, `/meu-primeiro-post-do-blog`, `/en/*`, `/es/*` | `/` |

Regra: **destino próximo quando existe; `/roteiros` quando o roteiro morreu.** Nunca
redirecionar para um destino enganoso — o Google trata isso como soft-404 e não passa
autoridade, e o visitante se sente enganado.

---

## 5. Estratégia de palavras-chave

**A premissa inicial estava errada e isso é central.** "roteiro Chile Atacama" é busca
**informacional** — quem digita está montando a própria viagem e **não compra excursão
em grupo**. É disputa com blogs e OTAs, por um clique que não converte.

O público (60+, viagem acompanhada, saída de Florianópolis) busca assim:

| Termo | Nota |
|---|---|
| **excursão para o Chile** | "excursão" é a palavra do público; "roteiro" é jargão de blogueiro |
| **viagem em grupo para o Chile** | casa 1:1 com o produto |
| **viagem para o Chile saindo de Florianópolis** | pouquíssima concorrência |
| **excursão Turquia 2026** | data fixa é o produto |
| **viagem em grupo com guia em português** | diferencial real |
| **viagem melhor idade** / **viagem terceira idade em grupo** | "melhor idade" tende a ter mais intenção comercial |
| **agência de viagem em grupo Florianópolis** | decidido pelo Google Business, não pelo site (§7) |

**Princípio:** o diferencial não é o destino (disputa com o mundo), é **grupo +
acompanhante brasileiro + saída de Florianópolis** (quase sem concorrência).

Aplicação:
- `/roteiros`: `<h1>` "Viagens em grupo com saída de Florianópolis", texto real
  explicando o modelo (grupo acompanhado, guia em português, data fixa, desde 1967),
  cards com destino e data **no HTML**
- cada roteiro: `<title>` tipo **"Excursão Turquia com Antália 2026 · Viagem em grupo
  saindo de Florianópolis"** (hoje: "Turquia — Pereira Oliveira Turismo")

Sem dado de volume, a priorização é **julgamento declarado, não medição**. Reavaliar
com o Search Console em ~60 dias, quando houver impressões reais.

---

## 6. Slug: identidade estável, não palavra-chave

### O mito, desfeito

Palavra-chave em URL **não é fator de ranking relevante**. A documentação do Google diz
apenas: *"Use descriptive URLs. When possible, use readable words rather than long ID
numbers in your URLs"* — justificada por **legibilidade humana**, não por SEO. Não há
recomendação de URL com palavra-chave.

Logo `/roteiros/viagem-em-grupo-para-escandinavia` **não** rankeia melhor que
`/roteiros/escandinavia`. As palavras do público ("excursão", "viagem em grupo",
"saindo de Florianópolis") rendem no `<title>`, `<h1>` e no texto — ver §5.

### O critério real: os roteiros se repetem

`tesouros-asiaticos2` não foi escolha de ninguém: o painel bateu em slug duplicado e
grudou um número. **Confirmado pelo cliente: é nova edição da mesma viagem.** Sem
regra, 2028 gera `tesouros-asiaticos3`.

Isso importa porque **autoridade se acumula por URL**. Edição nova em URL nova =
recomeçar do zero todo ano, com links e histórico presos numa viagem que já aconteteu.
URL estável = engorda a cada edição.

**Decisão do cliente: uma edição por destino de cada vez** (quando abre a próxima, a
anterior já saiu). Portanto:

> **Regra: slug = a identidade estável da viagem, sem os detalhes variáveis da edição.**
> Fora datas, temas, paradas específicas ("com Antália") e sufixo numérico.
> `/roteiros/tesouros-asiaticos` sempre aponta para a edição vigente. A URL nunca morre.

### Renomeações (grátis agora — nada indexado; depois custa `301`)

| Hoje | Novo | Por quê |
|---|---|---|
| `turquia-com-antalia` | `turquia` | Antália é parada desta edição |
| `chile-santiago-e-deserto-do-atacama` | `chile-e-deserto-do-atacama` | Santiago é escala |
| `tesouros-asiaticos2` | `tesouros-asiaticos` | `2` = colisão do painel |
| `um-roteiro-exclusivo-pelo-melhor-da-escandinavia-com-acompan` | `escandinavia` | truncado no meio da palavra; resto é edição |
| `caminhos-da-india` | mantém | estável e legível |
| `coreia-do-sul-japao-dubai` | mantém | descreve melhor que o título "Tesouros do Oriente" |

### Consequências no painel e no banco

- **Remover a lógica de sufixo numérico.** Com uma edição por vez, slug repetido não é
  colisão — é a mesma viagem voltando, e é o comportamento desejado.
- **Índice único parcial:** só um roteiro `ativo` por slug
  (`CREATE UNIQUE INDEX ... ON po_roteiros (slug) WHERE ativo`). Edições arquivadas
  mantêm o slug na linha, mas não são servidas (`roteiro.php` filtra `ativo = true`).
- Edição nova **herda a URL e a autoridade** da anterior.
- Importador: truncar slug em **limite de palavra**, nunca no meio.

---

## 7. Google Business Profile — fora do código, provavelmente o maior retorno

Perfil: **5,0 · 22 avaliações**. A captura mostra **"É proprietário desta empresa?"** e
endereço incompleto ("R. Alm. Lamego - Centro", sem número/sala) — **indício forte de
perfil não reivindicado**.

Se confirmado: a agência tem 5,0 com 22 avaliações e **não controla o próprio perfil** —
não responde avaliação, não posta roteiro, não completa categoria/horário. É o ativo que
decide "agência de viagem Florianópolis". **Reivindicar é grátis e supera qualquer ganho
desta fase.** Ação do cliente, não nossa. Não é certeza — inferido de captura.

---

## 8. Fora de escopo

**Fase 2:** avaliações no site (tabela nova, CRUD no painel, exibição em `/roteiros`,
em cada roteiro e na Nossa História; cadastro manual permite filtrar negativas).

> **Regra do Google, confirmada na documentação:** "If the entity that's being reviewed
> controls the reviews about itself, their pages that use `LocalBusiness` or any other
> type of `Organization` structured data are ineligible for star review feature" — e a
> política cobre nominalmente widgets de avaliação do Google embutidos no próprio site.
>
> Portanto: avaliação no site é **conversão, não SEO**. Não gera estrelas no resultado
> e não ajuda a rankear. **Não marcar com `Review`/`AggregateRating`** — seria violação
> de diretriz, com risco de ação manual. Usar `<blockquote>` semântico.
>
> "5 estrelas no Google" **pode** ser publicado: a nota real é 5,0 (verificada).
> Omitir o total de 22 é escolha do cliente e é legítimo. Se a nota mudar, o texto muda.

**Adiado:** blog, páginas de continente/categoria, link building. Primeiro parar de
sabotar e recuperar o que já era nosso; conteúdo novo quando houver base para medir.

---

## 9. Critérios de sucesso

1. `curl https://pereiraoliveiraturismo.com.br/roteiros/turquia` devolve HTML com
   título, dia a dia e JSON-LD **sem executar JavaScript**
2. Nenhuma URL de roteiro à venda contém `noindex`
3. `/sitemap.xml` lista os 6 roteiros ativos e **nenhum** roteiro extinto
4. Roteiro novo cadastrado no painel aparece em `/roteiros` e no sitemap **sem deploy**
5. Toda URL da §4 devolve `301` para o destino certo (teste automatizado com `curl`)
6. `www` e `http` → `301` para `https://` não-www
7. Slug inexistente → **404 de verdade** (não 200)
8. Search Console: sitemap novo submetido, 0 erro de cobertura

---

## 10. Riscos e limites honestos

- **Nada disso é rápido.** Site sem autoridade acumulada. Os `301` dão sinal em semanas;
  ranking orgânico real leva meses. O que controlamos é parar de sabotar e mirar certo.
- **PHP no caminho do visitante.** Mitigado por cache em disco + 503 (nunca HTML vazio).
- **Migrar as 5 estáticas → banco não está previsto.** Elas morrem; o conteúdo delas
  (Grécia etc.) some do site. Confirmado pelo cliente: são passado.
- **Sem dado de volume**, a estratégia de §5 é hipótese fundamentada. Medir em 60 dias.
- **A cliente publicando roteiro sem cuidado agora afeta SEO** (título e slug viram URL
  e `<title>`). O painel deve orientar. Risco novo, criado por esta fase.
