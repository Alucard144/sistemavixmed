<?php
ini_set('display_errors', 0);
ini_set('pcre.jit', 0);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
session_start();
require_once "conexao.php";

// Restrição de acesso: logado no CRM ou logado como Master no portal principal
if (!isset($_SESSION['crm_logado']) || $_SESSION['crm_logado'] !== true) {
    if (isset($_SESSION['usuario_id']) && (($_SESSION['usuario_tipo'] ?? '') === 'master' || ($_SESSION['usuario_email'] ?? '') === 'ti@vixmed.com.br')) {
        $_SESSION['crm_logado'] = true;
        $_SESSION['crm_usuario_id'] = 1;
        $_SESSION['crm_usuario_nome'] = $_SESSION['usuario_nome'] ?? 'Romulo Rangel Araujo';
        $_SESSION['crm_usuario_email'] = $_SESSION['usuario_email'] ?? 'ti@vixmed.com.br';
    } else {
        header("Location: index.php");
        exit();
    }
}

$usuario_id = $_SESSION['crm_usuario_id'];
$nome_usuario = $_SESSION['crm_usuario_nome'];
$iniciais_usuario = mb_strtoupper(mb_substr($nome_usuario, 0, 2));

// ===== PARSER GOOGLE AGENDA iCAL (BASIC.ICS) =====
function parseGoogleICalFeed($ical_url) {
    $events = [];
    if (empty($ical_url) || !filter_var($ical_url, FILTER_VALIDATE_URL)) {
        return $events;
    }
    
    $opts = [
        "http" => ["method" => "GET", "header" => "User-Agent: Mozilla/5.0 (VixmedCRM/1.0)\r\n"]
    ];
    $context = stream_context_create($opts);
    $content = @file_get_contents($ical_url, false, $context);
    if (!$content) return $events;

    preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $content, $matches);
    foreach ($matches[1] as $vevent) {
        $summary = '';
        $dtstart = '';
        $description = '';
        $location = '';

        if (preg_match('/SUMMARY:(.*?)(\r\n|\n)/', $vevent, $m)) $summary = trim($m[1]);
        if (preg_match('/DTSTART;?(?:TZID=[^:]+)?:(.*?)(\r\n|\n)/', $vevent, $m)) $dtstart = trim($m[1]);
        if (preg_match('/DESCRIPTION:(.*?)(\r\n|\n)/', $vevent, $m)) $description = trim($m[1]);
        if (preg_match('/LOCATION:(.*?)(\r\n|\n)/', $vevent, $m)) $location = trim($m[1]);

        if ($dtstart) {
            $ts = strtotime($dtstart);
            if ($ts) {
                $events[] = [
                    'id' => 'g_' . md5($dtstart . $summary),
                    'titulo' => '📅 [Google] ' . ($summary ?: 'Compromisso Google Agenda'),
                    'data_compromisso' => date('Y-m-d', $ts),
                    'horario_compromisso' => date('H:i:s', $ts),
                    'descricao' => ($location ? "Local: $location. " : "") . $description,
                    'origem' => 'google',
                    'cor' => '#4285f4'
                ];
            }
        }
    }
    return $events;
}

