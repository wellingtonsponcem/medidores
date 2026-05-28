const SUPABASE_URL = "https://jmdlraaprcztshkfyeud.supabase.co";
const SUPABASE_KEY = "sb_publishable_2c7MrFAZaCiQzY_EuQuizQ_wTgAzbIC";
const supabaseClient = window.supabase.createClient(SUPABASE_URL, SUPABASE_KEY);

const state = { agua: [], energia: [], usuarios: [], currentTab: 'agua', energyMeter: 'rua_inhambu' };
let editingRow = null;
let chartInstance = null;

function createRowId() {
  if (window.crypto?.randomUUID) return window.crypto.randomUUID();
  return `row-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

const ENERGY_METER_CONFIG = {
  rua_inhambu: {
    key: 'rua_inhambu',
    label: 'Rua Inhambu',
    title: 'Indicadores de Energia - Rua Inhambu',
    tableTitle: 'Histórico Detalhado Energia - Rua Inhambu',
    col1: 'Custo Casa 1',
    col2: 'Custo Casa 2',
    modalTitle: 'Nova Leitura de Energia - Rua Inhambu',
    uploadFaturaLabel: '📄<br>Fatura Energia',
    uploadInternoLabel: '⚡<br>Relógio Interno',
    padraoLabel: 'L. Padrão',
    internoLabel: 'L. Interna',
    previousLabel: 'Última Leitura Interna',
    usePreviousRef: false,
    internalPartyLabel: 'Casa 2',
    externalPartyLabel: 'Casa 1',
  },
  av_brasil: {
    key: 'av_brasil',
    label: 'Av Brasil',
    title: 'Indicadores de Energia - Av Brasil',
    tableTitle: 'Histórico Detalhado Energia - Av Brasil',
    col1: 'Casa 03',
    col2: 'Loja 02',
    modalTitle: 'Nova Leitura de Energia - Av Brasil',
    uploadFaturaLabel: '📄<br>Fatura EDP',
    uploadInternoLabel: '⚡<br>Print Whatímetro',
    padraoLabel: 'Consumo Total Fatura (kWh)',
    internoLabel: 'L. Casa 3 (Whatímetro)',
    interno2Label: 'L. Loja 2 (Whatímetro)',
    previousLabel: 'Anterior Casa 3',
    previous2Label: 'Anterior Loja 2',
    usePreviousRef: true,
    useSecondMeter: true,
    internalPartyLabel: 'Casa 03',
    externalPartyLabel: 'Loja 02',
  }
};

function getEnergyConfig() {
  return ENERGY_METER_CONFIG[state.energyMeter] || ENERGY_METER_CONFIG.rua_inhambu;
}

function getVisibleEnergiaRows() {
  return state.energia.filter(row => (row.local_medidor || 'rua_inhambu') === state.energyMeter);
}

function updateEnergySelectorVisibility() {
  const wrap = document.getElementById('energia-meter-switch');
  if (wrap) wrap.classList.toggle('hidden', state.currentTab !== 'energia');
}

function syncEnergyContextUI() {
  const cfg = getEnergyConfig();
  const select = document.getElementById('energia-meter-select');
  const th1 = document.getElementById('th-en-col-1');
  const th2 = document.getElementById('th-en-col-2');
  if (select) select.value = state.energyMeter;
  if (th1) th1.textContent = cfg.col1;
  if (th2) th2.textContent = cfg.col2;
  if (state.currentTab === 'energia') {
    document.getElementById('target-title').textContent = cfg.title;
    document.getElementById('table-title').textContent = cfg.tableTitle;
  }
  updateEnergySelectorVisibility();
}

function getEnergyInsertPayload() {
  const totalConsumo = Number(document.getElementById('ins-en-padrao').value || 0);
  const leituraAtual = Number(document.getElementById('ins-en-interno').value || 0);
  const leituraAnterior = Number(document.getElementById('ins-en-interno-anterior')?.value || 0);
  
  const leituraAtual2Input = document.getElementById('ins-en-interno-2');
  const leituraAtual2 = leituraAtual2Input && leituraAtual2Input.value !== '' ? Number(leituraAtual2Input.value) : null;
  
  const leituraAnterior2Input = document.getElementById('ins-en-interno-anterior-2');
  const leituraAnterior2 = leituraAnterior2Input && leituraAnterior2Input.value !== '' ? Number(leituraAnterior2Input.value) : null;

  const valorFatura = Number(document.getElementById('ins-fatura').value || 0);
  const valorKwh = totalConsumo > 0 ? valorFatura / totalConsumo : 0;
  
  const consumoInterno = Math.max(0, leituraAtual - leituraAnterior);
  let consumoExterno = 0;
  
  if (leituraAtual2 !== null) {
    consumoExterno = Math.max(0, leituraAtual2 - leituraAnterior2);
  } else {
    consumoExterno = Math.max(0, totalConsumo - consumoInterno);
  }

  return { totalConsumo, leituraAtual, leituraAnterior, leituraAtual2, leituraAnterior2, valorFatura, valorKwh, consumoInterno, consumoExterno };
}

function refreshEnergiaPreview() {
  const previewBox = document.getElementById('energia-preview-box');
  const previewContent = document.getElementById('energia-preview-content');
  if (!previewBox || !previewContent) return;
  if (state.currentTab !== 'energia' || state.energyMeter !== 'av_brasil') {
    previewBox.classList.add('hidden');
    previewContent.innerHTML = '';
    return;
  }
  const cfg = getEnergyConfig();
  const { totalConsumo, leituraAtual, leituraAnterior, leituraAtual2, leituraAnterior2, valorFatura, valorKwh, consumoInterno, consumoExterno } = getEnergyInsertPayload();
  if (!totalConsumo && !leituraAtual && !leituraAnterior && !valorFatura) {
    previewBox.classList.add('hidden');
    previewContent.innerHTML = '';
    return;
  }
  previewBox.classList.remove('hidden');
  
  let diffInfo = '';
  if (leituraAtual2 !== null) {
    const soma = consumoInterno + consumoExterno;
    const diff = totalConsumo - soma;
    if (Math.abs(diff) > 0.1) {
      diffInfo = `<div class="text-[10px] text-amber-600 mt-1 italic">Diferença para o total: ${diff.toFixed(2)} kWh (Luz comum/perda)</div>`;
    }
  }

  previewContent.innerHTML = `
    <div>Total fatura: <strong>${totalConsumo.toFixed(2)} kWh</strong></div>
    <div>${cfg.internalPartyLabel}: <strong>${consumoInterno.toFixed(2)} kWh</strong> ${leituraAtual2 !== null ? `(medido)` : `(residual)`}</div>
    <div>${cfg.externalPartyLabel}: <strong>${consumoExterno.toFixed(2)} kWh</strong> ${leituraAtual2 !== null ? `(medido)` : `(residual)`}</div>
    ${diffInfo}
    <div class="border-t border-slate-200 my-1 pt-1">Valor/kWh: <strong>${fmtMoney(valorKwh)}</strong></div>
    <div>${cfg.internalPartyLabel} paga: <strong>${fmtMoney(consumoInterno * valorKwh)}</strong></div>
    <div>${cfg.externalPartyLabel} paga: <strong>${fmtMoney(consumoExterno * valorKwh)}</strong></div>
  `;
}

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
  displayAlert("Erro JS (" + lineno + "): " + msg);
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
  // Se o valor aumentou, para consumo e conta, geralmente é ruim (Vermelho/Rosa), se abaixou é bom (Teal/Verde)
  if (diff > 0) return `<span class="delta-badge delta-up">↗ +${perc.toFixed(1)}%</span>`;
  return `<span class="delta-badge delta-down">↘ ${perc.toFixed(1)}%</span>`;
}

// Formatação Datas Seguras
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

function fmtDateForInput(raw) {
  if (!raw) return '';
  const s = String(raw).trim();
  const brMatch = s.match(/^(\d{2})[\/\-](\d{2})[\/\-](\d{4})$/);
  if (brMatch) return brMatch[3] + "-" + brMatch[2] + "-" + brMatch[1];
  const isoMatch = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (isoMatch) return isoMatch[1] + "-" + isoMatch[2] + "-" + isoMatch[3];
  const d = new Date(s);
  if (!isNaN(d.getTime())) return d.toISOString().slice(0, 10);
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
    const { data, error } = await supabaseClient.from('vw_energia_rateio_admin').select('*').order('data_leitura', { ascending: false });
    if (error) throw error;
    state.energia = data;
    if (state.currentTab === 'energia') {
      syncEnergyContextUI();
      renderEnergia();
      updateDashboard();
    }
  } catch(err) {
    displayAlert("Erro dados de Energia: " + err.message);
  }
}

async function loadUsuarios() {
  const { data, error } = await supabaseClient.from('usuarios').select('*').order('created_at', { ascending: false });
  if (error) return console.error('Erro usuários', error);
  state.usuarios = data;
  if(state.currentTab === 'moradores') renderUsuarios();
}

// ======================== DASHBOARD (KPIs & Chart) ========================
function updateDashboard() {
  const type = state.currentTab;
  const arr = type === 'energia' ? getVisibleEnergiaRows() : state[type];
  if (!arr || arr.length === 0) return;

  const kpiPanel = document.getElementById('kpi-panel');
  const latest = arr[0];
  
  // KPI 1: Última Fatura
  const valFatura = Number(latest.valor_fatura_total);
  const prevFatura = arr[1] ? Number(arr[1].valor_fatura_total) : null;
  const faturaDelta = getDeltaHtml(valFatura, prevFatura);

  // KPI 2: Média (Últimos 6 meses)
  const last6 = arr.slice(0, 6);
  const sumFatura = last6.reduce((acc, row) => acc + Number(row.valor_fatura_total), 0);
  const avgFatura = sumFatura / last6.length;
  // Calculando delta da média tb se houver mes 7
  const prev6 = arr.slice(1, 7);
  const prevAvgFatura = prev6.length > 0 ? (prev6.reduce((acc, row) => acc + Number(row.valor_fatura_total), 0) / prev6.length) : null;
  const avgDelta = getDeltaHtml(avgFatura, prevAvgFatura);

  // KPI 3: Custo Unitário
  const unitCost = type === 'agua' ? Number(latest.valor_por_m3) : Number(latest.valor_kwh);
  const prevUnitCost = arr[1] ? (type === 'agua' ? Number(arr[1].valor_por_m3) : Number(arr[1].valor_kwh)) : null;
  const unitDelta = getDeltaHtml(unitCost, prevUnitCost);

  // Oaze.io Card Box Style
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
  kpiPanel.innerHTML = `
    ${buildCard('Última Fatura Emitida', fmtMoney(valFatura), faturaDelta, 'Detalhes', true)}
    ${buildCard('Gasto Médio Histórico', fmtMoney(avgFatura), avgDelta, 'Ver Projeção')}
    ${buildCard(type === 'agua' ? 'Tarifa Água / m³' : 'Tarifa Energética / kWh', fmtMoney(unitCost), unitDelta, 'Análise')}
  `;

  // Update Title
  const targetTitle = document.getElementById('target-title');
  if (targetTitle) targetTitle.textContent = type === 'agua' ? 'Indicadores de Água' : getEnergyConfig().title;

  // Draw Chart
  drawChart(last6.slice().reverse(), type);
}

function drawChart(dataAsc, type) {
  const canvas = document.getElementById('historyChart');
  if (!canvas) return;
  const ctx = canvas.getContext('2d');

  if (chartInstance) {
    chartInstance.destroy();
  }

  const labels = dataAsc.map(r => fmtDateSecure(r.data_leitura).substring(0, 5)); // DD/MM
  
  // Custom Gradient like the image
  let gradient1 = ctx.createLinearGradient(0, 0, 0, 400);
  gradient1.addColorStop(0, 'rgba(15, 23, 42, 0.08)'); // Slate-900 translúcido
  gradient1.addColorStop(1, 'rgba(15, 23, 42, 0.00)');

  let datasets = [];
  if (type === 'agua') {
    // Only plotting the raw consumed totals to keep the chart incredibly sleek. 
    datasets = [
      { 
        label: 'Gasto Total da Fatura (R$)', 
        data: dataAsc.map(r => Number(r.valor_fatura_total)), 
        borderColor: '#0f172a', // Dark Navy like Oaze
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
        borderColor: '#f59e0b', // Amber
        borderWidth: 2,
        backgroundColor: 'transparent',
        fill: false, 
        tension: 0.45,
        pointRadius: 0
      }
    ];
  } else {
    const cfg = getEnergyConfig();
    datasets = [
       { 
        label: 'Fatura Inteira (R$)', 
        data: dataAsc.map(r => Number(r.valor_fatura_total)), 
        borderColor: '#0f172a', // Dark Navy
        borderWidth: 3,
        backgroundColor: gradient1, 
        fill: true, 
        tension: 0.45,
        pointBackgroundColor: '#ffffff',
        pointBorderColor: '#0f172a',
        pointBorderWidth: 2,
        pointRadius: 4
      },
      { 
        label: `${cfg.internalPartyLabel} (R$)`, 
        data: dataAsc.map(r => Number(r.valor_casa_2)), 
        borderColor: '#ec4899', 
        borderWidth: 2,
        backgroundColor: 'transparent',
        fill: false, 
        tension: 0.45,
        pointRadius: 0
      }
    ];
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
        y: { beginAtZero: false, grid: { color: '#f1f5f9', drawBorder: false }, border: { display: false }, ticks: { font: { family: 'Inter', size: 10 }, color: '#94a3b8', maxTicksLimit: 5 } },
        x: { grid: { display: false, drawBorder: false }, border: { display: false }, ticks: { font: { family: 'Inter', size: 10, weight: '600' }, color: '#64748b' } }
      }
    }
  });
}

// ======================== RENDERIZAÇÃO DOM ========================
function renderAgua() {
  const tbody = document.getElementById('tbody-agua');
  tbody.innerHTML = '';
  if (state.agua.length === 0) {
    tbody.innerHTML = '<tr><td colspan="7" class="text-center py-8 text-slate-400 text-sm">Nenhum registro encontrado no banco de dados.</td></tr>';
    return;
  }

  state.agua.forEach((row, idx) => {
    const tr = document.createElement('tr');
    const trClass = idx === 0 ? 'bg-blue-50/20' : ''; // destaque suave para a row 0
    tr.className = trClass;

    if (editingRow === row.id) {
      tr.innerHTML = `
        <td><input type="date" id="ed-agua-data" class="edit-input date-input" value="${fmtDateForInput(row.data_leitura)}"></td>
        <td colspan="4" class="text-xs text-slate-500">
           Cesan: <input type="number" id="ed-agua-cesan" class="edit-input" value="${row.leitura_cesan}" style="width:60px">
           H1: <input type="number" id="ed-agua-h1" class="edit-input" value="${row.leitura_h1}" style="width:60px">
           H2: <input type="number" id="ed-agua-h2" class="edit-input" value="${row.leitura_h2}" style="width:60px">
           H3: <input type="number" id="ed-agua-h3" class="edit-input" value="${row.leitura_h3}" style="width:60px">
        </td>
        <td><input type="number" id="ed-agua-fatura" class="edit-input" value="${row.valor_fatura_total}"></td>
        <td class="text-right">
          <button class="btn-action btn-save" onclick="saveAgua('${row.id}')">Salvar</button>
          <button class="btn-action ml-1" onclick="cancelEdit()">Cancelar</button>
        </td>
      `;
    } else {
      tr.innerHTML = `
        <td class="font-bold text-slate-700">${fmtDateSecure(row.data_leitura)}</td>
        <td class="text-slate-500">${Number(row.consumo_cesan).toFixed(1)} m³</td>
        <td><span class="text-slate-600 bg-slate-100 px-2 py-1 rounded-md text-xs font-semibold whitespace-nowrap">${fmtMoney(row.valor_h1)} <span class="text-[10px] text-slate-400 font-normal">(${Number(row.consumo_h1).toFixed(1)} m³)</span></span></td>
        <td><span class="text-slate-600 bg-slate-100 px-2 py-1 rounded-md text-xs font-semibold whitespace-nowrap">${fmtMoney(row.valor_h2)} <span class="text-[10px] text-slate-400 font-normal">(${Number(row.consumo_h2).toFixed(1)} m³)</span></span></td>
        <td><span class="text-slate-600 bg-slate-100 px-2 py-1 rounded-md text-xs font-semibold whitespace-nowrap">${fmtMoney(row.valor_h3)} <span class="text-[10px] text-slate-400 font-normal">(${Number(row.consumo_h3).toFixed(1)} m³)</span></span></td>
        <td><span class="text-amber-600 bg-amber-50 px-2 py-1 rounded-md text-xs font-semibold whitespace-nowrap">${fmtMoney(row.valor_servico)} <span class="text-[10px] text-amber-500 font-normal">(${Number(row.consumo_servico).toFixed(1)} m³)</span></span></td>
        <td class="font-black text-slate-800">${fmtMoney(row.valor_fatura_total)}</td>
        <td class="text-right">
          <button class="btn-action btn-edit mr-1 mb-1" onclick="startEdit('${row.id}')">Edit</button>
          <button class="btn-action bg-emerald-50 text-emerald-600 hover:bg-emerald-100 mr-1 mb-1" onclick="shareWhatsApp('${row.id}')">Whatsapp</button>
          <button class="btn-action btn-delete mb-1" onclick="deleteRow('consumo_agua', '${row.id}')">Del</button>
        </td>
      `;
    }
    tbody.appendChild(tr);
  });
}

function renderEnergia() {
  const tbody = document.getElementById('tbody-energia');
  tbody.innerHTML = '';
  const cfg = getEnergyConfig();
  const rows = getVisibleEnergiaRows();
  syncEnergyContextUI();
  if (rows.length === 0) {
    tbody.innerHTML = '<tr><td colspan="5" class="text-center py-8 text-slate-400 text-sm">Nenhum registro encontrado no banco de dados.</td></tr>';
    return;
  }

  rows.forEach((row, idx) => {
    const tr = document.createElement('tr');
    const trClass = idx === 0 ? 'bg-blue-50/20' : '';
    tr.className = trClass;

    if (editingRow === row.id) {
      tr.innerHTML = `
        <td><input type="date" id="ed-ene-data" class="edit-input date-input" value="${fmtDateForInput(row.data_leitura)}"></td>
        <td colspan="2" class="text-xs text-slate-500">
           ${cfg.padraoLabel}: <input type="number" id="ed-ene-padrao" class="edit-input" value="${row.leitura_padrao}" style="width:70px">
           L1: <input type="number" id="ed-ene-interno" class="edit-input" value="${row.leitura_interno}" style="width:70px">
           L2: <input type="number" id="ed-ene-interno-2" class="edit-input" value="${row.leitura_interno_2 ?? ''}" style="width:70px">
           ${cfg.usePreviousRef ? `<br>Ant1: <input type="number" id="ed-ene-anterior" class="edit-input" value="${row.leitura_interno_anterior_ref ?? ''}" style="width:70px">` : ''}
           ${cfg.usePreviousRef ? `Ant2: <input type="number" id="ed-ene-anterior-2" class="edit-input" value="${row.leitura_interno_2_anterior_ref ?? ''}" style="width:70px">` : ''}
        </td>
        <td><input type="number" id="ed-ene-fatura" class="edit-input font-bold" value="${row.valor_fatura_total}" style="width:80px"></td>
        <td class="text-right">
          <button class="btn-action btn-save" onclick="saveEnergia('${row.id}')">Salvar</button>
          <button class="btn-action ml-1" onclick="cancelEdit()">Cancelar</button>
        </td>
      `;
    } else {
      const firstValue = cfg.key === 'av_brasil'
        ? { valor: row.valor_casa_2, consumo: row.consumo_casa_2 }
        : { valor: row.valor_casa_1, consumo: row.consumo_casa_1 };
      const secondValue = cfg.key === 'av_brasil'
        ? { valor: row.valor_casa_1, consumo: row.consumo_casa_1 }
        : { valor: row.valor_casa_2, consumo: row.consumo_casa_2 };
      tr.innerHTML = `
        <td class="font-bold text-slate-700">${fmtDateSecure(row.data_leitura)}</td>
        <td><span class="text-slate-600 bg-slate-100 px-2 py-1 rounded-md text-xs font-semibold whitespace-nowrap">${fmtMoney(firstValue.valor)} <span class="text-[10px] text-slate-400 font-normal">(${Number(firstValue.consumo).toFixed(1)} kWh)</span></span></td>
        <td><span class="text-slate-600 bg-slate-100 px-2 py-1 rounded-md text-xs font-semibold whitespace-nowrap">${fmtMoney(secondValue.valor)} <span class="text-[10px] text-slate-400 font-normal">(${Number(secondValue.consumo).toFixed(1)} kWh)</span></span></td>
        <td class="font-black text-slate-800">${fmtMoney(row.valor_fatura_total)}</td>
        <td class="text-right">
          <button class="btn-action btn-edit mr-1 mb-1" onclick="startEdit('${row.id}')">Edit</button>
          <button class="btn-action bg-emerald-50 text-emerald-600 hover:bg-emerald-100 mr-1 mb-1" onclick="shareWhatsApp('${row.id}')">Whatsapp</button>
          <button class="btn-action btn-delete" onclick="deleteRow('consumo_energia', '${row.id}')">Del</button>
        </td>
      `;
    }
    tbody.appendChild(tr);
  });
}

function renderUsuarios() {
  const tbody = document.getElementById('tbody-usuarios');
  tbody.innerHTML = '';
  if (state.usuarios.length === 0) {
    tbody.innerHTML = '<tr><td colspan="4" class="text-center py-8 text-slate-400 text-sm">Nenhum morador registrado.</td></tr>';
    return;
  }
  state.usuarios.forEach(user => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td class="font-bold text-slate-700 cursor-pointer text-blue-600 hover:underline" onclick="resetSenha('${user.id}')" title="Clique para redefinir a senha">${user.nome}</td>
      <td class="text-slate-500 font-mono text-xs cursor-help" title="A senha não é mostrada por segurança">••••••••</td>
      <td><span class="text-slate-600 bg-slate-100 px-2 py-1 rounded-md text-xs font-semibold whitespace-nowrap uppercase">${user.perfil}</span></td>
      <td class="text-right">
         <button onclick="deletarUsuario('${user.id}')" class="btn-action btn-delete">Excluir</button>
      </td>
    `;
    tbody.appendChild(tr);
  });
}

