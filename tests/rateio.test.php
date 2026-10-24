<?php
/**
 * Regras de rateio.
 *
 * Os testes aqui são dirigidos por propriedade, e não por exemplos soltos: em
 * vez de afirmar "este número dá aquele", afirmamos coisas que precisam ser
 * verdadeiras para QUALQUER leitura. É o jeito de pegar o caso que ninguém
 * pensou — e o HISTORICO.md do projeto pede exatamente isso.
 */

require __DIR__ . '/_apoio/ambiente.php';

// ---------------------------------------------------------------------------
// Água: a soma dos valores rateados tem que fechar com a fatura.
// O que sobra do consumo vira Serviço/Comum, então nada se perde no caminho.
// Os dados aleatórios respeitam a restrição física do condomínio: o H1 mede a
// Casa 1 e a Casa 2 juntas, então ele nunca pode medir menos que o H2.
// ---------------------------------------------------------------------------
for ($i = 0; $i < 200; $i++) {
    $prev = [
        'cesan' => mt_rand(100, 3000),
        'h1' => mt_rand(10, 900),
        'h2' => mt_rand(1, 899),
        'h3' => mt_rand(1, 900),
    ];
    $delta = mt_rand(0, 400);
    // reparte o consumo do mês entre os três medidores, com H1 >= H2 por construção
    $bH2 = mt_rand(0, $delta);
    $bH3 = mt_rand(0, $delta - $bH2);
    $bH1 = $bH2 + mt_rand(0, $delta - $bH2 - $bH3);

    $atual = [
        'cesan' => $prev['cesan'] + $delta,
        'h1' => $prev['h1'] + $bH1,
        'h2' => $prev['h2'] + $bH2,
        'h3' => $prev['h3'] + $bH3,
        'fatura' => mt_rand(1000, 90000) / 100,
    ];
    $r = rateio_agua($atual, $prev);

    // consumo zero é o caso legítimo em que ninguém deve nada: a tarifa vira 0
    // e a soma não fecha com a fatura, que é o resultado correto
    if ($r['consumo_cesan'] <= 0) {
        continue;
    }

    if ($r['consumo_h1'] < 0 || $r['consumo_h2'] < 0 || $r['consumo_h3'] < 0 || $r['consumo_servico'] < 0) {
        ok(false, "propriedade: nenhum consumo negativo (iteração {$i})");
        break;
    }
    if ($r['consumo_h1'] + $r['consumo_h2'] + $r['consumo_h3'] + $r['consumo_servico'] > $r['consumo_cesan'] + 0.0001) {
        ok(false, "propriedade: a soma dos consumos nunca passa do total da CESAN (iteração {$i})");
        break;
    }
    $soma = $r['valor_h1'] + $r['valor_h2'] + $r['valor_h3'] + $r['valor_servico'];
    if (abs($soma - $atual['fatura']) > 0.01) {
        ok(false, "propriedade: soma dos valores rateados fecha com a fatura (iteração {$i}: {$soma} vs {$atual['fatura']})");
        break;
    }
}
ok(true, 'propriedade: soma dos valores rateados fecha com a fatura (200 leituras aleatórias)');

// O contrário também é verdade: quando os medidores passam da concessionária,
// o rateio supercobra — e é exatamente isso que conferir_agua avisa antes do
// usuário confirmar. O teste existe para travar esse comportamento.
$r = rateio_agua(['cesan' => 100, 'h1' => 90, 'h2' => 50, 'h3' => 60, 'fatura' => 300],
    ['cesan' => 0, 'h1' => 0, 'h2' => 0, 'h3' => 0]);
aproximado($r['consumo_servico'], 0.0, 'serviço zerado quando os medidores passam da CESAN');
ok($r['valor_h1'] + $r['valor_h2'] + $r['valor_h3'] > $r['valor_fatura_total'],
    'medidor acima da fatura resulta em rateio maior que a fatura (o aviso cobre isso)');

