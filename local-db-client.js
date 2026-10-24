// local-db-client.js
// Wrapper compatível com o Supabase JS Client para direcionar operações para o banco local MySQL via api.php
// Suporta MODO DESENVOLVEDOR (Simulação Sandbox com persistência em sessionStorage)

const DEV_MODE_KEY = 'dev_mode_active';
const DEV_SHADOW_KEY = 'dev_shadow_db';
const VIRTUAL_DEV_USER = {
  id: 'dev-mode-user',
  nome: 'Desenvolvedor (Modo Dev)',
  senha: 'dev',
  perfil: 'admin',
  senha_alterada: true,
  ativo: 1
};

function getApiUrl(queryParams = {}) {
  const isLocal = typeof window !== 'undefined' && (
    window.location.hostname === 'localhost' ||
    window.location.hostname === '127.0.0.1' ||
    window.location.protocol === 'file:'
  );
  
  const baseUrl = isLocal ? 'https://wedistinto.com/medidores/api.php' : 'api.php';
  const queryString = new URLSearchParams(queryParams).toString();
  return queryString ? `${baseUrl}?${queryString}` : baseUrl;
}

// Token da sessão emitido pela api.php no login. É ele que o servidor confere —
// o userAuth no localStorage serve só para a tela saber o nome e o perfil.
const AUTH_TOKEN_KEY = 'authToken';

function limparSessao() {
  localStorage.removeItem('userAuth');
  localStorage.removeItem('admin_logged');
  localStorage.removeItem(AUTH_TOKEN_KEY);
  sessionStorage.removeItem('ignored_pwd');
}

function irParaLogin() {
  limparSessao();
  if (!window.location.pathname.endsWith('index.html') && !window.location.pathname.endsWith('/')) {
    window.location.href = 'index.html';
  }
}

/**
 * fetch com o token da sessão. Toda chamada à api.php e ao sync.php passa por
 * aqui; sessão expirada (401) devolve para o login, exceto no Modo Dev, que
 * é simulação e não tem sessão no servidor.
 */
async function apiFetch(url, options = {}) {
  const headers = Object.assign({}, options.headers || {});
  const token = localStorage.getItem(AUTH_TOKEN_KEY);
  if (token) headers['X-Auth-Token'] = token;
  const res = await fetch(url, Object.assign({}, options, { headers }));
  if (res.status === 401 && !localClient.isDevMode()) {
    irParaLogin();
  }
  return res;
}

/** Encerra a sessão no servidor e volta para o login. */
async function sair() {
  try {
    await apiFetch(getApiUrl({ action: 'logout' }), { method: 'POST' });
  } catch (e) {}
  sessionStorage.removeItem(DEV_MODE_KEY);
  sessionStorage.removeItem(DEV_SHADOW_KEY);
  limparSessao();
  window.location.href = 'index.html';
}
window.sair = sair;
window.apiFetch = apiFetch;

async function getShadowTable(tableName) {
  let shadow = {};
  try {
    shadow = JSON.parse(sessionStorage.getItem(DEV_SHADOW_KEY) || '{}');
  } catch(e) { shadow = {}; }

  if (shadow[tableName]) {
    return shadow[tableName];
  }

  // Busca snapshot inicial direto da api.php
  try {
    const res = await apiFetch(getApiUrl({ table: tableName }));
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const json = await res.json();
    let rows = json.data || [];
    if (!Array.isArray(rows)) {
      rows = rows ? [rows] : [];
    }
    shadow[tableName] = rows;
    sessionStorage.setItem(DEV_SHADOW_KEY, JSON.stringify(shadow));
    return rows;
  } catch(e) {
    console.warn(`[Modo Dev] Não foi possível carregar ${tableName} via api.php, usando tabela vazia.`, e);
    shadow[tableName] = [];
    sessionStorage.setItem(DEV_SHADOW_KEY, JSON.stringify(shadow));
    return [];
  }
}

function saveShadowTable(tableName, rows) {
  let shadow = {};
  try {
    shadow = JSON.parse(sessionStorage.getItem(DEV_SHADOW_KEY) || '{}');
  } catch(e) { shadow = {}; }
  shadow[tableName] = rows;
  sessionStorage.setItem(DEV_SHADOW_KEY, JSON.stringify(shadow));
}

