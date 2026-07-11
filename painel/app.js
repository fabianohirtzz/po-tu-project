/* ============================================================
   Painel Pereira Oliveira — Supabase + render
   Views: Leads · Relatórios · Roteiros (CRUD + importador Word/PDF/PPT)
============================================================ */
const $=s=>document.querySelector(s), $$=s=>[...document.querySelectorAll(s)];
const CFG=window.PO_CONFIG||{};
if(!CFG.SUPABASE_URL){alert('Configure o painel/config.js com URL e anon key do Supabase.');}
const sb=window.supabase.createClient(CFG.SUPABASE_URL,CFG.SUPABASE_ANON_KEY);
const BUCKET=CFG.STORAGE_BUCKET||'po-imagens';

/* ---------- dicionários ---------- */
const STATUS={
  venda:{label:'Venda feita',cls:'s-venda'},
  negociacao:{label:'Em negociação',cls:'s-nego'},
  atendimento:{label:'Atendimento iniciado',cls:'s-aberto'},
  semresposta:{label:'Sem resposta',cls:'s-sem'},
  perdido:{label:'Perdido',cls:'s-perdido'}
};
const ORIG={pago:{label:'Pago',cls:'pago'},organico:{label:'Orgânico',cls:'organico'},social:{label:'Social',cls:'social'},direto:{label:'Direto',cls:'direto'}};

/* ---------- helpers ---------- */
const brl=n=>n>0?n.toLocaleString('pt-BR',{style:'currency',currency:'BRL',maximumFractionDigits:0}):'—';
const brl2=n=>(n||0).toLocaleString('pt-BR',{style:'currency',currency:'BRL',maximumFractionDigits:0});
function esc(s){return (s==null?'':String(s)).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));}
function fmtData(iso){if(!iso)return '—';const d=new Date(iso);return d.toLocaleDateString('pt-BR',{day:'2-digit',month:'short'})+' · '+d.toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'});}
function isPago(o){return o==='pago';}
function monthKey(iso){return (iso||'').slice(0,7);}
function monthLabel(key){if(key==='all')return 'Todos os meses';const[y,m]=key.split('-').map(Number);const s=new Date(y,m-1,1).toLocaleDateString('pt-BR',{month:'long',year:'numeric'});return s.charAt(0).toUpperCase()+s.slice(1).replace(' de ',' / ');}
function slugify(s){return (s||'').toString().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'').replace(/[^a-z0-9]+/g,'-').replace(/(^-|-$)/g,'').slice(0,60);}
let toastT;function toast(msg,err){const t=$('#toast');t.textContent=msg;t.className='toast on'+(err?' err':'');clearTimeout(toastT);toastT=setTimeout(()=>t.className='toast',2800);}

/* ---------- estado ---------- */
let LEADS=[], spend={}, ROTEIROS=[];
let F={orig:'todos',status:'todos',q:'',month:''};
let openId=null, editRot=null;

/* ============================================================ AUTH */
const loginForm=$('#login-form'), lgErr=$('#lg-error'), lgBtn=$('#lg-btn');
loginForm.addEventListener('submit',async e=>{
  e.preventDefault();lgErr.textContent='';lgBtn.disabled=true;lgBtn.textContent='Entrando…';
  const {error}=await sb.auth.signInWithPassword({email:$('#lg-email').value.trim(),password:$('#lg-pass').value});
  lgBtn.disabled=false;lgBtn.textContent='Entrar →';
  if(error){lgErr.textContent='E-mail ou senha incorretos.';return;}
  enterApp();
});
$('#logout-btn').onclick=async()=>{await sb.auth.signOut();location.reload();};
async function enterApp(){
  const {data:{user}}=await sb.auth.getUser();if(!user)return;
  $('#user-email').textContent=user.email;
  $('#login').classList.add('hidden');$('#app').classList.add('on');
  await loadData();
}

/* ============================================================ CARGA */
async function loadData(){
  const [{data:leadsData,error:e1},{data:spendData},{data:rotData,error:e3}]=await Promise.all([
    sb.from('po_leads').select('*').order('created_at',{ascending:false}),
    sb.from('po_ad_spend').select('*'),
    sb.from('po_roteiros').select('*').order('ordem').order('created_at')
  ]);
  if(e1){toast('Erro ao carregar leads: '+e1.message,true);}
  LEADS=(leadsData||[]).map(mapRow);
  spend={};(spendData||[]).forEach(s=>{spend[s.month]=Number(s.amount)||0;});
  ROTEIROS=rotData||[];
  buildMonths();renderLeads();renderRoteiros();
}
function classifyChannel(r){
  if(r.gclid) return 'pago';
  const med=(r.utm_medium||'').toLowerCase();
  if(/cpc|ppc|paid|ads/.test(med)) return 'pago';
  if(/social|facebook|instagram|meta/.test(med)) return 'social';
  if(r.utm_source||med) return 'organico';
  return 'direto';
}
function mapRow(r){
  const auto=classifyChannel(r);
  const eff=r.origem_manual||auto;
  return {id:r.id,data:r.created_at,nome:r.nome||'(sem nome)',
    tel:r.telefone||'—',email:r.email||'',cidade:r.cidade||'—',
    viajantes:r.viajantes||'—',roteiro:r.roteiro||'—',msg:r.mensagem||'(sem mensagem)',
    origemRaw:r.origem||'—',origemAuto:auto,origem:eff,
    camp:r.utm_campaign||r.origem||'—',land:r.landing_page||'—',
    status:r.status||'semresposta',orc:Number(r.orcamento)||0,ven:Number(r.venda)||0,obs:r.observacoes||''};
}
function buildMonths(){
  const set=[...new Set(LEADS.map(l=>monthKey(l.data)).filter(Boolean))].sort().reverse();
  if(!set.length){const now=new Date();set.push(now.toISOString().slice(0,7));}
  F.month=set[0];
  const sel=$('#month-sel');
  sel.innerHTML=set.map(k=>`<option value="${k}">${monthLabel(k)}</option>`).join('')+`<option value="all">Todos os meses</option>`;
  sel.value=F.month;syncSpendInput();
}

