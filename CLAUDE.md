# Pereira Oliveira Turismo — Site + Painel de Leads

> Documento-cérebro do projeto. Manter a seção **Estado atual** sempre atualizada.
> Método: `freela-method` / comando `/site`. Regras de copy Freela valem para todo texto.

---

## Cliente & negócio

- **Razão social:** Pereira Oliveira Consultoria em Turismo e Viagens LTDA
- **CNPJ:** 05.622.878/0001-82
- **Assinatura de marca:** "Realizando sonhos desde 1967"
- **Segmento:** Operadora de viagens em grupo — roteiros internacionais com datas fixas
  (Europa, Ásia, cruzeiros, mercados de Natal, floração das cerejeiras etc.)
- **Cidade:** Florianópolis / SC
- **Endereço:** Rua Almirante Lamego 1090, Sala 801, Centro, Florianópolis/SC
  (atendimento presencial apenas com hora marcada)
- **Contato:** WhatsApp +55 48 99604-8882 · Instagram @pereiraoliveiraturismo
- **Site atual:** https://pereiraoliveiraturismo.com.br (WordPress + Elementor) — será substituído

## Objetivo do projeto

Substituir o site atual por um site próprio (código-fonte nosso) e **trocar o fluxo de
contato**: hoje a reserva/informação vai direto para o WhatsApp; passará a ser
**formulário de captura de lead** + **painel de acompanhamento** de leads, no mesmo
padrão do projeto **NOX Cozinhas**.

## Escopo & estrutura (mesma do site atual)

Página única institucional, seções ancoradas:

1. **Header** fixo — logo · Início · Nossa História · Contato · "desde 1967" · WhatsApp/Instagram
2. **Hero** — título do roteiro em destaque + carrossel dos roteiros (capas retrato)
3. **Nossa História** — trajetória desde 1967
4. **Roteiros / Próximas viagens** — cards com destino, duração e datas
5. **Contato** — **formulário de lead** (substitui o "fale no WhatsApp")
6. **Footer** — dados, redes, endereço
7. **WhatsApp flutuante** (mantido como canal secundário)

Separado: **/painel** — login + gestão de leads (ver Arquitetura).

## Arquitetura técnica (replicando NOX)

- **Front-end:** HTML/CSS/JS estático (sem build), fontes via Google Fonts.
- **Backend do formulário:** `enviar.php` (PHP no cPanel) com **PHPMailer via SMTP
  autenticado** (o host bloqueia `mail()` sem auth) + gravação do lead no **Supabase**.
  Antispam: honeypot, time-trap (`_t`), bloqueio de links/BBCode. Captura UTM/origem.
  Segredos ficam em `config.local.php` (só no servidor, fora do Git).
- **Painel `/painel`:** HTML/JS + Supabase Auth (login) e RLS. Lista de leads, filtros,
  gaveta de edição (status, valor de orçamento, valor de venda, observações) e aba de
  **Relatórios** (conversão, ROAS, ROI, CPL, funil, orçado×vendido, timeline, ad_spend).
- **Supabase:** tabela `leads` (+ `ad_spend`). Definir se reaproveita o projeto Supabase
  compartilhado (como NOX/hd360) ou cria projeto novo. `service_role` só no servidor;
  `anon key` pública no painel protegida por RLS + login.

### Campos do formulário (turismo — a refinar)

nome · telefone/WhatsApp · e-mail · cidade · roteiro de interesse · nº de viajantes ·
mensagem/observação · (ocultos: origem, utm_*, gclid, referrer, landing_page).

## Feature planejada — Importador de roteiros (Word/PDF/PPT)

A cliente monta os roteiros no **Word**, exporta em **PDF**, e anexa imagens no documento.
Objetivo: tela no painel para **anexar o arquivo → sistema lê texto + imagens → gera a
página do roteiro**.

- **Word (.docx):** formato ideal — pacote XML, texto e imagens (`word/media/`) extraíveis
  de forma confiável. Recomendado padronizar títulos/campos no documento para import
  determinístico.
