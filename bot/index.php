<?php
/**
 * Webhook do bot do Telegram — D-Meter.
 *
 * Rota única. O Telegram envia o update aqui e devolvemos 200. É o mesmo
 * desenho dos dois projetos irmãos: webhook em vez de long polling, porque a
 * hospedagem compartilhada não mantém um processo vivo esperando mensagem.
 *
 * Proteções, nesta ordem (a mesma do Gampo, que é o mais rígido dos dois):
 *   1. secret_token do Telegram — só o próprio Telegram alcança esta URL
 *   2. allowlist de user id, falha fechada — sem env e sem cadastro, ninguém entra
 *   3. dedupe por update_id — o Telegram reenvia se a resposta demorar
 *
 * E a regra de negócio que não se negocia: nada é gravado no banco de leituras
 * sem confirmação explícita por botão. Ver Pendencia.php.
 */

require_once __DIR__ . '/config.php';

/** Janela em que fotos de um álbum ainda são consideradas o mesmo envio. */
const ALBUM_SILENCIO_MS = 3000;
/** Teto de espera por uma foto que nunca chega, para não segurar a requisição. */
const ALBUM_LIMITE_MS = 20000;
const ALBUM_PASSO_MS = 600;

/** Menu publicado no Telegram pelo scripts/telegram-setup.mjs. */
const COMANDOS = [
    '/resumo' => 'Água e energia do último mês',
    '/mensagens' => 'Mensagens individuais dos moradores para copiar',
    '/agua' => 'Rateio detalhado da água (CESAN)',
    '/energia' => 'Rateio detalhado da energia',
    '/ultimo' => 'Últimas leituras brutas',
    '/compartilhar' => 'Texto do rateio para copiar',
    '/pendentes' => 'Leituras aguardando confirmação',
    '/sincronizar' => 'Sincronizar whatímetro Tuya e Loja',
    '/logo' => 'Envia o logo do D-Meter em PNG e JPG',
    '/senha' => 'Redefinir senha de morador ou admin',
    '/vincular' => 'Entrar com o usuário do painel',
    '/sair' => 'Desvincular este aparelho',
    '/ajuda' => 'Lista o que o bot faz',
];

// ============================================================ entrada

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($metodo === 'GET') {
    // health check: diagnostica a configuração sem entregar nada sensível
    echo json_encode([
        'servico' => 'D-Meter Telegram Webhook',
        'tem_token' => Telegram::temToken(),
        'token_mascarado' => Telegram::temToken() ? maskar((string) env('TELEGRAM_BOT_TOKEN')) : null,
        'secret_configurado' => env('TELEGRAM_SECRET_TOKEN') !== null,
        'allowlist' => count(Banco::idsPermitidos()),
        'url_painel' => SITE_URL,
    ]);
    exit;
}

if ($metodo !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não suportado']);
    exit;
}

// ---- 1. secret_token: só o Telegram passa ----
$secret = env('TELEGRAM_SECRET_TOKEN');
if ($secret === null) {
    // falha fechada: sem segredo configurado o webhook fica fechado em vez de aberto.
    // O scripts/telegram-setup.mjs gera e grava o segredo sozinho para isso não travar.
    error_log('[bot] TELEGRAM_SECRET_TOKEN ausente — webhook recusando requisições');
    http_response_code(500);
    echo json_encode(['error' => 'TELEGRAM_SECRET_TOKEN não configurado. Rode: node scripts/telegram-setup.mjs set <url>']);
    exit;
}
$recebido = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if (!hash_equals($secret, (string) $recebido)) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$corpo = file_get_contents('php://input');
$update = json_decode($corpo, true);
if (!is_array($update)) {
    http_response_code(400);
    echo json_encode(['error' => 'update inválido']);
    exit;
}

$updateId = (int) ($update['update_id'] ?? 0);
if ($updateId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'update_id ausente']);
    exit;
}

// ---- 3. dedupe ----
try {
    if (!Banco::marcarUpdate($updateId)) {
        echo json_encode(['ignorado' => 'duplicado']);
        exit;
    }
    if (random_int(1, 100) <= 2) {
        Banco::limparUpdatesAntigos();
    }
} catch (Exception $e) {
    // sem banco não dá para nem sequer dedupe: devolve erro para o Telegram reenviar depois
    error_log('[bot] falha ao registrar update: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'banco indisponível']);
    exit;
}

try {
    despachar($update);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    // libera o update: o Telegram pode reenviar, e assim a mensagem não se perde
    Banco::liberarUpdate($updateId);
    error_log('[bot] erro ao processar update ' . $updateId . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'falha ao processar']);
}

// ============================================================ despacho

function despachar(array $update): void
{
    if (isset($update['callback_query'])) {
        aoClicarBotao($update['callback_query']);
        return;
    }
    if (!isset($update['message'])) {
        return;
    }
    $m = $update['message'];
    $chatId = (int) ($m['chat']['id'] ?? 0);
    if ($chatId === 0) {
        return;
    }

    $textoBruto = trim((string) ($m['text'] ?? ''));
    if ($textoBruto !== '' && preg_match('/^\/?(vincular|login)\b/i', $textoBruto)) {
        aoComando($chatId, $m, $textoBruto);
        return;
    }

    // ---- 2. allowlist, falha fechada ----
    $userId = isset($m['from']['id']) ? (int) $m['from']['id'] : null;
    if (!autorizado($chatId, $userId)) {
        $nome = (string) ($m['from']['first_name'] ?? 'Olá');
        Telegram::enviarMensagem($chatId, resp_boas_vindas($nome) . "\n\n" . resp_nao_autorizado());
        return;
    }
    Banco::marcarUso($chatId);

    // mídia primeiro: uma legenda pode vir com a foto
    if (!empty($m['photo'])) {
        aoReceberFoto($chatId, $m, end($m['photo']));
        return;
    }
    if (!empty($m['document']) && isFoto($m['document'])) {
        aoReceberFoto($chatId, $m, $m['document']);
        return;
    }
    if (!empty($m['voice']) || !empty($m['audio'])) {
        Telegram::enviarMensagem($chatId, '🎤 Por enquanto não leio áudio. Mande por texto ou foto.');
        return;
    }

    $texto = trim((string) ($m['text'] ?? ''));
    if ($texto === '') {
        return;
    }
    if ($texto[0] === '/') {
        aoComando($chatId, $m, $texto);
        return;
    }
    aoTexto($chatId, $m, $texto);
}

function isFoto(array $anexo): bool
{
    $mime = (string) ($anexo['mime_type'] ?? '');
    return strpos($mime, 'image/') === 0;
}

// ============================================================ segurança

function maskar(string $token): string
{
    $partes = explode(':', $token);
    return (count($partes) === 2 ? $partes[0] . ':' : '') . str_repeat('•', 10);
}

