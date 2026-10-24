<?php
/**
 * Cliente HTTP nativo da API do Bots do Telegram — sem nenhuma dependência.
 *
 * É a tradução de api/_lib/telegram.js, que existe nos dois projetos irmãos.
 * A escolha de não usar grammY/telegraf é proposital: o bot tem 8 chamadas
 * distintas, e uma biblioteca inteira para isso só adicionaria superfície de
 * falha e um processo de longa duração que a hospedagem compartilhada não suporta.
 */
class Telegram
{
    private static string $base = 'https://api.telegram.org';

    public static function token(): ?string
    {
        return env('TELEGRAM_BOT_TOKEN');
    }

    public static function temToken(): bool
    {
        return self::token() !== null;
    }

    /**
     * Chama um método da API.
     *
     * @return array{ok:bool, resultado?:array, descricao?:string, status?:int}
     */
    public static function chamar(string $metodo, array $payload = []): array
    {
        $token = self::token();
        if ($token === null) {
            return ['ok' => false, 'descricao' => 'TELEGRAM_BOT_TOKEN ausente'];
        }
        $url = self::$base . '/bot' . $token . '/' . $metodo;
        $res = Http::postJson($url, $payload);
        $json = json_decode($res['corpo'], true);
        if (!is_array($json)) {
            return ['ok' => false, 'descricao' => 'Resposta inválida do Telegram', 'status' => $res['status']];
        }
        $json['status'] = $res['status'];
        return $json;
    }

    /**
     * Envia mensagem. O Telegram rejeita a formatação Markdown mal feita, então
     * tentamos com parse_mode e, se recusar, reenviamos como texto puro — o
     * usuário recebe a informação mesmo que a negrito tenha falhado.
     */
    public static function enviarMensagem($chatId, string $texto, ?array $teclado = null, ?string $parseMode = 'Markdown'): array
    {
        $payload = ['chat_id' => $chatId, 'text' => $texto];
        if ($teclado !== null) {
            $payload['reply_markup'] = ['inline_keyboard' => $teclado];
        }
        if ($parseMode !== null) {
            $payload['parse_mode'] = $parseMode;
        }
        $r = self::chamar('sendMessage', $payload);

        if (!$r['ok'] && $parseMode !== null) {
            unset($payload['parse_mode']);
            $r = self::chamar('sendMessage', $payload);
        }
        return $r;
    }

    /** Teclado inline: [[['Texto','callback:data'], ...], ...] */
    public static function enviarTeclado($chatId, string $texto, array $teclado): array
    {
        return self::enviarMensagem($chatId, $texto, $teclado);
    }

    public static function editarMensagem($chatId, int $mensagemId, string $texto, ?array $teclado = null): array
    {
        $payload = [
            'chat_id' => $chatId,
            'message_id' => $mensagemId,
            'text' => $texto,
            'parse_mode' => 'Markdown',
        ];
        if ($teclado !== null) {
            $payload['reply_markup'] = ['inline_keyboard' => $teclado];
        }
        $r = self::chamar('editMessageText', $payload);
        // "message is not modified" não é erro: o usuário clicou duas vezes no mesmo botão
        if (!$r['ok'] && stripos((string) ($r['descricao'] ?? ''), 'message is not modified') !== false) {
            return $r;
        }
        if (!$r['ok']) {
            unset($payload['parse_mode']);
            $r = self::chamar('editMessageText', $payload);
        }
        return $r;
    }

    /** Mostra ao usuário que o bot está digitando enquanto processa. */
    public static function acaoChat($chatId, string $acao = 'typing'): void
    {
        self::chamar('sendChatAction', ['chat_id' => $chatId, 'action' => $acao]);
    }

    /** Confirma o clique num botão inline (senão o Telegram mostra um relógio pra sempre). */
    public static function responderCallback(string $callbackQueryId, ?string $texto = null): array
    {
        $payload = ['callback_query_id' => $callbackQueryId];
        if ($texto !== null) {
            $payload['text'] = $texto;
            $payload['show_alert'] = false;
        }
        return self::chamar('answerCallbackQuery', $payload);
    }

