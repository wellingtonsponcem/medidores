<?php
/**
 * Bootstrap do bot dos medidores (D-Meter).
 *
 * Por que existe: o app irmão do Uber Control resolve tudo com webhook serverless
 * em Node. Aqui a hospedagem é compartilhada (Hostinger/PHP), então o mesmo
 * princípio vira um webhook PHP. Nenhuma dependência externa — só curl nativo.
 *
 * Ordem de carregamento dos segredos (mesma regra do bot do Finpo: env -> banco):
 *   1. variável já injetada pelo ambiente
 *   2. arquivo de env carregado do disco
 * O arquivo é procurado FORA da public_web em produção (veja carregarEnv()).
 */

if (!defined('BOT_RAIZ')) {
    define('BOT_RAIZ', dirname(__DIR__));
}

// URL pública do painel — aparece no fim de quase toda mensagem do bot.
if (!defined('SITE_URL')) {
    define('SITE_URL', env('MEDIDORES_URL', 'https://wedistinto.com/medidores/'));
}

require_once __DIR__ . '/lib/Formato.php';
require_once __DIR__ . '/lib/Sanitizador.php';
require_once __DIR__ . '/lib/Http.php';
require_once __DIR__ . '/lib/Rateio.php';
require_once __DIR__ . '/lib/Gemini.php';
require_once __DIR__ . '/lib/Telegram.php';
require_once __DIR__ . '/lib/Banco.php';
require_once __DIR__ . '/lib/Conversa.php';
require_once __DIR__ . '/lib/Pendencia.php';
require_once __DIR__ . '/lib/Ocr.php';
require_once __DIR__ . '/lib/Respostas.php';
require_once __DIR__ . '/lib/Interpretacao.php';
require_once __DIR__ . '/lib/Acesso.php';
require_once BOT_RAIZ . '/db_config.php';

/**
 * Carrega o arquivo de variáveis sem derrubar o processo se ele não existir.
 *
 * Em produção o arquivo mora FORA de public_html (ex.: /home/u306254544/medidores.env)
 * porque subir .env dentro da webroot exporia os segredos num GET direto.
 */
function carregarEnv()
{
    static $carregado = false;
    if ($carregado) {
        return;
    }
    $carregado = true;

    $candidatos = [];
    if (getenv('MEDIDORES_ENV_PATH')) {
        $candidatos[] = getenv('MEDIDORES_ENV_PATH');
    }
    // Dois níveis acima de public_html -> /home/u306254544, que o servidor web
    // não serve. Um nível só seria public_html/medidores.env — dentro da
    // webroot, entregando a chave do Gemini num GET direto. O .htaccess nega
    // .env* como rede de segurança, mas quem depende dele como única barreira
    // já está um passo de entregar o segredo.
    $candidatos[] = dirname(BOT_RAIZ, 2) . '/medidores.env';
    // Fallbacks para desenvolvimento local — nunca usar em produção.
    $candidatos[] = BOT_RAIZ . '/.env';
    $candidatos[] = BOT_RAIZ . '/env';

    foreach ($candidatos as $arquivo) {
        if (!is_readable($arquivo)) {
            continue;
        }
        $linhas = file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($linhas === false) {
            continue;
        }
        foreach ($linhas as $linha) {
            $linha = trim($linha);
            // ignora comentários e linhas malformadas em vez de quebrar o boot
            if ($linha === '' || $linha[0] === '#' || strpos($linha, '=') === false) {
                continue;
            }
            [$nome, $valor] = explode('=', $linha, 2);
            $nome = trim($nome);
            $valor = trim($valor, " \t\n\r\0\x0B\"'");
            if ($nome === '' || getenv($nome) !== false) {
                continue; // já definida no ambiente vence o arquivo
            }
            putenv("$nome=$valor");
            $_ENV[$nome] = $valor;
        }
        return;
    }
}

/**
 * Lê uma variável de ambiente. Retorna $padrao quando ausente ou vazia —
 * assim uma env em branco no servidor não vira "modo aberto" por engano.
 */
function env(string $nome, ?string $padrao = null): ?string
{
    $valor = getenv($nome);
    if ($valor === false || trim($valor) === '') {
        return $padrao;
    }
    return trim($valor);
}

/**
 * Conexão PDO compartilhada.
 *
 * O teste injeta o banco em `Banco::$pdo` antes de chamar qualquer função,
 * exatamente como o Gampo faz com `globalThis.__GANPO_SQL_TESTE__`. Isso permite
 * exercitar o handler real sem depender de um MySQL rodando.
 */
function pdo(): PDO
{
    return Banco::pdo();
}

carregarEnv();
