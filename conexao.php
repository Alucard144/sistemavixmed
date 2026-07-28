<?php
$host = "localhost";
$banco = "vixmed_db";
$usuario = "vixmed_user"; // Mudou aqui
$senha = "vixmed123";      // Mudou aqui

try {
    // Cria a conexão com o banco de dados
    $pdo = new PDO("mysql:host=$host;dbname=$banco;charset=utf8mb4", $usuario, $senha);
    // Configura o PDO para avisar se acontecer algum erro de SQL
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $erro) {
    die("Erro ao conectar ao banco de dados da Vixmed: " . $erro->getMessage());
}
?>