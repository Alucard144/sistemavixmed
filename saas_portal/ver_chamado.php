<?php
session_start();
if (!isset($_SESSION['logado'])) { header("Location: index.php"); exit(); }
require_once "conexao.php";
require_once "config_saas.php";

$tipo = $_SESSION['usuario_tipo'] ?? 'funcionario';
$uid = $_SESSION['usuario_id'];
$chamado_id = intval($_GET['id'] ?? 0);

if ($chamado_id <= 0) { header("Location: pagina.php"); exit(); }

// Buscar chamado
$sql = "SELECT c.*, u.nome as criador_nome FROM saas_chamados c JOIN saas_usuarios u ON c.usuario_id = u.id WHERE c.id = :id";
$stmt = $pdo->prepare($sql);
$stmt->execute([':id' => $chamado_id]);
$chamado = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$chamado) { header("Location: pagina.php"); exit(); }

// Funcionário só vê o próprio chamado
if ($tipo !== 'master' && $chamado['usuario_id'] != $uid) {
    header("Location: pagina.php"); exit();
}

// Master pode alterar status via POST
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['novo_status']) && $tipo === 'master') {
    $novo_status = $_POST['novo_status'];
    $validos = ['aberto','em_andamento','resolvido','fechado'];
    if (in_array($novo_status, $validos)) {
        $stmt = $pdo->prepare("UPDATE saas_chamados SET status = :s WHERE id = :id");
        $stmt->execute([':s' => $novo_status, ':id' => $chamado_id]);
        $chamado['status'] = $novo_status;
    }
}

$status_labels = [
    'aberto' => 'Aberto',
    'em_andamento' => 'Em Andamento',
    'resolvido' => 'Resolvido',
    'fechado' => 'Fechado'
];

