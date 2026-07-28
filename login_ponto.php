<?php
// 1. Inicia a sessão na primeira linha
session_start();

// 2. Importa o arquivo de conexão
require_once "conexao.php";

$mensagem_erro = "";

// 3. Verifica se o formulário foi enviado
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email_digitado = $_POST['email'];
    $senha_digitada = $_POST['senha'];

    try {
        // Prepara uma consulta SQL segura para buscar o usuário pelo e-mail
        $sql = "SELECT * FROM usuarios WHERE email = :email";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':email', $email_digitado);
        $stmt->execute();

        // Recupera os dados do usuário encontrado
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        // Se o usuário existir no banco, a senha bater e for master
        if ($usuario && $senha_digitada === $usuario['senha'] && ($usuario['tipo'] ?? '') === 'master') {
            // Salva os dados na sessão
            $_SESSION['logado'] = true;
            $_SESSION['usuario_id'] = $usuario['id'];
            $_SESSION['usuario_email'] = $usuario['email'];
            $_SESSION['usuario_nome'] = $usuario['nome'] ?? 'Usuário';
            $_SESSION['usuario_tipo'] = 'master';
            $_SESSION['usuario_funcao'] = $usuario['funcao'] ?? '';

            header("Location: folhadeponto.php");
            exit();
        } else {
            $mensagem_erro = "Acesso permitido somente ao usuário master.";
        }

    } catch (PDOException $erro) {
        $mensagem_erro = "Erro no sistema: " . $erro->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vixmed Ponto</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            background: linear-gradient(180deg, #eef4ff 0%, #f8fbff 100%);
            font-family: Inter, system-ui, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #0f172a;
        }

        .login-panel {
            width: min(460px, 100%);
            background: #ffffff;
            border-radius: 32px;
            box-shadow: 0 30px 80px rgba(15, 23, 42, 0.12);
            padding: 40px;
        }

        .login-panel h1 {
            margin: 0 0 10px;
            font-size: clamp(2rem, 3vw, 2.6rem);
        }

        .login-panel span {
            color: #2563eb;
        }

        .login-panel p {
            margin: 0 0 24px;
            color: #475569;
            font-size: 0.98rem;
            line-height: 1.7;
        }

        .form-group {
            display: grid;
            gap: 10px;
            margin-bottom: 18px;
        }

        .form-group label {
            color: #475569;
            font-weight: 600;
        }

        .form-group input {
            width: 100%;
            padding: 14px 16px;
            border: 1px solid #cbd5e1;
            border-radius: 16px;
            font-size: 1rem;
            outline: none;
        }

        .form-group input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 5px rgba(37, 99, 235, 0.12);
        }

        .btn-primary {
            width: 100%;
            border: none;
            border-radius: 16px;
            padding: 14px 18px;
            background: #2563eb;
            color: #ffffff;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
        }

        .message {
            margin-bottom: 18px;
            padding: 14px 16px;
            border-radius: 16px;
            background: #fee2e2;
            color: #b91c1c;
        }
    </style>
</head>
<body>
    <div class="login-panel">
        <h1>Vix<span>med</span> Ponto</h1>
        <p>Somente o usuário master pode acessar este login para cadastrar os demais colaboradores.</p>

        <?php if (!empty($mensagem_erro)): ?>
            <div class="message"><?= htmlspecialchars($mensagem_erro) ?></div>
        <?php endif; ?>

        <form method="POST" action="login_ponto.php">
            <div class="form-group">
                <label>E-mail Master</label>
                <input type="email" name="email" placeholder="admin@vixmed.com.br" required>
            </div>
            <div class="form-group">
                <label>Senha</label>
                <input type="password" name="senha" placeholder="Digite sua senha" required>
            </div>
            <button type="submit" class="btn-primary">Entrar no Ponto</button>
        </form>
    </div>
</body>
</html>
