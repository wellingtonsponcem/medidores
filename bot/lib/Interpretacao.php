<?php
/**
 * Interpretação do texto digitado.
 *
 * A ordem importa: primeiro regex, depois IA. Detecção por regra é instantânea,
 * não gasta cota do Gemini e não erra por causa de um dia em que o modelo acorda
 * mal-humorado. A IA só entra quando as regras não souberem decidir — é o mesmo
 * desenho do Gampo (interpretacao.js) e do Finpo (atalhoTelegram).
 *
 * A maioria das mensagens do dia a dia cai inteira nas regras: "quanto deu a
 * água?", "resumo", "cesan 1806 h1 490...".
 */
class Interpretacao
{
    /**
     * @return array|null intenção estruturada, ou null quando a IA é necessária
     */
    public static function analisar(string $texto, ?string $perfil): ?array
    {
        $t = self::normalizar($texto);
        if ($t === '') {
            return null;
        }

        // ---- lançamento por texto (admin) ----
        $lancamento = self::detectarLancamento($t);
        if ($lancamento !== null) {
            return $lancamento;
        }

        // ---- consultas por regra ----
        $consulta = self::detectarConsulta($t);
        if ($consulta !== null) {
            $local = self::detectarLocal($t);
            return [
                'tipo' => 'consulta',
                'pergunta' => $consulta,
                'local' => $local,
                'texto' => $texto,
            ];
        }

        return null;
    }

    public static function detectarLocal(string $texto): ?string
    {
        $t = self::normalizar($texto);
        if (preg_match('/\b(av|avenida|brasil|carapebus|loja|loja2|fernando|graca)\b/', $t)) {
            return 'av_brasil';
        }
        if (preg_match('/\b(inhambu|novo horizonte|casa1|casa2|mirian|rose|wellington)\b/', $t)) {
            return 'rua_inhambu';
        }
        return null;
    }

