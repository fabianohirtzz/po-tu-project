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
  - **Antes do primeiro disparo pago:** ensaio com um destinatário; conferir que o
    template aprovado tem **exatamente uma variável** (`{{1}}` = nome), senão a campanha
    inteira vira falha terminal; conferir o tier do número; e conferir a lista contra
    estrangeiro discado com `00`.
- **`git checkout --` altera fim de linha neste repo** (`core.autocrlf=true`). O teste de
  cache-buster hasheia conteúdo **normalizado** por causa disso — hashear bytes crus fazia
  o lock depender de como o arquivo tinha sido materializado, e o merge do plano 4 ficou
  vermelho com o `painel.css` idêntico. Para restaurar arquivo em rodada de mutação, use
  cópia de bytes.
- **Falta:** o plano 4 (transmissão), a conexão real com a Meta,
  a homologação, e a **conferência visual do painel com login real** — seis tarefas
  mexeram em `painel/app.js`, `index.html` e `painel.css`, e nenhum agente conseguiu
  passar do login do Supabase. O deploy foi validado por sintaxe, testes (19 arquivos),
  hash do conteúdo servido e status HTTP; **a tela em si ninguém viu logada**.
- **Aberto:** se o Embedded Signup da coexistência exige revisão de app da Meta. Se exigir,
  entra uma etapa a mais antes de conectar.
- **A confirmar com a cliente:** identidade nas fotos do arquivo (legendei por local/era,
  ex. "Cuba · arquivo Ilhatur"; se o homem jovem for o próprio fundador, dá para virar um
  "então & agora") · destino dos botões "Ver roteiro" (hoje `#`; criar páginas de roteiro
  ou levar ao WhatsApp) · texto/números da História ("até 300 pessoas", "+35 países",
  2003 rebranding, puxados do site atual).
- **Pendências a decidir:** projeto Supabase (novo x compartilhado) · confirmação do
  host cPanel do domínio · manter/migrar a Google Tag · campos finais do formulário.