$pagina_atual = 'ver_chamado';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chamado #<?= $chamado_id ?> - Vixmed</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="app-container">
    <?php include 'sidebar.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <h2>💬 Chamado #<?= $chamado_id ?></h2>
            <a href="pagina.php" class="btn btn-secondary">← Voltar</a>
        </div>

        <!-- Info do chamado -->
        <div class="card" style="margin-bottom: 20px;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
                <div style="flex:1;">
                    <h3 style="font-size:20px; margin-bottom:8px;"><?= htmlspecialchars($chamado['assunto']) ?></h3>
                    <p style="color:var(--cinza-texto); font-size:14px; margin-bottom:12px;"><?= htmlspecialchars($chamado['descricao']) ?></p>
                    <?php if (!empty($chamado['imagem'])): ?>
                        <div style="margin-bottom:12px;">
                            <a href="<?= htmlspecialchars($chamado['imagem']) ?>" target="_blank">
                                <img src="<?= htmlspecialchars($chamado['imagem']) ?>" style="max-width:300px; max-height:200px; border-radius:8px; border:1px solid var(--cinza-borda); cursor:pointer;" alt="Anexo do chamado">
                            </a>
                        </div>
                    <?php endif; ?>
                    <div style="display:flex; gap:12px; flex-wrap:wrap; font-size:13px; color:var(--cinza-texto);">
                        <span>👤 <?= htmlspecialchars($chamado['criador_nome']) ?></span>
                        <span>🏢 <?= htmlspecialchars($chamado['setor']) ?></span>
                        <span>📅 <?= date('d/m/Y H:i', strtotime($chamado['criado_em'])) ?></span>
                        <span class="badge badge-<?= $chamado['prioridade'] ?>"><?= ucfirst($chamado['prioridade']) ?></span>
                        <span class="badge badge-<?= $chamado['status'] ?>" id="statusBadge"><?= $status_labels[$chamado['status']] ?></span>
                    </div>
                </div>
                <?php if ($tipo === 'master'): ?>
                <form method="POST" style="display:flex; gap:8px; align-items:center;">
                    <select name="novo_status" style="padding:8px 12px; border:2px solid var(--cinza-borda); border-radius:8px; font-family:Inter,sans-serif; font-size:13px;">
                        <?php foreach ($status_labels as $k => $v): ?>
                            <option value="<?= $k ?>" <?= $chamado['status'] === $k ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-primary btn-sm">Alterar</button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($tipo === 'master' && !in_array($chamado['status'], ['resolvido', 'fechado'])): ?>
            <!-- Buscar produtos ativos no estoque -->
            <?php 
                $prods_disponiveis = $pdo->query("SELECT * FROM saas_produtos WHERE quantidade > 0 ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
            ?>
            <div class="card" style="margin-bottom: 20px; border-left: 5px solid var(--verde);">
                <h3 style="font-size:16px; margin-bottom:12px; display:flex; align-items:center; gap:8px;">📦 Vincular Equipamento do Estoque</h3>
                <form id="formDispensar" onsubmit="dispensarProduto(event)" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
                    <div style="flex:2; min-width:200px;">
                        <label style="display:block; font-size:12px; font-weight:600; margin-bottom:6px; color:var(--texto-escuro);">Produto</label>
                        <select id="dispensar_produto_id" required style="padding:10px; border:2px solid var(--cinza-borda); border-radius:8px; width:100%; font-family:Inter,sans-serif; font-size:13px; background: var(--branco);">
                            <option value="">Selecione um produto com estoque...</option>
                            <?php foreach ($prods_disponiveis as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nome']) ?> (Disponível: <?= $p['quantidade'] ?> | Cód: <?= $p['codigo'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="width:100px;">
                        <label style="display:block; font-size:12px; font-weight:600; margin-bottom:6px; color:var(--texto-escuro);">Quantidade</label>
                        <input type="number" id="dispensar_quantidade" value="1" min="1" required style="padding:10px; border:2px solid var(--cinza-borda); border-radius:8px; width:100%; font-family:Inter,sans-serif; font-size:13px;">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm" style="height:41px; padding:0 20px;">Entregar Equipamento</button>
                </form>
            </div>
        <?php endif; ?>

        <!-- Chat -->
        <div class="chat-container">
            <div class="chat-header">
                <h3>Conversa</h3>
                <span style="font-size:12px; color:var(--cinza-texto);" id="chatStatus">🟢 Tempo real ativo</span>
            </div>
            <div class="chat-messages" id="chatMessages">
                <div class="empty-state" id="chatEmpty">
                    <div class="empty-icon">💬</div>
                    <h3>Sem mensagens ainda</h3>
                    <p>Envie a primeira mensagem!</p>
                </div>
            </div>

            <!-- Preview da imagem selecionada -->
            <div id="imgPreviewBar" style="display:none; padding:8px 20px; border-top:1px solid var(--cinza-borda); background:#f8f9fa;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <img id="chatImgPreview" style="height:50px; border-radius:6px; border:1px solid var(--cinza-borda);">
                    <span style="font-size:13px; color:var(--cinza-texto);" id="imgFileName"></span>
                    <button onclick="cancelarImagem()" style="margin-left:auto; background:none; border:none; cursor:pointer; font-size:18px; color:var(--vermelho);">✕</button>
                </div>
            </div>

            <div class="chat-input">
                <input type="file" id="chatFileInput" accept="image/*" style="display:none" onchange="previewChatImg(this)">
                <button onclick="document.getElementById('chatFileInput').click()" style="width:46px; height:46px; border-radius:50%; background:var(--cinza-fundo); color:var(--cinza-texto); border:2px solid var(--cinza-borda); font-size:18px; cursor:pointer; display:flex; align-items:center; justify-content:center;" title="Anexar imagem">📎</button>
                <input type="text" id="msgInput" placeholder="Digite sua mensagem..." autocomplete="off">
                <button id="btnEnviar" onclick="enviarMensagem()">➤</button>
            </div>
        </div>
    </div>
</div>

<script>
const chamadoId = <?= $chamado_id ?>;
const usuarioId = <?= $uid ?>;
const usuarioTipo = '<?= $tipo ?>';
let ultimaMsgId = 0;

// Buscar mensagens
function buscarMensagens() {
    fetch('api/buscar_mensagens.php?chamado_id=' + chamadoId + '&ultima_id=' + ultimaMsgId)
        .then(r => r.json())
        .then(data => {
            if (data.length > 0) {
                document.getElementById('chatEmpty').style.display = 'none';
                const container = document.getElementById('chatMessages');
                data.forEach(msg => {
                    const isMe = parseInt(msg.usuario_id) === usuarioId;
                    const div = document.createElement('div');
                    div.className = 'msg ' + (isMe ? 'msg-master' : 'msg-cliente');
                    
                    let conteudo = '';
                    if (msg.imagem) {
                        conteudo += `<a href="${msg.imagem}" target="_blank"><img src="${msg.imagem}" style="max-width:250px; max-height:180px; border-radius:8px; margin-bottom:6px; display:block;"></a>`;
                    }
                    if (msg.mensagem) {
                        conteudo += msg.mensagem;
                    }
                    
                    div.innerHTML = `
                        <div class="msg-sender">${msg.nome}</div>
                        ${conteudo}
                        <div class="msg-time">${msg.hora}</div>
                    `;
                    container.appendChild(div);
                    ultimaMsgId = msg.id;
                });
                container.scrollTop = container.scrollHeight;
            }
        })
        .catch(() => {
            document.getElementById('chatStatus').textContent = '🔴 Erro de conexão';
        });
}

// Preview imagem no chat
function previewChatImg(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('chatImgPreview').src = e.target.result;
            document.getElementById('imgFileName').textContent = input.files[0].name;
            document.getElementById('imgPreviewBar').style.display = 'block';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function cancelarImagem() {
    document.getElementById('chatFileInput').value = '';
    document.getElementById('imgPreviewBar').style.display = 'none';
}

// Enviar mensagem (com ou sem imagem)
function enviarMensagem() {
    const input = document.getElementById('msgInput');
    const fileInput = document.getElementById('chatFileInput');
    const texto = input.value.trim();
    const temImagem = fileInput.files && fileInput.files.length > 0;

    if (!texto && !temImagem) return;

    const formData = new FormData();
    formData.append('chamado_id', chamadoId);
    formData.append('mensagem', texto);
    if (temImagem) {
        formData.append('imagem', fileInput.files[0]);
    }

    input.value = '';
    cancelarImagem();

    fetch('api/enviar_mensagem.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data && data.erro) {
            alert("Aviso ao enviar: " + data.erro);
        } else {
            buscarMensagens();
        }
    })
    .catch(() => {
        alert("Erro de conexão ao enviar arquivo.");
    });
}

// Enter para enviar
document.getElementById('msgInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') enviarMensagem();
});

// Dispensar produto do estoque vinculado ao chamado
function dispensarProduto(e) {
    e.preventDefault();
    const prodId = document.getElementById('dispensar_produto_id').value;
    const qtd = document.getElementById('dispensar_quantidade').value;
    
    if (!prodId || qtd <= 0) return;
    
    if (!confirm('Deseja realmente retirar este equipamento do estoque e vinculá-lo a este chamado?')) return;
    
    fetch('api/dispensar_produto.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            chamado_id: chamadoId,
            produto_id: prodId,
            quantidade: qtd
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.erro) {
            alert(data.erro);
        } else {
            alert("Equipamento vinculado e retirado do estoque com sucesso!");
            window.location.reload();
        }
    })
    .catch(() => {
        alert("Erro de conexão ao dispensar produto.");
    });
}

// Buscar a cada 3 segundos (tempo real)
buscarMensagens();
setInterval(buscarMensagens, 3000);
</script>
</body>
</html>
