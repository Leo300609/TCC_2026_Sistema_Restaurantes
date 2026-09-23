<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

$host = '127.0.0.1';
$port = '3308'; // Porta MariaDB do WampServer
$db   = 'tcc';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    echo json_encode([
        'sucesso' => false, 
        'mensagem' => 'Erro ao conectar ao banco de dados: ' . $e->getMessage()
    ]);
    exit;
}

$id_empresa = $_SESSION['id_empresa'] ?? 1;
$funcao = $_POST['funcao'] ?? '';
$nivel_acesso = $_POST['nivel_acesso'] ?? 1;

if (empty($funcao) || empty($nivel_acesso)) {
    echo json_encode([
        'sucesso' => false, 
        'mensagem' => 'Por favor, selecione a função e o nível de acesso.'
    ]);
    exit;
}

// Gera o código randômico (ex: XTEC-A1B2C)
$hashRandom = strtoupper(substr(md5(uniqid(rand(), true)), 0, 5));
$codigoFinal = "XTEC-" . $hashRandom;

try {
    $sql = "INSERT INTO convites_equipe (id_empresa, codigo, cargo, nivel_acesso, status) 
            VALUES (:id_empresa, :codigo, :cargo, :nivel_acesso, 'pendente')";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id_empresa'  => $id_empresa,
        ':codigo'       => $codigoFinal,
        ':cargo'        => $funcao,
        ':nivel_acesso' => $nivel_acesso
    ]);

    echo json_encode([
        'sucesso' => true, 
        'codigo'  => $codigoFinal
    ]);
} catch (PDOException $e) {
    echo json_encode([
        'sucesso' => false, 
        'mensagem' => 'Erro ao salvar convite no banco: ' . $e->getMessage()
    ]);
}
?>