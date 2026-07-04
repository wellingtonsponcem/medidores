const SUPABASE_URL = "https://jmdlraaprcztshkfyeud.supabase.co";
const SUPABASE_KEY = "sb_publishable_2c7MrFAZaCiQzY_EuQuizQ_wTgAzbIC";
const supabaseClient = window.supabase.createClient(SUPABASE_URL, SUPABASE_KEY);

const userAuth = JSON.parse(localStorage.getItem('userAuth') || 'null');
if (!userAuth) {
  window.location.href = 'index.html';
}
const isCasa1 = userAuth?.perfil === 'casa1';
const isCasa2 = userAuth?.perfil === 'casa2';
const isCasa3 = userAuth?.perfil === 'casa3';
const isLoja2 = userAuth?.perfil === 'loja2';
const isResident = isCasa1 || isCasa2 || isCasa3 || isLoja2;
const isAdmin = userAuth?.perfil === 'admin';

const METER_MAP = {
  'casa1': 'rua_inhambu',
  'casa2': 'rua_inhambu',
  'loja2': 'av_brasil',
  'casa3': 'av_brasil'
};
const userMeter = userAuth ? (METER_MAP[userAuth.perfil] || null) : null;

function initApp() {
    if(userAuth) {
      const un = document.getElementById('top-user-name');
      if(un) un.textContent = `Olá, ${userAuth.nome}`;
      const ui = document.getElementById('top-user-initial');
      if(ui) ui.textContent = (userAuth.nome || 'M').charAt(0).toUpperCase();
      
      // Lógica de visibilidade de colunas no desktop
      const isBrasil = userMeter === 'av_brasil';
      const th1 = document.getElementById('th-en-casa1');
      const th2 = document.getElementById('th-en-casa2');

      if (isBrasil) {
         if(th1) th1.textContent = isLoja2 ? "Sua Unidade (Loja 2)" : "Loja 2";
         if(th2) th2.textContent = isCasa3 ? "Sua Unidade (Casa 3)" : "Casa 3";
         if(isLoja2) {
            // Loja 2 não tem água - esconde nav e redireciona
            document.getElementById('tab-agua')?.classList.add('hidden');
         }
         if(isCasa3) {
            document.getElementById('th-ag-h1')?.classList.add('hidden');
            document.getElementById('th-ag-h2')?.classList.add('hidden');
         }
      } else {
         if(th1) th1.textContent = isCasa1 ? "Sua Unidade (Casa 1)" : "Casa 1";
         if(th2) th2.textContent = isCasa2 ? "Sua Unidade (Casa 2)" : "Casa 2";
         if(isCasa1) {
            document.getElementById('th-ag-h2')?.classList.add('hidden');
            document.getElementById('th-ag-h3')?.classList.add('hidden');
         }
         if(isCasa2) {
            document.getElementById('th-ag-h1')?.classList.add('hidden');
            document.getElementById('th-ag-h3')?.classList.add('hidden');
         }
      }

      // Inicialização sincronizada da Aba
      if (state.currentTab === 'energia') {
         document.getElementById('tab-energia')?.classList.add('active-nav');
         document.getElementById('tab-agua')?.classList.remove('active-nav');
         document.getElementById('view-energia')?.classList.remove('hidden');
         document.getElementById('view-agua')?.classList.add('hidden');
      }

      if(isAdmin) {
        document.getElementById('btn-go-admin')?.classList.remove('hidden');
        document.getElementById('mob-btn-go-admin')?.classList.remove('hidden');
        const wrap = document.getElementById('meter-selector-wrap');
        if(wrap) {
          if (state.currentTab === 'energia') {
            wrap.classList.remove('hidden');
          } else {
            wrap.classList.add('hidden');
          }
          const sel = document.getElementById('meter-selector');
          if(sel) {
            sel.value = state.currentMeter || 'rua_inhambu';
            sel.addEventListener('change', (e) => {
              state.currentMeter = e.target.value;
              loadEnergia();
            });
          }
        }
      }
      
      if(userAuth.senha_alterada === false && sessionStorage.getItem('ignored_pwd') !== 'true') {
        abrirModalSenha(true);
      }
    }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initApp);
} else {
  initApp();
}