    /**
     * Baixa um arquivo enviado ao bot e devolve em base64.
     *
     * Comprime para no máximo 1200px quando a extensão GD existe — mesma regra
     * do painel web (compressImage), porque o Gemini cobra por pixel e uma foto
     * de 12MP vira token à toa.
     *
     * @return array{ok:bool, data?:string, mimeType?:string, erro?:string}
     */
    public static function baixarArquivo(string $fileId, int $ladoMax = 1200, int $qualidade = 80): array
    {
        $info = self::chamar('getFile', ['file_id' => $fileId]);
        if (empty($info['ok']) || empty($info['resultado']['file_path'])) {
            return ['ok' => false, 'erro' => 'Não consegui baixar o arquivo enviado.'];
        }
        $caminho = $info['resultado']['file_path'];
        $url = self::$base . '/file/bot' . self::token() . '/' . $caminho;
        $res = Http::get($url, [], 90);
        if ($res['status'] !== 200 || $res['corpo'] === '') {
            return ['ok' => false, 'erro' => 'Falha ao transferir a imagem (HTTP ' . $res['status'] . ').'];
        }

        $bytes = $res['corpo'];
        $mime = self::adivinharMime($caminho, $bytes);

        if (strpos($mime, 'image/') === 0) {
            $comprimido = self::comprimir($bytes, $ladoMax, $qualidade);
            if ($comprimido !== null) {
                $bytes = $comprimido;
                $mime = 'image/jpeg';
            }
        }

        return ['ok' => true, 'data' => base64_encode($bytes), 'mimeType' => $mime];
    }

    private static function adivinharMime(string $caminho, string $bytes): string
    {
        $ext = strtolower(pathinfo($caminho, PATHINFO_EXTENSION));
        $mapa = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
        if (isset($mapa[$ext])) {
            return $mapa[$ext];
        }
        if (strncmp($bytes, "\xFF\xD8", 2) === 0) {
            return 'image/jpeg';
        }
        if (strncmp($bytes, "\x89PNG", 4) === 0) {
            return 'image/png';
        }
        return 'application/octet-stream';
    }

    /** Redimensiona e recomprime. Devolve null quando não há GD ou a foto já é pequena. */
    private static function comprimir(string $bytes, int $ladoMax, int $qualidade): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return null;
        }
        $largura = imagesx($src);
        $altura = imagesy($src);
        if ($largura === 0 || $altura === 0) {
            return null;
        }
        $maior = max($largura, $altura);
        if ($maior <= $ladoMax) {
            // já é pequena o bastante: não recomprime, para não perder dígito de hidrômetro
            imagedestroy($src);
            return null;
        }
        $escala = $ladoMax / $maior;
        $novaL = max(1, (int) round($largura * $escala));
        $novaA = max(1, (int) round($altura * $escala));
        $dest = imagecreatetruecolor($novaL, $novaA);
        imagecopyresampled($dest, $src, 0, 0, 0, 0, $novaL, $novaA, $largura, $altura);
        ob_start();
        imagejpeg($dest, null, $qualidade);
        $saida = ob_get_clean();
        imagedestroy($src);
        imagedestroy($dest);
        return $saida === '' ? null : $saida;
    }

    /** Registra o webhook. Chamado pelo scripts/telegram-setup.mjs, nunca pelo bot. */
    public static function definirWebhook(string $url, ?string $secretToken = null): array
    {
        $payload = [
            'url' => $url,
            'allowed_updates' => ['message', 'callback_query'],
            'drop_pending_updates' => true,
        ];
        if ($secretToken !== null) {
            $payload['secret_token'] = $secretToken;
        }
        return self::chamar('setWebhook', $payload);
    }

    public static function infoWebhook(): array
    {
        return self::chamar('getWebhookInfo');
    }

    public static function apagarWebhook(): array
    {
        return self::chamar('deleteWebhook', ['drop_pending_updates' => true]);
    }

    /** Publica o menu de comandos no Telegram (o mesmo mapa do handler). */
    public static function definirComandos(array $comandos): array
    {
        $lista = [];
        foreach ($comandos as $cmd => $descricao) {
            $lista[] = ['command' => ltrim($cmd, '/'), 'description' => $descricao];
        }
        return self::chamar('setMyCommands', ['commands' => $lista]);
    }

    public static function quemSou(): array
    {
        return self::chamar('getMe');
    }
}