/**
 * Quem pode falar com o bot.
 *
 * Duas portas: a allowlist da env (o administrador liberou o número) ou um chat
 * já registrado em telegram_chats (vinculado antes). Sem nenhuma das duas, não
 * entra — inclusive se a env estiver faltando, que é o modo aberto por engano.
 */
function autorizado(int $chatId, ?int $userId): bool
{
    $permitidos = Banco::idsPermitidos();
    if ($userId !== null && in_array($userId, $permitidos, true)) {
        return true;
    }
    // chat já conhecido: quem está no banco passa, mesmo sem a env
    try {
        $chat = Banco::chat($chatId);
        return $chat !== null && !empty($chat['perfil']);
    } catch (Exception $e) {
        return false;
    }
}

function chatDe(int $chatId): array
{
    return Banco::chat($chatId) ?: [];
}

function ehAdmin(int $chatId): bool
{
    return (string) (chatDe($chatId)['perfil'] ?? '') === 'admin';
}

function perfilDe(int $chatId): ?string
{
    $p = chatDe($chatId)['perfil'] ?? null;
    return $p !== null && $p !== '' ? (string) $p : null;
}

// ============================================================ comandos

function aoComando(int $chatId, array $m, string $texto): void
{
    $partes = preg_split('/\s+/', trim($texto));
    $comando = strtolower(ltrim((string) $partes[0], '/'));
    $args = array_slice($partes, 1);

    switch ($comando) {
        case 'start':
        case 'ajuda':
        case 'help':
        case 'comandos':
            Telegram::enviarMensagem($chatId, resp_ajuda(ehAdmin($chatId)));
            return;

        case 'vincular':
        case 'login':
            cmdVincular($chatId, $m, $args);
            return;

        case 'sair':
            Banco::desvincularChat($chatId);
            Telegram::enviarMensagem($chatId, 'Aparelho desvinculado. Use `/vincular usuário senha` para entrar de novo.');
            return;

        case 'resumo':
            cmdResumo($chatId);
            return;

        case 'mensagens':
        case 'msg':
            cmdMensagens($chatId);
            return;

        case 'agua':
            cmdAgua($chatId);
            return;

        case 'energia':
            $local = isset($args[0]) ? energia_local_normalizado($args[0]) : null;
            cmdEnergia($chatId, $local);
            return;

        case 'ultimo':
            cmdUltimo($chatId);
            return;

        case 'compartilhar':
            cmdCompartilhar($chatId);
            return;

        case 'pendentes':
            cmdPendentes($chatId);
            return;

        case 'sincronizar':
        case 'sync':
        case 'tuya':
            cmdSincronizar($chatId, $args);
            return;

        case 'usuarios':
            cmdUsuarios($chatId);
            return;

        case 'senha':
        case 'resetarsenha':
        case 'redefinir':
            cmdRedefinirSenha($chatId, $args);
            return;

        case 'logo':
        case 'logotipo':
            cmdLogo($chatId);
            return;

        default:
            Telegram::enviarMensagem($chatId, "Não conheço o comando `/{$comando}`.\n\n" . resp_ajuda(ehAdmin($chatId)));
    }
}

function cmdVincular(int $chatId, array $m, array $args): void
{
    if (count($args) < 2) {
        Telegram::enviarMensagem($chatId,
            "Para vincular, use:\n`/vincular seu_usuario sua_senha`\n\n"
            . "São os mesmos dados do painel em " . SITE_URL);
        return;
    }
    $usuario = $args[0];
    $senha = implode(' ', array_slice($args, 1));

    try {
        $u = Banco::usuarioPorNomeESenha($usuario, $senha);
    } catch (Exception $e) {
        Telegram::enviarMensagem($chatId, 'Não consegui consultar o cadastro agora. Tente em instantes.');
        return;
    }
    if ($u === null) {
        Telegram::enviarMensagem($chatId, resp_vinculo_errado());
        return;
    }

    $userId = isset($m['from']['id']) ? (int) $m['from']['id'] : null;
    $nome = (string) ($m['from']['first_name'] ?? $u['nome']);
    Banco::vincularChat($chatId, $userId, $nome, (string) $u['perfil']);

    $s = "✅ Vinculado como *" . ucfirst((string) $u['nome']) . "* ({$u['perfil']}).\n\n";
    $s .= ehAdmin($chatId)
        ? "Você é administrador: pode consultar tudo e lançar leitura por foto ou por texto.\n\n" . resp_ajuda(true)
        : "Você consulta o consumo da sua unidade.\n\n" . resp_ajuda(false);
    Telegram::enviarMensagem($chatId, $s);
}

function cmdResumo(int $chatId): void
{
    $nomes = Banco::nomesPorPerfil();
    $perfil = perfilDe($chatId);
    $agua = Banco::ultimoRateioAgua();
    $energia = Banco::ultimoRateioEnergia('rua_inhambu');
    Telegram::enviarMensagem($chatId, resp_resumo($agua, $energia, $nomes, $perfil));
}

function cmdAgua(int $chatId): void
{
    $row = Banco::ultimoRateioAgua();
    if (!$row) {
        Telegram::enviarMensagem($chatId, 'Ainda não há leitura de água lançada.');
        return;
    }
    Telegram::enviarMensagem($chatId, resp_agua($row, Banco::nomesPorPerfil(), perfilDe($chatId)));
}

function cmdEnergia(int $chatId, ?string $local): void
{
    $perfil = perfilDe($chatId);
    $nomes = Banco::nomesPorPerfil();

    if ($local !== null && in_array($local, ['av_brasil', 'rua_inhambu'], true)) {
        $row = Banco::ultimoRateioEnergia($local);
        if (!$row) {
            Telegram::enviarMensagem($chatId, 'Ainda não há leitura de energia lançada para este imóvel.');
            return;
        }
        Telegram::enviarMensagem($chatId, resp_energia($row, $nomes, $perfil));
        return;
    }

    // morador só tem um imóvel; para ele o local é implícito
    if ($perfil !== null && $perfil !== 'admin') {
        $local = in_array($perfil, ['loja2', 'casa3'], true) ? 'av_brasil' : 'rua_inhambu';
        $row = Banco::ultimoRateioEnergia($local);
        if (!$row) {
            Telegram::enviarMensagem($chatId, 'Ainda não há leitura de energia lançada.');
            return;
        }
        Telegram::enviarMensagem($chatId, resp_energia($row, $nomes, $perfil));
        return;
    }

    // Admin sem especificar imóvel: exibe os dois imóveis
    $eBrasil = Banco::ultimoRateioEnergia('av_brasil');
    $eInhambu = Banco::ultimoRateioEnergia('rua_inhambu');

    if (!$eInhambu && !$eBrasil) {
        Telegram::enviarMensagem($chatId, 'Ainda não há leitura de energia lançada.');
        return;
    }

    $msg = '';
    if ($eBrasil) {
        $msg .= resp_energia($eBrasil, $nomes, null) . "\n\n─────────────\n\n";
    }
    if ($eInhambu) {
        $msg .= resp_energia($eInhambu, $nomes, null);
    }
    Telegram::enviarMensagem($chatId, trim($msg));
}

