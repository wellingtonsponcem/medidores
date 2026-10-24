<?php
/**
 * Regras de acesso do painel e do /vincular.
 *
 * Antes destas regras a api.php entregava a tabela de senhas a qualquer GET e
 * aceitava nome de coluna com crase. Estes testes travam a porta fechada: se
 * alguém "simplificar" a lista de tabelas ou a validação de identificador, a
 * suíte fica vermelha antes do deploy.
 */

require __DIR__ . '/_apoio/ambiente.php';

// ---- identificadores: só letra, número e sublinhado ----
ok(Acesso::identificadorValido('data_leitura'), 'coluna comum passa');
ok(Acesso::identificadorValido('_x1'), 'sublinhado no começo passa');
ok(!Acesso::identificadorValido('id` = 1 OR 1=1 -- '), 'crase de injeção é recusada');
ok(!Acesso::identificadorValido('nome; DROP TABLE usuarios'), 'ponto e vírgula é recusado');
ok(!Acesso::identificadorValido('1coluna'), 'começar com dígito é recusado (chave numérica de lista)');
ok(!Acesso::identificadorValido(''), 'vazio é recusado');
ok(!Acesso::identificadorValido(null), 'nulo é recusado');
ok(!Acesso::identificadorValido(['id']), 'array é recusado');
ok(!Acesso::identificadorValido(str_repeat('a', 65)), 'nome maior que o limite do MySQL é recusado');

// ---- permissões ----
ok(!Acesso::podeAcessar(null, 'vw_agua_rateio', 'GET'), 'sem perfil não lê nada');
ok(Acesso::podeAcessar('casa1', 'vw_agua_rateio', 'GET'), 'morador lê o rateio da água');
ok(Acesso::podeAcessar('loja2', 'vw_energia_rateio', 'GET'), 'morador lê o rateio da energia');
ok(!Acesso::podeAcessar('casa1', 'usuarios', 'GET'), 'morador não lê usuarios');
ok(!Acesso::podeAcessar('casa1', 'vw_energia_rateio_admin', 'GET'), 'morador não lê a view do admin');
ok(!Acesso::podeAcessar('casa1', 'consumo_agua', 'PUT'), 'morador não altera leitura');
ok(!Acesso::podeAcessar('casa1', 'vw_agua_rateio', 'DELETE'), 'morador não apaga nem a view');
ok(Acesso::podeAcessar('admin', 'usuarios', 'POST'), 'admin cria usuário');
ok(Acesso::podeAcessar('admin', 'consumo_energia', 'DELETE'), 'admin apaga leitura');
ok(!Acesso::podeAcessar('admin', 'vw_agua_rateio', 'PUT'), 'view não aceita escrita nem do admin');
ok(!Acesso::podeAcessar('admin', 'app_secrets', 'GET'), 'app_secrets não sai pela api, nem para o admin');
ok(!Acesso::podeAcessar('admin', 'telegram_chats', 'GET'), 'tabela fora da lista é recusada');

// ---- usuário público nunca leva a senha ----
$pub = Acesso::usuarioPublico(['id' => 'u1', 'nome' => 'ana', 'senha' => 'x', 'ativo' => '1', 'senha_alterada' => '0']);
ok(!array_key_exists('senha', $pub), 'senha é removida da resposta');
igual($pub['ativo'], true, 'ativo vira booleano');
igual($pub['senha_alterada'], false, 'senha_alterada vira booleano');

// ---- senha: hash e texto puro legado ----
$hash = Acesso::gerarHash('segredo 123');
ok($hash !== 'segredo 123', 'hash não é a senha');
ok(Acesso::senhaConfere('segredo 123', $hash), 'hash confere com a senha certa');
ok(!Acesso::senhaConfere('segredo 12', $hash), 'hash recusa a senha errada');
ok(Acesso::senhaConfere('antiga', 'antiga'), 'texto puro legado ainda entra');
ok(!Acesso::senhaConfere('', ''), 'senha vazia guardada nunca entra');
ok(Acesso::precisaRehash('antiga'), 'texto puro pede rehash');
ok(!Acesso::precisaRehash($hash), 'hash atual não pede rehash');

// ---- autenticar migra a senha legada para hash ----
$pdo = ambiente();
$pdo->tabelas['usuarios'] = [
    ['id' => 'u1', 'nome' => 'ana', 'senha' => 'antiga', 'perfil' => 'casa1', 'ativo' => 1],
    ['id' => 'u2', 'nome' => 'bia', 'senha' => 'x', 'perfil' => 'casa2', 'ativo' => 0],
];
igual(Acesso::autenticar($pdo, 'ana', 'errada'), null, 'senha errada não autentica');
$u = Acesso::autenticar($pdo, 'ana', 'antiga');
igual($u['id'] ?? null, 'u1', 'senha legada certa autentica');
$guardada = $pdo->tabelas['usuarios'][0]['senha'];
ok($guardada !== 'antiga' && password_verify('antiga', $guardada), 'senha legada foi regravada como hash');
igual(Acesso::autenticar($pdo, 'ana', 'antiga')['id'] ?? null, 'u1', 'segundo login já confere pelo hash');
igual(Acesso::autenticar($pdo, 'bia', 'x'), null, 'usuário inativo não autentica');
igual(Acesso::autenticar($pdo, '', ''), null, 'nome e senha vazios não autenticam');

// ---- o /vincular do bot usa a mesma regra ----
igual(Banco::usuarioPorNomeESenha('ana', 'antiga')['id'] ?? null, 'u1', 'bot vincula com a senha já em hash');
igual(Banco::usuarioPorNomeESenha('ana', 'outra'), null, 'bot recusa senha errada');

encerrarTeste();
