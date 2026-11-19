/* ============================================================
   Pereira Oliveira Turismo: Formulário de lead (compartilhado)
   Usado nas páginas de roteiro e em contato.html.
   Grava o lead no Supabase (po_leads, anon insert) e dispara
   enviar.php para a notificação por e-mail (best-effort).
   Antispam: honeypot + time-trap. Sem WhatsApp.
   Requer: @supabase/supabase-js + po-config.js carregados antes.
============================================================ */
function initLeadForm() {
  'use strict';
  const form = document.getElementById('lead-form');
  if (!form || form.dataset.wired) return;
  form.dataset.wired = '1';

  /* ---------- modal (páginas de roteiro): abre no lugar de rolar até o form ---------- */
  const modal = document.getElementById('lead-modal');
  if (modal) {
    const openModal = () => {
      modal.setAttribute('data-open', 'true');
      modal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('lm-open');
      const first = form.querySelector('input,select,textarea');
      if (first) setTimeout(() => first.focus(), 60);
    };
    const closeModal = () => {
      modal.setAttribute('data-open', 'false');
      modal.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('lm-open');
    };
    // qualquer link para #contato abre o modal (header, hero, investimento, flutuante)
    document.querySelectorAll('a[href="#contato"], a[href$="#contato"]').forEach((a) => {
      a.addEventListener('click', (e) => { e.preventDefault(); openModal(); });
    });
    modal.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', closeModal));
    window.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && modal.getAttribute('data-open') === 'true') closeModal();
    });
    modal._close = closeModal;
  }

  const msg = document.getElementById('form-msg');
  const roteiroInput = document.getElementById('f-roteiro');
  const startTs = Date.now();
  const setV = (id, v) => { const el = document.getElementById(id); if (el) el.value = v; };

  // time-trap + captura de origem/UTM
  setV('f-t', String(startTs));
  const q = new URLSearchParams(location.search);
  setV('f-utm_source', q.get('utm_source') || '');
  setV('f-utm_medium', q.get('utm_medium') || '');
  setV('f-utm_campaign', q.get('utm_campaign') || '');
  setV('f-gclid', q.get('gclid') || '');
  setV('f-referrer', document.referrer || '');
  setV('f-landing', location.href);

  const setMsg = (kind, text) => { if (msg) { msg.setAttribute('data-show', kind); msg.textContent = text; } };
  const emailOk = (v) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);

  // cliente Supabase (gravação primária: funciona no preview e na produção)
  let sb = null;
  try {
    if (window.supabase && window.PO_CONFIG) {
      sb = window.supabase.createClient(PO_CONFIG.SUPABASE_URL, PO_CONFIG.SUPABASE_ANON_KEY);
    }
  } catch (e) { /* segue sem sb; enviar.php cobre a produção */ }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const data = poAplicaOrigem(new FormData(form));

    // honeypot
    if ((data.get('website') || '').trim() !== '') {
      setMsg('ok', 'Recebemos sua mensagem. Em breve entramos em contato.');
      return;
    }
    // time-trap
    if (Date.now() - startTs < 2500) { setMsg('err', 'Aguarde um instante e envie novamente.'); return; }

    const nome = (data.get('nome') || '').trim();
    const tel = (data.get('telefone') || '').trim();
    const email = (data.get('email') || '').trim();
    if (!nome || !tel || !email) { setMsg('err', 'Preencha nome, WhatsApp e e-mail para continuar.'); return; }
    if (!emailOk(email)) { setMsg('err', 'Confira o e-mail informado.'); return; }

    const btn = form.querySelector('.form__submit');
    if (btn) { btn.disabled = true; btn.style.opacity = '.7'; }

    const lead = {
      nome: nome,
      telefone: tel,
      email: email,
      cidade: (data.get('cidade') || '').trim() || null,
      viajantes: data.get('viajantes') || null,
      roteiro: (data.get('roteiro') || '').trim() || null,
      mensagem: (data.get('mensagem') || '').trim() || null,
      origem: data.get('origem') || null,
      utm_source: data.get('utm_source') || null,
      utm_medium: data.get('utm_medium') || null,
      utm_campaign: data.get('utm_campaign') || null,
      utm_term: data.get('utm_term') || null,
      utm_content: data.get('utm_content') || null,
      gclid: data.get('gclid') || null,
      gbraid: data.get('gbraid') || null,
      wbraid: data.get('wbraid') || null,
      fbclid: data.get('fbclid') || null,
      referrer: data.get('referrer') || null,
      landing_page: data.get('landing_page') || null
    };

    // Gravação com caminho único por ambiente (evita lead duplicado):
    //  1) produção (cPanel/PHP): enviar.php grava no Supabase (service_role) + e-mail;
    //  2) preview/Pages (sem PHP): enviar.php não roda → fallback insert anon aqui.
    let done = false;
    try {
      // Absoluto de raiz: as páginas de roteiro agora vivem em /roteiros/<slug>
      // (um nível a mais de path). 'enviar.php' relativo virava /roteiros/enviar.php
      // (404) e o lead se perdia com um erro genérico.
      const r = await fetch('/enviar.php', { method: 'POST', body: data });
      if (r.ok) { const j = await r.json().catch(() => null); if (j && j.ok === true) done = true; }
    } catch (e) { /* sem backend PHP: usa fallback abaixo */ }

    if (!done && sb) {
      try {
        let { error } = await sb.from('po_leads').insert(lead);
        // Coluna de origem que o banco ainda nao tem (migration atrasada):
        // grava sem ela. Perder o lead por causa de um campo de origem, nunca.
        if (error && error.code === 'PGRST204') ({ error } = await sb.from('po_leads').insert(poSemOrigemNova(lead)));
        if (!error) done = true;
      } catch (e) { /* noop */ }
    }

    if (done) {
      form.reset(); // restaura o roteiro fixo (value do input) automaticamente
      setMsg('ok', 'Recebemos seus dados. Nossa equipe entra em contato em breve com os valores.');
      poAvisaLead(lead);
    } else {
      setMsg('err', 'Não foi possível enviar agora. Tente novamente em instantes.');
    }
    if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
  });
}