function cmdUltimo(int $chatId): void
{
    $a = Banco::ultimaAguaBruta();
    $e = Banco::ultimaEnergiaBruta('rua_inhambu');
    $e2 = Banco::ultimaEnergiaBruta('av_brasil');

    $s = "*Últimas leituras brutas*\n\n";
    if ($a) {
        $s .= '💧 Água — ' . fmt_data_br($a['data_leitura']) . "\n";
        $s .= 'CESAN ' . fmt_num($a['leitura_cesan'], 0)
            . ' · H1 ' . fmt_num($a['leitura_h1'], 0)
            . ' · H2 ' . fmt_num($a['leitura_h2'], 0)
            . ' · H3 ' . fmt_num($a['leitura_h3'], 0)
            . ' · ' . fmt_moeda($a['valor_fatura_total']) . "\n\n";
    } else {
        $s .= "_Nenhuma leitura de água._\n\n";
    }
    foreach ([['rua_inhambu', $e, 'Rua Inhambu'], ['av_brasil', $e2, 'Av. Brasil']] as $par) {
        if ($par[1]) {
            $s .= "⚡ {$par[2]} — " . fmt_data_br($par[1]['data_leitura']) . "\n";
            $s .= 'Padrão ' . fmt_num($par[1]['leitura_padrao'], 2)
                . ' · Interno ' . fmt_num($par[1]['leitura_interno'], 2)
                . ' · ' . fmt_moeda($par[1]['valor_fatura_total']) . "\n\n";
        }
    }
    Telegram::enviarMensagem($chatId, $s);
}

function cmdCompartilhar(int $chatId): void
{
    if (!ehAdmin($chatId)) {
        Telegram::enviarMensagem($chatId, resp_compartilhar(
            Banco::ultimoRateioAgua(),
            Banco::ultimoRateioEnergia('rua_inhambu'),
            Banco::nomesPorPerfil()
        ));
        return;
    }
    Telegram::enviarMensagem($chatId, resp_compartilhar(
        Banco::ultimoRateioAgua(),
        Banco::ultimoRateioEnergia('rua_inhambu'),
        Banco::nomesPorPerfil()
    ) . "\n\n_Com copiar e colar você manda por WhatsApp._");
}

function cmdMensagens(int $chatId): void
{
    if (!ehAdmin($chatId)) {
        Telegram::enviarMensagem($chatId, 'Apenas administradores podem solicitar as mensagens individuais de todos os moradores.');
        return;
    }
    $nomes = Banco::nomesPorPerfil();
    $agua = Banco::ultimoRateioAgua();
    $enviou = false;
    if ($agua) {
        $separadasAgua = resp_mensagens_separadas_agua($agua, $nomes);
        foreach ($separadasAgua as $msg) {
            Telegram::enviarMensagem($chatId, $msg);
            $enviou = true;
        }
    }
    $energiaInhambu = Banco::ultimoRateioEnergia('rua_inhambu');
    if ($energiaInhambu) {
        $separadasInhambu = resp_mensagens_separadas_energia($energiaInhambu, $nomes);
        foreach ($separadasInhambu as $msg) {
            Telegram::enviarMensagem($chatId, $msg);
            $enviou = true;
        }
    }
    $energiaAv = Banco::ultimoRateioEnergia('av_brasil');
    if ($energiaAv) {
        $separadasAv = resp_mensagens_separadas_energia($energiaAv, $nomes);
        foreach ($separadasAv as $msg) {
            Telegram::enviarMensagem($chatId, $msg);
            $enviou = true;
        }
    }
    if (!$enviou) {
        Telegram::enviarMensagem($chatId, 'Nenhum rateio recente encontrado no sistema para gerar mensagens.');
    }
}

function cmdPendentes(int $chatId): void
{
    $lista = Banco::pendenciasDoChat($chatId);
    Telegram::enviarMensagem($chatId, resp_pendentes($lista, Banco::nomesPorPerfil()));
}

function cmdSincronizar(int $chatId, array $args): void
{
    $perfil = perfilDe($chatId);
    $ehAdmin = ehAdmin($chatId);
    $ehFernando = ($perfil === 'loja2');

    if (!$ehAdmin && !$ehFernando) {
        Telegram::enviarMensagem($chatId, resp_sem_permissao());
        return;
    }

    Telegram::acaoChat($chatId, 'typing');
    $res = Pendencia::executarTuya([]);
    $tuya = Banco::ultimoTuya();

    if ($tuya) {
        $val = fmt_num((float) $tuya['valor_extraido'], 2) . ' kWh';
        $dt = fmt_data_br($tuya['data_leitura']);
        $s = "🔄 *Watímetro Tuya Sincronizado!*\n\n"
            . "• *Leitura:* `{$val}`\n"
            . "• *Registro:* {$dt}\n\n";

        $watLoja = Banco::leituraFoto('ULTIMO_WATIMETRO_LOJA2');
        if ($watLoja) {
            $quando = $watLoja['em'] ? ' (foto de ' . fmt_data_br($watLoja['em']) . ')' : '';
            $s .= "🔌 *Watímetro Loja (Fernando):* `" . fmt_num($watLoja['valor'], 2) . " kWh`{$quando}\n\n";
        }
        $s .= "Ambos os medidores estão sincronizados e prontos para o rateio da Av. Brasil.";
        Telegram::enviarMensagem($chatId, $s);
    } else {
        if ($res['ok']) {
            Telegram::enviarMensagem($chatId, "🔄 Sincronização Tuya concluída com sucesso!");
        } else {
            Telegram::enviarMensagem($chatId, "⚠️ Não consegui sincronizar com a Tuya no momento: " . ($res['erro'] ?? 'erro desconhecido'));
        }
    }
}

function cmdTuya(int $chatId, array $args): void
{
    cmdSincronizar($chatId, $args);
}

function cmdUsuarios(int $chatId): void
{
    if (!ehAdmin($chatId)) {
        Telegram::enviarMensagem($chatId, resp_sem_permissao());
        return;
    }
    try {
        Telegram::enviarMensagem($chatId, resp_usuarios(Banco::usuarios()));
    } catch (Exception $e) {
        Telegram::enviarMensagem($chatId, 'Não consegui listar os acessos agora.');
    }
}

