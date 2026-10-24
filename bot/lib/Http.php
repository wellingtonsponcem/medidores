<?php
/**
 * Camada HTTP do bot.
 *
 * Existe como módulo separado por um motivo só: testabilidade. O Gampo intercepta
 * `globalThis.fetch` para os testes rodarem sem rede; aqui o equivalente é trocar
 * `Http::$transporte`. Assim o handler real é exercitado de ponta a ponta sem
 * bater no Telegram nem no Gemini.
 *
 * Sem curl a função cai para streams nativos — a hospedagem tem curl, mas a
 * máquina de desenvolvimento nem sempre, e uma dependência ausente não pode
 * derrubar o bot inteiro.
 */
class Http
{
    /** @var callable|null substituído pelos testes: fn(string $url, array $opcoes): array */
    public static $transporte = null;

    /**
     * POST com corpo JSON.
     *
     * @return array{status:int, corpo:string} status HTTP e corpo cru
     */
    public static function postJson(string $url, $corpo, array $headers = [], int $timeout = 60): array
    {
        $opcoes = [
            'metodo' => 'POST',
            'corpo' => is_string($corpo) ? $corpo : json_encode($corpo, JSON_UNESCAPED_UNICODE),
            'headers' => array_merge(['Content-Type: application/json'], $headers),
            'timeout' => $timeout,
        ];
        return self::enviar($url, $opcoes);
    }

    /** GET simples. */
    public static function get(string $url, array $headers = [], int $timeout = 60): array
    {
        return self::enviar($url, ['metodo' => 'GET', 'headers' => $headers, 'timeout' => $timeout]);
    }

    public static function enviar(string $url, array $opcoes): array
    {
        if (self::$transporte !== null) {
            $fn = self::$transporte;
            $r = $fn($url, $opcoes);
            // o transporte de teste devolve o mesmo formato — não confiamos nele às cegas
            return [
                'status' => (int) ($r['status'] ?? 0),
                'corpo' => (string) ($r['corpo'] ?? ''),
            ];
        }

        if (function_exists('curl_init')) {
            return self::enviarCurl($url, $opcoes);
        }
        return self::enviarStream($url, $opcoes);
    }

    private static function enviarCurl(string $url, array $opcoes): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) ($opcoes['timeout'] ?? 60));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $opcoes['headers'] ?? []);
        if (($opcoes['metodo'] ?? 'GET') === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $opcoes['corpo'] ?? '');
        } else {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $opcoes['metodo'] ?? 'GET');
        }
        $corpo = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'corpo' => $corpo === false ? '' : $corpo];
    }

    /** Fallback sem curl. Só é usado se a extensão não existir. */
    private static function enviarStream(string $url, array $opcoes): array
    {
        $metodo = $opcoes['metodo'] ?? 'GET';
        $contexto = stream_context_create([
            'http' => [
                'method' => $metodo,
                'header' => implode("\r\n", $opcoes['headers'] ?? []),
                'content' => $opcoes['corpo'] ?? '',
                'timeout' => (int) ($opcoes['timeout'] ?? 60),
                'ignore_errors' => true,
            ],
        ]);
        $corpo = @file_get_contents($url, false, $contexto);
        $status = 0;
        foreach ($http_response_header ?? [] as $linha) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $linha, $m)) {
                $status = (int) $m[1];
            }
        }
        return ['status' => $status, 'corpo' => $corpo === false ? '' : $corpo];
    }
}