window.abrirModalSenha = function(isObrigatorio) {
  const modal = document.getElementById('modal-senha');
  if(!modal) return;
  const btnCancel = document.getElementById('btn-modal-senha-cancel');
  if(isObrigatorio) {
    btnCancel.textContent = "Ignorar";
    document.getElementById('txt-modal-senha').textContent = "Como esta é uma senha provisória, é obrigatório ou altamente recomendado que você altere sua senha de acesso agora para manter o sistema seguro.";
  } else {
    btnCancel.textContent = "Cancelar";
    document.getElementById('txt-modal-senha').textContent = "Digite uma nova senha de segurança. Ela será aplicada na hora.";
  }
  document.getElementById('ipt-nova-senha').value = '';
  modal.classList.remove('hidden');
}

window.ignorarSenha = function() {
  sessionStorage.setItem('ignored_pwd', 'true');
  document.getElementById('modal-senha')?.classList.add('hidden');
}

window.salvarNovaSenha = async function() {
  if(!userAuth.id) return alert('Modo Legado ativo. Apenas para usuários criados com Admin no banco.');
  const nova = document.getElementById('ipt-nova-senha').value;
  if(nova.trim() === "") return alert("A senha não pode estar vazia.");
  
  const { error } = await supabaseClient.from('usuarios').update({ senha: nova, senha_alterada: true }).eq('id', userAuth.id);
  if(error) return alert("Erro ao trocar senha: " + error.message);
  
  userAuth.senha_alterada = true;
  localStorage.setItem('userAuth', JSON.stringify(userAuth));
  alert("Senha alterada e vinculada com sucesso!");
  document.getElementById('modal-senha')?.classList.add('hidden');
}

const initialTab = userAuth.perfil === 'loja2' ? 'energia' : 'agua';
const state = { agua: [], energia: [], currentTab: initialTab, chartRange: '6months', currentMeter: userMeter || 'rua_inhambu' };
window.state = state;
let editingRow = null;
let chartInstance = null;

function displayAlert(msg) {
  const al = document.getElementById('status-alerta');
  if (al) {
    al.textContent = msg;
    al.classList.remove('hidden');
  } else {
    alert(msg);
  }
}

window.onerror = function(msg, src, lineno, colno, error) {
  const fileName = src ? src.split('/').pop() : 'desconhecido';
  displayAlert(`Erro JS (${fileName}:${lineno}): ${msg}`);
};

// Formatação R$
function fmtMoney(val) {
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(val || 0);
}

function getDeltaHtml(curr, prev) {
  if (typeof prev !== 'number' || prev === 0 || isNaN(prev)) return '';
  const diff = curr - prev;
  const perc = (diff / prev) * 100;
  if (Math.abs(perc) < 1) return '';
  if (diff > 0) return `<span class="delta-badge delta-up">↗ +${perc.toFixed(1)}%</span>`;
  return `<span class="delta-badge delta-down">↘ ${perc.toFixed(1)}%</span>`;
}

function getTableBadge(rowVal, rowCons, prevVal, isHighlight, unit) {
  let badgeClass = isHighlight 
    ? 'text-blue-800 bg-blue-100 border border-blue-300 shadow-sm px-3 py-1.5 text-[13px] inline-block font-black' 
    : 'text-slate-600 bg-slate-100 px-2 py-1 text-xs font-semibold';
  
  let trend = '';
  if (prevVal && rowVal) {
    const diff = Number(rowVal) - Number(prevVal);
    const p = (diff / Number(prevVal)) * 100;
    if (Math.abs(p) >= 1) {
       trend = `<span class="text-[10px] ${p>0?'text-red-500':'text-emerald-600'} font-bold ml-1">${p>0?'↗':'↘'}${Math.abs(p).toFixed(1)}%</span>`;
    }
  }
  return `<span class="rounded-md whitespace-nowrap transition-all ${badgeClass}">${fmtMoney(rowVal)} <span class="text-[10px] ${isHighlight?'text-blue-600':'text-slate-400'} font-normal ml-0.5">(${Number(rowCons).toFixed(1)} ${unit})</span>${trend}</span>`;
}

