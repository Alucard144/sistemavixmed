<?php
session_start();
if (!isset($_SESSION['logado'])) { header("Location: index.php"); exit(); }
if (($_SESSION['usuario_tipo'] ?? '') !== 'master') { header("Location: pagina.php"); exit(); }
require_once "conexao.php";

$mensagem = "";
$tipo_msg = "";

// Processar formulários POST
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['acao'])) {
    $acao = $_POST['acao'];
    
    try {
        if ($acao === 'cadastrar_produto') {
            $codigo = trim($_POST['codigo'] ?? '');
            $nome = trim($_POST['nome'] ?? '');
            $codigo_barras = trim($_POST['codigo_barras'] ?? '');
            $categoria = trim($_POST['categoria'] ?? 'Periféricos');
            $quantidade = intval($_POST['quantidade'] ?? 0);
            $estoque_minimo = intval($_POST['estoque_minimo'] ?? 5);

            if (empty($codigo) || empty($nome)) {
                throw new Exception("Código e Nome são obrigatórios!");
            }

            $pdo->beginTransaction();

            // Verificar duplicado
            $check = $pdo->prepare("SELECT COUNT(*) FROM produtos WHERE codigo = :codigo");
            $check->execute([':codigo' => $codigo]);
            if ($check->fetchColumn() > 0) {
                throw new Exception("Já existe um produto cadastrado com este código!");
            }

            // Inserir produto
            $stmt = $pdo->prepare("INSERT INTO produtos (codigo, nome, codigo_barras, categoria, quantidade, estoque_minimo) VALUES (:codigo, :nome, :codigo_barras, :categoria, :quantidade, :estoque_minimo)");
            $stmt->execute([
                ':codigo' => $codigo,
                ':nome' => $nome,
                ':codigo_barras' => !empty($codigo_barras) ? $codigo_barras : null,
                ':categoria' => $categoria,
                ':quantidade' => $quantidade,
                ':estoque_minimo' => $estoque_minimo
            ]);
            $produto_id = $pdo->lastInsertId();

            // Gravar movimentação inicial se houver estoque
            if ($quantidade > 0) {
                $stmt_mov = $pdo->prepare("INSERT INTO movimentacao_estoque (produto_id, tipo, quantidade, motivo, usuario_id) VALUES (:pid, 'entrada', :qtd, 'Estoque inicial cadastrado', :uid)");
                $stmt_mov->execute([
                    ':pid' => $produto_id,
                    ':qtd' => $quantidade,
                    ':uid' => $_SESSION['usuario_id']
                ]);
            }

            $pdo->commit();
            $mensagem = "Produto '$nome' cadastrado com sucesso!";
            $tipo_msg = "success";
        }
        
        elseif ($acao === 'editar_produto') {
            $id = intval($_POST['id'] ?? 0);
            $codigo = trim($_POST['codigo'] ?? '');
            $nome = trim($_POST['nome'] ?? '');
            $codigo_barras = trim($_POST['codigo_barras'] ?? '');
            $categoria = trim($_POST['categoria'] ?? 'Periféricos');
            $estoque_minimo = intval($_POST['estoque_minimo'] ?? 5);

            if ($id <= 0 || empty($codigo) || empty($nome)) {
                throw new Exception("Campos obrigatórios inválidos!");
            }

            // Verificar se o novo código colide com outro produto
            $check = $pdo->prepare("SELECT COUNT(*) FROM produtos WHERE codigo = :codigo AND id != :id");
            $check->execute([':codigo' => $codigo, ':id' => $id]);
            if ($check->fetchColumn() > 0) {
                throw new Exception("Outro produto já utiliza este código!");
            }

            $stmt = $pdo->prepare("UPDATE produtos SET codigo = :codigo, nome = :nome, codigo_barras = :codigo_barras, categoria = :categoria, estoque_minimo = :estoque_minimo WHERE id = :id");
            $stmt->execute([
                ':codigo' => $codigo,
                ':nome' => $nome,
                ':codigo_barras' => !empty($codigo_barras) ? $codigo_barras : null,
                ':categoria' => $categoria,
                ':estoque_minimo' => $estoque_minimo,
                ':id' => $id
            ]);

            $mensagem = "Produto atualizado com sucesso!";
            $tipo_msg = "success";
        }
        
        elseif ($acao === 'movimentar_estoque') {
            $produto_id = intval($_POST['produto_id'] ?? 0);
            $tipo = $_POST['tipo'] ?? ''; // 'entrada' ou 'saida'
            $quantidade = intval($_POST['quantidade'] ?? 0);
            $motivo = trim($_POST['motivo'] ?? '');

            if ($produto_id <= 0 || !in_array($tipo, ['entrada', 'saida']) || $quantidade <= 0 || empty($motivo)) {
                throw new Exception("Todos os campos de movimentação são obrigatórios!");
            }

            $pdo->beginTransaction();

            // Buscar quantidade atual
            $stmt_check = $pdo->prepare("SELECT nome, quantidade FROM produtos WHERE id = :id FOR UPDATE");
            $stmt_check->execute([':id' => $produto_id]);
            $produto = $stmt_check->fetch(PDO::FETCH_ASSOC);
            if (!$produto) {
                throw new Exception("Produto não encontrado!");
            }

            if ($tipo === 'saida' && $produto['quantidade'] < $quantidade) {
                throw new Exception("Estoque insuficiente para esta saída! Qtd atual: " . $produto['quantidade']);
            }

            // Atualizar estoque
            if ($tipo === 'entrada') {
                $stmt_up = $pdo->prepare("UPDATE produtos SET quantidade = quantidade + :qtd WHERE id = :id");
            } else {
                $stmt_up = $pdo->prepare("UPDATE produtos SET quantidade = quantidade - :qtd WHERE id = :id");
            }
            $stmt_up->execute([':qtd' => $quantidade, ':id' => $produto_id]);

            // Gravar log de movimentação
            $stmt_mov = $pdo->prepare("INSERT INTO movimentacao_estoque (produto_id, tipo, quantidade, motivo, usuario_id) VALUES (:pid, :tipo, :qtd, :motivo, :uid)");
            $stmt_mov->execute([
                ':pid' => $produto_id,
                ':tipo' => $tipo,
                ':qtd' => $quantidade,
                ':motivo' => $motivo,
                ':uid' => $_SESSION['usuario_id']
            ]);

            $pdo->commit();
            $mensagem = "Estoque do produto '{$produto['nome']}' ajustado com sucesso!";
            $tipo_msg = "success";
        }
        
        elseif ($acao === 'excluir_produto') {
            $id = intval($_POST['produto_id'] ?? 0);
            if ($id <= 0) {
                throw new Exception("Produto inválido!");
            }

            $stmt = $pdo->prepare("DELETE FROM produtos WHERE id = :id");
            $stmt->execute([':id' => $id]);

            $mensagem = "Produto excluído do estoque com sucesso!";
            $tipo_msg = "success";
        }

        elseif ($acao === 'transferir_produto') {
            $produto_id = intval($_POST['produto_id'] ?? 0);
            $funcionario_id = intval($_POST['funcionario_id'] ?? 0);
            $quantidade = intval($_POST['quantidade'] ?? 1);
            $observacao = trim($_POST['observacao'] ?? '');

            if ($produto_id <= 0 || $funcionario_id <= 0 || $quantidade <= 0) {
                throw new Exception("Todos os campos de transferência são obrigatórios!");
            }

            $pdo->beginTransaction();

            // Buscar estoque e validar
            $stmt_prod = $pdo->prepare("SELECT nome, codigo, quantidade FROM produtos WHERE id = :id FOR UPDATE");
            $stmt_prod->execute([':id' => $produto_id]);
            $produto = $stmt_prod->fetch(PDO::FETCH_ASSOC);
            if (!$produto) {
                throw new Exception("Produto não encontrado!");
            }
            if ($produto['quantidade'] < $quantidade) {
                throw new Exception("Estoque insuficiente para transferir. Disponível: " . $produto['quantidade']);
            }

            // Validar se o funcionário existe
            $stmt_func = $pdo->prepare("SELECT nome FROM usuarios WHERE id = :id");
            $stmt_func->execute([':id' => $funcionario_id]);
            $funcionario = $stmt_func->fetch(PDO::FETCH_ASSOC);
            if (!$funcionario) {
                throw new Exception("Funcionário destinatário não está cadastrado!");
            }

            // Deduzir quantidade
            $stmt_dec = $pdo->prepare("UPDATE produtos SET quantidade = quantidade - :qtd WHERE id = :id");
            $stmt_dec->execute([':qtd' => $quantidade, ':id' => $produto_id]);

            // Gravar transferência
            $stmt_trans = $pdo->prepare("INSERT INTO transferencias (produto_id, funcionario_id, quantidade, observacao, usuario_id) VALUES (:pid, :fid, :qtd, :obs, :uid)");
            $stmt_trans->execute([
                ':pid' => $produto_id,
                ':fid' => $funcionario_id,
                ':qtd' => $quantidade,
                ':obs' => !empty($observacao) ? $observacao : null,
                ':uid' => $_SESSION['usuario_id']
            ]);

            // Gravar log de movimentação de estoque
            $motivo = "Transferência para o funcionário: " . $funcionario['nome'];
            if (!empty($observacao)) {
                $motivo .= " (Obs: " . $observacao . ")";
            }
            $stmt_mov = $pdo->prepare("INSERT INTO movimentacao_estoque (produto_id, tipo, quantidade, motivo, usuario_id) VALUES (:pid, 'saida', :qtd, :motivo, :uid)");
            $stmt_mov->execute([
                ':pid' => $produto_id,
                ':qtd' => $quantidade,
                ':motivo' => $motivo,
                ':uid' => $_SESSION['usuario_id']
            ]);

            $pdo->commit();
            $mensagem = "Equipamento transferido com sucesso para {$funcionario['nome']}!";
            $tipo_msg = "success";
        }

        elseif ($acao === 'devolver_produto') {
            $transferencia_id = intval($_POST['transferencia_id'] ?? 0);
            if ($transferencia_id <= 0) {
                throw new Exception("Transferência inválida!");
            }

            $pdo->beginTransaction();

            // Obter transferência
            $stmt_trans = $pdo->prepare("SELECT t.*, p.nome as produto_nome, u.nome as funcionario_nome FROM transferencias t JOIN produtos p ON t.produto_id = p.id JOIN usuarios u ON t.funcionario_id = u.id WHERE t.id = :id FOR UPDATE");
            $stmt_trans->execute([':id' => $transferencia_id]);
            $trans = $stmt_trans->fetch(PDO::FETCH_ASSOC);

            if (!$trans) {
                throw new Exception("Transferência não encontrada!");
            }
            if ($trans['status'] === 'devolvido') {
                throw new Exception("Esta transferência já foi devolvida!");
            }

            // Alterar status
            $stmt_up = $pdo->prepare("UPDATE transferencias SET status = 'devolvido', data_devolucao = NOW() WHERE id = :id");
            $stmt_up->execute([':id' => $transferencia_id]);

            // Devolver para o estoque
            $stmt_add = $pdo->prepare("UPDATE produtos SET quantidade = quantidade + :qtd WHERE id = :id");
            $stmt_add->execute([':qtd' => $trans['quantidade'], ':id' => $trans['produto_id']]);

            // Gravar log de movimentação de estoque
            $motivo = "Devolução de equipamento do funcionário: " . $trans['funcionario_nome'];
            $stmt_mov = $pdo->prepare("INSERT INTO movimentacao_estoque (produto_id, tipo, quantidade, motivo, usuario_id) VALUES (:pid, 'entrada', :qtd, :motivo, :uid)");
            $stmt_mov->execute([
                ':pid' => $trans['produto_id'],
                ':qtd' => $trans['quantidade'],
                ':motivo' => $motivo,
                ':uid' => $_SESSION['usuario_id']
            ]);

            $pdo->commit();
            $mensagem = "Equipamento recebido de volta no estoque com sucesso!";
            $tipo_msg = "success";
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $mensagem = "Erro: " . $e->getMessage();
        $tipo_msg = "error";
    }
}

// Buscar todos os produtos
$produtos = $pdo->query("SELECT * FROM produtos ORDER BY categoria, nome")->fetchAll(PDO::FETCH_ASSOC);

// Buscar movimentações
$sql_mov = "SELECT m.*, p.nome as produto_nome, p.codigo as produto_codigo, u.nome as usuario_nome, c.id as chamado_id 
            FROM movimentacao_estoque m 
            JOIN produtos p ON m.produto_id = p.id 
            JOIN usuarios u ON m.usuario_id = u.id 
            LEFT JOIN chamados c ON m.chamado_id = c.id
            ORDER BY m.criado_em DESC";
$movimentacoes = $pdo->query($sql_mov)->fetchAll(PDO::FETCH_ASSOC);

// Buscar transferências
$sql_trans = "SELECT t.*, p.nome as produto_nome, p.codigo as produto_codigo, u_func.nome as funcionario_nome, u_func.email as funcionario_email, u_admin.nome as admin_nome 
              FROM transferencias t 
              JOIN produtos p ON t.produto_id = p.id 
              JOIN usuarios u_func ON t.funcionario_id = u_func.id 
              JOIN usuarios u_admin ON t.usuario_id = u_admin.id 
              ORDER BY t.data_transferencia DESC";
$transferencias = $pdo->query($sql_trans)->fetchAll(PDO::FETCH_ASSOC);

// Buscar funcionários cadastrados para o dropdown (todos os usuários do sistema)
$funcionarios_cadastrados = $pdo->query("SELECT id, nome, email, funcao FROM usuarios ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

// Filtrar produtos com estoque baixo
$alertas = array_filter($produtos, fn($p) => $p['quantidade'] <= $p['estoque_minimo']);

$pagina_atual = 'gerenciar_estoque';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Estoque - Vixmed</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* ===== ESTILOS TABS ===== */
        .tabs-header {
            display: flex;
            gap: 12px;
            border-bottom: 2px solid var(--cinza-borda);
            margin-bottom: 24px;
            flex-wrap: wrap;
        }
        
        .tab-btn {
            background: none;
            border: none;
            padding: 12px 24px;
            font-size: 15px;
            font-weight: 600;
            color: var(--cinza-texto);
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: var(--transition);
        }
        
        .tab-btn:hover {
            color: var(--texto-escuro);
        }
        
        .tab-btn.active {
            color: var(--verde);
            border-bottom-color: var(--verde);
        }
        
        .tab-content {
            display: none;
            animation: fadeIn 0.3s ease-in-out;
        }
        
        .tab-content.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(5px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ===== ESTILOS BUSCA E FILTROS ===== */
        .filters-container {
            display: flex;
            gap: 16px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .search-input-wrapper {
            flex: 1;
            min-width: 250px;
            position: relative;
        }

        .search-input-wrapper input {
            padding-left: 40px;
        }

        .search-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--cinza-texto);
            font-size: 16px;
            pointer-events: none;
        }

        /* ===== ESTILOS DE TABELA & BADGES ===== */
        .badge-estoque {
            font-weight: 700;
            font-size: 13px;
        }
        .badge-normal { background: rgba(0, 200, 83, 0.1); color: var(--verde); }
        .badge-baixo { background: rgba(245, 158, 11, 0.12); color: #d97706; }
        .badge-critico { background: rgba(239, 68, 68, 0.08); color: var(--vermelho); }

        .btn-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: 1px solid var(--cinza-borda);
            background: var(--branco);
            cursor: pointer;
            transition: var(--transition);
            font-size: 14px;
            text-decoration: none;
        }
        .btn-action:hover {
            background: var(--cinza-fundo);
            transform: scale(1.05);
        }

        /* ===== ESTILOS MODAL ===== */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(10, 22, 40, 0.5);
            backdrop-filter: blur(4px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
        }

        .modal-overlay.active {
            opacity: 1;
            pointer-events: auto;
        }

        .modal-box {
            background: var(--branco);
            border-radius: var(--radius);
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            width: 100%;
            max-width: 500px;
            padding: 28px;
            transform: scale(0.9);
            transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        .modal-overlay.active .modal-box {
            transform: scale(1);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-header h3 {
            font-size: 18px;
            font-weight: 700;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 20px;
            color: var(--cinza-texto);
            cursor: pointer;
        }
        .modal-close:hover { color: var(--vermelho); }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 24px;
        }
    </style>
</head>
<body>
<div class="app-container">
    <?php include 'sidebar.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <h2>📦 Controle de Estoque</h2>
            <div style="display: flex; gap: 12px;">
                <button onclick="openModal('modalTransfer')" class="btn btn-secondary">🤝 Transferir Periférico</button>
                <button onclick="openModal('modalAddProduct')" class="btn btn-primary">➕ Novo Produto</button>
            </div>
        </div>

        <?php if ($mensagem): ?>
            <div class="alert alert-<?= $tipo_msg ?>"><?= $mensagem ?></div>
        <?php endif; ?>

        <!-- Abas de Navegação -->
        <div class="tabs-header">
            <button class="tab-btn active" onclick="switchTab(event, 'tab-produtos')">📋 Produtos (<?= count($produtos) ?>)</button>
            <button class="tab-btn" onclick="switchTab(event, 'tab-transferencias')">🤝 Transferências (<?= count($transferencias) ?>)</button>
            <button class="tab-btn" onclick="switchTab(event, 'tab-movimentacoes')">🔄 Movimentações (<?= count($movimentacoes) ?>)</button>
            <button class="tab-btn" onclick="switchTab(event, 'tab-alertas')">
                ⚠️ Alertas 
                <?php if (count($alertas) > 0): ?>
                    <span class="badge badge-alta" style="padding:2px 8px; font-size:10px; margin-left:4px;"><?= count($alertas) ?></span>
                <?php endif; ?>
            </button>
        </div>

        <!-- CONTEÚDO DA ABA PRODUTOS -->
        <div id="tab-produtos" class="tab-content active">
            <!-- Filtros de Busca -->
            <div class="filters-container">
                <div class="search-input-wrapper">
                    <span class="search-icon">🔍</span>
                    <input type="text" id="searchProduct" placeholder="Buscar por código, nome ou código de barras..." onkeyup="filterProducts()">
                </div>
                <div style="width: 200px;">
                    <select id="filterCategory" onchange="filterProducts()">
                        <option value="">Todas as categorias</option>
                        <option value="Periféricos">Periféricos</option>
                        <option value="Telas">Telas</option>
                        <option value="Computadores">Computadores</option>
                        <option value="Cabos">Cabos</option>
                        <option value="Outros">Outros</option>
                    </select>
                </div>
            </div>

            <div class="table-container">
                <table id="productsTable">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Nome do Produto</th>
                            <th>Código de Barras</th>
                            <th>Categoria</th>
                            <th style="text-align: center;">Quantidade</th>
                            <th>Min.</th>
                            <th style="text-align: center; width: 150px;">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($produtos as $p): 
                        $statusClass = 'badge-normal';
                        if ($p['quantidade'] <= 0) {
                            $statusClass = 'badge-critico';
                        } elseif ($p['quantidade'] <= $p['estoque_minimo']) {
                            $statusClass = 'badge-baixo';
                        }
                    ?>
                        <tr class="product-row" data-codigo="<?= htmlspecialchars(strtolower($p['codigo'])) ?>" data-nome="<?= htmlspecialchars(strtolower($p['nome'])) ?>" data-barras="<?= htmlspecialchars(strtolower($p['codigo_barras'] ?? '')) ?>" data-categoria="<?= htmlspecialchars($p['categoria']) ?>">
                            <td><strong><?= htmlspecialchars($p['codigo']) ?></strong></td>
                            <td><?= htmlspecialchars($p['nome']) ?></td>
                            <td style="font-family: monospace; font-size: 13px; color: var(--cinza-texto);"><?= htmlspecialchars($p['codigo_barras'] ?: '—') ?></td>
                            <td><span class="badge" style="background: rgba(10, 22, 40, 0.05); color: var(--azul-escuro);"><?= htmlspecialchars($p['categoria']) ?></span></td>
                            <td style="text-align: center;">
                                <span class="badge badge-estoque <?= $statusClass ?>">
                                    <?= $p['quantidade'] ?>
                                </span>
                            </td>
                            <td style="color: var(--cinza-texto); font-weight: 500;"><?= $p['estoque_minimo'] ?></td>
                            <td style="text-align: center;">
                                <div style="display: flex; gap: 6px; justify-content: center;">
                                    <button onclick="openAdjustStock(<?= $p['id'] ?>, '<?= htmlspecialchars($p['nome']) ?>')" class="btn-action" title="Movimentar estoque">⚖️</button>
                                    <button onclick="openEditProduct(<?= htmlspecialchars(json_encode($p)) ?>)" class="btn-action" title="Editar produto">✏️</button>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Deseja realmente excluir este produto? Isso também apagará o histórico de movimentações dele.')">
                                        <input type="hidden" name="acao" value="excluir_produto">
                                        <input type="hidden" name="produto_id" value="<?= $p['id'] ?>">
                                        <button type="submit" class="btn-action" style="color: var(--vermelho); border-color: rgba(239, 68, 68, 0.2);" title="Excluir">🗑️</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($produtos)): ?>
                        <tr class="no-data"><td colspan="7" style="text-align:center; padding:40px; color:var(--cinza-texto);">Nenhum produto cadastrado</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- CONTEÚDO DA ABA TRANSFERÊNCIAS -->
        <div id="tab-transferencias" class="tab-content">
            <div class="filters-container">
                <div class="search-input-wrapper">
                    <span class="search-icon">🔍</span>
                    <input type="text" id="searchTransfer" placeholder="Buscar por funcionário, produto ou código..." onkeyup="filterTransfers()">
                </div>
            </div>

            <div class="table-container">
                <table id="transfersTable">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Produto</th>
                            <th>Funcionário Beneficiário</th>
                            <th style="text-align: center;">Quantidade</th>
                            <th>Status</th>
                            <th>Devolvido Em</th>
                            <th>Responsável (Admin)</th>
                            <th>Observação</th>
                            <th style="text-align: center; width: 120px;">Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($transferencias as $t): ?>
                        <tr class="transfer-row" data-funcionario="<?= htmlspecialchars(strtolower($t['funcionario_nome'])) ?>" data-produto="<?= htmlspecialchars(strtolower($t['produto_nome'])) ?>" data-codigo="<?= htmlspecialchars(strtolower($t['produto_codigo'])) ?>">
                            <td style="white-space: nowrap; font-size:13px;"><?= date('d/m/Y H:i', strtotime($t['data_transferencia'])) ?></td>
                            <td><strong><?= htmlspecialchars($t['produto_nome']) ?></strong> <span style="font-family: monospace; font-size: 11px; color: var(--cinza-texto);">(<?= htmlspecialchars($t['produto_codigo']) ?>)</span></td>
                            <td>
                                <strong><?= htmlspecialchars($t['funcionario_nome']) ?></strong>
                                <br><span style="font-size:11px; color: var(--cinza-texto);"><?= htmlspecialchars($t['funcionario_email']) ?></span>
                            </td>
                            <td style="text-align: center; font-weight:700;"><?= $t['quantidade'] ?></td>
                            <td>
                                <span class="badge badge-<?= $t['status'] === 'entregue' ? 'em_andamento' : 'resolvido' ?>">
                                    <?= $t['status'] === 'entregue' ? 'Entregue' : 'Devolvido' ?>
                                </span>
                            </td>
                            <td style="font-size:13px; color:var(--cinza-texto);">
                                <?= $t['data_devolucao'] ? date('d/m/Y H:i', strtotime($t['data_devolucao'])) : '—' ?>
                            </td>
                            <td><?= htmlspecialchars($t['admin_nome']) ?></td>
                            <td style="font-size:13px; color:var(--cinza-texto);"><?= htmlspecialchars($t['observacao'] ?: '—') ?></td>
                            <td style="text-align: center;">
                                <?php if ($t['status'] === 'entregue'): ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Confirmar o recebimento e devolução deste equipamento para o estoque?')">
                                        <input type="hidden" name="acao" value="devolver_produto">
                                        <input type="hidden" name="transferencia_id" value="<?= $t['id'] ?>">
                                        <button type="submit" class="btn btn-secondary btn-sm" style="padding: 4px 10px;">⚡ Receber</button>
                                    </form>
                                <?php else: ?>
                                    <span style="color:var(--cinza-texto); font-size:12px;">Finalizado</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($transferencias)): ?>
                        <tr class="no-data-t"><td colspan="9" style="text-align:center; padding:40px; color:var(--cinza-texto);">Nenhuma transferência de periféricos registrada</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- CONTEÚDO DA ABA MOVIMENTAÇÕES -->
        <div id="tab-movimentacoes" class="tab-content">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Código</th>
                            <th>Produto</th>
                            <th>Tipo</th>
                            <th style="text-align: center;">Qtd</th>
                            <th>Responsável</th>
                            <th>Motivo / Observação</th>
                            <th>Vínculo</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($movimentacoes as $m): ?>
                        <tr>
                            <td style="white-space: nowrap; font-size:13px;"><?= date('d/m/Y H:i', strtotime($m['criado_em'])) ?></td>
                            <td><span style="font-family: monospace; font-weight:600;"><?= htmlspecialchars($m['produto_codigo']) ?></span></td>
                            <td><?= htmlspecialchars($m['produto_nome']) ?></td>
                            <td>
                                <span class="badge badge-<?= $m['tipo'] === 'entrada' ? 'aberto' : 'fechado' ?>">
                                    <?= $m['tipo'] === 'entrada' ? '➕ Entrada' : '➖ Saída' ?>
                                </span>
                            </td>
                            <td style="text-align: center; font-weight:700;"><?= $m['quantidade'] ?></td>
                            <td><?= htmlspecialchars($m['usuario_nome']) ?></td>
                            <td style="font-size:13px; color:var(--cinza-texto);"><?= htmlspecialchars($m['motivo']) ?></td>
                            <td>
                                <?php if ($m['chamado_id']): ?>
                                    <a href="ver_chamado.php?id=<?= $m['chamado_id'] ?>" style="font-size:12px; font-weight:600;">💬 Chamado #<?= $m['chamado_id'] ?></a>
                                <?php else: ?>
                                    <span style="color:var(--cinza-borda);">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($movimentacoes)): ?>
                        <tr><td colspan="8" style="text-align:center; padding:40px; color:var(--cinza-texto);">Nenhuma movimentação registrada</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- CONTEÚDO DA ABA ALERTAS -->
        <div id="tab-alertas" class="tab-content">
            <?php if (empty($alertas)): ?>
                <div class="card" style="text-align:center; padding:40px;">
                    <div style="font-size:48px; margin-bottom:12px;">✅</div>
                    <h3>Tudo sob controle!</h3>
                    <p style="color:var(--cinza-texto);">Nenhum produto está com estoque abaixo do mínimo estabelecido.</p>
                </div>
            <?php else: ?>
                <div class="alert alert-error" style="margin-bottom: 20px;">
                    ⚠️ Atenção: Os seguintes produtos requerem reposição imediata de estoque.
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Nome do Produto</th>
                                <th>Categoria</th>
                                <th style="text-align: center;">Quantidade Atual</th>
                                <th>Estoque Mínimo</th>
                                <th>Situação</th>
                                <th style="text-align: center;">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($alertas as $a): 
                            $critico = $a['quantidade'] <= 0;
                        ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($a['codigo']) ?></strong></td>
                                <td><?= htmlspecialchars($a['nome']) ?></td>
                                <td><?= htmlspecialchars($a['categoria']) ?></td>
                                <td style="text-align: center;">
                                    <span class="badge badge-estoque <?= $critico ? 'badge-critico' : 'badge-baixo' ?>">
                                        <?= $a['quantidade'] ?>
                                    </span>
                                </td>
                                <td><?= $a['estoque_minimo'] ?></td>
                                <td>
                                    <span class="badge <?= $critico ? 'badge-critico' : 'badge-media' ?>" style="font-size:11px;">
                                        <?= $critico ? 'Esgotado' : 'Estoque Baixo' ?>
                                    </span>
                                </td>
                                <td style="text-align: center;">
                                    <button onclick="openAdjustStock(<?= $a['id'] ?>, '<?= htmlspecialchars($a['nome']) ?>')" class="btn btn-secondary btn-sm" style="padding: 4px 10px;">⚡ Repor</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ================= MODAL CADASTRAR PRODUTO ================= -->
