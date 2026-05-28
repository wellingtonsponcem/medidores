<?php
/**
 * Ponte de Sincronização PHP (Nativa)
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$scriptPath = __DIR__ . '/cron/coleta_tuya.php';

if (!file_exists($scriptPath)) {
    echo json_encode(['success' => false, 'message' => 'Arquivo nao encontrado: ' . $scriptPath]);
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
