# Importador de roteiros com IA (Gemini) — Design

**Data:** 2026-07-12
**Projeto:** Pereira Oliveira Turismo — painel de leads/roteiros
**Autor:** Freela In Home

## Problema

O importador atual (`painel/app.js`, `parseParas` + `importPdf/importDocx/importPptx`)
falha em dois pontos com os PDFs reais da cliente:

1. **Imagens do PDF nunca são extraídas.** `importPdf()` só lê texto
   (`pdf.getTextContent()`); não existe `parsed.galeria` no caminho do PDF. Só
   `.docx`/`.pptx` puxam imagens (da pasta `word/media/` do zip). PDF não tem essa
   pasta — as fotos são objetos embutidos no stream da página. Recurso nunca
   implementado, não é bug de extração.

2. **Informação alocada no campo errado.** `parseParas()` decide as seções por
   cabeçalhos fixos que o documento real não usa:
   - procura `PACOTE INCLUI` / `^INCLUI` → o PDF diz **"SEU ROTEIRO INCLUI"** (não bate);
   - procura `HOTÉIS` / `HOTELARIA` → no PDF os hotéis ficam **dentro** de "SEU ROTEIRO
     INCLUI" (não bate);
   - `NÃO INCLUI` bate, mas como o "inclui" nunca ativou, o conteúdo vaza pra seção errada.

   Além disso a **capa do PDF sai com texto embaralhado** na extração (letras
   decorativas verticais viram `VOC M Ê PREC U ISA...`), então título e primeiras
   linhas viram lixo.

**Diagnóstico:** o parser de regex é frágil e casado com um formato específico.
Qualquer documento fora daquele molde quebra.

## Decisões (validadas com o cliente)

- **Abordagem:** importador com **IA** (Gemini), não template rígido nem heurística.
- **Provedor:** **Gemini** (`gemini-2.5-flash`), tier **gratuito** do Google AI Studio
  (`aistudio.google.com/apikey`). ~10 roteiros/ano cabem folgado nos limites de taxa.
  A assinatura consumidora "Gemini Advanced / Google One" **não** dá acesso à API — a
  chave é criada separadamente no AI Studio. Trade-off do tier gratuito: o Google pode
  usar o conteúdo enviado para melhorar os modelos; irrelevante aqui (roteiro é material
  público de marketing). Ativar billing na mesma chave torna privado sem mudar código.
- **Onde a IA roda:** endpoint **PHP no cPanel** (`importar.php`), mesmo padrão do
  `enviar.php` — chave em `config.local.php`, fora do Git. Consequência: o importador
  **só funciona na produção (cPanel)**, não no preview do GitHub Pages. Aceitável (é
  ferramenta de admin). No preview degrada para o parser antigo (offline).
