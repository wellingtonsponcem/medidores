<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

require_once 'db_config.php';

try {
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (Exception $e) {
    echo json_encode(["error" => "Falha na conexão com o banco local: " . $e->getMessage()]);
    exit;
}

$action = $_GET['action'] ?? null;

// Proxy para Edge Functions do Supabase (ex: ocr-gemini)
if ($action === 'invoke_function') {
    $fnName = $_GET['name'] ?? '';
    if ($fnName !== 'ocr-gemini') {
        echo json_encode(["error" => "Função não permitida"]);
        exit;
    }
    
    $body = file_get_contents('php://input');
    
    // Fazer a requisição para a Edge Function do Supabase
    $supabaseUrl = "https://jmdlraaprcztshkfyeud.supabase.co/functions/v1/ocr-gemini";
    $supabaseKey = "sb_publishable_2c7MrFAZaCiQzY_EuQuizQ_wTgAzbIC";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $supabaseUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "apikey: " . $supabaseKey,
        "Authorization: Bearer " . $supabaseKey
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        echo json_encode(["error" => "Erro na função Supabase ($httpCode): " . $response]);
    } else {
        echo json_encode(["data" => json_decode($response, true), "error" => null]);
    }
    exit;
}

// Lógica de tabelas (CRUD)
$table = $_GET['table'] ?? null;
if (!$table) {
    echo json_encode(["error" => "Tabela não especificada"]);
    exit;
}

// Sanitização básica do nome da tabela/view
$allowedTables = [
    'usuarios', 'consumo_agua', 'consumo_energia', 'leitura_energia', 'app_secrets',
    'vw_agua_rateio', 'vw_energia_rateio', 'vw_energia_rateio_admin'
];
if (!in_array($table, $allowedTables)) {
    echo json_encode(["error" => "Acesso não permitido a esta tabela"]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Filtros
        $filtersJson = $_GET['filters'] ?? '{}';
        $filters = json_decode($filtersJson, true);
        if (!is_array($filters)) $filters = [];

        // Ordenação
        $ordersJson = $_GET['orders'] ?? '[]';
        $orders = json_decode($ordersJson, true);
        if (!is_array($orders)) $orders = [];

        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : null;
        $single = isset($_GET['single']) && ($_GET['single'] === 'true' || $_GET['single'] === true);

        // Montar Query SELECT
        $sql = "SELECT * FROM `$table` WHERE 1";
        $params = [];
        $i = 0;
        foreach ($filters as $col => $val) {
            $sql .= " AND `$col` = :filter_$i";
            $params["filter_$i"] = $val;
            $i++;
        }

        if (count($orders) > 0) {
            $sql .= " ORDER BY ";
            $orderParts = [];
            foreach ($orders as $o) {
                $dir = ($o['ascending'] ?? true) ? 'ASC' : 'DESC';
                $orderParts[] = "`" . $o['column'] . "` " . $dir;
            }
            $sql .= implode(", ", $orderParts);
        }

        if ($limit) {
            $sql .= " LIMIT $limit";
        }

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            // Converter booleanos de 0/1 do MySQL para true/false do JS
            if ($table === 'usuarios') {
                foreach ($rows as &$r) {
                    if (isset($r['senha_alterada'])) {
                        $r['senha_alterada'] = $r['senha_alterada'] == 1;
                    }
                    if (isset($r['ativo'])) {
                        $r['ativo'] = $r['ativo'] == 1;
                    }
                }
            }

            if ($single) {
                $data = count($rows) > 0 ? $rows[0] : null;
            } else {
                $data = $rows;
            }

            echo json_encode(["data" => $data, "error" => null]);
        } catch (Exception $e) {
            echo json_encode(["error" => "Erro na consulta: " . $e->getMessage()]);
        }
        break;

    case 'POST':
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) {
            echo json_encode(["error" => "Payload inválido"]);
            exit;
        }

        // Converter booleanos para MySQL
        foreach ($body as $k => $v) {
            if (is_bool($v)) {
                $body[$k] = $v ? 1 : 0;
            }
        }

        $cols = array_keys($body);
        $colList = implode("`, `", $cols);
        $paramList = implode(", :", $cols);
        $sql = "INSERT INTO `$table` (`$colList`) VALUES (:$paramList)";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($body);
            echo json_encode(["data" => $body, "error" => null]);
        } catch (Exception $e) {
            echo json_encode(["error" => "Erro na inserção: " . $e->getMessage()]);
        }
        break;

    case 'PUT':
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) {
            echo json_encode(["error" => "Payload inválido"]);
            exit;
        }

        $filtersJson = $_GET['filters'] ?? '{}';
        $filters = json_decode($filtersJson, true);
        if (!is_array($filters) || count($filters) === 0) {
            echo json_encode(["error" => "Filtros para atualização não especificados"]);
            exit;
        }

        // Converter booleanos para MySQL
        foreach ($body as $k => $v) {
            if (is_bool($v)) {
                $body[$k] = $v ? 1 : 0;
            }
        }

        $setParts = [];
        $params = [];
        foreach ($body as $col => $val) {
            $setParts[] = "`$col` = :set_$col";
            $params["set_$col"] = $val;
        }
        $setString = implode(", ", $setParts);

        $sql = "UPDATE `$table` SET $setString WHERE 1";
        $i = 0;
        foreach ($filters as $col => $val) {
            $sql .= " AND `$col` = :filter_$i";
            $params["filter_$i"] = $val;
            $i++;
        }

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            echo json_encode(["data" => $body, "error" => null]);
        } catch (Exception $e) {
            echo json_encode(["error" => "Erro na atualização: " . $e->getMessage()]);
        }
        break;

    case 'DELETE':
        $filtersJson = $_GET['filters'] ?? '{}';
        $filters = json_decode($filtersJson, true);
        if (!is_array($filters) || count($filters) === 0) {
            echo json_encode(["error" => "Filtros para exclusão não especificados"]);
            exit;
        }

        $sql = "DELETE FROM `$table` WHERE 1";
        $params = [];
        $i = 0;
        foreach ($filters as $col => $val) {
            $sql .= " AND `$col` = :filter_$i";
            $params["filter_$i"] = $val;
            $i++;
        }

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            echo json_encode(["data" => true, "error" => null]);
        } catch (Exception $e) {
            echo json_encode(["error" => "Erro na exclusão: " . $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(["error" => "Método não suportado"]);
        break;
}