function fmtDateSecure(raw) {
  if (!raw) return '-';
  const s = String(raw).trim();
  const brMatch = s.match(/^(\d{2})[\/\-](\d{2})[\/\-](\d{4})$/);
  if (brMatch) return brMatch[1] + "/" + brMatch[2] + "/" + brMatch[3];
  const isoMatch = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (isoMatch) return isoMatch[3] + "/" + isoMatch[2] + "/" + isoMatch[1];
  const d = new Date(s);
  if (!isNaN(d.getTime())) {
      const day = String(d.getUTCDate()).padStart(2, "0");
      const month = String(d.getUTCMonth() + 1).padStart(2, "0");
      const year = d.getUTCFullYear();
      return day + "/" + month + "/" + year;
  }
  return s;
}

// ======================== CARREGAMENTO ========================
async function loadAgua() {
  try {
    const { data, error } = await supabaseClient.from('vw_agua_rateio').select('*').order('data_leitura', { ascending: false });
    if (error) throw error;
    state.agua = data;
    if (state.currentTab === 'agua') {
      renderAgua();
      updateDashboard();
    }
  } catch(err) {
    displayAlert("Erro dados de Água: " + err.message);
  }
}

async function loadEnergia() {
  try {
    const { data: rawData, error } = await supabaseClient.from('vw_energia_rateio').select('*').order('data_leitura', { ascending: false });
    if (error) throw error;
    
    // Filtro por Medidor (Morador ou Seleção do Admin)
    const activeMeter = isAdmin ? (state.currentMeter || 'rua_inhambu') : (userMeter || 'av_brasil');
    state.energia = (isAdmin || activeMeter) ? rawData.filter(r => (r.local_medidor || 'rua_inhambu') === activeMeter) : rawData;
    if (state.currentTab === 'energia') {
      renderEnergia();
      updateDashboard();
    }
  } catch(err) {
    displayAlert("Erro dados de Energia: " + err.message);
  }
}

