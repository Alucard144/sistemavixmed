<?php
$host = "localhost";
$banco = "vixmed_db";
$usuario = "vixmed_user";
$senha = "vixmed123";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$banco;charset=utf8mb4", $usuario, $senha);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $erro) {
    die("Erro ao conectar ao banco de dados do Vixmed CRM: " . $erro->getMessage());
}
?>