// ======================== EVENTOS E TABS ========================
document.getElementById('tab-agua').addEventListener('click', () => {
  if (state.currentTab === 'agua') return;
  state.currentTab = 'agua';
  document.getElementById('tab-agua').className = 'nav-item active-nav';
  document.getElementById('tab-energia').className = 'nav-item';
  document.getElementById('tab-moradores').className = 'nav-item';
  document.getElementById('view-agua').classList.remove('hidden');
  document.getElementById('view-energia').classList.add('hidden');
  document.getElementById('view-moradores').classList.add('hidden');
  document.getElementById('table-title').textContent = 'Histórico Detalhado Água';
  updateEnergySelectorVisibility();
  cancelEdit();
  renderAgua();
  updateDashboard();
});

document.getElementById('tab-energia').addEventListener('click', () => {
  if (state.currentTab === 'energia') return;
  state.currentTab = 'energia';
  document.getElementById('tab-energia').className = 'nav-item active-nav';
  document.getElementById('tab-agua').className = 'nav-item';
  document.getElementById('tab-moradores').className = 'nav-item';
  document.getElementById('view-energia').classList.remove('hidden');
  document.getElementById('view-agua').classList.add('hidden');
  document.getElementById('view-moradores').classList.add('hidden');
  syncEnergyContextUI();
  cancelEdit();
  renderEnergia();
  updateDashboard();
});