function cmdLogo(int $chatId): void
{
    Telegram::acaoChat($chatId, 'upload_photo');
    $urlJpg = SITE_URL . '/logo.jpg';
    $urlPng = SITE_URL . '/logo.png';
    $urlSvg = SITE_URL . '/logo.svg';

    $teclado = [
        [
            ['text' => '🌐 Ver SVG Vetorial', 'url' => $urlSvg],
            ['text' => '📥 Baixar PNG HD', 'url' => $urlPng],
        ]
    ];

    // 1. Envia o JPG como Foto com legenda e botões
    Telegram::enviarFoto(
        $chatId,
        $urlJpg,
        "🖼 *Logo D-Meter (JPG)*\n\n• Resolução: 1905x1875\n• Fundo: Branco\n• Ideal para: Documentos, faturas e relatórios",
        $teclado
    );

    // 2. Envia o PNG como Documento (preserva canal Alpha e qualidade original sem compressão)
    Telegram::enviarDocumento(
        $chatId,
        $urlPng,
        "📄 *Logo D-Meter (PNG Transparente)*\n\n• Resolução: 1905x1875 (com canal Alpha)\n• Fundo: Transparente\n• Ideal para: Design, web e sobreposições"
    );
}

function cmdRedefinirSenha(int $chatId, array $args): void
{
    if (!ehAdmin($chatId)) {
        Telegram::enviarMensagem($chatId, resp_sem_permissao());
        return;
    }
    if (count($args) < 2) {
        Telegram::enviarMensagem($chatId, "Para redefinir a senha de um usuário, use:\n`/senha usuario novasenha`\n\nExemplo: `/senha maria 123456`");
        return;
    }
    $usuario = trim((string) $args[0]);
    $novaSenha = trim((string) $args[1]);
    if (strlen($novaSenha) < 4) {
        Telegram::enviarMensagem($chatId, "⚠️ A nova senha deve ter pelo menos 4 caracteres.");
        return;
    }

    try {
        $ok = Banco::redefinirSenha($usuario, $novaSenha);
        if ($ok) {
            Telegram::enviarMensagem(
                $chatId,
                "✅ Senha do usuário *{$usuario}* redefinida com sucesso para: `{$novaSenha}`\n\n"
                . "A pessoa já pode enviar no bot do Telegram:\n"
                . "`/vincular {$usuario} {$novaSenha}`"
            );
        } else {
            Telegram::enviarMensagem($chatId, "⚠️ Usuário *{$usuario}* não foi encontrado no cadastro.");
        }
    } catch (Exception $e) {
        Telegram::enviarMensagem($chatId, "Erro ao redefinir a senha: " . $e->getMessage());
    }
}

// ============================================================ texto livre

function aoTexto(int $chatId, array $m, string $texto): void
{
    $perfil = perfilDe($chatId);

    $textoNorm = strtolower(trim($texto));
    if (in_array($textoNorm, ['sincronizar', 'sincroniza', 'sincronização', 'sincronizacao', 'sync', 'tuya'], true)) {
        cmdSincronizar($chatId, []);
        return;
    }

    // estado curto vence: o bot estava esperando uma resposta desta conversa
    $estado = Conversa::ler($chatId);
    if ($estado['estado'] === 'escolhendo_local') {
        Conversa::limpar($chatId);
        cmdEnergia($chatId, energia_local_normalizado($texto));
        return;
    }

    $intencao = Interpretacao::analisar($texto, $perfil);

    if ($intencao === null) {
        $ia = Interpretacao::viaIa($texto, $perfil);
        if ($ia['ok'] && $ia['intencao'] === 'consulta' && !empty($ia['consulta'])) {
            $intencao = [
                'tipo' => 'consulta',
                'pergunta' => $ia['consulta'],
                'local' => $ia['local'] ?? Interpretacao::detectarLocal($texto),
                'texto' => $texto,
            ];
        } elseif ($ia['ok'] && $ia['intencao'] === 'lancamento' && !empty($ia['lancamento'])) {
            $intencao = iaParaLancamento($ia['lancamento'], $perfil);
        } elseif ($ia['ok'] && $ia['intencao'] === 'conversa') {
            if (!empty($ia['resposta'])) {
                Telegram::enviarMensagem($chatId, $ia['resposta']);
            } else {
                Telegram::enviarMensagem($chatId, resp_ajuda(ehAdmin($chatId)));
            }
            return;
        } else {
            Telegram::enviarMensagem($chatId,
                "Não entendi. Envie uma foto da fatura, ou digite por exemplo:\n"
                . "`agua cesan 1806 h1 490 h2 189 h3 284 fatura 560.18`\n\n"
                . resp_ajuda(ehAdmin($chatId)));
            return;
        }
    }

    if ($intencao['tipo'] === 'consulta') {
        responderConsulta($chatId, $intencao['pergunta'], $intencao['local'] ?? null, $texto);
        return;
    }

    // lançar leitura é exclusivo do administrador
    if (!ehAdmin($chatId)) {
        Telegram::enviarMensagem($chatId, resp_sem_permissao());
        return;
    }
    criarPendenciaDeLancamento($chatId, $intencao);
}

function responderConsulta(int $chatId, string $pergunta, ?string $local = null, string $texto = ''): void
{
    switch ($pergunta) {
        case 'agua':
            cmdAgua($chatId);
            return;
        case 'energia':
            $perfil = perfilDe($chatId);
            if ($local === null && $texto !== '') {
                $local = Interpretacao::detectarLocal($texto);
            }
            if ($local === null && $perfil !== null && $perfil !== 'admin') {
                $local = in_array($perfil, ['casa3', 'loja2'], true) ? 'av_brasil' : 'rua_inhambu';
            }
            cmdEnergia($chatId, $local);
            return;
        case 'ultimo':
            cmdUltimo($chatId);
            return;
        case 'compartilhar':
            cmdCompartilhar($chatId);
            return;
        case 'mensagens':
            cmdMensagens($chatId);
            return;
        case 'logo':
            cmdLogo($chatId);
            return;
        default:
            cmdResumo($chatId);
    }
}

/** Converte a intenção vinda da IA no mesmo formato que a regra produz. */
function iaParaLancamento(array $l, ?string $perfil): array
{
    $tipo = ($l['tipo_dado'] ?? '') === 'energia' ? 'energia' : 'agua';
    $campos = is_array($l['campos'] ?? null) ? $l['campos'] : [];

    if ($tipo === 'agua') {
        $payload = [
            'data' => fmt_data_iso($l['data'] ?? null) ?? fmt_hoje(),
            'cesan' => sanitizar_numero($campos['cesan'] ?? null),
            'h1' => sanitizar_numero($campos['h1'] ?? null),
            'h2' => sanitizar_numero($campos['h2'] ?? null),
            'h3' => sanitizar_numero($campos['h3'] ?? null),
            'fatura' => sanitizar_numero($campos['fatura'] ?? null) ?? 0,
            'origem' => 'texto',
        ];
        return ['tipo' => 'lancamento', 'tipo_dado' => 'agua', 'payload' => $payload];
    }

    $local = energia_local_normalizado($campos['local'] ?? 'rua_inhambu');
    $ant = null;
    try {
        $ant = Banco::ultimaEnergiaBruta($local);
    } catch (Exception $e) {
        // sem referência anterior o rateio sai por diferença, que é o comportamento padrão
    }
    return [
        'tipo' => 'lancamento',
        'tipo_dado' => 'energia',
        'payload' => [
            'data' => fmt_data_iso($l['data'] ?? null) ?? fmt_hoje(),
            'local' => $local,
            'padrao' => sanitizar_numero($campos['padrao'] ?? null),
            'interno' => sanitizar_numero($campos['interno'] ?? null),
            'interno2' => null,
            'fatura' => sanitizar_numero($campos['fatura'] ?? null) ?? 0,
            'ref1' => $ant ? (float) ($ant['leitura_interno_anterior_ref'] ?? $ant['leitura_interno'] ?? 0) : null,
            'ref2' => null,
            'origem' => 'texto',
        ],
    ];
}

