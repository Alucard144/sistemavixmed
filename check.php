<?php
require_once "conexao.php";
echo "<pre>";
echo "=== ESTRUTURA DA TABELA CHAMADOS ===\n";
$cols = $pdo->query("DESCRIBE chamados")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo $c['Field'] . " | " . $c['Type'] . " | " . $c['Null'] . " | " . $c['Default'] . "\n";
}
echo "</pre>";