document.getElementById('tab-moradores').addEventListener('click', () => {
  if (state.currentTab === 'moradores') return;
  state.currentTab = 'moradores';
  document.getElementById('tab-moradores').className = 'nav-item active-nav';
  document.getElementById('tab-energia').className = 'nav-item';
  document.getElementById('tab-agua').className = 'nav-item';
  document.getElementById('view-moradores').classList.remove('hidden');
  document.getElementById('view-energia').classList.add('hidden');
  document.getElementById('view-agua').classList.add('hidden');
  document.getElementById('table-title').textContent = 'Gestão de Moradores';
  updateEnergySelectorVisibility();
  cancelEdit();
  renderUsuarios();
});

// ======================== LÓGICA DE DADOS ========================
window.startEdit = function (id) {
  editingRow = id;
  if (state.currentTab === 'agua') renderAgua();
  else renderEnergia();
}

window.cancelEdit = function () {
  editingRow = null;
  if (state.currentTab === 'agua') renderAgua();
  else renderEnergia();
}

window.deleteRow = async function (table, id) {
  if (!confirm("Tem certeza que deseja apagar o registro permanentemente?")) return;
  const { error } = await supabaseClient.from(table).delete().eq('id', id);
  if (error) displayAlert("Falha ao deletar: " + error.message);
  else {
    if (table === 'consumo_agua') loadAgua();
    else loadEnergia();
  }
}

