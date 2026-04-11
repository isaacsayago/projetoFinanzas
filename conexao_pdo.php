<?php
$dbHost = '127.0.0.1';
$dbName = 'financeiro'; 
$dbUser = 'root';
$dbPass = '23092022noah.';

try {
    $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (Exception $e) {
    die("Falha na conexão (PDO): " . $e->getMessage());
}