/** Valida e cria a pendência de um lançamento vindo de texto ou de IA. */
function criarPendenciaDeLancamento(int $chatId, array $intencao): void
{
    $payload = $intencao['payload'];
    $tipo = $intencao['tipo_dado'];

    if ($tipo === 'agua') {
        if ((float) ($payload['cesan'] ?? 0) <= 0) {
            Telegram::enviarMensagem($chatId,
                'Faltou a leitura da CESAN. Envie assim:\n`agua cesan 1806 h1 490 h2 189 h3 284 fatura 560.18`');
            return;
        }
        foreach (['h1', 'h2', 'h3'] as $h) {
            if (!isset($payload[$h]) || $payload[$h] === null) {
                $payload[$h] = 0;
            }
        }
    } else {
        if ((float) ($payload['padrao'] ?? 0) <= 0 && (float) ($payload['interno'] ?? 0) <= 0) {
            Telegram::enviarMensagem($chatId,
                'Faltou a leitura de energia. Envie assim:\n`energia padrao 2500 interno 3500 fatura 200.50`');
            return;
        }
    }

    $id = Pendencia::criar($chatId, $tipo, $payload);
    $p = Banco::pendencia($id);
    Telegram::enviarTeclado($chatId, Pendencia::texto($p, Banco::nomesPorPerfil()), Pendencia::teclado($id));
}

// ============================================================ fotos

/**
 * Recebe uma foto (ou as de um álbum) e propõe o lançamento.
 *
 * A legenda do envio tem prioridade sobre a IA: quem manda "h1" na legenda já
 * sabe o que a foto é, e aí não gastamos uma chamada de classificação.
 */
function aoReceberFoto(int $chatId, array $m, array $anexo): void
{
    $perfil = perfilDe($chatId);
    $ehAdmin = ehAdmin($chatId);
    $ehFernando = ($perfil === 'loja2');

    if (!$ehAdmin && !$ehFernando) {
        Telegram::enviarMensagem($chatId, resp_sem_permissao());
        return;
    }

    $legenda = trim((string) ($m['caption'] ?? ''));
    $papel = $legenda !== '' ? papelDaLegenda($legenda) : null;

    // a Loja 02 não tem conta de água: "fatura"/"conta" na legenda dela é a da EDP
    if ($ehFernando && $papel === 'fatura') {
        $papel = 'fatura_energia';
    }
    if ($ehFernando && $papel !== null && !in_array($papel, ['interno', 'fatura_energia'], true)) {
        Telegram::enviarMensagem($chatId, "Você pode enviar fotos do watímetro da sua loja ou da fatura de energia da Av. Brasil.");
        return;
    }

    $albumId = (string) ($m['media_group_id'] ?? '');
    if ($albumId !== '') {
        processarAlbum($chatId, $m, $anexo, $albumId, $papel);
        return;
    }
    processarUmArquivo($chatId, $m, $anexo, $papel);
}

/** Legenda curta diz o que a foto é — "h1", "fatura", "energia", "relógio". */
function papelDaLegenda(string $legenda): ?string
{
    $t = strtolower($legenda);
    if (preg_match('/\b(h1|hidro\s*1|hidrometro\s*1)\b/', $t)) {
        return 'h1';
    }
    if (preg_match('/\b(h2|hidro\s*2|hidrometro\s*2)\b/', $t)) {
        return 'h2';
    }
    if (preg_match('/\b(h3|hidro\s*3|hidrometro\s*3)\b/', $t)) {
        return 'h3';
    }
    // "relógio"/"whatímetro" antes de "energia", porque o queímetro é da energia
    if (preg_match('/\b(whatimetro|relorio|relatorio|interno|medidor)\b/', $t)) {
        return 'interno';
    }
    if (preg_match('/\b(energia|edp|kwh)\b/', $t)) {
        return 'fatura_energia';
    }
    if (preg_match('/\b(fatura|conta|cesan|agua)\b/', $t)) {
        return 'fatura';
    }
    return null;
}

/**
 * Álbum: as fotos chegam em updates separados e sem ordem garantida.
 *
 * Guardamos as peças e esperamos uma pausa antes de processar — o Telegram manda
 * as fotos de um álbum em poucos segundos. Quem vencer o UPDATE atômico de
 * status no registro master faz a leitura; os demais processos saem sem tocar
 * no banco ou enviar mensagens duplicadas.
 */