// ======================== DASHBOARD (KPIs & Chart) ========================
function updateDashboard() {
  const type = state.currentTab;
  const arr = state[type];
  if (!arr || arr.length === 0) return;

  const kpiPanel = document.getElementById('kpi-panel');
  const targetTitle = document.getElementById('target-title');
  const latest = arr[0];
  
  const valFatura = Number(latest.valor_fatura_total);
  const prevFatura = arr[1] ? Number(arr[1].valor_fatura_total) : null;
  const faturaDelta = getDeltaHtml(valFatura, prevFatura);

  let lastX = state.chartRange === 'annual' ? arr.slice(0, 12) : arr.slice(0, 6);
  const sumFatura = lastX.reduce((acc, row) => acc + Number(row.valor_fatura_total), 0);
  const avgFatura = sumFatura / lastX.length;
  const prevX = state.chartRange === 'annual' ? arr.slice(1, 13) : arr.slice(1, 7);
  const prevAvgFatura = prevX.length > 0 ? (prevX.reduce((acc, row) => acc + Number(row.valor_fatura_total), 0) / prevX.length) : null;
  const avgDelta = getDeltaHtml(avgFatura, prevAvgFatura);

  const unitCost = type === 'agua' ? Number(latest.valor_por_m3) : Number(latest.valor_kwh);
  const prevUnitCost = arr[1] ? (type === 'agua' ? Number(arr[1].valor_por_m3) : Number(arr[1].valor_kwh)) : null;
  const unitDelta = getDeltaHtml(unitCost, prevUnitCost);

  const buildCard = (label, valStr, deltaStr, linkText, isPrimary=false) => `
    <div class="bg-white rounded-2xl border ${isPrimary?'border-slate-200 shadow-md':'border-slate-100 shadow-sm'} p-6 flex flex-col justify-between h-full transition duration-300 hover:shadow-md">
      <div class="flex justify-between items-start mb-6">
         <div class="text-[11px] font-bold text-slate-400 uppercase tracking-widest">${label}</div>
         <div class="text-[#0ea5e9] text-xs font-bold cursor-pointer hover:text-blue-700 transition">${linkText}</div>
      </div>
      <div class="flex justify-between items-end mt-auto">
         <div class="text-3xl font-black ${isPrimary?'text-slate-900':'text-slate-800'} tracking-tight">${valStr}</div>
         <div class="pb-[3px]">${deltaStr}</div>
      </div>
    </div>
  `;

  if (kpiPanel) {
    kpiPanel.innerHTML = `
      ${buildCard('Última Fatura Emitida', fmtMoney(valFatura), faturaDelta, 'Detalhes', true)}
      ${buildCard('Gasto Médio Histórico', fmtMoney(avgFatura), avgDelta, 'Ver Projeção')}
      ${buildCard(type === 'agua' ? 'Tarifa Água / m³' : 'Tarifa Energética / kWh', fmtMoney(unitCost), unitDelta, 'Análise')}
    `;
  }

  if (targetTitle) {
    targetTitle.textContent = type === 'agua' ? 'Indicadores de Água' : 'Indicadores de Energia';
  }
  
  const chartTitle = document.querySelector('h2.text-slate-400.uppercase.tracking-widest');
  if(chartTitle) {
    chartTitle.textContent = `Evolução do Consumo (${state.chartRange === 'annual' ? '12' : '6'} Meses)`;
  }

  drawChart(lastX.slice().reverse(), type);
}

