/* ============================================================
   Pereira Oliveira — vídeo do Instagram: comprimir + enviar.

   A cliente escolhe o reels direto do arquivo original (que pode
   ter centenas de MB). Aqui ele é re-encodado NO NAVEGADOR antes
   de sair, e enviado em fatias para o upload-video.php.

   POR QUE COMPRIMIR ANTES DE ENVIAR:
   o requisito é aceitar arquivo de qualquer tamanho. Sem re-encodar,
   um reels bruto de 150 MB ficaria pesado no servidor e lento para o
   visitante. Com re-encode, um reels de 60 s sai em ~9 MB não importa
   o tamanho que entrou.

   POR QUE EM FATIAS:
   o cPanel limita o tamanho de um POST (tipicamente 64 MB). Cortar em
   pedaços de 5 MB contorna o limite e ainda dá barra de progresso real.

   Usa mediabunny (WebCodecs por baixo) via ESM da CDN, carregado sob
   demanda — o painel não paga o download até alguém enviar um vídeo.

   API: window.POVideo = { supported, encode, upload, remove }
============================================================ */
(function () {
  'use strict';

  const CDN        = 'https://cdn.jsdelivr.net/npm/mediabunny@1.50.8/+esm';
  const BITRATE    = 1_200_000;          // ~1,2 Mbps: reels nítido e leve
  const BOX_LONGA  = 1280;               // maior lado
  const BOX_CURTA  = 720;                // menor lado
  const CHUNK      = 5 * 1024 * 1024;    // 5 MB por fatia

  let mbPromise = null;
  const loadMB = () => (mbPromise || (mbPromise = import(CDN)));

  /* Sem WebCodecs não há como re-encodar; o painel avisa e sobe o original. */
  function supported() {
    return typeof VideoEncoder !== 'undefined' && typeof VideoDecoder !== 'undefined';
  }

  /* displayWidth/displayHeight aparecem como propriedade em algumas versões
     do mediabunny e como método em outras. Aceita as duas formas. */
  async function dim(track, nome) {
    const v = track[nome];
    if (typeof v === 'function') return await track[nome]();
    if (v != null) return v;
    const alt = track['get' + nome[0].toUpperCase() + nome.slice(1)];
    return typeof alt === 'function' ? await alt.call(track) : null;
  }

  /* Encaixa o vídeo na caixa 1280x720 (ou 720x1280 se for retrato) SEM
     distorcer e sem nunca ampliar. Dimensões pares — H.264 exige. */
  function caixa(w, h) {
    const retrato = h >= w;
    const boxW = retrato ? BOX_CURTA : BOX_LONGA;
    const boxH = retrato ? BOX_LONGA : BOX_CURTA;
    const escala = Math.min(boxW / w, boxH / h, 1);
    const par = (n) => Math.max(2, Math.round(n * escala / 2) * 2);
    return { width: par(w), height: par(h) };
  }

  /**
   * Re-encoda o vídeo. Devolve um Blob mp4.
   * O ÁUDIO É COPIADO como está (sem re-encodar): preserva a qualidade
   * original e é mais rápido. O mediabunny só transcodifica o que precisa,
   * então basta não declarar opções de áudio.
   */
  async function encode(file, onProgress) {
    const mb = await loadMB();

    const input = new mb.Input({ formats: mb.ALL_FORMATS, source: new mb.BlobSource(file) });
    const track = await input.getPrimaryVideoTrack();
    if (!track) throw new Error('O arquivo não tem faixa de vídeo.');

    const w = (await dim(track, 'displayWidth'))  || 1080;
    const h = (await dim(track, 'displayHeight')) || 1920;
    const { width, height } = caixa(w, h);

    const output = new mb.Output({
      // fastStart põe o índice no começo do arquivo: o vídeo começa a tocar
      // sem esperar o download inteiro.
      format: new mb.Mp4OutputFormat({ fastStart: 'in-memory' }),
      target: new mb.BufferTarget(),
    });

    const conv = await mb.Conversion.init({
      input, output,
      video: { width, height, fit: 'contain', bitrate: BITRATE },
    });
    if (!conv.isValid) throw new Error('Não foi possível converter este vídeo.');
    if (onProgress) conv.onProgress = (p) => onProgress(p);

    await conv.execute();
    return new Blob([output.target.buffer], { type: 'video/mp4' });
  }

  /** Envia em fatias de 5 MB. Resolve com a URL pública do vídeo. */
  async function upload(blob, slug, token, onProgress) {
    const uid = Array.from(crypto.getRandomValues(new Uint8Array(8)))
      .map((b) => b.toString(16).padStart(2, '0')).join('');

    let url = '';
    for (let offset = 0; offset < blob.size; offset += CHUNK) {
      const fim  = Math.min(offset + CHUNK, blob.size);
      const last = fim >= blob.size;

      const fd = new FormData();
      fd.append('sb_token', token);
      fd.append('slug', slug);
      fd.append('uid', uid);
      fd.append('offset', String(offset));
      fd.append('last', last ? '1' : '0');
      fd.append('chunk', blob.slice(offset, fim), 'chunk');

      const resp = await fetch('../upload-video.php', { method: 'POST', body: fd });
      const txt  = await resp.text();
      let j; try { j = JSON.parse(txt); } catch (_) { j = null; }
      if (!resp.ok || !j || !j.ok) {
        throw new Error((j && j.error) || ('Falha no envio (' + resp.status + ').'));
      }
      if (onProgress) onProgress(fim / blob.size);
      if (last) url = j.url;
    }
    return url;
  }

  /** Remove o vídeo do roteiro no servidor. */
  async function remove(slug, token) {
    const fd = new FormData();
    fd.append('sb_token', token);
    fd.append('slug', slug);
    fd.append('action', 'delete');
    await fetch('../upload-video.php', { method: 'POST', body: fd });
  }

  window.POVideo = { supported, encode, upload, remove };
})();
