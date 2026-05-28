const https = require('https');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

// --- CONFIGURAÇÃO ---
// Tenta carregar do .env manualmente se não estiver no process.env
const envPath = path.join(__dirname, '.env');
if (fs.existsSync(envPath)) {
    const envFile = fs.readFileSync(envPath, 'utf8');
    envFile.split('\n').forEach(line => {
        const [key, ...value] = line.split('=');
        if (key && value) {
            process.env[key.trim()] = value.join('=').trim();
        }
    });
}

const CONFIG = {
    clientId: process.env.TUYA_CLIENT_ID,
    secret: process.env.TUYA_CLIENT_SECRET,
    deviceId: process.env.TUYA_DEVICE_ID,
    supabaseUrl: process.env.SUPABASE_URL,
    supabaseKey: process.env.SUPABASE_KEY,
    baseUrl: 'openapi.tuyacn.com', // Altere se o seu Data Center for outro (ex: openapi.tuyaus.com)
};

// --- FUNÇÕES AUXILIARES ---

function request(options, body = '') {
    return new Promise((resolve, reject) => {
        const req = https.request(options, (res) => {
            let data = '';
            res.on('data', (chunk) => data += chunk);
            res.on('end', () => resolve(JSON.parse(data)));
        });
        req.on('error', reject);
        if (body) req.write(body);
        req.end();
    });
}

function calcSign(clientId, secret, t, accessToken, stringToSign) {
    const str = clientId + accessToken + t + "" + stringToSign;
    return crypto.createHmac('sha256', secret).update(str).digest('hex').toUpperCase();
}

async function getTuyaToken() {
    const t = Date.now();
    const method = 'GET';
    const url = '/v1.0/token?grant_type=1';
    const contentHash = crypto.createHash('sha256').update('').digest('hex');
    const stringToSign = [method, contentHash, '', url].join('\n');
    const sign = calcSign(CONFIG.clientId, CONFIG.secret, t, '', stringToSign);

    const options = {
        hostname: CONFIG.baseUrl,
        path: url,
        method: method,
        headers: {
            'client_id': CONFIG.clientId,
            'sign': sign,
            't': t,
            'sign_method': 'HMAC-SHA256',
        }
    };

    const res = await request(options);
    if (!res.success) throw new Error('Falha ao obter token Tuya: ' + JSON.stringify(res));
    return res.result.access_token;
}

async function getDeviceStatus(token) {
    const t = Date.now();
    const method = 'GET';
    const url = `/v1.0/devices/${CONFIG.deviceId}/status`;
    const contentHash = crypto.createHash('sha256').update('').digest('hex');
    const stringToSign = [method, contentHash, '', url].join('\n');
    const sign = calcSign(CONFIG.clientId, CONFIG.secret, t, token, stringToSign);

    const options = {
        hostname: CONFIG.baseUrl,
        path: url,
        method: method,
        headers: {
            'client_id': CONFIG.clientId,
            'sign': sign,
            't': t,
            'access_token': token,
            'sign_method': 'HMAC-SHA256',
        }
    };

    const res = await request(options);
    if (!res.success) throw new Error('Falha ao obter status do dispositivo: ' + JSON.stringify(res));
    return res.result;
}

async function saveToSupabase(valor) {
    const body = JSON.stringify({
        valor_extraido: valor,
        data_leitura: new Date().toISOString(),
        medidor_id: CONFIG.deviceId
    });

    const urlObj = new URL(CONFIG.supabaseUrl);
    const options = {
        hostname: urlObj.hostname,
        path: '/rest/v1/leitura_energia',
        method: 'POST',
        headers: {
            'apikey': CONFIG.supabaseKey,
            'Authorization': `Bearer ${CONFIG.supabaseKey}`,
            'Content-Type': 'application/json',
            'Prefer': 'return=minimal'
        }
    };

    return await request(options, body);
}

// --- EXECUÇÃO PRINCIPAL ---

async function main() {
    try {
        console.log('--- Iniciando coleta Tuya ---');
        
        console.log('1. Obtendo Access Token...');
        const token = await getTuyaToken();
        
        console.log('2. Buscando status do medidor...');
        const status = await getDeviceStatus(token);
        
        // Procura o valor do "Total Ele" (Energia Acumulada)
        const totalEleObj = status.find(s => s.code === 'add_ele' || s.code === 'total_forward_energy');
        if (!totalEleObj) {
            console.log('Status recebido:', JSON.stringify(status));
            throw new Error('Código "add_ele" não encontrado no retorno da API.');
        }

        let valorFinal = totalEleObj.value;
        // Geralmente a Tuya envia com 2 casas decimais implícitas (ex: 12345 = 123.45 kWh)
        // Verificamos se o valor é muito alto para ser kWh direto
        if (valorFinal > 10000) {
            valorFinal = valorFinal / 100;
        }

        console.log(`3. Valor extraído: ${valorFinal} kWh`);

        console.log('4. Salvando no Supabase...');
        await saveToSupabase(valorFinal);

        console.log('--- Sucesso! Dados salvos. ---');
    } catch (error) {
        console.error('ERRO:', error.message);
        process.exit(1);
    }
}

main();