/* ============================================================ NAV */
$$('.nav-item').forEach(b=>b.onclick=()=>{
  $$('.nav-item').forEach(x=>x.classList.remove('active'));b.classList.add('active');
  const v=b.dataset.view;$$('.view').forEach(x=>x.classList.remove('on'));
  $('#month-box').style.visibility=(v==='roteiros')?'hidden':'visible';
  if(v==='leads'){$('#view-leads').classList.add('on');$('#top-title').innerHTML='Leads<span>.</span>';renderLeads();}
  else if(v==='reports'){$('#view-reports').classList.add('on');$('#top-title').innerHTML='Relatórios<span>.</span>';renderReports();}
  else{$('#view-roteiros').classList.add('on');$('#top-title').innerHTML='Roteiros<span>.</span>';renderRoteiros();}
});
$('#month-sel').onchange=e=>{F.month=e.target.value;syncSpendInput();renderLeads();if($('#view-reports').classList.contains('on'))renderReports();};
$('#q').oninput=e=>{F.q=e.target.value;renderLeads();};
$('#status-filter').onchange=e=>{F.status=e.target.value;renderLeads();};
$$('#orig-filter .chip').forEach(c=>c.onclick=()=>{$$('#orig-filter .chip').forEach(x=>x.classList.remove('active'));c.classList.add('active');F.orig=c.dataset.orig;renderLeads();});

/* ============================================================ LEADS */
function inMonth(l){return F.month==='all'||monthKey(l.data)===F.month;}
function filtered(){
  return LEADS.filter(inMonth)
    .filter(l=>F.orig==='todos'||(F.orig==='pago'?l.origem==='pago':l.origem!=='pago'))
    .filter(l=>F.status==='todos'||l.status===F.status)
    .filter(l=>{if(!F.q)return true;const s=(l.nome+l.cidade+l.roteiro).toLowerCase();return s.includes(F.q.toLowerCase());});
}
function origBadge(o){const x=ORIG[o]||ORIG.direto;return `<span class="orig ${x.cls}">${x.label}</span>`;}
function renderLeads(){
  const rows=filtered();const tb=$('#lead-rows');
  if(!rows.length)tb.innerHTML=`<tr><td colspan="10"><div class="empty">Nenhum lead com esses filtros.</div></td></tr>`;
  else tb.innerHTML=rows.map(l=>{const st=STATUS[l.status]||STATUS.semresposta;return `<tr data-id="${l.id}">
    <td>${origBadge(l.origem)}</td>
    <td class="mono">${fmtData(l.data)}</td>
    <td><div class="c-name">${esc(l.nome)}</div><div class="c-sub">${esc(l.cidade)}</div></td>
    <td><div class="mono">${esc(l.tel)}</div><div class="c-sub mono">${esc(l.email)||'—'}</div></td>
    <td class="desc-cell" title="${esc(l.roteiro)}">${esc(l.roteiro)}</td>
    <td>${esc(l.viajantes)}</td>
    <td class="mono">${esc(l.cidade)}</td>
    <td><span class="status-sel ${st.cls}">${st.label}</span></td>
    <td class="money ${l.orc?'':'zero'}">${brl(l.orc)}</td>
    <td class="money ${l.ven?'':'zero'}">${brl(l.ven)}</td></tr>`;}).join('');
  $$('#lead-rows tr[data-id]').forEach(tr=>tr.onclick=()=>openDrawer(tr.dataset.id));
  renderLeadKpis(rows);
}
function renderLeadKpis(rows){
  const total=rows.length,vendas=rows.filter(l=>l.status==='venda');
  const conv=total?Math.round(vendas.length/total*100):0;
  const valVen=rows.reduce((s,l)=>s+l.ven,0),valOrc=rows.reduce((s,l)=>s+l.orc,0);
  $('#lead-kpis').innerHTML=`
    <div class="kpi"><div class="kpi-l">Leads no período</div><div class="kpi-n">${total}</div><div class="kpi-sub">${rows.filter(l=>isPago(l.origem)).length} pago · ${rows.filter(l=>!isPago(l.origem)).length} orgânico</div></div>
    <div class="kpi k-green"><div class="kpi-l">Vendas fechadas</div><div class="kpi-n">${vendas.length}</div><div class="kpi-sub"><b>${conv}%</b> de conversão</div></div>
    <div class="kpi k-orange"><div class="kpi-l">Em orçamentos</div><div class="kpi-n" style="font-size:1.7rem">${brl2(valOrc)}</div><div class="kpi-sub">valor total cotado</div></div>
    <div class="kpi k-green"><div class="kpi-l">Em vendas</div><div class="kpi-n" style="font-size:1.7rem">${brl2(valVen)}</div><div class="kpi-sub">faturamento fechado</div></div>`;
}
function openDrawer(id){
  const l=LEADS.find(x=>String(x.id)===String(id));if(!l)return;openId=l.id;
  $('#dr-orig').innerHTML=origBadge(l.origem);
  $('#dr-name').textContent=l.nome;$('#dr-comp').textContent=l.roteiro;
  $('#dr-tel').textContent=l.tel;$('#dr-email').textContent=l.email||'—';
  $('#dr-cidade').textContent=l.cidade;$('#dr-viajantes').textContent=l.viajantes;
  $('#dr-roteiro').textContent=l.roteiro;$('#dr-data').textContent=fmtData(l.data);
  $('#dr-canal').textContent=(ORIG[l.origemAuto]||ORIG.direto).label;
  $('#dr-camp').textContent=l.camp;$('#dr-land').textContent=l.land;$('#dr-desc').textContent=l.msg;
  $('#e-status').value=l.status;$('#e-orig').value=l.origem;
  $('#e-orc').value=l.orc||'';$('#e-ven').value=l.ven||'';$('#e-obs').value=l.obs;
  $('#scrim').classList.add('on');$('#drawer').classList.add('on');
}
function closeDrawer(){$('#scrim').classList.remove('on');$('#drawer').classList.remove('on');openId=null;}
$('#dr-close').onclick=closeDrawer;$('#dr-cancel').onclick=closeDrawer;
$('#scrim').onclick=()=>{closeDrawer();closeRot();};
$('#dr-save').onclick=async()=>{
  const l=LEADS.find(x=>x.id===openId);if(!l)return;
  const btn=$('#dr-save');btn.disabled=true;btn.textContent='Salvando…';
  const patch={status:$('#e-status').value,origem_manual:$('#e-orig').value,orcamento:Number($('#e-orc').value)||0,venda:Number($('#e-ven').value)||0,observacoes:$('#e-obs').value};
  const {error}=await sb.from('po_leads').update(patch).eq('id',l.id);
  btn.disabled=false;btn.textContent='Salvar';
  if(error){toast('Erro ao salvar: '+error.message,true);return;}
  l.status=patch.status;l.origem=patch.origem_manual;l.orc=patch.orcamento;l.ven=patch.venda;l.obs=patch.observacoes;
  const f=$('#save-flash');f.classList.add('on');setTimeout(()=>f.classList.remove('on'),1600);
  renderLeads();toast('Lead atualizado.');
};