window.deletarUsuario = async function(id) {
  if (!confirm("Remover este acesso permanentemente?")) return;
  const { error } = await supabaseClient.from('usuarios').delete().eq('id', id);
  if (error) { displayAlert("Erro ao excluir!"); return; }
  await loadUsuarios();
};

window.saveAgua = async function (id) {
  const payload = {
    data_leitura: document.getElementById('ed-agua-data').value,
    leitura_cesan: document.getElementById('ed-agua-cesan').value,
    leitura_h1: document.getElementById('ed-agua-h1').value,
    leitura_h2: document.getElementById('ed-agua-h2').value,
    leitura_h3: document.getElementById('ed-agua-h3').value,
    valor_fatura_total: document.getElementById('ed-agua-fatura').value
  };
  const { error } = await supabaseClient.from('consumo_agua').update(payload).eq('id', id);
  if (error) displayAlert("Erro ao salvar no banco: " + error.message);
  else {
    editingRow = null;
    loadAgua();
  }
}

window.saveEnergia = async function (id) {
  const payload = {
    data_leitura: document.getElementById('ed-ene-data').value,
    leitura_padrao: document.getElementById('ed-ene-padrao').value,
    leitura_interno: document.getElementById('ed-ene-interno').value,
    valor_fatura_total: document.getElementById('ed-ene-fatura').value,
    leitura_interno_2: document.getElementById('ed-ene-interno-2')?.value || null,
    ...(state.energyMeter === 'av_brasil' ? { 
      leitura_interno_anterior_ref: document.getElementById('ed-ene-anterior')?.value !== '' ? Number(document.getElementById('ed-ene-anterior').value) : null,
      leitura_interno_2_anterior_ref: document.getElementById('ed-ene-anterior-2')?.value !== '' ? Number(document.getElementById('ed-ene-anterior-2').value) : null
    } : {})
  };
  const { error } = await supabaseClient.from('consumo_energia').update(payload).eq('id', id);
  if (error) displayAlert("Erro ao salvar no banco: " + error.message);
  else {
    editingRow = null;
    loadEnergia();
  }
}

let currentWaRowId = null;

window.shareWhatsApp = async function (id) {
  currentWaRowId = id;
  const { data: users, error } = await supabaseClient.from('usuarios').select('*');
  if(error) return alert("Erro ao carregar usuários");

  const listDiv = document.getElementById('wa-moradores-list');
  listDiv.innerHTML = `
    <button onclick="executeShareWhatsApp('ALL')" class="w-full text-left px-4 py-3 rounded-lg border border-slate-200 hover:border-blue-500 hover:bg-blue-50 transition flex items-center justify-between mb-2">
      <div>
        <div class="text-sm font-bold text-slate-800">Cópia do Administrador</div>
        <div class="text-[10px] text-slate-500">Relatório Completo com todos os dados</div>
      </div>
      <span class="text-blue-500">➤</span>
    </button>
  `;

  users.forEach(u => {
    if(u.perfil === 'admin') return;
    let label = u.perfil.toUpperCase();
    if (u.perfil === 'casa1') label = 'Casa 1 (Milena)';
    if (u.perfil === 'casa2') label = 'Casa 2 (Mirian)';
    if (u.perfil === 'casa3') label = 'Casa 3';
    if (u.perfil === 'loja2') label = 'Loja 02';

    listDiv.innerHTML += `
      <button onclick="executeShareWhatsApp('${u.id}')" class="w-full text-left px-4 py-3 rounded-lg border border-slate-200 hover:border-emerald-500 hover:bg-emerald-50 transition flex items-center justify-between">
        <div>
          <div class="text-sm font-bold text-slate-800">${u.nome} (${label})</div>
          <div class="text-[10px] text-slate-500">${u.senha_alterada ? 'Senha Segura' : 'Pendente de Troca de Senha'}</div>
        </div>
        <span class="text-emerald-500">➤</span>
      </button>
    `;
  });

  document.getElementById('modal-whatsapp').classList.remove('hidden');
}

