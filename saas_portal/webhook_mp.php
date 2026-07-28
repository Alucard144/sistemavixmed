<?php
// webhook_mp.php - Recebimento de notificações automáticas do Mercado Pago
require_once "conexao.php";

header('Content-Type: application/json');

// Recebe o corpo da notificação POST
$json = file_get_contents('php://input');
$dados = json_decode($json, true);

if (!$dados) {
    http_response_code(400);
    echo json_encode(['erro' => 'Requisição inválida ou vazia']);
    exit();
}

// Logs para fins de auditoria no servidor (opcional)
// file_put_contents('mp_logs.txt', date('Y-m-d H:i:s') . " - " . $json . "\n", FILE_APPEND);

// Formatos comuns de assinatura (Preapproval) do Mercado Pago:
// 1. type = 'subscription_authorized' (quando a assinatura é criada/autorizada)
// 2. action = 'payment.created' (quando uma fatura mensal é paga com sucesso)
$preapproval_id = null;

if (isset($dados['type']) && $dados['type'] === 'subscription_authorized') {
    $preapproval_id = $dados['data']['id'] ?? null;
} elseif (isset($dados['action']) && $dados['action'] === 'payment.created') {
    // O ID da assinatura pré-aprovada pode vir aninhado nos detalhes do pagamento
    $preapproval_id = $dados['data']['preapproval_id'] ?? null;
}

// Fallback de desenvolvimento (permite simular facilmente via curl enviando 'preapproval_id')
if (!$preapproval_id && isset($dados['preapproval_id'])) {
    $preapproval_id = $dados['preapproval_id'];
}

if ($preapproval_id) {
    try {
        $pdo->beginTransaction();

        // 1. Buscar a empresa vinculada a esse ID de assinatura
        $stmt = $pdo->prepare("SELECT id, nome_fantasia, cobranca_automatica FROM saas_empresas WHERE mp_preapproval_id = :id FOR UPDATE");
        $stmt->execute([':id' => $preapproval_id]);
        $empresa = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$empresa) {
            throw new Exception("Assinatura '$preapproval_id' não está vinculada a nenhuma empresa cadastrada.");
        }

        // 2. Se a cobrança automática estiver ativada, estende por mais 31 dias
        if ($empresa['cobranca_automatica'] == 1) {
            $nova_data = date('Y-m-d', strtotime('+31 days'));
            
            $up = $pdo->prepare("UPDATE saas_empresas 
                                 SET status_assinatura = 'ativo', data_expiracao = :data 
                                 WHERE id = :id");
            $up->execute([
                ':data' => $nova_data,
                ':id' => $empresa['id']
            ]);

            $pdo->commit();
            echo json_encode([
                'ok' => true,
                'mensagem' => "Assinatura da empresa '{$empresa['nome_fantasia']}' renovada com sucesso até {$nova_data}."
            ]);
            exit();
        } else {
            $pdo->rollBack();
            echo json_encode([
                'ok' => true,
                'mensagem' => "Empresa '{$empresa['nome_fantasia']}' possui cobrança manual ativa. Ignorando renovação automática."
            ]);
            exit();
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(404);
        echo json_encode(['erro' => $e->getMessage()]);
        exit();
    }
}

// Se receber outro evento (ex: atualização de cadastro de cartão, etc), responde 200 para o MP não reenviar
http_response_code(200);
echo json_encode([
    'ok' => true,
    'mensagem' => 'Evento recebido, mas sem ações de faturamento necessárias.'
]);
?>
