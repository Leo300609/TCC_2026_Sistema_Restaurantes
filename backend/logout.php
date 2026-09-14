<?php
session_start();

// Destrói todas as variáveis de sessão
$_SESSION = array();

// Se houver cookie de sessão, remove
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), 
        '', 
        time() - 42000,
        $params["path"], 
        $params["domain"],
        $params["secure"], 
        $params["httponly"]
    );
}

// Destrói a sessão no servidor
session_destroy();

// Redireciona com caminho relativo saindo da pasta backend/ e entrando em frontend/html/
header("Location: ../frontend/html/login.html");
exit;
?>