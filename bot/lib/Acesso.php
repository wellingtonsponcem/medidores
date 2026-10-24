<?php
/**
 * Regras de acesso do painel: senha, sessão e o que cada perfil pode tocar.
 *
 * Por que existe: até aqui o login era conferido no navegador e a api.php
 * atendia qualquer um — bastava um GET em ?table=usuarios para levar as senhas
 * de todos, ou em ?table=app_secrets para levar a chave do Gemini. A conferência
 * agora mora no servidor, e o navegador só carrega um token opaco.
 *
 * Mora em bot/lib porque o bot também confere senha (/vincular) e o deploy já
 * sobe a pasta inteira — uma regra, um lugar.
 */
class Acesso
{
    /** Validade de uma sessão do painel. */
    const SESSAO_DIAS = 30;

    /** Leitura liberada para qualquer morador logado — é o que o dashboard mostra. */
    const TABELAS_MORADOR = ['vw_agua_rateio', 'vw_energia_rateio'];

    /**
     * O que o administrador alcança pela api genérica.
     *
     * app_secrets fica DE FORA de propósito: a chave do Gemini só entra por
     * set_gemini_key e nunca sai. Views só leem — o MySQL recusaria a escrita de
     * qualquer jeito, mas é melhor recusar antes com uma mensagem clara.
     */
    const TABELAS_ADMIN = [
        'usuarios' => ['GET', 'POST', 'PUT', 'DELETE'],
        'consumo_agua' => ['GET', 'POST', 'PUT', 'DELETE'],
        'consumo_energia' => ['GET', 'POST', 'PUT', 'DELETE'],
        'leitura_energia' => ['GET', 'POST', 'PUT', 'DELETE'],
        'vw_agua_rateio' => ['GET'],
        'vw_energia_rateio' => ['GET'],
        'vw_energia_rateio_admin' => ['GET'],
    ];

    // -------------------------------------------------------------- senha

    /**
     * Confere a senha digitada contra a guardada.
     *
     * As senhas antigas estão em texto puro no banco. Em vez de exigir uma
     * migração manual (a hospedagem não dá shell), aceitamos as duas formas e
     * quem chama regrava como hash no primeiro login certo — ver precisaRehash().
     */
    public static function senhaConfere(string $digitada, string $guardada): bool
    {
        if ($guardada === '') {
            return false;
        }
        if (self::ehHash($guardada)) {
            return password_verify($digitada, $guardada);
        }
        return hash_equals($guardada, $digitada);
    }

    /** Verdadeiro quando a senha guardada ainda é texto puro ou um hash antigo. */
    public static function precisaRehash(string $guardada): bool
    {
        return !self::ehHash($guardada) || password_needs_rehash($guardada, PASSWORD_DEFAULT);
    }

    public static function gerarHash(string $senha): string
    {
        return password_hash($senha, PASSWORD_DEFAULT);
    }

    private static function ehHash(string $valor): bool
    {
        $info = password_get_info($valor);
        return !empty($info['algo']);
    }

    // -------------------------------------------------------------- permissões

