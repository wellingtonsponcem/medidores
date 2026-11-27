<?php
/**
 * OCR — a parte que mais pode estragar o rateio, porque o número vem de uma
 * foto e não de um campo digitado.
 *
 * O que cobrimos aqui é o que dá para cobrir sem chamar a API: a tolerância do
 * parse de JSON (o modelo devolve ```json e texto solto), as correções que
 * usam a leitura anterior como referência, e o fato de que SEM chave o bot
 * avisa em vez de fingir que leu. O que depende de rede fica de fora — não
 * vamos puxar a fatura do condomínio em teste.
 */

require __DIR__ . '/_apoio/ambiente.php';

$pdo = ambiente();
semearLeituraAnteriorAgua($pdo);

// ---- parsearJson: o modelo quase nunca devolve JSON limpo ----
igual(Ocr::parsearJson('{"a":1}'), ['a' => 1], 'JSON limpo');
igual(Ocr::parsearJson('```json{"a":1}```'), ['a' => 1], 'JSON dentro de crase');
igual(Ocr::parsearJson("Aqui está o resultado:\n```json\n{\"a\": 1}\n```\nEspero que ajude!"),
    ['a' => 1], 'JSON com frase antes e depois');
igual(Ocr::parsearJson('{"fatura":{"valor_total":560.18},"x":[1,2]}'),
    ['fatura' => ['valor_total' => 560.18], 'x' => [1, 2]], 'aninhamento e lista');
igual(Ocr::parsearJson(null), null, 'nulo devolve nulo');
igual(Ocr::parsearJson(''), null, 'vazio devolve nulo');
igual(Ocr::parsearJson('   '), null, 'só espaço devolve nulo');
igual(Ocr::parsearJson('não consegui ler a imagem'), null, 'frase sem JSON devolve nulo');
igual(Ocr::parsearJson('{json quebrado'), null, 'chave sem fechamento devolve nulo');
igual(Ocr::parsearJson('{}'), [], 'objeto vazio é array vazio, e não nulo');

// Propriedade: qualquer entrada, por mais estranha que venha da API, devolve
// array ou nulo — nunca lança. Um erro aqui derrubaria o webhook inteiro.
$entradas = [null, '', '{', '}', 'null', '[]', '"texto"', '123', str_repeat('{', 50),
    "\x00\x01\x02", '{"a":' . str_repeat('9', 500) . '}'];
foreach ($entradas as $i => $entrada) {
    $r = Ocr::parsearJson($entrada);
    if ($r !== null && !is_array($r)) {
        ok(false, "propriedade: parse devolve array ou nulo (entrada {$i})");
        break;
    }
}
ok(true, 'propriedade: parse devolve sempre array ou nulo, sem lançar (11 entradas)');

// ---- corrigirAgua: usa a leitura anterior do banco como régua ----
// Números invertidos: a fatura veio com atual 1772 e anterior 1806, mas o banco
// já tem 1806 — então o que a IA chamou de "anterior" é o atual mesmo.
$invertidos = Ocr::corrigirAgua(['fatura' => ['leitura_atual' => 1772, 'leitura_anterior' => 1806]]);
aproximado((float) $invertidos['valores']['fatura']['leitura_atual'], 1806.0, 'inverteu o atual para cima');
aproximado((float) $invertidos['valores']['fatura']['leitura_anterior'], 1772.0, 'e o anterior para baixo');
ok(count($invertidos['avisos']) === 1, 'avisou que inverteu');
ok(strpos($invertidos['avisos'][0], 'invertidos') !== false, 'o aviso diz que estavam invertidos');

// Anterior diferente do banco: avisa, mas não mexe em nada — quem julga é o
// administrador na hora de confirmar.
$diferente = Ocr::corrigirAgua(['fatura' => ['leitura_atual' => 1806, 'leitura_anterior' => 1700]]);
aproximado((float) $diferente['valores']['fatura']['leitura_atual'], 1806.0, 'não mexe no atual');
aproximado((float) $diferente['valores']['fatura']['leitura_anterior'], 1700.0, 'não mexe no anterior');
ok(strpos($diferente['avisos'][0], '1.700') !== false && strpos($diferente['avisos'][0], '1.772') !== false,
    'o aviso mostra os dois números para comparar');

// Leitura coerente: nenhum aviso, nada alterado.
$ok = Ocr::corrigirAgua(['fatura' => ['leitura_atual' => 1806, 'leitura_anterior' => 1772]]);
igual($ok['avisos'], [], 'leitura coerente não gera aviso');
aproximado((float) $ok['valores']['fatura']['leitura_atual'], 1806.0, 'leitura coerente passa intacta');

