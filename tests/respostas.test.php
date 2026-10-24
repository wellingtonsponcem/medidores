<?php
/**
 * Mensagens do bot — e a regra de visibilidade que elas carregam.
 *
 * O teste central aqui não é se o texto está bonito. É que a resposta de um
 * morador nunca contenha os números das outras unidades. Aqui isso não é
 * capricho de privacidade: os moradores são vizinhos, o rateio é por consumo
 * individual, e um número que aparece na tela errada vira discussão de portão
 * aberto. Então afirmamos a propriedade: para cada perfil, os valores das
 * unidades alheias não aparecem no texto.
 */

require __DIR__ . '/_apoio/ambiente.php';

// Valores por unidade propositalmente distintos e facilmente reconhecíveis.
$rowAgua = [
    'data_leitura' => '2026-10-05',
    'consumo_cesan' => 34.00,
    'valor_fatura_total' => 560.18,
    'valor_por_m3' => 16.4759,
    'consumo_h1' => 24.00, 'valor_h1' => 395.42,
    'consumo_h2' => 6.00,  'valor_h2' => 98.86,
    'consumo_h3' => 9.00,  'valor_h3' => 148.28,
    'consumo_servico' => 0.00, 'valor_servico' => 0.00,
];

$rowEnergia = [
    'data_leitura' => '2026-10-05',
    'local_medidor' => 'rua_inhambu',
    'consumo_total_edp' => 1088.85,
    'valor_fatura_total' => 600.00,
    'valor_kwh' => 0.5510,
    'consumo_casa_2' => 968.85, 'valor_casa_2' => 533.84,
    'consumo_casa_1' => 120.00, 'valor_casa_1' => 66.12,
];

$nomes = ['casa1' => 'Jeane', 'casa2' => 'Wellington', 'casa3' => 'Sandra', 'loja2' => 'Padaria'];

// ---- cada morador vê a própria unidade, e só ela ----
$perfis = [
    'casa1' => ['395,42', '98,86', '148,28'],   // só o da casa 1 pode aparecer
    'casa2' => ['98,86', '395,42', '148,28'],   // só o da casa 2
    'casa3' => ['148,28', '395,42', '98,86'],   // só o da casa 3
];
foreach ($perfis as $perfil => [$proprio, $alheio1, $alheio2]) {
    $t = resp_agua($rowAgua, $nomes, $perfil);
    ok(strpos($t, $proprio) !== false, "{$perfil} vê o valor da própria unidade ({$proprio})");
    ok(strpos($t, $alheio1) === false, "{$perfil} NÃO vê o valor da unidade alheia ({$alheio1})");
    ok(strpos($t, $alheio2) === false, "{$perfil} NÃO vê o valor da unidade alheia ({$alheio2})");
}

// Só o morador da casa 3 é que vê a conta de serviço — para os outros, o
// serviço é uma linha que não diz respeito a eles.
ok(strpos(resp_agua($rowAgua, $nomes, 'casa3'), 'Serviço') !== false, 'casa3 vê o serviço, que é dela');
ok(strpos(resp_agua($rowAgua, $nomes, 'casa1'), 'Serviço') === false, 'casa1 não vê o serviço');
ok(strpos(resp_agua($rowAgua, $nomes, 'casa2'), 'Serviço') === false, 'casa2 não vê o serviço');

// O morador vê o total do condomínio e o valor do m³, que são a fatura inteira
// e não a conta de ninguém em particular — isso é o que ele precisa para
// conferir se o rateio do mês bate.
$tc1 = resp_agua($rowAgua, $nomes, 'casa1');
ok(strpos($tc1, '34,00') !== false, 'o morador vê o consumo total da fatura');
ok(strpos($tc1, SITE_URL) !== false, 'toda resposta termina com o link do painel');

// ---- perfil sem hidrômetro associado não ganha número de ninguém ----
$orfa = resp_agua($rowAgua, $nomes, 'visitante');
ok(strpos($orfa, '395,42') === false && strpos($orfa, '98,86') === false && strpos($orfa, '148,28') === false,
    'cadastro sem hidrômetro não recebe nenhum valor');
ok(strpos($orfa, 'Não consegui associar') !== false, 'e é avisado para falar com o administrador');

