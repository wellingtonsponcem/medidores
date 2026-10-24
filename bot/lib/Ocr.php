<?php
/**
 * OCR das faturas e dos medidores pelo Gemini.
 *
 * Os prompts são a tradução literal dos que o painel admin usa em
 * processarImagens(). As regras ali foram ajustadas ao longo do tempo por
 * causa de erros reais — dígito vermelho virando leitura, zeros à esquerda,
 * leitura anterior trocada com a atual — e reescrever do zero aqui seria
 * jogar fora essa correção. Por isso o texto muda junto com o painel.
 */
class Ocr
{
    /** Ordem em que as imagens são apresentadas ao modelo. */
    public const PAPEIS_AGUA = ['fatura', 'h1', 'h2', 'h3'];
    public const PAPEIS_ENERGIA = ['fatura', 'interno'];

    /** Relógio que cada imóvel recebe por foto, e a coluna do rateio onde ele vai parar. */
    public const RELOGIO_POR_FOTO = [
        'av_brasil' => ['chave' => 'ULTIMO_WATIMETRO_LOJA2', 'coluna' => 'leitura_interno_2'],
        'rua_inhambu' => ['chave' => 'ULTIMO_WATIMETRO_CASA2', 'coluna' => 'leitura_interno'],
    ];

    private static function chaveGemini(): ?string
    {
        return Banco::chaveGemini();
    }

    /** Valor do relógio já usado no último rateio gravado deste imóvel, ou null. */
    public static function relogioUsadoNoRateio(string $local): ?float
    {
        $cfg = self::RELOGIO_POR_FOTO[$local] ?? null;
        $ant = $cfg ? Banco::ultimaEnergiaBruta($local) : null;
        $v = $ant[$cfg['coluna'] ?? ''] ?? null;
        return $v !== null && $v !== '' ? (float) $v : null;
    }

    /**
     * Confere a leitura nova de um relógio antes de guardá-la.
     *
     * Relógio de energia só anda para frente. Uma leitura menor que a última
     * conhecida é foto errada (outro medidor, dígito mal lido) — e, guardada,
     * entraria calada no próximo rateio.
     *
     * @return string|null mensagem de recusa, ou null quando está tudo certo
     */
    public static function conferirRelogioNovo(string $local, float $nova): ?string
    {
        $cfg = self::RELOGIO_POR_FOTO[$local] ?? null;
        if ($cfg === null) {
            return null;
        }
        $refs = array_filter([
            Banco::leituraFoto($cfg['chave'])['valor'] ?? null,
            self::relogioUsadoNoRateio($local),
        ], fn($v) => $v !== null);
        if (!$refs) {
            return null;
        }
        $anterior = max($refs);
        if ($nova < $anterior) {
            return 'A leitura ' . fmt_num($nova, 2) . ' kWh é menor que a última registrada ('
                . fmt_num($anterior, 2) . " kWh). Relógio não anda para trás — confira a foto e mande de novo.\n\n"
                . 'Não guardei nada.';
        }
        return null;
    }

    /**
     * Leitura do relógio por foto que pode entrar no rateio deste mês.
     *
     * Antes, a última foto valia para sempre: se ninguém mandasse uma nova, o
     * rateio seguinte reaproveitava a do mês passado e a unidade saía com
     * consumo zero sem aviso. Agora uma foto que não passou da leitura já usada
     * no último rateio é tratada como velha e fica de fora.
     *
     * @return array{valor: ?float, aviso: ?string}
     */
    public static function relogioParaRateio(string $local, string $rotulo): array
    {
        $cfg = self::RELOGIO_POR_FOTO[$local] ?? null;
        $foto = $cfg ? Banco::leituraFoto($cfg['chave']) : null;
        if ($foto === null) {
            return ['valor' => null, 'aviso' => null];
        }
        $usado = self::relogioUsadoNoRateio($local);
        if ($usado !== null && $foto['valor'] <= $usado) {
            $quando = $foto['em'] ? ' (foto de ' . fmt_data_br($foto['em']) . ')' : '';
            return ['valor' => null, 'aviso' => "A última foto do relógio da {$rotulo} — "
                . fmt_num($foto['valor'], 2) . " kWh{$quando} — já entrou no rateio anterior. Peça uma foto nova."];
        }
        return ['valor' => $foto['valor'], 'aviso' => null];
    }