<div id="modalAddProduct" class="modal-overlay" onclick="closeModalOnOverlay(event, 'modalAddProduct')">
    <div class="modal-box">
        <div class="modal-header">
            <h3>➕ Cadastrar Novo Produto</h3>
            <button onclick="closeModal('modalAddProduct')" class="modal-close">✕</button>
        </div>
        <form method="POST">
            <input type="hidden" name="acao" value="cadastrar_produto">
            
            <div class="form-group">
                <label>Categoria *</label>
                <select name="categoria" required>
                    <option value="Periféricos">Periféricos (Mouse, Teclado, etc.)</option>
                    <option value="Telas">Telas (Monitores, Displays)</option>
                    <option value="Computadores">Computadores (Notebooks, Desktops)</option>
                    <option value="Cabos">Cabos (HDMI, Rede, Fontes)</option>
                    <option value="Outros">Outros</option>
                </select>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label>Código Interno *</label>
                    <input type="text" name="codigo" placeholder="Ex: MSE-001" required>
                </div>
                <div class="form-group">
                    <label>Código de Barras</label>
                    <input type="text" name="codigo_barras" placeholder="Ex: 789123...">
                </div>
            </div>

            <div class="form-group">
                <label>Nome do Produto *</label>
                <input type="text" name="nome" placeholder="Ex: Mouse USB Dell MS116" required>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label>Qtd Inicial em Estoque</label>
                    <input type="number" name="quantidade" value="0" min="0" required>
                </div>
                <div class="form-group">
                    <label>Mínimo de Alerta (Estoque Mínimo)</label>
                    <input type="number" name="estoque_minimo" value="5" min="0" required>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" onclick="closeModal('modalAddProduct')" class="btn btn-secondary">Cancelar</button>
                <button type="submit" class="btn btn-primary">Cadastrar</button>
            </div>
        </form>
    </div>