- **PPT (.pptx):** viável (XML/zip), extrai texto e imagens dos slides.
- **PDF:** melhor esforço — texto sem estrutura e imagens dependentes de layout.
- **Recomendação:** padronizar no **.docx** com estrutura mínima de campos.
- **Status:** **implementado com IA (Gemini)**. O painel tem importador (`painel/app.js`)
  que manda o arquivo pro `importar.php` (proxy PHP no cPanel; chave `GEMINI_API_KEY` no
  `config.local.php`, valida login Supabase). PDF vai inteiro pro `gemini-2.5-flash`
  multimodal (resolve capa embaralhada e alocação errada); docx/pptx mandam texto.
  Saída em JSON estruturado (responseSchema) casando 1:1 com o formulário. Fallback: se
  a IA falhar (preview sem PHP), cai no parser regex local. Imagens: extraídas no
  navegador (PDF via pdf.js embedded XObjects com filtro <200px + dedup; docx/pptx do zip)
  e vão **só pra galeria** — capa é sempre manual. Tier gratuito do AI Studio cobre o
  volume (~10 roteiros/ano). Spec: `docs/superpowers/specs/2026-07-12-importador-roteiros-ia-design.md`.

## Design tokens (base — extraídos do site atual, refinar página a página)

- **Tipografia:** `Rubik` (títulos/display) · `DM Sans` (corpo) — Google Fonts.
- **Marca (logo pin+globo, gradiente):** azul `#1FA8DD` → verde `#84C440`.
- **Texto/ink:** `#2C2B2B` / `#333333` · **cinza:** `#7A7A7A`.
- **Superfícies:** branco `#FFFFFF` · off-white `#F9F9F9`.
- **Hero:** foto de fundo com overlay escuro, texto branco, botões pill translúcidos.
- Assets reais salvos em `assets/images/` (logo, favicon, 5 capas de roteiro).

## Integrações

- WhatsApp (botão flutuante + no header/contato) · Instagram.
- Analytics: o site atual usa Google Tag `GT-TNH4L3BV` — confirmar se mantemos/migramos.

## Deploy

- **Produção:** hospedagem **erehost** (cPanel/PHP, padrão NOX). Domínio
  `pereiraoliveiraturismo.com.br`. Deploy é **manual via FTP** (não há CI/GitHub Action).
- **Preview/acompanhamento:** **GitHub Pages** deste repo — sempre com `noindex`
  (o backend PHP não roda no Pages; o preview serve o front-end em construção).
- Repo: https://github.com/fabianohirtzz/po-tu-project

### Como fazer deploy na hospedagem (FTP erehost)

- **Protocolo:** FTPS explícito (porta 21). O certificado do servidor tem nome
  divergente (hospedagem compartilhada), então o `curl` precisa de `-k` para pular a
  verificação do certificado (a conexão continua criptografada).
- **Host:** `ftp.pereiraoliveiraturismo.com.br`
- **Usuário:** `sitepo@pereiraoliveiraturismo.com.br`
- **Senha:** NÃO versionada aqui (o CLAUDE.md vai pro GitHub). O cliente/dono fornece
  na hora do deploy; usar via arquivo `.netrc` temporário no scratchpad (fora do Git),
  nunca colar a senha inline no comando. Apagar o `.netrc` ao terminar.
- **A raiz do FTP já é o docroot** do site — `index.html`, as páginas de roteiro,
  `enviar.php`, `importar.php`, `config.local.php` e as pastas `assets/`, `images/`,
  `lib/`, `painel/` ficam direto na raiz. Ou seja, cada arquivo do repo sobe para o
  caminho equivalente (ex.: `painel/app.js` → `/painel/app.js`).
- **Segredos:** `config.local.php` (chaves SMTP/Supabase) e a chave `GEMINI_API_KEY`
  já vivem só no servidor; **nunca** sobrescrever esses no deploy.

Receita (ajustar o `<ARQUIVO>` e o caminho de destino):

