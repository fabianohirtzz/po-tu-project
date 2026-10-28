/* ============================================================
   Prova social (22/10/2026): avaliacoes do Google e videos do Instagram que
   aparecem no site (Nossa Historia e painel "Sobre" da home), montados no
   servidor por lib/po-social.php.

   Avaliacoes: a cliente cola nome e texto do Google. O nome e publicado como
   "Nome I." (LGPD, decidido com o cliente) e quem garante isso e poNomeCurto,
   no ponto de gravacao: colar o nome completo nao publica o nome completo.

   Instagram (padrao Casa Angelina): a cliente SOBE O VIDEO do reels. O painel
   tira um quadro como capa (storage do projeto), comprime o video com o mesmo
   POVideo dos roteiros e envia ao upload-video.php (tipo "feed", na ereHost).
   Cada video tem um id proprio (ig-<8 hex>) no nome do arquivo: trocar ou
   excluir um nunca apaga outro. So video (sem link do post desde 23/10: o
   "Ver no Instagram" do site vai ao perfil). Duas abas no painel, "Avaliacoes"
   e "Instagram", com este mesmo arquivo. O site mostra os 12
   primeiros ativos, na ordem das setas.

   Toda gravacao chama limpaCacheSite() (app.js): o site le estas tabelas com
   cache de 10 min, e sem limpar a mudanca demoraria a aparecer.
============================================================ */

let VITRINE = { avaliacoes: [], insta: [] };

/* "Beatriz Esther Hagemann Ruschel" -> "Beatriz R.". Ja abreviado ou nome
   unico ficam como estao. */
function poNomeCurto(nome) {
  const partes = String(nome || '').trim().split(/\s+/).filter(Boolean);
  if (!partes.length) return '';
  const cap = s => s.charAt(0).toLocaleUpperCase('pt-BR') + s.slice(1);
  if (partes.length === 1) return cap(partes[0]);
  const ultimo = partes[partes.length - 1];
  if (/^\p{L}\.$/u.test(ultimo)) return cap(partes[0]) + ' ' + ultimo.toLocaleUpperCase('pt-BR');
  return cap(partes[0]) + ' ' + ultimo.charAt(0).toLocaleUpperCase('pt-BR') + '.';
}

/* id do item a partir do arquivo (.../ig-xxxxxxxx-feed-hash.mp4): e o "slug"
   com que o upload-video.php apaga o video certo, e so ele. */
function poIdDoVideo(url) {
  const m = String(url || '').match(/\/(ig-[0-9a-f]{8})-feed-[0-9a-f]+\.mp4$/);
  return m ? m[1] : null;
}

async function poCarregaVitrine() {
  const [a, i] = await Promise.all([
    sb.from('po_avaliacoes').select('*').order('ordem').order('created_at'),
    sb.from('po_instagram').select('*').order('ordem').order('created_at', { ascending: false }),
  ]);
  if (a.error || i.error) { toast('Erro ao ler a prova social: ' + (a.error || i.error).message, true); return; }
  VITRINE = { avaliacoes: a.data || [], insta: i.data || [] };
  poRenderVitrine();
}

function poRenderVitrine() {
  const av = document.querySelector('#vit-av-lista');
  const ig = document.querySelector('#vit-ig-lista');
  if (!av || !ig) return;
  const ativas = VITRINE.avaliacoes.filter(r => r.ativo).length;
  document.querySelector('#vit-av-conta').textContent = ativas + ' no site (as 3 primeiras também aparecem na home)';
  av.innerHTML = VITRINE.avaliacoes.length ? VITRINE.avaliacoes.map((r, n) => `
    <div class="vit-av${r.ativo ? '' : ' off'}" data-id="${esc(r.id)}">
      <div class="vit-av__top">
        <input class="vit-nome" value="${esc(r.nome)}" aria-label="Nome">
        <input class="vit-data" value="${esc(r.data_label || '')}" placeholder="Mês e ano, ex.: outubro de 2026" aria-label="Data">
      </div>
      <textarea class="vit-texto" rows="3" aria-label="Texto">${esc(r.texto)}</textarea>
      <div class="vit-acoes">
        <button type="button" class="btn-soft vit-sobe" ${n === 0 ? 'disabled' : ''} title="Subir">↑</button>
        <button type="button" class="btn-soft vit-desce" ${n === VITRINE.avaliacoes.length - 1 ? 'disabled' : ''} title="Descer">↓</button>
        <button type="button" class="btn-soft vit-salva">Salvar</button>
        <button type="button" class="btn-soft vit-ativo">${r.ativo ? 'Ocultar do site' : 'Mostrar no site'}</button>
        <button type="button" class="btn-soft vit-exclui">Excluir</button>
      </div>
    </div>`).join('') : '<div class="empty">Nenhuma avaliação ainda.</div>';

  const igAtivos = VITRINE.insta.filter(p => p.ativo && p.video_url);
  document.querySelector('#vit-ig-conta').textContent = igAtivos.length + ' no site (aparecem os 12 primeiros)';
  ig.innerHTML = VITRINE.insta.length ? VITRINE.insta.map((p, n) => {
    const noSite = p.ativo && igAtivos.indexOf(p) < 12;
    return `<div class="vit-ig${p.ativo ? '' : ' off'}" data-id="${esc(p.id)}">
      <a href="${esc(p.video_url || '#')}" target="_blank" rel="noopener" class="vit-ig__img" style="background-image:url('${esc(p.poster_url || '')}')"></a>
      <span class="vit-ig__st">${noSite ? 'No site' : (p.ativo ? 'Fora dos 12' : 'Oculto')}</span>
      <div class="vit-acoes">
        <button type="button" class="btn-soft vit-ig-sobe" ${n === 0 ? 'disabled' : ''} title="Mais para a frente">←</button>
        <button type="button" class="btn-soft vit-ig-desce" ${n === VITRINE.insta.length - 1 ? 'disabled' : ''} title="Mais para trás">→</button>
        <button type="button" class="btn-soft vit-ig-ativo">${p.ativo ? 'Ocultar' : 'Mostrar'}</button>
        <button type="button" class="btn-soft vit-ig-exclui">Excluir</button>
      </div></div>`;
  }).join('') : '<div class="empty">Nenhum vídeo ainda.</div>';
}

