<?php
/**
 * Duplo de banco para os testes.
 *
 * Por que não um SQLite ou MySQL de verdade: o PHP desta máquina não tem
 * driver de banco embutido algum, e baixar um só para testar regras de rateio
 * seria pedir para o teste quebrar em outra máquina. Então interpretamos, de
 * forma propositalmente estreita, o SQL exato que Banco.php emite.
 *
 * A estreiteza é a garantia: qualquer SQL que mude sem o duplo acompanhar
 * lança "SQL não coberta pelo duplo de teste" e o teste falha em vez de passar
 * em silêncio. Isso é melhor que um mock que aceita qualquer coisa.
 */
class PdoFalso
{
    /** @var array<string, array<int, array>> */
    public array $tabelas = [];
    /** log de tudo que passou por aqui, para os testes conferirem */
    public array $log = [];
    public int $falhasForçadas = 0;
    private bool $emTransacao = false;
    private $snapshot = null;

    public function __construct()
    {
        $this->tabelas = [
            'telegram_updates' => [],
            'telegram_chats' => [],
            'pendencias' => [],
            'telegram_aguardando' => [],
            'telegram_album' => [],
            'consumo_agua' => [],
            'consumo_energia' => [],
            'usuarios' => [],
            'app_secrets' => [],
            'leitura_energia' => [],
            'vw_agua_rateio' => [],
            'vw_energia_rateio' => [],
        ];
    }

    public function prepare(string $sql): PdoFalsoStatement
    {
        return new PdoFalsoStatement($this, $sql);
    }

    /** Igual ao PDO: executa e devolve a instrução, para o chamador chamar fetch(). */
    public function query(string $sql): PdoFalsoStatement
    {
        $stmt = $this->prepare($sql);
        $stmt->execute([]);
        return $stmt;
    }

    public function exec(string $sql): int
    {
        if (stripos($sql, 'CREATE TABLE') !== false || stripos($sql, 'CREATE INDEX') !== false) {
            return 0;
        }
        if (stripos($sql, 'DELETE FROM telegram_updates WHERE recebido_em') !== false) {
            return 0;
        }
        $this->falha('exec: ' . $sql);
        return 0;
    }

    public function beginTransaction(): void
    {
        $this->emTransacao = true;
        $this->snapshot = $this->tabelas;
    }

    public function commit(): void
    {
        $this->emTransacao = false;
        $this->snapshot = null;
    }

    public function rollBack(): void
    {
        if ($this->snapshot !== null) {
            $this->tabelas = $this->snapshot;
        }
        $this->emTransacao = false;
        $this->snapshot = null;
    }

    public function inTransaction(): bool
    {
        return $this->emTransacao;
    }

    public function falha(string $sql): void
    {
        throw new RuntimeException("SQL não coberta pelo duplo de teste: {$sql}");
    }

    /** Insere uma linha direto, para montar o cenário do teste. */
    public function semeando(string $tabela, array $linha): void
    {
        $this->tabelas[$tabela][] = $linha;
    }

    public function linhas(string $tabela): array
    {
        return $this->tabelas[$tabela] ?? [];
    }
}

class PdoFalsoStatement
{
    private PdoFalso $pdo;
    private string $sql;
    private array $linhas = [];
    private int $afetadas = 0;

    public function __construct(PdoFalso $pdo, string $sql)
    {
        $this->pdo = $pdo;
        $this->sql = preg_replace('/\s+/', ' ', trim($sql));
        $this->pdo->log[] = $this->sql;
    }

