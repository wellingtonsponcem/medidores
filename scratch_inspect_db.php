<?php
require_once __DIR__ . '/db_config.php';

try {
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    echo "=== CONSUMO ENERGIA (Últimos 10 registros) ===\n";
    $stmt = $pdo->query("SELECT * FROM consumo_energia ORDER BY data_fatura DESC, id DESC LIMIT 10");
    print_r($stmt->fetchAll());

    echo "\n=== LEITURA ENERGIA (TUYA) (Últimos 5 registros) ===\n";
    $stmt = $pdo->query("SELECT * FROM leitura_energia ORDER BY data_leitura DESC LIMIT 5");
    print_r($stmt->fetchAll());

    echo "\n=== ESTRUTURA DA TABELA consumo_energia ===\n";
    $stmt = $pdo->query("DESCRIBE consumo_energia");
    print_r($stmt->fetchAll());

} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}
