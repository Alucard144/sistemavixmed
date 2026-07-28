<?php
// config_saas.php - Verificação de assinatura e recursos por empresa

$self = basename($_SERVER['PHP_SELF']);
if ($self !== 'bloqueado.php' && $self !== 'logout.php' && $self !== 'index.php') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!isset($_SESSION['logado'])) {
        header("Location: index.php");
        exit();
    }
    
    require_once "conexao.php";
    
    $empresa_id = $_SESSION['usuario_empresa_id'] ?? 0;
    
    if ($empresa_id <= 0) {
        header("Location: logout.php");
        exit();
    }
    
    // Buscar status de assinatura e expiração da empresa
    $stmt = $pdo->prepare("SELECT status_assinatura, data_expiracao, cobranca_automatica, recurso_estoque, recurso_transferencias FROM saas_empresas WHERE id = :id");
    $stmt->execute([':id' => $empresa_id]);
    $empresa = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$empresa) {
        header("Location: logout.php");
        exit();
    }
    
    // Salvar recursos na sessão
    $_SESSION['recurso_estoque'] = $empresa['recurso_estoque'];
    $_SESSION['recurso_transferencias'] = $empresa['recurso_transferencias'];
    
    // Verificar vencimento (apenas se a cobrança automática estiver ativa)
    $vencido = false;
    if ($empresa['cobranca_automatica'] == 1) {
        $hoje = date('Y-m-d');
        if ($empresa['data_expiracao'] < $hoje) {
            $vencido = true;
            
            // Se venceu, vamos atualizar o status para suspenso
            if ($empresa['status_assinatura'] !== 'suspenso') {
                $up = $pdo->prepare("UPDATE saas_empresas SET status_assinatura = 'suspenso' WHERE id = :id");
                $up->execute([':id' => $empresa_id]);
            }
        }
    }
    
    if ($empresa['status_assinatura'] === 'suspenso' || $vencido) {
        header("Location: bloqueado.php");
        exit();
    }
}
?>