```bash
# 1) netrc temporario no scratchpad (fora do Git)
cat > "$SCRATCH/.netrc" <<'EOF'
machine ftp.pereiraoliveiraturismo.com.br
login sitepo@pereiraoliveiraturismo.com.br
password <SENHA_FORNECIDA_NA_HORA>
EOF
chmod 600 "$SCRATCH/.netrc"

# 2) listar (conferir estrutura antes de sobrescrever)
curl -sS -k --ssl-reqd --netrc-file "$SCRATCH/.netrc" --ftp-pasv \
  "ftp://ftp.pereiraoliveiraturismo.com.br/painel/"

# 3) subir um arquivo (upload = -T)
curl -sS -k --ssl-reqd --netrc-file "$SCRATCH/.netrc" --ftp-pasv \
  -T "painel/app.js" "ftp://ftp.pereiraoliveiraturismo.com.br/painel/app.js"

# 4) conferir tamanho/data no servidor e apagar o netrc
curl -sS -k --ssl-reqd --netrc-file "$SCRATCH/.netrc" --ftp-pasv \
  "ftp://ftp.pereiraoliveiraturismo.com.br/painel/" | grep app.js
rm -f "$SCRATCH/.netrc"
```

Depois do deploy, testar com **Ctrl+F5** (cache do navegador para JS/CSS).

## Regras de copy (Freela)

Português. Sem travessões, sem emojis. Números concretos. Tom de confiança e tradição
(58 anos), sem exageros vazios.

---

## Estado atual

- **Fase:** Build em andamento, página a página.
- **Arquitetura de páginas:** o site é **multi-página**. `index.html` (home) é uma
  **tela cheia travada** (`position:fixed`, sem rolagem, vídeo + focus-rail) e **deve
  permanecer assim**. Cada seção institucional vira **página própria** que rola. O menu
  "Nossa História" aponta para `nossa-historia.html`. CSS/JS da home ficam em
  `assets/css/style.css` + `assets/js/main.js`; a página História é **standalone**
  (`assets/css/historia.css` + `assets/js/historia.js`) para não interferir na home.
- **Pronto:** Home (header glass · hero vídeo · coverflow dos roteiros) ·
  **Página `nossa-historia.html`** · **as 5 páginas de roteiro** (`grecia-terra-mar`,
  `mercados-de-natal`, `tesouros-asiaticos`, `floracao-das-cerejeiras`,
  `encantos-do-mediterraneo`), todas no mesmo template.
- **nossa-historia.html:** conceito "arquivo em papel" (papel morno, contraste com a home
  cinematográfica). Blocos: (1) intro com o ano **1967** como janela fotográfica
  (`background-clip:text` com `images/Fotos antigas/turismo3.jpg`); (2) fundador Antônio
  Pereira Oliveira (`images/historia1.jpg`) em cópia impressa com fita kraft; (3) linha do
  tempo em ledger (1967 · 80/90 · 2003 · 2000s · Hoje) com as fotos de
  `images/Fotos antigas/`; (4) contact sheet "Do arquivo"; (5) fecho com números (anos
  calculados ao vivo desde 1967, 3 gerações, +35 países); (6) **Roteiros** em carrossel de
  **cards-destino** (porte do 21st.dev `ravikatiyar162/card-21`: imagem full-bleed,
  overlay, título + badge de duração, stats, botão "Ver roteiro", **tilt 3D no hover**),
  **3 visíveis** no desktop / 2 tablet / 1 mobile, com setas + swipe + contador; depois
  footer escuro + WhatsApp flutuante. Fontes: Rubik + DM Sans + **DM Mono**. Reveal on
  scroll + count-up via IntersectionObserver, `prefers-reduced-motion` respeitado.
