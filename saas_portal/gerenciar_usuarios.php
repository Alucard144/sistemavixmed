<?php
session_start();
if (!isset($_SESSION['logado'])) { header("Location: index.php"); exit(); }
if (($_SESSION['usuario_tipo'] ?? '') !== 'master') { header("Location: pagina.php"); exit(); }
require_once "conexao.php";
require_once "config_saas.php";

$mensagem = "";
$tipo_msg = "";

$empresa_id = $_SESSION['usuario_empresa_id'];

// Buscar setores para o dropdown da empresa
$stmt_setores = $pdo->prepare("SELECT * FROM saas_setores WHERE empresa_id = :empresa_id ORDER BY nome");
$stmt_setores->execute([':empresa_id' => $empresa_id]);
$setores = $stmt_setores->fetchAll(PDO::FETCH_ASSOC);

// Processar ações
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['acao'])) {
    if ($_POST['acao'] === 'adicionar') {
        $nome = trim($_POST['nome'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $senha = trim($_POST['senha'] ?? '');
        $funcao = trim($_POST['funcao'] ?? '');
        $setor_id = intval($_POST['setor_id'] ?? 0);
        $tipo = $_POST['tipo'] ?? 'funcionario';

        if (empty($nome) || empty($email) || empty($senha)) {
            $mensagem = "Nome, e-mail e senha são obrigatórios!";
            $tipo_msg = "error";
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO saas_usuarios (empresa_id, nome, email, senha, funcao, setor_id, tipo) VALUES (:empresa_id, :nome, :email, :senha, :funcao, :sid, :tipo)");
                $stmt->execute([
                    ':empresa_id' => $empresa_id,
                    ':nome' => $nome,
                    ':email' => $email,
                    ':senha' => $senha,
                    ':funcao' => $funcao,
                    ':sid' => $setor_id > 0 ? $setor_id : null,
                    ':tipo' => $tipo
                ]);
                $mensagem = "Usuário '$nome' cadastrado com sucesso!";
                $tipo_msg = "success";
            } catch (PDOException $e) {
                $mensagem = (strpos($e->getMessage(), 'Duplicate') !== false) ? "Esse e-mail já está cadastrado!" : "Erro: " . $e->getMessage();
                $tipo_msg = "error";
            }
        }
    } elseif ($_POST['acao'] === 'excluir') {
        $id = intval($_POST['user_id'] ?? 0);
        if ($id == $_SESSION['usuario_id']) {
            $mensagem = "Você não pode excluir a si mesmo!";
            $tipo_msg = "error";
        } else {
            try {
                $stmt = $pdo->prepare("DELETE FROM saas_usuarios WHERE id = :id AND empresa_id = :empresa_id");
                $stmt->execute([':id' => $id, ':empresa_id' => $empresa_id]);
                $mensagem = "Usuário excluído!";
                $tipo_msg = "success";
            } catch (PDOException $e) {
                $mensagem = "Não é possível excluir: usuário possui chamados.";
                $tipo_msg = "error";
            }
        }
    }
}

// Buscar todos os usuários com nome do setor da empresa logada
$stmt_users = $pdo->prepare("SELECT u.*, s.nome as setor_nome FROM saas_usuarios u LEFT JOIN saas_setores s ON u.setor_id = s.id WHERE u.empresa_id = :empresa_id ORDER BY u.nome");
$stmt_users->execute([':empresa_id' => $empresa_id]);
$usuarios = $stmt_users->fetchAll(PDO::FETCH_ASSOC);

$pagina_atual = 'gerenciar_usuarios';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Usuários - Vixmed</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="app-container">
    <?php include 'sidebar.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <h2>👥 Gerenciar Usuários</h2>
        </div>

        <?php if ($mensagem): ?>
            <div class="alert alert-<?= $tipo_msg ?>"><?= $mensagem ?></div>
        <?php endif; ?>

        <!-- Formulário adicionar -->
        <div class="card" style="margin-bottom: 24px;">
            <h3 style="margin-bottom: 16px;">Cadastrar Novo Usuário</h3>
            <form method="POST">
                <input type="hidden" name="acao" value="adicionar">
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label>Nome Completo *</label>
                        <input type="text" name="nome" placeholder="Ex: Maria Santos" required>
                    </div>
                    <div class="form-group">
                        <label>E-mail *</label>
                        <input type="email" name="email" placeholder="Ex: maria@vixmed.com.br" required>
                    </div>
                    <div class="form-group">
                        <label>Senha *</label>
                        <input type="text" name="senha" placeholder="Senha inicial" required>
                    </div>
                    <div class="form-group">
                        <label>Função</label>
                        <input type="text" name="funcao" placeholder="Ex: Recepcionista">
                    </div>
                    <div class="form-group">
                        <label>Setor</label>
                        <select name="setor_id">
                            <option value="0">Selecione...</option>
                            <?php foreach ($setores as $s): ?>
                                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Tipo</label>
                        <select name="tipo">
                            <option value="funcionario">Funcionário</option>
                            <option value="master">Master (Admin)</option>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Cadastrar Usuário</button>
            </form>
        </div>

        <!-- Lista de usuários -->
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Nome</th>
                        <th>E-mail</th>
                        <th>Função</th>
                        <th>Setor</th>
                        <th>Tipo</th>
                        <th>Ação</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($usuarios as $u): ?>
                    <tr>
                        <td><?= $u['id'] ?></td>
                        <td><strong><?= htmlspecialchars($u['nome']) ?></strong></td>
                        <td><?= htmlspecialchars($u['email']) ?></td>
                        <td><?= htmlspecialchars($u['funcao'] ?: '—') ?></td>
                        <td><?= htmlspecialchars($u['setor_nome'] ?? '—') ?></td>
                        <td><span class="badge <?= $u['tipo'] === 'master' ? 'badge-alta' : 'badge-aberto' ?>"><?= $u['tipo'] === 'master' ? '👑 Master' : 'Funcionário' ?></span></td>
                        <td>
                            <?php if ($u['id'] != $_SESSION['usuario_id']): ?>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Excluir este usuário?')">
                                <input type="hidden" name="acao" value="excluir">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">Excluir</button>
                            </form>
                            <?php else: ?>
                                <span style="color:var(--cinza-texto); font-size:13px;">Você</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>
