<?php
session_start();
require_once "conexao.php";

// Buscar dados da empresa para exibir informações úteis
$empresa_nome = "Sua Empresa";
$email_financeiro = "suporte@seusistema.com";

if (isset($_SESSION['usuario_empresa_id'])) {
    $empresa_id = $_SESSION['usuario_empresa_id'];
    $stmt = $pdo->prepare("SELECT nome_fantasia, email_financeiro FROM saas_empresas WHERE id = :id");
    $stmt->execute([':id' => $empresa_id]);
    $empresa = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($empresa) {
        $empresa_nome = $empresa['nome_fantasia'];
        $email_financeiro = $empresa['email_financeiro'];
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acesso Bloqueado - Portal de Chamados</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --vermelho: #e53935;
            --vermelho-hover: #d32f2f;
            --cinza-escuro: #1a1a1a;
            --cinza-claro: #f5f5f7;
            --texto: #424242;
            --branco: #ffffff;
            --sombra: 0 10px 30px rgba(0,0,0,0.08);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--cinza-claro);
            color: var(--texto);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }

        .blocked-container {
            background-color: var(--branco);
            border-radius: 16px;
            box-shadow: var(--sombra);
            padding: 40px;
            width: 100%;
            max-width: 550px;
            text-align: center;
            border-top: 6px solid var(--vermelho);
        }

        .icon {
            font-size: 64px;
            margin-bottom: 24px;
            animation: pulse 2s infinite ease-in-out;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.08); }
            100% { transform: scale(1); }
        }

        h1 {
            color: var(--cinza-escuro);
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 16px;
        }

        p {
            font-size: 15px;
            line-height: 1.6;
            margin-bottom: 24px;
            color: #616161;
        }

        .highlight {
            font-weight: 600;
            color: var(--cinza-escuro);
        }

        .btn-group {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-bottom: 28px;
        }

        .btn {
            display: block;
            width: 100%;
            padding: 14px;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s ease;
            cursor: pointer;
            border: none;
        }

        .btn-primary {
            background-color: var(--vermelho);
            color: var(--branco);
        }

        .btn-primary:hover {
            background-color: var(--vermelho-hover);
        }

        .btn-secondary {
            background-color: transparent;
            color: var(--texto);
            border: 2px solid #e0e0e0;
        }

        .btn-secondary:hover {
            background-color: #fafafa;
            border-color: #bdbdbd;
        }

        .footer {
            font-size: 12px;
            color: #9e9e9e;
            border-top: 1px solid #eeeeee;
            padding-top: 20px;
        }
    </style>
</head>
<body>
    <div class="blocked-container">
        <div class="icon">🔒</div>
        <h1>Portal Suspenso ou Expirado</h1>
        <p>
            Olá, <span class="highlight"><?= htmlspecialchars($empresa_nome) ?></span>.<br><br>
            Identificamos que o período de testes do seu portal de chamados expirou ou que há uma pendência financeira pendente de regularização.
        </p>
        <p style="background: #fff8e1; border-left: 4px solid #ffb300; padding: 12px; border-radius: 6px; text-align: left; font-size: 14px;">
            ℹ️ <strong>Administrador:</strong> Para reativar seu acesso e manter todos os chamados e estoque intactos, realize o pagamento da assinatura ou entre em contato com nosso suporte técnico.
        </p>

        <div class="btn-group">
            <!-- Em produção, o link apontaria para o checkout do Mercado Pago do cliente -->
            <a href="https://www.mercadopago.com.br" target="_blank" class="btn btn-primary">💳 Regularizar Assinatura</a>
            <a href="mailto:<?= htmlspecialchars($email_financeiro) ?>" class="btn btn-secondary">✉️ Contatar Financeiro</a>
            <a href="logout.php" class="btn btn-secondary">🚪 Fazer Logout</a>
        </div>

        <div class="footer">
            Desenvolvido por Vixmed SaaS &copy; <?= date('Y') ?>
        </div>
    </div>
</body>
</html>
