<?php
/**
 * Regras de rateio — puras, sem banco e sem HTTP.
 *
 * Estas funções são a tradução EXATA da prévia do admin-painel.js
 * (refreshAguaPreview / getEnergyInsertPayload). O bot não pode ter conta
 * própria: se ele calcular diferente do painel web, o morador recebe um valor
 * e o administrador vê outro. Por isso a matemática mora aqui, num lugar só,
 * e o painel web continua sendo a referência visual.
 *
 * A view SQL vw_agua_rateio / vw_energia_rateio faz o mesmo cálculo no banco.
 * Estas funções servem para pré-visualizar uma leitura ANTES de gravar — ou
 * seja, para o bot mostrar o rateio na pendência de confirmação.
 */

/** Rótulos por medidor de energia (port do ENERGY_METER_CONFIG do admin-painel.js). */
function energia_config(string $local): array
{
    $base = [
        'rua_inhambu' => [
            'local' => 'rua_inhambu',
            'rotulo' => 'Rua Inhambu',
            'interno_rotulo' => 'Casa 2',
            'externo_rotulo' => 'Casa 1',
            'usa_segundo_medidor' => false,
            'usa_ref_anterior' => false,
            // iluminação pública (e multa/juros) é da conta, não do consumo:
            // divide igual entre as duas casas; o resto vai por kWh
            'divide_fixo' => true,
        ],
        'av_brasil' => [
            'local' => 'av_brasil',
            'rotulo' => 'Av. Brasil',
            'interno_rotulo' => 'Casa 03',
            'externo_rotulo' => 'Loja 02',
            'usa_segundo_medidor' => true,
            'usa_ref_anterior' => true,
            'divide_fixo' => false,
        ],
    ];
    return $base[$local] ?? $base['rua_inhambu'];
}

/** Aceita os apelidos usados na conversa e no painel. */
function energia_local_normalizado($bruto): string
{
    $s = strtolower(trim((string) ($bruto ?? '')));
    $s = str_replace(['_', '-', ' '], '', $s);
    if (strpos($s, 'brasil') !== false || $s === 'av' || $s === 'carapebus' || $s === 'loja') {
        return 'av_brasil';
    }
    return 'rua_inhambu';
}

/**
 * Rateio de água a partir de uma leitura nova e a anterior já gravada.
 *
 * @param array $atual  cesan, h1, h2, h3, fatura
 * @param array $anterior leitura do mês passado (mesmas chaves)
 * @return array consumos em m³ e valores em R$
 */
function rateio_agua(array $atual, array $anterior): array
{
    $cesan = (float) ($atual['cesan'] ?? 0);
    $h1 = (float) ($atual['h1'] ?? 0);
    $h2 = (float) ($atual['h2'] ?? 0);
    $h3 = (float) ($atual['h3'] ?? 0);
    $fatura = (float) ($atual['fatura'] ?? 0);

    $prevCesan = (float) ($anterior['cesan'] ?? 0);
    $prevH1 = (float) ($anterior['h1'] ?? 0);
    $prevH2 = (float) ($anterior['h2'] ?? 0);
    $prevH3 = (float) ($anterior['h3'] ?? 0);

    $consumoCesan = max(0, $cesan - $prevCesan);
    $brutoH1 = max(0, $h1 - $prevH1);
    $brutoH2 = max(0, $h2 - $prevH2);
    $brutoH3 = max(0, $h3 - $prevH3);

    // O H1 mede Casa 1 + Casa 2 em conjunto; a Casa 1 é o que sobra depois do H2.
    $consumoH1 = max(0, $brutoH1 - $brutoH2);
    $consumoH2 = $brutoH2;
    $consumoH3 = $brutoH3;

    $consumoMedidores = $consumoH1 + $consumoH2 + $consumoH3;
    $consumoServico = max(0, $consumoCesan - $consumoMedidores);

    // sempre float: o rateio é dinheiro, e alternar entre int 0 e float 0
    // faz o consumidor precisar de conversão em cada comparação
    $vpm3 = $consumoCesan > 0 ? $fatura / $consumoCesan : 0.0;

    return [
        'consumo_cesan' => (float) $consumoCesan,
        'consumo_h1' => (float) $consumoH1,
        'consumo_h2' => (float) $consumoH2,
        'consumo_h3' => (float) $consumoH3,
        'consumo_servico' => (float) $consumoServico,
        'bruto_h1' => (float) $brutoH1,
        'bruto_h2' => (float) $brutoH2,
        'bruto_h3' => (float) $brutoH3,
        'valor_por_m3' => (float) $vpm3,
        'valor_h1' => (float) ($consumoH1 * $vpm3),
        'valor_h2' => (float) ($consumoH2 * $vpm3),
        'valor_h3' => (float) ($consumoH3 * $vpm3),
        'valor_servico' => (float) ($consumoServico * $vpm3),
        'valor_fatura_total' => $fatura,
    ];
}