/* ============================================================
   Origem do lead (13/11/2026). Ela sai da memoria do site
   (assets/js/origem.js), nao so da URL desta pagina: quem chega pelo
   anuncio no roteiro e preenche em /contato, ou volta dias depois, ainda
   manda o gclid e a UTM da chegada. Sem o origem.js (ou se ele falhar),
   ficam os campos ocultos que a pagina ja tinha: o comportamento de antes.
   Nada aqui pode impedir o envio do lead.
============================================================ */
function poAplicaOrigem(data) {
  'use strict';
  try {
    const o = window.poOrigem && typeof window.poOrigem.campos === 'function' ? window.poOrigem.campos() : null;
    if (o) Object.keys(o).forEach((k) => data.set(k, o[k] == null ? '' : String(o[k])));
  } catch (e) { /* segue com os campos ocultos */ }
  return data;
}
/* Os campos de origem que entraram em 13/11. Se o banco ainda nao os tem,
   a gravacao de reserva tenta de novo sem eles (o enviar.php faz o mesmo). */
function poSemOrigemNova(lead) {
  'use strict';
  const o = Object.assign({}, lead);
  ['utm_term', 'utm_content', 'gbraid', 'wbraid', 'fbclid'].forEach((k) => { delete o[k]; });
  return o;
}

/* ============================================================
   Aviso de lead gravado (fase 4 do rastreio, 13/11/2026). Quem conta a
   conversao para o Google Ads e para o Pixel da Meta e o
   assets/js/rastreio.js; o formulario so diz que um lead ENTROU, e so
   depois de gravar, por qualquer dos dois caminhos. O honeypot finge
   sucesso para o robo e nunca chega aqui: spam virando conversao ensinaria
   o lance automatico a comprar mais spam. O aviso nao leva dado pessoal
   (nome, telefone, e-mail): so o roteiro e a origem do formulario.
============================================================ */
function poAvisaLead(lead) {
  'use strict';
  try {
    document.dispatchEvent(new CustomEvent('po:lead', {
      detail: { roteiro: lead.roteiro || '', origem: lead.origem || '' }
    }));
  } catch (e) { /* o lead ja gravou: a medicao nunca atrapalha */ }
}

/* ============================================================
   Popula o <select id="f-roteiro"> (contato.html) com os roteiros
   ATIVOS do banco (po_roteiros), sempre atualizado: some se um for
   excluído/desativado, aparece se um for adicionado. Mantém a 1ª
   opção "quero uma sugestão" e o fallback do HTML se o banco falhar.
   Nas páginas de roteiro o #f-roteiro é um <input> readonly (roteiro
   fixo), então esta função ignora e não faz nada.
============================================================ */
async function populateRoteiroSelect() {
  'use strict';
  const sel = document.getElementById('f-roteiro');
  if (!sel || sel.tagName !== 'SELECT') return;
  if (typeof window.poFetchRoteiros !== 'function') return;

  const list = await window.poFetchRoteiros();
  if (!list || !list.length) return; // banco indisponível → mantém o fallback do HTML

  const prev = sel.value;
  // preserva a 1ª opção ("Ainda não sei / quero uma sugestão")
  let firstOpt = sel.querySelector('option[value=""]');
  if (!firstOpt) {
    firstOpt = document.createElement('option');
    firstOpt.value = '';
    firstOpt.textContent = 'Ainda não sei / quero uma sugestão';
  }
  sel.innerHTML = '';
  sel.appendChild(firstOpt);

  list.forEach((r) => {
    const t = (r.titulo || '').trim();
    if (!t) return;
    const o = document.createElement('option');
    o.textContent = t; // value = título (name="roteiro")
    sel.appendChild(o);
  });

  // restaura a seleção anterior se o roteiro ainda existir
  if (prev && Array.from(sel.options).some((o) => o.value === prev)) sel.value = prev;
}

window.initLeadForm = initLeadForm;
window.populateRoteiroSelect = populateRoteiroSelect;
if (!window.__deferLeadInit) { initLeadForm(); populateRoteiroSelect(); }
