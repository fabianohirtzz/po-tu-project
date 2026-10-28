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
- **Usuário:** `pereirapo@pereiraoliveiraturismo.com.br`
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
login pereirapo@pereiraoliveiraturismo.com.br
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

#### Conferir o deploy: `bash scripts/confere-deploy.sh`

Compara o painel publicado com o repo **arquivo por arquivo**, montando a lista a
partir do proprio `painel/index.html` (arquivo novo entra sozinho na conferencia).

**A regra que ele encarna, e que custou um bug em producao:** conferir a **lista
completa**, nunca a lista do que voce lembra de ter subido. Em 13/09/2026 o deploy
subiu o `painel/index.html` (que passou a pedir `app.js?v=8`) e **nao** subiu o
`painel/app.js` nem o `painel/painel.css`. A conferencia olhou so os arquivos
enviados e deu tudo certo; a aba "Textos do robo" foi ao ar desenhando a tela de
Roteiros, porque o `app.js` publicado nao conhecia a view.

**O `?v=` nao muda o arquivo no servidor.** Ele so muda a URL que o navegador
pede. Subir o `index.html` com versao nova sem subir o arquivo faz o cache-buster
servir o conteudo velho — e pior, carimba o conteudo velho na versao nova por mais
um mes. Quando isso acontecer, **suba o `?v=` de novo**: quem ja abriu o painel tem
o par errado em cache.

`tests/test-cache-buster.mjs` amarra cada `?v=` ao **SHA** do arquivo
(`painel/assets-lock.json`): arquivo alterado sem bump fica vermelho. Pino no
numero na mao nao protege nada — o que existia passou verde com o bug dentro.

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
- **WhatsApp em todo o site (16/07/2026):** o número é **(48) 99604-8882** e o link é
  **`https://wa.me/5548996048882`** — com o **55** na frente. O cliente passou
  `wa.me/48996048882` (sem código do país), que o WhatsApp não resolve de forma
  confiável; o site já usava a forma completa e ela foi mantida em todos os pontos.
  Onde ele aparece: botão **`.hd__wa`** ao lado do CTA de contato no header (os dois
  agora vivem dentro de um `.hd__actions`, para o `justify-content:space-between` do
  `.hd` seguir com 4 filhos) · mesmo botão no **menu mobile** (o header some abaixo de
  960px) · **`.btn--wa` "Reserve pelo WhatsApp"** ao lado de todo "Quero este roteiro"
  (hero + investimento, nas 5 páginas estáticas e no `roteiro-dynamic.js`) · ícone no
  **`.ft__social`** e telefone (`tel:`) no bloco Contato do rodapé · **`.cta__contact`**
  do WhatsApp abaixo do Instagram na página Contato · botões **"Reservar um roteiro" +
  "Falar no WhatsApp"** no fecho da Nossa História (o `historia.css` não tinha `.btn`,
  foi adicionado no mesmo porte do `roteiro.css`).
  **Botão flutuante:** era um envelope apontando pro formulário; agora é o WhatsApp
  (`--wa:#25D366`) em todas as páginas. **Passou a existir também na home** — lá o
  header esconde o botão no celular, então o flutuante é o único acesso; ele fica fora
  da `.stage` travada e não colide com o "Ver roteiro" (conferido a 390px).
  **Texto e ícone dos botões verdes são brancos, por decisão do cliente** (16/07), para
  casar com o botão Contato ao lado. Fica registrado que **branco sobre `#25D366` dá
  ~1.9:1** de contraste (o mínimo AA para texto normal é 4.5:1); é o mesmo par que o
  próprio WhatsApp usa, mas se um dia o contraste virar requisito, a saída é **escurecer
  o verde** e manter o branco, não voltar o texto para escuro.
  No menu mobile o branco precisa de `.sheet__panel .hd__wa{color:#fff}` explícito: o
  `.sheet__panel a` genérico (0,2,0) ganha do `.hd__wa` (0,1,0), então qualquer cor que
  se queira ali tem que vencer aquela regra.
  **Descrição dos dias (`.day__p`) agora é justificada** (`text-align:justify` +
  `hyphens:auto`, que depende do `lang="pt-BR"` que todas as páginas já têm).
- **Página de links da bio `/link` (16/07/2026):** destino do link único da bio do
  Instagram. Standalone (`link.html` + `assets/css/link.css` + `assets/js/link.js`),
  coluna de 420px no **papel morno da Nossa História** (contraste com a home). Fundo de
  **mapa-múndi em SVG inline** com rotas pontilhadas e aviões — **opacidade 7%, é textura,
  não ilustração**. O mapa é **dado cartográfico real** (Natural Earth 110m, domínio
  público), convertido de TopoJSON pelo `tools/mapa-mundi.py` e projetado na
  equiretangular do viewBox 1000x500 (`x=(lon+180)/360*1000`, `y=(90-lat)/180*500`) — a
  **mesma** das rotas, senão o avião pousa fora do continente. Desenhar continente à mão
  virava mancha; refazer o mapa = rodar o script (Antártida fora, e anel que cruza o
  antimeridiano vira polilinha aberta, senão o traço risca o mapa). As rotas são decoração
  fixa (o banco não tem coordenadas, então roteiro novo não ganha rota) e apontam para os
  destinos que a empresa vende. Os **aviões** são o `images/plane.svg` (num `<defs>` com 3
  `<use>`; nascem apontando para cima-e-direita, daí o `rotate(45)` que os alinha ao +x que
  o `rotate="auto"` prende na rota). **Opacidade é por camada, não no container**: a terra
  fica em 7% (é textura), rota e avião sobem para ~0,2 (são o acento) — no container tudo
  achatava em 7% e o avião sumia. Tem: selo do Google 5,0 → `https://www.google.com/maps?cid=10478700579601202326`
  · números da História (anos calculados ao vivo desde 1967 (59 em 2026), 3 gerações, +35
  países) · CTA "Reservar passeio" → `contato.html` · WhatsApp · **cards de roteiro do
  banco** (capa quadrada, dias, período, "Ver roteiro" via `poRoteiroHref`) · redes ·
  rodapé. **`noindex` e fora do `sitemap.xml`** — conteúdo fino e duplicado; indexar só
  criaria concorrência com a home e as páginas de roteiro. `.htaccess` faz o rewrite
  `/link`. UTM (`utm_source=instagram&utm_medium=bio&utm_campaign=link`, + `utm_content=<slug>`
  no card) vai **escrito na URL**, não injetado por JS, para rastrear mesmo sem JS; o
  `lead-form.js` já captura. **Sem banco = sem card**, e o resto da página segue de pé — a
  mesma regra da home, e nunca escrever roteiro à mão no HTML.
  **Logo:** esta página usa **`assets/images/logo-azul.png`**, e é a única que usa. O
  `logo.png` padrão é o **wordmark branco**, feito para as superfícies escuras do resto do
  site (header de vidro, rodapé preto); no papel claro daqui ele sumiria, sobrando só o
  pin. A versão azul veio de `images/logo pereira oliveira turismo azul.png`, que estava
  no repo fora do Git. **Regra: fundo claro pede o logo azul, fundo escuro pede o branco.**
  **Selo do Google:** as 5 estrelas são **visuais**; nada de `AggregateRating` no JSON-LD
  (avaliação que a própria empresa hospeda sobre si é violação de política e não gera
  estrela na busca). **Link do Google:** usar o formato `?cid=`; o botão "Compartilhar" da
  página de resultados gera `share.google/...`, que compartilha a **pesquisa**, não a
  ficha.
  **Logo:** esta página usa **`assets/images/logo-azul.png`** (362x108, ~23 KB), a versão
  de tinta para fundo claro; o `logo.png` padrão é o wordmark branco das superfícies
  escuras. Regra: fundo claro pede o azul, fundo escuro pede o branco.
  **Números iguais:** o contador dos anos vive num `<span>` dentro do `<b>`, então a regra
  do rótulo (`.lk__nums span`) o acertava e o número saía menor e cinza; a regra é
  `li > span`, que pega só o rótulo. **Verificação:** o Chrome `--headless` desta máquina
  **não desce abaixo de ~500px** (janela mínima do Windows), então screenshot a 390 sai
  cortado como falso positivo — validar mobile a 500px ou pelo Playwright MCP.
  Spec: `docs/superpowers/specs/2026-07-16-pagina-link-bio-design.md`.
- **`roteiro.php` (SEO fase 1, 16/07/2026):** a página de roteiro passou a existir também
  renderizada no servidor, em `/roteiros/<slug>` (`roteiro.php?slug=`), porte 1:1 de
  `assets/js/roteiro-dynamic.js` (que só montava a página no navegador e ficava
  `noindex`, então os 6 roteiros à venda não existiam na busca). `lib/po-data.php` lê o
  Supabase com cache em disco; `lib/po-view.php` monta o `<head>`/header/footer/chrome;
  `roteiro.php` monta o corpo (`po_roteiro_html`) e o JSON-LD `TouristTrip`
  (`po_roteiro_jsonld`, só emite `offers` com preço real, nunca inventado). Slug
  inexistente ou inativo devolve **404 de verdade** (`http_response_code(404)` + o
  `404.html`), não 200 com "não encontrado" (isso vira soft-404 indexado pelo Google).
  "Outros roteiros" saem prontos no HTML (`po_outros_roteiros`, busca no banco e pula o
  slug atual) em vez de esperar JavaScript, porque link interno é sinal de SEO.
  **Decisão registrada:** o `<video class="hero__vid">` do hero, que o JS só emitia
  depois de checar `matchMedia('prefers-reduced-motion: reduce')` no navegador, agora
  **sempre** sai no HTML (o servidor não sabe a preferência de quem pediu a página); quem
  pede menos animação passa a **baixar o vídeo capa (~1,3 MB) e não vê-lo**, escondido via
  CSS (`assets/css/roteiro.css`, regra `@media (prefers-reduced-motion: reduce)` em
  `.hero__vid`). Custo aceito em troca de a página existir para o Google.
- **Próximo:** página/seção de Contato geral (formulário de lead na home) · backend
  `enviar.php` + Supabase · painel de leads.
