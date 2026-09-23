<?php
session_start();

if (file_exists(__DIR__ . '/conexao/conexao.php')) {
    require_once __DIR__ . '/conexao/conexao.php';
}

if (!isset($pdo)) {
    $host = '127.0.0.1';
    $port = '3308';
    $db   = 'tcc';
    $user = 'root';
    $pass = '';

    try {
        $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    } catch (PDOException $e) {
        die("Erro na conexão com o banco de dados: " . $e->getMessage());
    }
}

$codigo_equipe   = strtoupper(trim($_POST['codigo_equipe'] ?? ''));
$nome            = trim($_POST['nome'] ?? '');
$email           = trim($_POST['email'] ?? '');
$senha           = $_POST['senha'] ?? '';
$confirmar_senha = $_POST['confirmar_senha'] ?? '';

if (empty($codigo_equipe) || empty($nome) || empty($email) || empty($senha)) {
    die("Erro: Por favor, preencha todos os campos do formulário.");
}

if ($senha !== $confirmar_senha) {
    die("Erro: A senha e a confirmação de senha não coincidem.");
}

// 1. Busca o convite pendente
$stmt = $pdo->prepare("SELECT * FROM convites_equipe WHERE codigo = :codigo AND status = 'pendente'");
$stmt->execute([':codigo' => $codigo_equipe]);
$convite = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$convite) {
    die("Erro: Código de equipe inválido, expirado ou já utilizado.");
}

// 2. Hash da senha para segurança
$senha_hash = password_hash($senha, PASSWORD_BCRYPT);

try {
    $pdo->beginTransaction();

    // 3. Cadastra o novo usuário utilizando a estrutura de colunas existente
    $sql_user = "INSERT INTO usuarios (nome, email, senha, nivel, ativo) 
                 VALUES (:nome, :email, :senha, :nivel, 'ATIVO')";
    
    $stmt_user = $pdo->prepare($sql_user);
    $stmt_user->execute([
        ':nome'  => $nome,
        ':email' => $email,
        ':senha' => $senha_hash,
        ':nivel' => $convite['nivel_acesso'] ?? 'FUNCIONARIO'
    ]);

    // 4. Marca o convite como utilizado
    $stmt_update = $pdo->prepare("UPDATE convites_equipe SET status = 'usado' WHERE id = :id");
    $stmt_update->execute([':id' => $convite['id']]);

    $pdo->commit();

    echo "<script>
            alert('Cadastro de funcionário realizado com sucesso!');
            window.location.href = '../frontend/html/login.html';
          </script>";
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    die("Erro ao cadastrar funcionário no banco: " . $e->getMessage());
}
?>