// ---- admin vê o quadro inteiro ----
$admin = resp_agua($rowAgua, $nomes, null);
foreach (['395,42', '98,86', '148,28', '560,18', 'Jeane', 'Wellington', 'Sandra'] as $esperado) {
    ok(strpos($admin, $esperado) !== false, "admin vê {$esperado}");
}
ok(strpos($admin, 'Serviço') !== false, 'admin vê a conta de serviço');

// ---- energia: mesma propriedade ----
// Na Rua Inhambu o relógio interno é a Casa 2 (consumo_casa_2) e o externo é a
// Casa 1 (consumo_casa_1) — é o que o painel chama de internalPartyLabel e
// externalPartyLabel. Casa 3 mora junto com a Casa 2 e divide o mesmo relógio.
$perfisEnergia = [
    'casa1' => ['proprio' => '66,12', 'alheios' => ['533,84']],
    'casa2' => ['proprio' => '533,84', 'alheios' => ['66,12']],
    'casa3' => ['proprio' => '533,84', 'alheios' => ['66,12']],
];
foreach ($perfisEnergia as $perfil => $casos) {
    $t = resp_energia($rowEnergia, $nomes, $perfil);
    ok(strpos($t, $casos['proprio']) !== false, "energia {$perfil} vê o próprio valor ({$casos['proprio']})");
    foreach ($casos['alheios'] as $alheio) {
        ok(strpos($t, $alheio) === false, "energia {$perfil} NÃO vê o alheio ({$alheio})");
    }
}
// O nome exibido tem que ser o da unidade do morador. A Casa 3 divide o relógio
// com a Casa 2, mas continua sendo a Casa 3 — e é o nome dela que deve aparecer.
ok(strpos(resp_energia($rowEnergia, $nomes, 'casa3'), 'Casa 3 (Sandra)') !== false,
    'energia casa3 mostra o nome da casa 3, e não o da vizinha');
ok(strpos(resp_energia($rowEnergia, $nomes, 'casa2'), 'Casa 2 (Wellington)') !== false,
    'energia casa2 mostra o nome da casa 2');
ok(strpos(resp_energia($rowEnergia, $nomes, 'casa1'), 'Casa 1 — Jeane') !== false,
    'energia casa1 mostra o nome da casa 1');

// Loja 2 só existe na Av. Brasil. Na Rua Inhambu, o consumo_casa_1 é da Casa 1,
// então entregar esse número a um loja2 seria mostrar a conta de outra pessoa.
ok(strpos(resp_energia($rowEnergia, $nomes, 'loja2'), '533,84') === false
    && strpos(resp_energia($rowEnergia, $nomes, 'loja2'), '66,12') === false,
    'loja2 na Rua Inhambu não recebe o valor de ninguém');
ok(strpos(resp_energia($rowEnergia, $nomes, 'loja2'), 'Não consegui associar') !== false,
    'loja2 na Rua Inhambu é avisado para falar com o administrador');

$rowAv = ['local_medidor' => 'av_brasil'] + $rowEnergia;
$tLoja = resp_energia($rowAv, $nomes, 'loja2');
ok(strpos($tLoja, '66,12') !== false, 'loja2 na Av. Brasil recebe o valor da loja');
ok(strpos($tLoja, 'Loja 2 — Padaria') !== false, 'loja2 na Av. Brasil mostra o nome da loja');
ok(strpos($tLoja, '533,84') === false, 'loja2 na Av. Brasil não vê o valor da casa');

// O texto para copiar é o do WhatsApp: sem acento, sem a fatura total (que o
// painel também não mostra) e com o nome de cada unidade.
$comp = resp_compartilhar($rowAgua, $rowEnergia, $nomes);
foreach (['16,48', '395,42', '98,86', '148,28', '533,84', '66,12', SITE_URL] as $esperado) {
    ok(strpos($comp, $esperado) !== false, "compartilhar traz {$esperado}");
}
ok(strpos($comp, '560,18') === false, 'compartilhar não repete o total da fatura, igual ao painel');
ok(strpos($comp, 'm3 ->') !== false, 'usa a seta do texto do WhatsApp, não a do Telegram');
ok(strpos($comp, 'Serviço') === false, 'sem acento, igual ao texto que já vai pelo WhatsApp');
ok(strpos($comp, 'Hidro 1 - Jeane') !== false, 'mostra o nome de cada unidade');