- **SEO fase 1 — DEPLOY COMPLETO E VERIFICADO NO AR (20/07/2026).** As 5 páginas
  estáticas de roteiro foram **aposentadas** (decisão da cliente: são passado) e o site
  passou a servir os roteiros **do banco, por PHP**. Publicado por FTP na ereHost e
  conferido em produção:
  - `/roteiros/<slug>` (via `roteiro.php`) e `/roteiros` (página mãe, `roteiros.php`)
    renderizam **no servidor**, indexáveis, com `robots index,follow`, canonical, OG,
    JSON-LD (`TouristTrip`+`Offer` no roteiro, `ItemList` na mãe). `sitemap.php` gera o
    sitemap **do banco** (roteiro novo no painel entra sozinho, sem deploy) e é servido
    em `/sitemap.xml` via rewrite. O `roteiro.html?slug=` `noindex` **não existe mais**.
  - **Slugs renomeados** (migration `2026-07-16-slug-estavel.sql`, rodada no Supabase):
    `turquia`, `chile-e-deserto-do-atacama`, `tesouros-asiaticos`, `escandinavia`
    (os 2 bons mantidos). **Regra permanente:** slug = identidade estável da viagem
    (sem data/tema/parada da edição); uma edição ativa por slug (índice único parcial
    `po_roteiros_slug_ativo_uniq`); edição nova **herda a URL e a autoridade** da
    anterior. O painel truncava slug no meio da palavra (`.slice(0,60)`); corrigido para
    limite de palavra. `PO_STATIC_ROTEIRO`/`STATIC_ROTEIRO` **removidos** (front + painel).
  - **301 do WordPress antigo:** ~20 URLs que eram **404 sem redirect** (a `/turquia`
    rankeava e jogava fora a autoridade) agora redirecionam para o roteiro/página certa;
    `www`→não-www; conferido 1 salto, sem laço. O `.htaccess` foi **fundido** com o
    bloco `/link` da outra sessão (nunca sobrescrever: FTP não faz merge).
  - **Copy de SEO:** títulos no padrão "Excursão <destino> <ano> · Viagem em grupo saindo
    de Florianópolis" (o público 60+ busca **"excursão"**, não "roteiro"); o diferencial
    que rankeia sem concorrência é **grupo + acompanhante brasileiro + saída de Floripa**,
    não o destino. `<h1>` da mãe = "Viagens em grupo com saída de Florianópolis".
  - **Cache:** `po-data.php` tem cache em disco de 10 min (`/tmp/po-cache`). Depois de
    editar roteiro/rodar migration, ele mascara a mudança por até 10 min. Para forçar,
    apagar os `*.json` de lá (script de uso único; não deixar no servidor).
  - **`prefers-reduced-motion` do hero virou CSS** (`.hero__vid{display:none}`), porque o
    servidor não sabe a preferência: quem pede menos animação baixa o vídeo e não o vê.
  - **Injeções corrigidas na branch:** JSON-LD fechava `<script>` (resolvido com
    `JSON_HEX_TAG`; a flag `JSON_UNESCAPED_SLASHES` era a causa) e `capa_url` escapava do
    atributo `style` (`po_e` vira `&#039;` mas o parser decodifica antes do CSS ler;
    resolvido com `po_css_url()`). Dado de roteiro vem do painel/importador IA: **sempre
    escapar** com `po_e`/`po_css_url`.
  - **Ainda pendente da cliente:** resubmeter o `sitemap.xml` no Search Console e pedir
    indexação de `/roteiros`; **reivindicar o Google Business Profile** (5,0 · 22
    avaliações, provavelmente **não reivindicado** — link "É proprietário desta empresa?";
    é possivelmente o maior retorno de tudo e decide "agência de viagem Florianópolis").
- **Slug repetido no painel (22/10/2026): CORRIGIDO E NO AR.** A cliente reimportou o
  "Vietnã completo e Tailândia com Paris" (já salvo oculto mais cedo) e o painel recusou
  com `duplicate key ... po_roteiros_slug_key`. A migration do slug estável criou o
  índice parcial `po_roteiros_slug_ativo_uniq`, mas **nunca derrubou o `UNIQUE (slug)`
  original**, então a regra "edição arquivada mantém o slug" nunca tinha valido. A
  migration `2026-10-22-slug-sem-unique-global.sql` (**rodada**) derruba a constraint.
  Quando o índice parcial recusar (dois roteiros **visíveis** no mesmo endereço), o painel
  agora diz qual roteiro ocupa o endereço (`rotSaveErro`, `app.js?v=12`). Reimportar o
  mesmo arquivo gera um segundo card oculto: é esperado, basta excluir o repetido.
- **Status da viagem + importar dentro da gaveta (22/10/2026): FEITO E NO AR.**
  - Coluna `po_roteiros.status_viagem` (`em_breve` | `em_viagem` | `esgotado` | null,
    check no banco; migration `2026-10-22-status-viagem.sql`, **rodada**). Separado de
    `ativo`: `ativo` decide se o roteiro existe no site; o status só informa a situação.
  - Vira tag colorida (amarelo / verde / vermelho, classe `st-tag--<chave>`) no card da
    home (canto superior direito), no hero da página do roteiro (acima do `<h1>`), nos
    cards de `/roteiros` e no card do painel. **A lista vive em três lugares que têm que
    andar juntos:** `PO_STATUS_VIAGEM` (`lib/po-view.php`), `poStatusTag`
    (`roteiros-shared.js`) e `STATUS_VIAGEM` (`painel/app.js`); `test-status-tag.mjs`
    confere PHP x JS. Valor fora da lista não emite tag nem classe.
  - **Ícones:** relógio (em breve), avião inclinado (em viagem), círculo cortado
    (esgotado). São SVG em `mask` no `.st-tag::before`, pintados com `currentColor`,
    então seguem a cor do texto e não exigem markup.
  - **REGRA: a tag aparece em TODO lugar que mostra roteiro** (pedido do cliente):
    home, `/roteiros`, hero e "Outros roteiros" da página do roteiro, carrossel da
    Nossa História, cards do `/link` e painel. O CSS é **um arquivo só**,
    `assets/css/status-tag.css`, carregado por todas essas páginas (antes era copiado
    por CSS e faltava onde ninguém copiou). Card novo de roteiro em qualquer lugar =
    chamar `po_status_tag()`/`poStatusTag()` + carregar o `status-tag.css` + posicionar
    o seletor lá; `test-status-tag.mjs` confere cada renderizador e cada página.
  - **O painel limpa o cache do site ao salvar/excluir roteiro** (`limpa-cache.php`,
    só com login; `po_cache_limpa()`). Antes o status levava até 10 min para aparecer
    em `/roteiros` e nas páginas dos roteiros.
  - **"Últimas vagas" (`ultimas_vagas`)** entrou depois (migration
    `2026-10-22-status-ultimas-vagas.sql`, **rodada**): laranja `#F07C1A` com texto
    escuro e ícone de chama; vira `LimitedAvailability` no JSON-LD. Ordem da lista =
    ciclo da viagem: em breve, últimas vagas, esgotado, em viagem. O teste exige a
    MESMA lista, na mesma ordem, em PHP, `roteiros-shared.js` e painel, e cor + ícone
    de cada uma no `status-tag.css` e no `painel.css`.
- **Card do carrossel da home é clicável (22/10/2026).** Imagem e título do card do
  centro abrem o roteiro (o título é `<a>` de verdade). A regra está em
  `slideClique()` (`main.js`, `test-home-slide.mjs`): **click até 400 ms depois de um
  arraste é ignorado**, senão arrastar abriria o roteiro (e antes fazia o carrossel
  voltar ao card de origem). **Card lateral NÃO recebe clique** e já não recebia antes:
  no 3D ele fica atrás do `.rail__track`, que leva o clique (`elementFromPoint` dá o
  trilho). Acesso aos laterais é por setas e arraste.
- **Trilha "Início > Roteiros > nome" removida (22/10/2026)**, a pedido do cliente.
  Só existia no hero do `roteiro.php`; o CSS `.hero__crumb` saiu junto e o teste da
  página trava a ausência. Os `BreadcrumbList` em JSON-LD de Contato e História
  (invisíveis) ficaram.
  - **Esgotado vira `SoldOut` no JSON-LD** (antes era `InStock` fixo).
  - **Cache:** a home lê o banco ao vivo; `/roteiros` e `/roteiros/<slug>` passam pelo
    cache de 10 min do `po-data.php`, então o status leva até 10 min para aparecer lá.
  - **O importador saiu da barra e mora na gaveta.** A IA só preenche campo **vazio**
    (`rotPreenche`, função pura, `test-rot-preenche.mjs`); dia a dia, valores e galeria
    são um bloco cada e só entram se o bloco estiver vazio. Galeria já com foto nem sobe
    as imagens do arquivo (ficariam órfãs no storage). Importar não abre gaveta nova.
- **Pré-visualização do roteiro pelo painel (22/10/2026): NO AR.** O "Ver" do painel
  abria `/roteiros/<slug>`, que só serve roteiro **ativo**, então todo roteiro oculto
  dava 404. Agora `rotVer()` (`painel/app.js`) posta `preview_id` + `sb_token` para a
  mesma URL; o `roteiro.php` confere o login (`po_auth_ok`) e lê **por id, com o token
  dela** (`po_fetch_roteiro_preview`), então quem libera o rascunho é a RLS, sem
  `service_role`. Leitura com token **nunca passa pelo cache em disco** (o cache é
  compartilhado e serviria o rascunho ao próximo anônimo). Resposta `noindex` +
  `no-store` + faixa "Pré-visualização do painel". Login inválido = o mesmo 404.
  Por id, não por slug: edições ocultas podem repetir o slug.
- **Bug que eu introduzi e corrigi no mesmo dia (22/10):** a emenda que pôs a tag de
  status no card de `/roteiros` colou `<img` no atributo (`<imgsrc=`) e **as fotos
  sumiram da página**. O teste olhava só a tag. Agora `test-roteiros-page.php` confere
  `<img src=` de cada card. Lição: ao emendar HTML em string, testar o elemento vizinho.
