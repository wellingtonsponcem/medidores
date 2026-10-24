<?php
/**
 * Camada de banco do bot.
 *
 * Toda consulta e toda gravação passam por aqui. Isso deixa o handler fino e,
 * principalmente, permite trocar o PDO inteiro nos testes (Banco::$pdo) sem
 * depender de um MySQL rodando — mesmo truque do Gampo com PGlite.
 *
 * As tabelas do bot são criadas sob demanda por garantirTabelas(), que é
 * idempotente. A hospedagem compartilhada não dá acesso ao shell nem ao
 * phpMyAdmin garantido, então migração manual não é um caminho confiável.
 */
class Banco
{
    /** @var PDO|null injetado pelos testes */
    public static $pdo = null;
    /** @var bool memoização das tabelas — pública para os testes conseguirem zerar */
    public static bool $tabelasOk = false;

    /**
     * Devolve a conexão.
     *
     * Sem tipo de retorno declarado de propósito: os testes injetam um duplo que
     * entende o subconjunto de SQL que esta classe emite. Se algum desses SQL
     * mudar, o duplo falha alto e o teste quebra — em vez de passar em silêncio
     * com um banco que não existe.
     *
     * @return PDO|object
     */
    public static function pdo()
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return self::$pdo;
    }

    /**
     * Cria as tabelas do bot se não existirem. Uma consulta por requisição —
     * depois do primeiro SELECT o resultado é memoizado na própria instância.
     */
    public static function garantirTabelas(): void
    {
        if (self::$tabelasOk) {
            return;
        }
        $pdo = self::pdo();
        $ddl = [
            // dedupe: o Telegram reenvia o update se a resposta demorar ou falhar
            'CREATE TABLE IF NOT EXISTS telegram_updates (
                update_id BIGINT PRIMARY KEY,
                recebido_em DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            // quem pode falar com o bot e qual perfil do app ele representa
            'CREATE TABLE IF NOT EXISTS telegram_chats (
                chat_id BIGINT PRIMARY KEY,
                user_id BIGINT NULL,
                nome VARCHAR(120) NULL,
                perfil VARCHAR(20) NULL,
                vinculado_em DATETIME NULL,
                ultimo_uso DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            // nada é gravado no app sem o usuário confirmar pelos botões
            'CREATE TABLE IF NOT EXISTS pendencias (
                id VARCHAR(50) PRIMARY KEY,
                chat_id BIGINT NOT NULL,
                tipo VARCHAR(30) NOT NULL,
                payload TEXT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT "pendente",
                criada_em DATETIME NOT NULL,
                decidida_em DATETIME NULL,
                decidida_por BIGINT NULL,
                INDEX idx_pend_chat (chat_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            // estado curto: o que o bot está esperando este chat responder
            'CREATE TABLE IF NOT EXISTS telegram_aguardando (
                chat_id BIGINT PRIMARY KEY,
                estado VARCHAR(40) NOT NULL,
                payload TEXT NULL,
                atualizado_em DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            // fotos de um álbum chegam em updates separados e precisam ser reunidas
            'CREATE TABLE IF NOT EXISTS telegram_album (
                id VARCHAR(80) PRIMARY KEY,
                chat_id BIGINT NOT NULL,
                mensagem_id BIGINT NOT NULL,
                file_id VARCHAR(200) NOT NULL,
                papel VARCHAR(30) NULL,
                status VARCHAR(20) NOT NULL DEFAULT "recebendo",
                atualizado_em DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ];
        foreach ($ddl as $sql) {
            $pdo->exec($sql);
        }
        self::$tabelasOk = true;
    }

    // ---------------------------------------------------------------- updates

    /**
     * Registra o update como já processado.
     *
     * @return bool true quando é a primeira vez (processa), false quando é repete
     */
    public static function marcarUpdate(int $updateId): bool
    {
        self::garantirTabelas();
        $stmt = self::pdo()->prepare('INSERT IGNORE INTO telegram_updates (update_id, recebido_em) VALUES (:id, NOW())');
        $stmt->execute([':id' => $updateId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Libera o update para o Telegram reenviar.
     *
     * Chamado quando o handler falhou por motivo transitório (timeout de banco,
     * 502 do Gemini). Sem isso o update ficaria marcado como respondido e a
     * mensagem do usuário se perderia.
     */
    public static function liberarUpdate(int $updateId): void
    {
        try {
            $stmt = self::pdo()->prepare('DELETE FROM telegram_updates WHERE update_id = :id');
            $stmt->execute([':id' => $updateId]);
        } catch (Exception $e) {
            // limpeza best-effort: não pode mascarar o erro original
        }
    }

    /** Limpa updates antigos. Roda 2% das vezes para não custar tempo em toda requisição. */
    public static function limparUpdatesAntigos(): void
    {
        try {
            self::pdo()->exec("DELETE FROM telegram_updates WHERE recebido_em < DATE_SUB(NOW(), INTERVAL 7 DAY)");
        } catch (Exception $e) {
        }
    }

    // ------------------------------------------------------------------ chats

    /** @return array|null */
    public static function chat(int $chatId): ?array
    {
        self::garantirTabelas();
        $stmt = self::pdo()->prepare('SELECT * FROM telegram_chats WHERE chat_id = :c LIMIT 1');
        $stmt->execute([':c' => $chatId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Retorna todos os chats vinculados a um determinado perfil (ex: admin, loja2). */
    public static function chatsPorPerfil(string $perfil): array
    {
        self::garantirTabelas();
        $stmt = self::pdo()->prepare('SELECT * FROM telegram_chats WHERE perfil = :p');
        $stmt->execute([':p' => $perfil]);
        return $stmt->fetchAll() ?: [];
    }

    /** Vincula o chat a um perfil do app (admin, casa1, casa2, casa3, loja2). */
    public static function vincularChat(int $chatId, ?int $userId, ?string $nome, string $perfil): void
    {
        self::garantirTabelas();
        $sql = 'INSERT INTO telegram_chats (chat_id, user_id, nome, perfil, vinculado_em, ultimo_uso)
                VALUES (:c, :u, :n, :p, NOW(), NOW())
                ON DUPLICATE KEY UPDATE user_id = COALESCE(VALUES(user_id), user_id),
                    nome = COALESCE(VALUES(nome), nome),
                    perfil = VALUES(perfil),
                    vinculado_em = NOW(), ultimo_uso = NOW()';
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute([':c' => $chatId, ':u' => $userId, ':n' => $nome, ':p' => $perfil]);
    }

    public static function marcarUso(int $chatId): void
    {
        try {
            $stmt = self::pdo()->prepare('UPDATE telegram_chats SET ultimo_uso = NOW() WHERE chat_id = :c');
            $stmt->execute([':c' => $chatId]);
        } catch (Exception $e) {
        }
    }

    public static function desvincularChat(int $chatId): void
    {
        self::garantirTabelas();
        $stmt = self::pdo()->prepare('UPDATE telegram_chats SET perfil = NULL, vinculado_em = NULL WHERE chat_id = :c');
        $stmt->execute([':c' => $chatId]);
    }

    /** Allowlist vinda da variável de ambiente (separada por vírgula ou espaço). */
    public static function idsPermitidos(): array
    {
        $bruto = env('TELEGRAM_ALLOWED_USER_IDS');
        if ($bruto === null) {
            return [];
        }
        $ids = [];
        foreach (preg_split('/[\s,;]+/', $bruto) as $pedaco) {
            if ($pedaco !== '' && ctype_digit($pedaco)) {
                $ids[] = (int) $pedaco;
            }
        }
        return $ids;
    }

    // -------------------------------------------------------------- pendências

    /** @return array|null */
    public static function pendencia(string $id): ?array
    {
        self::garantirTabelas();
        $stmt = self::pdo()->prepare('SELECT * FROM pendencias WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $row['payload'] = json_decode($row['payload'], true) ?: [];
        return $row;
    }

    public static function criarPendencia(string $id, int $chatId, string $tipo, array $payload): void
    {
        self::garantirTabelas();
        $stmt = self::pdo()->prepare(
            'INSERT INTO pendencias (id, chat_id, tipo, payload, status, criada_em)
             VALUES (:id, :chat, :tipo, :payload, "pendente", NOW())'
        );
        $stmt->execute([
            ':id' => $id,
            ':chat' => $chatId,
            ':tipo' => $tipo,
            ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
    }

    public static function pendenciasDoChat(int $chatId, int $limite = 10): array
    {
        self::garantirTabelas();
        $stmt = self::pdo()->prepare('SELECT * FROM pendencias WHERE chat_id = :c AND status = "pendente" ORDER BY criada_em DESC LIMIT ' . (int) $limite);
        $stmt->execute([':c' => $chatId]);
        $linhas = $stmt->fetchAll();
        foreach ($linhas as &$l) {
            $l['payload'] = json_decode($l['payload'], true) ?: [];
        }
        return $linhas;
    }

    /**
     * Reivindica a pendência de forma atômica.
     *
     * O UPDATE com cláusula de status é o que impede o toque duplo de gravar em
     * dobro: o primeiro clique muda para "confirmada", o segundo não encontra
     * nenhuma linha e devolve false. Só o vencedor grava no app.
     *
     * @return array|null a pendência reivindicada, ou null se já foi decidida
     */
    public static function reivindicarPendencia(string $id, ?int $decisorPor = null): ?array
    {
        self::garantirTabelas();
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'UPDATE pendencias SET status = "confirmada", decidida_em = NOW(), decidida_por = :u
                 WHERE id = :id AND status = "pendente"'
            );
            $stmt->execute([':id' => $id, ':u' => $decisorPor]);
            $ganhou = $stmt->rowCount() > 0;
            if (!$ganhou) {
                $pdo->rollBack();
                return null;
            }
            $sel = $pdo->prepare('SELECT * FROM pendencias WHERE id = :id LIMIT 1');
            $sel->execute([':id' => $id]);
            $row = $sel->fetch();
            $pdo->commit();
            $row['payload'] = json_decode($row['payload'], true) ?: [];
            return $row;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function descartarPendencia(string $id): void
    {
        self::garantirTabelas();
        $stmt = self::pdo()->prepare('UPDATE pendencias SET status = "descartada", decidida_em = NOW() WHERE id = :id AND status = "pendente"');
        $stmt->execute([':id' => $id]);
    }

    /** Devolve uma pendência para "pendente" quando a gravação falhou. */
    public static function reabrirPendencia(string $id): void
    {
        try {
            $stmt = self::pdo()->prepare('UPDATE pendencias SET status = "pendente", decidida_em = NULL, decidida_por = NULL WHERE id = :id');
            $stmt->execute([':id' => $id]);
        } catch (Exception $e) {
        }
    }

    // ----------------------------------------------------------- lançamentos

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /**
     * Grava a leitura de água. Payload com o MESMO formato que o painel envia,
     * para a view vw_agua_rateio calcular igual em qualquer origem.
     */
    public static function inserirAgua(array $p): string
    {
        $id = self::uuid();
        $stmt = self::pdo()->prepare(
            'INSERT INTO consumo_agua (id, data_leitura, leitura_cesan, leitura_h1, leitura_h2, leitura_h3, valor_fatura_total)
             VALUES (:id, :data, :cesan, :h1, :h2, :h3, :fatura)'
        );
        $stmt->execute([
            ':id' => $id,
            ':data' => $p['data'],
            ':cesan' => $p['cesan'],
            ':h1' => $p['h1'],
            ':h2' => $p['h2'],
            ':h3' => $p['h3'],
            ':fatura' => $p['fatura'],
        ]);
        return $id;
    }

    public static function inserirEnergia(array $p): string
    {
        $id = self::uuid();
        $stmt = self::pdo()->prepare(
            'INSERT INTO consumo_energia (id, data_leitura, leitura_padrao, leitura_interno, leitura_interno_2,
                 valor_fatura_total, local_medidor, leitura_interno_anterior_ref, leitura_interno_2_anterior_ref)
             VALUES (:id, :data, :padrao, :interno, :interno2, :fatura, :local, :ref1, :ref2)'
        );
        $stmt->execute([
            ':id' => $id,
            ':data' => $p['data'],
            ':padrao' => $p['padrao'],
            ':interno' => $p['interno'],
            ':interno2' => $p['interno2'],
            ':fatura' => $p['fatura'],
            ':local' => $p['local'],
            ':ref1' => $p['ref1'],
            ':ref2' => $p['ref2'],
        ]);
        return $id;
    }

    /** Última leitura bruta de água — a referência para validar continuidade do OCR. */
    public static function ultimaAguaBruta(): ?array
    {
        $stmt = self::pdo()->query('SELECT * FROM consumo_agua ORDER BY data_leitura DESC LIMIT 1');
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function ultimaEnergiaBruta(string $local): ?array
    {
        $stmt = self::pdo()->prepare('SELECT * FROM consumo_energia WHERE local_medidor = :l ORDER BY data_leitura DESC LIMIT 1');
        $stmt->execute([':l' => $local]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Último rateio de água já calculado pela view SQL. */
    public static function ultimoRateioAgua(): ?array
    {
        $stmt = self::pdo()->query('SELECT * FROM vw_agua_rateio ORDER BY data_leitura DESC LIMIT 1');
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function ultimoRateioEnergia(string $local): ?array
    {
        $stmt = self::pdo()->prepare('SELECT * FROM vw_energia_rateio WHERE local_medidor = :l ORDER BY data_leitura DESC LIMIT 1');
        $stmt->execute([':l' => $local]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function usuarios(): array
    {
        return self::pdo()->query('SELECT * FROM usuarios ORDER BY perfil, nome')->fetchAll();
    }

    /** @return array|null */
    public static function usuarioPorNomeESenha(string $nome, string $senha): ?array
    {
        // mesma conferência do painel: aceita hash e migra senha em texto puro
        return Acesso::autenticar(self::pdo(), $nome, $senha);
    }

    /** Mapeia perfil -> nome do morador, para as mensagens saírem com nome de gente. */
    public static function nomesPorPerfil(): array
    {
        $mapa = [];
        foreach (self::usuarios() as $u) {
            if ((int) ($u['ativo'] ?? 1) === 0) {
                continue;
            }
            $mapa[$u['perfil']] = ucfirst((string) $u['nome']);
        }
        return $mapa;
    }

    /** Busca snapshot do Tuya por data da fatura ou mais recente. */
    public static function ultimoTuya(?string $data = null): ?array
    {
        self::garantirTabelas();
        if ($data) {
            $stmt = self::pdo()->prepare('SELECT valor_extraido, data_leitura, medidor_id FROM leitura_energia WHERE DATE(data_leitura) = :d ORDER BY data_leitura DESC LIMIT 1');
            $stmt->execute([':d' => $data]);
            $row = $stmt->fetch();
            if ($row) {
                return $row;
            }
            $stmt = self::pdo()->prepare('SELECT valor_extraido, data_leitura, medidor_id FROM leitura_energia WHERE DATE(data_leitura) <= :d ORDER BY data_leitura DESC LIMIT 1');
            $stmt->execute([':d' => $data]);
            $row = $stmt->fetch();
            if ($row) {
                return $row;
            }
        }
        $stmt = self::pdo()->query('SELECT valor_extraido, data_leitura, medidor_id FROM leitura_energia ORDER BY data_leitura DESC LIMIT 1');
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function salvarSegredo(string $nome, string $valor): void
    {
        self::garantirTabelas();
        $stmt = self::pdo()->prepare(
            'INSERT INTO app_secrets (name, value, created_at)
             VALUES (:n, :v, NOW())
             ON DUPLICATE KEY UPDATE value = VALUES(value), created_at = NOW()'
        );
        $stmt->execute([':n' => $nome, ':v' => $valor]);
    }

    public static function obterSegredo(string $nome): ?string
    {
        self::garantirTabelas();
        try {
            $stmt = self::pdo()->prepare('SELECT value FROM app_secrets WHERE name = :n LIMIT 1');
            $stmt->execute([':n' => $nome]);
            $row = $stmt->fetch();
            return $row ? (string) $row['value'] : null;
        } catch (Exception $e) {
            return null;
        }
    }

    /** Busca chave ativa do Gemini no MySQL (app_secrets) ou cai no ambiente. */
    public static function chaveGemini(): ?string
    {
        $dbKey = self::obterSegredo('GEMINI_API_KEY');
        if ($dbKey !== null && $dbKey !== '') {
            return $dbKey;
        }
        return env('GEMINI_API_KEY');
    }
}