window.executeShareWhatsApp = async function (userId) {
  const isAgua = state.currentTab === 'agua';
  const row = isAgua ? state.agua.find(r => r.id === currentWaRowId) : state.energia.find(r => r.id === currentWaRowId);
  if (!row) return;

  let text = '';
  
  if (userId === 'ALL') {
    text = `*Resumo de Rateio - ${fmtDateSecure(row.data_leitura).substring(3)}*\n\n`;
    if (isAgua) {
      const vpm3 = row.valor_por_m3 || (row.valor_fatura_total / Math.max(1, row.consumo_cesan));
      text += `*AGUA (CESAN)*\n`;
      text += `Consumo total: ${Number(row.consumo_cesan).toFixed(2)} m3\n`;
      text += `Valor do m3: ${fmtMoney(vpm3)}\n\n`;
      
      text += `Hidro 1 - Milena\n`;
      text += `${Number(row.consumo_h1).toFixed(2)} m3 -> *${fmtMoney(row.valor_h1)}*\n\n`;
      
      text += `Hidro 2 - Mirian\n`;
      text += `${Number(row.consumo_h2).toFixed(2)} m3 -> *${fmtMoney(row.valor_h2)}*\n\n`;
      
      text += `Hidro 3 - Maria das Gracas\n`;
      text += `${Number(row.consumo_h3).toFixed(2)} m3 -> *${fmtMoney(row.valor_h3)}*\n\n`;
      
      text += `Servico - Maria das Gracas\n`;
      text += `${Number(row.consumo_servico).toFixed(2)} m3 -> *${fmtMoney(row.valor_servico)}*`;
      
    } else {
      const vkwh = row.valor_kwh || (row.valor_fatura_total / Math.max(1, row.consumo_total_edp));
      const cfg = ENERGY_METER_CONFIG[row.local_medidor || 'rua_inhambu'] || ENERGY_METER_CONFIG.rua_inhambu;
      text += `*ENERGIA*\n`;
      text += `Consumo total: ${Number(row.consumo_total_edp).toFixed(2)} kWh\n`;
      text += `Valor do kWh: ${fmtMoney(vkwh)}\n\n`;
      
      text += `Casa 3\n`;
      text += `${Number(row.consumo_casa_2).toFixed(2)} kWh -> *${fmtMoney(row.valor_casa_2)}*\n\n`;
      
      text += `Loja 2\n`;
      text += `${Number(row.consumo_casa_1).toFixed(2)} kWh -> *${fmtMoney(row.valor_casa_1)}*`;
    }
    text += `\n\n*Acesse seu painel completo:* https://wedistinto.com/medidores/`;
  } else {
    // Para um morador específico
    document.getElementById('modal-whatsapp').classList.add('hidden');
    const { data: user } = await supabaseClient.from('usuarios').select('*').eq('id', userId).single();
    if(!user) return;
    
    text = `Ola, *${user.nome}*!\nAqui esta o seu consumo do mes de *${fmtDateSecure(row.data_leitura).substring(3)}*:\n\n`;
    
    if (isAgua) {
      const vpm3 = row.valor_por_m3 || (row.valor_fatura_total / Math.max(1, row.consumo_cesan));
      text += `*AGUA (CESAN)*\n`;
      text += `Consumo total: ${Number(row.consumo_cesan).toFixed(2)} m3\n`;
      text += `Valor do m3: ${fmtMoney(vpm3)}\n\n`;
      
      if (user.perfil === 'casa1') {
         text += `*Sua Unidade (Hidro 1) - Milena*\n`;
         text += `${Number(row.consumo_h1).toFixed(2)} m3 -> *${fmtMoney(row.row_p_valor_h1 || row.valor_h1)}*\n`;
      } else if (user.perfil === 'casa2') {
         text += `*Sua Unidade (Hidro 2) - Mirian*\n`;
         text += `${Number(row.consumo_h2).toFixed(2)} m3 -> *${fmtMoney(row.row_p_valor_h2 || row.valor_h2)}*\n`;
      } else if (user.perfil === 'casa3') {
         text += `*Sua Unidade (Hidro 3) - Casa 3*\n`;
         text += `${Number(row.consumo_h3).toFixed(2)} m3 -> *${fmtMoney(row.row_p_valor_h3 || row.valor_h3)}*\n`;
      } else {
         text += `*Sua Unidade*\nNao foi possivel associar seu cadastro automaticamente.\n`;
      }
    } else if (user.perfil === 'loja2') {
      const { data: allU } = await supabaseClient.from('usuarios').select('nome, perfil');
      const c3 = allU.find(u => u.perfil === 'casa3');
      const c3Name = c3 ? c3.nome : 'Casa 3';
      const vkwh = row.valor_fatura_total / Math.max(1, row.consumo_total_edp);
      const ref = fmtDateSecure(row.data_leitura).substring(3);

      text = `*Resumo da Divisao de Energia - Ref. ${ref}*\n\n`;
      text += `Ola, *${user.nome}*! Segue o detalhamento da conta de energia deste mes para conferencia:\n\n`;
      text += `1. *Dados da Fatura (EDP):*\n\n`;
      text += `* Consumo Total: ${Number(row.consumo_total_edp).toFixed(2)} kWh\n`;
      text += `* Valor Total: ${fmtMoney(row.valor_fatura_total)}\n`;
      text += `* Valor do kWh (real): ${fmtMoney(vkwh)}\n\n`;
      
      text += `3. *Divisao Final:*\n\n`;
      text += `* ${c3Name}:\n`;
      text += `* Consumo: ${Number(row.consumo_casa_2).toFixed(2)} kWh\n`;
      text += `* Valor a pagar: ${fmtMoney(row.row_p_valor_casa_2 || row.valor_casa_2)}\n\n`;
      
      text += `* ${user.nome}:\n`;
      text += `* Consumo (Diferenca do total): ${Number(row.consumo_casa_1).toFixed(2)} kWh\n`;
      text += `* Valor a pagar: ${fmtMoney(row.valor_casa_1)}\n\n`;
      
      text += `---\n\n`;
      text += `Total da Fatura: ${fmtMoney(row.valor_fatura_total)}`;
    } else {
      const vkwh = row.valor_kwh || (row.valor_fatura_total / Math.max(1, row.consumo_total_edp));
      const cfg = ENERGY_METER_CONFIG[row.local_medidor || 'rua_inhambu'] || ENERGY_METER_CONFIG.rua_inhambu;
      text += `*ENERGIA (${cfg.label})*\n`;
      text += `Consumo total: ${Number(row.consumo_total_edp).toFixed(2)} kWh\n`;
      text += `Valor do kWh: ${fmtMoney(vkwh)}\n\n`;
      
      if (user.perfil === 'casa1') {
         text += `*Sua Unidade (Casa 1 - Milena)*\n`;
         text += `${Number(row.consumo_casa_1).toFixed(2)} kWh -> *${fmtMoney(row.valor_casa_1)}*\n`;
      } else if (user.perfil === 'casa2' || user.perfil === 'casa3') {
         const label = user.perfil === 'casa3' ? 'Casa 3' : 'Casa 2 (Mirian)';
         text += `*Sua Unidade (${label})*\n`;
         text += `${Number(row.consumo_casa_2).toFixed(2)} kWh -> *${fmtMoney(row.row_p_valor_casa_2 || row.valor_casa_2)}*\n`;
      }
    }
    
    text += `\n\n*Acesse seu painel completo:* https://wedistinto.com/medidores/`;
    
    if (!user.senha_alterada) {
       text += `\n\n*Aviso de Privacidade:*\nSeu usuario: ${user.nome}\nSua senha provisoria: ${user.senha}\nO acesso exigira uma redefinicao obrigatoria pelo aplicativo.`;
    }
  }

  const url = `https://wa.me/?text=${encodeURIComponent(text)}`;
  window.open(url, '_blank');
}

