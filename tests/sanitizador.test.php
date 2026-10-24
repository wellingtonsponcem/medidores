<?php
/**
 * Limpeza de números.
 *
 * O sanitizeHidro original vive no admin-painel.js e já salvou o rateiro várias
 * vezes: o Gemini insiste em devolver o dígito vermelho (decimal) colado no
 * fim, ou repetir o primeiro dígito. Traduzimos para cá sem mudar a regra, e
 * travamos aqui para que um "limpar" no futuro não reintroduza o erro.
 */

require __DIR__ . '/_apoio/ambiente.php';

// zeros à esquerda: o rolete mostra "0490", o valor é 490
igual(sanitizar_hidro('0490'), '490', 'remove zero à esquerda');
igual(sanitizar_hidro('0189'), '189', 'remove zero à esquerda');
igual(sanitizar_hidro('0000'), '0', 'zero vira zero, não string vazia');
igual(sanitizar_hidro('0284'), '284', 'remove zero à esquerda em três dígitos');

// dígito vermelho colado no fim.
//
// O comentário no admin-painel.js diz "4910" -> "490", mas o CÓDIGO original
// devolve "491" — ele só derruba o último dígito, e 491 fica dentro da margem
// de 150 da leitura anterior. Testamos o comportamento real, não o comentário.
// O caso que devolve 490 mesmo é "4901": preto 490 + vermelho 1.
igual(sanitizar_hidro('4910', 471), '491', 'derruba só o último dígito (o comentário do painel diz 490, mas o código dá 491)');
igual(sanitizar_hidro('4901', 471), '490', 'preto 490 + vermelho 1 dá 490');
// dígito repetido no começo: pretos "0189", a IA devolve "1189", anterior 183
igual(sanitizar_hidro('1189', 183), '189', 'derruba o dígito duplicado do começo');

// sem referência não há como adivinhar o que é lixo — devolve o valor limpo
igual(sanitizar_hidro('4910'), '4910', 'sem anterior não arrisca cortar dígito');

// valores que já estão certos passam reto
igual(sanitizar_hidro('189', 183), '189', 'leitura coerente passa sem tocar');
igual(sanitizar_hidro(490, 471), '490', 'aceita número além de string');
igual(sanitizar_hidro(null), '', 'nulo vira vazio');
igual(sanitizar_hidro(''), '', 'string vazia vira vazio');

// Propriedade: com referência, o resultado nunca fica abaixo do anterior,
// porque leitura de hidrômetro não anda para trás. Se ficar, é sinal de que a
// sanitização cortou um dígito que não devia.
for ($i = 0; $i < 300; $i++) {
    $anterior = mt_rand(50, 900);
    $leitura = (string) ($anterior + mt_rand(0, 60));
    $ruido = mt_rand(0, 3) === 0
        ? $leitura . mt_rand(0, 9)       // dígito vermelho no fim
        : $leitura . $leitura[0];        // dígito repetido no começo
    $resultado = sanitizar_hidro($ruido, $anterior);
    if (!is_numeric($resultado) || (float) $resultado < $anterior) {
        ok(false, "propriedade: leitura sanitizada nunca fica abaixo da anterior (iteração {$i}: {$ruido} com anterior {$anterior} -> {$resultado})");
        break;
    }
}
ok(true, 'propriedade: leitura sanitizada nunca fica abaixo da anterior (300 leituras ruidosas)');

// --- números digitados ou vindos do Gemini ---
aproximado((float) sanitizar_numero('560.18'), 560.18, 'decimal americano');
aproximado((float) sanitizar_numero('560,18'), 560.18, 'decimal brasileiro');
aproximado((float) sanitizar_numero('1.806'), 1806.0, 'milhar brasileiro');
aproximado((float) sanitizar_numero('1,806'), 1.806, 'vírgula sozinha é decimal');
aproximado((float) sanitizar_numero('1.806,50'), 1806.50, 'milhar + decimal');
aproximado((float) sanitizar_numero('1,806.50'), 1806.50, 'formato americano com milhar');
aproximado((float) sanitizar_numero('R$ 560,18'), 560.18, 'ignora símbolo de moeda');
igual(sanitizar_numero(''), null, 'vazio devolve null');
igual(sanitizar_numero(null), null, 'nulo devolve null');
igual(sanitizar_numero('abc'), null, 'texto sem número devolve null');

// "1.806" com ponto e três dígitos é milhar brasileiro; "560.18" com dois é decimal.
// A ambiguidade existe e resolvemos a favor do português, que é o idioma de quem digita.
aproximado((float) sanitizar_numero('1.806.500'), 1806500.0, 'vários milhares com ponto');

// Propriedade: o que sai é sempre um número finito, nunca NaN nem INF.
for ($i = 0; $i < 100; $i++) {
    $entrada = (mt_rand(0, 1) ? '' : 'R$ ') . mt_rand(0, 999999) . (mt_rand(0, 1) ? ',' : '.') . mt_rand(0, 99);
    $resultado = sanitizar_numero($entrada);
    if (!is_numeric($resultado) || !is_finite((float) $resultado)) {
        ok(false, "propriedade: número digitado sempre vira número finito ({$entrada})");
        break;
    }
}
ok(true, 'propriedade: número digitado sempre vira número finito (100 formatos)');

encerrarTeste();