</div>

<!-- ================= MODAL EDITAR PRODUTO ================= -->
<div id="modalEditProduct" class="modal-overlay" onclick="closeModalOnOverlay(event, 'modalEditProduct')">
    <div class="modal-box">
        <div class="modal-header">
            <h3>✏️ Editar Produto</h3>
            <button onclick="closeModal('modalEditProduct')" class="modal-close">✕</button>
        </div>
        <form method="POST">
            <input type="hidden" name="acao" value="editar_produto">
            <input type="hidden" name="id" id="edit_id">
            
            <div class="form-group">
                <label>Categoria *</label>
                <select name="categoria" id="edit_categoria" required>
                    <option value="Periféricos">Periféricos</option>
                    <option value="Telas">Telas</option>
                    <option value="Computadores">Computadores</option>
                    <option value="Cabos">Cabos</option>
                    <option value="Outros">Outros</option>
                </select>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label>Código Interno *</label>
                    <input type="text" name="codigo" id="edit_codigo" required>
                </div>
                <div class="form-group">
                    <label>Código de Barras</label>
                    <input type="text" name="codigo_barras" id="edit_codigo_barras">
                </div>
            </div>

            <div class="form-group">
                <label>Nome do Produto *</label>
                <input type="text" name="nome" id="edit_nome" required>
            </div>

            <div class="form-group" style="max-width: 50%;">
                <label>Estoque Mínimo</label>
                <input type="number" name="estoque_minimo" id="edit_estoque_minimo" min="0" required>
            </div>

            <div class="modal-footer">
                <button type="button" onclick="closeModal('modalEditProduct')" class="btn btn-secondary">Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar Alterações</button>
            </div>
        </form>
    </div>
