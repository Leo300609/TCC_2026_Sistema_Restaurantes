<?php
session_start();

// Destrói a sessão no servidor
session_destroy();

// Redireciona com caminho relativo saindo da pasta backend/ e entrando em frontend/html/
header("Location: /TCC_2026_SISTEMA_RESTAURANTES/frontend/html/login.html");
exit;
?>