- **GEO (buscas com IA), 22/10/2026.** Auditoria no ar: nenhum robô de IA é bloqueado
  (GPTBot, OAI-SearchBot, ChatGPT-User, ClaudeBot, PerplexityBot, Google-Extended: 200);
  páginas de roteiro têm ~2.000 palavras sem JS (ótimo); **a home tem 126**.
  - **`/perguntas-frequentes` (`faq.php` + `assets/css/faq.css`): NO AR.** 11 perguntas
    em `<details>` nativo; o `FAQPage` do JSON-LD sai da **mesma lista** que o texto
    visível (`po_faq_itens()`, `test-faq.php` confere item a item). **Regra do conteúdo:
    só afirmar o que site e banco já publicam**; o que varia por roteiro (aéreo, seguro,
    acompanhante com número mínimo no Chile) manda para a página do roteiro. Rich result
    de FAQ o Google só mostra para gov/saúde desde 2023: a marcação é para IA. Link no
    rodapé de todas as páginas e no sitemap.
  - **`/llms.txt` (`llms.php`): NO AR**, gerado do banco como o sitemap (roteiro oculto
    nunca entra). Sem efeito comprovado em buscador grande; custo zero.
  - **Texto oculto na home: RECUSADO** (spam de "texto oculto" do Google, risco de ação
    manual no site inteiro). **Feito no lugar: painel "Sobre" (`#sobre` no `index.html`)**,
    aberto por um link pequeno, mas visível, no canto inferior esquerdo ("Sobre a
    Pereira Oliveira"). Abre por `:target`, então funciona sem JS; o `main.js` só põe
    Esc e foco. ~600 palavras escritas a partir de pesquisa de busca do nicho (viagem
    em grupo saindo de Florianópolis, excursão com coordenador brasileiro, melhor idade,
    viajar sem companhia, seguro na Europa): dado do MTur (84% dos 60+ preferem grupo)
    e Schengen (30 mil euros). `test-home-sobre.mjs` trava: link não pode ficar
    invisível (opacidade >= .5), painel abre por `:target`, **nada de preço/data de
    roteiro** ali. Título da home virou "Viagens em grupo saindo de Florianópolis ·
    Pereira Oliveira Turismo" (era o slogan, que ninguém busca).
    **Reescrito no mesmo dia para ~900 palavras** (cliente achou pouco): como funciona,
    acompanhante brasileiro, terceira idade/aposentados, viajar sem companhia, "vale a
    pena viajar em grupo", destinos, cidades de SC (Joinville, Blumenau, BC, Itajaí,
    Criciúma, São José, Palhoça), seguro/passaporte/visto, pagamento, reserva.
    **A lista de destinos só cita o que comprovadamente esteve nos roteiros** (banco +
    slugs do WordPress antigo nos 301): não inventar país.
  - **A HOME AGORA É SERVIDA POR `index.php` (22/10/2026).** Ele lê o `index.html` e
    troca o marcador `<!--PO:PROXIMAS-VIAGENS-->` (dentro do painel "Sobre") pela
    lista dos roteiros **visíveis** (`po_fetch_roteiros()`, cache de 10 min que o
    painel limpa ao salvar). `.htaccess`: `DirectoryIndex index.php index.html` (antes
    era o contrário, por medo do `index.php` do WordPress, que não existe mais) e
    `/index.html` reescrito para o `index.php`. **Editar a home continua sendo editar o
    `index.html`**; o PHP só injeta a lista. Banco fora: vira link para `/roteiros`.
    Escolhido no lugar de uma rotina semanal regravando o HTML: o FTP do deploy
    desfaria a regravação, e a lista ficaria até 7 dias velha. `test-home-index.php`.
  - **"Coordenador/acompanhante", NÃO "guia".** Guia de turismo é profissão
    regulamentada (Lei 8.623/93, Cadastur). O site só pode chamar o acompanhante de
    "guia brasileiro" se ele tiver registro; **perguntado ao cliente em 22/10, sem
    resposta ainda**. Guias locais: o idioma varia (Turquia português, Chile e Coreia
    espanhol), então nunca prometer "guia em português" de forma geral.
  - **Menu ganhou "FAQ"** (→ `/perguntas-frequentes`) em todas as páginas; o rodapé
    segue com "Perguntas frequentes". FAQ centralizado. O menu da 404 apontava
    "Roteiros" para `/index.html#inicio`; corrigido para `/roteiros`.
  - **Search Console (22/10/2026), URL por URL:** 9 roteiros do WordPress em 404
    ganharam 301 → `/roteiros`; `/india-nepal` e `/tesouros-asiaticos.html` apontavam
    para roteiros ocultos (404) e agora vão para `/roteiros`; restos do WordPress
    (`/feed`, `/author`, `/wp-includes`, `/wp-content`, `/wp-json`, `xmlrpc`,
    `wp-login`) respondem **410**. `test-htaccess-redirects.php` aplica as regex e
    garante que nenhuma URL do site atual cai nelas. **Chile e Tesouros do Oriente
    estavam em "Detectada, não indexada" (nunca rastreados)**: pedir indexação manual.
  - **Pendências:** Bing Webmaster (cliente importando do Search Console); ficha da
    empresa no JSON-LD sem CEP, coordenadas, horário, e-mail, fundador e `sameAs` além do
    Instagram (aguardando dados); Google Business Profile ainda sem dono; Cadastur.
  - **Achado, não corrigido:** o select "Roteiro de interesse" do `contato.html` ainda
    lista os 5 roteiros aposentados (Mercados de Natal, Grécia etc.).
