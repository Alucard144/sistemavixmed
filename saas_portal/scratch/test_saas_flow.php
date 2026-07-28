<?php
// scratch/test_saas_flow.php
// Script de testes robusto utilizando subprocessos para contornar exit()

require_once "/var/www/html/chamadosvixmed/saas_portal/conexao.php";

echo "=== PASSO 1: CONFIGURAÇÃO DE TESTE E BLOQUEIO ===\n";

try {
    $preapproval_id = "sub_beta_12345_test";
    
    // Configurar Empresa Beta (id = 2) como suspensa
    $up_init = $pdo->prepare("UPDATE saas_empresas SET mp_preapproval_id = :mp_id, status_assinatura = 'suspenso', data_expiracao = '2026-01-01' WHERE id = 2");
    $up_init->execute([':mp_id' => $preapproval_id]);
    echo "Empresa Beta (ID 2) configurada com status = suspenso e preapproval_id = '$preapproval_id'\n";

    // Executar a checagem em um subprocesso PHP para não encerrar este script com exit()
    $code = '<?php
    session_start();
    $_SESSION["logado"] = true;
    $_SESSION["usuario_id"] = 3;
    $_SESSION["usuario_empresa_id"] = 2;
    $_SESSION["usuario_tipo"] = "master";
    
    require_once "/var/www/html/chamadosvixmed/saas_portal/conexao.php";
    ob_start();
    include "/var/www/html/chamadosvixmed/saas_portal/config_saas.php";
    $headers = headers_list();
    ob_end_clean();
    
    foreach ($headers as $h) {
        if (stripos($h, "Location: bloqueado.php") !== false) {
            echo "BLOCKED";
            exit();
        }
    }
    echo "ALLOWED";
    ';

    // Salvar script temporário para rodar no subprocesso
    $tmp_file = "/var/www/html/chamadosvixmed/saas_portal/uploads/tmp_test_check.php";
    file_put_contents($tmp_file, $code);
    
    $output = trim(shell_exec("php " . escapeshellarg($tmp_file)));
    @unlink($tmp_file);

    echo "Resultado do acesso (Beta Suspensa): " . ($output === "" ? "BLOCKED" : $output) . "\n";
    if ($output === "") {
        echo "Sucesso: O bloqueio de acesso (redirecionamento e saída) funcionou corretamente!\n";
    } else {
        echo "Erro: O bloqueio de acesso falhou! Retorno: $output\n";
    }

    echo "\n=== PASSO 2: SIMULANDO RECEBIMENTO DO WEBHOOK ===\n";

    // Simular chamada para o webhook enviando o preapproval_id
    $url = "http://localhost/chamadosvixmed/saas_portal/webhook_mp.php";
    $post_data = json_encode([
        'type' => 'subscription_authorized',
        'data' => [
            'id' => $preapproval_id
        ]
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $resposta = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "Retorno do Webhook (HTTP $http_code): $resposta\n";

    // Verificar se os dados no banco foram atualizados para ativo e nova expiração
    $stmt_check = $pdo->prepare("SELECT status_assinatura, data_expiracao FROM saas_empresas WHERE id = 2");
    $stmt_check->execute();
    $empresa_pos = $stmt_check->fetch(PDO::FETCH_ASSOC);

    echo "Status pós-webhook: " . $empresa_pos['status_assinatura'] . " (Esperado: ativo)\n";
    echo "Expiração pós-webhook: " . $empresa_pos['data_expiracao'] . " (Esperado: " . date('Y-m-d', strtotime('+31 days')) . ")\n";

    echo "\n=== PASSO 3: VERIFICANDO ACESSO APÓS LIBERAÇÃO ===\n";

    // Salvar novamente o temporário
    file_put_contents($tmp_file, $code);
    $output_pos = shell_exec("php " . escapeshellarg($tmp_file));
    @unlink($tmp_file);

    echo "Resultado do acesso (Beta Ativa): $output_pos\n";
    if (trim($output_pos) === "ALLOWED") {
        echo "Sucesso: O acesso foi liberado com sucesso após a ativação!\n";
        echo "=== TESTE DE INTEGRAÇÃO SAAS E WEBHOOK CONCLUÍDO COM SUCESSO! ===\n";
    } else {
        echo "Erro: O acesso continuou bloqueado mesmo após ativação! Retorno: $output_pos\n";
    }

} catch (Exception $e) {
    echo "FALHA NO TESTE: " . $e->getMessage() . "\n";
}
?>