- **grecia-terra-mar.html:** primeira **página de roteiro** e template das demais. Standalone
  (`assets/css/roteiro.css` + `assets/js/roteiro.js`), conceito **hero cinematográfica →
  corpo editorial claro** (contraste com o papel morno da História). Seções: hero de tela
  cheia com **vídeo do YouTube em background** (embed `youtube-nocookie`, cover, poster =
  capa do roteiro, fallback em `prefers-reduced-motion`) → "Por que viajar" (5 propostas de
  valor) → **roteiro dia a dia** (timeline dos 16 dias, pills de refeições) → informações do
  roteiro (spec de inclusos) → **investimento** (2 price cards: duplo €6.115+€535 / individual
  €7.635, entrada 30% + 10x) → **galeria** com lightbox → **outros roteiros** (carrossel de
  cards-destino, mesmo componente da História) → **formulário de lead `#contato`** (honeypot,
  time-trap `_t`, UTM ocultos; posta em `enviar.php`, com fallback WhatsApp no preview) →
  footer + WhatsApp. IDs de vídeo de cada roteiro coletados do site atual: Mercados de Natal
  `K6l4OFPdqkw` · Tesouros Asiáticos `S4Z-125K3_0` · Cerejeiras `6gQV0HJALEE` · **Grécia
  `8Z2kpec0TSE`** · Mediterrâneo `b5SY6WY498I`. No painel haverá campo para o link do vídeo.
  Fotos reais de cada destino em `images/roteiros/<slug>/` (baixadas do site atual: Grécia
  como `grecia1..20`, os demais normalizados como `img1..N`; o set `images/galeria-de-imagens/`
  é genérico de outras viagens, **não** usar em roteiro). Todos os botões "Ver roteiro"
  (home + História) já apontam para as páginas.
- **As 5 páginas de roteiro** seguem o mesmo template do `grecia-terra-mar.html`. O bloco
  "outros roteiros" é **data-driven**: catálogo único em `roteiro.js`, cada página declara
  `data-current="<slug>"` no `#more-track` e exclui a si mesma; o nome do roteiro no lead
  vem do input `#f-roteiro`. Investimento adapta-se: Grécia tem 2 cards (duplo €6.115+€535 /
  individual €7.635, entrada 30% + 10x); os demais têm **1 card** (`.invest__cards--single`) —
  Mercados de Natal €6.635, Tesouros Asiáticos R$ 42.311,48, Cerejeiras R$ 57.891,52,
  Mediterrâneo R$ 34.145,00 (sem forma de pagamento informada). Dias da semana calculados e
  conferidos (os do site atual estavam errados).
- **SEO (feito):** todas as 8 páginas indexáveis (home, História, Contato e os 5 roteiros)
  têm `<title>`/`description` únicos, **canonical**, **Open Graph** (type/site_name/locale/
  title/description/url/image/image:alt), **Twitter Card** `summary_large_image`,
  `meta robots` (`index,follow,max-image-preview:large`), `theme-color` e `apple-touch-icon`.
  **JSON-LD**: home = `TravelAgency` (com CNPJ/endereço/telefone/foundingDate 1967) + `WebSite`;
  História = `AboutPage` + `BreadcrumbList`; Contato = `ContactPage` + `BreadcrumbList`; cada
  roteiro = `TouristTrip` + `Offer` (preço/moeda reais, casando com a página) + `BreadcrumbList`.
  Cada página com **1 `<h1>`** (Contato promovido de h2→h1); todas as `<img>` com `alt`. og:image
  usa a capa de cada roteiro (`assets/images/roteiro-*.jpg`) e a da Grécia nas páginas gerais.
  `roteiro.html` (template dinâmico) fica `noindex` e fora do sitemap. Criado **`404.html`**
  branded (`noindex`) + `ErrorDocument 404` no `.htaccess`. `sitemap.xml` + `robots.txt` já ok.
  **Deploy (12/07/2026):** as 8 páginas + `404.html` + `.htaccess` publicados na ereHost por
  FTP (docroot direto); verificado no ar (OG, JSON-LD, 404 = HTTP 404). Meta
  `google-site-verification` (token `nGYv…dAVg`) já no `index.html` em produção. Google Tag
  `GT-TNH4L3BV` confirmada pela cliente como a correta.
  **Search Console (13/07/2026):** propriedade **verificada** e **`sitemap.xml` submetido**
  pelo cliente (verificação pelo método HTML tag; não há TXT no DNS, e não precisa). Saúde
  externa conferida: sitemap HTTP 200 `application/xml`, `robots.txt` apontando o sitemap,
  e **as 8 URLs do sitemap retornam 200 sem redirect**, todas com `meta robots index,follow`.
  Indexação e relatórios do Search Console levam de horas a dias para popular.