/* ============================================================ RELATÓRIOS */
function syncSpendInput(){
  const inp=$('#spend-input');$('#spend-month-lbl').textContent=monthLabel(F.month);
  if(F.month==='all'){inp.value=Object.values(spend).reduce((a,b)=>a+b,0);inp.readOnly=true;inp.style.opacity=.5;}
  else{inp.value=spend[F.month]||0;inp.readOnly=false;inp.style.opacity=1;}
}
$('#spend-input').onchange=async e=>{
  if(F.month==='all')return;const amount=Number(e.target.value)||0;spend[F.month]=amount;
  const {error}=await sb.from('po_ad_spend').upsert({month:F.month,amount},{onConflict:'month'});
  if(error){toast('Erro ao salvar investimento: '+error.message,true);return;}
  renderReports();toast('Investimento salvo.');
};
function curSpend(){return F.month==='all'?Object.values(spend).reduce((a,b)=>a+b,0):(spend[F.month]||0);}
function rowsForReports(){return LEADS.filter(inMonth).filter(l=>F.orig==='todos'||(F.orig==='pago'?l.origem==='pago':l.origem!=='pago'));}
function renderReports(){
  const rows=rowsForReports();const total=rows.length;
  const vendas=rows.filter(l=>l.status==='venda');
  const valVen=rows.reduce((s,l)=>s+l.ven,0),valOrc=rows.reduce((s,l)=>s+l.orc,0);
  const conv=total?(vendas.length/total*100):0,ticket=vendas.length?valVen/vendas.length:0;
  const sp=curSpend(),venPago=rows.filter(l=>isPago(l.origem)).reduce((s,l)=>s+l.ven,0),leadsPago=rows.filter(l=>isPago(l.origem)).length;
  const roas=sp>0?venPago/sp:0,roi=sp>0?((venPago-sp)/sp*100):0,cpl=leadsPago>0?sp/leadsPago:0;
  $('#rep-kpis').innerHTML=`
    <div class="kpi"><div class="kpi-l">Total de leads</div><div class="kpi-n">${total}</div><div class="kpi-sub">${leadsPago} via anúncios</div></div>
    <div class="kpi k-green"><div class="kpi-l">Taxa de conversão</div><div class="kpi-n">${conv.toFixed(0)}<small>%</small></div><div class="kpi-sub">${vendas.length} de ${total} leads</div></div>
    <div class="kpi k-orange"><div class="kpi-l">ROAS (pago)</div><div class="kpi-n">${sp>0?roas.toFixed(1):'—'}<small>${sp>0?'x':''}</small></div><div class="kpi-sub">retorno s/ anúncios</div></div>
    <div class="kpi ${roi>=0?'k-green':''}"><div class="kpi-l">ROI (pago)</div><div class="kpi-n">${sp>0?(roi>=0?'+':'')+roi.toFixed(0):'—'}<small>${sp>0?'%':''}</small></div><div class="kpi-sub">${brl2(sp)} investido</div></div>`;
  const byOrig={pago:0,organico:0,social:0,direto:0};rows.forEach(l=>{byOrig[l.origem]=(byOrig[l.origem]||0)+1;});
  const colors={pago:'var(--azul)',organico:'var(--st-venda)',social:'var(--st-nego)',direto:'#9aa4b2'};
  let acc=0,segs=[];Object.entries(byOrig).forEach(([k,v])=>{if(v&&total){const pct=v/total*100;segs.push(`${colors[k]} ${acc}% ${acc+pct}%`);acc+=pct;}});
  $('#donut').style.background=`conic-gradient(${segs.join(',')||'#e4e7eb 0 100%'})`;
  $('#donut').innerHTML=`<div class="donut-c"><b>${total}</b><span>leads</span></div>`;
  $('#donut-legend').innerHTML=Object.entries(byOrig).filter(([,v])=>v).map(([k,v])=>`<div class="legend-row"><span class="legend-dot" style="background:${colors[k]}"></span>${(ORIG[k]||ORIG.direto).label}<span class="pct">${total?(v/total*100).toFixed(0):0}%</span><span class="n">${v}</span></div>`).join('')||'<div class="card-sub">Sem dados no período.</div>';
  const order=['atendimento','negociacao','venda','semresposta','perdido'];
  const byStatus={};order.forEach(s=>byStatus[s]=rows.filter(l=>l.status===s).length);
  const maxS=Math.max(1,...Object.values(byStatus));
  const scolor={atendimento:'var(--st-aberto)',negociacao:'var(--st-nego)',venda:'var(--st-venda)',semresposta:'var(--st-sem)',perdido:'var(--st-perdido)'};
  $('#status-bars').innerHTML=order.map(s=>`<div class="bar-row"><div class="bar-lbl">${STATUS[s].label}</div><div class="bar-track"><div class="bar-fill" style="width:${byStatus[s]/maxS*100}%;background:${scolor[s]}"></div></div><div class="bar-n">${byStatus[s]}</div></div>`).join('');
  $('#vs-orc').textContent=brl2(valOrc);$('#vs-ven').textContent=brl2(valVen);
  const close=valOrc>0?valVen/valOrc*100:0;$('#close-bar').style.width=Math.min(100,close)+'%';$('#close-n').textContent=close.toFixed(0)+'%';
  $('#m-roas').textContent=sp>0?roas.toFixed(1)+'x':'—';
  $('#m-roi').textContent=sp>0?(roi>=0?'+':'')+roi.toFixed(0)+'%':'—';$('#m-roi').style.color=roi>=0?'var(--st-venda)':'var(--st-perdido)';
  $('#m-cpl').textContent=cpl>0?brl2(Math.round(cpl)):'—';$('#m-vpago').textContent=brl2(venPago);$('#m-ticket').textContent=brl2(Math.round(ticket));
  renderTimeline(rows);
}
function renderTimeline(rows){
  if(F.month==='all'){
    const by={};rows.forEach(l=>{const m=monthKey(l.data);by[m]=(by[m]||0)+1;});
    const keys=Object.keys(by).sort(),max=Math.max(1,...Object.values(by));
    $('#timeline').innerHTML=keys.map(k=>`<div class="tl-bar" style="height:${by[k]/max*100}%"><span>${by[k]}</span></div>`).join('');
    $('#tl-axis').innerHTML=keys.map(k=>`<span>${k.slice(5)}/${k.slice(2,4)}</span>`).join('');return;
  }
  const[y,m]=F.month.split('-').map(Number),days=new Date(y,m,0).getDate();
  const by=new Array(days+1).fill(0);rows.forEach(l=>{const d=Number((l.data||'').slice(8,10));if(d>=1&&d<=days)by[d]++;});
  const max=Math.max(1,...by);let html='';for(let d=1;d<=days;d++)html+=`<div class="tl-bar" style="height:${by[d]/max*100}%"><span>${by[d]}</span></div>`;
  $('#timeline').innerHTML=html;$('#tl-axis').innerHTML='<span>01</span><span>08</span><span>15</span><span>22</span><span>'+days+'</span>';
}

