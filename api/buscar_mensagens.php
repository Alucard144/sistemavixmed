<?php
session_start();
if (!isset($_SESSION['logado'])) { http_response_code(401); exit('[]'); }
require_once "../conexao.php";

$chamado_id = intval($_GET['chamado_id'] ?? 0);
$ultima_id = intval($_GET['ultima_id'] ?? 0);

if ($chamado_id <= 0) { echo '[]'; exit(); }

// Verificar permissão de acesso
$uid = $_SESSION['usuario_id'];
$tipo = $_SESSION['usuario_tipo'] ?? 'funcionario';

if ($tipo !== 'master') {
    $stmt_check = $pdo->prepare("SELECT usuario_id FROM chamados WHERE id = :cid");
    $stmt_check->execute([':cid' => $chamado_id]);
    $chamado = $stmt_check->fetch(PDO::FETCH_ASSOC);
    if (!$chamado || $chamado['usuario_id'] != $uid) {
        http_response_code(403);
        echo '[]';
        exit();
    }
}

$sql = "SELECT m.id, m.usuario_id, m.mensagem, m.imagem, m.criado_em, u.nome, u.tipo 
        FROM mensagens m JOIN usuarios u ON m.usuario_id = u.id 
        WHERE m.chamado_id = :cid AND m.id > :uid 
        ORDER BY m.criado_em ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute([':cid' => $chamado_id, ':uid' => $ultima_id]);
$mensagens = $stmt->fetchAll(PDO::FETCH_ASSOC);

$resultado = [];
foreach ($mensagens as $m) {
    $resultado[] = [
        'id' => $m['id'],
        'usuario_id' => $m['usuario_id'],
        'nome' => $m['nome'],
        'tipo' => $m['tipo'],
        'mensagem' => htmlspecialchars($m['mensagem']),
        'imagem' => $m['imagem'] ?? null,
        'hora' => date('d/m H:i', strtotime($m['criado_em']))
    ];
}

header('Content-Type: application/json');
echo json_encode($resultado);