async function poVitrineGrava(promessa, okMsg) {
  const { error } = await promessa;
  if (error) { toast('Erro ao salvar: ' + error.message, true); return false; }
  limpaCacheSite();
  if (okMsg) toast(okMsg);
  await poCarregaVitrine();
  return true;
}

async function poAddAvaliacao() {
  const nome = document.querySelector('#vit-av-nome').value;
  const texto = document.querySelector('#vit-av-texto').value.trim();
  const data = document.querySelector('#vit-av-data').value.trim();
  if (!poNomeCurto(nome) || !texto) { toast('Preencha o nome e o texto da avaliação.', true); return; }
  const ordem = VITRINE.avaliacoes.reduce((m, r) => Math.max(m, r.ordem || 0), 0) + 1;
  const ok = await poVitrineGrava(sb.from('po_avaliacoes').insert({
    nome: poNomeCurto(nome), texto, data_label: data || null, nota: 5, ordem,
  }), 'Avaliação adicionada.');
  if (ok) ['#vit-av-nome', '#vit-av-texto', '#vit-av-data'].forEach(s => { document.querySelector(s).value = ''; });
}

/* Troca a ordem com o vizinho e renumera tudo: itens com a mesma ordem (0,
   cadastrados sem ordem) nao trocariam de lugar. Serve as duas tabelas. */
async function poMove(tabela, lista, id, passo) {
  const l = lista.slice();
  const i = l.findIndex(r => r.id === id), j = i + passo;
  if (i < 0 || j < 0 || j >= l.length) return;
  [l[i], l[j]] = [l[j], l[i]];
  for (let n = 0; n < l.length; n++) {
    if (l[n].ordem === n + 1) continue;
    const { error } = await sb.from(tabela).update({ ordem: n + 1 }).eq('id', l[n].id);
    if (error) { toast('Erro ao reordenar: ' + error.message, true); return; }
  }
  limpaCacheSite();
  await poCarregaVitrine();
}

/* Um quadro do video como capa (jpg), tirado no navegador: e o que o
   visitante ve antes de clicar, sem baixar o video. Pula o comeco, que nos
   reels costuma ser a vinheta de abertura. */
function poQuadroDoVideo(file) {
  return new Promise((ok, falha) => {
    const v = document.createElement('video');
    const url = URL.createObjectURL(file);
    v.muted = true; v.playsInline = true; v.preload = 'auto'; v.src = url;
    v.onloadedmetadata = () => { v.currentTime = Math.min(3, (v.duration || 3) / 3); };
    v.onseeked = () => {
      const largura = 540, fator = largura / (v.videoWidth || largura);
      const c = document.createElement('canvas');
      c.width = largura; c.height = Math.round((v.videoHeight || 960) * fator);
      c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
      c.toBlob(b => { URL.revokeObjectURL(url); b ? ok(b) : falha(new Error('não deu para ler o vídeo')); }, 'image/jpeg', 0.82);
    };
    v.onerror = () => { URL.revokeObjectURL(url); falha(new Error('esse arquivo de vídeo não abriu no navegador')); };
  });
}

