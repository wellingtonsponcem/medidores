<?php
/**
 * Ambiente de teste — o equivalente ao ambiente.mjs do Gampo.
 *
 * Cada teste roda com banco falso injetado e sem nenhum segredo do ambiente
 * real. Isso importa: um teste que lê a GEMINI_API_KEY de verdade e faz uma
 * chamada de rede não é teste, é loteria.
 */

require_once dirname(__DIR__, 2) . '/bot/config.php';
require_once __DIR__ . '/PdoFalso.php';

// zera o estado estático entre arquivos de teste
Banco::$pdo = null;
Http::$transporte = null;
putenv('GEMINI_API_KEY');
putenv('TELEGRAM_BOT_TOKEN');
putenv('TELEGRAM_SECRET_TOKEN');
putenv('TELEGRAM_ALLOWED_USER_IDS');

$GLOBALS['__ok'] = 0;
$GLOBALS['__falhas'] = [];

/** @return PdoFalso */
function ambiente(): PdoFalso
{
    $pdo = new PdoFalso();
    Banco::$pdo = $pdo;
    Banco::$tabelasOk = false;
    return $pdo;
}

function ok($condicao, string $mensagem): void
{
    if ($condicao) {
        $GLOBALS['__ok']++;
        echo "  ok  {$mensagem}\n";
        return;
    }
    $GLOBALS['__falhas'][] = $mensagem;
    echo "FAIL  {$mensagem}\n";
}

function igual($obtido, $esperado, string $mensagem): void
{
    $detalhe = '';
    if ($obtido !== $esperado) {
        $detalhe = ' (obtido: ' . var_export($obtido, true) . ' | esperado: ' . var_export($esperado, true) . ')';
    }
    ok($obtido === $esperado, $mensagem . $detalhe);
}

function aproximado(float $obtido, float $esperado, string $mensagem, float $epsilon = 0.001): void
{
    ok(abs($obtido - $esperado) <= $epsilon,
        $mensagem . ' (obtido: ' . round($obtido, 4) . ' | esperado: ' . round($esperado, 4) . ')');
}

/** Roda ao final de cada arquivo de teste e sai com 1 se algo falhou. */
function encerrarTeste(): void
{
    echo "\n" . $GLOBALS['__ok'] . ' verificações ok, ' . count($GLOBALS['__falhas']) . " falha(s)\n";
    exit($GLOBALS['__falhas'] ? 1 : 0);
}

/**
 * Semente com uma leitura anterior, para os testes de rateio terem referência.
 *
 * A água: CESAN 1772, H1 300, H2 183, H3 284, fatura 500.
 */
function semearLeituraAnteriorAgua(PdoFalso $pdo): array
{
    $anterior = [
        'id' => 'seed-agua',
        'data_leitura' => '2026-09-05',
        'leitura_cesan' => 1772,
        'leitura_h1' => 300,
        'leitura_h2' => 183,
        'leitura_h3' => 284,
        'valor_fatura_total' => 500,
    ];
    $pdo->semeando('consumo_agua', $anterior);
    $pdo->semeando('vw_agua_rateio', [
        'id' => 'seed-agua',
        'data_leitura' => '2026-09-05',
        'local_medidor' => null,
        'consumo_cesan' => 34,
        'consumo_h1' => 12,
        'consumo_h2' => 8,
        'consumo_h3' => 11,
        'consumo_servico' => 3,
        'valor_por_m3' => 14.7058,
        'valor_h1' => 176.47,
        'valor_h2' => 117.65,
        'valor_h3' => 161.76,
        'valor_servico' => 44.12,
        'valor_fatura_total' => 500,
    ]);
    return $anterior;
}