function processarAlbum(int $chatId, array $m, array $anexo, string $albumId, ?string $papel = null): void
{
    Banco::garantirTabelas();
    $pdo = Banco::pdo();

    $agora = time();
    $msgId = (int) ($m['message_id'] ?? 0);

    // Grava cada foto individual do álbum
    $stmt = $pdo->prepare(
        'INSERT INTO telegram_album (id, chat_id, mensagem_id, file_id, papel, status, atualizado_em)
         VALUES (:id, :chat, :msg, :file, :papel, "recebendo", NOW())
         ON DUPLICATE KEY UPDATE file_id = VALUES(file_id), papel = COALESCE(VALUES(papel), papel), atualizado_em = NOW()'
    );
    $stmt->execute([
        ':id' => $albumId . ':' . $msgId,
        ':chat' => $chatId,
        ':msg' => $msgId,
        ':file' => (string) $anexo['file_id'],
        ':papel' => $papel,
    ]);

    // Registro coordenador do álbum: armazena timestamp PHP (int) no campo mensagem_id
    $stmtMaster = $pdo->prepare(
        'INSERT INTO telegram_album (id, chat_id, mensagem_id, file_id, papel, status, atualizado_em)
         VALUES (:id, :chat, :ts, "", NULL, "recebendo", NOW())
         ON DUPLICATE KEY UPDATE mensagem_id = VALUES(mensagem_id), status = IF(status = "processando", status, "recebendo"), atualizado_em = NOW()'
    );
    $stmtMaster->execute([
        ':id' => 'master:' . $albumId,
        ':chat' => $chatId,
        ':ts' => $agora,
    ]);

    $inicio = microtime(true);
    while (true) {
        usleep(ALBUM_PASSO_MS * 1000);

        // Verifica estado do coordenador
        $sel = $pdo->prepare('SELECT mensagem_id, status FROM telegram_album WHERE id = :id');
        $sel->execute([':id' => 'master:' . $albumId]);
        $master = $sel->fetch();

        // Se outro processo já assumiu o álbum, ou se foi cancelado, sai silenciosamente
        if (!$master || $master['status'] !== 'recebendo') {
            return;
        }

        $ultimoTs = (int) $master['mensagem_id'];
        $silencio = (time() - $ultimoTs) * 1000 >= ALBUM_SILENCIO_MS;
        $estourou = (microtime(true) - $inicio) * 1000 >= ALBUM_LIMITE_MS;

        if ($silencio || $estourou) {
            break;
        }
    }

    // Claim atômico: exatamente UM processo consegue mudar de "recebendo" para "processando"
    $claim = $pdo->prepare('UPDATE telegram_album SET status = "processando" WHERE id = :id AND status = "recebendo"');
    $claim->execute([':id' => 'master:' . $albumId]);
    if ($claim->rowCount() === 0) {
        return; // Outro processo concorrente já assumiu
    }

    // Busca todas as fotos do álbum, excluindo o registro master
    $sel = $pdo->prepare('SELECT * FROM telegram_album WHERE id LIKE :a AND id != :m ORDER BY mensagem_id ASC, id ASC');
    $sel->execute([
        ':a' => $albumId . ':%',
        ':m' => 'master:' . $albumId,
    ]);
    $fotos = $sel->fetchAll();

    $fotosBase = [];
    foreach ($fotos as $f) {
        $baixado = Telegram::baixarArquivo((string) $f['file_id']);
        if (!$baixado['ok']) {
            continue;
        }
        $fotosBase[] = [
            'papel' => $f['papel'] ?: null,
            'data' => $baixado['data'],
            'mimeType' => $baixado['mimeType'],
            'legenda' => '',
        ];
    }

    if (!$fotosBase) {
        Telegram::enviarMensagem($chatId, 'Recebi as fotos, mas não consegui baixar nenhuma. Envie de novo, por favor.');
        return;
    }

    // Se alguma foto do álbum não tiver papel definido, classifica o álbum via IA
    $precisaClassificar = false;
    foreach ($fotosBase as $fb) {
        if (empty($fb['papel'])) {
            $precisaClassificar = true;
            break;
        }
    }

    if ($precisaClassificar) {
        Telegram::acaoChat($chatId, 'typing');
        $fotosBase = Ocr::classificarAlbum($fotosBase);
    }

    aoLerFotos($chatId, $fotosBase);
}

/** Arquivo único (foto ou imagem anexada como documento). */
function processarUmArquivo(int $chatId, array $m, array $anexo, ?string $papel): void
{
    Telegram::acaoChat($chatId, 'typing');

    // baixa uma vez só: classificação e leitura usam os mesmos bytes
    $baixado = Telegram::baixarArquivo((string) $anexo['file_id']);
    if (!$baixado['ok']) {
        Telegram::enviarMensagem($chatId, $baixado['erro'] ?? 'Não consegui baixar a imagem.');
        return;
    }

    if ($papel === null) {
        $classificado = Ocr::classificar($baixado['data'], $baixado['mimeType']);
        if ($classificado['ok']) {
            $papel = $classificado['papel'];
        } else {
            // não deu para adivinhar: perguntamos em vez de chutar um papel errado
            // e propor um lançamento torto — que depois o usuário teria de desfazer
            $intro = !empty($classificado['rotulo'])
                ? 'Parece ser ' . $classificado['rotulo'] . ', mas não tenho certeza.'
                : 'Não consegui identificar essa foto sozinho.';
            Conversa::definir($chatId, 'classificando_foto', ['file_id' => (string) $anexo['file_id']]);
            Telegram::enviarTeclado($chatId, $intro . "\n\nO que ela mostra?", tecladoDePapeis(perfilDe($chatId) === 'loja2'));
            return;
        }
    }

    aoLerFotos($chatId, [[
        'papel' => $papel,
        'data' => $baixado['data'],
        'mimeType' => $baixado['mimeType'],
        // a legenda decide o imóvel ("av brasil"); antes ela nunca chegava até lá
        'legenda' => (string) ($m['caption'] ?? ''),
    ]]);
}

/**
 * Opções oferecidas quando a classificação automática não decidiu.
 *
 * A Loja 02 só manda conta de energia e o próprio watímetro — mostrar CESAN e
 * hidrômetro para ela só abriria caminho para um toque errado.
 */
function tecladoDePapeis(bool $soEnergia = false): array
{
    if ($soEnergia) {
        return [[
            ['text' => '⚡ Fatura EDP', 'callback_data' => 'f:fatura_energia'],
            ['text' => '🔌 Meu watímetro', 'callback_data' => 'f:interno'],
        ]];
    }
    return [
        [
            ['text' => '📄 Fatura CESAN', 'callback_data' => 'f:fatura'],
            ['text' => '💧 H1', 'callback_data' => 'f:h1'],
            ['text' => '💧 H2', 'callback_data' => 'f:h2'],
            ['text' => '💧 H3', 'callback_data' => 'f:h3'],
        ],
        [
            ['text' => '⚡ Fatura EDP', 'callback_data' => 'f:fatura_energia'],
            ['text' => '🔌 Relógio/Whatímetro', 'callback_data' => 'f:interno'],
        ],
    ];
}