// ---- hidrômetros: a IA lê rolete vermelho, e a régua do banco limpa ----
// Os valores têm que estar próximos da leitura anterior (H1 300, H2 183,
// H3 284): a correção só age quando o candidato está a menos de 150 da régua,
// porque acima disso não é dígito vermelho, é um salto de verdade.
$comRuido = Ocr::corrigirAgua([
    'fatura' => ['leitura_atual' => 1806, 'leitura_anterior' => 1772],
    'medidores_individuais' => [
        ['identificador' => 'h1', 'leitura_processada' => '3107'], // pretos 310 + vermelho 7
        ['identificador' => 'h2', 'leitura_processada' => '1890'], // pretos 189 + vermelho 0
        ['identificador' => 'h3', 'leitura_processada' => '284'],
    ],
]);
$limpos = [];
foreach ($comRuido['valores']['medidores_individuais'] as $m) {
    $limpos[$m['identificador']] = $m['leitura_processada'];
}
igual($limpos['h1'], '310', 'h1: derruba o dígito vermelho do fim');
igual($limpos['h2'], '189', 'h2: derruba o dígito vermelho do fim');
igual($limpos['h3'], '284', 'h3: leitura limpa passa intacta');

// Salto grande demais não é dígito vermelho, é outra coisa — e aí o bot não
// adivinha. Deixa o número como veio e o admin confere na hora de confirmar.
$semRéguaPerto = Ocr::corrigirAgua([
    'fatura' => ['leitura_atual' => 1806, 'leitura_anterior' => 1772],
    'medidores_individuais' => [['identificador' => 'h1', 'leitura_processada' => '4907']],
]);
igual($semRéguaPerto['valores']['medidores_individuais'][0]['leitura_processada'], '4907',
    'salto de 190 acima da régua passa intacto, sem chutar');

// Identificador que não é hidrômetro é ignorado, e não derruba o resto.
$outro = Ocr::corrigirAgua([
    'fatura' => ['leitura_atual' => 1806, 'leitura_anterior' => 1772],
    'medidores_individuais' => [['identificador' => 'h4', 'leitura_processada' => '9999']],
]);
igual($outro['valores']['medidores_individuais'][0]['leitura_processada'], '9999',
    'identificador desconhecido não é alterado');

// Sem leitura anterior, não há régua — e então não se corrige nada, para não
// "corrigir" para um número inventado.
$pdo->tabelas['consumo_agua'] = [];
$sRégua = Ocr::corrigirAgua(['fatura' => ['leitura_atual' => 1772, 'leitura_anterior' => 1806]]);
igual($sRégua['avisos'], [], 'sem leitura anterior, não há o que avisar');
igual($sRégua['valores']['fatura']['leitura_atual'], 1772, 'sem régua, os valores passam como vieram');

// ---- prompt: precisa listar os arquivos na ordem que vieram ----
$p = Ocr::promptAgua([
    ['papel' => 'fatura'], ['papel' => 'h1'], ['papel' => 'h2'], ['papel' => 'h3'],
]);
ok(strpos($p, 'Imagem 1: Fatura') !== false, 'a imagem 1 é a fatura');
ok(strpos($p, 'Imagem 2: Hidrômetro') !== false, 'a imagem 2 é o hidrômetro');
ok(strpos($p, 'TOTAL A PAGAR') !== false, 'instrui a achar o total a pagar');
ok(strpos($p, 'NUNCA') !== false, 'insiste para não inventar dígito vermelho');
$ordem = [strpos($p, 'Imagem 1'), strpos($p, 'Imagem 2'), strpos($p, 'Imagem 3'), strpos($p, 'Imagem 4')];
ok($ordem[0] < $ordem[1] && $ordem[1] < $ordem[2] && $ordem[2] < $ordem[3], 'as imagens aparecem na ordem');

// ---- sem chave do Gemini, nada de fingir que leu ----
Http::$transporte = fn() => ['status' => 500, 'corpo' => 'não deveria ser chamado'];

$semChave = Ocr::ler('agua', [['base64' => 'AAAA', 'mime' => 'image/jpeg']]);
igual($semChave['ok'], false, 'sem chave, não tenta ler');
ok(strpos($semChave['erro'], 'chave ativa do Gemini') !== false, 'o erro diz que falta a chave');
ok(strpos($semChave['erro'], 'painel admin') !== false, 'e diz onde cadastrar');

$cls = Ocr::classificar('AAAA', 'image/jpeg');
igual($cls['ok'], false, 'classificar sem chave também falha');
igual($cls['papel'], null, 'sem chave, não devolve papel nenhum');
ok(strpos($cls['erro'], 'chave ativa do Gemini') !== false, 'e o mesmo aviso de chave');

// classificarAlbum sem chave devolve as fotos como vieram
$albumSemChave = Ocr::classificarAlbum([
    ['papel' => null, 'data' => 'AAAA', 'mimeType' => 'image/jpeg'],
    ['papel' => null, 'data' => 'BBBB', 'mimeType' => 'image/jpeg'],
]);
igual($albumSemChave[0]['papel'], null, 'album sem chave mantém papel nulo 1');
igual($albumSemChave[1]['papel'], null, 'album sem chave mantém papel nulo 2');