/* ============================================================ ROTEIROS — grid */
function renderRoteiros(){
  const g=$('#rot-grid');
  if(!ROTEIROS.length){g.innerHTML=`<div class="empty" style="grid-column:1/-1">Nenhum roteiro. Clique em “Novo roteiro” ou importe um arquivo.</div>`;return;}
  g.innerHTML=ROTEIROS.map(r=>`<div class="rcard" data-id="${r.id}">
    <div class="rcard__img" style="background-image:url('${esc(r.capa_url||'')}')">${r.ativo?'':'<span class="rcard__off">Oculto</span>'}</div>
    <div class="rcard__b">
      <div class="rcard__t">${esc(r.titulo||'(sem título)')}</div>
      <div class="rcard__s">${esc(r.data_label||r.periodo||'—')} · ${esc(r.local_label||'')}</div>
      <div class="rcard__foot"><button class="edit" data-id="${r.id}">Editar</button><button class="del" data-id="${r.id}">Excluir</button></div>
    </div></div>`).join('');
  $$('#rot-grid .edit').forEach(b=>b.onclick=e=>{e.stopPropagation();openRot(ROTEIROS.find(x=>String(x.id)===b.dataset.id));});
  $$('#rot-grid .del').forEach(b=>b.onclick=async e=>{e.stopPropagation();const r=ROTEIROS.find(x=>String(x.id)===b.dataset.id);if(!confirm('Excluir o roteiro “'+(r.titulo||'')+'”?'))return;const {error}=await sb.from('po_roteiros').delete().eq('id',r.id);if(error){toast('Erro ao excluir: '+error.message,true);return;}ROTEIROS=ROTEIROS.filter(x=>x.id!==r.id);renderRoteiros();toast('Roteiro excluído.');});
}
$('#rot-new').onclick=()=>openRot(null);

