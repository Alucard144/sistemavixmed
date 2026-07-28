<?php
session_start();
if (!isset($_SESSION['logado'])) { header("Location: index.php"); exit(); }
if (($_SESSION['usuario_tipo'] ?? '') !== 'master') { header("Location: pagina.php"); exit(); }
require_once "conexao.php";
require_once "config_saas.php";

$mensagem = "";
$tipo_msg = "";

$empresa_id = $_SESSION['usuario_empresa_id'];

// Adicionar setor
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['acao'])) {
    if ($_POST['acao'] === 'adicionar') {
        $nome = trim($_POST['nome'] ?? '');
        if (!empty($nome)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO saas_setores (empresa_id, nome) VALUES (:empresa_id, :nome)");
                $stmt->execute([':empresa_id' => $empresa_id, ':nome' => $nome]);
                $mensagem = "Setor '$nome' criado com sucesso!";
                $tipo_msg = "success";
            } catch (PDOException $e) {
                $mensagem = (strpos($e->getMessage(), 'Duplicate') !== false) ? "Esse setor já existe!" : "Erro: " . $e->getMessage();
                $tipo_msg = "error";
            }
        }
    } elseif ($_POST['acao'] === 'excluir') {
        $id = intval($_POST['setor_id'] ?? 0);
        try {
            $stmt = $pdo->prepare("DELETE FROM saas_setores WHERE id = :id AND empresa_id = :empresa_id");
            $stmt->execute([':id' => $id, ':empresa_id' => $empresa_id]);
            $mensagem = "Setor excluído!";
            $tipo_msg = "success";
        } catch (PDOException $e) {
            $mensagem = "Não é possível excluir: setor em uso.";
            $tipo_msg = "error";
        }
    }
}

$stmt_setores = $pdo->prepare("SELECT s.*, (SELECT COUNT(*) FROM saas_usuarios u WHERE u.setor_id = s.id AND u.empresa_id = :empresa_id) as total_usuarios FROM saas_setores s WHERE s.empresa_id = :empresa_id ORDER BY s.nome");
$stmt_setores->execute([':empresa_id' => $empresa_id]);
$setores = $stmt_setores->fetchAll(PDO::FETCH_ASSOC);

$pagina_atual = 'gerenciar_setores';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Setores - Vixmed</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="app-container">
    <?php include 'sidebar.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <h2>🏢 Gerenciar Setores</h2>
        </div>

        <?php if ($mensagem): ?>
            <div class="alert alert-<?= $tipo_msg ?>"><?= $mensagem ?></div>
        <?php endif; ?>

        <!-- Formulário adicionar -->
        <div class="card" style="margin-bottom: 24px; max-width: 500px;">
            <h3 style="margin-bottom: 16px;">Adicionar Novo Setor</h3>
            <form method="POST" style="display:flex; gap:12px;">
                <input type="hidden" name="acao" value="adicionar">
                <div class="form-group" style="flex:1; margin-bottom:0;">
                    <input type="text" name="nome" placeholder="Nome do setor..." required>
                </div>
                <button type="submit" class="btn btn-primary" style="height:46px;">Adicionar</button>
            </form>
        </div>

        <!-- Lista de setores -->
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Nome do Setor</th>
                        <th>Usuários</th>
                        <th>Criado em</th>
                        <th>Ação</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($setores as $s): ?>
                    <tr>
                        <td><?= $s['id'] ?></td>
                        <td><strong><?= htmlspecialchars($s['nome']) ?></strong></td>
                        <td><?= $s['total_usuarios'] ?> pessoa(s)</td>
                        <td><?= date('d/m/Y', strtotime($s['criado_em'])) ?></td>
                        <td>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Excluir este setor?')">
                                <input type="hidden" name="acao" value="excluir">
                                <input type="hidden" name="setor_id" value="<?= $s['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">Excluir</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($setores)): ?>
                    <tr><td colspan="5" style="text-align:center; padding:40px; color:var(--cinza-texto);">Nenhum setor cadastrado</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>
