<?php
/**
 * Ponte de Sincronização PHP (Nativa)
 *
 * Dispara a coleta da Tuya, que grava no banco — então só administrador
 * logado (mesmo token X-Auth-Token da api.php) ou a linha de comando.
 */
error_reporting(E_ALL);
// erro vai para o log, nunca para a resposta: o stack trace entrega caminho e credencial
ini_set('display_errors', 0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Token');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    exit;
}

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/db_config.php';
    require_once __DIR__ . '/bot/lib/Acesso.php';
    try {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdoAuth = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        $usuario = Acesso::usuarioDaSessao($pdoAuth, Acesso::tokenDaRequisicao());
    } catch (Exception $e) {
        error_log('[sync] ' . $e->getMessage());
        $usuario = null;
    }
    if ($usuario === null || ($usuario['perfil'] ?? '') !== 'admin') {
        http_response_code($usuario === null ? 401 : 403);
        echo json_encode(['success' => false, 'message' => 'Apenas administradores logados podem sincronizar.']);
        exit;
    }
}

$scriptPath = __DIR__ . '/cron/coleta_tuya.php';

if (!file_exists($scriptPath)) {
    echo json_encode(['success' => false, 'message' => 'Coletor da Tuya não encontrado no servidor.']);
    exit;
}

ob_start();
try {
    include $scriptPath;
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage();
}
$output = ob_get_clean();

$success = (strpos($output, 'Sucesso!') !== false);

echo json_encode([
    'success' => $success,
    'output' => $output
]);