/* ---------- editor de roteiro ---------- */
function repItemDia(d={}){return `<div class="rep-item"><button type="button" class="rm">✕</button>
  <div class="field-3"><div class="field"><label>Nº</label><input class="rd-n" type="number" value="${d.n||''}"></div>
  <div class="field"><label>Data</label><input class="rd-data" value="${esc(d.data||'')}"></div>
  <div class="field"><label>Dia da semana</label><input class="rd-sem" value="${esc(d.dia_semana||'')}"></div></div>
  <div class="field"><label>Cidades</label><input class="rd-cid" value="${esc(d.cidades||'')}"></div>
  <div class="field"><label>Descrição</label><textarea class="rd-desc">${esc(d.descricao||'')}</textarea></div>
  <div class="field"><label>Refeições (opcional)</label><input class="rd-ref" value="${esc(d.refeicoes||'')}"></div></div>`;}
function repItemVal(v={}){return `<div class="rep-item"><button type="button" class="rm">✕</button>
  <div class="field-2"><div class="field"><label>Rótulo</label><input class="rv-tag" value="${esc(v.tag||'')}"></div>
  <div class="field"><label>Prefixo (ex: a partir de)</label><input class="rv-de" value="${esc(v.de||'')}"></div></div>
  <div class="field-2"><div class="field"><label>Valor</label><input class="rv-valor" value="${esc(v.valor||'')}"></div>
  <div class="field"><label>Complemento</label><input class="rv-extra" value="${esc(v.extra||'')}"></div></div></div>`;}