async function poAddInstagram(file) {
  const st = document.querySelector('#vit-ig-st');
  const btn = document.querySelector('#vit-ig-add');
  const { data: { session } } = await sb.auth.getSession();
  if (!session) { toast('Sessão expirada. Entre de novo.', true); return; }
  const id = 'ig-' + Array.from(crypto.getRandomValues(new Uint8Array(4))).map(b => b.toString(16).padStart(2, '0')).join('');
  btn.disabled = true;
  try {
    st.textContent = 'Lendo o vídeo…';
    const quadro = await poQuadroDoVideo(file);
    const poster = await uploadImg(new File([quadro], id + '.jpg', { type: 'image/jpeg' }), 'instagram');
    if (!poster) return;
    // Compressao automatica (mesmo perfil do reels dos roteiros: 1,2 Mbps, audio
    // copiado). Se sair maior que o original, vai o original.
    let blob = file;
    if (POVideo.supported()) {
      const menor = await POVideo.encode(file, p => { st.textContent = 'Comprimindo… ' + Math.round(p * 100) + '%'; }, 'feed');
      if (menor && menor.size < file.size) blob = menor;
    } else {
      toast('Este navegador não comprime vídeo; enviando o original. No Chrome ou Edge atualizados ele sai bem menor.', true);
    }
    const video = await POVideo.upload(blob, id, session.access_token, p => { st.textContent = 'Enviando… ' + Math.round(p * 100) + '%'; }, 'feed');
    // entra na frente da fila: o mais novo aparece primeiro no site
    const ordem = VITRINE.insta.reduce((m, r) => Math.min(m, r.ordem || 0), 0) - 1;
    // sem link do post: o "Ver no Instagram" do site leva ao perfil
    await poVitrineGrava(sb.from('po_instagram').insert({
      video_url: video, poster_url: poster, url: null, ordem, ativo: true,
    }), 'Vídeo adicionado.');
  } catch (err) {
    toast('Falha ao enviar o vídeo: ' + err.message, true);
  } finally { st.textContent = ''; btn.disabled = false; }
}

document.addEventListener('click', e => {
  const t = e.target;
  if (t.id === 'vit-av-add') return poAddAvaliacao();
  if (t.id === 'vit-ig-add') return document.querySelector('#vit-ig-file').click();
  const av = t.closest('.vit-av'), ig = t.closest('.vit-ig');
  if (av) {
    const id = av.dataset.id, r = VITRINE.avaliacoes.find(x => x.id === id);
    if (t.classList.contains('vit-salva')) {
      const nome = av.querySelector('.vit-nome').value, texto = av.querySelector('.vit-texto').value.trim();
      if (!poNomeCurto(nome) || !texto) { toast('Nome e texto não podem ficar vazios.', true); return; }
      return poVitrineGrava(sb.from('po_avaliacoes').update({ nome: poNomeCurto(nome), texto,
        data_label: av.querySelector('.vit-data').value.trim() || null }).eq('id', id), 'Avaliação salva.');
    }
    if (t.classList.contains('vit-ativo')) return poVitrineGrava(sb.from('po_avaliacoes').update({ ativo: !r.ativo }).eq('id', id));
    if (t.classList.contains('vit-sobe')) return poMove('po_avaliacoes', VITRINE.avaliacoes, id, -1);
    if (t.classList.contains('vit-desce')) return poMove('po_avaliacoes', VITRINE.avaliacoes, id, 1);
    if (t.classList.contains('vit-exclui') && confirm('Excluir esta avaliação do site?')) {
      return poVitrineGrava(sb.from('po_avaliacoes').delete().eq('id', id), 'Avaliação excluída.');
    }
  }
  if (ig) {
    const id = ig.dataset.id, p = VITRINE.insta.find(x => x.id === id);
    if (t.classList.contains('vit-ig-ativo')) return poVitrineGrava(sb.from('po_instagram').update({ ativo: !p.ativo }).eq('id', id));
    if (t.classList.contains('vit-ig-sobe')) return poMove('po_instagram', VITRINE.insta, id, -1);
    if (t.classList.contains('vit-ig-desce')) return poMove('po_instagram', VITRINE.insta, id, 1);
    if (t.classList.contains('vit-ig-exclui') && confirm('Excluir este vídeo do site?')) {
      return (async () => {
        const ok = await poVitrineGrava(sb.from('po_instagram').delete().eq('id', id), 'Vídeo excluído.');
        const slug = poIdDoVideo(p.video_url);
        if (ok && slug) {
          const { data: { session } } = await sb.auth.getSession();
          if (session) POVideo.remove(slug, session.access_token, 'feed'); // so o arquivo deste item
        }
      })();
    }
  }
});

document.addEventListener('change', e => {
  if (e.target.id !== 'vit-ig-file') return;
  const f = e.target.files[0]; e.target.value = '';
  if (f) poAddInstagram(f);
});
