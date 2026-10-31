<?php
declare(strict_types=1);

/**
 * Cadastro de funcionário com o código de convite gerado pela gerência
 * (tela Empresa > Equipe). Cargo e nível de acesso vêm do convite.
 */
require_once __DIR__ . '/../lib/sessao.php';
require_once __DIR__ . '/../lib/bd.php';

iniciar_sessao();

$voltar = static function (string $mensagem, array $dados = []): never {
    $q = ['erro' => $mensagem] + array_intersect_key($dados, array_flip(['codigo', 'nome', 'email']));
    header('Location: ../../frontend/html/cadastro.html?' . http_build_query($q));
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: ../../frontend/html/cadastro.html');
    exit;
}

$codigo = strtoupper(trim((string) ($_POST['codigo_equipe'] ?? '')));
$nome = trim((string) ($_POST['nome'] ?? ''));
$email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
$senha = (string) ($_POST['senha'] ?? '');
$confirmar = (string) ($_POST['confirmar_senha'] ?? '');
$dados = ['codigo' => $codigo, 'nome' => $nome, 'email' => $email];

if ($codigo === '' || $nome === '' || $email === '' || $senha === '') {
    $voltar('Preencha todos os campos do formulário.', $dados);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $voltar('Informe um e-mail válido.', $dados);
}
if (mb_strlen($senha) < 8 || !preg_match('/\d/', $senha) || !preg_match('/\pL/u', $senha)) {
    $voltar('A senha precisa ter ao menos 8 caracteres, com letras e números.', $dados);
}
if ($senha !== $confirmar) {
    $voltar('A senha e a confirmação não coincidem.', $dados);
}

try {
    $novo = chamar_um('sp_convite_usar', [$codigo, $nome, $email, password_hash($senha, PASSWORD_BCRYPT)]);
    registrar_atividade('equipe', 'cadastro', "Nova conta criada com o convite {$codigo} ({$novo['cargo']})", (int) $novo['id'], $nome);
    header('Location: ../../frontend/html/login.html?' . http_build_query(['ok' => 'cadastro', 'email' => $email]));
    exit;
} catch (ErroNegocio $e) {
    $voltar($e->getMessage(), $dados);
} catch (Throwable $e) {
    error_log('[tcc] cadastro: ' . $e->getMessage());
    $voltar('Não foi possível concluir o cadastro agora. Tente novamente em instantes.', $dados);
}