function arrToLines(a){return (a||[]).map(x=>typeof x==='string'?x:(x.cidade?x.cidade+': '+x.hotel:JSON.stringify(x))).join('\n');}
function roteiroForm(r){
  r=r||{};
  const dias=(r.roteiro_dias&&r.roteiro_dias.length?r.roteiro_dias:[{}]).map(repItemDia).join('');
  const vals=(r.valores&&r.valores.length?r.valores:[{}]).map(repItemVal).join('');
  const gal=(r.galeria||[]).map(u=>`<div class="gal-thumb" style="background-image:url('${esc(u)}')" data-url="${esc(u)}"><button type="button">✕</button></div>`).join('');
  return `
  <div class="dr-section-l">Identificação</div>
  <div class="field"><label>Título</label><input id="r-titulo" value="${esc(r.titulo||'')}"></div>
  <div class="field"><label>Slug (URL) — ex: chile-atacama</label><input id="r-slug" value="${esc(r.slug||'')}" placeholder="gerado do título se vazio"></div>
  <div class="field"><label>Subtítulo</label><input id="r-subtitulo" value="${esc(r.subtitulo||'')}"></div>
  <div class="field"><label>Descrição curta</label><textarea id="r-desc">${esc(r.descricao_curta||'')}</textarea></div>
  <div class="field-3">
    <div class="field"><label>Dias</label><input id="r-dias" type="number" value="${r.dias||''}"></div>
    <div class="field"><label>Noites</label><input id="r-noites" type="number" value="${r.noites||''}"></div>
    <div class="field"><label>Ordem</label><input id="r-ordem" type="number" value="${r.ordem||0}"></div>
  </div>
  <div class="field-3">
    <div class="field"><label>Badge (ex: 8 dias)</label><input id="r-badge" value="${esc(r.badge||'')}"></div>
    <div class="field"><label>Data (card)</label><input id="r-datalabel" value="${esc(r.data_label||'')}"></div>
    <div class="field"><label>Local (card)</label><input id="r-local" value="${esc(r.local_label||'')}"></div>
  </div>
  <div class="field"><label>Período (texto)</label><input id="r-periodo" value="${esc(r.periodo||'')}"></div>
  <div class="field-2">
    <div class="field"><label>Vídeo YouTube (ID)</label><input id="r-video" value="${esc(r.video_id||'')}"></div>
    <div class="field"><label>Playlist/mix (opcional)</label><input id="r-videolist" value="${esc(r.video_list||'')}"></div>
  </div>
  <div class="field"><label>Ativo no site</label><select id="r-ativo"><option value="1" ${r.ativo!==false?'selected':''}>Sim, publicado</option><option value="0" ${r.ativo===false?'selected':''}>Não (oculto)</option></select></div>

  <div class="dr-section-l">Capa</div>
  <div class="field">
    <div class="gal-thumbs" id="r-capa-wrap">${r.capa_url?`<div class="gal-thumb" style="background-image:url('${esc(r.capa_url)}')" data-capa><button type="button">✕</button></div>`:''}</div>
    <input type="hidden" id="r-capa" value="${esc(r.capa_url||'')}">
    <input type="file" id="r-capa-file" accept="image/*" hidden>
    <button type="button" class="rep-add" id="r-capa-btn">Enviar capa</button>
    <span class="uploading" id="r-capa-up"></span>
  </div>

  <div class="dr-section-l">Roteiro dia a dia</div>
  <div class="rep" id="r-dias-rep">${dias}</div>
  <button type="button" class="rep-add" id="r-dias-add">+ Adicionar dia</button>

  <div class="dr-section-l">Hotéis previstos</div>
  <div class="field"><label>Um por linha (ex: Santiago: Hotel X – 4 estrelas)</label><textarea id="r-hoteis">${esc(arrToLines(r.hoteis))}</textarea></div>

  <div class="dr-section-l">O pacote inclui</div>
  <div class="field"><label>Um item por linha</label><textarea id="r-inclui" style="min-height:120px">${esc((r.inclui||[]).join('\n'))}</textarea></div>

  <div class="dr-section-l">O pacote não inclui</div>
  <div class="field"><label>Um item por linha</label><textarea id="r-naoinclui" style="min-height:100px">${esc((r.nao_inclui||[]).join('\n'))}</textarea></div>

  <div class="dr-section-l">Valores</div>
  <div class="rep" id="r-val-rep">${vals}</div>
  <button type="button" class="rep-add" id="r-val-add">+ Adicionar valor</button>

  <div class="dr-section-l">Galeria</div>
  <div class="gal-thumbs" id="r-gal-wrap">${gal}</div>
  <input type="file" id="r-gal-file" accept="image/*" multiple hidden>
  <button type="button" class="rep-add" id="r-gal-btn">+ Enviar imagens</button>
  <span class="uploading" id="r-gal-up"></span>`;
}
function wireRoteiroForm(){
  // remover linha repetível
  $('#rdr-body').addEventListener('click',e=>{if(e.target.classList.contains('rm'))e.target.closest('.rep-item').remove();});
  $('#r-dias-add').onclick=()=>$('#r-dias-rep').insertAdjacentHTML('beforeend',repItemDia());
  $('#r-val-add').onclick=()=>$('#r-val-rep').insertAdjacentHTML('beforeend',repItemVal());
  // capa upload
  $('#r-capa-btn').onclick=()=>$('#r-capa-file').click();
  $('#r-capa-file').onchange=async e=>{const f=e.target.files[0];if(!f)return;$('#r-capa-up').textContent='Enviando…';const url=await uploadImg(f,curSlug());$('#r-capa-up').textContent='';if(!url)return;$('#r-capa').value=url;$('#r-capa-wrap').innerHTML=`<div class="gal-thumb" style="background-image:url('${url}')" data-capa><button type="button">✕</button></div>`;};
  // galeria upload
  $('#r-gal-btn').onclick=()=>$('#r-gal-file').click();
  $('#r-gal-file').onchange=async e=>{const fs=[...e.target.files];if(!fs.length)return;$('#r-gal-up').textContent='Enviando '+fs.length+'…';for(const f of fs){const url=await uploadImg(f,curSlug());if(url)$('#r-gal-wrap').insertAdjacentHTML('beforeend',`<div class="gal-thumb" style="background-image:url('${url}')" data-url="${url}"><button type="button">✕</button></div>`);}$('#r-gal-up').textContent='';};
  // remover thumb (capa/galeria)
  $('#rdr-body').addEventListener('click',e=>{if(e.target.tagName==='BUTTON'&&e.target.closest('.gal-thumb')){const t=e.target.closest('.gal-thumb');if(t.hasAttribute('data-capa'))$('#r-capa').value='';t.remove();}});
}
function curSlug(){return slugify($('#r-slug')?.value||$('#r-titulo')?.value||'roteiro');}
async function uploadImg(file,slug){
  const clean=file.name.replace(/[^a-zA-Z0-9.\-_]/g,'_');
  const path=`roteiros/${slug||'sem-slug'}/${Date.now()}-${clean}`;
  const {error}=await sb.storage.from(BUCKET).upload(path,file,{upsert:true,contentType:file.type});
  if(error){toast('Erro no upload: '+error.message,true);return null;}
  return sb.storage.from(BUCKET).getPublicUrl(path).data.publicUrl;
}
function openRot(r){
  editRot=r;$('#rdr-title').textContent=r?('Editar: '+(r.titulo||'')):'Novo roteiro';
  $('#rdr-del').style.display=r?'block':'none';
  $('#rdr-body').innerHTML=roteiroForm(r||{});
  wireRoteiroForm();
  $('#scrim').classList.add('on');$('#rdrawer').classList.add('on');$('#rdrawer').scrollTop=0;
}
function closeRot(){$('#rdrawer').classList.remove('on');if(!$('#drawer').classList.contains('on'))$('#scrim').classList.remove('on');editRot=null;}
$('#rdr-close').onclick=closeRot;$('#rdr-cancel').onclick=closeRot;
$('#rdr-del').onclick=async()=>{if(!editRot)return;if(!confirm('Excluir este roteiro?'))return;const{error}=await sb.from('po_roteiros').delete().eq('id',editRot.id);if(error){toast('Erro: '+error.message,true);return;}ROTEIROS=ROTEIROS.filter(x=>x.id!==editRot.id);closeRot();renderRoteiros();toast('Roteiro excluído.');};
function collectRoteiro(){
  const lines=id=>($('#'+id).value||'').split('\n').map(s=>s.trim()).filter(Boolean);
  const dias=[...$('#r-dias-rep').children].map(it=>({n:Number(it.querySelector('.rd-n').value)||null,data:it.querySelector('.rd-data').value.trim(),dia_semana:it.querySelector('.rd-sem').value.trim(),cidades:it.querySelector('.rd-cid').value.trim(),descricao:it.querySelector('.rd-desc').value.trim(),refeicoes:it.querySelector('.rd-ref').value.trim()})).filter(d=>d.cidades||d.descricao);
  const valores=[...$('#r-val-rep').children].map(it=>({tag:it.querySelector('.rv-tag').value.trim(),de:it.querySelector('.rv-de').value.trim(),valor:it.querySelector('.rv-valor').value.trim(),extra:it.querySelector('.rv-extra').value.trim()})).filter(v=>v.valor);
  const galeria=[...$('#r-gal-wrap').querySelectorAll('.gal-thumb')].map(t=>t.dataset.url).filter(Boolean);
  return {
    slug:curSlug(),titulo:$('#r-titulo').value.trim(),subtitulo:$('#r-subtitulo').value.trim(),
    descricao_curta:$('#r-desc').value.trim(),dias:Number($('#r-dias').value)||null,noites:Number($('#r-noites').value)||null,
    ordem:Number($('#r-ordem').value)||0,badge:$('#r-badge').value.trim(),data_label:$('#r-datalabel').value.trim(),
    local_label:$('#r-local').value.trim(),periodo:$('#r-periodo').value.trim(),
    video_id:$('#r-video').value.trim(),video_list:$('#r-videolist').value.trim()||null,
    ativo:$('#r-ativo').value==='1',capa_url:$('#r-capa').value.trim()||null,
    roteiro_dias:dias,hoteis:lines('r-hoteis'),inclui:lines('r-inclui'),nao_inclui:lines('r-naoinclui'),
    valores:valores,galeria:galeria
  };
}
$('#rdr-save').onclick=async()=>{
  const data=collectRoteiro();
  if(!data.titulo){toast('Informe ao menos o título.',true);return;}
  if(!data.slug)data.slug=slugify(data.titulo);
  const btn=$('#rdr-save');btn.disabled=true;btn.textContent='Salvando…';
  let error;
  if(editRot&&editRot.id){({error}=await sb.from('po_roteiros').update(data).eq('id',editRot.id));}
  else{({error}=await sb.from('po_roteiros').insert(data));}
  btn.disabled=false;btn.textContent='Salvar roteiro';
  if(error){toast('Erro ao salvar: '+error.message,true);return;}
  const f=$('#rsave-flash');f.classList.add('on');setTimeout(()=>f.classList.remove('on'),1600);
  await reloadRoteiros();closeRot();toast('Roteiro salvo.');
};
async function reloadRoteiros(){const{data}=await sb.from('po_roteiros').select('*').order('ordem').order('created_at');ROTEIROS=data||[];renderRoteiros();}

