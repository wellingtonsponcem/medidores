// local-db-client.js
// Wrapper compatível com o Supabase JS Client para direcionar operações para o banco local MySQL via api.php

const localClient = {
  createClient: function() {
    return localClient;
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
          queryParams.filters = JSON.stringify(filters);
          queryParams.orders = JSON.stringify(orders);
          
          let url = 'api.php?' + new URLSearchParams(queryParams).toString();
          let options = {
            method: method,
            headers: {
              'Content-Type': 'application/json'
            }
          };
          if (method !== 'GET' && body) {
            options.body = JSON.stringify(body);
          }
          
          const res = await fetch(url, options);
          if (!res.ok) {
             throw new Error(`Erro HTTP ${res.status}`);
          }
          const json = await res.json();
          
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
        const res = await fetch('api.php?action=invoke_function&name=' + fnName, {
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

// Vincula ao escopo global do navegador
window.supabase = localClient;
window.supabaseClient = localClient;
