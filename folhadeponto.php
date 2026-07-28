<?php
session_start();

// 1. Configuração do Fuso Horário Oficial (Horário de Brasília - MTE Portaria 671)
date_default_timezone_set('America/Sao_Paulo');

// Restrição de Acesso: apenas usuários logados
if (!isset($_SESSION['logado']) || $_SESSION['logado'] !== true) {
    header("Location: index.php");
    exit();
}

require_once "conexao.php";

$usuario_id = $_SESSION['usuario_id'];
$nome = $_SESSION['usuario_nome'] ?? 'Usuário';
$iniciais = mb_strtoupper(mb_substr($nome, 0, 2));

// Buscar dados do usuário (CPF)
$stmt_u = $pdo->prepare("SELECT email, nome FROM usuarios WHERE id = :id");
$stmt_u->execute([':id' => $usuario_id]);
$dados_user = $stmt_u->fetch(PDO::FETCH_ASSOC);
$cpf_trabalhador = "000.000.000-00";

// ===== EXPORTAÇÕES FISCAIS (PORTARIA 671 MTE) =====

// 1. Exportação AFD (Arquivo Fonte de Dados - TXT Inalterável)
if (isset($_GET['export']) && $_GET['export'] === 'afd' && ($_SESSION['usuario_tipo'] ?? '') === 'master') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="AFD_Portaria671_Vixmed_' . date('Ymd_His') . '.txt"');

    $stmt_afd = $pdo->query("SELECT r.*, u.nome FROM ponto_registros r JOIN usuarios u ON r.usuario_id = u.id ORDER BY r.nsr ASC");
    $registros = $stmt_afd->fetchAll(PDO::FETCH_ASSOC);

    $cnpj_empregador = "00000000000100";
    $razao_social = str_pad("VIXMED SOLUCOES MEDICAS LTDA", 150, " ");
    $data_inicial = count($registros) > 0 ? date('dmY', strtotime($registros[0]['data_hora'])) : date('dmY');
    $data_final = date('dmY');
    $data_geracao = date('dmYHis');

    echo "00000000011" . $cnpj_empregador . $razao_social . $data_inicial . $data_final . $data_geracao . "\r\n";

    $count = 0;
    foreach ($registros as $reg) {
        $count++;
        $nsr = str_pad($reg['nsr'], 9, "0", STR_PAD_LEFT);
        $tipo_cod = "3";
        $data_reg = date('dmY', strtotime($reg['data_hora']));
        $hora_reg = date('His', strtotime($reg['data_hora']));
        $cpf_reg = str_pad(preg_replace('/\D/', '', $reg['cpf'] ?: '00000000000'), 11, "0", STR_PAD_LEFT);

        echo $nsr . $tipo_cod . $data_reg . $hora_reg . $cpf_reg . "\r\n";
    }

    $total_linhas = str_pad($count, 9, "0", STR_PAD_LEFT);
    echo "999999999" . $total_linhas . "9\r\n";
    exit();
}

// 2. Exportação AEJ (Arquivo Eletrônico de Jornada)
if (isset($_GET['export']) && $_GET['export'] === 'aej' && ($_SESSION['usuario_tipo'] ?? '') === 'master') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="AEJ_Portaria671_Vixmed_' . date('Ymd_His') . '.txt"');

    $stmt_aej = $pdo->query("SELECT r.*, u.nome FROM ponto_registros r JOIN usuarios u ON r.usuario_id = u.id ORDER BY r.data_hora ASC");
    $registros = $stmt_aej->fetchAll(PDO::FETCH_ASSOC);

    echo "AEJ - ARQUIVO ELETRONICO DE JORNADA (PORTARIA MTE 671)\r\n";
    echo "EMPRESA: VIXMED SOLUCOES MEDICAS LTDA\r\n";
    echo "CNPJ: 00.000.000/0001-00\r\n";
    echo "DATA DE EMISSAO: " . date('d/m/Y H:i:s') . "\r\n";
    echo "--------------------------------------------------------------------------------\r\n";
    echo "NSR       | DATA/HORA           | TRABALHADOR          | TIPO DE MARCAÇÃO    | AUTENTICAÇÃO SHA-256\r\n";
    echo "--------------------------------------------------------------------------------\r\n";

    foreach ($registros as $r) {
        $tipo_rotulo = match($r['tipo_registro']) {
            'entrada' => 'ENTRADA DIÁRIA',
            'saida_almoco' => 'SAÍDA INTERVALO',
            'retorno_almoco' => 'RETORNO INTERVALO',
            'saida' => 'SAÍDA DIÁRIA',
            default => 'MARCAÇÃO'
        };
        echo sprintf("%-9s | %-19s | %-20s | %-19s | %s\r\n", 
            str_pad($r['nsr'], 9, "0", STR_PAD_LEFT),
            date('d/m/Y H:i:s', strtotime($r['data_hora'])),
            mb_substr($r['nome'], 0, 20),
            $tipo_rotulo,
            mb_substr($r['hash_comprovante'], 0, 16) . '...'
        );
    }
    echo "--------------------------------------------------------------------------------\r\n";
    echo "TOTAL DE MARCAÇÕES COMPUTADAS: " . count($registros) . "\r\n";
    exit();
}

