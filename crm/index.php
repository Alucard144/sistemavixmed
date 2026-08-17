<?php
session_start();
require_once "conexao.php";

$mensagem_erro = "";
$mensagem_sucesso = "";

// Processar login
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';

    if (!empty($email) && !empty($senha)) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM crm_usuarios WHERE email = :email");
            $stmt->execute([':email' => $email]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($usuario && password_verify($senha, $usuario['senha'])) {
                $_SESSION['crm_logado'] = true;
                $_SESSION['crm_usuario_id'] = $usuario['id'];
                $_SESSION['crm_usuario_nome'] = $usuario['nome'];
                $_SESSION['crm_usuario_email'] = $usuario['email'];

                header("Location: dashboard.php");
                exit();
            } else {
                $mensagem_erro = "E-mail ou senha incorretos!";
            }
        } catch (PDOException $e) {
            $mensagem_erro = "Erro no banco de dados: " . $e->getMessage();
        }
    } else {
        $mensagem_erro = "Preencha todos os campos!";
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Vixmed CRM</title>
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
            width: min(440px, 100%);
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

        .page-note {
            margin-top: 20px;
            font-size: 12px;
            color: var(--text-muted);
            line-height: 1.5;
        }

        .page-note a {
            color: var(--primary);
            font-weight: 600;
            text-decoration: none;
        }

        .page-note a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="page-container">
        <div class="page-logo">
            💼 Vixmed CRM
        </div>
        <div class="page-hero">
            <p>Faça login para gerenciar sua agenda, contatos e tarefas.</p>
        </div>

        <?php if (!empty($mensagem_erro)): ?>
            <div class="banner erro"><?= htmlspecialchars($mensagem_erro) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>E-mail</label>
                <input type="email" name="email" placeholder="nome@vixmed.com" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Senha</label>
                <input type="password" name="senha" placeholder="Sua senha" required>
            </div>
            <button type="submit" class="btn-submit">Entrar</button>
        </form>

        <div class="page-note">
            Não tem uma conta? <a href="cadastro.php" target="_blank">Cadastre-se</a>
        </div>
    </div>
</body>
</html>
