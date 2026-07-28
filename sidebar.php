<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['logado'])) { header("Location: index.php"); exit(); }

$pagina_atual = $pagina_atual ?? basename($_SERVER['PHP_SELF'], '.php');
$tipo = $_SESSION['usuario_tipo'] ?? 'funcionario';
$nome = $_SESSION['usuario_nome'] ?? 'Usuário';
$funcao = $_SESSION['usuario_funcao'] ?? '';
$iniciais = mb_strtoupper(mb_substr($nome, 0, 2));
?>

<style>
/* FORÇAR CONTAINER VERTICAL 100% */
.app-container {
    display: flex !important;
    flex-direction: column !important;
    width: 100% !important;
    min-height: 100vh !important;
    margin: 0 !important;
    padding: 0 !important;
    box-sizing: border-box !important;
    background: #0a1628 !important;
}

/* BARRA SUPERIOR BLINDADA (TOPBAR 100%) */
.chamados-topbar {
    width: 100% !important;
    padding: 16px 24px !important;
    box-sizing: border-box !important;
}

.chamados-topbar-inner {
    max-width: 1400px;
    width: 100% !important;
    margin: 0 auto;
    background: #1a2d4a;
    border-radius: 16px;
    padding: 10px 20px;
    display: flex !important;
    flex-direction: row !important;
    align-items: center !important;
    justify-content: space-between !important;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
    border: 1px solid rgba(255, 255, 255, 0.08);
    box-sizing: border-box !important;
}

.chamados-brand {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
}

.chamados-brand img {
    width: 36px !important;
    height: 36px !important;
    max-width: 36px !important;
    max-height: 36px !important;
    border-radius: 8px;
    object-fit: contain !important;
    display: block !important;
}

.chamados-brand h1 {
    font-size: 18px !important;
    font-weight: 700 !important;
    color: #ffffff !important;
    margin: 0 !important;
    letter-spacing: -0.5px;
    font-family: 'Inter', system-ui, sans-serif;
}

.chamados-brand h1 span {
    color: #00c853 !important;
}

.chamados-nav {
    list-style: none !important;
    display: flex !important;
    flex-direction: row !important;
    gap: 6px !important;
    align-items: center !important;
    margin: 0 !important;
    padding: 0 !important;
}

.chamados-nav li {
    margin: 0 !important;
    padding: 0 !important;
    list-style: none !important;
}

.chamados-nav li a {
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    padding: 10px 16px !important;
    color: rgba(255, 255, 255, 0.7) !important;
    text-decoration: none !important;
    border-radius: 12px !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    font-family: 'Inter', system-ui, sans-serif;
    transition: all 0.2s ease !important;
}

.chamados-nav li a:hover {
    background: rgba(255, 255, 255, 0.1) !important;
    color: #ffffff !important;
}

.chamados-nav li a.active {
    background: #00c853 !important;
    color: #ffffff !important;
    box-shadow: 0 4px 12px rgba(0, 200, 83, 0.3) !important;
}

.chamados-user {
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
}

.chamados-user-avatar {
    width: 32px !important;
    height: 32px !important;
    background: #00c853 !important;
    border-radius: 50% !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-weight: 700 !important;
    font-size: 12px !important;
    color: #ffffff !important;
    font-family: 'Inter', system-ui, sans-serif;
}

.chamados-user-name {
    font-size: 13px !important;
    font-weight: 600 !important;
    color: rgba(255, 255, 255, 0.9) !important;
    font-family: 'Inter', system-ui, sans-serif;
}

.chamados-user-logout {
    color: rgba(255, 255, 255, 0.5) !important;
    text-decoration: none !important;
    font-size: 13px !important;
    padding: 6px 12px !important;
    border-radius: 8px !important;
    font-family: 'Inter', system-ui, sans-serif;
    transition: all 0.2s ease !important;
}

.chamados-user-logout:hover {
    background: rgba(239, 68, 68, 0.2) !important;
    color: #ef4444 !important;
}

/* CONTEÚDO PRINCIPAL VERTICALIZADO ABAIXO DA TOPBAR */
.main-content {
    flex: 1 !important;
    width: 100% !important;
    max-width: 1400px !important;
    margin: 0 auto !important;
    padding: 16px 24px 32px !important;
    box-sizing: border-box !important;
    background: #0a1628 !important;
}

@media (max-width: 768px) {
    .chamados-topbar { padding: 10px 12px !important; }
    .chamados-topbar-inner { flex-wrap: wrap !important; gap: 8px !important; padding: 10px !important; }
    .chamados-nav { width: 100% !important; justify-content: center !important; flex-wrap: wrap !important; }
    .chamados-nav li a { padding: 8px 10px !important; font-size: 12px !important; }
    .chamados-user-name { display: none !important; }
    .main-content { padding: 12px !important; }
}
</style>

<!-- BARRA SUPERIOR COM UL/LI -->
<header class="chamados-topbar">
    <div class="chamados-topbar-inner">
        <div class="chamados-brand">
            <img src="img-10.webp" alt="Logo Vixmed" style="width: 36px; height: 36px; object-fit: contain;">
            <h1>Vix<span>med</span> Chamados</h1>
        </div>

        <ul class="chamados-nav">
            <li><a href="pagina.php" class="<?= $pagina_atual === 'pagina' || $pagina_atual === 'dashboard' ? 'active' : '' ?>"><span class="nav-icon">📊</span> Dashboard</a></li>
            <li><a href="novo_chamado.php" class="<?= $pagina_atual === 'novo_chamado' ? 'active' : '' ?>"><span class="nav-icon">➕</span> Novo Chamado</a></li>

            <?php if ($tipo === 'master'): ?>
            <li><a href="gerenciar_usuarios.php" class="<?= $pagina_atual === 'gerenciar_usuarios' ? 'active' : '' ?>"><span class="nav-icon">👥</span> Usuários</a></li>
            <li><a href="gerenciar_setores.php" class="<?= $pagina_atual === 'gerenciar_setores' ? 'active' : '' ?>"><span class="nav-icon">🏢</span> Setores</a></li>
            <li><a href="gerenciar_estoque.php" class="<?= $pagina_atual === 'gerenciar_estoque' ? 'active' : '' ?>"><span class="nav-icon">📦</span> Estoque</a></li>
            <?php endif; ?>
        </ul>

        <div class="chamados-user">
            <div class="chamados-user-avatar"><?= $iniciais ?></div>
            <span class="chamados-user-name"><?= htmlspecialchars($nome) ?></span>
            <span class="chamados-user-role"><?= $tipo === 'master' ? '👑' : '' ?></span>
            <a href="logout.php" class="chamados-user-logout">🚪 Sair</a>
        </div>
    </div>
</header>
