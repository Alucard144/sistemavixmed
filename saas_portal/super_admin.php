<?php
// super_admin.php - Painel de Controle das Feature Flags e Assinaturas (Master dos Masters)
session_start();

if (!isset($_SESSION['logado'])) { 
    header("Location: index.php"); 
    exit(); 
}

if (($_SESSION['usuario_super_admin'] ?? 0) != 1) { 
    header("Location: pagina.php"); 
    exit(); 
}

require_once "conexao.php";
require_once "config_saas.php"; // Mantém validação básica

$mensagem = "";
$tipo_msg = "";

// Processar formulários POST
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['acao'])) {
    $acao = $_POST['acao'];
    
    try {
        if ($acao === 'cadastrar_empresa') {
            $nome = trim($_POST['nome_fantasia'] ?? '');
            $email = trim($_POST['email_financeiro'] ?? '');
            $status = $_POST['status_assinatura'] ?? 'teste';
            $expiracao = $_POST['data_expiracao'] ?? date('Y-m-d', strtotime('+14 days'));
            $cobranca = isset($_POST['cobranca_automatica']) ? 1 : 0;
            $mp_id = trim($_POST['mp_preapproval_id'] ?? '');
            $estoque = isset($_POST['recurso_estoque']) ? 1 : 0;
            $transferencias = isset($_POST['recurso_transferencias']) ? 1 : 0;

            if (empty($nome) || empty($email)) {
                throw new Exception("Nome Fantasia e E-mail Financeiro são obrigatórios!");
            }

            $stmt = $pdo->prepare("INSERT INTO saas_empresas (nome_fantasia, email_financeiro, status_assinatura, data_expiracao, cobranca_automatica, mp_preapproval_id, recurso_estoque, recurso_transferencias) 
                                   VALUES (:nome, :email, :status, :exp, :cob, :mp_id, :est, :trans)");
            $stmt->execute([
                ':nome' => $nome,
                ':email' => $email,
                ':status' => $status,
                ':exp' => $expiracao,
                ':cob' => $cobranca,
                ':mp_id' => !empty($mp_id) ? $mp_id : null,
                ':est' => $estoque,
                ':trans' => $transferencias
            ]);

            $mensagem = "Empresa '$nome' cadastrada com sucesso!";
            $tipo_msg = "success";
        }
        
        elseif ($acao === 'atualizar_empresa') {
            $id = intval($_POST['empresa_id'] ?? 0);
            $nome = trim($_POST['nome_fantasia'] ?? '');
            $email = trim($_POST['email_financeiro'] ?? '');
            $status = $_POST['status_assinatura'] ?? 'teste';
            $expiracao = $_POST['data_expiracao'] ?? '';
            $cobranca = isset($_POST['cobranca_automatica']) ? 1 : 0;
            $mp_id = trim($_POST['mp_preapproval_id'] ?? '');
            $estoque = isset($_POST['recurso_estoque']) ? 1 : 0;
            $transferencias = isset($_POST['recurso_transferencias']) ? 1 : 0;

            if ($id <= 0 || empty($nome) || empty($email) || empty($expiracao)) {
                throw new Exception("Campos obrigatórios inválidos!");
            }

            $stmt = $pdo->prepare("UPDATE saas_empresas SET 
                nome_fantasia = :nome,
                email_financeiro = :email,
                status_assinatura = :status,
                data_expiracao = :exp,
                cobranca_automatica = :cob,
                mp_preapproval_id = :mp_id,
                recurso_estoque = :est,
                recurso_transferencias = :trans
                WHERE id = :id");
            $stmt->execute([
                ':nome' => $nome,
                ':email' => $email,
                ':status' => $status,
                ':exp' => $expiracao,
                ':cob' => $cobranca,
                ':mp_id' => !empty($mp_id) ? $mp_id : null,
                ':est' => $estoque,
                ':trans' => $transferencias,
                ':id' => $id
            ]);

            // Se for a própria empresa do usuário logado, atualiza as flags na sessão de forma imediata!
            if ($id == $_SESSION['usuario_empresa_id']) {
                $_SESSION['recurso_estoque'] = $estoque;
                $_SESSION['recurso_transferencias'] = $transferencias;
            }

            $mensagem = "Configurações da empresa '$nome' atualizadas com sucesso!";
            $tipo_msg = "success";
        }
    } catch (Exception $e) {
        $mensagem = "Erro: " . $e->getMessage();
        $tipo_msg = "error";
    }
}

// Buscar todas as empresas cadastradas
$empresas = $pdo->query("SELECT * FROM saas_empresas ORDER BY nome_fantasia")->fetchAll(PDO::FETCH_ASSOC);

$pagina_atual = 'super_admin';
$tipo = $_SESSION['usuario_tipo'] ?? 'funcionario';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel Super Admin - Gestão SaaS</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .form-row {
            display: flex;
            gap: 16px;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }
        .form-col {
            flex: 1;
            min-width: 150px;
        }
        .flag-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            cursor: pointer;
            margin-top: 10px;
        }
        .flag-checkbox input {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .badge-ativo { background: #e8f5e9; color: #2e7d32; }
        .badge-suspenso { background: #ffebee; color: #c62828; }
        .badge-teste { background: #fff8e1; color: #f57f17; }
        
        .companies-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
            background: var(--branco);
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        .companies-table th, .companies-table td {
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid var(--cinza-borda);
            font-size: 13px;
        }
        .companies-table th {
            background-color: var(--cinza-borda);
            color: var(--texto-escuro);
            font-weight: 600;
        }
        .companies-table tr:hover {
            background-color: #fcfcfc;
        }
    </style>
</head>
<body>
<div class="app-container">
    <?php include 'sidebar.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <h2>🌐 Painel Super Admin - Gestão de Clientes</h2>
            <button onclick="openModal('modalAddCompany')" class="btn btn-primary">➕ Cadastrar Nova Empresa</button>
        </div>

        <?php if ($mensagem): ?>
            <div class="alert alert-<?= $tipo_msg ?>"><?= $mensagem ?></div>
        <?php endif; ?>

        <!-- Listagem de Empresas -->
        <div class="card" style="padding: 20px;">
            <h3>Clientes Cadastrados (Multi-tenant)</h3>
            <div style="overflow-x: auto;">
                <table class="companies-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Empresa</th>
                            <th>Financeiro</th>
                            <th>Assinatura</th>
                            <th>Expiração</th>
                            <th>Cobrança Automática</th>
                            <th>Recursos Ativos</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($empresas as $e): ?>
                        <tr>
                            <td><strong>#<?= $e['id'] ?></strong></td>
                            <td><?= htmlspecialchars($e['nome_fantasia']) ?></td>
                            <td><?= htmlspecialchars($e['email_financeiro']) ?></td>
                            <td>
                                <span class="badge badge-<?= $e['status_assinatura'] ?>">
                                    <?= $e['status_assinatura'] ?>
                                </span>
                            </td>
                            <td><?= date('d/m/Y', strtotime($e['data_expiracao'])) ?></td>
                            <td><?= $e['cobranca_automatica'] == 1 ? '✅ Mercado Pago' : '❌ Manual / Cortesia' ?></td>
                            <td>
                                <div style="display:flex; gap:8px;">
                                    <?= $e['recurso_estoque'] == 1 ? '<span style="font-size:16px;" title="Estoque Ativo">📦</span>' : '' ?>
                                    <?= $e['recurso_transferencias'] == 1 ? '<span style="font-size:16px;" title="Transferências Ativas">🤝</span>' : '' ?>
                                </div>
                            </td>
                            <td>
                                <button class="btn btn-secondary btn-sm" onclick="editCompany(<?= htmlspecialchars(json_encode($e)) ?>)">⚙️ Configurar</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- MODAL CADASTRAR EMPRESA -->
        <div id="modalAddCompany" class="modal-overlay" onclick="closeModalOnOverlay(event, 'modalAddCompany')" style="display:none;">
            <div class="modal-content" style="max-width: 600px;">
                <button onclick="closeModal('modalAddCompany')" class="modal-close">✕</button>
                <h2>➕ Cadastrar Nova Empresa</h2>
                
                <form method="POST" style="margin-top:20px;">
                    <input type="hidden" name="acao" value="cadastrar_empresa">
                    
                    <div class="form-row">
                        <div class="form-col">
                            <label class="form-label">Nome Fantasia</label>
                            <input type="text" name="nome_fantasia" required placeholder="Ex: Clínica Sorriso" class="form-input">
                        </div>
                        <div class="form-col">
                            <label class="form-label">E-mail Financeiro</label>
                            <input type="email" name="email_financeiro" required placeholder="financeiro@empresa.com" class="form-input">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-col">
                            <label class="form-label">Status Assinatura</label>
                            <select name="status_assinatura" class="form-input">
                                <option value="teste" selected>Teste (Gratuito)</option>
                                <option value="ativo">Ativo</option>
                                <option value="suspenso">Suspenso</option>
                            </select>
                        </div>
                        <div class="form-col">
                            <label class="form-label">Data de Expiração</label>
                            <input type="date" name="data_expiracao" value="<?= date('Y-m-d', strtotime('+14 days')) ?>" class="form-input">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-col">
                            <label class="form-label">ID Assinatura Mercado Pago (opcional)</label>
                            <input type="text" name="mp_preapproval_id" placeholder="preapproval_plan_id ou similar" class="form-input">
                        </div>
                    </div>

                    <div style="background:var(--cinza-claro); padding:16px; border-radius:8px; margin-bottom: 20px;">
                        <h4>🛠️ Módulos Adicionais (Feature Flags)</h4>
                        <div style="display:flex; gap:20px; flex-wrap:wrap;">
                            <label class="flag-checkbox">
                                <input type="checkbox" name="recurso_estoque" value="1" checked> 📦 Habilitar Controle de Estoque
                            </label>
                            <label class="flag-checkbox">
                                <input type="checkbox" name="recurso_transferencias" value="1" checked> 🤝 Habilitar Transferências de Periféricos
                            </label>
                        </div>
                        <label class="flag-checkbox" style="margin-top: 14px; border-top: 1px solid #e0e0e0; padding-top: 10px;">
                            <input type="checkbox" name="cobranca_automatica" value="1" checked> 💳 Cobrança Automática (Ativa Mercado Pago)
                        </label>
                    </div>

                    <div style="display:flex; gap:12px; justify-content:flex-end;">
                        <button type="button" onclick="closeModal('modalAddCompany')" class="btn btn-secondary">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Cadastrar Empresa</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- MODAL EDITAR EMPRESA -->
        <div id="modalEditCompany" class="modal-overlay" onclick="closeModalOnOverlay(event, 'modalEditCompany')" style="display:none;">
            <div class="modal-content" style="max-width: 600px;">
                <button onclick="closeModal('modalEditCompany')" class="modal-close">✕</button>
                <h2>⚙️ Configurar Empresa: <span id="editTitle"></span></h2>
                
                <form method="POST" style="margin-top:20px;">
                    <input type="hidden" name="acao" value="atualizar_empresa">
                    <input type="hidden" name="empresa_id" id="editId">
                    
                    <div class="form-row">
                        <div class="form-col">
                            <label class="form-label">Nome Fantasia</label>
                            <input type="text" name="nome_fantasia" id="editNome" required class="form-input">
                        </div>
                        <div class="form-col">
                            <label class="form-label">E-mail Financeiro</label>
                            <input type="email" name="email_financeiro" id="editEmail" required class="form-input">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-col">
                            <label class="form-label">Status Assinatura</label>
                            <select name="status_assinatura" id="editStatus" class="form-input">
                                <option value="teste">Teste (Gratuito)</option>
                                <option value="ativo">Ativo</option>
                                <option value="suspenso">Suspenso</option>
                            </select>
                        </div>
                        <div class="form-col">
                            <label class="form-label">Data de Expiração</label>
                            <input type="date" name="data_expiracao" id="editExp" class="form-input">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-col">
                            <label class="form-label">ID Assinatura Mercado Pago (opcional)</label>
                            <input type="text" name="mp_preapproval_id" id="editMpId" class="form-input">
                        </div>
                    </div>

                    <div style="background:var(--cinza-claro); padding:16px; border-radius:8px; margin-bottom: 20px;">
                        <h4>🛠️ Módulos Adicionais (Feature Flags)</h4>
                        <div style="display:flex; gap:20px; flex-wrap:wrap;">
                            <label class="flag-checkbox">
                                <input type="checkbox" name="recurso_estoque" id="editEstoque" value="1"> 📦 Habilitar Controle de Estoque
                            </label>
                            <label class="flag-checkbox">
                                <input type="checkbox" name="recurso_transferencias" id="editTransferencias" value="1"> 🤝 Habilitar Transferências de Periféricos
                            </label>
                        </div>
                        <label class="flag-checkbox" style="margin-top: 14px; border-top: 1px solid #e0e0e0; padding-top: 10px;">
                            <input type="checkbox" name="cobranca_automatica" id="editCobranca" value="1"> 💳 Cobrança Automática (Ativa Mercado Pago)
                        </label>
                    </div>

                    <div style="display:flex; gap:12px; justify-content:flex-end;">
                        <button type="button" onclick="closeModal('modalEditCompany')" class="btn btn-secondary">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Salvar Alterações</button>
                    </div>
                </form>
            </div>
        </div>

    </div>
</div>

<script>
    // Modal Helpers
    function openModal(id) {
        document.getElementById(id).style.display = 'flex';
    }
    
    function closeModal(id) {
        document.getElementById(id).style.display = 'none';
    }

    function closeModalOnOverlay(event, id) {
        if (event.target.id === id) {
            closeModal(id);
        }
    }

    // Carregar dados no modal de edição
    function editCompany(empresa) {
        document.getElementById('editId').value = empresa.id;
        document.getElementById('editTitle').innerText = empresa.nome_fantasia;
        document.getElementById('editNome').value = empresa.nome_fantasia;
        document.getElementById('editEmail').value = empresa.email_financeiro;
        document.getElementById('editStatus').value = empresa.status_assinatura;
        document.getElementById('editExp').value = empresa.data_expiracao;
        document.getElementById('editMpId').value = empresa.mp_preapproval_id || '';
        
        document.getElementById('editEstoque').checked = (empresa.recurso_estoque == 1);
        document.getElementById('editTransferencias').checked = (empresa.recurso_transferencias == 1);
        document.getElementById('editCobranca').checked = (empresa.cobranca_automatica == 1);
        
        openModal('modalEditCompany');
    }
</script>
</body>
</html>
