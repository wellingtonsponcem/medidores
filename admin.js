const SUPABASE_URL = "https://jmdlraaprcztshkfyeud.supabase.co";
const SUPABASE_KEY = "sb_publishable_2c7MrFAZaCiQzY_EuQuizQ_wTgAzbIC";
let supabase = null;

const state = { agua: [], energia: [], currentTab: 'agua' };
let editingRow = null;

function fmtDateSecure(raw) {
  if (!raw) return '-';
  const s = String(raw).trim();
  const brMatch = s.match(/^(\d{2})[\/\-](\d{2})[\/\-](\d{4})$/);
  if (brMatch) return brMatch[1] + "/" + brMatch[2] + "/" + brMatch[3];
  const isoMatch = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (isoMatch) return isoMatch[3] + "/" + isoMatch[2] + "/" + isoMatch[1];
  const d = new Date(s);
  if (!isNaN(d.getTime())) return d.toLocaleDateString('pt-BR', { timeZone: 'UTC' });
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
  const { data, error } = await supabase.from('consumo_agua').select('*').order('data_leitura', { ascending: false });
  if (!error) {
    state.agua = data;
    renderAgua();
  } else { alert("Erro ao carregar água: " + error.message); }
}

async function loadEnergia() {
  const { data, error } = await supabase.from('consumo_energia').select('*').order('data_leitura', { ascending: false });
  if (!error) {
    state.energia = data;
    renderEnergia();
  } else { alert("Erro ao carregar energia: " + error.message); }
}

// ======================== RENDERIZAÇÃO ========================
function renderAgua() {
  const tbody = document.getElementById('tbody-agua');
  tbody.innerHTML = '';
  if (state.agua.length === 0) {
    tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-slate-500">Nenhum registro.</td></tr>';
    return;
  }

  state.agua.forEach(row => {
    const tr = document.createElement('tr');
    if (editingRow === row.id) {
      tr.innerHTML = `
        <td><input type="date" id="ed-agua-data" class="edit-input date-input" value="${fmtDateForInput(row.data_leitura)}"></td>
        <td><input type="number" id="ed-agua-cesan" class="edit-input" value="${row.leitura_cesan}"></td>
        <td><input type="number" id="ed-agua-h1" class="edit-input" value="${row.leitura_h1}"></td>
        <td><input type="number" id="ed-agua-h2" class="edit-input" value="${row.leitura_h2}"></td>
        <td><input type="number" id="ed-agua-h3" class="edit-input" value="${row.leitura_h3}"></td>
        <td><input type="number" id="ed-agua-fatura" class="edit-input" value="${row.valor_fatura_total}"></td>
        <td>
          <button class="btn-action btn-save" onclick="saveAgua('${row.id}')">Salvar</button>
          <button class="btn-action" onclick="cancelEdit()">Cancelar</button>
        </td>
      `;
    } else {
      tr.innerHTML = `
        <td>${fmtDateSecure(row.data_leitura)}</td>
        <td>${row.leitura_cesan}</td>
        <td>${row.leitura_h1}</td>
        <td>${row.leitura_h2}</td>
        <td>${row.leitura_h3}</td>
        <td>R$ ${Number(row.valor_fatura_total).toFixed(2)}</td>
        <td>
          <button class="btn-action btn-edit" onclick="startEdit('${row.id}')">Editar</button>
          <button class="btn-action btn-delete" onclick="deleteRow('consumo_agua', '${row.id}')">Excluir</button>
        </td>
      `;
    }
    tbody.appendChild(tr);
  });
}

function renderEnergia() {
  const tbody = document.getElementById('tbody-energia');
  tbody.innerHTML = '';
  if (state.energia.length === 0) {
    tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-slate-500">Nenhum registro.</td></tr>';
    return;
  }

  state.energia.forEach(row => {
    const tr = document.createElement('tr');
    if (editingRow === row.id) {
      tr.innerHTML = `
        <td><input type="date" id="ed-ene-data" class="edit-input date-input" value="${fmtDateForInput(row.data_leitura)}"></td>
        <td><input type="number" id="ed-ene-padrao" class="edit-input" value="${row.leitura_padrao}"></td>
        <td><input type="number" id="ed-ene-interno" class="edit-input" value="${row.leitura_interno}"></td>
        <td><input type="number" id="ed-ene-fatura" class="edit-input" value="${row.valor_fatura_total}"></td>
        <td>
          <button class="btn-action btn-save" onclick="saveEnergia('${row.id}')">Salvar</button>
          <button class="btn-action" onclick="cancelEdit()">Cancelar</button>
        </td>
      `;
    } else {
      tr.innerHTML = `
        <td>${fmtDateSecure(row.data_leitura)}</td>
        <td>${row.leitura_padrao}</td>
        <td>${row.leitura_interno}</td>
        <td>R$ ${Number(row.valor_fatura_total).toFixed(2)}</td>
        <td>
          <button class="btn-action btn-edit" onclick="startEdit('${row.id}')">Editar</button>
          <button class="btn-action btn-delete" onclick="deleteRow('consumo_energia', '${row.id}')">Excluir</button>
        </td>
      `;
    }
    tbody.appendChild(tr);
  });
}

// ======================== TABS ========================
document.getElementById('tab-agua').addEventListener('click', () => {
  state.currentTab = 'agua';
  document.getElementById('tab-agua').className = 'nav-btn active';
  document.getElementById('tab-energia').className = 'nav-btn';
  document.getElementById('view-agua').classList.remove('hidden');
  document.getElementById('view-energia').classList.add('hidden');
  cancelEdit();
});

document.getElementById('tab-energia').addEventListener('click', () => {
  state.currentTab = 'energia';
  document.getElementById('tab-energia').className = 'nav-btn active';
  document.getElementById('tab-agua').className = 'nav-btn';
  document.getElementById('view-energia').classList.remove('hidden');
  document.getElementById('view-agua').classList.add('hidden');
  cancelEdit();
});

// ======================== AÇÕES ========================
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
  if (!confirm("Tem certeza que deseja excluir permanentemente este registro?")) return;
  const { error } = await supabase.from(table).delete().eq('id', id);
  if (error) alert("Erro ao excluir: " + error.message);
  else {
    if (table === 'consumo_agua') loadAgua();
    else loadEnergia();
  }
}

window.saveAgua = async function (id) {
  const payload = {
    data_leitura: document.getElementById('ed-agua-data').value,
    leitura_cesan: document.getElementById('ed-agua-cesan').value,
    leitura_h1: document.getElementById('ed-agua-h1').value,
    leitura_h2: document.getElementById('ed-agua-h2').value,
    leitura_h3: document.getElementById('ed-agua-h3').value,
    valor_fatura_total: document.getElementById('ed-agua-fatura').value
  };
  const { error } = await supabase.from('consumo_agua').update(payload).eq('id', id);
  if (error) alert("Erro: " + error.message);
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
    valor_fatura_total: document.getElementById('ed-ene-fatura').value
  };
  const { error } = await supabase.from('consumo_energia').update(payload).eq('id', id);
  if (error) alert("Erro: " + error.message);
  else {
    editingRow = null;
    loadEnergia();
  }
}

  // INICIALIZAÇÃO
  (async function init() {
    try {
      supabase = window.supabase.createClient(SUPABASE_URL, SUPABASE_KEY);
      loadAgua();
      loadEnergia();
    } catch(err) {
      console.error(err);
    }
  })();
