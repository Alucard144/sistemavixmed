<?php
// 1. Inicia a sessão SEMPRE na primeira linha do PHP
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
        $sql = "SELECT * FROM saas_usuarios WHERE email = :email";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':email', $email_digitado);
        $stmt->execute();

        // Recupera os dados do usuário encontrado
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        // Se o usuário existir no banco e a senha bater (hash ou texto plano)
        if ($usuario && (password_verify($senha_digitada, $usuario['senha']) || $senha_digitada === $usuario['senha'])) {
            // Salva os dados na sessão
            $_SESSION['logado'] = true;
            $_SESSION['usuario_id'] = $usuario['id'];
            $_SESSION['usuario_empresa_id'] = $usuario['empresa_id'];
            $_SESSION['usuario_email'] = $usuario['email'];
            $_SESSION['usuario_nome'] = $usuario['nome'] ?? 'Usuário';
            $_SESSION['usuario_tipo'] = $usuario['tipo'] ?? 'funcionario';
            $_SESSION['usuario_funcao'] = $usuario['funcao'] ?? '';
            $_SESSION['usuario_super_admin'] = $usuario['super_admin'] ?? 0;
            
            // Redireciona para a página de chamados
            header("Location: pagina.php"); 
            exit();
        } else {
            // Se o e-mail não existir ou a senha estiver errada
            $mensagem_erro = "E-mail ou senha incorretos!";
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
    <title>Login - Chamados Vixmed</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="login-page">

    <form method="POST" action="index.php">
        <div class="login-card">
            <div class="login-icon">🛡️</div>
            <h1>Vix<span>med</span></h1>
            <p>Sistema de Chamados — Faça login para acessar</p>

            <?php if (!empty($mensagem_erro)): ?>
                <div class="erro"><?php echo $mensagem_erro; ?></div>
            <?php endif; ?>

            <div class="form-group">
                <label>E-mail</label>
                <input type="email" name="email" placeholder="Digite seu e-mail" required>
            </div>
            
            <div class="form-group">
                <label>Senha</label>
                <input type="password" name="senha" placeholder="Digite sua senha" required>
            </div>
            
            <button type="submit" class="btn btn-primary">Entrar</button>
        </div>
    </form>

</body>
</html>