- **Capa:** sempre **manual**. Imagens extraídas vão **só para a galeria**. Remover o
  comportamento atual que promove a 1ª imagem a capa ([app.js:417](../../../painel/app.js#L417)).
- **Revisão humana mantida:** o formulário vem preenchido; nada publica automático.

## Arquitetura

Fluxo: **sobe arquivo → extrai conteúdo → Gemini estrutura no schema → formulário
preenchido → revisão humana → salva.**

O trabalho se divide em duas trilhas independentes:

### Trilha A — Estrutura (dados/texto) → Gemini via PHP

- **PDF:** o navegador envia o **arquivo PDF inteiro** (base64) ao `importar.php`. O
  Gemini lê **multimodal** (layout + imagens), o que resolve capa embaralhada e alocação
  errada — ele entende "SEU ROTEIRO INCLUI", hotéis aninhados etc. sem regex de cabeçalho.
- **DOCX/PPTX:** o navegador extrai o **texto limpo** do zip (já funciona hoje) e envia o
  texto ao `importar.php`. (Gemini estrutura o texto; não precisa do arquivo binário.)

`importar.php`:
1. Carrega `GEMINI_API_KEY` do `config.local.php` (fallback: 400 "não configurado").
2. **Valida o login:** o painel envia o `access_token` do Supabase no header
   `Authorization: Bearer`. O PHP valida chamando `GET {SUPABASE_URL}/auth/v1/user` com
   `apikey: {anon}` + o bearer do usuário. Sem usuário válido → 401. Impede que o
   endpoint vire um proxy aberto de Gemini.
3. Chama `POST generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent`
   com:
   - `contents`: o PDF (`inline_data` base64 `application/pdf`) **ou** o texto;
   - `generationConfig.responseMimeType = application/json` + `responseSchema` = schema
     do roteiro (saída estruturada obrigatória);
   - instrução (system/prompt) em PT-BR: extrair **fielmente** o que está no documento,
     mapear para o schema, não inventar; refeições de cada dia lidas do texto
     ("Café da manhã e jantar incluídos").
4. Devolve o JSON do Gemini ao navegador (ou erro com mensagem amigável).

Guardas: método `POST` apenas; limite de tamanho do arquivo (ex.: rejeitar > 15 MB
antes do base64, para caber no limite inline do Gemini); timeout de cURL generoso
(ex.: 60s — Gemini multimodal demora mais que SMTP).

### Trilha B — Imagens (binários para a galeria) → navegador

Continua **no navegador**, host-independente:

- **DOCX/PPTX:** media do zip (já existe).
- **PDF (novo):** extrair imagens **embutidas** via pdf.js — percorrer o operator list
  de cada página (`OPS.paintImageXObject` / `paintInlineImageXObject`), resolver o objeto
  em `page.objs`, desenhar em canvas e `toBlob('image/jpeg')`. Aplicar:
  - **filtro de tamanho mínimo** (ex.: largura ou altura < 200px → descartar logo,
    bandeira, ícones decorativos);
  - **dedup** (por dimensões + hash simples de amostra) para não repetir a mesma foto
    que aparece em várias páginas.
- Cada imagem resultante sobe pro Supabase via `uploadImg(file, slug)` (já existe) e
  entra **só na galeria** (`p.galeria`). **Nunca** vira capa.

Se a extração client-side falhar num PDF específico, o fallback é "galeria vazia, você
sobe as fotos na mão" — não trava a importação dos dados.

## Schema do roteiro (saída do Gemini)

Casa 1:1 com os campos do formulário (`collectRoteiro` / `prefill`):

```
titulo            : string
periodo           : string        // ex.: "17 dias"
dias              : integer
noites            : integer
descricao_curta   : string
roteiro_dias      : [ { n:int, data:string, dia_semana:string,
                        cidades:string, descricao:string, refeicoes:string } ]
inclui            : [ string ]
nao_inclui        : [ string ]
hoteis            : [ string ]    // ex.: "Istambul: Ramada Taksim – 4 estrelas"
valores           : [ { tag:string, valor:string } ]  // ex.: {tag:"Quarto duplo", valor:"EUR 2.631"}
```

## Mudanças no código

- **`painel/app.js`**
  - `importPdf`: enviar o arquivo ao `importar.php` para estrutura + extrair imagens
    embutidas (nova `extractPdfImages`).
  - `importDocx` / `importPptx`: manter extração de texto/imagens; trocar a estruturação
    de `parseParas(paras)` por chamada ao `importar.php` com o texto. Manter `parseParas`
    como **fallback** se a chamada falhar (preview/offline).
  - `prefill`: **remover** o bloco que promove `galeria[0]` a capa (linha ~417).
  - Nova função de chamada ao endpoint que injeta o `Authorization: Bearer` da sessão
    Supabase (`sb.auth.getSession()`).
  - Endpoint configurável (ex.: `IMPORT_ENDPOINT` relativo `../importar.php`).
- **`importar.php`** (novo, na raiz, junto do `enviar.php`).
- **`config.local.php`** (servidor): acrescentar `$GEMINI_API_KEY`.
- **Docs:** registrar em `supabase/README.md` ou `README.md` onde colocar a chave e como
  criar no AI Studio.

## Fora de escopo (YAGNI)

- IA adivinhar qual foto vai em qual dia.
- Abstração multi-provedor (só Gemini).
- Extração de imagem no servidor (fica no navegador).
- Files API do Gemini para arquivos grandes (guard de 15 MB cobre os casos reais).

## Critérios de sucesso

1. Subir o PDF "Tesouros da Turquia com Antália 2026" no painel (produção) preenche
   corretamente: título ("Turquia com Antália"), período/dias, os 17 dias com
   cidade+descrição+refeições, **inclui** e **não-inclui** nos campos certos, hotéis
   listados, e os dois blocos de valores (duplo/individual/aéreo).
2. As fotos reais do PDF (mesquita, balões, Antália, Pamukkale, Éfeso...) aparecem na
   **galeria**, sem o logo/bandeira, sem duplicatas — e **nenhuma** vira capa.
3. Endpoint recusa chamada sem login Supabase válido.
