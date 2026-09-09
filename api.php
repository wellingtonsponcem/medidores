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

// Snapshot Tuya por data da fatura — Av Brasil (sincronia diária 14h)
if ($action === 'get_tuya_by_date') {
    $date = $_GET['date'] ?? null; // esperado YYYY-MM-DD
    $medidorId = $_GET['medidor_id'] ?? null;
    if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        echo json_encode(["error" => "Parâmetro 'date' obrigatório no formato YYYY-MM-DD"]);
        exit;
    }
    try {
        // 1) tenta exato no dia (mais recente do dia, ideal 14h)
        $sql = "SELECT valor_extraido, data_leitura, medidor_id FROM leitura_energia WHERE DATE(data_leitura) = :d";
        $params = [":d" => $date];
        if ($medidorId) { $sql .= " AND medidor_id = :m"; $params[":m"] = $medidorId; }
        $sql .= " ORDER BY data_leitura DESC LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        if ($row) {
            echo json_encode(["data" => ["valor" => (float)$row['valor_extraido'], "data_real" => $row['data_leitura'], "medidor_id" => $row['medidor_id'], "estimado" => false, "delta_dias" => 0, "interpolado" => false], "error" => null]);
            exit;
        }
        // 2) fallback: anterior e posterior mais próximos (até 7 dias)
        $sqlPrev = "SELECT valor_extraido, data_leitura FROM leitura_energia WHERE DATE(data_leitura) < :d";
        $pPrev = [":d" => $date];
        if ($medidorId) { $sqlPrev .= " AND medidor_id = :m"; $pPrev[":m"] = $medidorId; }
        $sqlPrev .= " ORDER BY data_leitura DESC LIMIT 1";
        $stmt = $pdo->prepare($sqlPrev);
        $stmt->execute($pPrev);
        $prev = $stmt->fetch();

        $sqlNext = "SELECT valor_extraido, data_leitura FROM leitura_energia WHERE DATE(data_leitura) > :d";
        $pNext = [":d" => $date];
        if ($medidorId) { $sqlNext .= " AND medidor_id = :m"; $pNext[":m"] = $medidorId; }
        $sqlNext .= " ORDER BY data_leitura ASC LIMIT 1";
        $stmt = $pdo->prepare($sqlNext);
        $stmt->execute($pNext);
        $next = $stmt->fetch();

        if ($prev && $next) {
            $dPrev = new DateTime($prev['data_leitura']);
            $dNext = new DateTime($next['data_leitura']);
            $dTarget = new DateTime($date . " 14:00:00");
            $span = $dNext->getTimestamp() - $dPrev->getTimestamp();
            $off  = $dTarget->getTimestamp() - $dPrev->getTimestamp();
            if ($span > 0 && $span < 14*86400) {
                $ratio = max(0, min(1, $off / $span));
                $interp = (float)$prev['valor_extraido'] + $ratio * ((float)$next['valor_extraido'] - (float)$prev['valor_extraido']);
                $delta = (int) round(abs($dTarget->getTimestamp() - $dPrev->getTimestamp())/86400);
                echo json_encode(["data" => ["valor" => round($interp,3), "data_real" => $prev['data_leitura'], "data_real_next" => $next['data_leitura'], "estimado" => true, "interpolado" => true, "delta_dias" => $delta], "error" => null]);
                exit;
            }
        }
        // 3) usa o mais próximo isolado
        $closest = $prev ?: $next;
        if ($closest) {
            $dClosest = new DateTime($closest['data_leitura']);
            $dTarget = new DateTime($date);
            $delta = (int) $dClosest->diff($dTarget)->days;
            if ($delta <= 7) {
                echo json_encode(["data" => ["valor" => (float)$closest['valor_extraido'], "data_real" => $closest['data_leitura'], "estimado" => true, "interpolado" => false, "delta_dias" => $delta], "error" => null]);
                exit;
            }
        }
        echo json_encode(["data" => null, "error" => "Nenhum snapshot Tuya encontrado próximo a $date (até 7 dias)"]);
    } catch (Exception $e) {
        echo json_encode(["error" => "Erro get_tuya_by_date: " . $e->getMessage()]);
    }
    exit;
}

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
