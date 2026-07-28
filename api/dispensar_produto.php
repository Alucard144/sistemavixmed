<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logado'])) { 
    http_response_code(401); 
    exit(json_encode(['erro' => 'Não autorizado'])); 
}
if (($_SESSION['usuario_tipo'] ?? '') !== 'master') {
    http_response_code(403);
    exit(json_encode(['erro' => 'Apenas administradores podem dispensar produtos.']));
}

require_once "../conexao.php";

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    // fall back to POST parameters
    $data = $_POST;
}

$chamado_id = intval($data['chamado_id'] ?? 0);
$produto_id = intval($data['produto_id'] ?? 0);
$quantidade = intval($data['quantidade'] ?? 1);

if ($chamado_id <= 0 || $produto_id <= 0 || $quantidade <= 0) {
    http_response_code(400);
    exit(json_encode(['erro' => 'Parâmetros inválidos.']));
}

try {
    $pdo->beginTransaction();

    // 1. Verificar se o chamado existe e está aberto/em andamento
    $stmt_chamado = $pdo->prepare("SELECT status FROM chamados WHERE id = :id");
    $stmt_chamado->execute([':id' => $chamado_id]);
    $chamado = $stmt_chamado->fetch(PDO::FETCH_ASSOC);
    if (!$chamado) {
        throw new Exception("Chamado não encontrado.");
    }
    if (in_array($chamado['status'], ['resolvido', 'fechado'])) {
        throw new Exception("Não é possível vincular produtos a um chamado resolvido ou fechado.");
    }

    // 2. Verificar estoque do produto e travar a linha para update
    $stmt_prod = $pdo->prepare("SELECT nome, codigo, quantidade FROM produtos WHERE id = :id FOR UPDATE");
    $stmt_prod->execute([':id' => $produto_id]);
    $produto = $stmt_prod->fetch(PDO::FETCH_ASSOC);
    if (!$produto) {
        throw new Exception("Produto não encontrado.");
    }
    if ($produto['quantidade'] < $quantidade) {
        throw new Exception("Estoque insuficiente para '{$produto['nome']}'. Qtd disponível: {$produto['quantidade']}");
    }

    // 3. Decrementar estoque do produto
    $stmt_dec = $pdo->prepare("UPDATE produtos SET quantidade = quantidade - :qtd WHERE id = :id");
    $stmt_dec->execute([':qtd' => $quantidade, ':id' => $produto_id]);

    // 4. Inserir registro na tabela de movimentações
    $motivo = "Dispensado para o chamado #{$chamado_id}";
    $stmt_mov = $pdo->prepare("INSERT INTO movimentacao_estoque (produto_id, tipo, quantidade, motivo, usuario_id, chamado_id) VALUES (:pid, 'saida', :qtd, :motivo, :uid, :cid)");
    $stmt_mov->execute([
        ':pid' => $produto_id,
        ':qtd' => $quantidade,
        ':motivo' => $motivo,
        ':uid' => $_SESSION['usuario_id'],
        ':cid' => $chamado_id
    ]);

    // 5. Inserir mensagem de sistema no chat do chamado
    $msg_chat = "📦 **EQUIPAMENTO DISPENSADO DO ESTOQUE**\nRetirado(s) **{$quantidade}x {$produto['nome']}** (Cód: `{$produto['codigo']}`) e vinculado(s) a este chamado.";
    $stmt_msg = $pdo->prepare("INSERT INTO mensagens (chamado_id, usuario_id, mensagem) VALUES (:cid, :uid, :msg)");
    $stmt_msg->execute([
        ':cid' => $chamado_id,
        ':uid' => $_SESSION['usuario_id'],
        ':msg' => $msg_chat
    ]);

    $pdo->commit();
    echo json_encode(['ok' => true, 'mensagem' => $msg_chat]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['erro' => $e->getMessage()]);
}
