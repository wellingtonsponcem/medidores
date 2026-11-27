<?php
/**
 * Leitura de água montada aos poucos.
 *
 * O erro real que este arquivo trava: a foto só da fatura da CESAN virava
 * pendência com H1, H2 e H3 em 0, o rateio jogava a conta inteira em
 * "Serviço/Comum" e o bot oferecia "Confirmar lançamento" mesmo assim. O certo
 * é guardar a fatura e pedir os hidrômetros.
 */

require __DIR__ . '/_apoio/ambiente.php';

function geminiDevolve(array $valores): void
{
    Http::$transporte = fn() => ['status' => 200, 'corpo' => json_encode([
        'candidates' => [['content' => ['parts' => [['text' => json_encode($valores)]]]]],
    ])];
}

$pdo = ambiente();
$pdo->tabelas['app_secrets']['GEMINI_API_KEY'] = ['name' => 'GEMINI_API_KEY', 'value' => 'chave-de-teste'];

$faturaLida = [
    'fatura' => ['leitura_atual' => 1861, 'valor_total' => 407.06, 'data_leitura' => '2026-11-27'],
    // o modelo às vezes devolve hidrômetro mesmo sem foto de hidrômetro
    'medidores_individuais' => [['identificador' => 'h1', 'leitura_processada' => '999']],
];

// ---- foto só da fatura: nada de hidrômetro inventado ----
geminiDevolve($faturaLida);
$r = Ocr::lerAgua([['papel' => 'fatura', 'data' => 'AAAA', 'mimeType' => 'image/jpeg']]);
ok($r['ok'], 'fatura sozinha é lida');
igual($r['parcial'], ['cesan' => 1861.0, 'data' => '2026-11-27', 'fatura' => 407.06], 'só a fatura entra, sem H1 inventado');

// ---- o que falta ----
igual(Pendencia::aguaFaltando($r['parcial']), ['H1', 'H2', 'H3'], 'com a fatura, faltam os três hidrômetros');
igual(Pendencia::aguaFaltando([]), ['fatura da CESAN', 'H1', 'H2', 'H3'], 'sem nada, falta tudo');
igual(Pendencia::aguaFaltando(['cesan' => 1861, 'h1' => 1, 'h2' => 1, 'h3' => 1]), ['fatura da CESAN'],
    'sem o valor em R$ a fatura ainda falta');
igual(Pendencia::aguaFaltando(['cesan' => 1, 'fatura' => 1, 'h1' => 1, 'h2' => 0, 'h3' => 1]), ['H2'], 'zero conta como faltando');

// ---- hidrômetros por foto ----
geminiDevolve(['medidores_individuais' => [
    ['identificador' => 'h1', 'leitura_processada' => '0512'],
    ['identificador' => 'h2', 'leitura_processada' => '301'],
]]);
$h = Ocr::lerAgua([
    ['papel' => 'h1', 'data' => 'AAAA', 'mimeType' => 'image/jpeg'],
    ['papel' => 'h2', 'data' => 'AAAA', 'mimeType' => 'image/jpeg'],
]);
igual($h['parcial'], ['h1' => 512.0, 'h2' => 301.0], 'hidrômetros entram, zero à esquerda removido');

// ---- juntar envios ----
$juntos = Pendencia::aguaJuntar($r['parcial'] + ['avisos_ocr' => ['aviso A']], $h['parcial'] + ['avisos_ocr' => ['aviso A', 'aviso B']]);
igual(Pendencia::aguaFaltando($juntos), ['H3'], 'fatura + dois hidrômetros: falta o H3');
igual($juntos['avisos_ocr'], ['aviso A', 'aviso B'], 'avisos se acumulam sem repetir');
igual(Pendencia::aguaJuntar(['h1' => 500.0], ['h1' => 512.0, 'h2' => null])['h1'], 512.0, 'valor novo corrige o guardado');
ok(!array_key_exists('h2', Pendencia::aguaJuntar([], ['h2' => null])), 'nulo não apaga nem cria campo');

// ---- números digitados ----
igual(Pendencia::aguaDoTexto('h3 200'), ['h3' => 200.0], 'um hidrômetro digitado');
igual(Pendencia::aguaDoTexto('H1: 512, h2=301 e h 3 - 0200'), ['h1' => 512.0, 'h2' => 301.0, 'h3' => 200.0], 'formatos variados');
igual(Pendencia::aguaDoTexto('cesan 1861 fatura R$ 407,06'), ['cesan' => 1861.0, 'fatura' => 407.06], 'fatura digitada');
igual(Pendencia::aguaDoTexto('quanto deu a água?'), [], 'pergunta não vira número');

// ---- mensagem para o usuário ----
$msg = resp_agua_parcial($juntos, ['H3']);
ok(strpos($msg, 'falta: *H3*') !== false, 'pede só o que falta');
ok(strpos($msg, 'CESAN 1.861') !== false && strpos($msg, 'H1 512') !== false, 'mostra o que já chegou');
ok(strpos($msg, 'h1 512 h2 300 h3 200') !== false, 'ensina a digitar os hidrômetros');
ok(strpos(resp_agua_parcial([], ['fatura da CESAN']), 'cesan 1861 fatura') !== false, 'sem fatura, ensina a digitar a fatura');

// ---- estado vive 24 h, os outros 15 min ----
Conversa::definir(7, 'montando_agua', ['cesan' => 1861]);
$pdo->tabelas['telegram_aguardando'][7]['atualizado_em'] = date('Y-m-d H:i:s', time() - 3600);
igual(Conversa::ler(7)['estado'], 'montando_agua', 'montagem de água sobrevive 1 hora');
Conversa::definir(8, 'classificando_foto', []);
$pdo->tabelas['telegram_aguardando'][8]['atualizado_em'] = date('Y-m-d H:i:s', time() - 3600);
igual(Conversa::ler(8)['estado'], null, 'pergunta rápida expira em 15 minutos');

encerrarTeste();