// O H1 mede Casa 1 + Casa 2 juntas — então a Casa 1 é o resíduo do H1 menos o H2.
$r = rateio_agua(['cesan' => 1806, 'h1' => 490, 'h2' => 189, 'h3' => 284, 'fatura' => 560.18],
    ['cesan' => 1772, 'h1' => 460, 'h2' => 183, 'h3' => 275]);
aproximado($r['bruto_h1'], 30, 'H1 bruto é a diferença entre as leituras');
aproximado($r['bruto_h2'], 6, 'H2 bruto é a diferença entre as leituras');
aproximado($r['consumo_h1'], 24, 'Casa 1 = H1 bruto − H2 bruto (o H1 mede as duas casas)');
aproximado($r['consumo_h2'], 6, 'Casa 2 = H2 bruto');
aproximado($r['consumo_h3'], 9, 'Casa 3 = H3 bruto');
aproximado($r['consumo_cesan'], 34, 'Consumo CESAN = diferença das leituras');
aproximado($r['consumo_servico'], 0, 'Serviço = o que sobra do total (aqui os medidores passaram)');

// Se os hidrômetros medem mais que a CESAN, o serviço não fica negativo.
$r = rateio_agua(['cesan' => 100, 'h1' => 90, 'h2' => 50, 'h3' => 60, 'fatura' => 300],
    ['cesan' => 0, 'h1' => 0, 'h2' => 0, 'h3' => 0]);
aproximado($r['consumo_servico'], 0.0, 'consumo de serviço nunca fica negativo');

// Primeira leitura do condomínio: não há anterior, tudo é consumo do mês.
$r = rateio_agua(['cesan' => 34, 'h1' => 12, 'h2' => 8, 'h3' => 11, 'fatura' => 500], []);
aproximado($r['consumo_cesan'], 34, 'sem leitura anterior, o consumo é a leitura inteira');
aproximado($r['consumo_h1'], 4, 'sem anterior, Casa 1 = H1 − H2');

// Tarifa zero quando não há consumo: divisão por zero não pode virar INF/NaN.
$r = rateio_agua(['cesan' => 0, 'h1' => 0, 'h2' => 0, 'h3' => 0, 'fatura' => 0], []);
igual($r['valor_por_m3'], 0.0, 'sem consumo a tarifa é zero, não infinito');
igual($r['valor_h1'], 0.0, 'sem consumo o valor é zero');

// ---------------------------------------------------------------------------
// Energia: sem o segundo relógio medido, o externo é o resíduo do total.
// Propriedade: nesse caso os dois valores têm que fechar com a fatura.
// ---------------------------------------------------------------------------
for ($i = 0; $i < 200; $i++) {
    // total e interno na mesma unidade (kWh) — o consumo interno nunca pode
    // passar do total faturado, que é a condição física do imóvel
    $total = mt_rand(500, 80000) / 100;
    $interno = mt_rand(0, (int) round($total * 100)) / 100;
    $atual = ['padrao' => $total, 'interno' => $interno, 'interno2' => null, 'fatura' => mt_rand(1000, 90000) / 100];
    $r = rateio_energia($atual, ['interno_ref' => 0, 'interno2_ref' => null], 'rua_inhambu');

    $soma = $r['valor_interno'] + $r['valor_externo'];
    if (abs($soma - $atual['fatura']) > 0.02) {
        ok(false, "propriedade: sem segundo relógio, os dois valores fecham com a fatura (iteração {$i}: {$soma} vs {$atual['fatura']})");
        break;
    }
}
ok(true, 'propriedade: sem segundo relógio medido, valor interno + externo = fatura (200 leituras)');

