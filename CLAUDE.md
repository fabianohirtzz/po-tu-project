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
  **Falta (cliente):** no Search Console, clicar **Verify** (método HTML tag já está no ar),
  submeter o `sitemap.xml` e rodar o Rich Results Test nas URLs reais.
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
  **Vídeo fica na ereHost, em `/videos/<slug>-<hash>.mp4`, NÃO no Supabase Storage** — o
  projeto Supabase é compartilhado com NOX/hd360 e o free tier dá 1 GB de storage e 5 GB de
  egress/mês pro projeto inteiro; um reels de 10 MB visto 500× já come 5 GB. Coluna nova:
  `po_roteiros.video_insta_url` (migration em `supabase/migrations/`).
  **Falta:** rodar a migration no Supabase e subir por FTP `upload-video.php`,
  `painel/video-encode.js`, `painel/index.html`, `painel/app.js`, `painel/painel.css`,
  `assets/css/roteiro.css`, `assets/js/roteiro.js`, `assets/js/roteiros-shared.js`,
  `assets/js/roteiro-dynamic.js`. A pasta `/videos` precisa ter permissão de escrita.
- **Próximo:** página/seção de Contato geral (formulário de lead na home) · backend
  `enviar.php` + Supabase · painel de leads.
- **A confirmar com a cliente:** identidade nas fotos do arquivo (legendei por local/era,
  ex. "Cuba · arquivo Ilhatur"; se o homem jovem for o próprio fundador, dá para virar um
  "então & agora") · destino dos botões "Ver roteiro" (hoje `#`; criar páginas de roteiro
  ou levar ao WhatsApp) · texto/números da História ("até 300 pessoas", "+35 países",
  2003 rebranding, puxados do site atual).
- **Pendências a decidir:** projeto Supabase (novo x compartilhado) · confirmação do
  host cPanel do domínio · manter/migrar a Google Tag · campos finais do formulário.
