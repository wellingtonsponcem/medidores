<?php
/**
 * Relógios que chegam por foto: o watímetro da Loja 02 (Fernando) e o
 * submedidor da Casa 2.
 *
 * Três erros reais que estes testes travam:
 *  - a última foto valia para sempre, e um mês sem foto nova reaproveitava a
 *    leitura do mês anterior — a loja saía com consumo zero sem aviso;
 *  - uma leitura menor que a anterior era guardada sem pergunta;
 *  - a fatura que o Fernando mandava podia virar lançamento da Rua Inhambu.
 */

require __DIR__ . '/_apoio/ambiente.php';

/** Gemini falso: devolve o JSON que a "leitura" da foto teria produzido. */
function geminiDevolve(array $valores): void
{
    Http::$transporte = fn() => ['status' => 200, 'corpo' => json_encode([
        'candidates' => [['content' => ['parts' => [['text' => json_encode($valores)]]]]],
    ])];
}

$pdo = ambiente();
$pdo->tabelas['app_secrets']['GEMINI_API_KEY'] = ['name' => 'GEMINI_API_KEY', 'value' => 'chave-de-teste'];

// ---- guarda o valor junto com o dia ----
igual(Banco::leituraFoto('ULTIMO_WATIMETRO_LOJA2'), null, 'sem foto, nada guardado');
Banco::salvarLeituraFoto('ULTIMO_WATIMETRO_LOJA2', 1200.5);
igual(Banco::leituraFoto('ULTIMO_WATIMETRO_LOJA2'), ['valor' => 1200.5, 'em' => fmt_hoje()], 'valor e data da foto');

// ---- sem rateio anterior, a foto vale ----
$r = Ocr::relogioParaRateio('av_brasil', 'Loja 02');
igual($r['valor'], 1200.5, 'primeira foto entra no rateio');
igual($r['aviso'], null, 'primeira foto não gera aviso');

// ---- rateio anterior já usou 1200.5: a mesma foto agora é velha ----
$pdo->tabelas['consumo_energia'][] = [
    'id' => 'e1', 'data_leitura' => '2026-09-05', 'local_medidor' => 'av_brasil',
    'leitura_padrao' => 5000, 'leitura_interno' => 800, 'leitura_interno_2' => 1200.5,
    'valor_fatura_total' => 900,
];
$r = Ocr::relogioParaRateio('av_brasil', 'Loja 02');
igual($r['valor'], null, 'foto já usada no rateio anterior fica de fora');
ok(strpos((string) $r['aviso'], 'já entrou no rateio anterior') !== false, 'e o admin é avisado');

// ---- leitura nova só se andar para frente ----
igual(Ocr::conferirRelogioNovo('av_brasil', 1300.0), null, 'leitura maior passa');
igual(Ocr::conferirRelogioNovo('av_brasil', 1200.5), null, 'leitura igual passa (loja fechada no mês)');
ok(strpos((string) Ocr::conferirRelogioNovo('av_brasil', 1100.0), 'menor que a última') !== false,
    'leitura menor que a anterior é recusada');
Banco::salvarLeituraFoto('ULTIMO_WATIMETRO_LOJA2', 1350.0);
ok(Ocr::conferirRelogioNovo('av_brasil', 1300.0) !== null,
    'a foto guardada também conta como referência, não só o rateio gravado');
igual(Ocr::conferirRelogioNovo('rua_inhambu', 1.0), null, 'Rua Inhambu sem histórico não recusa');

// ---- foto nova depois do rateio entra ----
$r = Ocr::relogioParaRateio('av_brasil', 'Loja 02');
igual($r['valor'], 1350.0, 'foto nova depois do rateio entra');

// ---- fatura do Fernando fica presa na Av. Brasil ----
// a Tuya do dia já está no banco: o teste não pode disparar a coleta real
$pdo->tabelas['leitura_energia'][] = ['id' => 't1', 'valor_extraido' => 950, 'data_leitura' => '2026-10-05 14:00:00', 'medidor_id' => 'x'];
geminiDevolve(['faturaTotal' => 1000, 'leituraPadrao' => 5600, 'local' => 'rua_inhambu', 'dataLeitura' => '2026-10-05']);
$fatura = [['papel' => 'fatura_energia', 'data' => 'AAAA', 'mimeType' => 'image/jpeg']];

$travada = Ocr::montarEnergia('av_brasil', $fatura, null, true);
ok($travada['ok'], 'fatura travada é lida');
igual($travada['payload']['local'], 'av_brasil', 'Loja 02: a IA dizer Rua Inhambu não muda o imóvel');
ok(strpos(implode(' ', $travada['avisos']), 'Confira se é a conta certa') !== false,
    'a discordância vira aviso para o admin');
igual($travada['payload']['interno2'], 1350.0, 'o watímetro da loja entra no rateio');
igual($travada['payload']['interno'], 950.0, 'a Tuya do dia entra como Casa 03');

$livre = Ocr::montarEnergia('av_brasil', $fatura);
igual($livre['payload']['local'], 'rua_inhambu', 'admin: o endereço lido na fatura continua decidindo');

geminiDevolve(['faturaTotal' => 1000, 'leituraPadrao' => 5600, 'local' => 'av_brasil', 'dataLeitura' => '2026-10-05']);
$certa = Ocr::montarEnergia('av_brasil', $fatura, null, true);
igual($certa['avisos'], [], 'fatura da Av. Brasil certa não gera aviso de endereço');

// ---- avisos do OCR aparecem na pendência que o admin confirma ----
Banco::salvarLeituraFoto('ULTIMO_WATIMETRO_LOJA2', 1200.5); // volta para a foto velha
$velha = Ocr::montarEnergia('av_brasil', $fatura, null, true);
igual($velha['payload']['interno2'], null, 'foto velha não entra na fatura nova');
$id = Pendencia::criar(1, 'energia', $velha['payload']);
$texto = Pendencia::texto(Banco::pendencia($id), []);
ok(strpos($texto, 'já entrou no rateio anterior') !== false, 'o aviso de foto velha aparece na pendência');
ok(strpos($texto, 'sai por diferença do total') !== false, 'e o de valor por diferença também');

// ---- teclado da Loja 02 só tem energia ----
// index.php é o webhook e não pode ser incluído aqui; conferimos o contrato do
// teclado pelo texto do arquivo, que é o que o Telegram vai receber
$fonte = file_get_contents(dirname(__DIR__) . '/bot/index.php');
ok(strpos($fonte, "tecladoDePapeis(perfilDe(\$chatId) === 'loja2')") !== false, 'foto sem classificação da Loja 02 usa o teclado só de energia');
ok(strpos($fonte, "\$perfil === 'loja2' ? 'av_brasil' : 'rua_inhambu'") !== false, 'fatura da Loja 02 começa na Av. Brasil');

encerrarTeste();