async function handleDevModeQuery(tableName, method, body, filters, orders, limit, single) {
  let rows = await getShadowTable(tableName);

  if (method === 'GET') {
    let filtered = rows.filter(r => {
      for (let col in filters) {
        let targetVal = filters[col];
        let val = r[col];
        if (typeof targetVal === 'boolean') {
          if (val === 1 || val === true) val = true;
          else if (val === 0 || val === false) val = false;
        }
        if (val != targetVal) return false;
      }
      return true;
    });

    if (orders && orders.length > 0) {
      filtered.sort((a, b) => {
        for (let o of orders) {
          let valA = a[o.column];
          let valB = b[o.column];
          if (valA < valB) return o.ascending ? -1 : 1;
          if (valA > valB) return o.ascending ? 1 : -1;
        }
        return 0;
      });
    }

    let result = filtered;
    if (limit) {
      result = result.slice(0, limit);
    }
    if (single) {
      result = result.length > 0 ? result[0] : null;
    }
    return { data: result, error: null };
  }

  if (method === 'POST') {
    let newRows = Array.isArray(body) ? body : [body];
    newRows.forEach(r => {
      if (!r.id) {
        r.id = 'dev-' + Date.now() + '-' + Math.random().toString(36).substring(2, 6);
      }
      if (!r.created_at) {
        r.created_at = new Date().toISOString();
      }
      rows.push(r);
    });
    saveShadowTable(tableName, rows);
    const returnData = Array.isArray(body) ? newRows : newRows[0];
    return { data: single ? returnData : (Array.isArray(body) ? returnData : [returnData]), error: null };
  }

  if (method === 'PUT') {
    let updatedRows = [];
    rows.forEach(r => {
      let match = true;
      for (let col in filters) {
        if (r[col] != filters[col]) {
          match = false;
          break;
        }
      }
      if (match) {
        Object.assign(r, body);
        updatedRows.push(r);
      }
    });
    saveShadowTable(tableName, rows);
    return { data: updatedRows, error: null };
  }

  if (method === 'DELETE') {
    let deletedRows = [];
    let remainingRows = [];
    rows.forEach(r => {
      let match = true;
      for (let col in filters) {
        if (r[col] != filters[col]) {
          match = false;
          break;
        }
      }
      if (match) {
        deletedRows.push(r);
      } else {
        remainingRows.push(r);
      }
    });
    saveShadowTable(tableName, remainingRows);
    return { data: deletedRows, error: null };
  }

  return { data: null, error: { message: 'Operação não suportada' } };
}

const localClient = {
  createClient: function() {
    return localClient;
  },

  isDevMode: function() {
    return sessionStorage.getItem(DEV_MODE_KEY) === 'true';
  },

  toggleDevMode: function(enable) {
    if (enable) {
      sessionStorage.setItem(DEV_MODE_KEY, 'true');
      const currentUser = JSON.parse(localStorage.getItem('userAuth') || 'null');
      if (!currentUser || currentUser.perfil !== 'admin') {
        localStorage.setItem('userAuth', JSON.stringify(VIRTUAL_DEV_USER));
        localStorage.setItem('admin_logged', 'true');
      }
      if (window.location.pathname.endsWith('index.html') || window.location.pathname.endsWith('/')) {
        window.location.href = 'dashboard.html';
        return;
      }
    } else {
      sessionStorage.removeItem(DEV_MODE_KEY);
      sessionStorage.removeItem(DEV_SHADOW_KEY);
      const currentUser = JSON.parse(localStorage.getItem('userAuth') || 'null');
      if (currentUser && currentUser.id === VIRTUAL_DEV_USER.id) {
        localStorage.removeItem('userAuth');
        localStorage.removeItem('admin_logged');
        window.location.href = 'index.html';
        return;
      }
    }
    window.location.reload();
  },

  resetDevModeData: function() {
    if (!confirm('Deseja descartar todas as alterações temporárias do Modo Desenvolvedor e restaurar os dados originais do banco?')) return;
    sessionStorage.removeItem(DEV_SHADOW_KEY);
    window.location.reload();
  },

  from: function(tableName) {
    let queryParams = { table: tableName };
    let method = 'GET';
    let body = null;
    let filters = {};
    let orders = [];

    const builder = {
      select: function(fields = '*') {
        queryParams.select = fields;
        return this;
      },
      order: function(column, options = {}) {
        orders.push({ column, ascending: options.ascending !== false });
        return this;
      },
      eq: function(column, value) {
        filters[column] = value;
        return this;
      },
      limit: function(count) {
        queryParams.limit = count;
        return this;
      },
      single: function() {
        queryParams.single = true;
        return this;
      },
      insert: function(payload) {
        method = 'POST';
        body = payload;
        return this;
      },
      update: function(payload) {
        method = 'PUT';
        body = payload;
        return this;
      },
      delete: function() {
        method = 'DELETE';
        return this;
      },
      
      // Thenable para suporte nativo a async/await
      then: async function(onFulfilled, onRejected) {
        try {
          if (localClient.isDevMode()) {
            const devResult = await handleDevModeQuery(tableName, method, body, filters, orders, queryParams.limit, queryParams.single);
            if (onFulfilled) return onFulfilled(devResult);
            return devResult;
          }

          queryParams.filters = JSON.stringify(filters);
          queryParams.orders = JSON.stringify(orders);
          
          let url = getApiUrl(queryParams);
          let options = {
            method: method,
            headers: {
              'Content-Type': 'application/json'
            }
          };
          if (method !== 'GET' && body) {
            options.body = JSON.stringify(body);
          }
          
          const res = await apiFetch(url, options);
          // 400/403 trazem a mensagem do servidor no JSON; só sem ela é erro HTTP cru
          const json = await res.json().catch(() => null);
          if (!json) {
             throw new Error(`Erro HTTP ${res.status}`);
          }
          
          let result;
          if (json.error) {
            result = { data: null, error: { message: json.error } };
          } else {
            result = { data: json.data, error: null };
          }
          
          if (onFulfilled) {
            return onFulfilled(result);
          }
          return result;
        } catch(err) {
          let result = { data: null, error: { message: err.message } };
          if (onFulfilled) {
            return onFulfilled(result);
          }
          return result;
        }
      }
    };

    return builder;
  },
  
  functions: {
    invoke: async function(fnName, options = {}) {
      try {
        const res = await apiFetch(getApiUrl({ action: 'invoke_function', name: fnName }), {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify(options.body || {})
        });
        const json = await res.json();
        if (json.error) {
          return { data: null, error: { message: json.error } };
        }
        return { data: json.data, error: null };
      } catch(err) {
        return { data: null, error: { message: err.message } };
      }
    }
  }
};

