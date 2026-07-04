<?php
header('Content-Type: text/html; charset=utf-8');
require_once 'db_config.php';

echo "<h1>Executando Migração de Banco de Dados: Supabase -> MySQL</h1>";

try {
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    echo "<p style='color:green;'>✅ Conectado com sucesso ao MySQL local da hospedagem.</p>";
} catch (Exception $e) {
    echo "<p style='color:red;'>❌ Falha na conexão com o MySQL: " . $e->getMessage() . "</p>";
    exit;
}

// 1. Criar estrutura de tabelas
echo "<h2>1. Criando Tabelas...</h2>";
try {
    $queries = [
        "DROP VIEW IF EXISTS vw_energia_rateio_admin",
        "DROP VIEW IF EXISTS vw_energia_rateio",
        "DROP VIEW IF EXISTS vw_agua_rateio",
        
        "CREATE TABLE IF NOT EXISTS usuarios (
            id VARCHAR(36) PRIMARY KEY,
            nome VARCHAR(255) NOT NULL,
            senha VARCHAR(255) NOT NULL,
            perfil VARCHAR(50) NOT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            senha_alterada TINYINT(1) DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS consumo_agua (
            id VARCHAR(50) PRIMARY KEY,
            data_leitura DATE,
            leitura_cesan DECIMAL(12,4),
            leitura_h1 DECIMAL(12,4),
            leitura_h2 DECIMAL(12,4),
            leitura_h3 DECIMAL(12,4),
            valor_fatura_total DECIMAL(12,4),
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS consumo_energia (
            id VARCHAR(50) PRIMARY KEY,
            data_leitura DATE,
            leitura_padrao DECIMAL(12,4),
            leitura_interno DECIMAL(12,4),
            valor_fatura_total DECIMAL(12,4),
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            local_medidor VARCHAR(50) NOT NULL,
            leitura_interno_anterior_ref DECIMAL(12,4),
            leitura_interno_2 DECIMAL(12,4),
            leitura_interno_2_anterior_ref DECIMAL(12,4)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS leitura_energia (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            data_execucao DATETIME,
            data_leitura DATETIME,
            valor_extraido DECIMAL(12,4),
            medidor_id VARCHAR(50),
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS app_secrets (
            name VARCHAR(100) PRIMARY KEY,
            value TEXT NOT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
    ];

    foreach ($queries as $q) {
        $pdo->exec($q);
    }
    echo "<p style='color:green;'>✅ Estrutura de tabelas criada/verificada com sucesso.</p>";
} catch (Exception $e) {
    echo "<p style='color:red;'>❌ Erro ao criar tabelas: " . $e->getMessage() . "</p>";
    exit;
}

// 2. Importar dados do Supabase
echo "<h2>2. Importando dados do Supabase...</h2>";

$supabaseUrl = "https://jmdlraaprcztshkfyeud.supabase.co";
$supabaseKey = "sb_publishable_2c7MrFAZaCiQzY_EuQuizQ_wTgAzbIC";

function fetchSupabase($table, $url, $key) {
    $apiUrl = $url . "/rest/v1/" . $table . "?select=*";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "apikey: " . $key,
        "Authorization: Bearer " . $key
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception("Erro HTTP $httpCode ao ler tabela $table do Supabase. Resposta: " . $response);
    }
    return json_decode($response, true);
}

$tables = ['usuarios', 'consumo_agua', 'consumo_energia', 'leitura_energia', 'app_secrets'];

foreach ($tables as $table) {
    try {
        echo "<p>Buscando dados da tabela <strong>$table</strong> do Supabase...</p>";
        $data = fetchSupabase($table, $supabaseUrl, $supabaseKey);
        $count = count($data);
        echo "<p style='color:blue;'>Lidos $count registros do Supabase. Inserindo no MySQL...</p>";

        if ($count > 0) {
            // Limpa tabela antes de importar
            $pdo->exec("DELETE FROM `$table` WHERE 1");

            // Preparar insert genérico
            $columns = array_keys($data[0]);
            $colList = implode("`, `", $columns);
            $paramList = implode(", :", $columns);
            $sql = "INSERT INTO `$table` (`$colList`) VALUES (:$paramList)";
            $stmt = $pdo->prepare($sql);

            $inserted = 0;
            foreach ($data as $row) {
                // Converter booleanos para 0 ou 1 no MySQL
                foreach ($row as $key => $val) {
                    if (is_bool($val)) {
                        $row[$key] = $val ? 1 : 0;
                    }
                }
                $stmt->execute($row);
                $inserted++;
            }
            echo "<p style='color:green;'>✅ Tabela <strong>$table</strong> migrada com sucesso ($inserted registros inseridos).</p>";
        } else {
            echo "<p style='color:orange;'>Tabela <strong>$table</strong> estava vazia no Supabase.</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color:red;'>❌ Erro ao migrar tabela $table: " . $e->getMessage() . "</p>";
    }
}

// 3. Criar Views no MySQL
echo "<h2>3. Criando Views...</h2>";
try {
    // View 1: vw_agua_rateio
    $vwAguaSql = "
    CREATE VIEW vw_agua_rateio AS
    WITH ordered AS (
        SELECT id,
            data_leitura,
            leitura_cesan,
            leitura_h1,
            leitura_h2,
            leitura_h3,
            valor_fatura_total,
            LAG(leitura_cesan) OVER (ORDER BY data_leitura) AS prev_cesan,
            LAG(leitura_h1) OVER (ORDER BY data_leitura) AS prev_h1,
            LAG(leitura_h2) OVER (ORDER BY data_leitura) AS prev_h2,
            LAG(leitura_h3) OVER (ORDER BY data_leitura) AS prev_h3
        FROM consumo_agua
    ), deltas AS (
        SELECT id,
            data_leitura,
            leitura_cesan,
            leitura_h1,
            leitura_h2,
            leitura_h3,
            valor_fatura_total,
            prev_cesan,
            prev_h1,
            prev_h2,
            prev_h3,
            GREATEST(0, (leitura_cesan - COALESCE(prev_cesan, 0))) AS consumo_cesan_calc,
            GREATEST(0, (leitura_h1 - COALESCE(prev_h1, 0))) AS bruto_h1,
            GREATEST(0, (leitura_h2 - COALESCE(prev_h2, 0))) AS bruto_h2,
            GREATEST(0, (leitura_h3 - COALESCE(prev_h3, 0))) AS bruto_h3
        FROM ordered
    ), consumos AS (
        SELECT id,
            data_leitura,
            leitura_cesan,
            leitura_h1,
            leitura_h2,
            leitura_h3,
            valor_fatura_total,
            prev_cesan,
            prev_h1,
            prev_h2,
            prev_h3,
            consumo_cesan_calc,
            bruto_h1,
            bruto_h2,
            bruto_h3,
            GREATEST(0, (bruto_h1 - bruto_h2)) AS consumo_h1,
            GREATEST(0, bruto_h2) AS consumo_h2,
            GREATEST(0, bruto_h3) AS consumo_h3,
            CASE
                WHEN (consumo_cesan_calc > 0) THEN (valor_fatura_total / consumo_cesan_calc)
                ELSE 0
            END AS valor_por_m3
        FROM deltas
    )
    SELECT id,
        data_leitura,
        leitura_cesan,
        leitura_h1,
        leitura_h2,
        leitura_h3,
        valor_fatura_total,
        consumo_cesan_calc AS consumo_cesan,
        consumo_h1,
        consumo_h2,
        consumo_h3,
        GREATEST(0, (consumo_cesan_calc - ((consumo_h1 + consumo_h2) + consumo_h3))) AS consumo_servico,
        valor_por_m3,
        (consumo_h1 * valor_por_m3) AS valor_h1,
        (consumo_h2 * valor_por_m3) AS valor_h2,
        (consumo_h3 * valor_por_m3) AS valor_h3,
        (GREATEST(0, (consumo_cesan_calc - ((consumo_h1 + consumo_h2) + consumo_h3))) * valor_por_m3) AS valor_servico
    FROM consumos;
    ";

    $pdo->exec($vwAguaSql);
    echo "<p style='color:green;'>✅ View <strong>vw_agua_rateio</strong> criada com sucesso.</p>";

    // View 2: vw_energia_rateio
    $vwEnergiaSql = "
    CREATE VIEW vw_energia_rateio AS
    WITH ord AS (
        SELECT id,
            data_leitura,
            local_medidor,
            leitura_padrao,
            leitura_interno,
            leitura_interno_2,
            leitura_interno_anterior_ref,
            leitura_interno_2_anterior_ref,
            valor_fatura_total,
            LAG(leitura_interno) OVER (PARTITION BY local_medidor ORDER BY data_leitura) AS prev_interno,
            LAG(leitura_interno_2) OVER (PARTITION BY local_medidor ORDER BY data_leitura) AS prev_interno_2
        FROM consumo_energia
    )
    SELECT id,
        data_leitura,
        local_medidor,
        leitura_padrao AS consumo_total_edp,
        valor_fatura_total,
        CASE
            WHEN (leitura_padrao > 0) THEN (valor_fatura_total / leitura_padrao)
            ELSE 0
        END AS valor_kwh,
        GREATEST(0, (leitura_interno - COALESCE(leitura_interno_anterior_ref, prev_interno, 0))) AS consumo_casa_2,
        CASE
            WHEN ((leitura_interno_2 IS NOT NULL) AND (COALESCE(leitura_interno_2_anterior_ref, prev_interno_2) IS NOT NULL)) THEN GREATEST(0, (leitura_interno_2 - COALESCE(leitura_interno_2_anterior_ref, prev_interno_2)))
            ELSE GREATEST(0, (leitura_padrao - GREATEST(0, (leitura_interno - COALESCE(leitura_interno_anterior_ref, prev_interno, 0)))))
        END AS consumo_casa_1,
        (GREATEST(0, (leitura_interno - COALESCE(leitura_interno_anterior_ref, prev_interno, 0))) *
            CASE
                WHEN (leitura_padrao > 0) THEN (valor_fatura_total / leitura_padrao)
                ELSE 0
            END) AS valor_casa_2,
        (
            CASE
                WHEN ((leitura_interno_2 IS NOT NULL) AND (COALESCE(leitura_interno_2_anterior_ref, prev_interno_2) IS NOT NULL)) THEN GREATEST(0, (leitura_interno_2 - COALESCE(leitura_interno_2_anterior_ref, prev_interno_2)))
                ELSE GREATEST(0, (leitura_padrao - GREATEST(0, (leitura_interno - COALESCE(leitura_interno_anterior_ref, prev_interno, 0)))))
            END *
            CASE
                WHEN (leitura_padrao > 0) THEN (valor_fatura_total / leitura_padrao)
                ELSE 0
            END) AS valor_casa_1
    FROM ord;
    ";

    $pdo->exec($vwEnergiaSql);
    echo "<p style='color:green;'>✅ View <strong>vw_energia_rateio</strong> criada com sucesso.</p>";

    // View 3: vw_energia_rateio_admin
    $vwEnergiaAdminSql = "
    CREATE VIEW vw_energia_rateio_admin AS
    SELECT v.id,
        v.data_leitura,
        v.local_medidor,
        v.consumo_total_edp,
        v.valor_fatura_total,
        v.valor_kwh,
        v.consumo_casa_2,
        v.valor_casa_2,
        v.consumo_casa_1,
        v.valor_casa_1,
        ce.leitura_interno,
        ce.leitura_interno_2,
        ce.leitura_interno_anterior_ref,
        ce.leitura_interno_2_anterior_ref
    FROM vw_energia_rateio v
    JOIN consumo_energia ce ON ce.id = v.id;
    ";

    $pdo->exec($vwEnergiaAdminSql);
    echo "<p style='color:green;'>✅ View <strong>vw_energia_rateio_admin</strong> criada com sucesso.</p>";

} catch (Exception $e) {
    echo "<p style='color:red;'>❌ Erro ao criar views: " . $e->getMessage() . "</p>";
}

echo "<h2>Migração finalizada!</h2>";
echo "<p>Agora você já pode testar o sistema apontando para esta base MySQL.</p>";
