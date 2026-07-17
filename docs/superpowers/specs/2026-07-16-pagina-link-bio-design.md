# Página de links da bio (`/link`) — design

**Data:** 2026-07-16
**Status:** aprovado, pronto para plano de implementação

## Problema

O Instagram (`@pereiraoliveiraturismo`) dá **um único link** na bio. Hoje ele aponta
para a home. A home é uma tela cheia travada, cinematográfica, sem rolagem — ótima como
vitrine, ruim como ponto de partida de quem chegou de um story e quer uma ação agora.

A página `/link` resolve isso: uma coluna de destinos, aberta no celular, com o caminho
mais curto entre "vi o roteiro no story" e "meu lead está no painel".

Referência de porte: `freelainhome.com.br/link` (print salvo em `freela-link.jpeg` na raiz
do repo) — coluna centralizada, logo, selo do Google, headline, linha de números, lista de
links com ícone + título + subtítulo + seta, redes e rodapé. **Invertemos o tom**: lá é
escuro, aqui é o papel morno da `nossa-historia.html`.

## Decisões

| Decisão | Escolha | Por quê |
|---|---|---|
| Fonte dos roteiros | **Banco, ao vivo** (`po_roteiros`) | Publicou no painel, aparece na bio. Excluiu, some. Sem deploy. É a regra que já vale no site: nunca escrever roteiro à mão no HTML. |
| CTA do card de roteiro | **Página do roteiro** | A pessoa vê o dia a dia e o preço antes de decidir. O formulário já mora no fim daquela página. |
| Botão principal do topo | **`contato.html`** | É o "quero viajar, ainda não escolhi". Formulário de lead que já existe, um só para manter. |
| Fundo | **SVG inline** de mapa-múndi + rotas pontilhadas animadas | ~15 KB, sem imagem nem biblioteca, cor vinda dos tokens, nítido em qualquer tela. |
| Layout dos roteiros | **Empilhado, sem carrossel** | Numa bio aberta no polegar, seta e swipe escondem roteiro. Roteiro escondido é roteiro que não vende. |
| Indexação | **`noindex`**, fora do `sitemap.xml` | Conteúdo fino e duplicado do site. Indexar só criaria concorrência com a home e as páginas de roteiro, que são as que devem rankear. |

## Arquitetura

Página **standalone**, no mesmo padrão da `nossa-historia.html` — não toca em nada
existente:

- `link.html`
- `assets/css/link.css`
- `assets/js/link.js`

Reaproveita o que já é compartilhado (mesmas peças que a home usa no coverflow):

- `@supabase/supabase-js` (CDN) · `assets/js/po-config.js` · `assets/js/roteiros-shared.js`
- De `roteiros-shared.js`: `poFetchRoteiros()`, `poRoteiroHref(slug)`, `poCard(r)`

Ordem de carga no fim do `<body>`, igual à `index.html`:

```html
<script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2"></script>
<script src="assets/js/po-config.js"></script>
<script src="assets/js/roteiros-shared.js?v=3"></script>
<script src="assets/js/link.js?v=1"></script>
```

**`.htaccess`:** rewrite de `/link` → `/link.html`, para a URL na bio ficar
`pereiraoliveiraturismo.com.br/link` (mesmo padrão da Freela). Sem `.html` à mostra.

## Estrutura da página

Coluna única, `max-width: 420px`, centralizada, mobile-first. De cima para baixo:

1. **Cabeçalho** — logo (`assets/images/logo.png`) + "Realizando sonhos desde 1967".
   O logo é o **wordmark branco**, e existe um arquivo só: todo o resto do site o usa
   sobre superfície escura. Solto no papel ele some (sobra o pin), então ele fica dentro
   de um **chip escuro** (`rgba(13,18,25,.95)`, raio 16px), ecoando o header do site e
   lendo como selo de papel timbrado. Se a cliente enviar uma versão escura do logo, o
   chip pode cair.
2. **Selo do Google** — ícone do Google + 5 estrelas + "5,0". Sem contagem de avaliações.
3. **Headline** curta + linha de **números**: `58 anos · 3 gerações · +35 países`
   (os mesmos da História; os anos calculados ao vivo a partir de 1967, não escritos à mão)
