<?php
session_start();

// Destrói a sessão no servidor.
session_destroy();

// Redireciona para o login, mantendo o contexto da pasta do projeto.
header("Location: ../../frontend/html/login.html");
exit();
