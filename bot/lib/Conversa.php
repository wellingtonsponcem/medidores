<?php
/**
 * Estado curto da conversa — o que o bot está esperando deste chat responder.
 *
 * Guardado em tabela, não em memória: cada requisição do webhook é um processo
 * PHP novo, então memória não sobrevive de uma chamada para a outra. É o
 * equivalente ao telegram_chats.aguardando do Gampo.
 *
 * Cuidado com o consumo: a linha tem atualizado_em e é apagada quando o estado
 * é resolvido ou quando expira, para a tabela não virar lixo acumulado.
 */
class Conversa
{
    public const VIDA_SEGUNDOS = 900; // 15 min: mais que isso, o usuário já esqueceu o que estava fazendo

    /** @return array{estado:?string, payload:array} */
    public static function ler(int $chatId): array
    {
        Banco::garantirTabelas();
        $stmt = Banco::pdo()->prepare('SELECT estado, payload, atualizado_em FROM telegram_aguardando WHERE chat_id = :c LIMIT 1');
        $stmt->execute([':c' => $chatId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return ['estado' => null, 'payload' => []];
        }
        // estado velho não vale mais: o bot não pode ficar esperando para sempre
        $idade = time() - strtotime($row['atualizado_em']);
        if ($idade > self::VIDA_SEGUNDOS) {
            self::limpar($chatId);
            return ['estado' => null, 'payload' => []];
        }
        return ['estado' => $row['estado'], 'payload' => json_decode($row['payload'], true) ?: []];
    }

    public static function definir(int $chatId, string $estado, array $payload = []): void
    {
        Banco::garantirTabelas();
        $sql = 'INSERT INTO telegram_aguardando (chat_id, estado, payload, atualizado_em)
                VALUES (:c, :e, :p, NOW())
                ON DUPLICATE KEY UPDATE estado = VALUES(estado), payload = VALUES(payload), atualizado_em = NOW()';
        $stmt = Banco::pdo()->prepare($sql);
        $stmt->execute([':c' => $chatId, ':e' => $estado, ':p' => json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    }

    public static function limpar(int $chatId): void
    {
        try {
            $stmt = Banco::pdo()->prepare('DELETE FROM telegram_aguardando WHERE chat_id = :c');
            $stmt->execute([':c' => $chatId]);
        } catch (Exception $e) {
        }
    }
}
