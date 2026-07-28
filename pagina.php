<?php
session_start();
if (!isset($_SESSION['logado']) || $_SESSION['logado'] !== true) {
    header("Location: index.php");
    exit();
}
require_once "conexao.php";

$tipo = $_SESSION['usuario_tipo'] ?? 'funcionario';
$uid = $_SESSION['usuario_id'];

// Buscar chamados
if ($tipo === 'master') {
    $sql = "SELECT c.*, u.nome as criador_nome FROM chamados c JOIN usuarios u ON c.usuario_id = u.id ORDER BY c.criado_em DESC";
    $stmt = $pdo->prepare($sql);
} else {
    $sql = "SELECT c.*, u.nome as criador_nome FROM chamados c JOIN usuarios u ON c.usuario_id = u.id WHERE c.usuario_id = :uid ORDER BY c.criado_em DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':uid', $uid);
}
$stmt->execute();
$chamados = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas
$total = count($chamados);
$abertos = count(array_filter($chamados, fn($c) => $c['status'] === 'aberto'));
$andamento = count(array_filter($chamados, fn($c) => $c['status'] === 'em_andamento'));
$resolvidos = count(array_filter($chamados, fn($c) => in_array($c['status'], ['resolvido','fechado'])));

// SLA (Tempo Médio de Resolução)
$sql_sla = "SELECT criado_em, atualizado_em FROM chamados WHERE status IN ('resolvido', 'fechado')";
if ($tipo !== 'master') {
    $sql_sla .= " AND usuario_id = :uid";
}
$stmt_sla = $pdo->prepare($sql_sla);
if ($tipo !== 'master') {
    $stmt_sla->bindParam(':uid', $uid);
}
$stmt_sla->execute();
$resolved_tickets = $stmt_sla->fetchAll(PDO::FETCH_ASSOC);

$sla_texto = "N/A";
if (count($resolved_tickets) > 0) {
    $total_seconds = 0;
    foreach ($resolved_tickets as $rt) {
        $criado = strtotime($rt['criado_em']);
        $atualizado = strtotime($rt['atualizado_em']);
        $total_seconds += max(0, $atualizado - $criado);
    }
    $avg_seconds = $total_seconds / count($resolved_tickets);
    if ($avg_seconds < 3600) {
        $sla_texto = round($avg_seconds / 60) . " min";
    } elseif ($avg_seconds < 86400) {
        $sla_texto = round($avg_seconds / 3600, 1) . " hrs";
    } else {
        $sla_texto = round($avg_seconds / 86400, 1) . " dias";
    }
}

// Distribuição de Prioridades
$prioridades = ['alta' => 0, 'media' => 0, 'baixa' => 0];
foreach ($chamados as $c) {
    if (isset($prioridades[$c['prioridade']])) {
        $prioridades[$c['prioridade']]++;
    }
}

// Chamados por Setor
$setores_stats = [];
foreach ($chamados as $c) {
    $set = $c['setor'] ?: 'Não Informado';
    if (!isset($setores_stats[$set])) {
        $setores_stats[$set] = 0;
    }
    $setores_stats[$set]++;
}
arsort($setores_stats);

// Atividade Recente (últimas mensagens nos chamados)
if ($tipo === 'master') {
    $sql_feed = "SELECT m.*, u.nome as usuario_nome, c.assunto as chamado_assunto 
                 FROM mensagens m 
                 JOIN usuarios u ON m.usuario_id = u.id 
                 JOIN chamados c ON m.chamado_id = c.id 
                 ORDER BY m.criado_em DESC LIMIT 5";
    $stmt_feed = $pdo->prepare($sql_feed);
} else {
    $sql_feed = "SELECT m.*, u.nome as usuario_nome, c.assunto as chamado_assunto 
                 FROM mensagens m 
                 JOIN usuarios u ON m.usuario_id = u.id 
                 JOIN chamados c ON m.chamado_id = c.id 
                 WHERE c.usuario_id = :uid 
                 ORDER BY m.criado_em DESC LIMIT 5";
    $stmt_feed = $pdo->prepare($sql_feed);
    $stmt_feed->bindParam(':uid', $uid);
}
$stmt_feed->execute();
$atividades = $stmt_feed->fetchAll(PDO::FETCH_ASSOC);

function tempo_relativo($data) {
    $diferenca = time() - strtotime($data);
    if ($diferenca < 60) return "agora";
    if ($diferenca < 3600) return round($diferenca / 60) . " min atrás";
    if ($diferenca < 86400) return round($diferenca / 3600) . "h atrás";
    return date('d/m H:i', strtotime($data));
}

$pagina_atual = 'dashboard';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Chamados Vixmed</title>
    <link rel="stylesheet" href="css/style.css?v=<?= time() ?>">
