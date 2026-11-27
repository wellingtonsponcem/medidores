<?php
/**
 * Convite de uso único para vincular um aparelho sem senha.
 *
 * O administrador gera o link com /convite <usuário>; quem toca nele abre o bot
 * com "/start <código>" e o chat fica vinculado ao perfil daquele usuário.
 *
 * Por que existe: o bot não pode puxar conversa por número de telefone, e um
 * morador novo nasce com senha aleatória. Passar senha por WhatsApp é pior do
 * que um link que vale uma vez e expira.
 *
 * Só o hash SHA-256 do código fica no banco: quem ler a tabela não consegue
 * usar um convite ainda aberto.
 */
class Convite
{
    public const HORAS = 72;

    /** Perfis que podem ser convidados. Admin fica de fora: um link vazado viraria acesso total. */
    public const PERFIS = ['casa1', 'casa2', 'casa3', 'loja2'];

    /**
     * @return array{ok:bool, codigo?:string, usuario?:array, expira_em?:string, erro?:string}
     */
    public static function criar(string $nomeUsuario, int $criadoPor, ?int $agora = null): array
    {
        $agora = $agora ?? time();
        $u = self::usuarioAtivo($nomeUsuario);
        if ($u === null) {
            return ['ok' => false, 'erro' => "Não achei o usuário *{$nomeUsuario}* ativo no cadastro."];
        }
        if (!in_array((string) $u['perfil'], self::PERFIS, true)) {
            return ['ok' => false, 'erro' => 'Convite só serve para moradores e lojas. Administrador entra com `/vincular`.'];
        }

        Banco::garantirTabelas();
        $codigo = bin2hex(random_bytes(16));
        $expira = date('Y-m-d H:i:s', $agora + self::HORAS * 3600);
        $stmt = Banco::pdo()->prepare(
            'INSERT INTO telegram_convites (codigo_hash, usuario_id, usuario_nome, perfil, criado_por, criado_em, expira_em)
             VALUES (:h, :u, :n, :p, :c, :agora, :expira)'
        );
        $stmt->execute([
            ':h' => hash('sha256', $codigo),
            ':u' => (string) $u['id'],
            ':n' => (string) $u['nome'],
            ':p' => (string) $u['perfil'],
            ':c' => $criadoPor,
            ':agora' => date('Y-m-d H:i:s', $agora),
            ':expira' => $expira,
        ]);
        return ['ok' => true, 'codigo' => $codigo, 'usuario' => $u, 'expira_em' => $expira];
    }

    /** Formato do código que vem no /start. */
    public static function pareceCodigo(string $texto): bool
    {
        return (bool) preg_match('/^[a-f0-9]{32}$/', $texto);
    }

    /**
     * Gasta o convite e vincula o chat. A baixa é atômica: dois toques no mesmo
     * link (ou dois aparelhos) não vinculam duas vezes.
     *
     * @return array{ok:bool, usuario?:array, criado_por?:int, erro?:string}
     */
    public static function usar(string $codigo, int $chatId, ?int $userId, ?string $nomeChat, ?int $agora = null): array
    {
        $invalido = ['ok' => false, 'erro' => 'Este convite não vale mais: já foi usado ou expirou. Peça um novo ao administrador.'];
        if (!self::pareceCodigo($codigo)) {
            return $invalido;
        }
        $agoraStr = date('Y-m-d H:i:s', $agora ?? time());
        $hash = hash('sha256', $codigo);
        Banco::garantirTabelas();

        // quem já está vinculado não troca de perfil por link: protege o admin
        // de virar morador por engano ao tocar num convite repassado
        $chat = Banco::chat($chatId);
        if ($chat !== null && !empty($chat['perfil'])) {
            return ['ok' => false, 'erro' => 'Este aparelho já está vinculado como *' . $chat['perfil'] . '*. Para usar o convite, envie /sair antes.'];
        }

        $baixa = Banco::pdo()->prepare(
            'UPDATE telegram_convites SET usado_em = :agora, usado_por = :c
             WHERE codigo_hash = :h AND usado_em IS NULL AND expira_em > :limite'
        );
        // o MySQL com prepare nativo não aceita o mesmo marcador duas vezes
        $baixa->execute([':agora' => $agoraStr, ':c' => $chatId, ':h' => $hash, ':limite' => $agoraStr]);
        if ($baixa->rowCount() !== 1) {
            return $invalido;
        }

        $sel = Banco::pdo()->prepare('SELECT * FROM telegram_convites WHERE codigo_hash = :h LIMIT 1');
        $sel->execute([':h' => $hash]);
        $conv = $sel->fetch();
        // o cadastro pode ter mudado depois do convite: confere de novo
        $u = $conv ? self::usuarioAtivo((string) $conv['usuario_nome']) : null;
        if ($u === null || (string) $u['perfil'] !== (string) $conv['perfil']) {
            return ['ok' => false, 'erro' => 'O cadastro deste convite mudou ou foi desativado. Peça um novo ao administrador.'];
        }

        Banco::vincularChat($chatId, $userId, $nomeChat ?: (string) $u['nome'], (string) $u['perfil']);
        return ['ok' => true, 'usuario' => $u, 'criado_por' => (int) $conv['criado_por']];
    }

    private static function usuarioAtivo(string $nome): ?array
    {
        $stmt = Banco::pdo()->prepare('SELECT * FROM usuarios WHERE nome = :n AND ativo = 1');
        $stmt->execute([':n' => trim($nome)]);
        $u = $stmt->fetch();
        return $u ?: null;
    }
}