function drawChart(dataAsc, type) {
  const canvas = document.getElementById('historyChart');
  if (!canvas) return;
  const ctx = canvas.getContext('2d');

  if (chartInstance) {
    chartInstance.destroy();
  }

  const labels = dataAsc.map(r => fmtDateSecure(r.data_leitura).substring(0, 5)); 
  let gradient1 = ctx.createLinearGradient(0, 0, 0, 400);
  gradient1.addColorStop(0, 'rgba(15, 23, 42, 0.08)'); 
  gradient1.addColorStop(1, 'rgba(15, 23, 42, 0.00)');

  let datasets = [];
  if (type === 'agua') {
    datasets = [
      { 
        label: 'Gasto Total da Fatura (R$)', 
        data: dataAsc.map(r => Number(r.valor_fatura_total)), 
        borderColor: '#0f172a', 
        borderWidth: 3,
        backgroundColor: gradient1, 
        fill: true, 
        tension: 0.45,
        pointBackgroundColor: '#ffffff',
        pointBorderColor: '#0f172a',
        pointBorderWidth: 2,
        pointRadius: 4,
        pointHoverRadius: 6
      },
      { 
        label: 'Custo Serviço Comum (R$)', 
        data: dataAsc.map(r => Number(r.valor_servico)), 
        borderColor: '#f59e0b', 
        borderWidth: 2,
        backgroundColor: 'transparent',
        fill: false, 
        tension: 0.45,
        pointRadius: 0
      }
    ];
  } else {
    datasets = [
       { 
        label: 'Fatura Inteira (R$)', 
        data: dataAsc.map(r => Number(r.valor_fatura_total)), 
        borderColor: '#0f172a', 
        borderWidth: 3,
        backgroundColor: gradient1, 
        fill: true, 
        tension: 0.45,
        pointBackgroundColor: '#ffffff',
        pointBorderColor: '#0f172a',
        pointBorderWidth: 2,
        pointRadius: 4
      }
    ];
    
    // Dataset customizado baseado no medidor ativo
    const activeMeter = isAdmin ? (state.currentMeter || 'rua_inhambu') : userMeter;
    
    if (activeMeter === 'rua_inhambu' || isAdmin) {
      if (!isCasa1) {
        datasets.push({ label: 'Obrigação Casa 2 (R$)', data: dataAsc.map(r => Number(r.valor_casa_2)), borderColor: '#ec4899', borderWidth: 2, fill: false, tension: 0.45, pointRadius: 0 });
      }
      if (!isCasa2) {
        datasets.push({ label: 'Obrigação Casa 1 (R$)', data: dataAsc.map(r => Number(r.valor_casa_1)), borderColor: '#3b82f6', borderWidth: 2, fill: false, tension: 0.45, pointRadius: 0 });
      }
    } 
    
    if (activeMeter === 'av_brasil' || isAdmin) {
      if (!isLoja2) {
        // Coluna 2 (r.valor_casa_2) agora é a Casa 3 (menor consumo)
        datasets.push({ label: 'Obrigação Casa 3 (R$)', data: dataAsc.map(r => Number(r.valor_casa_2)), borderColor: '#6366f1', borderWidth: 2, fill: false, tension: 0.45, pointRadius: 0 });
      }
      if (!isCasa3) {
        // Coluna 1 (r.valor_casa_1) agora é a Loja 2 (maior consumo)
        datasets.push({ label: 'Obrigação Loja 2 (R$)', data: dataAsc.map(r => Number(r.valor_casa_1)), borderColor: '#14b8a6', borderWidth: 2, fill: false, tension: 0.45, pointRadius: 0 });
      }
    }
  }

  chartInstance = new Chart(ctx, {
    type: 'line',
    data: { labels, datasets },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: { 
        legend: { position: 'top', align: 'end', labels: { usePointStyle: true, boxWidth: 6, font: { family: 'Inter', size: 11, weight: '600' } } },
        tooltip: { backgroundColor: '#0f172a', titleFont: { family: 'Inter' }, bodyFont: { family: 'Inter', weight: 'bold' }, padding: 12, cornerRadius: 8, displayColors: false }
      },
      scales: { 
        y: { grid: { color: '#f1f5f9' }, ticks: { font: { family: 'Inter', size: 10 }, color: '#94a3b8', maxTicksLimit: 5 } },
        x: { grid: { display: false }, ticks: { font: { family: 'Inter', size: 10, weight: '600' }, color: '#64748b' } }
      }
    }
  });
}

function renderAgua() {
  const tbody = document.getElementById('tbody-agua');
  if (!tbody) return;
  tbody.innerHTML = '';
  if (state.agua.length === 0) {
    tbody.innerHTML = '<tr><td colspan="7" class="text-center py-8 text-slate-400 text-sm">Nenhum registro encontrado.</td></tr>';
    return;
  }

  state.agua.forEach((row, idx) => {
    const tr = document.createElement('tr');
    tr.className = idx === 0 ? 'bg-blue-50/20' : '';
    const prev = state.agua[idx + 1];

    tr.innerHTML = `
        <td class="font-bold text-slate-700">${fmtDateSecure(row.data_leitura)}</td>
        <td class="text-slate-500">${Number(row.consumo_cesan).toFixed(1)} m³</td>
        ${(!isCasa2 && !isCasa3 && !isLoja2) ? `<td>${getTableBadge(row.valor_h1, row.consumo_h1, prev?.valor_h1, isCasa1, 'm³')}</td>` : ''}
        ${(!isCasa1 && !isCasa3 && !isLoja2) ? `<td>${getTableBadge(row.valor_h2, row.consumo_h2, prev?.valor_h2, isCasa2, 'm³')}</td>` : ''}
        ${(isAdmin || isCasa3) ? `<td>${getTableBadge(row.valor_h3, row.consumo_h3, prev?.valor_h3, isCasa3, 'm³')}</td>` : ''}
        <td><span class="text-amber-600 bg-amber-50 px-2 py-1 rounded-md text-xs font-semibold whitespace-nowrap">${fmtMoney(row.valor_servico)} <span class="text-[10px] text-amber-500 font-normal">(${Number(row.consumo_servico).toFixed(1)} m³)</span></span></td>
        <td class="font-black text-slate-800">${fmtMoney(row.valor_fatura_total)}</td>
      `;
    tbody.appendChild(tr);
  });
}

