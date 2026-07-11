/* ============================================================
   Pereira Oliveira Turismo — Formulário de lead (compartilhado)
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

  // cliente Supabase (gravação primária — funciona no preview e na produção)
  let sb = null;
  try {
    if (window.supabase && window.PO_CONFIG) {
      sb = window.supabase.createClient(PO_CONFIG.SUPABASE_URL, PO_CONFIG.SUPABASE_ANON_KEY);
    }
  } catch (e) { /* segue sem sb; enviar.php cobre a produção */ }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const data = new FormData(form);

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
      gclid: data.get('gclid') || null,
      referrer: data.get('referrer') || null,
      landing_page: data.get('landing_page') || null
    };

    // Gravação com caminho único por ambiente (evita lead duplicado):
    //  1) produção (cPanel/PHP): enviar.php grava no Supabase (service_role) + e-mail;
    //  2) preview/Pages (sem PHP): enviar.php não roda → fallback insert anon aqui.
    let done = false;
    try {
      const r = await fetch('enviar.php', { method: 'POST', body: data });
      if (r.ok) { const j = await r.json().catch(() => null); if (j && j.ok === true) done = true; }
    } catch (e) { /* sem backend PHP: usa fallback abaixo */ }

    if (!done && sb) {
      try { const { error } = await sb.from('po_leads').insert(lead); if (!error) done = true; } catch (e) { /* noop */ }
    }

    if (done) {
      form.reset(); // restaura o roteiro fixo (value do input) automaticamente
      setMsg('ok', 'Recebemos seus dados. Nossa equipe entra em contato em breve com os valores.');
    } else {
      setMsg('err', 'Não foi possível enviar agora. Tente novamente em instantes.');
    }
    if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
  });
}

window.initLeadForm = initLeadForm;
if (!window.__deferLeadInit) initLeadForm();