function aoLerFotos(int $chatId, array $fotos): void
{
    $temAgua = false;
    $temEnergia = false;
    $apenasInterno = true;
    foreach ($fotos as $f) {
        $p = $f['papel'];
        if (in_array($p, ['fatura', 'h1', 'h2', 'h3'], true)) {
            $temAgua = true;
            $apenasInterno = false;
        }
        if (in_array($p, ['fatura_energia', 'interno'], true)) {
            $temEnergia = true;
            if ($p !== 'interno') {
                $apenasInterno = false;
            }
        }
    }

    $perfil = perfilDe($chatId);
    if ($perfil === 'loja2' && $temAgua) {
        Telegram::enviarMensagem($chatId, 'Você pode enviar apenas fotos do watímetro da sua loja ou da fatura de energia da Av. Brasil.');
        return;
    }

    // um envio só pode ser de um tipo de conta — se vier misturado, o usuário
    // deve separar, senão a leitura sai errada nos dois sentidos
    if ($temAgua && $temEnergia) {
        Telegram::enviarMensagem($chatId,
            'Misturei fatura de água com foto de energia no mesmo envio.\n'
            . 'Separe por favor: mande as fotos de cada conta em envios diferentes.');
        return;
    }

    // Quando envia apenas a foto do watímetro/submedidor (sem fatura)
    if ($apenasInterno && $temEnergia) {
        $local = ($perfil === 'loja2') ? 'av_brasil' : 'rua_inhambu';
        foreach ($fotos as $f) {
            $leg = strtolower($f['legenda'] ?? '');
            if (strpos($leg, 'av') !== false || strpos($leg, 'brasil') !== false || strpos($leg, 'loja') !== false) {
                $local = 'av_brasil';
            }
        }

        $r = Ocr::ler($local, $fotos);
        if (!$r['ok']) {
            Telegram::enviarMensagem($chatId, $r['erro']);
            return;
        }

        $leituraWatimetro = (float) ($r['valores']['leituraInterno'] ?? 0);
        if ($leituraWatimetro <= 0) {
            Telegram::enviarMensagem($chatId, 'Não consegui ler o número do display do watímetro. Foto mais nítida, por favor.');
            return;
        }

        $recusa = Ocr::conferirRelogioNovo($local, $leituraWatimetro);
        if ($recusa !== null) {
            Telegram::enviarMensagem($chatId, $recusa);
            return;
        }

        if ($local === 'av_brasil') {
            Banco::salvarLeituraFoto(Ocr::RELOGIO_POR_FOTO['av_brasil']['chave'], $leituraWatimetro);
            // a Tuya mede a Casa 03; sincroniza junto para os dois relógios serem do mesmo dia
            $sync = Pendencia::executarTuya([]);
            $tuya = Banco::ultimoTuya();
            $valTuya = $tuya ? fmt_num((float) $tuya['valor_extraido'], 2) . ' kWh' : '---';

            $s = "🔌 *Watímetro Loja (Fernando) registrado:* `" . fmt_num($leituraWatimetro, 2) . " kWh`\n";
            if ($sync['ok']) {
                $s .= "📊 *Watímetro Tuya (Graça) sincronizado:* `{$valTuya}`\n\n"
                    . "Os dois relógios estão em dia. Assim que a conta da EDP da Av. Brasil for enviada, o rateio sai com eles.";
            } else {
                $s .= "📊 *Watímetro Tuya (Graça):* não sincronizou agora (último: `{$valTuya}`).\n\n"
                    . "Sua leitura ficou guardada. O administrador pode rodar /sincronizar depois.";
            }
            Telegram::enviarMensagem($chatId, $s);
            return;
        } else {
            Banco::salvarLeituraFoto(Ocr::RELOGIO_POR_FOTO['rua_inhambu']['chave'], $leituraWatimetro);
            $s = "🔌 *Submedidor Casa 2 (Mirian) registrado:* `" . fmt_num($leituraWatimetro, 2) . " kWh`\n\n"
                . "Leitura registrada! Assim que a fatura da EDP for enviada, o rateio será calculado automaticamente.";
            Telegram::enviarMensagem($chatId, $s);
            return;
        }
    }

    if ($temAgua) {
        $r = Ocr::montarAgua($fotos);
    } elseif ($temEnergia) {
        // a Loja 02 só existe na Av. Brasil: a conta dela nunca vai para a Rua Inhambu
        $local = $perfil === 'loja2' ? 'av_brasil' : 'rua_inhambu';
        foreach ($fotos as $f) {
            $leg = strtolower($f['legenda'] ?? '');
            if (strpos($leg, 'av') !== false || strpos($leg, 'brasil') !== false) {
                $local = 'av_brasil';
            }
        }
        $r = Ocr::montarEnergia($local, $fotos, null, $perfil === 'loja2');
    } else {
        Telegram::enviarMensagem($chatId,
            "Não consegui identificar o que é essa foto.\n\n"
            . "Para facilitar, mande com a legenda dizendo o que é:\n"
            . "• `fatura` — fatura da CESAN\n"
            . "• `h1`, `h2`, `h3` — hidrômetro de cada casa\n"
            . "• `energia` — fatura da EDP\n"
            . "• `interno` — relógio interno ou whatímetro");
        return;
    }

    if (!$r['ok']) {
        Telegram::enviarMensagem($chatId, $r['erro']);
        return;
    }

    $payload = $r['payload'];
    foreach ($r['avisos'] ?? [] as $aviso) {
        // avisos do OCR entram no corpo da pendência, não em mensagens soltas
        $payload['avisos_ocr'][] = $aviso;
    }

    $tipo = $temAgua ? 'agua' : 'energia';
    $id = Pendencia::criar($chatId, $tipo, $payload);
    $p = Banco::pendencia($id);
    $nomes = Banco::nomesPorPerfil();

    if ($perfil === 'loja2') {
        // Fernando recebe de volta apenas o consumo e preço dele
        $rPreview = Pendencia::rateioDaPendencia($p);
        // o rateio calculado não carrega a data; sem ela o resumo saía "Resumo de Rateio - "
        $msg = resp_rateio_fernando($rPreview + ['data' => $payload['data'] ?? null], $nomes);
        if (empty($payload['interno2'])) {
            $msg .= "\n\n⚠️ Sem uma foto nova do seu watímetro, o valor da loja saiu pela diferença do total. "
                . "Mande a foto do display para o cálculo sair medido.";
        }
        $msg .= "\n\nO administrador ainda vai conferir antes de gravar.";
        Telegram::enviarMensagem($chatId, $msg);

        // Notifica administradores para conferir e confirmar
        $admins = Banco::chatsPorPerfil('admin');
        foreach ($admins as $adm) {
            $admChatId = (int) $adm['chat_id'];
            if ($admChatId !== $chatId) {
                Telegram::enviarTeclado($admChatId, "🔔 *Fernando enviou a fatura da Av. Brasil:*\n\n" . Pendencia::texto($p, $nomes), Pendencia::teclado($id));
            }
        }
        return;
    }

    Telegram::enviarTeclado($chatId, Pendencia::texto($p, $nomes), Pendencia::teclado($id));
}

// ============================================================ botões

