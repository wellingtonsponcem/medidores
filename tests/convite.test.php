<?php
/**
 * Convite de uso único: vincula o aparelho de um morador sem senha.
 *
 * O que este arquivo trava: o link vale uma vez, expira, não serve para
 * administrador, não rebaixa um aparelho já vinculado e não guarda o código
 * em claro no banco.
 */

require __DIR__ . '/_apoio/ambiente.php';

$pdo = ambiente();
$pdo->tabelas['usuarios'] = [
    ['id' => 'u-adm', 'nome' => 'admin', 'perfil' => 'admin', 'ativo' => 1, 'senha' => 'x'],
    ['id' => 'u-mir', 'nome' => 'mirian', 'perfil' => 'casa2', 'ativo' => 0, 'senha' => 'x'],
    ['id' => 'u-wel', 'nome' => 'wellington', 'perfil' => 'casa2', 'ativo' => 1, 'senha' => 'x'],
];
$agora = strtotime('2026-11-27 16:00:00');
$ADMIN = 111;

// ---- criar ----
$r = Convite::criar('wellington', $ADMIN, $agora);
ok($r['ok'], 'admin gera convite para morador');
ok(Convite::pareceCodigo($r['codigo']), 'código tem 32 hex (cabe no start do Telegram)');
igual($r['expira_em'], '2026-11-30 16:00:00', 'expira em 72 horas');
$linha = array_values($pdo->tabelas['telegram_convites'])[0];
igual($linha['codigo_hash'], hash('sha256', $r['codigo']), 'banco guarda só o hash');
ok(strpos(json_encode($pdo->tabelas['telegram_convites']), $r['codigo']) === false, 'código em claro não aparece no banco');

igual(Convite::criar('admin', $ADMIN, $agora)['ok'], false, 'não existe convite para administrador');
igual(Convite::criar('mirian', $ADMIN, $agora)['ok'], false, 'usuário desativado não recebe convite');
igual(Convite::criar('ninguem', $ADMIN, $agora)['ok'], false, 'usuário inexistente não recebe convite');

// ---- usar ----
$u = Convite::usar($r['codigo'], 500, 900, 'Wel', $agora + 60);
ok($u['ok'], 'convite válido vincula');
igual($u['criado_por'], $ADMIN, 'sabe quem convidou, para avisar');
$chat = Banco::chat(500);
igual($chat['perfil'] ?? null, 'casa2', 'chat ficou com o perfil do usuário convidado');
ok(autorizadoPeloBanco(500), 'chat vinculado passa a ser reconhecido');

$de_novo = Convite::usar($r['codigo'], 501, 901, 'Outro', $agora + 120);
igual($de_novo['ok'], false, 'segundo uso do mesmo link é recusado');
igual(Banco::chat(501), null, 'segundo aparelho não foi vinculado');

// ---- expiração ----
$r2 = Convite::criar('wellington', $ADMIN, $agora);
igual(Convite::usar($r2['codigo'], 502, 902, 'X', $agora + 72 * 3600 + 1)['ok'], false, 'convite vencido é recusado');

// ---- aparelho já vinculado não troca de perfil ----
Banco::vincularChat(503, 903, 'Adm', 'admin');
$r3 = Convite::criar('wellington', $ADMIN, $agora);
$res = Convite::usar($r3['codigo'], 503, 903, 'Adm', $agora + 60);
igual($res['ok'], false, 'admin que toca num convite não vira morador');
igual(Banco::chat(503)['perfil'], 'admin', 'perfil do admin preservado');
igual(Convite::usar($r3['codigo'], 504, 904, 'Y', $agora + 90)['ok'], true, 'e o convite continua valendo para o morador');

// ---- cadastro mudou depois do convite ----
$r4 = Convite::criar('wellington', $ADMIN, $agora);
$pdo->tabelas['usuarios'][2]['ativo'] = 0;
igual(Convite::usar($r4['codigo'], 505, 905, 'Z', $agora + 60)['ok'], false, 'usuário desativado depois do convite não entra');
$pdo->tabelas['usuarios'][2]['ativo'] = 1;

// ---- lixo no start ----
igual(Convite::usar('abc', 506, 906, 'W', $agora)['ok'], false, 'código mal formado é recusado sem consultar');
igual(Convite::usar(str_repeat('0', 32), 506, 906, 'W', $agora)['ok'], false, 'código inexistente é recusado');

function autorizadoPeloBanco(int $chatId): bool
{
    $c = Banco::chat($chatId);
    return $c !== null && !empty($c['perfil']);
}

encerrarTeste();