</head>
<body>
<div class="app-container">
    <?php include 'sidebar.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <h2>📊 Dashboard</h2>
            <a href="novo_chamado.php" class="btn btn-primary">＋ Novo Chamado</a>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon total">📋</div>
                <div class="stat-info"><h3><?= $total ?></h3><p>Total de Chamados</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon aberto">📂</div>
                <div class="stat-info"><h3><?= $abertos ?></h3><p>Em Aberto</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon andamento">⏳</div>
                <div class="stat-info"><h3><?= $andamento ?></h3><p>Em Andamento</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon fechado">✅</div>
                <div class="stat-info"><h3><?= $resolvidos ?></h3><p>Resolvidos</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon total" style="background: rgba(59, 130, 246, 0.1); color: #3b82f6;" title="Tempo médio calculado entre a data de abertura e a data de resolução dos chamados finalizados.">⏱️</div>
                <div class="stat-info">
                    <h3><?= $sla_texto ?></h3>
                    <p title="Média de tempo até a solução">Tempo de Resolução</p>
                </div>
            </div>
        </div>

        <div class="dashboard-layout">
            <div class="dashboard-main">
                <?php if (count($chamados) > 0): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Assunto</th>
                                <th>Setor</th>
                                <th>Prioridade</th>
                                <th>Status</th>
                                <th>Criado por</th>
                                <th>Data</th>
                                <th>Ação</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($chamados as $c): ?>
                            <tr>
                                <td><strong>#<?= $c['id'] ?></strong></td>
                                <td><?= htmlspecialchars($c['assunto']) ?></td>
                                <td><?= htmlspecialchars($c['setor']) ?></td>
                                <td><span class="badge badge-<?= $c['prioridade'] ?>"><?= ucfirst($c['prioridade']) ?></span></td>
                                <td><span class="badge badge-<?= $c['status'] ?>"><?= str_replace('_', ' ', ucfirst($c['status'])) ?></span></td>
                                <td><?= htmlspecialchars($c['criador_nome']) ?></td>
                                <td><?= date('d/m/Y H:i', strtotime($c['criado_em'])) ?></td>
                                <td><a href="ver_chamado.php?id=<?= $c['id'] ?>">💬 Abrir</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="card">
                    <div class="empty-state">
                        <div class="empty-icon">📭</div>
                        <h3>Nenhum chamado encontrado</h3>
                        <p>Clique em "Novo Chamado" para criar o primeiro!</p>
                        <a href="novo_chamado.php" class="btn btn-primary">＋ Criar Chamado</a>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="dashboard-sidebar">
                <!-- Painel de Prioridades -->
                <div class="bi-card">
                    <h4>🔥 Urgência Acumulada</h4>
                    <p class="bi-explanation">Distribuição dos chamados por nível de prioridade. Ajuda a identificar o volume de tarefas críticas (Alta) que necessitam de atenção imediata.</p>
                    
                    <?php 
                    $total_pri = array_sum($prioridades);
                    foreach (['alta', 'media', 'baixa'] as $prio): 
                        $count = $prioridades[$prio];
                        $pct = $total_pri > 0 ? round(($count / $total_pri) * 100) : 0;
                    ?>
                        <div class="bi-metric-row">
                            <div class="bi-metric-info">
                                <span class="bi-metric-label"><?= $prio === 'media' ? 'Média' : ucfirst($prio) ?></span>
                                <span class="bi-metric-val"><?= $count ?> (<?= $pct ?>%)</span>
                            </div>
                            <div class="bi-progress-bar">
                                <div class="bi-progress-fill <?= $prio ?>" style="width: <?= $pct ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Painel de Setores -->
                <div class="bi-card">
                    <h4>🏢 Demandas por Setor (Top 5)</h4>
                    <p class="bi-explanation">Indica quais departamentos estão gerando mais solicitações. Ideal para identificar onde ocorrem os gargalos operacionais na empresa.</p>
                    
                    <?php if (count($setores_stats) > 0): 
                        $total_set = array_sum($setores_stats);
                        $limit = 0;
                        foreach ($setores_stats as $setor => $count): 
                            if ($limit++ >= 5) break; 
                            $pct = $total_set > 0 ? round(($count / $total_set) * 100) : 0;
                    ?>
                        <div class="bi-metric-row">
                            <div class="bi-metric-info">
                                <span class="bi-metric-label"><?= htmlspecialchars($setor) ?></span>
                                <span class="bi-metric-val"><?= $count ?> (<?= $pct ?>%)</span>
                            </div>
                            <div class="bi-progress-bar">
                                <div class="bi-progress-fill default" style="width: <?= $pct ?>%"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="font-size: 13px; color: var(--cinza-texto);">Sem dados de setores registrados.</p>
                    <?php endif; ?>
                </div>

                <!-- Painel de Atividades Recentes -->
                <div class="bi-card">
                    <h4>💬 Últimas Conversas</h4>
                    <p class="bi-explanation">Acompanhe as interações recentes nos chamados para se manter atualizado sem precisar abrir chamado por chamado.</p>
                    
                    <div class="activity-feed">
                        <?php if (count($atividades) > 0): ?>
                            <?php foreach ($atividades as $a): 
                                $iniciais_autor = mb_strtoupper(mb_substr($a['usuario_nome'], 0, 2));
                            ?>
                                <div class="activity-item">
                                    <div class="activity-avatar"><?= $iniciais_autor ?></div>
                                    <div class="activity-content">
                                        <div class="activity-user"><?= htmlspecialchars($a['usuario_nome']) ?></div>
                                        <div class="activity-msg" title="<?= htmlspecialchars($a['mensagem']) ?>"><?= htmlspecialchars($a['mensagem']) ?></div>
                                        <div class="activity-meta">
                                            <span><?= tempo_relativo($a['criado_em']) ?></span>
                                            <a href="ver_chamado.php?id=<?= $a['chamado_id'] ?>" class="activity-ticket-link">#<?= $a['chamado_id'] ?> (<?= htmlspecialchars(mb_substr($a['chamado_assunto'], 0, 12)) ?>...)</a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p style="font-size: 13px; color: var(--cinza-texto);">Nenhuma mensagem enviada recentemente.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>