</div>

<!-- ================= MODAL AJUSTAR ESTOQUE ================= -->
<div id="modalAdjustStock" class="modal-overlay" onclick="closeModalOnOverlay(event, 'modalAdjustStock')">
    <div class="modal-box">
        <div class="modal-header">
            <h3>⚖️ Movimentação de Estoque</h3>
            <button onclick="closeModal('modalAdjustStock')" class="modal-close">✕</button>
        </div>
        <p style="font-size:14px; margin-bottom:16px; color:var(--cinza-texto);">Produto: <strong id="adjust_prod_name" style="color:var(--texto-escuro);">-</strong></p>
        
        <form method="POST">
            <input type="hidden" name="acao" value="movimentar_estoque">
            <input type="hidden" name="produto_id" id="adjust_prod_id">
            
            <div class="form-group">
                <label>Tipo de Operação</label>
                <select name="tipo" required>
                    <option value="entrada">🟢 Entrada (Adicionar no estoque)</option>
                    <option value="saida">🔴 Saída (Retirar do estoque)</option>
                </select>
            </div>

            <div class="form-group">
                <label>Quantidade</label>
                <input type="number" name="quantidade" value="1" min="1" required>
            </div>

            <div class="form-group">
                <label>Motivo / Justificativa *</label>
                <input type="text" name="motivo" placeholder="Ex: Compra de lote, Descarte, Empréstimo, etc." required>
            </div>

            <div class="modal-footer">
                <button type="button" onclick="closeModal('modalAdjustStock')" class="btn btn-secondary">Cancelar</button>
                <button type="submit" class="btn btn-primary">Registrar</button>
            </div>
        </form>
    </div>