    public function execute(array $params = []): bool
    {
        $this->linhas = [];
        $this->afetadas = 0;

        if (stripos($this->sql, 'INSERT IGNORE INTO telegram_updates') === 0) {
            $id = (int) $params[':id'];
            if (isset($this->pdo->tabelas['telegram_updates'][$id])) {
                return true; // duplicado: nenhuma linha afetada
            }
            $this->pdo->tabelas['telegram_updates'][$id] = ['update_id' => $id, 'recebido_em' => date('Y-m-d H:i:s')];
            $this->afetadas = 1;
            return true;
        }
        if (stripos($this->sql, 'DELETE FROM telegram_updates WHERE update_id') === 0) {
            unset($this->pdo->tabelas['telegram_updates'][(int) $params[':id']]);
            $this->afetadas = 1;
            return true;
        }

        if (stripos($this->sql, 'INSERT INTO pendencias ') === 0) {
            $this->pdo->tabelas['pendencias'][] = [
                'id' => $params[':id'],
                'chat_id' => (int) $params[':chat'],
                'tipo' => $params[':tipo'],
                'payload' => $params[':payload'],
                'status' => 'pendente',
                'criada_em' => date('Y-m-d H:i:s'),
                'decidida_em' => null,
                'decidida_por' => null,
            ];
            $this->afetadas = 1;
            return true;
        }

        // claim atômico — o coração da regra "nada gravado sem confirmação"
        if (preg_match('/^UPDATE pendencias SET status = "confirmada".*WHERE id = :id AND status = "pendente"$/', $this->sql)) {
            foreach ($this->pdo->tabelas['pendencias'] as $i => $p) {
                if ($p['id'] === $params[':id'] && $p['status'] === 'pendente') {
                    $this->pdo->tabelas['pendencias'][$i]['status'] = 'confirmada';
                    $this->pdo->tabelas['pendencias'][$i]['decidida_em'] = date('Y-m-d H:i:s');
                    $this->pdo->tabelas['pendencias'][$i]['decidida_por'] = $params[':u'] ?? null;
                    $this->afetadas = 1;
                    return true;
                }
            }
            return true; // ninguém venceu — não é erro, é o toque duplo sendo barrado
        }
        if (preg_match('/^UPDATE pendencias SET status = "descartada".*WHERE id = :id AND status = "pendente"$/', $this->sql)) {
            foreach ($this->pdo->tabelas['pendencias'] as $i => $p) {
                if ($p['id'] === $params[':id'] && $p['status'] === 'pendente') {
                    $this->pdo->tabelas['pendencias'][$i]['status'] = 'descartada';
                    $this->afetadas = 1;
                }
            }
            return true;
        }
        if (stripos($this->sql, 'UPDATE pendencias SET status = "pendente", decidida_em = NULL') === 0) {
            foreach ($this->pdo->tabelas['pendencias'] as $i => $p) {
                if ($p['id'] === $params[':id']) {
                    $this->pdo->tabelas['pendencias'][$i]['status'] = 'pendente';
                    $this->pdo->tabelas['pendencias'][$i]['decidida_em'] = null;
                    $this->pdo->tabelas['pendencias'][$i]['decidida_por'] = null;
                    $this->afetadas = 1;
                }
            }
            return true;
        }

        if (stripos($this->sql, 'INSERT INTO consumo_agua ') === 0) {
            if ($this->pdo->falhasForçadas > 0) {
                $this->pdo->falhasForçadas--;
                throw new RuntimeException('banco indisponível (falha simulada)');
            }
            $this->pdo->tabelas['consumo_agua'][] = [
                'id' => $params[':id'],
                'data_leitura' => $params[':data'],
                'leitura_cesan' => $params[':cesan'],
                'leitura_h1' => $params[':h1'],
                'leitura_h2' => $params[':h2'],
                'leitura_h3' => $params[':h3'],
                'valor_fatura_total' => $params[':fatura'],
            ];
            $this->afetadas = 1;
            return true;
        }

        if (stripos($this->sql, 'INSERT INTO consumo_energia ') === 0) {
            if ($this->pdo->falhasForçadas > 0) {
                $this->pdo->falhasForçadas--;
                throw new RuntimeException('banco indisponível (falha simulada)');
            }
            $this->pdo->tabelas['consumo_energia'][] = [
                'id' => $params[':id'],
                'data_leitura' => $params[':data'],
                'leitura_padrao' => $params[':padrao'],
                'leitura_interno' => $params[':interno'],
                'leitura_interno_2' => $params[':interno2'],
                'valor_fatura_total' => $params[':fatura'],
                'local_medidor' => $params[':local'],
                'leitura_interno_anterior_ref' => $params[':ref1'],
                'leitura_interno_2_anterior_ref' => $params[':ref2'],
            ];
            $this->afetadas = 1;
            return true;
        }

        if (stripos($this->sql, 'INSERT INTO telegram_chats ') === 0) {
            $chatId = (int) $params[':c'];
            $existe = null;
            foreach ($this->pdo->tabelas['telegram_chats'] as $i => $c) {
                if ((int) $c['chat_id'] === $chatId) {
                    $existe = $i;
                }
            }
            $nova = [
                'chat_id' => $chatId,
                'user_id' => $params[':u'] ?? null,
                'nome' => $params[':n'] ?? null,
                'perfil' => $params[':p'] ?? null,
                'vinculado_em' => date('Y-m-d H:i:s'),
                'ultimo_uso' => date('Y-m-d H:i:s'),
            ];
            if ($existe === null) {
                $this->pdo->tabelas['telegram_chats'][] = $nova;
            } else {
                $this->pdo->tabelas['telegram_chats'][$existe] = array_merge($this->pdo->tabelas['telegram_chats'][$existe], $nova);
            }
            $this->afetadas = 1;
            return true;
        }

        if (stripos($this->sql, 'INSERT INTO telegram_aguardando ') === 0) {
            $chatId = (int) $params[':c'];
            $this->pdo->tabelas['telegram_aguardando'][$chatId] = [
                'chat_id' => $chatId,
                'estado' => $params[':e'],
                'payload' => $params[':p'],
                'atualizado_em' => date('Y-m-d H:i:s'),
            ];
            $this->afetadas = 1;
            return true;
        }

        if (stripos($this->sql, 'DELETE FROM telegram_aguardando WHERE chat_id') === 0) {
            unset($this->pdo->tabelas['telegram_aguardando'][(int) $params[':c']]);
            $this->afetadas = 1;
            return true;
        }

        if (stripos($this->sql, 'UPDATE telegram_chats SET perfil = NULL') === 0) {
            foreach ($this->pdo->tabelas['telegram_chats'] as $i => $c) {
                if ((int) $c['chat_id'] === (int) $params[':c']) {
                    $this->pdo->tabelas['telegram_chats'][$i]['perfil'] = null;
                    $this->pdo->tabelas['telegram_chats'][$i]['vinculado_em'] = null;
                    $this->afetadas = 1;
                }
            }
            return true;
        }

        // ---------------- leituras ----------------

        if (preg_match('/^SELECT \* FROM (consumo_agua|vw_agua_rateio) ORDER BY data_leitura DESC LIMIT 1$/', $this->sql, $m)) {
            $this->filtrarOrdenarELimitar($m[1], [], 1);
            return true;
        }
        if (preg_match('/^SELECT \* FROM (consumo_energia|vw_energia_rateio) WHERE local_medidor = :l ORDER BY data_leitura DESC LIMIT 1$/', $this->sql, $m)) {
            $this->filtrarOrdenarELimitar($m[1], ['local_medidor' => $params[':l']], 1);
            return true;
        }
        if (preg_match('/^SELECT \* FROM pendencias WHERE id = :id LIMIT 1$/', $this->sql)) {
            foreach ($this->pdo->tabelas['pendencias'] as $p) {
                if ($p['id'] === $params[':id']) {
                    $this->linhas = [$p];
                    return true;
                }
            }
            $this->linhas = [];
            return true;
        }
        if (preg_match('/^SELECT \* FROM pendencias WHERE chat_id = :c AND status = "pendente" ORDER BY criada_em DESC LIMIT (\d+)$/', $this->sql, $m)) {
            $fora = [];
            foreach ($this->pdo->tabelas['pendencias'] as $p) {
                if ((int) $p['chat_id'] === (int) $params[':c'] && $p['status'] === 'pendente') {
                    $fora[] = $p;
                }
            }
            usort($fora, fn($a, $b) => strcmp($b['criada_em'], $a['criada_em']));
            $this->linhas = array_slice($fora, 0, (int) $m[1]);
            return true;
        }
        if (preg_match('/^SELECT \* FROM telegram_chats WHERE chat_id = :c LIMIT 1$/', $this->sql)) {
            foreach ($this->pdo->tabelas['telegram_chats'] as $c) {
                if ((int) $c['chat_id'] === (int) $params[':c']) {
                    $this->linhas = [$c];
                    return true;
                }
            }
            $this->linhas = [];
            return true;
        }
        if (preg_match('/^SELECT \* FROM telegram_chats WHERE perfil = :p$/', $this->sql)) {
            $res = [];
            foreach ($this->pdo->tabelas['telegram_chats'] as $c) {
                if (($c['perfil'] ?? '') === ($params[':p'] ?? '')) {
                    $res[] = $c;
                }
            }
            $this->linhas = $res;
            return true;
        }
        if (preg_match('/^SELECT estado, payload, atualizado_em FROM telegram_aguardando WHERE chat_id = :c LIMIT 1$/', $this->sql)) {
            $id = (int) $params[':c'];
            $this->linhas = isset($this->pdo->tabelas['telegram_aguardando'][$id])
                ? [$this->pdo->tabelas['telegram_aguardando'][$id]]
                : [];
            return true;
        }
        if (stripos($this->sql, 'SELECT MAX(atualizado_em) AS ultimo FROM telegram_album WHERE id LIKE') === 0) {
            $this->linhas = [['ultimo' => date('Y-m-d H:i:s')]];
            return true;
        }
        if ($this->sql === 'SELECT * FROM usuarios WHERE nome = :n AND ativo = 1') {
            $this->linhas = array_values(array_filter($this->pdo->tabelas['usuarios'],
                fn($u) => $u['nome'] === $params[':n'] && (int) $u['ativo'] === 1));
            return true;
        }
        if ($this->sql === 'UPDATE usuarios SET senha = :s WHERE id = :id') {
            foreach ($this->pdo->tabelas['usuarios'] as &$u) {
                if ((string) $u['id'] === (string) $params[':id']) {
                    $u['senha'] = $params[':s'];
                }
            }
            unset($u);
            return true;
        }
        if (stripos($this->sql, 'SELECT * FROM usuarios ORDER BY perfil, nome') === 0) {
            $this->linhas = $this->pdo->tabelas['usuarios'];
            return true;
        }
        if (stripos($this->sql, 'INSERT INTO app_secrets') === 0) {
            $n = $params[':n'];
            $v = $params[':v'];
            $this->pdo->tabelas['app_secrets'][$n] = ['name' => $n, 'value' => $v, 'created_at' => date('Y-m-d H:i:s')];
            $this->afetadas = 1;
            return true;
        }
        if (preg_match('/^SELECT value FROM app_secrets WHERE name = :n LIMIT 1$/', $this->sql)) {
            $n = $params[':n'];
            $this->linhas = isset($this->pdo->tabelas['app_secrets'][$n]) ? [$this->pdo->tabelas['app_secrets'][$n]] : [];
            return true;
        }
        if (stripos($this->sql, 'SELECT valor_extraido, data_leitura, medidor_id FROM leitura_energia') === 0) {
            $linhas = array_values($this->pdo->linhas('leitura_energia'));
            if (isset($params[':d'])) {
                $d = $params[':d'];
                $encontrada = null;
                foreach ($linhas as $l) {
                    $lData = substr((string) ($l['data_leitura'] ?? ''), 0, 10);
                    if ($lData === $d || (stripos($this->sql, '<= :d') !== false && $lData <= $d)) {
                        $encontrada = $l;
                        break;
                    }
                }
                $this->linhas = $encontrada ? [$encontrada] : [];
                return true;
            }
            usort($linhas, fn($a, $b) => strcmp((string) ($b['data_leitura'] ?? ''), (string) ($a['data_leitura'] ?? '')));
            $this->linhas = array_slice($linhas, 0, 1);
            return true;
        }

        $this->pdo->falha($this->sql);
        return false;
    }

    private function filtrarOrdenarELimitar(string $tabela, array $filtro, int $limite): void
    {
        $linhas = $this->pdo->linhas($tabela);
        if ($filtro) {
            $linhas = array_values(array_filter($linhas, function ($l) use ($filtro) {
                foreach ($filtro as $col => $valor) {
                    if ((string) ($l[$col] ?? '') !== (string) $valor) {
                        return false;
                    }
                }
                return true;
            }));
        }
        usort($linhas, fn($a, $b) => strcmp((string) ($b['data_leitura'] ?? ''), (string) ($a['data_leitura'] ?? '')));
        $this->linhas = array_slice($linhas, 0, $limite);
    }

    public function rowCount(): int
    {
        return $this->afetadas;
    }

    public function fetch()
    {
        if (!$this->linhas) {
            return false;
        }
        return array_shift($this->linhas);
    }

    public function fetchAll(): array
    {
        $r = $this->linhas;
        $this->linhas = [];
        return $r;
    }
}
