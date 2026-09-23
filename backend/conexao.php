<?php
class BancodeDados {
    // Nas linhas abaixo voce podera colocar as informaçoes do Banco de Dados.
    private $host = "localhost:3308";
   // private $host = "localhost"; 	// Nome ou IP do Servidor
    private $user = "root"; 		// Usuario do Servidor MySQL
    private $senha = ""; 		// Senha do Usuario MySQL
    private $banco = "tcc"; 		// Nome do seu Banco de Dados
    public $con;

	// metodo responsavel para conexao a base de dados
	function conecta(){
        $this->con = mysqli_connect($this->host,$this->user,$this->senha, $this->banco);
       //   $this->con = @mysqli_connect($this->host,$this->user,$this->senha, $this->banco);
        // Conecta ao Banco de Dados
        if(!$this->con){
      		// Caso ocorra um erro, exibe uma mensagem com o erro
			die ("Problemas com a conex&atildeo");
        }
    }

	// metodo responsavel para fechar a conexao
	function fechar(){
		mysqli_close($this->con);
		return;
	}

}
?>