/**
 * Rateio de energia. O que sobra depois do submedidor interno vira o externo —
 * mesma regra da view, que é assim porque o que a concessionária fatura é o
 * total, e não a soma dos dois relógios.
 *
 * Na Rua Inhambu, a parte fixa da conta (iluminação pública, multa e juros —
 * 'fixo') é dividida igualmente entre as duas casas, e só o restante vira preço
 * do kWh. Sem 'fixo' (contas antigas, Av. Brasil), tudo vai por kWh como antes.
 *
 * @param array $atual padrao (consumo total da fatura), interno, interno2 (null quando não medido), fatura, fixo
 * @param array $anterior interno_ref, interno2_ref (null quando não há referência gravada)
 */
function rateio_energia(array $atual, array $anterior, string $local = 'rua_inhambu'): array
{
    $cfg = energia_config($local);

    $totalConsumo = (float) ($atual['padrao'] ?? 0);
    $leituraAtual = (float) ($atual['interno'] ?? 0);
    $leituraAnterior = (float) ($anterior['interno_ref'] ?? 0);
    $leituraAtual2 = isset($atual['interno2']) && $atual['interno2'] !== null ? (float) $atual['interno2'] : null;
    $leituraAnterior2 = isset($anterior['interno2_ref']) && $anterior['interno2_ref'] !== null ? (float) $anterior['interno2_ref'] : null;
    $fatura = (float) ($atual['fatura'] ?? 0);
    $fixo = !empty($cfg['divide_fixo']) ? min($fatura, max(0.0, (float) ($atual['fixo'] ?? 0))) : 0.0;
    $fixoPorUnidade = $fixo / 2;

    $valorKwh = $totalConsumo > 0 ? ($fatura - $fixo) / $totalConsumo : 0.0;
    $consumoInterno = max(0, $leituraAtual - $leituraAnterior);

    $medido = $leituraAtual2 !== null && $leituraAnterior2 !== null;
    if ($medido) {
        $consumoExterno = max(0, $leituraAtual2 - $leituraAnterior2);
    } else {
        // Sem o segundo relógio medido, o externo é a diferença para o total faturado.
        $consumoExterno = max(0, $totalConsumo - $consumoInterno);
    }

    // mesmo raciocínio do rateio_agua: tipos estáveis, sempre float
    return [
        'local' => $cfg['local'],
        'rotulo' => $cfg['rotulo'],
        'interno_rotulo' => $cfg['interno_rotulo'],
        'externo_rotulo' => $cfg['externo_rotulo'],
        'medido' => $medido,
        'consumo_total' => (float) $totalConsumo,
        'consumo_interno' => (float) $consumoInterno,
        'consumo_externo' => (float) $consumoExterno,
        // diferença entre o total faturado e a soma dos dois relógios (luz comum/perda)
        'diferenca' => (float) ($totalConsumo - ($consumoInterno + $consumoExterno)),
        'valor_kwh' => (float) $valorKwh,
        'valor_fixo' => (float) $fixo,
        'fixo_por_unidade' => (float) $fixoPorUnidade,
        'valor_interno' => (float) ($consumoInterno * $valorKwh + $fixoPorUnidade),
        'valor_externo' => (float) ($consumoExterno * $valorKwh + $fixoPorUnidade),
        'valor_fatura_total' => $fatura,
    ];
}