/* ============================================================ IMPORTADOR */
$('#imp-btn').onclick=()=>$('#imp-file').click();
$('#imp-file').onchange=async e=>{const f=e.target.files[0];e.target.value='';if(!f)return;
  const ext=(f.name.split('.').pop()||'').toLowerCase();
  showImp(true,'Lendo arquivo…');
  try{
    let parsed;
    if(ext==='docx')parsed=await importDocx(f);
    else if(ext==='pptx')parsed=await importPptx(f);
    else if(ext==='pdf')parsed=await importPdf(f);
    else{toast('Formato não suportado.',true);showImp(false);return;}
    showImp(false);
    openRot(null);
    prefill(parsed);
    toast('Arquivo lido. Revise e salve.');
  }catch(err){console.error(err);showImp(false);toast('Falha ao ler o arquivo: '+err.message,true);}
};
function showImp(on,msg){$('#imp-status').classList.toggle('on',on);if(msg)$('#imp-msg').textContent=msg;}
function prefill(p){
  const set=(id,v)=>{const el=$('#'+id);if(el&&v!=null&&v!=='')el.value=v;};
  set('r-titulo',p.titulo);set('r-slug',slugify(p.titulo||''));set('r-periodo',p.periodo);
  set('r-dias',p.dias);set('r-noites',p.noites);
  if(p.dias)set('r-badge',p.dias+' dias');
  set('r-desc',p.descricao_curta);
  if(p.inclui&&p.inclui.length)$('#r-inclui').value=p.inclui.join('\n');
  if(p.nao_inclui&&p.nao_inclui.length)$('#r-naoinclui').value=p.nao_inclui.join('\n');
  if(p.hoteis&&p.hoteis.length)$('#r-hoteis').value=p.hoteis.join('\n');
  if(p.roteiro_dias&&p.roteiro_dias.length)$('#r-dias-rep').innerHTML=p.roteiro_dias.map(repItemDia).join('');
  if(p.valores&&p.valores.length)$('#r-val-rep').innerHTML=p.valores.map(repItemVal).join('');
  if(p.galeria&&p.galeria.length)$('#r-gal-wrap').innerHTML=p.galeria.map(u=>`<div class="gal-thumb" style="background-image:url('${u}')" data-url="${u}"><button type="button">✕</button></div>`).join('');
  if(p.galeria&&p.galeria.length&&!$('#r-capa').value){$('#r-capa').value=p.galeria[0];$('#r-capa-wrap').innerHTML=`<div class="gal-thumb" style="background-image:url('${p.galeria[0]}')" data-capa><button type="button">✕</button></div>`;}
}
/* ---- parser comum (parágrafos → estrutura) ---- */
function parseParas(paras){
  const out={titulo:'',periodo:'',dias:null,noites:null,roteiro_dias:[],inclui:[],nao_inclui:[],hoteis:[],descricao_curta:''};
  let section=null;
  paras.forEach(line=>{
    const L=(line||'').replace(/\s+/g,' ').trim();if(!L)return;const up=L.toUpperCase();
    if(!out.titulo){out.titulo=L;return;}
    if(!out.periodo&&/\d+\s*dias?/i.test(L)&&/\d/.test(L)){out.periodo=L;const md=L.match(/(\d+)\s*dias?/i);if(md)out.dias=+md[1];const mn=L.match(/(\d+)\s*noites?/i);if(mn)out.noites=+mn[1];return;}
    if(/HOT[ÉE]IS/.test(up)){section='hoteis';return;}
    if(/N[ÃA]O\s+INCLUI/.test(up)){section='nao';return;}
    if(/PACOTE\s+INCLUI|^INCLUI\b/.test(up)){section='inc';return;}
    if(/VALORES/.test(up)){section='val';return;}
    const dm=L.match(/^(\d+)\s*[ºo]?\s*DIA\b\s*[–\-:]?\s*(.*)$/i);
    if(dm){
      const rest=dm[2];const ci=rest.indexOf(':');
      const header=ci>=0?rest.slice(0,ci):rest;const desc=ci>=0?rest.slice(ci+1).trim():'';
      const hp=header.split(/\s+[–—-]\s+/);
      const dataP=(hp.find(x=>/\d{1,2}\/\d{1,2}/.test(x))||'').trim();
      const semP=(hp.find(x=>/FEIRA|S[ÁA]BADO|DOMINGO/i.test(x))||'').trim();
      const cid=hp.filter(x=>x.trim()&&x!==dataP&&x!==semP).join(' – ').trim()||header.trim();
      out.roteiro_dias.push({n:+dm[1],data:dataP,dia_semana:semP,cidades:cid,descricao:desc});
      section='day';return;
    }
    if(section==='hoteis')out.hoteis.push(L);
    else if(section==='inc')out.inclui.push(L);
    else if(section==='nao')out.nao_inclui.push(L);
    else if(section==='day'&&out.roteiro_dias.length){const last=out.roteiro_dias[out.roteiro_dias.length-1];last.descricao=(last.descricao?last.descricao+' ':'')+L;}
  });
  if(!out.dias&&out.roteiro_dias.length)out.dias=out.roteiro_dias.length;
  if(out.roteiro_dias.length){const c=out.roteiro_dias.map(d=>d.cidades).filter(Boolean);out.descricao_curta=out.descricao_curta||('Roteiro por '+[...new Set(c.join(' · ').split(/[·–—]/).map(s=>s.trim()))].slice(0,4).join(', ')+'.');}
  return out;
}
function parseTablesXml(xml){
  const valores=[];
  (xml.match(/<w:tbl>[\s\S]*?<\/w:tbl>/g)||[]).forEach(t=>{
    (t.match(/<w:tr\b[\s\S]*?<\/w:tr>/g)||[]).forEach(r=>{
      const cells=(r.match(/<w:tc>[\s\S]*?<\/w:tc>/g)||[]).map(c=>(c.match(/<w:t[^>]*>([\s\S]*?)<\/w:t>/g)||[]).map(x=>x.replace(/<[^>]+>/g,'')).join('').trim());
      if(cells.length>=2&&cells[1]&&/(R\$|USD|€|\d)/.test(cells[1])&&cells[0])valores.push({tag:cells[0],valor:cells[1]});
    });
  });
  return valores;
}
async function importDocx(file){
  const zip=await JSZip.loadAsync(file);
  const xml=await zip.file('word/document.xml').async('string');
  const paras=xml.split(/<\/w:p>/).map(p=>{const t=(p.match(/<w:t[^>]*>([\s\S]*?)<\/w:t>/g)||[]).map(x=>x.replace(/<[^>]+>/g,'')).join('');return decodeEnt(t);});
  const parsed=parseParas(paras);
  const vals=parseTablesXml(xml);if(vals.length)parsed.valores=vals;
  // imagens
  showImp(true,'Enviando imagens…');
  const slug=slugify(parsed.titulo||'import');
  const media=Object.keys(zip.files).filter(n=>/^word\/media\//i.test(n)&&/\.(jpe?g|png|webp)$/i.test(n));
  parsed.galeria=[];
  for(const n of media){const blob=await zip.file(n).async('blob');const f=new File([blob],n.split('/').pop(),{type:blob.type||'image/jpeg'});const url=await uploadImg(f,slug);if(url)parsed.galeria.push(url);}
  return parsed;
}
async function importPptx(file){
  const zip=await JSZip.loadAsync(file);
  const slideNames=Object.keys(zip.files).filter(n=>/^ppt\/slides\/slide\d+\.xml$/i.test(n)).sort();
  const paras=[];
  for(const n of slideNames){const xml=await zip.file(n).async('string');(xml.match(/<a:t>([\s\S]*?)<\/a:t>/g)||[]).forEach(t=>{const s=decodeEnt(t.replace(/<[^>]+>/g,''));if(s.trim())paras.push(s);});}
  const parsed=parseParas(paras);
  showImp(true,'Enviando imagens…');
  const slug=slugify(parsed.titulo||'import');
  const media=Object.keys(zip.files).filter(n=>/^ppt\/media\//i.test(n)&&/\.(jpe?g|png|webp)$/i.test(n));
  parsed.galeria=[];
  for(const n of media){const blob=await zip.file(n).async('blob');const f=new File([blob],n.split('/').pop(),{type:blob.type||'image/jpeg'});const url=await uploadImg(f,slug);if(url)parsed.galeria.push(url);}
  return parsed;
}
async function importPdf(file){
  await loadPdfJs();
  const buf=await file.arrayBuffer();
  const pdf=await window.pdfjsLib.getDocument({data:buf}).promise;
  let paras=[];
  for(let p=1;p<=pdf.numPages;p++){
    const page=await pdf.getPage(p);const tc=await page.getTextContent();
    let lastY=null,line='';
    tc.items.forEach(it=>{const y=it.transform[5];if(lastY!==null&&Math.abs(y-lastY)>4){if(line.trim())paras.push(line.trim());line='';}line+=it.str+' ';lastY=y;});
    if(line.trim())paras.push(line.trim());
  }
  return parseParas(paras);
}
function loadPdfJs(){return new Promise((res,rej)=>{if(window.pdfjsLib){window.pdfjsLib.GlobalWorkerOptions.workerSrc='https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js';return res();}const s=document.createElement('script');s.src='https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js';s.onload=()=>{window.pdfjsLib.GlobalWorkerOptions.workerSrc='https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js';res();};s.onerror=rej;document.head.appendChild(s);});}
function decodeEnt(s){return (s||'').replace(/&amp;/g,'&').replace(/&lt;/g,'<').replace(/&gt;/g,'>').replace(/&quot;/g,'"').replace(/&#39;/g,"'").replace(/&apos;/g,"'");}

/* ============================================================ BOOT */
(async function(){const {data:{session}}=await sb.auth.getSession();if(session)enterApp();})();