    private static function normalizar(string $texto): string
    {
        $t = strtolower(trim($texto));
        $t = strtr($t, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
            'é' => 'e', 'ê' => 'e', 'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);
        return $t;
    }

    private static function detectarConsulta(string $t): ?string
    {
        // "situacao" por inteiro: um prefixo como "situa" com \b não casa dentro
        // da palavra, e a pessoa digita "situação" na integral
        if (preg_match('/\b(resumo|painel|situacao|geral|status)\b/', $t)) {
            return 'resumo';
        }
        if (preg_match('/\b(agua|cesan|hidrometro|hidro|molho)\b/', $t)) {
            return 'agua';
        }
        if (preg_match('/\b(energia|edp|kwh|relatorio|luz)\b/', $t)) {
            return 'energia';
        }
        if (preg_match('/\b(ultima|ultimo|leitura|lanco|lanca|tem)\b/', $t)) {
            return 'ultimo';
        }
        if (preg_match('/\b(compartilhar|copiar|whatsapp|texto)\b/', $t)) {
            return 'compartilhar';
        }
        if (preg_match('/\b(logo|logotipo|marca)\b/', $t)) {
            return 'logo';
        }
        return null;
    }

    /**
     * Detecta um lançamento escrito por extenso.
     *
     * Formatos aceitos (qualquer ordem, sinônimos):
     *   agua cesan 1806 h1 490 h2 189 h3 284 fatura 560.18
     *   energia padrao 2500 interno 3500 fatura 200.50 av
     *
     * @return array|null
     */
    public static function detectarLancamento(string $t): ?array
    {
        if (preg_match('/\b(agua|cesan)\b/', $t)) {
            $cesan = self::campo($t, ['cesan', 'leitura', 'total']);
            $h1 = self::campo($t, ['h1', 'hidro 1', 'hidro1']);
            $h2 = self::campo($t, ['h2', 'hidro 2', 'hidro2']);
            $h3 = self::campo($t, ['h3', 'hidro 3', 'hidro3']);
            $fatura = self::campo($t, ['fatura', 'valor', 'total a pagar']);

            // precisa ter pelo menos a leitura da CESAN para virar lançamento
            if ($cesan === null) {
                return null;
            }
            return [
                'tipo' => 'lancamento',
                'tipo_dado' => 'agua',
                'payload' => [
                    'data' => self::dataDoTexto($t),
                    'cesan' => sanitizar_numero($cesan),
                    'h1' => $h1 !== null ? sanitizar_numero($h1) : null,
                    'h2' => $h2 !== null ? sanitizar_numero($h2) : null,
                    'h3' => $h3 !== null ? sanitizar_numero($h3) : null,
                    'fatura' => $fatura !== null ? sanitizar_numero($fatura) : 0,
                    'origem' => 'texto',
                ],
            ];
        }

        if (preg_match('/\b(energia|edp|kwh)\b/', $t)) {
            $padrao = self::campo($t, ['padrao', 'consumo', 'total', 'fatura consumo']);
            $interno = self::campo($t, ['interno', 'interna', 'medidor', 'relatorio interno', 'submedidor']);
            $fatura = self::campo($t, ['fatura', 'valor', 'total a pagar']);

            if ($padrao === null && $interno === null) {
                return null;
            }
            $local = energia_local_normalizado(strpos($t, 'av') !== false || strpos($t, 'brasil') !== false ? 'av_brasil' : 'rua_inhambu');
            $ant = null;
            try {
                $ant = Banco::ultimaEnergiaBruta($local);
            } catch (Exception $e) {
                // sem banco não há referência anterior — o rateio sai residual
            }

            return [
                'tipo' => 'lancamento',
                'tipo_dado' => 'energia',
                'payload' => [
                    'data' => self::dataDoTexto($t),
                    'local' => $local,
                    'padrao' => $padrao !== null ? sanitizar_numero($padrao) : 0,
                    'interno' => $interno !== null ? sanitizar_numero($interno) : 0,
                    'interno2' => null,
                    'fatura' => $fatura !== null ? sanitizar_numero($fatura) : 0,
                    'ref1' => $ant ? (float) ($ant['leitura_interno_anterior_ref'] ?? $ant['leitura_interno'] ?? 0) : null,
                    'ref2' => null,
                    'origem' => 'texto',
                ],
            ];
        }

        return null;
    }

    /**
     * Pega o primeiro número que aparece depois de um dos sinônimos.
     *
     * Procuramos "depois de" e não "colado" porque o texto vem digitado solto:
     * "cesan 1806", "fatura: 560,18", "h1 0490" — todos precisam funcionar.
     */
    private static function campo(string $t, array $sinonimos): ?string
    {
        foreach ($sinonimos as $s) {
            $s = self::normalizar($s);
            if (preg_match('/\b' . preg_quote($s, '/') . '\s*[:=]?\s*(\d[\d.,]*)/', $t, $m)) {
                return $m[1];
            }
        }
        return null;
    }

    /**
     * Data escrita na mensagem, ou hoje.
     *
     * "dia 20" vira o dia 20 do mês corrente; se isso já passou no relógio mas a
     * fatura é do mês anterior, recua um mês. O valor aparece na pendência, então
     * qualquer erro é visível antes de gravar.
     */
    public static function dataDoTexto(string $t): string
    {
        // a data pode estar no começo, no meio ou no fim da frase, então
        // procuramos em qualquer lugar — o texto vem digitado solto
        if (preg_match('/(\d{4})-(\d{1,2})-(\d{1,2})/', $t, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/', $t, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        if (preg_match('/\bdia\s*(\d{1,2})\b/', $t, $m)) {
            $hoje = getdate();
            $dia = (int) $m[1];
            if ($dia < 1 || $dia > 31) {
                return date('Y-m-d');
            }
            $ts = mktime(12, 0, 0, (int) $hoje['mon'], $dia, (int) $hoje['year']);
            // dia ainda não aconteceu neste mês -> provavelmente é a fatura do mês passado
            if ($ts > time()) {
                $ts = mktime(12, 0, 0, (int) $hoje['mon'] - 1, $dia, (int) $hoje['year']);
            }
            return date('Y-m-d', $ts);
        }
        return date('Y-m-d');
    }

    /**
     * Última camada: o que as regras não cobriram vai para a IA.
     *
     * O schema é estrito e devolve uma das intenções conhecidas. Quando o modelo
     * não consegue classificar, ele devolve 'conversa' e o bot responde com a
     * ajuda — em vez de inventar uma ação.
     *
     * @return array{ok:bool, intencao?:string, duvida?:string, erro?:string}
     */
    public static function viaIa(string $texto, ?string $perfil): array
    {
        $chave = Banco::chaveGemini();
        if ($chave === null) {
            return ['ok' => false, 'erro' => 'sem_ia'];
        }

        $regras = "Você interpreta mensagens de um morador ou administrador sobre contas de condomínio.\n"
            . "Perfis possíveis: admin (gerencia tudo), casa1, casa2, casa3, loja2.\n"
            . "Locais de energia:\n"
            . "- 'av_brasil': Avenida Brasil, Praia de Carapebus, Loja 02, Fernando, Graça\n"
            . "- 'rua_inhambu': Rua Inhambu, Novo Horizonte, Casa 1, Casa 2, Mirian, Rose, Wellington\n\n"
            . "Perfil de quem falou: " . ($perfil ?: 'desconhecido') . "\n\n"
            . "Responda APENAS com o JSON, sem markdown, sem comentário.\n"
            . "Se for consulta de energia, informe em 'local' o imóvel ('av_brasil' ou 'rua_inhambu') caso mencionado.\n"
            . "Se a mensagem for conversa, dúvida ou cumprimento, use intencao 'conversa'.\n"
            . "Só use intencao 'lancamento' se tiver números claros de leitura.";

        $corpo = json_encode([
            'contents' => [['parts' => [['text' => $regras . "\n\nMensagem: " . $texto]]]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'intencao' => ['type' => 'STRING', 'enum' => ['consulta', 'lancamento', 'conversa']],
                        'consulta' => ['type' => 'STRING', 'enum' => ['resumo', 'agua', 'energia', 'ultimo', 'compartilhar', 'logo']],
                        'local' => ['type' => 'STRING', 'enum' => ['av_brasil', 'rua_inhambu']],
                        'duvida' => ['type' => 'STRING'],
                        'resposta' => ['type' => 'STRING'],
                        'lancamento' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'tipo_dado' => ['type' => 'STRING', 'enum' => ['agua', 'energia']],
                                'data' => ['type' => 'STRING'],
                                'campos' => ['type' => 'OBJECT'],
                            ],
                        ],
                    ],
                ],
                'temperature' => 0,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $r = Gemini::gerarJson($chave, $corpo);
        if (!$r['ok']) {
            return ['ok' => false, 'erro' => $r['erro']];
        }
        $json = Ocr::parsearJson(Gemini::textoDoResultado($r['data']));
        if ($json === null || empty($json['intencao'])) {
            return ['ok' => false, 'erro' => 'resposta_invalida'];
        }
        return [
            'ok' => true,
            'intencao' => $json['intencao'],
            'consulta' => $json['consulta'] ?? null,
            'local' => $json['local'] ?? null,
            'resposta' => $json['resposta'] ?? null,
            'duvida' => $json['duvida'] ?? null,
            'lancamento' => $json['lancamento'] ?? null,
        ];
    }
}