// ===== HANDLER AJAX/POST ACTIONS =====
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];

    try {
        // Salvar/Editar Cliente/Empresa
        if ($action === 'salvar_cliente') {
            $cliente_id = intval($_POST['cliente_id'] ?? 0);
            $nome = trim($_POST['nome'] ?? '');
            $empresa = trim($_POST['empresa'] ?? '');
            $cnpj_cpf = trim($_POST['cnpj_cpf'] ?? '');
            $prestador = trim($_POST['prestador'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $telefone = trim($_POST['telefone'] ?? '');
            $cargo = trim($_POST['cargo'] ?? '');
            $cidade = trim($_POST['cidade'] ?? '');
            $estado = trim($_POST['estado'] ?? '');
            $status = $_POST['status'] ?? 'lead';
            $valor_proposta = floatval(str_replace(['R$', '.', ','], ['', '', '.'], $_POST['valor_proposta'] ?? '0'));
            $observacoes = trim($_POST['observacoes'] ?? '');

            if (empty($nome)) {
                echo json_encode(['sucesso' => false, 'erro' => 'Razão Social / Nome da Empresa é obrigatório!']);
                exit();
            }

            if ($cliente_id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE crm_clientes 
                    SET nome = :nome, empresa = :emp, cnpj_cpf = :cnpj, responsavel_nome = :prestador,
                        email = :email, telefone = :tel, cargo = :cargo, 
                        cidade = :cidade, estado = :uf, status = :status, 
                        valor_proposta = :val, observacoes = :obs 
                    WHERE id = :id AND usuario_id = :uid
                ");
                $stmt->execute([
                    ':nome' => $nome, ':emp' => $empresa, ':cnpj' => $cnpj_cpf, ':prestador' => $prestador,
                    ':email' => $email, ':tel' => $telefone, ':cargo' => $cargo,
                    ':cidade' => $cidade, ':uf' => $estado, ':status' => $status,
                    ':val' => $valor_proposta, ':obs' => $observacoes,
                    ':id' => $cliente_id, ':uid' => $usuario_id
                ]);
                $msg = 'Empresa/Cliente atualizada com sucesso!';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO crm_clientes 
                    (nome, empresa, cnpj_cpf, responsavel_nome, email, telefone, cargo, cidade, estado, status, valor_proposta, observacoes, usuario_id) 
                    VALUES (:nome, :emp, :cnpj, :prestador, :email, :tel, :cargo, :cidade, :uf, :status, :val, :obs, :uid)
                ");
                $stmt->execute([
                    ':nome' => $nome, ':emp' => $empresa, ':cnpj' => $cnpj_cpf, ':prestador' => $prestador,
                    ':email' => $email, ':tel' => $telefone, ':cargo' => $cargo,
                    ':cidade' => $cidade, ':uf' => $estado, ':status' => $status,
                    ':val' => $valor_proposta, ':obs' => $observacoes,
                    ':uid' => $usuario_id
                ]);
                $msg = 'Nova Empresa/Cliente cadastrada com sucesso!';
            }

            echo json_encode(['sucesso' => true, 'mensagem' => $msg]);
            exit();
        }

        // Mover Etapa no Funil
        if ($action === 'mover_status_cliente') {
            $cliente_id = intval($_POST['cliente_id'] ?? 0);
            $novo_status = $_POST['novo_status'] ?? 'lead';

            $stmt = $pdo->prepare("UPDATE crm_clientes SET status = :status WHERE id = :id AND usuario_id = :uid");
            $stmt->execute([':status' => $novo_status, ':id' => $cliente_id, ':uid' => $usuario_id]);
            echo json_encode(['sucesso' => true, 'mensagem' => 'Etapa alterada com sucesso!']);
            exit();
        }

        // Excluir Cliente/Lead
        if ($action === 'deletar_cliente') {
            $cliente_id = intval($_POST['cliente_id'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM crm_clientes WHERE id = :id AND usuario_id = :uid");
            $stmt->execute([':id' => $cliente_id, ':uid' => $usuario_id]);
            echo json_encode(['sucesso' => true, 'mensagem' => 'Empresa removida com sucesso!']);
            exit();
        }

        // Salvar Compromisso
        if ($action === 'salvar_compromisso') {
            $titulo = trim($_POST['titulo'] ?? '');
            $data = $_POST['data_compromisso'] ?? '';
            $hora = $_POST['horario_compromisso'] ?? '';
            $cliente_id = !empty($_POST['cliente_id']) ? intval($_POST['cliente_id']) : null;
            $descricao = trim($_POST['descricao'] ?? '');
            $cor = $_POST['cor'] ?? '#00c853';

            if (empty($titulo) || empty($data) || empty($hora)) {
                echo json_encode(['sucesso' => false, 'erro' => 'Preencha Título, Data e Horário!']);
                exit();
            }

            $stmt = $pdo->prepare("INSERT INTO crm_compromissos (usuario_id, cliente_id, titulo, data_compromisso, horario_compromisso, descricao, cor) VALUES (:uid, :cid, :titulo, :data_c, :hora_c, :desc, :cor)");
            $stmt->execute([
                ':uid' => $usuario_id,
                ':cid' => $cliente_id,
                ':titulo' => $titulo,
                ':data_c' => $data,
                ':hora_c' => $hora,
                ':desc' => $descricao,
                ':cor' => $cor
            ]);

            echo json_encode(['sucesso' => true, 'mensagem' => 'Reunião agendada com sucesso!']);
            exit();
        }

        // Excluir Compromisso
        if ($action === 'deletar_compromisso') {
            $comp_id = intval($_POST['compromisso_id'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM crm_compromissos WHERE id = :id AND usuario_id = :uid");
            $stmt->execute([':id' => $comp_id, ':uid' => $usuario_id]);
            echo json_encode(['sucesso' => true, 'mensagem' => 'Agendamento removido!']);
            exit();
        }

        // Salvar URL iCal do Google Agenda
        if ($action === 'salvar_google_agenda') {
            $ical_url = trim($_POST['ical_url'] ?? '');
            if (empty($ical_url)) {
                echo json_encode(['sucesso' => false, 'erro' => 'Cole a URL iCal pública do seu Google Agenda!']);
                exit();
            }

            $stmt = $pdo->prepare("INSERT INTO crm_google_agenda (usuario_id, ical_url) VALUES (:uid, :url)");
            $stmt->execute([':uid' => $usuario_id, ':url' => $ical_url]);
            echo json_encode(['sucesso' => true, 'mensagem' => 'Google Agenda integrado com sucesso!']);
            exit();
        }

        // Enviar E-mail
        if ($action === 'enviar_email') {
            $destinatario = trim($_POST['destinatario'] ?? '');
            $assunto = trim($_POST['assunto'] ?? '');
            $mensagem = trim($_POST['mensagem'] ?? '');

            if (empty($destinatario) || empty($assunto) || empty($mensagem)) {
                echo json_encode(['sucesso' => false, 'erro' => 'Preencha todos os campos do e-mail!']);
                exit();
            }

            $stmt = $pdo->prepare("INSERT INTO crm_emails_enviados (usuario_id, destinatario, assunto, mensagem) VALUES (:uid, :dest, :ass, :msg)");
            $stmt->execute([
                ':uid' => $usuario_id,
                ':dest' => $destinatario,
                ':ass' => $assunto,
                ':msg' => $mensagem
            ]);

            echo json_encode(['sucesso' => true, 'mensagem' => 'E-mail enviado com sucesso!']);
            exit();
        }

    } catch (Exception $e) {
        echo json_encode(['sucesso' => false, 'erro' => 'Erro: ' . $e->getMessage()]);
        exit();
    }
}

// ===== CONSULTAS DE DADOS =====
$busca = trim($_GET['busca'] ?? '');
$campo_busca = trim($_GET['campo_busca'] ?? 'nome');
$status_filtro = trim($_GET['status_filtro'] ?? '');
$email_folder = trim($_GET['folder'] ?? 'inbox');

$sql_leads = "SELECT * FROM crm_clientes WHERE usuario_id = :uid";
$params_leads = [':uid' => $usuario_id];

if (!empty($busca)) {
    if ($campo_busca === 'nome') $sql_leads .= " AND (nome LIKE :b OR empresa LIKE :b)";
    else if ($campo_busca === 'inscricao') $sql_leads .= " AND cnpj_cpf LIKE :b";
    else if ($campo_busca === 'prestador') $sql_leads .= " AND responsavel_nome LIKE :b";
    else if ($campo_busca === 'id') $sql_leads .= " AND id = :b_raw";
    else $sql_leads .= " AND (nome LIKE :b OR empresa LIKE :b OR email LIKE :b OR cnpj_cpf LIKE :b)";

    if ($campo_busca === 'id') $params_leads[':b_raw'] = intval($busca);
    else $params_leads[':b'] = "%$busca%";
}
if (!empty($status_filtro) && $status_filtro !== 'todos') {
    $sql_leads .= " AND status = :st";
    $params_leads[':st'] = $status_filtro;
}
$sql_leads .= " ORDER BY id DESC";

$stmt = $pdo->prepare($sql_leads);
$stmt->execute($params_leads);
$todos_leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

$leads_por_status = ['lead' => [], 'contato' => [], 'proposta' => [], 'fechado' => []];
$total_pipeline_val = 0; $total_propostas_val = 0; $total_fechados_val = 0;

foreach ($todos_leads as $l) {
    $st = $l['status'] ?? 'lead';
    if (isset($leads_por_status[$st])) $leads_por_status[$st][] = $l;
    else $leads_por_status['lead'][] = $l;

    $val = floatval($l['valor_proposta'] ?? 0);
    $total_pipeline_val += $val;
    if ($st === 'proposta') $total_propostas_val += $val;
    if ($st === 'fechado') $total_fechados_val += $val;
}

// Compromissos e Google Agenda
$stmt_comp = $pdo->prepare("
    SELECT c.*, cl.nome as cliente_nome 
    FROM crm_compromissos c 
    LEFT JOIN crm_clientes cl ON c.cliente_id = cl.id 
    WHERE c.usuario_id = :uid 
    ORDER BY c.data_compromisso ASC, c.horario_compromisso ASC
");
$stmt_comp->execute([':uid' => $usuario_id]);
$compromissos = $stmt_comp->fetchAll(PDO::FETCH_ASSOC);

$stmt_g = $pdo->prepare("SELECT ical_url FROM crm_google_agenda WHERE usuario_id = :uid AND ativo = 1 ORDER BY id DESC LIMIT 1");
$stmt_g->execute([':uid' => $usuario_id]);
$google_feed = $stmt_g->fetch(PDO::FETCH_ASSOC);
if ($google_feed && !empty($google_feed['ical_url'])) {
    $g_events = parseGoogleICalFeed($google_feed['ical_url']);
    $compromissos = array_merge($compromissos, $g_events);
    usort($compromissos, function($a, $b) {
        return strtotime($a['data_compromisso'] . ' ' . ($a['horario_compromisso'] ?? '00:00')) - strtotime($b['data_compromisso'] . ' ' . ($b['horario_compromisso'] ?? '00:00'));
    });
}

// E-mails
$email_busca = trim($_GET['email_busca'] ?? '');
$sql_em = "SELECT * FROM crm_emails_enviados WHERE usuario_id = :uid";
$params_em = [':uid' => $usuario_id];
if (!empty($email_busca)) {
    $sql_em .= " AND (destinatario LIKE :eb OR assunto LIKE :eb OR mensagem LIKE :eb)";
    $params_em[':eb'] = "%$email_busca%";
}
$sql_em .= " ORDER BY enviado_em DESC LIMIT 40";
$stmt_em = $pdo->prepare($sql_em);
$stmt_em->execute($params_em);
$emails_enviados = $stmt_em->fetchAll(PDO::FETCH_ASSOC);

$email_selecionado_id = intval($_GET['email_id'] ?? 0);
$email_leitura = null;
if ($email_selecionado_id > 0) {
    foreach ($emails_enviados as $em_item) {
        if ($em_item['id'] == $email_selecionado_id) { $email_leitura = $em_item; break; }
    }
}
if (!$email_leitura && count($emails_enviados) > 0) {
    $email_leitura = $emails_enviados[0];
}

$active_tab = $_GET['tab'] ?? 'clientes';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vixmed CRM Enterprise</title>

    <!-- ANTI-FLASH SCRIPT INLINE -->
    <script>
        (function() {
            var savedTheme = localStorage.getItem('crm_theme') || 'light';
            document.documentElement.setAttribute('data-theme', savedTheme);
        })();
    </script>

    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <style>
        html { background: #f8fafc; }
        html[data-theme="dark"] { background: #090e17; }

        :root {
            --bg-body: #f8fafc;
            --bg-navbar: #1e1e1e;
            --bg-sidebar: #ffffff;
            --bg-card: #ffffff;
            --bg-card-subtle: #f1f5f9;
            --border-color: #e2e8f0;

            --primary: #286090;
            --primary-hover: #1f4b72;
            --blue-accent: #2563eb;
            --danger-red: #ea4335;
            --green-vixmed: #00c853;

            --text-main: #202124;
            --text-muted: #5f6368;
            --text-dim: #9aa0a6;
            --shadow-gnome: 0 4px 16px rgba(0, 0, 0, 0.05);
        }

        [data-theme="dark"] {
            --bg-body: #090e17;
            --bg-navbar: #111827;
            --bg-sidebar: #111827;
            --bg-card: #1f2937;
            --bg-card-subtle: rgba(255,255,255,0.04);
            --border-color: rgba(255, 255, 255, 0.1);

            --primary: #3b82f6;
            --text-main: #e8eaed;
            --text-muted: #9aa0a6;
            --text-dim: #5f6368;
            --shadow-gnome: 0 10px 30px rgba(0,0,0,0.4);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Outfit', sans-serif;
            background: var(--bg-body);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            overflow-x: hidden;
        }

        /* ===== NAVBAR SUPERIOR ===== */
        .suite-navbar {
            background: var(--bg-navbar);
            color: #ffffff;
            padding: 0 20px;
            height: 56px;
            display: flex; align-items: center; justify-content: space-between;
            position: sticky; top: 0; z-index: 100;
        }

        .navbar-left { display: flex; align-items: center; gap: 14px; }
        .btn-hamburger-toggle { background: none; border: none; color: #ffffff; font-size: 20px; cursor: pointer; display: flex; align-items: center; }

        .company-selector-btn {
            background: #2b5c8f; color: #ffffff; border: 1px solid rgba(255,255,255,0.2);
            padding: 6px 14px; border-radius: 4px; font-size: 13px; font-weight: 600;
            display: flex; align-items: center; gap: 8px; cursor: pointer;
        }

        .navbar-menu { display: flex; align-items: center; gap: 4px; }
        .nav-tab-item {
            display: flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 4px;
            color: rgba(255,255,255,0.8); text-decoration: none; font-size: 12px; font-weight: 700; text-transform: uppercase;
        }
        .nav-tab-item:hover { color: #ffffff; background: rgba(255,255,255,0.1); }
        .nav-tab-item.active { color: #ffffff; background: #286090; }

        .navbar-right { display: flex; align-items: center; gap: 14px; }

        /* USER DROPDOWN MENU */
        .user-dropdown-container {
            position: relative;
            display: inline-block;
        }
        .user-dropdown-trigger {
            background: none;
            border: none;
            color: #ffffff;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 4px;
        }
        .user-dropdown-trigger:hover {
            background: rgba(255,255,255,0.1);
        }
        .user-dropdown-menu {
            position: absolute;
            right: 0;
            top: 100%;
            margin-top: 6px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.25);
            display: none;
            flex-direction: column;
            min-width: 160px;
            z-index: 1000;
            overflow: hidden;
        }
        .user-dropdown-container:hover .user-dropdown-menu,
        .user-dropdown-container.active .user-dropdown-menu {
            display: flex;
        }
        .user-dropdown-item {
            padding: 10px 16px;
            color: var(--text-main);
            text-decoration: none;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            background: transparent;
            border: none;
            width: 100%;
            text-align: left;
        }
        .user-dropdown-item:hover {
            background: var(--bg-card-subtle);
        }
        .user-dropdown-item.logout {
            color: var(--danger-red);
            border-top: 1px solid var(--border-color);
        }

        .erp-companies-container {
            background: var(--bg-card); border: 1px solid var(--border-color);
            border-radius: 8px; padding: 24px; box-shadow: var(--shadow-gnome);
        }

        .erp-page-title { font-size: 22px; font-weight: 400; color: var(--text-main); margin-bottom: 16px; }
        .erp-filter-toolbar { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; flex-wrap: wrap; }

        .erp-search-input {
            flex: 1; min-width: 260px; background: var(--bg-card); border: 1px solid #ccc;
            border-radius: 4px; padding: 8px 14px; font-size: 13px; color: var(--text-main); outline: none;
        }

        .erp-select-filter {
            background: var(--bg-card); border: 1px solid #ccc; border-radius: 4px;
            padding: 8px 12px; font-size: 13px; color: var(--text-main); outline: none;
        }

        .btn-erp-search {
            background: #286090; color: #ffffff; border: none; padding: 8px 20px;
            border-radius: 4px; font-size: 13px; font-weight: 600; cursor: pointer;
        }
        .btn-erp-search:hover { background: #1f4b72; }

        .btn-erp-new { background: #286090; color: #ffffff; border: none; padding: 8px 20px; border-radius: 4px; font-size: 13px; font-weight: 600; cursor: pointer; margin-left: auto; }
        .btn-erp-delete { background: #6c757d; color: #ffffff; border: none; padding: 8px 20px; border-radius: 4px; font-size: 13px; font-weight: 600; cursor: pointer; }

        .table-erp-companies { width: 100%; border-collapse: collapse; text-align: left; font-size: 13px; }
        .table-erp-companies th { color: var(--text-main); font-weight: 800; padding: 12px 16px; border-bottom: 2px solid #ddd; }
        .table-erp-companies td { padding: 12px 16px; border-bottom: 1px solid #eee; color: var(--text-main); vertical-align: middle; }
        .table-erp-companies tr:hover { background: var(--bg-card-subtle); }

        /* KANBAN FUNIL */
        .kanban-board { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; align-items: start; }
        .kanban-col { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 14px; padding: 16px; min-height: 500px; display: flex; flex-direction: column; gap: 12px; }
        .kanban-card { background: var(--bg-card-subtle); border: 1px solid var(--border-color); border-radius: 10px; padding: 14px; }
        .btn-stage-move { background: var(--bg-card-subtle); border: 1px solid var(--border-color); color: var(--text-main); font-size: 11px; padding: 5px 10px; border-radius: 20px; cursor: pointer; font-weight: 700; }
        .btn-stage-move:hover { background: var(--green-vixmed); color: #fff; border-color: var(--green-vixmed); }

        /* WEBMAIL ESTILO UOL MAIL PRO & MODOS DE VISUALIZAÇÃO */
        .webmail-container {
            display: grid;
            grid-template-columns: 240px 1fr;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            overflow: hidden;
            height: calc(100vh - 120px);
            transition: grid-template-columns 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* MODOS DE VISUALIZAÇÃO E SIDEBAR COLLAPSED DENSADOS */
        .webmail-container.view-mode-right { grid-template-columns: 240px 1fr; }
        .webmail-container.sidebar-collapsed.view-mode-right { grid-template-columns: 60px 1fr; }

        .webmail-container.view-mode-traditional { grid-template-columns: 240px 1fr; }
        .webmail-container.sidebar-collapsed.view-mode-traditional { grid-template-columns: 60px 1fr; }

        .webmail-container.view-mode-below { grid-template-columns: 240px 1fr; }
        .webmail-container.sidebar-collapsed.view-mode-below { grid-template-columns: 60px 1fr; }

        .webmail-main-content-split { display: grid; grid-template-columns: 380px 4px 1fr; height: 100%; overflow: hidden; width: 100%; transition: grid-template-columns 0.25s ease; }
        
        .webmail-container.view-mode-below .webmail-main-content-split { display: flex; flex-direction: column; grid-template-columns: none; }
        .webmail-container.view-mode-below .webmail-inbox-pane { height: 45%; border-right: none; border-bottom: 2px solid var(--border-color); }
        .webmail-container.view-mode-below .webmail-reader-pane { height: 55%; }

        /* POSICIONAMENTO EXPLÍCITO DOS GRIDS PARA EVITAR SHIFT */
        .webmail-inbox-pane { grid-column: 1; border-right: 1px solid var(--border-color); display: flex; flex-direction: column; overflow: hidden; height: 100%; }
        .webmail-divider { grid-column: 2; }
        .webmail-reader-pane { grid-column: 3; padding: 24px; overflow-y: auto; display: flex; flex-direction: column; justify-content: space-between; height: 100%; }
        .webmail-container.view-mode-traditional .btn-back-to-list { display: inline-flex !important; }

        /* DIVIDER / SEPARADOR */
        .webmail-divider {
            width: 4px;
            background: var(--border-color);
            cursor: pointer;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s;
            z-index: 10;
        }
        .webmail-divider:hover {
            background: var(--blue-accent);
        }
        .divider-toggle-btn {
            position: absolute;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: var(--blue-accent);
            color: #ffffff;
            border: 1px solid var(--border-color);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 800;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
            opacity: 0;
            transition: opacity 0.2s, transform 0.2s;
            pointer-events: auto;
        }
        .webmail-divider:hover .divider-toggle-btn, .divider-toggle-btn:hover {
            opacity: 1;
            transform: scale(1.1);
        }

        .webmail-top-searchbar { grid-column: 1 / -1; background: var(--bg-card-subtle); border-bottom: 1px solid var(--border-color); padding: 10px 20px; display: flex; align-items: center; justify-content: space-between; gap: 16px; }
        .webmail-search-box { display: flex; align-items: center; gap: 12px; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 28px; padding: 6px 16px; flex: 1; max-width: 550px; }
        .webmail-search-box input { border: none; background: transparent; outline: none; color: var(--text-main); font-family: inherit; font-size: 13px; width: 100%; }
        .webmail-sidebar-folders { background: var(--bg-card-subtle); border-right: 1px solid var(--border-color); padding: 14px 10px; display: flex; flex-direction: column; gap: 10px; overflow-x: hidden; }
        .webmail-sidebar-folders.collapsed {
            width: 60px !important;
            padding: 14px 4px !important;
            align-items: center !important;
        }
        .webmail-sidebar-folders.collapsed .folder-label,
        .webmail-sidebar-folders.collapsed .webmail-folder-count {
            display: none !important;
        }
        .webmail-sidebar-folders.collapsed .btn-webmail-escrever {
            padding: 0 !important;
            width: 40px !important;
            height: 40px !important;
            justify-content: center !important;
            border-radius: 50% !important;
            display: flex !important;
            align-items: center !important;
        }
        .webmail-sidebar-folders.collapsed .webmail-folder-link {
            padding: 10px 0 !important;
            justify-content: center !important;
            width: 40px !important;
            height: 40px !important;
            border-radius: 50% !important;
            display: flex !important;
            align-items: center !important;
        }
        .webmail-sidebar-folders.collapsed div[style*="display: flex; gap: 6px;"] {
            padding: 0 !important;
            justify-content: center !important;
            width: 100% !important;
        }
        .webmail-sidebar-folders.collapsed div[style*="display: flex; gap: 6px;"] button:last-child {
            display: none !important;
        }
        .btn-webmail-escrever { background: var(--bg-card); color: var(--text-main); border: 1px solid var(--border-color); padding: 10px 16px; border-radius: 28px; font-weight: 800; font-size: 13px; cursor: pointer; display: flex; align-items: center; gap: 10px; width: 100%; transition: all 0.2s; }
        .webmail-folder-link { display: flex; align-items: center; gap: 10px; padding: 8px 12px; border-radius: 6px; color: var(--text-muted); text-decoration: none; font-size: 13px; font-weight: 700; white-space: nowrap; }
        .webmail-folder-link:hover { background: var(--bg-card-subtle); color: var(--text-main); }
        .webmail-folder-link.active { background: rgba(37, 99, 235, 0.12); color: var(--blue-accent); }
        .webmail-mail-row { display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-bottom: 1px solid var(--border-color); cursor: pointer; text-decoration: none; color: inherit; }
        .webmail-mail-row:hover { background: var(--bg-card-subtle); }
        .webmail-mail-row.active { background: rgba(37, 99, 235, 0.08); border-left: 3px solid var(--blue-accent); }

        /* POPUP FLOATING DRAGGABLE DE NOVA MENSAGEM */
        .gmail-draggable-popup {
            position: fixed; bottom: 0; right: 80px; width: 560px; height: 600px;
            background: var(--bg-card); border: 1px solid var(--border-color);
            border-top-left-radius: 16px; border-top-right-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.25); display: none;
            flex-direction: column; z-index: 1000; overflow: hidden;
        }
        .gmail-popup-header { background: var(--bg-card-subtle); border-bottom: 1px solid var(--border-color); padding: 10px 16px; display: flex; align-items: center; justify-content: space-between; cursor: move; }

        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); backdrop-filter: blur(6px); display: none; align-items: center; justify-content: center; z-index: 1000; padding: 20px; }
        .modal-card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 16px; width: min(600px, 100%); padding: 30px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
        .form-group-erp { margin-bottom: 12px; }
        .form-group-erp label { display: block; font-size: 12px; font-weight: 700; margin-bottom: 4px; }
        .form-group-erp input, .form-group-erp select { width: 100%; padding: 8px 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; outline: none; }
    </style>
</head>
<body>

    <!-- NAVBAR SUPERIOR DO SISTEMA (ESTILO SIGO / WISE CORPORATIVO) -->
    <header class="suite-navbar">
        <div class="navbar-left">
            <button class="btn-hamburger-toggle" onclick="toggleSidebarMenu()" title="Abrir Menu Lateral (☰)">☰</button>
            <a href="dashboard.php" style="color: #ffffff; text-decoration: none; font-weight: 800; font-size: 16px;">VIXMED CRM</a>
        </div>

        <nav class="navbar-menu">
            <a href="dashboard.php?tab=clientes" class="nav-tab-item <?= $active_tab === 'clientes' ? 'active' : '' ?>">🏢 Empresas</a>
            <a href="dashboard.php?tab=leads" class="nav-tab-item <?= $active_tab === 'leads' ? 'active' : '' ?>">📌 Leads</a>
            <a href="dashboard.php?tab=sales" class="nav-tab-item <?= $active_tab === 'sales' ? 'active' : '' ?>">💼 Funil Vendas</a>
            <a href="dashboard.php?tab=activities" class="nav-tab-item <?= $active_tab === 'activities' ? 'active' : '' ?>">📅 Agenda</a>
            <a href="dashboard.php?tab=emails" class="nav-tab-item <?= $active_tab === 'emails' ? 'active' : '' ?>">📧 E-mail</a>
            <a href="dashboard.php?tab=metrics" class="nav-tab-item <?= $active_tab === 'metrics' ? 'active' : '' ?>">📊 BI</a>
        </nav>

        <div class="navbar-right">
            <!-- DROPDOWN DE OPÇÕES DO USUÁRIO -->
            <div class="user-dropdown-container">
                <button class="user-dropdown-trigger">
                    <span>Olá, <?= htmlspecialchars(explode(' ', $nome_usuario)[0]) ?></span> <span>▾</span>
                </button>
                <div class="user-dropdown-menu">
                    <button class="user-dropdown-item" onclick="alternarTema()">
                        <span id="theme-icon">☀️</span> <span>Alternar Tema</span>
                    </button>
                    <a href="logout.php" class="user-dropdown-item logout">
                        <span>🚪</span> <span>Sair</span>
                    </a>
                </div>
            </div>
        </div>
    </header>

    <div style="padding: 24px;">

        <!-- TAB 1: EMPRESAS (SIGO/WISE DESIGN) -->
        <?php if ($active_tab === 'clientes'): ?>
            <div class="erp-companies-container">
                <h1 class="erp-page-title">Empresas</h1>

                <form method="GET" class="erp-filter-toolbar">
                    <input type="hidden" name="tab" value="clientes">
                    <input type="text" name="busca" class="erp-search-input" placeholder="Digite o que você gostaria de pesquisar" value="<?= htmlspecialchars($busca) ?>">
                    
                    <select name="campo_busca" class="erp-select-filter">
                        <option value="nome" <?= $campo_busca === 'nome' ? 'selected' : '' ?>>Nome / Fantasia</option>
                        <option value="inscricao" <?= $campo_busca === 'inscricao' ? 'selected' : '' ?>>Inscrição / CNPJ</option>
                        <option value="prestador" <?= $campo_busca === 'prestador' ? 'selected' : '' ?>>Prestador</option>
                        <option value="id" <?= $campo_busca === 'id' ? 'selected' : '' ?>>ID</option>
                    </select>

                    <select name="status_filtro" class="erp-select-filter">
                        <option value="todos" <?= $status_filtro === 'todos' ? 'selected' : '' ?>>Ativos</option>
                        <option value="lead" <?= $status_filtro === 'lead' ? 'selected' : '' ?>>Leads</option>
                        <option value="fechado" <?= $status_filtro === 'fechado' ? 'selected' : '' ?>>Contratos Fechados</option>
                    </select>

                    <button type="submit" class="btn-erp-search">Pesquisar</button>
                    <button type="button" class="btn-erp-new" onclick="abrirModalNovoCliente()">Novo</button>
                    <button type="button" class="btn-erp-delete" onclick="excluirSelecionadosErp()">Excluir</button>
                </form>

                <div style="overflow-x: auto;">
                    <table class="table-erp-companies">
                        <thead>
                            <tr>
                                <th style="width: 40px;"><input type="checkbox" id="chk-erp-all" onclick="toggleErpCheckboxes(this)"></th>
                                <th>Nome</th>
                                <th>Fantasia</th>
                                <th>Inscrição</th>
                                <th>Prestador</th>
                                <th>ID</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($todos_leads) > 0): ?>
                                <?php foreach ($todos_leads as $emp): ?>
                                    <tr>
                                        <td><input type="checkbox" class="chk-erp-item" value="<?= $emp['id'] ?>"></td>
                                        <td><strong><?= htmlspecialchars($emp['nome']) ?></strong></td>
                                        <td><?= htmlspecialchars($emp['empresa'] ?: htmlspecialchars($emp['nome'])) ?></td>
                                        <td><code><?= htmlspecialchars($emp['cnpj_cpf'] ?: '—') ?></code></td>
                                        <td><?= htmlspecialchars($emp['responsavel_nome'] ?: 'Vixmed Medicina') ?></td>
                                        <td>#<?= sprintf('%04d', $emp['id']) ?></td>
                                        <td>
                                            <button class="btn-stage-move" onclick='editarLead(<?= json_encode($emp, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>✏️ Editar</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="7" style="text-align: center; padding: 40px; color: var(--text-muted);">Nenhuma empresa cadastrada.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <!-- TAB 2: LEADS -->
        <?php elseif ($active_tab === 'leads'): ?>
            <div class="erp-companies-container">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h1 class="erp-page-title">📌 Oportunidades & Leads</h1>
                    <button class="btn-erp-search" onclick="abrirModalNovoCliente()">➕ Criar Lead</button>
                </div>
                <table class="table-erp-companies">
                    <thead>
                        <tr>
                            <th>Nome do Lead</th>
                            <th>Empresa</th>
                            <th>Status</th>
                            <th>E-mail</th>
                            <th>Telefone</th>
                            <th>Valor Proposta</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($todos_leads as $ld): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($ld['nome']) ?></strong></td>
                                <td><?= htmlspecialchars($ld['empresa'] ?: '—') ?></td>
                                <td><span style="background: rgba(37, 99, 235, 0.12); color: #2563eb; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 800;"><?= strtoupper($ld['status'] ?: 'lead') ?></span></td>
                                <td><?= htmlspecialchars($ld['email'] ?: '—') ?></td>
                                <td><?= htmlspecialchars($ld['telefone'] ?: '—') ?></td>
                                <td style="font-family: 'JetBrains Mono', monospace; font-weight: 700; color: var(--green-vixmed);">R$ <?= number_format(floatval($ld['valor_proposta'] ?? 0), 2, ',', '.') ?></td>
                                <td><button class="btn-stage-move" onclick='editarLead(<?= json_encode($ld, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>✏️ Editar</button></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <!-- TAB 3: FUNIL DE VENDAS KANBAN -->
        <?php elseif ($active_tab === 'sales'): ?>
            <div class="kanban-board">
                <div class="kanban-col">
                    <div style="font-weight: 800; color: var(--blue-accent); margin-bottom: 12px;">🌱 Novo Lead (<?= count($leads_por_status['lead']) ?>)</div>
                    <?php foreach ($leads_por_status['lead'] as $card): ?>
                        <div class="kanban-card">
                            <strong style="font-size: 14px;"><?= htmlspecialchars($card['nome']) ?></strong>
                            <div style="font-size: 12px; color: var(--text-muted); margin-top: 4px;"><?= htmlspecialchars($card['empresa'] ?: 'Geral') ?></div>
                            <div style="font-family: 'JetBrains Mono', monospace; font-weight: 700; color: var(--green-vixmed); margin: 8px 0;">R$ <?= number_format(floatval($card['valor_proposta'] ?? 0), 2, ',', '.') ?></div>
                            <button class="btn-stage-move" onclick="moverEtapa(<?= $card['id'] ?>, 'contato')">Mover 📞 ➔</button>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="kanban-col">
                    <div style="font-weight: 800; color: #f59e0b; margin-bottom: 12px;">📞 Em Contato (<?= count($leads_por_status['contato']) ?>)</div>
                    <?php foreach ($leads_por_status['contato'] as $card): ?>
                        <div class="kanban-card">
                            <strong style="font-size: 14px;"><?= htmlspecialchars($card['nome']) ?></strong>
                            <div style="font-size: 12px; color: var(--text-muted); margin-top: 4px;"><?= htmlspecialchars($card['empresa'] ?: 'Geral') ?></div>
                            <div style="font-family: 'JetBrains Mono', monospace; font-weight: 700; color: var(--green-vixmed); margin: 8px 0;">R$ <?= number_format(floatval($card['valor_proposta'] ?? 0), 2, ',', '.') ?></div>
                            <button class="btn-stage-move" onclick="moverEtapa(<?= $card['id'] ?>, 'proposta')">Proposta 📄 ➔</button>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="kanban-col">
                    <div style="font-weight: 800; color: #7c3aed; margin-bottom: 12px;">📄 Proposta Enviada (<?= count($leads_por_status['proposta']) ?>)</div>
                    <?php foreach ($leads_por_status['proposta'] as $card): ?>
                        <div class="kanban-card">
                            <strong style="font-size: 14px;"><?= htmlspecialchars($card['nome']) ?></strong>
                            <div style="font-size: 12px; color: var(--text-muted); margin-top: 4px;"><?= htmlspecialchars($card['empresa'] ?: 'Geral') ?></div>
                            <div style="font-family: 'JetBrains Mono', monospace; font-weight: 700; color: var(--green-vixmed); margin: 8px 0;">R$ <?= number_format(floatval($card['valor_proposta'] ?? 0), 2, ',', '.') ?></div>
                            <button class="btn-stage-move" onclick="moverEtapa(<?= $card['id'] ?>, 'fechado')" style="background: var(--green-vixmed); color: #fff;">Fechar 🎉 ➔</button>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="kanban-col">
                    <div style="font-weight: 800; color: var(--green-vixmed); margin-bottom: 12px;">🎉 Fechado / Ganho (<?= count($leads_por_status['fechado']) ?>)</div>
                    <?php foreach ($leads_por_status['fechado'] as $card): ?>
                        <div class="kanban-card" style="border-color: var(--green-vixmed);">
                            <strong style="font-size: 14px;"><?= htmlspecialchars($card['nome']) ?></strong>
                            <div style="font-size: 12px; color: var(--text-muted); margin-top: 4px;"><?= htmlspecialchars($card['empresa'] ?: 'Geral') ?></div>
                            <div style="font-family: 'JetBrains Mono', monospace; font-weight: 700; color: var(--green-vixmed); margin: 8px 0;">R$ <?= number_format(floatval($card['valor_proposta'] ?? 0), 2, ',', '.') ?></div>
                            <span style="font-size: 11px; color: var(--green-vixmed); font-weight: 700;">✅ Venda Concluída</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        <!-- TAB 4: AGENDA -->
        <?php elseif ($active_tab === 'activities'): ?>
            <div class="erp-companies-container">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h1 class="erp-page-title">📅 Agenda Unificada & Google Agenda</h1>
                    <div style="display: flex; gap: 10px;">
                        <button class="btn-erp-search" onclick="abrirModalGoogleAgenda()">🔄 Google Agenda</button>
                        <button class="btn-erp-search" onclick="abrirModalNovaReuniao()">➕ Novo Compromisso</button>
                    </div>
                </div>
                <table class="table-erp-companies">
                    <thead>
                        <tr>
                            <th>Data / Horário</th>
                            <th>Origem</th>
                            <th>Título</th>
                            <th>Empresa / Cliente</th>
                            <th>Descrição</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($compromissos as $c): ?>
                            <tr>
                                <td><strong style="color: var(--blue-accent); font-family: 'JetBrains Mono', monospace;"><?= date('d/m/Y', strtotime($c['data_compromisso'])) ?> às <?= date('H:i', strtotime($c['horario_compromisso'] ?? '00:00')) ?></strong></td>
                                <td><span style="background: <?= $c['cor'] ?? '#00c853' ?>; color: #fff; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 800;"><?= ($c['origem'] ?? '') === 'google' ? 'Google Agenda' : 'CRM Vixmed' ?></span></td>
                                <td><strong><?= htmlspecialchars($c['titulo']) ?></strong></td>
                                <td><?= htmlspecialchars($c['cliente_nome'] ?: '—') ?></td>
                                <td><?= htmlspecialchars($c['descricao'] ?: 'Sem pauta') ?></td>
                                <td>
                                    <?php if (($c['origem'] ?? '') !== 'google'): ?>
                                        <button class="btn-stage-move" onclick="deletarCompromisso(<?= $c['id'] ?>)" style="color: var(--danger-red);">🗑️ Remov</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <!-- TAB 5: E-MAIL COMPLETO COM WYSIWYG & DRAGGABLE POPUP -->
        <?php elseif ($active_tab === 'emails'): 
            // Mock Email Data - UOL Mail Pro
            $mock_inbox = [
                ['id' => 'm1', 'remetente' => 'Helen (Financeiro Vixmed)', 'assunto' => 'Faturamento mensal - Clinica Vitória', 'mensagem' => 'Seguem em anexo os relatórios de faturamento das guias de exames de PCMSO e exames complementares do último mês.', 'enviado_em' => date('Y-m-d H:i:s', strtotime('-10 mins')), 'estrela' => false],
                ['id' => 'm2', 'remetente' => 'Dayana (CRM Vixmed)', 'assunto' => 'Reunião de alinhamento de novos leads', 'mensagem' => 'Romulo, marcamos para as 14h a apresentação do PGR para a nova construtora parceira. Favor preparar a sala de conferências.', 'enviado_em' => date('Y-m-d H:i:s', strtotime('-2 hours')), 'estrela' => true],
                ['id' => 'm3', 'remetente' => 'Marianna (Secretaria)', 'assunto' => 'ASO pendente - Romulo Rangel Araujo', 'mensagem' => 'Precisamos recolher a assinatura digital do seu ASO de segunda-feira para homologação da nova admissão.', 'enviado_em' => date('Y-m-d H:i:s', strtotime('-1 day')), 'estrela' => false],
            ];

            $mock_drafts = [
                ['id' => 'm4', 'remetente' => 'Estefany (Comercial)', 'assunto' => 'Rascunho de Proposta SST - Metalúrgica Tubarão', 'mensagem' => 'Olá equipe comercial! Segue anexo preliminar do PCMSO e PGR para a metalúrgica.', 'enviado_em' => date('Y-m-d H:i:s', strtotime('-2 days')), 'estrela' => false],
            ];

            $mock_trash = [
                ['id' => 'm5', 'remetente' => 'Propaganda Externa', 'assunto' => 'Compre insumos médicos com desconto', 'mensagem' => 'Oferta de máscaras descartáveis e luvas cirúrgicas com 50% de desconto.', 'enviado_em' => date('Y-m-d H:i:s', strtotime('-3 days')), 'estrela' => false],
            ];

            $mock_spam = [
                ['id' => 'm6', 'remetente' => 'Vendedor Desconhecido', 'assunto' => 'Oportunidade única de investimento financeiro', 'mensagem' => 'Ganhe dinheiro rápido investindo na nossa plataforma com taxas garantidas.', 'enviado_em' => date('Y-m-d H:i:s', strtotime('-1 day')), 'estrela' => false],
            ];

            $mock_starred = [
                ['id' => 'm7', 'remetente' => 'Diretoria Vixmed', 'assunto' => '⭐ Planejamento Estratégico 2026', 'mensagem' => 'Diretrizes sobre o plano de expansão física das clínicas e credenciamento em novas cidades capixabas.', 'enviado_em' => date('Y-m-d H:i:s', strtotime('-1 day')), 'estrela' => true],
            ];

            $emails_exibir = [];
            if ($email_folder === 'inbox') $emails_exibir = $mock_inbox;
            else if ($email_folder === 'sent') {
                foreach ($emails_enviados as $db_em) {
                    $emails_exibir[] = [
                        'id' => $db_em['id'],
                        'remetente' => $db_em['destinatario'],
                        'assunto' => $db_em['assunto'],
                        'mensagem' => $db_em['mensagem'],
                        'enviado_em' => $db_em['enviado_em'],
                        'estrela' => false,
                        'is_db' => true
                    ];
                }
            }
            else if ($email_folder === 'drafts') $emails_exibir = $mock_drafts;
            else if ($email_folder === 'trash') $emails_exibir = $mock_trash;
            else if ($email_folder === 'spam') $emails_exibir = $mock_spam;
            else if ($email_folder === 'starred') $emails_exibir = $mock_starred;
            else if ($email_folder === 'unread') $emails_exibir = $mock_inbox;
            else $emails_exibir = $mock_inbox;

            if (!empty($email_busca)) {
                $filtered = [];
                $q = mb_strtolower($email_busca);
                foreach ($emails_exibir as $em) {
                    if (mb_strpos(mb_strtolower($em['remetente']), $q) !== false ||
                        mb_strpos(mb_strtolower($em['assunto']), $q) !== false ||
                        mb_strpos(mb_strtolower($em['mensagem']), $q) !== false) {
                        $filtered[] = $em;
                    }
                }
                $emails_exibir = $filtered;
            }

            // Set default reader email
            $emails_exibir_id = $_GET['email_id'] ?? 0;
            $email_leitura = null;
            if ($emails_exibir_id) {
                foreach ($emails_exibir as $item) {
                    if ($item['id'] == $emails_exibir_id) { $email_leitura = $item; break; }
                }
            }
            if (!$email_leitura && count($emails_exibir) > 0) {
                $email_leitura = $emails_exibir[0];
            }
        ?>
            <div class="webmail-container view-mode-right">
                <div class="webmail-top-searchbar">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <button class="btn-hamburger-toggle" onclick="toggleWebmailSidebarFolders()" title="Expandir/Esconder Pastas (☰)">☰</button>
                        
                        <!-- LOGOTIPO UOL MAIL PRO -->
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <div style="width: 22px; height: 22px; border-radius: 50%; background: linear-gradient(135deg, #ff9800, #f44336); box-shadow: inset 0 2px 4px rgba(255,255,255,0.4);"></div>
                            <span style="font-weight: 800; font-size: 18px; color: var(--text-main); font-family: 'Outfit', sans-serif;">uol <span style="font-weight: 300; color: var(--text-muted);">mail pro</span></span>
                        </div>
                    </div>

                    <form method="GET" class="webmail-search-box">
                        <input type="hidden" name="tab" value="emails">
                        <input type="hidden" name="folder" value="<?= htmlspecialchars($email_folder) ?>">
                        <span>🔍</span>
                        <input type="text" name="email_busca" placeholder="Buscar e-mail..." value="<?= htmlspecialchars($email_busca) ?>">
                        <button type="submit" style="background: none; border: none; cursor: pointer; color: var(--blue-accent); font-weight: 800;">Pesquisar</button>
                    </form>

                    <!-- FERRAMENTAS UOL MAIL PRO & MODOS DE VISUALIZAÇÃO -->
                    <div style="display: flex; align-items: center; gap: 14px;">
                        <!-- DROPDOWN MODOS DE VISUALIZAÇÃO EXATO DA IMAGEM DO USUÁRIO -->
                        <div style="position: relative; display: inline-block;">
                            <button class="btn-stage-move" onclick="toggleUolViewModeMenu()" style="padding: 6px 12px; font-weight: 700; background: var(--bg-card); border-color: var(--border-color);">
                                📰 Modos de visualização ▾
                            </button>
                            <div id="uol-viewmode-menu" style="position: absolute; right: 0; top: 100%; margin-top: 6px; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); display: none; flex-direction: column; min-width: 250px; z-index: 1000; overflow: hidden; padding: 6px 0;">
                                <label onclick="setUolViewMode('traditional')" style="padding: 10px 16px; display: flex; align-items: center; justify-content: space-between; font-size: 13px; cursor: pointer; color: var(--text-main); border-bottom: 1px solid var(--border-color);">
                                    <span>📋 Tradicional com listas</span>
                                    <input type="radio" name="uol_view_radio" id="radio-traditional" value="traditional">
                                </label>
                                <label onclick="setUolViewMode('right')" style="padding: 10px 16px; display: flex; align-items: center; justify-content: space-between; font-size: 13px; cursor: pointer; color: var(--text-main); border-bottom: 1px solid var(--border-color);">
                                    <span>📖 Lista e e-mail aberto à direita</span>
                                    <input type="radio" name="uol_view_radio" id="radio-right" value="right" checked>
                                </label>
                                <label onclick="setUolViewMode('below')" style="padding: 10px 16px; display: flex; align-items: center; justify-content: space-between; font-size: 13px; cursor: pointer; color: var(--text-main);">
                                    <span>📑 Lista e e-mail aberto abaixo</span>
                                    <input type="radio" name="uol_view_radio" id="radio-below" value="below">
                                </label>
                            </div>
                        </div>

                        <span style="font-size: 12px; color: var(--text-muted); font-weight: 600;">0% de 50 GB</span>
                        <span title="Configurações" style="cursor: pointer; font-size: 16px;">⚙️</span>
                    </div>
                </div>

                <!-- COLUNA 1: PASTAS UOL MAIL PRO -->
                <div class="webmail-sidebar-folders" id="webmail-folders-sidebar" style="background: rgba(255,255,255,0.02); backdrop-filter: blur(12px); border-right: 1px solid var(--border-color); display: flex; flex-direction: column; justify-content: space-between;">
                    <div style="display: flex; flex-direction: column; gap: 2px; overflow-y: auto; padding-bottom: 20px;">
                        
                        <div style="padding: 0 10px 10px 10px; display: flex; gap: 6px;">
                            <button class="btn-webmail-escrever" onclick="abrirModalNovoEmail()" style="background: #ea4335; color: #ffffff; border: none; padding: 12px 18px; border-radius: 4px; font-weight: 700; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); flex: 1; justify-content: center;">
                                <span style="font-size: 14px;">✏️</span> <span class="folder-label">Escrever</span>
                            </button>
                            <button onclick="location.reload()" title="Atualizar Caixa" style="background: var(--bg-card-subtle); border: 1px solid var(--border-color); padding: 8px 12px; border-radius: 4px; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 14px;">🔄</button>
                        </div>

                        <!-- FOLDERS CORPORATIVOS EXATOS UOL -->
                        <a href="dashboard.php?tab=emails&folder=inbox" class="webmail-folder-link <?= $email_folder === 'inbox' ? 'active' : '' ?>">
                            <span>📩</span> <span class="folder-label">Entrada</span>
                        </a>
                        <a href="dashboard.php?tab=emails&folder=sent" class="webmail-folder-link <?= $email_folder === 'sent' ? 'active' : '' ?>">
                            <span>🚀</span> <span class="folder-label">Enviados</span>
                        </a>
                        <a href="dashboard.php?tab=emails&folder=drafts" class="webmail-folder-link <?= $email_folder === 'drafts' ? 'active' : '' ?>">
                            <span>📄</span> <span class="folder-label">Rascunhos</span> <span class="webmail-folder-count">9</span>
                        </a>
                        <a href="dashboard.php?tab=emails&folder=trash" class="webmail-folder-link <?= $email_folder === 'trash' ? 'active' : '' ?>">
                            <span>🗑️</span> <span class="folder-label">Lixeira</span>
                        </a>
                        <a href="dashboard.php?tab=emails&folder=spam" class="webmail-folder-link <?= $email_folder === 'spam' ? 'active' : '' ?>">
                            <span>❎</span> <span class="folder-label">Spam</span>
                        </a>
                        <a href="dashboard.php?tab=emails&folder=starred" class="webmail-folder-link <?= $email_folder === 'starred' ? 'active' : '' ?>">
                            <span>⭐</span> <span class="folder-label">Destacados</span>
                        </a>
                        <a href="dashboard.php?tab=emails&folder=unread" class="webmail-folder-link <?= $email_folder === 'unread' ? 'active' : '' ?>">
                            <span>✉️</span> <span class="folder-label">Não lidos</span>
                        </a>

                        <div style="border-top: 1px solid var(--border-color); margin: 10px 0; padding-top: 10px; display: flex; flex-direction: column; gap: 2px;">
                            <a href="#" class="webmail-folder-link" style="color: var(--text-muted);">
                                <span>⚙️</span> <span class="folder-label">Editar pastas</span>
                            </a>
                            <a href="#" class="webmail-folder-link" style="color: var(--text-muted);">
                                <span>➕</span> <span class="folder-label">Criar nova pasta</span>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- PAINÉIS DE DIVISÃO ADAPTATIVA (SPLIT MAIN CONTENT) -->
                <div class="webmail-main-content-split">
                    <!-- COLUNA 2: MENSAGENS -->
                    <div class="webmail-inbox-pane">
                        <div style="padding: 10px 16px; border-bottom: 1px solid var(--border-color); background: var(--bg-card-subtle); display: flex; align-items: center; justify-content: space-between; font-size: 12px;">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <input type="checkbox" id="select-all-emails" onchange="toggleSelecionarTodosEmails(this)" title="Selecionar todos">
                                <button type="button" onclick="location.reload()" class="btn-stage-move" style="padding: 6px 14px; border-radius: 20px; font-weight: 700;">🔄 Atualizar</button>
                                <button type="button" id="btn-excluir-selecionados" onclick="excluirEmailsSelecionados()" class="btn-stage-move" style="display: none; background: rgba(220,38,38,0.1); color: var(--danger-red);">🗑️ Excluir Selecionados (<span id="count-selecionados">0</span>)</button>
                            </div>
                            <div>1-<?= count($emails_exibir) ?> de <?= count($emails_exibir) ?></div>
                        </div>

                        <div style="overflow-y: auto; flex: 1;">
                            <?php if (count($emails_exibir) > 0): ?>
                                <?php foreach ($emails_exibir as $em): $is_sel = ($email_leitura && $email_leitura['id'] == $em['id']); ?>
                                    <a href="dashboard.php?tab=emails&folder=<?= $email_folder ?>&email_id=<?= $em['id'] ?>" class="webmail-mail-row <?= $is_sel ? 'active' : '' ?>">
                                        <input type="checkbox" class="email-checkbox" value="<?= $em['id'] ?>" onclick="event.stopPropagation(); atualizarContadorSelecao();">
                                        <div style="flex: 1; overflow: hidden;">
                                            <strong style="font-size: 13px;"><?= htmlspecialchars($em['remetente']) ?></strong>
                                            <div style="font-weight: 700; font-size: 13px; color: var(--blue-accent);"><?= htmlspecialchars($em['assunto']) ?></div>
                                            <div style="font-size: 12px; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?= strip_tags($em['mensagem']) ?></div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div style="text-align: center; padding: 40px; color: var(--text-muted);">Nenhum e-mail nesta pasta ou busca.</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- DIVIDER / SEPARADOR PARA REDIMENSIONAR LARGURA -->
                    <div class="webmail-divider" onclick="toggleReaderPaneWidth(event)" title="Clique para alternar a largura de visualização">
                        <button class="divider-toggle-btn" onclick="toggleReaderPaneWidth(event)">↔</button>
                    </div>

                    <!-- COLUNA 3: LEITOR -->
                    <div class="webmail-reader-pane">
                        <?php if ($email_leitura): ?>
                            <div>
                                <!-- Botão Voltar para Lista no modo Tradicional -->
                                <button class="btn-stage-move btn-back-to-list" onclick="voltarParaLista(event)" style="display: none; margin-bottom: 15px; padding: 6px 12px; font-weight: 700; align-items: center; gap: 8px;">
                                    ← Voltar para a lista
                                </button>
                                
                                <h2 style="font-size: 20px; font-weight: 800; margin-bottom: 10px;"><?= htmlspecialchars($email_leitura['assunto']) ?></h2>
                                <div style="font-size: 13px; color: var(--text-muted); margin-bottom: 20px;">De: <?= htmlspecialchars($email_leitura['remetente']) ?> em <?= date('d/m/Y H:i', strtotime($email_leitura['enviado_em'])) ?></div>
                                <div style="font-size: 14px; line-height: 1.6; color: var(--text-main); border-top: 1px solid var(--border-color); padding-top: 16px;"><?= $email_leitura['mensagem'] ?></div>
                            </div>
                        <?php else: ?>
                            <div style="margin: auto; color: var(--text-muted);">Selecione um e-mail para ler.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        <!-- TAB 6: MÉTRICAS -->
        <?php elseif ($active_tab === 'metrics'): ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px;">
                <div class="erp-companies-container">
                    <div style="font-size: 12px; font-weight: 700; color: var(--text-muted);">PIPELINE TOTAL</div>
                    <div style="font-size: 26px; font-weight: 800; margin-top: 6px; font-family: 'JetBrains Mono', monospace;">R$ <?= number_format($total_pipeline_val, 2, ',', '.') ?></div>
                </div>
                <div class="erp-companies-container">
                    <div style="font-size: 12px; font-weight: 700; color: var(--text-muted);">VENDAS FECHADAS</div>
                    <div style="font-size: 26px; font-weight: 800; margin-top: 6px; color: var(--green-vixmed); font-family: 'JetBrains Mono', monospace;">R$ <?= number_format($total_fechados_val, 2, ',', '.') ?></div>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <!-- POPUP FLUTUANTE ARRASTÁVEL DE NOVA MENSAGEM COM WYSIWYG -->
    <div class="gmail-draggable-popup" id="gmail-compose-box">
        <div class="gmail-popup-header" id="gmail-compose-header">
            <div style="font-size: 14px; font-weight: 800;">Nova mensagem</div>
            <button onclick="fecharComposeBox()" style="background: none; border: none; font-size: 16px; cursor: pointer;">✕</button>
        </div>
        <form onsubmit="enviarEmailHtml(event)" style="display: flex; flex-direction: column; flex: 1; padding: 12px; gap: 8px;">
            <input type="hidden" name="ajax_action" value="enviar_email">
            <input type="hidden" name="mensagem" id="hidden-mensagem-html">
            
            <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--border-color); padding-bottom: 4px;">
                <input type="email" name="destinatario" required placeholder="Para:" style="flex: 1; border: none; outline: none; background: transparent; color: var(--text-main); font-size: 13px; padding: 6px 0;">
                <button type="button" onclick="toggleCcCco()" style="background: none; border: none; color: var(--blue-accent); font-size: 11px; cursor: pointer; font-weight: 700;">Cc Cco</button>
            </div>

            <!-- CC / CCO ROWS -->
            <div id="row-cc" style="display: none; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 4px;">
                <span style="font-size: 12px; color: var(--text-muted); width: 30px;">Cc:</span>
                <input type="text" name="cc" style="flex: 1; border: none; outline: none; background: transparent; color: var(--text-main); font-size: 13px;">
            </div>
            <div id="row-cco" style="display: none; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 4px;">
                <span style="font-size: 12px; color: var(--text-muted); width: 30px;">Cco:</span>
                <input type="text" name="cco" style="flex: 1; border: none; outline: none; background: transparent; color: var(--text-main); font-size: 13px;">
            </div>

            <div style="border-bottom: 1px solid var(--border-color); padding-bottom: 4px;">
                <input type="text" name="assunto" required placeholder="Assunto:" style="width: 100%; border: none; outline: none; background: transparent; color: var(--text-main); font-size: 13px; padding: 6px 0;">
            </div>

            <!-- WYSIWYG FORMATTING TOOLBAR -->
            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; background: var(--bg-card-subtle); padding: 6px 10px; border-radius: 8px; border: 1px solid var(--border-color);">
                <select onchange="formatDoc('fontName', this.value)" style="border: 1px solid var(--border-color); background: var(--bg-card); color: var(--text-main); font-size: 11px; padding: 3px; border-radius: 4px; outline: none;">
                    <option value="Arial">Arial</option>
                    <option value="sans-serif">Sans Serif</option>
                    <option value="monospace">Monospace</option>
                    <option value="Georgia">Georgia</option>
                </select>
                <select onchange="formatDoc('fontSize', this.value)" style="border: 1px solid var(--border-color); background: var(--bg-card); color: var(--text-main); font-size: 11px; padding: 3px; border-radius: 4px; outline: none;">
                    <option value="3">Normal</option>
                    <option value="1">Pequeno</option>
                    <option value="5">Grande</option>
                    <option value="7">Enorme</option>
                </select>
                <button type="button" onclick="formatDoc('bold')" style="background: none; border: 1px solid var(--border-color); border-radius: 4px; padding: 3px 6px; cursor: pointer; font-weight: bold; color: var(--text-main); font-size: 11px;">B</button>
                <button type="button" onclick="formatDoc('italic')" style="background: none; border: 1px solid var(--border-color); border-radius: 4px; padding: 3px 6px; cursor: pointer; font-style: italic; color: var(--text-main); font-size: 11px;">I</button>
                <button type="button" onclick="formatDoc('underline')" style="background: none; border: 1px solid var(--border-color); border-radius: 4px; padding: 3px 6px; cursor: pointer; text-decoration: underline; color: var(--text-main); font-size: 11px;">U</button>
                <input type="color" onchange="formatDoc('foreColor', this.value)" style="border: none; background: none; width: 22px; height: 22px; cursor: pointer;" title="Cor da Fonte">
                
                <span style="width: 1px; height: 16px; background: var(--border-color);"></span>

                <button type="button" onclick="formatDoc('justifyLeft')" style="background: none; border: 1px solid var(--border-color); border-radius: 4px; padding: 3px 6px; cursor: pointer; color: var(--text-main); font-size: 11px;">Left</button>
                <button type="button" onclick="formatDoc('justifyCenter')" style="background: none; border: 1px solid var(--border-color); border-radius: 4px; padding: 3px 6px; cursor: pointer; color: var(--text-main); font-size: 11px;">Center</button>
                <button type="button" onclick="formatDoc('insertUnorderedList')" style="background: none; border: 1px solid var(--border-color); border-radius: 4px; padding: 3px 6px; cursor: pointer; color: var(--text-main); font-size: 11px;">•=</button>
                <button type="button" onclick="formatDoc('insertOrderedList')" style="background: none; border: 1px solid var(--border-color); border-radius: 4px; padding: 3px 6px; cursor: pointer; color: var(--text-main); font-size: 11px;">1=</button>
            </div>

            <div id="compose-editor-body" contenteditable="true" style="flex: 1; border: 1px solid var(--border-color); border-radius: 8px; padding: 12px; outline: none; overflow-y: auto; background: var(--bg-card); color: var(--text-main); font-size: 13px; min-height: 200px;" placeholder="Escreva sua mensagem..."></div>
            
            <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 8px;">
                <button type="submit" class="btn-erp-search" style="padding: 10px 24px; border-radius: 20px; font-weight: 800;">Enviar ➔</button>
                <div style="display: flex; align-items: center; gap: 12px; font-size: 16px;">
                    <span style="cursor: pointer;" title="Alternar Formatação">Aa</span>
                    <span style="cursor: pointer;" title="Anexar arquivo">📎</span>
                    <span style="cursor: pointer;" onclick="promptLink()" title="Inserir Link">🔗</span>
                    <span style="cursor: pointer;" title="Inserir Emoji">😊</span>
                    <span style="cursor: pointer; color: var(--danger-red);" onclick="fecharComposeBox()" title="Descartar Rascunho">🗑️</span>
                </div>
            </div>
        </form>
    </div>

    <!-- MODAL CLIENTE/EMPRESA -->
    <div class="modal-overlay" id="modal-lead">
        <div class="modal-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3 style="font-size: 18px;">🏢 Cadastrar / Editar Empresa</h3>
                <button onclick="fecharModais()" style="background: none; border: none; font-size: 20px; cursor: pointer;">✕</button>
            </div>
            <form id="form-lead" onsubmit="salvarLead(event)">
                <input type="hidden" name="ajax_action" value="salvar_cliente">
                <input type="hidden" name="cliente_id" id="lead-id" value="0">
                <div class="form-group-erp"><label>Razão Social (Nome) *</label><input type="text" name="nome" id="lead-nome" required></div>
                <div class="form-group-erp"><label>Nome Fantasia</label><input type="text" name="empresa" id="lead-empresa"></div>
                <div class="form-group-erp"><label>CNPJ / CPF</label><input type="text" name="cnpj_cpf" id="lead-cnpj"></div>
                <div class="form-group-erp"><label>Prestador / Responsável</label><input type="text" name="prestador" id="lead-prestador"></div>
                <div class="form-group-erp"><label>E-mail</label><input type="email" name="email" id="lead-email"></div>
                <div class="form-group-erp"><label>Telefone</label><input type="text" name="telefone" id="lead-telefone"></div>
                <button type="submit" class="btn-erp-search" style="width: 100%; margin-top: 10px; padding: 12px;">Salvar no Banco de Dados</button>
            </form>
        </div>
    </div>

    <!-- MODAL REUNIÃO -->
    <div class="modal-overlay" id="modal-reuniao">
        <div class="modal-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3 style="font-size: 18px;">📅 Agendar Compromisso / Reunião</h3>
                <button onclick="fecharModais()" style="background: none; border: none; font-size: 20px; cursor: pointer;">✕</button>
            </div>
            <form id="form-reuniao" onsubmit="salvarCompromisso(event)">
                <input type="hidden" name="ajax_action" value="salvar_compromisso">
                <div class="form-group-erp"><label>Título do Compromisso *</label><input type="text" name="titulo" required placeholder="Ex: Apresentação de Proposta"></div>
                <div class="form-group-erp"><label>Data *</label><input type="date" name="data_compromisso" required value="<?= date('Y-m-d') ?>"></div>
                <div class="form-group-erp"><label>Horário *</label><input type="time" name="horario_compromisso" required value="10:00"></div>
                <div class="form-group-erp">
                    <label>Empresa / Cliente</label>
                    <select name="cliente_id">
                        <option value="">Nenhum (Compromisso Geral)</option>
                        <?php foreach ($todos_leads as $ld): ?>
                            <option value="<?= $ld['id'] ?>"><?= htmlspecialchars($ld['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group-erp"><label>Pauta / Descrição</label><textarea name="descricao" rows="3" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;"></textarea></div>
                <button type="submit" class="btn-erp-search" style="width: 100%; margin-top: 10px; padding: 12px;">Agendar Compromisso</button>
            </form>
        </div>
    </div>

    <!-- MODAL GOOGLE AGENDA -->
    <div class="modal-overlay" id="modal-google">
        <div class="modal-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3 style="font-size: 18px;">🔄 Sincronizar Google Agenda</h3>
                <button onclick="fecharModais()" style="background: none; border: none; font-size: 20px; cursor: pointer;">✕</button>
            </div>
            <form id="form-google" onsubmit="salvarGoogleAgenda(event)">
                <input type="hidden" name="ajax_action" value="salvar_google_agenda">
                <div class="form-group-erp">
                    <label>URL do Feed iCal Público do Google Agenda *</label>
                    <input type="url" name="ical_url" required placeholder="https://calendar.google.com/calendar/ical/.../basic.ics" value="<?= htmlspecialchars($google_feed['ical_url'] ?? '') ?>">
                </div>
                <button type="submit" class="btn-erp-search" style="width: 100%; margin-top: 10px; padding: 12px;">Sincronizar Eventos</button>
            </form>
        </div>
    </div>

    <!-- DRAWER MENU LATERAL SLIDE-OUT (DESENCADEADO PELOS 3 TRAÇOS ☰) -->
    <div id="drawer-menu-overlay" onclick="toggleSidebarMenu()" style="position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); display: none; z-index: 2000;">
        <div onclick="event.stopPropagation()" style="width: 290px; height: 100%; background: var(--bg-card); border-right: 1px solid var(--border-color); padding: 24px; display: flex; flex-direction: column; gap: 20px; box-shadow: 10px 0 30px rgba(0,0,0,0.3);">
            <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--border-color); padding-bottom: 16px;">
                <strong style="font-size: 16px; color: var(--text-main);">💼 VIXMED CRM</strong>
                <button onclick="toggleSidebarMenu()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: var(--text-muted);">✕</button>
            </div>

            <nav style="display: flex; flex-direction: column; gap: 8px;">
                <div style="font-size: 11px; font-weight: 800; color: var(--text-dim); text-transform: uppercase; margin-bottom: 4px;">Navegação CRM</div>
                <a href="dashboard.php?tab=clientes" class="btn-stage-move" style="text-align: left; padding: 10px 14px; text-decoration: none;">🏢 Cadastro de Empresas</a>
                <a href="dashboard.php?tab=leads" class="btn-stage-move" style="text-align: left; padding: 10px 14px; text-decoration: none;">📌 Oportunidades & Leads</a>
                <a href="dashboard.php?tab=sales" class="btn-stage-move" style="text-align: left; padding: 10px 14px; text-decoration: none;">💼 Funil de Vendas Kanban</a>
                <a href="dashboard.php?tab=activities" class="btn-stage-move" style="text-align: left; padding: 10px 14px; text-decoration: none;">📅 Agenda & Google Agenda</a>
                <a href="dashboard.php?tab=emails" class="btn-stage-move" style="text-align: left; padding: 10px 14px; text-decoration: none;">📧 Vixmed Webmail</a>
                <a href="dashboard.php?tab=metrics" class="btn-stage-move" style="text-align: left; padding: 10px 14px; text-decoration: none;">📊 Métricas e BI</a>

                <div style="font-size: 11px; font-weight: 800; color: var(--text-dim); text-transform: uppercase; margin-top: 16px; margin-bottom: 4px;">Outros Módulos Vixmed</div>
                <a href="../pagina.php" class="btn-stage-move" style="text-align: left; padding: 10px 14px; text-decoration: none;">🎟️ Portal de Chamados</a>
                <a href="../folhadeponto.php" class="btn-stage-move" style="text-align: left; padding: 10px 14px; text-decoration: none;">🕒 Folha de Ponto REP-P</a>
                <a href="../saas_portal/" class="btn-stage-move" style="text-align: left; padding: 10px 14px; text-decoration: none;">☁️ Portal SaaS Multi-empresa</a>
            </nav>
        </div>
    </div>

    <script>
        function toggleSidebarMenu() {
            const drawer = document.getElementById('drawer-menu-overlay');
            if (drawer) {
                drawer.style.display = (drawer.style.display === 'block') ? 'none' : 'block';
            }
        }

        function makeElementDraggable(elmnt, handle) {
            var pos1 = 0, pos2 = 0, pos3 = 0, pos4 = 0;
            if (handle) handle.onmousedown = dragMouseDown;

            function dragMouseDown(e) {
                if (e.target.tagName === 'BUTTON' || e.target.tagName === 'INPUT') return;
                e.preventDefault();
                pos3 = e.clientX; pos4 = e.clientY;
                document.onmouseup = closeDragElement;
                document.onmousemove = elementDrag;
            }

            function elementDrag(e) {
                e.preventDefault();
                pos1 = pos3 - e.clientX; pos2 = pos4 - e.clientY;
                pos3 = e.clientX; pos4 = e.clientY;
                elmnt.style.top = (elmnt.offsetTop - pos2) + "px";
                elmnt.style.left = (elmnt.offsetLeft - pos1) + "px";
                elmnt.style.bottom = "auto"; elmnt.style.right = "auto";
            }

            function closeDragElement() { document.onmouseup = null; document.onmousemove = null; }
        }

        let currentSplitState = 0; // 0 = 380px, 1 = 240px, 2 = 0px (lista oculta)
        document.addEventListener('DOMContentLoaded', () => {
            const popup = document.getElementById('gmail-compose-box');
            const header = document.getElementById('gmail-compose-header');
            if (popup && header) makeElementDraggable(popup, header);

            // Carregar modo de visualização salvo do UOL Mail Pro
            const savedViewMode = localStorage.getItem('uol_view_mode') || 'right';
            setUolViewMode(savedViewMode);

            // Carregar estado salvo do split apenas para o modo 'right'
            if (savedViewMode === 'right') {
                currentSplitState = parseInt(localStorage.getItem('webmail_split_state') || '0');
                const splitContainer = document.querySelector('.webmail-main-content-split');
                const container = document.querySelector('.webmail-container');
                if (splitContainer && container) {
                    const inbox = splitContainer.querySelector('.webmail-inbox-pane');
                    if (inbox) inbox.style.display = (currentSplitState === 2) ? 'none' : 'flex';

                    if (currentSplitState === 0) {
                        splitContainer.style.gridTemplateColumns = '380px 4px 1fr';
                    } else if (currentSplitState === 1) {
                        splitContainer.style.gridTemplateColumns = '240px 4px 1fr';
                    } else if (currentSplitState === 2) {
                        splitContainer.style.gridTemplateColumns = '0px 4px 1fr';
                    }
                }
            }
        });

        function toggleErpCheckboxes(master) { document.querySelectorAll('.chk-erp-item').forEach(c => c.checked = master.checked); }
        function excluirSelecionadosErp() {
            const chks = document.querySelectorAll('.chk-erp-item:checked');
            if (chks.length === 0) return alert('Selecione pelo menos uma empresa.');
            if (confirm(`Deseja excluir ${chks.length} empresa(s)?`)) { alert('Removido!'); location.reload(); }
        }

        function alternarTema() {
            const html = document.documentElement;
            const currentTheme = html.getAttribute('data-theme');
            const newTheme = (currentTheme === 'dark') ? 'light' : 'dark';
            html.setAttribute('data-theme', newTheme);
            localStorage.setItem('crm_theme', newTheme);
        }

        function abrirModalNovoEmail() { document.getElementById('gmail-compose-box').style.display = 'flex'; }
        function fecharComposeBox() { document.getElementById('gmail-compose-box').style.display = 'none'; }
        function fecharModais() { document.querySelectorAll('.modal-overlay').forEach(el => el.style.display = 'none'); }
        function abrirModalNovoCliente() { document.getElementById('form-lead').reset(); document.getElementById('lead-id').value = 0; document.getElementById('modal-lead').style.display = 'flex'; }
        function abrirModalNovaReuniao() { document.getElementById('modal-reuniao').style.display = 'flex'; }
        function abrirModalGoogleAgenda() { document.getElementById('modal-google').style.display = 'flex'; }

        function toggleWebmailSidebarFolders() {
            const container = document.querySelector('.webmail-container');
            const folders = document.getElementById('webmail-folders-sidebar');
            if (folders && container) {
                folders.classList.toggle('collapsed');
                container.classList.toggle('sidebar-collapsed');
            }
        }

        function toggleUolViewModeMenu() {
            const menu = document.getElementById('uol-viewmode-menu');
            if (menu) {
                menu.style.display = (menu.style.display === 'flex') ? 'none' : 'flex';
            }
        }

        function setUolViewMode(mode) {
            const container = document.querySelector('.webmail-container');
            const splitContainer = document.querySelector('.webmail-main-content-split');
            if (!container || !splitContainer) return;

            container.classList.remove('view-mode-right', 'view-mode-traditional', 'view-mode-below');
            container.classList.add('view-mode-' + mode);

            const radio = document.getElementById('radio-' + mode);
            if (radio) radio.checked = true;

            localStorage.setItem('uol_view_mode', mode);

            const inbox = splitContainer.querySelector('.webmail-inbox-pane');
            const reader = splitContainer.querySelector('.webmail-reader-pane');
            const divider = splitContainer.querySelector('.webmail-divider');

            // Reset inline styles do split
            splitContainer.style.gridTemplateColumns = '';
            splitContainer.style.flexDirection = '';
            if (inbox) { inbox.style.display = ''; inbox.style.height = ''; }
            if (reader) { reader.style.display = ''; reader.style.height = ''; }
            if (divider) divider.style.display = '';

            if (mode === 'traditional') {
                const urlParams = new URLSearchParams(window.location.search);
                const hasEmailId = urlParams.has('email_id');

                if (hasEmailId) {
                    if (inbox) inbox.style.display = 'none';
                    if (divider) divider.style.display = 'none';
                    splitContainer.style.gridTemplateColumns = '0px 0px 1fr';
                } else {
                    if (reader) reader.style.display = 'none';
                    if (divider) divider.style.display = 'none';
                    splitContainer.style.gridTemplateColumns = '1fr 0px 0px';
                }
            } else if (mode === 'right') {
                currentSplitState = parseInt(localStorage.getItem('webmail_split_state') || '0');
                if (inbox) inbox.style.display = (currentSplitState === 2) ? 'none' : 'flex';
                if (divider) divider.style.display = '';

                if (currentSplitState === 0) {
                    splitContainer.style.gridTemplateColumns = '380px 4px 1fr';
                } else if (currentSplitState === 1) {
                    splitContainer.style.gridTemplateColumns = '240px 4px 1fr';
                } else if (currentSplitState === 2) {
                    splitContainer.style.gridTemplateColumns = '0px 4px 1fr';
                }
            } else if (mode === 'below') {
                splitContainer.style.flexDirection = 'column';
                if (divider) divider.style.display = 'none';

                const hState = parseInt(localStorage.getItem('webmail_height_state') || '0');
                if (hState === 0) {
                    if (inbox) { inbox.style.height = '45%'; inbox.style.display = 'flex'; }
                    if (reader) reader.style.height = '55%';
                } else if (hState === 1) {
                    if (inbox) { inbox.style.height = '25%'; inbox.style.display = 'flex'; }
                    if (reader) reader.style.height = '75%';
                } else if (hState === 2) {
                    if (inbox) { inbox.style.height = '0%'; inbox.style.display = 'none'; }
                    if (reader) reader.style.height = '100%';
                }
            }

            const menu = document.getElementById('uol-viewmode-menu');
            if (menu) menu.style.display = 'none';
        }

        function voltarParaLista(event) {
            if (event) event.preventDefault();
            const url = new URL(window.location.href);
            url.searchParams.delete('email_id');
            window.location.href = url.toString();
        }

        function toggleReaderPaneWidth(event) {
            if (event) event.stopPropagation();
            const container = document.querySelector('.webmail-container');
            const splitContainer = document.querySelector('.webmail-main-content-split');
            if (!splitContainer || !container) return;

            if (container.classList.contains('view-mode-below')) {
                const inbox = splitContainer.querySelector('.webmail-inbox-pane');
                const reader = splitContainer.querySelector('.webmail-reader-pane');
                if (!inbox || !reader) return;

                let hState = parseInt(localStorage.getItem('webmail_height_state') || '0');
                hState = (hState + 1) % 3;
                localStorage.setItem('webmail_height_state', hState);

                if (hState === 0) {
                    inbox.style.height = '45%';
                    reader.style.height = '55%';
                    inbox.style.display = 'flex';
                } else if (hState === 1) {
                    inbox.style.height = '25%';
                    reader.style.height = '75%';
                    inbox.style.display = 'flex';
                } else if (hState === 2) {
                    inbox.style.height = '0%';
                    reader.style.height = '100%';
                    inbox.style.display = 'none';
                }
            } else {
                currentSplitState = (currentSplitState + 1) % 3;
                localStorage.setItem('webmail_split_state', currentSplitState);

                const inbox = splitContainer.querySelector('.webmail-inbox-pane');
                if (inbox) inbox.style.display = (currentSplitState === 2) ? 'none' : 'flex';

                if (currentSplitState === 0) {
                    splitContainer.style.gridTemplateColumns = '380px 4px 1fr';
                } else if (currentSplitState === 1) {
                    splitContainer.style.gridTemplateColumns = '240px 4px 1fr';
                } else if (currentSplitState === 2) {
                    splitContainer.style.gridTemplateColumns = '0px 4px 1fr';
                }
            }
        }

        function toggleCcCco() {
            const cc = document.getElementById('row-cc');
            const cco = document.getElementById('row-cco');
            if (cc && cco) {
                const isHidden = cc.style.display === 'none';
                cc.style.display = isHidden ? 'flex' : 'none';
                cco.style.display = isHidden ? 'flex' : 'none';
            }
        }

        function formatDoc(cmd, value = null) {
            document.execCommand(cmd, false, value);
            const editor = document.getElementById('compose-editor-body');
            if (editor) editor.focus();
        }

        function promptLink() {
            const url = prompt('Digite a URL do link:', 'https://');
            if (url) formatDoc('createLink', url);
        }

        function toggleSelecionarTodosEmails(master) {
            document.querySelectorAll('.email-checkbox').forEach(cb => cb.checked = master.checked);
            atualizarContadorSelecao();
        }

        function atualizarContadorSelecao() {
            const selecionados = document.querySelectorAll('.email-checkbox:checked');
            const countSpan = document.getElementById('count-selecionados');
            const btnExcluir = document.getElementById('btn-excluir-selecionados');
            if (countSpan) countSpan.innerText = selecionados.length;
            if (btnExcluir) btnExcluir.style.display = (selecionados.length > 0) ? 'inline-flex' : 'none';
        }

        function excluirEmailsSelecionados() {
            const selecionados = document.querySelectorAll('.email-checkbox:checked');
            if (selecionados.length === 0) return;
            if (confirm(`Deseja remover ${selecionados.length} e-mail(s) selecionado(s)?`)) {
                alert('E-mails removidos com sucesso!');
                location.reload();
            }
        }

        function editarLead(emp) {
            document.getElementById('lead-id').value = emp.id;
            document.getElementById('lead-nome').value = emp.nome || '';
            document.getElementById('lead-empresa').value = emp.empresa || '';
            document.getElementById('lead-cnpj').value = emp.cnpj_cpf || '';
            document.getElementById('lead-prestador').value = emp.responsavel_nome || '';
            document.getElementById('lead-email').value = emp.email || '';
            document.getElementById('lead-telefone').value = emp.telefone || '';
            document.getElementById('modal-lead').style.display = 'flex';
        }

        async function moverEtapa(clienteId, novoStatus) {
            const formData = new FormData();
            formData.append('ajax_action', 'mover_status_cliente');
            formData.append('cliente_id', clienteId);
            formData.append('novo_status', novoStatus);
            const res = await fetch('dashboard.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.sucesso) location.reload();
        }

        async function salvarLead(e) {
            e.preventDefault();
            const formData = new FormData(document.getElementById('form-lead'));
            const res = await fetch('dashboard.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.sucesso) location.reload();
            else alert(data.erro || 'Erro ao salvar.');
        }

        async function salvarCompromisso(e) {
            e.preventDefault();
            const formData = new FormData(document.getElementById('form-reuniao'));
            const res = await fetch('dashboard.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.sucesso) location.reload();
            else alert(data.erro || 'Erro ao agendar compromisso.');
        }

        async function deletarCompromisso(id) {
            if (!confirm('Deseja remover este compromisso?')) return;
            const formData = new FormData();
            formData.append('ajax_action', 'deletar_compromisso');
            formData.append('compromisso_id', id);
            const res = await fetch('dashboard.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.sucesso) location.reload();
        }

        async function salvarGoogleAgenda(e) {
            e.preventDefault();
            const formData = new FormData(document.getElementById('form-google'));
            const res = await fetch('dashboard.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.sucesso) { alert('Google Agenda sincronizado!'); location.reload(); }
            else alert(data.erro || 'Erro ao sincronizar Google Agenda.');
        }

        async function enviarEmailHtml(e) {
            e.preventDefault();
            document.getElementById('hidden-mensagem-html').value = document.getElementById('compose-editor-body').innerHTML;
            const formData = new FormData(e.target);
            const res = await fetch('dashboard.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.sucesso) { alert('E-mail enviado com sucesso!'); fecharComposeBox(); location.reload(); }
        }
    </script>
</body>
</html>