</div>

<!-- ================= MODAL TRANSFERIR EQUIPAMENTO ================= -->
<div id="modalTransfer" class="modal-overlay" onclick="closeModalOnOverlay(event, 'modalTransfer')">
    <div class="modal-box">
        <div class="modal-header">
            <h3>🤝 Transferir Equipamento para Funcionário</h3>
            <button onclick="closeModal('modalTransfer')" class="modal-close">✕</button>
        </div>
        <form method="POST">
            <input type="hidden" name="acao" value="transferir_produto">
            
            <div class="form-group">
                <label>Produto *</label>
                <select name="produto_id" required style="background: var(--branco);">
                    <option value="">Selecione um produto com estoque...</option>
                    <?php foreach ($produtos as $p): 
                        if ($p['quantidade'] > 0): ?>
                        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nome']) ?> (Disponível: <?= $p['quantidade'] ?> | Cód: <?= $p['codigo'] ?>)</option>
                    <?php endif; endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Funcionário Destinatário *</label>
                <select name="funcionario_id" required style="background: var(--branco);">
                    <option value="">Selecione um funcionário cadastrado...</option>
                    <?php foreach ($funcionarios_cadastrados as $f): ?>
                        <option value="<?= $f['id'] ?>"><?= htmlspecialchars($f['nome']) ?> (<?= htmlspecialchars($f['email']) ?><?php if(!empty($f['funcao'])) echo " — " . htmlspecialchars($f['funcao']); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="max-width: 50%;">
                <label>Quantidade *</label>
                <input type="number" name="quantidade" value="1" min="1" required>
            </div>

            <div class="form-group">
                <label>Observação / Justificativa</label>
                <input type="text" name="observacao" placeholder="Ex: Teclado para home office, Substituição de mouse com defeito">
            </div>

            <div class="modal-footer">
                <button type="button" onclick="closeModal('modalTransfer')" class="btn btn-secondary">Cancelar</button>
                <button type="submit" class="btn btn-primary">Realizar Transferência</button>
            </div>
        </form>
    </div>
</div>

<script>
    // ===== CONTROLE DE ABAS =====
    function switchTab(evt, tabId) {
        // Ocultar todos os conteúdos
        const contents = document.getElementsByClassName("tab-content");
        for (let i = 0; i < contents.length; i++) {
            contents[i].classList.remove("active");
        }

        // Remover classe active de todos os botões
        const buttons = document.getElementsByClassName("tab-btn");
        for (let i = 0; i < buttons.length; i++) {
            buttons[i].classList.remove("active");
        }

        // Exibir o container correspondente e marcar botão
        document.getElementById(tabId).classList.add("active");
        evt.currentTarget.classList.add("active");
    }

    // ===== FILTRO DE PRODUTOS JAVASCRIPT =====
    function filterProducts() {
        const query = document.getElementById("searchProduct").value.toLowerCase();
        const category = document.getElementById("filterCategory").value;
        const rows = document.getElementsByClassName("product-row");
        let foundAny = false;

        for (let i = 0; i < rows.length; i++) {
            const row = rows[i];
            const codigo = row.getAttribute("data-codigo");
            const nome = row.getAttribute("data-nome");
            const barras = row.getAttribute("data-barras");
            const cat = row.getAttribute("data-categoria");

            const matchesSearch = codigo.includes(query) || nome.includes(query) || barras.includes(query);
            const matchesCategory = category === "" || cat === category;

            if (matchesSearch && matchesCategory) {
                row.style.display = "";
                foundAny = true;
            } else {
                row.style.display = "none";
            }
        }

        // Exibir mensagem se não achar nada
        let noDataRow = document.querySelector("#productsTable .no-data-js");
        if (!foundAny) {
            if (!noDataRow) {
                noDataRow = document.createElement("tr");
                noDataRow.className = "no-data-js";
                noDataRow.innerHTML = `<td colspan="7" style="text-align:center; padding:40px; color:var(--cinza-texto);">Nenhum produto correspondente aos filtros</td>`;
                document.querySelector("#productsTable tbody").appendChild(noDataRow);
            }
        } else if (noDataRow) {
            noDataRow.remove();
        }
    }

    // ===== FILTRO DE TRANSFERÊNCIAS JAVASCRIPT =====
    function filterTransfers() {
        const query = document.getElementById("searchTransfer").value.toLowerCase();
        const rows = document.getElementsByClassName("transfer-row");
        let foundAny = false;

        for (let i = 0; i < rows.length; i++) {
            const row = rows[i];
            const funcionario = row.getAttribute("data-funcionario");
            const produto = row.getAttribute("data-produto");
            const codigo = row.getAttribute("data-codigo");

            const matches = funcionario.includes(query) || produto.includes(query) || codigo.includes(query);

            if (matches) {
                row.style.display = "";
                foundAny = true;
            } else {
                row.style.display = "none";
            }
        }

        let noDataRow = document.querySelector("#transfersTable .no-data-js-t");
        if (!foundAny) {
            if (!noDataRow) {
                noDataRow = document.createElement("tr");
                noDataRow.className = "no-data-js-t";
                noDataRow.innerHTML = `<td colspan="9" style="text-align:center; padding:40px; color:var(--cinza-texto);">Nenhuma transferência correspondente</td>`;
                document.querySelector("#transfersTable tbody").appendChild(noDataRow);
            }
        } else if (noDataRow) {
            noDataRow.remove();
        }
    }

    // ===== CONTROLE DOS MODALS =====
    function openModal(modalId) {
        document.getElementById(modalId).classList.add("active");
    }

    function closeModal(modalId) {
        document.getElementById(modalId).classList.remove("active");
    }

    function closeModalOnOverlay(e, modalId) {
        if (e.target === e.currentTarget) {
            closeModal(modalId);
        }
    }

    // Modal Ajustar Estoque
    function openAdjustStock(id, nome) {
        document.getElementById("adjust_prod_id").value = id;
        document.getElementById("adjust_prod_name").textContent = nome;
        openModal("modalAdjustStock");
    }

    // Modal Editar Produto
    function openEditProduct(p) {
        document.getElementById("edit_id").value = p.id;
        document.getElementById("edit_codigo").value = p.codigo;
        document.getElementById("edit_nome").value = p.nome;
        document.getElementById("edit_codigo_barras").value = p.codigo_barras || '';
        document.getElementById("edit_categoria").value = p.categoria;
        document.getElementById("edit_estoque_minimo").value = p.estoque_minimo;
        openModal("modalEditProduct");
    }
</script>
</body>
</html>