// ---- o resumo com os dois consumos ----
$semAgua = resp_resumo(null, $rowEnergia, $nomes, null);
ok(strpos($semAgua, 'Ainda não há leitura de água') !== false, 'sem leitura de água, avisa em vez de mostrar vazio');
ok(strpos($semAgua, '533,84') !== false, 'e ainda mostra a energia que existe');

$semNada = resp_resumo(null, null, $nomes, 'casa1');
ok(strpos($semNada, 'Ainda não há leitura de energia') !== false, 'sem leitura de energia, avisa');

// ---- lista de pendentes ----
ok(strpos(resp_pendentes([], $nomes), 'Nenhuma leitura aguardando') !== false, 'sem pendências, diz que não há nada');
$pend = resp_pendentes([
    ['tipo' => 'agua', 'criada_em' => '2026-10-22 10:00:00', 'payload' => ['data' => '2026-10-05']],
    ['tipo' => 'energia', 'criada_em' => '2026-10-22 11:00:00', 'payload' => ['data' => '2026-10-05']],
    ['tipo' => 'tuya', 'criada_em' => '2026-10-22 12:00:00', 'payload' => []],
], $nomes);
ok(strpos($pend, 'Água') !== false && strpos($pend, 'Energia') !== false && strpos($pend, 'Tuya') !== false,
    'lista os três tipos de pendência');

// ---- lista de usuários: o admin não aparece e a senha nunca aparece ----
$usu = resp_usuarios([
    ['nome' => 'jeane', 'perfil' => 'casa1', 'ativo' => 1, 'senha_alterada' => 1],
    ['nome' => 'wellington', 'perfil' => 'casa2', 'ativo' => 1, 'senha_alterada' => 0],
    ['nome' => 'admin', 'perfil' => 'admin', 'ativo' => 1, 'senha_alterada' => 1],
]);
ok(strpos($usu, 'Jeane (casa1) — ativo, senha ok') !== false, 'mostra nome, perfil, situação e estado da senha');
ok(strpos($usu, 'Wellington (casa2) — ativo, senha provisória') !== false, 'avisa quando a senha ainda é a provisória');
ok(strpos($usu, 'admin') === false, 'a lista de acessos dos moradores não expõe o admin');
ok(strpos($usu, 'senha123') === false && preg_match('/\bsenha\b\s*[:=]/i', $usu) !== 1, 'nenhuma senha vaza na listagem');

// ---- textos fixos ----
ok(strpos(resp_nao_autorizado(), 'não está liberado') !== false, 'avisa que o aparelho não está liberado');
ok(strpos(resp_vinculo_errado(), 'não conferem') !== false, 'diz que o login não bate');
ok(strpos(resp_sem_permissao(), 'lançar leitura é função do administrador') !== false, 'explica que lançar é do admin');
ok(strpos(resp_boas_vindas('Jeane'), 'Jeane') !== false, 'a saudação usa o nome');

// ---- ajuda: o morador não recebe a parte de lançamento ----
$ajudaM = resp_ajuda(false);
ok(strpos($ajudaM, 'Você consulta seu consumo') !== false, 'morador é avisado de que só consulta');
ok(strpos($ajudaM, '/tuya') === false, 'morador não recebe o comando /tuya');
ok(strpos($ajudaM, 'Envie a foto') === false, 'morador não recebe o convite de foto');

$ajudaA = resp_ajuda(true);
ok(strpos($ajudaA, '/tuya') !== false, 'admin recebe o /tuya');
ok(strpos($ajudaA, 'Envie a foto') !== false, 'admin recebe o convite de foto');
ok(strpos($ajudaA, 'agua cesan 1806') !== false, 'admin recebe o exemplo de digitação');

