<?php
/**
 * Chamada direta à API do Gemini, com cascata de modelos.
 *
 * Antes existia dentro do api.php e agora mora aqui de propósito: o painel web
 * e o bot usam o MESMO proxy de OCR. Se a lista de modelos mudar, muda nos dois
 * lugares ao mesmo tempo — que é exatamente o problema que o reuso evita.
 *
 * A cascata existe porque o Gemini renomeia modelos com frequência. Em vez de
 * quebrar quando um nome sai do ar, tentamos a lista inteira e só desistimos
 * quando o erro não é 404 (quota, chave inválida, etc).
 */
require_once __DIR__ . '/Http.php';

class Gemini
{
    public const MODELOS_PADRAO = [
        'gemini-2.5-flash',
        'gemini-2.0-flash',
        'gemini-1.5-flash-latest',
        'gemini-1.5-flash-8b',
        'gemini-1.5-flash',
        'gemini-2.5-pro',
    ];

    /**
     * @param string|null $chave chave da API (do .env ou do app_secrets)
     * @param string $corpo payload completo {contents, generationConfig}
     * @return array{ok:bool, data:?array, erro:?string, modelo:?string}
     */
    public static function gerarJson(?string $chave, string $corpo, ?array $modelos = null): array
    {
        if ($chave === null || $chave === '') {
            return ['ok' => false, 'data' => null, 'erro' => 'Nenhuma chave ativa do Gemini encontrada', 'modelo' => null];
        }

        $modelos = $modelos ?: self::MODELOS_PADRAO;
        $ultimoErro = null;

        foreach ($modelos as $modelo) {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $modelo . ':generateContent?key=' . rawurlencode($chave);
            $res = Http::postJson($url, $corpo, [], 90);

            if ($res['status'] === 200) {
                $json = json_decode($res['corpo'], true);
                return ['ok' => true, 'data' => $json, 'erro' => null, 'modelo' => $modelo];
            }

            $json = json_decode($res['corpo'], true);
            $ultimoErro = is_array($json) ? ($json['error']['message'] ?? $res['corpo']) : $res['corpo'];

            // 404 = modelo não existe mais nesta conta -> tenta o próximo
            if ($res['status'] !== 404) {
                break;
            }
        }

        return ['ok' => false, 'data' => null, 'erro' => (string) $ultimoErro, 'modelo' => null];
    }

    /** Extrai o texto de candidato[0] e devolve o JSON já interpretado. */
    public static function textoDoResultado($resultado): ?string
    {
        return $resultado['candidates'][0]['content']['parts'][0]['text'] ?? null;
    }
}
