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
/* 'novo' e o status que o robo do WhatsApp grava no primeiro contato. Sem
   ele aqui a badge caia no fallback e mostrava "Sem resposta", o lead sumia
   do funil de barras e, pior, o <select> da gaveta ficava sem selecao e o
   salvar gravava status:'' por cima do lead. */
const STATUS={
  novo:{label:'Contato feito',cls:'s-novo'},
  venda:{label:'Venda feita',cls:'s-venda'},
  negociacao:{label:'Em negociação',cls:'s-nego'},
  atendimento:{label:'Atendimento iniciado',cls:'s-aberto'},
  semresposta:{label:'Sem resposta',cls:'s-sem'},
  perdido:{label:'Perdido',cls:'s-perdido'}
};
// A cor mora aqui junto do rótulo: o donut e a legenda dos relatórios leem deste
// dicionário. Manter uma segunda tabela de cores lá deixava origem nova sem cor
// (e um "undefined" no meio do conic-gradient derruba o gradiente inteiro).
const ORIG={
  pago     :{label:'Pago',     cls:'pago',     cor:'var(--azul)'},
  organico :{label:'Orgânico', cls:'organico', cor:'var(--st-venda)'},
  social   :{label:'Social',   cls:'social',   cor:'var(--st-nego)'},
  instagram:{label:'Instagram',cls:'instagram',cor:'#c13584'},
  whatsapp :{label:'WhatsApp', cls:'whatsapp', cor:'#25D366'},
  direto   :{label:'Direto',   cls:'direto',   cor:'#9aa4b2'}
};

/* ---------- helpers ---------- */
const brl=n=>n>0?n.toLocaleString('pt-BR',{style:'currency',currency:'BRL',maximumFractionDigits:0}):'—';
const brl2=n=>(n||0).toLocaleString('pt-BR',{style:'currency',currency:'BRL',maximumFractionDigits:0});
function esc(s){return (s==null?'':String(s)).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));}
function fmtData(iso){if(!iso)return '—';const d=new Date(iso);return d.toLocaleDateString('pt-BR',{day:'2-digit',month:'short'})+' · '+d.toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'});}
function isPago(o){return o==='pago';}
/* Ciclo de venda: dias entre a entrada do lead e o carimbo do fechamento.
   Null enquanto não fechou (e nas vendas anteriores à migration, que não têm
   carimbo — entram como "—" em vez de fingir um ciclo de 0 dias). */
function cicloDias(l){
  if(!l.vendaAt||!l.data)return null;
  const d=(new Date(l.vendaAt)-new Date(l.data))/86400000;
  return d>=0?Math.round(d):null;
}
function fmtCiclo(n){return n==null?'—':(n===0?'menos de 1 dia':n+(n===1?' dia':' dias'));}
function monthKey(iso){return (iso||'').slice(0,7);}
function monthLabel(key){if(key==='all')return 'Todos os meses';const[y,m]=key.split('-').map(Number);const s=new Date(y,m-1,1).toLocaleDateString('pt-BR',{month:'long',year:'numeric'});return s.charAt(0).toUpperCase()+s.slice(1).replace(' de ',' / ');}
/* Trunca em limite de PALAVRA. O .slice(0,60) cru cortava no meio e gerou
   'um-roteiro-exclusivo-pelo-melhor-da-escandinavia-com-acompan' no banco.
   O slug e URL publica permanente: cortar palavra ao meio fica pra sempre. */
function slugify(s){
  const base=(s||'').toString().toLowerCase().normalize('NFD')
    .replace(/[̀-ͯ]/g,'').replace(/[^a-z0-9]+/g,'-').replace(/(^-|-$)/g,'');
  if(base.length<=60)return base;
  const corte=base.slice(0,60);
  const ult=corte.lastIndexOf('-');
  return (ult>0?corte.slice(0,ult):corte).replace(/-$/,'');
}
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
    status:r.status||'semresposta',orc:Number(r.orcamento)||0,ven:Number(r.venda)||0,
    notas:Array.isArray(r.notas)?r.notas:[],vendaAt:r.venda_at||null,
    waId:r.wa_id||''};
}
function buildMonths(){
  const set=[...new Set(LEADS.map(l=>monthKey(l.data)).filter(Boolean))].sort().reverse();
  if(!set.length){const now=new Date();set.push(now.toISOString().slice(0,7));}
  // Preserva o mês escolhido ao recarregar (ex.: depois de cadastrar um lead
  // antigo); só cai no mais recente se o mês atual sumiu da lista.
  if(!F.month||(F.month!=='all'&&!set.includes(F.month)))F.month=set[0];
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
  else if(v==='clientes'){$('#view-clientes').classList.add('on');$('#top-title').innerHTML='Clientes<span>.</span>';if(typeof poRenderClientes==='function')poRenderClientes();}
  else if(v==='funil'){$('#view-funil').classList.add('on');$('#top-title').innerHTML='Funil<span>.</span>';if(typeof poRenderFunil==='function')poRenderFunil();}
  else{$('#view-roteiros').classList.add('on');$('#top-title').innerHTML='Roteiros<span>.</span>';renderRoteiros();}
});
$('#month-sel').onchange=e=>{F.month=e.target.value;syncSpendInput();renderLeads();if($('#view-reports').classList.contains('on'))renderReports();if($('#view-clientes').classList.contains('on'))poRenderClientes();if($('#view-funil').classList.contains('on'))poRenderFunil();};
$('#q').oninput=e=>{F.q=e.target.value;renderLeads();if($('#view-clientes').classList.contains('on'))poRenderClientes();if($('#view-funil').classList.contains('on'))poRenderFunil();};
$('#status-filter').onchange=e=>{F.status=e.target.value;renderLeads();if($('#view-clientes').classList.contains('on'))poRenderClientes();if($('#view-funil').classList.contains('on'))poRenderFunil();};
$$('#orig-filter .chip').forEach(c=>c.onclick=()=>{$$('#orig-filter .chip').forEach(x=>x.classList.remove('active'));c.classList.add('active');F.orig=c.dataset.orig;renderLeads();if($('#view-clientes').classList.contains('on'))poRenderClientes();if($('#view-funil').classList.contains('on'))poRenderFunil();});

/* ============================================================ LEADS */
function inMonth(l){return F.month==='all'||monthKey(l.data)===F.month;}
/* O recorte dos leads em pedacos, porque cada tela quer um recorte
   diferente e reusar o mesmo em todas dava resultado errado em duas delas.
   'usa' liga ou desliga cada filtro; a busca vale sempre. Os leads e o
   filtro entram por parametro para a funcao poder ser testada sem o
   painel. */
function poFiltraLeads(leads,f,usa){
  const q=(f.q||'').toLowerCase();
  return (leads||[]).filter(l=>{
    if(usa.mes&&!(f.month==='all'||monthKey(l.data)===f.month))return false;
    if(usa.origem&&!(f.orig==='todos'||l.origem===f.orig))return false;
    if(usa.status&&!(f.status==='todos'||l.status===f.status))return false;
    if(q&&!(l.nome+l.cidade+l.roteiro).toLowerCase().includes(q))return false;
    return true;
  });
}
/* A aba Leads e a tabela do mes: usa os quatro filtros. */
function filtered(){return poFiltraLeads(LEADS,F,{mes:true,origem:true,status:true});}
/* O Funil ignora o filtro de status: as colunas do quadro JA sao o status.
   Com ele ligado, mover um card para outra coluna fazia o card sumir do
   quadro inteiro, restando so o toast dizendo que tinha sido movido. */