// Av. Brasil: os dois relógios são medidos, então cada um paga o que consumiu.
$r = rateio_energia(
    ['padrao' => 2000, 'interno' => 5589.71, 'interno2' => 5100, 'fatura' => 2300],
    ['interno_ref' => 4620.86, 'interno2_ref' => 4980],
    'av_brasil'
);
igual($r['medido'], true, 'com os dois relógios, o rateio é medido');
aproximado($r['consumo_interno'], 968.85, 'consumo interno = diferença das leituras');
aproximado($r['consumo_externo'], 120.0, 'consumo externo = diferença das leituras');
igual($r['interno_rotulo'], 'Casa 03', 'na Av. Brasil o interno é a Casa 03');
igual($r['externo_rotulo'], 'Loja 02', 'na Av. Brasil o externo é a Loja 02');

// Rua Inhambu: só um relógio, o resto sai por diferença.
$r = rateio_energia(['padrao' => 1000, 'interno' => 300, 'interno2' => null, 'fatura' => 600],
    ['interno_ref' => 250, 'interno2_ref' => null], 'rua_inhambu');
igual($r['medido'], false, 'sem segundo relógio o rateio é residual');
aproximado($r['consumo_interno'], 50.0, 'consumo interno pela diferença');
aproximado($r['consumo_externo'], 950.0, 'consumo externo = total − interno');
aproximado($r['diferenca'], 0.0, 'com o externo por diferença, a soma bate com o total');

// Referência anterior vinda da coluna explícita tem prioridade sobre o LAG.
$r = rateio_energia(['padrao' => 1000, 'interno' => 500, 'interno2' => null, 'fatura' => 700],
    ['interno_ref' => 400, 'interno2_ref' => null], 'rua_inhambu');
aproximado($r['consumo_interno'], 100.0, 'usa a referência explícita quando existe');

// ---------------------------------------------------------------------------
// Conferência: os avisos que o usuário vê ANTES de confirmar.
// ---------------------------------------------------------------------------
$avisos = conferir_agua(['cesan' => 1700, 'h1' => 400, 'h2' => 180, 'h3' => 280, 'fatura' => 500],
    ['cesan' => 1772, 'h1' => 300, 'h2' => 183, 'h3' => 284],
    rateio_agua(['cesan' => 1700, 'h1' => 400, 'h2' => 180, 'h3' => 280, 'fatura' => 500],
        ['cesan' => 1772, 'h1' => 300, 'h2' => 183, 'h3' => 284]));
ok(count($avisos) > 0, 'leitura menor que a anterior gera aviso');

// Exemplo coerente: os três medidores somam menos que a CESAN, então há serviço
// e nenhum aviso. Os números anteriores viram 14+6+5 = 25 m³ sobre 34 m³ faturados.
$atualCoerente = ['cesan' => 1806, 'h1' => 480, 'h2' => 189, 'h3' => 280, 'fatura' => 560.18];
$anteriorCoerente = ['cesan' => 1772, 'h1' => 460, 'h2' => 183, 'h3' => 275];
$avisos = conferir_agua($atualCoerente, $anteriorCoerente, rateio_agua($atualCoerente, $anteriorCoerente));
igual(count($avisos), 0, 'leitura coerente não gera nenhum aviso');

$avisos = conferir_energia(['padrao' => 0, 'interno' => 0, 'interno2' => null, 'fatura' => 0],
    ['interno_ref' => 0], rateio_energia(['padrao' => 0, 'interno' => 0, 'interno2' => null, 'fatura' => 0], ['interno_ref' => 0]));
ok(count($avisos) >= 2, 'fatura e consumo zerados são ambos avisados');

// O normalizador de local aceita os apelidos que a gente digita no celular.
igual(energia_local_normalizado('av'), 'av_brasil', 'apelido curto da Av. Brasil');
igual(energia_local_normalizado('Av. Brasil'), 'av_brasil', 'nome completo');
igual(energia_local_normalizado('loja'), 'av_brasil', 'apelido pela unidade');
igual(energia_local_normalizado('inhambu'), 'rua_inhambu', 'nome da Rua Inhambu');
igual(energia_local_normalizado(null), 'rua_inhambu', 'sem indicação assume a Rua Inhambu');

encerrarTeste();
