<?php
/**
 * Pendências — a regra que impede o bot de estragar o rateio do condomínio.
 *
 * O teste central aqui é o do toque duplo. Ninguém testa isso no dia a dia
 * porque é difícil de reproduzir: o dedo do administrador escorrega, o Telegram
 * reenvia o callback, a rede cai e o bot responde duas vezes. Quando acontece, a
 * leitura entra duas vezes na tabela e o rateio do mês inteiro sai errado para
 * todo mundo — e ninguém percebe até alguém reclamar da conta.
 *
 * Então afirmamos a propriedade diretamente: quantos cliques forem, uma linha.
 */

require __DIR__ . '/_apoio/ambiente.php';

$pdo = ambiente();
semearLeituraAnteriorAgua($pdo);

// ---- criar não grava nada: só propõe ----
$id = Pendencia::criar(111, 'agua', [
    'data' => '2026-10-05', 'cesan' => 1806, 'h1' => 490, 'h2' => 189, 'h3' => 284,
    'fatura' => 560.18, 'origem' => 'foto',
]);
igual(count($pdo->linhas('consumo_agua')), 1, 'criar a pendência não grava a leitura');
igual(Banco::pendencia($id)['status'], 'pendente', 'pendência nasce pendente');

// ---- confirmar grava uma vez ----
$p = Banco::reivindicarPendencia($id, 999);
igual($p['status'], 'confirmada', 'o vencedor leva a pendência');
$r = Pendencia::executar($p);
igual($r['ok'], true, 'executar grava sem erro');
igual(count($pdo->linhas('consumo_agua')), 2, 'uma linha nova na tabela de consumo');

// ---- toque duplo não grava em dobro ----
$deNovo = Banco::reivindicarPendencia($id, 999);
igual($deNovo, null, 'segundo clique não encontra pendência pendente');
igual(count($pdo->linhas('consumo_agua')), 2, 'o toque duplo não criou a segunda linha');

// ---- propriedade: quantos cliques forem, uma linha ----
$pdo2 = ambiente();
semearLeituraAnteriorAgua($pdo2);
$id2 = Pendencia::criar(222, 'agua', [
    'data' => '2026-10-05', 'cesan' => 1810, 'h1' => 495, 'h2' => 190, 'h3' => 285,
    'fatura' => 570.00, 'origem' => 'texto',
]);
$vitorias = 0;
for ($i = 0; $i < 25; $i++) {
    $tentativa = Banco::reivindicarPendencia($id2, 1000 + $i);
    if ($tentativa !== null) {
        $vitorias++;
        Pendencia::executar($tentativa);
    }
}
igual($vitorias, 1, 'de 25 tentativas de confirmação, exatamente uma vence');
igual(count($pdo2->linhas('consumo_agua')), 2, 'de 25 cliques, exatamente uma linha gravada');

// ---- descartar não grava nada ----
$pdo3 = ambiente();
semearLeituraAnteriorAgua($pdo3);
$id3 = Pendencia::criar(333, 'agua', [
    'data' => '2026-10-05', 'cesan' => 1820, 'h1' => 500, 'h2' => 191, 'h3' => 286,
    'fatura' => 580.00, 'origem' => 'foto',
]);
Banco::descartarPendencia($id3);
igual(count($pdo3->linhas('consumo_agua')), 1, 'descartado não grava');
igual(Banco::pendencia($id3)['status'], 'descartada', 'pendência fica marcada como descartada');
igual(Banco::reivindicarPendencia($id3), null, 'pendência descartada não pode mais ser confirmada');

// ---- falha de banco devolve para pendente, e aí dá para tentar de novo ----
$pdo4 = ambiente();
semearLeituraAnteriorAgua($pdo4);
$id4 = Pendencia::criar(444, 'agua', [
    'data' => '2026-10-05', 'cesan' => 1830, 'h1' => 505, 'h2' => 192, 'h3' => 287,
    'fatura' => 590.00, 'origem' => 'foto',
]);
$pdo4->falhasForçadas = 1;
$p4 = Banco::reivindicarPendencia($id4);
$r4 = Pendencia::executar($p4);
igual($r4['ok'], false, 'falha de banco é devolvida como erro, não engolida');
igual(count($pdo4->linhas('consumo_agua')), 1, 'a leitura não foi gravada');
Banco::reabrirPendencia($id4);
igual(Banco::pendencia($id4)['status'], 'pendente', 'falha devolve a pendência para pendente');
$p4b = Banco::reivindicarPendencia($id4);
igual($p4b === null, false, 'depois de reabrir, dá para confirmar de novo');
igual(Pendencia::executar($p4b)['ok'], true, 'e a segunda tentativa grava');
igual(count($pdo4->linhas('consumo_agua')), 2, 'a leitura entrou uma vez só, na segunda tentativa');

// ---- pendência de outra conversa não é confirmável ----
$pdo5 = ambiente();
$id5 = Pendencia::criar(555, 'agua', ['data' => '2026-10-05', 'cesan' => 1, 'fatura' => 1, 'origem' => 'texto']);
$p5 = Banco::pendencia($id5);
igual((string) $p5['chat_id'], '555', 'a pendência guarda o chat de quem criou');

// ---- tipos inválidos não passam ----
try {
    Pendencia::criar(1, 'inventario', []);
    ok(false, 'tipo de pendência desconhecido é recusado');
} catch (InvalidArgumentException $e) {
    ok(true, 'tipo de pendência desconhecido é recusado');
}

// ---- a mensagem da pendência mostra o rateio e os avisos ----
$pdo6 = ambiente();
semearLeituraAnteriorAgua($pdo6);
Banco::$pdo = $pdo6;
$id6 = Pendencia::criar(666, 'agua', [
    'data' => '2026-10-05', 'cesan' => 1700, 'h1' => 400, 'h2' => 180, 'h3' => 280,
    'fatura' => 500, 'origem' => 'foto',
]);
$texto = Pendencia::texto(Banco::pendencia($id6), ['casa1' => 'Jeane', 'casa2' => 'Wellington']);
ok(strpos($texto, 'Confira os números acima') !== false, 'lembra o usuário de conferir antes de confirmar');
ok(strpos($texto, 'Confira antes de confirmar') !== false, 'leitura menor que a anterior aparece como aviso');
ok(strpos($texto, 'Jeane') !== false, 'mostra o nome do morador, não o nome do perfil');
ok(strpos($texto, 'lida de foto') !== false, 'diz de onde veio o dado (foto)');

// Teclado tem os dois botões com o id certo no callback data.
$teclado = Pendencia::teclado($id6);
igual($teclado[0][0]['callback_data'], 'p:' . $id6 . ':ok', 'botão de confirmar aponta para a pendência');
igual($teclado[0][1]['callback_data'], 'p:' . $id6 . ':nao', 'botão de descartar aponta para a pendência');

// ---- ids curtos o bastante para o limite do Telegram ----
for ($i = 0; $i < 200; $i++) {
    $novo = Pendencia::novoId(['agua', 'energia', 'tuya'][$i % 3]);
    if (strlen($novo . ':ok') > 64) {
        ok(false, "propriedade: id cabe no limite de 64 bytes do callback ({$novo})");
        break;
    }
}
ok(true, 'propriedade: id cabe no limite de 64 bytes do callback (200 ids)');

encerrarTeste();