4. **Reservar passeio** — botão destacado, gradiente da marca → `contato.html`
5. **Falar no WhatsApp** — botão verde (`--wa:#25D366`) → `https://wa.me/5548996048882`
6. **Cards de roteiro** — do banco, empilhados
7. **Ver avaliações no Google** → `https://www.google.com/maps?cid=10478700579601202326`
8. **Redes sociais** — Instagram + WhatsApp
9. **Rodapé** — razão social, cidade, ano

### Copy (definida, não a inventar na implementação)

Regras Freela: português, sem travessão, sem emoji, números concretos, tom de confiança e
tradição sem exagero vazio.

| Elemento | Texto |
|---|---|
| Headline | **Viaje em grupo. Desde 1967.** |
| Sub-headline | Roteiros internacionais com data marcada e grupo acompanhado. |
| Números | `<anos> anos` · `3 gerações` · `+35 países` — os anos são **calculados ao vivo** (`ano atual - 1967`), então em 2026 saem 59, e o número nunca envelhece. O `58` escrito no HTML é só o fallback de quem está sem JS, mantido em paridade com a `nossa-historia.html`. |
| Botão 1 (título / subtítulo) | **Reservar passeio** / Conte seu destino e retornamos com o roteiro |
| Botão 2 | **Falar no WhatsApp** / Atendimento direto com a equipe |
| Botão do card | **Ver roteiro** |
| Botão Google | **Ver avaliações no Google** / 5,0 estrelas no Google |
| Rodapé | © 2026 Pereira Oliveira Turismo · Florianópolis, SC |

O selo do Google no topo mostra apenas o ícone, as 5 estrelas e `5,0` — **sem contagem de
avaliações**, por pedido do cliente.

### Card de roteiro

- **Capa quadrada** — `aspect-ratio: 1`, `object-fit: cover`, do `capa_url` do banco
- Sobre a capa: **título** + **subtítulo**
- Abaixo: **dias** e **período** — do `badge` e do `periodo`
  (hoje rende `17 dias · De 14/09/26 à 30/09/26`)
- **Botão "Ver roteiro"** em largura total → `poRoteiroHref(slug)`, que já resolve sozinho
  entre página estática e a dinâmica `roteiro.html?slug=`

Os 6 roteiros ativos hoje: `caminhos-da-india`, `turquia-com-antalia`,
`chile-santiago-e-deserto-do-atacama`, `tesouros-asiaticos2`, `coreia-do-sul-japao-dubai`,
`um-roteiro-exclusivo-pelo-melhor-da-escandinavia-com-acompan`. A lista **não é fixa** —
o que valer no banco no dia é o que a página mostra.

## Fundo: mapa-múndi + rotas

SVG inline no HTML, atrás do conteúdo, `aria-hidden="true"`, `pointer-events:none`.

- **Mapa** em outline de traço fino, cor de tinta desbotada sobre o papel
- **Opacidade 6–8%.** Ele é **textura, não ilustração** — acima disso briga com as capas
  dos roteiros e a página vira poluição. Este é o ponto de falha mais provável do visual.
- **3 ou 4 rotas** em curva pontilhada saindo de Florianópolis, com um avião percorrendo
  cada traçado via `stroke-dashoffset`
- **`prefers-reduced-motion`**: aviões e rotas param. Mesma regra do resto do site.

**Limite conhecido e aceito:** as rotas são desenhadas à mão no SVG e apontam para os
destinos dos roteiros ativos hoje (Índia, Turquia, Chile, Vietnã, Japão, Escandinávia).
O banco não tem coordenadas, então **o traçado não acompanha o banco** — roteiro novo não
ganha rota. É decoração fixa. Preço justo para textura de fundo.

## Tom visual

Herda os tokens da `historia.css` — o papel morno, em contraste deliberado com a home
cinematográfica:

```
--his-paper:#F3F0E9   --his-print:#fff      --his-ink:#22242B
--his-ink-soft:#6C6E75  --his-line:rgba(34,36,43,.14)  --his-kraft:#E3D8C3
--brand-azul:#1FA8DD  --brand-verde:#84C440  --wa:#25D366
```