// ===== PROCESSAMENTO DE MARCAÇÃO DE PONTO =====
$mensagem_sucesso = "";
$comprovante_emitido = null;

if (isset($_POST['acao'])) {

    // Aceitar LGPD
    if ($_POST['acao'] === 'aceitar_lgpd') {
        $stmt_lgpd = $pdo->prepare("INSERT INTO ponto_lgpd_termos (usuario_id, aceito, data_aceite, ip) VALUES (:uid, 1, NOW(), :ip)");
        $stmt_lgpd->execute([':uid' => $usuario_id, ':ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);
        header("Location: folhadeponto.php");
        exit();
    }

    // Registrar Marcação de Ponto
    if ($_POST['acao'] === 'marcar_ponto') {
        $tipo_batida = $_POST['tipo_registro'] ?? 'entrada';
        $tipos_validos = ['entrada', 'saida_almoco', 'retorno_almoco', 'saida'];

        if (in_array($tipo_batida, $tipos_validos)) {
            // Calcular próximo NSR (Número Sequencial de Registro)
            $stmt_nsr = $pdo->query("SELECT MAX(nsr) as max_nsr FROM ponto_registros");
            $row_nsr = $stmt_nsr->fetch(PDO::FETCH_ASSOC);
            $proximo_nsr = ($row_nsr['max_nsr'] ?? 0) + 1;

            $data_hora_atual = date('Y-m-d H:i:s');

            // Gerar Hash SHA-256 de autenticidade
            $string_assinatura = "VIXMED|{$proximo_nsr}|{$cpf_trabalhador}|{$data_hora_atual}|{$tipo_batida}";
            $hash_comprovante = hash('sha256', $string_assinatura);

            // Inserir registro no banco
            $stmt_ins = $pdo->prepare("INSERT INTO ponto_registros (usuario_id, nsr, cpf, tipo_registro, data_hora, hash_comprovante, ip_origem) VALUES (:uid, :nsr, :cpf, :tipo, :dh, :hash, :ip)");
            $stmt_ins->execute([
                ':uid' => $usuario_id,
                ':nsr' => $proximo_nsr,
                ':cpf' => $cpf_trabalhador,
                ':tipo' => $tipo_batida,
                ':dh' => $data_hora_atual,
                ':hash' => $hash_comprovante,
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);

            $mensagem_sucesso = "Ponto marcado com sucesso! Comprovante gerado.";

            // Dados do Comprovante para Exibição
            $comprovante_emitido = [
                'nsr' => str_pad($proximo_nsr, 9, "0", STR_PAD_LEFT),
                'trabalhador' => $nome,
                'cpf' => $cpf_trabalhador,
                'data_hora' => date('d/m/Y H:i:s', strtotime($data_hora_atual)),
                'tipo' => strtoupper(str_replace('_', ' ', $tipo_batida)),
                'hash' => $hash_comprovante
            ];

            // Resposta JSON para requisições AJAX
            if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
                header('Content-Type: application/json');
                echo json_encode([
                    'sucesso' => true,
                    'mensagem' => $mensagem_sucesso,
                    'comprovante' => $comprovante_emitido
                ]);
                exit();
            }
        }
    }
}

// Verificar se já aceitou LGPD
$stmt_check_lgpd = $pdo->prepare("SELECT id FROM ponto_lgpd_termos WHERE usuario_id = :uid AND aceito = 1");
$stmt_check_lgpd->execute([':uid' => $usuario_id]);
$lgpd_aceito = $stmt_check_lgpd->fetchColumn();

// Buscar histórico de registros do mês atual
$stmt_hist = $pdo->prepare("SELECT * FROM ponto_registros WHERE usuario_id = :uid AND MONTH(data_hora) = MONTH(CURRENT_DATE()) AND YEAR(data_hora) = YEAR(CURRENT_DATE()) ORDER BY nsr DESC");
$stmt_hist->execute([':uid' => $usuario_id]);
$historico = $stmt_hist->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vixmed Ponto - REP-P (Portaria MTE 671)</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --azul-escuro: #0a1628;
            --azul-medio: #1a2d4a;
            --azul-claro: #243b5e;
            --verde: #00c853;
            --verde-hover: #00a844;
            --verde-suave: rgba(0, 200, 83, 0.12);
            --branco: #ffffff;
            --cinza-borda: rgba(255, 255, 255, 0.1);
            --cinza-texto: rgba(255, 255, 255, 0.75);
            --vermelho: #ef4444;
            --amarelo: #f59e0b;
            --sombra: 0 4px 24px rgba(0, 0, 0, 0.3);
            --radius: 14px;
            --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--azul-escuro);
            color: var(--branco);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* BARRA SUPERIOR NAV */
        .ponto-topbar { padding: 16px 24px; width: 100%; }
        .ponto-topbar-inner {
            max-width: 1400px;
            margin: 0 auto;
            background: var(--azul-medio);
            border-radius: 16px;
            padding: 10px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            border: 1px solid var(--cinza-borda);
        }

        .ponto-brand { display: flex; align-items: center; gap: 10px; }
        .ponto-brand img { width: 32px; height: 32px; border-radius: 8px; object-fit: contain; }
        .ponto-brand h1 { font-size: 18px; font-weight: 700; color: var(--branco); }
        .ponto-brand h1 span { color: var(--verde); }

        .ponto-nav { list-style: none; display: flex; gap: 6px; align-items: center; }
        .ponto-nav li a {
            display: flex; align-items: center; gap: 8px; padding: 10px 16px;
            color: rgba(255, 255, 255, 0.7); text-decoration: none; border-radius: 12px;
            font-size: 13px; font-weight: 600; transition: var(--transition);
            cursor: pointer !important;
        }
        .ponto-nav li a:hover { background: rgba(255, 255, 255, 0.1); color: var(--branco); }
        .ponto-nav li a.active { background: var(--verde); color: var(--branco); box-shadow: 0 4px 12px rgba(0, 200, 83, 0.3); }

        .ponto-user { display: flex; align-items: center; gap: 10px; }
        .ponto-user-avatar {
            width: 32px; height: 32px; background: var(--verde); border-radius: 50%;
            display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 12px; color: var(--branco);
        }
        .ponto-user-name { font-size: 13px; font-weight: 600; color: rgba(255, 255, 255, 0.9); }
        .ponto-user-logout { color: rgba(255, 255, 255, 0.5); text-decoration: none; font-size: 13px; padding: 6px 12px; border-radius: 8px; cursor: pointer !important; }
        .ponto-user-logout:hover { background: rgba(239, 68, 68, 0.2); color: var(--vermelho); }

        /* MAIN CONTENT */
        .ponto-main {
            flex: 1;
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
            padding: 16px 24px 32px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        /* SELO CONFORMIDADE MTE */
        .mte-badge {
            background: rgba(0, 200, 83, 0.12);
            border: 1px solid rgba(0, 200, 83, 0.25);
            color: var(--verde);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 20px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        /* RELÓGIO */
        .ponto-clock { text-align: center; margin-bottom: 24px; }
        .ponto-clock-time {
            font-size: clamp(3rem, 6vw, 4.5rem);
            font-weight: 800;
            letter-spacing: -2px;
            color: var(--branco);
        }
        .ponto-clock-date { font-size: 15px; color: rgba(255, 255, 255, 0.6); font-weight: 500; margin-top: 4px; text-transform: capitalize; }

        /* SELEÇÃO DO TIPO DE MARCAÇÃO */
        .tipo-selector {
            display: flex;
            gap: 8px;
            margin-bottom: 28px;
            background: var(--azul-medio);
            padding: 6px;
            border-radius: 14px;
            border: 1px solid var(--cinza-borda);
        }
        .tipo-btn {
            padding: 10px 18px;
            border: none;
            background: transparent;
            color: rgba(255, 255, 255, 0.7);
            font-family: inherit;
            font-size: 13px;
            font-weight: 600;
            border-radius: 10px;
            cursor: pointer !important;
            transition: var(--transition);
        }
        .tipo-btn.active {
            background: var(--verde);
            color: var(--branco);
            box-shadow: 0 4px 12px rgba(0, 200, 83, 0.3);
        }

        /* BOTÃO PUNCH CLOCK INTERATIVO (COM CURSOR POINTER) */
        .ponto-btn-container { position: relative; display: flex; align-items: center; justify-content: center; margin-bottom: 32px; }
        .ponto-btn-ring {
            position: absolute; width: 210px; height: 210px; border-radius: 50%;
            border: 3px solid rgba(0, 200, 83, 0.25); animation: pulseRing 2.5s ease-in-out infinite;
            pointer-events: none;
        }
        @keyframes pulseRing { 0%, 100% { transform: scale(1); opacity: 1; } 50% { transform: scale(1.08); opacity: 0.5; } }

        .ponto-btn {
            width: 170px; height: 170px; border-radius: 50%;
            background: linear-gradient(135deg, var(--verde) 0%, #00a844 100%);
            border: none; color: var(--branco); font-family: inherit; font-size: 15px; font-weight: 700;
            cursor: pointer !important; display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 6px; box-shadow: 0 8px 40px rgba(0, 200, 83, 0.4); transition: var(--transition);
            text-transform: uppercase; letter-spacing: 1px; user-select: none; z-index: 10;
        }
        .ponto-btn:hover { cursor: pointer !important; transform: scale(1.06) !important; box-shadow: 0 14px 55px rgba(0, 200, 83, 0.55) !important; }
        .ponto-btn:active { transform: scale(0.95) !important; }
        .ponto-btn-icon { font-size: 32px; pointer-events: none; }

        /* CARDS E CONTEÚDO PRINCIPAL */
        .grid-ponto-full { width: 100%; max-width: 1200px; margin-top: 20px; }
        .card-ponto {
            background: var(--azul-medio); border-radius: var(--radius); padding: 24px;
            border: 1px solid var(--cinza-borda); box-shadow: var(--sombra); width: 100%;
        }
        .card-header-flex { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; flex-wrap: wrap; gap: 10px; }
        .card-ponto h3 { font-size: 16px; font-weight: 700; color: var(--branco); display: flex; align-items: center; gap: 8px; margin: 0; }

        /* TABELA DE HISTÓRICO */
        .table-ponto { width: 100%; border-collapse: collapse; margin-top: 12px; }
        .table-ponto th { background: #0d2137; color: var(--branco); padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; text-transform: uppercase; }
        .table-ponto td { padding: 12px 16px; border-bottom: 1px solid var(--cinza-borda); font-size: 13px; color: rgba(255, 255, 255, 0.85); }
        .table-ponto tr:hover { background: rgba(0, 200, 83, 0.08); }

        /* COMPROVANTE DO TRABALHADOR ESTILO RECEIPT */
        .comprovante-box {
            background: #ffffff; color: #1a1a2e; padding: 20px; border-radius: 12px; font-family: monospace; font-size: 12px; line-height: 1.6;
            border: 2px dashed #00c853; box-shadow: 0 8px 30px rgba(0,0,0,0.4); margin-bottom: 16px;
        }
        .comprovante-header { text-align: center; border-bottom: 1px solid #ddd; padding-bottom: 10px; margin-bottom: 10px; font-weight: bold; }
        .comprovante-row { display: flex; justify-content: space-between; margin-bottom: 4px; }
        .comprovante-hash { background: #f0f2f5; padding: 6px; word-break: break-all; font-size: 10px; border-radius: 4px; margin-top: 8px; border: 1px solid #ccc; }

        /* BOTÕES DE AÇÃO */
        .btn-action-view {
            background: rgba(0, 200, 83, 0.15); border: 1px solid rgba(0, 200, 83, 0.3);
            color: var(--verde); padding: 6px 12px; border-radius: 8px; font-size: 12px;
            font-weight: 600; cursor: pointer !important; transition: var(--transition);
        }
        .btn-action-view:hover { background: var(--verde); color: var(--branco); }

        .btn-folha-unificada {
            background: rgba(0, 200, 83, 0.15); border: 1px solid rgba(0, 200, 83, 0.3);
            color: var(--verde); padding: 8px 14px; border-radius: 10px; font-size: 12px;
            font-weight: 700; cursor: pointer !important; transition: var(--transition);
        }
        .btn-folha-unificada:hover { background: var(--verde); color: var(--branco); }

        /* ===== MODAL POPUP COMPROVANTE (CLICAR FORA FECHA) ===== */
        .modal-comprovante-overlay {
            position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
            background: rgba(10, 22, 40, 0.85); backdrop-filter: blur(8px);
            display: none; align-items: center; justify-content: center; z-index: 1000; padding: 20px;
        }
        .modal-comprovante-overlay.open {
            display: flex !important; animation: popModal 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }
        @keyframes popModal { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }

        .modal-comprovante-card {
            background: var(--azul-medio); border: 1px solid var(--cinza-borda);
            border-radius: 20px; padding: 28px; max-width: 580px; width: 100%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.6); color: var(--branco); position: relative;
        }
        .modal-comprovante-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 18px; border-bottom: 1px solid var(--cinza-borda); padding-bottom: 12px;
        }
        .modal-comprovante-header h3 { font-size: 17px; font-weight: 700; color: var(--branco); margin: 0; }
        .btn-close-modal {
            background: rgba(255, 255, 255, 0.1); border: none; color: var(--branco);
            width: 32px; height: 32px; border-radius: 50%; font-size: 16px; cursor: pointer !important;
            display: flex; align-items: center; justify-content: center; transition: var(--transition);
        }
        .btn-close-modal:hover { background: rgba(239, 68, 68, 0.3); color: var(--vermelho); }

        .modal-footer-btns { display: flex; gap: 10px; margin-top: 18px; }

        /* MODAL LGPD */
        .lgpd-overlay {
            position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
            background: rgba(10, 22, 40, 0.85); backdrop-filter: blur(8px);
            display: flex; align-items: center; justify-content: center; z-index: 999;
        }
        .lgpd-card {
            background: var(--azul-medio); border: 1px solid var(--cinza-borda);
            border-radius: 20px; padding: 32px; max-width: 540px; width: 90%; color: var(--branco);
        }
        .lgpd-card h3 { font-size: 20px; margin-bottom: 12px; color: var(--verde); }
        .lgpd-card p { font-size: 14px; color: var(--cinza-texto); line-height: 1.6; margin-bottom: 20px; }

        .btn-green { background: var(--verde); color: var(--branco); border: none; padding: 12px 20px; border-radius: 10px; font-weight: 700; cursor: pointer !important; transition: var(--transition); }
        .btn-green:hover { background: var(--verde-hover); }

        .btn-secondary-modal { background: rgba(255, 255, 255, 0.1); color: var(--branco); border: 1px solid var(--cinza-borda); padding: 12px 20px; border-radius: 10px; font-weight: 600; cursor: pointer !important; transition: var(--transition); }
        .btn-secondary-modal:hover { background: rgba(255, 255, 255, 0.2); }

        @media (max-width: 768px) {
            .ponto-nav { flex-wrap: wrap; justify-content: center; }
            .ponto-user-name { display: none; }
        }
    </style>
</head>
<body>

    <!-- MODAL POPUP COMPROVANTE (CLICAR FORA FECHA) -->
    <div id="modal-comprovante-overlay" class="modal-comprovante-overlay" onclick="fecharModalComprovante(event)">
        <div class="modal-comprovante-card" onclick="event.stopPropagation()">
            <div class="modal-comprovante-header">
                <h3>🧾 Comprovante do Trabalhador (Portaria 671)</h3>
                <button type="button" class="btn-close-modal" onclick="fecharModalComprovanteDirect()" title="Fechar (ESC)">✖</button>
            </div>
            <div id="modal-comprovante-body">
                <!-- Conteúdo dinâmico do comprovante -->
            </div>
            <div class="modal-footer-btns">
                <button class="btn-green" style="flex: 1;" onclick="imprimirComprovante()">🖨️ Imprimir / Baixar PDF</button>
                <button class="btn-secondary-modal" onclick="fecharModalComprovanteDirect()">Fechar</button>
            </div>
        </div>
    </div>

    <!-- MODAL LGPD (Portaria MTE 671) -->
    <?php if (!$lgpd_aceito): ?>
    <div class="lgpd-overlay">
        <div class="lgpd-card">
            <h3>🔒 Termo de Consentimento LGPD</h3>
            <p>Em atendimento à Lei Geral de Proteção de Dados (Lei nº 13.709/2018) e às diretrizes do Ministério do Trabalho e Emprego (Portaria MTE nº 671/2021), informamos que os seus dados de registro de ponto (horário, IP de origem e identificação) são coletados exclusivamente para fins de controle de jornada de trabalho e emissão de comprovantes fiscais inalteráveis.</p>
            <form method="POST">
                <input type="hidden" name="acao" value="aceitar_lgpd">
                <button type="submit" class="btn-green" style="width: 100%;">Concordar e Acessar o Ponto</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- BARRA SUPERIOR -->
    <header class="ponto-topbar">
        <div class="ponto-topbar-inner">
            <div class="ponto-brand">
                <img src="img-10.webp" alt="Logo Vixmed">
                <h1>Vix<span>med</span> Ponto</h1>
            </div>

            <ul class="ponto-nav">
                <li><a href="folhadeponto.php" class="active"><span class="nav-icon">⏰</span> Marcar Ponto</a></li>
                <?php if (($_SESSION['usuario_tipo'] ?? '') === 'master'): ?>
                <li><a href="folhadeponto.php?export=afd" title="Arquivo Fonte de Dados para Fiscalização do Trabalho"><span class="nav-icon">📥</span> Exportar AFD (MTE)</a></li>
                <li><a href="folhadeponto.php?export=aej" title="Arquivo Eletrônico de Jornada"><span class="nav-icon">📊</span> Exportar AEJ (MTE)</a></li>
                <?php endif; ?>
                <li><a href="pagina.php"><span class="nav-icon">📊</span> Ir para Chamados</a></li>
            </ul>

            <div class="ponto-user">
                <div class="ponto-user-avatar"><?= $iniciais ?></div>
                <span class="ponto-user-name"><?= htmlspecialchars($nome) ?></span>
                <a href="logout.php" class="ponto-user-logout">🚪 Sair</a>
            </div>
        </div>
    </header>

    <!-- CONTEÚDO PRINCIPAL -->
    <main class="ponto-main">

        <!-- SELO CONFORMIDADE MTE -->
        <div class="mte-badge">
            <span>🛡️</span> Sistema de Ponto Homologado REP-P — Portaria MTE nº 671/2021
        </div>

        <!-- MENSAGEM SUCESSO -->
        <div id="mensagem-banner" style="display: <?= $mensagem_sucesso ? 'block' : 'none' ?>; background: rgba(0, 200, 83, 0.15); border: 1px solid rgba(0, 200, 83, 0.3); color: var(--verde); padding: 12px 24px; border-radius: 12px; margin-bottom: 20px; font-weight: 600;">
            ✅ <?= htmlspecialchars($mensagem_sucesso) ?>
        </div>

        <!-- RELÓGIO EM TEMPO REAL (HORÁRIO DE BRASÍLIA) -->
        <div class="ponto-clock">
            <div class="ponto-clock-time" id="relogio">00:00:00</div>
            <div class="ponto-clock-date" id="data-hoje"></div>
        </div>

        <!-- FORMULÁRIO DE MARCAÇÃO (AJAX NATIVO) -->
        <form method="POST" action="folhadeponto.php" id="form-ponto" onsubmit="return submeterPonto(event)" style="display: flex; flex-direction: column; align-items: center;">
            <input type="hidden" name="acao" value="marcar_ponto">
            <input type="hidden" name="tipo_registro" id="input-tipo-registro" value="entrada">

            <!-- ABAS SELEÇÃO TIPO -->
            <div class="tipo-selector">
                <button type="button" class="tipo-btn active" onclick="setTipo('entrada', this)">📥 Entrada</button>
                <button type="button" class="tipo-btn" onclick="setTipo('saida_almoco', this)">☕ Saída Almoço</button>
                <button type="button" class="tipo-btn" onclick="setTipo('retorno_almoco', this)">🥗 Retorno Almoço</button>
                <button type="button" class="tipo-btn" onclick="setTipo('saida', this)">📤 Saída</button>
            </div>

            <!-- BOTÃO CENTRAL MARCAR PONTO -->
            <div class="ponto-btn-container">
                <div class="ponto-btn-ring"></div>
                <button type="submit" class="ponto-btn" id="btn-marcar-ponto">
                    <span class="ponto-btn-icon">👆</span>
                    Registrar
                </button>
            </div>
        </form>

        <!-- REGISTROS RECENTES DO MÊS E ACESSO AOS COMPROVANTES -->
        <div class="grid-ponto-full">
            <div class="card-ponto">
                <div class="card-header-flex">
                    <h3>📋 Marcações do Mês Vigente</h3>
                    <?php if (count($historico) > 0): ?>
                        <button class="btn-folha-unificada" onclick="imprimirFolhaCompleta()">📑 Imprimir Folha Completa de Comprovantes</button>
                    <?php endif; ?>
                </div>

                <div id="tabela-historico-container">
                <?php if (count($historico) > 0): ?>
                    <table class="table-ponto">
                        <thead>
                            <tr>
                                <th>NSR</th>
                                <th>Data/Hora</th>
                                <th>Tipo</th>
                                <th>Hash SHA-256</th>
                                <th>Ação</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($historico as $h): 
                                $dados_comp = [
                                    'nsr' => str_pad($h['nsr'], 9, "0", STR_PAD_LEFT),
                                    'trabalhador' => $nome,
                                    'cpf' => $cpf_trabalhador,
                                    'data_hora' => date('d/m/Y H:i:s', strtotime($h['data_hora'])),
                                    'tipo' => strtoupper(str_replace('_', ' ', $h['tipo_registro'])),
                                    'hash' => $h['hash_comprovante']
                                ];
                            ?>
                                <tr>
                                    <td><strong>#<?= str_pad($h['nsr'], 6, "0", STR_PAD_LEFT) ?></strong></td>
                                    <td><?= date('d/m/Y H:i:s', strtotime($h['data_hora'])) ?></td>
                                    <td>
                                        <span style="color: var(--verde); font-weight: 600;">
                                            <?= str_replace('_', ' ', ucfirst($h['tipo_registro'])) ?>
                                        </span>
                                    </td>
                                    <td title="<?= htmlspecialchars($h['hash_comprovante']) ?>">
                                        <code><?= substr($h['hash_comprovante'], 0, 8) ?>...</code>
                                    </td>
                                    <td>
                                        <button class="btn-action-view" onclick='abrirModalComprovante(<?= json_encode($dados_comp, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>🧾 Comprovante</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p style="color: var(--cinza-texto); font-size: 13px; padding: 20px 0;">Nenhuma marcação de ponto registrada este mês.</p>
                <?php endif; ?>
                </div>
            </div>
        </div>

    </main>

    <script>
        const historicoCompleto = <?= json_encode(array_map(function($h) use ($nome, $cpf_trabalhador) {
            return [
                'nsr' => str_pad($h['nsr'], 9, "0", STR_PAD_LEFT),
                'trabalhador' => $nome,
                'cpf' => $cpf_trabalhador,
                'data_hora' => date('d/m/Y H:i:s', strtotime($h['data_hora'])),
                'tipo' => strtoupper(str_replace('_', ' ', $h['tipo_registro'])),
                'hash' => $h['hash_comprovante']
            ];
        }, $historico), JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        // Relógio em Tempo Real (Horário Oficial de Brasília)
        function atualizarRelogio() {
            const agora = new Date();
            const horas = String(agora.getHours()).padStart(2, '0');
            const minutos = String(agora.getMinutes()).padStart(2, '0');
            const segundos = String(agora.getSeconds()).padStart(2, '0');
            document.getElementById('relogio').textContent = `${horas}:${minutos}:${segundos}`;

            const opcoes = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            document.getElementById('data-hoje').textContent = agora.toLocaleDateString('pt-BR', opcoes);
        }

        setInterval(atualizarRelogio, 1000);
        atualizarRelogio();

        // Seleção do Tipo de Registro
        function setTipo(tipo, btn) {
            document.getElementById('input-tipo-registro').value = tipo;
            document.querySelectorAll('.tipo-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
        }

        // Submeter Ponto via AJAX
        function submeterPonto(e) {
            if (e) e.preventDefault();
            const btn = document.getElementById('btn-marcar-ponto');
            const inputTipo = document.getElementById('input-tipo-registro').value;

            btn.style.background = 'linear-gradient(135deg, #00a844 0%, #007a33 100%)';
            btn.innerHTML = '<span class="ponto-btn-icon">⏳</span>Registrando...';

            const formData = new FormData();
            formData.append('acao', 'marcar_ponto');
            formData.append('tipo_registro', inputTipo);
            formData.append('ajax', '1');

            fetch('folhadeponto.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.sucesso) {
                    btn.style.background = 'linear-gradient(135deg, #00c853 0%, #00a844 100%)';
                    btn.innerHTML = '<span class="ponto-btn-icon">✅</span>Registrado!';

                    // Abre o comprovante direto no Modal Popup
                    abrirModalComprovante(data.comprovante);

                    // Banner de sucesso
                    const banner = document.getElementById('mensagem-banner');
                    if (banner) {
                        banner.style.display = 'block';
                        banner.innerHTML = '✅ ' + data.mensagem;
                    }

                    setTimeout(() => {
                        window.location.reload();
                    }, 1200);
                } else {
                    document.getElementById('form-ponto').submit();
                }
            })
            .catch(err => {
                document.getElementById('form-ponto').submit();
            });

            return false;
        }

        // ABRIR E FECHAR MODAL COMPROVANTE (CLICAR FORA FECHA)
        function abrirModalComprovante(comp) {
            const body = document.getElementById('modal-comprovante-body');
            if (body) {
                body.innerHTML = `
                    <div class="comprovante-box" id="comprovante-print">
                        <div class="comprovante-header">
                            VIXMED SOLUÇÕES MÉDICAS LTDA<br>
                            CNPJ: 00.000.000/0001-00<br>
                            COMPROVANTE DE REGISTRO DE PONTO DO TRABALHADOR
                        </div>
                        <div class="comprovante-row"><span>NSR:</span> <strong>${comp.nsr}</strong></div>
                        <div class="comprovante-row"><span>Trabalhador:</span> <strong>${comp.trabalhador}</strong></div>
                        <div class="comprovante-row"><span>CPF:</span> <strong>${comp.cpf}</strong></div>
                        <div class="comprovante-row"><span>Data/Hora:</span> <strong>${comp.data_hora}</strong></div>
                        <div class="comprovante-row"><span>Tipo Batida:</span> <strong>${comp.tipo}</strong></div>
                        <div class="comprovante-hash">
                            <strong>Assinatura SHA-256:</strong><br>
                            ${comp.hash}
                        </div>
                    </div>
                `;
            }

            const overlay = document.getElementById('modal-comprovante-overlay');
            if (overlay) {
                overlay.classList.add('open');
            }
        }

        function fecharModalComprovante(e) {
            if (e.target.id === 'modal-comprovante-overlay') {
                fecharModalComprovanteDirect();
            }
        }

        function fecharModalComprovanteDirect() {
            const overlay = document.getElementById('modal-comprovante-overlay');
            if (overlay) {
                overlay.classList.remove('open');
            }
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                fecharModalComprovanteDirect();
            }
        });

        // Imprimir Comprovante Individual
        function imprimirComprovante() {
            const conteudo = document.getElementById('comprovante-print').outerHTML;
            const jan = window.open('', '', 'width=600,height=500');
            jan.document.write('<html><head><title>Comprovante de Ponto MTE</title></head><body style="font-family:monospace;">' + conteudo + '</body></html>');
            jan.document.close();
            jan.print();
        }

        // Imprimir Folha Completa Unificada de Todos os Comprovantes do Mês
        function imprimirFolhaCompleta() {
            if (!historicoCompleto || historicoCompleto.length === 0) {
                alert('Nenhum comprovante disponível para impressão.');
                return;
            }

            let html = `<html><head><title>Folha Unificada de Comprovantes - Vixmed Ponto</title>
            <style>
                body { font-family: monospace; font-size: 11px; padding: 24px; color: #1a1a2e; background: #fff; }
                .folha-header { text-align: center; margin-bottom: 24px; border-bottom: 2px solid #00c853; padding-bottom: 12px; }
                .folha-header h2 { margin: 0 0 4px 0; font-size: 16px; }
                .folha-header h3 { margin: 0 0 4px 0; font-size: 13px; color: #555; }
                .comprovante-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
                .comprovante-item { border: 1px dashed #00c853; padding: 12px; border-radius: 8px; background: #fafafa; page-break-inside: avoid; }
                .comp-title { font-weight: bold; border-bottom: 1px solid #eee; padding-bottom: 4px; margin-bottom: 8px; text-align: center; font-size: 10px; }
                .comp-row { display: flex; justify-content: space-between; margin-bottom: 3px; }
                .comp-hash { background: #f0f2f5; padding: 4px; font-size: 8px; word-break: break-all; margin-top: 6px; border-radius: 4px; border: 1px solid #ddd; }
                @media print { .comprovante-grid { grid-template-columns: repeat(2, 1fr); } }
            </style></head><body>
            <div class="folha-header">
                <h2>VIXMED SOLUÇÕES MÉDICAS LTDA — CNPJ: 00.000.000/0001-00</h2>
                <h3>FOLHA UNIFICADA DE COMPROVANTES DE REGISTRO DE PONTO (REP-P PORTARIA 671)</h3>
                <p>Trabalhador: <strong>${historicoCompleto[0].trabalhador}</strong> | CPF: <strong>${historicoCompleto[0].cpf}</strong></p>
            </div>
            <div class="comprovante-grid">`;

            historicoCompleto.forEach(item => {
                html += `
                <div class="comprovante-item">
                    <div class="comp-title">COMPROVANTE REGISTRO DE PONTO — NSR: ${item.nsr}</div>
                    <div class="comp-row"><span>Data/Hora:</span> <strong>${item.data_hora}</strong></div>
                    <div class="comp-row"><span>Tipo Batida:</span> <strong>${item.tipo}</strong></div>
                    <div class="comp-hash"><strong>Assinatura SHA-256:</strong><br>${item.hash}</div>
                </div>`;
            });

            html += `</div></body></html>`;

            const jan = window.open('', '', 'width=900,height=700');
            jan.document.write(html);
            jan.document.close();
            jan.print();
        }
    </script>

</body>
</html>
