<?php
/**
 * Coletor Tuya em PHP - Versão Final Otimizada
 */

// Garante que o PHP mostre erros se houver falha na leitura do .env
$envPath = __DIR__ . '/.env';
if (!file_exists($envPath)) {
    die("ERRO: Arquivo .env nao encontrado em: $envPath");
}

$env = parse_ini_file($envPath);
$clientId = $env['TUYA_CLIENT_ID'];
$secret = $env['TUYA_CLIENT_SECRET'];
$deviceId = $env['TUYA_DEVICE_ID'];
$supabaseUrl = $env['SUPABASE_URL'];
$supabaseKey = $env['SUPABASE_KEY'];

// Western America Data Center
$baseUrl = 'https://openapi.tuyaus.com';

function tuya_request($method, $path, $token = '', $body = '')
{
    global $clientId, $secret, $baseUrl;

    $t = round(microtime(true) * 1000);
    $nonce = "";
    $contentHash = hash('sha256', $body);
    
    // Na assinatura Tuya V2, o path deve incluir a query string se houver
    $stringToSign = "$method\n$contentHash\n\n$path";

    $signStr = $clientId . $token . $t . $nonce . $stringToSign;
    $sign = strtoupper(hash_hmac('sha256', $signStr, $secret));

    $headers = [
        "client_id: $clientId",
        "sign: $sign",
        "t: $t",
        "sign_method: HMAC-SHA256",
        "Content-Type: application/json"
    ];
    if ($token)
        $headers[] = "access_token: $token";

    $ch = curl_init($baseUrl . $path);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($body)
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

try {
    echo "1. Obtendo Token...\n";
    $auth = tuya_request('GET', '/v1.0/token?grant_type=1');
    if (!$auth['success'])
        throw new Exception("Falha Token: " . json_encode($auth));
    $token = $auth['result']['access_token'];

    echo "2. Buscando propriedades do dispositivo...\n";
    $res = tuya_request('GET', "/v2.0/cloud/thing/$deviceId/shadow/properties", $token);
    if (!$res['success'])
        throw new Exception("Falha ao buscar propriedades: " . json_encode($res));

    $props = $res['result']['properties'];
    $valores = [];
    foreach ($props as $p) {
        $valores[$p['code']] = $p['value'];
    }

    // ele (dp_id 123) = Total Ele em milésimos (1818816 → 1818.816 kWh)
    if (!isset($valores['ele']))
        throw new Exception("Código 'ele' (Total Ele) não encontrado no dispositivo.");

    $totalEle = $valores['ele'] / 1000;
    echo "   Total Ele: $totalEle kWh\n";

    echo "3. Salvando no Banco de Dados MySQL local...\n";
    require_once __DIR__ . '/../db_config.php';
    
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    $sql = "INSERT INTO leitura_energia (valor_extraido, data_leitura, data_execucao, medidor_id) 
            VALUES (:valor_extraido, :data_leitura, :data_execucao, :medidor_id)";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':valor_extraido' => $totalEle,
        ':data_leitura' => date('Y-m-d H:i:s'),
        ':data_execucao' => date('Y-m-d H:i:s'),
        ':medidor_id' => $deviceId
    ]);

    echo "--- Sucesso! $totalEle kWh salvo. ---\n";

} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage();
}