- **PROVA SOCIAL: avaliações do Google + Instagram (22/10/2026): NO AR.**
  - Tabelas `po_avaliacoes` e `po_instagram` (migration `2026-10-22-avaliacoes-instagram.sql`,
    **rodada**; RLS igual ao `po_roteiros`). Aba **"Prova social"** no painel
    (`painel/vitrine.js`). Montado no servidor por `lib/po-social.php`:
    `nossa-historia.php` (lê o `nossa-historia.html` e troca `<!--PO:AVALIACOES-->` e
    `<!--PO:INSTAGRAM-->`; `/nossa-historia.html` é reescrito para ele) e `index.php`
    (`<!--PO:AVALIACOES-SOBRE-->`, 3 avaliações + selo no painel Sobre).
  - **Avaliações:** as 12 foram lidas do perfil do Google pelo navegador e gravadas no
    banco (nota 5,0, 27 avaliações, todas 5 estrelas em 22/10). **Nome publicado =
    primeiro nome + inicial (LGPD)**, garantido por `poNomeCurto` no ponto de gravação.
    **Texto fiel ao Google**, sem corrigir ortografia (só o fim truncado com "…" foi
    cortado). **A avaliação do Fabiano (desenvolvedor) fica de fora**: prova social
    de quem fez o site é enganosa. **Sem `AggregateRating`/microdata** (teste trava).
    Link do perfil: `?cid=10478700579601202326`.
  - **Instagram = VÍDEOS enviados pelo painel (padrão Casa Angelina).** A 1ª versão
    (colar o link e buscar a capa pelo `og:image`) foi **recusada pelo cliente** no
    mesmo dia e removida (`insta-capa.php`, `lib/po-insta.php`, apagados do servidor).
    Agora: a cliente **sobe o reels**; o painel tira um quadro como capa (canvas → jpg
    no storage do projeto via `uploadImg`), comprime com o `POVideo` dos roteiros e
    envia ao `upload-video.php` com **tipo `feed`** e "slug" = id do item `ig-<8hex>`
    (arquivo `/videos/ig-<id>-feed-<hash>.mp4`, na ereHost, nunca no Supabase). Id por
    item = excluir/trocar um nunca apaga outro; `poIdDoVideo` só reconhece esse
    formato, então a exclusão do feed nunca alcança vídeo de roteiro (testado).
    Colunas `video_url`, `poster_url`, `url` (link do post, **opcional**: sem ele o
    "Ver no Instagram" vai ao perfil), ordem pelas setas; o site mostra os 12 primeiros.
    **A grade não tem nenhum `<video>`** (teste trava): só capas; `assets/js/insta-feed.js`
    toca a prévia muda no hover (desktop) e abre o visualizador com som no clique.
    Medido: **0 bytes de vídeo antes da interação**. Sem JS, cada capa é link para o
    post/perfil. Carrossel horizontal 9:16 com setas (mesmo estilo do de roteiros).
    **Os 9 vídeos iniciais** vieram do Snapinsta pelo Fabiano, comprimidos aqui com
    ffmpeg (1,2 Mbps, áudio copiado). O 10º era o mesmo reels da Índia repetido em
    loop (76s = 2×38s) e ficou de fora. `video-encode.js` passou a ter `?v=` e entrou
    no `assets-lock.json`. **Armadilha:** `md5sum` com caminho Windows prefixa `\` no
    hash; usar `md5sum < arquivo`.
  - **Do perfil do Google também saíram:** CEP **88015-601**, coordenadas
    -27.5887033,-48.5565705 e horário (abre 08:00). Ainda não foram para o JSON-LD.
- **Painel: "Prova social" virou DUAS abas (23/10/2026): "Avaliações" e "Instagram"**
  (`data-view` `avaliacoes` e `instagram`, mesmo `painel/vitrine.js` e mesmo
  `poCarregaVitrine`). **Instagram = só envio de vídeo**: o campo de link do post saiu
  (o "Ver no Instagram" do site vai ao perfil; `url` fica null). Compressão automática
  no envio (POVideo, perfil do reels); sem WebCodecs, avisa e manda o original.
- **URLs sem `.html` (22/10/2026): NO AR.** `/nossa-historia`, `/contato`,
  `/politica-de-privacidade`, `/link`, `/`. O `.htaccess` dá **301 do `.html` para o
  limpo** olhando `%{THE_REQUEST}` (o que o navegador pediu), o que impede loop com o
  rewrite interno de volta para o arquivo; barra no fim também vira 301 (rewrite com
  barra quebraria os caminhos relativos `assets/...`). **Havia o redirect contrário**
  (`/nossa-historia` → `.html`, `/contato` → `.html`), removido; `/sobre-nos` agora vai
  para `/nossa-historia`. Todos os links internos, canonical, og:url, JSON-LD, sitemap
  e `llms.txt` usam o limpo (`test-urls-limpas.php`). Medido no ar: 1 salto, UTM
  preservado. **Os arquivos continuam `.html` no disco**; só o endereço mudou.
- **1967 da Nossa História (22/10/2026):** o número recorta agora a foto do **grupo
  Ilhatur na Disney** (`turismo2.jpg`, 1440x600, camisetas "30 anos, 1997"), no lugar
  da foto do fundador entre folhagens; Rubik **900** (peso adicionado ao link do
  Google Fonts da página) e `letter-spacing:-.055em`. O cliente escolheu a **primeira
  versão** testada (celular a 30vw); versões que preenchiam mais a largura foram
  recusadas. O `<figure>` mantém a margem padrão do navegador nessa versão.
- **Selo do Cadastur (23/10/2026): NO AR.** Certificado de **Agência de Turismo** do
  Ministério do Turismo (CNPJ, válido de 25/03/2025 a 25/03/2027). Logo **extraído do
  próprio PDF** (desmistura do fundo azul): `assets/images/cadastur.png`, **branco**, só
  sobre fundo escuro (rodapé) ou sobre o cartão azul `.cad-selo` (Contato, FAQ). Rodapé de
  todas as páginas (`po_footer()` + cópias em `contato.html`, `nossa-historia.html`,
  `politica-de-privacidade.html`), selo no Contato e no FAQ, e uma pergunta nova no FAQ
  ("é uma agência regularizada?", entra no `FAQPage`). CSS único `assets/css/cadastur.css`.
  Link = a consulta pública do **QR code do certificado**
  (`PO_CADASTUR_URL`). **A validade NÃO vai para o site** (data vencida publicada é pior
  que nenhuma; `test-cadastur.php` trava). **Renovar até 25/03/2027.**
  **O Cadastur é da EMPRESA:** não libera chamar o acompanhante de "guia", que exige
  Cadastur de pessoa física, como guia de turismo.
- **Fase 2 (planejada):** avaliações do Google no site (mãe, roteiro, História), cadastro
  manual pelo painel (permite filtrar negativa). **Sem `AggregateRating`** — a diretriz do
  Google proíbe estrela de review que a própria empresa controla; é violação com risco de
  ação manual. Avaliação no site é **conversão, não SEO**. Nota real **5,0** pode ser
  publicada; usar `<blockquote>` semântico.

## Automação de WhatsApp + funil automático (11/09/2026)

Projeto novo, vendido por **R$ 2.850 (3x) ou R$ 2.300 (2x)**, sem mensalidade. Proposta em
`propostas.freelainhome.com.br/proposal-2w4UybS27p5Sotv6sc7hKoVc/`.
Spec: `docs/superpowers/specs/2026-09-11-automacao-whatsapp-crm-design.md`.

- **Arquitetura:** Cloud API oficial da Meta em **modo de convivência** (a cliente segue
  atendendo pelo app do celular; o mesmo número roda na API em paralelo). Motor próprio em
  PHP no cPanel, **sem BSP e sem IA**. Descartados n8n (exige VPS, o cPanel não roda Node) e
  ManyChat/BotConversa (mensalidade + ainda exigiriam a ponte com o Supabase).
- **Custo:** lead vindo de anúncio Click-to-WhatsApp abre janela grátis de 72h; mensagem que
  ela digita no app **nunca** é cobrada. O único custo real é a **transmissão** (~R$ 0,31 por
  destinatário), porque a coexistência **desativa a lista de transmissão do app**.
- **PLANO 1 DE 3 — MOTOR DA CONVERSA: FEITO E MERGEADO** (`main`, merge `14304be`).
  8 tarefas em TDD, 13 arquivos de teste, revisão por tarefa + revisão final de branch.
  Arquivos: `whatsapp.php` (webhook), `wa-cron.php`, `lib/wa-{config,db,send,fone,roteiro,
  webhook,motor,timeout}.php`, migrations `2026-09-11-whatsapp-motor.sql` e
  `2026-09-11-lembrete-menu.sql` (**as duas já rodadas no Supabase**).
- **NO AR desde 12/09/2026, porém INERTE.** Os 10 arquivos do motor estão publicados na
  ereHost, mas o `config.local.php` do servidor **ainda não tem as chaves da Meta** — e com
  `WA_APP_SECRET`, `WA_VERIFY_TOKEN` e `WA_CRON_KEY` vazios os dois endpoints **recusam
  tudo** (segredo vazio nunca autoriza, por decisão de segurança). Conferido em produção:
  `whatsapp.php` devolve 403 no GET sem token, no `hub_verify_token` chutado e no POST sem
  assinatura; `wa-cron.php` devolve 403 sem chave e com chave chutada; os `lib/wa-*.php`
  servem corpo vazio. **Nenhum cron foi cadastrado no cPanel** — cadastrar só junto com as
  chaves, senão ele roda de hora em hora sem ter o que varrer.
- **Regras permanentes deste subsistema:**
  - **O eco (`smb_message_echoes`) silencia o robô para sempre naquele contato**, inclusive
    quando o eco é atalho ou PDF. Robô e humana falando por cima uma da outra é o pior modo
    de falha do sistema.
  - **Estado só avança se o envio saiu** (`ok=true`, que garante `wamid`). Senão o lead fica
    "aguardando resposta" de mensagem que nunca chegou e o cron o encerra.
  - **Idempotência por `wamid`**, gravando antes de processar; `duplicado` pula, `falha`
    processa assim mesmo (perder mensagem de cliente é pior que duplicar).
  - **Só a pergunta da data desqualifica.** Quem tem data e nunca viajou em grupo é o
    cliente-alvo, não um descarte. Incerteza ("não sei ainda") devolve `null` e não mexe no
    funil.
  - **Segredo vazio ou com menos de 16 caracteres nunca autoriza** (`hash_equals('','')` é
    `true`; foi buraco real no webhook e no cron).
  - **`po_config()` não expõe segredo** — é carregada no render público das páginas de
    roteiro. Segredo vem de `wa_config()`, em `lib/wa-config.php`.
  - Status do funil: `novo` (contato feito) → `atendimento` (qualificado) → `negociacao`
    (proposta) → `venda`. `venda`/`venda_at` intocados, então ROAS e ciclo seguem valendo.
- **Pendências conhecidas:** `docs/superpowers/reviews/2026-09-11-wa-motor-pendencias.md`.
  As duas primeiras bloqueiam o go-live.
- **PLANO 2 DE 3 — PAINEL DO FUNIL: FEITO E MERGEADO** (`main`, merge `e7145ed`).
  6 tarefas em TDD, a suíte foi de 13 para **19 arquivos** de teste (o runner passou a
  rodar também os `test-*.mjs`, que existiam e nunca rodavam). Arquivos novos:
  `painel/{clientes,funil,conversa}.js`. **NO AR desde 12/09/2026** (ver Deploy abaixo).
  - **Aba Clientes:** lista **pessoas**, não interesses avulsos. A ficha agrega por
    telefone (spec 7.1), sem tabela nova. O KPI "já viajaram" existe porque é a lista
    que a transmissão do plano 3 vai querer.
  - **Aba Funil:** kanban de 5 colunas. Arrastar no desktop, menu de etapas no celular
    (HTML puro não tem arrastar por toque e o projeto não carrega biblioteca de front).
  - **Conversa** do WhatsApp numa aba dentro da gaveta do lead.
  - **Regras permanentes deste subsistema:**
    - **`semresposta` NÃO é legado.** É o `DEFAULT` de `po_leads.status`, então todo lead
      do formulário do site nasce assim. Cai em "Contato feito" junto com os do WhatsApp.
    - **Cada tela tem seu recorte de filtro:** o Funil ignora o filtro de status (as
      colunas *são* os status) e a aba Clientes ignora o de mês (base de clientes não é
      recorte mensal). A aba Leads segue como sempre foi.
    - **Somatório de venda filtra por `status === 'venda'`**, nos relatórios e na ficha.
      Sem isso, tirar um card de "Contrato assinado" deixava o valor inflando o
      faturamento e o ROAS para sempre, em silêncio.
    - **A gaveta só força `status='venda'` quando o valor acabou de mudar.** Antes,
      salvar uma nota recarimbava `venda_at` com a data de hoje e apagava a data real do
      fechamento, que alimenta o ciclo de venda.
    - **A consulta da conversa filtra por `wa_id` e isso é travado por teste.** O filtro
      já sumiu uma vez num commit que prometia outra coisa, e a tela passou a mostrar as
      mensagens de todos os contatos. Ver as pendências.
  - **Pendências:** `docs/superpowers/reviews/2026-09-11-painel-funil-pendencias.md`.
    A primeira (duas linhas de teste) fecha o gatilho do vazamento acima.
- **DEPLOY (12/09/2026): FEITO.** 16 arquivos por FTP, conferidos byte a byte contra o
  local e por HTTPS: `painel/{index.html,app.js,painel.css,clientes,funil,conversa}.js`,
  `lib/wa-{config,db,fone,send,roteiro,webhook,motor,timeout}.php`, `whatsapp.php` e
  `wa-cron.php`. `config.local.php` **não** foi tocado. O site público segue de pé
  (home, `/roteiros`, `/roteiros/turquia`, `/link`, `/contato.html` todos 200).
  - **Cache-buster obrigatório no painel.** O `.htaccess` cacheia JS/CSS por 1 mês e o
    `painel/index.html` não tinha versão nenhuma nas tags: sem o `?v=` a cliente seguiria
    com o `app.js` e o `painel.css` de julho. Hoje está `painel.css?v=2`, `app.js?v=2`,
    `{clientes,funil,conversa}.js?v=1`. **Toda mexida nesses arquivos exige subir o número.**
  - **Ordem do upload importa:** os JS novos primeiro, o `index.html` por último. Ao
    contrário, existe uma janela em que a página já pede arquivos que ainda dão 404.
- **PLANO 3 DE 3 — IMPORTADOR E AUDITORIA DE FUSÃO: FEITO E REVISADO** (branch
  `plano3-importador`, 24 commits, **ainda não mergeada nem empurrada**). 9 tarefas em TDD,
  a suíte foi de 19 para **31 arquivos**. Arquivos novos: `lib/wa-{import,vcard,import-carga}.php`,
  `lib/po-auth.php`, `contatos-importar.php`, `painel/{fone,importar-contatos}.js`,
  `scripts/importa-crm.php`, migrations `2026-09-12-ficha-cliente.sql` e
  `2026-09-12-pos-carga-crm.sql` (**as duas já rodadas**).
  - **A base do CRM antigo já está carregada:** 775 fichas (de 778, 3 eram a própria
    agência repetida), com 423 CPFs e 289 passaportes. `po_leads` foi de 11 para **786**.
  - **O número que decide o plano 4:** dessas 775, só **22 têm celular**. 138 têm só fixo
    e **615 não têm telefone nenhum**. A base do CRM é ficha de cliente, **não lista de
    disparo** — quem vai encher a transmissão são as duas agendas de celular.
  - **Regras permanentes deste subsistema:**
    - **As três regras do Armando (dono), que são critério de aceitação:** (1) o registro
      com histórico é o dono e nunca é sobrescrito; (2) a fusão **só soma, nunca zera**;
      (3) **nunca duplicar**.
    - **Identidade por camadas, a primeira que bate vence:** CPF → celular E.164 →
      nome + data de nascimento. **Fusão automática só por CPF ou celular**; nome+nascimento
      apenas **sugere** (vira `revisar`).
    - **Fixo nunca casa identidade.** Há fixo de empresa repetido em 3 fichas; se casasse,
      três clientes virariam um.
    - **`WA_IMPORT_CAMPOS_PREENCHIVEIS` é a trava estrutural da regra 1.** Se `status`,
      `venda`, `venda_at`, `notas` ou `qualif_*` entrarem em `preenche`, uma importação de
      agenda apaga o funil e o faturamento. A escrita leva **exatamente** as chaves de
      `preenche`, nada mais.
    - **Da agenda, só entra quem tem o marcador "PO"** no nome. O regex precisa do
      modificador `/u`: sem ele, `PO` casa dentro de `Poá`/`Poços` e come o nome.
    - **Leitura de banco tem que distinguir erro de vazio** (`wa_db_select_estrito`). Com
      `wa_db_select`, um Supabase fora do ar faz a base ler como vazia e **todo contato do
      arquivo entra como novo**, duplicando tudo com a tela dizendo sucesso.
    - **Revogar privilégio por COLUNA não restringe nada no Supabase** — os grants são no
      nível da TABELA e quem restringe é a **RLS**. Foi assim que se fechou o buraco de
      `anon` poder plantar contato já `revisado=true`.
    - **Nunca usar dado real de cliente em fixture de teste.** Aconteceu duas vezes nesta
      branch (CPF válido e ID do CRM); o repositório é **público**.
  - **Pendências que bloqueiam o uso real do importador** (viraram o plano 3.1): não existe
    **nenhuma** UI de `revisado`/`cliente` no painel, então contato de agenda entra
    `revisado=false` e **não há caminho para virar `true`**; o preview calcula `preenche` e
    nunca o mostra; as 775 entraram no denominador dos relatórios do mês sem filtro; e
    reaplicar o mesmo arquivo duplica as fichas sem CPF nem celular.
- **PLANO 3.1 — REVISÃO, PREVIEW E RELATÓRIOS: FEITO, MERGEADO E PUBLICADO.** 5 tarefas em
  TDD; a suíte foi de 31 para **33 arquivos**. Novos: `painel/revisao.js`, migrations
  `2026-09-12-import-lotes.sql` e `2026-09-12-import-lotes-estado.sql` (**as duas rodadas**).
  - **Aba Revisão:** a fila de contatos importados, com ação em massa. Sem ela não existia
    **nenhum** caminho para um contato virar `revisado=true`, e a importação de agenda era
    um beco sem saída.
  - **Regras permanentes deste subsistema:**
    - **"Marcar todos" seleciona só os pendentes**, mesmo com "mostrar já revisados" ligado.
      Sem isso, um clique em "Não é cliente" tirava os 775 clientes das campanhas de uma vez.
    - **Ação em massa vai em lotes de 100.** `.in('id', ids)` com ~180 ids já estoura o
      buffer de 8 KB do gateway (414). A spec 9.5 pede "lista em lotes" por esse motivo.
    - **O lote de importação é RESERVADO antes da escrita** (o `unique` em `hash` é o lock),
      com `concluido_at` marcando o fim. Registrar só no fim deixava passar o retry disparado
      enquanto a primeira requisição ainda rodava — que é justamente o que o timeout produz.
      Linha incompleta e mais velha que 10 min é órfã e libera nova tentativa.
    - **`wa_id` está na whitelist da fusão.** Sem isso, uma ficha existente com telefone e
      `wa_id` nulo fundia sem ganhar o `wa_id`, e o motor criava um lead novo na primeira
      mensagem. São 11 fichas nessa situação hoje.
    - **O KPI da aba Leads ignora a base importada; a TABELA mostra tudo.** A tabela é o que
      permite achar a ficha de um cliente antigo; o KPI é o número que ela lê primeiro.
- **FAXINA + TELA DE TEXTOS (13/09): FEITA, MERGEADA E NO AR.** 5 tarefas; a suíte foi de
  33 para **35 arquivos**. Novo: `painel/textos.js`, `lib/po-fone-lead.php`.
  - **`wa_e164` aceita os formatos que export de agenda produz** (`0048…`, `048 …`), que
    antes viravam `null` e faziam o contato entrar **sem telefone**. Medido sobre 96 mil
    entradas: **zero telefones que funcionavam pararam de funcionar**.
  - **Regras permanentes deste subsistema:**
    - **O `wamid` de um evento `status` É o da mensagem original**, e `po_wa_mensagens.wamid`
      é `unique`. Por isso status **não** passa pela idempotência de `po_wa_mensagens`: eles
      competiriam pela mesma linha e o eco viraria `duplicado`, fazendo **o robô não se
      calar**. A idempotência de entrega é do plano 4, em `po_wa_envios`.
    - **Estrangeiro discado por `00` pode virar celular brasileiro plausível.** A regra não é
      uma lista de países: é **todo DDI de dois dígitos que também é DDD válido**. Cuba,
      Espanha, Peru e Coreia estão no catálogo de roteiros da casa. Conferir a lista
      importada antes do primeiro disparo pago.
    - **A ordem das perguntas é contrato:** `wa_respostas_numeradas` atribui sempre
      `1 → data`, `2 → grupo`. Texto com as perguntas trocadas transforma o cliente-alvo em
      lead perdido. A tela de textos valida isso.
    - **Limite da Cloud API:** 1024 caracteres para legenda de documento e corpo de lista,
      4096 para texto. `wa_send_text` e `wa_send_document` **não truncam** — acima disso a
      Meta devolve 400 e o cliente não recebe nada, em silêncio.
    - **Trava tem que ser no PONTO DE CHAMADA, não só na função pura.** Foi assim que o
      filtro `wa_id` da conversa sumiu uma vez, e assim que a Task 4 passou verde reintroduzindo
      o próprio bug.
  - **A tela de Textos** deixa a cliente escrever as 7 mensagens do robô sem SQL. São elas
    que viram os **templates submetidos à Meta**. A linha `saudacao` continua no banco e o
    motor **nunca a lê** (resíduo do seed, decisão de não apagar).
- **PLANO 4 DE 4 — TRANSMISSÃO: FEITO, MERGEADO E NO AR, PORÉM INERTE (14/09).**
  8 tarefas em TDD; a suíte foi de 33 para **42 arquivos**. Novos: `campanha.php`,
  `lib/wa-camp.php`, `lib/wa-camp-fila.php`, `lib/wa-camp-recibo.php`,
  `painel/campanhas.js`, migration `2026-09-14-transmissao.sql` (**já rodada**).
  Pendências e as 24 decisões da execução:
  `docs/superpowers/reviews/2026-09-14-transmissao-pendencias.md`.
  - **Está no ar e não pode disparar.** `WA_PHONE_ID` está vazio (o número só ganha
    id depois de conectado em convivência) e **o cron não foi cadastrado no cPanel**.
    O `campanha.php` **recusa criar e drenar** enquanto o número não estiver conectado:
    sem ele todo envio falharia, e `falha` é **terminal**, então uma campanha criada
    agora queimaria a base sem possibilidade de reenvio.
  - **Regras permanentes deste subsistema:**
    - **O cadeado da campanha é por FICHA e por NÚMERO.** A Meta cobra por número e a
      base tem ficha repetida da mesma pessoa (CRM antigo mais agenda). Só o índice
      por ficha deixava o mesmo telefone ser cobrado duas vezes.
    - **Quem pede SAIR é excluído por NÚMERO, não por ficha.** `wa_lead()` acha só por
      `wa_id` e as fichas importadas não têm esse campo, então o SAIR criava ficha nova
      enquanto a antiga continuava recebendo — com a tela dizendo que não.
    - **Reserve antes de enviar, e tome posse antes de chamar a Meta.** São duas travas
      diferentes: a primeira impede duplicar a LINHA, a segunda impede duplicar o ENVIO.
      A posse é um PATCH que casa `status=eq.reservado`; quem escreve primeiro leva.
    - **Linha em `enviando` conta como pendente.** Contar só `reservado` fazia a campanha
      ser concluída com gente sem receber, e a recuperação de órfãs só roda enquanto
      alguém drena.
    - **`falha` é terminal e fica no TOPO da escada de status.** Sem posto, caía no
      degrau zero e um recibo reentregue depois de uma falha ressuscitava o envio.
    - **Contador de entrega não vira coluna.** `enviados`/`entregues`/`lidos`/`falhas`
      são contados de `po_wa_envios` na hora de mostrar. Contador copiado desanda no
      primeiro webhook fora de ordem.
    - **Trava no PONTO DE CHAMADA.** Vale para o `select=` do `campanha.php`, a fiação
      do `wa-cron.php` e a idempotência do webhook. Três defeitos deste plano foram
      exatamente isso: a função pura travada e o chamador livre.
    - **Teste que fabrica resposta que o servidor não manda não é teste.** Um dublê
      inventava um campo e metade de uma guarda estava morta com a suíte verde.
    - **`WA_CAMP_TETO_DIARIO` está em 250**, que é o piso da Meta para número novo.
      Subir exige conferir o tier real no painel dela, não editar a constante.
  - **Perdeu por DATA entra na transmissão (23/10/2026, pedido do cliente).**
    `wa_camp_perdeu_data()` = `status='perdido'` + `qualif_data=false` (o que o robô
    grava quando a data não serve). Entra **sem passar pela Revisão** (o lead veio
    da própria pessoa, não de agenda); SAIR, fixo e "Não é cliente" marcado na
    Revisão continuam valendo. `#perdeu` manual NÃO conta (não diz o motivo).
    Para os perdidos à mão, a gaveta do lead tem a caixa **"Perdeu só pela data"**
    (só aparece com status Perdido) que grava a **mesma coluna**. O resumo da
    transmissão diz quantos entraram por isso. Em 23/10: 13 perdidos, 0 marcados.
  - **Antes do primeiro disparo pago:** ensaio com um destinatário; conferir que o
    template aprovado tem **exatamente uma variável** (`{{1}}` = nome), senão a campanha
    inteira vira falha terminal; conferir o tier do número; e conferir a lista contra
    estrangeiro discado com `00`.
