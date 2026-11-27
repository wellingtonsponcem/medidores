<?php
/**
 * Parte fixa da conta de energia da Rua Inhambu.
 *
 * Regra combinada em 27/11/2026: iluminação pública (e multa/juros, se houver)
 * é da conta, não do consumo — divide igual entre Casa 1 e Casa 2. O resto da
 * fatura (energia, distribuição, encargos e os impostos, que são % do consumo)
 * vira preço do kWh. A Av. Brasil continua tudo por kWh.
 *
 * Números reais da fatura EDP de 09/2026: R$ 305,84, 247 kWh, CIP R$ 34,98;
 * Casa 2 consumiu 164 kWh e Casa 1 83 kWh.
 */

require __DIR__ . '/_apoio/ambiente.php';

function geminiDevolve(array $valores): void
{
    Http::$transporte = fn() => ['status' => 200, 'corpo' => json_encode([
        'candidates' => [['content' => ['parts' => [['text' => json_encode($valores)]]]]],
    ])];
}

$pdo = ambiente();

// ---- rateio ----
$set = ['padrao' => 247, 'interno' => 4815, 'interno2' => null, 'fatura' => 305.84, 'fixo' => 34.98];
$r = rateio_energia($set, ['interno_ref' => 4651, 'interno2_ref' => null], 'rua_inhambu');
aproximado($r['valor_kwh'], (305.84 - 34.98) / 247, 'kWh sai sem a iluminação pública');
aproximado($r['fixo_por_unidade'], 17.49, 'metade da iluminação para cada casa');
aproximado($r['valor_interno'], 164 * (270.86 / 247) + 17.49, 'Casa 2: consumo + metade da parte fixa');
aproximado($r['valor_externo'], 83 * (270.86 / 247) + 17.49, 'Casa 1: consumo + metade da parte fixa');
aproximado($r['valor_interno'] + $r['valor_externo'], 305.84, 'as duas somam a fatura inteira');
ok($r['valor_externo'] > 102.77, 'quem gasta menos paga um pouco mais que na regra antiga (R$ 102,77)');

$antiga = rateio_energia(['fixo' => 0] + $set, ['interno_ref' => 4651], 'rua_inhambu');
aproximado($antiga['valor_interno'], 203.0679, 'sem parte fixa, igual ao que está gravado hoje');

$av = rateio_energia(['padrao' => 1000, 'interno' => 500, 'interno2' => null, 'fatura' => 700, 'fixo' => 50],
    ['interno_ref' => 0], 'av_brasil');
aproximado($av['valor_kwh'], 0.7, 'Av. Brasil ignora a parte fixa');
igual($av['valor_fixo'], 0.0, 'Av. Brasil sem parte fixa');

$absurdo = rateio_energia(['fixo' => 999] + $set, ['interno_ref' => 4651], 'rua_inhambu');
aproximado($absurdo['valor_fixo'], 305.84, 'parte fixa nunca passa do total da fatura');

// ---- avisos ----
$semCip = conferir_energia(['fixo' => 0] + $set, ['interno_ref' => 4651], $antiga);
ok((bool) array_filter($semCip, fn($a) => strpos($a, 'iluminação pública') !== false), 'avisa quando não leu a iluminação pública');
$comCip = conferir_energia($set, ['interno_ref' => 4651], $r);
ok(!array_filter($comCip, fn($a) => strpos($a, 'iluminação pública') !== false), 'sem aviso quando leu');

// ---- leitura da fatura ----
$pdo->tabelas['app_secrets']['GEMINI_API_KEY'] = ['name' => 'GEMINI_API_KEY', 'value' => 'chave-de-teste'];
$pdo->tabelas['app_secrets']['ULTIMO_WATIMETRO_CASA2'] = ['name' => 'ULTIMO_WATIMETRO_CASA2', 'value' => '5400'];
$pdo->tabelas['app_secrets']['ULTIMO_WATIMETRO_CASA2_EM'] = ['name' => 'ULTIMO_WATIMETRO_CASA2_EM', 'value' => date('Y-m-d')];
geminiDevolve(['faturaTotal' => 305.84, 'leituraPadrao' => 247, 'dataLeitura' => '2026-09-03', 'local' => 'rua_inhambu',
    'iluminacaoPublica' => '34,98', 'multasJuros' => 2.5]);
$m = Ocr::montarEnergia('rua_inhambu', [['papel' => 'fatura', 'data' => 'AAAA', 'mimeType' => 'image/jpeg']]);
ok($m['ok'], 'fatura lida');
igual($m['payload']['fixo'], 37.48, 'iluminação + multa viram a parte fixa (vírgula brasileira aceita)');

geminiDevolve(['faturaTotal' => 700, 'leituraPadrao' => 1000, 'dataLeitura' => '2026-09-03', 'local' => 'av_brasil',
    'iluminacaoPublica' => 50, 'leituraInterno' => 500]);
$mav = Ocr::montarEnergia('av_brasil', [['papel' => 'fatura', 'data' => 'AAAA', 'mimeType' => 'image/jpeg']]);
igual($mav['payload']['fixo'] ?? null, 0.0, 'Av. Brasil não guarda parte fixa');

// ---- texto digitado ----
$t = Interpretacao::analisar('energia padrao 247 interno 4815 fatura 305,84 iluminação 34,98', 'admin');
igual($t['payload']['fixo'] ?? null, 34.98, 'iluminação digitada entra');
igual(Interpretacao::analisar('energia padrao 247 interno 4815 fatura 305,84', 'admin')['payload']['fixo'] ?? null, 0.0, 'sem iluminação, zero');

// ---- gravação ----
$id = Banco::inserirEnergia(['data' => '2026-09-03', 'padrao' => 247, 'interno' => 4815, 'interno2' => null,
    'fatura' => 305.84, 'local' => 'rua_inhambu', 'ref1' => 4651, 'ref2' => null, 'fixo' => 34.98]);
igual(end($pdo->tabelas['consumo_energia'])['valor_dividido_igual'], 34.98, 'parte fixa gravada na fatura');

// ---- mensagens ----
$row = ['local_medidor' => 'rua_inhambu', 'data_leitura' => '2026-09-03', 'consumo_total_edp' => 247,
    'valor_fatura_total' => 305.84, 'valor_kwh' => $r['valor_kwh'], 'valor_dividido_igual' => 34.98,
    'consumo_casa_2' => 164, 'valor_casa_2' => $r['valor_interno'], 'consumo_casa_1' => 83, 'valor_casa_1' => $r['valor_externo']];
$msgs = resp_mensagens_separadas_energia($row, ['casa1' => 'Rose', 'casa2' => 'Wellington']);
ok(strpos($msgs[0], 'Metade da parte fixa: R$ 17,49') !== false, 'mensagem da casa mostra a metade da parte fixa');
ok(strpos($msgs[1], 'Iluminação pública e taxas fixas: R$ 34,98') !== false, 'mensagem mostra a iluminação pública');
ok(strpos(resp_energia($row, [], 'admin'), 'R$ 17,49 para cada casa') !== false, 'resumo do admin mostra a divisão');
ok(strpos(resp_energia(['valor_dividido_igual' => 0] + $row, [], 'admin'), 'taxas fixas') === false, 'conta antiga sem linha de parte fixa');

encerrarTeste();