// Propriedade: nenhuma resposta vazia, e nenhuma com "Array" vazando — costuma
// acontecer quando alguém passa um array onde se espera string.
foreach ([
    'boas-vindas' => resp_boas_vindas('X'), 'ajuda-m' => resp_ajuda(false), 'ajuda-a' => resp_ajuda(true),
    'agua-admin' => resp_agua($rowAgua, $nomes, null), 'agua-c1' => resp_agua($rowAgua, $nomes, 'casa1'),
    'energia-admin' => resp_energia($rowEnergia, $nomes, null), 'energia-c2' => resp_energia($rowEnergia, $nomes, 'casa2'),
    'resumo' => resp_resumo($rowAgua, $rowEnergia, $nomes, null),
    'compartilhar' => resp_compartilhar($rowAgua, $rowEnergia, $nomes),
    'pendentes' => resp_pendentes([], $nomes), 'usuarios' => resp_usuarios([]),
    'nao-aut' => resp_nao_autorizado(), 'vinculo' => resp_vinculo_errado(), 'sem-perm' => resp_sem_permissao(),
] as $nome => $texto) {
    if ($texto === '' || $texto === null || strpos($texto, 'Array') !== false || strpos($texto, 'Warning') !== false) {
        ok(false, "propriedade: {$nome} devolve texto não vazio e sem 'Array' vazando");
        break;
    }
}
ok(true, 'propriedade: toda resposta devolve texto não vazio e sem "Array" vazando (14 mensagens)');

// ---- mensagens separadas e resumo do Fernando ----
$rowAvBrasil = [
    'data_leitura' => '2026-09-20',
    'consumo_total_edp' => 1289.0,
    'valor_fatura_total' => 1499.19,
    'valor_kwh' => 1.163,
    'consumo_casa_2' => 752.49,
    'valor_casa_2' => 875.19,
    'consumo_casa_1' => 536.51,
    'valor_casa_1' => 624.00,
    'local_medidor' => 'av_brasil',
];
$msgFernando = resp_rateio_fernando($rowAvBrasil, ['casa3' => 'Graca', 'loja2' => 'Fernando']);
ok(strpos($msgFernando, 'Resumo de Rateio - 09/2026') !== false, 'fernando: cabeçalho correto com mês de ref');
ok(strpos($msgFernando, 'Consumo total: 1.289,00 kWh') !== false, 'fernando: consumo total 1289 kWh');
ok(strpos($msgFernando, 'Graca') !== false, 'fernando: nome da Graca presente');
ok(strpos($msgFernando, '752,49 kWh -> R$ 875,19') !== false, 'fernando: consumo e valor da Graca');
ok(strpos($msgFernando, 'Fernando') !== false, 'fernando: nome do Fernando presente');
ok(strpos($msgFernando, '536,51 kWh -> R$ 624,00') !== false, 'fernando: consumo e valor do Fernando');
ok(strpos($msgFernando, 'Casa 1') === false && strpos($msgFernando, 'Mirian') === false, 'fernando: nunca vê moradores do outro imóvel');

$msgsAgua = resp_mensagens_separadas_agua($rowAgua, $nomes);
igual(count($msgsAgua), 3, 'mensagens separadas de água geram 3 textos individuais');
ok(strpos($msgsAgua[0], 'Hidro 1') !== false, 'mensagem 1 de água é do Hidro 1');
ok(strpos($msgsAgua[1], 'Hidro 2') !== false, 'mensagem 2 de água é do Hidro 2');
ok(strpos($msgsAgua[2], 'Hidro 3') !== false, 'mensagem 3 de água é do Hidro 3');

$msgsEnergiaInhambu = resp_mensagens_separadas_energia($rowEnergia, ['casa1' => 'Rose', 'casa2' => 'Mirian']);
igual(count($msgsEnergiaInhambu), 2, 'energia Inhambu gera 2 mensagens separadas');
ok(strpos($msgsEnergiaInhambu[0], 'Mirian') !== false, 'energia Inhambu tem mensagem da Mirian');
ok(strpos($msgsEnergiaInhambu[1], 'Residual') !== false, 'energia Inhambu tem mensagem do Residual');

$msgsEnergiaBrasil = resp_mensagens_separadas_energia($rowAvBrasil, ['casa3' => 'Graca', 'loja2' => 'Fernando']);
igual(count($msgsEnergiaBrasil), 2, 'energia Av Brasil gera 2 mensagens separadas');
ok(strpos($msgsEnergiaBrasil[0], 'Graca') !== false, 'energia Av Brasil tem mensagem da Graca');
ok(strpos($msgsEnergiaBrasil[1], 'Fernando') !== false, 'energia Av Brasil tem resumo do Fernando');

encerrarTeste();