/**
 * Lista de avisos sobre uma leitura de água proposta.
 *
 * Existe porque a maior fonte de erro do lançamento é leitura anterior fora de
 * ordem. O bot avisa ANTES de gravar, do mesmo jeito que o painel mostra a
 * prévia — mas aqui em texto, para quem está no celular no meio da rua.
 */
function conferir_agua(array $atual, array $anterior, array $rateio): array
{
    $avisos = [];
    $cesan = (float) ($atual['cesan'] ?? 0);
    $prevCesan = (float) ($anterior['cesan'] ?? 0);

    if ($cesan <= 0) {
        $avisos[] = 'Leitura da CESAN zerada ou vazia.';
    }
    if ($prevCesan > 0 && $cesan < $prevCesan) {
        $avisos[] = 'A leitura da CESAN (' . fmt_num($cesan, 0) . ') é menor que a anterior (' . fmt_num($prevCesan, 0) . ').';
    }
    foreach ([['h1', 'H1'], ['h2', 'H2'], ['h3', 'H3']] as $par) {
        $atualV = (float) ($atual[$par[0]] ?? 0);
        $prevV = (float) ($anterior[$par[0]] ?? 0);
        if ($prevV > 0 && $atualV < $prevV) {
            $avisos[] = 'A leitura do ' . $par[1] . ' (' . fmt_num($atualV, 0) . ') é menor que a anterior (' . fmt_num($prevV, 0) . ').';
        }
    }
    if ((float) ($atual['fatura'] ?? 0) <= 0) {
        $avisos[] = 'Valor da fatura zerado — o rateio vai sair em R$ 0,00.';
    }
    if ((float) ($rateio['consumo_cesan'] ?? 0) > 0) {
        $soma = $rateio['consumo_h1'] + $rateio['consumo_h2'] + $rateio['consumo_h3'];
        if ($soma > $rateio['consumo_cesan'] + 0.01) {
            $avisos[] = 'A soma dos hidrômetros (' . fmt_num($soma, 1) . ' m³) passa do consumo da CESAN (' . fmt_num($rateio['consumo_cesan'], 1) . ' m³).';
        }
    }
    return $avisos;
}

/** Mesma ideia para energia: sobra de consumo interno negativo é sinal de troca de leitura. */
function conferir_energia(array $atual, array $anterior, array $rateio): array
{
    $avisos = [];
    if ((float) ($atual['padrao'] ?? 0) <= 0) {
        $avisos[] = 'Consumo total da fatura zerado — confira a leitura padrão.';
    }
    if ((float) ($atual['fatura'] ?? 0) <= 0) {
        $avisos[] = 'Valor da fatura zerado — o rateio vai sair em R$ 0,00.';
    }
    if (!empty(energia_config($rateio['local'] ?? 'rua_inhambu')['divide_fixo']) && (float) ($atual['fixo'] ?? 0) <= 0) {
        $avisos[] = 'Não li a iluminação pública da fatura — sem ela, a conta toda é dividida pelo consumo. '
            . 'Para corrigir, digite por exemplo: `energia padrao 282 fatura 332,93 iluminacao 34,98`.';
    }
    if ($rateio['medido'] && $rateio['consumo_externo'] <= 0) {
        $avisos[] = 'Consumo do ' . $rateio['externo_rotulo'] . ' ficou em zero — confira as leituras dos dois relógios.';
    }
    if (!$rateio['medido'] && abs($rateio['diferenca']) > 0.01) {
        $avisos[] = 'Sem o relógio do ' . $rateio['externo_rotulo'] . ' medido, o valor sai por diferença de ' . fmt_num($rateio['diferenca'], 2) . ' kWh.';
    }
    return $avisos;
}