// ---- classificarAlbum com mock da IA ----
$pdo->tabelas['app_secrets']['GEMINI_API_KEY'] = ['name' => 'GEMINI_API_KEY', 'value' => 'chave-teste'];

// Mock da resposta do Gemini para classificação de água
Http::$transporte = function ($url = null, $opcoes = null) {
    $resposta = [
        'candidates' => [
            [
                'content' => [
                    'parts' => [
                        [
                            'text' => json_encode([
                                'tipo_geral' => 'agua',
                                'imagens' => [
                                    ['indice' => 1, 'papel' => 'fatura_cesan'],
                                    ['indice' => 2, 'papel' => 'h1'],
                                    ['indice' => 3, 'papel' => 'h2'],
                                    ['indice' => 4, 'papel' => 'h3'],
                                ],
                            ]),
                        ],
                    ],
                ],
            ],
        ],
    ];
    return ['status' => 200, 'corpo' => json_encode($resposta)];
};

$albumAgua = Ocr::classificarAlbum([
    ['papel' => null, 'data' => 'F1', 'mimeType' => 'image/jpeg'],
    ['papel' => null, 'data' => 'F2', 'mimeType' => 'image/jpeg'],
    ['papel' => null, 'data' => 'F3', 'mimeType' => 'image/jpeg'],
    ['papel' => null, 'data' => 'F4', 'mimeType' => 'image/jpeg'],
]);
igual($albumAgua[0]['papel'], 'fatura', 'album água: imagem 1 virou fatura');
igual($albumAgua[1]['papel'], 'h1', 'album água: imagem 2 virou h1');
igual($albumAgua[2]['papel'], 'h2', 'album água: imagem 3 virou h2');
igual($albumAgua[3]['papel'], 'h3', 'album água: imagem 4 virou h3');

// Preserva papel vindo de legenda prévia
$albumComLegenda = Ocr::classificarAlbum([
    ['papel' => 'fatura', 'data' => 'F1', 'mimeType' => 'image/jpeg'],
    ['papel' => null, 'data' => 'F2', 'mimeType' => 'image/jpeg'],
    ['papel' => null, 'data' => 'F3', 'mimeType' => 'image/jpeg'],
    ['papel' => null, 'data' => 'F4', 'mimeType' => 'image/jpeg'],
]);
igual($albumComLegenda[0]['papel'], 'fatura', 'preserva papel que veio de legenda');

// Hidrômetros genéricos são distribuídos sequencialmente para h1, h2, h3
Http::$transporte = function () {
    return ['status' => 200, 'corpo' => json_encode([
        'candidates' => [[
            'content' => ['parts' => [['text' => json_encode([
                'tipo_geral' => 'agua',
                'imagens' => [
                    ['indice' => 1, 'papel' => 'fatura'],
                    ['indice' => 2, 'papel' => 'hidrometro'],
                    ['indice' => 3, 'papel' => 'hidrometro'],
                    ['indice' => 4, 'papel' => 'hidrometro'],
                ],
            ])]]],
        ]],
    ])];
};

$albumGenerico = Ocr::classificarAlbum([
    ['papel' => null, 'data' => 'F1', 'mimeType' => 'image/jpeg'],
    ['papel' => null, 'data' => 'F2', 'mimeType' => 'image/jpeg'],
    ['papel' => null, 'data' => 'F3', 'mimeType' => 'image/jpeg'],
    ['papel' => null, 'data' => 'F4', 'mimeType' => 'image/jpeg'],
]);
igual($albumGenerico[0]['papel'], 'fatura', 'hidro genérico: imagem 1 fatura');
igual($albumGenerico[1]['papel'], 'h1', 'hidro genérico distribuído para h1');
igual($albumGenerico[2]['papel'], 'h2', 'hidro genérico distribuído para h2');
igual($albumGenerico[3]['papel'], 'h3', 'hidro genérico distribuído para h3');

// Mock para álbum de energia
Http::$transporte = function () {
    return ['status' => 200, 'corpo' => json_encode([
        'candidates' => [[
            'content' => ['parts' => [['text' => json_encode([
                'tipo_geral' => 'energia',
                'imagens' => [
                    ['indice' => 1, 'papel' => 'fatura_edp'],
                    ['indice' => 2, 'papel' => 'watimetro'],
                ],
            ])]]],
        ]],
    ])];
};

$albumEnergia = Ocr::classificarAlbum([
    ['papel' => null, 'data' => 'E1', 'mimeType' => 'image/jpeg'],
    ['papel' => null, 'data' => 'E2', 'mimeType' => 'image/jpeg'],
]);
igual($albumEnergia[0]['papel'], 'fatura_energia', 'album energia: imagem 1 fatura_energia');
igual($albumEnergia[1]['papel'], 'interno', 'album energia: imagem 2 interno');

encerrarTeste();