- **Vídeo do Instagram no roteiro (16/07/2026):** a seção "por que viajar" mostra o **reels
  do roteiro** quando existe, e a **capa + selo de dias** quando não (regra única em
  `poIntroMedia()`, no `roteiros-shared.js`; vale p/ as páginas estáticas e a dinâmica).
  Nunca toca sozinho: `preload="none"`, poster = capa, botão de play central e a tag
  "Assista ao vídeo do roteiro" (somem no play, voltam no pause). Controles **nativos**
  (só depois do play) com `controlslist="nofullscreen"` — a **tela cheia é própria**
  (`.intro__media:fullscreen`), com o reels em `contain` e o fundo preenchido pela capa
  borrada, porque o fullscreen nativo deixaria tarjas pretas no desktop. As páginas
  estáticas nascem com a capa (SEO/LCP) e trocam pelo vídeo via `poFetchRoteiro(slug)`,
  usando o Supabase que elas **já carregam** para o carrossel.
  **Painel:** o campo "Playlist/mix" (`video_list`) foi **removido** (nunca usado; o loop do
  hero já cai no fallback `playlist=<id>`, que é o truque padrão do YouTube). No lugar entrou
  **"Vídeo do Instagram"**, por upload. Aceita **arquivo de qualquer tamanho**: o
  `painel/video-encode.js` re-encoda no navegador com **mediabunny/WebCodecs** para 720x1280
  a ~1,2 Mbps (**áudio copiado sem re-encodar**) e o `upload-video.php` recebe **em fatias de
  5 MB**, contornando o `upload_max_filesize` do cPanel. Se o re-encode sair maior que o
  original, manda o original. Medido: 38,7 MB → 1,1 MB.
  **Vídeo fica na ereHost, em `/videos/<slug>-<tipo>-<hash>.mp4`, NÃO no Supabase Storage** — o
  projeto Supabase é compartilhado com NOX/hd360 e o free tier dá 1 GB de storage e 5 GB de
  egress/mês pro projeto inteiro; um reels de 10 MB visto 500× já come 5 GB. Coluna nova:
  `po_roteiros.video_insta_url` (migration em `supabase/migrations/`).
