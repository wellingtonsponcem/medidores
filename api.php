<?php
// Origem livre continua: a autenticação é por token no cabeçalho, não por
// cookie, então outro site não "pega carona" na sessão de quem está logado.
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, X-Auth-Token");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/bot/lib/Acesso.php';

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

function negar(int $codigo, string $mensagem): void
{
    http_response_code($codigo);
    echo json_encode(["data" => null, "error" => $mensagem]);
    exit;
}

$action = $_GET['action'] ?? null;

// ---- login: a única rota aberta ----
if ($action === 'login') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        negar(405, "Use POST");
    }
    $body = json_decode(file_get_contents('php://input'), true);
    $nome = trim((string) ($body['nome'] ?? ''));
    if (strtolower($nome) === 'admn') {
        $nome = 'admin'; // erro de digitação comum, mantido do login antigo
    }
    $senha = (string) ($body['senha'] ?? '');
    try {
        $u = Acesso::autenticar($pdo, $nome, $senha);
        if ($u === null) {
            // freio barato contra tentativa em massa
            usleep(700000);
            negar(401, "Usuário ou senha incorretos");
        }
        $token = Acesso::criarSessao($pdo, (string) $u['id']);
        echo json_encode(["data" => ["token" => $token, "usuario" => Acesso::usuarioPublico($u)], "error" => null]);
    } catch (Exception $e) {
        error_log('[api] login: ' . $e->getMessage());
        negar(500, "Erro ao verificar o login");
    }
    exit;
}

// ---- daqui para baixo, só com sessão válida ----
$token = Acesso::tokenDaRequisicao();
$usuario = $token !== '' ? Acesso::usuarioDaSessao($pdo, $token) : null;
if ($usuario === null) {
    negar(401, "Sessão expirada. Entre novamente.");
}
$ehAdmin = ($usuario['perfil'] ?? '') === 'admin';

if ($action === 'logout') {
    Acesso::encerrarSessao($pdo, $token);
    echo json_encode(["data" => true, "error" => null]);
    exit;
}

// Troca da própria senha — a única escrita que um morador faz.
if ($action === 'change_password') {
    $body = json_decode(file_get_contents('php://input'), true);
    $nova = (string) ($body['senha'] ?? '');
    if (trim($nova) === '') {
        negar(400, "A senha não pode estar vazia.");
    }
    $stmt = $pdo->prepare("UPDATE usuarios SET senha = :s, senha_alterada = 1 WHERE id = :id");
    $stmt->execute([':s' => Acesso::gerarHash($nova), ':id' => $usuario['id']]);
    // quem tinha a senha antiga e um token aberto perde o acesso
    Acesso::encerrarSessoesDoUsuario($pdo, (string) $usuario['id'], $token);
    echo json_encode(["data" => true, "error" => null]);
    exit;
}

// Todas as ações restantes são do painel administrativo.
if ($action !== null && !$ehAdmin) {
    negar(403, "Apenas administradores");
}

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

