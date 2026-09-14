<?php
session_start();

class BancodeDados {
    // Definida a porta 3307 onde o seu banco de dados 'tcc' está rodando
    private $host = "127.0.0.1:3307"; 
    private $user = "root";
    private $senha = "";
    private $banco = "tcc";
    public $con;

    function conecta(){
        $this->con = mysqli_connect($this->host, $this->user, $this->senha, $this->banco);
        if(!$this->con){
            die("Problemas com a conexão: " . mysqli_connect_error());
        }
        mysqli_set_charset($this->con, "utf8mb4");
    }

    function fechar(){
        if($this->con){
            mysqli_close($this->con);
        }
    }
}

$mysql = new BancodeDados();
$mysql->conecta();

$pemail = $_POST["email"] ?? '';
$psenha = $_POST["senha"] ?? '';

if (!empty($pemail) && !empty($psenha)) {
    $emailEscapado = mysqli_real_escape_string($mysql->con, $pemail);

    $sql = "SELECT * FROM usuarios WHERE EMAIL = '$emailEscapado' AND ATIVO = 'ATIVO' LIMIT 1";
    $result = mysqli_query($mysql->con, $sql);

    if ($result && mysqli_num_rows($result) > 0) {
        $usuario = mysqli_fetch_assoc($result);

        $senhaValida = false;
        if (password_verify($psenha, $usuario['SENHA'])) {
            $senhaValida = true;
        } elseif ($psenha === $usuario['SENHA']) {
            $senhaValida = true;
        }

        if ($senhaValida) {
            $_SESSION['usuario_id'] = $usuario['ID'];
            $_SESSION['usuario_nome'] = $usuario['NOME'];
            $_SESSION['usuario_nivel'] = $usuario['NIVEL'];
            $_SESSION['id_empresa'] = 1;

            $mysql->fechar();
            
            // Redireciona para a página principal
            header("Location: ../frontend/html/dashboard.html");
            exit;
        }
    }
}

$mysql->fechar();

// Se o login falhar
header("Location: ../frontend/html/login.html?erro=1");
exit;
?>