- **CONEXAO EM CONVIVENCIA: a pagina existe e esta publicada (07/10/2026).**
  Novos: `conectar-numero.php`, `lib/wa-es.php`, `tests/test-wa-es.php`
  (suite de 42 para **43** arquivos). Ainda **nao conectado**: falta a Lais
  com o celular na mao.
  - **A convivencia nao tem tela no WhatsApp Manager.** O unico caminho
    documentado pela Meta e o **Embedded Signup**, um popup que so abre a
    partir de uma pagina **nossa**, com o SDK do Facebook, em dominio
    cadastrado no app. O caminho "Adicionar numero de telefone" do Manager e
    o fluxo normal, que **tira o numero do celular** e nao se desfaz.
  - **A diferenca entre convivencia e migracao e um parametro:**
    `extras.featureType = 'whatsapp_business_app_onboarding'`. Perder essa
    palavra **nao da erro em tela**: abre o outro fluxo. Por isso ela mora em
    `wa_es_extras()`, no PHP, e o teste confere o **HTML servido**, nao so a
    funcao pura. O valor antigo `coexistence` foi invalidado em 29/05/2025.
  - **Construida na v4.** A Meta desliga o Embedded Signup v2 e v3 em
    **15/10/2026**. Na v4 quem decide o fluxo e a **configuracao do Login do
    Facebook para Empresas**, e `sessionInfoVersion` **nao vai mais** no
    extras.
  - **O app precisa ser PROVEDOR DE TECNOLOGIA antes de existir configuracao
    de convivencia.** Conferido no console em 08/10/2026: o assistente de
    configuracao do Login do Facebook para Empresas oferece **so a variacao
    "Geral"**, e a tela de ativos nao lista "contas do WhatsApp". Sem o ativo
    do WhatsApp nao ha Embedded Signup, e sem Embedded Signup nao ha
    convivencia. A leitura anterior aqui (de que revisao de app nao seria
    exigida por serem nossos proprios ativos) vale para o uso comum da Cloud
    API, **nao** para o Embedded Signup. O cadastro fica em Casos de uso >
    WhatsApp > Personalizar > integracao de provedor de tecnologia, e pede
    verificacao de negocio (ja feita) mais revisao de app com acesso avancado
    a `whatsapp_business_messaging` e `whatsapp_business_management`, com dois
    videos demonstrando envio e criacao de template.
  - **O `phone_number_id` chega pelo evento `WA_EMBEDDED_SIGNUP`**, no
    `window.addEventListener('message')`, **nao** no retorno do `FB.login`.
    Sem esse ouvinte o fluxo conecta e o numero que o sistema precisa morre
    junto com a janela.
  - A pagina fica atras de `WA_ES_KEY` (piso de 16 caracteres) e **nao
    imprime uma linha de html antes de conferir a chave** - senao contaria a
    estranhos em que pe esta a instalacao. `WA_ES_APP_ID` e `WA_ES_CONFIG_ID`
    tambem vivem no `config.local.php`, por comodidade de deploy; segredo
    de verdade ali e so a `WA_ES_KEY`.
  - **Apagar `conectar-numero.php` do servidor depois de conectar.** A
    convivencia e uma vez so.
  - **Sincronizacao de contatos e de historico: UMA vez cada, e a janela e de
    24 horas** depois do fluxo. Perdida, so desconectando e refazendo tudo.
  - **O que a convivencia tira do celular dela para sempre:** a lista de
    transmissao do app (que e o motivo de existir o plano 4), as mensagens
    temporarias nas conversas individuais, e os dispositivos vinculados
    Windows/WearOS. Grupos, chamadas, localizacao ao vivo e "ver uma vez"
    continuam no app, mas o robo **nao os enxerga**. Vazao fixa em 20 msg/s.
  - **Depois de conectar, o usuario de sistema precisa ganhar acesso a nova
    conta do WhatsApp** nas configuracoes do portfolio, senao o `WA_TOKEN`
    que ja esta no servidor nao alcanca o numero.