// ======================== MODAL INSERIR ========================
window.abrirModal = function() {
  document.getElementById('modal-inserir').classList.remove('hidden');
  document.getElementById('ins-data').value = new Date().toISOString().slice(0, 10);
  document.getElementById('ins-fatura').value = '';
  
  if (state.currentTab === 'agua') {
    document.getElementById('modal-title').textContent = 'Nova Leitura de Água';
    document.getElementById('fields-agua').classList.remove('hidden');
    document.getElementById('fields-energia').classList.add('hidden');
    document.getElementById('ai-upload-agua').classList.remove('hidden');
    document.getElementById('ai-upload-energia').classList.add('hidden');
    document.getElementById('ins-ag-cesan').value = '';
    document.getElementById('ins-ag-h1').value = '';
    document.getElementById('ins-ag-h2').value = '';
    document.getElementById('ins-ag-h3').value = '';
  } else {
    const cfg = getEnergyConfig();
    const latestRow = getVisibleEnergiaRows()[0];
    document.getElementById('modal-title').textContent = cfg.modalTitle;
    document.getElementById('fields-energia').classList.remove('hidden');
    document.getElementById('fields-agua').classList.add('hidden');
    document.getElementById('ai-upload-energia').classList.remove('hidden');
    document.getElementById('ai-upload-agua').classList.add('hidden');
    document.getElementById('lbl-en-padrao').textContent = cfg.padraoLabel;
    document.getElementById('lbl-en-interno').textContent = cfg.internoLabel;
    document.getElementById('lbl-en-anterior').textContent = cfg.previousLabel;
    document.querySelector('#card-en-fat span').innerHTML = cfg.uploadFaturaLabel;
    document.querySelector('#card-en-int span').innerHTML = cfg.uploadInternoLabel;
    document.getElementById('ins-en-padrao').value = '';
    document.getElementById('ins-en-interno').value = '';
    document.getElementById('ins-en-interno-2').value = '';
    document.getElementById('ins-en-interno-anterior').value = latestRow && cfg.usePreviousRef ? Number(latestRow.leitura_interno).toFixed(2) : '';
    document.getElementById('ins-en-interno-anterior-2').value = latestRow && cfg.usePreviousRef && latestRow.leitura_interno_2 ? Number(latestRow.leitura_interno_2).toFixed(2) : '';
    
    document.getElementById('en-prev-wrap').classList.toggle('hidden', !cfg.usePreviousRef);
    document.getElementById('en-prev-2-wrap').classList.toggle('hidden', !cfg.useSecondMeter);
    document.getElementById('en-int-2-wrap').classList.toggle('hidden', !cfg.useSecondMeter);
    
    if (cfg.useSecondMeter) {
      document.getElementById('lbl-en-anterior-2').textContent = cfg.previous2Label;
      document.getElementById('lbl-en-interno-2').textContent = cfg.interno2Label;
    }

    refreshEnergiaPreview();

    if (cfg.key === 'av_brasil') {
      preencherCasa3Tuya();
    }
  }
}

window.tagFile = function(cardId) {
  document.getElementById(cardId).classList.add('has-file');
}

// Convert File helper
const fileToBase64 = (file) => new Promise((resolve) => {
  const reader = new FileReader();
  reader.onloadend = () => resolve({ mimeType: file.type, data: reader.result.split(',')[1] });
  reader.readAsDataURL(file);
});

window.processarImagens = async function() {
  const btn = document.getElementById('btn-ai-process');
  const loader = document.getElementById('ai-loading');
  
  if(!confirm("Tem certeza que deseja processar essas imagens com IA? Pode levar alguns segundos...")) return;
  
  btn.classList.add('hidden');
  loader.classList.remove('hidden');

  try {
    // A chave agora é gerenciada com segurança via Supabase Edge Functions.
    // O request é feito "lado servidor" para proteger as credenciais.

    // 2. Agrupar as fotos escolhidas
    const inlineDataArr = [];
    let prompt_text = "";

    if (state.currentTab === 'agua') {
      prompt_text = `Você é um especialista em OCR e extração de dados estruturados. Sua tarefa é processar imagens de faturas de água (CESAN) e fotos de hidrômetros para fins de gestão financeira.

### REGRAS DE EXTRAÇÃO:

1. **Fatura CESAN:**
   - Localize o valor no campo "TOTAL A PAGAR" (ex: R$ 539,02).
   - Localize o número no campo "LEITURA ATUAL" dentro da seção de informações de leitura (ex: 1587).

2. **Hidrômetros (Fotos):**
   - Identifique apenas os dígitos nos roletes de cor **preta**. Ignore completamente os dígitos vermelhos.
   - **Lógica de Formatação (Dígito Inicial):** - Analise o primeiro dígito da sequência preta identificada.
     - SE o primeiro dígito for igual a "0": Remova-o e retorne apenas os números restantes (exemplo: "0365" deve ser retornado como "365").
     - SE o primeiro dígito for maior que "0": Retorne o número completo (exemplo: "1365" deve ser retornado como "1365").

### INFORMAÇÃO DOS ARQUIVOS:
Você receberá as imagens na seguinte ordem exata:`;

      const f1 = document.getElementById('up-ag-fatura').files[0];
      const f2 = document.getElementById('up-ag-h1').files[0];
      const f3 = document.getElementById('up-ag-h2').files[0];
      const f4 = document.getElementById('up-ag-h3').files[0];
      
      let imgCount = 1;
      if(f1) { inlineDataArr.push(await fileToBase64(f1)); prompt_text += `\n- Imagem ${imgCount++}: Fatura`; }
      if(f2) { inlineDataArr.push(await fileToBase64(f2)); prompt_text += `\n- Imagem ${imgCount++}: Hidrômetro com identificador "h1"`; }
      if(f3) { inlineDataArr.push(await fileToBase64(f3)); prompt_text += `\n- Imagem ${imgCount++}: Hidrômetro com identificador "h2"`; }
      if(f4) { inlineDataArr.push(await fileToBase64(f4)); prompt_text += `\n- Imagem ${imgCount++}: Hidrômetro com identificador "h3"`; }
      
      prompt_text += `

### FORMATO DE SAÍDA:
Retorne os dados estritamente em formato JSON para integração de sistema:

{
  "fatura": {
    "valor_total": 539.02,
    "leitura_fatura": 1587
  },
  "medidores_individuais": [
    {
      "identificador": "h1",
      "leitura_processada": "154"
    }
  ]
}

### RESTRIÇÕES:
- Não adicione comentários, explicações ou texto introdutório fora do JSON.
- Se a imagem estiver ilegível, retorne "null" no campo correspondente.`;

    } else {
      const cfg = getEnergyConfig();
      prompt_text = "Você é um robô de leitura de medidores. Analise IMAGEM por IMAGEM estritamente nas regras abaixo e retorne APENAS um JSON limpo:\n\n";
      let formatJson = {};
      let currentImageIndex = 1;
      
      const f1 = document.getElementById('up-en-fatura').files[0];
      const f2 = document.getElementById('up-en-interno').files[0];
      
      if(f1) {
        inlineDataArr.push(await fileToBase64(f1));
        prompt_text += cfg.key === 'av_brasil'
          ? `IMAGEM ${currentImageIndex} [Fatura EDP]: Extraia 'faturaTotal' (valor total a pagar). Extraia APENAS o campo "Cons. Consumo kWh" ou o total de consumo em kWh destacado no quadro do medidor e salve em 'leituraPadrao'.\n`
          : `IMAGEM ${currentImageIndex} [Fatura EDP]: Extraia 'faturaTotal' (o Valor a Pagar float, ex: 200.50). Extraia a Leitura do Mês faturada ou Consumo ativo (número) e salve em 'leituraPadrao'.\n`;
        formatJson.faturaTotal = 200.50;
        formatJson.leituraPadrao = 2500;
        currentImageIndex++;
      }
      if(f2) {
        inlineDataArr.push(await fileToBase64(f2));
        prompt_text += cfg.key === 'av_brasil'
          ? `IMAGEM ${currentImageIndex} [Whatímetro Interno]: Extraia o valor "Total Ele" exibido no aplicativo e salve em 'leituraInterno'.\n`
          : `IMAGEM ${currentImageIndex} [Relógio Interno]: Extraia o número total da leitura exibida no display. Salve em 'leituraInterno'.\n`;
        formatJson.leituraInterno = 3500;
        currentImageIndex++;
      }
      prompt_text += `\nFORNEÇA EXATAMENTE O JSON ABAIXO (sem markdown e sem texto antes/depois) com os valores reais encontrados:\n${JSON.stringify(formatJson)}`;
    }

    if (inlineDataArr.length === 0) {
      alert("Selecione fotos para analisar.");
      throw new Error("Nenhuma foto selecionada");
    }

    // Chamar a Edge Function segura do Supabase (A chave fica escondida lá)
    const { data: data, error: fnError } = await supabaseClient.functions.invoke('ocr-gemini', {
      body: {
        contents: [{
          parts: [
            { text: prompt_text },
            ...inlineDataArr.map(id => ({ inlineData: id }))
          ]
        }],
        generationConfig: {
          responseMimeType: "application/json"
        }
      }
    });

    if (fnError) throw new Error(fnError.message);
    
    let textOut = data.candidates[0].content.parts[0].text;
    textOut = textOut.replace(/```json/g, '').replace(/```/g, '').trim(); 
    
    // Fallback: the API sometimes might still output things before/after brackets
    const jsonStart = textOut.indexOf('{');
    const jsonEnd = textOut.lastIndexOf('}');
    if (jsonStart !== -1 && jsonEnd !== -1) {
      textOut = textOut.substring(jsonStart, jsonEnd + 1);
    }
    
    const obj = JSON.parse(textOut);

    // Auto-Preenchimento
    if (state.currentTab === 'agua') {
      if(obj.fatura) {
        if(obj.fatura.valor_total) document.getElementById('ins-fatura').value = obj.fatura.valor_total;
        if(obj.fatura.leitura_fatura) document.getElementById('ins-ag-cesan').value = obj.fatura.leitura_fatura;
      }
      if(obj.medidores_individuais) {
        for (const medidor of obj.medidores_individuais) {
          if(medidor.identificador === 'h1' && medidor.leitura_processada) document.getElementById('ins-ag-h1').value = medidor.leitura_processada;
          if(medidor.identificador === 'h2' && medidor.leitura_processada) document.getElementById('ins-ag-h2').value = medidor.leitura_processada;
          if(medidor.identificador === 'h3' && medidor.leitura_processada) document.getElementById('ins-ag-h3').value = medidor.leitura_processada;
        }
      }
    } else {
      if(obj.faturaTotal) document.getElementById('ins-fatura').value = obj.faturaTotal;
      if(obj.leituraPadrao) document.getElementById('ins-en-padrao').value = obj.leituraPadrao;
      if(obj.leituraInterno) document.getElementById('ins-en-interno').value = obj.leituraInterno;
      refreshEnergiaPreview();
    }

  } catch(e) {
    console.error(e);
    alert("Falha na automação Gemini OCR. Verifique as fotos enviadas.");
  } finally {
    btn.classList.remove('hidden');
    loader.classList.add('hidden');
  }
}

