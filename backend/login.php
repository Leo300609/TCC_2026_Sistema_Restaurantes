<?php
require_once('conexao.php');
	//criando o objeto mysql e conectando ao banco de dados
	$mysql = new BancodeDados();
	$mysql -> conecta();

//declarando variaveis no php para receber o valor dos input do forms da pagina index.html
$pemail=$_POST["email"] ?? '';
$psenha=$_POST["senha"] ?? '';
 

//criando condiçoes para aparacer um prompt ao o admin ou funcionario ser logado e mostrar q a senha ou o login estão inválidos 
echo"<center>";
if ($pemail=="admin@tcc.com" && $psenha=="Admin@2026") {
  echo"<script language='javascript' type='text/javascript'>
          alert('Bem Vindo Administrador Master. Você está logado');window.location.href='principal.php';
          </script>";
}else if ($pemail=="func1@tcc.com" && $psenha=="123456"){
 echo"<script language='javascript' type='text/javascript'>
          alert('Bem vindo Vendedor João ao Sistema');window.location.href='principal.php';
          </script>"; 
}else if ($pemail=="func2@tcc.com" && $psenha=="123456"){
 echo"<script language='javascript' type='text/javascript'>
          alert('Bem vinda Vendedora Maria ao Sistema');window.location.href='principal.php';
          </script>";
}else if ($pemail=="func3@tcc.com" && $psenha=="123456"){
 echo"<script language='javascript' type='text/javascript'>
          alert('Bem vindo Comprador Carlos ao Sistema');window.location.href='principal.php';
          </script>";
}else if ($pemail=="func4@tcc.com" && $psenha=="123456"){
 echo"<script language='javascript' type='text/javascript'>
          alert('Bem vinda Compradora Ana ao Sistema');window.location.href='principal.php';
          </script>";
}else if ($pemail=="func5@tcc.com" && $psenha=="123456"){
 echo"<script language='javascript' type='text/javascript'>
          alert('Bem vindo Gestor de Estoque Pedro ao Sistema');window.location.href='principal.php';
          </script>";
}else{

echo"<script language='javascript' type='text/javascript'>
            alert('Seu Login ou sua Senha estão inválidos');window.location.href
            ='http://localhost/tcc_2026/frontend/html/index.html';</script>";
}

//fechando o objeto mysql
$mysql->fechar();
 
echo"</center>";
?>