- **Vídeo capa no hero + fim do YouTube (16/07/2026):** o hero de cada roteiro deixou de ser
  embed do YouTube e virou **`<video>` self-hosted**. Motivo: o **YouTube não faz autoplay no
  mobile** — é bloqueio de plataforma, não configuração (o código já tinha `mute=1` +
  `playsinline=1` + `allow="autoplay"` e mesmo assim o hero ficava parado no celular). Um
  `<video autoplay muted loop playsinline>` toca, e ainda economiza o ~1 MB de JS do player
  que todo visitante baixava. Coluna `video_id` **removida**; entra `po_roteiros.video_capa_url`
  (migration `2026-07-16-video-capa.sql`). Sem vídeo, o hero mostra a `capa_url` parada.
  **São 2 vídeos por roteiro e os perfis são diferentes de propósito** (`painel/video-encode.js`):
  `insta` = 1,2 Mbps, áudio copiado, sem corte, `preload="none"` (só baixa no clique);
  `capa` = **700 kbps, áudio descartado, corte em 15 s** — porque ele **autoplay para todo
  visitante**, então o orçamento de peso não é o mesmo. Medido no fonte 4K de 40 MB da home:
  1280x720/15 s → **1,33 MB** (500k→0,97 MB · 800k→1,51 MB). A **guarda de tamanho não vale
  para a capa**: lá o re-encode não é só compressão (tira áudio e corta), então cair no
  original publicaria vídeo com som e sem corte. Sem WebCodecs o painel **recusa** a capa
  (aceita o reels).
  **Colisão de arquivo (corrigida):** o `upload-video.php` limpa os vídeos antigos por glob.
  Com o nome `<slug>-<hash>.mp4`, o glob `<slug>-*.mp4` casava com os DOIS vídeos e subir um
  apagava o outro em silêncio. Agora o POST manda `tipo` (`insta|capa`, lista fechada) e nome
  e limpeza são escopados: `<slug>-<tipo>-<hash>.mp4` / glob `<slug>-<tipo>-*.mp4`.
  **Legado:** quando o `tipo` entrou, **já havia 5 reels em produção** com o nome antigo
  `<slug>-<hash>.mp4` (a cliente subiu pelo painel em 16/07). Eles não casam com o glob novo,
  então virariam órfãos eternos na troca. Por isso a limpeza do `insta` também varre o padrão
  legado, com regex **fechada em 8 dígitos hex** (`^<slug>-[0-9a-f]{8}\.mp4$`) — assim um
  `<slug>-capa-<hash>.mp4` nunca casa, e a limpeza do reels jamais alcança o vídeo capa.
  **Deploy (16/07/2026): FEITO.** Migrations rodadas (conferido: `video_capa_url` existe,
  `video_id` e `video_list` retornam 400) e tudo publicado por FTP — as 10 páginas HTML,
  `assets/css/{style,roteiro}.css`, `assets/js/{main,roteiro,roteiros-shared,roteiro-dynamic,
  lead-form}.js`, `upload-video.php`, `painel/*`, `videos/home.mp4`, o poster e o `.htaccess`.
  Verificado no ar: `videos/home.mp4` HTTP 200 `video/mp4`, poster 200, `.hero__vid` no CSS,
  `wireHero` no JS, e **zero ocorrência de "youtube" na home**. Cache-buster aplicado
  (`style.css?v=9`, `main.js?v=3`, `roteiro.css?v=5`, `roteiro.js?v=2`, `roteiros-shared.js?v=2`)
  — o `.htaccess` cacheia JS/CSS por 1 mês, sem isso o visitante recorrente ficaria com o
  arquivo velho. `/videos` já tem permissão de escrita (a cliente já subiu reels por lá).
  **Falta:** a cliente subir os vídeos capa pelo painel.
  **ATENÇÃO — as 5 páginas estáticas não estão no banco.** O `po_roteiros` tem 6 roteiros
  (`caminhos-da-india`, `turquia-com-antalia`, `chile-santiago-e-deserto-do-atacama`,
  `tesouros-asiaticos2`, `coreia-do-sul-japao-dubai`, `um-roteiro-...-escandinavia`), e
  **nenhum** é `grecia-terra-mar`/`mercados-de-natal`/`tesouros-asiaticos`/
  `floracao-das-cerejeiras`/`encantos-do-mediterraneo`. Como o vídeo capa das páginas
  estáticas vem do banco via `poFetchRoteiro(slug)`, **elas nunca vão receber vídeo** —
  o fetch devolve null e fica a capa parada (fallback correto, mas sem vídeo). Os roteiros
  do banco são servidos pela `roteiro.html` dinâmica e esses sim recebem. Decidir se as 5
  estáticas entram no banco ou são aposentadas.
- **Home também saiu do YouTube (16/07/2026):** o fundo da `index.html` virou `<video>`
  self-hosted, pelo mesmo motivo (autoplay não funcionava no mobile). **`videos/home.mp4`**
  = 1280x720, sem áudio, ~900 kbps, 24 s, **2,5 MB** (encodado de `videos/video site2.mp4`,
  o fonte 4K de 40 MB, que segue no repo). O poster `assets/images/home-poster.jpg` (52 KB)
  é **background da `.video-bg`**: preenche a tela antes do vídeo e é o que fica com
  `prefers-reduced-motion` (aí o `main.js` remove o vídeo, que nem chega a baixar).
  **Não há campo no painel para a home** — trocar o vídeo é substituir `videos/home.mp4` +
  o poster, por código/FTP. Decisão do cliente: o volume não justifica UI.
  **Com isso o YouTube saiu 100% do site** (home + 5 roteiros).