- **`WA_GRAPH` SUBIU PARA `v26.0` (08/10/2026), e a validade virou TESTE.**
  Antes estava em `v21.0`, que expira em 21/01/2027. Versao expirada da Graph
  **nao devolve erro**: a Meta redireciona a chamada em silencio e segue
  respondendo, entao o motor pode ler um formato diferente, dar o envio como
  feito e avancar o estado da conversa sem nada ter saido.
  - **O despertador agora e `tests/test-wa-graph.php`.** `WA_GRAPH_VALIDADE`
    (em `lib/wa-send.php`) guarda a data de expiracao de cada versao, e o
    teste fica **vermelho 90 dias antes** do vencimento da versao pinada.
    Versao fora do catalogo tambem e vermelho, que e o caso de alguem subir o
    numero e esquecer de trazer a data nova. Rodada de mutacao: 6 de 6 mortas.
    Suite de 46 para **47** arquivos.
  - **A versao e lida do proprio `WA_GRAPH`** por regex ancorada em `/$`, que
    e o que trava a **barra final**: sem ela o id do numero cola na versao
    (`.../v26.0123456/messages`) e a Meta recusa.
  - **v26.0 foi a mais nova medida no proprio endpoint** em 08/10/2026 (a
    v27.0 ainda nao existe) e a Meta ainda nao publicou expiracao para ela.
    Datas das outras: v22 20/05/2027 · v23 08/10/2027 · v24 18/02/2028 ·
    v25 29/07/2028.
  - **Conferido contra a conta de verdade, nao contra o changelog:** o GET
    `message_templates` devolve `data[]` com `name`/`status`/`language`/
    `category`/`components`/`id` **identicos** na v21 e na v26 (7 modelos), e
    o POST `/messages` devolve o mesmo envelope `error.message` nas duas (a
    sonda manda corpo vazio, que a Meta recusa na validacao, entao **nada e
    entregue a ninguem**). O envelope de SUCESSO (`messages[0].id`) nao tem
    como ser conferido sem entregar mensagem: fica para o ensaio com um
    destinatario, que ja e passo obrigatorio antes do primeiro disparo.
  - **O SDK do navegador em `conectar-numero.php` e um dial SEPARADO** e
    segue em `v25.0` (expira 29/07/2028). Nao foi mexido de proposito: a
    convivencia e uma vez so e acontece em dias, e mudar a versao do dialogo
    de login as vesperas nao compra nada.
  - **A versao do WEBHOOK e um terceiro dial**, que mora no console da Meta
    (Webhooks do app), nao no `WA_GRAPH`. O payload que `lib/wa-webhook.php`
    le (`entry[].changes[].value.messages[]` e `.statuses[]`) obedece a ela.
    Para v24.0+ a Meta parou de mandar o objeto `conversation` nos status
    fora de janela de entrada gratuita - **o codigo nao le `conversation` nem
    `pricing`**, entao nao quebra, mas conferir a versao escolhida la quando
    o webhook for ligado.
  - **`wa_tpl_lista` pede `limit=50` e NAO pagina.** A conta tem 7 modelos
    hoje; passando de 50, um modelo da segunda pagina seria lido como
    inexistente e o `campanha.php` recusaria criar a campanha (502/400, nunca
    envio errado). Falha segura, mas e divida.

- **ABA MODELOS + POLITICA DE PRIVACIDADE (08/10/2026).** Novos:
  `painel/modelos.js`, `templates.php`, `lib/wa-template.php`,
  `politica-de-privacidade.html`. Suite de 43 para **46** arquivos. No ar.
  - **Nasceram da analise de app da Meta**, que exige URL de politica de
    privacidade e um video da **criacao de um modelo de mensagem**. O painel
    nao criava template: a cliente escrevia na aba "Textos do robo" e alguem
    copiava para o WhatsApp Manager na mao.
  - **As regras de template da Meta agora sao codigo** (`wa_tpl_problemas`).
    A conferencia que o CLAUDE.md mandava fazer na mao antes do primeiro
    disparo pago parou de depender de alguem lembrar. Alem do numero de
    variaveis: nao comecar nem terminar com variavel, numeracao em sequencia
    a partir de {{1}}, chaves sem espaco dentro, duas variaveis nunca
    coladas, exemplo obrigatorio, e o limite de 1024 contado em
    **caracteres** (com `strlen`, 900 letras acentuadas seriam recusadas a
    toa). A Meta so informa isso por recusa generica, horas depois, por
    e-mail, sem dizer qual regra caiu.
  - **O modo `conferir` julga pela MESMA funcao do `criar`**, sem gastar
    chamada. Tela que valida diferente do envio aprova o que a Meta recusa.
    Por isso a tela **nao** reimplementa as regras em JavaScript, e um teste
    de fonte impede que alguem o faca.
  - **A validacao mora dentro de `wa_tpl_cria`**, nao so no endpoint: assim
    nao existe caminho que submeta sem ela.
  - **Modelo criado nao se renomeia nem se apaga de imediato na Meta.** Por
    isso o botao nasce desabilitado e so uma conferencia limpa o habilita, e
    volta a desabilitar depois de cada envio.
  - **So modelo `APPROVED` ganha botao de usar na transmissao.** Campanha com
    modelo em analise faz a Graph recusar todo destinatario, e falha e
    terminal.
  - **Erro de leitura da lista nunca vira "nenhum modelo"**: com a Meta fora
    do ar alguem criaria um duplicado que fica na conta para sempre. Mesma
    regra do `wa_db_select_estrito`.
  - **`WA_WABA_ID` e `WA_TEST_PHONE_ID` ja estao no servidor** (08/10/2026),
    apontando para a conta e o numero de TESTE criados pela Etapa 1 do
    console. Sem `WA_WABA_ID` o endpoint recusa em vez de chamar a Meta sem
    destino. **Modelo nao se transfere entre contas**: o que for aprovado na
    conta de teste nao serve para a campanha real.
  - **ENSAIO (`modo=ensaio`): uma mensagem, um numero, longe da base.** Ele
    existe porque o vidoe 1 da analise pede o app enviando, e o caminho
    obvio - a tela de Transmissao - e justamente o proibido.
    **NUNCA preencher `WA_PHONE_ID` com o numero de teste.** Aquela chave
    vazia e o que mantem o `campanha.php` inerte; preenchida, uma campanha
    reservaria as 786 fichas e tentaria enviar pelo numero de teste, que so
    alcanca 5 destinatarios cadastrados. Todas falhariam, e **falha e
    terminal**: o indice unico impede re-reservar, entao a base inteira
    ficaria queimada para campanha em definitivo, sem custar um centavo.
  - **O ensaio sai pelo numero da AGENCIA quando ele existir, e pelo de teste
    so enquanto nao existir.** Nesta ordem, e nao na inversa: preferindo o de
    teste, um `WA_TEST_PHONE_ID` esquecido no config faria o ensaio seguir
    saindo por ele depois da convivencia, e o ensaio com um destinatario no
    numero REAL - exigido antes do primeiro disparo pago - nunca aconteceria,
    com todo mundo vendo "enviado". A rodada de mutacao achou isto.
  - **MODELO E IDENTIFICADO POR NOME + IDIOMA, nao so por nome.** O nome
    certo com o idioma errado devolve `#132001 Template name does not exist
    in the translation` e **nao entrega** - para um destinatario ou para a
    base inteira, do mesmo jeito. O `hello_world` que a Meta cria junto com
    a conta de teste existe **so em en_US**, e foi assim que isto apareceu.
    O ensaio pergunta o idioma a conta antes de enviar; a campanha confere
    o modelo na Meta no `criar` (aprovado? no idioma certo?) e recusa em
    400, porque descobrir isso no envio significa a base inteira marcada
    como `falha`, que e terminal. **`WA_CAMP_IDIOMA` e lida pelo criar E
    pelo envio**: lados que leem valores diferentes aprovam o que o outro
    recusa. Meta fora do ar na conferencia vira **502, nunca "modelo
    invalido"** - a cliente iria cacar um erro de digitacao que nao existe.
  - **Numero novo da Meta nasce `status=PENDING` e nao envia.** O numero de
    teste criado pela Etapa 1 do console devolvia `#133010 Account not
    registered` ate receber um `POST /{phone_id}/register` com um PIN de 6
    digitos (usamos `135790` no de teste). Depois disso ele fica
    `platform_type=CLOUD_API` e `status=CONNECTED`.
  - **`templates.php` nao carrega o `lib/wa-db.php` e nao conhece `po_leads`**,
    e um teste de fonte trava isso: o ensaio manda para um numero digitado na
    hora e nao pode ganhar caminho para varrer a base.
  - **A politica de privacidade diz o que vai ser verdade, nao o padrao:**
    aviso de novos roteiros para quem ja viajou ou ja pediu informacao se
    apoia em **legitimo interesse** pela relacao existente, nao em
    consentimento, porque os 775 clientes do CRM antigo nunca assinaram
    autorizacao de marketing. Escrever "consentimento" ali faria a
    transmissao contradizer a propria politica no primeiro disparo. **O SAIR
    deixou de ser detalhe tecnico e virou a contrapartida que sustenta essa
    base legal**, com quadro proprio na pagina.
  - Prazos publicados: 5 anos para lead que nao virou viagem, 5 anos apos a
    viagem para quem viajou. Contato: poturismo@poturismo.com.br.
  - **Cabecalho, menu e rodape da pagina vem do `contato.html` por script,
    verbatim.** Copiar esses blocos na mao produz a pagina com o menu
    atrasado em relacao as outras.

