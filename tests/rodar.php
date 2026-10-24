<?php
/**
 * Runner de testes — `php tests/rodar.php` ou `npm test`.
 *
 * Cada arquivo roda num processo separado. Isso isola o estado estático
 * (Banco::$pdo, Http::$transporte) e garante que um erro fatal num teste derrube
 * só ele, e não a suíte inteira.
 *
 * Nenhum teste faz chamada de rede: o transporte HTTP é substituído e o banco é
 * o duplo em memória. Rodar os testes nunca mexe em produção.
 */

$raiz = dirname(__DIR__);
$arquivos = glob(__DIR__ . '/*.test.php');
sort($arquivos);

if (!$arquivos) {
    echo "Nenhum teste encontrado em tests/*.test.php\n";
    exit(1);
}

$php = PHP_BINARY;
$aprovados = 0;
$reprovados = [];
$totalOk = 0;

echo "Rodando " . count($arquivos) . " arquivo(s) de teste...\n\n";

foreach ($arquivos as $arquivo) {
    $nome = basename($arquivo);
    $saida = [];
    $codigo = 0;
    exec(escapeshellarg($php) . ' ' . escapeshellarg($arquivo) . ' 2>&1', $saida, $codigo);
    $texto = implode("\n", $saida);

    if (preg_match('/^(\d+) verificações ok/m', $texto, $m)) {
        $totalOk += (int) $m[1];
    }

    if ($codigo === 0) {
        $aprovados++;
        echo "✓ {$nome}\n";
        foreach ($saida as $linha) {
            if (strpos($linha, '  ok') === 0) {
                echo '    ' . trim($linha) . "\n";
            }
        }
    } else {
        $reprovados[] = $nome;
        echo "✗ {$nome}\n";
        echo '    ' . str_replace("\n", "\n    ", $texto) . "\n";
    }
}

echo "\n" . str_repeat('=', 55) . "\n";
echo "{$aprovados}/" . count($arquivos) . " arquivos aprovados · {$totalOk} verificações\n";

if ($reprovados) {
    echo 'Reprovados: ' . implode(', ', $reprovados) . "\n";
    exit(1);
}
echo "Tudo verde.\n";