function filtradosFunil(){return poFiltraLeads(LEADS,F,{mes:true,origem:true,status:false});}
/* A aba Clientes ignora o filtro de mes: base de clientes nao e recorte
   mensal. O seletor comeca no mes mais recente, entao a aba que existe
   para responder "quem ja viajou com a gente" abria mostrando so quem deu
   sinal neste mes, e os KPIs contavam so essas pessoas. */
function filtradasPessoas(){return poFiltraLeads(LEADS,F,{mes:false,origem:true,status:true});}
function origBadge(o){const x=ORIG[o]||ORIG.direto;return `<span class="orig ${x.cls}">${x.label}</span>`;}
function renderLeads(){
  const rows=filtered();const tb=$('#lead-rows');
  if(!rows.length)tb.innerHTML=`<tr><td colspan="11"><div class="empty">Nenhum lead com esses filtros.</div></td></tr>`;
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
    <td class="money ${l.ven?'':'zero'}">${brl(l.ven)}</td>
    <td class="mono ${cicloDias(l)==null?'zero':''}">${cicloDias(l)==null?'—':cicloDias(l)+'d'}</td></tr>`;}).join('');
  $$('#lead-rows tr[data-id]').forEach(tr=>tr.onclick=()=>openDrawer(tr.dataset.id));
  renderLeadKpis(rows);
}
function renderLeadKpis(rows){
  const total=rows.length,vendas=rows.filter(l=>l.status==='venda');
  const conv=total?Math.round(vendas.length/total*100):0;
  // Em vendas soma so quem esta em status 'venda': arrastar o card pra
  // fora de venda limpa o venda_at (regra de negocio), mas o valor (ven)
  // continua no lead de proposito (mover de volta por engano nao pode
  // destruir o numero). Sem o filtro por status o faturamento fica
  // inflado em silencio pra sempre.
  const valVen=vendas.reduce((s,l)=>s+l.ven,0),valOrc=rows.reduce((s,l)=>s+l.orc,0);
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
  $('#dr-ciclo').textContent=fmtCiclo(cicloDias(l));
  $('#dr-canal').textContent=(ORIG[l.origemAuto]||ORIG.direto).label;
  $('#dr-camp').textContent=l.camp;$('#dr-land').textContent=l.land;$('#dr-desc').textContent=l.msg;
  $('#e-status').value=l.status;$('#e-orig').value=l.origem;
  $('#e-orc').value=l.orc||'';$('#e-ven').value=l.ven||'';
  $('#n-txt').value='';renderNotes(l);
  // A conversa carrega junto com a gaveta. So l.waId, sem fallback pro
  // telefone: o enviar.php nao normaliza o telefone do site pro formato
  // E.164, entao ele nunca bate com um wa_id de verdade — usa-lo so
  // gastaria uma consulta que sempre volta vazia. O motor do WhatsApp
  // sempre grava wa_id no lead que ele cria; quem nao tem o campo e
  // porque nao veio pelo WhatsApp mesmo.
  $$('.dr-tab').forEach(t=>t.classList.toggle('on',t.dataset.tab==='dados'));
  $('#pane-dados').classList.add('on');$('#pane-conversa').classList.remove('on');
  if(typeof poRenderConversa==='function')poRenderConversa(l.waId);
  $('#scrim').classList.add('on');$('#drawer').classList.add('on');
}
$$('.dr-tab').forEach(t=>t.onclick=()=>{
  $$('.dr-tab').forEach(x=>x.classList.remove('on'));t.classList.add('on');
  $('#pane-dados').classList.toggle('on',t.dataset.tab==='dados');
  $('#pane-conversa').classList.toggle('on',t.dataset.tab==='conversa');
  // A rolagem para a ultima mensagem acontece aqui, e nao no render: a
  // gaveta abre sempre na aba Dados, entao na hora de renderizar a aba
  // Conversa ainda esta em display:none e um elemento sem caixa tem
  // scrollHeight 0 — a rolagem simplesmente nao acontecia e a conversa
  // abria na mensagem mais antiga das 300.
  if(t.dataset.tab==='conversa'&&typeof poRolaConversaFim==='function')poRolaConversaFim();
});

/* ---------- observações em linha temporal ---------- */
function fmtNota(ts){const d=new Date(ts);if(isNaN(d))return '—';
  return d.toLocaleDateString('pt-BR',{day:'2-digit',month:'2-digit',year:'numeric'})+' às '+d.toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'});}
function renderNotes(l){
  const box=$('#dr-notes'),ns=[...(l.notas||[])].sort((a,b)=>String(b.ts).localeCompare(String(a.ts)));
  if(!ns.length){box.innerHTML='<div class="notes-empty">Nenhuma anotação ainda. A data e a hora entram sozinhas.</div>';return;}
  box.innerHTML=ns.map(n=>`<div class="note" data-ts="${esc(n.ts)}">
    <div class="note-t">${fmtNota(n.ts)}</div>
    <div class="note-x">${esc(n.txt)}</div>
    <button type="button" class="note-rm" title="Apagar anotação">✕</button></div>`).join('');
}
/* Relê as notas antes de gravar: dois atendentes na mesma ficha não se sobrescrevem. */
async function saveNotes(l,notas){
  const {error}=await sb.from('po_leads').update({notas}).eq('id',l.id);
  if(error){toast('Erro ao salvar anotação: '+error.message,true);return false;}
  l.notas=notas;renderNotes(l);return true;
}
$('#n-add').onclick=async()=>{
  const l=LEADS.find(x=>x.id===openId);if(!l)return;
  const txt=$('#n-txt').value.trim();
  if(!txt){toast('Escreva a anotação antes de adicionar.',true);return;}
  const btn=$('#n-add');btn.disabled=true;
  const {data,error}=await sb.from('po_leads').select('notas').eq('id',l.id).single();
  if(error){btn.disabled=false;toast('Erro ao ler anotações: '+error.message,true);return;}
  const atuais=Array.isArray(data&&data.notas)?data.notas:[];
  const ok=await saveNotes(l,[...atuais,{ts:new Date().toISOString(),txt}]);
  btn.disabled=false;
  if(ok){$('#n-txt').value='';toast('Anotação adicionada.');}
};
$('#dr-notes').addEventListener('click',async e=>{
  if(!e.target.classList.contains('note-rm'))return;
  const l=LEADS.find(x=>x.id===openId);if(!l)return;
  const ts=e.target.closest('.note').dataset.ts;
  if(!confirm('Apagar esta anotação?'))return;
  if(await saveNotes(l,(l.notas||[]).filter(n=>String(n.ts)!==ts)))toast('Anotação apagada.');
});
function closeDrawer(){$('#scrim').classList.remove('on');$('#drawer').classList.remove('on');openId=null;}
$('#dr-close').onclick=closeDrawer;$('#dr-cancel').onclick=closeDrawer;
$('#fi-close').onclick=()=>poFechaFicha();
$('#scrim').onclick=()=>{closeDrawer();closeRot();closeNewLead();poFechaFicha();};
/* O que a gaveta grava no banco quando a dona clica em Salvar. Mora fora
   do handler porque e regra de negocio, nao de interface, e porque e a
   unica parte deste arquivo que consegue perder dado em silencio.

   "Preencher a venda conclui o lead" continua valendo, mas so quando o
   valor foi preenchido AGORA. O quadro do funil tira o card de "Contrato
   assinado" mantendo o valor de proposito (um engano de arrasto nao pode
   destruir o numero); sem a comparacao com o valor anterior, abrir esse
   lead depois para escrever uma anotacao e salvar empurrava o status de
   volta para venda e recarimbava venda_at com a data de hoje, apagando a
   data real do fechamento, que alimenta o ciclo de venda dos relatorios.

   venda_at que ja existe nunca e reescrito: mesma regra do poPatchStatus
   do funil. */
function poPatchGaveta(campos,lead,agora){
  const ven=Number(campos.ven)||0;
  const venMudou=ven!==(Number(lead.ven)||0);
  const status=(ven>0&&venMudou)?'venda':campos.status;
  const vendaAt=status==='venda'?(lead.vendaAt||agora):null;
  return {status,origem_manual:campos.origem,orcamento:Number(campos.orc)||0,venda:ven,venda_at:vendaAt};
}
$('#dr-save').onclick=async()=>{
  const l=LEADS.find(x=>x.id===openId);if(!l)return;
  const btn=$('#dr-save');btn.disabled=true;btn.textContent='Salvando…';
  const patch=poPatchGaveta({ven:$('#e-ven').value,orc:$('#e-orc').value,status:$('#e-status').value,origem:$('#e-orig').value},l,new Date().toISOString());
  const {error}=await sb.from('po_leads').update(patch).eq('id',l.id);
  btn.disabled=false;btn.textContent='Salvar';
  if(error){toast('Erro ao salvar: '+error.message,true);return;}
  l.status=patch.status;l.origem=patch.origem_manual;l.orc=patch.orcamento;l.ven=patch.venda;l.vendaAt=patch.venda_at;
  $('#e-status').value=l.status;$('#dr-ciclo').textContent=fmtCiclo(cicloDias(l));
  const f=$('#save-flash');f.classList.add('on');setTimeout(()=>f.classList.remove('on'),1600);
  renderLeads();toast('Lead atualizado.');
};

/* ============================================================ NOVO LEAD (manual) */
function pad2(n){return String(n).padStart(2,'0');}
// datetime-local trabalha em hora local, sem fuso. Estes dois helpers convertem
// nos dois sentidos para o lead não nascer 3h deslocado.
function toLocalInput(d){return d.getFullYear()+'-'+pad2(d.getMonth()+1)+'-'+pad2(d.getDate())+'T'+pad2(d.getHours())+':'+pad2(d.getMinutes());}
function fromLocalInput(v){const d=new Date(v);return isNaN(d)?new Date():d;}
function openNewLead(){
  $('#n-data').value=toLocalInput(new Date());
  ['n-nome','n-tel','n-email','n-cidade','n-viajantes','n-roteiro','n-msg'].forEach(id=>$('#'+id).value='');
  $('#n-orig').value='whatsapp';
  $('#n-roteiros').innerHTML=ROTEIROS.map(r=>`<option value="${esc(r.titulo||'')}">`).join('');
  $('#scrim').classList.add('on');$('#ndrawer').classList.add('on');$('#ndrawer').scrollTop=0;
  $('#n-nome').focus();
}
function closeNewLead(){$('#ndrawer').classList.remove('on');if(!$('#drawer').classList.contains('on')&&!$('#rdrawer').classList.contains('on'))$('#scrim').classList.remove('on');}
$('#lead-new').onclick=openNewLead;
$('#nd-close').onclick=closeNewLead;$('#nd-cancel').onclick=closeNewLead;
$('#nd-save').onclick=async()=>{
  const nome=$('#n-nome').value.trim(),tel=$('#n-tel').value.trim(),email=$('#n-email').value.trim();
  if(!nome){toast('Informe ao menos o nome.',true);return;}
  if(!tel&&!email){toast('Informe um contato: telefone ou e-mail.',true);return;}
  const btn=$('#nd-save');btn.disabled=true;btn.textContent='Cadastrando…';
  const row={
    created_at:fromLocalInput($('#n-data').value).toISOString(),
    nome,telefone:tel,email,cidade:$('#n-cidade').value.trim(),
    viajantes:$('#n-viajantes').value.trim(),roteiro:$('#n-roteiro').value.trim(),
    mensagem:$('#n-msg').value.trim(),
    origem:'Cadastro manual',origem_manual:$('#n-orig').value,
    status:'atendimento',notas:[]
  };
  const {error}=await sb.from('po_leads').insert(row);
  btn.disabled=false;btn.textContent='Cadastrar lead';
  if(error){toast('Erro ao cadastrar: '+error.message,true);return;}
  closeNewLead();
  F.month=monthKey(row.created_at); // pula pro mês do lead — senão um lead antigo some do filtro
  await loadData();
  toast('Lead cadastrado.');
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
function rowsForReports(){return LEADS.filter(inMonth).filter(l=>F.orig==='todos'||l.origem===F.orig);}
function renderReports(){
  const rows=rowsForReports();const total=rows.length;
  const vendas=rows.filter(l=>l.status==='venda');
  // Mesma regra do KPI da aba Leads: so soma ven de quem esta em status
  // 'venda'. O ven do lead nao e zerado ao sair de venda (o poMoveLead do
  // funil so limpa o venda_at), entao sem este filtro o faturamento e o
  // ROAS ficariam inflados pra sempre por um lead que saiu de venda.
  const valVen=vendas.reduce((s,l)=>s+l.ven,0),valOrc=rows.reduce((s,l)=>s+l.orc,0);
  const conv=total?(vendas.length/total*100):0,ticket=vendas.length?valVen/vendas.length:0;
  const sp=curSpend(),venPago=vendas.filter(l=>isPago(l.origem)).reduce((s,l)=>s+l.ven,0),leadsPago=rows.filter(l=>isPago(l.origem)).length;
  const roas=sp>0?venPago/sp:0,roi=sp>0?((venPago-sp)/sp*100):0,cpl=leadsPago>0?sp/leadsPago:0;
  // Ciclo: leads que ENTRARAM no período e já fecharam. Responde "quanto demorei
  // para fechar os leads deste mês" — não "o que fechou neste mês".
  const ciclos=rows.map(l=>({nome:l.nome,dias:cicloDias(l)})).filter(c=>c.dias!=null).sort((a,b)=>a.dias-b.dias);
  const cicloMed=ciclos.length?ciclos.reduce((s,c)=>s+c.dias,0)/ciclos.length:null;
  $('#rep-kpis').innerHTML=`
    <div class="kpi"><div class="kpi-l">Total de leads</div><div class="kpi-n">${total}</div><div class="kpi-sub">${leadsPago} via anúncios</div></div>
    <div class="kpi k-green"><div class="kpi-l">Taxa de conversão</div><div class="kpi-n">${conv.toFixed(0)}<small>%</small></div><div class="kpi-sub">${vendas.length} de ${total} leads</div></div>
    <div class="kpi k-orange"><div class="kpi-l">ROAS (pago)</div><div class="kpi-n">${sp>0?roas.toFixed(1):'—'}<small>${sp>0?'x':''}</small></div><div class="kpi-sub">retorno s/ anúncios</div></div>
    <div class="kpi ${roi>=0?'k-green':''}"><div class="kpi-l">ROI (pago)</div><div class="kpi-n">${sp>0?(roi>=0?'+':'')+roi.toFixed(0):'—'}<small>${sp>0?'%':''}</small></div><div class="kpi-sub">${brl2(sp)} investido</div></div>
    <div class="kpi k-green"><div class="kpi-l">Ciclo de venda médio</div><div class="kpi-n">${cicloMed==null?'—':cicloMed.toFixed(0)}<small>${cicloMed==null?'':' dias'}</small></div><div class="kpi-sub">${ciclos.length?'sobre '+ciclos.length+' venda'+(ciclos.length>1?'s':''):'nenhuma venda fechada'}</div></div>`;
  renderCiclos(ciclos);
  const byOrig={};Object.keys(ORIG).forEach(k=>byOrig[k]=0);rows.forEach(l=>{byOrig[l.origem]=(byOrig[l.origem]||0)+1;});
  const corOrig=k=>(ORIG[k]||ORIG.direto).cor;
  let acc=0,segs=[];Object.entries(byOrig).forEach(([k,v])=>{if(v&&total){const pct=v/total*100;segs.push(`${corOrig(k)} ${acc}% ${acc+pct}%`);acc+=pct;}});
  $('#donut').style.background=`conic-gradient(${segs.join(',')||'#e4e7eb 0 100%'})`;
  $('#donut').innerHTML=`<div class="donut-c"><b>${total}</b><span>leads</span></div>`;
  $('#donut-legend').innerHTML=Object.entries(byOrig).filter(([,v])=>v).map(([k,v])=>`<div class="legend-row"><span class="legend-dot" style="background:${corOrig(k)}"></span>${(ORIG[k]||ORIG.direto).label}<span class="pct">${total?(v/total*100).toFixed(0):0}%</span><span class="n">${v}</span></div>`).join('')||'<div class="card-sub">Sem dados no período.</div>';
  const order=['novo','atendimento','negociacao','venda','semresposta','perdido'];
  const byStatus={};order.forEach(s=>byStatus[s]=rows.filter(l=>l.status===s).length);
  const maxS=Math.max(1,...Object.values(byStatus));
  const scolor={novo:'var(--st-novo)',atendimento:'var(--st-aberto)',negociacao:'var(--st-nego)',venda:'var(--st-venda)',semresposta:'var(--st-sem)',perdido:'var(--st-perdido)'};
  $('#status-bars').innerHTML=order.map(s=>`<div class="bar-row"><div class="bar-lbl">${STATUS[s].label}</div><div class="bar-track"><div class="bar-fill" style="width:${byStatus[s]/maxS*100}%;background:${scolor[s]}"></div></div><div class="bar-n">${byStatus[s]}</div></div>`).join('');
  $('#vs-orc').textContent=brl2(valOrc);$('#vs-ven').textContent=brl2(valVen);
  const close=valOrc>0?valVen/valOrc*100:0;$('#close-bar').style.width=Math.min(100,close)+'%';$('#close-n').textContent=close.toFixed(0)+'%';
  $('#m-roas').textContent=sp>0?roas.toFixed(1)+'x':'—';
  $('#m-roi').textContent=sp>0?(roi>=0?'+':'')+roi.toFixed(0)+'%':'—';$('#m-roi').style.color=roi>=0?'var(--st-venda)':'var(--st-perdido)';
  $('#m-cpl').textContent=cpl>0?brl2(Math.round(cpl)):'—';$('#m-vpago').textContent=brl2(venPago);$('#m-ticket').textContent=brl2(Math.round(ticket));
  renderTimeline(rows);
}
/* Ciclo por lead: barra proporcional ao mais lento, do mais rápido ao mais lento. */
function renderCiclos(ciclos){
  const box=$('#cycle-list'),foot=$('#cycle-foot');
  if(!ciclos.length){
    box.innerHTML='<div class="card-sub">Nenhuma venda fechada no período. O ciclo começa a contar quando o lead entra e fecha quando você preenche o valor da venda.</div>';
    foot.innerHTML='';return;
  }
  const max=Math.max(1,...ciclos.map(c=>c.dias));
  box.innerHTML=ciclos.map(c=>`<div class="bar-row">
    <div class="bar-lbl" title="${esc(c.nome)}">${esc(c.nome)}</div>
    <div class="bar-track"><div class="bar-fill" style="width:${Math.max(4,c.dias/max*100)}%;background:var(--st-venda)"></div></div>
    <div class="bar-n">${c.dias}d</div></div>`).join('');
  foot.innerHTML=`<span>Mais rápido <b>${fmtCiclo(ciclos[0].dias)}</b></span><span>Mais lento <b>${fmtCiclo(ciclos[ciclos.length-1].dias)}</b></span>`;
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
function pubHref(slug){return '../roteiros/'+encodeURIComponent(slug);}
$('#rot-refresh').onclick=async()=>{await reloadRoteiros();toast('Lista de roteiros atualizada. O site já reflete os ativos.');};
$('#rdr-view').onclick=()=>{if(!editRot||!editRot.id){toast('Salve o roteiro primeiro para visualizar.',true);return;}window.open(pubHref(editRot.slug),'_blank');};
function renderRoteiros(){
  const g=$('#rot-grid');
  if(!ROTEIROS.length){g.innerHTML=`<div class="empty" style="grid-column:1/-1">Nenhum roteiro. Clique em “Novo roteiro” ou importe um arquivo.</div>`;return;}
  g.innerHTML=ROTEIROS.map(r=>`<div class="rcard" data-id="${r.id}">
    <div class="rcard__img" style="background-image:url('${esc(r.capa_url||'')}')">${r.ativo?'':'<span class="rcard__off">Oculto</span>'}</div>
    <div class="rcard__b">
      <div class="rcard__t">${esc(r.titulo||'(sem título)')}</div>
      <div class="rcard__s">${esc(r.data_label||r.periodo||'—')} · ${esc(r.local_label||'')}</div>
      <div class="rcard__foot"><button class="ver" data-id="${r.id}">Ver ↗</button><button class="edit" data-id="${r.id}">Editar</button><button class="del" data-id="${r.id}">Excluir</button></div>
    </div></div>`).join('');
  $$('#rot-grid .ver').forEach(b=>b.onclick=e=>{e.stopPropagation();const r=ROTEIROS.find(x=>String(x.id)===b.dataset.id);window.open(pubHref(r.slug),'_blank');});
  $$('#rot-grid .edit').forEach(b=>b.onclick=e=>{e.stopPropagation();openRot(ROTEIROS.find(x=>String(x.id)===b.dataset.id));});
  $$('#rot-grid .del').forEach(b=>b.onclick=async e=>{e.stopPropagation();const r=ROTEIROS.find(x=>String(x.id)===b.dataset.id);if(!confirm('Excluir o roteiro “'+(r.titulo||'')+'”?'))return;const {error}=await sb.from('po_roteiros').delete().eq('id',r.id);if(error){toast('Erro ao excluir: '+error.message,true);return;}ROTEIROS=ROTEIROS.filter(x=>x.id!==r.id);renderRoteiros();toast('Roteiro excluído.');});
}
$('#rot-new').onclick=()=>openRot(null);

/* ---------- editor de roteiro ---------- */
function repItemDia(d={}){return `<div class="rep-item"><button type="button" class="drag" title="Arraste para reordenar os dias">⠿</button><button type="button" class="rm">✕</button>
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
  <div class="field"><label>Slug (URL), vira o endereço da página, ex: /roteiros/chile-atacama</label><input id="r-slug" value="${esc(r.slug||'')}" placeholder="gerado do título se vazio"><small class="hint">Use o destino, sem data nem tema da edição. Repetir o slug de uma edição anterior é o certo: a página herda o histórico no Google.</small></div>
  <div class="field"><label>Subtítulo</label><input id="r-subtitulo" value="${esc(r.subtitulo||'')}"></div>
  <div class="field"><label>Descrição curta</label><textarea id="r-desc">${esc(r.descricao_curta||'')}</textarea></div>
  <div class="field-3">
    <div class="field"><label>Dias</label><input id="r-dias" type="number" value="${r.dias||''}"></div>
    <div class="field"><label>Noites</label><input id="r-noites" type="number" value="${r.noites||''}"></div>
    <div class="field"><label>Ordem</label><input id="r-ordem" type="number" value="${r.ordem||0}"></div>
  </div>
  <div class="field"><label>Badge (ex: 8 dias)</label><input id="r-badge" value="${esc(r.badge||'')}"></div>
  <div class="field"><label>Período (texto) — usado no card e no hero</label><input id="r-periodo" value="${esc(r.periodo||'')}"></div>
  <div class="field"><label>Ativo no site</label><select id="r-ativo"><option value="1" ${r.ativo!==false?'selected':''}>Sim, publicado</option><option value="0" ${r.ativo===false?'selected':''}>Não (oculto)</option></select></div>

  <div class="dr-section-l">Capa</div>
  <div class="field">
    <div class="gal-thumbs" id="r-capa-wrap">${r.capa_url?`<div class="gal-thumb" style="background-image:url('${esc(r.capa_url)}')" data-capa><button type="button">✕</button></div>`:''}</div>
    <input type="hidden" id="r-capa" value="${esc(r.capa_url||'')}">
    <input type="file" id="r-capa-file" accept="image/*" hidden>
    <button type="button" class="rep-add" id="r-capa-btn">Enviar capa</button>
    <span class="uploading" id="r-capa-up"></span>
    <p class="vid-hint">Imagem parada. Aparece no carrossel da home e é o fundo do topo enquanto o vídeo capa não carrega.</p>
  </div>

  <div class="dr-section-l">Vídeo capa (topo da página)</div>
  <div class="field">
    <div id="r-vcapa-wrap">${r.video_capa_url?vidPreview(r.video_capa_url):''}</div>
    <input type="hidden" id="r-vcapa" value="${esc(r.video_capa_url||'')}">
    <input type="file" id="r-vcapa-file" accept="video/*" hidden>
    <button type="button" class="rep-add" id="r-vcapa-btn">Enviar vídeo capa</button>
    <span class="uploading" id="r-vcapa-up"></span>
    <p class="vid-hint">Preenche o topo da página do roteiro, no celular e no computador. Toca sozinho, em repetição, sem som e sem controles. Sem vídeo, o topo mostra a capa parada.
      Envie até 15 segundos: acima disso o painel corta e o trecho cortado entra em repetição. O som é removido (o vídeo toca mudo).</p>
  </div>

  <div class="dr-section-l">Vídeo do Instagram</div>
  <div class="field">
    <div id="r-vid-wrap">${r.video_insta_url?vidPreview(r.video_insta_url):''}</div>
    <input type="hidden" id="r-vid" value="${esc(r.video_insta_url||'')}">
    <input type="file" id="r-vid-file" accept="video/*" hidden>
    <button type="button" class="rep-add" id="r-vid-btn">Enviar vídeo</button>
    <span class="uploading" id="r-vid-up"></span>
    <p class="vid-hint">O reels aparece na seção "Por que viajar" da página do roteiro. Sem vídeo, mostra a capa.
      Pode enviar o arquivo original, de qualquer tamanho: o painel comprime antes de enviar.</p>
  </div>

  <div class="dr-section-l">Roteiro em PDF (download)</div>
  <div class="field">
    <div id="r-pdf-wrap">${r.pdf_url?pdfPreview(r.pdf_url):''}</div>
    <input type="hidden" id="r-pdf" value="${esc(r.pdf_url||'')}">
    <input type="file" id="r-pdf-file" accept="application/pdf,.pdf" hidden>
    <button type="button" class="rep-add" id="r-pdf-btn">Enviar PDF</button>
    <span class="uploading" id="r-pdf-up"></span>
    <p class="vid-hint">Aparece na faixa "Baixar roteiro em PDF" da página. Sem PDF, a faixa não aparece.
      Envie o PDF que você exporta do Word, de qualquer tamanho. Abre em nova aba para o visitante.</p>
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
// Arrastar-para-reordenar os .rep-item (via alça .drag). collectRoteiro lê a ordem do DOM.
function repReorder(rep){
  if(!rep||rep._reorder)return;rep._reorder=true;
  let drag=null;
  rep.addEventListener('mousedown',e=>{const it=e.target.closest('.rep-item');if(e.target.closest('.drag')&&it){it.draggable=true;document.addEventListener('mouseup',()=>{it.draggable=false;},{once:true});}});
  rep.addEventListener('dragstart',e=>{const it=e.target.closest('.rep-item');if(!it||!it.draggable)return;drag=it;it.classList.add('dragging');e.dataTransfer.effectAllowed='move';});
  rep.addEventListener('dragover',e=>{if(!drag)return;e.preventDefault();const after=repAfter(rep,e.clientY);if(after==null)rep.appendChild(drag);else rep.insertBefore(drag,after);});
  rep.addEventListener('dragend',()=>{if(drag){drag.classList.remove('dragging');drag.draggable=false;drag=null;}});
}
function repAfter(rep,y){
  const els=[...rep.querySelectorAll('.rep-item:not(.dragging)')];
  let best=null,bestOff=-Infinity;
  for(const el of els){const b=el.getBoundingClientRect();const off=y-b.top-b.height/2;if(off<0&&off>bestOff){bestOff=off;best=el;}}
  return best;
}
function wireRoteiroForm(){
  // remover linha repetível
  $('#rdr-body').addEventListener('click',e=>{if(e.target.classList.contains('rm'))e.target.closest('.rep-item').remove();});
  $('#r-dias-add').onclick=()=>$('#r-dias-rep').insertAdjacentHTML('beforeend',repItemDia());
  $('#r-val-add').onclick=()=>$('#r-val-rep').insertAdjacentHTML('beforeend',repItemVal());
  repReorder($('#r-dias-rep')); // arrastar cards de dia p/ corrigir a ordem
  // capa upload
  $('#r-capa-btn').onclick=()=>$('#r-capa-file').click();
  $('#r-capa-file').onchange=async e=>{const f=e.target.files[0];if(!f)return;$('#r-capa-up').textContent='Enviando…';const url=await uploadImg(f,curSlug());$('#r-capa-up').textContent='';if(!url)return;$('#r-capa').value=url;$('#r-capa-wrap').innerHTML=`<div class="gal-thumb" style="background-image:url('${url}')" data-capa><button type="button">✕</button></div>`;};
  // galeria upload
  $('#r-gal-btn').onclick=()=>$('#r-gal-file').click();
  $('#r-gal-file').onchange=async e=>{const fs=[...e.target.files];if(!fs.length)return;$('#r-gal-up').textContent='Enviando '+fs.length+'…';for(const f of fs){const url=await uploadImg(f,curSlug());if(url)$('#r-gal-wrap').insertAdjacentHTML('beforeend',`<div class="gal-thumb" style="background-image:url('${url}')" data-url="${url}"><button type="button">✕</button></div>`);}$('#r-gal-up').textContent='';};
  // remover thumb (capa/galeria)
  $('#rdr-body').addEventListener('click',e=>{if(e.target.tagName==='BUTTON'&&e.target.closest('.gal-thumb')){const t=e.target.closest('.gal-thumb');if(t.hasAttribute('data-capa'))$('#r-capa').value='';t.remove();}});
  // vídeos do roteiro (capa = hero · insta = reels do "por que viajar")
  ['capa','insta'].forEach(tipo=>{
    const el=VID[tipo];
    $(el.btn).onclick=()=>$(el.file).click();
    $(el.file).onchange=async e=>{const f=e.target.files[0];e.target.value='';if(f)await sendVideo(f,tipo);};
    $(el.wrap).addEventListener('click',async e=>{
      if(!e.target.classList.contains('vid-rm'))return;
      if(!confirm('Remover este vídeo do roteiro?'))return;
      const {data:{session}}=await sb.auth.getSession();
      await POVideo.remove(curSlug(),session&&session.access_token?session.access_token:'',tipo);
      $(el.inp).value='';$(el.wrap).innerHTML='';
      toast('Vídeo removido. Salve o roteiro para confirmar.');
    });
  });
  // PDF do roteiro (download na página)
  $('#r-pdf-btn').onclick=()=>$('#r-pdf-file').click();
  $('#r-pdf-file').onchange=async e=>{const f=e.target.files[0];e.target.value='';if(f)await sendPdf(f);};
  $('#r-pdf-wrap').addEventListener('click',async e=>{
    if(!e.target.classList.contains('vid-rm'))return;
    if(!confirm('Remover o PDF deste roteiro?'))return;
    const {data:{session}}=await sb.auth.getSession();
    await POPdf.remove(curSlug(),session&&session.access_token?session.access_token:'');
    $('#r-pdf').value='';$('#r-pdf-wrap').innerHTML='';
    toast('PDF removido. Salve o roteiro para confirmar.');
  });
}
function pdfPreview(url){return `<div class="pdf-prev"><a href="${esc(url)}" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg> Ver PDF atual</a><button type="button" class="vid-rm" title="Remover PDF">✕</button></div>`;}
// PDF não passa por compressão (diferente do vídeo): sobe direto, em fatias.
async function sendPdf(file){
  const st=$('#r-pdf-up'),btn=$('#r-pdf-btn'),slug=curSlug();
  if(!slug||slug==='roteiro'){toast('Defina o título (ou o slug) do roteiro antes de enviar o PDF.',true);return;}
  if(file.type&&file.type!=='application/pdf'&&!/\.pdf$/i.test(file.name)){toast('Envie um arquivo PDF.',true);return;}
  const {data:{session}}=await sb.auth.getSession();
  const token=session&&session.access_token?session.access_token:'';
  if(!token){toast('Sessão expirada. Faça login novamente.',true);return;}
  btn.disabled=true;
  try{
    st.textContent='Enviando… 0%';
    const url=await POPdf.upload(file,slug,token,p=>{st.textContent='Enviando… '+Math.round(p*100)+'%';});
    $('#r-pdf').value=url;$('#r-pdf-wrap').innerHTML=pdfPreview(url);st.textContent='';
    toast('PDF enviado ('+(file.size/1048576).toFixed(1)+' MB). Salve o roteiro para publicar.');
  }catch(err){st.textContent='';toast('Erro no PDF: '+((err&&err.message)||err),true);}
  finally{btn.disabled=false;}
}
// Sobe o PDF em fatias de 5 MB para o upload-pdf.php (mesmo motivo do vídeo:
// contorna o upload_max_filesize do cPanel). Sem re-encode: PDF vai como está.
const POPdf=(()=>{
  const CHUNK=5*1024*1024;
  async function upload(file,slug,token,onProgress){
    const uid=Array.from(crypto.getRandomValues(new Uint8Array(8))).map(b=>b.toString(16).padStart(2,'0')).join('');
    let url='';
    for(let offset=0;offset<file.size;offset+=CHUNK){
      const fim=Math.min(offset+CHUNK,file.size),last=fim>=file.size;
      const fd=new FormData();
      fd.append('sb_token',token);fd.append('slug',slug);fd.append('uid',uid);
      fd.append('offset',String(offset));fd.append('last',last?'1':'0');
      fd.append('chunk',file.slice(offset,fim),'chunk');
      const resp=await fetch('../upload-pdf.php',{method:'POST',body:fd});
      const txt=await resp.text();let j;try{j=JSON.parse(txt);}catch(_){j=null;}
      if(!resp.ok||!j||!j.ok)throw new Error((j&&j.error)||('Falha no envio ('+resp.status+').'));
      if(onProgress)onProgress(fim/file.size);
      if(last)url=j.url;
    }
    return url;
  }
  async function remove(slug,token){
    const fd=new FormData();fd.append('sb_token',token);fd.append('slug',slug);fd.append('action','delete');
    await fetch('../upload-pdf.php',{method:'POST',body:fd});
  }
  return {upload,remove};
})();
// Os dois vídeos do roteiro usam o mesmo fluxo; só mudam os campos da tela
// e o perfil de compressão (ver painel/video-encode.js).
const VID={
  capa: {inp:'#r-vcapa',wrap:'#r-vcapa-wrap',btn:'#r-vcapa-btn',up:'#r-vcapa-up',file:'#r-vcapa-file'},
  insta:{inp:'#r-vid',  wrap:'#r-vid-wrap',  btn:'#r-vid-btn',  up:'#r-vid-up',  file:'#r-vid-file'},
};
function vidPreview(url){return `<div class="vid-prev"><video src="${esc(url)}" controls preload="metadata" playsinline></video><button type="button" class="vid-rm" title="Remover vídeo">✕</button></div>`;}
// Comprime o vídeo no navegador e sobe em fatias para o upload-video.php.
// Fica na ereHost (não no Supabase Storage) porque o projeto Supabase é
// compartilhado com NOX/hd360 e o egress do free tier é do projeto inteiro.
async function sendVideo(file,tipo){
  const el=VID[tipo]||VID.insta;
  const btn=$(el.btn),st=$(el.up),slug=curSlug();
  if(!slug||slug==='roteiro'){toast('Defina o título (ou o slug) do roteiro antes de enviar o vídeo.',true);return;}
  const {data:{session}}=await sb.auth.getSession();
  const token=session&&session.access_token?session.access_token:'';
  if(!token){toast('Sessão expirada. Faça login novamente.',true);return;}

  // Sem WebCodecs o arquivo iria cru. Para o reels isso é só "mais pesado" —
  // ele só baixa se alguém clicar no play. Para a capa seria grave: ela toca
  // sozinha para TODO visitante, então um arquivo bruto viraria peso em cima
  // de cada acesso, com áudio e sem o corte. Melhor recusar que publicar isso.
  if(!POVideo.supported()&&tipo==='capa'){
    toast('Este navegador não comprime vídeo, e o vídeo capa carrega para todos os visitantes. Envie-o pelo Chrome ou Edge.',true);
    return;
  }

  btn.disabled=true;
  try{
    let blob=file;
    if(POVideo.supported()){
      st.textContent='Comprimindo… 0%';
      const menor=await POVideo.encode(file,p=>{st.textContent='Comprimindo… '+Math.round(p*100)+'%';},tipo);
      // A guarda de tamanho vale só para o reels: se o arquivo já era mais leve
      // que o nosso alvo, re-encodar só engordaria e perderia qualidade à toa.
      // Na capa o re-encode NÃO é só compressão — ele tira o áudio e corta em
      // 15 s. Cair no original aqui publicaria um vídeo com som e sem corte.
      blob=(tipo==='capa')?menor:(menor.size<file.size?menor:file);
    }else{
      toast('Este navegador não comprime vídeo; o arquivo vai como está. Use o Chrome para um vídeo mais leve.',true);
    }
    st.textContent='Enviando… 0%';
    const url=await POVideo.upload(blob,slug,token,p=>{st.textContent='Enviando… '+Math.round(p*100)+'%';},tipo);
    $(el.inp).value=url;$(el.wrap).innerHTML=vidPreview(url);st.textContent='';
    toast('Vídeo enviado ('+(blob.size/1048576).toFixed(1)+' MB). Salve o roteiro para publicar.');
  }catch(err){
    st.textContent='';toast('Erro no vídeo: '+((err&&err.message)||err),true);
  }finally{btn.disabled=false;}
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
    // data_label/local_label saíram do formulário: o card e o hero caem nos
    // fallbacks (periodo/subtitulo). Não vão no patch para um update não apagar
    // o que os roteiros antigos já têm gravado.
    ordem:Number($('#r-ordem').value)||0,badge:$('#r-badge').value.trim(),periodo:$('#r-periodo').value.trim(),
    video_capa_url:$('#r-vcapa').value.trim()||null,video_insta_url:$('#r-vid').value.trim()||null,
    pdf_url:$('#r-pdf').value.trim()||null,
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
  set('r-subtitulo',p.subtitulo);
  set('r-dias',p.dias);set('r-noites',p.noites);
  if(p.dias)set('r-badge',p.dias+' dias');
  set('r-desc',p.descricao_curta);
  if(p.inclui&&p.inclui.length)$('#r-inclui').value=p.inclui.join('\n');
  if(p.nao_inclui&&p.nao_inclui.length)$('#r-naoinclui').value=p.nao_inclui.join('\n');
  if(p.hoteis&&p.hoteis.length)$('#r-hoteis').value=arrToLines(p.hoteis);
  if(p.roteiro_dias&&p.roteiro_dias.length){
    const dias=[...p.roteiro_dias].sort((a,b)=>(a.n||0)-(b.n||0));
    $('#r-dias-rep').innerHTML=dias.map(repItemDia).join('');
  }
  if(p.valores&&p.valores.length)$('#r-val-rep').innerHTML=p.valores.map(repItemVal).join('');
  if(p.galeria&&p.galeria.length)$('#r-gal-wrap').innerHTML=p.galeria.map(u=>`<div class="gal-thumb" style="background-image:url('${u}')" data-url="${u}"><button type="button">✕</button></div>`).join('');
  // Capa é SEMPRE manual — imagens importadas ficam só na galeria (não viram capa).
}
/* ---- parser comum (parágrafos → estrutura) ---- */
const RX_WEEKDAY=/(segunda|ter[çc]a|quarta|quinta|sexta|s[áa]bado|domingo)(\s*[-–]?\s*feira)?/i;
const RX_LONGDATE=/\d{1,2}\s*de\s*[a-zà-ú]+/i;
// Linha de cidade/destino: curta, toda em maiúsculas, não começa com dígito
// (ex.: "BRASIL", "COPENHAGUE – CASTELO DE KRONBORG"). Distingue do texto descritivo.
function isCityLine(s){return s.length<=90 && /[A-ZÀ-Ý]/.test(s) && s===s.toUpperCase() && !/^\d/.test(s);}
// Detecta refeições explicitamente incluídas no texto do dia.
function dayMeals(desc){
  const m=[];
  if(/caf[ée]\s+da\s+manh/i.test(desc))m.push('Café da manhã');
  if(/almo[çc]o\s+inclu|inclu[ií]d[ao][^.]{0,25}almo[çc]o/i.test(desc))m.push('Almoço');
  if(/jantar\s+inclu|inclu[ií]d[ao][^.]{0,25}jantar/i.test(desc))m.push('Jantar');
  return [...new Set(m)].join(', ');
}
function parseParas(paras){
  const out={titulo:'',periodo:'',dias:null,noites:null,roteiro_dias:[],inclui:[],nao_inclui:[],hoteis:[],descricao_curta:''};
  let section=null, expectCity=false;
  paras.forEach(line=>{
    const L=(line||'').replace(/\s+/g,' ').trim();if(!L)return;const up=L.toUpperCase();
    if(!out.titulo){out.titulo=L;return;}
    if(!out.periodo&&/\d+\s*dias?/i.test(L)&&/\d/.test(L)){out.periodo=L;const md=L.match(/(\d+)\s*dias?/i);if(md)out.dias=+md[1];const mn=L.match(/(\d+)\s*noites?/i);if(mn)out.noites=+mn[1];return;}
    if(/HOT[ÉE]IS|HOTELARIA/.test(up)){section='hoteis';expectCity=false;return;}
    if(/N[ÃA]O\s+INCLUI/.test(up)){section='nao';expectCity=false;return;}
    if(/PACOTE\s+INCLUI|^INCLUI\b|EST[ÃA]O\s+INCLU/.test(up)){section='inc';expectCity=false;return;}
    if(/VALORES|VALOR\s+TOTAL|FORMA\s+DE\s+PAGAMENTO/.test(up)){section='val';expectCity=false;return;}
    // Cabeçalho de dia — aceita "Dia N – data – dia da semana" (formato da cliente)
    // e o antigo "N[º] DIA – Cidade : descrição".
    const dm=L.match(/^Dia\s*(\d{1,3})\b\s*(.*)$/i) || L.match(/^(\d{1,3})\s*[ºo]?\s*DIA\b\s*(.*)$/i);
    if(dm){
      let rest=dm[2].replace(/^[–—\-:.\s]+/,'');
      const ci=rest.indexOf(':');
      let header=rest, desc='';
      if(ci>=0){header=rest.slice(0,ci);desc=rest.slice(ci+1).trim();}
      const hp=header.split(/\s*[–—]\s*|\s+-\s+/).map(s=>s.trim()).filter(Boolean);
      const dataP=(hp.find(x=>/\d{1,2}\s*\/\s*\d{1,2}/.test(x)||RX_LONGDATE.test(x)||/\d{1,2}\.\d{1,2}/.test(x))||'').trim();
      const semP=(hp.find(x=>RX_WEEKDAY.test(x))||'').trim();
      const cid=hp.filter(x=>x!==dataP&&x!==semP&&!RX_WEEKDAY.test(x)&&!RX_LONGDATE.test(x)).join(' – ').trim();
      out.roteiro_dias.push({n:+dm[1],data:dataP,dia_semana:semP,cidades:cid,descricao:desc,refeicoes:dayMeals(desc)});
      section='day';expectCity=!cid; // sem cidade no cabeçalho → a próxima linha de destino é a cidade
      return;
    }
    if(section==='hoteis')out.hoteis.push(L);
    else if(section==='inc')out.inclui.push(L);
    else if(section==='nao')out.nao_inclui.push(L);
    else if(section==='day'&&out.roteiro_dias.length){
      const last=out.roteiro_dias[out.roteiro_dias.length-1];
      if(expectCity&&isCityLine(L)){last.cidades=L;expectCity=false;return;}
      expectCity=false;
      last.descricao=(last.descricao?last.descricao+' ':'')+L;
      last.refeicoes=dayMeals(last.descricao);
    }
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
/* Estrutura via IA (Gemini) através do importar.php. O painel envia o token
   do Supabase p/ validar o login. Lança erro se o endpoint falhar (offline,
   preview no Pages sem PHP, ou IA indisponível) → chamador cai no parser local. */
const IMPORT_ENDPOINT='../importar.php';
async function callImporter(formData){
  const {data:{session}}=await sb.auth.getSession();
  formData.append('sb_token',session&&session.access_token?session.access_token:'');
  showImp(true,'Consultando a IA…');
  const res=await fetch(IMPORT_ENDPOINT,{method:'POST',body:formData});
  let j=null;try{j=await res.json();}catch(e){throw new Error('resposta inválida do servidor');}
  if(!res.ok||!j||!j.ok)throw new Error(j&&j.error?j.error:('HTTP '+res.status));
  return j.data;
}
async function importDocx(file){
  const zip=await JSZip.loadAsync(file);
  const xml=await zip.file('word/document.xml').async('string');
  const paras=xml.split(/<\/w:p>/).map(p=>{const t=(p.match(/<w:t[^>]*>([\s\S]*?)<\/w:t>/g)||[]).map(x=>x.replace(/<[^>]+>/g,'')).join('');return decodeEnt(t);});
  let parsed;
  try{const fd=new FormData();fd.append('text',paras.filter(Boolean).join('\n'));parsed=await callImporter(fd);}
  catch(err){console.warn('Importador IA falhou, usando parser local:',err);toast('IA indisponível, extração básica: '+err.message,true);parsed=parseParas(paras);const vals=parseTablesXml(xml);if(vals.length)parsed.valores=vals;}
  // imagens (galeria) — sempre no navegador, do próprio zip
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
  let parsed;
  try{const fd=new FormData();fd.append('text',paras.join('\n'));parsed=await callImporter(fd);}
  catch(err){console.warn('Importador IA falhou, usando parser local:',err);toast('IA indisponível, extração básica: '+err.message,true);parsed=parseParas(paras);}
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
  // texto extraído serve APENAS de fallback (o caminho bom manda o PDF pro Gemini)
  let paras=[];
  for(let p=1;p<=pdf.numPages;p++){
    const page=await pdf.getPage(p);const tc=await page.getTextContent();
    let lastY=null,line='';
    tc.items.forEach(it=>{const y=it.transform[5];if(lastY!==null&&Math.abs(y-lastY)>4){if(line.trim())paras.push(line.trim());line='';}line+=it.str+' ';lastY=y;});
    if(line.trim())paras.push(line.trim());
  }
  let parsed;
  try{const fd=new FormData();fd.append('pdf',file,file.name);parsed=await callImporter(fd);}
  catch(err){console.warn('Importador IA falhou, usando parser local:',err);toast('IA indisponível, extração básica: '+err.message,true);parsed=parseParas(paras);}
  // imagens embutidas → galeria (nunca capa)
  showImp(true,'Extraindo imagens…');
  const slug=slugify(parsed.titulo||'import');
  parsed.galeria=await extractPdfImages(pdf,slug);
  return parsed;
}
/* Extrai as imagens embutidas do PDF via pdf.js: percorre o operator list,
   pega os XObjects de imagem, descarta logos/ícones (< 200px) e duplicatas,
   e sobe cada foto pro Supabase. Falha de uma imagem não derruba as outras. */
async function extractPdfImages(pdf,slug){
  const OPS=window.pdfjsLib.OPS, urls=[], seen=new Set();
  for(let p=1;p<=pdf.numPages;p++){
    let page,ops;
    try{page=await pdf.getPage(p);ops=await page.getOperatorList();}catch(e){continue;}
    const names=[];
    for(let i=0;i<ops.fnArray.length;i++){
      const fn=ops.fnArray[i];
      if(fn===OPS.paintImageXObject||fn===OPS.paintJpegXObject||fn===OPS.paintImageXObjectRepeat){
        const a=ops.argsArray[i];if(a&&a[0]&&typeof a[0]==='string')names.push(a[0]);
      }
    }
    for(const name of [...new Set(names)]){
      try{
        const img=await getPdfObj(page,name);
        if(!img)continue;
        const w=img.width,h=img.height;
        if(!w||!h||Math.min(w,h)<200)continue; // logo, bandeira, ícone
        const blob=await imgToBlob(img);
        if(!blob)continue;
        const sig=w+'x'+h+'-'+blob.size; // dedup: mesma foto repetida entre páginas
        if(seen.has(sig))continue;seen.add(sig);
        const f=new File([blob],`pdf-p${p}-${(''+name).replace(/[^a-z0-9]/gi,'')}.jpg`,{type:'image/jpeg'});
        const url=await uploadImg(f,slug);
        if(url)urls.push(url);
      }catch(e){/* ignora imagem problemática */}
    }
  }
  return urls;
}
// page.objs.get é assíncrono (resolve durante o parse); guarda com timeout p/ não travar.
function getPdfObj(page,name){
  return new Promise(res=>{
    let done=false;const fin=v=>{if(!done){done=true;res(v);}};
    const to=setTimeout(()=>fin(null),5000);
    const grab=store=>{try{store.get(name,o=>{clearTimeout(to);fin(o);});}catch(e){/* tenta o próximo */}};
    try{
      if(page.objs&&page.objs.has&&page.objs.has(name))return page.objs.get(name,o=>{clearTimeout(to);fin(o);});
      grab(page.objs);
      // imagens compartilhadas entre páginas vivem em commonObjs
      if(page.commonObjs)setTimeout(()=>{if(!done)grab(page.commonObjs);},50);
    }catch(e){clearTimeout(to);fin(null);}
  });
}
// Converte a imagem decodificada do pdf.js (bitmap OU raw RGBA/RGB/cinza) em JPEG.
function imgToBlob(img){
  return new Promise(res=>{
    try{
      const w=img.width,h=img.height;
      const cv=document.createElement('canvas');cv.width=w;cv.height=h;
      const ctx=cv.getContext('2d');
      if(img.bitmap){ctx.drawImage(img.bitmap,0,0,w,h);}
      else if(img.data){
        const src=img.data,out=ctx.createImageData(w,h),dst=out.data,kind=img.kind;
        if(kind===3){dst.set(src);}                              // RGBA_32BPP
        else if(kind===2){for(let i=0,j=0;i<src.length;i+=3,j+=4){dst[j]=src[i];dst[j+1]=src[i+1];dst[j+2]=src[i+2];dst[j+3]=255;}} // RGB_24BPP
        else{return res(null);} // 1BPP (packed) e outros formatos raros → deixa p/ upload manual
        ctx.putImageData(out,0,0);
      }else{return res(null);}
      cv.toBlob(b=>res(b),'image/jpeg',0.9);
    }catch(e){res(null);}
  });
}
function loadPdfJs(){return new Promise((res,rej)=>{if(window.pdfjsLib){window.pdfjsLib.GlobalWorkerOptions.workerSrc='https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js';return res();}const s=document.createElement('script');s.src='https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js';s.onload=()=>{window.pdfjsLib.GlobalWorkerOptions.workerSrc='https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js';res();};s.onerror=rej;document.head.appendChild(s);});}
function decodeEnt(s){return (s||'').replace(/&amp;/g,'&').replace(/&lt;/g,'<').replace(/&gt;/g,'>').replace(/&quot;/g,'"').replace(/&#39;/g,"'").replace(/&apos;/g,"'");}

/* ============================================================ BOOT */
(async function(){const {data:{session}}=await sb.auth.getSession();if(session)enterApp();})();