- **ANALISE DE APP SUBMETIDA A META (08/10/2026).** App `4057725214358864`,
  portfolio da Pereira Oliveira (nao da Freela In Home: a agencia nao tem
  negocio verificado e este trabalho e sob medida para esta cliente). Pedido
  de **Independent Tech Provider**, com `whatsapp_business_messaging`,
  `whatsapp_business_management` e `public_profile`.
  - **Sem isso nao ha convivencia.** O assistente de configuracao do Login
    para Empresas so oferece a variacao "Geral" e nao lista contas do
    WhatsApp nos ativos enquanto o app nao for provedor aprovado. Sem esse
    ativo nao ha Embedded Signup, e sem ele nao ha convivencia.
  - **Os dois videos foram gravados no numero de TESTE**, nunca no da
    agencia: envio de `hello_world` com o WhatsApp Web ao lado, e criacao de
    modelo pelo painel. Modelos `teste_analise_meta` e `teste_analise_meta_2`
    ficaram na conta de teste (modelo nao se apaga nem se renomeia na Meta).
  - **Nunca gravar passando pela aba Leads.** Ela mostra nome, telefone e
    e-mail de cliente real, e o video vai para um revisor terceiro. A
    gravacao comeca com a aba Modelos ja aberta.
  - **Credencial de revisor foi RECUSADA, com justificativa.** O painel nao
    tem nivel de acesso reduzido: quem entra ve as 786 fichas, com CPF e
    passaporte. A recusa cita a propria politica de privacidade publicada, com
    link. Isso e mais defensavel que inventar um usuario de teste.
  - **`fblogin-web-1` foi respondida NAO.** O produto esta configurado, mas a
    pagina de conexao devolve 403 (a chave nao existe e a configuracao do
    Login so pode ser criada depois da aprovacao). Revisor que testa e bate em
    erro recusa. O texto das instrucoes declara isso explicitamente, entao nao
    ha contradicao escondida.
  - **A sugestao de texto da IA da Meta nao foi usada, nas tres permissoes.**
    Ela afirmava que o app integra contas de clientes empresariais terceiros,
    que nao existem aqui, e vinha com `[Your Name]` e com o proprio rascunho
    dela no fim do texto.
  - **Operadores declarados:** ereHost (Brasil), Supabase (EUA) e Freela In
    Home (Brasil), todos na categoria de solucoes e servicos de TI.
    Controlador: Pereira Oliveira Consultoria em Turismo e Viagens LTDA.
  - **`requests-4` foi marcada com analise de legitimidade e minimizacao, e a
    politica de privacidade ganhou a secao "Pedidos de autoridades" NO MESMO
    DIA** para a declaracao ter lastro. Contestacao judicial e livro de
    registro ficaram desmarcados: a agencia nao faz nem um nem outro, e
    declarar o que nao se faz e pior que declarar menos.
  - **APROVADA (envio de 22/10/2026, conferido em 26/10).** As tres permissoes
    em **Aprovado**, e a tela de Verificacao mostra os dois selos verdes:
    **Verificacao da empresa** (BM - Pereira Oliveira, id `968987612512542`) e
    **Verificacao do acesso** - "sua empresa foi verificada como uma provedora
    de tecnologia". O medidor de "0 de 1 chamada de API obrigatoria" que tinha
    ficado em aberto no envio deixou de ser problema: a analise concluiu.
    **Com isso cai o unico bloqueio da convivencia** - o assistente do Login
    para Empresas passa a oferecer o ativo de contas do WhatsApp.

- **`git checkout --` altera fim de linha neste repo** (`core.autocrlf=true`). O teste de
  cache-buster hasheia conteúdo **normalizado** por causa disso — hashear bytes crus fazia
  o lock depender de como o arquivo tinha sido materializado, e o merge do plano 4 ficou
  vermelho com o `painel.css` idêntico. Para restaurar arquivo em rodada de mutação, use
  cópia de bytes.
- **Falta:** a conexão real com a Meta e a homologação. (O plano 4 saiu, e a
  conferência visual do painel logado foi feita em 08/10/2026 — ver abaixo.)

- **CONFERENCIA VISUAL DO PAINEL LOGADO: FEITA (08/10/2026).** Era a pendência
  mais antiga aberta: seis tarefas mexeram em `painel/app.js`, `index.html` e
  `painel.css` e **ninguém nunca tinha visto a tela logada**. As 10 abas foram
  abertas com a sessão real da Laís. **Todas renderizam.**
  - **Nenhuma tela do painel pode ser julgada por screenshot imediato.** Três
    "defeitos" que apareceram nesta rodada eram todos a mesma coisa: a aba
    pinta a moldura na hora e preenche depois do fetch. A aba Textos apareceu
    **sem nenhum campo** (eram 7, chegaram depois), a Transmissão ficou em
    "Calculando o público..." (resolveu), e os cards de Roteiros ficaram
    **cinza** (as capas são ~600 KB cada, 6 delas, ~2,5 MB na primeira
    pintura). Esperar de 5 a 10 segundos antes de concluir qualquer coisa.
  - **Clique sintético de automação some durante o boot do painel.** Um
    `left_click` do navegador controlado no primeiro segundo **não chega nem
    como `pointerdown`** (confirmado com listener de captura: log vazio). Não
    é bug do painel - o handler está ligado e funciona. Para trocar de aba por
    automação, use `document.querySelector('[data-view="..."]').click()`.
  - **DIVERGENCIA REAL, a decidir:** o mesmo lead é contado em caixas
    diferentes em duas telas. `painel/funil.js:13` dobra `semresposta` dentro
    de **"Contato feito"** (que é a regra escrita deste subsistema), mas
    `painel/app.js:495` lista `semresposta` como **coluna própria** no funil
    dos Relatórios. Hoje, com 1 lead: o Funil mostra "Contato feito 1" e os
    Relatórios mostram "Contato feito 0 / Sem resposta 1". Nenhum dado está
    errado; o que confunde é o rótulo igual com número diferente.
  - **Transmissão calcula certo e bate com a base:** R$ 6,82 estimado, **22
    pessoas recebem**, 17 não são clientes, **753 estão sem telefone** - os
    mesmos 22 celulares que o plano 3 mediu.
  - **A aba Modelos lista pela Graph v26.0 de ponta a ponta** (painel →
    `templates.php` → Meta, com o token e a WABA reais): 7 modelos, os 2 de
    teste em EM ANÁLISE **sem** botão de usar, os 5 aprovados **com** botão.
    É a prova de que a subida de versão funciona no servidor.
  - **Clientes mostra contato de EMPRESA vindo da agenda** ("VIVO INTERNET",
    "VIVO CELULARES", "Vital Card"). Todos sem telefone, então não entram em
    campanha nem custam nada - mas sujam a lista de pessoas.
  - **Celular NAO foi verificado.** A janela do Chrome desta máquina não
    encolhe abaixo de ~1440px (maximizada; o `resize_window` responde sucesso
    e o `innerWidth` não muda), então o ponto de quebra de 980px, onde a barra
    lateral vira gaveta, continua sem ninguém ter visto. Testar no celular de
    verdade, que é onde a Laís usa.
  - **O formulário do site está produzindo:** entrou um lead orgânico em
    08/10/2026 às 05:45 (Chile e Deserto do Atacama, Balneário Camboriú), o
    primeiro desde que a base foi zerada. Ele aparece em Leads, em Clientes e
    no Funil, e o KPI ignora a base importada, como foi desenhado.
- **RESOLVIDO (26/10/2026):** o Embedded Signup da coexistência **exigia** sim revisão
  de app da Meta, e ela **foi aprovada**. A etapa a mais existiu e está cumprida.

