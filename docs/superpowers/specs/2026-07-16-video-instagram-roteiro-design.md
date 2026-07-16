# Vídeo do Instagram na página de roteiro — design

Data: 2026-07-16
Status: aprovado, em implementação

## Problema

Na seção "Por que viajar com a Pereira Oliveira" de cada página de roteiro, a figura à
direita mostra hoje a **capa do roteiro** (`capa_url`) com um selo de dias de viagem.

A Pereira Oliveira produz um **reels de Instagram** (vídeo retrato 9:16) para divulgar
cada roteiro. Esse vídeo é o melhor material de venda que existe do roteiro e hoje não
aparece no site.

Além disso, o painel tem um campo **"Playlist/mix (opcional)"** (`video_list`) que nunca
foi usado e não será.

## Escopo

1. Remover o campo `video_list` do painel e do banco.
2. Criar um campo **"Vídeo Instagram"** no painel, por **upload de arquivo** (não link).
3. Exibir o vídeo na seção "Por que viajar", no lugar da capa, quando houver.
4. Comprimir o vídeo no envio, sem impor limite de tamanho ao arquivo de entrada.

## Decisões

### Hospedagem: ereHost (não Supabase Storage)

O Supabase é **projeto compartilhado** com NOX e hd360. O free tier dá 1 GB de storage e
5 GB de egress/mês **para o projeto inteiro**. Um reels de 10 MB assistido 500 vezes já
consome 5 GB e derrubaria a cota dos outros clientes junto. A ereHost (cPanel) não tem
esse teto, então o vídeo vai para o docroot, via um endpoint PHP próprio.

Imagens (capa/galeria) continuam no Supabase Storage — são pequenas e já funcionam.

### Compressão: WebCodecs no navegador, antes do POST

Requisito do cliente: **aceitar arquivo de qualquer tamanho**. Isso torna a compressão
obrigatória, não opcional — um reels bruto de 150 MB não passaria no `upload_max_filesize`
do cPanel (tipicamente 64 MB).

O painel re-encoda antes de enviar:

- demux com **mp4box.js**;
- `VideoDecoder` → reescala para **720x1280** via `OffscreenCanvas` → `VideoEncoder`
  (H.264, ~1,2 Mbps);
- **o áudio é copiado sem re-encodar** (as amostras AAC são remuxadas direto). Preserva
  qualidade e elimina toda a metade de áudio do pipeline;
- remux com **mp4-muxer**, com `faststart` para o vídeo começar a tocar antes de baixar
  inteiro.

Um reels de 60 s sai em ~9 MB, independente do tamanho de entrada. Medido: um source de
38,7 MB saiu com 1,1 MB (97% menor), em 720x1280, com a duração e o áudio intactos.

**Guarda de tamanho:** se o re-encode sair MAIOR que o original, o painel envia o original.
Um arquivo já leve (abaixo do nosso alvo de 1,2 Mbps) só engordaria e perderia qualidade ao
ser re-encodado. Isso apareceu no teste: um vídeo de 159 kb/s virou um de 1,2 Mbps.

Se o navegador não tiver WebCodecs, o original sobe sem compressão (o upload em chunks
sustenta isso). O painel avisa.

### Upload em chunks

O `upload-video.php` recebe o arquivo em **fatias de 5 MB** e remonta no servidor. Isso
contorna `upload_max_filesize` e `post_max_size` de vez — é o que sustenta o "aceita
qualquer tamanho" mesmo no caminho sem compressão.

### Player: controles nativos + tela cheia customizada

O `<video>` usa os controles nativos do navegador (play, pause, volume, timeline) — já
acessíveis, já testados, zero manutenção. Por cima vai um overlay próprio: botão de play
central + a tag "Assista ao vídeo do roteiro".

A **tela cheia é customizada**: a Fullscreen API é aplicada a um wrapper, com o vídeo em
`contain` no centro e o fundo preenchido pela **capa borrada e ampliada**. Fullscreen
nativo deixaria tarjas pretas largas nas laterais no desktop (reels 9:16 em tela 16:9).
O fundo usa a capa em vez de um segundo `<video>` sincronizado, que custaria CPU e banda
em dobro pelo mesmo efeito visual.

## Regra de exibição

Fonte única de verdade, aplicada igual nas páginas estáticas e na dinâmica:

- **tem `video_insta_url`** → `<video>` com `poster` = capa, overlay de play e a tag
  "Assista ao vídeo do roteiro";
- **não tem** → `<img>` da capa + selo de dias, exatamente como é hoje.

Sem autoplay. `preload="none"`: nenhum byte de vídeo é baixado até o clique no play — o
`poster` é a capa, que a página já carrega. A tag e o botão somem no play e voltam no
pause.

## Mudanças

### Dados (`supabase/schema.sql` + migration)

```
alter table po_roteiros drop column video_list;
alter table po_roteiros add column video_insta_url text;
```

`video_list` alimentava o loop do vídeo do hero em `assets/js/roteiro.js:39`, mas a linha
já tem o fallback correto: `playlist=<id>` é o truque padrão do YouTube para repetir um
vídeo único. Nenhum dos 5 roteiros usa `video_list`. Remover simplifica a linha.

### Arquivos

| Arquivo | Mudança |
|---|---|
| `supabase/schema.sql` | `video_list` → `video_insta_url` |
| `supabase/migrations/` | migration idempotente |
| `upload-video.php` | **novo** — auth Supabase + chunks + grava em `/videos/<slug>.mp4` |
| `painel/video-encode.js` | **novo** — pipeline WebCodecs |
| `painel/app.js` | campo Playlist/mix → campo Vídeo Instagram |
| `painel/painel.css` | UI do campo de vídeo (progresso, preview) |
| `assets/css/roteiro.css` | `.intro__media`, overlay, fullscreen |
| `assets/js/roteiro.js` | player + fullscreen; limpa `video_list` |
| `assets/js/roteiro-dynamic.js` | `.intro__photo` → `.intro__media` |
| as 5 páginas de roteiro | mesma troca no HTML estático |

O vídeo antigo do roteiro é apagado ao substituir, para não acumular lixo no servidor.

## Fora de escopo

- Transcodificar no servidor (ffmpeg não é garantido na hospedagem compartilhada).
- Vídeo do Instagram na home ou na página de História.
- Múltiplos vídeos por roteiro. É um por roteiro.