window.fecharModal = function() {
  document.getElementById('modal-inserir').classList.add('hidden');
}

window.resetSenha = async function(id) {
  const nova = prompt("Digite a nova senha para este morador:");
  if (nova && nova.trim() !== "") {
    const { error } = await supabaseClient.from('usuarios').update({senha: nova}).eq('id', id);
    if(error) displayAlert('Erro ao atualizar: ' + error.message);
    else { alert('Senha atualizada com sucesso!'); loadUsuarios(); }
  }
}

// =================== INIT E LISTENERS ===================
async function initAdmin() {
  if (localStorage.getItem('admin_logged') !== 'true') return window.location.href = 'index.html';
  
  await loadAgua();
  await loadEnergia();
  await loadUsuarios();
  syncEnergyContextUI();
  setupClipboard();

  document.getElementById('energia-meter-select')?.addEventListener('change', (e) => {
    state.energyMeter = e.target.value;
    if (state.currentTab === 'energia') {
      syncEnergyContextUI();
      cancelEdit();
      renderEnergia();
      updateDashboard();
    }
  });
  document.getElementById('ins-en-padrao')?.addEventListener('input', refreshEnergiaPreview);
  document.getElementById('ins-en-interno')?.addEventListener('input', refreshEnergiaPreview);
  document.getElementById('ins-en-interno-anterior')?.addEventListener('input', refreshEnergiaPreview);
  document.getElementById('ins-fatura')?.addEventListener('input', refreshEnergiaPreview);
  
  const formUsr = document.getElementById('form-usuario');
  if(formUsr) {
    formUsr.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = formUsr.querySelector('button[type="submit"]');
      btn.disabled = true; btn.textContent = 'Aguarde...';
      const nome = document.getElementById('ipt-usr-nome').value.trim();
      const senha = document.getElementById('ipt-usr-senha').value;
      const perfil = document.getElementById('ipt-usr-perfil').value;
      
      const { error } = await supabaseClient.from('usuarios').insert({ nome, senha, perfil });
      if(error) { displayAlert("Erro ao criar: " + error.message); }
      else { formUsr.reset(); await loadUsuarios(); alert("Criado com sucesso!"); }
      
      btn.disabled = false; btn.textContent = 'Criar Morador';
    });
  }

  const formInsert = document.getElementById('form-inserir');
  if(formInsert) {
    formInsert.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = formInsert.querySelector('button[type="submit"]');
      btn.disabled = true; btn.textContent = 'Salvando...';

      const dataLeitura = document.getElementById('ins-data').value;
      const dateStr = dataLeitura + "T12:00:00Z";
      const fatura = document.getElementById('ins-fatura').value;

      if (state.currentTab === 'agua') {
        const payload = {
          id: createRowId(),
          data_leitura: dateStr,
          leitura_cesan: document.getElementById('ins-ag-cesan').value || 0,
          leitura_h1: document.getElementById('ins-ag-h1').value || 0,
          leitura_h2: document.getElementById('ins-ag-h2').value || 0,
          leitura_h3: document.getElementById('ins-ag-h3').value || 0,
          valor_fatura_total: fatura || 0
        };
        const { error } = await supabaseClient.from('consumo_agua').insert(payload);
        if (error) displayAlert("Erro água: " + error.message);
        else { formInsert.reset(); document.querySelectorAll('.upload-card').forEach(el => el.classList.remove('has-file')); fecharModal(); await loadAgua(); }
      } else {
        const cfg = getEnergyConfig();
        const { totalConsumo, leituraAtual, leituraAnterior, valorFatura, valorKwh, consumoInterno, consumoExterno } = getEnergyInsertPayload();

        const payload = {
          id: createRowId(),
          data_leitura: dateStr,
          valor_fatura_total: fatura || 0,
          leitura_padrao: document.getElementById('ins-en-padrao').value || 0,
          leitura_interno: document.getElementById('ins-en-interno').value || 0,
          leitura_interno_2: document.getElementById('ins-en-interno-2').value || null,
          local_medidor: state.energyMeter,
          leitura_interno_anterior_ref: state.energyMeter === 'av_brasil' ? (document.getElementById('ins-en-interno-anterior').value || 0) : null,
          leitura_interno_2_anterior_ref: state.energyMeter === 'av_brasil' ? (document.getElementById('ins-en-interno-anterior-2').value || 0) : null
        };
        const { error } = await supabaseClient.from('consumo_energia').insert(payload);
        if (error) displayAlert("Erro energia: " + error.message);
        else { formInsert.reset(); document.querySelectorAll('.upload-card').forEach(el => el.classList.remove('has-file')); fecharModal(); await loadEnergia(); }
      }
      btn.disabled = false; btn.textContent = 'Salvar Registo Definitivo';
    });
  }
}

document.addEventListener('DOMContentLoaded', initAdmin);