- **CONVIVENCIA ESTABELECIDA (26/10/2026) - E NAO FOI PELA NOSSA PAGINA.**
  O numero da agencia **ja estava conectado** ao portfolio quando fomos rodar o
  Embedded Signup. Descoberto em Configuracoes > Contas do WhatsApp, e
  confirmado pela tela "Plataforma conectada" do app da Lais, que diz
  textualmente **"O app WhatsApp Business nao sera afetado"** e oferece
  "Toque para desconectar". Isso e convivencia, nao migracao.
  - **Os numeros que o sistema precisava** (lidos da Graph, nao da tela):
    WABA real **`1150774113972883`** (`account_review_status: APPROVED`),
    `phone_number_id` **`444498328748420`**, +55 48 9604-8882,
    `status: CONNECTED`, `platform_type: CLOUD_API`, limite 250/24h.
    Ja gravados: `WA_PHONE_ID` saiu de vazio, e `WA_WABA_ID` saiu da conta de
    TESTE (`2153684915493165`) para a real.
  - **O `WA_TOKEN` que ja estava no servidor ALCANCA a WABA nova** (as consultas
    a `/phone_numbers` e `/subscribed_apps` responderam 200). Entao o passo de
    "dar acesso do usuario de sistema a conta nova", que este documento previa,
    **nao e necessario**: a conta nasceu dentro do mesmo portfolio.
  - **POR QUE O EMBEDDED SIGNUP FALHAVA, e e a licao cara do dia:** com o numero
    ja conectado, o fluxo tentava criar uma conta NOVA e compartilha-la com
    `BM - Pereira Oliveira`, que e o proprio portfolio de quem estava logado.
    Empresa nao compartilha ativo consigo mesma: dai o
    `#1690130 - 968987612512542 nao e uma identificacao da empresa valida`, e
    dai a queda no caminho generico, que oferecia **criar numero novo com
    verificacao por SMS**. **REGRA: antes de rodar o Embedded Signup, abrir
    Configuracoes > Contas do WhatsApp e ver se o numero ja esta la.** Um
    clique teria poupado a sessao inteira com a cliente.
  - **Na convivencia a verificacao e por QR CODE escaneado no app, nunca por
    SMS ou ligacao.** Tela oferecendo SMS e o fluxo normal, o que tira o numero
    do aparelho. Foi o sinal que fez parar a tempo.
  - **A Lais precisa abrir o app ao menos uma vez a cada 14 dias**, senao a
    conexao cai (aviso da propria tela do aplicativo).
  - **O app foi PUBLICADO** (saiu do modo de desenvolvimento) em 26/10. Nao era
    a causa do problema, mas e requisito do Embedded Signup de qualquer forma.
  - **O QUE AINDA BLOQUEIA O ENVIO, medido na Graph:**
    1. **`health_status.can_send_message = BLOCKED`**, erro **141006: "There is
       an error with the payment method. This will block business initiated
       conversations."** E isto que a tela do celular chama de "Termine a
       configuracao em BM - Pereira Oliveira". **Sem forma de pagamento na
       conta, nenhuma conversa iniciada pela empresa sai.**
    2. **`subscribed_apps` esta VAZIO.** O app nao esta inscrito no webhook
       desta WABA, entao **nenhuma mensagem de cliente chega no
       `whatsapp.php`**. Inscrever e `POST /{waba_id}/subscribed_apps`, mas
       isso **liga o robo para os clientes reais** - decisao da cliente, nao
       tarefa tecnica.
    3. **`message_templates` esta VAZIO.** Modelo nao se transfere entre
       contas: os `teste_analise_meta*` ficaram na conta de teste. Os de
       verdade precisam ser criados aqui e aprovados pela Meta.
  - **O piso de 16 caracteres vale so para SEGREDO** (`WA_ES_KEY`,
    `WA_CRON_KEY`, webhook). `WA_PHONE_ID` tem 15 digitos e isso esta correto.

- **CONFIGURACAO DO LOGIN PARA EMPRESAS CRIADA, E A PAGINA DE CONEXAO ESTA VIVA
  (26/10/2026).** (Acabou nao sendo o caminho usado - ver o item acima.) `WA_ES_CONFIG_ID = 1104813102438169`. As tres chaves do Embedded
  Signup foram acrescentadas ao `config.local.php` do servidor (as outras 10 variaveis
  conferidas uma a uma, por hash de linha, antes e depois). **`conectar-numero.php`
  saiu do 403**: sem chave 403, com chave errada 403, com a certa 200.
  - **O que foi marcado no assistente, e por que** (cada tela avisa "nao pode ser
    alterado depois"): variacao **Cadastro incorporado do WhatsApp** (em 08/10 so
    existia "Geral" - e a prova de que a aprovacao surtiu efeito); produto **somente
    WhatsApp Cloud API**, sem a MM Lite (a transmissao manda template pelo `/messages`
    comum) e sem CTWA/CTD/CTM/Conversions (sao para apps que gerenciam anuncio em nome
    da empresa, que nao e o caso; conversa vinda de anuncio chega no webhook do mesmo
    jeito); token de **usuario do sistema** (travado pela variacao, e o que casa com o
    `response_type: 'code'` da pagina); expiracao **Nunca**; ativo **Contas do WhatsApp**
    (ja vinha travado), com as outras cinco desmarcadas e as task permissions vazias
    (a Meta ja atribui "Gerenciar" por padrao, que e o nivel cheio).
  - **Expiracao "Nunca" foi escolha deliberada, contra o "recomendado" da Meta.** Aquele
    prazo vale para o token que sairia da troca do `code`, **que este projeto nao troca**:
    o motor roda com o `WA_TOKEN` permanente do proprio portfolio. Como o token nem chega
    a ser emitido, "Nunca" nao deixa credencial eterna solta, e evita que uma porta de
    uso unico ganhe prazo de validade que morreria em silencio.
  - **Conferido no HTML servido, nao so no codigo:** `featureType` =
    `whatsapp_business_app_onboarding`, sem o valor antigo `coexistence`, sem
    `sessionInfoVersion` (que a v4 nao quer), com o `config_id` e o `app_id` certos e
    com o ouvinte de `WA_EMBEDDED_SIGNUP` presente.
  - **Falta so `WA_PHONE_ID`** — ele so existe depois da convivencia, e **continua sendo
    o que mantem o `campanha.php` inerte**. Nunca preencher com o numero de teste.
  - **`WA_WABA_ID` aponta para a conta de TESTE.** Depois de conectar ele tem que
    passar a apontar para a WABA real, e **os modelos têm que ser recriados lá**
    (modelo não se transfere entre contas) e esperar nova aprovação da Meta. Essa
    espera é o que separa a conexão do primeiro disparo: começar cedo.
- **FLUXO DE CONVERSA, PLANO A (o motor): FEITO, MERGEADO, NADA ATIVO (27/10/2026).**
  10 tarefas em TDD na branch `worktree-fluxo-motor` (26 commits, merge fast-forward em
  `main`); a suite foi de 47 para **56** arquivos. Novos: `lib/wa-fluxo.php`, as migrations
  `2026-10-27-fluxo-conversa.sql` e `2026-10-27-fluxo-semeado.sql`, e 9 arquivos de teste.
  Spec: `docs/superpowers/specs/2026-10-27-fluxo-conversa-painel-design.md` · plano:
  `docs/superpowers/plans/2026-10-27-fluxo-motor.md` · **pendencias e a heranca obrigatoria
  do Plano B: `docs/superpowers/reviews/2026-10-27-fluxo-motor-pendencias.md`** (ler antes
  de comecar o painel).
  - O robo deixou de ter maquina de estados escrita a mao: ele **interpreta um grafo de
    cards**, para a cliente montar o atendimento sem codigo. A ramificacao e por **botao
    interativo**, para a resposta voltar como identificador e nao como frase, e **todo card
    que espera resposta tem caminho para quem digita** (o publico tem 60+ e digita).
  - **NADA FOI PUBLICADO.** As duas migrations **nao rodaram** (o Supabase e compartilhado
    com NOX/hd360), nada subiu por FTP, nenhum cron, nenhum webhook inscrito. Os dois
    fluxos semeados nascem **`ativo = false`**.
  - **Regras permanentes deste subsistema:**
    - **O motor so le `po_wa_fluxo_versoes.grafo`**, que e retrato imutavel. As tabelas de
      edicao sao rascunho, e `po_wa_conversas.fluxo_versao_id` **prende a conversa a versao
      em que ela entrou**: editar o fluxo nunca muda a conversa de quem ja esta no meio.
    - **Publicar e Ligar sao atos separados.** `wa_fluxo_publica` **nunca escreve `ativo`**
      (ha trava de fonte). Publicar fluxo novo nao o poe no ar.
    - **As nove leis do motor continuam no motor.** O `wa-fluxo.php` so roteia, e **nunca
      requer o `wa-motor.php`** (a seta e uma so: db/send/po-data <- fluxo <- motor <- timeout).
    - **A paciencia caiu para ~23h, nao ~48h:** lembrete em 20h e despedida em ~23h, tudo
      **dentro da janela de 24h** da Meta. Fora dela so sai template aprovado, e este plano
      nao manda template: a despedida que a cliente escrever nunca sairia.
    - **Rotulo de botao recusa acima de 20 caracteres, nao trunca** (contado em
      `mb_strlen`), porque truncar manda ao cliente um rotulo mutilado.
    - **Deploy, nesta ordem:** `wa-fluxo.php` **antes** de `wa-motor.php` (o motor faz
      `require_once` dele; no FTP arquivo a arquivo a janela invertida da fatal error). A
      conversao de estado da migration e de **rodada unica**. E ao ligar, **"Lista de
      espera" antes de "Boas-vindas e triagem"**, porque a triagem salta para a espera e a
      regra 11 bloqueia saltar para fluxo desligado.
    - **Trava de fonte le arquivo normalizando o fim de linha.** Com `core.autocrlf=true`
      aqui, regex ancorado em `
` casa no worktree (LF) e falha no checkout principal
      (CRLF). Custou um vermelho que dez revisoes nao viram, porque o worktree so teve LF.
  - **Falta o Plano B:** a aba do painel (`painel/fluxo.js`, `fluxo.php`, `fluxo.css`, o
    simulador e o filtro de transmissao). Sem ela a cliente nao tem como montar nem ligar
    fluxo: hoje so existe o que as migrations semearam.
- **A confirmar com a cliente:** identidade nas fotos do arquivo (legendei por local/era,
  ex. "Cuba · arquivo Ilhatur"; se o homem jovem for o próprio fundador, dá para virar um
  "então & agora") · destino dos botões "Ver roteiro" (hoje `#`; criar páginas de roteiro
  ou levar ao WhatsApp) · texto/números da História ("até 300 pessoas", "+35 países",
  2003 rebranding, puxados do site atual).
- **Pendências a decidir:** projeto Supabase (novo x compartilhado) · confirmação do
  host cPanel do domínio · manter/migrar a Google Tag · campos finais do formulário.
