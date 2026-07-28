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
        $sql = "SELECT * FROM usuarios WHERE email = :email";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':email', $email_digitado);
        $stmt->execute();

        // Recupera os dados do usuário encontrado
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        // Se o usuário existir no banco e a senha bater
        if ($usuario && $senha_digitada === $usuario['senha']) {
            
            // Salva os dados na sessão
            $_SESSION['logado'] = true;
            $_SESSION['usuario_id'] = $usuario['id'];
            $_SESSION['usuario_email'] = $usuario['email'];
            $_SESSION['usuario_nome'] = $usuario['nome'] ?? 'Usuário';
            $_SESSION['usuario_tipo'] = $usuario['tipo'] ?? 'funcionario';
            $_SESSION['usuario_funcao'] = $usuario['funcao'] ?? '';
            
            // Redireciona de acordo com o formulário que foi submetido
            if (isset($_POST['login_tipo_ponto'])) {
                header("Location: folhadeponto.php"); 
            } else {
                header("Location: pagina.php"); 
            }
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
    <title>Login - Vixmed</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        :root {
            --azul-escuro: #0a1628;
            --azul-medio: #1a2d4a;
            --verde: #00c853;
            --verde-hover: #00a844;
            --branco: #ffffff;
            --muted: rgba(255, 255, 255, 0.5);
            --shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            min-height: 100vh;
            font-family: Inter, system-ui, sans-serif;
            background: var(--azul-escuro);
            color: var(--branco);
            overflow: hidden;
        }

        .page-wrapper {
            width: 100vw;
            height: 100vh;
            overflow: hidden;
            position: relative;
        }

        .pages {
            display: flex;
            width: 200vw;
            height: 100%;
            transition: transform 0.7s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .page {
            width: 100vw;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px;
        }

        /* Fundo diferente para cada seção */
        .page[data-page="0"] {
            background: linear-gradient(135deg, var(--azul-escuro) 0%, var(--azul-medio) 50%, #0d2137 100%);
        }

        .page[data-page="1"] {
            background: linear-gradient(135deg, #0d2137 0%, var(--azul-medio) 50%, var(--azul-escuro) 100%);
        }

        /* Card de login com glassmorphism */
        .page-content {
            width: min(460px, 100%);
            background: rgba(255, 255, 255, 0.06);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 24px;
            box-shadow: var(--shadow);
            padding: 44px;
            text-align: center;
        }

        /* Logo dentro do card */
        .page-logo {
            width: 72px;
            height: 72px;
            margin: 0 auto 20px;
            border-radius: 16px;
            overflow: hidden;
        }

        .page-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .page-hero {
            margin-bottom: 28px;
        }

        .page-hero h1 {
            margin: 0;
            font-size: clamp(1.6rem, 3vw, 2.2rem);
            font-weight: 700;
            letter-spacing: -0.03em;
            color: var(--branco);
        }

        .page-hero h1 span {
            color: var(--verde);
        }

        .page-hero p {
            margin: 8px 0 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.6;
        }

        /* Formulários */
        .form-group {
            margin-bottom: 16px;
            text-align: left;
        }

        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: rgba(255, 255, 255, 0.7);
            margin-bottom: 6px;
        }

        .form-group input {
            width: 100%;
            padding: 13px 16px;
            background: rgba(255, 255, 255, 0.06);
            border: 2px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            font-family: inherit;
            font-size: 14px;
            color: var(--branco);
            outline: none;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .form-group input::placeholder {
            color: rgba(255, 255, 255, 0.3);
        }

        .form-group input:focus {
            border-color: var(--verde);
            box-shadow: 0 0 0 3px rgba(0, 200, 83, 0.15);
        }

        /* Botões */
        .btn-login {
            width: 100%;
            border: none;
            border-radius: 12px;
            padding: 14px 18px;
            font-family: inherit;
            font-weight: 700;
            font-size: 14px;
            color: var(--branco);
            background: var(--verde);
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(0, 200, 83, 0.25);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 8px;
        }

        .btn-login:hover {
            background: var(--verde-hover);
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(0, 200, 83, 0.35);
        }

        /* Nota informativa */
        .page-note {
            margin-top: 20px;
            padding: 14px 16px;
            border-radius: 12px;
            background: rgba(0, 200, 83, 0.08);
            color: rgba(255, 255, 255, 0.6);
            font-size: 13px;
            line-height: 1.6;
            border: 1px solid rgba(0, 200, 83, 0.12);
        }

        .page-note.erro {
            background: rgba(239, 68, 68, 0.12);
            border-color: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
        }

        /* Indicador de bolinhas */
        .indicator {
            position: absolute;
            bottom: 28px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 12px;
            align-items: center;
            background: rgba(255, 255, 255, 0.06);
            padding: 10px 20px;
            border-radius: 24px;
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        .dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            cursor: pointer;
            transition: transform 0.3s ease, background-color 0.3s ease;
        }

        .dot.active {
            background: var(--verde);
            transform: scale(1.3);
            box-shadow: 0 0 12px rgba(0, 200, 83, 0.4);
        }

        .dot-label {
            color: rgba(255, 255, 255, 0.4);
            font-size: 12px;
            font-weight: 500;
        }

        @media (max-width: 480px) {
            .page-content { padding: 32px 24px; }
            .page { padding: 16px; }
        }
    </style>
</head>
<body>
    <div class="page-wrapper" id="pageWrapper">
        <div class="pages" id="pages">

            <!-- PÁGINA 1: Vixmed Chamados -->
            <section class="page" data-page="0">
                <div class="page-content">
                    <div class="page-logo">
                        <img src="img-10.webp" alt="Logo Vixmed">
                    </div>
                    <div class="page-hero">
                        <h1>Vix<span>med</span> Chamados</h1>
                        <p>Faça login para acessar o sistema de chamados.</p>
                    </div>

                    <?php if (!empty($mensagem_erro) && !isset($_POST['login_tipo_ponto'])): ?>
                        <div class="page-note erro"><?= htmlspecialchars($mensagem_erro) ?></div>
                    <?php endif; ?>

                    <form method="POST" action="index.php">
                        <div class="form-group">
                            <label>E-mail</label>
                            <input type="email" name="email" placeholder="Digite seu e-mail" required>
                        </div>
                        <div class="form-group">
                            <label>Senha</label>
                            <input type="password" name="senha" placeholder="Digite sua senha" required>
                        </div>
                        <button type="submit" class="btn-login">Entrar nos Chamados</button>
                    </form>

                    <div class="page-note">
                        Role o scroll do mouse para ir para a página do ponto.
                    </div>
                </div>
            </section>

            <!-- PÁGINA 2: Vixmed Ponto -->
            <section class="page" data-page="1">
                <div class="page-content">
                    <div class="page-logo">
                        <img src="img-10.webp" alt="Logo Vixmed">
                    </div>
                    <div class="page-hero">
                        <h1>Vix<span>med</span> Ponto</h1>
                        <p>Login da folha de ponto. Apenas o master deve entrar aqui.</p>
                    </div>

                    <?php if (!empty($mensagem_erro) && isset($_POST['login_tipo_ponto'])): ?>
                        <div class="page-note erro"><?= htmlspecialchars($mensagem_erro) ?></div>
                    <?php endif; ?>

                    <form method="POST" action="index.php">
                        <input type="hidden" name="login_tipo_ponto" value="true">
                        <div class="form-group">
                            <label>E-mail Master</label>
                            <input type="email" name="email" placeholder="admin@vixmed.com.br" required>
                        </div>
                        <div class="form-group">
                            <label>Senha</label>
                            <input type="password" name="senha" placeholder="Digite sua senha" required>
                        </div>
                        <button type="submit" class="btn-login">Entrar no Ponto</button>
                    </form>

                    <div class="page-note">
                        Esta página é apenas para acesso master. Depois do login, você poderá cadastrar os demais colaboradores.
                    </div>
                </div>
            </section>

        </div>

        <!-- BOLINHAS INDICADORAS -->
        <div class="indicator">
            <span class="dot active" data-target="0"></span>
            <span class="dot" data-target="1"></span>
            <span class="dot-label">Role o scroll para trocar</span>
        </div>
    </div>

    <script>
        const pages = document.getElementById('pages');
        const dots = document.querySelectorAll('.dot');
        let currentPage = 0;
        let isScrolling = false;

        function setPage(index) {
            currentPage = Math.max(0, Math.min(index, 1));
            pages.style.transform = `translateX(-${currentPage * 100}vw)`;
            dots.forEach((dot, idx) => dot.classList.toggle('active', idx === currentPage));
        }

        function handleWheel(event) {
            if (isScrolling) return;
            if (event.deltaY > 0 && currentPage === 0) {
                setPage(1);
                blockScroll();
            } else if (event.deltaY < 0 && currentPage === 1) {
                setPage(0);
                blockScroll();
            }
        }

        function blockScroll() {
            isScrolling = true;
            setTimeout(() => { isScrolling = false; }, 500);
        }

        dots.forEach(dot => dot.addEventListener('click', () => setPage(Number(dot.dataset.target))));
        window.addEventListener('wheel', handleWheel, { passive: false });

        setPage(<?= (!empty($mensagem_erro) && isset($_POST['login_tipo_ponto'])) ? 1 : 0 ?>);
    </script>
</body>
</html>