function renderEnergia() {
  const tbody = document.getElementById('tbody-energia');
  if (!tbody) return;
  tbody.innerHTML = '';
  if (state.energia.length === 0) {
    tbody.innerHTML = '<tr><td colspan="5" class="text-center py-8 text-slate-400 text-sm">Nenhum registro encontrado.</td></tr>';
    return;
  }

  state.energia.forEach((row, idx) => {
    const tr = document.createElement('tr');
    tr.className = idx === 0 ? 'bg-blue-50/20' : '';
    const prev = state.energia[idx + 1];

    const isAvBrasil = (row.local_medidor || 'rua_inhambu') === 'av_brasil';
    const c1Mine = isAvBrasil ? isLoja2 : (isCasa1 || isCasa3);
    const c2Mine = isAvBrasil ? isCasa3 : (isCasa2 || isLoja2);

    tr.innerHTML = `
        <td class="font-bold text-slate-700">${fmtDateSecure(row.data_leitura)}</td>
        <td>${getTableBadge(row.valor_casa_1, row.consumo_casa_1, prev?.valor_casa_1, c1Mine, 'kWh')}</td>
        <td>${getTableBadge(row.valor_casa_2, row.consumo_casa_2, prev?.valor_casa_2, c2Mine, 'kWh')}</td>
        <td class="font-black text-slate-800">${fmtMoney(row.valor_fatura_total)}</td>
      `;
    tbody.appendChild(tr);
  });
}

document.getElementById('tab-agua').addEventListener('click', () => {
  if (state.currentTab === 'agua') return;
  state.currentTab = 'agua';
  document.getElementById('tab-agua').className = 'nav-item active-nav';
  document.getElementById('tab-energia').className = 'nav-item';
  document.getElementById('view-agua').classList.remove('hidden');
  document.getElementById('view-energia').classList.add('hidden');
  
  if (isAdmin) {
    document.getElementById('meter-selector-wrap')?.classList.add('hidden');
  }

  renderAgua();
  updateDashboard();
});

document.getElementById('tab-energia').addEventListener('click', () => {
  if (state.currentTab === 'energia') return;
  state.currentTab = 'energia';
  document.getElementById('tab-energia').className = 'nav-item active-nav';
  document.getElementById('tab-agua').className = 'nav-item';
  document.getElementById('view-energia').classList.remove('hidden');
  document.getElementById('view-agua').classList.add('hidden');

  if (isAdmin) {
    document.getElementById('meter-selector-wrap')?.classList.remove('hidden');
  }

  renderEnergia();
  updateDashboard();
});

document.getElementById('btn-chart-monthly')?.addEventListener('click', () => {
  state.chartRange = '6months';
  document.getElementById('btn-chart-monthly').className = 'px-3 py-1 text-xs font-bold bg-slate-800 text-white rounded-md shadow-sm';
  document.getElementById('btn-chart-annual').className = 'px-3 py-1 text-xs font-bold bg-white border border-slate-200 text-slate-500 hover:bg-slate-50 rounded-md';
  updateDashboard();
});

document.getElementById('btn-chart-annual')?.addEventListener('click', () => {
  state.chartRange = 'annual';
  document.getElementById('btn-chart-annual').className = 'px-3 py-1 text-xs font-bold bg-slate-800 text-white rounded-md shadow-sm';
  document.getElementById('btn-chart-monthly').className = 'px-3 py-1 text-xs font-bold bg-white border border-slate-200 text-slate-500 hover:bg-slate-50 rounded-md';
  updateDashboard();
});

loadAgua();
loadEnergia();