- **Leads zerados (16/07/2026):** os 3 leads da `po_leads` eram todos de teste
  ("TESTE Fabiano (preview)", "Fabiano Teste UI", "Fabiano Hirtz / teste 1") e foram
  apagados por ID via REST com a `service_role`. Base em 0.
- **Home sem roteiro escrito na mão (16/07/2026):** o `index.html` trazia o Mercados de
  Natal no painel (título, subtítulo, data e descrição). O navegador pintava isso no
  primeiro frame e o `main.js` só sobrescrevia depois da resposta do Supabase, então o
  roteiro **já excluído piscava ~1s em toda recarga** — e, por estar no fonte do HTML,
  cache e guia anônima não tinham efeito. Agora o painel nasce com **chamada
  institucional** (sem dado de roteiro nenhum): é o que veem quem está sem JS e os
  crawlers que não renderizam. **REGRA: nunca colocar dados de roteiro no `index.html`** —
  eles envelhecem e piscam.
  O esconderijo mora num **`<script>` inline no `<head>`** (classe `.po-boot` no `<html>`),
  não no `main.js`: o `main.js` carrega no fim do `<body>` e **na rede real o HTML pinta
  antes** — a primeira tentativa escondia pelo `main.js` e só trocou um flash por outro
  (a chamada institucional piscando ~400ms). **Cuidado: localhost esconde esse bug**, o
  servidor responde instantâneo e o teste local passa como falso positivo; testar com
  latência (CDP `Network.emulateNetworkConditions`, 400ms) ou direto em produção.
  Ser um `<script>` é o que garante o progressive enhancement (sem JS a classe nunca entra).
  Tem **rede de segurança de 5s** que remove a `.po-boot` sozinha: sem ela, se o `main.js`
  não carregar, ninguém tira a classe e o painel (`opacity:0`) fica invisível para sempre.
  O `FALLBACK` do `main.js` **foi removido** — as 5 viagens dele estavam todas com data
  vencida (12/2025 a 06/2026) e uma era a excluída, então ele reapresentaria o bug se o
  banco piscasse. **O banco é a única fonte dos roteiros da home.** Sem banco: só a chamada
  institucional, sem carrossel. **Deploy feito** (`index.html` + `assets/js/main.js`,
  `main.js?v=5`); verificado em produção com 400ms de latência: nada pisca, 6 cards, zero
  erro no console.
  **Armadilha de teste:** o perfil do Chrome do Playwright tinha um **mock interceptando o
  Supabase** (fixture com `"data_label":"x"` e um `grecia-terra-mar` inexistente no banco),
  sobra de sessão antiga, que fez uma verificação passar com dado falso. Se o teste
  divergir do `curl`, conferir se a resposta tem os cabeçalhos `sb-project-ref`/`CF-Ray`;
  limpar com `context.unrouteAll()`.
- **Próximo:** página/seção de Contato geral (formulário de lead na home) · backend
  `enviar.php` + Supabase · painel de leads.
- **Dívida relacionada (não tratada):** o `mercados-de-natal.html` continua existindo e
  **no `sitemap.xml`**, e os catálogos do `historia.js` e do `roteiro.js` ainda listam os 5
  roteiros antigos — a página do roteiro excluído segue no ar e indexável. Mesma raiz (dado
  do banco duplicado à mão), mas mexe em SEO; decidir junto com "as 5 estáticas entram no
  banco ou são aposentadas".
- **A confirmar com a cliente:** identidade nas fotos do arquivo (legendei por local/era,
  ex. "Cuba · arquivo Ilhatur"; se o homem jovem for o próprio fundador, dá para virar um
  "então & agora") · destino dos botões "Ver roteiro" (hoje `#`; criar páginas de roteiro
  ou levar ao WhatsApp) · texto/números da História ("até 300 pessoas", "+35 países",
  2003 rebranding, puxados do site atual).
- **Pendências a decidir:** projeto Supabase (novo x compartilhado) · confirmação do
  host cPanel do domínio · manter/migrar a Google Tag · campos finais do formulário.