function aoClicarBotao(array $cb): void
{
    $chatId = (int) ($cb['message']['chat']['id'] ?? 0);
    $userId = isset($cb['from']['id']) ? (int) $cb['from']['id'] : null;
    $dados = (string) ($cb['data'] ?? '');

    if ($chatId === 0) {
        Telegram::responderCallback((string) $cb['id']);
        return;
    }
    if (!autorizado($chatId, $userId)) {
        Telegram::responderCallback((string) $cb['id'], 'Aparelho não liberado.');
        return;
    }

    $partes = explode(':', $dados);
    $chave = $partes[0] ?? '';

    if ($chave === 'f') {
        // usuário dizendo o que a foto é, depois da classificação ter falhado
        aoFoto($chatId, $partes);
        Telegram::responderCallback((string) $cb['id']);
        return;
    }

    if ($chave !== 'p' || count($partes) < 3) {
        Telegram::responderCallback((string) $cb['id'], 'Botão expirado.');
        return;
    }

    $id = $partes[1];
    $acao = $partes[2];
    $p = Banco::pendencia($id);

    if ($p === null) {
        Telegram::responderCallback((string) $cb['id'], 'Essa proposta já não existe mais.');
        return;
    }
    if ((string) $p['chat_id'] !== (string) $chatId && !ehAdmin($chatId)) {
        Telegram::responderCallback((string) $cb['id'], 'Esta proposta é de outra conversa.');
        return;
    }

    if ($acao === 'nao') {
        Banco::descartarPendencia($id);
        Telegram::responderCallback((string) $cb['id'], 'Descartado. Nada foi gravado.');
        if (!empty($cb['message']['message_id'])) {
            Telegram::editarMensagem($chatId, (int) $cb['message']['message_id'],
                "❌ *Descartado*\n\n_Nenhuma leitura foi gravada._");
        }
        return;
    }

    if ($acao !== 'ok') {
        Telegram::responderCallback((string) $cb['id'], 'Botão expirado.');
        return;
    }

    // ---- claim atômico: o toque duplo não grava em dobro ----
    $reivindicada = Banco::reivindicarPendencia($id, $userId);
    if ($reivindicada === null) {
        Telegram::responderCallback((string) $cb['id'], 'Essa leitura já foi decidida.');
        return;
    }

    $resultado = Pendencia::executar($reivindicada);
    if (!$resultado['ok']) {
        Banco::reabrirPendencia($id);
        Telegram::responderCallback((string) $cb['id'], 'Não consegui gravar.');
        if (!empty($cb['message']['message_id'])) {
            Telegram::editarMensagem($chatId, (int) $cb['message']['message_id'],
                "⚠️ *Falha ao gravar*\n\n" . $resultado['erro']
                . "\n\nA leitura continua pendente — toque em Confirmar de novo quando quiser.");
        }
        return;
    }

    Telegram::responderCallback((string) $cb['id'], 'Gravado!');

    $p = Banco::pendencia($id);
    $d = $p['payload'];
    $r = Pendencia::rateioDaPendencia($p);
    $nomes = Banco::nomesPorPerfil();

    $ok = "✅ *Leitura gravada*\n\n";
    if ($p['tipo'] === 'agua') {
        $ok .= resp_agua([
            'data_leitura' => $d['data'],
            'consumo_cesan' => $r['consumo_cesan'],
            'consumo_h1' => $r['consumo_h1'],
            'consumo_h2' => $r['consumo_h2'],
            'consumo_h3' => $r['consumo_h3'],
            'consumo_servico' => $r['consumo_servico'],
            'valor_por_m3' => $r['valor_por_m3'],
            'valor_h1' => $r['valor_h1'],
            'valor_h2' => $r['valor_h2'],
            'valor_h3' => $r['valor_h3'],
            'valor_servico' => $r['valor_servico'],
            'valor_fatura_total' => $r['valor_fatura_total'],
        ], $nomes, null);
    } elseif ($p['tipo'] === 'energia') {
        $row = Banco::ultimoRateioEnergia($d['local'] ?? 'rua_inhambu');
        $ok .= $row ? resp_energia($row, $nomes, null) : 'Leitura gravada.';
    } else {
        $ok .= 'O whatímetro foi sincronizado.';
    }

    if (!empty($cb['message']['message_id'])) {
        Telegram::editarMensagem($chatId, (int) $cb['message']['message_id'], $ok);
    } else {
        Telegram::enviarMensagem($chatId, $ok);
    }

    $separadas = [];
    if ($p['tipo'] === 'agua') {
        $dadosRateio = [
            'data_leitura' => $d['data'],
            'consumo_cesan' => $r['consumo_cesan'],
            'consumo_h1' => $r['consumo_h1'],
            'consumo_h2' => $r['consumo_h2'],
            'consumo_h3' => $r['consumo_h3'],
            'consumo_servico' => $r['consumo_servico'],
            'valor_por_m3' => $r['valor_por_m3'],
            'valor_h1' => $r['valor_h1'],
            'valor_h2' => $r['valor_h2'],
            'valor_h3' => $r['valor_h3'],
            'valor_servico' => $r['valor_servico'],
            'valor_fatura_total' => $r['valor_fatura_total'],
        ];
        $separadas = resp_mensagens_separadas_agua($dadosRateio, $nomes);
    } elseif ($p['tipo'] === 'energia') {
        $local = $d['local'] ?? 'rua_inhambu';
        $row = Banco::ultimoRateioEnergia($local);
        if ($row) {
            if ($local === 'av_brasil') {
                // Notifica Fernando (loja2) na mesma hora com o resumo dele
                $msgFernando = resp_rateio_fernando($row, $nomes);
                $chatsLoja2 = Banco::chatsPorPerfil('loja2');
                foreach ($chatsLoja2 as $cL2) {
                    $cid = (int) $cL2['chat_id'];
                    Telegram::enviarMensagem($cid, $msgFernando);
                }
            }
            $separadas = resp_mensagens_separadas_energia($row, $nomes);
        }
    }

    // Coleta todos os administradores cadastrados (ex: wellington, maria)
    $admins = Banco::chatsPorPerfil('admin');
    $adminChatIds = array_unique(array_filter(array_map(fn($a) => (int) ($a['chat_id'] ?? 0), $admins)));
    if (!in_array($chatId, $adminChatIds, true)) {
        $adminChatIds[] = $chatId;
    }

    // Notifica os outros administradores com o resumo geral
    foreach ($adminChatIds as $admChatId) {
        if ($admChatId !== $chatId) {
            Telegram::enviarMensagem($admChatId, "📢 *Leitura confirmada no condomínio:*\n\n" . $ok);
        }
    }

    // Dispara para TODOS os administradores as mensagens individuais prontas para copiar
    foreach ($adminChatIds as $admChatId) {
        foreach ($separadas as $msgSeparada) {
            Telegram::enviarMensagem($admChatId, $msgSeparada);
        }
    }
}

/** Responde ao botão "isto é o que" quando a IA não soube classificar. */
function aoFoto(int $chatId, array $partes): void
{
    $papel = $partes[1] ?? '';
    $permitidos = ['fatura', 'h1', 'h2', 'h3', 'fatura_energia', 'interno'];
    if (!in_array($papel, $permitidos, true)) {
        Telegram::enviarMensagem($chatId, 'Opção desconhecida. Envie a foto de novo, por favor.');
        return;
    }

    $estado = Conversa::ler($chatId);
    $fileId = (string) ($estado['payload']['file_id'] ?? '');
    if ($estado['estado'] !== 'classificando_foto' || $fileId === '') {
        Telegram::enviarMensagem($chatId, 'Esqueci qual foto era essa. Envie de novo, por favor.');
        return;
    }
    Conversa::limpar($chatId);

    Telegram::acaoChat($chatId, 'typing');
    $baixado = Telegram::baixarArquivo($fileId);
    if (!$baixado['ok']) {
        Telegram::enviarMensagem($chatId, $baixado['erro'] ?? 'Não consegui baixar a imagem.');
        return;
    }
    aoLerFotos($chatId, [['papel' => $papel, 'data' => $baixado['data'], 'mimeType' => $baixado['mimeType']]]);
}
