<?php
session_start();
if (!isset($_SESSION['logado'])) { header("Location: index.php"); exit(); }
require_once "conexao.php";
require_once "config_saas.php";

$mensagem = "";
$tipo_msg = "";

$empresa_id = $_SESSION['usuario_empresa_id'];
// Buscar setores do banco
$stmt_set = $pdo->prepare("SELECT * FROM saas_setores WHERE empresa_id = :empresa_id ORDER BY nome");
$stmt_set->execute([':empresa_id' => $empresa_id]);
$setores = $stmt_set->fetchAll(PDO::FETCH_ASSOC);

// Criar pasta uploads se não existir
$upload_dir = __DIR__ . '/uploads';
if (!is_dir($upload_dir)) { mkdir($upload_dir, 0755, true); }

// Processar formulário
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $assunto = trim($_POST['assunto'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $setor = trim($_POST['setor'] ?? '');
    $prioridade = $_POST['prioridade'] ?? 'media';

    if (empty($assunto) || empty($descricao) || empty($setor)) {
        $mensagem = "Preencha todos os campos obrigatórios!";
        $tipo_msg = "error";
    } else {
        try {
            // Upload de imagem (opcional)
            $imagem_path = null;
            if (isset($_FILES['imagem']) && $_FILES['imagem']['error'] === UPLOAD_ERR_OK) {
                $extensoes_permitidas = ['jpg','jpeg','png','gif','webp'];
                $ext = strtolower(pathinfo($_FILES['imagem']['name'], PATHINFO_EXTENSION));
                
                if (in_array($ext, $extensoes_permitidas)) {
                    if ($_FILES['imagem']['size'] <= 5 * 1024 * 1024) { // 5MB max
                        $nome_arquivo = 'chamado_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                        $destino = $upload_dir . '/' . $nome_arquivo;
                        if (move_uploaded_file($_FILES['imagem']['tmp_name'], $destino)) {
                            $imagem_path = 'uploads/' . $nome_arquivo;
                        }
                    } else {
                        $mensagem = "A imagem deve ter no máximo 5MB!";
                        $tipo_msg = "error";
                    }
                } else {
                    $mensagem = "Formato de imagem não permitido! Use: JPG, PNG, GIF ou WEBP";
                    $tipo_msg = "error";
                }
            }

            if ($tipo_msg !== 'error') {
                $sql = "INSERT INTO saas_chamados (empresa_id, usuario_id, assunto, descricao, setor, prioridade, imagem) VALUES (:empresa_id, :uid, :assunto, :desc, :setor, :prio, :img)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':empresa_id' => $empresa_id,
                    ':uid' => $_SESSION['usuario_id'],
                    ':assunto' => $assunto,
                    ':desc' => $descricao,
                    ':setor' => $setor,
                    ':prio' => $prioridade,
                    ':img' => $imagem_path
                ]);
                header("Location: pagina.php");
                exit();
            }
        } catch (PDOException $e) {
            $mensagem = "Erro ao criar chamado: " . $e->getMessage();
            $tipo_msg = "error";
        }
    }
}

$pagina_atual = 'novo_chamado';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Novo Chamado - Vixmed</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="app-container">
    <?php include 'sidebar.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <h2>➕ Novo Chamado</h2>
            <a href="pagina.php" class="btn btn-secondary">← Voltar</a>
        </div>

        <?php if ($mensagem): ?>
            <div class="alert alert-<?= $tipo_msg ?>"><?= $mensagem ?></div>
        <?php endif; ?>

        <div class="card" style="max-width: 700px;">
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Setor *</label>
                    <select name="setor" required>
                        <option value="">Selecione o setor...</option>
                        <?php foreach ($setores as $s): ?>
                            <option value="<?= htmlspecialchars($s['nome']) ?>"><?= htmlspecialchars($s['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Assunto *</label>
                    <input type="text" name="assunto" placeholder="Ex: Impressora não funciona" required>
                </div>

                <div class="form-group">
                    <label>Prioridade</label>
                    <select name="prioridade">
                        <option value="baixa">🟢 Baixa</option>
                        <option value="media" selected>🟡 Média</option>
                        <option value="alta">🔴 Alta</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Descrição do Problema *</label>
                    <textarea name="descricao" placeholder="Descreva o problema com detalhes..." required></textarea>
                </div>

                <div class="form-group">
                    <label>📎 Anexar Imagem (opcional)</label>
                    <div class="upload-area" id="uploadArea" onclick="document.getElementById('fileInput').click()">
                        <input type="file" name="imagem" id="fileInput" accept="image/*" style="display:none" onchange="previewImagem(this)">
                        <div id="uploadPlaceholder">
                            <div style="font-size:36px; margin-bottom:8px;">📷</div>
                            <p style="font-size:14px; color:var(--cinza-texto);">Clique aqui ou arraste uma imagem</p>
                            <p style="font-size:12px; color:var(--cinza-texto); opacity:0.7;">JPG, PNG, GIF ou WEBP — Máx. 5MB</p>
                        </div>
                        <img id="previewImg" style="display:none; max-width:100%; max-height:200px; border-radius:8px;">
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" style="width:100%; padding:14px;">Criar Chamado</button>
            </form>
        </div>
    </div>
</div>

<script>
function previewImagem(input) {
    const preview = document.getElementById('previewImg');
    const placeholder = document.getElementById('uploadPlaceholder');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            preview.style.display = 'block';
            placeholder.style.display = 'none';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// Drag & drop
const area = document.getElementById('uploadArea');
area.addEventListener('dragover', e => { e.preventDefault(); area.style.borderColor = 'var(--verde)'; area.style.background = 'var(--verde-suave)'; });
area.addEventListener('dragleave', e => { e.preventDefault(); area.style.borderColor = 'var(--cinza-borda)'; area.style.background = ''; });
area.addEventListener('drop', e => {
    e.preventDefault();
    area.style.borderColor = 'var(--cinza-borda)';
    area.style.background = '';
    const input = document.getElementById('fileInput');
    input.files = e.dataTransfer.files;
    previewImagem(input);
});
</script>
</body>
</html>