    /**
     * @param array $fotos lista de ['papel' => string, 'data' => base64, 'mimeType' => string]
     * @return array{ok:bool, valores?:array, erro?:string}
     */
    public static function ler(string $local, array $fotos): array
    {
        if (!$fotos) {
            return ['ok' => false, 'erro' => 'Envie ao menos uma foto para eu ler.'];
        }
        $chave = self::chaveGemini();
        if ($chave === null) {
            return ['ok' => false, 'erro' => 'Não há chave ativa do Gemini. Cadastre uma no painel admin para usar a leitura por foto.'];
        }

        $ehAgua = false;
        foreach ($fotos as $f) {
            if (in_array($f['papel'] ?? '', self::PAPEIS_AGUA, true)) {
                $ehAgua = true;
                break;
            }
        }
        // o papel de cada foto diz qual prompt usar: água e energia têm regras distintas
        if ($ehAgua) {
            $prompt = self::promptAgua($fotos);
        } else {
            $prompt = self::promptEnergia($fotos, $local === 'av_brasil');
        }

        $parts = [['text' => $prompt]];
        foreach ($fotos as $f) {
            $parts[] = ['inlineData' => ['mimeType' => $f['mimeType'], 'data' => $f['data']]];
        }

        $corpo = json_encode([
            'contents' => [['parts' => $parts]],
            'generationConfig' => ['responseMimeType' => 'application/json'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $r = Gemini::gerarJson($chave, $corpo);
        if (!$r['ok']) {
            return ['ok' => false, 'erro' => 'Falha na leitura por IA: ' . $r['erro']];
        }

        $texto = Gemini::textoDoResultado($r['data']);
        $json = self::parsearJson($texto);
        if ($json === null) {
            return ['ok' => false, 'erro' => 'A IA respondeu fora do formato esperado. Tente uma foto mais nítida.'];
        }
        return ['ok' => true, 'valores' => $json];
    }

    /**
     * Diz o que a foto mostra, para o admin não precisar descrever uma a uma.
     *
     * Devolve enum fechado: o modelo não pode inventar categoria nova, e quando
     * não tem certeza ele devolve 'outro' e perguntamos. Hidrômetro volta como
     * caso à parte porque as três casas têm medidor igual — ninguém, nem o
     * modelo, consegue saber se é o H1 ou o H2 só olhando o número.
     *
     * @return array{ok:bool, papel:?string, rotulo:?string, erro?:string}
     */
    public static function classificar(string $base64, string $mime): array
    {
        $chave = self::chaveGemini();
        if ($chave === null) {
            return ['ok' => false, 'papel' => null, 'rotulo' => null,
                'erro' => 'Não há chave ativa do Gemini. Cadastre uma no painel admin para usar a leitura por foto.'];
        }

        $prompt = "Analise a imagem e classifique o que ela mostra.\n"
            . "Contexto: são fotos de contas e medidores de um condomínio brasileiro.\n\n"
            . "Responda APENAS o JSON, sem markdown.\n\n"
            . 'Valores possiveis para "tipo": "fatura_agua" (fatura da CESAN/agua), '
            . '"h1" (hidrometro com etiqueta/adesivo 01 ou H1 visivel), '
            . '"h2" (hidrometro com etiqueta/adesivo 02 ou H2 visivel), '
            . '"h3" (hidrometro com etiqueta/adesivo 03 ou H3 visivel), '
            . '"hidrometro" (hidrometro sem etiqueta identificadora clara), '
            . '"fatura_energia" (fatura de luz/EDP), '
            . '"relogio" (relatorio interno, submedidor Nansen ou whatimetro digital), '
            . '"outro" (qualquer outra coisa).\n'
            . 'Use "outro" quando nao tiver certeza. Nao invente.';

        $corpo = json_encode([
            'contents' => [['parts' => [
                ['text' => $prompt],
                ['inlineData' => ['mimeType' => $mime, 'data' => $base64]],
            ]]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'tipo' => ['type' => 'STRING', 'enum' => ['fatura_agua', 'h1', 'h2', 'h3', 'hidrometro', 'fatura_energia', 'relogio', 'outro']],
                        'confianca' => ['type' => 'STRING', 'enum' => ['alta', 'media', 'baixa']],
                    ],
                ],
                'temperature' => 0,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $r = Gemini::gerarJson($chave, $corpo);
        if (!$r['ok']) {
            return ['ok' => false, 'papel' => null, 'rotulo' => null, 'erro' => $r['erro']];
        }
        $json = self::parsearJson(Gemini::textoDoResultado($r['data']));
        $tipo = (string) ($json['tipo'] ?? 'outro');
        $confianca = (string) ($json['confianca'] ?? 'baixa');

        $mapa = [
            'fatura_agua' => ['fatura', 'a fatura da CESAN'],
            'h1' => ['h1', 'o hidrômetro H1 (Casa 1)'],
            'h2' => ['h2', 'o hidrômetro H2 (Casa 2)'],
            'h3' => ['h3', 'o hidrômetro H3 (Casa 3)'],
            'fatura_energia' => ['fatura_energia', 'a fatura de energia'],
            'relogio' => ['interno', 'o relógio interno'],
            'hidrometro' => ['hidrometro', 'um hidrômetro'],
        ];

        // confiança baixa não vale a pena arriscar: perguntamos
        if ($confianca === 'baixa' || !isset($mapa[$tipo])) {
            return ['ok' => false, 'papel' => null, 'rotulo' => null];
        }
        if ($tipo === 'hidrometro') {
            return ['ok' => false, 'papel' => 'hidrometro', 'rotulo' => $mapa[$tipo][1]];
        }
        return ['ok' => true, 'papel' => $mapa[$tipo][0], 'rotulo' => $mapa[$tipo][1]];
    }

    /**
     * Extrai o primeiro objeto JSON válido da resposta.
     *
     * O modelo às vezes envolta em ```json ou escreve uma frase antes. Em vez de
     * falhar, procuramos o primeiro { ... } fechado — mesma tolerância do
     * admin-painel.js e do limparEParsearJson do Gampo.
     */
    public static function parsearJson(?string $texto): ?array
    {
        if ($texto === null) {
            return null;
        }
        $s = trim(str_replace(['```json', '```'], '', $texto));
        $inicio = strpos($s, '{');
        $fim = strrpos($s, '}');
        if ($inicio === false || $fim === false || $fim <= $inicio) {
            return null;
        }
        $json = json_decode(substr($s, $inicio, $fim - $inicio + 1), true);
        return is_array($json) ? $json : null;
    }

    public static function promptAgua(array $fotos): string
    {
        $prompt = "Você é um especialista em OCR e extração de dados estruturados. Sua tarefa é processar imagens de faturas de água (CESAN) e fotos de hidrômetros para fins de gestão financeira.

### REGRAS RIGOROSAS DE EXTRAÇÃO:

1. **Fatura CESAN:**
   - **Valor Total:** Localize o valor no campo \"TOTAL A PAGAR\" ou \"VALOR DA FATURA\" (ex: 560.18). Salve em 'valor_total'.
   - **Leitura Atual (Mês Vigente):** Localize a **LEITURA ATUAL** no quadro de dados da fatura. É SEMPRE o número MAIOR da leitura do mês vigente (ex: 1806). Salve em 'leitura_atual'.
   - **Leitura Anterior (Mês Passado):** Localize a **LEITURA ANTERIOR** no quadro de dados da fatura. É o número MENOR (ex: 1772). Salve em 'leitura_anterior'.
   - **Data da Leitura / Apresentação:** Localize a data da leitura atual ou emissão da fatura (formatada como 'AAAA-MM-DD', ex: '2026-10-04'). Salve em 'data_leitura'.

2. **Hidrômetros (Fotos dos Medidores Individuais):**
   - Identifique APENAS os dígitos nos roletes de cor **PRETA**. Ignore totalmente os dígitos vermelhos/decimais.
   - **Remoção de Zeros Iniciais:** Se a sequência de roletes pretos for '0490', retorne '490'. Se for '0189', retorne '189'. Se for '0284', retorne '284'.
   - **NÃO INCLUA DÍGITOS VERMELHOS:** Os valores dos hidrômetros residenciais possuem de 3 a 4 dígitos inteiros no total. NUNCA adicione um dígito extra vindo de rolete vermelho (ex: NUNCA retorne '4910' se os pretos forem '0490' nem '1189' se forem '0189').

### INFORMAÇÃO DOS ARQUIVOS:
Você receberá as imagens na seguinte ordem exata:";

        $n = 1;
        foreach ($fotos as $f) {
            $papel = $f['papel'] ?? 'foto';
            $rotulo = [
                'fatura' => 'Fatura',
                'h1' => 'Hidrômetro com identificador \"h1\"',
                'h2' => 'Hidrômetro com identificador \"h2\"',
                'h3' => 'Hidrômetro com identificador \"h3\"',
            ][$papel] ?? ('Imagem ' . $papel);
            $prompt .= "\n- Imagem " . $n++ . ': ' . $rotulo;
        }

        return $prompt . '

### FORMATO DE SAÍDA:
Retorne os dados estritamente em formato JSON para integração de sistema:

{
  "fatura": {
    "valor_total": 560.18,
    "leitura_atual": 1806,
    "leitura_anterior": 1772,
    "data_leitura": "2026-10-04"
  },
  "medidores_individuais": [
    {
      "identificador": "h1",
      "leitura_processada": "490"
    }
  ]
}

### RESTRIÇÕES:
- Não adicione comentários, explicações ou texto introdutório fora do JSON.
- Se a imagem estiver ilegível, retorne "null" no campo correspondente.';
    }

    /** Prompt da energia — a ordem e o texto mudam conforme o medidor. */
    private static function promptEnergia(array $fotos, bool $avBrasil): string
    {
        $prompt = "Você é um robô de leitura de medidores. Analise IMAGEM por IMAGEM estritamente nas regras abaixo e retorne APENAS um JSON limpo:\n\n";
        $formato = [];
        $n = 1;

        foreach ($fotos as $f) {
            $papel = $f['papel'] ?? '';
            if ($papel === 'fatura' || $papel === 'fatura_energia') {
                $prompt .= "IMAGEM {$n} [Fatura EDP]: Extraia 'faturaTotal' (valor total a pagar). Extraia o consumo total em kWh faturado e salve em 'leituraPadrao'. Verifique o endereço/UC da fatura: se for Avenida Brasil/Praia de Carapebus salve 'av_brasil' em 'local'; se for Rua Inhambu/Novo Horizonte salve 'rua_inhambu' em 'local'. Extraia a data da leitura atual ('AAAA-MM-DD') e salve em 'dataLeitura'.\n";
                $formato['faturaTotal'] = 200.50;
                $formato['leituraPadrao'] = 2500;
                $formato['local'] = $avBrasil ? 'av_brasil' : 'rua_inhambu';
                $formato['dataLeitura'] = '2026-10-05';
            } elseif ($papel === 'interno') {
                $prompt .= "IMAGEM {$n} [Relógio/Submedidor Interno]: Extraia o número da leitura em kWh exibido no DISPLAY DIGITAL LCD (ex: 4651, 4815, 5019) ou o valor 'Total Ele' no app Tuya. NUNCA use o número de série/código de barras gravado no plástico do medidor (ex: 48299884). Salve o número do display em 'leituraInterno'.\n";
                $formato['leituraInterno'] = 3500;
            }
            $n++;
        }

        return $prompt . "\nFORNEÇA EXATAMENTE O JSON ABAIXO (sem markdown e sem texto antes/depois) com os valores reais encontrados:\n"
            . json_encode($formato, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Corrige a resposta do OCR com base na leitura anterior do banco.
     *
     * É a continuidade que o painel faz: se a IA devolveu a leitura anterior como
     * se fosse a atual (números invertidos), trocamos. Isso evita um mês com
     * consumo negativo — o que a view SQL protege com GREATEST(0, ...), mascarando
     * o erro em vez de avisando.
     *
     * @return array{valores:array, avisos:array}
     */
    public static function corrigirAgua(array $valores): array
    {
        $avisos = [];
        $ant = Banco::ultimaAguaBruta();
        if (!$ant || empty($ant['leitura_cesan'])) {
            return ['valores' => $valores, 'avisos' => $avisos];
        }
        $dbAtual = (float) $ant['leitura_cesan'];

        $cesanAtual = (float) ($valores['fatura']['leitura_atual'] ?? $valores['fatura']['leitura_fatura'] ?? 0);
        $cesanAnterior = (float) ($valores['fatura']['leitura_anterior'] ?? 0);

        // números invertidos: o "atual" veio menor ou igual ao do banco
        if ($cesanAtual > 0 && $cesanAtual <= $dbAtual && $cesanAnterior > $dbAtual) {
            $valores['fatura']['leitura_atual'] = $cesanAnterior;
            $valores['fatura']['leitura_anterior'] = $cesanAtual;
            $avisos[] = 'Ajustei a leitura da CESAN: a fatura veio com atual e anterior invertidos.';
        } elseif ($cesanAnterior > 0 && $cesanAnterior != $dbAtual) {
            $avisos[] = 'A leitura anterior da fatura (' . fmt_num($cesanAnterior, 0)
                . ') difere da que está no banco (' . fmt_num($dbAtual, 0) . ').';
        }

        // limpa cada hidrômetro com a referência da coluna correspondente
        $mapa = ['h1' => 'leitura_h1', 'h2' => 'leitura_h2', 'h3' => 'leitura_h3'];
        foreach ($valores['medidores_individuais'] ?? [] as $i => $med) {
            $id = strtolower((string) ($med['identificador'] ?? ''));
            if (!isset($mapa[$id]) || !isset($med['leitura_processada'])) {
                continue;
            }
            $limpo = sanitizar_hidro($med['leitura_processada'], $ant[$mapa[$id]] ?? null);
            if ($limpo === '') {
                unset($valores['medidores_individuais'][$i]);
                continue;
            }
            $valores['medidores_individuais'][$i]['leitura_processada'] = $limpo;
        }

        return ['valores' => $valores, 'avisos' => $avisos];
    }

    /**
     * Monta a leitura de água pronta para virar pendência.
     *
     * @return array{ok:bool, payload?:array, avisos?:array, erro?:string}
     */
    public static function montarAgua(array $fotos, ?string $data = null): array
    {
        $r = self::ler('agua', $fotos);
        if (!$r['ok']) {
            return ['ok' => false, 'erro' => $r['erro']];
        }
        $v = $r['valores'];
        $corrigido = self::corrigirAgua($v);
        $v = $corrigido['valores'];

        $fatura = $v['fatura'] ?? [];
        $data = fmt_data_iso($fatura['data_leitura'] ?? null) ?? ($data ?: fmt_hoje());

        $payload = [
            'data' => $data,
            'cesan' => (float) ($fatura['leitura_atual'] ?? $fatura['leitura_fatura'] ?? 0),
            'h1' => 0,
            'h2' => 0,
            'h3' => 0,
            'fatura' => (float) ($fatura['valor_total'] ?? 0),
            'origem' => 'foto',
            'avisos_ocr' => $corrigido['avisos'],
        ];

        $faltando = [];
        foreach ($v['medidores_individuais'] ?? [] as $med) {
            $id = strtolower((string) ($med['identificador'] ?? ''));
            if (!in_array($id, ['h1', 'h2', 'h3'], true)) {
                continue;
            }
            $valor = sanitizar_hidro($med['leitura_processada'] ?? '', null);
            if ($valor === '' || (float) $valor <= 0) {
                $faltando[] = strtoupper($id);
                continue;
            }
            $payload[$id] = (float) $valor;
        }

        if ($payload['cesan'] <= 0) {
            return ['ok' => false, 'erro' => 'Não consegui ler a leitura da CESAN na fatura. Foto mais nítida, por favor.'];
        }
        foreach ($faltando as $f) {
            $payload[strtolower($f)] = null;
        }
        if ($faltando) {
            $payload['avisos_ocr'][] = 'Não consegui ler o hidrômetro ' . implode(' e ', $faltando)
                . '. Confira na mensagem e ajuste digitando o número, ou reenvie a foto.';
        }

        return ['ok' => true, 'payload' => $payload, 'avisos' => $corrigido['avisos']];
    }

    /**
     * Monta a leitura de energia pronta para virar pendência.
     *
     * @param bool $travarLocal o remetente só tem um imóvel (a Loja 02 é só da Av.
     *   Brasil): o local não muda pelo que a IA achar do endereço — se ela
     *   discordar, vira aviso para o admin conferir.
     */
    public static function montarEnergia(string $local, array $fotos, ?string $data = null, bool $travarLocal = false): array
    {
        $local = $local === 'av_brasil' ? 'av_brasil' : 'rua_inhambu';
        $r = self::ler($local, $fotos);
        if (!$r['ok']) {
            return ['ok' => false, 'erro' => $r['erro']];
        }
        $v = $r['valores'];
        $avisos = [];

        // Se a fatura EDP indicou o local exato (Av. Brasil vs Rua Inhambu), respeita
        $localLido = $v['local'] ?? null;
        if (in_array($localLido, ['av_brasil', 'rua_inhambu'], true) && $localLido !== $local) {
            if ($travarLocal) {
                $avisos[] = 'O endereço da fatura parece ser da ' . energia_config($localLido)['rotulo']
                    . ', mas quem enviou só tem a ' . energia_config($local)['rotulo'] . '. Confira se é a conta certa.';
            } else {
                $local = $localLido;
            }
        }
        if (empty($data) && !empty($v['dataLeitura'])) {
            $dataIso = fmt_data_iso($v['dataLeitura']);
            if ($dataIso) $data = $dataIso;
        }

        $ant = Banco::ultimaEnergiaBruta($local);
        $payload = [
            'data' => $data ?: fmt_hoje(),
            'local' => $local,
            'padrao' => (float) ($v['leituraPadrao'] ?? 0),
            'interno' => (float) ($v['leituraInterno'] ?? 0),
            'interno2' => null,
            'fatura' => (float) ($v['faturaTotal'] ?? 0),
            'ref1' => $ant ? (float) $ant['leitura_interno'] : null,
            'ref2' => null,
            'origem' => 'foto',
            'avisos_ocr' => [],
        ];

        if ($local === 'av_brasil') {
            // na Av. Brasil o medidor interno é o Tuya digital (Casa 03 - Graça)
            if ($payload['interno'] <= 0) {
                $tuya = Banco::ultimoTuya($payload['data']);
                if (!$tuya) {
                    Pendencia::executarTuya([]);
                    $tuya = Banco::ultimoTuya($payload['data']);
                }
                if ($tuya) {
                    $payload['interno'] = (float) $tuya['valor_extraido'];
                }
            }

            // O watímetro da Loja 02 (Fernando) chega por foto — desde que seja deste mês
            $watLoja = self::relogioParaRateio('av_brasil', 'Loja 02');
            $payload['interno2'] = $watLoja['valor'];
            if ($watLoja['aviso']) {
                $payload['avisos_ocr'][] = $watLoja['aviso'];
            }

            $payload['ref1'] = $ant ? (float) ($ant['leitura_interno_anterior_ref'] ?? $ant['leitura_interno'] ?? 0) : null;
            if (empty($payload['interno2'])) {
                $payload['avisos_ocr'][] = 'Não li o relógio da Loja 02 — o valor dela sai por diferença do total.';
            }
        } elseif ($local === 'rua_inhambu') {
            if ($payload['interno'] <= 0) {
                $watMirian = self::relogioParaRateio('rua_inhambu', 'Casa 2');
                if ($watMirian['valor'] !== null) {
                    $payload['interno'] = $watMirian['valor'];
                }
                if ($watMirian['aviso']) {
                    $payload['avisos_ocr'][] = $watMirian['aviso'];
                }
            }
        }

        if ($payload['padrao'] <= 0) {
            return ['ok' => false, 'erro' => 'Não consegui ler o consumo total da fatura de energia. Foto mais nítida, por favor.'];
        }
        return ['ok' => true, 'payload' => $payload, 'avisos' => $avisos];
    }
}