// Auto-injeta o banner e controles visuais do Modo Desenvolvedor
function renderDevModeBanner() {
  if (document.getElementById('dev-mode-banner-wrap')) return;

  const isDev = localClient.isDevMode();

  if (isDev) {
    const banner = document.createElement('div');
    banner.id = 'dev-mode-banner-wrap';
    banner.style.cssText = 'position: sticky; top: 0; z-index: 10000; background: linear-gradient(90deg, #d97706, #f59e0b); color: #0f172a; font-family: sans-serif; font-size: 13px; font-weight: 700; padding: 8px 16px; display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #b45309; box-shadow: 0 4px 12px rgba(0,0,0,0.15);';
    banner.innerHTML = `
      <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
        <span style="background: #78350f; color: #ffffff; padding: 2px 8px; border-radius: 4px; text-transform: uppercase; font-size: 10px; font-weight: 900; letter-spacing: 0.5px;">🛠️ MODO DESENVOLVEDOR</span>
        <span>Simulação Ativa — Nenhuma alteração é salva no banco de dados real!</span>
      </div>
      <div style="display: flex; align-items: center; gap: 8px;">
        <button onclick="window.supabase.resetDevModeData()" style="background: #ffffff; color: #b45309; border: none; padding: 4px 10px; border-radius: 6px; font-weight: 800; cursor: pointer; font-size: 11px; transition: all 0.2s;" onmouseover="this.style.background='#fef3c7'" onmouseout="this.style.background='#ffffff'">🔄 Resetar Alterações</button>
        <button onclick="window.supabase.toggleDevMode(false)" style="background: #0f172a; color: #ffffff; border: none; padding: 4px 10px; border-radius: 6px; font-weight: 800; cursor: pointer; font-size: 11px; transition: all 0.2s;" onmouseover="this.style.background='#1e293b'" onmouseout="this.style.background='#0f172a'">❌ Sair do Modo Dev</button>
      </div>
    `;
    document.body.prepend(banner);
  } else {
    // Adiciona botão discreto de gatilho para o Modo Desenvolvedor fora da página de login
    if (!window.location.pathname.endsWith('index.html') && !document.getElementById('dev-mode-trigger')) {
      const btn = document.createElement('button');
      btn.id = 'dev-mode-trigger';
      btn.title = 'Ativar Modo Desenvolvedor (Simulação)';
      btn.style.cssText = 'position: fixed; bottom: 16px; right: 16px; z-index: 9999; background: #0f172a; color: #fbbf24; border: 1px solid #334155; padding: 8px 14px; border-radius: 20px; font-family: sans-serif; font-size: 12px; font-weight: 800; cursor: pointer; box-shadow: 0 4px 14px rgba(0,0,0,0.3); display: flex; align-items: center; gap: 6px; transition: all 0.2s;';
      btn.innerHTML = '🛠️ Modo Dev';
      btn.onclick = () => localClient.toggleDevMode(true);
      btn.onmouseover = () => { btn.style.transform = 'scale(1.05)'; btn.style.background = '#1e293b'; };
      btn.onmouseout = () => { btn.style.transform = 'scale(1)'; btn.style.background = '#0f172a'; };
      document.body.appendChild(btn);
    }
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', renderDevModeBanner);
} else {
  renderDevModeBanner();
}

// Sessão do login antigo (sem token) não vale mais: o servidor recusaria tudo.
if (localStorage.getItem('userAuth') && !localStorage.getItem(AUTH_TOKEN_KEY) && !localClient.isDevMode()) {
  irParaLogin();
}

// Vincula ao escopo global do navegador
window.supabase = localClient;
window.supabaseClient = localClient;
