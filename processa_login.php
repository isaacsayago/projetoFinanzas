<?php
// login.php
// Processa o login do usuário

session_start(); // Inicia a sessão

// Inclui o arquivo de conexão
require_once 'conexao.php';

// Verifica se o formulário foi enviado
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Protege contra XSS e SQL Injection
    $email = htmlspecialchars(trim($_POST['email']));
    $senha = htmlspecialchars(trim($_POST['senha']));

    // Verifica se os campos estão preenchidos
    if (empty($email) || empty($senha)) {
        $_SESSION['erro'] = "Preencha todos os campos!";
        header("Location: index.php");
        exit();
    }

    // Prepara a consulta para buscar o usuário pelo email
    $sql = "SELECT * FROM usuarios WHERE email = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    $resultado = mysqli_stmt_get_result($stmt);

    // Verifica se encontrou o usuário
    if ($usuario = mysqli_fetch_assoc($resultado)) {
        // Verifica a senha usando password_verify
        if (password_verify($senha, $usuario['senha'])) {
            // Login válido, salva dados na sessão
            $_SESSION['id'] = $usuario['id'];
            $_SESSION['nome'] = $usuario['nome'];
            $_SESSION['nivel'] = $usuario['nivel'];

            // Redireciona conforme o nível
            if ($usuario['nivel'] == 'adm') {
                header("Location: dashboard.php");
            } else {
                header("Location: dashboard.php");
            }
            exit();
        } else {
            $_SESSION['erro'] = "Senha incorreta!";
        }
    } else {
        $_SESSION['erro'] = "Usuário não encontrado!";
    }
    header("Location: index.php");
    exit();
} else {
    header("Location: index.php");
    exit();
}
?> 