<?php
// logout.php
// Destroi a sessão e redireciona para o login

session_start();
session_unset(); // Limpa as variáveis de sessão
session_destroy(); // Destroi a sessão

header("Location: index.php");
exit();
?> 