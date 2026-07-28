<?php
session_start();
if (!isset($_SESSION['logado'])) { http_response_code(401); exit(json_encode(['erro' => 'Não autorizado'])); }
require_once "../conexao.php";

$uid = $_SESSION['usuario_id'];

// Criar pasta uploads se não existir
$upload_dir = dirname(__DIR__) . '/uploads';
if (!is_dir($upload_dir)) { @mkdir($upload_dir, 0777, true); }
@chmod($upload_dir, 0777);

// Suporta JSON (sem imagem) e FormData (com imagem)
if (isset($_POST['chamado_id'])) {
    $chamado_id = intval($_POST['chamado_id']);
    $mensagem = trim($_POST['mensagem'] ?? '');
} else {
    $data = json_decode(file_get_contents('php://input'), true);
    $chamado_id = intval($data['chamado_id'] ?? 0);
    $mensagem = trim($data['mensagem'] ?? '');
}

// Upload de imagem
$imagem_path = null;
$erro_upload = null;

if (isset($_FILES['imagem'])) {
    $err = $_FILES['imagem']['error'];
    if ($err === UPLOAD_ERR_OK) {
        $extensoes = ['jpg','jpeg','png','gif','webp'];
        $ext = strtolower(pathinfo($_FILES['imagem']['name'], PATHINFO_EXTENSION));
        
        if (in_array($ext, $extensoes)) {
            if ($_FILES['imagem']['size'] <= 10 * 1024 * 1024) { // 10MB
                $nome_arquivo = 'msg_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $destino = $upload_dir . '/' . $nome_arquivo;
                if (move_uploaded_file($_FILES['imagem']['tmp_name'], $destino)) {
                    $imagem_path = 'uploads/' . $nome_arquivo;
                } else {
                    $erro_upload = "Falha ao salvar imagem no servidor (permissão de pasta).";
                }
            } else {
                $erro_upload = "A imagem é muito grande (máximo 10MB).";
            }
        } else {
            $erro_upload = "Formato inválido. Use JPG, PNG, GIF ou WEBP.";
        }
    } else if ($err !== UPLOAD_ERR_NO_FILE) {
        $erro_upload = "Erro no envio da imagem (Código PHP: $err).";
    }
}

if ($erro_upload) {
    echo json_encode(['erro' => $erro_upload]);
    exit();
}

if ($chamado_id <= 0 || (empty($mensagem) && empty($imagem_path))) {
    echo json_encode(['erro' => 'Digite uma mensagem ou selecione uma imagem para enviar.']);
    exit();
}

// Verificar permissão para enviar mensagem
$tipo = $_SESSION['usuario_tipo'] ?? 'funcionario';
if ($tipo !== 'master') {
    $stmt_check = $pdo->prepare("SELECT usuario_id FROM chamados WHERE id = :cid");
    $stmt_check->execute([':cid' => $chamado_id]);
    $chamado = $stmt_check->fetch(PDO::FETCH_ASSOC);
    if (!$chamado || $chamado['usuario_id'] != $uid) {
        http_response_code(403);
        echo json_encode(['erro' => 'Você não tem permissão para enviar mensagens neste chamado.']);
        exit();
    }
}

try {
    $stmt = $pdo->prepare("INSERT INTO mensagens (chamado_id, usuario_id, mensagem, imagem) VALUES (:cid, :uid, :msg, :img)");
    $stmt->execute([':cid' => $chamado_id, ':uid' => $uid, ':msg' => $mensagem, ':img' => $imagem_path]);
    echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId()]);
} catch (PDOException $e) {
    echo json_encode(['erro' => 'Erro no banco: ' . $e->getMessage()]);
}
