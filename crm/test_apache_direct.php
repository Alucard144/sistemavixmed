<?php
require_once "conexao.php";
require_once "ImapClient.php";

header('Content-Type: application/json');

try {
    $stmt = $pdo->prepare("SELECT * FROM crm_email_config WHERE usuario_id = 1");
    $stmt->execute();
    $conf = $stmt->fetch(PDO::FETCH_ASSOC);

    $imap = new ImapClient(
        $conf['imap_host'],
        $conf['imap_port'],
        $conf['imap_secure'],
        $conf['email_usuario'],
        $conf['senha_usuario']
    );

    $result = $imap->getEmails('inbox', null);
    echo json_encode(['sucesso' => true, 'count' => count($result)]);
} catch (Exception $e) {
    echo json_encode(['sucesso' => false, 'error' => $e->getMessage()]);
}
?>
