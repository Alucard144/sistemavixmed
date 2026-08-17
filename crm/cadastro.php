<?php
require_once "conexao.php";

$mensagem_erro = "";
$cadastrado_sucesso = false;

// Processar cadastro
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nome = trim($_POST['nome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';
    $confirmar_senha = $_POST['confirmar_senha'] ?? '';

    if (!empty($nome) && !empty($email) && !empty($senha) && !empty($confirmar_senha)) {
        if ($senha !== $confirmar_senha) {
            $mensagem_erro = "As senhas não coincidem!";
        } else {
            try {
                // Verificar se o e-mail já existe
                $stmt_check = $pdo->prepare("SELECT id FROM crm_usuarios WHERE email = :email");
                $stmt_check->execute([':email' => $email]);
                if ($stmt_check->fetch()) {
                    $mensagem_erro = "Este e-mail já está cadastrado!";
                } else {
                    // Inserir usuário
                    $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
                    $stmt_ins = $pdo->prepare("INSERT INTO crm_usuarios (nome, email, senha) VALUES (:nome, :email, :senha)");
                    $stmt_ins->execute([
                        ':nome' => $nome,
                        ':email' => $email,
                        ':senha' => $senha_hash
                    ]);
                    $novo_usuario_id = $pdo->lastInsertId();

                    // Cadastrar automaticamente as configurações de e-mail padrão do UOL Host
                    $stmt_mail = $pdo->prepare("INSERT INTO crm_email_config (usuario_id, smtp_host, smtp_port, smtp_secure, imap_host, imap_port, imap_secure, email_usuario, senha_usuario) VALUES (:uid, 'smtps.uhserver.com', 465, 'ssl', 'imap.uhserver.com', 993, 'ssl', :email, :senha)");
                    $stmt_mail->execute([
                        ':uid' => $novo_usuario_id,
                        ':email' => $email,
                        ':senha' => $senha
                    ]);

                    $cadastrado_sucesso = true;
                }
            } catch (PDOException $e) {
                $mensagem_erro = "Erro ao cadastrar: " . $e->getMessage();
            }
        }
    } else {
        $mensagem_erro = "Preencha todos os campos do cadastro!";
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro - Vixmed CRM</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-dark: #0b132b;
            --bg-medium: #1c2541;
            --bg-light: #3a506b;
            --primary: #00e676;
            --primary-hover: #00b359;
            --accent: #8a2be2;
            --white: #ffffff;
            --text-muted: rgba(255, 255, 255, 0.6);
            --shadow: 0 15px 40px rgba(0, 0, 0, 0.4);
            --border: rgba(255, 255, 255, 0.08);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            min-height: 100vh;
            font-family: 'Outfit', sans-serif;
            background: var(--bg-dark);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .page-container {
            width: min(460px, 100%);
            background: rgba(28, 37, 65, 0.45);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border);
            border-radius: 28px;
            box-shadow: var(--shadow);
            padding: 40px;
            text-align: center;
        }

        .page-logo {
            font-size: 32px;
            font-weight: 800;
            color: var(--white);
            margin-bottom: 8px;
            letter-spacing: -1px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .page-logo span {
            color: var(--primary);
            text-shadow: 0 0 15px rgba(0, 230, 118, 0.4);
        }

        .page-hero {
            margin-bottom: 28px;
        }

        .page-hero p {
            color: var(--text-muted);
            font-size: 14px;
            line-height: 1.6;
        }

        .form-group {
            margin-bottom: 18px;
            text-align: left;
        }

        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-group input {
            width: 100%;
            padding: 14px 16px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: 12px;
            font-family: inherit;
            font-size: 14px;
            color: var(--white);
            outline: none;
            transition: all 0.2s ease;
        }

        .form-group input::placeholder {
            color: rgba(255, 255, 255, 0.25);
        }

        .form-group input:focus {
            border-color: var(--primary);
            background: rgba(255, 255, 255, 0.08);
            box-shadow: 0 0 0 4px rgba(0, 230, 118, 0.15);
        }

        .btn-submit {
            width: 100%;
            border: none;
            border-radius: 12px;
            padding: 14px 18px;
            font-family: inherit;
            font-weight: 700;
            font-size: 14px;
            color: var(--bg-dark);
            background: var(--primary);
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 4px 15px rgba(0, 230, 118, 0.35);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 8px;
        }

        .btn-submit:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(0, 230, 118, 0.45);
        }

        .btn-close-window {
            background: #ef4444;
            color: var(--white);
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.35);
        }
        .btn-close-window:hover {
            background: #dc2626;
            box-shadow: 0 6px 20px rgba(239, 68, 68, 0.45);
        }

        .banner {
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 13px;
            line-height: 1.5;
            margin-bottom: 20px;
            text-align: left;
            border: 1px solid transparent;
        }

        .banner.erro {
            background: rgba(239, 68, 68, 0.12);
            border-color: rgba(239, 68, 68, 0.25);
            color: #fca5a5;
        }

        .banner.sucesso {
            background: rgba(0, 230, 118, 0.12);
            border-color: rgba(0, 230, 118, 0.25);
            color: #a7f3d0;
            font-size: 14px;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <div class="page-container">
        <div class="page-logo">
            💼 Vixmed CRM
        </div>

        <?php if ($cadastrado_sucesso): ?>
            <div class="page-hero" style="margin-top: 20px;">
                <div class="banner sucesso" style="text-align: center; margin-bottom: 24px;">
                    🎉 Cadastro realizado com sucesso!
                </div>
                <p style="margin-bottom: 28px; font-size: 15px;">Você já pode fechar esta aba e retornar para a tela de login.</p>
                <button class="btn-submit btn-close-window" onclick="window.close()">Fechar esta aba</button>
            </div>
        <?php else: ?>
            <div class="page-hero">
                <p>Crie sua conta para ter acesso ao painel de controle corporativo.</p>
            </div>

            <?php if (!empty($mensagem_erro)): ?>
                <div class="banner erro"><?= htmlspecialchars($mensagem_erro) ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label>Nome Completo</label>
                    <input type="text" name="nome" placeholder="Digite seu nome" required value="<?= htmlspecialchars($_POST['nome'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>E-mail Corporativo</label>
                    <input type="email" name="email" placeholder="nome@vixmed.com" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Senha</label>
                    <input type="password" name="senha" placeholder="Mínimo 6 caracteres" required>
                </div>
                <div class="form-group">
                    <label>Confirmar Senha</label>
                    <input type="password" name="confirmar_senha" placeholder="Digite novamente" required>
                </div>
                <button type="submit" class="btn-submit">Registrar Conta</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
