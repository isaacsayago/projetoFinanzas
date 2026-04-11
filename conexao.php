<?php
// conexao.php
// Arquivo responsável por conectar ao banco de dados MySQL

$host = "127.0.0.1"; // Host do banco
$usuario = "root"; // Usuário do banco
$senha = "23092022noah."; // Senha do banco
$banco = "financeiro"; // Nome do banco de dados

// Cria a conexão
$conn = mysqli_connect($host, $usuario, $senha, $banco);

// Verifica se houve erro na conexão
if (!$conn) {
    die("Falha na conexão: " . mysqli_connect_error());
}
?> 