// =================== CLIPBOARD / PASTE SUPPORT ===================
let activePasteCard = null;

async function colarDoClipboard(card) {
  // Garante o foco na janela para evitar a excecao "Document is not focused" do Chrome
  window.focus();
  
  try {
    if (!navigator.clipboard || !navigator.clipboard.read) {
      alert("Seu navegador não suporta colar diretamente via botão ou requer conexão segura (HTTPS). Por favor, clique no card e aperte Ctrl+V no teclado.");
      return false;
    }
    const clipboardItems = await navigator.clipboard.read();
    let imagemEncontrada = false;
    
    for (const item of clipboardItems) {
      for (const type of item.types) {
        if (type.startsWith('image/')) {
          const blob = await item.getType(type);
          const input = card.querySelector('input[type="file"]');
          
          const dataTransfer = new DataTransfer();
          dataTransfer.items.add(blob);
          input.files = dataTransfer.files;
          
          input.dispatchEvent(new Event('change', { bubbles: true }));
          console.log(`Imagem colada via Clipboard API no card ${card.id}`);
          
          // Remove destaque do card ativo
          card.classList.remove('active-drop');
          if (activePasteCard === card) activePasteCard = null;
          imagemEncontrada = true;
          return true;
        }
      }
    }
    
    if (!imagemEncontrada) {
      alert("Nenhuma imagem encontrada na área de transferência! Por favor, tire um print ou copie uma imagem (não texto) antes de clicar em colar.");
    }
  } catch (err) {
    console.error("Erro detalhado ao acessar o clipboard:", err);
    
    const msgErro = err.message || "";
    if (msgErro.includes("empty") || msgErro.includes("no supported data") || msgErro.includes("Clipboard is empty")) {
      alert("Sua área de transferência está vazia ou não contém uma imagem. Por favor, tire um print (Print Screen) ou copie uma imagem antes de colar!");
    } else if (msgErro.includes("NotAllowedError") || msgErro.includes("permission") || msgErro.includes("denied")) {
      alert("Permissão negada ou bloqueada pelo navegador. Você pode simplesmente clicar no card (ele ficará com borda azul) e apertar Ctrl+V no seu teclado para colar!");
    } else {
      alert("Não foi possível acessar a área de transferência automaticamente neste navegador. Mas não se preocupe! Você pode colar usando o atalho de teclado: selecione o card e aperte Ctrl+V.");
    }
  }
  return false;
}

function setupClipboard() {
  document.querySelectorAll('.upload-card').forEach(card => {
    // Escuta o clique no card geral
    card.addEventListener('click', (e) => {
      // Remove destaque de todos e coloca no atual
      document.querySelectorAll('.upload-card').forEach(c => c.classList.remove('active-drop'));
      card.classList.add('active-drop');
      activePasteCard = card;
      
      // Se o clique foi no botão de colar (btn-paste), colamos diretamente do clipboard
      if (e.target.closest('.btn-paste')) {
        e.preventDefault();
        e.stopPropagation();
        colarDoClipboard(card);
        return;
      }
      
      // Se o clique foi no botão de upload (btn-upload) ou na área geral (sem ser os botões de ação)
      if (e.target.closest('.btn-upload') || !e.target.closest('button')) {
        // Disparar o input de arquivo
        card.querySelector('input[type="file"]').click();
      }
    });
  });

  // Escuta o evento global de colar (Ctrl+V) para qualquer card que esteja focado (active-drop)
  document.addEventListener('paste', async (e) => {
    if (!activePasteCard) {
      console.warn("Nenhum card selecionado para colar a imagem.");
      return;
    }
    
    const items = (e.clipboardData || e.originalEvent.clipboardData).items;
    for (let i = 0; i < items.length; i++) {
      if (items[i].type.indexOf('image') !== -1) {
        const blob = items[i].getAsFile();
        const input = activePasteCard.querySelector('input[type="file"]');
        
        // Usar DataTransfer para simular a selecao do arquivo no input
        const dataTransfer = new DataTransfer();
        dataTransfer.items.add(blob);
        input.files = dataTransfer.files;
        
        // Disparar o evento change manualmente para o navegador saber que mudou
        input.dispatchEvent(new Event('change', { bubbles: true }));
        
        // Remove o destaque de "selecionado"
        activePasteCard.classList.remove('active-drop');
        activePasteCard = null;
        
        console.log("Imagem colada com sucesso no card!");
        break;
      }
    }
  });
}

// =================== SINCRONIZAÇÃO TUYA ===================
async function sincronizarTuya() {
  const btn = document.getElementById('btn-sync-tuya');
  const icon = document.getElementById('sync-icon');
  
  if (!btn || btn.disabled) return;
  
  btn.disabled = true;
  btn.classList.add('opacity-50', 'cursor-not-allowed');
  icon.classList.add('animate-spin');
  btn.innerHTML = `
    <svg class="animate-spin w-3.5 h-3.5" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
    Sincronizando...
  `;
  
  try {
    const response = await fetch('sync.php');
    const result = await response.json();
    
    if (result.success) {
      alert("✅ Sincronização Concluída!\n\nDados extraídos: " + result.output);
      // Recarrega a tabela de energia se estiver ativa
      if (typeof loadEnergia === 'function') await loadEnergia();
    } else {
      alert("❌ Falha na Sincronização:\n\n" + (result.message || result.output));
      console.error(result);
    }
  } catch (error) {
    alert("❌ Erro ao chamar o sincronizador: " + error.message);
    console.error(error);
  } finally {
    btn.disabled = false;
    btn.classList.remove('opacity-50', 'cursor-not-allowed');
    btn.innerHTML = `
      <svg id="sync-icon" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
      Sincronizar Tuya (Casa 2)
    `;
  }
}

window.sincronizarTuya = sincronizarTuya;

// =================== TUYA AUTO-FILL (Casa 3 Whatímetro) ===================
async function fetchLatestTuyaReading() {
  const { data, error } = await supabaseClient
    .from('leitura_energia')
    .select('valor_extraido, data_leitura')
    .order('data_leitura', { ascending: false })
    .limit(1);
  if (error || !data || data.length === 0) return null;
  return data[0];
}

async function preencherCasa3Tuya() {
  const btnRefresh = document.getElementById('btn-refresh-tuya');
  if (state.energyMeter !== 'av_brasil') {
    if (btnRefresh) btnRefresh.classList.add('hidden');
    return;
  }
  if (btnRefresh) btnRefresh.classList.remove('hidden');
  const reading = await fetchLatestTuyaReading();
  if (!reading) return;

  const input = document.getElementById('ins-en-interno');
  if (input) input.value = reading.valor_extraido;

  const badge = document.getElementById('tuya-last-update');
  if (badge) {
    const d = new Date(reading.data_leitura);
    const diffMin = Math.round((Date.now() - d.getTime()) / 60000);
    let agoText;
    if (diffMin < 1) agoText = 'agora';
    else if (diffMin < 60) agoText = `há ${diffMin}min`;
    else if (diffMin < 1440) agoText = `há ${Math.round(diffMin / 60)}h`;
    else agoText = `há ${Math.round(diffMin / 1440)}d`;
    badge.textContent = `Tuya: ${reading.valor_extraido} kWh (${agoText})`;
    badge.classList.remove('hidden');
  }

  refreshEnergiaPreview();
}

window.refreshTuyaReading = async function() {
  const btn = document.getElementById('btn-refresh-tuya');
  if (btn) { btn.disabled = true; btn.textContent = '...'; }
  await preencherCasa3Tuya();
  if (btn) { btn.disabled = false; btn.textContent = '↻'; }
}