// Salvar Chave do Gemini no MySQL
if ($action === 'set_gemini_key') {
    $body = json_decode(file_get_contents('php://input'), true);
    $key = trim($body['key'] ?? '');
    if (!$key) {
        echo json_encode(["error" => "Chave não informada"]);
        exit;
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS app_secrets (
            name VARCHAR(100) PRIMARY KEY,
            value TEXT NOT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $stmt = $pdo->prepare("REPLACE INTO app_secrets (name, value) VALUES ('GEMINI_API_KEY', :v)");
        $stmt->execute([':v' => $key]);
        echo json_encode(["data" => true, "error" => null]);
    } catch(Exception $e) {
        echo json_encode(["error" => "Erro ao salvar chave no banco: " . $e->getMessage()]);
    }
    exit;
}

if ($action === 'get_gemini_key_status') {
    try {
        $stmt = $pdo->prepare("SELECT value FROM app_secrets WHERE name = 'GEMINI_API_KEY' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch();
        $hasKey = !empty($row['value']);
        echo json_encode(["data" => ["has_key" => $hasKey], "error" => null]);
    } catch(Exception $e) {
        echo json_encode(["data" => ["has_key" => false], "error" => null]);
    }
    exit;
}

// Proxy para Leitura OCR via IA (Gemini Direct com Fallback de Modelos)
if ($action === 'invoke_function') {
    $fnName = $_GET['name'] ?? '';
    if ($fnName !== 'ocr-gemini') {
        echo json_encode(["error" => "Função não permitida"]);
        exit;
    }
    
    $body = file_get_contents('php://input');
    
    // 1) Busca a chave do Google Gemini salva no banco de dados local (app_secrets)
    $geminiKey = null;
    try {
        $stmt = $pdo->prepare("SELECT value FROM app_secrets WHERE name = 'GEMINI_API_KEY' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row && !empty($row['value'])) {
            $geminiKey = trim($row['value']);
        }
    } catch(Exception $e) {}

    if ($geminiKey) {
        // Cascata de modelos centralizada: o bot do Telegram usa o mesmo caminho,
        // então a lista de modelos só é mantida em um lugar (bot/lib/Gemini.php).
        require_once __DIR__ . '/bot/lib/Gemini.php';
        $r = Gemini::gerarJson($geminiKey, $body);

        if ($r['ok']) {
            echo json_encode(["data" => $r['data'], "error" => null]);
        } else {
            echo json_encode(["error" => "Erro na API Gemini: " . $r['erro']]);
        }
        exit;
    }

    // 2) Fallback para a Edge Function do Supabase (se a chave do Gemini não estiver cadastrada no MySQL)
    $supabaseUrl = "https://jmdlraaprcztshkfyeud.supabase.co/functions/v1/ocr-gemini";
    $supabaseKey = "sb_publishable_2c7MrFAZaCiQzY_EuQuizQ_wTgAzbIC";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $supabaseUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "apikey: " . $supabaseKey,
        "Authorization: Bearer " . $supabaseKey
    ]);
    
    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        echo json_encode(["error" => "Nenhuma chave ativa do Gemini encontrada e o serviço secundário do Supabase está inativo. Por favor, cadastre uma chave gratuita da API do Gemini no Painel Admin."]);
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

$method = $_SERVER['REQUEST_METHOD'];

// Tabela, método e perfil numa regra só — ver Acesso::TABELAS_ADMIN
if (!is_string($table) || !Acesso::podeAcessar($usuario['perfil'] ?? null, $table, $method)) {
    negar(403, "Acesso não permitido a esta tabela");
}

$filters = json_decode($_GET['filters'] ?? '{}', true);
if (!is_array($filters)) $filters = [];
foreach ($filters as $col => $val) {
    if (!Acesso::identificadorValido($col) || !is_scalar($val) && $val !== null) {
        negar(400, "Filtro inválido");
    }
}

/**
 * Corpo de um INSERT/UPDATE pronto para o SQL: nomes de coluna validados,
 * booleanos em 0/1 e senha de usuário sempre gravada como hash.
 */
function corpoParaGravar(string $table): array
{
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body) || count($body) === 0) {
        negar(400, "Payload inválido");
    }
    foreach ($body as $k => $v) {
        if (!Acesso::identificadorValido($k) || !is_scalar($v) && $v !== null) {
            negar(400, "Campo inválido no payload");
        }
        if (is_bool($v)) {
            $body[$k] = $v ? 1 : 0;
        }
    }
    if ($table === 'usuarios' && array_key_exists('senha', $body)) {
        if (trim((string) $body['senha']) === '') {
            negar(400, "A senha não pode estar vazia.");
        }
        $body['senha'] = Acesso::gerarHash((string) $body['senha']);
    }
    return $body;
}

switch ($method) {
    case 'GET':
        // Ordenação
        $ordersJson = $_GET['orders'] ?? '[]';
        $orders = json_decode($ordersJson, true);
        if (!is_array($orders)) $orders = [];
        foreach ($orders as $o) {
            if (!is_array($o) || !Acesso::identificadorValido($o['column'] ?? null)) {
                negar(400, "Ordenação inválida");
            }
        }

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

            // Sem a senha, e com os booleanos de 0/1 do MySQL viram true/false do JS
            if ($table === 'usuarios') {
                $rows = array_map([Acesso::class, 'usuarioPublico'], $rows);
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
        $body = corpoParaGravar($table);

        $cols = array_keys($body);
        $colList = implode("`, `", $cols);
        $paramList = implode(", :", $cols);
        $sql = "INSERT INTO `$table` (`$colList`) VALUES (:$paramList)";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($body);
            echo json_encode(["data" => $table === 'usuarios' ? Acesso::usuarioPublico($body) : $body, "error" => null]);
        } catch (Exception $e) {
            echo json_encode(["error" => "Erro na inserção: " . $e->getMessage()]);
        }
        break;

    case 'PUT':
        $body = corpoParaGravar($table);
        if (count($filters) === 0) {
            negar(400, "Filtros para atualização não especificados");
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
            // senha redefinida pelo admin: quem estava logado com a antiga sai
            if ($table === 'usuarios' && isset($body['senha']) && isset($filters['id'])) {
                Acesso::encerrarSessoesDoUsuario($pdo, (string) $filters['id']);
            }
            echo json_encode(["data" => $table === 'usuarios' ? Acesso::usuarioPublico($body) : $body, "error" => null]);
        } catch (Exception $e) {
            echo json_encode(["error" => "Erro na atualização: " . $e->getMessage()]);
        }
        break;

    case 'DELETE':
        if (count($filters) === 0) {
            negar(400, "Filtros para exclusão não especificados");
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