    /**
     * Nome de tabela ou coluna seguro para ir entre crases.
     *
     * Os VALORES vão por bind, mas os NOMES de coluna vêm do cliente e entram
     * direto no SQL. Uma crase dentro do nome fecha o identificador e abre
     * espaço para injeção — então só letra, número e sublinhado.
     */
    public static function identificadorValido($nome): bool
    {
        return is_string($nome) && preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $nome) === 1;
    }

    public static function podeAcessar(?string $perfil, string $tabela, string $metodo): bool
    {
        if ($perfil === null || $perfil === '') {
            return false;
        }
        if ($perfil === 'admin') {
            return in_array($metodo, self::TABELAS_ADMIN[$tabela] ?? [], true);
        }
        return $metodo === 'GET' && in_array($tabela, self::TABELAS_MORADOR, true);
    }

    /** Linha de usuário sem a senha — nem o hash sai do servidor. */
    public static function usuarioPublico(array $u): array
    {
        unset($u['senha']);
        if (array_key_exists('senha_alterada', $u)) {
            $u['senha_alterada'] = (int) $u['senha_alterada'] === 1;
        }
        if (array_key_exists('ativo', $u)) {
            $u['ativo'] = (int) $u['ativo'] === 1;
        }
        return $u;
    }

    // -------------------------------------------------------------- usuário

    /**
     * Busca o usuário ativo com este nome e esta senha, ou null.
     *
     * Regrava a senha como hash quando ela ainda está em texto puro: é assim que
     * o banco migra sozinho, um login de cada vez.
     *
     * @param PDO|object $pdo
     */
    public static function autenticar($pdo, string $nome, string $senha): ?array
    {
        if ($nome === '' || $senha === '') {
            return null;
        }
        $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE nome = :n AND ativo = 1');
        $stmt->execute([':n' => $nome]);
        foreach ($stmt->fetchAll() ?: [] as $u) {
            $guardada = (string) ($u['senha'] ?? '');
            if (!self::senhaConfere($senha, $guardada)) {
                continue;
            }
            if (self::precisaRehash($guardada)) {
                $up = $pdo->prepare('UPDATE usuarios SET senha = :s WHERE id = :id');
                $up->execute([':s' => self::gerarHash($senha), ':id' => $u['id']]);
            }
            return $u;
        }
        return null;
    }

    // -------------------------------------------------------------- sessão

    /** @param PDO|object $pdo */
    public static function garantirTabelaSessoes($pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS api_sessoes (
            token_hash CHAR(64) PRIMARY KEY,
            usuario_id VARCHAR(36) NOT NULL,
            criado_em DATETIME NOT NULL,
            expira_em DATETIME NOT NULL,
            INDEX (usuario_id),
            INDEX (expira_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    }

    /**
     * Abre uma sessão e devolve o token em claro — a única vez que ele existe
     * fora do navegador. No banco fica só o SHA-256: um dump da tabela não
     * serve para entrar no painel.
     *
     * @param PDO|object $pdo
     */
    public static function criarSessao($pdo, string $usuarioId): string
    {
        self::garantirTabelaSessoes($pdo);
        $token = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare('INSERT INTO api_sessoes (token_hash, usuario_id, criado_em, expira_em)
            VALUES (:h, :u, NOW(), DATE_ADD(NOW(), INTERVAL ' . (int) self::SESSAO_DIAS . ' DAY))');
        $stmt->execute([':h' => hash('sha256', $token), ':u' => $usuarioId]);
        // faxina oportunista: sem cron confiável, quem limpa é o próprio login
        $pdo->exec('DELETE FROM api_sessoes WHERE expira_em < NOW()');
        return $token;
    }

    /**
     * Usuário dono do token, ou null.
     *
     * Junta com usuarios a cada requisição de propósito: desativar um morador
     * ou trocar o perfil dele vale na hora, sem esperar o token expirar.
     *
     * @param PDO|object $pdo
     */
    public static function usuarioDaSessao($pdo, string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        try {
            $stmt = $pdo->prepare('SELECT u.* FROM api_sessoes s
                JOIN usuarios u ON u.id = s.usuario_id
                WHERE s.token_hash = :h AND s.expira_em > NOW() AND u.ativo = 1 LIMIT 1');
            $stmt->execute([':h' => hash('sha256', $token)]);
        } catch (Exception $e) {
            // tabela ainda não criada = ninguém logou ainda = ninguém autenticado
            return null;
        }
        $u = $stmt->fetch();
        return $u ?: null;
    }

    /** @param PDO|object $pdo */
    public static function encerrarSessao($pdo, string $token): void
    {
        try {
            $stmt = $pdo->prepare('DELETE FROM api_sessoes WHERE token_hash = :h');
            $stmt->execute([':h' => hash('sha256', $token)]);
        } catch (Exception $e) {
            // sair é best-effort: o navegador apaga o token de qualquer jeito
        }
    }

    /** Derruba todas as sessões de um usuário — usado quando a senha muda. */
    public static function encerrarSessoesDoUsuario($pdo, string $usuarioId, ?string $exceto = null): void
    {
        try {
            $sql = 'DELETE FROM api_sessoes WHERE usuario_id = :u';
            $params = [':u' => $usuarioId];
            if ($exceto !== null) {
                $sql .= ' AND token_hash <> :h';
                $params[':h'] = hash('sha256', $exceto);
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        } catch (Exception $e) {
            // sem a tabela não há sessão para derrubar
        }
    }

    /** Token enviado pelo navegador no cabeçalho X-Auth-Token. */
    public static function tokenDaRequisicao(): string
    {
        return trim((string) ($_SERVER['HTTP_X_AUTH_TOKEN'] ?? ''));
    }
}