Fontes: Rubik (display) · DM Sans (corpo) · DM Mono (números), como na História.

## Rastreamento

Todo link de saída carrega `utm_source=instagram&utm_medium=bio&utm_campaign=link`.
O card acrescenta o slug do roteiro (`utm_content=<slug>`).

O `lead-form.js` **já** captura UTM em campo oculto e o `enviar.php` já grava. O lead cai
no painel dizendo de onde veio, **sem código novo** de backend.

Google Tag `GT-TNH4L3BV`: mesma tag do resto do site, no `<head>`.

## Estados

| Situação | Comportamento |
|---|---|
| Banco fora do ar, ou zero roteiro ativo | **Nenhum card.** O resto da página (números, WhatsApp, formulário, Google) segue de pé. |
| Sem JS | Página completa menos os cards. Cabeçalho, botões, Google e redes são HTML puro. |
| `prefers-reduced-motion` | Rotas e aviões parados. |

Não mostrar roteiro nenhum é **deliberado**, e é a mesma regra que já vale na home: melhor
card nenhum do que card vencido. Não existe fallback escrito à mão — foi exatamente o que
causou o flash do roteiro excluído na home (ver CLAUDE.md, 16/07/2026).

## Fora de escopo

**Estrelas do Google são elemento visual apenas.** Nada de `AggregateRating`/`Review` no
JSON-LD. Avaliação que a própria empresa hospeda sobre si mesma é violação de política do
Google, não gera estrela no resultado de busca, e a spec de SEO do projeto
(`2026-07-16-seo-indexacao-roteiros-design.md`) já registra isso.

**Falso alarme corrigido (16/07/2026):** uma versão anterior desta spec registrava um bug
de mojibake nos títulos do `po_roteiros` ("Caminhos da ÃÍndia" etc.). **Não existe.** Era
o pipeline `curl | python -m json.tool` do terminal corrompendo a exibição. Verificado no
navegador: os títulos vêm corretos do banco ("Caminhos da Índia", "Turquia com Antália",
"Escandinávia, semana Medieval de Visby"). Não abrir chamado.

## Verificação

- [ ] Os 6 roteiros do banco aparecem, com capa, dias e período
- [ ] "Ver roteiro" abre a página certa de cada roteiro
- [ ] "Reservar passeio" abre a `contato.html`; lead de teste chega no painel com
      `utm_source=instagram`
- [ ] WhatsApp abre em `wa.me/5548996048882` (com o **55**)
- [ ] O link do Google abre a ficha da Pereira Oliveira
- [ ] Sem console error; `noindex` presente; `/link` responde 200
- [ ] 390px de largura: nada estoura, mapa não compete com as capas
- [ ] `prefers-reduced-motion`: aviões parados
- [ ] Com o Supabase bloqueado: zero card, resto da página de pé
- [ ] Testar com **latência** (CDP `Network.emulateNetworkConditions`, 400ms) — localhost
      esconde bug de ordem de pintura (ver CLAUDE.md)
- [ ] Playwright: conferir que a resposta do Supabase tem `sb-project-ref`/`CF-Ray`.
      O perfil do Chrome já teve mock interceptando o Supabase e fez teste passar com dado
      falso. Se divergir do `curl`, `context.unrouteAll()`.

## Link do Google

`https://www.google.com/maps?cid=10478700579601202326` — **confirmado pelo cliente em
16/07/2026**, abre a ficha da Pereira Oliveira.

Derivado do identificador `0x916bd2a89acf3c96`, extraído da URL da ficha no Maps
(CID decimal = `0x916bd2a89acf3c96`). É o formato canônico: aponta para a ficha por ID,
sem risco de homônimo e sem depender de busca.

**Não usar links `share.google/...`**: o botão "Compartilhar" da *página de resultados* do
Google gera um share da **pesquisa**, não da ficha. Foram testados dois
(`4VFSfPZyvSzeGzsr3` e `cv5K5MPSGM9tWbJBw`) e ambos expandiam para
`google.com/search?q=Pereira+Oliveira+Turismo`.
