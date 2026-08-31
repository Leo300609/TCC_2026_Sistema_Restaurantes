<?php
// Inicia a sessão para permitir o acesso e manipulação das variáveis globais $_SESSION
session_start();

// Checa se a variável de sessão 'log' existe e possui o valor "ativo".
if ($_SESSION['log'] == "ativo"){
    
    // Destrói os dados associados à sessão no servidor
    session_destroy();
    
    // Limpa todas as variáveis de sessão na memória 
    session_unset();
    
    // Reinicia a sessão no servidor para permitir guardar um novo estado
    session_start();
    
    // Define a variável 'log' como "desativo" para indicar que o usuário deslogou
    $_SESSION['log'] = "desativo";

    // Exibe um alerta de agradecimento via JavaScript e redireciona para a página inicial
    echo "<script language='javascript' type='text/javascript'>
    alert('Muito Obrigado pela visita');
    window.location.href='http://localhost/tcc_2026/frontend/html/index.html';
    </script>";
} else {
    // Exibe um alerta avisando que ele não está logado e o redireciona para a tela inicial
    echo "<script language='javascript' type='text/javascript'>
    alert('Você não está mais logado, faça o login primeiro');
    window.location.href='http://localhost/tcc_2026/frontend/html/index.html';
    </script>";
}

?>